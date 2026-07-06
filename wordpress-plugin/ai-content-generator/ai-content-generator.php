<?php
/**
 * Plugin Name: AI Content Generator
 * Plugin URI: https://github.com/sesolibre/ai-content-generator
 * Description: Genera artículos y resúmenes de noticias usando múltiples proveedores de IA (OpenAI, Anthropic, DeepSeek, OpenRouter)
 * Version: 2.9.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Eduardo Llaguno
 * Author URI: https://sesolibre.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-content-generator
 * Domain Path: /languages
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Constantes del plugin
define('AICG_VERSION', '2.9.0');
define('AICG_DB_VERSION', '2');
define('AICG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AICG_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AICG_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Clase principal del plugin
 */
final class AI_Content_Generator {

    /**
     * Instancia única del plugin
     */
    private static $instance = null;

    /**
     * Proveedor de IA actual
     */
    private $ai_provider = null;

    /**
     * Obtener instancia única (Singleton)
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor privado
     */
    private function __construct() {
        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->define_cron_hooks();
        $this->maybe_upgrade_database();
    }

    /**
     * Permitir img con data: URIs en wp_kses (para los SVG de referencias).
     *
     * Estos filtros NO se registran globalmente: se aplican solo alrededor de
     * la inserción de posts del propio plugin (add_content_filters /
     * remove_content_filters), para no debilitar el filtrado KSES del resto
     * del sitio frente a usuarios sin capacidad unfiltered_html.
     */
    public static function kses_allow_img_attributes($allowed, $context) {
        if ($context === 'post') {
            if (!isset($allowed['img'])) {
                $allowed['img'] = array();
            }
            $allowed['img']['src'] = true;
            $allowed['img']['width'] = true;
            $allowed['img']['height'] = true;
            $allowed['img']['alt'] = true;
            $allowed['img']['style'] = true;
            $allowed['img']['class'] = true;
            $allowed['img']['aria-hidden'] = true;
        }
        return $allowed;
    }

    /**
     * Permitir el protocolo data: en KSES (solo mientras esté activo el filtro)
     */
    public static function kses_allow_data_protocol($protocols) {
        if (!in_array('data', $protocols)) {
            $protocols[] = 'data';
        }
        return $protocols;
    }

    /**
     * Activar los filtros KSES del plugin (llamar justo antes de insertar contenido propio)
     */
    public static function add_content_filters() {
        add_filter('wp_kses_allowed_html', array(__CLASS__, 'kses_allow_img_attributes'), 10, 2);
        add_filter('kses_allowed_protocols', array(__CLASS__, 'kses_allow_data_protocol'));
    }

    /**
     * Desactivar los filtros KSES del plugin (llamar justo después de insertar)
     */
    public static function remove_content_filters() {
        remove_filter('wp_kses_allowed_html', array(__CLASS__, 'kses_allow_img_attributes'), 10);
        remove_filter('kses_allowed_protocols', array(__CLASS__, 'kses_allow_data_protocol'));
    }

    /**
     * Cargar dependencias
     */
    private function load_dependencies() {
        // Clases base
        require_once AICG_PLUGIN_DIR . 'includes/class-logger.php';
        require_once AICG_PLUGIN_DIR . 'includes/class-ai-provider-interface.php';
        require_once AICG_PLUGIN_DIR . 'includes/class-ai-provider-factory.php';

        // Proveedores de IA
        require_once AICG_PLUGIN_DIR . 'includes/providers/class-openai-provider.php';
        require_once AICG_PLUGIN_DIR . 'includes/providers/class-anthropic-provider.php';
        require_once AICG_PLUGIN_DIR . 'includes/providers/class-deepseek-provider.php';
        require_once AICG_PLUGIN_DIR . 'includes/providers/class-openrouter-provider.php';

        // Generadores de contenido
        require_once AICG_PLUGIN_DIR . 'includes/class-article-generator.php';
        require_once AICG_PLUGIN_DIR . 'includes/class-news-aggregator.php';
        require_once AICG_PLUGIN_DIR . 'includes/class-image-processor.php';
        require_once AICG_PLUGIN_DIR . 'includes/class-gutenberg-converter.php';

        // Administración
        require_once AICG_PLUGIN_DIR . 'admin/class-admin-settings.php';
        require_once AICG_PLUGIN_DIR . 'admin/class-admin-dashboard.php';

        // Cron y programación
        require_once AICG_PLUGIN_DIR . 'includes/class-cron-scheduler.php';
    }

