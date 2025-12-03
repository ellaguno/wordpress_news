<?php
/**
 * Plugin Name: AI Content Generator
 * Plugin URI: https://github.com/sesolibre/ai-content-generator
 * Description: Genera artículos y resúmenes de noticias usando múltiples proveedores de IA (OpenAI, Anthropic, DeepSeek, OpenRouter)
 * Version: 2.5.2
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
define('AICG_VERSION', '2.5.2');
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
        $this->allow_data_uris();
    }

    /**
     * Permitir data URIs en el contenido (para SVGs base64)
     */
    private function allow_data_uris() {
        // Permitir data: protocol en wp_kses para imágenes SVG base64
        add_filter('wp_kses_allowed_html', function($allowed, $context) {
            if ($context === 'post') {
                // Asegurar que img está permitido con src
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
        }, 10, 2);

        // Permitir data: URIs en el protocolo
        add_filter('kses_allowed_protocols', function($protocols) {
            if (!in_array('data', $protocols)) {
                $protocols[] = 'data';
            }
            return $protocols;
        });
    }

    /**
     * Cargar dependencias
     */
    private function load_dependencies() {
        // Clases base
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
     * Activación del plugin
     */
    public static function activate() {
        // Crear opciones por defecto
        $default_options = array(
            'aicg_ai_provider' => 'openai',
            'aicg_openai_api_key' => '',
            'aicg_anthropic_api_key' => '',
            'aicg_deepseek_api_key' => '',
            'aicg_openrouter_api_key' => '',
            'aicg_default_model' => 'gpt-4o',
            'aicg_image_model' => 'dall-e-3',
            'aicg_article_min_words' => 1500,
            'aicg_article_max_words' => 2000,
            'aicg_article_sections' => 4,
            'aicg_watermark_enabled' => false,
            'aicg_watermark_image' => '',
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
            'aicg_content_format' => 'gutenberg',
            'aicg_news_post_type' => 'post',
            'aicg_reference_style' => 'circle',
            'aicg_reference_color' => '#0073aa',
            'aicg_reference_orientation' => 'horizontal',
            'aicg_image_size' => '1792x1024'
        );

        foreach ($default_options as $key => $value) {
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

        // Crear tabla para historial
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'aicg_history';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            type varchar(20) NOT NULL,
            post_id bigint(20) DEFAULT NULL,
            provider varchar(50) NOT NULL,
            model varchar(100) NOT NULL,
            topic varchar(255) NOT NULL,
            tokens_used int(11) DEFAULT 0,
            cost decimal(10,6) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY type (type),
            KEY post_id (post_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Crear tabla para URLs de noticias usadas
        $urls_table = $wpdb->prefix . 'aicg_used_urls';
        $sql_urls = "CREATE TABLE IF NOT EXISTS $urls_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            url varchar(500) NOT NULL,
            used_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY url (url(255))
        ) $charset_collate;";

        dbDelta($sql_urls);

        flush_rewrite_rules();
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

    /**
     * Desinstalación del plugin
     */
    public static function uninstall() {
        // Solo ejecutar si se confirma desinstalación
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            exit;
        }

        // Eliminar opciones
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'aicg_%'");

        // Eliminar tablas
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}aicg_history");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}aicg_used_urls");
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
