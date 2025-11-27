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
            'openai/dall-e-3' => array(
                'name' => 'DALL-E 3 (via OpenRouter)',
                'description' => 'OpenAI DALL-E 3 a través de OpenRouter',
                'sizes' => array('1024x1024', '1792x1024', '1024x1792'),
                'qualities' => array('standard', 'hd'),
                'cost_standard' => 0.04,
                'cost_hd' => 0.08
            ),
            'stability/stable-diffusion-xl' => array(
                'name' => 'Stable Diffusion XL',
                'description' => 'Stability AI SDXL',
                'sizes' => array('1024x1024'),
                'qualities' => array('standard'),
                'cost_standard' => 0.002
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
     * @param string $prompt
     * @param array  $options
     * @return array|WP_Error
     */
    public function generate_image($prompt, $options = array()) {
        $defaults = array(
            'model' => 'openai/dall-e-3',
            'size' => '1024x1024',
            'quality' => 'standard',
            'n' => 1
        );

        $options = wp_parse_args($options, $defaults);

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
            return $response;
        }

        if (!isset($response['data'][0]['url'])) {
            return new WP_Error('invalid_response', __('Respuesta inválida para generación de imagen', 'ai-content-generator'));
        }

        $models = $this->get_image_models();
        $cost = isset($models[$options['model']]['cost_' . $options['quality']])
            ? $models[$options['model']]['cost_' . $options['quality']]
            : 0.04;

        return array(
            'url' => $response['data'][0]['url'],
            'model' => $options['model'],
            'cost' => $cost
        );
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
