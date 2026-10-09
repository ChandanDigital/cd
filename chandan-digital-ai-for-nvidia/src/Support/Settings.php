<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Support;

/**
 * General plugin settings and NVIDIA API key storage.
 *
 * The API key is resolved in this order:
 *
 * 1. The `CHANDAN_NVIDIA_API_KEY` constant in wp-config.php.
 * 2. The key saved on the plugin's settings screen (encrypted at rest).
 * 3. The `NVIDIA_API_KEY` environment variable or constant (the method the original plugin documented).
 * 4. The key saved under Settings > Connectors in WordPress 7.0 and later.
 *
 * The key is never printed back to the browser. Screens only ever show a masked version.
 *
 * Since 1.2.1 the key saved here and the key under Settings > Connectors are kept in sync, so the
 * plugin and every plugin that uses the WordPress AI Client work with the same key: saving a new key
 * in either place updates the other one. If an old key was left behind in one place, a request that
 * NVIDIA rejects with that key is tried once with the other saved key, and the key that works is then
 * used in both places.
 *
 * @since 1.1.0
 */
final class Settings
{
    public const OPTION = 'cdnv_settings';
    public const KEY_OPTION = 'cdnv_api_key';
    public const STATUS_OPTION = 'cdnv_connection_status';
    public const KEY_CONSTANT = 'CHANDAN_NVIDIA_API_KEY';
    public const LEGACY_KEY_NAME = 'NVIDIA_API_KEY';
    public const CONNECTORS_OPTION = 'connectors_ai_nvidia_api_key';
    public const DEFAULT_BASE_URL = 'https://integrate.api.nvidia.com/v1';

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /** True while this class writes the Connectors option, so its own change is not copied back. */
    private static bool $syncing = false;

    /** Encrypted key that was replaced by a Connectors key in this request, or null. */
    private static ?string $replacedKey = null;

    /**
     * Default values for every general setting.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'base_url' => self::DEFAULT_BASE_URL,
            'timeout' => 120,
            'connect_timeout' => 15,
            'max_retries' => 1,
            'max_retry_wait' => 10,
            'default_model' => ModelRegistry::KIMI_K3,
            'ai_client_key_mode' => 'fallback',
            'editorial_policy' => 1,
            'playground_policy' => 1,
            'playground_access' => 'administrator',
            'logging' => 0,
            'log_content' => 0,
            'log_retention_days' => 7,
            'auto_refresh_models' => 0,
            'delete_data_on_uninstall' => 0,
            'seo_assistant' => 1,
            'seo_assistant_access' => 'editor',
            'seo_model' => '',
            'seo_image_model' => 'black-forest-labs/flux.1-schnell',
        ];
    }

    /**
     * Returns all settings merged over the defaults.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (self::$cache === null) {
            $stored = get_option(self::OPTION, []);
            self::$cache = array_merge(self::defaults(), is_array($stored) ? $stored : []);
        }
        return self::$cache;
    }

    /**
     * Returns one setting.
     *
     * @param string $key Setting name.
     * @return mixed
     */
    public static function get(string $key)
    {
        $all = self::all();
        return $all[$key] ?? (self::defaults()[$key] ?? null);
    }

    /**
     * Validates and stores general settings. Unknown keys are ignored.
     *
     * @param array<string, mixed> $input Raw input.
     * @return array<string, string> Validation messages keyed by field (empty when everything was valid).
     */
    public static function update(array $input): array
    {
        $current = self::all();
        $errors = [];

        if (array_key_exists('base_url', $input)) {
            $url = self::sanitize_base_url((string) $input['base_url']);
            if ($url === null) {
                $errors['base_url'] = __('The API base URL must be a valid https:// address. The previous value was kept.', 'chandan-digital-ai-for-nvidia');
            } else {
                $current['base_url'] = $url;
            }
        }

        $ints = [
            'timeout' => [10, 600],
            'connect_timeout' => [3, 60],
            'max_retries' => [0, 3],
            'max_retry_wait' => [1, 60],
            'log_retention_days' => [1, 90],
        ];
        foreach ($ints as $key => [$min, $max]) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = filter_var($input[$key], FILTER_VALIDATE_INT);
            if ($value === false || $value < $min || $value > $max) {
                /* translators: 1: minimum value, 2: maximum value. */
                $errors[$key] = sprintf(__('Enter a whole number between %1$d and %2$d.', 'chandan-digital-ai-for-nvidia'), $min, $max);
                continue;
            }
            $current[$key] = $value;
        }

