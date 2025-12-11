<?php
// Ryzumi S3 Server
// Compatible with standard S3 SDKs, supporting Multipart Uploads for large files.

// CLI Server Support (php -S)
if (php_sapi_name() == 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    // Only serve existing files that are NOT valid S3 bucket paths
    if (is_file(__DIR__ . $path) && file_exists(__DIR__ . $path) && $path !== '/index.php') {
        return false; 
    }
}

// 1. Load Config
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    $configFile = __DIR__ . '/config.example.php';
}

if (!file_exists($configFile)) {
    // If we can't load config, we can't do much. 
    // Fallback errors to internal error log.
    error_log("Config file not found in " . __DIR__);
    http_response_code(500);
    exit;
}

$config = require $configFile;

// Maintenance Mode Check
if (isset($config['maintenance_mode']) && $config['maintenance_mode'] === true) {
    http_response_code(503); // Service Unavailable
    include __DIR__ . '/mt.php';
    exit;
}
$dataDir = rtrim($config['base_dir'], '/\\');

// 2. Configure Logging based on Config
$enableErrorLog = $config['enable_error_log'] ?? false;
$enableDebugLog = $config['enable_debug_log'] ?? false;

ini_set('display_errors', 0);
ini_set('log_errors', $enableErrorLog ? 1 : 0);
ini_set('error_log', __DIR__ . '/error.log');
error_reporting(E_ALL);

// Debug Log Function
function debugLog($msg) {
    global $enableDebugLog;
    if ($enableDebugLog) {
        file_put_contents(__DIR__ . '/debug.log', "[" . date('c') . "] " . $msg . "\n", FILE_APPEND);
    }
}

// Manually parse query string for safety in CLI mode
if (php_sapi_name() == 'cli-server') {
    $parts = parse_url($_SERVER['REQUEST_URI']);
    if (isset($parts['query'])) {
        parse_str($parts['query'], $_GET);
    }
}

$uri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];
//debugLog("Request: $method $uri");

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, PUT, POST, DELETE, HEAD, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, x-amz-date, x-amz-content-sha256, x-amz-user-agent, x-amz-acl, x-amz-security-token");

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Global Exception Handler
set_exception_handler(function($e) {
    $code = 'InternalError';
    $message = $e->getMessage();
    debugLog("Exception: $message");
    
    // Ensure we send XML error even if we crashed
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/xml');
    }
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Error><Code>$code</Code><Message>$message</Message><Resource></Resource><RequestId>" . uniqid() . "</RequestId></Error>";
    exit;
});

// Polyfill for getallheaders
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

// Config loaded at bootstrap
if (!is_dir($dataDir)) {
    if (!mkdir($dataDir, 0777, true) && !is_dir($dataDir)) {
         throw new Exception("Failed to create data directory.");
    }
}

// Helper Functions
function sendXml($content, $code = 200) {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/xml');
    }
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" . $content;
    exit;
}

function getMimeType($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mimes = [
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mkv' => 'video/x-matroska',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'pdf' => 'application/pdf', 'txt' => 'text/plain', 'xml' => 'application/xml',
        'json' => 'application/json', 'html' => 'text/html', 'css' => 'text/css', 'js' => 'application/javascript'
    ];
    if (isset($mimes[$ext])) return $mimes[$ext];
    
    // Fallback to native detection
    return mime_content_type($filename) ?: 'application/octet-stream';
}

function sendError($code, $message, $resource = '', $httpCode = 400) {
    debugLog("Error Response: $code - $message");
    $xml = "<Error><Code>$code</Code><Message>$message</Message><Resource>$resource</Resource><RequestId>" . uniqid() . "</RequestId></Error>";
    sendXml($xml, $httpCode);
}

// Basic Authentication Helper
function checkAuth($config) {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (empty($auth)) {
        return false;
    }
    
    // Check for Access Key in the Authorization string
    // Format: AWS4-HMAC-SHA256 Credential=ACCESS_KEY/...
    if (strpos($auth, 'Credential=' . $config['access_key']) === false) {
        return false;
    }
    
    return true;
}

