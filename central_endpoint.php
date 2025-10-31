<?php
/**
 * central_endpoint.php
 *
 * Endpoint mínimo para recibir archivos, procesarlos (resize/convert) y subirlos
 * a Hetzner Object Storage (S3 compatible) usando CentralStorageS3 + ImageProcessor.
 *
 * Requisitos:
 *  - composer require aws/aws-sdk-php
 *  - ext-imagick recommended (fallback to GD)
 *  - configurar variables de entorno (ver .env.example)
 *
 * Uso:
 *  - POST raw body: curl --data-binary "@/path/to/img.jpg" "https://tu.api/central_endpoint.php?action=setFile&key=cliente123&filename=foto.jpg"
 *  - POST multipart/form-data: curl -F "file=@/path/to/img.jpg" "https://tu.api/central_endpoint.php?action=setFile&key=cliente123"
 *
 * Respuesta JSON:
 *  { success: true, url: "...", key: "uploads/2025/10/abcdef.jpg" }
 */

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/CentralStorageS3.php';
require_once __DIR__ . '/ImageProcessor.php';

/**
 * Simple .env loader (if you prefer phpdotenv replace this).
 */
function load_dotenv(string $path = __DIR__ . '/.env'): void
{
    if (!file_exists($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2) + [null, null]);
        if ($k !== null && !getenv($k)) {
            putenv(sprintf('%s=%s', $k, $v));
            $_ENV[$k] = $v;
        }
    }
}

load_dotenv(); // carga .env si existe

// Construir config para S3
$s3config = [
    'key' => getenv('S3_KEY') ?: null,
    'secret' => getenv('S3_SECRET') ?: null,
    'endpoint' => getenv('S3_ENDPOINT') ?: null,
    'bucket' => getenv('S3_BUCKET') ?: null,
    'region' => getenv('S3_REGION') ?: 'us-east-1',
    'cdn' => getenv('CDN_DOMAIN') ?: null,
    'path_style' => true
];

// Validación inicial
if (!$s3config['key'] || !$s3config['secret'] || !$s3config['endpoint'] || !$s3config['bucket']) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'S3 configuration missing. Set S3_KEY,S3_SECRET,S3_ENDPOINT,S3_BUCKET in env.']);
    exit;
}

$storage = new CentralStorageS3($s3config);

// Simple router: only action=setFile handled here
$action = $_GET['action'] ?? '';
$keyParam = $_GET['key'] ?? null;

header('Content-Type: application/json; charset=utf-8');

if ($action !== 'setFile' || $keyParam === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid action or missing key']);
    exit;
}

// Determine incoming file: multipart/form-data or raw body
$filename = $_GET['filename'] ?? null;
$tmpInput = null;
$origFilename = $filename;

if (!empty($_FILES) && isset($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
    // multipart
    $tmpInput = $_FILES['file']['tmp_name'];
    $origFilename = $origFilename ?: $_FILES['file']['name'] ?? ('file');
} else {
    // raw body
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'empty body']);
        exit;
    }
    // create temp file
    $tmpInput = tempnam(sys_get_temp_dir(), 'up_');
    file_put_contents($tmpInput, $raw);
    if (!$origFilename) $origFilename = 'file';
}

// Optional: validate file size limit (e.g., 50MB)
$maxBytes = (int)(getenv('MAX_UPLOAD_BYTES') ?: 50 * 1024 * 1024);
if (filesize($tmpInput) > $maxBytes) {
    @unlink($tmpInput);
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'file too large']);
    exit;
}

// Process image (try convert to webp); if not an image, we will upload as-is
$desiredFormat = getenv('DESIRED_FORMAT') ?: 'webp';
$maxWidth = (int)(getenv('MAX_IMAGE_WIDTH') ?: 1200);

$processed = ImageProcessor::processImage($tmpInput, $maxWidth, $desiredFormat);
$uploadPath = $processed ?: $tmpInput;

// Ensure correct extension for key generation (prefer original filename extension or processed type)
if ($processed) {
    // Try to infer extension from processed tmp via mime
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($processed) ?: 'application/octet-stream';
    $map = [
        'image/webp' => 'webp',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf'
    ];
    $ext = $map[$mime] ?? pathinfo($origFilename, PATHINFO_EXTENSION) ?: 'bin';
} else {
    $ext = pathinfo($origFilename, PATHINFO_EXTENSION) ?: 'bin';
}

// generate object key
$objectKey = $storage->generateKey('uploads', 'file.' . $ext);

// upload
$options = [
    'acl' => getenv('S3_ACL') ?: 'public-read',
    'cache_control' => getenv('S3_CACHE_CONTROL') ?: 'max-age=31536000, public'
];

$res = $storage->uploadFile($uploadPath, $objectKey, $options);

// cleanup temps
if ($tmpInput && file_exists($tmpInput)) @unlink($tmpInput);
if ($processed && file_exists($processed) && $processed !== $tmpInput) @unlink($processed);

if ($res === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'upload failed']);
    exit;
}

// return URL
echo json_encode(['success' => true, 'url' => $res['url'], 'key' => $res['key']]);