        foreach (['editorial_policy', 'playground_policy', 'seo_assistant', 'logging', 'log_content', 'auto_refresh_models', 'delete_data_on_uninstall'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $current[$flag] = empty($input[$flag]) ? 0 : 1;
            }
        }

        if (array_key_exists('ai_client_key_mode', $input)) {
            $mode = (string) $input['ai_client_key_mode'];
            if (in_array($mode, ['fallback', 'always', 'never'], true)) {
                $current['ai_client_key_mode'] = $mode;
            }
        }

        if (array_key_exists('playground_access', $input)) {
            $access = (string) $input['playground_access'];
            if (in_array($access, ['administrator', 'editor'], true)) {
                $current['playground_access'] = $access;
            }
        }

        if (array_key_exists('seo_assistant_access', $input)) {
            $access = (string) $input['seo_assistant_access'];
            if (in_array($access, ['administrator', 'editor', 'author'], true)) {
                $current['seo_assistant_access'] = $access;
            }
        }

        if (array_key_exists('seo_model', $input)) {
            $model = (string) $input['seo_model'];
            $found = ModelRegistry::get($model);
            if ($model === '' || ($found !== null && $found['kind'] !== 'image')) {
                $current['seo_model'] = $model;
            } else {
                $errors['seo_model'] = __('Choose a chat model for the SEO Assistant.', 'chandan-digital-ai-for-nvidia');
            }
        }

        if (array_key_exists('seo_image_model', $input)) {
            $model = (string) $input['seo_image_model'];
            $found = ModelRegistry::get($model);
            if ($found !== null && $found['kind'] === 'image') {
                $current['seo_image_model'] = $model;
            } else {
                $errors['seo_image_model'] = __('Choose a FLUX image model.', 'chandan-digital-ai-for-nvidia');
            }
        }

        if (array_key_exists('default_model', $input)) {
            $model = (string) $input['default_model'];
            if (ModelRegistry::get($model) !== null) {
                $current['default_model'] = $model;
            } else {
                $errors['default_model'] = __('The selected default model is not registered.', 'chandan-digital-ai-for-nvidia');
            }
        }

        update_option(self::OPTION, $current);
        self::$cache = null;

