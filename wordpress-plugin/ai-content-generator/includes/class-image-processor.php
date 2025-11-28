<?php
/**
 * Procesador de imágenes
 *
 * @package AI_Content_Generator
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para procesar imágenes
 */
class AICG_Image_Processor {

    /**
     * Descargar imagen desde URL o decodificar desde data URL base64
     *
     * @param string $url URL de la imagen o data URL (data:image/png;base64,...)
     * @return string|WP_Error Datos binarios de la imagen o error
     */
    public function download_image($url) {
        // Verificar si es un data URL base64
        if (strpos($url, 'data:image/') === 0) {
            return $this->decode_base64_image($url);
        }

        // URL HTTP/HTTPS normal
        $response = wp_remote_get($url, array(
            'timeout' => 60,
            'headers' => array(
                'User-Agent' => 'Mozilla/5.0 (compatible; WordPress/' . get_bloginfo('version') . ')'
            )
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return new WP_Error(
                'download_error',
                sprintf(__('Error al descargar imagen: HTTP %d', 'ai-content-generator'), $status_code)
            );
        }

        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (strpos($content_type, 'image') === false) {
            return new WP_Error(
                'invalid_content',
                __('El contenido descargado no es una imagen', 'ai-content-generator')
            );
        }

        return wp_remote_retrieve_body($response);
    }

    /**
     * Decodificar imagen desde data URL base64
     *
     * @param string $data_url Data URL en formato data:image/TYPE;base64,DATA
     * @return string|WP_Error Datos binarios de la imagen o error
     */
    public function decode_base64_image($data_url) {
        // Formato esperado: data:image/png;base64,iVBORw0KGgo...
        if (!preg_match('/^data:image\/([a-zA-Z0-9+]+);base64,(.+)$/', $data_url, $matches)) {
            return new WP_Error(
                'invalid_data_url',
                __('Formato de data URL inválido', 'ai-content-generator')
            );
        }

        $mime_type = $matches[1];
        $base64_data = $matches[2];

        // Decodificar base64
        $image_data = base64_decode($base64_data, true);

        if ($image_data === false) {
            return new WP_Error(
                'base64_decode_error',
                __('Error al decodificar datos base64', 'ai-content-generator')
            );
        }

        // Verificar que los datos son una imagen válida
        if (strlen($image_data) < 8) {
            return new WP_Error(
                'invalid_image_data',
                __('Los datos de imagen son demasiado pequeños', 'ai-content-generator')
            );
        }

        // Verificar firma de archivo de imagen
        $header = substr($image_data, 0, 8);
        $is_png = (substr($header, 0, 4) === "\x89PNG");
        $is_jpeg = (substr($header, 0, 2) === "\xFF\xD8");
        $is_gif = (substr($header, 0, 3) === "GIF");
        $is_webp = (substr($header, 0, 4) === "RIFF" && substr($image_data, 8, 4) === "WEBP");

        if (!$is_png && !$is_jpeg && !$is_gif && !$is_webp) {
            error_log('[AICG] Image header bytes: ' . bin2hex(substr($header, 0, 4)));
            return new WP_Error(
                'invalid_image_format',
                __('El formato de imagen no es válido (esperado PNG, JPEG, GIF o WebP)', 'ai-content-generator')
            );
        }

        error_log('[AICG] Successfully decoded base64 image: ' . strlen($image_data) . ' bytes, type: ' . $mime_type);

        return $image_data;
    }

    /**
     * Obtener extensión de archivo desde data URL
     *
     * @param string $data_url Data URL
     * @return string Extensión del archivo (png, jpg, gif, webp)
     */
    public function get_extension_from_data_url($data_url) {
        if (preg_match('/^data:image\/([a-zA-Z0-9+]+);base64,/', $data_url, $matches)) {
            $mime = strtolower($matches[1]);
            switch ($mime) {
                case 'jpeg':
                case 'jpg':
                    return 'jpg';
                case 'png':
                    return 'png';
                case 'gif':
                    return 'gif';
                case 'webp':
                    return 'webp';
                default:
                    return 'png';
            }
        }
        return 'png';
    }

    /**
     * Aplicar marca de agua a imagen
     *
     * @param string $image_data Datos binarios de la imagen
     * @param int    $watermark_id ID del attachment de la marca de agua
     * @return string|WP_Error Datos de la imagen con marca de agua
     */
    public function apply_watermark($image_data, $watermark_id) {
        // Verificar que GD está disponible
        if (!function_exists('imagecreatefrompng')) {
            return new WP_Error('gd_not_available', __('La extensión GD no está disponible', 'ai-content-generator'));
        }

        // Obtener ruta de la marca de agua
        $watermark_path = get_attached_file($watermark_id);
        if (!$watermark_path || !file_exists($watermark_path)) {
            return $image_data; // Retornar imagen original si no hay watermark
        }

        // Crear imagen desde datos
        $image = @imagecreatefromstring($image_data);
        if (!$image) {
            return new WP_Error('image_create_error', __('No se pudo crear la imagen', 'ai-content-generator'));
        }

        // Cargar marca de agua
        $mime_type = mime_content_type($watermark_path);
        $watermark = null;

        switch ($mime_type) {
            case 'image/png':
                $watermark = @imagecreatefrompng($watermark_path);
                break;
            case 'image/jpeg':
            case 'image/jpg':
                $watermark = @imagecreatefromjpeg($watermark_path);
                break;
            case 'image/gif':
                $watermark = @imagecreatefromgif($watermark_path);
                break;
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $watermark = @imagecreatefromwebp($watermark_path);
                }
                break;
        }

        if (!$watermark) {
            imagedestroy($image);
            return $image_data; // Retornar original si no se puede cargar watermark
        }

        // Obtener dimensiones
        $image_width = imagesx($image);
        $image_height = imagesy($image);
        $watermark_width = imagesx($watermark);
        $watermark_height = imagesy($watermark);

        // Calcular tamaño del watermark (10% de la altura de la imagen)
        $new_height = (int) ($image_height * 0.1);
        $new_width = (int) ($watermark_width * ($new_height / $watermark_height));

        // Redimensionar watermark
        $resized_watermark = imagecreatetruecolor($new_width, $new_height);
        imagealphablending($resized_watermark, false);
        imagesavealpha($resized_watermark, true);
        $transparent = imagecolorallocatealpha($resized_watermark, 0, 0, 0, 127);
        imagefill($resized_watermark, 0, 0, $transparent);

        imagecopyresampled(
            $resized_watermark,
            $watermark,
            0, 0, 0, 0,
            $new_width, $new_height,
            $watermark_width, $watermark_height
        );

        // Posición: esquina inferior derecha
        $position_x = $image_width - $new_width - 10;
        $position_y = $image_height - $new_height - 10;

        // Activar alpha blending en la imagen principal
        imagealphablending($image, true);

        // Aplicar watermark
        imagecopy(
            $image,
            $resized_watermark,
            $position_x, $position_y,
            0, 0,
            $new_width, $new_height
        );

        // Guardar a buffer
        ob_start();
        imagepng($image, null, 9);
        $result = ob_get_clean();

        // Limpiar memoria
        imagedestroy($image);
        imagedestroy($watermark);
        imagedestroy($resized_watermark);

        return $result;
    }

