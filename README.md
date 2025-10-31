```markdown
# CentralStorage (Hetzner Object Storage) — README

Resumen
-------
Esta aplicación mínima recibe archivos (imágenes, PDFs, etc.), opcionalmente procesa imágenes (resize + conversión a `.webp`) y sube el resultado a un Object Storage S3‑compatible (por ejemplo Hetzner Object Storage). Devuelve una URL pública donde tu frontend puede mostrar/preview el archivo. Está pensada para uso interno: simple, rápida y barata.

Qué entrego
-----------
En el proyecto encontrarás (archivos ya preparados):

- `CentralStorageS3.php` — Clase que encapsula la conexión S3 y subidas.
- `ImageProcessor.php` — Procesador de imágenes (Imagick preferible; GD como fallback).
- `central_endpoint.php` — Endpoint HTTP que recibe archivos, los procesa y los sube a Object Storage; devuelve JSON con la URL pública.
- `.env.example` — Ejemplo de variables de entorno que debes configurar (credenciales y opciones).
- (Opcional) Scripts: migración, presigned URL generator (puedes añadirlos si los solicitas).

Principios de diseño
--------------------
- Solo almacenamiento en Object Storage; no persistimos ficheros en disco permanentemente en tu servidor.
- Procesamiento local temporal: el archivo se escribe como temporal para procesarlo (resize/convert) y luego se sube; tras la subida se elimina el temporal.
- URLs devueltas pueden usar un CDN (si lo configuras) o el endpoint direct del Object Storage; en `.env` puedes poner `CDN_DOMAIN` para que las URLs devueltas usen el dominio CDN.
- Seguridad mínima por diseño (uso interno). No incluyas credenciales en repositorio público.

Requisitos
----------
- PHP 8.x (recomendado)
- ext-fileinfo (normalmente incluido)
- composer
- `aws/aws-sdk-php` (instalar con composer)
- ext-imagick (recomendado) o GD (fallback)
- Acceso a Hetzner Object Storage (Access Key, Secret Key, endpoint, bucket)

Comandos de instalación
-----------------------
1. Clona / sitúa los archivos en tu servidor (por ejemplo `/var/www/central/`).
2. Instala dependencias PHP (composer):
   ```
   cd /var/www/central
   composer require aws/aws-sdk-php
   ```
3. Instala Imagick (opcional, mejora calidad y rendimiento de conversión):
   - En Debian/Ubuntu:
     ```
     sudo apt update
     sudo apt install php-imagick
     sudo systemctl restart php8.1-fpm   # o el servicio PHP que uses
     ```
4. Asegúrate de que PHP puede escribir en el directorio temporal (`sys_get_temp_dir()`), usualmente `/tmp`.

Configuración (.env)
--------------------
Crea un archivo `.env` en el mismo directorio con las variables siguientes (no pongas secretos en repositorios públicos):

```
S3_KEY=TU_ACCESS_KEY
S3_SECRET=TU_SECRET_KEY
S3_ENDPOINT=https://hel1.your-objectstorage.com
S3_BUCKET=nombre-de-tu-bucket
S3_REGION=us-east-1
CDN_DOMAIN=                           # opcional, ejemplo: https://mi-cdn.example.com

# Opciones de procesamiento
DESIRED_FORMAT=webp
MAX_IMAGE_WIDTH=1200
MAX_UPLOAD_BYTES=52428800

# Subida S3 defaults
S3_ACL=public-read
S3_CACHE_CONTROL=max-age=31536000, public
```

Asegúrate de reemplazar con tus credenciales y el nombre del bucket. Si más tarde agregas un CDN, pon su dominio en `CDN_DOMAIN`.

Uso: endpoints y ejemplos
-------------------------

Endpoint principal:
- `central_endpoint.php?action=setFile&key=<client_key>&filename=<original_name>`
  - Método: POST
  - Body:
    - raw binary (ej. `curl --data-binary "@/ruta/archivo.jpg" "https://tu.api/...&key=abc&filename=foto.jpg"`)
    - o `multipart/form-data` con campo `file` (ej. `curl -F "file=@/ruta/archivo.jpg" "https://tu.api/...&key=abc"`)

Respuesta JSON de éxito:
```json
{
  "success": true,
  "url": "https://hel1.your-objectstorage.com/your-bucket/uploads/2025/10/abcdef1234.webp",
  "key": "uploads/2025/10/abcdef1234.webp"
}
```

Ejemplos prácticos

1) Subida raw via curl (body con contenido):
```bash
curl --data-binary "@/home/usuario/foto.jpg" \
  "https://tu.api/central_endpoint.php?action=setFile&key=cliente123&filename=foto.jpg"
```

2) Subida multipart/form-data via curl:
```bash
curl -F "file=@/home/usuario/foto.jpg" \
  "https://tu.api/central_endpoint.php?action=setFile&key=cliente123&filename=foto.jpg"
```

3) Prueba desde PHP (cliente simple):
```php
$client = new ApiClient('https://tu.api/central_endpoint.php'); // si usas ApiClient
$ok = $client->sendFile('cliente123', '/path/to/foto.jpg');
```

Cómo funciona internamente
-------------------------
1. central_endpoint recibe la petición:
   - Si es multipart, usa `$_FILES['file']`.
   - Si es raw, lee `php://input` y lo escribe a un archivo temporal.