try {
    $parsedUrl = parse_url($uri);
    $path = $parsedUrl['path'];
    $query = [];
    if (isset($parsedUrl['query'])) {
        parse_str($parsedUrl['query'], $query);
    }
    if (empty($query) && !empty($_GET)) { 
        $query = $_GET; // Fallback to $_GET which we populated manually
    }

    // Extract Bucket and Key
    $parts = explode('/', ltrim($path, '/'));
    $parts = array_filter($parts, function($v) { return $v !== ''; });
    $parts = array_values($parts);

    $bucket = $parts[0] ?? '';
    // Re-construct key
    $key = isset($parts[1]) ? implode('/', array_slice($parts, 1)) : '';
    $key = urldecode($key);

    debugLog("Parsed: Bucket=$bucket, Key=$key");

    // === SERVICE: List Buckets ===
    if (!$bucket) {
        if ($method === 'GET') {
            // Require Auth
            if (!checkAuth($config)) {
                sendError('AccessDenied', 'Access Denied', '', 403);
            }

            $buckets = glob($dataDir . '/*', GLOB_ONLYDIR);
            $bucketsXml = '';
            foreach ($buckets as $b) {
                if (basename($b) === '.multipart') continue;
                $name = basename($b);
                $date = date('c', filemtime($b));
                $bucketsXml .= "<Bucket><Name>$name</Name><CreationDate>$date</CreationDate></Bucket>";
            }
            sendXml("<ListAllMyBucketsResult xmlns=\"http://s3.amazonaws.com/doc/2006-03-01/\"><Owner><ID>ryzumi</ID><DisplayName>ryzumi</DisplayName></Owner><Buckets>$bucketsXml</Buckets></ListAllMyBucketsResult>");
        }
        exit;
    }

    $bucketPath = $dataDir . '/' . $bucket;

    // === BUCKET OPERATIONS ===
    if ($bucket && !$key) {
        if ($method === 'PUT') {
            // Require Auth to Create Bucket
            if (!checkAuth($config)) {
                sendError('AccessDenied', 'Access Denied', $bucket, 403);
            }

            if (!is_dir($bucketPath)) {
                mkdir($bucketPath, 0777, true);
            }
            header('Location: /' . $bucket);
            // PUT Bucket returns empty body usually
            header("Content-Length: 0");
            header("Content-Type: text/plain");
            exit;
        }
        if ($method === 'HEAD') {
            if (is_dir($bucketPath)) {
                http_response_code(200);
            } else {
                http_response_code(404);
            }
            exit;
        }
        if ($method === 'GET') {
            // Listing Objects in Bucket - Require Auth
            if (!checkAuth($config)) {
                sendError('AccessDenied', 'Access Denied', $bucket, 403);
            }

            if (!is_dir($bucketPath)) {
                sendError('NoSuchBucket', 'The specified bucket does not exist', $bucket, 404);
            }
            $contents = "";
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($bucketPath));
            foreach ($files as $file) {
                if ($file->isDir()) continue;
                $fname = substr($file->getPathname(), strlen($bucketPath) + 1);
                $fname = str_replace('\\', '/', $fname);
                if (strpos($fname, '.multipart') !== false) continue;
                
                $size = $file->getSize();
                $mtime = date('c', $file->getMTime());
                $etag = md5_file($file->getPathname());
                $contents .= "<Contents><Key>$fname</Key><LastModified>$mtime</LastModified><ETag>\"$etag\"</ETag><Size>$size</Size><StorageClass>STANDARD</StorageClass></Contents>";
            }
            sendXml("<ListBucketResult xmlns=\"http://s3.amazonaws.com/doc/2006-03-01/\"><Name>$bucket</Name><Prefix></Prefix><Marker></Marker><MaxKeys>1000</MaxKeys><IsTruncated>false</IsTruncated>$contents</ListBucketResult>");
        }
        exit;
    }

    // === OBJECT OPERATIONS ===

    // Ensure bucket exists - STRICT CHECK
    if (!is_dir($bucketPath)) {
         sendError('NoSuchBucket', 'The specified bucket does not exist', $bucket, 404);
    }

    $objectPath = $bucketPath . '/' . $key;

    // --- Multipart: Initiate ---
    // Note: S3 sends uploads= via query string logic
    if (array_key_exists('uploads', $query) && $method === 'POST') {
        // Require Auth
        if (!checkAuth($config)) sendError('AccessDenied', 'Access Denied', $key, 403);

        debugLog("Multipart Initiate: Bucket=$bucket Key=$key");
        $uploadId = bin2hex(random_bytes(16));
        $mpDir = $dataDir . '/.multipart/' . $bucket . '/' . $uploadId;
        if (!is_dir($mpDir)) {
            mkdir($mpDir, 0777, true);
        }
        file_put_contents($mpDir . '/key', $key);
        sendXml("<InitiateMultipartUploadResult xmlns=\"http://s3.amazonaws.com/doc/2006-03-01/\"><Bucket>$bucket</Bucket><Key>$key</Key><UploadId>$uploadId</UploadId></InitiateMultipartUploadResult>");
    }

    // --- Multipart: Upload Part ---
    if (isset($query['uploadId']) && isset($query['partNumber']) && $method === 'PUT') {
        // Require Auth
        if (!checkAuth($config)) sendError('AccessDenied', 'Access Denied', $key, 403);

        $uploadId = $query['uploadId'];
        $partNumber = $query['partNumber'];
        debugLog("Multipart Upload Part: $partNumber for $uploadId");
        
        $mpDir = $dataDir . '/.multipart/' . $bucket . '/' . $uploadId;
        
        if (!is_dir($mpDir)) {
            sendError('NoSuchUpload', 'The specified upload does not exist', $uploadId, 404);
        }
        
        $partFile = $mpDir . '/' . $partNumber . '.part';
        
        $input = fopen("php://input", "r");
        $output = fopen($partFile, "w");
        if ($input && $output) {
            stream_copy_to_stream($input, $output);
            fclose($input);
            fclose($output);
        } else {
             sendError('InternalError', 'Failed to write part', $key, 500);
        }
        
        $etag = md5_file($partFile);
        
        // Response for UploadPart MUST NOT have XML Content-Type
        header("ETag: \"$etag\"");
        header("Content-Length: 0");
        header("Content-Type: text/plain"); 
        http_response_code(200);
        exit;
    }

    // --- Multipart: Complete ---
    if (isset($query['uploadId']) && $method === 'POST') {
        // Require Auth
        if (!checkAuth($config)) sendError('AccessDenied', 'Access Denied', $key, 403);

        $uploadId = $query['uploadId'];
        debugLog("Multipart Complete: $uploadId");
        
        $mpDir = $dataDir . '/.multipart/' . $bucket . '/' . $uploadId;
        
        if (!is_dir($mpDir)) {
             sendError('NoSuchUpload', 'The specified upload does not exist', $uploadId, 404);
        }
        
        $xmlData = file_get_contents('php://input');
        
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlData);
        if ($xml === false) {
             sendError('MalformedXML', 'The XML you provided was not well-formed', $key, 400);
        }
        
        $dir = dirname($objectPath);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        
        $finalFile = fopen($objectPath, 'w');
        
        if ($finalFile) {
            foreach ($xml->Part as $part) {
                $pNum = (string)$part->PartNumber;
                $partPath = $mpDir . '/' . $pNum . '.part';
                if (file_exists($partPath)) {
                    $pHandle = fopen($partPath, 'r');
                    stream_copy_to_stream($pHandle, $finalFile);
                    fclose($pHandle);
                } else {
                    debugLog("Missing part $pNum at $partPath");
                }
            }
            fclose($finalFile);
        } else {
            sendError('InternalError', 'Could not write final file', $key, 500);
        }
        
        // Cleanup
        $files = glob($mpDir . '/*');
        foreach ($files as $file) unlink($file);
        rmdir($mpDir);
        
        $etag = md5_file($objectPath);
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $location = "https://$host/$bucket/$key";
        
        sendXml("<CompleteMultipartUploadResult xmlns=\"http://s3.amazonaws.com/doc/2006-03-01/\"><Location>$location</Location><Bucket>$bucket</Bucket><Key>$key</Key><ETag>\"$etag\"</ETag></CompleteMultipartUploadResult>");
    }

    // --- Multipart: Abort ---
    if (isset($query['uploadId']) && $method === 'DELETE') {
         // Require Auth
        if (!checkAuth($config)) sendError('AccessDenied', 'Access Denied', $key, 403);

        $uploadId = $query['uploadId'];
        $mpDir = $dataDir . '/.multipart/' . $bucket . '/' . $uploadId;
        if (is_dir($mpDir)) {
             $files = glob($mpDir . '/*');
             foreach ($files as $file) unlink($file);
             rmdir($mpDir);
        }
        http_response_code(204);
        exit;
    }

    // === STANDARD OPERATIONS ===

    if ($method === 'PUT') {
        // Require Auth
        if (!checkAuth($config)) sendError('AccessDenied', 'Access Denied', $key, 403);

        debugLog("Standard PUT: $key");
        $dir = dirname($objectPath);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        
        $input = fopen("php://input", "r");
        $output = fopen($objectPath, "w");
        if ($input && $output) {
            stream_copy_to_stream($input, $output);
            fclose($input);
            fclose($output);
        } else {
            sendError('InternalError', 'Failed to write file', $key, 500);
        }
        
        $etag = md5_file($objectPath);
        
        // Response for PutObject MUST NOT have XML Content-Type
        header("ETag: \"$etag\"");
        header("Content-Length: 0");
        header("Content-Type: text/plain");
        http_response_code(200);
        exit;
    }

    if ($method === 'GET') {
        if (!file_exists($objectPath)) {
            sendError('NoSuchKey', 'The specified key does not exist', $key, 404);
        }
        
        // Disable output buffering to prevent memory issues for large files
        if (ob_get_level()) ob_end_clean();
        
        $filesize = filesize($objectPath);
        $mime = getMimeType($objectPath);
        
        // Handle Range Requests (Video Playback)
        $range = $_SERVER['HTTP_RANGE'] ?? null;
        
        header("Content-Type: $mime");
        header("Content-Disposition: inline; filename=\"" . basename($objectPath) . "\"");
        header("Accept-Ranges: bytes");
        header("Last-Modified: " . gmdate("D, d M Y H:i:s T", filemtime($objectPath)));
        header("ETag: \"" . md5_file($objectPath) . "\"");
        
        if ($range) {
            $parts = explode('=', $range);
            if ($parts[0] == 'bytes') {
                $range = explode('-', $parts[1]);
                $start = intval($range[0]);
                $end = ($range[1] !== "") ? intval($range[1]) : $filesize - 1;
                $length = $end - $start + 1;
                
                http_response_code(206);
                header("Content-Range: bytes $start-$end/$filesize");
                header("Content-Length: $length");
                
                $fp = fopen($objectPath, 'rb');
                fseek($fp, $start);
                
                // Stream chunks
                while(!feof($fp) && ($p = ftell($fp)) <= $end) {
                    if ($p + 8192 > $end) {
                        echo fread($fp, $end - $p + 1);
                    } else {
                        echo fread($fp, 8192);
                    }
                    flush();
                }
                fclose($fp);
                exit;
            }
        }
        
        header("Content-Length: $filesize");
        readfile($objectPath);
        exit;
    }

    if ($method === 'HEAD') {
        if (!file_exists($objectPath)) {
            http_response_code(404);
            exit;
        }
        $mime = getMimeType($objectPath);
        header("Content-Type: $mime");
        header("Content-Length: " . filesize($objectPath));
        header("ETag: \"" . md5_file($objectPath) . "\"");
        header("Last-Modified: " . gmdate("D, d M Y H:i:s T", filemtime($objectPath)));
        exit;
    }

    if ($method === 'DELETE') {
        // Require Auth
        if (!checkAuth($config)) sendError('AccessDenied', 'Access Denied', $key, 403);

        if (file_exists($objectPath)) {
            unlink($objectPath);
        }
        http_response_code(204);
        exit;
    }

    http_response_code(405);
    exit;

} catch (Exception $e) {
    sendError('InternalError', $e->getMessage(), '', 500);
}
