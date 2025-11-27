<?php
/**
 * Agregador de noticias
 *
 * @package AI_Content_Generator
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para agregar y resumir noticias
 */
class AICG_News_Aggregator {

    /**
     * Proveedor de IA
     *
     * @var AICG_AI_Provider_Interface
     */
    private $provider;

    /**
     * URLs de Google News
     *
     * @var array
     */
    private $news_sources = array(
        'headlines' => 'https://news.google.com/news/rss/headlines/section/topic/WORLD?hl=es-419&gl=MX&ceid=MX:es-419',
        'nation' => 'https://news.google.com/news/rss/headlines/section/topic/NATION?hl=es-419&gl=MX&ceid=MX:es-419',
        'breaking' => 'https://news.google.com/news/rss/headlines/section/topic/BREAKING?hl=es-419&gl=MX&ceid=MX:es-419'
    );

    /**
     * User Agent para requests
     *
     * @var string
     */
    private $user_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /**
     * Constructor
     */
    public function __construct() {
        $this->provider = AICG_AI_Provider_Factory::get_text_provider();
    }

    /**
     * Generar resumen de noticias
     *
     * @param array $args Argumentos
     * @return array|WP_Error
     */
    public function generate($args = array()) {
        $defaults = array(
            'topics' => array(),
            'include_headlines' => true,
            'post_status' => 'draft'
        );

        $args = wp_parse_args($args, $defaults);

        // Validar proveedor
        if (is_wp_error($this->provider)) {
            return $this->provider;
        }

        if (!$this->provider->is_configured()) {
            return new WP_Error('provider_not_configured', __('Proveedor de IA no configurado', 'ai-content-generator'));
        }

        // Si no hay temas, usar los configurados
        if (empty($args['topics'])) {
            $configured_topics = get_option('aicg_news_topics', array());
            $args['topics'] = array_column($configured_topics, 'nombre');
        }

        if (empty($args['topics'])) {
            return new WP_Error('no_topics', __('No hay temas de noticias configurados', 'ai-content-generator'));
        }

        $result = array(
            'title' => '',
            'content' => '',
            'post_id' => 0,
            'tokens_used' => 0,
            'cost' => 0,
            'news_count' => 0,
            'topics_processed' => array()
        );

        try {
            // Obtener URLs ya usadas
            $used_urls = $this->get_used_urls();

            $content_parts = array('<div class="aicg-news-summary">');

            // Paso 1: Obtener y resumir titulares principales
            if ($args['include_headlines']) {
                $headlines = $this->fetch_main_headlines();
                if (!empty($headlines)) {
                    $summary_result = $this->generate_headlines_summary($headlines);
                    if (!is_wp_error($summary_result) && !empty($summary_result['content'])) {
                        $content_parts[] = '<div class="aicg-general-summary">';
                        $content_parts[] = '<h2>' . esc_html__('Resumen del Día', 'ai-content-generator') . '</h2>';
                        $content_parts[] = $summary_result['content'];
                        $content_parts[] = '</div>';
                        $result['tokens_used'] += isset($summary_result['usage']['total_tokens']) ? $summary_result['usage']['total_tokens'] : 0;
                        $result['cost'] += isset($summary_result['cost']) ? $summary_result['cost'] : 0;
                    } elseif (is_wp_error($summary_result)) {
                        // Log error pero continuar
                        error_log('[AICG] Error generando resumen de titulares: ' . $summary_result->get_error_message());
                    }
                } else {
                    error_log('[AICG] No se obtuvieron titulares principales');
                }
            }

            // Paso 2: Procesar cada tema
            $news_topics_config = get_option('aicg_news_topics', array());
            $topics_images = array();
            foreach ($news_topics_config as $topic_config) {
                $topics_images[$topic_config['nombre']] = $topic_config['imagen'] ?? '';
            }

            foreach ($args['topics'] as $topic) {
                error_log('[AICG] Procesando tema: ' . $topic);
                $news = $this->fetch_news_for_topic($topic);
                $original_count = count($news);
                error_log('[AICG] Noticias obtenidas para "' . $topic . '": ' . $original_count);

                // Filtrar URLs ya usadas
                $news = array_filter($news, function($item) use ($used_urls) {
                    return !in_array($item['link'], $used_urls);
                });
                error_log('[AICG] Noticias después de filtrar usadas para "' . $topic . '": ' . count($news));

                // Filtrar noticias locales en sección internacional
                if (strtolower($topic) === 'internacional') {
                    $news = array_filter($news, function($item) {
                        return !$this->is_local_news($item['title'], $item['description']);
                    });
                    error_log('[AICG] Noticias después de filtrar locales: ' . count($news));
                }

                $news = array_slice($news, 0, 5); // Máximo 5 noticias por tema

                if (empty($news)) {
                    error_log('[AICG] Sin noticias para "' . $topic . '" después de filtros');
                    continue;
                }

                // Generar resumen del tema
                $topic_summary = $this->generate_topic_summary($topic, $news);

                if (is_wp_error($topic_summary)) {
                    error_log('[AICG] Error generando resumen para tema "' . $topic . '": ' . $topic_summary->get_error_message());
                    continue;
                }

                if (empty($topic_summary['content'])) {
                    error_log('[AICG] Resumen vacío para tema "' . $topic . '"');
                    continue;
                }

                $result['tokens_used'] += isset($topic_summary['usage']['total_tokens']) ? $topic_summary['usage']['total_tokens'] : 0;
                $result['cost'] += isset($topic_summary['cost']) ? $topic_summary['cost'] : 0;
                $result['news_count'] += count($news);
                $result['topics_processed'][] = $topic;

                // Marcar URLs como usadas
                $this->mark_urls_as_used(array_column($news, 'link'));

                // Construir HTML de la sección
                $content_parts[] = '<div class="aicg-topic-section">';
                $content_parts[] = '<h2>' . esc_html($topic) . '</h2>';

                // Imagen del tema
                if (!empty($topics_images[$topic])) {
                    $content_parts[] = '<img src="' . esc_url($topics_images[$topic]) . '" alt="' . esc_attr($topic) . '" class="aicg-topic-image" />';
                }

                $content_parts[] = $topic_summary['content'];

                // Referencias
                $content_parts[] = '<div class="aicg-references">';
                foreach ($news as $index => $item) {
                    $content_parts[] = sprintf(
                        '<sup><a href="%s" target="_blank" rel="noopener"><strong>%d</strong></a></sup> ',
                        esc_url($item['link']),
                        $index + 1
                    );
                }
                $content_parts[] = '</div>';
                $content_parts[] = '<hr></div>';
            }

            $content_parts[] = '</div>';

            // Generar título
            $date_formatted = wp_date('l, j \d\e F \d\e Y');
            $result['title'] = sprintf(__('Resumen de noticias - %s', 'ai-content-generator'), $date_formatted);
            $result['content'] = implode("\n", $content_parts);

            // Verificar que hay contenido real (más que solo el wrapper vacío)
            $content_without_wrapper = str_replace(array('<div class="aicg-news-summary">', '</div>'), '', $result['content']);
            $content_without_wrapper = trim(strip_tags($content_without_wrapper));

            if (empty($content_without_wrapper) && empty($result['topics_processed'])) {
                error_log('[AICG] No se generó contenido de noticias. Topics procesados: 0');
                return new WP_Error('no_content', __('No se pudo generar contenido. Verifica que hay noticias disponibles y que el proveedor de IA está funcionando correctamente.', 'ai-content-generator'));
            }

            error_log('[AICG] Contenido generado. Topics: ' . implode(', ', $result['topics_processed']) . ' | Tokens: ' . $result['tokens_used']);

            // Crear post
            $post_id = $this->create_post($result, $args);

            if (is_wp_error($post_id)) {
                return $post_id;
            }

            $result['post_id'] = $post_id;

            // Registrar en historial
            $this->log_generation($result);

            return $result;

        } catch (Exception $e) {
            return new WP_Error('generation_error', $e->getMessage());
        }
    }

