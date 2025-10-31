<?php
/**
 * ImageProcessor
 *
 * Procesa imágenes: resize y conversión (webp preferido).
 * - Usa Imagick si está disponible (preferible).
 * - Si no, usa GD como fallback (más limitado).
 *
 * Retorna path a archivo temporal generado o null en caso de fallo.
 */

if (!class_exists('ImageProcessor')) {
    class ImageProcessor
    {
        /**
         * Procesa la imagen:
         * @param string $inputPath Ruta al fichero de entrada (local y legible)
         * @param int $maxWidth Anchura máxima (pixels)
         * @param string|null $format 'webp'|'jpg'|'png'|null (null = mantener formato original)
         * @return string|null Path temporal al fichero procesado
         */
        public static function processImage(string $inputPath, int $maxWidth = 1200, ?string $format = 'webp'): ?string
        {
            if (!is_readable($inputPath)) return null;

            // Try Imagick
            if (extension_loaded('imagick')) {
                try {
                    $img = new Imagick($inputPath);
                    // evitar rotaciones por EXIF automáticas
                    if (method_exists($img, 'setImageOrientation')) {
                        $img->setImageOrientation(0);
                    }

                    $w = $img->getImageWidth();
                    $h = $img->getImageHeight();

                    if ($w > $maxWidth) {
                        $newH = (int) round($maxWidth * ($h / $w));
                        $img->resizeImage($maxWidth, $newH, Imagick::FILTER_LANCZOS, 1);
                    }

                    if ($format === 'webp') {
                        $img->setImageFormat('webp');
                        $img->setImageCompressionQuality(80);
                    } elseif ($format !== null) {
                        $img->setImageFormat($format);
                    }

                    $tmp = tempnam(sys_get_temp_dir(), 'procimg_');
                    // escribir en tmp (Imagick deduce formato por la extensión del contenido)
                    $img->writeImage($tmp);
                    $img->clear();
                    $img->destroy();
                    return $tmp;
                } catch (Throwable $e) {
                    error_log('Imagick error: ' . $e->getMessage());
                    // fallback GD
                }
            }

            // Fallback GD
            $info = @getimagesize($inputPath);
            if ($info === false) return null;
            [$w, $h, $type] = $info;

            switch ($type) {
                case IMAGETYPE_JPEG:
                    $src = imagecreatefromjpeg($inputPath);
                    $ext = 'jpg';
                    break;
                case IMAGETYPE_PNG:
                    $src = imagecreatefrompng($inputPath);
                    $ext = 'png';
                    break;
                case IMAGETYPE_GIF:
                    $src = imagecreatefromgif($inputPath);
                    $ext = 'gif';
                    break;
                default:
                    return null;
            }

            if ($w > $maxWidth) {
                $newW = $maxWidth;
                $newH = (int) round($h * ($newW / $w));
            } else {
                $newW = $w; $newH = $h;
            }

            $dst = imagecreatetruecolor($newW, $newH);

            if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
                imagecolortransparent($dst, imagecolorallocatealpha($dst, 0, 0, 0, 127));
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
            }

            imagecopyresampled($dst, $src, 0,0,0,0, $newW, $newH, $w, $h);

            $tmp = tempnam(sys_get_temp_dir(), 'procimg_');

            if ($format === 'webp' && function_exists('imagewebp')) {
                imagewebp($dst, $tmp, 80);
            } elseif ($format === 'jpg') {
                imagejpeg($dst, $tmp, 85);
            } elseif ($format === 'png') {
                imagepng($dst, $tmp, 6);
            } else {
                // mantener original
                switch ($ext) {
                    case 'jpg': imagejpeg($dst, $tmp, 85); break;
                    case 'png': imagepng($dst, $tmp, 6); break;
                    default: imagejpeg($dst, $tmp, 85); break;
                }
            }

            imagedestroy($src);
            imagedestroy($dst);
            return $tmp;
        }
    }
}