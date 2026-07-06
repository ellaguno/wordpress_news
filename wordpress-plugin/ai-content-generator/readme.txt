=== AI Content Generator ===
Contributors: ellaguno
Tags: ai, content, generator, news, openai
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.10.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate articles and news summaries using multiple AI providers (OpenAI, Anthropic, DeepSeek, OpenRouter).

== Description ==

AI Content Generator is a WordPress plugin that automates content generation using artificial intelligence. It supports multiple AI providers and can generate both full articles and news summaries from RSS sources.

= Main Features =

* **Multiple AI providers:**
  * OpenAI (GPT-4o, GPT-3.5, DALL-E 3)
  * Anthropic (Claude Sonnet 4, Claude 3.5, Claude Haiku)
  * DeepSeek (DeepSeek Chat, DeepSeek Coder, DeepSeek R1)
  * OpenRouter (access to 100+ models)

* **Article generation:**
  * AI-generated, attention-grabbing titles
  * Structured HTML content
  * Images generated with DALL-E 3
  * Customizable watermark
  * Automatic category and tag assignment

* **News aggregator:**
  * Fetches news from Google News
  * AI-generated summaries
  * Automatic URL deduplication
  * References to original sources

* **Automatic scheduling:**
  * Scheduled article generation
  * Scheduled news generation
  * Configurable frequencies (hourly, daily, weekly)
  * Background processing with real progress (no request timeouts)

* **Admin panel:**
  * Dashboard with statistics and next scheduled runs
  * Generation history with success/error status
  * Token and cost tracking (including current-month cost)
  * Intuitive interface

== Installation ==

1. Upload the `ai-content-generator` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to 'AI Content' → 'Settings' to configure your API key.
4. Configure the article and news topics to fit your needs.

== Frequently Asked Questions ==

= How much does it cost to use this plugin? =

The plugin is free, but the AI APIs have costs. Costs vary by provider:

* OpenAI GPT-4o: ~$0.005/1K input tokens, ~$0.015/1K output tokens
* Anthropic Claude: ~$0.003/1K input tokens, ~$0.015/1K output tokens
* DeepSeek: ~$0.00014/1K tokens (very economical)

= Can I use multiple providers? =

Yes, you can configure all providers and switch between them as needed.

= Are articles published automatically? =

By default, articles are created as drafts. You can configure automatic publishing.

= Does it work with any WordPress theme? =

Yes, the plugin generates standard HTML compatible with any theme.

= Does scheduled generation require anything special? =

It relies on WP-Cron, which is enabled by default. Generation runs in the background and reports real progress; failures are recorded in the history and can be emailed to the admin.

== Screenshots ==

1. Main dashboard with statistics
2. Article generator
3. News generator
4. Settings page
5. Generation history

== Changelog ==

= 2.10.2 =
* WordPress Coding Standards / Plugin Check compliance: output escaping, wp_unslash on inputs, $wpdb->prepare and justified phpcs:ignore for custom-table queries
* Discouraged functions replaced with WordPress equivalents (wp_strip_all_tags, wp_parse_url, wp_delete_file, gmdate)
* i18n: translators comments and numbered placeholders
* The title_normalized column moved to the versioned schema (dbDelta, DB v3); removed runtime ALTER TABLE statements
* Removed discouraged load_plugin_textdomain() call (WordPress auto-loads translations since 4.6)
* readme translated to English

= 2.10.0 =
* Background generation: the page no longer blocks; the job is queued and runs via cron, avoiding timeouts on long generations
* Real progress bar (via job-status polling) instead of a simulated one
* Actionable dashboard: next scheduled runs, "Run now" button and current-month cost
* Model selector with autocomplete and a "Load models" button that queries the provider catalog
* Min/max word validation and unsaved-changes warning on the Settings page
* Fixed the "Test Connection" button label after testing; JS strings internationalized and escaped

= 2.9.0 =
* Security: KSES filters (data: URIs) scoped to the plugin content instead of weakening wp_kses site-wide
* Security: API keys are no longer printed in full in the HTML (write-only masked pattern)
* Scheduled-generation errors visible on the Dashboard, with a button to clear them
* Email notifications for scheduled generation (with an "errors only" mode)
* History also records failures (status column and error message) and can filter by them
* Debug logging gated behind WP_DEBUG; response dumps truncated
* Removed ~900 lines of dead code (Wikimedia regions/maps, unregistered REST, duplicated Settings API)
* Centralized option defaults (fixes divergences between activation and settings)

= 2.8.8 =
* The author selector is respected when generating articles and news
* The configured text model is honored by all 4 providers; history records the real model
* Fixed a potential fatal error when creating categories from cron
* wp_insert_post failures are no longer silent
* Lock against overlapping cron runs (avoids duplicate posts and spend)
* Anti-SSRF: destination validation and wp_safe_remote_get for feeds, og:image and image downloads

= 1.0.0 =
* Initial release
* Support for OpenAI, Anthropic, DeepSeek and OpenRouter
* Article generation with images
* RSS news aggregator
* Automatic scheduling with WP-Cron
* Complete admin panel

== Upgrade Notice ==

= 2.10.2 =
WordPress Plugin Check compliance and English readme. The history table migrates automatically.

= 2.10.0 =
Generation now runs in the background with real progress. Requires WP-Cron to be working on the site (enabled by default). Dashboard with "Run now" and monthly cost.

= 2.9.0 =
Security improvements (scoped KSES, masked API keys) and visibility of automatic-generation errors. The history table migrates automatically.

== Privacy Policy ==

This plugin sends data to external AI APIs (OpenAI, Anthropic, DeepSeek, OpenRouter) depending on the configured provider. Prompts and generated content are processed by those services. Please review each provider's privacy policy.

The plugin stores locally:

* Settings and API keys (in the WordPress options table; protect access to your database)
* Generation history
* Used news URLs

Note: the plugin's user interface is currently in Spanish.