        return $errors;
    }

    /**
     * Resets the in-memory cache. Used after options change outside this class.
     */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * Validates an API base URL. Only https:// addresses are accepted.
     *
     * @param string $url Raw URL.
     * @return string|null The cleaned URL without a trailing slash, or null when invalid.
     */
    public static function sanitize_base_url(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return self::DEFAULT_BASE_URL;
        }
        $url = esc_url_raw($url, ['https']);
        if ($url === '' || wp_parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return null;
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $url = untrailingslashit($url);
        // Accept a pasted full chat completions URL and keep only the base.
        $url = (string) preg_replace('#/chat/completions$#', '', $url);
        return $url;
    }

    /**
     * The API base URL actually used for requests.
     */
    public static function base_url(): string
    {
        /**
         * Filters the NVIDIA API base URL.
         *
         * Only code you control (for example a must-use plugin) can use this filter, so it is the place
         * to point the plugin at a private NVIDIA NIM deployment or a local test server.
         *
         * @since 1.1.0
         *
         * @param string $url Base URL without a trailing slash, for example https://integrate.api.nvidia.com/v1.
         */
        $url = (string) apply_filters('cdnv_api_base_url', (string) self::get('base_url'));
        return untrailingslashit($url !== '' ? $url : self::DEFAULT_BASE_URL);
    }

    /**
     * Base URL of NVIDIA's GenAI endpoint, used for FLUX image generation.
     *
     * @since 1.2.0
     */
    public static function genai_base_url(): string
    {
        /**
         * Filters the NVIDIA GenAI (image generation) base URL.
         *
         * @since 1.2.0
         *
         * @param string $url Base URL without a trailing slash.
         */
        return untrailingslashit((string) apply_filters('cdnv_genai_base_url', 'https://ai.api.nvidia.com/v1/genai'));
    }

    /**
     * The full chat completions endpoint.
     */
    public static function chat_url(): string
    {
        return self::base_url() . '/chat/completions';
    }

    /**
     * Whether the base URL points to NVIDIA's own hosted API.
     */
    public static function is_nvidia_host(): bool
    {
        $host = (string) wp_parse_url(self::base_url(), PHP_URL_HOST);
        return $host === 'nvidia.com' || substr($host, -strlen('.nvidia.com')) === '.nvidia.com';
    }

    /**
     * Where the active API key comes from.
     *
     * @return string One of constant, plugin, env, legacy_constant, connectors or none.
     */
    public static function key_source(): string
    {
        if (defined(self::KEY_CONSTANT) && is_string(constant(self::KEY_CONSTANT)) && trim((string) constant(self::KEY_CONSTANT)) !== '') {
            return 'constant';
        }
        if (self::stored_key() !== '') {
            return 'plugin';
        }
        $env = getenv(self::LEGACY_KEY_NAME);
        if (is_string($env) && trim($env) !== '') {
            return 'env';
        }
        if (defined(self::LEGACY_KEY_NAME) && is_string(constant(self::LEGACY_KEY_NAME)) && trim((string) constant(self::LEGACY_KEY_NAME)) !== '') {
            return 'legacy_constant';
        }
        $connectors = get_option(self::CONNECTORS_OPTION, '');
        if (is_string($connectors) && trim($connectors) !== '') {
            return 'connectors';
        }
        return 'none';
    }

    /**
     * Human-readable label for a key source.
     *
     * @param string $source Key source identifier.
     */
    public static function key_source_label(string $source): string
    {
        switch ($source) {
            case 'constant':
                return __('wp-config.php constant CHANDAN_NVIDIA_API_KEY', 'chandan-digital-ai-for-nvidia');
            case 'plugin':
                return __('Saved in this plugin (encrypted)', 'chandan-digital-ai-for-nvidia');
            case 'env':
                return __('Server environment variable NVIDIA_API_KEY', 'chandan-digital-ai-for-nvidia');
            case 'legacy_constant':
                return __('wp-config.php constant NVIDIA_API_KEY', 'chandan-digital-ai-for-nvidia');
            case 'connectors':
                return __('WordPress Settings > Connectors', 'chandan-digital-ai-for-nvidia');
            default:
                return __('Not configured', 'chandan-digital-ai-for-nvidia');
        }
    }

    /**
     * The active API key, or an empty string when none is configured.
     *
     * Never send the return value to the browser.
     */
    public static function api_key(): string
    {
        switch (self::key_source()) {
            case 'constant':
                return trim((string) constant(self::KEY_CONSTANT));
            case 'plugin':
                return self::stored_key();
            case 'env':
                return trim((string) getenv(self::LEGACY_KEY_NAME));
            case 'legacy_constant':
                return trim((string) constant(self::LEGACY_KEY_NAME));
            case 'connectors':
                return trim((string) get_option(self::CONNECTORS_OPTION, ''));
            default:
                return '';
        }
    }

    /**
     * Whether a key was saved through this plugin but can no longer be decrypted.
     */
    public static function stored_key_unreadable(): bool
    {
        $raw = get_option(self::KEY_OPTION, '');
        return is_string($raw) && $raw !== '' && self::stored_key() === '';
    }

    /**
     * Decrypted key saved through this plugin.
     */
    public static function stored_key(): string
    {
        $raw = get_option(self::KEY_OPTION, '');
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        return self::decrypt($raw);
    }

    /**
     * Validates and saves an API key. The key is encrypted before it reaches the database.
     *
     * @param string $key Raw key.
     * @return string|null Error message, or null on success.
     */
    public static function save_api_key(string $key): ?string
    {
        $key = trim($key);
        if ($key === '') {
            return __('The API key is empty.', 'chandan-digital-ai-for-nvidia');
        }
        if (strlen($key) > 512 || !preg_match('/^[A-Za-z0-9._\-]+$/', $key)) {
            return __('The API key contains characters that NVIDIA keys never use. Paste the key again without spaces or quotes.', 'chandan-digital-ai-for-nvidia');
        }
        $encrypted = self::encrypt($key);
        if ($encrypted === '') {
            return __('The key could not be encrypted on this server, so it was not saved. Define CHANDAN_NVIDIA_API_KEY in wp-config.php instead.', 'chandan-digital-ai-for-nvidia');
        }
        // Not autoloaded: the key is only read when an NVIDIA request is made.
        delete_option(self::KEY_OPTION);
        add_option(self::KEY_OPTION, $encrypted, '', false);
        self::$replacedKey = null;
        self::sync_to_connectors($key);
        return null;
    }

    /**
     * The key saved under Settings > Connectors (WordPress 7.0+), or an empty string.
     */
    public static function connectors_key(): string
    {
        $value = get_option(self::CONNECTORS_OPTION, '');
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Whether this plugin and Settings > Connectors hold two different keys.
     */
    public static function keys_differ(): bool
    {
        $stored = self::stored_key();
        $connectors = self::connectors_key();
        return $stored !== '' && $connectors !== '' && !hash_equals($stored, $connectors);
    }

    /**
     * Puts a newly saved key into Settings > Connectors too, when a key is kept there.
     *
     * Nothing is written when Connectors has no key, so a site that only uses this plugin keeps its
     * key encrypted and out of that plain option.
     *
     * @param string $key Key that was just saved.
     */
    private static function sync_to_connectors(string $key): void
    {
        $connectors = self::connectors_key();
        if ($connectors === '' || hash_equals($connectors, $key)) {
            return;
        }
        self::$syncing = true;
        update_option(self::CONNECTORS_OPTION, $key);
        self::$syncing = false;
    }

    /**
     * Copies a key saved under Settings > Connectors into this plugin, so both use the new key.
     * Hooked to the Connectors option being added or updated.
     *
     * WordPress clears that option again when it rejects a key; the key it replaced here in the same
     * request is then put back.
     *
     * @param mixed $value New option value.
     */
    public static function connectors_key_changed($value): void
    {
        if (self::$syncing) {
            return;
        }
        $key = is_string($value) ? trim($value) : '';
        if ($key === '') {
            if (self::$replacedKey !== null) {
                update_option(self::KEY_OPTION, self::$replacedKey, false);
                self::$replacedKey = null;
            }
            return;
        }
        $stored = self::stored_key();
        // Only replace a key saved here. With no key here the Connectors key is already used.
        if ($stored === '' || hash_equals($stored, $key) || strlen($key) > 512 || !preg_match('/^[A-Za-z0-9._\-]+$/', $key)) {
            return;
        }
        $encrypted = self::encrypt($key);
        if ($encrypted === '') {
            return;
        }
        self::$replacedKey = (string) get_option(self::KEY_OPTION, '');
        update_option(self::KEY_OPTION, $encrypted, false);
    }

    /**
     * Another saved key to try when NVIDIA rejects the given one, or an empty string.
     *
     * @param string $rejected The key NVIDIA rejected.
     */
    public static function other_saved_key(string $rejected): string
    {
        if (self::key_source() === 'constant') {
            return '';
        }
        foreach ([self::stored_key(), self::connectors_key()] as $candidate) {
            if ($candidate !== '' && !hash_equals($candidate, $rejected)) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * Called after NVIDIA accepted a key. If this plugin and Settings > Connectors hold different
     * keys, the working key is saved in both places.
     *
     * @param string $key Key NVIDIA accepted.
     */
    public static function key_accepted(string $key): void
    {
        if ($key === '' || self::key_source() === 'constant') {
            return;
        }
        $stored = self::stored_key();
        if ($stored !== '' && !hash_equals($stored, $key)) {
            self::save_api_key($key);
            return;
        }
        self::sync_to_connectors($key);
    }

    /**
     * Removes the key saved through this plugin. Keys from wp-config.php or Connectors are untouched.
     */
    public static function delete_api_key(): void
    {
        delete_option(self::KEY_OPTION);
    }

    /**
     * Masks a key so only the prefix and the last four characters remain visible.
     *
     * @param string $key Raw key.
     */
    public static function mask_key(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        $prefix = strpos($key, 'nvapi-') === 0 ? 'nvapi-' : '';
        $tail = strlen($key) > 10 ? substr($key, -4) : '';
        return $prefix . str_repeat('•', 8) . $tail;
    }

    /**
     * Stores the result of the last connection test (no secrets, no prompts).
     *
     * @param array<string, mixed> $status Status data.
     */
    public static function save_status(array $status): void
    {
        $status['time'] = time();
        update_option(self::STATUS_OPTION, $status, false);
    }

    /**
     * Returns the last stored connection test result.
     *
     * @return array<string, mixed>
     */
    public static function status(): array
    {
        $status = get_option(self::STATUS_OPTION, []);
        return is_array($status) ? $status : [];
    }

    /**
     * Capability required to use the AI Playground.
     */
    public static function playground_capability(): string
    {
        $capability = self::get('playground_access') === 'editor' ? 'edit_others_posts' : 'manage_options';
        /**
         * Filters the capability required to use the AI Playground.
         *
         * @since 1.1.0
         *
         * @param string $capability Capability name.
         */
        return (string) apply_filters('cdnv_playground_capability', $capability);
    }

    /**
     * Capability needed to use the SEO Assistant (on top of being able to edit the post).
     *
     * @since 1.2.0
     */
    public static function seo_capability(): string
    {
        $map = ['administrator' => 'manage_options', 'editor' => 'edit_others_posts', 'author' => 'publish_posts'];
        $capability = $map[(string) self::get('seo_assistant_access')] ?? 'edit_others_posts';
        /**
         * Filters the capability required to use the SEO Assistant.
         *
         * @since 1.2.0
         *
         * @param string $capability Capability name.
         */
        return (string) apply_filters('cdnv_seo_capability', $capability);
    }

    /**
     * Derives the encryption key from the site's secret salts in wp-config.php.
     *
     * The salts live in wp-config.php, not in the database, so a leaked database
     * copy on its own does not reveal the API key.
     */
    private static function crypto_key(): string
    {
        return hash('sha256', 'cdnv|' . wp_salt('auth') . '|' . wp_salt('secure_auth'), true);
    }

    /**
     * Encrypts a value with libsodium (bundled with WordPress through sodium_compat).
     *
     * @param string $value Plain value.
     */
    private static function encrypt(string $value): string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            return '';
        }
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($value, $nonce, self::crypto_key());
        } catch (\Throwable $e) {
            return '';
        }
        return 'v1:' . base64_encode($nonce . $cipher);
    }

    /**
     * Decrypts a value produced by encrypt(). Returns an empty string when it cannot be read,
     * for example after the WordPress salts were changed.
     *
     * @param string $value Encrypted value.
     */
    private static function decrypt(string $value): string
    {
        if (strpos($value, 'v1:') !== 0 || !function_exists('sodium_crypto_secretbox_open')) {
            return '';
        }
        $raw = base64_decode(substr($value, 3), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        try {
            $plain = sodium_crypto_secretbox_open(
                substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                self::crypto_key()
            );
        } catch (\Throwable $e) {
            return '';
        }
        return is_string($plain) ? $plain : '';
    }
}
