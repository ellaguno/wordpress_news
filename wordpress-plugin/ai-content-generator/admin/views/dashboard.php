<?php
/**
 * Vista del Dashboard principal
 *
 * @package AI_Content_Generator
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Estas variables son locales al método que incluye esta plantilla, no globales.

// Obtener estadísticas
global $wpdb;
$table_history = $wpdb->prefix . 'aicg_history';

$stats = array(
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tabla propia del plugin; nombre desde $wpdb->prefix, sin input de usuario sin preparar.
    'total_articles' => $wpdb->get_var("SELECT COUNT(*) FROM $table_history WHERE type = 'article' AND status = 'success'"),
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tabla propia del plugin; nombre desde $wpdb->prefix, sin input de usuario sin preparar.
    'total_news' => $wpdb->get_var("SELECT COUNT(*) FROM $table_history WHERE type = 'news' AND status = 'success'"),
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tabla propia del plugin; nombre desde $wpdb->prefix, sin input de usuario sin preparar.
    'total_tokens' => $wpdb->get_var("SELECT SUM(tokens_used) FROM $table_history"),
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tabla propia del plugin; nombre desde $wpdb->prefix, sin input de usuario sin preparar.
    'total_cost' => $wpdb->get_var("SELECT SUM(cost) FROM $table_history"),
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tabla propia del plugin; nombre desde $wpdb->prefix, sin input de usuario sin preparar.
    'last_7_days' => $wpdb->get_var("SELECT COUNT(*) FROM $table_history WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tabla propia del plugin; nombre desde $wpdb->prefix, sin input de usuario sin preparar.
    'month_cost' => $wpdb->get_var("SELECT SUM(cost) FROM $table_history WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")
);

// Próximas ejecuciones programadas
$scheduled_events = class_exists('AICG_Cron_Scheduler') ? AICG_Cron_Scheduler::get_scheduled_events() : array();

// Verificar configuración
$provider = get_option('aicg_ai_provider', 'openai');
$api_key_option = 'aicg_' . $provider . '_api_key';
$is_configured = !empty(get_option($api_key_option, ''));
?>

<div class="wrap aicg-dashboard">
    <h1>
        <span class="dashicons dashicons-edit-page"></span>
        <?php esc_html_e('AI Content Generator', 'ai-content-generator'); ?>
    </h1>

    <?php if (!$is_configured) : ?>
    <div class="notice notice-warning">
        <p>
            <strong><?php esc_html_e('Configuración requerida:', 'ai-content-generator'); ?></strong>
            <?php
            /* translators: %1$s: nombre del proveedor de IA. %2$s: etiqueta de apertura del enlace. %3$s: etiqueta de cierre del enlace. */
            printf(
                esc_html__('Por favor, configura tu API Key de %1$s en la página de %2$sConfiguración%3$s.', 'ai-content-generator'),
                esc_html(ucfirst($provider)),
                '<a href="' . esc_url(admin_url('admin.php?page=aicg-settings')) . '">',
                '</a>'
            ); ?>
        </p>
    </div>
    <?php endif; ?>

    <?php
    // Errores recientes de las tareas programadas
    $cron_errors = get_option('aicg_cron_errors', array());
    if (!empty($cron_errors)) :
    ?>
    <div class="notice notice-error">
        <p>
            <strong><?php esc_html_e('Errores recientes de la generación programada:', 'ai-content-generator'); ?></strong>
        </p>
        <ul style="list-style: disc; margin-left: 20px;">
            <?php foreach (array_reverse(array_slice($cron_errors, -5)) as $error) : ?>
                <li>
                    <code><?php echo esc_html($error['time']); ?></code>
                    [<?php echo esc_html($error['type'] === 'article' ? __('Artículo', 'ai-content-generator') : __('Noticias', 'ai-content-generator')); ?>]
                    <?php echo esc_html($error['message']); ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=aicg_clear_cron_errors'), 'aicg_clear_cron_errors')); ?>" class="button">
                <?php esc_html_e('Limpiar errores', 'ai-content-generator'); ?>
            </a>
        </p>
    </div>
    <?php endif; ?>

    <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filtro de listado de solo lectura, sin acción que cambie estado. ?>
    <?php if (isset($_GET['aicg_ran'])) : ?>
    <div class="notice notice-success is-dismissible">
        <p>
            <?php esc_html_e('Generación encolada. Se está ejecutando en segundo plano; aparecerá en el Historial en unos minutos.', 'ai-content-generator'); ?>
        </p>
    </div>
    <?php endif; ?>

    <!-- Tarjetas de estadísticas -->
    <div class="aicg-stats-grid">
        <div class="aicg-stat-card">
            <div class="aicg-stat-icon">
                <span class="dashicons dashicons-media-document"></span>
            </div>
            <div class="aicg-stat-content">
                <span class="aicg-stat-number"><?php echo intval($stats['total_articles']); ?></span>
                <span class="aicg-stat-label"><?php esc_html_e('Artículos Generados', 'ai-content-generator'); ?></span>
            </div>
        </div>

        <div class="aicg-stat-card">
            <div class="aicg-stat-icon">
                <span class="dashicons dashicons-rss"></span>
            </div>
            <div class="aicg-stat-content">
                <span class="aicg-stat-number"><?php echo intval($stats['total_news']); ?></span>
                <span class="aicg-stat-label"><?php esc_html_e('Resúmenes de Noticias', 'ai-content-generator'); ?></span>
            </div>
        </div>

        <div class="aicg-stat-card">
            <div class="aicg-stat-icon">
                <span class="dashicons dashicons-chart-bar"></span>
            </div>
            <div class="aicg-stat-content">
                <span class="aicg-stat-number"><?php echo number_format(intval($stats['total_tokens'])); ?></span>
                <span class="aicg-stat-label"><?php esc_html_e('Tokens Utilizados', 'ai-content-generator'); ?></span>
            </div>
        </div>

        <div class="aicg-stat-card">
            <div class="aicg-stat-icon">
                <span class="dashicons dashicons-money-alt"></span>
            </div>
            <div class="aicg-stat-content">
                <span class="aicg-stat-number">$<?php echo number_format(floatval($stats['total_cost']), 2); ?></span>
                <span class="aicg-stat-label"><?php esc_html_e('Costo Total', 'ai-content-generator'); ?></span>
            </div>
        </div>

        <div class="aicg-stat-card">
            <div class="aicg-stat-icon">
                <span class="dashicons dashicons-calendar-alt"></span>
            </div>
            <div class="aicg-stat-content">
                <span class="aicg-stat-number">$<?php echo number_format(floatval($stats['month_cost']), 2); ?></span>
                <span class="aicg-stat-label"><?php esc_html_e('Costo del Mes', 'ai-content-generator'); ?></span>
            </div>
        </div>
    </div>

    <!-- Programación y ejecución manual -->
    <div class="aicg-scheduled-runs">
        <h2><?php esc_html_e('Generación Automática', 'ai-content-generator'); ?></h2>
        <table class="aicg-status-table">
            <tr>
                <td><?php esc_html_e('Próximo artículo', 'ai-content-generator'); ?></td>
                <td>
                    <?php if (isset($scheduled_events['article'])) : ?>
                        <strong><?php echo esc_html($scheduled_events['article']['date']); ?></strong>
                        (<?php echo esc_html($scheduled_events['article']['frequency']); ?>)
                    <?php else : ?>
                        <span class="aicg-status-inactive"><?php esc_html_e('No programado', 'ai-content-generator'); ?></span>
                    <?php endif; ?>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=aicg_run_now&type=article'), 'aicg_run_now')); ?>" class="button button-small" style="margin-left:10px;">
                        <span class="dashicons dashicons-controls-play" style="vertical-align:middle;"></span>
                        <?php esc_html_e('Ejecutar ahora', 'ai-content-generator'); ?>
                    </a>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e('Próximas noticias', 'ai-content-generator'); ?></td>
                <td>
                    <?php if (isset($scheduled_events['news'])) : ?>
                        <strong><?php echo esc_html($scheduled_events['news']['date']); ?></strong>
                        (<?php echo esc_html($scheduled_events['news']['frequency']); ?>)
                    <?php else : ?>
                        <span class="aicg-status-inactive"><?php esc_html_e('No programado', 'ai-content-generator'); ?></span>
                    <?php endif; ?>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=aicg_run_now&type=news'), 'aicg_run_now')); ?>" class="button button-small" style="margin-left:10px;">
                        <span class="dashicons dashicons-controls-play" style="vertical-align:middle;"></span>
                        <?php esc_html_e('Ejecutar ahora', 'ai-content-generator'); ?>
                    </a>
                </td>
            </tr>
        </table>
    </div>

    <!-- Acciones rápidas -->
    <div class="aicg-quick-actions">
        <h2><?php esc_html_e('Acciones Rápidas', 'ai-content-generator'); ?></h2>

        <div class="aicg-actions-grid">
            <a href="<?php echo esc_url(admin_url('admin.php?page=aicg-generate-article')); ?>" class="aicg-action-card">
                <span class="dashicons dashicons-welcome-write-blog"></span>
                <h3><?php esc_html_e('Generar Artículo', 'ai-content-generator'); ?></h3>
                <p><?php esc_html_e('Crea un artículo completo con título, contenido e imagen.', 'ai-content-generator'); ?></p>
            </a>

            <a href="<?php echo esc_url(admin_url('admin.php?page=aicg-generate-news')); ?>" class="aicg-action-card">
                <span class="dashicons dashicons-rss"></span>
                <h3><?php esc_html_e('Generar Noticias', 'ai-content-generator'); ?></h3>
                <p><?php esc_html_e('Crea un resumen de noticias del día usando fuentes RSS.', 'ai-content-generator'); ?></p>
            </a>

            <a href="<?php echo esc_url(admin_url('admin.php?page=aicg-settings')); ?>" class="aicg-action-card">
                <span class="dashicons dashicons-admin-generic"></span>
                <h3><?php esc_html_e('Configuración', 'ai-content-generator'); ?></h3>
                <p><?php esc_html_e('Configura proveedores de IA, modelos y preferencias.', 'ai-content-generator'); ?></p>
            </a>

            <a href="<?php echo esc_url(admin_url('admin.php?page=aicg-history')); ?>" class="aicg-action-card">
                <span class="dashicons dashicons-backup"></span>
                <h3><?php esc_html_e('Historial', 'ai-content-generator'); ?></h3>
                <p><?php esc_html_e('Ver el historial de contenido generado.', 'ai-content-generator'); ?></p>
            </a>
        </div>
    </div>

    <!-- Estado del sistema -->
    <div class="aicg-system-status">
        <h2><?php esc_html_e('Estado del Sistema', 'ai-content-generator'); ?></h2>

        <table class="aicg-status-table">
            <tr>
                <td><?php esc_html_e('Proveedor Activo', 'ai-content-generator'); ?></td>
                <td>
                    <strong><?php echo esc_html(ucfirst($provider)); ?></strong>
                    <?php if ($is_configured) : ?>
                        <span class="aicg-status-ok dashicons dashicons-yes-alt"></span>
                    <?php else : ?>
                        <span class="aicg-status-error dashicons dashicons-warning"></span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e('Modelo de Texto', 'ai-content-generator'); ?></td>
                <td><code><?php echo esc_html(get_option('aicg_default_model', 'gpt-4o')); ?></code></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Modelo de Imágenes', 'ai-content-generator'); ?></td>
                <td><code><?php echo esc_html(get_option('aicg_image_model', 'dall-e-3')); ?></code></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Artículos Programados', 'ai-content-generator'); ?></td>
                <td>
                    <?php if (get_option('aicg_schedule_articles', false)) : ?>
                        <span class="aicg-status-ok"><?php esc_html_e('Activo', 'ai-content-generator'); ?></span>
                        (<?php echo esc_html(get_option('aicg_schedule_articles_frequency', 'daily')); ?>)
                    <?php else : ?>
                        <span class="aicg-status-inactive"><?php esc_html_e('Inactivo', 'ai-content-generator'); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e('Noticias Programadas', 'ai-content-generator'); ?></td>
                <td>
                    <?php if (get_option('aicg_schedule_news', false)) : ?>
                        <span class="aicg-status-ok"><?php esc_html_e('Activo', 'ai-content-generator'); ?></span>
                        (<?php echo esc_html(get_option('aicg_schedule_news_frequency', 'twicedaily')); ?>)
                    <?php else : ?>
                        <span class="aicg-status-inactive"><?php esc_html_e('Inactivo', 'ai-content-generator'); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e('Últimos 7 días', 'ai-content-generator'); ?></td>
                <td>
                    <?php
                    /* translators: %d: número de publicaciones generadas. */
                    printf(
                        esc_html__('%d publicaciones generadas', 'ai-content-generator'),
                        intval($stats['last_7_days'])
                    ); ?>
                </td>
            </tr>
        </table>
    </div>

    <!-- Últimas generaciones -->
    <?php
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tabla propia del plugin; nombre desde $wpdb->prefix, sin input de usuario sin preparar.
    $recent = $wpdb->get_results( "SELECT * FROM $table_history ORDER BY created_at DESC LIMIT 5" );
    ?>

    <?php if (!empty($recent)) : ?>
    <div class="aicg-recent-activity">
        <h2><?php esc_html_e('Actividad Reciente', 'ai-content-generator'); ?></h2>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Fecha', 'ai-content-generator'); ?></th>
                    <th><?php esc_html_e('Tipo', 'ai-content-generator'); ?></th>
                    <th><?php esc_html_e('Tema', 'ai-content-generator'); ?></th>
                    <th><?php esc_html_e('Proveedor', 'ai-content-generator'); ?></th>
                    <th><?php esc_html_e('Tokens', 'ai-content-generator'); ?></th>
                    <th><?php esc_html_e('Costo', 'ai-content-generator'); ?></th>
                    <th><?php esc_html_e('Post', 'ai-content-generator'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $item) : ?>
                <tr>
                    <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item->created_at))); ?></td>
                    <td>
                        <?php if ($item->type === 'article') : ?>
                            <span class="dashicons dashicons-media-document"></span>
                            <?php esc_html_e('Artículo', 'ai-content-generator'); ?>
                        <?php else : ?>
                            <span class="dashicons dashicons-rss"></span>
                            <?php esc_html_e('Noticias', 'ai-content-generator'); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html(wp_trim_words($item->topic, 5)); ?></td>
                    <td><code><?php echo esc_html($item->provider); ?></code></td>
                    <td><?php echo number_format($item->tokens_used); ?></td>
                    <td>$<?php echo number_format($item->cost, 4); ?></td>
                    <td>
                        <?php if ($item->post_id) : ?>
                            <a href="<?php echo esc_url(get_edit_post_link($item->post_id)); ?>" target="_blank">
                                #<?php echo intval($item->post_id); ?>
                            </a>
                        <?php else : ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=aicg-history')); ?>" class="button">
                <?php esc_html_e('Ver Historial Completo', 'ai-content-generator'); ?>
            </a>
        </p>
    </div>
    <?php endif; ?>
</div>
