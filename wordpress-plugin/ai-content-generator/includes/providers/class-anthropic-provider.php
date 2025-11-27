<?php
/**
 * Proveedor Anthropic (Claude)
 *
 * @package AI_Content_Generator
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Implementación del proveedor Anthropic
 */
class AICG_Anthropic_Provider extends AICG_AI_Provider_Base {

    /**
     * Versión de la API
     *
     * @var string
     */
    private $api_version = '2023-06-01';

    /**
     * Constructor
     */
    public function __construct() {
        $api_key = get_option('aicg_anthropic_api_key', '');
        parent::__construct($api_key);
        $this->api_base_url = 'https://api.anthropic.com/v1';
    }

    /**
     * Obtener nombre del proveedor
     *
     * @return string
     */
    public function get_name() {
        return 'anthropic';
    }

    /**
     * Obtener headers personalizados para Anthropic
     *
     * @return array
     */
    protected function get_headers() {
        return array(
            'Content-Type' => 'application/json',
            'x-api-key' => $this->api_key,
            'anthropic-version' => $this->api_version
        );
    }

    /**
     * Obtener modelos disponibles
     *
     * @return array
     */
    public function get_available_models() {
        return array(
            'claude-sonnet-4-20250514' => array(
                'name' => 'Claude Sonnet 4',
                'description' => 'El modelo más reciente y equilibrado de Anthropic',
                'max_tokens' => 200000,
                'input_cost' => 0.003,
                'output_cost' => 0.015
            ),
            'claude-3-5-sonnet-20241022' => array(
                'name' => 'Claude 3.5 Sonnet',
                'description' => 'Excelente balance entre capacidad y velocidad',
                'max_tokens' => 200000,
                'input_cost' => 0.003,
                'output_cost' => 0.015
            ),
            'claude-3-5-haiku-20241022' => array(
                'name' => 'Claude 3.5 Haiku',
                'description' => 'Modelo rápido y económico',
                'max_tokens' => 200000,
                'input_cost' => 0.001,
                'output_cost' => 0.005
            ),
            'claude-3-opus-20240229' => array(
                'name' => 'Claude 3 Opus',
                'description' => 'Modelo más potente para tareas complejas',
                'max_tokens' => 200000,
                'input_cost' => 0.015,
                'output_cost' => 0.075
            ),
            'claude-3-haiku-20240307' => array(
                'name' => 'Claude 3 Haiku',
                'description' => 'El más rápido y económico',
                'max_tokens' => 200000,
                'input_cost' => 0.00025,
                'output_cost' => 0.00125
            )
        );
    }

    /**
     * Obtener modelos de imagen
     *
     * @return array
     */
    public function get_image_models() {
        // Anthropic no tiene generación de imágenes nativa
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
            'model' => 'claude-sonnet-4-20250514',
            'temperature' => 0.7,
            'max_tokens' => 4000,
            'system_message' => 'Eres un escritor experto que genera contenido de alta calidad en español.'
        );

        $options = wp_parse_args($options, $defaults);

        $body = array(
            'model' => $options['model'],
            'max_tokens' => (int) $options['max_tokens'],
            'temperature' => (float) $options['temperature'],
            'system' => $options['system_message'],
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            )
        );

        $response = $this->make_request('/messages', $body);

        if (is_wp_error($response)) {
            return $response;
        }

        if (!isset($response['content'][0]['text'])) {
            return new WP_Error('invalid_response', __('Respuesta inválida de Anthropic', 'ai-content-generator'));
        }

        $usage = isset($response['usage']) ? array(
            'prompt_tokens' => $response['usage']['input_tokens'],
            'completion_tokens' => $response['usage']['output_tokens'],
            'total_tokens' => $response['usage']['input_tokens'] + $response['usage']['output_tokens']
        ) : array(
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0
        );

        return array(
            'content' => $response['content'][0]['text'],
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
            __('Anthropic no soporta generación de imágenes. Use OpenAI DALL-E u otro proveedor.', 'ai-content-generator')
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
