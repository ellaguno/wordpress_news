<?php
/**
 * Logger del plugin
 *
 * @package AI_Content_Generator
 * @since 2.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logging condicionado: el detalle de depuración solo se escribe con
 * WP_DEBUG activo (o la opción aicg_debug_log), para no inundar el
 * error_log en producción. Los errores reales se registran siempre.
 */
class AICG_Logger {

    /**
     * Cache del flag de debug para no consultar la opción en cada llamada
     *
     * @var bool|null
     */
    private static $debug_enabled = null;

    /**
     * ¿Está habilitado el log de depuración?
     *
     * @return bool
     */
    public static function is_debug_enabled() {
        if (null === self::$debug_enabled) {
            self::$debug_enabled = (defined('WP_DEBUG') && WP_DEBUG)
                || (bool) get_option('aicg_debug_log', false);
        }
        return self::$debug_enabled;
    }

    /**
     * Log de depuración (solo con WP_DEBUG o aicg_debug_log)
     *
     * @param string $message
     */
    public static function debug($message) {
        if (self::is_debug_enabled()) {
            error_log($message);
        }
    }

    /**
     * Log de error (siempre se escribe)
     *
     * @param string $message
     */
    public static function error($message) {
        error_log($message);
    }
}
