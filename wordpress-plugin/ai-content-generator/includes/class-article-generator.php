<?php
/**
 * Generador de artículos
 *
 * @package AI_Content_Generator
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para generar artículos usando IA
 */
class AICG_Article_Generator {

    /**
     * Proveedor de IA
     *
     * @var AICG_AI_Provider_Interface
     */
    private $provider;

    /**
     * Proveedor de imágenes
     *
     * @var AICG_AI_Provider_Interface
     */
    private $image_provider;

    /**
     * Procesador de imágenes
     *
     * @var AICG_Image_Processor
     */
    private $image_processor;

    /**
     * Constructor
     */
    public function __construct() {
        $this->provider = AICG_AI_Provider_Factory::get_text_provider();
        $this->image_provider = AICG_AI_Provider_Factory::get_image_provider();
        $this->image_processor = new AICG_Image_Processor();
    }

    /**
     * Generar artículo completo
     *
     * @param array $args Argumentos de generación
     * @return array|WP_Error Resultado con post_id, o WP_Error
     */
    public function generate($args = array()) {
        $defaults = array(
            'topic' => '',
            'category_id' => 0,
            'post_status' => 'draft',
            'generate_image' => true,
            'min_words' => get_option('aicg_article_min_words', 1500),
            'max_words' => get_option('aicg_article_max_words', 2000),
            'sections' => get_option('aicg_article_sections', 4),
            'temperature' => 0.7
        );

        $args = wp_parse_args($args, $defaults);

        // Validar proveedor
        if (is_wp_error($this->provider)) {
            return $this->provider;
        }

        if (!$this->provider->is_configured()) {
            return new WP_Error('provider_not_configured', __('Proveedor de IA no configurado', 'ai-content-generator'));
        }

        // Validar tema
        if (empty($args['topic'])) {
            $topics = get_option('aicg_article_topics', array());
            if (empty($topics)) {
                return new WP_Error('no_topic', __('No se especificó tema y no hay temas configurados', 'ai-content-generator'));
            }
            $args['topic'] = $topics[array_rand($topics)];
        }

        $result = array(
            'topic' => $args['topic'],
            'title' => '',
            'content' => '',
            'image_id' => 0,
            'post_id' => 0,
            'tokens_used' => 0,
            'cost' => 0
        );

        try {
            // Paso 1: Generar título
            $title_result = $this->generate_title($args['topic'], $args['temperature']);
            if (is_wp_error($title_result)) {
                return $title_result;
            }
            $result['title'] = $title_result['title'];
            $result['tokens_used'] += $title_result['usage']['total_tokens'];
            $result['cost'] += $title_result['cost'];

            // Paso 2: Generar contenido
            $content_result = $this->generate_content(
                $args['topic'],
                $result['title'],
                $args['min_words'],
                $args['max_words'],
                $args['sections'],
                $args['temperature']
            );
            if (is_wp_error($content_result)) {
                return $content_result;
            }
            $result['content'] = $content_result['content'];
            $result['tokens_used'] += $content_result['usage']['total_tokens'];
            $result['cost'] += $content_result['cost'];

            // Paso 3: Generar imagen (opcional)
            if ($args['generate_image']) {
                $image_result = $this->generate_and_upload_image($result['title'], $args['topic']);
                if (!is_wp_error($image_result)) {
                    $result['image_id'] = $image_result['attachment_id'];
                    $result['cost'] += $image_result['cost'];
                }
            }

            // Paso 4: Crear post en WordPress
            $post_result = $this->create_post($result, $args);
            if (is_wp_error($post_result)) {
                return $post_result;
            }
            $result['post_id'] = $post_result;

            // Registrar en historial
            $this->log_generation($result, $args);

            return $result;

        } catch (Exception $e) {
            return new WP_Error('generation_error', $e->getMessage());
        }
    }

    /**
     * Generar título
     *
     * @param string $topic
     * @param float  $temperature
     * @return array|WP_Error
     */
    private function generate_title($topic, $temperature = 0.7) {
        $prompt = sprintf(
            'Genera un título atractivo, conciso y profesional para un artículo sobre "%s". ' .
            'El título debe ser claro, informativo y captar la atención del lector. ' .
            'Responde SOLO con el título, sin comillas ni explicaciones adicionales.',
            $topic
        );

        $result = $this->provider->generate_text($prompt, array(
            'max_tokens' => 100,
            'temperature' => $temperature,
            'system_message' => 'Eres un experto en crear títulos atractivos para artículos. Respondes solo con el título solicitado.'
        ));

        if (is_wp_error($result)) {
            return $result;
        }

        return array(
            'title' => trim(str_replace(array('"', "'"), '', $result['content'])),
            'usage' => $result['usage'],
            'cost' => $result['cost']
        );
    }