    /**
     * Subir imagen a la biblioteca de medios
     *
     * @param string $image_data Datos binarios de la imagen
     * @param string $filename Nombre del archivo
     * @param string $title Título del attachment
     * @return int|WP_Error ID del attachment o error
     */
    public function upload_to_media_library($image_data, $filename, $title = '') {
        // Asegurar que el nombre de archivo es seguro
        $filename = sanitize_file_name($filename);
        if (!preg_match('/\.(png|jpg|jpeg|gif|webp)$/i', $filename)) {
            $filename .= '.png';
        }

        // Crear directorio de uploads si no existe
        $upload_dir = wp_upload_dir();
        if ($upload_dir['error']) {
            return new WP_Error('upload_dir_error', $upload_dir['error']);
        }

        // Generar nombre único
        $unique_filename = wp_unique_filename($upload_dir['path'], $filename);
        $file_path = $upload_dir['path'] . '/' . $unique_filename;

        // Guardar archivo
        $saved = file_put_contents($file_path, $image_data);
        if ($saved === false) {
            return new WP_Error('file_save_error', __('No se pudo guardar el archivo', 'ai-content-generator'));
        }

        // Obtener tipo MIME
        $filetype = wp_check_filetype($unique_filename, null);

        // Preparar attachment
        $attachment = array(
            'post_mime_type' => $filetype['type'],
            'post_title' => $title ?: pathinfo($unique_filename, PATHINFO_FILENAME),
            'post_content' => '',
            'post_status' => 'inherit'
        );

        // Insertar attachment
        $attachment_id = wp_insert_attachment($attachment, $file_path);

        if (is_wp_error($attachment_id)) {
            @unlink($file_path);
            return $attachment_id;
        }

        // Generar metadata
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $metadata);

