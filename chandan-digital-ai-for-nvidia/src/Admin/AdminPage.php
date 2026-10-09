<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Admin;

use ChandanDigital\NvidiaAi\Content\IndianEnglishPolicy;
use ChandanDigital\NvidiaAi\Plugin;
use ChandanDigital\NvidiaAi\Rest\RestController;
use ChandanDigital\NvidiaAi\Support\Logger;
use ChandanDigital\NvidiaAi\Support\ModelRegistry;
use ChandanDigital\NvidiaAi\Support\Settings;

use const ChandanDigital\NvidiaAi\PLUGIN_FILE;
use const ChandanDigital\NvidiaAi\VERSION;

/**
 * The "Chandan Digital AI for NVIDIA" admin screen.
 *
 * Settings forms post to admin-post.php and are protected by a nonce and a manage_options check.
 * Interactive actions (tests, Playground) use the plugin's REST routes.
 *
 * @since 1.1.0
 */
final class AdminPage
{
    public const SLUG = 'chandan-digital-ai-nvidia';

    /** Tabs that need manage_options. The Playground can be opened to editors. */
    private const ADMIN_TABS = ['overview', 'api', 'models', 'kimi', 'seo', 'diagnostics', 'privacy'];

    private static string $hook = '';

    /**
     * Registers admin hooks.
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        foreach (['save_api', 'save_models', 'add_custom', 'remove_custom', 'save_model_settings', 'save_privacy', 'save_seo', 'clear_logs'] as $action) {
            add_action('admin_post_cdnv_' . $action, [self::class, 'handle_' . $action]);
        }
    }

    /**
     * URL of a tab.
     *
     * @param string $tab Tab ID.
     * @param array<string, string> $args Extra query arguments.
     */
    public static function url(string $tab = 'overview', array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG, 'tab' => $tab], $args), admin_url('admin.php'));
    }

    /**
     * Adds the top-level menu.
     */
    public static function menu(): void
    {
        $capability = current_user_can('manage_options') ? 'manage_options' : Settings::playground_capability();
        self::$hook = (string) add_menu_page(
            __('Chandan Digital AI for NVIDIA', 'chandan-digital-ai-for-nvidia'),
            __('NVIDIA AI', 'chandan-digital-ai-for-nvidia'),
            $capability,
            self::SLUG,
            [self::class, 'render'],
            'dashicons-format-chat',
            81
        );
    }

    /**
     * Current tab, limited to what the user may see.
     */
    public static function current_tab(): string
    {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
        if (!current_user_can('manage_options')) {
            return 'playground';
        }
        return in_array($tab, array_merge(self::ADMIN_TABS, ['playground']), true) ? $tab : 'overview';
    }

    /**
     * Loads CSS and JavaScript on this screen only.
     *
     * @param string $hook Current admin page hook.
     */
    public static function assets(string $hook): void
    {
        if ($hook !== self::$hook || self::$hook === '') {
            return;
        }
        $base = plugin_dir_url(PLUGIN_FILE) . 'assets/';
        wp_enqueue_style('cdnv-admin', $base . 'css/admin.css', [], VERSION);

        $tab = self::current_tab();
        $config = [
            'restUrl' => esc_url_raw(rest_url(RestController::NAMESPACE . '/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'i18n' => self::i18n(),
        ];

        wp_register_script('cdnv-sse', $base . 'js/sse.js', [], VERSION, true);
        if ($tab === 'playground') {
            wp_enqueue_script('cdnv-playground', $base . 'js/playground.js', ['cdnv-sse'], VERSION, true);
            $config['models'] = self::playground_models();
            $config['canUpload'] = current_user_can('upload_files');
            wp_add_inline_script('cdnv-playground', 'window.cdnvConfig = ' . wp_json_encode($config) . ';', 'before');
        } else {
            wp_enqueue_script('cdnv-admin', $base . 'js/admin.js', ['cdnv-sse'], VERSION, true);
            wp_add_inline_script('cdnv-admin', 'window.cdnvConfig = ' . wp_json_encode($config) . ';', 'before');
        }
    }

    /**
     * Renders the screen.
     */
    public static function render(): void
    {
        $tab = self::current_tab();
        if ($tab !== 'playground' && !current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view this page.', 'chandan-digital-ai-for-nvidia'));
        }
        if ($tab === 'playground' && !current_user_can(Settings::playground_capability())) {
            wp_die(esc_html__('You do not have permission to use the AI Playground.', 'chandan-digital-ai-for-nvidia'));
        }
        if ($tab === 'diagnostics' || $tab === 'privacy') {
            Logger::apply_retention();
        }

        $tabs = [
            'overview' => __('Overview', 'chandan-digital-ai-for-nvidia'),
            'api' => __('NVIDIA API Settings', 'chandan-digital-ai-for-nvidia'),
            'models' => __('AI Models', 'chandan-digital-ai-for-nvidia'),
            'kimi' => __('Kimi K3 Settings', 'chandan-digital-ai-for-nvidia'),
            'seo' => __('SEO Assistant', 'chandan-digital-ai-for-nvidia'),
            'playground' => __('AI Playground', 'chandan-digital-ai-for-nvidia'),
            'diagnostics' => __('API Diagnostics', 'chandan-digital-ai-for-nvidia'),
            'privacy' => __('Privacy & Security', 'chandan-digital-ai-for-nvidia'),
        ];
        if (!current_user_can('manage_options')) {
            $tabs = ['playground' => $tabs['playground']];
        }
        $notice = self::pull_notice();

        echo '<div class="wrap cdnv-wrap">';
        self::view('header', ['version' => VERSION]);
        echo '<nav class="nav-tab-wrapper cdnv-tabs" aria-label="' . esc_attr__('Plugin sections', 'chandan-digital-ai-for-nvidia') . '">';
        foreach ($tabs as $id => $label) {
            printf(
                '<a href="%s" class="nav-tab%s"%s>%s</a>',
                esc_url(self::url($id)),
                $id === $tab ? ' nav-tab-active' : '',
                $id === $tab ? ' aria-current="page"' : '',
                esc_html($label)
            );
        }
        echo '</nav>';

        if ($notice) {
            printf(
                '<div class="notice notice-%s is-dismissible cdnv-notice"><p>%s</p>%s</div>',
                esc_attr($notice['type']),
                esc_html($notice['message']),
                !empty($notice['errors']) ? '<ul class="cdnv-error-list"><li>' . implode('</li><li>', array_map('esc_html', $notice['errors'])) . '</li></ul>' : ''
            );
        }

        echo '<div class="cdnv-panel">';
        self::view($tab, []);
        echo '</div></div>';
    }

    /**
     * Includes a view file.
     *
     * @param string $name View name.
     * @param array<string, mixed> $vars Variables for the view.
     */
    public static function view(string $name, array $vars): void
    {
        $file = __DIR__ . '/views/' . $name . '.php';
        if (!preg_match('/^[a-z\-]+$/', $name) || !file_exists($file)) {
            return;
        }
        extract($vars, EXTR_SKIP); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- local view variables only.
        include $file;
    }

    /**
     * Saves the API key and general connection settings.
     */
    public static function handle_save_api(): void
    {
        self::verify('cdnv_save_api');
        $post = self::post();
        $messages = [];
        $errors = [];

        if (!empty($post['remove_key'])) {
            Settings::delete_api_key();
            $messages[] = __('The saved API key was removed.', 'chandan-digital-ai-for-nvidia');
        } elseif (isset($post['api_key']) && trim((string) $post['api_key']) !== '') {
            $error = Settings::save_api_key((string) $post['api_key']);
            if ($error !== null) {
                $errors[] = $error;
            } else {
                $messages[] = __('The API key was saved and encrypted.', 'chandan-digital-ai-for-nvidia');
                // A new key means old access results may no longer apply.
                delete_option(Settings::STATUS_OPTION);
            }
        }

        $fieldErrors = Settings::update([
            'base_url' => (string) ($post['base_url'] ?? ''),
            'timeout' => $post['timeout'] ?? '',
            'connect_timeout' => $post['connect_timeout'] ?? '',
            'max_retries' => $post['max_retries'] ?? '',
            'max_retry_wait' => $post['max_retry_wait'] ?? '',
            'ai_client_key_mode' => (string) ($post['ai_client_key_mode'] ?? 'fallback'),
        ]);
        $errors = array_merge($errors, array_values($fieldErrors));
        $messages[] = __('Connection settings saved.', 'chandan-digital-ai-for-nvidia');

        self::redirect('api', $errors ? 'warning' : 'success', implode(' ', $messages), $errors);
    }

    /**
     * Saves enabled models, the default model and catalogue refresh preference.
     */
    public static function handle_save_models(): void
    {
        self::verify('cdnv_save_models');
        $post = self::post();
        $enabled = isset($post['enabled']) && is_array($post['enabled']) ? array_map('strval', array_keys($post['enabled'])) : [];
        $default = (string) ($post['default_model'] ?? '');
        $errors = [];

        $defaultModel = ModelRegistry::get($default);
        if ($defaultModel === null || $defaultModel['kind'] === 'image') {
            $errors[] = __('Choose a chat model as the default model.', 'chandan-digital-ai-for-nvidia');
        } elseif (!in_array($default, $enabled, true)) {
            // The default model must stay enabled.
            $enabled[] = $default;
        }

        foreach (ModelRegistry::all() as $id => $model) {
            $want = in_array($id, $enabled, true);
            if ($want !== $model['enabled']) {
                ModelRegistry::set_enabled($id, $want);
            }
        }
        if (!$errors) {
            $errors = array_values(Settings::update(['default_model' => $default]));
        }
        Settings::update(['auto_refresh_models' => !empty($post['auto_refresh_models'])]);
        Plugin::sync_schedule();

        self::redirect('models', $errors ? 'warning' : 'success', __('Model choices saved.', 'chandan-digital-ai-for-nvidia'), $errors);
    }

    /**
     * Registers a custom model.
     */
    public static function handle_add_custom(): void
    {
        self::verify('cdnv_add_custom');
        $post = self::post();
        $error = ModelRegistry::add_custom(
            (string) ($post['model_id'] ?? ''),
            (string) ($post['model_name'] ?? ''),
            (string) ($post['model_developer'] ?? ''),
            (string) ($post['model_kind'] ?? 'text'),
            !empty($post['model_reasoning'])
        );
        if ($error !== null) {
            self::redirect('models', 'error', $error);
        }
        self::redirect('models', 'success', __('Custom model added. Use "Check access" to confirm your API key can use it; until then it is not offered to other plugins.', 'chandan-digital-ai-for-nvidia'));
    }

    /**
     * Removes a custom model.
     */
    public static function handle_remove_custom(): void
    {
        self::verify('cdnv_remove_custom');
        $id = (string) (self::post()['model_id'] ?? '');
        $model = ModelRegistry::get($id);
        if ($model === null || $model['source'] !== 'custom') {
            self::redirect('models', 'error', __('Only custom models can be removed. Built-in models can be disabled instead.', 'chandan-digital-ai-for-nvidia'));
        }
        ModelRegistry::remove_custom($id);
        self::redirect('models', 'success', __('Custom model removed.', 'chandan-digital-ai-for-nvidia'));
    }

    /**
     * Saves model-specific settings.
     */
    public static function handle_save_model_settings(): void
    {
        self::verify('cdnv_save_model_settings');
        $post = self::post();
        $id = (string) ($post['model_id'] ?? '');
        $back = (string) ($post['return_tab'] ?? 'models') === 'kimi' ? 'kimi' : 'models';
        $args = $back === 'models' ? ['configure' => $id] : [];
        $errors = ModelRegistry::save_settings($id, $post);
        if ($errors) {
            self::redirect($back, 'error', __('Settings were not saved. Fix the following and try again:', 'chandan-digital-ai-for-nvidia'), array_values($errors), $args);
        }
        self::redirect($back, 'success', __('Model settings saved.', 'chandan-digital-ai-for-nvidia'), [], $args);
    }

    /**
     * Saves privacy, logging and access settings.
     */
    public static function handle_save_privacy(): void
    {
        self::verify('cdnv_save_privacy');
        $post = self::post();
        $errors = Settings::update([
            'logging' => !empty($post['logging']),
            'log_content' => !empty($post['log_content']),
            'log_retention_days' => $post['log_retention_days'] ?? 7,
            'editorial_policy' => !empty($post['editorial_policy']),
            'playground_policy' => !empty($post['playground_policy']),
            'playground_access' => (string) ($post['playground_access'] ?? 'administrator'),
            'delete_data_on_uninstall' => !empty($post['delete_data_on_uninstall']),
        ]);
        if (!empty($post['writing_style_reset'])) {
            IndianEnglishPolicy::save('');
        } elseif (isset($post['writing_style'])) {
            $styleError = IndianEnglishPolicy::save((string) $post['writing_style']);
            if ($styleError !== null) {
                $errors['writing_style'] = $styleError;
            }
        }
        if (empty($post['logging'])) {
            Logger::clear();
        } else {
            Logger::apply_retention();
        }
        self::redirect('privacy', $errors ? 'warning' : 'success', __('Privacy and security settings saved.', 'chandan-digital-ai-for-nvidia'), array_values($errors));
    }

    /**
     * Saves SEO Assistant settings.
     */
    public static function handle_save_seo(): void
    {
        self::verify('cdnv_save_seo');
        $post = self::post();
        $errors = Settings::update([
            'seo_assistant' => !empty($post['seo_assistant']),
            'seo_assistant_access' => (string) ($post['seo_assistant_access'] ?? 'editor'),
            'seo_model' => (string) ($post['seo_model'] ?? ''),
            'seo_image_model' => (string) ($post['seo_image_model'] ?? ''),
        ]);
        self::redirect('seo', $errors ? 'warning' : 'success', __('SEO Assistant settings saved.', 'chandan-digital-ai-for-nvidia'), array_values($errors));
    }

    /**
     * Deletes all local log entries.
     */
    public static function handle_clear_logs(): void
    {
        self::verify('cdnv_clear_logs');
        Logger::clear();
        $back = (string) (self::post()['return_tab'] ?? 'privacy') === 'diagnostics' ? 'diagnostics' : 'privacy';
        self::redirect($back, 'success', __('Local logs cleared.', 'chandan-digital-ai-for-nvidia'));
    }

    /**
     * Models offered in the Playground, with only the settings the browser needs (no secrets).
     *
     * @return list<array<string, mixed>>
     */
    private static function playground_models(): array
    {
        $out = [];
        foreach (ModelRegistry::enabled_chat_models() as $id => $model) {
            $settings = ModelRegistry::settings($id);
            $out[] = [
                'id' => $id,
                'name' => $model['name'],
                'developer' => $model['developer'],
                'vision' => $model['kind'] === 'vision',
                'images' => $model['kind'] === 'vision' && !empty($settings['images']),
                'imageUrls' => $model['kind'] === 'vision' && !empty($settings['image_urls']),
                'imageFormats' => array_values((array) $settings['image_formats']),
                'imageMaxMb' => (int) $settings['image_max_mb'],
                'imageMaxCount' => (int) $settings['image_max_count'],
                'stream' => !empty($settings['stream']),
                'reasoning' => in_array('reasoning', $model['capabilities'], true),
                'status' => (string) ($model['status']['state'] ?? 'unknown'),
                'summary' => self::settings_summary($model, $settings),
            ];
        }
        return $out;
    }

    /**
     * One-line summary of the generation settings for a model.
     *
     * @param array<string, mixed> $model Model descriptor.
     * @param array<string, mixed> $settings Settings.
     */
    public static function settings_summary(array $model, array $settings): string
    {
        $notSent = __('not sent', 'chandan-digital-ai-for-nvidia');
        $parts = [
            /* translators: %s: value. */
            sprintf(__('Temperature: %s', 'chandan-digital-ai-for-nvidia'), $settings['temperature'] === null ? $notSent : (string) $settings['temperature']),
            /* translators: %s: value. */
            sprintf(__('Max tokens: %s', 'chandan-digital-ai-for-nvidia'), $settings['max_tokens'] === null ? $notSent : number_format_i18n((int) $settings['max_tokens'])),
        ];
        if ($model['reasoning_values']) {
            /* translators: %s: value. */
            $parts[] = sprintf(__('Reasoning: %s', 'chandan-digital-ai-for-nvidia'), $settings['reasoning_effort'] === 'default' ? __('model default', 'chandan-digital-ai-for-nvidia') : (string) $settings['reasoning_effort']);
        }
        /* translators: %s: value. */
        $parts[] = sprintf(__('Seed: %s', 'chandan-digital-ai-for-nvidia'), $settings['seed'] === null ? $notSent : (string) $settings['seed']);
        return implode(' · ', $parts);
    }

    /**
     * Strings used by the admin JavaScript.
     *
     * @return array<string, string>
     */
    private static function i18n(): array
    {
        return [
            'working' => __('Working…', 'chandan-digital-ai-for-nvidia'),
            'systemSaved' => __('Saved', 'chandan-digital-ai-for-nvidia'),
            'systemNotSaved' => __('Could not save. Try again.', 'chandan-digital-ai-for-nvidia'),
            'thinking' => __('Thinking…', 'chandan-digital-ai-for-nvidia'),
            'ok' => __('OK', 'chandan-digital-ai-for-nvidia'),
            'failed' => __('Failed', 'chandan-digital-ai-for-nvidia'),
            'notChecked' => __('Not checked', 'chandan-digital-ai-for-nvidia'),
            'verified' => __('Access confirmed', 'chandan-digital-ai-for-nvidia'),
            'notAvailable' => __('Not available to this key', 'chandan-digital-ai-for-nvidia'),
            'accessDenied' => __('Access denied', 'chandan-digital-ai-for-nvidia'),
            'error' => __('Check failed', 'chandan-digital-ai-for-nvidia'),
            'requestFailed' => __('The request to this WordPress site failed. Check your connection and try again.', 'chandan-digital-ai-for-nvidia'),
            /* translators: %d: number of models. */
            'refreshDone' => __('NVIDIA catalogue refreshed: %d models listed.', 'chandan-digital-ai-for-nvidia'),
            'unregistered' => __('Catalogue models that are not in your list yet (not checked for access):', 'chandan-digital-ai-for-nvidia'),
            'missing' => __('Registered models that NVIDIA no longer lists:', 'chandan-digital-ai-for-nvidia'),
            'useAsCustom' => __('Add as custom model', 'chandan-digital-ai-for-nvidia'),
            'none' => __('None', 'chandan-digital-ai-for-nvidia'),
            /* translators: 1: milliseconds until the first event, 2: milliseconds until the last event. */
            'streamWorks' => __('Streaming works: events arrived one by one (first after %1$d ms, last after %2$d ms).', 'chandan-digital-ai-for-nvidia'),
            /* translators: %d: milliseconds. */
            'streamBuffered' => __('Streaming is being buffered by the server or a proxy: all events arrived together after %d ms. Responses will still work, but text will appear all at once. Turn streaming off for your models, or ask your host to disable output buffering for this site.', 'chandan-digital-ai-for-nvidia'),
            'streamFailed' => __('The streaming test failed. Turn streaming off for your models; the non-streaming mode will be used.', 'chandan-digital-ai-for-nvidia'),
            'http' => __('HTTP', 'chandan-digital-ai-for-nvidia'),
            'you' => __('You', 'chandan-digital-ai-for-nvidia'),
            'assistant' => __('Assistant', 'chandan-digital-ai-for-nvidia'),
            'reasoning' => __('Reasoning', 'chandan-digital-ai-for-nvidia'),
            'copy' => __('Copy response', 'chandan-digital-ai-for-nvidia'),
            'copied' => __('Copied', 'chandan-digital-ai-for-nvidia'),
            'copyFailed' => __('Copy failed. Select the text and copy it manually.', 'chandan-digital-ai-for-nvidia'),
            'stopped' => __('Stopped. The text above may be incomplete and will not be used as context.', 'chandan-digital-ai-for-nvidia'),
            'interrupted' => __('This reply is incomplete and will not be used as context for the next message.', 'chandan-digital-ai-for-nvidia'),
            'lengthLimit' => __('The reply stopped because it reached the maximum output tokens setting.', 'chandan-digital-ai-for-nvidia'),
            'fallbackUsed' => __('Streaming did not start on this server, so the standard (non-streaming) mode was used.', 'chandan-digital-ai-for-nvidia'),
            'emptyPrompt' => __('Enter a prompt or add an image first.', 'chandan-digital-ai-for-nvidia'),
            /* translators: %d: number of images. */
            'tooManyImages' => __('This model accepts up to %d images per request.', 'chandan-digital-ai-for-nvidia'),
            /* translators: %d: size in megabytes. */
            'imageTooLarge' => __('The image is larger than the %d MB limit for this model.', 'chandan-digital-ai-for-nvidia'),
            /* translators: %s: list of image formats. */
            'imageType' => __('This image type is not enabled for the selected model. Allowed: %s.', 'chandan-digital-ai-for-nvidia'),
            'imageUrlInvalid' => __('Enter a full https:// image URL.', 'chandan-digital-ai-for-nvidia'),
            'remove' => __('Remove', 'chandan-digital-ai-for-nvidia'),
            /* translators: 1: prompt tokens, 2: output tokens. */
            'tokens' => __('%1$s prompt + %2$s output tokens', 'chandan-digital-ai-for-nvidia'),
            /* translators: %s: number of seconds. */
            'seconds' => __('%s s', 'chandan-digital-ai-for-nvidia'),
            /* translators: %d: number of seconds. */
            'retryAfter' => __('NVIDIA asked to wait %d seconds before trying again.', 'chandan-digital-ai-for-nvidia'),
            'noModels' => __('No chat models are enabled. Enable one on the AI Models tab.', 'chandan-digital-ai-for-nvidia'),
            'imageNotice' => __('Images you add are sent to NVIDIA for analysis.', 'chandan-digital-ai-for-nvidia'),
        ];
    }

    /**
     * Checks the nonce and capability for a form action.
     *
     * @param string $action Nonce action.
     */
    private static function verify(string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to change these settings.', 'chandan-digital-ai-for-nvidia'), '', ['response' => 403]);
        }
        check_admin_referer($action);
    }

    /**
     * Unslashed POST data.
     *
     * @return array<string, mixed>
     */
    private static function post(): array
    {
        // Nonce verified in verify() before this is called.
        return wp_unslash($_POST); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is validated where it is used.
    }

    /**
     * Stores a one-time notice and redirects back to a tab.
     *
     * @param string $tab Tab.
     * @param string $type success, warning or error.
     * @param string $message Message.
     * @param list<string> $errors Detailed errors.
     * @param array<string, string> $args Extra query arguments.
     * @return never
     */
    private static function redirect(string $tab, string $type, string $message, array $errors = [], array $args = []): void
    {
        set_transient('cdnv_notice_' . get_current_user_id(), [
            'type' => $type,
            'message' => $message,
            'errors' => $errors,
        ], 120);
        wp_safe_redirect(self::url($tab, $args));
        exit;
    }

    /**
     * Retrieves and removes the current user's one-time notice.
     *
     * @return array<string, mixed>|null
     */
    private static function pull_notice(): ?array
    {
        $key = 'cdnv_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if (!is_array($notice)) {
            return null;
        }
        delete_transient($key);
        return $notice;
    }
}
