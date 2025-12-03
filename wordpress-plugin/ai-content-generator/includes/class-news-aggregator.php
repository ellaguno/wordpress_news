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
     * URLs de fuentes RSS por defecto
     *
     * @var array
     */
    private $default_sources = array(
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
        // Registrar handler para capturar errores fatales
        register_shutdown_function(function() {
            $error = error_get_last();
            if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
                error_log('[AICG] ERROR FATAL en generate(): ' . $error['message'] . ' en ' . $error['file'] . ':' . $error['line']);
            }
        });

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

            // Obtener titulares principales (necesarios para imagen aunque no se muestren)
            $headlines = $this->fetch_main_headlines();
            $main_headlines = $headlines; // Guardar para generar imagen después

            // Paso 1: Resumir titulares principales (solo si include_headlines está activo)
            if ($args['include_headlines']) {
                if (!empty($headlines)) {
                    $summary_result = $this->generate_headlines_summary($headlines);
                    if (!is_wp_error($summary_result) && !empty($summary_result['content'])) {
                        $content_parts[] = '<div class="aicg-general-summary">';
                        $content_parts[] = '<h2>' . esc_html__('Resumen del Día', 'ai-content-generator') . '</h2>';
                        $content_parts[] = $summary_result['content'];
                        $content_parts[] = '</div>';
                        // Marcador para insertar imagen generada después del resumen
                        $content_parts[] = '<!--AICG_GENERATED_IMAGE_PLACEHOLDER-->';
                        $result['tokens_used'] += isset($summary_result['usage']['total_tokens']) ? $summary_result['usage']['total_tokens'] : 0;
                        $result['cost'] += isset($summary_result['cost']) ? $summary_result['cost'] : 0;
                    } elseif (is_wp_error($summary_result)) {
                        // Log error pero continuar
                        error_log('[AICG] Error generando resumen de titulares: ' . $summary_result->get_error_message());
                    }
                } else {
                    error_log('[AICG] No se obtuvieron titulares principales');
                }
            } else {
                // Agregar marcador para imagen al inicio aunque no haya resumen
                $content_parts[] = '<!--AICG_GENERATED_IMAGE_PLACEHOLDER-->';
            }

            // Paso 2: Procesar cada tema
            $news_topics_config = get_option('aicg_news_topics', array());
            $topics_images = array();
            foreach ($news_topics_config as $topic_config) {
                $topics_images[$topic_config['nombre']] = $topic_config['imagen'] ?? '';
            }

            // URLs usadas en esta sesión (para evitar repetición entre temas)
            $session_used_urls = array();

            foreach ($args['topics'] as $topic) {
                error_log('[AICG] Procesando tema: ' . $topic);
                $news = $this->fetch_news_for_topic($topic);
                $original_count = count($news);
                error_log('[AICG] Noticias obtenidas para "' . $topic . '": ' . $original_count);

                // Filtrar URLs ya usadas (base de datos + sesión actual)
                $all_used_urls = array_merge($used_urls, $session_used_urls);
                $news = array_filter($news, function($item) use ($all_used_urls) {
                    return !in_array($item['link'], $all_used_urls);
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

                // Marcar URLs como usadas (en DB y en sesión actual)
                $news_urls = array_column($news, 'link');
                $this->mark_urls_as_used($news_urls);
                $session_used_urls = array_merge($session_used_urls, $news_urls);

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
                $ref_size = intval(get_option('aicg_reference_size', 24));

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
                    // Extraer nombre de la fuente para el tooltip
                    $source_name = !empty($item['source']) ? $item['source'] : '';
                    if (empty($source_name) && !empty($item['title'])) {
                        // Intentar extraer fuente del título (formato: "Título - Fuente")
                        if (preg_match('/\s-\s([^-]+)$/', $item['title'], $matches)) {
                            $source_name = trim($matches[1]);
                        }
                    }
                    $tooltip = !empty($source_name) ? esc_attr($source_name) : esc_attr__('Ver fuente', 'ai-content-generator');

                    // Generar SVG con el número (no será leído por lectores de pantalla)
                    $svg_number = $this->generate_number_svg($num, $ref_color, $ref_style, $ref_size);

                    switch ($ref_style) {
                        case 'circle':
                            $refs_html .= sprintf(
                                '<a href="%s" target="_blank" rel="noopener" aria-hidden="true" tabindex="-1" title="%s" class="aicg-ref-circle" style="display: inline-flex; align-items: center; justify-content: center; width: %dpx; height: %dpx; text-decoration: none; margin: 0 3px;">%s</a>',
                                $link,
                                $tooltip,
                                $ref_size,
                                $ref_size,
                                $svg_number
                            );
                            break;

                        case 'square':
                            $refs_html .= sprintf(
                                '<a href="%s" target="_blank" rel="noopener" aria-hidden="true" tabindex="-1" title="%s" class="aicg-ref-square" style="display: inline-flex; align-items: center; justify-content: center; width: %dpx; height: %dpx; text-decoration: none; margin: 0 3px;">%s</a>',
                                $link,
                                $tooltip,
                                $ref_size,
                                $ref_size,
                                $svg_number
                            );
                            break;

                        case 'badge':
                            $refs_html .= sprintf(
                                '<a href="%s" target="_blank" rel="noopener" aria-hidden="true" tabindex="-1" title="%s" class="aicg-ref-badge" style="display: inline-flex; align-items: center; justify-content: center; height: %dpx; text-decoration: none; margin: 0 3px;">%s</a>',
                                $link,
                                $tooltip,
                                $ref_size,
                                $this->generate_badge_svg($num, $ref_color, $ref_size)
                            );
                            break;

                        case 'inline':
                        default:
                            $inline_size = max(10, intval($ref_size * 0.6)); // Tamaño proporcional para inline
                            $refs_html .= sprintf(
                                '<sup><a href="%s" target="_blank" rel="noopener" aria-hidden="true" tabindex="-1" title="%s" style="text-decoration: none;">%s</a></sup> ',
                                $link,
                                $tooltip,
                                $this->generate_inline_svg($num, $ref_color, $inline_size)
                            );
                            break;
                    }
                }

                // Agregar div de referencias como una sola línea
                $content_parts[] = '<div class="aicg-references aicg-ref-' . esc_attr($ref_style) . '" style="' . $orientation_style . '" data-color="' . esc_attr($ref_color) . '" data-size="' . esc_attr($ref_size) . '">' . $refs_html . '</div>';
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
            error_log('[AICG] generate_image arg: ' . ($args['generate_image'] ? 'true' : 'false'));
            error_log('[AICG] main_headlines count: ' . count($main_headlines));

            if (!empty($args['generate_image'])) {
                // Si no hay titulares principales, usar títulos de los temas procesados
                $headlines_for_image = $main_headlines;
                if (empty($headlines_for_image) && !empty($result['topics_processed'])) {
                    error_log('[AICG] No hay titulares principales, usando temas procesados para imagen');
                    foreach ($result['topics_processed'] as $topic) {
                        $headlines_for_image[] = array(
                            'title' => $topic,
                            'source' => 'Tema del resumen'
                        );
                    }
                }

                if (!empty($headlines_for_image)) {
                    error_log('[AICG] Generando imagen con ' . count($headlines_for_image) . ' titulares');

                    // Try-catch específico para generación de imagen
                    try {
                        $image_result = $this->generate_featured_image($headlines_for_image);
                        error_log('[AICG] generate_featured_image retornó: ' . (is_wp_error($image_result) ? 'WP_Error' : 'array'));

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
                    } catch (Exception $img_e) {
                        error_log('[AICG] Excepción generando imagen: ' . $img_e->getMessage());
                        // Continuar sin imagen, no fallar todo el proceso
                    } catch (Error $img_err) {
                        error_log('[AICG] Error fatal generando imagen: ' . $img_err->getMessage());
                        // Continuar sin imagen, no fallar todo el proceso
                    }
                } else {
                    error_log('[AICG] No hay titulares disponibles para generar imagen');
                }
            }

            error_log('[AICG] Continuando después de generación de imagen...');

            // Convertir a formato según configuración
            $content_format = get_option('aicg_content_format', 'gutenberg');

            // Si está vacío, usar gutenberg por defecto
            if (empty($content_format)) {
                $content_format = 'gutenberg';
            }

            // PRIMERO: Insertar imagen en HTML ANTES de conversión Gutenberg
            // (El convertidor Gutenberg no preserva comentarios HTML)
            if ($generated_image_data) {
                $image_html = sprintf(
                    '<figure class="aicg-generated-image alignwide" data-image-id="%d"><img src="%s" alt="%s" style="width: 100%%; height: auto; aspect-ratio: 16/9; object-fit: cover; display: block;"></figure>',
                    intval($generated_image_data['id']),
                    esc_url($generated_image_data['url']),
                    esc_attr($generated_image_data['alt'])
                );
                $result['content'] = str_replace('<!--AICG_GENERATED_IMAGE_PLACEHOLDER-->', $image_html, $result['content']);
                error_log('[AICG] Imagen insertada en HTML antes de conversión');
            } else {
                // Eliminar marcador si no hay imagen
                $result['content'] = str_replace('<!--AICG_GENERATED_IMAGE_PLACEHOLDER-->', '', $result['content']);
            }

            error_log('[AICG] ========== INICIO CONVERSION GUTENBERG ==========');
            error_log('[AICG] Formato de contenido configurado: "' . $content_format . '"');
            error_log('[AICG] Clase Gutenberg existe: ' . (class_exists('AICG_Gutenberg_Converter') ? 'SI' : 'NO'));
            error_log('[AICG] Comparación formato === gutenberg: ' . ($content_format === 'gutenberg' ? 'TRUE' : 'FALSE'));

            if ($content_format === 'gutenberg' && class_exists('AICG_Gutenberg_Converter')) {
                error_log('[AICG] ENTRANDO al bloque de conversión Gutenberg');
                error_log('[AICG] HTML antes de convertir (primeros 500 chars): ' . substr($result['content'], 0, 500));

                // Convertir HTML a bloques Gutenberg (la imagen ya está incluida en el HTML)
                $result['content'] = AICG_Gutenberg_Converter::convert($result['content']);

                error_log('[AICG] Contenido después de convertir (primeros 500 chars): ' . substr($result['content'], 0, 500));
                error_log('[AICG] Contenido convertido a formato Gutenberg');
            } else {
                error_log('[AICG] NO se entró al bloque de conversión Gutenberg. Razón: formato="' . $content_format . '", clase_existe=' . (class_exists('AICG_Gutenberg_Converter') ? 'SI' : 'NO'));
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

        // Obtener fuentes configuradas o usar por defecto
        $sources = get_option('aicg_news_sources', $this->default_sources);
        if (empty($sources)) {
            $sources = $this->default_sources;
        }

        foreach ($sources as $source) {
            // Solo procesar fuentes activas
            if (!isset($source['activo']) || !$source['activo']) {
                continue;
            }

            $news = $this->fetch_rss_feed($source['url']);
            $source_name = !empty($source['nombre']) ? $source['nombre'] : 'RSS Feed';

            foreach (array_slice($news, 0, 3) as $item) {
                $headlines[] = array(
                    'title' => $item['title'],
                    'source' => isset($item['source']) && !empty($item['source']) ? $item['source'] : $source_name
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
        // Usar plantilla de búsqueda configurable
        $default_template = 'https://news.google.com/rss/search?q={topic}&hl=es-419&gl=MX&ceid=MX:es-419';
        $template = get_option('aicg_news_search_template', $default_template);

        if (empty($template)) {
            $template = $default_template;
        }

        // Mejorar la búsqueda con términos más específicos según el tema
        $enhanced_topic = $this->enhance_topic_query($topic);
        error_log('[AICG] Tema "' . $topic . '" mejorado a: "' . $enhanced_topic . '"');

        $url = str_replace('{topic}', urlencode($enhanced_topic), $template);
        $news = $this->fetch_rss_feed($url);
        $count_before_relevance = count($news);

        // Filtrar noticias que no sean relevantes al tema
        $news = $this->filter_relevant_news($news, $topic);
        error_log('[AICG] Noticias después de filtro de relevancia para "' . $topic . '": ' . count($news) . ' (de ' . $count_before_relevance . ')');

        return $news;
    }

    /**
     * Mejorar la consulta del tema con términos más específicos
     *
     * @param string $topic
     * @return string
     */
    private function enhance_topic_query($topic) {
        // Mapeo de temas a términos de búsqueda más específicos
        $topic_mappings = array(
            'internacional' => 'noticias internacionales mundo -México -local',
            'economía' => 'economía finanzas mercados PIB inflación',
            'economia' => 'economía finanzas mercados PIB inflación',
            'ciencia y tecnología' => 'ciencia tecnología innovación investigación científica',
            'ciencia' => 'descubrimiento científico investigación ciencia',
            'tecnología' => 'tecnología inteligencia artificial software hardware',
            'tecnologia' => 'tecnología inteligencia artificial software hardware',
            'criptomonedas' => 'bitcoin ethereum criptomonedas blockchain crypto',
            'negocios' => 'negocios empresas corporativo fusiones adquisiciones',
            'conflictos y guerra' => 'guerra conflicto bélico militar ataque defensa',
            'deportes' => 'fútbol deportes liga campeón torneo',
            'entretenimiento' => 'cine películas series televisión celebridades',
            'salud' => 'salud medicina médico hospital enfermedad tratamiento',
            'medio ambiente' => 'clima cambio climático medio ambiente ecología',
            'política' => 'política gobierno elecciones congreso senado',
            'méxico' => 'México gobierno mexicano CDMX nacional',
        );

        $topic_lower = strtolower(trim($topic));

        // Buscar coincidencia exacta o parcial
        if (isset($topic_mappings[$topic_lower])) {
            return $topic_mappings[$topic_lower];
        }

        // Buscar coincidencia parcial
        foreach ($topic_mappings as $key => $value) {
            if (strpos($topic_lower, $key) !== false || strpos($key, $topic_lower) !== false) {
                return $value;
            }
        }

        // Si no hay mapeo, usar el tema original
        return $topic;
    }

    /**
     * Filtrar noticias que sean relevantes al tema
     *
     * @param array $news
     * @param string $topic
     * @return array
     */
    private function filter_relevant_news($news, $topic) {
        $topic_lower = strtolower($topic);

        // Determinar si es un tema de deportes
        $is_sports_topic = $this->is_sports_topic($topic_lower);

        // Palabras clave por tema para validar relevancia
        $relevance_keywords = array(
            'internacional' => array('mundo', 'internacional', 'global', 'países', 'naciones', 'extranjero', 'europa', 'asia', 'áfrica', 'estados unidos', 'rusia', 'china'),
            'economía' => array('economía', 'económico', 'finanzas', 'mercado', 'bolsa', 'inflación', 'pib', 'banco', 'inversión', 'dólar', 'peso'),
            'economia' => array('economía', 'económico', 'finanzas', 'mercado', 'bolsa', 'inflación', 'pib', 'banco', 'inversión', 'dólar', 'peso'),
            'ciencia y tecnología' => array('ciencia', 'tecnología', 'científico', 'investigación', 'descubrimiento', 'nasa', 'ia', 'inteligencia artificial'),
            'tecnología' => array('tecnología', 'tech', 'software', 'apple', 'google', 'microsoft', 'ia', 'inteligencia artificial', 'app'),
            'tecnologia' => array('tecnología', 'tech', 'software', 'apple', 'google', 'microsoft', 'ia', 'inteligencia artificial', 'app'),
            'criptomonedas' => array('bitcoin', 'crypto', 'criptomoneda', 'ethereum', 'blockchain', 'btc', 'token', 'nft'),
            'negocios' => array('empresa', 'negocio', 'corporativo', 'ceo', 'fusión', 'adquisición', 'startup', 'compañía'),
            'conflictos y guerra' => array('guerra', 'conflicto', 'militar', 'ejército', 'ataque', 'bombardeo', 'tropas', 'ucrania', 'gaza', 'israel'),
            'deportes' => array('fútbol', 'futbol', 'deporte', 'liga', 'gol', 'campeón', 'campeon', 'olimpico', 'olímpico', 'nba', 'nfl', 'mundial', 'jugador', 'equipo', 'partido', 'torneo'),
            'fútbol' => array('fútbol', 'futbol', 'liga', 'gol', 'partido', 'equipo', 'campeón', 'champions', 'mundial'),
            'futbol' => array('fútbol', 'futbol', 'liga', 'gol', 'partido', 'equipo', 'campeón', 'champions', 'mundial'),
            'salud' => array('salud', 'médico', 'hospital', 'enfermedad', 'tratamiento', 'vacuna', 'covid', 'oms'),
            'méxico' => array('méxico', 'mexicano', 'cdmx', 'amlo', 'sheinbaum', 'gobierno federal'),
        );

        // Primero: Excluir noticias de deportes si NO es un tema de deportes
        if (!$is_sports_topic) {
            $news = $this->exclude_sports_news($news);
        }

        // Si no hay keywords específicas, devolver las noticias filtradas
        if (!isset($relevance_keywords[$topic_lower])) {
            return $news;
        }

        $keywords = $relevance_keywords[$topic_lower];

        return array_filter($news, function($item) use ($keywords) {
            $text = strtolower($item['title'] . ' ' . $item['description']);
            foreach ($keywords as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    return true;
                }
            }
            return false;
        });
    }

    /**
     * Verificar si el tema es de deportes
     *
     * @param string $topic_lower Tema en minúsculas
     * @return bool
     */
    private function is_sports_topic($topic_lower) {
        $sports_topics = array(
            'deportes', 'deporte', 'fútbol', 'futbol', 'football',
            'básquetbol', 'basquetbol', 'basketball', 'nba',
            'béisbol', 'beisbol', 'baseball', 'mlb',
            'tenis', 'tennis', 'golf', 'boxeo', 'box',
            'fórmula 1', 'formula 1', 'f1', 'automovilismo',
            'olimpiadas', 'juegos olímpicos', 'juegos olimpicos',
            'nfl', 'americano', 'fútbol americano',
            'hockey', 'nhl', 'mls', 'liga mx'
        );

        foreach ($sports_topics as $sport) {
            if (strpos($topic_lower, $sport) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Excluir noticias de deportes
     *
     * @param array $news
     * @return array
     */
    private function exclude_sports_news($news) {
        // Palabras clave que indican contenido deportivo
        $sports_keywords = array(
            // Deportes generales
            'fútbol', 'futbol', 'soccer', 'gol', 'goles',
            'partido', 'partidos', 'jugador', 'jugadores', 'jugadora',
            'equipo deportivo', 'entrenador', 'técnico',
            'campeón', 'campeon', 'campeona', 'campeonato',
            'torneo', 'liga', 'copa', 'final', 'semifinal',
            'deporte', 'deportivo', 'deportiva', 'atleta',
            // Ligas y competiciones
            'champions league', 'champions', 'uefa', 'fifa',
            'liga mx', 'liga española', 'la liga', 'premier league',
            'bundesliga', 'serie a', 'ligue 1',
            'nba', 'nfl', 'mlb', 'nhl', 'mls',
            'mundial de fútbol', 'mundial qatar',
            'olimpiadas', 'olímpico', 'olimpico', 'juegos olímpicos',
            // Equipos conocidos
            'real madrid', 'barcelona', 'barça', 'atlético',
            'america', 'chivas', 'cruz azul', 'pumas', 'tigres',
            'manchester', 'liverpool', 'chelsea', 'arsenal',
            'lakers', 'celtics', 'warriors', 'bulls',
            'yankees', 'dodgers', 'red sox',
            // Deportes específicos
            'basquetbol', 'básquetbol', 'basketball', 'baloncesto',
            'béisbol', 'beisbol', 'baseball',
            'tenis', 'tennis', 'wimbledon', 'roland garros', 'us open',
            'golf', 'pga', 'masters',
            'boxeo', 'box', 'pelea', 'round', 'nocaut', 'knockout',
            'fórmula 1', 'formula 1', 'f1', 'gran premio', 'automovilismo',
            'hockey', 'nhl',
            'natación', 'natacion', 'atletismo',
            'ciclismo', 'tour de france',
            // Términos deportivos
            'fichaje', 'fichajes', 'transferencia', 'traspaso',
            'lesión deportiva', 'baja médica',
            'marcador', 'anotación', 'cancha', 'estadio',
            'arbitro', 'árbitro', 'var',
            'afición', 'aficionados', 'hinchada', 'porra',
            // Deportistas (términos genéricos)
            'delantero', 'portero', 'defensa', 'mediocampista',
            'pitcher', 'bateador', 'quarterback',
            // Específicos que mencionaste
            'testicular', 'cáncer testicular', // para el caso del deportista
            'thunder', 'okc thunder', 'nikola topic', // caso específico
        );

        $filtered_count = 0;

        $filtered_news = array_filter($news, function($item) use ($sports_keywords, &$filtered_count) {
            $text = strtolower($item['title'] . ' ' . $item['description']);

            foreach ($sports_keywords as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    $filtered_count++;
                    return false; // Excluir esta noticia
                }
            }
            return true; // Mantener esta noticia
        });

        if ($filtered_count > 0) {
            error_log('[AICG] Excluidas ' . $filtered_count . ' noticias de deportes');
        }

        return $filtered_news;
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

        // Usar prompt configurable
        $default_prompt = 'Create a professional news header image that represents these headlines from today: {headlines}. Style: Modern news media, clean design, abstract representation of news themes. Do NOT include any text or words in the image. Use a color palette suitable for a news website.';
        $prompt_template = get_option('aicg_news_image_prompt', $default_prompt);
        $prompt = str_replace('{headlines}', implode('; ', $headlines_text), $prompt_template);

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

        // Determinar el autor
        $post_author = get_current_user_id();
        if (!empty($args['post_author']) && $args['post_author'] > 0) {
            $post_author = intval($args['post_author']);
        } else {
            // Verificar si hay un autor por defecto configurado
            $default_author = get_option('aicg_default_author', 0);
            if ($default_author > 0) {
                $post_author = intval($default_author);
            }
        }

        $post_data = array(
            'post_title' => $result['title'],
            'post_content' => $result['content'],
            'post_status' => $args['post_status'],
            'post_type' => $post_type,
            'post_author' => $post_author
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
     * Convertir SVG a data URI base64 para uso en img tag
     *
     * @param string $svg SVG raw
     * @return string Data URI base64
     */
    private function svg_to_data_uri($svg) {
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Generar SVG con número usando paths (no texto)
     *
     * @param int    $num Número a mostrar
     * @param string $bg_color Color de fondo
     * @param string $style Estilo (circle o square)
     * @param int    $size Tamaño en píxeles
     * @return string img tag con SVG como data URI
     */
    private function generate_number_svg($num, $bg_color, $style, $size = 24) {
        $is_circle = ($style === 'circle');
        $rx = $is_circle ? '12' : '4';

        // Para números de un dígito
        if ($num < 10) {
            $path = $this->get_digit_path($num);
            $svg = sprintf(
                '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><rect width="24" height="24" fill="%s" rx="%s"/><g transform="translate(7,5)"><path d="%s" fill="white"/></g></svg>',
                esc_attr($bg_color),
                $rx,
                $path
            );
        } else {
            // Para números de dos dígitos
            $d1 = floor($num / 10);
            $d2 = $num % 10;
            $path1 = $this->get_digit_path($d1);
            $path2 = $this->get_digit_path($d2);

            $svg = sprintf(
                '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><rect width="24" height="24" fill="%s" rx="%s"/><g transform="translate(2,5) scale(0.9)"><path d="%s" fill="white"/></g><g transform="translate(12,5) scale(0.9)"><path d="%s" fill="white"/></g></svg>',
                esc_attr($bg_color),
                $rx,
                $path1,
                $path2
            );
        }

        return sprintf(
            '<img src="%s" width="%d" height="%d" alt="" aria-hidden="true" style="display:inline-block;vertical-align:middle;">',
            $this->svg_to_data_uri($svg),
            $size,
            $size
        );
    }

    /**
     * Generar SVG con número para estilo badge
     *
     * @param int    $num Número a mostrar
     * @param string $color Color del texto y borde
     * @param int    $size Tamaño en píxeles
     * @return string img tag con SVG como data URI
     */
    private function generate_badge_svg($num, $color, $size = 24) {
        $bg_color = $this->hex_to_rgba($color, 0.15);

        if ($num < 10) {
            $path = $this->get_digit_path($num);
            $svg = sprintf(
                '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><rect width="24" height="24" fill="%s" rx="12"/><g transform="translate(7,5)"><path d="%s" fill="%s"/></g></svg>',
                $bg_color,
                $path,
                esc_attr($color)
            );
            $width = $size;
        } else {
            $d1 = floor($num / 10);
            $d2 = $num % 10;
            $path1 = $this->get_digit_path($d1);
            $path2 = $this->get_digit_path($d2);

            $svg = sprintf(
                '<svg width="32" height="24" viewBox="0 0 32 24" xmlns="http://www.w3.org/2000/svg"><rect width="32" height="24" fill="%s" rx="12"/><g transform="translate(6,5) scale(0.9)"><path d="%s" fill="%s"/></g><g transform="translate(16,5) scale(0.9)"><path d="%s" fill="%s"/></g></svg>',
                $bg_color,
                $path1,
                esc_attr($color),
                $path2,
                esc_attr($color)
            );
            $width = intval($size * 1.33); // Proporción para dos dígitos
        }

        return sprintf(
            '<img src="%s" width="%d" height="%d" alt="" aria-hidden="true" style="display:inline-block;vertical-align:middle;">',
            $this->svg_to_data_uri($svg),
            $width,
            $size
        );
    }

    /**
     * Generar SVG con número para estilo inline (superíndice)
     *
     * @param int    $num Número a mostrar
     * @param string $color Color del texto
     * @param int    $size Tamaño en píxeles (altura)
     * @return string img tag con SVG como data URI
     */
    private function generate_inline_svg($num, $color, $size = 14) {
        $height = $size;
        if ($num < 10) {
            $path = $this->get_digit_path($num);
            $svg = sprintf(
                '<svg width="10" height="14" viewBox="0 0 10 14" xmlns="http://www.w3.org/2000/svg"><path d="%s" fill="%s"/></svg>',
                $path,
                esc_attr($color)
            );
            $width = intval($size * 0.71); // Proporción 10/14
        } else {
            $d1 = floor($num / 10);
            $d2 = $num % 10;
            $path1 = $this->get_digit_path($d1);
            $path2 = $this->get_digit_path($d2);

            $svg = sprintf(
                '<svg width="18" height="14" viewBox="0 0 18 14" xmlns="http://www.w3.org/2000/svg"><g transform="scale(0.85)"><path d="%s" fill="%s"/></g><g transform="translate(9,0) scale(0.85)"><path d="%s" fill="%s"/></g></svg>',
                $path1,
                esc_attr($color),
                $path2,
                esc_attr($color)
            );
            $width = intval($size * 1.29); // Proporción 18/14
        }

        return sprintf(
            '<img src="%s" width="%d" height="%d" alt="" aria-hidden="true" style="display:inline-block;vertical-align:super;">',
            $this->svg_to_data_uri($svg),
            $width,
            $height
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
