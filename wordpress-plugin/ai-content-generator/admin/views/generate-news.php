<?php
/**
 * Vista para generar resúmenes de noticias
 *
 * @package AI_Content_Generator
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$news_topics = get_option('aicg_news_topics', array());
?>

<div class="wrap aicg-generate-news">
    <h1>
        <span class="dashicons dashicons-rss"></span>
        <?php esc_html_e('Generar Resumen de Noticias', 'ai-content-generator'); ?>
    </h1>

    <?php if (empty($news_topics)) : ?>
    <div class="notice notice-warning">
        <p>
            <?php printf(
                esc_html__('No hay temas de noticias configurados. %sConfigura los temas%s para generar resúmenes.', 'ai-content-generator'),
                '<a href="' . admin_url('admin.php?page=aicg-settings#news-settings') . '">',
                '</a>'
            ); ?>
        </p>
    </div>
    <?php endif; ?>

    <div class="aicg-generate-container">
        <form id="aicg-news-form" class="aicg-generate-form">
            <?php wp_nonce_field('aicg_admin_nonce', 'aicg_nonce'); ?>

            <div class="aicg-form-section">
                <h2><?php esc_html_e('Configuración del Resumen', 'ai-content-generator'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label><?php esc_html_e('Temas a Incluir', 'ai-content-generator'); ?></label>
                        </th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" id="aicg_select_all_topics" checked>
                                    <strong><?php esc_html_e('Seleccionar todos', 'ai-content-generator'); ?></strong>
                                </label>
                                <br><br>
                                <?php foreach ($news_topics as $index => $topic) : ?>
                                    <label>
                                        <input type="checkbox"
                                               name="topics[]"
                                               value="<?php echo esc_attr($topic['nombre']); ?>"
                                               class="aicg-topic-checkbox"
                                               checked>
                                        <?php echo esc_html($topic['nombre']); ?>
                                    </label>
                                    <br>
                                <?php endforeach; ?>
                            </fieldset>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="aicg_include_headlines"><?php esc_html_e('Resumen General', 'ai-content-generator'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="include_headlines" id="aicg_include_headlines" value="1" checked>
                                <?php esc_html_e('Incluir resumen de titulares principales', 'ai-content-generator'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Agrega un resumen general de las noticias más importantes del día.', 'ai-content-generator'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="aicg_news_status"><?php esc_html_e('Estado del Post', 'ai-content-generator'); ?></label>
                        </th>
                        <td>
                            <select name="post_status" id="aicg_news_status">
                                <option value="draft"><?php esc_html_e('Borrador', 'ai-content-generator'); ?></option>
                                <option value="publish"><?php esc_html_e('Publicado', 'ai-content-generator'); ?></option>
                                <option value="pending"><?php esc_html_e('Pendiente de revisión', 'ai-content-generator'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="aicg-form-section">
                <h2><?php esc_html_e('Vista Previa de Fuentes', 'ai-content-generator'); ?></h2>
                <p class="description">
                    <?php esc_html_e('El sistema buscará noticias en Google News para cada tema seleccionado.', 'ai-content-generator'); ?>
                </p>

                <div class="aicg-sources-preview">
                    <ul>
                        <li>
                            <span class="dashicons dashicons-rss"></span>
                            <?php esc_html_e('Google News - Titulares Principales', 'ai-content-generator'); ?>
                        </li>
                        <li>
                            <span class="dashicons dashicons-rss"></span>
                            <?php esc_html_e('Google News - Noticias Nacionales', 'ai-content-generator'); ?>
                        </li>
                        <li>
                            <span class="dashicons dashicons-rss"></span>
                            <?php esc_html_e('Google News - Breaking News', 'ai-content-generator'); ?>
                        </li>
                        <?php foreach ($news_topics as $topic) : ?>
                        <li>
                            <span class="dashicons dashicons-rss"></span>
                            <?php printf(
                                esc_html__('Google News - Búsqueda: "%s"', 'ai-content-generator'),
                                esc_html($topic['nombre'])
                            ); ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <div class="aicg-form-actions">
                <button type="submit" class="button button-primary button-hero" id="aicg_generate_news_btn">
                    <span class="dashicons dashicons-rss"></span>
                    <?php esc_html_e('Generar Resumen de Noticias', 'ai-content-generator'); ?>
                </button>
            </div>
        </form>

        <!-- Área de progreso -->
        <div id="aicg-news-progress-area" style="display: none;">
            <div class="aicg-progress-card">
                <h3><?php esc_html_e('Generando Resumen de Noticias...', 'ai-content-generator'); ?></h3>

                <div class="aicg-progress-steps">
                    <div class="aicg-step" id="news-step-headlines">
                        <span class="aicg-step-icon"><span class="dashicons dashicons-megaphone"></span></span>
                        <span class="aicg-step-text"><?php esc_html_e('Obteniendo titulares principales...', 'ai-content-generator'); ?></span>
                    </div>
                    <div class="aicg-step" id="news-step-topics">
                        <span class="aicg-step-icon"><span class="dashicons dashicons-rss"></span></span>
                        <span class="aicg-step-text"><?php esc_html_e('Buscando noticias por tema...', 'ai-content-generator'); ?></span>
                    </div>
                    <div class="aicg-step" id="news-step-summary">
                        <span class="aicg-step-icon"><span class="dashicons dashicons-edit"></span></span>
                        <span class="aicg-step-text"><?php esc_html_e('Generando resúmenes con IA...', 'ai-content-generator'); ?></span>
                    </div>
                    <div class="aicg-step" id="news-step-publish">
                        <span class="aicg-step-icon"><span class="dashicons dashicons-upload"></span></span>
                        <span class="aicg-step-text"><?php esc_html_e('Publicando en WordPress...', 'ai-content-generator'); ?></span>
                    </div>
                </div>

                <div class="aicg-progress-bar">
                    <div class="aicg-progress-fill" id="aicg-news-progress-fill"></div>
                </div>

                <p class="aicg-progress-status" id="aicg-news-status-text">
                    <?php esc_html_e('Iniciando...', 'ai-content-generator'); ?>
                </p>
            </div>
        </div>

        <!-- Resultado -->
        <div id="aicg-news-result-area" style="display: none;">
            <div class="aicg-result-card aicg-result-success">
                <span class="dashicons dashicons-yes-alt"></span>
                <h3><?php esc_html_e('Resumen de Noticias Generado', 'ai-content-generator'); ?></h3>
                <p id="aicg-news-result-message"></p>
                <div class="aicg-result-stats" id="aicg-news-stats"></div>
                <div class="aicg-result-actions">
                    <a href="#" id="aicg-view-news-post" class="button button-primary" target="_blank">
                        <?php esc_html_e('Ver Resumen', 'ai-content-generator'); ?>
                    </a>
                    <a href="#" id="aicg-edit-news-post" class="button" target="_blank">
                        <?php esc_html_e('Editar', 'ai-content-generator'); ?>
                    </a>
                    <button type="button" class="button" id="aicg-generate-another-news">
                        <?php esc_html_e('Generar Otro', 'ai-content-generator'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Error -->
        <div id="aicg-news-error-area" style="display: none;">
            <div class="aicg-result-card aicg-result-error">
                <span class="dashicons dashicons-warning"></span>
                <h3><?php esc_html_e('Error al Generar Noticias', 'ai-content-generator'); ?></h3>
                <p id="aicg-news-error-message"></p>
                <button type="button" class="button" id="aicg-news-try-again">
                    <?php esc_html_e('Intentar de Nuevo', 'ai-content-generator'); ?>
                </button>
            </div>
        </div>
    </div>
</div>
