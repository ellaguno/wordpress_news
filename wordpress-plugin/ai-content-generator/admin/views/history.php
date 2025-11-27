<?php
/**
 * Vista del historial de generaciones
 *
 * @package AI_Content_Generator
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$table_name = $wpdb->prefix . 'aicg_history';

// Paginación
$per_page = 20;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$offset = ($current_page - 1) * $per_page;

// Filtros
$type_filter = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
$provider_filter = isset($_GET['provider']) ? sanitize_text_field($_GET['provider']) : '';

// Construir query
$where = array('1=1');
$params = array();

if ($type_filter) {
    $where[] = 'type = %s';
    $params[] = $type_filter;
}

if ($provider_filter) {
    $where[] = 'provider = %s';
    $params[] = $provider_filter;
}

$where_clause = implode(' AND ', $where);

// Total de registros
$total_query = "SELECT COUNT(*) FROM $table_name WHERE $where_clause";
$total_items = $wpdb->get_var($params ? $wpdb->prepare($total_query, $params) : $total_query);
$total_pages = ceil($total_items / $per_page);

// Obtener registros
$query = "SELECT * FROM $table_name WHERE $where_clause ORDER BY created_at DESC LIMIT %d OFFSET %d";
$params[] = $per_page;
$params[] = $offset;
$items = $wpdb->get_results($wpdb->prepare($query, $params));

// Estadísticas
$stats = $wpdb->get_row("
    SELECT
        SUM(tokens_used) as total_tokens,
        SUM(cost) as total_cost,
        COUNT(CASE WHEN type = 'article' THEN 1 END) as articles,
        COUNT(CASE WHEN type = 'news' THEN 1 END) as news
    FROM $table_name
");
?>

<div class="wrap aicg-history">
    <h1>
        <span class="dashicons dashicons-backup"></span>
        <?php esc_html_e('Historial de Generaciones', 'ai-content-generator'); ?>
    </h1>

    <!-- Estadísticas resumen -->
    <div class="aicg-history-stats">
        <div class="aicg-stat-box">
            <span class="aicg-stat-value"><?php echo intval($stats->articles); ?></span>
            <span class="aicg-stat-label"><?php esc_html_e('Artículos', 'ai-content-generator'); ?></span>
        </div>
        <div class="aicg-stat-box">
            <span class="aicg-stat-value"><?php echo intval($stats->news); ?></span>
            <span class="aicg-stat-label"><?php esc_html_e('Noticias', 'ai-content-generator'); ?></span>
        </div>
        <div class="aicg-stat-box">
            <span class="aicg-stat-value"><?php echo number_format(intval($stats->total_tokens)); ?></span>
            <span class="aicg-stat-label"><?php esc_html_e('Tokens Totales', 'ai-content-generator'); ?></span>
        </div>
        <div class="aicg-stat-box">
            <span class="aicg-stat-value">$<?php echo number_format(floatval($stats->total_cost), 2); ?></span>
            <span class="aicg-stat-label"><?php esc_html_e('Costo Total', 'ai-content-generator'); ?></span>
        </div>
    </div>

    <!-- Filtros -->
    <div class="aicg-history-filters">
        <form method="get" action="">
            <input type="hidden" name="page" value="aicg-history">

            <select name="type">
                <option value=""><?php esc_html_e('Todos los tipos', 'ai-content-generator'); ?></option>
                <option value="article" <?php selected($type_filter, 'article'); ?>><?php esc_html_e('Artículos', 'ai-content-generator'); ?></option>
                <option value="news" <?php selected($type_filter, 'news'); ?>><?php esc_html_e('Noticias', 'ai-content-generator'); ?></option>
            </select>

            <select name="provider">
                <option value=""><?php esc_html_e('Todos los proveedores', 'ai-content-generator'); ?></option>
                <option value="openai" <?php selected($provider_filter, 'openai'); ?>>OpenAI</option>
                <option value="anthropic" <?php selected($provider_filter, 'anthropic'); ?>>Anthropic</option>
                <option value="deepseek" <?php selected($provider_filter, 'deepseek'); ?>>DeepSeek</option>
                <option value="openrouter" <?php selected($provider_filter, 'openrouter'); ?>>OpenRouter</option>
            </select>

            <button type="submit" class="button"><?php esc_html_e('Filtrar', 'ai-content-generator'); ?></button>

            <?php if ($type_filter || $provider_filter) : ?>
                <a href="<?php echo admin_url('admin.php?page=aicg-history'); ?>" class="button">
                    <?php esc_html_e('Limpiar filtros', 'ai-content-generator'); ?>
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Tabla de historial -->
    <?php if (empty($items)) : ?>
        <div class="aicg-no-results">
            <p><?php esc_html_e('No se encontraron registros.', 'ai-content-generator'); ?></p>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th class="column-date"><?php esc_html_e('Fecha', 'ai-content-generator'); ?></th>
                    <th class="column-type"><?php esc_html_e('Tipo', 'ai-content-generator'); ?></th>
                    <th class="column-topic"><?php esc_html_e('Tema', 'ai-content-generator'); ?></th>
                    <th class="column-provider"><?php esc_html_e('Proveedor', 'ai-content-generator'); ?></th>
                    <th class="column-model"><?php esc_html_e('Modelo', 'ai-content-generator'); ?></th>
                    <th class="column-tokens"><?php esc_html_e('Tokens', 'ai-content-generator'); ?></th>
                    <th class="column-cost"><?php esc_html_e('Costo', 'ai-content-generator'); ?></th>
                    <th class="column-actions"><?php esc_html_e('Acciones', 'ai-content-generator'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item) : ?>
                <tr>
                    <td class="column-date">
                        <?php echo esc_html(date_i18n(
                            get_option('date_format') . ' ' . get_option('time_format'),
                            strtotime($item->created_at)
                        )); ?>
                    </td>
                    <td class="column-type">
                        <?php if ($item->type === 'article') : ?>
                            <span class="aicg-type-badge aicg-type-article">
                                <span class="dashicons dashicons-media-document"></span>
                                <?php esc_html_e('Artículo', 'ai-content-generator'); ?>
                            </span>
                        <?php else : ?>
                            <span class="aicg-type-badge aicg-type-news">
                                <span class="dashicons dashicons-rss"></span>
                                <?php esc_html_e('Noticias', 'ai-content-generator'); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="column-topic">
                        <strong><?php echo esc_html(wp_trim_words($item->topic, 8)); ?></strong>
                    </td>
                    <td class="column-provider">
                        <code><?php echo esc_html($item->provider); ?></code>
                    </td>
                    <td class="column-model">
                        <code><?php echo esc_html($item->model); ?></code>
                    </td>
                    <td class="column-tokens">
                        <?php echo number_format($item->tokens_used); ?>
                    </td>
                    <td class="column-cost">
                        $<?php echo number_format($item->cost, 4); ?>
                    </td>
                    <td class="column-actions">
                        <?php if ($item->post_id) : ?>
                            <a href="<?php echo get_permalink($item->post_id); ?>" target="_blank" class="button button-small" title="<?php esc_attr_e('Ver', 'ai-content-generator'); ?>">
                                <span class="dashicons dashicons-visibility"></span>
                            </a>
                            <a href="<?php echo get_edit_post_link($item->post_id); ?>" target="_blank" class="button button-small" title="<?php esc_attr_e('Editar', 'ai-content-generator'); ?>">
                                <span class="dashicons dashicons-edit"></span>
                            </a>
                        <?php else : ?>
                            <span class="aicg-no-post">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Paginación -->
        <?php if ($total_pages > 1) : ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php printf(
                        esc_html(_n('%s elemento', '%s elementos', $total_items, 'ai-content-generator')),
                        number_format($total_items)
                    ); ?>
                </span>
                <span class="pagination-links">
                    <?php
                    $base_url = admin_url('admin.php?page=aicg-history');
                    if ($type_filter) $base_url .= '&type=' . $type_filter;
                    if ($provider_filter) $base_url .= '&provider=' . $provider_filter;

                    if ($current_page > 1) : ?>
                        <a class="first-page button" href="<?php echo esc_url($base_url . '&paged=1'); ?>">
                            <span class="screen-reader-text"><?php esc_html_e('Primera página', 'ai-content-generator'); ?></span>
                            <span aria-hidden="true">&laquo;</span>
                        </a>
                        <a class="prev-page button" href="<?php echo esc_url($base_url . '&paged=' . ($current_page - 1)); ?>">
                            <span class="screen-reader-text"><?php esc_html_e('Página anterior', 'ai-content-generator'); ?></span>
                            <span aria-hidden="true">&lsaquo;</span>
                        </a>
                    <?php endif; ?>

                    <span class="paging-input">
                        <span class="tablenav-paging-text">
                            <?php echo intval($current_page); ?> de <span class="total-pages"><?php echo intval($total_pages); ?></span>
                        </span>
                    </span>

                    <?php if ($current_page < $total_pages) : ?>
                        <a class="next-page button" href="<?php echo esc_url($base_url . '&paged=' . ($current_page + 1)); ?>">
                            <span class="screen-reader-text"><?php esc_html_e('Página siguiente', 'ai-content-generator'); ?></span>
                            <span aria-hidden="true">&rsaquo;</span>
                        </a>
                        <a class="last-page button" href="<?php echo esc_url($base_url . '&paged=' . $total_pages); ?>">
                            <span class="screen-reader-text"><?php esc_html_e('Última página', 'ai-content-generator'); ?></span>
                            <span aria-hidden="true">&raquo;</span>
                        </a>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
