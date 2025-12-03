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
        // Sección: Proveedor de IA
        add_settings_section(
            'aicg_provider_section',
            __('Proveedor de IA', 'ai-content-generator'),
            array($this, 'render_provider_section'),
            'aicg-settings'
        );

        // Proveedor principal
        register_setting('aicg-settings', 'aicg_ai_provider', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'openai'
        ));

        add_settings_field(
            'aicg_ai_provider',
            __('Proveedor Principal', 'ai-content-generator'),
            array($this, 'render_provider_field'),
            'aicg-settings',
            'aicg_provider_section'
        );

        // API Keys
        $api_keys = array(
            'openai' => 'OpenAI',
            'anthropic' => 'Anthropic',
            'deepseek' => 'DeepSeek',
            'openrouter' => 'OpenRouter'
        );

        foreach ($api_keys as $key => $name) {
            register_setting('aicg-settings', 'aicg_' . $key . '_api_key', array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => ''
            ));

            add_settings_field(
                'aicg_' . $key . '_api_key',
                sprintf(__('API Key %s', 'ai-content-generator'), $name),
                array($this, 'render_api_key_field'),
                'aicg-settings',
                'aicg_provider_section',
                array('provider' => $key, 'name' => $name)
            );
        }

        // Sección: Modelos
        add_settings_section(
            'aicg_models_section',
            __('Modelos', 'ai-content-generator'),
            array($this, 'render_models_section'),
            'aicg-settings'
        );

        register_setting('aicg-settings', 'aicg_default_model', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'gpt-4o'
        ));

        add_settings_field(
            'aicg_default_model',
            __('Modelo de Texto', 'ai-content-generator'),
            array($this, 'render_model_field'),
            'aicg-settings',
            'aicg_models_section'
        );

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

        add_settings_field(
            'aicg_image_model',
            __('Modelo de Imágenes', 'ai-content-generator'),
            array($this, 'render_image_model_field'),
            'aicg-settings',
            'aicg_models_section'
        );

        // Sección: Artículos
        add_settings_section(
            'aicg_article_section',
            __('Configuración de Artículos', 'ai-content-generator'),
            array($this, 'render_article_section'),
            'aicg-settings'
        );

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

            add_settings_field(
                $key,
                $config['label'],
                array($this, 'render_number_field'),
                'aicg-settings',
                'aicg_article_section',
                array('option' => $key, 'default' => $config['default'])
            );
        }

        // Temas de artículos
        register_setting('aicg-settings', 'aicg_article_topics', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_topics'),
            'default' => array()
        ));

        add_settings_field(
            'aicg_article_topics',
            __('Temas para Artículos', 'ai-content-generator'),
            array($this, 'render_topics_field'),
            'aicg-settings',
            'aicg_article_section',
            array('option' => 'aicg_article_topics')
        );

        // Sección: Noticias
        add_settings_section(
            'aicg_news_section',
            __('Configuración de Noticias', 'ai-content-generator'),
            array($this, 'render_news_section'),
            'aicg-settings'
        );

        register_setting('aicg-settings', 'aicg_news_topics', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_news_topics'),
            'default' => array()
        ));

        add_settings_field(
            'aicg_news_topics',
            __('Temas de Noticias', 'ai-content-generator'),
            array($this, 'render_news_topics_field'),
            'aicg-settings',
            'aicg_news_section'
        );

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

        // Sección: Imágenes
        add_settings_section(
            'aicg_image_section',
            __('Configuración de Imágenes', 'ai-content-generator'),
            array($this, 'render_image_section'),
            'aicg-settings'
        );

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

        add_settings_field(
            'aicg_watermark_enabled',
            __('Marca de agua', 'ai-content-generator'),
            array($this, 'render_watermark_field'),
            'aicg-settings',
            'aicg_image_section'
        );

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

        // Sección: Programación
        add_settings_section(
            'aicg_schedule_section',
            __('Programación Automática', 'ai-content-generator'),
            array($this, 'render_schedule_section'),
            'aicg-settings'
        );

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

        add_settings_field(
            'aicg_schedule_articles',
            __('Artículos Programados', 'ai-content-generator'),
            array($this, 'render_schedule_articles_field'),
            'aicg-settings',
            'aicg_schedule_section'
        );

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

        add_settings_field(
            'aicg_schedule_news',
            __('Noticias Programadas', 'ai-content-generator'),
            array($this, 'render_schedule_news_field'),
            'aicg-settings',
            'aicg_schedule_section'
        );
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
     * Renderizar sección de proveedor
     */
    public function render_provider_section() {
        echo '<p>' . esc_html__('Configura los proveedores de IA que deseas utilizar.', 'ai-content-generator') . '</p>';
    }

    /**
     * Renderizar campo de proveedor
     */
    public function render_provider_field() {
        $providers = AICG_AI_Provider_Factory::get_available_providers();
        $current = get_option('aicg_ai_provider', 'openai');
        ?>
        <select name="aicg_ai_provider" id="aicg_ai_provider">
            <?php foreach ($providers as $key => $provider) : ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($current, $key); ?>>
                    <?php echo esc_html($provider['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e('Selecciona el proveedor principal para generación de texto.', 'ai-content-generator'); ?>
        </p>
        <?php
    }

    /**
     * Renderizar campo de API Key
     */
    public function render_api_key_field($args) {
        $option = 'aicg_' . $args['provider'] . '_api_key';
        $value = get_option($option, '');
        $masked = $value ? str_repeat('*', strlen($value) - 4) . substr($value, -4) : '';
        ?>
        <input type="password"
               name="<?php echo esc_attr($option); ?>"
               id="<?php echo esc_attr($option); ?>"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text aicg-api-key"
               autocomplete="off">
        <button type="button" class="button aicg-toggle-visibility" data-target="<?php echo esc_attr($option); ?>">
            <span class="dashicons dashicons-visibility"></span>
        </button>
        <button type="button"
                class="button aicg-test-connection"
                data-provider="<?php echo esc_attr($args['provider']); ?>">
            <?php esc_html_e('Probar', 'ai-content-generator'); ?>
        </button>
        <span class="aicg-test-result" id="test-result-<?php echo esc_attr($args['provider']); ?>"></span>
        <?php
    }

    /**
     * Renderizar sección de modelos
     */
    public function render_models_section() {
        echo '<p>' . esc_html__('Configura los modelos de IA para texto e imágenes.', 'ai-content-generator') . '</p>';
    }

    /**
     * Renderizar campo de modelo
     */
    public function render_model_field() {
        $current = get_option('aicg_default_model', 'gpt-4o');
        ?>
        <input type="text"
               name="aicg_default_model"
               id="aicg_default_model"
               value="<?php echo esc_attr($current); ?>"
               class="regular-text">
        <p class="description">
            <?php esc_html_e('Modelo para generación de texto. Ejemplos: gpt-4o, claude-sonnet-4-20250514, deepseek-chat', 'ai-content-generator'); ?>
        </p>
        <?php
    }

    /**
     * Renderizar campo de modelo de imagen
     */
    public function render_image_model_field() {
        $provider = get_option('aicg_image_provider', 'openai');
        $model = get_option('aicg_image_model', 'dall-e-3');
        ?>
        <select name="aicg_image_provider" id="aicg_image_provider">
            <option value="openai" <?php selected($provider, 'openai'); ?>>OpenAI</option>
            <option value="openrouter" <?php selected($provider, 'openrouter'); ?>>OpenRouter</option>
        </select>
        <input type="text"
               name="aicg_image_model"
               id="aicg_image_model"
               value="<?php echo esc_attr($model); ?>"
               class="regular-text">
        <p class="description">
            <?php esc_html_e('Modelo para generación de imágenes. Ejemplo: dall-e-3', 'ai-content-generator'); ?>
        </p>
        <?php
    }

    /**
     * Renderizar sección de artículos
     */
    public function render_article_section() {
        echo '<p>' . esc_html__('Configura los parámetros para la generación de artículos.', 'ai-content-generator') . '</p>';
    }

    /**
     * Renderizar campo numérico
     */
    public function render_number_field($args) {
        $value = get_option($args['option'], $args['default']);
        ?>
        <input type="number"
               name="<?php echo esc_attr($args['option']); ?>"
               id="<?php echo esc_attr($args['option']); ?>"
               value="<?php echo esc_attr($value); ?>"
               min="1"
               class="small-text">
        <?php
    }

    /**
     * Renderizar campo de temas
     */
    public function render_topics_field($args) {
        $topics = get_option($args['option'], array());
        $topics_text = is_array($topics) ? implode("\n", $topics) : '';
        ?>
        <textarea name="<?php echo esc_attr($args['option']); ?>"
                  id="<?php echo esc_attr($args['option']); ?>"
                  rows="10"
                  cols="50"
                  class="large-text"><?php echo esc_textarea($topics_text); ?></textarea>
        <p class="description">
            <?php esc_html_e('Un tema por línea. Estos temas se usarán para generar artículos aleatorios.', 'ai-content-generator'); ?>
        </p>
        <?php
    }

    /**
     * Renderizar sección de noticias
     */
    public function render_news_section() {
        echo '<p>' . esc_html__('Configura los temas para el agregador de noticias.', 'ai-content-generator') . '</p>';
    }

    /**
     * Renderizar campo de temas de noticias
     */
    public function render_news_topics_field() {
        $topics = get_option('aicg_news_topics', array());
        ?>
        <div id="aicg-news-topics-container">
            <?php if (!empty($topics)) : ?>
                <?php foreach ($topics as $index => $topic) : ?>
                    <div class="aicg-news-topic-row">
                        <input type="text"
                               name="aicg_news_topics[<?php echo $index; ?>][nombre]"
                               value="<?php echo esc_attr($topic['nombre']); ?>"
                               placeholder="<?php esc_attr_e('Nombre del tema', 'ai-content-generator'); ?>"
                               class="regular-text">
                        <input type="url"
                               name="aicg_news_topics[<?php echo $index; ?>][imagen]"
                               value="<?php echo esc_attr($topic['imagen']); ?>"
                               placeholder="<?php esc_attr_e('URL de imagen (opcional)', 'ai-content-generator'); ?>"
                               class="regular-text">
                        <button type="button" class="button aicg-remove-topic">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <button type="button" class="button" id="aicg-add-news-topic">
            <?php esc_html_e('Añadir Tema', 'ai-content-generator'); ?>
        </button>
        <?php
    }

    /**
     * Renderizar sección de imágenes
     */
    public function render_image_section() {
        echo '<p>' . esc_html__('Configura el procesamiento de imágenes.', 'ai-content-generator') . '</p>';
    }

    /**
     * Renderizar campo de watermark
     */
    public function render_watermark_field() {
        $enabled = get_option('aicg_watermark_enabled', false);
        $image_id = get_option('aicg_watermark_image', 0);
        $image_url = $image_id ? wp_get_attachment_url($image_id) : '';
        ?>
        <label>
            <input type="checkbox"
                   name="aicg_watermark_enabled"
                   id="aicg_watermark_enabled"
                   value="1"
                   <?php checked($enabled); ?>>
            <?php esc_html_e('Aplicar marca de agua a las imágenes generadas', 'ai-content-generator'); ?>
        </label>
        <br><br>
        <input type="hidden"
               name="aicg_watermark_image"
               id="aicg_watermark_image"
               value="<?php echo esc_attr($image_id); ?>">
        <button type="button" class="button" id="aicg-select-watermark">
            <?php esc_html_e('Seleccionar Imagen', 'ai-content-generator'); ?>
        </button>
        <div id="aicg-watermark-preview">
            <?php if ($image_url) : ?>
                <img src="<?php echo esc_url($image_url); ?>" style="max-width: 200px;">
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renderizar sección de programación
     */
    public function render_schedule_section() {
        echo '<p>' . esc_html__('Configura la generación automática de contenido.', 'ai-content-generator') . '</p>';
    }

    /**
     * Renderizar campo de programación de artículos
     */
    public function render_schedule_articles_field() {
        $enabled = get_option('aicg_schedule_articles', false);
        $frequency = get_option('aicg_schedule_articles_frequency', 'daily');
        ?>
        <label>
            <input type="checkbox"
                   name="aicg_schedule_articles"
                   id="aicg_schedule_articles"
                   value="1"
                   <?php checked($enabled); ?>>
            <?php esc_html_e('Generar artículos automáticamente', 'ai-content-generator'); ?>
        </label>
        <br><br>
        <select name="aicg_schedule_articles_frequency" id="aicg_schedule_articles_frequency">
            <option value="hourly" <?php selected($frequency, 'hourly'); ?>><?php esc_html_e('Cada hora', 'ai-content-generator'); ?></option>
            <option value="twicedaily" <?php selected($frequency, 'twicedaily'); ?>><?php esc_html_e('Dos veces al día', 'ai-content-generator'); ?></option>
            <option value="daily" <?php selected($frequency, 'daily'); ?>><?php esc_html_e('Diario', 'ai-content-generator'); ?></option>
            <option value="weekly" <?php selected($frequency, 'weekly'); ?>><?php esc_html_e('Semanal', 'ai-content-generator'); ?></option>
        </select>
        <?php
    }

    /**
     * Renderizar campo de programación de noticias
     */
    public function render_schedule_news_field() {
        $enabled = get_option('aicg_schedule_news', false);
        $frequency = get_option('aicg_schedule_news_frequency', 'twicedaily');
        ?>
        <label>
            <input type="checkbox"
                   name="aicg_schedule_news"
                   id="aicg_schedule_news"
                   value="1"
                   <?php checked($enabled); ?>>
            <?php esc_html_e('Generar resúmenes de noticias automáticamente', 'ai-content-generator'); ?>
        </label>
        <br><br>
        <select name="aicg_schedule_news_frequency" id="aicg_schedule_news_frequency">
            <option value="hourly" <?php selected($frequency, 'hourly'); ?>><?php esc_html_e('Cada hora', 'ai-content-generator'); ?></option>
            <option value="twicedaily" <?php selected($frequency, 'twicedaily'); ?>><?php esc_html_e('Dos veces al día', 'ai-content-generator'); ?></option>
            <option value="daily" <?php selected($frequency, 'daily'); ?>><?php esc_html_e('Diario', 'ai-content-generator'); ?></option>
        </select>
        <?php
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
}
