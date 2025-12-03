<?php
/**
 * Interface para proveedores de IA
 *
 * @package AI_Content_Generator
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface que deben implementar todos los proveedores de IA
 */
interface AICG_AI_Provider_Interface {

    /**
     * Obtener el nombre del proveedor
     *
     * @return string
     */
    public function get_name();

    /**
     * Obtener los modelos disponibles
     *
     * @return array
     */
    public function get_available_models();

    /**
     * Obtener los modelos de imagen disponibles
     *
     * @return array
     */
    public function get_image_models();

    /**
     * Generar texto
     *
     * @param string $prompt El prompt para generar texto
     * @param array  $options Opciones adicionales (model, temperature, max_tokens, etc.)
     * @return array|WP_Error Array con 'content' y 'usage', o WP_Error en caso de error
     */
    public function generate_text($prompt, $options = array());

    /**
     * Generar imagen
     *
     * @param string $prompt Descripción de la imagen
     * @param array  $options Opciones (size, quality, style)
     * @return array|WP_Error Array con 'url' de la imagen, o WP_Error
     */
    public function generate_image($prompt, $options = array());

    /**
     * Verificar si el proveedor está configurado correctamente
     *
     * @return bool
     */
    public function is_configured();

    /**
     * Probar la conexión con el proveedor
     *
     * @return array|WP_Error Array con info de la prueba, o WP_Error
     */
    public function test_connection();

    /**
     * Obtener el costo estimado por tokens
     *
     * @param int    $input_tokens Tokens de entrada
     * @param int    $output_tokens Tokens de salida
     * @param string $model Modelo usado
     * @return float Costo estimado en USD
     */
    public function estimate_cost($input_tokens, $output_tokens, $model);
}

/**
 * Clase base abstracta para proveedores de IA
 */
abstract class AICG_AI_Provider_Base implements AICG_AI_Provider_Interface {

    /**
     * API Key del proveedor
     *
     * @var string
     */
    protected $api_key;

    /**
     * URL base de la API
     *
     * @var string
     */
    protected $api_base_url;

    /**
     * Timeout para requests HTTP
     *
     * @var int
     */
    protected $timeout = 120;

    /**
     * Constructor
     *
     * @param string $api_key API Key del proveedor
     */
    public function __construct($api_key = '') {
        $this->api_key = $api_key;
    }

    /**
     * Realizar petición HTTP a la API
     *
     * @param string $endpoint Endpoint de la API
     * @param array  $body Cuerpo de la petición
     * @param string $method Método HTTP
     * @return array|WP_Error
     */
    protected function make_request($endpoint, $body = array(), $method = 'POST') {
        $url = $this->api_base_url . $endpoint;

        // Log del timeout que se está usando
        if (strpos($endpoint, 'image') !== false || strpos($endpoint, 'chat') !== false) {
            error_log('[AICG] make_request a ' . $endpoint . ' con timeout=' . $this->timeout . 's');
        }

        $args = array(
            'method'  => $method,
            'timeout' => $this->timeout,
            'headers' => $this->get_headers(),
            'body'    => $method === 'POST' ? wp_json_encode($body) : $body,
        );

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status_code >= 400) {
            $error_message = isset($data['error']['message'])
                ? $data['error']['message']
                : sprintf(__('Error HTTP %d', 'ai-content-generator'), $status_code);

            return new WP_Error('api_error', $error_message, array('status' => $status_code));
        }

        return $data;
    }

    /**
     * Obtener headers para la petición
     *
     * @return array
     */
    protected function get_headers() {
        return array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $this->api_key,
        );
    }

    /**
     * Verificar si está configurado
     *
     * @return bool
     */
    public function is_configured() {
        return !empty($this->api_key);
    }

    /**
     * Probar conexión
     *
     * @return array|WP_Error
     */
    public function test_connection() {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('API Key no configurada', 'ai-content-generator'));
        }

        $result = $this->generate_text('Di "Hola, la conexión funciona correctamente." en una sola línea.', array(
            'max_tokens' => 50,
            'temperature' => 0
        ));

        if (is_wp_error($result)) {
            return $result;
        }

        return array(
            'success' => true,
            'message' => $result['content'],
            'provider' => $this->get_name()
        );
    }

    /**
     * Sanitizar prompt para logging
     *
     * @param string $prompt
     * @return string
     */
    protected function sanitize_for_log($prompt) {
        return substr(sanitize_text_field($prompt), 0, 100) . '...';
    }

    /**
     * Registrar uso en la base de datos
     *
     * @param string $type Tipo de generación
     * @param int    $post_id ID del post
     * @param string $model Modelo usado
     * @param string $topic Tema
     * @param int    $tokens Tokens usados
     * @param float  $cost Costo estimado
     */
    protected function log_usage($type, $post_id, $model, $topic, $tokens = 0, $cost = 0) {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'aicg_history',
            array(
                'type'        => $type,
                'post_id'     => $post_id,
                'provider'    => $this->get_name(),
                'model'       => $model,
                'topic'       => $topic,
                'tokens_used' => $tokens,
                'cost'        => $cost,
                'created_at'  => current_time('mysql')
            ),
            array('%s', '%d', '%s', '%s', '%s', '%d', '%f', '%s')
        );
    }
}
