<?php
/**
 * Proveedor DeepSeek
 *
 * @package AI_Content_Generator
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Implementación del proveedor DeepSeek
 */
class AICG_DeepSeek_Provider extends AICG_AI_Provider_Base {

    /**
     * Constructor
     */
    public function __construct() {
        $api_key = get_option('aicg_deepseek_api_key', '');
        parent::__construct($api_key);
        $this->api_base_url = 'https://api.deepseek.com/v1';
    }

    /**
     * Obtener nombre del proveedor
     *
     * @return string
     */
    public function get_name() {
        return 'deepseek';
    }

    /**
     * Obtener modelos disponibles
     *
     * @return array
     */
    public function get_available_models() {
        return array(
            'deepseek-chat' => array(
                'name' => 'DeepSeek Chat',
                'description' => 'Modelo conversacional de propósito general',
                'max_tokens' => 64000,
                'input_cost' => 0.00014,  // $0.14 por 1M tokens
                'output_cost' => 0.00028  // $0.28 por 1M tokens
            ),
            'deepseek-coder' => array(
                'name' => 'DeepSeek Coder',
                'description' => 'Especializado en código y programación',
                'max_tokens' => 64000,
                'input_cost' => 0.00014,
                'output_cost' => 0.00028
            ),
            'deepseek-reasoner' => array(
                'name' => 'DeepSeek Reasoner (R1)',
                'description' => 'Modelo de razonamiento avanzado',
                'max_tokens' => 64000,
                'input_cost' => 0.00055,
                'output_cost' => 0.00219
            )
        );
    }

    /**
     * Obtener modelos de imagen
     *
     * @return array
     */
    public function get_image_models() {
        // DeepSeek no tiene generación de imágenes
        return array();
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
            'model' => 'deepseek-chat',
            'temperature' => 0.7,
            'max_tokens' => 4000,
            'presence_penalty' => 0,
            'frequency_penalty' => 0,
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
            'max_tokens' => (int) $options['max_tokens'],
            'presence_penalty' => (float) $options['presence_penalty'],
            'frequency_penalty' => (float) $options['frequency_penalty'],
            'stream' => false
        );

        $response = $this->make_request('/chat/completions', $body);

        if (is_wp_error($response)) {
            return $response;
        }

        if (!isset($response['choices'][0]['message']['content'])) {
            return new WP_Error('invalid_response', __('Respuesta inválida de DeepSeek', 'ai-content-generator'));
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
     * Generar imagen (no soportado)
     *
     * @param string $prompt
     * @param array  $options
     * @return WP_Error
     */
    public function generate_image($prompt, $options = array()) {
        return new WP_Error(
            'not_supported',
            __('DeepSeek no soporta generación de imágenes. Use OpenAI DALL-E u otro proveedor.', 'ai-content-generator')
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
}
