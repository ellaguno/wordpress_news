<?php
/**
 * Programador de tareas Cron
 *
 * @package AI_Content_Generator
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para manejar la programación automática
 */
class AICG_Cron_Scheduler {

    /**
     * Constructor
     */
    public function __construct() {
        // Reprogramar tareas cuando se actualizan las opciones
        add_action('update_option_aicg_schedule_articles', array($this, 'reschedule_articles'), 10, 2);
        add_action('update_option_aicg_schedule_articles_frequency', array($this, 'reschedule_articles'), 10, 2);
        add_action('update_option_aicg_schedule_news', array($this, 'reschedule_news'), 10, 2);
        add_action('update_option_aicg_schedule_news_frequency', array($this, 'reschedule_news'), 10, 2);
    }

    /**
     * Añadir intervalos de cron personalizados
     *
     * @param array $schedules
     * @return array
     */
    public function add_cron_intervals($schedules) {
        // Intervalo semanal
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = array(
                'interval' => 604800, // 7 días
                'display' => __('Una vez a la semana', 'ai-content-generator')
            );
        }

        // Intervalo cada 6 horas
        if (!isset($schedules['sixhourly'])) {
            $schedules['sixhourly'] = array(
                'interval' => 21600, // 6 horas
                'display' => __('Cada 6 horas', 'ai-content-generator')
            );
        }

        // Intervalo cada 8 horas
        if (!isset($schedules['eighthourly'])) {
            $schedules['eighthourly'] = array(
                'interval' => 28800, // 8 horas
                'display' => __('Cada 8 horas', 'ai-content-generator')
            );
        }

