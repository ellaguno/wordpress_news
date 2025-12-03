<?php
/**
 * Proveedor OpenRouter
 *
 * OpenRouter permite acceso a múltiples modelos de IA a través de una única API
 *
 * @package AI_Content_Generator
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Implementación del proveedor OpenRouter
 */
class AICG_OpenRouter_Provider extends AICG_AI_Provider_Base {

    /**
     * Constructor
     */
    public function __construct() {
        $api_key = get_option('aicg_openrouter_api_key', '');
        parent::__construct($api_key);
        $this->api_base_url = 'https://openrouter.ai/api/v1';
    }

    /**
     * Obtener nombre del proveedor
     *
     * @return string
     */
    public function get_name() {
        return 'openrouter';
    }

    /**
     * Obtener headers personalizados para OpenRouter
     *
     * @return array
     */
    protected function get_headers() {
        return array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->api_key,
            'HTTP-Referer' => home_url(),
            'X-Title' => get_bloginfo('name')
        );
    }

    /**
     * Obtener modelos disponibles
     *
     * OpenRouter tiene acceso a muchos modelos. Aquí listamos los más populares.
     *
     * @return array
     */
    public function get_available_models() {
        return array(
            // OpenAI
            'openai/gpt-4o' => array(
                'name' => 'GPT-4o (via OpenRouter)',
                'description' => 'OpenAI GPT-4o a través de OpenRouter',
                'max_tokens' => 128000,
                'input_cost' => 0.005,
                'output_cost' => 0.015
            ),
            'openai/gpt-4o-mini' => array(
                'name' => 'GPT-4o Mini (via OpenRouter)',
                'description' => 'OpenAI GPT-4o Mini económico',
                'max_tokens' => 128000,
                'input_cost' => 0.00015,
                'output_cost' => 0.0006
            ),

            // Anthropic
            'anthropic/claude-sonnet-4' => array(
                'name' => 'Claude Sonnet 4 (via OpenRouter)',
                'description' => 'Anthropic Claude Sonnet 4',
                'max_tokens' => 200000,
                'input_cost' => 0.003,
                'output_cost' => 0.015
            ),
            'anthropic/claude-3.5-sonnet' => array(
                'name' => 'Claude 3.5 Sonnet (via OpenRouter)',
                'description' => 'Anthropic Claude 3.5 Sonnet',
                'max_tokens' => 200000,
                'input_cost' => 0.003,
                'output_cost' => 0.015
            ),
            'anthropic/claude-3-haiku' => array(
                'name' => 'Claude 3 Haiku (via OpenRouter)',
                'description' => 'Modelo rápido y económico de Anthropic',
                'max_tokens' => 200000,
                'input_cost' => 0.00025,
                'output_cost' => 0.00125
            ),

            // Google
            'google/gemini-pro-1.5' => array(
                'name' => 'Gemini Pro 1.5',
                'description' => 'Google Gemini Pro 1.5',
                'max_tokens' => 1000000,
                'input_cost' => 0.00125,
                'output_cost' => 0.005
            ),
            'google/gemini-flash-1.5' => array(
                'name' => 'Gemini Flash 1.5',
                'description' => 'Google Gemini Flash - rápido y económico',
                'max_tokens' => 1000000,
                'input_cost' => 0.000075,
                'output_cost' => 0.0003
            ),

            // Meta
            'meta-llama/llama-3.1-405b-instruct' => array(
                'name' => 'Llama 3.1 405B',
                'description' => 'Meta Llama 3.1 - modelo más grande',
                'max_tokens' => 131072,
                'input_cost' => 0.003,
                'output_cost' => 0.003
            ),
            'meta-llama/llama-3.1-70b-instruct' => array(
                'name' => 'Llama 3.1 70B',
                'description' => 'Meta Llama 3.1 70B - balance calidad/costo',
                'max_tokens' => 131072,
                'input_cost' => 0.00035,
                'output_cost' => 0.0004
            ),
            'meta-llama/llama-3.1-8b-instruct' => array(
                'name' => 'Llama 3.1 8B',
                'description' => 'Meta Llama 3.1 8B - económico',
                'max_tokens' => 131072,
                'input_cost' => 0.00006,
                'output_cost' => 0.00006
            ),

            // Mistral
            'mistralai/mistral-large' => array(
                'name' => 'Mistral Large',
                'description' => 'Mistral AI - modelo grande',
                'max_tokens' => 128000,
                'input_cost' => 0.002,
                'output_cost' => 0.006
            ),
            'mistralai/mistral-medium' => array(
                'name' => 'Mistral Medium',
                'description' => 'Mistral AI - balance',
                'max_tokens' => 32000,
                'input_cost' => 0.00275,
                'output_cost' => 0.0081
            ),
            'mistralai/mixtral-8x7b-instruct' => array(
                'name' => 'Mixtral 8x7B',
                'description' => 'Mistral Mixtral MoE',
                'max_tokens' => 32768,
                'input_cost' => 0.00024,
                'output_cost' => 0.00024
            ),

            // DeepSeek
            'deepseek/deepseek-chat' => array(
                'name' => 'DeepSeek Chat (via OpenRouter)',
                'description' => 'DeepSeek Chat',
                'max_tokens' => 64000,
                'input_cost' => 0.00014,
                'output_cost' => 0.00028
            ),
            'deepseek/deepseek-r1' => array(
                'name' => 'DeepSeek R1 (via OpenRouter)',
                'description' => 'DeepSeek Reasoner',
                'max_tokens' => 64000,
                'input_cost' => 0.00055,
                'output_cost' => 0.00219
            ),

            // Qwen
            'qwen/qwen-2.5-72b-instruct' => array(
                'name' => 'Qwen 2.5 72B',
                'description' => 'Alibaba Qwen 2.5 72B',
                'max_tokens' => 131072,
                'input_cost' => 0.00035,
                'output_cost' => 0.0004
            ),

            // Modelos gratuitos (con límites)
            'google/gemma-2-9b-it:free' => array(
                'name' => 'Gemma 2 9B (Gratis)',
                'description' => 'Google Gemma 2 - gratis con límites',
                'max_tokens' => 8192,
                'input_cost' => 0,
                'output_cost' => 0
            ),
            'meta-llama/llama-3.2-3b-instruct:free' => array(
                'name' => 'Llama 3.2 3B (Gratis)',
                'description' => 'Meta Llama 3.2 3B - gratis con límites',
                'max_tokens' => 131072,
                'input_cost' => 0,
                'output_cost' => 0
            )
        );
    }

    /**
     * Obtener modelos de imagen
     *
     * @return array
     */
    public function get_image_models() {
        return array(
            // Modelos GPT-5 con capacidad de imagen (nuevos 2025)
            'openai/gpt-5-image' => array(
                'name' => 'GPT-5 Image',
                'description' => 'OpenAI GPT-5 con generación de imágenes avanzada',
                'sizes' => array('1024x1024', '1536x1024', '1024x1536'),
                'qualities' => array('standard', 'hd'),
                'cost_standard' => 0.04,
                'cost_hd' => 0.08,
                'uses_chat_api' => true
            ),
            'openai/gpt-5-image-mini' => array(
                'name' => 'GPT-5 Image Mini',
                'description' => 'GPT-5 Mini con imagen - económico y rápido',
                'sizes' => array('1024x1024', '1536x1024', '1024x1536'),
                'qualities' => array('standard'),
                'cost_standard' => 0.008,
                'uses_chat_api' => true
            ),
            // GPT Image 1 (modelo de imagen dedicado)
            'openai/gpt-image-1' => array(
                'name' => 'GPT Image 1',
                'description' => 'OpenAI GPT Image 1 - generación de imágenes dedicada',
                'sizes' => array('1024x1024', '1536x1024', '1024x1536'),
                'qualities' => array('low', 'medium', 'high'),
                'cost_standard' => 0.02,
                'uses_chat_api' => true
            ),
            // DALL-E 3 tradicional
            'openai/dall-e-3' => array(
                'name' => 'DALL-E 3',
                'description' => 'OpenAI DALL-E 3 - generación clásica',
                'sizes' => array('1024x1024', '1792x1024', '1024x1792'),
                'qualities' => array('standard', 'hd'),
                'cost_standard' => 0.04,
                'cost_hd' => 0.08,
                'uses_chat_api' => false
            ),
            // Google Gemini con imagen
            'google/gemini-2.0-flash-exp:free' => array(
                'name' => 'Gemini 2.0 Flash (Gratis)',
                'description' => 'Google Gemini con generación de imágenes - gratis',
                'sizes' => array('1024x1024'),
                'qualities' => array('standard'),
                'cost_standard' => 0,
                'uses_chat_api' => true
            ),
            // Stable Diffusion
            'stability/stable-diffusion-xl' => array(
                'name' => 'Stable Diffusion XL',
                'description' => 'Stability AI SDXL',
                'sizes' => array('1024x1024'),
                'qualities' => array('standard'),
                'cost_standard' => 0.002,
                'uses_chat_api' => false
            )
        );
    }

    /**
     * Generar texto
     *
     * @param string $prompt
     * @param array  $options
     * @return array|WP_Error
     */
    public function generate_text($prompt, $options = array()) {
        $defaults = array(
            'model' => 'openai/gpt-4o-mini',
            'temperature' => 0.7,
            'max_tokens' => 4000,
            'system_message' => 'Eres un escritor experto que genera contenido de alta calidad en español.'
        );

        $options = wp_parse_args($options, $defaults);

        $body = array(
            'model' => $options['model'],
            'messages' => array(
                array(
                    'role' => 'system',
                    'content' => $options['system_message']
                ),
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            'temperature' => (float) $options['temperature'],
            'max_tokens' => (int) $options['max_tokens']
        );

        // Añadir transforms para mejor compatibilidad
        $body['transforms'] = array('middle-out');

        $response = $this->make_request('/chat/completions', $body);

        if (is_wp_error($response)) {
            return $response;
        }

        if (!isset($response['choices'][0]['message']['content'])) {
            return new WP_Error('invalid_response', __('Respuesta inválida de OpenRouter', 'ai-content-generator'));
        }

        $usage = isset($response['usage']) ? $response['usage'] : array(
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0
        );

        return array(
            'content' => $response['choices'][0]['message']['content'],
            'usage' => $usage,
            'model' => $options['model'],
            'cost' => $this->estimate_cost(
                $usage['prompt_tokens'],
                $usage['completion_tokens'],
                $options['model']
            )
        );
    }

    /**
     * Generar imagen
     *
     * OpenRouter soporta generación de imágenes de dos formas:
     * 1. Modelos nativos de imagen (dall-e-3, stable-diffusion) via /images/generations
     * 2. Modelos multimodales (gpt-5-image, gpt-image-1, gemini) via /chat/completions con modalities
     *
     * @param string $prompt
     * @param array  $options
     * @return array|WP_Error
     */
    public function generate_image($prompt, $options = array()) {
        // Aumentar timeout para generación de imágenes (pueden tardar hasta 3 minutos)
        $original_timeout = $this->timeout;
        $this->timeout = 300; // 5 minutos

        // Intentar aumentar tiempo de ejecución de PHP si es posible
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $defaults = array(
            'model' => get_option('aicg_image_model', 'openai/gpt-5-image-mini'),
            'size' => '1024x1024',
            'quality' => 'standard',
            'n' => 1
        );

        $options = wp_parse_args($options, $defaults);

        error_log('[AICG] generate_image iniciado con timeout de ' . $this->timeout . ' segundos');

        // Verificar si el modelo usa la API de chat con modalities
        $image_models = $this->get_image_models();
        $uses_chat_api = isset($image_models[$options['model']]['uses_chat_api'])
            ? $image_models[$options['model']]['uses_chat_api']
            : $this->detect_chat_api_model($options['model']);

        try {
            if ($uses_chat_api) {
                $result = $this->generate_image_via_chat($prompt, $options);
                $this->timeout = $original_timeout;
                return $result;
            }

            // Método tradicional para DALL-E y Stable Diffusion via /images/generations
            $body = array(
                'model' => $options['model'],
                'prompt' => $prompt,
                'n' => $options['n'],
                'size' => $options['size']
            );

            if (strpos($options['model'], 'dall-e-3') !== false) {
                $body['quality'] = $options['quality'];
            }

            $response = $this->make_request('/images/generations', $body);

            if (is_wp_error($response)) {
                $this->timeout = $original_timeout;
                return $response;
            }

            if (!isset($response['data'][0]['url'])) {
                error_log('[AICG] OpenRouter image response: ' . print_r($response, true));
                $this->timeout = $original_timeout;
                return new WP_Error('invalid_response', __('Respuesta inválida para generación de imagen', 'ai-content-generator'));
            }

            $cost = isset($image_models[$options['model']]['cost_' . $options['quality']])
                ? $image_models[$options['model']]['cost_' . $options['quality']]
                : 0.04;

            $this->timeout = $original_timeout;
            return array(
                'url' => $response['data'][0]['url'],
                'model' => $options['model'],
                'cost' => $cost
            );
        } catch (Exception $e) {
            $this->timeout = $original_timeout;
            error_log('[AICG] Exception en generate_image: ' . $e->getMessage());
            return new WP_Error('image_generation_error', $e->getMessage());
        }
    }

    /**
     * Mapear tamaño de configuración al tamaño soportado por el modelo
     *
     * Los tamaños de ChatGPT/DALL-E 3 (1792x1024, 1024x1792) no son soportados
     * por GPT-5-image-mini que usa (1536x1024, 1024x1536)
     *
     * @param string $model
     * @param string $size
     * @return string
     */
    private function map_size_for_model($model, $size) {
        // Obtener tamaños soportados por el modelo
        $image_models = $this->get_image_models();
        $supported_sizes = isset($image_models[$model]['sizes'])
            ? $image_models[$model]['sizes']
            : array('1024x1024');

        // Si el tamaño ya es soportado, usarlo
        if (in_array($size, $supported_sizes)) {
            return $size;
        }

        // Mapear tamaños de DALL-E 3 a equivalentes GPT-5-image
        $size_mapping = array(
            '1792x1024' => '1536x1024',  // Horizontal DALL-E -> Horizontal GPT-5
            '1024x1792' => '1024x1536',  // Vertical DALL-E -> Vertical GPT-5
        );

        if (isset($size_mapping[$size]) && in_array($size_mapping[$size], $supported_sizes)) {
            error_log('[AICG] Mapeando tamaño ' . $size . ' a ' . $size_mapping[$size] . ' para modelo ' . $model);
            return $size_mapping[$size];
        }

        // Si el modelo es GPT-5 image y pide horizontal, usar 1536x1024
        if (strpos($model, 'gpt-5-image') !== false || strpos($model, 'gpt-image') !== false) {
            $parts = explode('x', $size);
            $width = isset($parts[0]) ? intval($parts[0]) : 1024;
            $height = isset($parts[1]) ? intval($parts[1]) : 1024;

            if ($width > $height && in_array('1536x1024', $supported_sizes)) {
                error_log('[AICG] Usando tamaño horizontal 1536x1024 para modelo ' . $model);
                return '1536x1024';
            } elseif ($height > $width && in_array('1024x1536', $supported_sizes)) {
                error_log('[AICG] Usando tamaño vertical 1024x1536 para modelo ' . $model);
                return '1024x1536';
            }
        }

        // Default: usar el primer tamaño soportado o 1024x1024
        return isset($supported_sizes[0]) ? $supported_sizes[0] : '1024x1024';
    }

    /**
     * Detectar si un modelo usa la API de chat para imágenes
     *
     * @param string $model
     * @return bool
     */
    private function detect_chat_api_model($model) {
        $chat_patterns = array(
            'gpt-5-image',
            'gpt-image',
            'gemini',
            'claude',
            'grok'
        );

        foreach ($chat_patterns as $pattern) {
            if (strpos($model, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generar imagen usando modelos de chat multimodales
     *
     * OpenRouter requiere el parámetro "modalities": ["text", "image"] para generar imágenes
     * La respuesta viene en formato base64 data URL dentro del content o en el campo images
     *
     * @param string $prompt
     * @param array  $options
     * @return array|WP_Error
     */
    private function generate_image_via_chat($prompt, $options) {
        // Mapear tamaño de configuración al soportado por el modelo
        $options['size'] = $this->map_size_for_model($options['model'], $options['size']);

        // Primero intentamos con el endpoint /images/generations que es más confiable
        // para modelos GPT-5 image y gpt-image-1
        if ($this->is_openai_image_model($options['model'])) {
            $result = $this->generate_image_via_images_endpoint($prompt, $options);
            if (!is_wp_error($result)) {
                return $result;
            }
            // Si falla, continuamos con el método de chat
            error_log('[AICG] Images endpoint failed, trying chat endpoint: ' . $result->get_error_message());
        }

        // Obtener dimensiones del tamaño
        $size_parts = explode('x', $options['size']);
        $width = isset($size_parts[0]) ? intval($size_parts[0]) : 1024;
        $height = isset($size_parts[1]) ? intval($size_parts[1]) : 1024;

        // Agregar instrucciones de tamaño/aspecto al prompt
        $aspect_instruction = '';
        if ($width > $height) {
            $aspect_instruction = ' The image MUST be in landscape/horizontal format (wider than tall).';
        } elseif ($height > $width) {
            $aspect_instruction = ' The image MUST be in portrait/vertical format (taller than wide).';
        }

        // Formato requerido por OpenRouter para generación de imágenes via chat
        $body = array(
            'model' => $options['model'],
            'modalities' => array('text', 'image'),  // CLAVE: indica que queremos imagen
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => 'Generate an image based on this description: ' . $prompt . $aspect_instruction
                )
            )
        );

        // Agregar parámetros de imagen si el modelo los soporta
        if (!empty($options['size'])) {
            $body['image_generation'] = array(
                'size' => $options['size']
            );
        }

        error_log('[AICG] OpenRouter chat image request: ' . print_r($body, true));
        error_log('[AICG] Iniciando make_request para imagen... (esto puede tardar varios minutos)');
        $start_time = microtime(true);

        $response = $this->make_request('/chat/completions', $body);

        $elapsed = round(microtime(true) - $start_time, 2);
        error_log('[AICG] make_request completado en ' . $elapsed . ' segundos');

        if (is_wp_error($response)) {
            error_log('[AICG] OpenRouter chat image error: ' . $response->get_error_message());
            return $response;
        }

        error_log('[AICG] OpenRouter chat image response keys: ' . print_r(array_keys($response), true));

        // Buscar la imagen en la respuesta
        $image_url = $this->extract_image_from_response($response);

        if (!$image_url) {
            error_log('[AICG] OpenRouter full response: ' . print_r($response, true));
            return new WP_Error(
                'no_image_in_response',
                __('No se encontró una imagen en la respuesta del modelo. Verifica que el modelo soporta generación de imágenes.', 'ai-content-generator')
            );
        }

        $usage = isset($response['usage']) ? $response['usage'] : array('total_tokens' => 0);

        return array(
            'url' => $image_url,
            'model' => $options['model'],
            'cost' => $this->estimate_image_cost($options['model'], $usage)
        );
    }

    /**
     * Verificar si es un modelo de imagen de OpenAI
     *
     * @param string $model
     * @return bool
     */
    private function is_openai_image_model($model) {
        $openai_image_patterns = array(
            'gpt-5-image',
            'gpt-image-1',
            'gpt-4o-image',
        );

        foreach ($openai_image_patterns as $pattern) {
            if (strpos($model, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generar imagen usando el endpoint /images/generations
     *
     * Este endpoint es más confiable para modelos como gpt-image-1 y gpt-5-image
     *
     * @param string $prompt
     * @param array  $options
     * @return array|WP_Error
     */
    private function generate_image_via_images_endpoint($prompt, $options) {
        $body = array(
            'model' => $options['model'],
            'prompt' => $prompt,
            'n' => 1,
            'size' => isset($options['size']) ? $options['size'] : '1024x1024',
        );

        // gpt-image-1 y gpt-5-image siempre retornan b64_json
        // No necesitamos especificar response_format ya que no es soportado

        error_log('[AICG] OpenRouter images endpoint request: ' . print_r($body, true));

        $response = $this->make_request('/images/generations', $body);

        if (is_wp_error($response)) {
            return $response;
        }

        error_log('[AICG] OpenRouter images endpoint response keys: ' . print_r(array_keys($response), true));

        // Buscar en data[0].b64_json o data[0].url
        if (isset($response['data'][0]['b64_json'])) {
            error_log('[AICG] Image found in data[0].b64_json');
            $image_url = 'data:image/png;base64,' . $response['data'][0]['b64_json'];
        } elseif (isset($response['data'][0]['url'])) {
            error_log('[AICG] Image found in data[0].url');
            $image_url = $response['data'][0]['url'];
        } else {
            error_log('[AICG] No image in images endpoint response: ' . print_r($response, true));
            return new WP_Error('no_image', __('No se encontró imagen en la respuesta', 'ai-content-generator'));
        }

        return array(
            'url' => $image_url,
            'model' => $options['model'],
            'cost' => $this->estimate_image_cost($options['model'], array())
        );
    }

    /**
     * Extraer imagen de la respuesta de OpenRouter
     *
     * Las imágenes pueden venir en diferentes formatos según el modelo:
     * 1. choices[0].message.images[] - Formato OpenRouter estándar
     * 2. choices[0].message.content como array con type: "image_url"
     * 3. data[0].url o data[0].b64_json - Formato DALL-E tradicional
     * 4. Inline base64 en content string
     *
     * @param array $response
     * @return string|null
     */
    private function extract_image_from_response($response) {
        // 1. PRIORIDAD: Buscar en choices[0].message.images (formato OpenRouter estándar)
        // Formato: {"images": [{"type": "image_url", "image_url": {"url": "data:image/png;base64,..."}}]}
        if (isset($response['choices'][0]['message']['images']) && is_array($response['choices'][0]['message']['images'])) {
            $images = $response['choices'][0]['message']['images'];
            foreach ($images as $img) {
                // Formato con image_url objeto
                if (isset($img['type']) && $img['type'] === 'image_url' && isset($img['image_url']['url'])) {
                    error_log('[AICG] Image found in message.images[].image_url.url');
                    return $img['image_url']['url'];
                }
                // Formato directo con url
                if (isset($img['url'])) {
                    error_log('[AICG] Image found in message.images[].url');
                    return $img['url'];
                }
                // Formato con b64_json
                if (isset($img['b64_json'])) {
                    error_log('[AICG] Image found in message.images[].b64_json');
                    return 'data:image/png;base64,' . $img['b64_json'];
                }
                // Si es string directo (data URL o URL)
                if (is_string($img)) {
                    error_log('[AICG] Image found in message.images[] as string');
                    return $img;
                }
            }
        }

        // 2. Buscar en choices[0].message.content
        if (isset($response['choices'][0]['message']['content'])) {
            $content = $response['choices'][0]['message']['content'];

            // Si content es un array (formato multimodal)
            if (is_array($content)) {
                foreach ($content as $item) {
                    // Formato: {"type": "image_url", "image_url": {"url": "data:image/png;base64,..."}}
                    if (isset($item['type']) && $item['type'] === 'image_url' && isset($item['image_url']['url'])) {
                        error_log('[AICG] Image found in content[].image_url.url');
                        return $item['image_url']['url'];
                    }
                    // Formato: {"type": "image", "url": "..."}
                    if (isset($item['type']) && $item['type'] === 'image' && isset($item['url'])) {
                        error_log('[AICG] Image found in content[].url');
                        return $item['url'];
                    }
                    // Formato: {"type": "image", "image_url": {"url": "..."}}
                    if (isset($item['type']) && $item['type'] === 'image' && isset($item['image_url']['url'])) {
                        error_log('[AICG] Image found in content[].image_url.url (type=image)');
                        return $item['image_url']['url'];
                    }
                    // Formato con b64_json
                    if (isset($item['type']) && $item['type'] === 'image' && isset($item['b64_json'])) {
                        error_log('[AICG] Image found in content[].b64_json');
                        return 'data:image/png;base64,' . $item['b64_json'];
                    }
                    // Buscar base64 en cualquier campo de texto
                    if (isset($item['text']) && preg_match('/data:image\/[^;]+;base64,[A-Za-z0-9+\/=]+/', $item['text'], $matches)) {
                        error_log('[AICG] Image found as base64 in content[].text');
                        return $matches[0];
                    }
                }
            }
            // Si content es string, buscar patrones de imagen
            elseif (is_string($content) && !empty($content)) {
                // Buscar data URL base64
                if (preg_match('/data:image\/[^;]+;base64,[A-Za-z0-9+\/=]+/', $content, $matches)) {
                    error_log('[AICG] Image found as base64 in content string');
                    return $matches[0];
                }
                // Buscar URL directa de imagen
                if (preg_match('/(https?:\/\/[^\s\'"]+\.(png|jpg|jpeg|webp|gif))/i', $content, $matches)) {
                    error_log('[AICG] Image found as URL in content string');
                    return $matches[1];
                }
            }
        }

        // 3. Buscar en data[0] (formato OpenAI DALL-E tradicional)
        if (isset($response['data'][0]['url'])) {
            error_log('[AICG] Image found in data[0].url (DALL-E format)');
            return $response['data'][0]['url'];
        }
        if (isset($response['data'][0]['b64_json'])) {
            error_log('[AICG] Image found in data[0].b64_json (DALL-E format)');
            return 'data:image/png;base64,' . $response['data'][0]['b64_json'];
        }

        // 4. Log detallado de la estructura para debugging
        error_log('[AICG] No image found. Response structure analysis:');
        if (isset($response['choices'][0]['message'])) {
            $message = $response['choices'][0]['message'];
            error_log('[AICG] - message keys: ' . print_r(array_keys($message), true));
            if (isset($message['content'])) {
                $content_type = gettype($message['content']);
                $content_empty = empty($message['content']);
                error_log("[AICG] - content type: {$content_type}, empty: " . ($content_empty ? 'yes' : 'no'));
            }
            if (isset($message['images'])) {
                error_log('[AICG] - images present: ' . print_r($message['images'], true));
            }
        }

        return null;
    }

    /**
     * Estimar costo de generación de imagen
     *
     * @param string $model
     * @param array  $usage
     * @return float
     */
    private function estimate_image_cost($model, $usage = array()) {
        $image_models = $this->get_image_models();

        if (isset($image_models[$model]['cost_standard'])) {
            return $image_models[$model]['cost_standard'];
        }

        // Costos por defecto basados en el nombre del modelo
        if (strpos($model, 'gpt-5-image-mini') !== false) {
            return 0.008;
        }
        if (strpos($model, 'gpt-5-image') !== false) {
            return 0.04;
        }
        if (strpos($model, 'gpt-image-1') !== false) {
            return 0.02;
        }
        if (strpos($model, 'dall-e-3') !== false) {
            return 0.04;
        }
        if (strpos($model, 'free') !== false) {
            return 0;
        }

        return 0.04; // Costo por defecto
    }

    /**
     * Estimar costo
     *
     * @param int    $input_tokens
     * @param int    $output_tokens
     * @param string $model
     * @return float
     */
    public function estimate_cost($input_tokens, $output_tokens, $model) {
        $models = $this->get_available_models();

        if (!isset($models[$model])) {
            return 0;
        }

        $input_cost = ($input_tokens / 1000) * $models[$model]['input_cost'];
        $output_cost = ($output_tokens / 1000) * $models[$model]['output_cost'];

        return round($input_cost + $output_cost, 6);
    }

    /**
     * Obtener lista actualizada de modelos desde la API
     *
     * @return array|WP_Error
     */
    public function fetch_available_models() {
        $response = $this->make_request('/models', array(), 'GET');

        if (is_wp_error($response)) {
            return $response;
        }

        return isset($response['data']) ? $response['data'] : array();
    }
}
