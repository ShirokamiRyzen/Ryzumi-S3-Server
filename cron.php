<?php
// Ryzumi S3 Server - Cleanup Cron Job
// Accessible via URL: /cron.php?secret_key=YOUR_SECRET_KEY

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

function sendCronXml($content, $code = 200) {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/xml');
    }
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" . $content;
    exit;
}

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    // If config is missing, we can't verify secret, so deny.
    sendCronXml("<Error><Code>ConfigurationMissing</Code><Message>Config file not found.</Message></Error>", 500);
}

$config = require $configFile;

// verify secret key
$secretKey = $config['cron_secret_key'] ?? '';
$requestKey = $_GET['secret_key'] ?? '';

// Allow CLI execution without key, or Web execution with key
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    if (empty($secretKey) || $requestKey !== $secretKey) {
        sendCronXml("<Error><Code>AccessDenied</Code><Message>Not Allowed</Message></Error>", 403);
    }
}

// Start Cleanup
$baseDir = rtrim($config['base_dir'], '/\\');
$deletedCount = 0;
$scannedCount = 0;
$logDetails = "";

if (isset($config['temp_buckets']) && is_array($config['temp_buckets'])) {
    $now = time();
    
    foreach ($config['temp_buckets'] as $bucket => $hours) {
        $bucketDir = $baseDir . '/' . $bucket;
        if (!is_dir($bucketDir)) continue;

        $expirationSeconds = $hours * 3600;
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($bucketDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            // If it's a directory (and not dot), we might remove it if empty later, 
            // but main logic is checking files validation.
            // S3 structure usually Files. Directory cleanup is secondary.
            
            if ($file->isFile()) {
                $scannedCount++;
                $fileAge = $now - $file->getMTime();
                if ($fileAge > $expirationSeconds) {
                    if (unlink($file->getPathname())) {
                        $deletedCount++;
                        $logDetails .= "Deleted: " . $file->getFilename() . " (Age: " . round($fileAge/3600, 1) . "h)\n";
                    }
                }
            }
        }
    }
}

if ($isCli) {
    echo "Cleanup Completed.\n";
    echo "Scanned: $scannedCount\n";
    echo "Deleted: $deletedCount\n";
    if ($logDetails) echo "Details:\n$logDetails";
} else {
    // Return XML Report
    $xml = "<CronResult>";
    $xml .= "<Status>Success</Status>";
    $xml .= "<ScannedFiles>$scannedCount</ScannedFiles>";
    $xml .= "<DeletedFiles>$deletedCount</DeletedFiles>";
    // Optional: we don't dump list in XML to save BW unless needed.
    $xml .= "</CronResult>";
    sendCronXml($xml, 200);
}
