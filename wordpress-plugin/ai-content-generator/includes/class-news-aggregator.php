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
            'topics_details' => array(), // Detalles por sección
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

            // Recopilar noticias procesadas para extracción de imágenes OG
            $all_processed_news = array();

            foreach ($args['topics'] as $topic) {
                error_log('[AICG] Procesando tema: ' . $topic);

                // Inicializar detalles del tema
                $topic_detail = array(
                    'name' => $topic,
                    'news_count' => 0,
                    'images_count' => 0,
                    'images_source' => '', // 'og' para imágenes de fuentes, 'generated' para generadas, '' para ninguna
                    'status' => 'pending',
                    'message' => ''
                );

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
                    $topic_detail['status'] = 'no_news';
                    $topic_detail['message'] = __('Sin noticias disponibles para este tema', 'ai-content-generator');
                    $result['topics_details'][] = $topic_detail;
                    continue;
                }

                // Generar resumen del tema
                $topic_summary = $this->generate_topic_summary($topic, $news);

                if (is_wp_error($topic_summary)) {
                    error_log('[AICG] Error generando resumen para tema "' . $topic . '": ' . $topic_summary->get_error_message());
                    $topic_detail['status'] = 'error';
                    $topic_detail['message'] = $topic_summary->get_error_message();
                    $result['topics_details'][] = $topic_detail;
                    continue;
                }

                if (empty($topic_summary['content'])) {
                    error_log('[AICG] Resumen vacío para tema "' . $topic . '"');
                    $topic_detail['status'] = 'empty';
                    $topic_detail['message'] = __('No se pudo generar resumen', 'ai-content-generator');
                    $result['topics_details'][] = $topic_detail;
                    continue;
                }

                $result['tokens_used'] += isset($topic_summary['usage']['total_tokens']) ? $topic_summary['usage']['total_tokens'] : 0;
                $result['cost'] += isset($topic_summary['cost']) ? $topic_summary['cost'] : 0;
                $result['news_count'] += count($news);
                $result['topics_processed'][] = $topic;

                // Actualizar detalles del tema
                $topic_detail['news_count'] = count($news);
                $topic_detail['status'] = 'success';

                // Marcar URLs como usadas (en DB y en sesión actual)
                $news_urls = array_column($news, 'link');
                $this->mark_urls_as_used($news_urls);
                $session_used_urls = array_merge($session_used_urls, $news_urls);

                // Agregar noticias a la lista para extracción de imágenes OG
                $all_processed_news = array_merge($all_processed_news, $news);

                // Construir HTML de la sección
                $content_parts[] = '<div class="aicg-topic-section">';
                $content_parts[] = '<h2>' . esc_html($topic) . '</h2>';

                // Contenido del resumen primero
                $content_parts[] = $topic_summary['content'];

                // Galería de imágenes del tema DESPUÉS del texto (si está habilitado)
                $carousel_enabled = get_option('aicg_news_carousel_enabled', true);
                if ($carousel_enabled) {
                    $carousel_data = $this->extract_topic_images($news, $topic);
                    if (!empty($carousel_data['html'])) {
                        $content_parts[] = $carousel_data['html'];
                        $topic_detail['images_count'] = count($carousel_data['images']);
                        $topic_detail['images_source'] = 'og';
                    }
                } elseif (!empty($topics_images[$topic])) {
                    // Fallback: Imagen estática del tema (configuración antigua)
                    $content_parts[] = '<img src="' . esc_url($topics_images[$topic]) . '" alt="' . esc_attr($topic) . '" class="aicg-topic-image" />';
                    $topic_detail['images_count'] = 1;
                    $topic_detail['images_source'] = 'static';
                }

                // Guardar detalles del tema
                $result['topics_details'][] = $topic_detail;

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

            // Agregar CSS y JS del carrusel si hay carruseles en el contenido
            $carousel_enabled = get_option('aicg_news_carousel_enabled', true);
            if ($carousel_enabled) {
                array_unshift($content_parts, self::get_carousel_css());
                $content_parts[] = self::get_carousel_js();
            }

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
                    error_log('[AICG] Generando imagen híbrida con ' . count($headlines_for_image) . ' titulares');

                    // Try-catch específico para generación de imagen
                    try {
                        // Pasar también las noticias con URLs para el esquema híbrido
                        $image_result = $this->generate_featured_image($headlines_for_image, $all_processed_news);
                        error_log('[AICG] generate_featured_image retornó: ' . (is_wp_error($image_result) ? 'WP_Error' : 'array') .
                                  (isset($image_result['source']) ? ' (fuente: ' . $image_result['source'] . ')' : ''));

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
        $all_news = array();

        // 1. Primero buscar en fuentes RSS personalizadas que tengan feeds específicos del tema
        $custom_sources = get_option('aicg_news_sources', array());
        $topic_lower = strtolower(trim($topic));

        // Mapeo de temas a dominios/fuentes relevantes
        $topic_source_mapping = array(
            'criptomonedas' => array('cointelegraph', 'crypto', 'bitcoin', 'coindesk', 'decrypt'),
            'tecnología' => array('xataka', 'wired', 'techcrunch', 'verge', 'engadget', 'ars'),
            'tecnologia' => array('xataka', 'wired', 'techcrunch', 'verge', 'engadget', 'ars'),
            'ciencia y tecnología' => array('xataka', 'wired', 'science', 'nature', 'scientific'),
            'ciencia' => array('science', 'nature', 'scientific', 'nasa'),
            'seguridad' => array('wired', 'security', 'krebs', 'bleeping'),
        );

        // Buscar en fuentes personalizadas relevantes para este tema
        $relevant_keywords = isset($topic_source_mapping[$topic_lower]) ? $topic_source_mapping[$topic_lower] : array();

        foreach ($custom_sources as $source) {
            if (!isset($source['activo']) || !$source['activo']) {
                continue;
            }

            $source_url_lower = strtolower($source['url']);
            $source_name_lower = strtolower($source['nombre'] ?? '');

            // Verificar si esta fuente es relevante para el tema
            $is_relevant_source = false;
            foreach ($relevant_keywords as $keyword) {
                if (strpos($source_url_lower, $keyword) !== false || strpos($source_name_lower, $keyword) !== false) {
                    $is_relevant_source = true;
                    break;
                }
            }

            if ($is_relevant_source) {
                error_log('[AICG] Consultando fuente personalizada para "' . $topic . '": ' . $source['url']);
                $source_news = $this->fetch_rss_feed($source['url']);

                // Agregar nombre de la fuente a cada noticia
                $source_name = !empty($source['nombre']) ? $source['nombre'] : 'RSS Feed';
                foreach ($source_news as &$item) {
                    if (empty($item['source'])) {
                        $item['source'] = $source_name;
                    }
                }

                $all_news = array_merge($all_news, $source_news);
                error_log('[AICG] Noticias obtenidas de ' . $source['nombre'] . ': ' . count($source_news));
            }
        }

        // 2. Luego buscar en Google News usando la plantilla
        $default_template = 'https://news.google.com/rss/search?q={topic}&hl=es-419&gl=MX&ceid=MX:es-419';
        $template = get_option('aicg_news_search_template', $default_template);

        if (empty($template)) {
            $template = $default_template;
        }

        // Mejorar la búsqueda con términos más específicos según el tema
        $enhanced_topic = $this->enhance_topic_query($topic);
        error_log('[AICG] Tema "' . $topic . '" mejorado a: "' . $enhanced_topic . '"');

        $url = str_replace('{topic}', urlencode($enhanced_topic), $template);
        $google_news = $this->fetch_rss_feed($url);

        // Combinar noticias de fuentes personalizadas con Google News
        $all_news = array_merge($all_news, $google_news);
        $count_before_relevance = count($all_news);

        error_log('[AICG] Total noticias combinadas para "' . $topic . '": ' . $count_before_relevance . ' (personalizadas: ' . (count($all_news) - count($google_news)) . ', Google: ' . count($google_news) . ')');

        // Filtrar noticias que no sean relevantes al tema
        $news = $this->filter_relevant_news($all_news, $topic);
        error_log('[AICG] Noticias después de filtro de relevancia para "' . $topic . '": ' . count($news) . ' (de ' . $count_before_relevance . ')');

        // Eliminar duplicados por título similar
        $news = $this->remove_duplicate_news($news);

        return $news;
    }

    /**
     * Eliminar noticias duplicadas por título similar
     *
     * @param array $news
     * @return array
     */
    private function remove_duplicate_news($news) {
        $unique_news = array();
        $seen_titles = array();

        foreach ($news as $item) {
            // Normalizar título para comparación
            $normalized_title = strtolower(preg_replace('/[^a-z0-9\s]/i', '', $item['title']));
            $normalized_title = preg_replace('/\s+/', ' ', trim($normalized_title));

            // Verificar si ya existe un título similar (primeras 50 caracteres)
            $title_key = substr($normalized_title, 0, 50);

            if (!isset($seen_titles[$title_key])) {
                $seen_titles[$title_key] = true;
                $unique_news[] = $item;
            }
        }

        return $unique_news;
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
            // Keywords ampliadas para criptomonedas
            'criptomonedas' => array(
                'bitcoin', 'btc', 'ethereum', 'eth', 'crypto', 'cripto',
                'criptomoneda', 'criptomonedas', 'criptodivisa', 'criptodivisas',
                'blockchain', 'cadena de bloques', 'token', 'tokens', 'nft',
                'binance', 'coinbase', 'kraken', 'bitso', 'exchange',
                'minería', 'minar', 'minero', 'halving', 'satoshi',
                'altcoin', 'stablecoin', 'usdt', 'tether', 'usdc',
                'solana', 'cardano', 'dogecoin', 'doge', 'ripple', 'xrp',
                'defi', 'web3', 'metaverso', 'wallet', 'billetera digital',
                'moneda digital', 'moneda virtual', 'activo digital',
                'cointelegraph', 'coindesk', 'mercado cripto'
            ),
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

        $result = $this->provider->generate_text($prompt, array(
            'max_tokens' => 1000,
            'temperature' => 0.5,
            'system_message' => $system_prompt
        ));

        // Post-procesar para convertir Markdown a HTML si la IA lo ignoró
        if (!is_wp_error($result) && !empty($result['content'])) {
            $result['content'] = $this->convert_markdown_to_html($result['content']);
        }

        return $result;
    }

    /**
     * Convertir Markdown básico a HTML
     * (para cuando la IA ignora las instrucciones de usar HTML)
     *
     * @param string $text
     * @return string
     */
    private function convert_markdown_to_html($text) {
        // Convertir **texto** a <strong>texto</strong>
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);

        // Convertir *texto* a <em>texto</em> (pero no si ya es parte de **)
        $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text);

        // Convertir __texto__ a <strong>texto</strong>
        $text = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $text);

        // Convertir _texto_ a <em>texto</em>
        $text = preg_replace('/(?<!_)_([^_]+)_(?!_)/', '<em>$1</em>', $text);

        // Convertir [texto](url) a <a href="url">texto</a>
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $text);

        // Convertir líneas que empiezan con # a headings (si no están ya en tags)
        $text = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $text);
        $text = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $text);
        $text = preg_replace('/^# (.+)$/m', '<h2>$1</h2>', $text);

        // Envolver párrafos sueltos en <p> si no están ya
        // Primero detectar si hay tags <p> existentes
        if (strpos($text, '<p>') === false) {
            // Dividir por doble salto de línea y envolver cada párrafo
            $paragraphs = preg_split('/\n\s*\n/', $text);
            $paragraphs = array_map(function($p) {
                $p = trim($p);
                if (empty($p)) return '';
                // No envolver si ya es un tag de bloque
                if (preg_match('/^<(h[1-6]|ul|ol|li|div|blockquote|table|figure)/', $p)) {
                    return $p;
                }
                return '<p>' . $p . '</p>';
            }, $paragraphs);
            $text = implode("\n", array_filter($paragraphs));
        }

        return $text;
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

        $result = $this->provider->generate_text($prompt, array(
            'max_tokens' => 1500,
            'temperature' => 0.5,
            'system_message' => $system_prompt
        ));

        // Post-procesar para convertir Markdown a HTML si la IA lo ignoró
        if (!is_wp_error($result) && !empty($result['content'])) {
            $result['content'] = $this->convert_markdown_to_html($result['content']);
        }

        return $result;
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

        // Verificar si la tabla existe, si no, crearla
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            $this->create_used_urls_table();
        }

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
     * Crear tabla de URLs usadas si no existe
     */
    private function create_used_urls_table() {
        global $wpdb;

        $table = $wpdb->prefix . 'aicg_used_urls';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            url varchar(500) NOT NULL,
            used_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY url (url(255))
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        error_log('[AICG] Tabla ' . $table . ' creada/verificada');
    }

    /**
     * Generar imagen destacada usando esquema híbrido:
     * 1. Intentar extraer imagen OG/Twitter de las fuentes (si está habilitado)
     * 2. Si falla, buscar imagen de mapa de la región mencionada (si está habilitado)
     * 3. Si falla, generar con IA (si está habilitado)
     *
     * @param array $headlines
     * @param array $news_items Noticias con URLs de fuentes (opcional)
     * @return array|WP_Error
     */
    private function generate_featured_image($headlines, $news_items = array()) {
        error_log('[AICG] Iniciando generación de imagen del resumen');

        // Para la imagen principal del resumen, siempre generamos con IA
        // Las imágenes OG se usan solo para las galerías de cada tema
        error_log('[AICG] Generando imagen con IA para el resumen principal');
        return $this->generate_ai_image($headlines);
    }

    /**
     * Extraer imagen OG/Twitter de las URLs de las noticias
     *
     * @param array $news_items Array de noticias con 'link'
     * @return array|WP_Error
     */
    private function extract_og_image_from_sources($news_items) {
        foreach (array_slice($news_items, 0, 5) as $item) {
            if (empty($item['link'])) {
                continue;
            }

            $image_url = $this->get_og_image_from_url($item['link']);
            if ($image_url) {
                // Descargar y guardar la imagen
                $attachment_id = $this->save_image_to_media_library($image_url);
                if (!is_wp_error($attachment_id)) {
                    return array('attachment_id' => $attachment_id);
                }
            }
        }

        return new WP_Error('no_og_image', __('No se encontró imagen OG en las fuentes', 'ai-content-generator'));
    }

    /**
     * Obtener imagen OG/Twitter de una URL
     *
     * @param string $url URL del artículo
     * @return string|false URL de la imagen o false
     */
    private function get_og_image_from_url($url) {
        // Resolver URL real (especialmente para Google News)
        $real_url = $this->resolve_google_news_url($url);
        if (!$real_url) {
            error_log('[AICG] No se pudo resolver URL: ' . $url);
            return false;
        }

        error_log('[AICG] URL resuelta: ' . $real_url);

        // Obtener HTML de la página real
        $response = wp_remote_get($real_url, array(
            'timeout' => 15,
            'redirection' => 5,
            'headers' => array(
                'User-Agent' => $this->user_agent,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'es-ES,es;q=0.9,en;q=0.8'
            )
        ));

        if (is_wp_error($response)) {
            error_log('[AICG] Error obteniendo página: ' . $response->get_error_message());
            return false;
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return false;
        }

        // Buscar og:image (varios formatos posibles)
        $patterns = array(
            '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/',
            '/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image["\']/',
            '/<meta[^>]+property=["\']og:image:url["\'][^>]+content=["\']([^"\']+)["\']/',
            '/<meta[^>]+itemprop=["\']image["\'][^>]+content=["\']([^"\']+)["\']/',
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $image_url = $this->validate_image_url($matches[1]);
                if ($image_url) {
                    error_log('[AICG] Imagen OG encontrada: ' . $image_url);
                    return $image_url;
                }
            }
        }

        return false;
    }

    /**
     * Resolver URL de Google News a la URL real del artículo
     *
     * @param string $url URL de Google News
     * @return string|false URL real o false
     */
    private function resolve_google_news_url($url) {
        // Si no es URL de Google News, devolverla tal cual
        if (strpos($url, 'news.google.com') === false) {
            return $url;
        }

        error_log('[AICG] Resolviendo URL de Google News: ' . substr($url, 0, 100) . '...');

        // MÉTODO 1: Usar la API batchexecute de Google (método más confiable para URLs modernas)
        $batch_url = $this->resolve_with_batchexecute($url);
        if ($batch_url) {
            error_log('[AICG] URL desde batchexecute: ' . $batch_url);
            return $batch_url;
        }

        // MÉTODO 2: Intentar decodificar desde el path de la URL (URLs antiguas)
        $decoded_url = $this->decode_google_news_url($url);
        if ($decoded_url) {
            error_log('[AICG] URL decodificada de base64: ' . $decoded_url);
            return $decoded_url;
        }

        // MÉTODO 3: Usar cURL para seguir redirects
        if (function_exists('curl_init')) {
            $curl_url = $this->resolve_with_curl($url);
            if ($curl_url) {
                error_log('[AICG] URL desde cURL redirect: ' . $curl_url);
                return $curl_url;
            }
        }

        error_log('[AICG] No se pudo resolver URL de Google News');
        return false;
    }

    /**
     * Resolver URL usando la API batchexecute de Google News
     *
     * Este método hace una petición POST a la API interna de Google News
     * para obtener la URL real del artículo.
     *
     * @param string $url URL de Google News
     * @return string|false
     */
    private function resolve_with_batchexecute($url) {
        // Extraer el ID del artículo del path
        if (!preg_match('/\/rss\/articles\/([A-Za-z0-9_-]+)/', $url, $matches)) {
            if (!preg_match('/\/articles\/([A-Za-z0-9_-]+)/', $url, $matches)) {
                return false;
            }
        }

        $article_id = $matches[1];
        $timestamp = time() * 1000; // milliseconds

        // Construir el payload para batchexecute
        // El formato es específico de la API interna de Google
        $req_data = json_encode([
            [
                [
                    "Fbv4je",
                    "[\"garturlreq\",[[\"X\",\"X\",[\"X\",\"X\"],null,null,1,1,\"US:en\",null,1,null,null,null,null,null,0,1],\"X\",\"X\",1,[1,1,1],1,1,null,0,0,null,0],\"{$article_id}\",{$timestamp},\"X\"]"
                ]
            ]
        ]);

        $body = 'f.req=' . urlencode($req_data);

        // Hacer la petición POST
        $response = wp_remote_post('https://news.google.com/_/DotsSplashUi/data/batchexecute', array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => '*/*',
                'Accept-Language' => 'es-ES,es;q=0.9,en;q=0.8',
                'Referer' => 'https://news.google.com/',
                'Origin' => 'https://news.google.com',
            ),
            'body' => $body,
        ));

        if (is_wp_error($response)) {
            error_log('[AICG] Error en batchexecute: ' . $response->get_error_message());
            return false;
        }

        $response_body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);

        if ($http_code !== 200 || empty($response_body)) {
            error_log('[AICG] batchexecute respuesta: HTTP ' . $http_code);
            return false;
        }

        // La respuesta tiene un formato especial: comienza con )]}' y luego tiene JSON
        // Buscar URLs en la respuesta
        if (preg_match_all('#https?://[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,}[^"\'\s\\\]*#', $response_body, $url_matches)) {
            foreach ($url_matches[0] as $found_url) {
                // Limpiar caracteres de escape
                $found_url = stripslashes($found_url);
                $found_url = preg_replace('/\\\\u([0-9A-Fa-f]{4})/', '', $found_url); // Remover unicode escapes
                $found_url = rtrim($found_url, '\\');

                if ($this->is_valid_article_url($found_url)) {
                    return $found_url;
                }
            }
        }

        return false;
    }

    /**
     * Resolver URL usando cURL (sigue redirects de forma más agresiva)
     *
     * @param string $url
     * @return string|false
     */
    private function resolve_with_curl($url) {
        $ch = curl_init();

        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => array(
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: es-ES,es;q=0.9,en;q=0.8',
            ),
            CURLOPT_HEADER => false,
        ));

        $body = curl_exec($ch);
        $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        // Si la URL final es diferente y válida
        if ($final_url && $final_url !== $url && $this->is_valid_article_url($final_url)) {
            return $final_url;
        }

        // Buscar en el HTML si hay body
        if (!empty($body)) {
            // Meta refresh
            if (preg_match('/<meta[^>]+http-equiv=["\']refresh["\'][^>]+content=["\'][^"\']*url=([^"\'>\s]+)/i', $body, $matches)) {
                $refresh_url = html_entity_decode($matches[1]);
                if ($this->is_valid_article_url($refresh_url)) {
                    return $refresh_url;
                }
            }

            // Canonical
            if (preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)/i', $body, $matches)) {
                $canonical_url = html_entity_decode($matches[1]);
                if ($this->is_valid_article_url($canonical_url)) {
                    return $canonical_url;
                }
            }
        }

        return false;
    }

    /**
     * Decodificar URL de Google News desde el parámetro base64
     *
     * Las URLs de Google News RSS tienen el formato:
     * https://news.google.com/rss/articles/CBMi...
     * donde CBMi... es una cadena protobuf que puede contener datos anidados
     *
     * @param string $google_url
     * @return string|false
     */
    private function decode_google_news_url($google_url) {
        // Extraer el path del artículo
        $parsed = parse_url($google_url);
        if (!isset($parsed['path'])) {
            return false;
        }

        // El path es /rss/articles/ENCODED_STRING
        if (!preg_match('/\/rss\/articles\/([A-Za-z0-9_-]+)/', $parsed['path'], $matches)) {
            return false;
        }

        $encoded = $matches[1];

        // Intentar decodificar recursivamente (Google usa codificación anidada)
        $decoded = $this->decode_nested_base64($encoded);
        if (!$decoded) {
            return false;
        }

        // Buscar URLs en los datos decodificados
        // Usar expresión regular más específica para URLs de artículos
        if (preg_match_all('#(https?://[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,}(?:/[^\x00-\x1F\x7F\s"\'<>]*)?)?#', $decoded, $url_matches)) {
            foreach ($url_matches[1] as $found_url) {
                if (empty($found_url)) continue;

                // Limpiar caracteres basura al final
                $found_url = preg_replace('/[\x00-\x1F\x7F-\xFF]+.*$/', '', $found_url);
                $found_url = rtrim($found_url, '?&#');

                if ($this->is_valid_article_url($found_url)) {
                    return $found_url;
                }
            }
        }

        return false;
    }

    /**
     * Decodificar datos base64 anidados (protobuf de Google News)
     *
     * @param string $encoded Cadena codificada
     * @param int $depth Profundidad máxima de recursión
     * @return string|false Datos decodificados o false
     */
    private function decode_nested_base64($encoded, $depth = 0) {
        if ($depth > 3) {
            return false; // Evitar recursión infinita
        }

        // Convertir base64url a base64 estándar
        $base64 = str_replace(array('-', '_'), array('+', '/'), $encoded);

        // Agregar padding si es necesario
        $padding = strlen($base64) % 4;
        if ($padding) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            return false;
        }

        // Si encontramos una URL válida, devolverla
        if (preg_match('#https?://[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,}#', $decoded)) {
            return $decoded;
        }

        // Buscar otra cadena base64 dentro (formato protobuf)
        // Google anida los datos: el primer decode da algo como "AU_yqL..." que es otro base64
        if (preg_match('/([A-Za-z0-9+\/_-]{20,})/', $decoded, $inner_matches)) {
            $inner_result = $this->decode_nested_base64($inner_matches[1], $depth + 1);
            if ($inner_result) {
                return $inner_result;
            }
        }

        return $decoded;
    }

    /**
     * Verificar si una URL es válida para un artículo (no es de Google, gstatic, etc.)
     *
     * @param string $url
     * @return bool
     */
    private function is_valid_article_url($url) {
        if (empty($url) || strpos($url, 'http') !== 0) {
            return false;
        }

        // Lista de dominios a excluir
        $excluded_domains = array(
            'google.com',
            'gstatic.com',
            'googleapis.com',
            'googleusercontent.com',
            'googlesyndication.com',
            'doubleclick.net',
            'google-analytics.com',
            'facebook.com',
            'twitter.com',
            'youtube.com'
        );

        $parsed = parse_url($url);
        if (!isset($parsed['host'])) {
            return false;
        }

        $host = strtolower($parsed['host']);
        foreach ($excluded_domains as $excluded) {
            if (strpos($host, $excluded) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validar que la URL de imagen es válida
     *
     * @param string $url
     * @return string|false
     */
    private function validate_image_url($url) {
        $url = trim($url);

        // Verificar que es una URL válida
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Verificar que tiene extensión de imagen o es una URL de imagen conocida
        $image_extensions = array('.jpg', '.jpeg', '.png', '.gif', '.webp');
        $parsed = parse_url(strtolower($url));
        $path = isset($parsed['path']) ? $parsed['path'] : '';

        foreach ($image_extensions as $ext) {
            if (strpos($path, $ext) !== false) {
                return $url;
            }
        }

        // Algunas URLs de imágenes no tienen extensión (ej: CDNs)
        // Verificar dominios conocidos de imágenes
        $image_hosts = array('cdn', 'img', 'image', 'static', 'media', 's3.amazonaws', 'cloudinary');
        foreach ($image_hosts as $host) {
            if (strpos($parsed['host'], $host) !== false) {
                return $url;
            }
        }

        // Aceptar URLs que parezcan de imágenes aunque no tengan extensión
        if (strpos($url, '/image') !== false || strpos($url, '/img') !== false || strpos($url, '/foto') !== false) {
            return $url;
        }

        return $url; // Intentar de todos modos
    }

    /**
     * Extraer región/país de los titulares
     *
     * @param array $headlines
     * @return string|false
     */
    private function extract_region_from_headlines($headlines) {
        // Lista de países y regiones
        $regions = array(
            // Países de América
            'estados unidos' => 'United States',
            'eeuu' => 'United States',
            'usa' => 'United States',
            'méxico' => 'Mexico',
            'mexico' => 'Mexico',
            'canadá' => 'Canada',
            'canada' => 'Canada',
            'brasil' => 'Brazil',
            'argentina' => 'Argentina',
            'chile' => 'Chile',
            'colombia' => 'Colombia',
            'perú' => 'Peru',
            'peru' => 'Peru',
            'venezuela' => 'Venezuela',
            'cuba' => 'Cuba',
            'guatemala' => 'Guatemala',
            'ecuador' => 'Ecuador',
            'bolivia' => 'Bolivia',
            'paraguay' => 'Paraguay',
            'uruguay' => 'Uruguay',
            'panamá' => 'Panama',
            'panama' => 'Panama',
            'costa rica' => 'Costa Rica',
            'honduras' => 'Honduras',
            'el salvador' => 'El Salvador',
            'nicaragua' => 'Nicaragua',
            'puerto rico' => 'Puerto Rico',
            'república dominicana' => 'Dominican Republic',
            'haití' => 'Haiti',
            'haiti' => 'Haiti',

            // Europa
            'rusia' => 'Russia',
            'ucrania' => 'Ukraine',
            'alemania' => 'Germany',
            'francia' => 'France',
            'reino unido' => 'United Kingdom',
            'inglaterra' => 'England',
            'españa' => 'Spain',
            'italia' => 'Italy',
            'portugal' => 'Portugal',
            'polonia' => 'Poland',
            'holanda' => 'Netherlands',
            'países bajos' => 'Netherlands',
            'bélgica' => 'Belgium',
            'belgica' => 'Belgium',
            'suiza' => 'Switzerland',
            'austria' => 'Austria',
            'grecia' => 'Greece',
            'turquía' => 'Turkey',
            'turquia' => 'Turkey',
            'suecia' => 'Sweden',
            'noruega' => 'Norway',
            'dinamarca' => 'Denmark',
            'finlandia' => 'Finland',
            'irlanda' => 'Ireland',
            'escocia' => 'Scotland',
            'rumania' => 'Romania',
            'hungría' => 'Hungary',
            'hungria' => 'Hungary',
            'república checa' => 'Czech Republic',
            'serbia' => 'Serbia',
            'croacia' => 'Croatia',
            'bulgaria' => 'Bulgaria',
            'eslovaquia' => 'Slovakia',
            'eslovenia' => 'Slovenia',

            // Asia
            'china' => 'China',
            'japón' => 'Japan',
            'japon' => 'Japan',
            'corea del sur' => 'South Korea',
            'corea del norte' => 'North Korea',
            'india' => 'India',
            'pakistán' => 'Pakistan',
            'pakistan' => 'Pakistan',
            'afganistán' => 'Afghanistan',
            'afganistan' => 'Afghanistan',
            'irán' => 'Iran',
            'iran' => 'Iran',
            'irak' => 'Iraq',
            'iraq' => 'Iraq',
            'siria' => 'Syria',
            'israel' => 'Israel',
            'palestina' => 'Palestine',
            'gaza' => 'Gaza Strip',
            'líbano' => 'Lebanon',
            'libano' => 'Lebanon',
            'arabia saudita' => 'Saudi Arabia',
            'arabia saudí' => 'Saudi Arabia',
            'emiratos árabes' => 'United Arab Emirates',
            'dubai' => 'Dubai',
            'qatar' => 'Qatar',
            'tailandia' => 'Thailand',
            'vietnam' => 'Vietnam',
            'filipinas' => 'Philippines',
            'indonesia' => 'Indonesia',
            'malasia' => 'Malaysia',
            'singapur' => 'Singapore',
            'taiwán' => 'Taiwan',
            'taiwan' => 'Taiwan',
            'hong kong' => 'Hong Kong',
            'bangladesh' => 'Bangladesh',
            'nepal' => 'Nepal',
            'myanmar' => 'Myanmar',
            'birmania' => 'Myanmar',
            'camboya' => 'Cambodia',
            'laos' => 'Laos',
            'kazajistán' => 'Kazakhstan',
            'uzbekistán' => 'Uzbekistan',

            // África
            'egipto' => 'Egypt',
            'sudáfrica' => 'South Africa',
            'sudafrica' => 'South Africa',
            'nigeria' => 'Nigeria',
            'marruecos' => 'Morocco',
            'argelia' => 'Algeria',
            'túnez' => 'Tunisia',
            'tunez' => 'Tunisia',
            'libia' => 'Libya',
            'sudán' => 'Sudan',
            'sudan' => 'Sudan',
            'etiopía' => 'Ethiopia',
            'etiopia' => 'Ethiopia',
            'kenia' => 'Kenya',
            'kenya' => 'Kenya',
            'tanzania' => 'Tanzania',
            'uganda' => 'Uganda',
            'zimbabue' => 'Zimbabwe',
            'ghana' => 'Ghana',
            'senegal' => 'Senegal',
            'costa de marfil' => 'Ivory Coast',
            'camerún' => 'Cameroon',
            'camerun' => 'Cameroon',
            'congo' => 'Congo',
            'angola' => 'Angola',
            'mozambique' => 'Mozambique',
            'somalia' => 'Somalia',

            // Oceanía
            'australia' => 'Australia',
            'nueva zelanda' => 'New Zealand',
            'nueva zelandia' => 'New Zealand',

            // Regiones
            'medio oriente' => 'Middle East',
            'oriente medio' => 'Middle East',
            'unión europea' => 'European Union',
            'union europea' => 'European Union',
            'europa' => 'Europe',
            'asia' => 'Asia',
            'áfrica' => 'Africa',
            'africa' => 'Africa',
            'latinoamérica' => 'Latin America',
            'latinoamerica' => 'Latin America',
            'américa latina' => 'Latin America',
            'centroamérica' => 'Central America',
            'centroamerica' => 'Central America',
            'sudamérica' => 'South America',
            'sudamerica' => 'South America',
            'norteamérica' => 'North America',
            'norteamerica' => 'North America',
            'caribe' => 'Caribbean',
        );

        // Buscar en los titulares
        foreach ($headlines as $headline) {
            $title = strtolower($headline['title']);

            foreach ($regions as $spanish => $english) {
                if (strpos($title, $spanish) !== false) {
                    return $english;
                }
            }
        }

        return false;
    }

    /**
     * Extraer región prominente (que aparece en múltiples titulares)
     * Solo devuelve una región si aparece en al menos 2 titulares
     *
     * @param array $headlines
     * @return string|false
     */
    private function extract_prominent_region_from_headlines($headlines) {
        if (count($headlines) < 2) {
            return false;
        }

        // Obtener la lista de regiones
        $regions = $this->get_regions_list();

        // Contar menciones de cada región
        $region_counts = array();

        foreach ($headlines as $headline) {
            $title = strtolower($headline['title']);

            foreach ($regions as $spanish => $english) {
                if (strpos($title, $spanish) !== false) {
                    if (!isset($region_counts[$english])) {
                        $region_counts[$english] = 0;
                    }
                    $region_counts[$english]++;
                    break; // Solo contar una región por titular
                }
            }
        }

        // Buscar la región más mencionada con al menos 2 menciones
        if (!empty($region_counts)) {
            arsort($region_counts);
            $top_region = key($region_counts);
            $top_count = current($region_counts);

            if ($top_count >= 2) {
                error_log('[AICG] Región prominente: ' . $top_region . ' (' . $top_count . ' menciones)');
                return $top_region;
            }
        }

        return false;
    }

    /**
     * Obtener lista de regiones (para reutilizar)
     *
     * @return array
     */
    private function get_regions_list() {
        return array(
            // Países de América
            'estados unidos' => 'United States',
            'eeuu' => 'United States',
            'usa' => 'United States',
            'méxico' => 'Mexico',
            'mexico' => 'Mexico',
            'canadá' => 'Canada',
            'canada' => 'Canada',
            'brasil' => 'Brazil',
            'argentina' => 'Argentina',
            'chile' => 'Chile',
            'colombia' => 'Colombia',
            'perú' => 'Peru',
            'peru' => 'Peru',
            'venezuela' => 'Venezuela',
            'cuba' => 'Cuba',
            'guatemala' => 'Guatemala',
            'ecuador' => 'Ecuador',
            'bolivia' => 'Bolivia',
            'paraguay' => 'Paraguay',
            'uruguay' => 'Uruguay',
            'panamá' => 'Panama',
            'panama' => 'Panama',
            'costa rica' => 'Costa Rica',
            'honduras' => 'Honduras',
            'el salvador' => 'El Salvador',
            'nicaragua' => 'Nicaragua',
            // Europa
            'rusia' => 'Russia',
            'ucrania' => 'Ukraine',
            'alemania' => 'Germany',
            'francia' => 'France',
            'reino unido' => 'United Kingdom',
            'españa' => 'Spain',
            'italia' => 'Italy',
            // Asia
            'china' => 'China',
            'japón' => 'Japan',
            'japon' => 'Japan',
            'corea del sur' => 'South Korea',
            'corea del norte' => 'North Korea',
            'india' => 'India',
            'irán' => 'Iran',
            'iran' => 'Iran',
            'israel' => 'Israel',
            'palestina' => 'Palestine',
            'gaza' => 'Gaza Strip',
            // Regiones
            'medio oriente' => 'Middle East',
            'oriente medio' => 'Middle East',
            'europa' => 'Europe',
            'asia' => 'Asia'
        );
    }

    /**
     * Obtener imagen de mapa de una región
     *
     * @param string $region Nombre de la región en inglés
     * @return array|WP_Error
     */
    private function fetch_map_image($region) {
        // Usar Wikimedia Commons para mapas
        // Buscar mapa de ubicación o mapa político
        $search_terms = array(
            $region . ' location map',
            $region . ' in the world',
            $region . ' map',
        );

        foreach ($search_terms as $term) {
            $image_url = $this->search_wikimedia_map($term);
            if ($image_url) {
                $attachment_id = $this->save_image_to_media_library($image_url);
                if (!is_wp_error($attachment_id)) {
                    return array('attachment_id' => $attachment_id);
                }
            }
        }

        return new WP_Error('no_map_found', __('No se encontró mapa para la región', 'ai-content-generator'));
    }

    /**
     * Buscar mapa en Wikimedia Commons
     *
     * @param string $query Término de búsqueda
     * @return string|false URL de la imagen
     */
    private function search_wikimedia_map($query) {
        // API de Wikimedia Commons
        $api_url = 'https://commons.wikimedia.org/w/api.php?' . http_build_query(array(
            'action' => 'query',
            'list' => 'search',
            'srsearch' => $query . ' filetype:bitmap',
            'srnamespace' => '6', // File namespace
            'srlimit' => '5',
            'format' => 'json',
        ));

        $response = wp_remote_get($api_url, array(
            'timeout' => 15,
            'headers' => array(
                'User-Agent' => 'WordPress AI Content Generator Plugin'
            )
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['query']['search'])) {
            return false;
        }

        // Buscar el primer resultado que sea un mapa
        foreach ($data['query']['search'] as $result) {
            $title = $result['title'];

            // Filtrar para obtener solo mapas (evitar banderas, escudos, etc.)
            $title_lower = strtolower($title);
            if (strpos($title_lower, 'map') !== false ||
                strpos($title_lower, 'location') !== false ||
                strpos($title_lower, 'mapa') !== false) {

                // Evitar mapas muy viejos o de baja calidad
                if (strpos($title_lower, 'flag') !== false ||
                    strpos($title_lower, 'coat of arms') !== false ||
                    strpos($title_lower, 'escudo') !== false) {
                    continue;
                }

                // Obtener URL de la imagen
                $image_url = $this->get_wikimedia_image_url($title);
                if ($image_url) {
                    return $image_url;
                }
            }
        }

        return false;
    }

    /**
     * Obtener URL directa de imagen de Wikimedia
     *
     * @param string $title Título del archivo (ej: "File:Map.png")
     * @return string|false
     */
    private function get_wikimedia_image_url($title) {
        $api_url = 'https://commons.wikimedia.org/w/api.php?' . http_build_query(array(
            'action' => 'query',
            'titles' => $title,
            'prop' => 'imageinfo',
            'iiprop' => 'url|size',
            'iiurlwidth' => '1200', // Solicitar versión de 1200px de ancho
            'format' => 'json',
        ));

        $response = wp_remote_get($api_url, array(
            'timeout' => 15,
            'headers' => array(
                'User-Agent' => 'WordPress AI Content Generator Plugin'
            )
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $pages = isset($data['query']['pages']) ? $data['query']['pages'] : array();

        foreach ($pages as $page) {
            if (isset($page['imageinfo'][0])) {
                $info = $page['imageinfo'][0];
                // Preferir la versión redimensionada si está disponible
                if (isset($info['thumburl'])) {
                    return $info['thumburl'];
                }
                return $info['url'];
            }
        }

        return false;
    }

    /**
     * Generar imagen con IA (método original)
     *
     * @param array $headlines
     * @return array|WP_Error
     */
    private function generate_ai_image($headlines) {
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
            'cost' => isset($image_result['cost']) ? $image_result['cost'] : 0,
            'source' => 'ai_generated'
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
     * Descargar imagen y redimensionar para carrusel
     *
     * @param string $image_url URL de la imagen
     * @param string $title Título de la noticia (para alt text)
     * @param int $max_width Ancho máximo (default 400px)
     * @param int $max_height Alto máximo (default 225px)
     * @return array|WP_Error Array con attachment_id y url, o WP_Error
     */
    private function download_and_resize_image($image_url, $title = '', $max_width = 400, $max_height = 225) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // Generar nombre único basado en URL
        $url_hash = md5($image_url);
        $filename = 'carousel-' . $url_hash . '-' . date('Ymd');

        // Verificar si ya existe esta imagen (evitar duplicados)
        $existing = get_posts(array(
            'post_type' => 'attachment',
            'meta_key' => '_aicg_source_url_hash',
            'meta_value' => $url_hash,
            'posts_per_page' => 1
        ));

        if (!empty($existing)) {
            $attachment_id = $existing[0]->ID;
            $thumb_url = wp_get_attachment_image_url($attachment_id, 'medium');
            error_log('[AICG] Imagen ya existe en biblioteca: ' . $attachment_id);
            return array(
                'attachment_id' => $attachment_id,
                'url' => $thumb_url ?: wp_get_attachment_url($attachment_id)
            );
        }

        // Descargar imagen
        $tmp_file = download_url($image_url, 30);

        if (is_wp_error($tmp_file)) {
            error_log('[AICG] Error descargando imagen para carrusel: ' . $tmp_file->get_error_message());
            return $tmp_file;
        }

        // Detectar tipo MIME
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $tmp_file);
        finfo_close($finfo);

        // Solo procesar imágenes válidas
        $valid_mimes = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
        if (!in_array($mime_type, $valid_mimes)) {
            @unlink($tmp_file);
            return new WP_Error('invalid_image', __('Tipo de imagen no válido', 'ai-content-generator'));
        }

        $ext = 'jpg';
        if ($mime_type === 'image/png') {
            $ext = 'png';
        } elseif ($mime_type === 'image/gif') {
            $ext = 'gif';
        } elseif ($mime_type === 'image/webp') {
            $ext = 'webp';
        }

        // Redimensionar imagen si es necesario
        $resized_file = $this->resize_image_file($tmp_file, $max_width, $max_height, $mime_type);
        if ($resized_file && $resized_file !== $tmp_file) {
            @unlink($tmp_file);
            $tmp_file = $resized_file;
        }

        $file_array = array(
            'name' => $filename . '.' . $ext,
            'tmp_name' => $tmp_file
        );

        // Subir a biblioteca de medios
        $alt_text = $title ?: __('Imagen de noticia', 'ai-content-generator');
        $attachment_id = media_handle_sideload($file_array, 0, $alt_text);

        if (is_wp_error($attachment_id)) {
            @unlink($tmp_file);
            error_log('[AICG] Error guardando imagen carrusel: ' . $attachment_id->get_error_message());
            return $attachment_id;
        }

        // Guardar hash de URL para evitar duplicados
        update_post_meta($attachment_id, '_aicg_source_url_hash', $url_hash);
        update_post_meta($attachment_id, '_aicg_source_url', $image_url);

        // Actualizar alt text
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);

        $thumb_url = wp_get_attachment_image_url($attachment_id, 'medium');

        error_log('[AICG] Imagen carrusel guardada: ' . $attachment_id);

        return array(
            'attachment_id' => $attachment_id,
            'url' => $thumb_url ?: wp_get_attachment_url($attachment_id)
        );
    }

    /**
     * Redimensionar archivo de imagen
     *
     * @param string $file_path Ruta al archivo temporal
     * @param int $max_width Ancho máximo
     * @param int $max_height Alto máximo
     * @param string $mime_type Tipo MIME
     * @return string|false Ruta al archivo redimensionado o false
     */
    private function resize_image_file($file_path, $max_width, $max_height, $mime_type) {
        // Obtener dimensiones originales
        $image_size = getimagesize($file_path);
        if (!$image_size) {
            return false;
        }

        $orig_width = $image_size[0];
        $orig_height = $image_size[1];

        // Si ya es suficientemente pequeña, no redimensionar
        if ($orig_width <= $max_width && $orig_height <= $max_height) {
            return $file_path;
        }

        // Calcular nuevas dimensiones manteniendo proporción
        $ratio = min($max_width / $orig_width, $max_height / $orig_height);
        $new_width = round($orig_width * $ratio);
        $new_height = round($orig_height * $ratio);

        // Usar el editor de imágenes de WordPress
        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) {
            error_log('[AICG] No se pudo crear editor de imagen: ' . $editor->get_error_message());
            return false;
        }

        $editor->resize($new_width, $new_height, false);
        $editor->set_quality(85);

        // Guardar en archivo temporal
        $resized_path = $file_path . '_resized';
        $result = $editor->save($resized_path);

        if (is_wp_error($result)) {
            error_log('[AICG] Error redimensionando: ' . $result->get_error_message());
            return false;
        }

        return $result['path'];
    }

    /**
     * Extraer imágenes de noticias para un tema y crear carrusel
     *
     * @param array $news Array de noticias con 'link' y 'title'
     * @param string $topic_name Nombre del tema
     * @return array Array con 'images' (array de imágenes) y 'html' (HTML del carrusel)
     */
    private function extract_topic_images($news, $topic_name) {
        $images = array();
        $max_images = 5; // Máximo de imágenes por carrusel

        // Verificar si está habilitada la extracción de imágenes OG
        $use_og = get_option('aicg_image_source_og', true);
        if (!$use_og) {
            return array('images' => array(), 'html' => '');
        }

        foreach (array_slice($news, 0, $max_images) as $item) {
            if (empty($item['link'])) {
                continue;
            }

            // Obtener imagen OG de la noticia
            $og_image_url = $this->get_og_image_from_url($item['link']);
            if (!$og_image_url) {
                continue;
            }

            // Descargar y redimensionar
            $result = $this->download_and_resize_image(
                $og_image_url,
                isset($item['title']) ? $item['title'] : '',
                400,
                225
            );

            if (!is_wp_error($result)) {
                $images[] = array(
                    'url' => $result['url'],
                    'attachment_id' => $result['attachment_id'],
                    'title' => isset($item['title']) ? $item['title'] : '',
                    'link' => $item['link'],
                    'source' => isset($item['source']) ? $item['source'] : ''
                );
            }
        }

        // Generar HTML del carrusel
        $html = $this->generate_carousel_html($images, $topic_name);

        return array(
            'images' => $images,
            'html' => $html
        );
    }

    /**
     * Generar HTML de la galería horizontal de imágenes
     * Usa solo CSS inline para compatibilidad con WordPress (sin JavaScript)
     * Estructura resistente a plugins de auto-linking
     *
     * @param array $images Array de imágenes
     * @param string $topic_name Nombre del tema
     * @return string HTML de la galería
     */
    private function generate_carousel_html($images, $topic_name) {
        if (empty($images)) {
            return '';
        }

        $gallery_id = 'aicg-gallery-' . sanitize_title($topic_name) . '-' . uniqid();
        $flex_container_class = 'aicg-flex-' . substr(uniqid(), -6);
        $card_class = 'aicg-card-' . substr(uniqid(), -6);

        ob_start();
        ?>
        <style>
        #<?php echo esc_attr($gallery_id); ?> .<?php echo esc_attr($flex_container_class); ?> {
            display: flex !important;
            flex-direction: row !important;
            flex-wrap: nowrap !important;
            gap: 8px;
            padding: 5px 0;
        }
        #<?php echo esc_attr($gallery_id); ?> .<?php echo esc_attr($card_class); ?> {
            flex: 0 0 auto !important;
            width: 150px !important;
            min-width: 150px !important;
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,0.12);
            background: #fff;
        }
        </style>
        <div id="<?php echo esc_attr($gallery_id); ?>" class="aicg-news-gallery noautotaglink" style="margin: 15px 0; overflow-x: auto; -webkit-overflow-scrolling: touch;">
            <div class="<?php echo esc_attr($flex_container_class); ?>">
                <?php foreach ($images as $image) :
                    $short_title = wp_trim_words($image['title'], 6, '…');
                ?>
                <div class="aicg-gallery-card noautotaglink <?php echo esc_attr($card_class); ?>">
                    <a href="<?php echo esc_url($image['link']); ?>" target="_blank" rel="noopener noreferrer" style="display: block; text-decoration: none;">
                        <img src="<?php echo esc_url($image['url']); ?>"
                             alt="<?php echo esc_attr($image['title']); ?>"
                             loading="lazy"
                             style="width: 150px; height: 85px; object-fit: cover; display: block;">
                    </a>
                    <div style="padding: 6px 8px; background: #f8f9fa; min-height: 42px;">
                        <a href="<?php echo esc_url($image['link']); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr($image['title']); ?>" style="text-decoration: none; color: inherit;">
                            <span style="display: block; font-size: 10px; line-height: 1.3; color: #333; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;"><?php echo esc_html($short_title); ?></span>
                        </a>
                        <?php if (!empty($image['source'])) : ?>
                        <span style="display: block; font-size: 9px; color: #888; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo esc_html($image['source']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Obtener CSS de la galería (mantenido por compatibilidad)
     * La galería ahora usa CSS inline para compatibilidad con WordPress
     *
     * @return string CSS vacío
     */
    public static function get_carousel_css() {
        return '';
    }

    /**
     * Obtener JavaScript de la galería (ya no es necesario)
     * La galería ahora es estática con scroll horizontal nativo
     *
     * @return string JavaScript vacío
     */
    public static function get_carousel_js() {
        return '';
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
