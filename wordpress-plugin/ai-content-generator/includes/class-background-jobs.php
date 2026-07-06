<?php
/**
 * Cola de trabajos en segundo plano
 *
 * @package AI_Content_Generator
 * @since 2.10.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ejecuta la generación de contenido de forma asíncrona.
 *
 * La petición del navegador encola un trabajo y devuelve un job_id de
 * inmediato; la generación real (que puede tardar minutos) corre en una
 * tarea de cron programada al instante y disparada por un loopback no
 * bloqueante. El estado y el progreso se guardan en un transient que el
 * navegador consulta por polling. Así se evita el timeout de la petición
 * AJAX síncrona y el progreso mostrado es real, no simulado.
 */
class AICG_Background_Jobs {

    /**
     * Hook de cron que ejecuta un trabajo
     */
    const HOOK = 'aicg_run_job';

    /**
     * Vigencia del transient de un trabajo (segundos)
     */
    const TTL = 1800; // 30 min

    /**
     * job_id del trabajo en ejecución en este request (para el progreso)
     *
     * @var string|null
     */
    private static $current_job_id = null;

    /**
     * Registrar el hook de ejecución
     */
    public static function init() {
        add_action(self::HOOK, array(__CLASS__, 'run_job'), 10, 1);
    }

    /**
     * Clave del transient de un trabajo
     *
     * @param string $job_id
     * @return string
     */
    private static function key($job_id) {
        return 'aicg_job_' . $job_id;
    }

    /**
     * Crear y encolar un trabajo
     *
     * @param string $type 'article' o 'news'
     * @param array  $args Argumentos de generación (ya sanitizados)
     * @return string job_id
     */
    public static function enqueue($type, $args) {
        // ID sin depender de random (no disponible en algunos contextos):
        // suficiente unicidad con uniqid + el usuario actual
        $job_id = uniqid('', true);
        $job_id = str_replace('.', '', $job_id) . get_current_user_id();

        self::update($job_id, array(
            'type'    => $type,
            'status'  => 'queued',
            'percent' => 0,
            'message' => __('En cola...', 'ai-content-generator'),
            'result'  => null,
            'error'   => null,
            'args'    => $args,
        ));

        // Programar la ejecución inmediata y dispararla con un loopback
        if (!wp_next_scheduled(self::HOOK, array($job_id))) {
            wp_schedule_single_event(time(), self::HOOK, array($job_id));
        }
        self::spawn_cron();

        return $job_id;
    }

    /**
     * Disparar WP-Cron con una petición loopback no bloqueante para que el
     * trabajo empiece cuanto antes sin esperar al siguiente tick natural.
     */
    private static function spawn_cron() {
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            // El sitio usa cron real del sistema: el evento correrá en su tick
            return;
        }

        $url = site_url('wp-cron.php?doing_wp_cron=' . sprintf('%.22F', microtime(true)));
        wp_remote_post($url, array(
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ));
    }

    /**
     * Ejecutar un trabajo encolado (callback de cron)
     *
     * @param string $job_id
     */
    public static function run_job($job_id) {
        $job = self::get($job_id);
        if (!$job || $job['status'] !== 'queued') {
            return; // Ya procesado o inexistente
        }

        self::$current_job_id = $job_id;
        add_action('aicg_progress', array(__CLASS__, 'on_progress'), 10, 2);

        self::update($job_id, array(
            'status'  => 'running',
            'percent' => 5,
            'message' => __('Iniciando generación...', 'ai-content-generator'),
        ));

        try {
            if ($job['type'] === 'article') {
                $generator = new AICG_Article_Generator();
                $result = $generator->generate($job['args']);
            } else {
                $aggregator = new AICG_News_Aggregator();
                $result = $aggregator->generate($job['args']);
            }

            if (is_wp_error($result)) {
                self::update($job_id, array(
                    'status'  => 'error',
                    'percent' => 100,
                    'message' => __('Error', 'ai-content-generator'),
                    'error'   => $result->get_error_message(),
                ));
            } else {
                self::update($job_id, array(
                    'status'  => 'done',
                    'percent' => 100,
                    'message' => __('Completado', 'ai-content-generator'),
                    'result'  => self::format_result($job['type'], $result),
                ));
            }
        } catch (Exception $e) {
            self::update($job_id, array(
                'status'  => 'error',
                'percent' => 100,
                'message' => __('Error', 'ai-content-generator'),
                'error'   => $e->getMessage(),
            ));
        }

        remove_action('aicg_progress', array(__CLASS__, 'on_progress'), 10);
        self::$current_job_id = null;
    }

    /**
     * Escuchar el progreso emitido por los generadores
     *
     * @param int    $percent
     * @param string $message
     */
    public static function on_progress($percent, $message) {
        if (self::$current_job_id === null) {
            return;
        }
        self::update(self::$current_job_id, array(
            'percent' => max(5, min(95, (int) $percent)),
            'message' => (string) $message,
        ));
    }

    /**
     * Dar formato a la respuesta para el navegador (mismos campos que antes)
     *
     * @param string $type
     * @param array  $result
     * @return array
     */
    private static function format_result($type, $result) {
        $common = array(
            'post_id'     => $result['post_id'],
            'title'       => isset($result['title']) ? $result['title'] : '',
            'tokens_used' => isset($result['tokens_used']) ? $result['tokens_used'] : 0,
            'cost'        => number_format(isset($result['cost']) ? $result['cost'] : 0, 4),
            'edit_url'    => get_edit_post_link($result['post_id'], 'raw'),
            'view_url'    => get_permalink($result['post_id']),
        );

        if ($type === 'article') {
            $common['topic'] = isset($result['topic']) ? $result['topic'] : '';
            $common['message'] = sprintf(
                __('Artículo "%s" generado exitosamente.', 'ai-content-generator'),
                $common['title']
            );
        } else {
            $common['news_count'] = isset($result['news_count']) ? $result['news_count'] : 0;
            $common['topics_processed'] = isset($result['topics_processed']) ? $result['topics_processed'] : array();
            $common['topics_details'] = isset($result['topics_details']) ? $result['topics_details'] : array();
            $common['message'] = sprintf(
                __('Resumen de noticias generado con %d noticias de %d temas.', 'ai-content-generator'),
                $common['news_count'],
                count($common['topics_processed'])
            );
        }

        return $common;
    }

    /**
     * Obtener el estado de un trabajo
     *
     * @param string $job_id
     * @return array|false
     */
    public static function get($job_id) {
        return get_transient(self::key($job_id));
    }

    /**
     * Actualizar (fusionando) el estado de un trabajo
     *
     * @param string $job_id
     * @param array  $data
     */
    private static function update($job_id, $data) {
        $existing = get_transient(self::key($job_id));
        if (!is_array($existing)) {
            $existing = array();
        }
        set_transient(self::key($job_id), array_merge($existing, $data), self::TTL);
    }
}
