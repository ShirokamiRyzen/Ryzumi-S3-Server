# Ryzumi S3 Server

A lightweight, PHP-based S3-compatible object storage server.  
Designed to work with standard S3 SDKs (like `@aws-sdk/client-s3`), supporting **Multipart Uploads** to bypass Cloudflare/PHP upload limits.

## Features

- **S3 Compatibility**: Supports standard File Operations (GET, PUT, DELETE, HEAD) and Multipart Uploads.
- **Multipart Upload Support**: Handles large files (100MB+) by splitting them into chunks.
- **Temporary Buckets**: Auto-delete files in specific buckets after a set time (configurable).
- **Video Streaming**: Supports HTTP Range requests for streaming video playback (MP4, MKV) in browsers.
- **Web Cron**: Cleanup expired files via a secure URL endpoint.
- **Logging**: Configurable Debug and Error logging.

## Requirements

- PHP 8.2 or higher
- Web Server (Nginx, Apache, or PHP Built-in Server)
- Write permissions for the `data/` directory

## Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/ShirokamiRyzen/Ryzumi-S3-Server.git
   cd Ryzumi-S3-Server
   ```

2. **Configure the Server**
   Copy the example config:
   ```bash
   cp config.example.php config.php
   ```
   Edit `config.php` with your credentials and preferences:
   ```php
   return [
       'access_key' => 'RANDOM_UUID',
       'secret_key' => 'RANDOM_UUID_OR_RANDOM_STRING',
       'base_dir'   => __DIR__ . '/data',
       
       // Cron Security
       'cron_secret_key' => 'RANDOM_STRING',

       // Temporary Buckets (Auto-delete files after X hours)
       'temp_buckets' => [
           'ryzumi-api' => 24, // 24 hours
           'temp-files' => 72, // 3 days
       ],

       // Logging
       'enable_debug_log' => false,
       'enable_error_log' => true,

   ];
   ```

## Usage

### Local Development
You can run the server using PHP's built-in server:
```bash
php -S 0.0.0.0:5000 index.php
```
Your Endpoint will be: `http://localhost:5000`

### Nginx Configuration
Add this rewrite rule to your Nginx server block to handle S3 URL routing:
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

## Automatic Cleanup (Cron)

This server includes a cleanup system for "Temporary Buckets". You can trigger it securely via URL.

**Endpoint:**
`GET /cron.php?secret_key=cron_secret_key`

**Example Response:**
```xml
<CronResult>
    <Status>Success</Status>
    <ScannedFiles>150</ScannedFiles>
    <DeletedFiles>5</DeletedFiles>
</CronResult>
```

**Setting up Cron Job:**
You can set up a real system cron/task to hit this URL every hour:
```bash
curl -s "http://your-domain.com/cron.php?secret_key=cron_secret_key"
```

## Client Example (Node.js / JavaScript)

```javascript
import { S3Client } from "@aws-sdk/client-s3";
import { Upload } from "@aws-sdk/lib-storage";

const s3 = new S3Client({
    region: "us-east-1", // Region can be anything
    endpoint: "http://localhost:5000", // Your server URL
    credentials: {
        accessKeyId: "access_key",
        secretAccessKey: "secret_key"
    },
    forcePathStyle: true // Important for custom S3 servers
});

// Multipart Upload Example
async function uploadFile(file) {
    const parallelUploads3 = new Upload({
        client: s3,
        params: { 
            Bucket: "ryzumi-api", // Bucket Name
            Key: file.name, // File Name
            Body: file // File Data
        },
    });

    parallelUploads3.on("httpUploadProgress", (progress) => {
        console.log(progress);
    });

    await parallelUploads3.done();
}
```

## License
MIT
