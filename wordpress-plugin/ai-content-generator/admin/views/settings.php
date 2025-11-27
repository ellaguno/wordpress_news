<?php
/**
 * Vista de configuración del plugin
 *
 * @package AI_Content_Generator
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap aicg-settings-wrap">
    <h1>
        <span class="dashicons dashicons-admin-generic"></span>
        <?php esc_html_e('AI Content Generator - Configuración', 'ai-content-generator'); ?>
    </h1>

    <?php settings_errors(); ?>

    <form method="post" action="options.php">
        <?php
        settings_fields('aicg-settings');
        ?>

        <div class="aicg-settings-container">
            <!-- Tabs de navegación -->
            <nav class="aicg-settings-nav">
                <a href="#provider-settings" class="aicg-nav-tab active">
                    <span class="dashicons dashicons-cloud"></span>
                    <?php esc_html_e('Proveedores', 'ai-content-generator'); ?>
                </a>
                <a href="#model-settings" class="aicg-nav-tab">
                    <span class="dashicons dashicons-admin-tools"></span>
                    <?php esc_html_e('Modelos', 'ai-content-generator'); ?>
                </a>
                <a href="#article-settings" class="aicg-nav-tab">
                    <span class="dashicons dashicons-media-document"></span>
                    <?php esc_html_e('Artículos', 'ai-content-generator'); ?>
                </a>
                <a href="#news-settings" class="aicg-nav-tab">
                    <span class="dashicons dashicons-rss"></span>
                    <?php esc_html_e('Noticias', 'ai-content-generator'); ?>
                </a>
                <a href="#image-settings" class="aicg-nav-tab">
                    <span class="dashicons dashicons-format-image"></span>
                    <?php esc_html_e('Imágenes', 'ai-content-generator'); ?>
                </a>
                <a href="#schedule-settings" class="aicg-nav-tab">
                    <span class="dashicons dashicons-clock"></span>
                    <?php esc_html_e('Programación', 'ai-content-generator'); ?>
                </a>
            </nav>

            <div class="aicg-settings-content">
                <!-- Sección: Proveedores -->
                <div id="provider-settings" class="aicg-settings-section active">
                    <h2><?php esc_html_e('Configuración de Proveedores de IA', 'ai-content-generator'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Configura las API Keys de los proveedores que deseas utilizar. Puedes configurar múltiples proveedores y cambiar entre ellos.', 'ai-content-generator'); ?>
                    </p>

                    <table class="form-table">
                        <?php
                        $api_keys_config = array(
                            'openai' => array(
                                'name' => 'OpenAI',
                                'url' => 'https://platform.openai.com/api-keys',
                                'description' => 'GPT-4o, GPT-3.5, DALL-E 3'
                            ),
                            'anthropic' => array(
                                'name' => 'Anthropic',
                                'url' => 'https://console.anthropic.com/settings/keys',
                                'description' => 'Claude Sonnet, Claude Opus, Claude Haiku'
                            ),
                            'deepseek' => array(
                                'name' => 'DeepSeek',
                                'url' => 'https://platform.deepseek.com/api_keys',
                                'description' => 'DeepSeek Chat, DeepSeek Coder, DeepSeek R1'
                            ),
                            'openrouter' => array(
                                'name' => 'OpenRouter',
                                'url' => 'https://openrouter.ai/keys',
                                'description' => 'Acceso a múltiples modelos (GPT, Claude, Llama, Gemini, etc.)'
                            )
                        );

                        foreach ($api_keys_config as $key => $config) :
                            $option_name = 'aicg_' . $key . '_api_key';
                            $value = get_option($option_name, '');
                        ?>
                        <tr class="aicg-api-key-row" data-provider="<?php echo esc_attr($key); ?>">
                            <th scope="row">
                                <label for="<?php echo esc_attr($option_name); ?>">
                                    <?php echo esc_html($config['name']); ?> API Key
                                </label>
                            </th>
                            <td>
                                <div class="aicg-api-key-wrapper">
                                    <input type="password"
                                           name="<?php echo esc_attr($option_name); ?>"
                                           id="<?php echo esc_attr($option_name); ?>"
                                           value="<?php echo esc_attr($value); ?>"
                                           class="regular-text aicg-api-key-input"
                                           autocomplete="off">
                                    <button type="button" class="button aicg-toggle-visibility" data-target="<?php echo esc_attr($option_name); ?>">
                                        <span class="dashicons dashicons-visibility"></span>
                                    </button>
                                    <button type="button" class="button button-secondary aicg-test-connection" data-provider="<?php echo esc_attr($key); ?>">
                                        <?php esc_html_e('Probar Conexión', 'ai-content-generator'); ?>
                                    </button>
                                </div>
                                <span class="aicg-test-result" id="test-result-<?php echo esc_attr($key); ?>"></span>
                                <p class="description">
                                    <?php echo esc_html($config['description']); ?> -
                                    <a href="<?php echo esc_url($config['url']); ?>" target="_blank">
                                        <?php esc_html_e('Obtener API Key', 'ai-content-generator'); ?>
                                        <span class="dashicons dashicons-external"></span>
                                    </a>
                                </p>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>

                <!-- Sección: Modelos -->
                <div id="model-settings" class="aicg-settings-section">
                    <h2><?php esc_html_e('Configuración de Modelos', 'ai-content-generator'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Selecciona los proveedores y modelos a utilizar para texto e imágenes.', 'ai-content-generator'); ?>
                    </p>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aicg_text_provider"><?php esc_html_e('Proveedor de Texto', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <?php
                                $text_providers = AICG_AI_Provider_Factory::get_available_providers();
                                $current_text_provider = get_option('aicg_ai_provider', 'openai');
                                ?>
                                <select name="aicg_ai_provider" id="aicg_text_provider" class="aicg-text-provider-select">
                                    <?php foreach ($text_providers as $key => $provider) : ?>
                                        <option value="<?php echo esc_attr($key); ?>"
                                                <?php selected($current_text_provider, $key); ?>
                                                data-models="<?php echo esc_attr($key); ?>">
                                            <?php echo esc_html($provider['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('El proveedor que se usará para generar texto (artículos y noticias).', 'ai-content-generator'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_default_model"><?php esc_html_e('Modelo de Texto', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <input type="text"
                                       name="aicg_default_model"
                                       id="aicg_default_model"
                                       value="<?php echo esc_attr(get_option('aicg_default_model', 'gpt-4o')); ?>"
                                       class="regular-text">
                                <div id="aicg-model-suggestions" class="aicg-model-suggestions">
                                    <p class="description" data-provider="openai" style="display:none;">
                                        <strong>OpenAI:</strong> gpt-4o, gpt-4o-mini, gpt-4-turbo, gpt-3.5-turbo
                                    </p>
                                    <p class="description" data-provider="anthropic" style="display:none;">
                                        <strong>Anthropic:</strong> claude-sonnet-4-20250514, claude-3-5-sonnet-20241022, claude-3-haiku-20240307
                                    </p>
                                    <p class="description" data-provider="deepseek" style="display:none;">
                                        <strong>DeepSeek:</strong> deepseek-chat, deepseek-coder, deepseek-reasoner
                                    </p>
                                    <p class="description" data-provider="openrouter" style="display:none;">
                                        <strong>OpenRouter:</strong> openai/gpt-4o, anthropic/claude-sonnet-4, google/gemini-pro, meta-llama/llama-3-70b-instruct
                                    </p>
                                </div>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_image_provider"><?php esc_html_e('Proveedor de Imágenes', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <?php $image_provider = get_option('aicg_image_provider', 'openai'); ?>
                                <select name="aicg_image_provider" id="aicg_image_provider">
                                    <option value="openai" <?php selected($image_provider, 'openai'); ?>>OpenAI (DALL-E)</option>
                                    <option value="openrouter" <?php selected($image_provider, 'openrouter'); ?>>OpenRouter</option>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_image_model"><?php esc_html_e('Modelo de Imágenes', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <input type="text"
                                       name="aicg_image_model"
                                       id="aicg_image_model"
                                       value="<?php echo esc_attr(get_option('aicg_image_model', 'dall-e-3')); ?>"
                                       class="regular-text">
                                <p class="description">
                                    <?php esc_html_e('Modelos disponibles: dall-e-3, dall-e-2', 'ai-content-generator'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Sección: Artículos -->
                <div id="article-settings" class="aicg-settings-section">
                    <h2><?php esc_html_e('Configuración de Artículos', 'ai-content-generator'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aicg_article_min_words"><?php esc_html_e('Mínimo de palabras', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <input type="number"
                                       name="aicg_article_min_words"
                                       id="aicg_article_min_words"
                                       value="<?php echo esc_attr(get_option('aicg_article_min_words', 1500)); ?>"
                                       min="100"
                                       max="10000"
                                       class="small-text">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_article_max_words"><?php esc_html_e('Máximo de palabras', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <input type="number"
                                       name="aicg_article_max_words"
                                       id="aicg_article_max_words"
                                       value="<?php echo esc_attr(get_option('aicg_article_max_words', 2000)); ?>"
                                       min="100"
                                       max="10000"
                                       class="small-text">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_article_sections"><?php esc_html_e('Número de secciones', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <input type="number"
                                       name="aicg_article_sections"
                                       id="aicg_article_sections"
                                       value="<?php echo esc_attr(get_option('aicg_article_sections', 4)); ?>"
                                       min="1"
                                       max="10"
                                       class="small-text">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_article_topics"><?php esc_html_e('Temas para Artículos', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <?php
                                $topics = get_option('aicg_article_topics', array());
                                $topics_text = is_array($topics) ? implode("\n", $topics) : '';
                                ?>
                                <textarea name="aicg_article_topics"
                                          id="aicg_article_topics"
                                          rows="10"
                                          cols="50"
                                          class="large-text"><?php echo esc_textarea($topics_text); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e('Un tema por línea. Estos temas se usarán cuando se genere un artículo aleatorio.', 'ai-content-generator'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Sección: Noticias -->
                <div id="news-settings" class="aicg-settings-section">
                    <h2><?php esc_html_e('Configuración de Noticias', 'ai-content-generator'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Configura los temas para el agregador de noticias RSS.', 'ai-content-generator'); ?>
                    </p>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e('Temas de Noticias', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <div id="aicg-news-topics-container">
                                    <?php
                                    $news_topics = get_option('aicg_news_topics', array());
                                    if (!empty($news_topics)) :
                                        foreach ($news_topics as $index => $topic) :
                                    ?>
                                        <div class="aicg-news-topic-row">
                                            <input type="text"
                                                   name="aicg_news_topics[<?php echo $index; ?>][nombre]"
                                                   value="<?php echo esc_attr($topic['nombre']); ?>"
                                                   placeholder="<?php esc_attr_e('Nombre del tema', 'ai-content-generator'); ?>"
                                                   class="regular-text">
                                            <input type="url"
                                                   name="aicg_news_topics[<?php echo $index; ?>][imagen]"
                                                   value="<?php echo esc_attr($topic['imagen']); ?>"
                                                   placeholder="<?php esc_attr_e('URL de imagen (opcional)', 'ai-content-generator'); ?>"
                                                   class="regular-text">
                                            <button type="button" class="button aicg-remove-topic">
                                                <span class="dashicons dashicons-trash"></span>
                                            </button>
                                        </div>
                                    <?php
                                        endforeach;
                                    endif;
                                    ?>
                                </div>
                                <button type="button" class="button" id="aicg-add-news-topic">
                                    <span class="dashicons dashicons-plus"></span>
                                    <?php esc_html_e('Añadir Tema', 'ai-content-generator'); ?>
                                </button>
                                <p class="description">
                                    <?php esc_html_e('Cada tema buscará noticias en Google News y generará un resumen.', 'ai-content-generator'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Sección: Imágenes -->
                <div id="image-settings" class="aicg-settings-section">
                    <h2><?php esc_html_e('Configuración de Imágenes', 'ai-content-generator'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aicg_watermark_enabled"><?php esc_html_e('Marca de Agua', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                           name="aicg_watermark_enabled"
                                           id="aicg_watermark_enabled"
                                           value="1"
                                           <?php checked(get_option('aicg_watermark_enabled', false)); ?>>
                                    <?php esc_html_e('Aplicar marca de agua a las imágenes generadas', 'ai-content-generator'); ?>
                                </label>
                            </td>
                        </tr>

                        <tr id="aicg-watermark-image-row">
                            <th scope="row">
                                <label><?php esc_html_e('Imagen de Marca de Agua', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <?php
                                $watermark_id = get_option('aicg_watermark_image', 0);
                                $watermark_url = $watermark_id ? wp_get_attachment_url($watermark_id) : '';
                                ?>
                                <input type="hidden"
                                       name="aicg_watermark_image"
                                       id="aicg_watermark_image"
                                       value="<?php echo esc_attr($watermark_id); ?>">
                                <button type="button" class="button" id="aicg-select-watermark">
                                    <?php esc_html_e('Seleccionar Imagen', 'ai-content-generator'); ?>
                                </button>
                                <button type="button" class="button" id="aicg-remove-watermark" <?php echo $watermark_id ? '' : 'style="display:none;"'; ?>>
                                    <?php esc_html_e('Eliminar', 'ai-content-generator'); ?>
                                </button>
                                <div id="aicg-watermark-preview" class="aicg-image-preview">
                                    <?php if ($watermark_url) : ?>
                                        <img src="<?php echo esc_url($watermark_url); ?>" alt="Watermark">
                                    <?php endif; ?>
                                </div>
                                <p class="description">
                                    <?php esc_html_e('Se recomienda usar una imagen PNG con transparencia.', 'ai-content-generator'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Sección: Programación -->
                <div id="schedule-settings" class="aicg-settings-section">
                    <h2><?php esc_html_e('Programación Automática', 'ai-content-generator'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Configura la generación automática de contenido mediante WordPress Cron.', 'ai-content-generator'); ?>
                    </p>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e('Artículos Automáticos', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                           name="aicg_schedule_articles"
                                           id="aicg_schedule_articles"
                                           value="1"
                                           <?php checked(get_option('aicg_schedule_articles', false)); ?>>
                                    <?php esc_html_e('Generar artículos automáticamente', 'ai-content-generator'); ?>
                                </label>
                                <br><br>
                                <?php $freq = get_option('aicg_schedule_articles_frequency', 'daily'); ?>
                                <select name="aicg_schedule_articles_frequency" id="aicg_schedule_articles_frequency">
                                    <option value="hourly" <?php selected($freq, 'hourly'); ?>><?php esc_html_e('Cada hora', 'ai-content-generator'); ?></option>
                                    <option value="twicedaily" <?php selected($freq, 'twicedaily'); ?>><?php esc_html_e('Dos veces al día', 'ai-content-generator'); ?></option>
                                    <option value="daily" <?php selected($freq, 'daily'); ?>><?php esc_html_e('Diario', 'ai-content-generator'); ?></option>
                                    <option value="weekly" <?php selected($freq, 'weekly'); ?>><?php esc_html_e('Semanal', 'ai-content-generator'); ?></option>
                                </select>

                                <?php
                                $next_article = wp_next_scheduled('aicg_generate_scheduled_article');
                                if ($next_article) :
                                ?>
                                <p class="description">
                                    <?php printf(
                                        esc_html__('Próxima ejecución: %s', 'ai-content-generator'),
                                        date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_article)
                                    ); ?>
                                </p>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e('Noticias Automáticas', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                           name="aicg_schedule_news"
                                           id="aicg_schedule_news"
                                           value="1"
                                           <?php checked(get_option('aicg_schedule_news', false)); ?>>
                                    <?php esc_html_e('Generar resúmenes de noticias automáticamente', 'ai-content-generator'); ?>
                                </label>
                                <br><br>
                                <?php $freq_news = get_option('aicg_schedule_news_frequency', 'twicedaily'); ?>
                                <select name="aicg_schedule_news_frequency" id="aicg_schedule_news_frequency">
                                    <option value="hourly" <?php selected($freq_news, 'hourly'); ?>><?php esc_html_e('Cada hora', 'ai-content-generator'); ?></option>
                                    <option value="twicedaily" <?php selected($freq_news, 'twicedaily'); ?>><?php esc_html_e('Dos veces al día', 'ai-content-generator'); ?></option>
                                    <option value="daily" <?php selected($freq_news, 'daily'); ?>><?php esc_html_e('Diario', 'ai-content-generator'); ?></option>
                                </select>

                                <?php
                                $next_news = wp_next_scheduled('aicg_generate_scheduled_news');
                                if ($next_news) :
                                ?>
                                <p class="description">
                                    <?php printf(
                                        esc_html__('Próxima ejecución: %s', 'ai-content-generator'),
                                        date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_news)
                                    ); ?>
                                </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <?php submit_button(__('Guardar Cambios', 'ai-content-generator')); ?>
    </form>
</div>
