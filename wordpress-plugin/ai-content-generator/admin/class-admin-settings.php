<?php
/**
 * Configuración del panel de administración
 *
 * @package AI_Content_Generator
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para manejar la configuración del plugin
 */
class AICG_Admin_Settings {

    /**
     * Añadir menú de administración
     */
    public function add_admin_menu() {
        // Menú principal
        add_menu_page(
            __('AI Content Generator', 'ai-content-generator'),
            __('AI Content', 'ai-content-generator'),
            'manage_options',
            'aicg-dashboard',
            array($this, 'render_dashboard_page'),
            'dashicons-edit-page',
            30
        );

        // Submenús
        add_submenu_page(
            'aicg-dashboard',
            __('Dashboard', 'ai-content-generator'),
            __('Dashboard', 'ai-content-generator'),
            'manage_options',
            'aicg-dashboard',
            array($this, 'render_dashboard_page')
        );

        add_submenu_page(
            'aicg-dashboard',
            __('Generar Artículo', 'ai-content-generator'),
            __('Generar Artículo', 'ai-content-generator'),
            'manage_options',
            'aicg-generate-article',
            array($this, 'render_generate_article_page')
        );

        add_submenu_page(
            'aicg-dashboard',
            __('Generar Noticias', 'ai-content-generator'),
            __('Generar Noticias', 'ai-content-generator'),
            'manage_options',
            'aicg-generate-news',
            array($this, 'render_generate_news_page')
        );

        add_submenu_page(
            'aicg-dashboard',
            __('Configuración', 'ai-content-generator'),
            __('Configuración', 'ai-content-generator'),
            'manage_options',
            'aicg-settings',
            array($this, 'render_settings_page')
        );

        add_submenu_page(
            'aicg-dashboard',
            __('Historial', 'ai-content-generator'),
            __('Historial', 'ai-content-generator'),
            'manage_options',
            'aicg-history',
            array($this, 'render_history_page')
        );
    }

    /**
     * Registrar configuraciones
     */
    public function register_settings() {
        // Nota: la página de configuración usa una vista manual
        // (admin/views/settings.php), no do_settings_sections(). Aquí solo se
        // registran las opciones para el whitelist y la sanitización.

        // Proveedor principal
        register_setting('aicg-settings', 'aicg_ai_provider', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'openai'
        ));

        // API Keys
        $api_keys = array(
            'openai' => 'OpenAI',
            'anthropic' => 'Anthropic',
            'deepseek' => 'DeepSeek',
            'openrouter' => 'OpenRouter'
        );

        foreach ($api_keys as $key => $name) {
            $option_name = 'aicg_' . $key . '_api_key';
            register_setting('aicg-settings', $option_name, array(
                'type' => 'string',
                'sanitize_callback' => function($value) use ($option_name) {
                    return AICG_Admin_Settings::sanitize_api_key($value, $option_name);
                },
                'default' => ''
            ));
        }