    /**
     * Generar contenido
     *
     * @param string $topic
     * @param string $title
     * @param int    $min_words
     * @param int    $max_words
     * @param int    $sections
     * @param float  $temperature
     * @return array|WP_Error
     */
    private function generate_content($topic, $title, $min_words, $max_words, $sections, $temperature = 0.3) {
        $prompt = sprintf(
            'Escribe un artículo completo con el título "%s" sobre el tema "%s".

Requisitos:
- Longitud: entre %d y %d palabras
- Mínimo %d secciones con subtítulos
- Incluir un párrafo introductorio atractivo
- Contenido informativo, bien estructurado y original
- Tono profesional pero accesible

Formato HTML requerido:
- Usar <h2> para títulos de sección (NO incluir el título principal)
- Usar <p> para párrafos
- Usar <strong> para énfasis importante
- Usar <ul> y <li> para listas cuando sea apropiado
- NO incluir <!DOCTYPE>, <html>, <head>, <body> ni bloques de código
- NO usar Markdown, solo HTML puro

Escribe directamente el contenido HTML sin explicaciones adicionales.',
            $title,
            $topic,
            $min_words,
            $max_words,
            $sections
        );

        $result = $this->provider->generate_text($prompt, array(
            'max_tokens' => 4000,
            'temperature' => $temperature,
            'system_message' => 'Eres un escritor experto que genera contenido de alta calidad en español, formateado en HTML limpio.'
        ));

        if (is_wp_error($result)) {
            return $result;
        }

        // Limpiar contenido
        $content = $this->clean_html_content($result['content']);

        return array(
            'content' => $content,
            'usage' => $result['usage'],
            'cost' => $result['cost']
        );
    }

    /**
     * Limpiar contenido HTML
     *
     * @param string $content
     * @return string
     */
    private function clean_html_content($content) {
        // Eliminar bloques de código markdown
        $content = preg_replace('/```html\s*/i', '', $content);
        $content = preg_replace('/```\s*/', '', $content);

        // Eliminar elementos HTML no deseados
        $content = preg_replace('/<\!DOCTYPE[^>]*>/i', '', $content);
        $content = preg_replace('/<\/?html[^>]*>/i', '', $content);
        $content = preg_replace('/<\/?head[^>]*>.*?<\/head>/is', '', $content);
        $content = preg_replace('/<\/?body[^>]*>/i', '', $content);
        $content = preg_replace('/<meta[^>]*>/i', '', $content);
        $content = preg_replace('/<title[^>]*>.*?<\/title>/is', '', $content);

        // Asegurar que empiece con párrafo o heading
        $content = trim($content);
        if (!preg_match('/^<(p|h[1-6]|div|ul|ol)/i', $content)) {
            $content = '<p>' . $content . '</p>';
        }

        return $content;
    }

    /**
     * Generar y subir imagen
     *
     * @param string $title
     * @param string $topic
     * @return array|WP_Error
     */
    private function generate_and_upload_image($title, $topic) {
        if (is_wp_error($this->image_provider)) {
            return $this->image_provider;
        }

        // Generar imagen con prompt configurable
        $default_prompt = 'Una imagen creativa, profesional y visualmente atractiva relacionada con "{topic}". Estilo: ilustración digital moderna o fotografía artística. Colores vibrantes pero profesionales. Sin texto ni logos.';
        $prompt_template = get_option('aicg_article_image_prompt', $default_prompt);
        $prompt = str_replace('{topic}', $topic, $prompt_template);

        $image_result = $this->image_provider->generate_image($prompt, array(
            'size' => '1024x1024',
            'quality' => 'standard',
            'style' => 'natural'
        ));

        if (is_wp_error($image_result)) {
            return $image_result;
        }

        // Descargar imagen
        $image_data = $this->image_processor->download_image($image_result['url']);
        if (is_wp_error($image_data)) {
            return $image_data;
        }

        // Aplicar watermark si está habilitado
        if (get_option('aicg_watermark_enabled', false)) {
            $watermark_id = get_option('aicg_watermark_image', 0);
            if ($watermark_id) {
                $image_data = $this->image_processor->apply_watermark($image_data, $watermark_id);
            }
        }

        // Subir a WordPress
        $attachment_id = $this->image_processor->upload_to_media_library(
            $image_data,
            sanitize_title($title) . '.png',
            $title
        );

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        return array(
            'attachment_id' => $attachment_id,
            'cost' => $image_result['cost']
        );
    }

