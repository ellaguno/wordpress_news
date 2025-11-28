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
            'post_status' => 'draft',
            'post_type' => get_option('aicg_news_post_type', 'post'),
            'generate_image' => get_option('aicg_news_generate_image', false)
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
            'topics_processed' => array(),
            'image_id' => 0
        );

        // Variable para almacenar titulares (usados para generar imagen)
        $main_headlines = array();

        try {
            // Obtener URLs ya usadas
            $used_urls = $this->get_used_urls();

            $content_parts = array('<div class="aicg-news-summary">');

            // Paso 1: Obtener y resumir titulares principales
            if ($args['include_headlines']) {
                $headlines = $this->fetch_main_headlines();
                $main_headlines = $headlines; // Guardar para generar imagen después
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

                // Referencias con estilos configurables
                $ref_style = get_option('aicg_reference_style', 'inline');
                $ref_color = get_option('aicg_reference_color', '#0073aa');
                $ref_orientation = get_option('aicg_reference_orientation', 'horizontal');

                // Estilos según orientación - horizontal por defecto con estilos explícitos
                if ($ref_orientation === 'vertical') {
                    $orientation_style = 'display: flex; flex-direction: column; align-items: flex-start; gap: 5px;';
                } else {
                    // Horizontal: forzar que los elementos estén en línea
                    $orientation_style = 'display: flex; flex-direction: row; flex-wrap: wrap; align-items: center; gap: 5px;';
                }

                // Construir referencias como una sola cadena (sin saltos de línea)
                // Usamos SVG para los números para evitar que lectores de pantalla los lean
                $refs_html = '';
                foreach ($news as $index => $item) {
                    $num = $index + 1;
                    $link = esc_url($item['link']);

                    // Generar SVG con el número (no será leído por lectores de pantalla)
                    $svg_number = $this->generate_number_svg($num, $ref_color, $ref_style);

                    switch ($ref_style) {
                        case 'circle':
                            $refs_html .= sprintf(
                                '<a href="%s" target="_blank" rel="noopener" aria-hidden="true" tabindex="-1" class="aicg-ref-circle" style="display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; text-decoration: none; margin: 0 3px;">%s</a>',
                                $link,
                                $svg_number
                            );
                            break;

                        case 'square':
                            $refs_html .= sprintf(
                                '<a href="%s" target="_blank" rel="noopener" aria-hidden="true" tabindex="-1" class="aicg-ref-square" style="display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; text-decoration: none; margin: 0 3px;">%s</a>',
                                $link,
                                $svg_number
                            );
                            break;

                        case 'badge':
                            $refs_html .= sprintf(
                                '<a href="%s" target="_blank" rel="noopener" aria-hidden="true" tabindex="-1" class="aicg-ref-badge" style="display: inline-flex; align-items: center; justify-content: center; height: 24px; text-decoration: none; margin: 0 3px;">%s</a>',
                                $link,
                                $this->generate_badge_svg($num, $ref_color)
                            );
                            break;

                        case 'inline':
                        default:
                            $refs_html .= sprintf(
                                '<sup><a href="%s" target="_blank" rel="noopener" aria-hidden="true" tabindex="-1" style="text-decoration: none;">%s</a></sup> ',
                                $link,
                                $this->generate_inline_svg($num, $ref_color)
                            );
                            break;
                    }
                }

                // Agregar div de referencias como una sola línea
                $content_parts[] = '<div class="aicg-references aicg-ref-' . esc_attr($ref_style) . '" style="' . $orientation_style . '" data-color="' . esc_attr($ref_color) . '">' . $refs_html . '</div>';
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

            // Variable para la imagen generada
            $generated_image_data = null;

            // Generar imagen con IA si está habilitado
            if (!empty($args['generate_image']) && !empty($main_headlines)) {
                $image_result = $this->generate_featured_image($main_headlines);
                if (!is_wp_error($image_result) && !empty($image_result['attachment_id'])) {
                    $generated_image_url = wp_get_attachment_url($image_result['attachment_id']);
                    $generated_image_alt = __('Imagen generada para el resumen de noticias', 'ai-content-generator');

                    $generated_image_data = array(
                        'url' => $generated_image_url,
                        'alt' => $generated_image_alt,
                        'id' => $image_result['attachment_id']
                    );

                    $result['generated_image_id'] = $image_result['attachment_id'];
                    $result['cost'] += isset($image_result['cost']) ? $image_result['cost'] : 0;
                    error_log('[AICG] Imagen generada con ID: ' . $image_result['attachment_id']);
                } elseif (is_wp_error($image_result)) {
                    error_log('[AICG] Error generando imagen: ' . $image_result->get_error_message());
                }
            }

            // Convertir a formato según configuración
            $content_format = get_option('aicg_content_format', 'gutenberg');

            // Si está vacío, usar gutenberg por defecto
            if (empty($content_format)) {
                $content_format = 'gutenberg';
            }

            error_log('[AICG] ========== INICIO CONVERSION GUTENBERG ==========');
            error_log('[AICG] Formato de contenido configurado: "' . $content_format . '"');
            error_log('[AICG] Clase Gutenberg existe: ' . (class_exists('AICG_Gutenberg_Converter') ? 'SI' : 'NO'));
            error_log('[AICG] Comparación formato === gutenberg: ' . ($content_format === 'gutenberg' ? 'TRUE' : 'FALSE'));

            if ($content_format === 'gutenberg' && class_exists('AICG_Gutenberg_Converter')) {
                error_log('[AICG] ENTRANDO al bloque de conversión Gutenberg');
                error_log('[AICG] HTML antes de convertir (primeros 500 chars): ' . substr($result['content'], 0, 500));

                // Convertir HTML a bloques Gutenberg
                $result['content'] = AICG_Gutenberg_Converter::convert($result['content']);

                error_log('[AICG] Contenido después de convertir (primeros 500 chars): ' . substr($result['content'], 0, 500));

                // Insertar imagen generada como bloque al inicio (si hay)
                if ($generated_image_data) {
                    $image_block = AICG_Gutenberg_Converter::image_block(
                        $generated_image_data['url'],
                        $generated_image_data['alt'],
                        '',
                        array(
                            'align' => 'center',
                            'sizeSlug' => 'large',
                            'id' => $generated_image_data['id']
                        )
                    );
                    $result['content'] = $image_block . "\n\n" . $result['content'];
                }

                error_log('[AICG] Contenido convertido a formato Gutenberg');
            } else {
                error_log('[AICG] NO se entró al bloque de conversión Gutenberg. Razón: formato="' . $content_format . '", clase_existe=' . (class_exists('AICG_Gutenberg_Converter') ? 'SI' : 'NO'));

                // Formato clásico HTML - insertar imagen al inicio
                if ($generated_image_data) {
                    $image_html = sprintf(
                        '<figure class="aicg-generated-image aligncenter" style="margin: 0 0 20px 0; text-align: center;"><img src="%s" alt="%s" style="max-width: 100%%; height: auto; display: block; margin: 0 auto;"></figure>',
                        esc_url($generated_image_data['url']),
                        esc_attr($generated_image_data['alt'])
                    );

                    // Insertar después del div de apertura
                    $result['content'] = preg_replace(
                        '/(<div class="aicg-news-summary">)/',
                        '$1' . $image_html,
                        $result['content'],
                        1
                    );
                }
            }

            // Usar imagen destacada fija si está configurada
            $featured_image_id = get_option('aicg_news_featured_image', 0);
            if ($featured_image_id > 0) {
                $result['image_id'] = $featured_image_id;
                error_log('[AICG] Usando imagen destacada fija ID: ' . $featured_image_id);
            }

            // Validar que el contenido tiene formato de bloques Gutenberg
            if ($content_format === 'gutenberg') {
                $starts_with_block = strpos(ltrim($result['content']), '<!-- wp:') === 0;
                error_log('[AICG] Contenido inicia con bloque Gutenberg: ' . ($starts_with_block ? 'SI' : 'NO'));
                error_log('[AICG] Primeros 200 caracteres del contenido final: ' . substr($result['content'], 0, 200));
            }

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

        // Obtener instrucciones adicionales del usuario
        $user_instructions = get_option('aicg_news_user_prompt', '');
        $style_instructions = !empty($user_instructions) ? "\n\nINSTRUCCIONES DE ESTILO:\n" . $user_instructions : '';

        $prompt = sprintf(
            'Como editor jefe, crea un resumen breve y conciso de las noticias más relevantes del día.

IMPORTANTE:
- Usar ESTRICTAMENTE los datos y hechos tal como aparecen en los titulares
- Usar HTML directamente (NO Markdown)
- Cada párrafo debe estar en tags <p></p>
- Usar <strong> para énfasis (NO asteriscos)
- Extensión: 3-4 párrafos cortos
%s

Titulares a resumir:
%s

FORMATO DE SALIDA REQUERIDO:
<p>Primer párrafo...</p>
<p>Segundo párrafo...</p>
<p>Tercer párrafo...</p>',
            $style_instructions,
            implode("\n", $headlines_text)
        );

        // Obtener prompt del sistema personalizado
        $system_prompt = get_option('aicg_news_system_prompt', 'Eres un periodista experto que resume noticias de forma objetiva y precisa. Usas HTML puro, nunca Markdown.');

        return $this->provider->generate_text($prompt, array(
            'max_tokens' => 1000,
            'temperature' => 0.5,
            'system_message' => $system_prompt
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

        // Obtener instrucciones adicionales del usuario
        $user_instructions = get_option('aicg_news_user_prompt', '');
        $style_instructions = !empty($user_instructions) ? "\n\nINSTRUCCIONES DE ESTILO:\n" . $user_instructions : '';

        $prompt = sprintf(
            'Genera un resumen sobre "%s" basado ÚNICAMENTE en estas noticias:

%s

REGLAS ESTRICTAS:
1. Usa SOLO la información proporcionada
2. Mantén todos los datos cuantitativos exactamente como aparecen
3. Si citas declaraciones, mantenlas textuales
4. No agregues información externa ni especulaciones
5. Si hay datos contradictorios entre fuentes, menciona ambas
%s

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
            implode("\n\n", $news_text),
            $style_instructions
        );

        // Obtener prompt del sistema personalizado
        $system_prompt = get_option('aicg_news_system_prompt', 'Eres un periodista experto que resume noticias de forma objetiva. Usas HTML puro, nunca Markdown.');

        return $this->provider->generate_text($prompt, array(
            'max_tokens' => 1500,
            'temperature' => 0.5,
            'system_message' => $system_prompt
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
     * Generar imagen destacada basada en los titulares
     *
     * @param array $headlines
     * @return array|WP_Error
     */
    private function generate_featured_image($headlines) {
        // Obtener proveedor de imágenes
        $image_provider = AICG_AI_Provider_Factory::get_image_provider();

        if (is_wp_error($image_provider)) {
            return $image_provider;
        }

        if (!$image_provider->is_configured()) {
            return new WP_Error('image_provider_not_configured', __('Proveedor de imágenes no configurado', 'ai-content-generator'));
        }

        // Crear prompt basado en los titulares
        $headlines_text = array();
        foreach (array_slice($headlines, 0, 5) as $headline) {
            $headlines_text[] = $headline['title'];
        }

        $prompt = sprintf(
            'Create a professional news header image that represents these headlines from today: %s.
            Style: Modern news media, clean design, abstract representation of news themes.
            Do NOT include any text or words in the image.
            Use a color palette suitable for a news website.',
            implode('; ', $headlines_text)
        );

        // Obtener configuración de imagen
        $image_size = get_option('aicg_image_size', '1792x1024');
        $image_quality = get_option('aicg_image_quality', 'standard');

        // Generar imagen
        $image_result = $image_provider->generate_image($prompt, array(
            'size' => $image_size,
            'quality' => $image_quality
        ));

        if (is_wp_error($image_result)) {
            return $image_result;
        }

        // Descargar y guardar en la biblioteca de medios
        $attachment_id = $this->save_image_to_media_library($image_result['url']);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        return array(
            'attachment_id' => $attachment_id,
            'cost' => isset($image_result['cost']) ? $image_result['cost'] : 0
        );
    }

    /**
     * Guardar imagen en la biblioteca de medios
     *
     * Soporta tanto URLs remotas como data URLs base64
     *
     * @param string $image_url URL de imagen o data URL base64
     * @return int|WP_Error
     */
    private function save_image_to_media_library($image_url) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $filename = 'news-summary-' . date('Y-m-d-His');

        // Verificar si es una data URL base64
        if (strpos($image_url, 'data:image/') === 0) {
            return $this->save_base64_image($image_url, $filename);
        }

        // Es una URL remota - descargar
        $tmp_file = download_url($image_url);

        if (is_wp_error($tmp_file)) {
            error_log('[AICG] Error descargando imagen: ' . $tmp_file->get_error_message());
            return $tmp_file;
        }

        // Detectar extensión desde el contenido
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $tmp_file);
        finfo_close($finfo);

        $ext = 'png';
        if ($mime_type === 'image/jpeg') {
            $ext = 'jpg';
        } elseif ($mime_type === 'image/webp') {
            $ext = 'webp';
        } elseif ($mime_type === 'image/gif') {
            $ext = 'gif';
        }

        $file_array = array(
            'name' => $filename . '.' . $ext,
            'tmp_name' => $tmp_file
        );

        // Mover a la biblioteca de medios
        $attachment_id = media_handle_sideload($file_array, 0, __('Imagen de resumen de noticias', 'ai-content-generator'));

        // Limpiar archivo temporal si hubo error
        if (is_wp_error($attachment_id)) {
            @unlink($tmp_file);
            error_log('[AICG] Error guardando imagen: ' . $attachment_id->get_error_message());
            return $attachment_id;
        }

        return $attachment_id;
    }

    /**
     * Guardar imagen base64 en la biblioteca de medios
     *
     * @param string $data_url Data URL en formato data:image/png;base64,...
     * @param string $filename Nombre base del archivo (sin extensión)
     * @return int|WP_Error
     */
    private function save_base64_image($data_url, $filename) {
        // Extraer tipo MIME y datos base64 (soporta formatos como png, jpeg, webp, gif)
        if (!preg_match('/^data:image\/([a-zA-Z0-9+]+);base64,(.+)$/s', $data_url, $matches)) {
            error_log('[AICG] Invalid base64 format. First 100 chars: ' . substr($data_url, 0, 100));
            return new WP_Error('invalid_base64', __('Formato de imagen base64 inválido', 'ai-content-generator'));
        }

        $image_type = strtolower($matches[1]);
        $base64_data = $matches[2];

        error_log('[AICG] Saving base64 image. Type: ' . $image_type . ', Base64 length: ' . strlen($base64_data));

        // Decodificar base64
        $image_data = base64_decode($base64_data, true);
        if ($image_data === false) {
            error_log('[AICG] Failed to decode base64 data');
            return new WP_Error('decode_error', __('Error al decodificar imagen base64', 'ai-content-generator'));
        }

        error_log('[AICG] Decoded image size: ' . strlen($image_data) . ' bytes');

        // Determinar extensión
        $ext = 'png';
        switch ($image_type) {
            case 'jpeg':
            case 'jpg':
                $ext = 'jpg';
                break;
            case 'webp':
                $ext = 'webp';
                break;
            case 'gif':
                $ext = 'gif';
                break;
            case 'png':
            default:
                $ext = 'png';
                break;
        }

        // Crear archivo temporal en el directorio de uploads
        $upload_dir = wp_upload_dir();
        $tmp_file = $upload_dir['path'] . '/' . $filename . '.' . $ext;

        // Escribir datos al archivo
        $bytes_written = file_put_contents($tmp_file, $image_data);
        if ($bytes_written === false) {
            error_log('[AICG] Failed to write image to: ' . $tmp_file);
            return new WP_Error('write_error', __('Error al escribir archivo de imagen', 'ai-content-generator'));
        }

        error_log('[AICG] Image written to: ' . $tmp_file . ' (' . $bytes_written . ' bytes)');

        // Preparar array de archivo
        $file_array = array(
            'name' => $filename . '.' . $ext,
            'tmp_name' => $tmp_file
        );

        // Mover a la biblioteca de medios
        $attachment_id = media_handle_sideload($file_array, 0, __('Imagen de resumen de noticias', 'ai-content-generator'));

        // Limpiar archivo temporal si hubo error
        if (is_wp_error($attachment_id)) {
            @unlink($tmp_file);
            error_log('[AICG] Error guardando imagen base64: ' . $attachment_id->get_error_message());
            return $attachment_id;
        }

        error_log('[AICG] Image saved with attachment ID: ' . $attachment_id);

        return $attachment_id;
    }

    /**
     * Crear post
     *
     * @param array $result
     * @param array $args
     * @return int|WP_Error
     */
    private function create_post($result, $args) {
        $post_type = isset($args['post_type']) ? $args['post_type'] : 'post';

        // Verificar si debemos actualizar un post existente
        $update_existing = get_option('aicg_news_update_existing', false);
        $target_post_id = get_option('aicg_news_target_post', 0);
        $existing_post_id = null;

        if ($update_existing) {
            if ($target_post_id > 0) {
                // Verificar que el post existe
                $existing_post = get_post($target_post_id);
                if ($existing_post && in_array($existing_post->post_status, array('publish', 'draft', 'pending', 'private'))) {
                    $existing_post_id = $target_post_id;
                }
            }

            // Si no hay target configurado, buscar el último post de noticias generado
            if (!$existing_post_id) {
                $last_news_post = get_posts(array(
                    'post_type' => $post_type,
                    'posts_per_page' => 1,
                    'meta_query' => array(
                        array(
                            'key' => '_aicg_type',
                            'value' => 'news',
                            'compare' => '='
                        )
                    ),
                    'orderby' => 'modified',
                    'order' => 'DESC'
                ));

                if (!empty($last_news_post)) {
                    $existing_post_id = $last_news_post[0]->ID;
                    // Guardar para futuras actualizaciones
                    update_option('aicg_news_target_post', $existing_post_id);
                }
            }
        }

        $post_data = array(
            'post_title' => $result['title'],
            'post_content' => $result['content'],
            'post_status' => $args['post_status'],
            'post_type' => $post_type,
            'post_author' => get_current_user_id()
        );

        // Si actualizamos un post existente
        if ($existing_post_id) {
            $post_data['ID'] = $existing_post_id;
            $post_id = wp_update_post($post_data);

            if (!is_wp_error($post_id)) {
                // Actualizar la fecha de modificación
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_modified' => current_time('mysql'),
                    'post_modified_gmt' => current_time('mysql', true)
                ));

                error_log('[AICG] Updated existing news post ID: ' . $post_id);
            }
        } else {
            // Solo agregar categoría si es un post nuevo (las páginas no tienen categorías)
            if ($post_type === 'post') {
                $category = get_term_by('name', 'Noticias', 'category');
                $category_id = $category ? $category->term_id : wp_create_category('Noticias');
                $post_data['post_category'] = array($category_id);
            }

            $post_id = wp_insert_post($post_data);

            // Si es la primera vez y está activa la opción de actualizar, guardar el ID
            if (!is_wp_error($post_id) && $update_existing && !$target_post_id) {
                update_option('aicg_news_target_post', $post_id);
            }

            error_log('[AICG] Created new news post ID: ' . $post_id);
        }

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Tags (solo para posts)
        if ($post_type === 'post') {
            wp_set_post_tags($post_id, $result['topics_processed']);
        }

        // Imagen destacada
        if (!empty($result['image_id'])) {
            set_post_thumbnail($post_id, $result['image_id']);
        }

        // Meta datos
        update_post_meta($post_id, '_aicg_generated', true);
        update_post_meta($post_id, '_aicg_type', 'news');
        update_post_meta($post_id, '_aicg_provider', $this->provider->get_name());
        update_post_meta($post_id, '_aicg_tokens', $result['tokens_used']);
        update_post_meta($post_id, '_aicg_cost', $result['cost']);
        update_post_meta($post_id, '_aicg_news_count', $result['news_count']);
        update_post_meta($post_id, '_aicg_last_updated', current_time('mysql'));

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

    /**
     * Obtener path SVG para un dígito (0-9)
     * Paths diseñados para viewBox de 10x14
     *
     * @param int $digit Dígito 0-9
     * @return string Path SVG
     */
    private function get_digit_path($digit) {
        $paths = array(
            0 => 'M5 1C2.5 1 1 3 1 7s1.5 6 4 6 4-2 4-6-1.5-6-4-6zm0 2c1.4 0 2 1.3 2 4s-.6 4-2 4-2-1.3-2-4 .6-4 2-4z',
            1 => 'M4 1L2 3v1h2v8h-2v1h6v-1h-2V1z',
            2 => 'M2 3c0-1.1.9-2 2-2h2c1.1 0 2 .9 2 2v2c0 1.1-.9 2-2 2H4v3h4v1H2v-5h4c.6 0 1-.4 1-1V3c0-.6-.4-1-1-1H4c-.6 0-1 .4-1 1v1H2V3z',
            3 => 'M2 3c0-1.1.9-2 2-2h2c1.1 0 2 .9 2 2v2c0 .6-.2 1-.6 1.4.4.4.6.8.6 1.4v3c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2v-1h1v1c0 .6.4 1 1 1h2c.6 0 1-.4 1-1V8c0-.6-.4-1-1-1H4V6h2c.6 0 1-.4 1-1V3c0-.6-.4-1-1-1H4c-.6 0-1 .4-1 1v1H2V3z',
            4 => 'M6 1v6H2V8h4v5h1V8h2V7H7V1z M6 7V3L3 7z',
            5 => 'M8 1H2v5h4c.6 0 1 .4 1 1v3c0 .6-.4 1-1 1H4c-.6 0-1-.4-1-1v-1H2v1c0 1.1.9 2 2 2h2c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2H3V2h5V1z',
            6 => 'M6 1H4C2.9 1 2 1.9 2 3v8c0 1.1.9 2 2 2h2c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2H3V3c0-.6.4-1 1-1h2V1zm0 6c.6 0 1 .4 1 1v3c0 .6-.4 1-1 1H4c-.6 0-1-.4-1-1V8c0-.6.4-1 1-1h2z',
            7 => 'M2 1v1h5l-4 11h1l4-11V1z',
            8 => 'M4 1C2.9 1 2 1.9 2 3v2c0 .6.2 1 .6 1.4-.4.4-.6.8-.6 1.4v3c0 1.1.9 2 2 2h2c1.1 0 2-.9 2-2V8c0-.6-.2-1-.6-1.4.4-.4.6-.8.6-1.4V3c0-1.1-.9-2-2-2H4zm0 1h2c.6 0 1 .4 1 1v2c0 .6-.4 1-1 1H4c-.6 0-1-.4-1-1V3c0-.6.4-1 1-1zm0 5h2c.6 0 1 .4 1 1v3c0 .6-.4 1-1 1H4c-.6 0-1-.4-1-1V8c0-.6.4-1 1-1z',
            9 => 'M4 1C2.9 1 2 1.9 2 3v3c0 1.1.9 2 2 2h3v3c0 .6-.4 1-1 1H4v1h2c1.1 0 2-.9 2-2V3c0-1.1-.9-2-2-2H4zm0 1h2c.6 0 1 .4 1 1v3c0 .6-.4 1-1 1H4c-.6 0-1-.4-1-1V3c0-.6.4-1 1-1z',
        );

        return isset($paths[$digit]) ? $paths[$digit] : $paths[0];
    }

    /**
     * Generar SVG con número usando paths (no texto)
     *
     * @param int    $num Número a mostrar
     * @param string $bg_color Color de fondo
     * @param string $num_color Color del número
     * @param string $style Estilo (circle o square)
     * @return string SVG inline
     */
    private function generate_number_svg($num, $bg_color, $style) {
        $is_circle = ($style === 'circle');
        $rx = $is_circle ? '12' : '4';

        // Para números de un dígito
        if ($num < 10) {
            $path = $this->get_digit_path($num);
            return sprintf(
                '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><rect width="24" height="24" fill="%s" rx="%s"/><g transform="translate(7,5)"><path d="%s" fill="white"/></g></svg>',
                esc_attr($bg_color),
                $rx,
                $path
            );
        }

        // Para números de dos dígitos
        $d1 = floor($num / 10);
        $d2 = $num % 10;
        $path1 = $this->get_digit_path($d1);
        $path2 = $this->get_digit_path($d2);

        return sprintf(
            '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><rect width="24" height="24" fill="%s" rx="%s"/><g transform="translate(2,5) scale(0.9)"><path d="%s" fill="white"/></g><g transform="translate(12,5) scale(0.9)"><path d="%s" fill="white"/></g></svg>',
            esc_attr($bg_color),
            $rx,
            $path1,
            $path2
        );
    }

    /**
     * Generar SVG con número para estilo badge
     *
     * @param int    $num Número a mostrar
     * @param string $color Color del texto y borde
     * @return string SVG inline
     */
    private function generate_badge_svg($num, $color) {
        $bg_color = $this->hex_to_rgba($color, 0.15);

        if ($num < 10) {
            $path = $this->get_digit_path($num);
            return sprintf(
                '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><rect width="24" height="24" fill="%s" rx="12"/><g transform="translate(7,5)"><path d="%s" fill="%s"/></g></svg>',
                $bg_color,
                $path,
                esc_attr($color)
            );
        }

        $d1 = floor($num / 10);
        $d2 = $num % 10;
        $path1 = $this->get_digit_path($d1);
        $path2 = $this->get_digit_path($d2);

        return sprintf(
            '<svg width="32" height="24" viewBox="0 0 32 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><rect width="32" height="24" fill="%s" rx="12"/><g transform="translate(6,5) scale(0.9)"><path d="%s" fill="%s"/></g><g transform="translate(16,5) scale(0.9)"><path d="%s" fill="%s"/></g></svg>',
            $bg_color,
            $path1,
            esc_attr($color),
            $path2,
            esc_attr($color)
        );
    }

    /**
     * Generar SVG con número para estilo inline (superíndice)
     *
     * @param int    $num Número a mostrar
     * @param string $color Color del texto
     * @return string SVG inline
     */
    private function generate_inline_svg($num, $color) {
        if ($num < 10) {
            $path = $this->get_digit_path($num);
            return sprintf(
                '<svg width="10" height="14" viewBox="0 0 10 14" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="%s" fill="%s"/></svg>',
                $path,
                esc_attr($color)
            );
        }

        $d1 = floor($num / 10);
        $d2 = $num % 10;
        $path1 = $this->get_digit_path($d1);
        $path2 = $this->get_digit_path($d2);

        return sprintf(
            '<svg width="18" height="14" viewBox="0 0 18 14" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><g transform="scale(0.85)"><path d="%s" fill="%s"/></g><g transform="translate(9,0) scale(0.85)"><path d="%s" fill="%s"/></g></svg>',
            $path1,
            esc_attr($color),
            $path2,
            esc_attr($color)
        );
    }

    /**
     * Convertir color hexadecimal a RGBA
     *
     * @param string $hex Color en formato hex (#RRGGBB)
     * @param float  $alpha Valor de transparencia (0-1)
     * @return string Color en formato rgba()
     */
    private function hex_to_rgba($hex, $alpha = 1) {
        // Remover # si existe
        $hex = ltrim($hex, '#');

        // Expandir formato corto (#RGB a #RRGGBB)
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        // Convertir a RGB
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return sprintf('rgba(%d, %d, %d, %.2f)', $r, $g, $b, $alpha);
    }
}
