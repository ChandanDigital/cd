<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi;

use ChandanDigital\NvidiaAi\Admin\AdminPage;
use ChandanDigital\NvidiaAi\Api\NvidiaClient;
use ChandanDigital\NvidiaAi\Provider\NvidiaProvider;
use ChandanDigital\NvidiaAi\Rest\RestController;
use ChandanDigital\NvidiaAi\Support\Logger;
use ChandanDigital\NvidiaAi\Support\ModelRegistry;
use ChandanDigital\NvidiaAi\Support\Settings;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;

/**
 * Wires the plugin into WordPress.
 *
 * @since 1.1.0
 */
final class Plugin
{
    public const VERSION_OPTION = 'cdnv_version';
    public const REFRESH_EVENT = 'cdnv_refresh_models';
    public const PROVIDER_ID = 'nvidia';

    /** AJAX action name used to dismiss the missing-client notice. */
    private const DISMISS_NOTICE_ACTION = 'cdnv_dismiss_notice';

    /** User meta key storing the per-user dismissal of the missing-client notice. */
    public const DISMISS_NOTICE_META = 'cdnv_notice_dismissed';

    /** Original plugin, which registers the same "nvidia" provider ID. */
    private const ORIGINAL_PLUGIN = 'ai-provider-for-nvidia/ai-provider-for-nvidia.php';

    /** Where the AI Client's NVIDIA key came from on this request, for the Overview tab. */
    private static string $aiClientKey = 'none';

    private static bool $booted = false;

    /**
     * Registers hooks. Safe to call more than once.
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        add_action('init', [self::class, 'load_textdomain'], 1);
        add_action('init', [self::class, 'maybe_upgrade'], 2);
        add_action('init', [self::class, 'register_provider'], 5);
        // After WordPress core binds Settings > Connectors keys at priority 20.
        add_action('init', [self::class, 'bind_ai_client_key'], 30);

        add_action('http_api_curl', [NvidiaClient::class, 'configure_curl'], 10, 2);
        add_action(self::REFRESH_EVENT, [self::class, 'scheduled_refresh']);

        add_filter('auto_update_plugin', [self::class, 'disable_auto_update'], 20, 2);
        add_filter('plugin_auto_update_setting_html', [self::class, 'auto_update_setting_html'], 20, 2);
        add_filter('plugin_action_links_' . plugin_basename(PLUGIN_FILE), [self::class, 'action_links']);

        add_action('admin_notices', [self::class, 'admin_notices']);
        add_action('wp_ajax_' . self::DISMISS_NOTICE_ACTION, [self::class, 'dismiss_missing_client_notice']);

        AdminPage::init();
        add_action('rest_api_init', [RestController::class, 'register_routes']);
    }

    /**
     * Activation: add default settings without touching any existing values.
     */
    public static function activate(): void
    {
        // add_option() never overwrites, so reinstalling or updating keeps every saved setting and key.
        add_option(Settings::OPTION, Settings::defaults());
        update_option(self::VERSION_OPTION, VERSION);
        self::sync_schedule();
    }