        register_setting('aicg-settings', 'aicg_default_model', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'gpt-4o'
        ));

        register_setting('aicg-settings', 'aicg_image_provider', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'openai'
        ));

        register_setting('aicg-settings', 'aicg_image_model', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'dall-e-3'
        ));

        // Artículos
        $article_settings = array(
            'aicg_article_min_words' => array('label' => __('Mínimo de palabras', 'ai-content-generator'), 'default' => 1500),
            'aicg_article_max_words' => array('label' => __('Máximo de palabras', 'ai-content-generator'), 'default' => 2000),
            'aicg_article_sections' => array('label' => __('Número de secciones', 'ai-content-generator'), 'default' => 4)
        );

        foreach ($article_settings as $key => $config) {
            register_setting('aicg-settings', $key, array(
                'type' => 'integer',
                'sanitize_callback' => 'absint',
                'default' => $config['default']
            ));
        }

        // Temas de artículos
        register_setting('aicg-settings', 'aicg_article_topics', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_topics'),
            'default' => array()
        ));

        // Noticias
        register_setting('aicg-settings', 'aicg_news_topics', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_news_topics'),
            'default' => array()
        ));

        // Fuentes RSS principales
        register_setting('aicg-settings', 'aicg_news_sources', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_news_sources'),
            'default' => array()
        ));

        // Plantilla de búsqueda para temas
        register_setting('aicg-settings', 'aicg_news_search_template', array(
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => 'https://news.google.com/rss/search?q={topic}&hl=es-419&gl=MX&ceid=MX:es-419'
        ));

        // Tipo de publicación para noticias
        register_setting('aicg-settings', 'aicg_news_post_type', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'post'
        ));

        // Formato de contenido (Gutenberg o clásico)
        register_setting('aicg-settings', 'aicg_content_format', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'gutenberg'
        ));

        // Imagen destacada fija para noticias
        register_setting('aicg-settings', 'aicg_news_featured_image', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 0
        ));

        // Generar imagen con IA para resumen de noticias
        register_setting('aicg-settings', 'aicg_news_generate_image', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false
        ));

        // Fuentes de imagen (esquema híbrido)
        register_setting('aicg-settings', 'aicg_image_source_og', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true
        ));

        register_setting('aicg-settings', 'aicg_image_source_map', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true
        ));

        register_setting('aicg-settings', 'aicg_image_source_ai', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true
        ));

        // Carrusel de imágenes por tema
        register_setting('aicg-settings', 'aicg_news_carousel_enabled', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true
        ));

        // Estilo de referencias
        register_setting('aicg-settings', 'aicg_reference_style', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'inline'
        ));

        register_setting('aicg-settings', 'aicg_reference_color', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default' => '#0073aa'
        ));

        register_setting('aicg-settings', 'aicg_reference_orientation', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'horizontal'
        ));

        register_setting('aicg-settings', 'aicg_reference_size', array(
            'type' => 'integer',
            'sanitize_callback' => array($this, 'sanitize_reference_size'),
            'default' => 24
        ));

        // Actualizar post existente
        register_setting('aicg-settings', 'aicg_news_update_existing', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false
        ));

        register_setting('aicg-settings', 'aicg_news_target_post', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 0
        ));

        // Prompts personalizables
        register_setting('aicg-settings', 'aicg_news_system_prompt', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => 'Eres un periodista experto que resume noticias de forma objetiva y precisa. Usas HTML puro, nunca Markdown.'
        ));

        register_setting('aicg-settings', 'aicg_news_user_prompt', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => ''
        ));

        // Imágenes
        // Tamaño de imagen
        register_setting('aicg-settings', 'aicg_image_size', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '1792x1024'
        ));

        // Calidad de imagen
        register_setting('aicg-settings', 'aicg_image_quality', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'standard'
        ));

        register_setting('aicg-settings', 'aicg_watermark_enabled', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false
        ));

        register_setting('aicg-settings', 'aicg_watermark_image', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 0
        ));

        register_setting('aicg-settings', 'aicg_article_image_prompt', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => 'Una imagen creativa, profesional y visualmente atractiva relacionada con "{topic}". Estilo: ilustración digital moderna o fotografía artística. Colores vibrantes pero profesionales. Sin texto ni logos.'
        ));

        register_setting('aicg-settings', 'aicg_news_image_prompt', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => 'Create a professional news header image that represents these headlines from today: {headlines}. Style: Modern news media, clean design, abstract representation of news themes. Do NOT include any text or words in the image. Use a color palette suitable for a news website.'
        ));

        // Programación
        register_setting('aicg-settings', 'aicg_schedule_articles', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false
        ));

        register_setting('aicg-settings', 'aicg_schedule_articles_frequency', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'daily'
        ));

        register_setting('aicg-settings', 'aicg_schedule_news', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false
        ));

        register_setting('aicg-settings', 'aicg_schedule_news_frequency', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'twicedaily'
        ));

        register_setting('aicg-settings', 'aicg_schedule_news_time', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_time_field'),
            'default' => '08:00'
        ));

        register_setting('aicg-settings', 'aicg_schedule_post_status', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_post_status_field'),
            'default' => 'draft'
        ));

        register_setting('aicg-settings', 'aicg_default_author', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 0
        ));

        // Notificaciones por email de la generación programada
        register_setting('aicg-settings', 'aicg_notify_admin', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false
        ));

        register_setting('aicg-settings', 'aicg_notify_on_error_only', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false
        ));
    }

    /**
     * Versión enmascarada de una API key para mostrar en la UI.
     *
     * Longitud fija de asteriscos para no revelar la longitud real de la clave.
     *
     * @param string $option_name Nombre de la opción (aicg_*_api_key)
     * @return string
     */
    public static function get_masked_api_key($option_name) {
        $value = get_option($option_name, '');

        if (empty($value)) {
            return '';
        }

        $suffix = strlen($value) > 8 ? substr($value, -4) : '';
        return str_repeat('*', 12) . $suffix;
    }

    /**
     * Sanitizar API key con patrón write-only: si el valor recibido es la
     * máscara (el usuario no lo tocó), se conserva la clave guardada. La clave
     * completa nunca vuelve a imprimirse en el HTML.
     *
     * @param string $value       Valor enviado en el formulario
     * @param string $option_name Nombre de la opción
     * @return string
     */
    public static function sanitize_api_key($value, $option_name) {
        $value = trim((string) $value);

        // Campo vacío = borrar la clave
        if ($value === '') {
            return '';
        }

        // Si empieza con asteriscos es la máscara sin editar: conservar la clave actual
        if (strpos($value, '****') === 0) {
            return get_option($option_name, '');
        }

        return sanitize_text_field($value);
    }

    /**
     * Sanitizar campo de hora
     */
    public function sanitize_time_field($value) {
        // Validar formato HH:00
        if (preg_match('/^([01]?[0-9]|2[0-3]):00$/', $value)) {
            return $value;
        }
        return '08:00';
    }

    /**
     * Sanitizar campo de estado de publicación
     */
    public function sanitize_post_status_field($value) {
        $allowed = array('draft', 'publish', 'pending');
        if (in_array($value, $allowed, true)) {
            return $value;
        }
        return 'draft';
    }

    /**
     * Renderizar página del dashboard
     */
    public function render_dashboard_page() {
        include AICG_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    /**
     * Renderizar página de generación de artículo
     */
    public function render_generate_article_page() {
        include AICG_PLUGIN_DIR . 'admin/views/generate-article.php';
    }

    /**
     * Renderizar página de generación de noticias
     */
    public function render_generate_news_page() {
        include AICG_PLUGIN_DIR . 'admin/views/generate-news.php';
    }

    /**
     * Renderizar página de configuración
     */
    public function render_settings_page() {
        include AICG_PLUGIN_DIR . 'admin/views/settings.php';
    }

    /**
     * Renderizar página de historial
     */
    public function render_history_page() {
        include AICG_PLUGIN_DIR . 'admin/views/history.php';
    }

    /**
     * Sanitizar temas
     */
    public function sanitize_topics($input) {
        if (is_string($input)) {
            $lines = explode("\n", $input);
            return array_filter(array_map('sanitize_text_field', array_map('trim', $lines)));
        }
        return array();
    }

    /**
     * Sanitizar temas de noticias
     */
    public function sanitize_news_topics($input) {
        if (!is_array($input)) {
            return array();
        }

        $sanitized = array();
        foreach ($input as $topic) {
            if (!empty($topic['nombre'])) {
                $sanitized[] = array(
                    'nombre' => sanitize_text_field($topic['nombre']),
                    'imagen' => esc_url_raw($topic['imagen'] ?? '')
                );
            }
        }

        return $sanitized;
    }

    /**
     * Sanitizar tamaño de referencias
     */
    public function sanitize_reference_size($value) {
        $value = intval($value);
        if ($value < 12) return 12;
        if ($value > 32) return 32;
        return $value;
    }

    /**
     * Sanitizar fuentes de noticias RSS
     */
    public function sanitize_news_sources($input) {
        if (!is_array($input)) {
            return array();
        }

        $sanitized = array();
        foreach ($input as $source) {
            if (!empty($source['url'])) {
                $sanitized[] = array(
                    'nombre' => sanitize_text_field($source['nombre'] ?? ''),
                    'url' => esc_url_raw($source['url']),
                    'activo' => isset($source['activo']) ? (bool) $source['activo'] : false
                );
            }
        }

        return $sanitized;
    }

    /**
     * AJAX: Probar conexión con proveedor
     */
    public function ajax_test_provider() {
        check_ajax_referer('aicg_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Sin permisos suficientes', 'ai-content-generator'));
        }

        $provider_name = sanitize_text_field($_POST['provider']);
        $provider = AICG_AI_Provider_Factory::create($provider_name);

        if (is_wp_error($provider)) {
            wp_send_json_error($provider->get_error_message());
        }

        $result = $provider->test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Obtener los modelos disponibles de un proveedor para el selector
     */
    public function ajax_fetch_models() {
        check_ajax_referer('aicg_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Sin permisos suficientes', 'ai-content-generator'));
        }

        $provider_name = sanitize_text_field($_POST['provider'] ?? '');
        $provider = AICG_AI_Provider_Factory::create($provider_name);

        if (is_wp_error($provider)) {
            wp_send_json_error($provider->get_error_message());
        }

        // Los proveedores exponen su catálogo de modelos (id => metadatos)
        $models = $provider->get_available_models();
        $out = array();
        foreach ($models as $id => $meta) {
            $out[] = array(
                'id'   => $id,
                'name' => isset($meta['name']) ? $meta['name'] : $id,
            );
        }

        wp_send_json_success(array('models' => $out));
    }
}
