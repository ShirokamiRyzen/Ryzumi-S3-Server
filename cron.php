<?php
// Ryzumi S3 Server - Cleanup Cron Job
// Run this script periodically (e.g., every hour) to delete expired files from temporary buckets.

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "[Ryzumi-S3-Cron] Starting cleanup process..." . PHP_EOL;

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    die("[Error] config.php not found." . PHP_EOL);
}

$config = require $configFile;
$baseDir = rtrim($config['base_dir'], '/\\');

if (!isset($config['temp_buckets']) || !is_array($config['temp_buckets'])) {
    die("[Info] No 'temp_buckets' configured. Nothing to clean." . PHP_EOL);
}

foreach ($config['temp_buckets'] as $bucket => $hours) {
    echo "[Info] Checking bucket: $bucket (Limit: $hours hours)" . PHP_EOL;
    
    $bucketDir = $baseDir . '/' . $bucket;
    
    if (!is_dir($bucketDir)) {
        echo "  - Bucket directory not found: $bucketDir" . PHP_EOL;
        continue;
    }
    
    $expirationSeconds = $hours * 3600;
    $now = time();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($bucketDir, RecursiveDirectoryIterator::SKIP_DOTS));
    
    $count = 0;
    $deleted = 0;
    
    foreach ($files as $file) {
        if ($file->isDir()) continue;
        
        // Skip .multipart directory/files if needed, or clean them too if strictly temporary?
        // Usually .multipart should be cleaned by AbortIncompleteMultipartUpload logic, 
        // but if the bucket is temporary, we might as well clean everything.
        // However, let's play safe and check if it's a file.
        
        $filePath = $file->getPathname();
        $fileAge = $now - $file->getMTime();
        
        if ($fileAge > $expirationSeconds) {
            echo "  - Deleting expired file: " . $file->getFilename() . " (Age: " . round($fileAge/3600, 1) . "h)" . PHP_EOL;
            if (unlink($filePath)) {
                $deleted++;
            } else {
                echo "    [Error] Failed to delete file." . PHP_EOL;
            }
        }
        $count++;
    }
    
    echo "  - Scanned $count files. Deleted $deleted files." . PHP_EOL;
}

echo "[Ryzumi-S3-Cron] Cleanup completed." . PHP_EOL;