    /**
     * Deactivation: stop the optional scheduled refresh. Settings are kept.
     */
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::REFRESH_EVENT);
    }

    /**
     * Loads translations from the plugin's languages folder.
     */
    public static function load_textdomain(): void
    {
        load_plugin_textdomain(TEXT_DOMAIN, false, dirname(plugin_basename(PLUGIN_FILE)) . '/languages');
    }

    /**
     * Runs light upgrade steps after a manual ZIP update (activation hooks do not run then).
     */
    public static function maybe_upgrade(): void
    {
        if (get_option(self::VERSION_OPTION) === VERSION) {
            return;
        }
        add_option(Settings::OPTION, Settings::defaults());
        update_option(self::VERSION_OPTION, VERSION);
        self::sync_schedule();
    }

    /**
     * Registers the NVIDIA provider with the WordPress AI Client, as the original plugin did.
     */
    public static function register_provider(): void
    {
        if (!class_exists(AiClient::class)) {
            return;
        }

        $registry = AiClient::defaultRegistry();

        if ($registry->hasProvider(self::PROVIDER_ID)) {
            // Either already registered by this plugin, or by another NVIDIA provider plugin.
            return;
        }

        $registry->registerProvider(NvidiaProvider::class);
    }

    /**
     * Whether this plugin's provider class is the one registered for "nvidia".
     */
    public static function provider_is_ours(): bool
    {
        if (!class_exists(AiClient::class)) {
            return false;
        }
        try {
            $registry = AiClient::defaultRegistry();
            return $registry->hasProvider(self::PROVIDER_ID)
                && $registry->getProviderClassName(self::PROVIDER_ID) === NvidiaProvider::class;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether another plugin registered a different provider class under "nvidia".
     */
    public static function provider_conflict(): bool
    {
        if (!class_exists(AiClient::class)) {
            return false;
        }
        try {
            $registry = AiClient::defaultRegistry();
            return $registry->hasProvider(self::PROVIDER_ID) && !self::provider_is_ours();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Gives the WordPress AI Client this plugin's API key, following the administrator's choice:
     *
     * - fallback (default): only when WordPress has no NVIDIA key of its own (Settings > Connectors,
     *   or an NVIDIA_API_KEY environment variable or constant), so an existing key is never replaced;
     * - always: this plugin's key wins;
     * - never: the AI Client only uses keys configured in WordPress itself.
     */
    public static function bind_ai_client_key(): void
    {
        if (!self::provider_is_ours()) {
            return;
        }
        $registry = AiClient::defaultRegistry();
        $existing = $registry->getProviderRequestAuthentication(self::PROVIDER_ID);
        self::$aiClientKey = $existing !== null ? 'wordpress' : 'none';

        $mode = (string) Settings::get('ai_client_key_mode');
        $source = Settings::key_source();
        if ($mode === 'never' || !in_array($source, ['constant', 'plugin'], true)) {
            return;
        }
        if ($mode === 'fallback' && $existing !== null) {
            return;
        }
        $registry->setProviderRequestAuthentication(self::PROVIDER_ID, new ApiKeyRequestAuthentication(Settings::api_key()));
        self::$aiClientKey = $source;
    }

    /**
     * Where the AI Client's NVIDIA key comes from on this request: constant, plugin, wordpress or none.
     */
    public static function ai_client_key_source(): string
    {
        return self::$aiClientKey;
    }

    /**
     * Prevents WordPress from ever auto-updating this plugin. Other plugins and core are untouched.
     *
     * @param bool|null $update Whether to update.
     * @param object $item Update offer.
     * @return bool|null
     */
    public static function disable_auto_update($update, $item)
    {
        if (is_object($item) && isset($item->plugin) && $item->plugin === plugin_basename(PLUGIN_FILE)) {
            return false;
        }
        return $update;
    }

    /**
     * Replaces the auto-update toggle on the Plugins screen with a short explanation.
     *
     * @param string $html Toggle HTML.
     * @param string $pluginFile Plugin basename.
     */
    public static function auto_update_setting_html($html, $pluginFile): string
    {
        if ($pluginFile === plugin_basename(PLUGIN_FILE)) {
            return esc_html__('Manual updates only (upload a new ZIP).', 'chandan-digital-ai-for-nvidia');
        }
        return (string) $html;
    }

    /**
     * Adds a Settings link on the Plugins screen.
     *
     * @param array<int|string, string> $links Action links.
     * @return array<int|string, string>
     */
    public static function action_links(array $links): array
    {
        if (current_user_can('manage_options')) {
            array_unshift($links, sprintf(
                '<a href="%s">%s</a>',
                esc_url(AdminPage::url()),
                esc_html__('Settings', 'chandan-digital-ai-for-nvidia')
            ));
        }
        return $links;
    }

    /**
     * Schedules or removes the optional daily catalogue refresh, following the setting (off by default).
     */
    public static function sync_schedule(): void
    {
        $scheduled = wp_next_scheduled(self::REFRESH_EVENT);
        if (Settings::get('auto_refresh_models')) {
            if (!$scheduled) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::REFRESH_EVENT);
            }
        } elseif ($scheduled) {
            wp_clear_scheduled_hook(self::REFRESH_EVENT);
        }
    }

    /**
     * Daily catalogue refresh, only when the administrator turned it on.
     */
    public static function scheduled_refresh(): void
    {
        if (!Settings::get('auto_refresh_models')) {
            wp_clear_scheduled_hook(self::REFRESH_EVENT);
            return;
        }
        $client = NvidiaClient::create();
        if (!$client instanceof NvidiaClient) {
            return;
        }
        $result = $client->list_models();
        if ($result['ok']) {
            ModelRegistry::save_catalog($result['ids']);
        }
        Logger::log([
            'type' => 'refresh_models',
            'status' => $result['status'],
            'duration_ms' => $result['duration_ms'],
            'error_code' => $result['error'] !== null ? $result['error']->code : '',
            'error' => $result['error'] !== null ? $result['error']->summary() : '',
        ]);
    }

    /**
     * Admin notices: missing AI Client (dismissible, Dashboard and Plugins screens only) and a
     * provider conflict with the original plugin.
     */
    public static function admin_notices(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $screenId = $screen ? $screen->id : '';
        $ourScreen = strpos($screenId, AdminPage::SLUG) !== false;

        if (self::provider_conflict() && ($ourScreen || in_array($screenId, ['dashboard', 'plugins'], true))) {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html(self::is_active(self::ORIGINAL_PLUGIN)
                    ? __('Chandan Digital AI for NVIDIA: the original "AI Provider for NVIDIA" plugin is also active and has registered the "nvidia" provider first. Deactivate it so Kimi K3 and your dashboard settings reach the WordPress AI Client. Your Playground still works.', 'chandan-digital-ai-for-nvidia')
                    : __('Chandan Digital AI for NVIDIA: another plugin has already registered the "nvidia" AI provider, so this plugin\'s provider was not registered. Deactivate the other NVIDIA provider plugin.', 'chandan-digital-ai-for-nvidia'))
            );
        }

        if (class_exists(AiClient::class) || !in_array($screenId, ['dashboard', 'plugins'], true)) {
            return;
        }
        // Respect a previous per-user dismissal.
        if (get_user_meta(get_current_user_id(), self::DISMISS_NOTICE_META, true)) {
            return;
        }
        printf(
            '<div id="cdnv-missing-client-notice" class="notice notice-warning is-dismissible" data-nonce="%s"><p>%s</p></div>',
            esc_attr(wp_create_nonce(self::DISMISS_NOTICE_ACTION)),
            esc_html__('Chandan Digital AI for NVIDIA: the WordPress AI Client (bundled with WordPress 7.0 and later) is not available, so other plugins cannot use NVIDIA models yet. The plugin\'s own dashboard and Playground still work.', 'chandan-digital-ai-for-nvidia')
        );
        // Persist the dismissal when the user clicks the notice's "X" (no jQuery dependency).
        wp_print_inline_script_tag(
            "document.addEventListener('click',function(e){"
            . "if(!e.target.classList.contains('notice-dismiss')){return;}"
            . "var n=e.target.closest('#cdnv-missing-client-notice');"
            . "if(!n){return;}"
            . "var b=new FormData();"
            . "b.append('action'," . wp_json_encode(self::DISMISS_NOTICE_ACTION) . ");"
            . "b.append('nonce',n.getAttribute('data-nonce'));"
            . "fetch(window.ajaxurl,{method:'POST',credentials:'same-origin',body:b});"
            . "});"
        );
    }

    /**
     * Persists the per-user dismissal of the missing-client notice.
     */
    public static function dismiss_missing_client_notice(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(null, 403);
        }
        check_ajax_referer(self::DISMISS_NOTICE_ACTION, 'nonce');
        update_user_meta(get_current_user_id(), self::DISMISS_NOTICE_META, 1);
        wp_send_json_success();
    }

    /**
     * Whether a plugin is active. is_plugin_active() is only loaded on admin screens.
     *
     * @param string $plugin Plugin basename.
     */
    private static function is_active(string $plugin): bool
    {
        if (in_array($plugin, (array) get_option('active_plugins', []), true)) {
            return true;
        }
        if (is_multisite()) {
            $network = (array) get_site_option('active_sitewide_plugins', []);
            return isset($network[$plugin]);
        }
        return false;
    }
}
