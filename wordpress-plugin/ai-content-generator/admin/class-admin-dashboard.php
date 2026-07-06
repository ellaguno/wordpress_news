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
                'testConnection' => __('Probar Conexión', 'ai-content-generator'),
                'testingConnection' => __('Probando conexión...', 'ai-content-generator'),
                'connectionSuccess' => __('Conexión exitosa', 'ai-content-generator'),
                'connectionError' => __('Error de conexión', 'ai-content-generator'),
                'queued' => __('En cola...', 'ai-content-generator'),
                'timeout' => __('La generación está tardando más de lo esperado. Puede seguir ejecutándose en segundo plano; revisa el Historial en unos minutos.', 'ai-content-generator'),
                'loadingModels' => __('Cargando...', 'ai-content-generator'),
                'loadModels' => __('Cargar modelos', 'ai-content-generator'),
                'modelsLoaded' => __('modelos cargados', 'ai-content-generator'),
                'unsavedChanges' => __('Tienes cambios sin guardar. ¿Seguro que quieres salir?', 'ai-content-generator'),
                'dragToReorder' => __('Arrastrar para reordenar', 'ai-content-generator'),
                'topicName' => __('Nombre del tema', 'ai-content-generator'),
                'imageUrlOptional' => __('URL de imagen (opcional)', 'ai-content-generator'),
                'sourceName' => __('Nombre de la fuente', 'ai-content-generator'),
                'rssFeedUrl' => __('URL del feed RSS', 'ai-content-generator'),
                'selectWatermark' => __('Seleccionar Marca de Agua', 'ai-content-generator')
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
        $topic_type = sanitize_text_field(wp_unslash($_POST['topic_type'] ?? 'preset'));
        $topic = '';

        switch ($topic_type) {
            case 'preset':
                $topic = sanitize_text_field(wp_unslash($_POST['preset_topic'] ?? ''));
                break;
            case 'random':
                $topics = get_option('aicg_article_topics', array());
                $topic = !empty($topics) ? $topics[array_rand($topics)] : '';
                break;
            case 'custom':
                $topic = sanitize_text_field(wp_unslash($_POST['custom_topic'] ?? ''));
                break;
        }

        if (empty($topic)) {
            wp_send_json_error(__('No se especificó un tema', 'ai-content-generator'));
        }

        // Validar min/max de palabras
        $min_words = absint($_POST['min_words'] ?? 1500);
        $max_words = absint($_POST['max_words'] ?? 2000);
        if ($max_words < $min_words) {
            wp_send_json_error(__('El máximo de palabras no puede ser menor que el mínimo', 'ai-content-generator'));
        }

        // Parámetros de generación
        $args = array(
            'topic' => $topic,
            'category_id' => absint($_POST['category'] ?? 0),
            'post_status' => sanitize_text_field(wp_unslash($_POST['post_status'] ?? 'draft')),
            'generate_image' => !empty($_POST['generate_image']),
            'min_words' => $min_words,
            'max_words' => $max_words,
            'sections' => absint($_POST['sections'] ?? 4),
            'temperature' => floatval($_POST['temperature'] ?? 0.7),
            // Resolver el autor ahora: el trabajo corre en cron sin usuario actual,
            // así que "usuario actual" (0) debe fijarse al usuario que lo solicita
            'post_author' => absint($_POST['post_author'] ?? 0) ?: get_current_user_id()
        );

        // Encolar el trabajo y devolver el job_id (la generación corre en segundo plano)
        $job_id = AICG_Background_Jobs::enqueue('article', $args);
        wp_send_json_success(array('job_id' => $job_id));
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
        $topics = isset($_POST['topics']) ? array_map('sanitize_text_field', wp_unslash($_POST['topics'])) : array();
        $include_headlines = !empty($_POST['include_headlines']);
        $post_status = sanitize_text_field(wp_unslash($_POST['post_status'] ?? 'draft'));
        $post_type = sanitize_text_field(wp_unslash($_POST['post_type'] ?? 'post'));
        $generate_image = !empty($_POST['generate_image']);

        // Encolar el trabajo y devolver el job_id (la generación corre en segundo plano)
        $job_id = AICG_Background_Jobs::enqueue('news', array(
            'topics' => $topics,
            'include_headlines' => $include_headlines,
            'post_status' => $post_status,
            'post_type' => $post_type,
            'generate_image' => $generate_image,
            // Ver nota en ajax_generate_article sobre el autor y el contexto cron
            'post_author' => absint($_POST['post_author'] ?? 0) ?: get_current_user_id()
        ));
        wp_send_json_success(array('job_id' => $job_id));
    }

    /**
     * AJAX: Consultar el estado de un trabajo en segundo plano (polling)
     */
    public function ajax_job_status() {
        check_ajax_referer('aicg_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Sin permisos suficientes', 'ai-content-generator'));
        }

        $job_id = sanitize_text_field(wp_unslash($_POST['job_id'] ?? ''));
        $job = AICG_Background_Jobs::get($job_id);

        if (!$job) {
            // Aún no existe el transient o expiró: pedir al cliente que siga esperando
            wp_send_json_success(array('status' => 'queued', 'percent' => 0, 'message' => ''));
        }

        wp_send_json_success(array(
            'status'  => $job['status'],
            'percent' => isset($job['percent']) ? $job['percent'] : 0,
            'message' => isset($job['message']) ? $job['message'] : '',
            'result'  => isset($job['result']) ? $job['result'] : null,
            'error'   => isset($job['error']) ? $job['error'] : null,
        ));
    }

    /**
     * Ejecutar una generación programada manualmente ("Ejecutar ahora")
     */
    public function handle_run_now() {
        check_admin_referer('aicg_run_now');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sin permisos suficientes', 'ai-content-generator'));
        }

        $type = isset($_GET['type']) && $_GET['type'] === 'news' ? 'news' : 'article';

        // Encolar como trabajo en segundo plano para no bloquear la respuesta
        if ($type === 'news') {
            AICG_Background_Jobs::enqueue('news', array(
                'include_headlines' => true,
                'post_status' => get_option('aicg_schedule_post_status', 'draft'),
            ));
        } else {
            $topics = get_option('aicg_article_topics', array());
            $topic = !empty($topics) ? $topics[array_rand($topics)] : '';
            AICG_Background_Jobs::enqueue('article', array(
                'topic' => $topic,
                'post_status' => get_option('aicg_schedule_post_status', 'draft'),
                'generate_image' => true,
            ));
        }

        $referer = wp_get_referer();
        $redirect = $referer ? add_query_arg('aicg_ran', $type, $referer) : admin_url('admin.php?page=aicg-dashboard');
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Limpiar el registro de errores de cron desde el dashboard
     */
    public function handle_clear_cron_errors() {
        check_admin_referer('aicg_clear_cron_errors');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sin permisos suficientes', 'ai-content-generator'));
        }

        delete_option('aicg_cron_errors');

        $referer = wp_get_referer();
        wp_safe_redirect($referer ? $referer : admin_url('admin.php?page=aicg-dashboard'));
        exit;
    }
}