2. ImageProcessor intenta procesar la imagen:
   - Si `imagick` está instalado lo usa para redimensionar y convertir (preferible).
   - Si no, usa GD (menos eficiente).
   - Output: archivo temporal procesado (por ejemplo `.webp`) o `null` si no es imagen.
3. CentralStorageS3 sube el archivo procesado (o el original si no se procesó) al bucket:
   - Genera una key como: `uploads/YYYY/MM/<uuid>.<ext>`
   - Sube por streaming usando `SourceFile` del SDK.
   - Devuelve una URL pública construida con `CDN_DOMAIN` (si está configurado) o usando `S3_ENDPOINT/bucket/key`.
4. central_endpoint elimina temporales y devuelve JSON con la URL y la key.

Preview y uso en frontend
------------------------
- Usa la `url` devuelta para mostrar la imagen en `<img src="...">` o para incrustar el PDF.
- Si más adelante añades CDN (pull), configura `CDN_DOMAIN` y las URLs devueltas usarán el dominio del CDN.
- Si el objeto es público (ACL `public-read`) el navegador puede hacer GET directo a la URL. Si lo quieres privado, usa presigned URLs (opcional).

Migración de archivos existentes
-------------------------------
Si tienes muchos archivos locales (`central_storage/`), crea un script de migración que:
- Recorra los archivos,
- Suba cada archivo al bucket (generando key),
- Guarde mapping (local_key -> object_key / url) en base de datos,
- Borre o archive el archivo local si la subida fue exitosa.

Consideraciones de coste
------------------------
- Hetzner Object Storage cobra por almacenamiento y por egress (transfer out).
- Sin CDN: cada petición de usuarios hará egress desde Hetzner (puede aumentar coste).
- Con CDN: la mayoría del tráfico se sirve desde el CDN, reduciendo coste de origen y mejorando latencia global.
- Recomendación: si tráfico es alto o usuarios globales, añade CDN (Bunny/Cloudflare) más adelante.

Seguridad y buenas prácticas
----------------------------
- Nunca subas las credenciales al repositorio.
- Usa `.env` y permisos de fichero restrictivos.
- Genera nombres de objeto no predecibles (UUIDs) para evitar enumeración.
- Si necesitas restringir acceso, usa objetos privados + presigned URLs para GET.
- Considera políticas de lifecycle del bucket para limpieza automática (delete after N days).

Depuración y resolución de errores
----------------------------------
- Si obtienes HTTP 500:
  - Revisa logs del worker / PHP-FPM o el log que implementes.
  - Asegúrate de que `S3_KEY`, `S3_SECRET`, `S3_ENDPOINT`, `S3_BUCKET` estén configurados.
  - Prueba conexión S3 desde CLI o con un pequeño script que haga `listBuckets()` o `putObject` a un key de prueba.
- Si la subida falla por tiempo:
  - Revisa `MAX_UPLOAD_BYTES`, `post_max_size` y `upload_max_filesize` en `php.ini`.
- Si `imagick` falla:
  - Verifica que la extensión exista: `php -m | grep imagick`.
  - Si no, el código usa GD como fallback (menos eficiente).

Ejemplo de prueba para S3 (CLI)
-------------------------------
Puedes probar con `curl` y con `aws-cli` (configurando `--endpoint-url`):

```bash
# listar buckets con aws-cli si lo instalas (ejemplo)
aws --endpoint-url=https://hel1.your-objectstorage.com s3 ls
```

Checklist de despliegue
-----------------------
1. Copiar archivos al proyecto (`CentralStorageS3.php`, `ImageProcessor.php`, `central_endpoint.php`).
2. Instalar dependencias composer.
3. Crear `.env` con credenciales y bucket.
4. Instalar `imagick` si deseas (opcional).
5. Asegurar permisos de temp y reiniciar PHP/roadrunner.
6. Probar con `curl` o `ApiClient`.

Qué hice por ti (en esta entrega)
---------------------------------
- Preparé el README completo y detallado con pasos prácticos, ejemplos curl y notas de depuración.
- Diseñé el flujo recomendado (procesamiento → subida S3 → devolución de URL).
- Incluí recomendaciones operativas (CDN opcional, lifecycle, presigned uploads).

Qué sigue (si quieres)
----------------------
- Puedo generar un script de migración listo para ejecutar que mueva todos los archivos locales al bucket y borre los originales.
- Puedo añadir un endpoint adicional para generar presigned PUT/POST si quieres permitir uploads directos desde browser.
- Puedo adaptar central_endpoint para almacenar metadata en una base de datos (MySQL/Postgres) automáticamente.

Si quieres que prepare alguno de los siguientes ahora, dime cuál:
- A) Script de migración masiva (local → S3) listo para ejecutar.
- B) Endpoint para generar presigned URL (PUT) y ejemplo JS para subir directo desde browser.
- C) Añadir almacenamiento de metadata en SQLite/MySQL para cada upload.
```