        // Añadir alt text
        if ($title) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $title);
        }

        return $attachment_id;
    }

    /**
     * Redimensionar imagen
     *
     * @param string $image_data Datos de la imagen
     * @param int    $max_width Ancho máximo
     * @param int    $max_height Alto máximo
     * @return string|WP_Error Imagen redimensionada
     */
    public function resize($image_data, $max_width, $max_height) {
        if (!function_exists('imagecreatefrompng')) {
            return new WP_Error('gd_not_available', __('La extensión GD no está disponible', 'ai-content-generator'));
        }

        $image = @imagecreatefromstring($image_data);
        if (!$image) {
            return new WP_Error('image_create_error', __('No se pudo crear la imagen', 'ai-content-generator'));
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // Calcular nuevas dimensiones manteniendo proporción
        $ratio = min($max_width / $width, $max_height / $height);

        if ($ratio >= 1) {
            imagedestroy($image);
            return $image_data; // No necesita redimensionar
        }

        $new_width = (int) ($width * $ratio);
        $new_height = (int) ($height * $ratio);

        // Crear imagen redimensionada
        $resized = imagecreatetruecolor($new_width, $new_height);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        imagecopyresampled(
            $resized,
            $image,
            0, 0, 0, 0,
            $new_width, $new_height,
            $width, $height
        );

        // Guardar a buffer
        ob_start();
        imagepng($resized, null, 9);
        $result = ob_get_clean();

        imagedestroy($image);
        imagedestroy($resized);

        return $result;
    }

    /**
     * Optimizar imagen
     *
     * @param string $image_data Datos de la imagen
     * @param int    $quality Calidad (0-100)
     * @return string Imagen optimizada
     */
    public function optimize($image_data, $quality = 85) {
        if (!function_exists('imagecreatefrompng')) {
            return $image_data;
        }

        $image = @imagecreatefromstring($image_data);
        if (!$image) {
            return $image_data;
        }

        ob_start();
        imagejpeg($image, null, $quality);
        $result = ob_get_clean();

        imagedestroy($image);

        return $result;
    }

    /**
     * Convertir SVG a PNG
     *
     * Requiere la extensión Imagick o la biblioteca CairoSVG
     *
     * @param string $svg_path Ruta al archivo SVG
     * @return string|WP_Error Datos PNG o error
     */
    public function svg_to_png($svg_path) {
        if (!file_exists($svg_path)) {
            return new WP_Error('file_not_found', __('Archivo SVG no encontrado', 'ai-content-generator'));
        }

        // Intentar con Imagick
        if (extension_loaded('imagick')) {
            try {
                $imagick = new Imagick();
                $imagick->readImage($svg_path);
                $imagick->setImageFormat('png');
                $result = $imagick->getImageBlob();
                $imagick->destroy();
                return $result;
            } catch (Exception $e) {
                // Continuar con alternativa
            }
        }

        // Alternativa: usar wp_get_image_editor
        $editor = wp_get_image_editor($svg_path);
        if (is_wp_error($editor)) {
            return $editor;
        }

        // Crear archivo temporal
        $temp_file = wp_tempnam('svg_to_png_') . '.png';
        $saved = $editor->save($temp_file, 'image/png');

        if (is_wp_error($saved)) {
            return $saved;
        }

        $result = file_get_contents($saved['path']);
        @unlink($saved['path']);

        return $result;
    }
}
