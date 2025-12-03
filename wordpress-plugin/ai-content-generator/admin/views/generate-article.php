<?php
/**
 * Vista para generar artículos
 *
 * @package AI_Content_Generator
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$topics = get_option('aicg_article_topics', array());
$categories = get_categories(array('hide_empty' => false));
?>

<div class="wrap aicg-generate-article">
    <h1>
        <span class="dashicons dashicons-welcome-write-blog"></span>
        <?php esc_html_e('Generar Artículo', 'ai-content-generator'); ?>
    </h1>

    <div class="aicg-generate-container">
        <form id="aicg-article-form" class="aicg-generate-form">
            <?php wp_nonce_field('aicg_admin_nonce', 'aicg_nonce'); ?>

            <div class="aicg-form-section">
                <h2><?php esc_html_e('Configuración del Artículo', 'ai-content-generator'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="aicg_topic_type"><?php esc_html_e('Tipo de Tema', 'ai-content-generator'); ?></label>
                        </th>
                        <td>
                            <select name="topic_type" id="aicg_topic_type">
                                <option value="preset"><?php esc_html_e('Tema predefinido', 'ai-content-generator'); ?></option>
                                <option value="random"><?php esc_html_e('Aleatorio de la lista', 'ai-content-generator'); ?></option>
                                <option value="custom"><?php esc_html_e('Tema personalizado', 'ai-content-generator'); ?></option>
                            </select>
                        </td>
                    </tr>

                    <tr id="aicg_preset_row">
                        <th scope="row">
                            <label for="aicg_preset_topic"><?php esc_html_e('Seleccionar Tema', 'ai-content-generator'); ?></label>
                        </th>
                        <td>
                            <select name="preset_topic" id="aicg_preset_topic" class="regular-text">
                                <?php foreach ($topics as $topic) : ?>
                                    <option value="<?php echo esc_attr($topic); ?>"><?php echo esc_html($topic); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>

                    <tr id="aicg_custom_row" style="display: none;">
                        <th scope="row">
                            <label for="aicg_custom_topic"><?php esc_html_e('Tema Personalizado', 'ai-content-generator'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   name="custom_topic"
                                   id="aicg_custom_topic"
                                   class="regular-text"
                                   placeholder="<?php esc_attr_e('Ej: Inteligencia Artificial en la medicina', 'ai-content-generator'); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="aicg_category"><?php esc_html_e('Categoría', 'ai-content-generator'); ?></label>
                        </th>
                        <td>
                            <select name="category" id="aicg_category">
                                <option value=""><?php esc_html_e('-- Seleccionar o crear nueva --', 'ai-content-generator'); ?></option>
                                <?php foreach ($categories as $cat) : ?>
                                    <option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_html($cat->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Si no seleccionas, se usará el tema como categoría.', 'ai-content-generator'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="aicg_post_status"><?php esc_html_e('Estado del Post', 'ai-content-generator'); ?></label>
                        </th>
                        <td>
                            <select name="post_status" id="aicg_post_status">
                                <option value="draft"><?php esc_html_e('Borrador', 'ai-content-generator'); ?></option>
                                <option value="publish"><?php esc_html_e('Publicado', 'ai-content-generator'); ?></option>
                                <option value="pending"><?php esc_html_e('Pendiente de revisión', 'ai-content-generator'); ?></option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="aicg_generate_image"><?php esc_html_e('Generar Imagen', 'ai-content-generator'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="generate_image" id="aicg_generate_image" value="1" checked>
                                <?php esc_html_e('Generar imagen destacada con IA', 'ai-content-generator'); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="aicg_article_author"><?php esc_html_e('Autor', 'ai-content-generator'); ?></label>
                        </th>
                        <td>
                            <?php
                            $default_author = get_option('aicg_default_author', 0);
                            $authors = get_users(array(
                                'capability' => array('edit_posts'),
                                'orderby' => 'display_name',
                                'order' => 'ASC'
                            ));
                            ?>
                            <select name="post_author" id="aicg_article_author">
                                <option value="0" <?php selected($default_author, 0); ?>><?php esc_html_e('-- Usuario actual --', 'ai-content-generator'); ?></option>
                                <?php foreach ($authors as $author) : ?>
                                    <option value="<?php echo esc_attr($author->ID); ?>" <?php selected($default_author, $author->ID); ?>>
                                        <?php echo esc_html($author->display_name); ?> (<?php echo esc_html($author->user_login); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Selecciona el autor para este artículo.', 'ai-content-generator'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="aicg-form-section">
                <h2><?php esc_html_e('Opciones Avanzadas', 'ai-content-generator'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="aicg_min_words"><?php esc_html_e('Palabras', 'ai-content-generator'); ?></label>
                        </th>
                        <td>
                            <input type="number"
                                   name="min_words"
                                   id="aicg_min_words"
                                   value="<?php echo esc_attr(get_option('aicg_article_min_words', 1500)); ?>"
                                   min="100"
                                   class="small-text">
                            -
                            <input type="number"
                                   name="max_words"
                                   id="aicg_max_words"
                                   value="<?php echo esc_attr(get_option('aicg_article_max_words', 2000)); ?>"
                                   min="100"
                                   class="small-text">
                            <?php esc_html_e('palabras', 'ai-content-generator'); ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="aicg_sections"><?php esc_html_e('Secciones', 'ai-content-generator'); ?></label>
                        </th>
                        <td>
                            <input type="number"
                                   name="sections"
                                   id="aicg_sections"
                                   value="<?php echo esc_attr(get_option('aicg_article_sections', 4)); ?>"
                                   min="1"
                                   max="10"
                                   class="small-text">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="aicg_temperature"><?php esc_html_e('Creatividad', 'ai-content-generator'); ?></label>
                        </th>
                        <td>
                            <input type="range"
                                   name="temperature"
                                   id="aicg_temperature"
                                   min="0"
                                   max="1"
                                   step="0.1"
                                   value="0.7">
                            <span id="aicg_temperature_value">0.7</span>
                            <p class="description">
                                <?php esc_html_e('0 = Más preciso y consistente, 1 = Más creativo y variado', 'ai-content-generator'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="aicg-form-actions">
                <button type="submit" class="button button-primary button-hero" id="aicg_generate_btn">
                    <span class="dashicons dashicons-admin-post"></span>
                    <?php esc_html_e('Generar Artículo', 'ai-content-generator'); ?>
                </button>
            </div>
        </form>

        <!-- Área de progreso -->
        <div id="aicg-progress-area" style="display: none;">
            <div class="aicg-progress-card">
                <h3><?php esc_html_e('Generando Artículo...', 'ai-content-generator'); ?></h3>

                <div class="aicg-progress-steps">
                    <div class="aicg-step" id="step-title">
                        <span class="aicg-step-icon"><span class="dashicons dashicons-text"></span></span>
                        <span class="aicg-step-text"><?php esc_html_e('Generando título...', 'ai-content-generator'); ?></span>
                    </div>
                    <div class="aicg-step" id="step-content">
                        <span class="aicg-step-icon"><span class="dashicons dashicons-edit"></span></span>
                        <span class="aicg-step-text"><?php esc_html_e('Escribiendo contenido...', 'ai-content-generator'); ?></span>
                    </div>
                    <div class="aicg-step" id="step-image">
                        <span class="aicg-step-icon"><span class="dashicons dashicons-format-image"></span></span>
                        <span class="aicg-step-text"><?php esc_html_e('Generando imagen...', 'ai-content-generator'); ?></span>
                    </div>
                    <div class="aicg-step" id="step-publish">
                        <span class="aicg-step-icon"><span class="dashicons dashicons-upload"></span></span>
                        <span class="aicg-step-text"><?php esc_html_e('Publicando en WordPress...', 'ai-content-generator'); ?></span>
                    </div>
                </div>

                <div class="aicg-progress-bar">
                    <div class="aicg-progress-fill" id="aicg-progress-fill"></div>
                </div>

                <p class="aicg-progress-status" id="aicg-status-text">
                    <?php esc_html_e('Iniciando...', 'ai-content-generator'); ?>
                </p>
            </div>
        </div>

        <!-- Resultado -->
        <div id="aicg-result-area" style="display: none;">
            <div class="aicg-result-card aicg-result-success">
                <span class="dashicons dashicons-yes-alt"></span>
                <h3><?php esc_html_e('Artículo Generado Exitosamente', 'ai-content-generator'); ?></h3>
                <p id="aicg-result-message"></p>
                <div class="aicg-result-actions">
                    <a href="#" id="aicg-view-post" class="button button-primary" target="_blank">
                        <?php esc_html_e('Ver Artículo', 'ai-content-generator'); ?>
                    </a>
                    <a href="#" id="aicg-edit-post" class="button" target="_blank">
                        <?php esc_html_e('Editar', 'ai-content-generator'); ?>
                    </a>
                    <button type="button" class="button" id="aicg-generate-another">
                        <?php esc_html_e('Generar Otro', 'ai-content-generator'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Error -->
        <div id="aicg-error-area" style="display: none;">
            <div class="aicg-result-card aicg-result-error">
                <span class="dashicons dashicons-warning"></span>
                <h3><?php esc_html_e('Error al Generar Artículo', 'ai-content-generator'); ?></h3>
                <p id="aicg-error-message"></p>
                <button type="button" class="button" id="aicg-try-again">
                    <?php esc_html_e('Intentar de Nuevo', 'ai-content-generator'); ?>
                </button>
            </div>
        </div>
    </div>
</div>
