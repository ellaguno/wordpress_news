<?php
/**
 * Proveedor OpenAI
 *
 * @package AI_Content_Generator
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Implementación del proveedor OpenAI
 */
class AICG_OpenAI_Provider extends AICG_AI_Provider_Base {

    /**
     * Constructor
     */
    public function __construct() {
        $api_key = get_option('aicg_openai_api_key', '');
        parent::__construct($api_key);
        $this->api_base_url = 'https://api.openai.com/v1';
    }

    /**
     * Obtener nombre del proveedor
     *
     * @return string
     */
    public function get_name() {
        return 'openai';
    }

    /**
     * Obtener modelos disponibles
     *
     * @return array
     */
    public function get_available_models() {
        return array(
            'gpt-4o' => array(
                'name' => 'GPT-4o',
                'description' => 'Modelo más capaz y eficiente',
                'max_tokens' => 128000,
                'input_cost' => 0.005,  // por 1K tokens
                'output_cost' => 0.015
            ),
            'gpt-4o-mini' => array(
                'name' => 'GPT-4o Mini',
                'description' => 'Versión económica de GPT-4o',
                'max_tokens' => 128000,
                'input_cost' => 0.00015,
                'output_cost' => 0.0006
            ),
            'gpt-4-turbo' => array(
                'name' => 'GPT-4 Turbo',
                'description' => 'GPT-4 optimizado para velocidad',
                'max_tokens' => 128000,
                'input_cost' => 0.01,
                'output_cost' => 0.03
            ),
            'gpt-3.5-turbo' => array(
                'name' => 'GPT-3.5 Turbo',
                'description' => 'Modelo rápido y económico',
                'max_tokens' => 16385,
                'input_cost' => 0.0005,
                'output_cost' => 0.0015
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
            'dall-e-3' => array(
                'name' => 'DALL-E 3',
                'description' => 'Generación de imágenes de alta calidad',
                'sizes' => array('1024x1024', '1792x1024', '1024x1792'),
                'qualities' => array('standard', 'hd'),
                'cost_standard' => 0.04,
                'cost_hd' => 0.08
            ),
            'dall-e-2' => array(
                'name' => 'DALL-E 2',
                'description' => 'Generación de imágenes económica',
                'sizes' => array('256x256', '512x512', '1024x1024'),
                'qualities' => array('standard'),
                'cost_standard' => 0.02
            )
        );
    }

    /**
     * Modelos que requieren max_completion_tokens en lugar de max_tokens
     *
     * @var array
     */
    private $new_api_models = array(
        'gpt-4o',
        'gpt-4o-mini',
        'gpt-4o-2024-05-13',
        'gpt-4o-2024-08-06',
        'gpt-4o-2024-11-20',
        'gpt-4o-mini-2024-07-18',
        'o1-preview',
        'o1-mini',
        'chatgpt-4o-latest'
    );

    /**
     * Generar texto
     *
     * @param string $prompt
     * @param array  $options
     * @return array|WP_Error
     */
    public function generate_text($prompt, $options = array()) {
        $defaults = array(
            'model' => get_option('aicg_default_model', 'gpt-4o'),
            'temperature' => 0.7,
            'max_tokens' => 4000,
            'presence_penalty' => 0.1,
            'frequency_penalty' => 0.1,
            'system_message' => 'Eres un escritor experto que genera contenido de alta calidad en español.'
        );

        $options = wp_parse_args($options, $defaults);

        // Determinar si usar max_completion_tokens o max_tokens según el modelo
        $use_new_api = $this->uses_new_api($options['model']);

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
            'presence_penalty' => (float) $options['presence_penalty'],
            'frequency_penalty' => (float) $options['frequency_penalty']
        );

        // Usar el parámetro correcto según el modelo
        if ($use_new_api) {
            $body['max_completion_tokens'] = (int) $options['max_tokens'];
        } else {
            $body['max_tokens'] = (int) $options['max_tokens'];
        }

        $response = $this->make_request('/chat/completions', $body);

        if (is_wp_error($response)) {
            return $response;
        }

        if (!isset($response['choices'][0]['message']['content'])) {
            return new WP_Error('invalid_response', __('Respuesta inválida de OpenAI', 'ai-content-generator'));
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
            'model' => get_option('aicg_image_model', 'dall-e-3'),
            'size' => '1024x1024',
            'quality' => 'standard',
            'style' => 'natural',
            'n' => 1
        );

        $options = wp_parse_args($options, $defaults);

        $body = array(
            'model' => $options['model'],
            'prompt' => $prompt,
            'n' => $options['n'],
            'size' => $options['size']
        );

        // DALL-E 3 soporta quality y style
        if ($options['model'] === 'dall-e-3') {
            $body['quality'] = $options['quality'];
            $body['style'] = $options['style'];
        }

        $response = $this->make_request('/images/generations', $body);

        if (is_wp_error($response)) {
            return $response;
        }

        if (!isset($response['data'][0]['url'])) {
            return new WP_Error('invalid_response', __('Respuesta inválida de DALL-E', 'ai-content-generator'));
        }

        $models = $this->get_image_models();
        $cost_key = 'cost_' . $options['quality'];
        $cost = isset($models[$options['model']][$cost_key])
            ? $models[$options['model']][$cost_key]
            : 0.04;

        return array(
            'url' => $response['data'][0]['url'],
            'revised_prompt' => isset($response['data'][0]['revised_prompt'])
                ? $response['data'][0]['revised_prompt']
                : $prompt,
            'model' => $options['model'],
            'cost' => $cost
        );
    }

    /**
     * Verificar si el modelo usa la nueva API (max_completion_tokens)
     *
     * @param string $model
     * @return bool
     */
    private function uses_new_api($model) {
        // Verificar coincidencia exacta
        if (in_array($model, $this->new_api_models, true)) {
            return true;
        }

        // Verificar si empieza con alguno de los prefijos de modelos nuevos
        $new_prefixes = array('gpt-4o', 'o1-', 'chatgpt-4o');
        foreach ($new_prefixes as $prefix) {
            if (strpos($model, $prefix) === 0) {
                return true;
            }
        }

        return false;
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
