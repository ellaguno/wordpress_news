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
                                <p class="description" style="margin-bottom: 10px;">
                                    <span class="dashicons dashicons-move" style="color: #999;"></span>
                                    <?php esc_html_e('Arrastra para reordenar los temas. El orden se reflejará en el resumen generado.', 'ai-content-generator'); ?>
                                </p>
                                <div id="aicg-news-topics-container" class="aicg-sortable-container">
                                    <?php
                                    $news_topics = get_option('aicg_news_topics', array());
                                    if (!empty($news_topics)) :
                                        foreach ($news_topics as $index => $topic) :
                                    ?>
                                        <div class="aicg-news-topic-row aicg-sortable-item">
                                            <span class="aicg-drag-handle dashicons dashicons-menu" title="<?php esc_attr_e('Arrastrar para reordenar', 'ai-content-generator'); ?>"></span>
                                            <input type="text"
                                                   name="aicg_news_topics[<?php echo $index; ?>][nombre]"
                                                   value="<?php echo esc_attr($topic['nombre']); ?>"
                                                   placeholder="<?php esc_attr_e('Nombre del tema', 'ai-content-generator'); ?>"
                                                   class="regular-text aicg-topic-nombre">
                                            <input type="url"
                                                   name="aicg_news_topics[<?php echo $index; ?>][imagen]"
                                                   value="<?php echo esc_attr(isset($topic['imagen']) ? $topic['imagen'] : ''); ?>"
                                                   placeholder="<?php esc_attr_e('URL de imagen (opcional)', 'ai-content-generator'); ?>"
                                                   class="regular-text aicg-topic-imagen">
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

                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e('Fuentes RSS Principales', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <p class="description" style="margin-bottom: 10px;">
                                    <?php esc_html_e('Fuentes RSS para obtener los titulares principales del "Resumen del Día". Puedes usar Google News u otras fuentes RSS.', 'ai-content-generator'); ?>
                                </p>
                                <div id="aicg-news-sources-container">
                                    <?php
                                    $default_sources = array(
                                        array(
                                            'nombre' => 'Google News - Mundo',
                                            'url' => 'https://news.google.com/news/rss/headlines/section/topic/WORLD?hl=es-419&gl=MX&ceid=MX:es-419',
                                            'activo' => true
                                        ),
                                        array(
                                            'nombre' => 'Google News - Nacional',
                                            'url' => 'https://news.google.com/news/rss/headlines/section/topic/NATION?hl=es-419&gl=MX&ceid=MX:es-419',
                                            'activo' => true
                                        ),
                                        array(
                                            'nombre' => 'Google News - Última Hora',
                                            'url' => 'https://news.google.com/news/rss/headlines/section/topic/BREAKING?hl=es-419&gl=MX&ceid=MX:es-419',
                                            'activo' => true
                                        )
                                    );
                                    $news_sources = get_option('aicg_news_sources', $default_sources);
                                    if (empty($news_sources)) {
                                        $news_sources = $default_sources;
                                    }
                                    foreach ($news_sources as $index => $source) :
                                    ?>
                                        <div class="aicg-news-source-row" style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                                            <label style="display: flex; align-items: center;">
                                                <input type="checkbox"
                                                       name="aicg_news_sources[<?php echo $index; ?>][activo]"
                                                       value="1"
                                                       <?php checked(isset($source['activo']) ? $source['activo'] : true); ?>>
                                            </label>
                                            <input type="text"
                                                   name="aicg_news_sources[<?php echo $index; ?>][nombre]"
                                                   value="<?php echo esc_attr($source['nombre']); ?>"
                                                   placeholder="<?php esc_attr_e('Nombre de la fuente', 'ai-content-generator'); ?>"
                                                   class="regular-text"
                                                   style="width: 200px;">
                                            <input type="url"
                                                   name="aicg_news_sources[<?php echo $index; ?>][url]"
                                                   value="<?php echo esc_attr($source['url']); ?>"
                                                   placeholder="<?php esc_attr_e('URL del feed RSS', 'ai-content-generator'); ?>"
                                                   class="regular-text"
                                                   style="flex: 1;">
                                            <button type="button" class="button aicg-remove-source">
                                                <span class="dashicons dashicons-trash"></span>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="button" id="aicg-add-news-source">
                                    <span class="dashicons dashicons-plus"></span>
                                    <?php esc_html_e('Añadir Fuente', 'ai-content-generator'); ?>
                                </button>
                                <p class="description" style="margin-top: 10px;">
                                    <strong><?php esc_html_e('Ejemplos de URLs de Google News:', 'ai-content-generator'); ?></strong><br>
                                    <?php esc_html_e('Tecnología:', 'ai-content-generator'); ?> <code>https://news.google.com/news/rss/headlines/section/topic/TECHNOLOGY?hl=es-419&gl=MX&ceid=MX:es-419</code><br>
                                    <?php esc_html_e('Negocios:', 'ai-content-generator'); ?> <code>https://news.google.com/news/rss/headlines/section/topic/BUSINESS?hl=es-419&gl=MX&ceid=MX:es-419</code><br>
                                    <?php esc_html_e('Deportes:', 'ai-content-generator'); ?> <code>https://news.google.com/news/rss/headlines/section/topic/SPORTS?hl=es-419&gl=MX&ceid=MX:es-419</code><br>
                                    <?php esc_html_e('Entretenimiento:', 'ai-content-generator'); ?> <code>https://news.google.com/news/rss/headlines/section/topic/ENTERTAINMENT?hl=es-419&gl=MX&ceid=MX:es-419</code><br>
                                    <?php esc_html_e('Ciencia:', 'ai-content-generator'); ?> <code>https://news.google.com/news/rss/headlines/section/topic/SCIENCE?hl=es-419&gl=MX&ceid=MX:es-419</code><br>
                                    <?php esc_html_e('Salud:', 'ai-content-generator'); ?> <code>https://news.google.com/news/rss/headlines/section/topic/HEALTH?hl=es-419&gl=MX&ceid=MX:es-419</code>
                                </p>
                                <p class="description" style="margin-top: 10px;">
                                    <strong><?php esc_html_e('Feeds RSS de otras fuentes (Criptomonedas, Tecnología, etc.):', 'ai-content-generator'); ?></strong><br>
                                    <em><?php esc_html_e('⚠️ IMPORTANTE: Debes usar URLs de feeds RSS, no páginas web normales.', 'ai-content-generator'); ?></em><br><br>
                                    <?php esc_html_e('Cointelegraph (Cripto en español):', 'ai-content-generator'); ?> <code>https://es.cointelegraph.com/rss</code><br>
                                    <?php esc_html_e('CoinDesk (Cripto en inglés):', 'ai-content-generator'); ?> <code>https://www.coindesk.com/arc/outboundfeeds/rss/</code><br>
                                    <?php esc_html_e('Decrypt (Cripto en inglés):', 'ai-content-generator'); ?> <code>https://decrypt.co/feed</code><br>
                                    <?php esc_html_e('Xataka México:', 'ai-content-generator'); ?> <code>https://www.xataka.com.mx/feed</code><br>
                                    <?php esc_html_e('Wired (Ciencia):', 'ai-content-generator'); ?> <code>https://www.wired.com/feed/category/science/latest/rss</code><br>
                                    <?php esc_html_e('Wired (Seguridad):', 'ai-content-generator'); ?> <code>https://www.wired.com/feed/category/security/latest/rss</code><br>
                                    <?php esc_html_e('Ars Technica:', 'ai-content-generator'); ?> <code>https://feeds.arstechnica.com/arstechnica/index</code><br>
                                    <?php esc_html_e('The Verge:', 'ai-content-generator'); ?> <code>https://www.theverge.com/rss/index.xml</code>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_news_search_template"><?php esc_html_e('Plantilla de Búsqueda', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <?php
                                $default_template = 'https://news.google.com/rss/search?q={topic}&hl=es-419&gl=MX&ceid=MX:es-419';
                                $search_template = get_option('aicg_news_search_template', $default_template);
                                ?>
                                <input type="url"
                                       name="aicg_news_search_template"
                                       id="aicg_news_search_template"
                                       value="<?php echo esc_attr($search_template); ?>"
                                       class="large-text"
                                       placeholder="<?php echo esc_attr($default_template); ?>">
                                <p class="description">
                                    <?php esc_html_e('URL para buscar noticias por tema. Usa {topic} como marcador para el nombre del tema.', 'ai-content-generator'); ?>
                                </p>
                                <button type="button" class="button button-small" id="aicg-reset-search-template" data-default="<?php echo esc_attr($default_template); ?>">
                                    <?php esc_html_e('Restaurar por defecto', 'ai-content-generator'); ?>
                                </button>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_news_post_type"><?php esc_html_e('Tipo de Publicación', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <?php $news_post_type = get_option('aicg_news_post_type', 'post'); ?>
                                <select name="aicg_news_post_type" id="aicg_news_post_type">
                                    <option value="post" <?php selected($news_post_type, 'post'); ?>><?php esc_html_e('Entrada (Post)', 'ai-content-generator'); ?></option>
                                    <option value="page" <?php selected($news_post_type, 'page'); ?>><?php esc_html_e('Página (Page)', 'ai-content-generator'); ?></option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Elige si los resúmenes de noticias se crean como entradas o como páginas.', 'ai-content-generator'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_content_format"><?php esc_html_e('Formato de Contenido', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <?php $content_format = get_option('aicg_content_format', 'gutenberg'); ?>
                                <select name="aicg_content_format" id="aicg_content_format">
                                    <option value="gutenberg" <?php selected($content_format, 'gutenberg'); ?>><?php esc_html_e('Bloques Gutenberg (recomendado)', 'ai-content-generator'); ?></option>
                                    <option value="classic" <?php selected($content_format, 'classic'); ?>><?php esc_html_e('HTML Clásico', 'ai-content-generator'); ?></option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Gutenberg genera contenido nativo compatible con el editor de bloques. HTML Clásico es para editores antiguos.', 'ai-content-generator'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_news_featured_image"><?php esc_html_e('Imagen Destacada', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <?php
                                $featured_id = get_option('aicg_news_featured_image', 0);
                                $featured_url = $featured_id ? wp_get_attachment_url($featured_id) : '';
                                ?>
                                <input type="hidden"
                                       name="aicg_news_featured_image"
                                       id="aicg_news_featured_image"
                                       value="<?php echo esc_attr($featured_id); ?>">
                                <button type="button" class="button" id="aicg-select-news-featured">
                                    <?php esc_html_e('Seleccionar Imagen', 'ai-content-generator'); ?>
                                </button>
                                <button type="button" class="button" id="aicg-remove-news-featured" <?php echo $featured_id ? '' : 'style="display:none;"'; ?>>
                                    <?php esc_html_e('Eliminar', 'ai-content-generator'); ?>
                                </button>
                                <div id="aicg-news-featured-preview" class="aicg-image-preview">
                                    <?php if ($featured_url) : ?>
                                        <img src="<?php echo esc_url($featured_url); ?>" alt="Featured">
                                    <?php endif; ?>
                                </div>
                                <p class="description">
                                    <?php esc_html_e('Imagen fija que se usará como imagen destacada del post de noticias. Si no se selecciona, no se asignará imagen destacada.', 'ai-content-generator'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_news_generate_image"><?php esc_html_e('Generar Imagen', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                           name="aicg_news_generate_image"
                                           id="aicg_news_generate_image"
                                           value="1"
                                           <?php checked(get_option('aicg_news_generate_image', false)); ?>>
                                    <?php esc_html_e('Generar imagen con IA para el resumen de noticias', 'ai-content-generator'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('La imagen se generará con IA basándose en los titulares principales y se insertará al inicio del contenido (debajo del título, antes del texto). Tiene costo adicional por uso de API de generación de imágenes.', 'ai-content-generator'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_news_carousel_enabled"><?php esc_html_e('Galería de Imágenes', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                           name="aicg_news_carousel_enabled"
                                           id="aicg_news_carousel_enabled"
                                           value="1"
                                           <?php checked(get_option('aicg_news_carousel_enabled', true)); ?>>
                                    <?php esc_html_e('Mostrar galería horizontal de imágenes en cada sección/tema', 'ai-content-generator'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('Cada tema mostrará una galería horizontal con las imágenes de sus noticias. Las imágenes se descargan, redimensionan y guardan en la biblioteca de medios.', 'ai-content-generator'); ?>
                                </p>
                                <div style="margin-top: 10px; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                                    <strong><?php esc_html_e('Características de la galería:', 'ai-content-generator'); ?></strong>
                                    <ul style="margin: 5px 0 0 20px; list-style: disc;">
                                        <li><?php esc_html_e('Hasta 5 imágenes por tema', 'ai-content-generator'); ?></li>
                                        <li><?php esc_html_e('Imágenes redimensionadas a 400x225px', 'ai-content-generator'); ?></li>
                                        <li><?php esc_html_e('Scroll horizontal para navegar', 'ai-content-generator'); ?></li>
                                        <li><?php esc_html_e('Compatible con WordPress (sin JavaScript)', 'ai-content-generator'); ?></li>
                                        <li><?php esc_html_e('Click en imagen para ir a la noticia original', 'ai-content-generator'); ?></li>
                                    </ul>
                                </div>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_reference_style"><?php esc_html_e('Estilo de Referencias', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <?php $ref_style = get_option('aicg_reference_style', 'inline'); ?>
                                <select name="aicg_reference_style" id="aicg_reference_style">
                                    <option value="inline" <?php selected($ref_style, 'inline'); ?>><?php esc_html_e('En línea (superíndice simple)', 'ai-content-generator'); ?></option>
                                    <option value="circle" <?php selected($ref_style, 'circle'); ?>><?php esc_html_e('Círculos (número en círculo)', 'ai-content-generator'); ?></option>
                                    <option value="square" <?php selected($ref_style, 'square'); ?>><?php esc_html_e('Cuadrados (número en cuadrado)', 'ai-content-generator'); ?></option>
                                    <option value="badge" <?php selected($ref_style, 'badge'); ?>><?php esc_html_e('Badge (estilo etiqueta)', 'ai-content-generator'); ?></option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Cómo se muestran los números de referencia a las fuentes de noticias.', 'ai-content-generator'); ?>
                                </p>

                                <!-- Vista previa de estilos -->
                                <div class="aicg-reference-preview" style="margin-top: 15px; padding: 15px; background: #f9f9f9; border-radius: 4px;">
                                    <strong style="display: block; margin-bottom: 10px;"><?php esc_html_e('Vista previa:', 'ai-content-generator'); ?></strong>
                                    <div class="aicg-preview-inline" style="display: <?php echo $ref_style === 'inline' ? 'block' : 'none'; ?>;">
                                        <?php esc_html_e('Texto de ejemplo', 'ai-content-generator'); ?> <sup><a href="#" style="color: #0073aa;"><strong>1</strong></a></sup> <sup><a href="#" style="color: #0073aa;"><strong>2</strong></a></sup> <sup><a href="#" style="color: #0073aa;"><strong>3</strong></a></sup>
                                    </div>
                                    <div class="aicg-preview-circle" style="display: <?php echo $ref_style === 'circle' ? 'block' : 'none'; ?>;">
                                        <?php esc_html_e('Texto de ejemplo', 'ai-content-generator'); ?>
                                        <a href="#" style="display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; background: #0073aa; color: white; border-radius: 50%; font-size: 12px; text-decoration: none; margin: 0 2px;">1</a>
                                        <a href="#" style="display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; background: #0073aa; color: white; border-radius: 50%; font-size: 12px; text-decoration: none; margin: 0 2px;">2</a>
                                        <a href="#" style="display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; background: #0073aa; color: white; border-radius: 50%; font-size: 12px; text-decoration: none; margin: 0 2px;">3</a>
                                    </div>
                                    <div class="aicg-preview-square" style="display: <?php echo $ref_style === 'square' ? 'block' : 'none'; ?>;">
                                        <?php esc_html_e('Texto de ejemplo', 'ai-content-generator'); ?>
                                        <a href="#" style="display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; background: #0073aa; color: white; border-radius: 3px; font-size: 12px; text-decoration: none; margin: 0 2px;">1</a>
                                        <a href="#" style="display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; background: #0073aa; color: white; border-radius: 3px; font-size: 12px; text-decoration: none; margin: 0 2px;">2</a>
                                        <a href="#" style="display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; background: #0073aa; color: white; border-radius: 3px; font-size: 12px; text-decoration: none; margin: 0 2px;">3</a>
                                    </div>
                                    <div class="aicg-preview-badge" style="display: <?php echo $ref_style === 'badge' ? 'block' : 'none'; ?>;">
                                        <?php esc_html_e('Texto de ejemplo', 'ai-content-generator'); ?>
                                        <a href="#" style="display: inline-block; padding: 2px 8px; background: #e7f3ff; color: #0073aa; border-radius: 10px; font-size: 11px; text-decoration: none; margin: 0 2px;">1</a>
                                        <a href="#" style="display: inline-block; padding: 2px 8px; background: #e7f3ff; color: #0073aa; border-radius: 10px; font-size: 11px; text-decoration: none; margin: 0 2px;">2</a>
                                        <a href="#" style="display: inline-block; padding: 2px 8px; background: #e7f3ff; color: #0073aa; border-radius: 10px; font-size: 11px; text-decoration: none; margin: 0 2px;">3</a>
                                    </div>
                                </div>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_reference_color"><?php esc_html_e('Color de Referencias', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <?php $ref_color = get_option('aicg_reference_color', '#0073aa'); ?>
                                <input type="color"
                                       name="aicg_reference_color"
                                       id="aicg_reference_color"
                                       value="<?php echo esc_attr($ref_color); ?>"
                                       style="width: 60px; height: 30px; padding: 0; border: 1px solid #ddd;">
                                <input type="text"
                                       id="aicg_reference_color_text"
                                       value="<?php echo esc_attr($ref_color); ?>"
                                       class="small-text"
                                       style="margin-left: 10px;">
                                <p class="description">
                                    <?php esc_html_e('Color principal para los números de referencia.', 'ai-content-generator'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_reference_size"><?php esc_html_e('Tamaño de Referencias', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <?php $ref_size = get_option('aicg_reference_size', 24); ?>
                                <input type="range"
                                       name="aicg_reference_size"
                                       id="aicg_reference_size"
                                       min="12"
                                       max="32"
                                       step="2"
                                       value="<?php echo esc_attr($ref_size); ?>"
                                       style="width: 150px; vertical-align: middle;">
                                <span id="aicg_reference_size_value" style="margin-left: 10px; font-weight: bold;"><?php echo esc_html($ref_size); ?>px</span>
                                <p class="description">
                                    <?php esc_html_e('Tamaño de los números de referencia (12px - 32px). Por defecto: 24px.', 'ai-content-generator'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_reference_orientation"><?php esc_html_e('Orientación de Referencias', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <?php $ref_orientation = get_option('aicg_reference_orientation', 'horizontal'); ?>
                                <select name="aicg_reference_orientation" id="aicg_reference_orientation">
                                    <option value="horizontal" <?php selected($ref_orientation, 'horizontal'); ?>><?php esc_html_e('Horizontal (en línea)', 'ai-content-generator'); ?></option>
                                    <option value="vertical" <?php selected($ref_orientation, 'vertical'); ?>><?php esc_html_e('Vertical (uno debajo del otro)', 'ai-content-generator'); ?></option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Cómo se disponen los números de referencia al final de cada sección.', 'ai-content-generator'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_news_update_existing"><?php esc_html_e('Actualizar Existente', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                           name="aicg_news_update_existing"
                                           id="aicg_news_update_existing"
                                           value="1"
                                           <?php checked(get_option('aicg_news_update_existing', false)); ?>>
                                    <?php esc_html_e('Actualizar la misma entrada/página en lugar de crear una nueva', 'ai-content-generator'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('Si está activo, el resumen de noticias siempre actualizará el mismo post, manteniendo la URL permanente.', 'ai-content-generator'); ?>
                                </p>

                                <div id="aicg-existing-post-selector" style="margin-top: 15px; <?php echo get_option('aicg_news_update_existing', false) ? '' : 'display: none;'; ?>">
                                    <label for="aicg_news_target_post"><?php esc_html_e('Entrada/Página a actualizar:', 'ai-content-generator'); ?></label>
                                    <?php
                                    $target_post_id = get_option('aicg_news_target_post', 0);
                                    $news_post_type = get_option('aicg_news_post_type', 'post');
                                    ?>
                                    <select name="aicg_news_target_post" id="aicg_news_target_post" style="min-width: 300px;">
                                        <option value="0"><?php esc_html_e('-- Crear automáticamente en primera ejecución --', 'ai-content-generator'); ?></option>
                                        <?php
                                        $existing_posts = get_posts(array(
                                            'post_type' => array('post', 'page'),
                                            'posts_per_page' => 50,
                                            'orderby' => 'modified',
                                            'order' => 'DESC',
                                            'meta_query' => array(
                                                array(
                                                    'key' => '_aicg_type',
                                                    'value' => 'news',
                                                    'compare' => '='
                                                )
                                            )
                                        ));

                                        if (empty($existing_posts)) {
                                            // Si no hay posts generados, mostrar todos
                                            $existing_posts = get_posts(array(
                                                'post_type' => array('post', 'page'),
                                                'posts_per_page' => 50,
                                                'orderby' => 'modified',
                                                'order' => 'DESC'
                                            ));
                                        }

                                        foreach ($existing_posts as $p) :
                                            $type_label = $p->post_type === 'page' ? __('Página', 'ai-content-generator') : __('Entrada', 'ai-content-generator');
                                        ?>
                                            <option value="<?php echo esc_attr($p->ID); ?>" <?php selected($target_post_id, $p->ID); ?>>
                                                <?php echo esc_html(sprintf('[%s] %s (ID: %d)', $type_label, $p->post_title, $p->ID)); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        <?php esc_html_e('Selecciona el post a actualizar o deja en automático para crear uno nuevo la primera vez.', 'ai-content-generator'); ?>
                                    </p>
                                </div>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_news_system_prompt"><?php esc_html_e('Prompt del Sistema', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <?php
                                $default_system = 'Eres un periodista experto que resume noticias de forma objetiva y precisa. Usas HTML puro, nunca Markdown.';
                                $system_prompt = get_option('aicg_news_system_prompt', $default_system);
                                ?>
                                <textarea name="aicg_news_system_prompt"
                                          id="aicg_news_system_prompt"
                                          rows="3"
                                          cols="60"
                                          class="large-text"><?php echo esc_textarea($system_prompt); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e('Define el rol y estilo de la IA. Ejemplos: "Eres un periodista sarcástico...", "Eres un analista político crítico..."', 'ai-content-generator'); ?>
                                </p>
                                <button type="button" class="button button-small" id="aicg-reset-system-prompt" data-default="<?php echo esc_attr($default_system); ?>">
                                    <?php esc_html_e('Restaurar por defecto', 'ai-content-generator'); ?>
                                </button>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_news_user_prompt"><?php esc_html_e('Instrucciones Adicionales', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <?php
                                $user_prompt = get_option('aicg_news_user_prompt', '');
                                ?>
                                <textarea name="aicg_news_user_prompt"
                                          id="aicg_news_user_prompt"
                                          rows="4"
                                          cols="60"
                                          class="large-text"
                                          placeholder="<?php esc_attr_e('Ej: Usa un tono sarcástico. Incluye comentarios irónicos sobre la situación política...', 'ai-content-generator'); ?>"><?php echo esc_textarea($user_prompt); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e('Instrucciones adicionales que se agregarán al prompt. Puedes definir el tono, estilo, o enfoque específico.', 'ai-content-generator'); ?>
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
                                <label for="aicg_image_size"><?php esc_html_e('Tamaño de Imagen', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <?php $image_size = get_option('aicg_image_size', '1792x1024'); ?>
                                <select name="aicg_image_size" id="aicg_image_size">
                                    <option value="1792x1024" <?php selected($image_size, '1792x1024'); ?>>1792x1024 - <?php esc_html_e('Horizontal (paisaje)', 'ai-content-generator'); ?></option>
                                    <option value="1024x1024" <?php selected($image_size, '1024x1024'); ?>>1024x1024 - <?php esc_html_e('Cuadrado', 'ai-content-generator'); ?></option>
                                    <option value="1024x1792" <?php selected($image_size, '1024x1792'); ?>>1024x1792 - <?php esc_html_e('Vertical (retrato)', 'ai-content-generator'); ?></option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Orientación de las imágenes generadas. Horizontal es recomendado para imágenes destacadas de blog.', 'ai-content-generator'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_image_quality"><?php esc_html_e('Calidad de Imagen', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <?php $image_quality = get_option('aicg_image_quality', 'standard'); ?>
                                <select name="aicg_image_quality" id="aicg_image_quality">
                                    <option value="standard" <?php selected($image_quality, 'standard'); ?>><?php esc_html_e('Estándar', 'ai-content-generator'); ?></option>
                                    <option value="hd" <?php selected($image_quality, 'hd'); ?>><?php esc_html_e('HD (Mayor costo)', 'ai-content-generator'); ?></option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('HD genera imágenes con más detalle pero cuesta el doble.', 'ai-content-generator'); ?>
                                </p>
                            </td>
                        </tr>

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

                        <tr>
                            <th scope="row">
                                <label for="aicg_article_image_prompt"><?php esc_html_e('Prompt para Artículos', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <?php
                                $default_article_prompt = 'Una imagen creativa, profesional y visualmente atractiva relacionada con "{topic}". Estilo: ilustración digital moderna o fotografía artística. Colores vibrantes pero profesionales. Sin texto ni logos.';
                                $article_image_prompt = get_option('aicg_article_image_prompt', $default_article_prompt);
                                ?>
                                <textarea name="aicg_article_image_prompt"
                                          id="aicg_article_image_prompt"
                                          rows="4"
                                          cols="60"
                                          class="large-text"><?php echo esc_textarea($article_image_prompt); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e('Prompt para generar imágenes de artículos. Usa {topic} como marcador para el tema del artículo.', 'ai-content-generator'); ?>
                                </p>
                                <button type="button" class="button button-small" id="aicg-reset-article-image-prompt" data-default="<?php echo esc_attr($default_article_prompt); ?>">
                                    <?php esc_html_e('Restaurar por defecto', 'ai-content-generator'); ?>
                                </button>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_news_image_prompt"><?php esc_html_e('Prompt para Noticias', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <?php
                                $default_news_prompt = 'Create a professional news header image that represents these headlines from today: {headlines}. Style: Modern news media, clean design, abstract representation of news themes. Do NOT include any text or words in the image. Use a color palette suitable for a news website.';
                                $news_image_prompt = get_option('aicg_news_image_prompt', $default_news_prompt);
                                ?>
                                <textarea name="aicg_news_image_prompt"
                                          id="aicg_news_image_prompt"
                                          rows="4"
                                          cols="60"
                                          class="large-text"><?php echo esc_textarea($news_image_prompt); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e('Prompt para generar imágenes de noticias. Usa {headlines} como marcador para los titulares del día.', 'ai-content-generator'); ?>
                                </p>
                                <button type="button" class="button button-small" id="aicg-reset-news-image-prompt" data-default="<?php echo esc_attr($default_news_prompt); ?>">
                                    <?php esc_html_e('Restaurar por defecto', 'ai-content-generator'); ?>
                                </button>
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

                                <br><br>
                                <label for="aicg_schedule_news_time"><?php esc_html_e('Hora de ejecución:', 'ai-content-generator'); ?></label>
                                <?php
                                $schedule_time = get_option('aicg_schedule_news_time', '08:00');
                                $wp_tz = wp_timezone();
                                ?>
                                <select name="aicg_schedule_news_time" id="aicg_schedule_news_time">
                                    <?php
                                    for ($h = 0; $h < 24; $h++) {
                                        $time_val = sprintf('%02d:00', $h);
                                        // Crear timestamp para hoy a esa hora en zona horaria de WP
                                        $today_at_hour = new DateTime('today', $wp_tz);
                                        $today_at_hour->setTime($h, 0, 0);
                                        $time_label = date_i18n('g:i A', $today_at_hour->getTimestamp());
                                        printf(
                                            '<option value="%s" %s>%s</option>',
                                            esc_attr($time_val),
                                            selected($schedule_time, $time_val, false),
                                            esc_html($time_label)
                                        );
                                    }
                                    ?>
                                </select>
                                <span class="description" style="margin-left: 10px;">
                                    <?php
                                    // Mostrar zona horaria actual
                                    printf(
                                        esc_html__('(Zona horaria: %s)', 'ai-content-generator'),
                                        $wp_tz->getName()
                                    );
                                    ?>
                                </span>
                                <p class="description" id="aicg-schedule-time-hint" style="<?php echo $freq_news === 'hourly' ? 'display:none;' : ''; ?>">
                                    <?php esc_html_e('Para "Dos veces al día": se ejecutará a esta hora y 12 horas después.', 'ai-content-generator'); ?>
                                </p>

                                <?php
                                $next_news = wp_next_scheduled('aicg_generate_scheduled_news');
                                if ($next_news) :
                                ?>
                                <p class="description" style="margin-top: 10px; font-weight: bold;">
                                    <?php printf(
                                        esc_html__('Próxima ejecución: %s', 'ai-content-generator'),
                                        date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_news)
                                    ); ?>
                                </p>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_schedule_post_status"><?php esc_html_e('Estado de Publicación', 'ai-content-generator'); ?></label>
                            </th>
                            <td>
                                <?php $post_status = get_option('aicg_schedule_post_status', 'draft'); ?>
                                <select name="aicg_schedule_post_status" id="aicg_schedule_post_status">
                                    <option value="draft" <?php selected($post_status, 'draft'); ?>><?php esc_html_e('Borrador (revisar antes de publicar)', 'ai-content-generator'); ?></option>
                                    <option value="publish" <?php selected($post_status, 'publish'); ?>><?php esc_html_e('Publicado (publicar automáticamente)', 'ai-content-generator'); ?></option>
                                    <option value="pending" <?php selected($post_status, 'pending'); ?>><?php esc_html_e('Pendiente de revisión', 'ai-content-generator'); ?></option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Estado con el que se crearán los artículos y noticias programados.', 'ai-content-generator'); ?>
                                </p>
                                <?php if ($post_status === 'publish') : ?>
                                <p class="description" style="color: #d63638;">
                                    <span class="dashicons dashicons-warning"></span>
                                    <?php esc_html_e('Advertencia: El contenido se publicará sin revisión previa.', 'ai-content-generator'); ?>
                                </p>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="aicg_default_author"><?php esc_html_e('Autor por Defecto', 'ai-content-generator'); ?></label>
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
                                <select name="aicg_default_author" id="aicg_default_author">
                                    <option value="0" <?php selected($default_author, 0); ?>><?php esc_html_e('-- Usuario actual --', 'ai-content-generator'); ?></option>
                                    <?php foreach ($authors as $author) : ?>
                                        <option value="<?php echo esc_attr($author->ID); ?>" <?php selected($default_author, $author->ID); ?>>
                                            <?php echo esc_html($author->display_name); ?> (<?php echo esc_html($author->user_login); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('El autor que se asignará a los artículos y noticias generados. Si se selecciona "Usuario actual", se usará el usuario que ejecute la generación.', 'ai-content-generator'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <?php submit_button(__('Guardar Cambios', 'ai-content-generator')); ?>
    </form>
</div>