    /**
     * Obtener titulares principales
     *
     * @return array
     */
    private function fetch_main_headlines() {
        $headlines = array();

        foreach ($this->news_sources as $source_url) {
            $news = $this->fetch_rss_feed($source_url);
            foreach (array_slice($news, 0, 3) as $item) {
                $headlines[] = array(
                    'title' => $item['title'],
                    'source' => isset($item['source']) ? $item['source'] : 'Google News'
                );
            }
        }

        return $headlines;
    }

    /**
     * Obtener noticias para un tema
     *
     * @param string $topic
     * @return array
     */
    private function fetch_news_for_topic($topic) {
        $url = 'https://news.google.com/rss/search?q=' . urlencode($topic) . '&hl=es-419&gl=MX&ceid=MX:es-419';
        return $this->fetch_rss_feed($url);
    }

    /**
     * Obtener feed RSS
     *
     * @param string $url
     * @return array
     */
    private function fetch_rss_feed($url) {
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => $this->user_agent
            )
        ));

        if (is_wp_error($response)) {
            return array();
        }

        $body = wp_remote_retrieve_body($response);

        if (empty($body)) {
            return array();
        }

        // Parsear XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);

        if ($xml === false) {
            return array();
        }

        $items = array();

        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $items[] = array(
                    'title' => (string) $item->title,
                    'link' => (string) $item->link,
                    'pubDate' => (string) $item->pubDate,
                    'description' => isset($item->description) ? strip_tags((string) $item->description) : '',
                    'source' => isset($item->source) ? (string) $item->source : ''
                );
            }
        }

        return $items;
    }

    /**
     * Generar resumen de titulares
     *
     * @param array $headlines
     * @return array|WP_Error
     */
    private function generate_headlines_summary($headlines) {
        $headlines_text = array();
        foreach ($headlines as $headline) {
            $headlines_text[] = sprintf('- %s (%s)', $headline['title'], $headline['source']);
        }

        $prompt = sprintf(
            'Como editor jefe, crea un resumen breve y conciso de las noticias más relevantes del día.

IMPORTANTE:
- Usar ESTRICTAMENTE los datos y hechos tal como aparecen en los titulares
- Usar HTML directamente (NO Markdown)
- Cada párrafo debe estar en tags <p></p>
- Usar <strong> para énfasis (NO asteriscos)
- Extensión: 3-4 párrafos cortos
- Tono objetivo y profesional

Titulares a resumir:
%s

FORMATO DE SALIDA REQUERIDO:
<p>Primer párrafo...</p>
<p>Segundo párrafo...</p>
<p>Tercer párrafo...</p>',
            implode("\n", $headlines_text)
        );

        return $this->provider->generate_text($prompt, array(
            'max_tokens' => 1000,
            'temperature' => 0.5,
            'system_message' => 'Eres un periodista experto que resume noticias de forma objetiva y precisa. Usas HTML puro, nunca Markdown.'
        ));
    }

    /**
     * Generar resumen de un tema
     *
     * @param string $topic
     * @param array  $news
     * @return array|WP_Error
     */
    private function generate_topic_summary($topic, $news) {
        $news_text = array();
        foreach ($news as $item) {
            $news_text[] = sprintf(
                "TITULAR: %s\nDESCRIPCIÓN: %s\nFUENTE: %s",
                $item['title'],
                $item['description'],
                $item['link']
            );
        }

        $prompt = sprintf(
            'Genera un resumen sobre "%s" basado ÚNICAMENTE en estas noticias:

%s

REGLAS ESTRICTAS:
1. Usa SOLO la información proporcionada
2. Mantén todos los datos cuantitativos exactamente como aparecen
3. Si citas declaraciones, mantenlas textuales
4. No agregues información externa ni especulaciones
5. Si hay datos contradictorios entre fuentes, menciona ambas

FORMATO REQUERIDO:
- Usar HTML puro (NO Markdown)
- Cada párrafo en tags <p></p>
- Usar <strong> para énfasis (NO asteriscos)
- NO usar headers (#)
- 2-3 párrafos máximo

EJEMPLO DE FORMATO:
<p>Primer párrafo con <strong>énfasis</strong> donde sea necesario...</p>
<p>Segundo párrafo con más información...</p>',
            $topic,
            implode("\n\n", $news_text)
        );

        return $this->provider->generate_text($prompt, array(
            'max_tokens' => 1500,
            'temperature' => 0.5,
            'system_message' => 'Eres un periodista experto que resume noticias de forma objetiva. Usas HTML puro, nunca Markdown.'
        ));
    }

    /**
     * Verificar si es noticia local
     *
     * @param string $title
     * @param string $description
     * @return bool
     */
    private function is_local_news($title, $description) {
        $local_keywords = array(
            'municipal', 'ayuntamiento', 'alcalde', 'gobernador', 'estatal',
            'local', 'ciudad', 'municipio', 'colonia', 'delegación', 'aeropuerto local'
        );

        $text = strtolower($title . ' ' . $description);

        foreach ($local_keywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Obtener URLs ya usadas
     *
     * @return array
     */
    private function get_used_urls() {
        global $wpdb;

        $table = $wpdb->prefix . 'aicg_used_urls';

        // Verificar si la tabla existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            return array();
        }

        // URLs de los últimos 7 días (sin prepare ya que no hay parámetros de usuario)
        $urls = $wpdb->get_col(
            "SELECT url FROM $table WHERE used_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        return $urls ?: array();
    }

    /**
     * Marcar URLs como usadas
     *
     * @param array $urls
     */
    private function mark_urls_as_used($urls) {
        global $wpdb;

        $table = $wpdb->prefix . 'aicg_used_urls';

        foreach ($urls as $url) {
            $wpdb->replace(
                $table,
                array(
                    'url' => $url,
                    'used_at' => current_time('mysql')
                ),
                array('%s', '%s')
            );
        }

        // Limpiar URLs antiguas (más de 30 días)
        $wpdb->query("DELETE FROM $table WHERE used_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    }

    /**
     * Crear post
     *
     * @param array $result
     * @param array $args
     * @return int|WP_Error
     */
    private function create_post($result, $args) {
        // Obtener o crear categoría "Noticias"
        $category = get_term_by('name', 'Noticias', 'category');
        $category_id = $category ? $category->term_id : wp_create_category('Noticias');

        $post_data = array(
            'post_title' => $result['title'],
            'post_content' => $result['content'],
            'post_status' => $args['post_status'],
            'post_type' => 'post',
            'post_author' => get_current_user_id(),
            'post_category' => array($category_id)
        );

        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Tags
        wp_set_post_tags($post_id, $result['topics_processed']);

        // Meta datos
        update_post_meta($post_id, '_aicg_generated', true);
        update_post_meta($post_id, '_aicg_type', 'news');
        update_post_meta($post_id, '_aicg_provider', $this->provider->get_name());
        update_post_meta($post_id, '_aicg_tokens', $result['tokens_used']);
        update_post_meta($post_id, '_aicg_cost', $result['cost']);
        update_post_meta($post_id, '_aicg_news_count', $result['news_count']);

        return $post_id;
    }

    /**
     * Registrar generación
     *
     * @param array $result
     */
    private function log_generation($result) {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'aicg_history',
            array(
                'type' => 'news',
                'post_id' => $result['post_id'],
                'provider' => $this->provider->get_name(),
                'model' => get_option('aicg_default_model', 'gpt-4o'),
                'topic' => implode(', ', $result['topics_processed']),
                'tokens_used' => $result['tokens_used'],
                'cost' => $result['cost'],
                'created_at' => current_time('mysql')
            ),
            array('%s', '%d', '%s', '%s', '%s', '%d', '%f', '%s')
        );
    }
}
