<?php
/**
 * Dashboard de administración
 *
 * @package AI_Content_Generator
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para manejar el dashboard y AJAX
 */
class AICG_Admin_Dashboard {

    /**
     * Cargar assets de administración
     *
     * @param string $hook Hook de la página actual
     */
    public function enqueue_assets($hook) {
        // Solo cargar en páginas del plugin
        if (strpos($hook, 'aicg') === false) {
            return;
        }

        // CSS
        wp_enqueue_style(
            'aicg-admin-styles',
            AICG_PLUGIN_URL . 'admin/css/admin-styles.css',
            array(),
            AICG_VERSION
        );

        // JavaScript
        wp_enqueue_script(
            'aicg-admin-scripts',
            AICG_PLUGIN_URL . 'admin/js/admin-scripts.js',
            array('jquery'),
            AICG_VERSION,
            true
        );

        // Localización
        wp_localize_script('aicg-admin-scripts', 'aicgAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aicg_admin_nonce'),
            'strings' => array(
                'generating' => __('Generando...', 'ai-content-generator'),
                'success' => __('Completado con éxito', 'ai-content-generator'),
                'error' => __('Error', 'ai-content-generator'),
                'confirmDelete' => __('¿Estás seguro?', 'ai-content-generator'),
                'testingConnection' => __('Probando conexión...', 'ai-content-generator'),
                'connectionSuccess' => __('Conexión exitosa', 'ai-content-generator'),
                'connectionError' => __('Error de conexión', 'ai-content-generator'),
                'step1' => __('Generando título...', 'ai-content-generator'),
                'step2' => __('Escribiendo contenido...', 'ai-content-generator'),
                'step3' => __('Generando imagen...', 'ai-content-generator'),
                'step4' => __('Publicando...', 'ai-content-generator')
            )
        ));

        // Media uploader para watermark
        if (strpos($hook, 'settings') !== false) {
            wp_enqueue_media();
        }
    }

    /**
     * AJAX: Generar artículo
     */
    public function ajax_generate_article() {
        // Verificar nonce
        check_ajax_referer('aicg_admin_nonce', 'nonce');

        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Sin permisos suficientes', 'ai-content-generator'));
        }

        // Obtener parámetros
        $topic_type = sanitize_text_field($_POST['topic_type'] ?? 'preset');
        $topic = '';

        switch ($topic_type) {
            case 'preset':
                $topic = sanitize_text_field($_POST['preset_topic'] ?? '');
                break;
            case 'random':
                $topics = get_option('aicg_article_topics', array());
                $topic = !empty($topics) ? $topics[array_rand($topics)] : '';
                break;
            case 'custom':
                $topic = sanitize_text_field($_POST['custom_topic'] ?? '');
                break;
        }

        if (empty($topic)) {
            wp_send_json_error(__('No se especificó un tema', 'ai-content-generator'));
        }

        // Parámetros de generación
        $args = array(
            'topic' => $topic,
            'category_id' => absint($_POST['category'] ?? 0),
            'post_status' => sanitize_text_field($_POST['post_status'] ?? 'draft'),
            'generate_image' => !empty($_POST['generate_image']),
            'min_words' => absint($_POST['min_words'] ?? 1500),
            'max_words' => absint($_POST['max_words'] ?? 2000),
            'sections' => absint($_POST['sections'] ?? 4),
            'temperature' => floatval($_POST['temperature'] ?? 0.7)
        );

        // Generar artículo
        $generator = new AICG_Article_Generator();
        $result = $generator->generate($args);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Respuesta exitosa
        wp_send_json_success(array(
            'post_id' => $result['post_id'],
            'title' => $result['title'],
            'topic' => $result['topic'],
            'tokens_used' => $result['tokens_used'],
            'cost' => number_format($result['cost'], 4),
            'edit_url' => get_edit_post_link($result['post_id'], 'raw'),
            'view_url' => get_permalink($result['post_id']),
            'message' => sprintf(
                __('Artículo "%s" generado exitosamente.', 'ai-content-generator'),
                $result['title']
            )
        ));
    }

    /**
     * AJAX: Generar noticias
     */
    public function ajax_generate_news() {
        // Verificar nonce
        check_ajax_referer('aicg_admin_nonce', 'nonce');

        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Sin permisos suficientes', 'ai-content-generator'));
        }

        // Obtener parámetros
        $topics = isset($_POST['topics']) ? array_map('sanitize_text_field', $_POST['topics']) : array();
        $include_headlines = !empty($_POST['include_headlines']);
        $post_status = sanitize_text_field($_POST['post_status'] ?? 'draft');
        $post_type = sanitize_text_field($_POST['post_type'] ?? 'post');
        $generate_image = !empty($_POST['generate_image']);

        // Generar noticias
        $aggregator = new AICG_News_Aggregator();
        $result = $aggregator->generate(array(
            'topics' => $topics,
            'include_headlines' => $include_headlines,
            'post_status' => $post_status,
            'post_type' => $post_type,
            'generate_image' => $generate_image
        ));

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Respuesta exitosa
        wp_send_json_success(array(
            'post_id' => $result['post_id'],
            'title' => $result['title'],
            'news_count' => $result['news_count'],
            'topics_processed' => $result['topics_processed'],
            'topics_details' => isset($result['topics_details']) ? $result['topics_details'] : array(),
            'tokens_used' => $result['tokens_used'],
            'cost' => number_format($result['cost'], 4),
            'edit_url' => get_edit_post_link($result['post_id'], 'raw'),
            'view_url' => get_permalink($result['post_id']),
            'message' => sprintf(
                __('Resumen de noticias generado con %d noticias de %d temas.', 'ai-content-generator'),
                $result['news_count'],
                count($result['topics_processed'])
            )
        ));
    }

    /**
     * AJAX: Probar conexión con proveedor
     */
    public function ajax_test_provider() {
        // Verificar nonce
        check_ajax_referer('aicg_admin_nonce', 'nonce');

        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Sin permisos suficientes', 'ai-content-generator'));
        }

        $provider_name = sanitize_text_field($_POST['provider'] ?? '');

        if (empty($provider_name)) {
            wp_send_json_error(__('Proveedor no especificado', 'ai-content-generator'));
        }

        $provider = AICG_AI_Provider_Factory::create($provider_name);

        if (is_wp_error($provider)) {
            wp_send_json_error($provider->get_error_message());
        }

        if (!$provider->is_configured()) {
            wp_send_json_error(__('API Key no configurada', 'ai-content-generator'));
        }

        $result = $provider->test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'message' => $result['message'],
            'provider' => $result['provider']
        ));
    }

    /**
     * AJAX: Obtener estadísticas
     */
    public function ajax_get_stats() {
        check_ajax_referer('aicg_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Sin permisos suficientes', 'ai-content-generator'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'aicg_history';

        $period = sanitize_text_field($_POST['period'] ?? '7days');

        switch ($period) {
            case '24hours':
                $where = "created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
                break;
            case '7days':
                $where = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                break;
            case '30days':
                $where = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                break;
            case 'all':
            default:
                $where = "1=1";
                break;
        }

        $stats = $wpdb->get_row("
            SELECT
                COUNT(*) as total,
                COUNT(CASE WHEN type = 'article' THEN 1 END) as articles,
                COUNT(CASE WHEN type = 'news' THEN 1 END) as news,
                SUM(tokens_used) as tokens,
                SUM(cost) as cost
            FROM $table
            WHERE $where
        ");

        wp_send_json_success(array(
            'total' => intval($stats->total),
            'articles' => intval($stats->articles),
            'news' => intval($stats->news),
            'tokens' => intval($stats->tokens),
            'cost' => floatval($stats->cost)
        ));
    }

    /**
     * Registrar REST API endpoints (opcional)
     */
    public function register_rest_routes() {
        register_rest_route('aicg/v1', '/generate/article', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_generate_article'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));

        register_rest_route('aicg/v1', '/generate/news', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_generate_news'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));

        register_rest_route('aicg/v1', '/stats', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_stats'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));
    }

    /**
     * REST: Generar artículo
     */
    public function rest_generate_article($request) {
        $generator = new AICG_Article_Generator();
        $result = $generator->generate(array(
            'topic' => $request->get_param('topic'),
            'post_status' => $request->get_param('status') ?: 'draft',
            'generate_image' => $request->get_param('generate_image') !== false
        ));

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * REST: Generar noticias
     */
    public function rest_generate_news($request) {
        $aggregator = new AICG_News_Aggregator();
        $result = $aggregator->generate(array(
            'topics' => $request->get_param('topics'),
            'include_headlines' => $request->get_param('include_headlines') !== false,
            'post_status' => $request->get_param('status') ?: 'draft'
        ));

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * REST: Obtener estadísticas
     */
    public function rest_get_stats($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'aicg_history';

        $stats = $wpdb->get_row("
            SELECT
                COUNT(*) as total,
                COUNT(CASE WHEN type = 'article' THEN 1 END) as articles,
                COUNT(CASE WHEN type = 'news' THEN 1 END) as news,
                SUM(tokens_used) as tokens,
                SUM(cost) as cost
            FROM $table
        ");

        return rest_ensure_response(array(
            'total' => intval($stats->total),
            'articles' => intval($stats->articles),
            'news' => intval($stats->news),
            'tokens' => intval($stats->tokens),
            'cost' => floatval($stats->cost)
        ));
    }
}