    /**
     * Configurar localización
     */
    private function set_locale() {
        add_action('plugins_loaded', function() {
            load_plugin_textdomain(
                'ai-content-generator',
                false,
                dirname(AICG_PLUGIN_BASENAME) . '/languages/'
            );
        });
    }

    /**
     * Definir hooks de administración
     */
    private function define_admin_hooks() {
        if (is_admin()) {
            $admin_settings = new AICG_Admin_Settings();
            $admin_dashboard = new AICG_Admin_Dashboard();

            // Menú de administración
            add_action('admin_menu', array($admin_settings, 'add_admin_menu'));

            // Registrar configuraciones
            add_action('admin_init', array($admin_settings, 'register_settings'));

            // Cargar assets de admin
            add_action('admin_enqueue_scripts', array($admin_dashboard, 'enqueue_assets'));

            // AJAX handlers
            add_action('wp_ajax_aicg_generate_article', array($admin_dashboard, 'ajax_generate_article'));
            add_action('wp_ajax_aicg_generate_news', array($admin_dashboard, 'ajax_generate_news'));
            add_action('wp_ajax_aicg_test_provider', array($admin_settings, 'ajax_test_provider'));

            // Limpiar errores de cron desde el dashboard
            add_action('admin_post_aicg_clear_cron_errors', array($admin_dashboard, 'handle_clear_cron_errors'));
        }
    }

    /**
     * Definir hooks de cron
     */
    private function define_cron_hooks() {
        $scheduler = new AICG_Cron_Scheduler();

        // Registrar intervalos personalizados
        add_filter('cron_schedules', array($scheduler, 'add_cron_intervals'));

        // Hooks de cron
        add_action('aicg_generate_scheduled_article', array($scheduler, 'run_scheduled_article'));
        add_action('aicg_generate_scheduled_news', array($scheduler, 'run_scheduled_news'));
    }

    /**
     * Obtener proveedor de IA configurado
     */
    public function get_ai_provider() {
        if (null === $this->ai_provider) {
            $provider_name = get_option('aicg_ai_provider', 'openai');
            $this->ai_provider = AICG_AI_Provider_Factory::create($provider_name);
        }
        return $this->ai_provider;
    }

    /**
     * Valores por defecto de las opciones del plugin.
     *
     * Fuente única de verdad: la usan activate() y puede consultarse desde
     * cualquier parte para no duplicar defaults (antes divergían entre
     * activate, register_setting y las vistas).
     *
     * @return array
     */
    public static function get_default_options() {
        return array(
            'aicg_ai_provider' => 'openai',
            'aicg_openai_api_key' => '',
            'aicg_anthropic_api_key' => '',
            'aicg_deepseek_api_key' => '',
            'aicg_openrouter_api_key' => '',
            'aicg_default_model' => 'gpt-4o',
            'aicg_image_provider' => 'openai',
            'aicg_image_model' => 'dall-e-3',
            'aicg_article_min_words' => 1500,
            'aicg_article_max_words' => 2000,
            'aicg_article_sections' => 4,
            'aicg_watermark_enabled' => false,
            'aicg_watermark_image' => 0,
            'aicg_article_topics' => array(
                'Tecnología e Innovación',
                'Ciencia y Descubrimientos',
                'Inteligencia Artificial',
                'Desarrollo Personal',
                'Ciberseguridad'
            ),
            'aicg_news_topics' => array(
                array('nombre' => 'Internacional', 'imagen' => ''),
                array('nombre' => 'Ciencia y Tecnología', 'imagen' => ''),
                array('nombre' => 'Economía', 'imagen' => '')
            ),
            'aicg_schedule_articles' => false,
            'aicg_schedule_articles_frequency' => 'daily',
            'aicg_schedule_news' => false,
            'aicg_schedule_news_frequency' => 'twicedaily',
            'aicg_schedule_news_time' => '08:00',
            'aicg_schedule_post_status' => 'draft',
            'aicg_default_author' => 0,
            'aicg_notify_admin' => false,
            'aicg_notify_on_error_only' => false,
            'aicg_content_format' => 'gutenberg',
            'aicg_news_post_type' => 'post',
            'aicg_reference_style' => 'inline',
            'aicg_reference_color' => '#0073aa',
            'aicg_reference_orientation' => 'horizontal',
            'aicg_reference_size' => 24,
            'aicg_image_size' => '1792x1024',
            'aicg_image_quality' => 'standard'
        );
    }

