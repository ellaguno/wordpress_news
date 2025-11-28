<?php
/**
 * Conversor de HTML a bloques de Gutenberg
 *
 * Convierte HTML generado por IA al formato de bloques de WordPress Gutenberg
 *
 * @package AI_Content_Generator
 * @since 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para convertir HTML a formato de bloques Gutenberg
 */
class AICG_Gutenberg_Converter {

    /**
     * Convertir HTML a bloques de Gutenberg
     *
     * @param string $html HTML a convertir
     * @return string Contenido en formato de bloques Gutenberg
     */
    public static function convert($html) {
        if (empty($html)) {
            return '';
        }

        error_log('[AICG Gutenberg] Iniciando conversión. HTML length: ' . strlen($html));

        $blocks = array();

        // Normalizar HTML
        $html = self::normalize_html($html);

        // Usar DOMDocument para parsear
        $dom = new DOMDocument('1.0', 'UTF-8');

        // Suprimir warnings de HTML mal formado
        libxml_use_internal_errors(true);

        // Envolver en estructura HTML válida con encoding UTF-8
        $wrapped_html = '<?xml encoding="UTF-8"><div id="aicg-root">' . $html . '</div>';

        $dom->loadHTML($wrapped_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $errors = libxml_get_errors();
        if (!empty($errors)) {
            error_log('[AICG Gutenberg] Errores XML: ' . count($errors));
            foreach (array_slice($errors, 0, 3) as $error) {
                error_log('[AICG Gutenberg] Error: ' . trim($error->message));
            }
        }
        libxml_clear_errors();

        // Buscar el div raíz
        $root = $dom->getElementById('aicg-root');

        if (!$root) {
            // Intentar con getElementsByTagName
            $divs = $dom->getElementsByTagName('div');
            foreach ($divs as $div) {
                if ($div->getAttribute('id') === 'aicg-root') {
                    $root = $div;
                    break;
                }
            }
        }

        if (!$root) {
            error_log('[AICG Gutenberg] No se encontró root, usando body o documentElement');
            $root = $dom->getElementsByTagName('body')->item(0);
            if (!$root) {
                $root = $dom->documentElement;
            }
        }

        if (!$root) {
            error_log('[AICG Gutenberg] No se pudo parsear HTML, devolviendo como bloque HTML');
            return self::wrap_as_html_block($html);
        }

        error_log('[AICG Gutenberg] Root encontrado con ' . $root->childNodes->length . ' hijos');

        // Procesar nodos hijos del root
        foreach ($root->childNodes as $node) {
            $block = self::node_to_block($node, $dom);
            if (!empty($block)) {
                $blocks[] = $block;
            }
        }

        if (empty($blocks)) {
            error_log('[AICG Gutenberg] No se generaron bloques, devolviendo HTML como bloque');
            return self::wrap_as_html_block($html);
        }

        // Unir bloques
        $result = implode("\n\n", $blocks);

        // Limpiar espacios al inicio
        $result = ltrim($result);

        error_log('[AICG Gutenberg] Conversión completada. Bloques: ' . count($blocks));
        error_log('[AICG Gutenberg] Primer bloque: ' . substr($blocks[0], 0, 100));

        return $result;
    }

    /**
     * Normalizar HTML antes de procesar
     */
    private static function normalize_html($html) {
        // Remover saltos de línea excesivos
        $html = preg_replace('/\n\s*\n/', "\n", $html);
        $html = trim($html);
        return $html;
    }

    /**
     * Convertir un nodo DOM a bloque Gutenberg
     */
    private static function node_to_block($node, $dom) {
        // Ignorar nodos de texto vacíos
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = trim($node->textContent);
            if (empty($text)) {
                return '';
            }
            return self::paragraph_block($text);
        }

        // Solo procesar elementos
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $tag = strtolower($node->nodeName);
        $innerHTML = self::get_inner_html($node, $dom);

        error_log('[AICG Gutenberg] Procesando tag: ' . $tag);

        switch ($tag) {
            case 'p':
                return self::paragraph_block($innerHTML);

            case 'h1':
                return self::heading_block($innerHTML, 1);

            case 'h2':
                return self::heading_block($innerHTML, 2);

            case 'h3':
                return self::heading_block($innerHTML, 3);

            case 'h4':
                return self::heading_block($innerHTML, 4);

            case 'h5':
                return self::heading_block($innerHTML, 5);

            case 'h6':
                return self::heading_block($innerHTML, 6);

            case 'ul':
                return self::list_block($node, $dom, false);

            case 'ol':
                return self::list_block($node, $dom, true);

            case 'blockquote':
                return self::quote_block($innerHTML);

            case 'figure':
                return self::figure_block($node, $dom);

            case 'img':
                return self::image_block_from_node($node);

            case 'hr':
                return self::separator_block();

            case 'div':
                return self::div_block($node, $dom);

            case 'table':
                return self::table_block($node, $dom);

            case 'br':
                return ''; // Ignorar br sueltos

            default:
                // Para otros elementos, intentar procesar hijos o envolver como HTML
                $inner_blocks = self::process_children($node, $dom);
                if (!empty($inner_blocks)) {
                    return implode("\n\n", $inner_blocks);
                }

                $outerHTML = $dom->saveHTML($node);
                if (!empty(trim(strip_tags($outerHTML)))) {
                    return self::wrap_as_html_block($outerHTML);
                }
                return '';
        }
    }