        return $schedules;
    }

    /**
     * Ejecutar generación de artículo programado
     */
    public function run_scheduled_article() {
        // Verificar que esté habilitado
        if (!get_option('aicg_schedule_articles', false)) {
            return;
        }

        // Verificar que haya proveedor configurado
        $provider = AICG_AI_Provider_Factory::get_text_provider();
        if (is_wp_error($provider) || !$provider->is_configured()) {
            $this->log_error('article', __('Proveedor de IA no configurado', 'ai-content-generator'));
            return;
        }

        // Obtener tema aleatorio
        $topics = get_option('aicg_article_topics', array());
        if (empty($topics)) {
            $this->log_error('article', __('No hay temas configurados', 'ai-content-generator'));
            return;
        }

        $topic = $topics[array_rand($topics)];

        // Generar artículo
        $generator = new AICG_Article_Generator();
        $result = $generator->generate(array(
            'topic' => $topic,
            'post_status' => 'draft', // Por seguridad, siempre como borrador
            'generate_image' => true
        ));

        if (is_wp_error($result)) {
            $this->log_error('article', $result->get_error_message());
            return;
        }

        // Log de éxito
        $this->log_success('article', sprintf(
            __('Artículo generado: "%s" (Post ID: %d)', 'ai-content-generator'),
            $result['title'],
            $result['post_id']
        ));

        // Notificar al administrador (opcional)
        $this->maybe_notify_admin('article', $result);
    }

    /**
     * Ejecutar generación de noticias programadas
     */
    public function run_scheduled_news() {
        // Verificar que esté habilitado
        if (!get_option('aicg_schedule_news', false)) {
            return;
        }

        // Verificar proveedor
        $provider = AICG_AI_Provider_Factory::get_text_provider();
        if (is_wp_error($provider) || !$provider->is_configured()) {
            $this->log_error('news', __('Proveedor de IA no configurado', 'ai-content-generator'));
            return;
        }

        // Verificar temas
        $topics = get_option('aicg_news_topics', array());
        if (empty($topics)) {
            $this->log_error('news', __('No hay temas de noticias configurados', 'ai-content-generator'));
            return;
        }

        // Generar resumen de noticias
        $aggregator = new AICG_News_Aggregator();
        $result = $aggregator->generate(array(
            'include_headlines' => true,
            'post_status' => 'draft'
        ));

        if (is_wp_error($result)) {
            $this->log_error('news', $result->get_error_message());
            return;
        }

        // Log de éxito
        $this->log_success('news', sprintf(
            __('Resumen de noticias generado (Post ID: %d, Noticias: %d)', 'ai-content-generator'),
            $result['post_id'],
            $result['news_count']
        ));

        // Notificar al administrador
        $this->maybe_notify_admin('news', $result);
    }

    /**
     * Reprogramar artículos
     *
     * @param mixed $old_value
     * @param mixed $new_value
     */
    public function reschedule_articles($old_value = null, $new_value = null) {
        // Limpiar programación existente
        wp_clear_scheduled_hook('aicg_generate_scheduled_article');

        // Si está habilitado, programar nueva tarea
        if (get_option('aicg_schedule_articles', false)) {
            $frequency = get_option('aicg_schedule_articles_frequency', 'daily');
            wp_schedule_event(time() + 60, $frequency, 'aicg_generate_scheduled_article');
        }
    }

    /**
     * Reprogramar noticias
     *
     * @param mixed $old_value
     * @param mixed $new_value
     */
    public function reschedule_news($old_value = null, $new_value = null) {
        // Limpiar programación existente
        wp_clear_scheduled_hook('aicg_generate_scheduled_news');

        // Si está habilitado, programar nueva tarea
        if (get_option('aicg_schedule_news', false)) {
            $frequency = get_option('aicg_schedule_news_frequency', 'twicedaily');
            wp_schedule_event(time() + 60, $frequency, 'aicg_generate_scheduled_news');
        }
    }

    /**
     * Registrar error
     *
     * @param string $type
     * @param string $message
     */
    private function log_error($type, $message) {
        error_log(sprintf(
            '[AI Content Generator] Error en tarea programada (%s): %s',
            $type,
            $message
        ));

        // Guardar en opción para mostrar en admin
        $errors = get_option('aicg_cron_errors', array());
        $errors[] = array(
            'type' => $type,
            'message' => $message,
            'time' => current_time('mysql')
        );

        // Mantener solo los últimos 10 errores
        $errors = array_slice($errors, -10);
        update_option('aicg_cron_errors', $errors);
    }

    /**
     * Registrar éxito
     *
     * @param string $type
     * @param string $message
     */
    private function log_success($type, $message) {
        error_log(sprintf(
            '[AI Content Generator] Tarea programada exitosa (%s): %s',
            $type,
            $message
        ));
    }

    /**
     * Notificar al administrador (opcional)
     *
     * @param string $type
     * @param array  $result
     */
    private function maybe_notify_admin($type, $result) {
        // Verificar si las notificaciones están habilitadas
        if (!get_option('aicg_notify_admin', false)) {
            return;
        }

        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');

        if ($type === 'article') {
            $subject = sprintf(
                __('[%s] Nuevo artículo generado automáticamente', 'ai-content-generator'),
                $site_name
            );
            $message = sprintf(
                __("Se ha generado un nuevo artículo automáticamente:\n\nTítulo: %s\nTema: %s\nTokens usados: %d\nCosto estimado: $%s\n\nEditar: %s", 'ai-content-generator'),
                $result['title'],
                $result['topic'],
                $result['tokens_used'],
                number_format($result['cost'], 4),
                admin_url('post.php?post=' . $result['post_id'] . '&action=edit')
            );
        } else {
            $subject = sprintf(
                __('[%s] Nuevo resumen de noticias generado', 'ai-content-generator'),
                $site_name
            );
            $message = sprintf(
                __("Se ha generado un nuevo resumen de noticias:\n\nTemas: %s\nNoticias procesadas: %d\nTokens usados: %d\nCosto estimado: $%s\n\nEditar: %s", 'ai-content-generator'),
                implode(', ', $result['topics_processed']),
                $result['news_count'],
                $result['tokens_used'],
                number_format($result['cost'], 4),
                admin_url('post.php?post=' . $result['post_id'] . '&action=edit')
            );
        }

        wp_mail($admin_email, $subject, $message);
    }

    /**
     * Obtener próximas ejecuciones programadas
     *
     * @return array
     */
    public static function get_scheduled_events() {
        $events = array();

        $article_next = wp_next_scheduled('aicg_generate_scheduled_article');
        if ($article_next) {
            $events['article'] = array(
                'timestamp' => $article_next,
                'date' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $article_next),
                'frequency' => get_option('aicg_schedule_articles_frequency', 'daily')
            );
        }

        $news_next = wp_next_scheduled('aicg_generate_scheduled_news');
        if ($news_next) {
            $events['news'] = array(
                'timestamp' => $news_next,
                'date' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $news_next),
                'frequency' => get_option('aicg_schedule_news_frequency', 'twicedaily')
            );
        }

        return $events;
    }

    /**
     * Ejecutar tarea manualmente (para testing)
     *
     * @param string $type 'article' o 'news'
     * @return array|WP_Error
     */
    public static function run_now($type) {
        $scheduler = new self();

        if ($type === 'article') {
            return $scheduler->run_scheduled_article();
        } elseif ($type === 'news') {
            return $scheduler->run_scheduled_news();
        }

        return new WP_Error('invalid_type', __('Tipo de tarea no válido', 'ai-content-generator'));
    }
}