    /**
     * Crear post en WordPress
     *
     * @param array $result
     * @param array $args
     * @return int|WP_Error
     */
    private function create_post($result, $args) {
        // Determinar el autor
        $post_author = get_current_user_id();
        if (!empty($args['post_author']) && $args['post_author'] > 0) {
            $post_author = intval($args['post_author']);
        } else {
            // Verificar si hay un autor por defecto configurado
            $default_author = get_option('aicg_default_author', 0);
            if ($default_author > 0) {
                $post_author = intval($default_author);
            }
        }

        // Convertir contenido a formato Gutenberg si está configurado
        $content = $result['content'];
        $content_format = get_option('aicg_content_format', 'gutenberg');
        if ($content_format === 'gutenberg' && class_exists('AICG_Gutenberg_Converter')) {
            $content = AICG_Gutenberg_Converter::convert($content);
        }

        $post_data = array(
            'post_title'   => $result['title'],
            'post_content' => $content,
            'post_status'  => $args['post_status'],
            'post_type'    => 'post',
            'post_author'  => $post_author
        );

        // Categoría
        if ($args['category_id']) {
            $post_data['post_category'] = array($args['category_id']);
        } else {
            // Crear o usar categoría basada en el tema
            $category = get_term_by('name', $args['topic'], 'category');
            if (!$category) {
                $cat_id = wp_create_category($args['topic']);
                if (!is_wp_error($cat_id)) {
                    $post_data['post_category'] = array($cat_id);
                }
            } else {
                $post_data['post_category'] = array($category->term_id);
            }
        }

        // Crear post
        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Asignar imagen destacada
        if ($result['image_id']) {
            set_post_thumbnail($post_id, $result['image_id']);
        }

        // Generar tags
        $tags = $this->generate_tags($result['title'], $args['topic']);
        if (!empty($tags)) {
            wp_set_post_tags($post_id, $tags);
        }

        // Meta datos
        update_post_meta($post_id, '_aicg_generated', true);
        update_post_meta($post_id, '_aicg_topic', $args['topic']);
        update_post_meta($post_id, '_aicg_provider', $this->provider->get_name());
        update_post_meta($post_id, '_aicg_tokens', $result['tokens_used']);
        update_post_meta($post_id, '_aicg_cost', $result['cost']);

        return $post_id;
    }

    /**
     * Generar tags a partir del título y tema
     *
     * @param string $title
     * @param string $topic
     * @return array
     */
    private function generate_tags($title, $topic) {
        $stopwords = array(
            'el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas',
            'de', 'del', 'al', 'a', 'en', 'con', 'por', 'para',
            'que', 'como', 'desde', 'entre', 'esto', 'esta', 'estos', 'estas',
            'y', 'o', 'pero', 'sin', 'sobre', 'hasta', 'donde', 'cuando'
        );

        $tags = array($topic);

        // Extraer palabras del título
        $words = preg_split('/\s+/', strtolower($title));
        foreach ($words as $word) {
            $word = trim($word, '.,;:!?¿¡"\'()[]{}');
            if (strlen($word) > 3 && !in_array($word, $stopwords)) {
                $tags[] = ucfirst($word);
            }
        }

        return array_unique(array_slice($tags, 0, 5));
    }

    /**
     * Registrar generación en historial
     *
     * @param array $result
     * @param array $args
     */
    private function log_generation($result, $args) {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'aicg_history',
            array(
                'type' => 'article',
                'post_id' => $result['post_id'],
                'provider' => $this->provider->get_name(),
                'model' => get_option('aicg_default_model', 'gpt-4o'),
                'topic' => $args['topic'],
                'tokens_used' => $result['tokens_used'],
                'cost' => $result['cost'],
                'created_at' => current_time('mysql')
            ),
            array('%s', '%d', '%s', '%s', '%s', '%d', '%f', '%s')
        );
    }
}