    /**
     * Procesar nodos hijos
     */
    private static function process_children($node, $dom) {
        $blocks = array();
        foreach ($node->childNodes as $child) {
            $block = self::node_to_block($child, $dom);
            if (!empty($block)) {
                $blocks[] = $block;
            }
        }
        return $blocks;
    }

    /**
     * Bloque de párrafo
     */
    public static function paragraph_block($content) {
        $content = self::clean_content($content);
        if (empty($content)) {
            return '';
        }

        return "<!-- wp:paragraph -->\n<p>" . $content . "</p>\n<!-- /wp:paragraph -->";
    }

    /**
     * Bloque de encabezado
     */
    public static function heading_block($content, $level = 2) {
        $content = self::clean_content($content);
        if (empty($content)) {
            return '';
        }

        $level = max(1, min(6, intval($level)));

        if ($level === 2) {
            return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . $content . "</h2>\n<!-- /wp:heading -->";
        }

        return "<!-- wp:heading {\"level\":" . $level . "} -->\n<h" . $level . " class=\"wp-block-heading\">" . $content . "</h" . $level . ">\n<!-- /wp:heading -->";
    }

    /**
     * Bloque de lista
     */
    public static function list_block($node, $dom, $ordered = false) {
        $items = array();

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) === 'li') {
                $itemContent = self::clean_content(self::get_inner_html($child, $dom));
                if (!empty($itemContent)) {
                    $items[] = '<li>' . $itemContent . '</li>';
                }
            }
        }

        if (empty($items)) {
            return '';
        }

        $tag = $ordered ? 'ol' : 'ul';
        $attrs = $ordered ? ' {"ordered":true}' : '';

        return "<!-- wp:list" . $attrs . " -->\n<" . $tag . ">" . implode('', $items) . "</" . $tag . ">\n<!-- /wp:list -->";
    }

    /**
     * Bloque de cita
     */
    public static function quote_block($content) {
        $content = self::clean_content($content);
        if (empty($content)) {
            return '';
        }

        if (strpos($content, '<p>') === false) {
            $content = '<p>' . $content . '</p>';
        }

        return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">" . $content . "</blockquote>\n<!-- /wp:quote -->";
    }

    /**
     * Bloque de imagen
     */
    public static function image_block($url, $alt = '', $caption = '', $attrs = array()) {
        if (empty($url)) {
            return '';
        }

        $block_attrs = array();
        $figure_classes = array('wp-block-image');
        $img_attrs = 'src="' . esc_url($url) . '"';

        if (!empty($alt)) {
            $img_attrs .= ' alt="' . esc_attr($alt) . '"';
        }

        if (!empty($attrs['width'])) {
            $block_attrs['width'] = intval($attrs['width']);
            $img_attrs .= ' width="' . intval($attrs['width']) . '"';
        }
        if (!empty($attrs['height'])) {
            $block_attrs['height'] = intval($attrs['height']);
            $img_attrs .= ' height="' . intval($attrs['height']) . '"';
        }

        if (!empty($attrs['align'])) {
            $block_attrs['align'] = $attrs['align'];
            $figure_classes[] = 'align' . $attrs['align'];
        }

        if (!empty($attrs['id'])) {
            $block_attrs['id'] = intval($attrs['id']);
            $figure_classes[] = 'wp-image-' . intval($attrs['id']);
        }

        if (!empty($attrs['sizeSlug'])) {
            $block_attrs['sizeSlug'] = $attrs['sizeSlug'];
            $figure_classes[] = 'size-' . $attrs['sizeSlug'];
        } else {
            $figure_classes[] = 'size-large';
        }

        $attrs_json = !empty($block_attrs) ? ' ' . wp_json_encode($block_attrs) : '';
        $figure_class = implode(' ', $figure_classes);

        $caption_html = '';
        if (!empty($caption)) {
            $caption_html = '<figcaption class="wp-element-caption">' . $caption . '</figcaption>';
        }

        return "<!-- wp:image" . $attrs_json . " -->\n<figure class=\"" . esc_attr($figure_class) . "\"><img " . $img_attrs . "/>" . $caption_html . "</figure>\n<!-- /wp:image -->";
    }

    /**
     * Bloque de imagen desde nodo IMG
     */
    private static function image_block_from_node($node) {
        $url = $node->getAttribute('src');
        $alt = $node->getAttribute('alt');
        $width = $node->getAttribute('width');
        $height = $node->getAttribute('height');

        $attrs = array();
        if ($width) $attrs['width'] = $width;
        if ($height) $attrs['height'] = $height;

        return self::image_block($url, $alt, '', $attrs);
    }

    /**
     * Bloque figure (puede contener imagen)
     */
    private static function figure_block($node, $dom) {
        $imgs = $node->getElementsByTagName('img');
        if ($imgs->length > 0) {
            $img = $imgs->item(0);
            $url = $img->getAttribute('src');
            $alt = $img->getAttribute('alt');

            $caption = '';
            $figcaptions = $node->getElementsByTagName('figcaption');
            if ($figcaptions->length > 0) {
                $caption = $figcaptions->item(0)->textContent;
            }

            $attrs = array();
            $width = $img->getAttribute('width');
            $height = $img->getAttribute('height');
            if ($width) $attrs['width'] = $width;
            if ($height) $attrs['height'] = $height;

            $class = $node->getAttribute('class');
            if (strpos($class, 'aligncenter') !== false || strpos($class, 'center') !== false) {
                $attrs['align'] = 'center';
            } elseif (strpos($class, 'alignleft') !== false) {
                $attrs['align'] = 'left';
            } elseif (strpos($class, 'alignright') !== false) {
                $attrs['align'] = 'right';
            }

            return self::image_block($url, $alt, $caption, $attrs);
        }

        return self::wrap_as_html_block($dom->saveHTML($node));
    }

    /**
     * Bloque separador
     */
    public static function separator_block() {
        return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
    }

    /**
     * Procesar div
     */
    private static function div_block($node, $dom) {
        $class = $node->getAttribute('class');

        error_log('[AICG Gutenberg] Procesando DIV con clase: ' . $class);

        // Divs de referencias - mantener como HTML
        if (strpos($class, 'aicg-references') !== false) {
            return self::wrap_as_html_block(self::decode_html($dom->saveHTML($node)));
        }

        // Procesar hijos de cualquier div
        $inner_blocks = self::process_children($node, $dom);

        if (!empty($inner_blocks)) {
            return implode("\n\n", $inner_blocks);
        }

        // Si no hay bloques internos pero hay contenido, envolver como HTML
        $outerHTML = $dom->saveHTML($node);
        if (!empty(trim(strip_tags($outerHTML)))) {
            return self::wrap_as_html_block(self::decode_html($outerHTML));
        }

        return '';
    }

    /**
     * Bloque de tabla
     */
    private static function table_block($node, $dom) {
        $tableHTML = self::decode_html($dom->saveHTML($node));

        if (strpos($tableHTML, 'wp-block-table') === false) {
            $tableHTML = preg_replace('/<table/', '<table class="wp-block-table"', $tableHTML, 1);
        }

        return "<!-- wp:table -->\n<figure class=\"wp-block-table\">" . $tableHTML . "</figure>\n<!-- /wp:table -->";
    }

    /**
     * Envolver como bloque HTML
     */
    public static function wrap_as_html_block($html) {
        $html = self::decode_html(trim($html));
        if (empty($html)) {
            return '';
        }

        return "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->";
    }

    /**
     * Obtener innerHTML de un nodo
     */
    private static function get_inner_html($node, $dom) {
        $innerHTML = '';
        foreach ($node->childNodes as $child) {
            $innerHTML .= $dom->saveHTML($child);
        }
        return self::decode_html(trim($innerHTML));
    }

    /**
     * Limpiar contenido
     */
    private static function clean_content($content) {
        $content = self::decode_html($content);
        $content = trim($content);
        return $content;
    }

    /**
     * Decodificar entidades HTML
     */
    private static function decode_html($html) {
        // Decodificar entidades HTML que DOMDocument crea
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $html;
    }

    /**
     * Crear bloque de referencias (para uso directo)
     */
    public static function create_references_block($references) {
        if (empty($references)) {
            return '';
        }

        $ref_style = get_option('aicg_reference_style', 'inline');
        $ref_color = get_option('aicg_reference_color', '#0073aa');
        $ref_orientation = get_option('aicg_reference_orientation', 'horizontal');

        if ($ref_orientation === 'vertical') {
            $container_style = 'display: flex; flex-direction: column; align-items: flex-start; gap: 5px;';
        } else {
            $container_style = 'display: flex; flex-direction: row; flex-wrap: wrap; align-items: center; gap: 5px;';
        }

        $refs_html = '';
        foreach ($references as $ref) {
            $num = isset($ref['number']) ? $ref['number'] : 1;
            $url = isset($ref['url']) ? $ref['url'] : '#';

            switch ($ref_style) {
                case 'circle':
                    $refs_html .= sprintf(
                        '<a href="%s" target="_blank" rel="noopener" style="display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; background: %s; color: white; border-radius: 50%%; font-size: 12px; text-decoration: none; margin: 0 3px; font-weight: bold;">%d</a>',
                        esc_url($url),
                        esc_attr($ref_color),
                        $num
                    );
                    break;

                case 'square':
                    $refs_html .= sprintf(
                        '<a href="%s" target="_blank" rel="noopener" style="display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; background: %s; color: white; border-radius: 4px; font-size: 12px; text-decoration: none; margin: 0 3px; font-weight: bold;">%d</a>',
                        esc_url($url),
                        esc_attr($ref_color),
                        $num
                    );
                    break;

                case 'badge':
                    $bg_color = self::hex_to_rgba($ref_color, 0.15);
                    $refs_html .= sprintf(
                        '<a href="%s" target="_blank" rel="noopener" style="display: inline-block; padding: 3px 10px; background: %s; color: %s; border-radius: 12px; font-size: 12px; text-decoration: none; margin: 0 3px; font-weight: 500;">%d</a>',
                        esc_url($url),
                        $bg_color,
                        esc_attr($ref_color),
                        $num
                    );
                    break;

                default:
                    $refs_html .= sprintf(
                        '<sup><a href="%s" target="_blank" rel="noopener" style="color: %s; text-decoration: none;"><strong>%d</strong></a></sup> ',
                        esc_url($url),
                        esc_attr($ref_color),
                        $num
                    );
                    break;
            }
        }

        $html = sprintf(
            '<div class="aicg-references" style="%s">%s</div>',
            $container_style,
            $refs_html
        );

        return self::wrap_as_html_block($html);
    }

    /**
     * Convertir hex a rgba
     */
    private static function hex_to_rgba($hex, $alpha = 1) {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return sprintf('rgba(%d, %d, %d, %.2f)', $r, $g, $b, $alpha);
    }
}