    /**
     * Activación del plugin
     */
    public static function activate() {
        // Crear opciones por defecto (solo las que no existan)
        foreach (self::get_default_options() as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }

        // Programar cron si está habilitado
        if (get_option('aicg_schedule_articles')) {
            if (!wp_next_scheduled('aicg_generate_scheduled_article')) {
                wp_schedule_event(time(), get_option('aicg_schedule_articles_frequency'), 'aicg_generate_scheduled_article');
            }
        }

        if (get_option('aicg_schedule_news')) {
            if (!wp_next_scheduled('aicg_generate_scheduled_news')) {
                wp_schedule_event(time(), get_option('aicg_schedule_news_frequency'), 'aicg_generate_scheduled_news');
            }
        }

        // Crear/actualizar tablas
        self::create_tables();
        update_option('aicg_db_version', AICG_DB_VERSION);

        flush_rewrite_rules();
    }

    /**
     * Crear o actualizar las tablas del plugin (dbDelta añade columnas faltantes)
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'aicg_history';

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            type varchar(20) NOT NULL,
            post_id bigint(20) DEFAULT NULL,
            provider varchar(50) NOT NULL,
            model varchar(100) NOT NULL,
            topic varchar(255) NOT NULL,
            tokens_used int(11) DEFAULT 0,
            cost decimal(10,6) DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'success',
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY type (type),
            KEY post_id (post_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Tabla para URLs de noticias usadas
        $urls_table = $wpdb->prefix . 'aicg_used_urls';
        $sql_urls = "CREATE TABLE $urls_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            url varchar(500) NOT NULL,
            used_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY url (url(255))
        ) $charset_collate;";

        dbDelta($sql_urls);
    }

    /**
     * Migrar el esquema de BD al actualizar el plugin sin reactivarlo.
     *
     * Se ejecuta en la carga del plugin (no solo en admin) para que el
     * esquema esté al día también cuando la primera ejecución tras la
     * actualización es una tarea de cron.
     */
    private function maybe_upgrade_database() {
        if (get_option('aicg_db_version') !== AICG_DB_VERSION) {
            self::create_tables();
            update_option('aicg_db_version', AICG_DB_VERSION);
        }
    }

    /**
     * Desactivación del plugin
     */
    public static function deactivate() {
        // Limpiar cron jobs
        wp_clear_scheduled_hook('aicg_generate_scheduled_article');
        wp_clear_scheduled_hook('aicg_generate_scheduled_news');

        flush_rewrite_rules();
    }

}

// Hooks de activación/desactivación
register_activation_hook(__FILE__, array('AI_Content_Generator', 'activate'));
register_deactivation_hook(__FILE__, array('AI_Content_Generator', 'deactivate'));

/**
 * Inicializar el plugin
 */
function aicg_init() {
    return AI_Content_Generator::get_instance();
}

// Iniciar el plugin
add_action('plugins_loaded', 'aicg_init');
