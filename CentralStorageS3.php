<?php
/**
 * CentralStorageS3
 *
 * Clase para subir archivos a un Object Storage S3‑compatible (p. ej. Hetzner Object Storage).
 * Lee configuración desde variables de entorno:
 *   S3_KEY, S3_SECRET, S3_ENDPOINT, S3_BUCKET, S3_REGION (opcional), CDN_DOMAIN (opcional)
 *
 * Requiere: composer require aws/aws-sdk-php
 */

use Aws\S3\S3Client;

if (!class_exists('CentralStorageS3')) {
    class CentralStorageS3
    {
        private S3Client $s3;
        private string $bucket;
        private ?string $cdnDomain;
        private string $endpoint;
        private string $region;

        /**
         * $config supports keys: key, secret, endpoint, bucket, region, cdn, path_style (bool)
         */
        public function __construct(array $config = [])
        {
            $key = $config['key'] ?? getenv('S3_KEY') ?: null;
            $secret = $config['secret'] ?? getenv('S3_SECRET') ?: null;
            $endpoint = $config['endpoint'] ?? getenv('S3_ENDPOINT') ?: null;
            $bucket = $config['bucket'] ?? getenv('S3_BUCKET') ?: null;
            $this->cdnDomain = $config['cdn'] ?? getenv('CDN_DOMAIN') ?: null;
            $this->region = $config['region'] ?? getenv('S3_REGION') ?: 'us-east-1';
            $pathStyle = array_key_exists('path_style', $config) ? (bool)$config['path_style'] : true;

            if (!$key || !$secret || !$endpoint || !$bucket) {
                throw new InvalidArgumentException('S3 configuration missing (key/secret/endpoint/bucket).');
            }

            $this->endpoint = rtrim($endpoint, '/');
            $this->bucket = $bucket;

            $this->s3 = new S3Client([
                'version' => 'latest',
                'region'  => $this->region,
                'endpoint' => $this->endpoint,
                'use_path_style_endpoint' => $pathStyle,
                'credentials' => [
                    'key' => $key,
                    'secret' => $secret
                ]
            ]);
        }

        /**
         * Genera una key para el objeto en el bucket.
         * Ejemplo: prefix/2025/10/abcdef1234.jpg
         */
        public function generateKey(string $prefix, string $filename): string
        {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $uuid = bin2hex(random_bytes(8));
            $cleanPrefix = trim($prefix, '/');
            $y = date('Y');
            $m = date('m');
            $extPart = $ext ? '.' . ltrim($ext, '.') : '';
            return sprintf('%s/%s/%s/%s%s', $cleanPrefix, $y, $m, $uuid, $extPart);
        }

        /**
         * Sube un archivo local al bucket.
         * Retorna array ['success'=>bool, 'url'=>..., 'key'=>..., 'mime'=>...] o false en error.
         */
        public function uploadFile(string $localPath, string $key, array $options = []): mixed
        {
            if (!is_readable($localPath)) {
                return false;
            }

            // detectar mime
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($localPath) ?: 'application/octet-stream';

            $acl = $options['acl'] ?? 'public-read';
            $cache = $options['cache_control'] ?? 'max-age=31536000, public';

            try {
                $this->s3->putObject([
                    'Bucket' => $this->bucket,
                    'Key'    => $key,
                    'SourceFile' => $localPath,
                    'ContentType' => $mime,
                    'ACL' => $acl,
                    'CacheControl' => $cache
                ]);
            } catch (Throwable $e) {
                error_log('S3 upload error: ' . $e->getMessage());
                return false;
            }

            $url = $this->buildUrl($key);
            return ['success' => true, 'url' => $url, 'key' => $key, 'mime' => $mime];
        }

        /**
         * Construye la URL pública final. Si se configuró CDN_DOMAIN, lo usará.
         * Sino intenta una URL basada en endpoint/bucket/key (path-style).
         */
        public function buildUrl(string $key): string
        {
            // Si hay CDN configurado, usarlo
            if ($this->cdnDomain) {
                return rtrim($this->cdnDomain, '/') . '/' . ltrim($key, '/');
            }

            // Construcción sencilla: endpoint + /bucket/key
            return $this->endpoint . '/' . $this->bucket . '/' . ltrim($key, '/');
        }

        /**
         * Elimina un objeto del bucket.
         */
        public function deleteObject(string $key): bool
        {
            try {
                $this->s3->deleteObject([
                    'Bucket' => $this->bucket,
                    'Key' => $key
                ]);
                return true;
            } catch (Throwable $e) {
                error_log('S3 delete error: ' . $e->getMessage());
                return false;
            }
        }
    }
}