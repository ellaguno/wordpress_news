<?php
/**
 * Uninstall AI Content Generator
 *
 * @package AI_Content_Generator
 * @since 1.0.0
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Eliminar opciones
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Limpieza de desinstalación; una sola vez, sin caché aplicable.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'aicg_%'");

// Eliminar tablas
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Limpieza de desinstalación de tablas propias.
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}aicg_history");
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Limpieza de desinstalación de tablas propias.
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}aicg_used_urls");

// Limpiar cron jobs
wp_clear_scheduled_hook('aicg_generate_scheduled_article');
wp_clear_scheduled_hook('aicg_generate_scheduled_news');

// Limpiar transients
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Limpieza de desinstalación; una sola vez, sin caché aplicable.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_aicg_%'");
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Limpieza de desinstalación; una sola vez, sin caché aplicable.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_aicg_%'");

// Nota: No eliminamos posts generados ni sus meta datos
// ya que podrían ser contenido valioso para el usuario
