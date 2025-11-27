<?php
/**
 * Factory para crear proveedores de IA
 *
 * @package AI_Content_Generator
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase Factory para instanciar proveedores de IA
 */
class AICG_AI_Provider_Factory {

    /**
     * Proveedores disponibles
     *
     * @var array
     */
    private static $providers = array(
        'openai' => 'AICG_OpenAI_Provider',
        'anthropic' => 'AICG_Anthropic_Provider',
        'deepseek' => 'AICG_DeepSeek_Provider',
        'openrouter' => 'AICG_OpenRouter_Provider'
    );

    /**
     * Crear instancia de proveedor
     *
     * @param string $provider_name Nombre del proveedor
     * @return AICG_AI_Provider_Interface|WP_Error
     */
    public static function create($provider_name) {
        $provider_name = strtolower($provider_name);

        if (!isset(self::$providers[$provider_name])) {
            return new WP_Error(
                'invalid_provider',
                sprintf(
                    __('Proveedor de IA no válido: %s. Proveedores disponibles: %s', 'ai-content-generator'),
                    $provider_name,
                    implode(', ', array_keys(self::$providers))
                )
            );
        }

        $class_name = self::$providers[$provider_name];

        if (!class_exists($class_name)) {
            return new WP_Error(
                'class_not_found',
                sprintf(__('Clase del proveedor no encontrada: %s', 'ai-content-generator'), $class_name)
            );
        }

        return new $class_name();
    }

    /**
     * Obtener lista de proveedores disponibles
     *
     * @return array
     */
    public static function get_available_providers() {
        return array(
            'openai' => array(
                'name' => 'OpenAI',
                'description' => __('GPT-4, GPT-3.5 y DALL-E para generación de texto e imágenes', 'ai-content-generator'),
                'supports_images' => true,
                'website' => 'https://openai.com'
            ),
            'anthropic' => array(
                'name' => 'Anthropic',
                'description' => __('Claude - modelos de IA seguros y útiles', 'ai-content-generator'),
                'supports_images' => false,
                'website' => 'https://anthropic.com'
            ),
            'deepseek' => array(
                'name' => 'DeepSeek',
                'description' => __('Modelos de IA de alto rendimiento y bajo costo', 'ai-content-generator'),
                'supports_images' => false,
                'website' => 'https://deepseek.com'
            ),
            'openrouter' => array(
                'name' => 'OpenRouter',
                'description' => __('Acceso unificado a múltiples modelos de IA (GPT, Claude, Llama, Gemini, etc.)', 'ai-content-generator'),
                'supports_images' => true,
                'website' => 'https://openrouter.ai'
            )
        );
    }

    /**
     * Obtener proveedor configurado para texto
     *
     * @return AICG_AI_Provider_Interface|WP_Error
     */
    public static function get_text_provider() {
        $provider_name = get_option('aicg_ai_provider', 'openai');
        return self::create($provider_name);
    }

    /**
     * Obtener proveedor configurado para imágenes
     *
     * Algunos proveedores no soportan imágenes, así que fallback a OpenAI
     *
     * @return AICG_AI_Provider_Interface|WP_Error
     */
    public static function get_image_provider() {
        $provider_name = get_option('aicg_image_provider', 'openai');
        $provider = self::create($provider_name);

        if (is_wp_error($provider)) {
            return $provider;
        }

        // Si el proveedor no soporta imágenes, usar OpenAI
        $image_models = $provider->get_image_models();
        if (empty($image_models)) {
            return self::create('openai');
        }

        return $provider;
    }

    /**
     * Verificar si un proveedor está configurado
     *
     * @param string $provider_name
     * @return bool
     */
    public static function is_provider_configured($provider_name) {
        $provider = self::create($provider_name);

        if (is_wp_error($provider)) {
            return false;
        }

        return $provider->is_configured();
    }

    /**
     * Obtener todos los modelos disponibles de todos los proveedores configurados
     *
     * @return array
     */
    public static function get_all_available_models() {
        $all_models = array();

        foreach (array_keys(self::$providers) as $provider_name) {
            $provider = self::create($provider_name);

            if (is_wp_error($provider) || !$provider->is_configured()) {
                continue;
            }

            $models = $provider->get_available_models();
            foreach ($models as $model_id => $model_info) {
                $all_models[$provider_name . '/' . $model_id] = array_merge(
                    $model_info,
                    array('provider' => $provider_name)
                );
            }
        }

        return $all_models;
    }
}
