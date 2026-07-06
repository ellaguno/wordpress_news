=== AI Content Generator ===
Contributors: ellaguno
Tags: ai, content, generator, openai, anthropic, deepseek, openrouter, articles, news
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Genera artículos y resúmenes de noticias usando múltiples proveedores de IA (OpenAI, Anthropic, DeepSeek, OpenRouter).

== Description ==

AI Content Generator es un plugin de WordPress que automatiza la generación de contenido utilizando inteligencia artificial. Soporta múltiples proveedores de IA y puede generar tanto artículos completos como resúmenes de noticias a partir de fuentes RSS.

= Características Principales =

* **Múltiples Proveedores de IA:**
  * OpenAI (GPT-4o, GPT-3.5, DALL-E 3)
  * Anthropic (Claude Sonnet 4, Claude 3.5, Claude Haiku)
  * DeepSeek (DeepSeek Chat, DeepSeek Coder, DeepSeek R1)
  * OpenRouter (Acceso a +100 modelos)

* **Generación de Artículos:**
  * Títulos atractivos generados por IA
  * Contenido HTML estructurado
  * Imágenes generadas con DALL-E 3
  * Marca de agua personalizable
  * Asignación automática de categorías y tags

* **Agregador de Noticias:**
  * Obtención de noticias desde Google News
  * Resúmenes generados por IA
  * Deduplicación automática de URLs
  * Referencias a fuentes originales

* **Programación Automática:**
  * Generación programada de artículos
  * Generación programada de noticias
  * Frecuencias configurables (horaria, diaria, semanal)

* **Panel de Administración:**
  * Dashboard con estadísticas
  * Historial de generaciones
  * Seguimiento de tokens y costos
  * Interfaz intuitiva

== Installation ==

1. Sube la carpeta `ai-content-generator` al directorio `/wp-content/plugins/`
2. Activa el plugin a través del menú 'Plugins' en WordPress
3. Ve a 'AI Content' → 'Configuración' para configurar tu API Key
4. Configura los temas de artículos y noticias según tus necesidades

== Configuration ==

= API Keys =

Necesitas al menos una API Key de cualquiera de los proveedores soportados:

* **OpenAI:** https://platform.openai.com/api-keys
* **Anthropic:** https://console.anthropic.com/settings/keys
* **DeepSeek:** https://platform.deepseek.com/api_keys
* **OpenRouter:** https://openrouter.ai/keys

= Temas de Artículos =

Configura una lista de temas (uno por línea) que se usarán para generar artículos. Ejemplos:
* Inteligencia Artificial
* Desarrollo Web
* Ciberseguridad
* Marketing Digital

= Temas de Noticias =

Configura los temas para el agregador de noticias con nombre e imagen opcional.

== Frequently Asked Questions ==

= ¿Cuánto cuesta usar este plugin? =

El plugin es gratuito, pero las APIs de IA tienen costos. Los costos varían según el proveedor:
* OpenAI GPT-4o: ~$0.005/1K tokens entrada, ~$0.015/1K tokens salida
* Anthropic Claude: ~$0.003/1K tokens entrada, ~$0.015/1K tokens salida
* DeepSeek: ~$0.00014/1K tokens (muy económico)

= ¿Puedo usar múltiples proveedores? =

Sí, puedes configurar todos los proveedores y cambiar entre ellos según necesites.

= ¿Los artículos se publican automáticamente? =

Por defecto, los artículos se crean como borradores. Puedes configurar que se publiquen automáticamente.

= ¿Funciona con cualquier tema de WordPress? =

Sí, el plugin genera HTML estándar compatible con cualquier tema.

== Screenshots ==

1. Dashboard principal con estadísticas
2. Generador de artículos
3. Generador de noticias
4. Página de configuración
5. Historial de generaciones

== Changelog ==

= 2.9.0 =
* Seguridad: filtros KSES (data: URIs) acotados al contenido del plugin, ya no se debilitan globalmente
* Seguridad: las API keys ya no se imprimen completas en el HTML (patrón write-only con máscara)
* Errores de la generación programada visibles en el Dashboard, con botón para limpiarlos
* Notificaciones por email de la generación programada (con modo "solo errores")
* El historial registra también los fallos (columna de estado y mensaje de error) y permite filtrarlos
* Logging de depuración condicionado a WP_DEBUG; los volcados de respuestas se truncan
* Eliminadas ~900 líneas de código muerto (regiones/mapas de Wikimedia, REST no registrado, Settings API duplicada)
* Defaults de opciones centralizados (corrige divergencias entre activación y configuración)

= 2.8.8 =
* El selector de autor se respeta al generar artículos y noticias
* El modelo de texto configurado lo respetan los 4 proveedores; el historial registra el modelo real
* Corregido fatal error potencial al crear categorías desde cron
* Los fallos de wp_insert_post ya no pasan en silencio
* Lock contra ejecuciones de cron solapadas (evita posts y gasto duplicados)
* Anti-SSRF: validación de destinos y wp_safe_remote_get en feeds, og:image y descargas de imágenes

= 1.0.0 =
* Versión inicial
* Soporte para OpenAI, Anthropic, DeepSeek y OpenRouter
* Generación de artículos con imágenes
* Agregador de noticias RSS
* Programación automática con WP-Cron
* Panel de administración completo

== Upgrade Notice ==

= 2.9.0 =
Mejoras de seguridad (KSES acotado, API keys enmascaradas) y visibilidad de errores de la generación automática. La tabla de historial se migra automáticamente.

== Privacy Policy ==

Este plugin envía datos a APIs externas de IA (OpenAI, Anthropic, DeepSeek, OpenRouter) según el proveedor configurado. Los prompts y contenido generado son procesados por estos servicios. Consulta las políticas de privacidad de cada proveedor.

El plugin almacena localmente:
* Configuración y API Keys (en la tabla de opciones de WordPress; protege el acceso a tu base de datos)
* Historial de generaciones
* URLs de noticias usadas
