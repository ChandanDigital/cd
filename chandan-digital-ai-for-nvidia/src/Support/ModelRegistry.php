<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Support;

/**
 * Registry of NVIDIA models known to the plugin, with their per-model settings and access status.
 *
 * Three kinds of models exist:
 *
 * - Built-in models carried over from the original plugin. These were verified by the original
 *   developer and are still shown to the WordPress AI Client only when NVIDIA's live catalogue lists them.
 * - Kimi K3, added in 1.1.0. It is shown to the WordPress AI Client only after a successful access
 *   check with your API key, because no catalogue listing proves that a key can call a model.
 * - Custom models registered by an administrator. The same access check rule applies.
 *
 * @since 1.1.0
 */
final class ModelRegistry
{
    public const OPTION = 'cdnv_models';
    public const CATALOG_OPTION = 'cdnv_catalog';
    public const KIMI_K3 = 'moonshotai/kimi-k3';

    /** Reasoning effort values the dashboard can offer. 'default' means "do not send the parameter". */
    public const REASONING_OPTIONS = ['default', 'low', 'medium', 'high', 'max'];

    /** Image formats the plugin can validate, mapped to MIME types. */
    public const IMAGE_FORMATS = [
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    /**
     * Models from the original plugin, in the original order, plus Kimi K3.
     *
     * Values are the model kind: 'text' (chat), 'vision' (chat that also accepts images) or 'image'
     * (image generation through NVIDIA's GenAI endpoint).
     *
     * @var array<string, string>
     */
    private const BUILTIN = [
        'meta/llama-3.3-70b-instruct' => 'text',
        'meta/llama-3.1-8b-instruct' => 'text',
        'meta/llama-3.1-70b-instruct' => 'text',
        'meta/llama-3.2-3b-instruct' => 'text',
        'meta/llama-3.2-1b-instruct' => 'text',
        'meta/llama-4-maverick-17b-128e-instruct' => 'text',
        'nvidia/nemotron-3-super-120b-a12b' => 'text',
        'nvidia/nemotron-3-nano-30b-a3b' => 'text',
        'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning' => 'text',
        'nvidia/nemotron-3-ultra-550b-a55b' => 'text',
        'nvidia/nvidia-nemotron-nano-9b-v2' => 'text',
        'nvidia/nemotron-mini-4b-instruct' => 'text',
        'nvidia/llama-3.3-nemotron-super-49b-v1.5' => 'text',
        'nvidia/llama-3.3-nemotron-super-49b-v1' => 'text',
        'nvidia/ising-calibration-1-35b-a3b' => 'text',
        'nvidia/riva-translate-4b-instruct-v1.1' => 'text',
        'mistralai/mistral-large-3-675b-instruct-2512' => 'text',
        'mistralai/mistral-medium-3.5-128b' => 'text',
        'mistralai/mistral-small-4-119b-2603' => 'text',
        'mistralai/mistral-nemotron' => 'text',
        'mistralai/ministral-14b-instruct-2512' => 'text',
        'mistralai/mixtral-8x7b-instruct-v0.1' => 'text',
        'openai/gpt-oss-120b' => 'text',
        'openai/gpt-oss-20b' => 'text',
        'qwen/qwen3.5-122b-a10b' => 'text',
        'qwen/qwen3-next-80b-a3b-instruct' => 'text',
        'microsoft/phi-4-mini-instruct' => 'text',
        'google/gemma-3n-e2b-it' => 'text',
        'google/gemma-2-2b-it' => 'text',
        'moonshotai/kimi-k2.6' => 'text',
        'minimaxai/minimax-m3' => 'text',
        'minimaxai/minimax-m2.7' => 'text',
        'stepfun-ai/step-3.7-flash' => 'text',
        'stepfun-ai/step-3.5-flash' => 'text',
        'z-ai/glm-5.1' => 'text',
        'abacusai/dracarys-llama-3.1-70b-instruct' => 'text',
        'bytedance/seed-oss-36b-instruct' => 'text',
        'stockmark/stockmark-2-100b-instruct' => 'text',
        'sarvamai/sarvam-m' => 'text',
        'upstage/solar-10.7b-instruct' => 'text',
        // Added in 1.1.0. Multimodal (text and image input), always-on reasoning.
        self::KIMI_K3 => 'vision',
        'meta/llama-3.2-90b-vision-instruct' => 'vision',
        'meta/llama-3.2-11b-vision-instruct' => 'vision',
        'nvidia/nemotron-nano-12b-v2-vl' => 'vision',
        'nvidia/llama-3.1-nemotron-nano-vl-8b-v1' => 'vision',
        'microsoft/phi-4-multimodal-instruct' => 'vision',
        'black-forest-labs/flux.1-dev' => 'image',
        'black-forest-labs/flux.1-schnell' => 'image',
        'black-forest-labs/flux.2-klein-4b' => 'image',
    ];

    /**
     * Model-specific profiles that differ from the generic chat model behaviour.
     *
     * Sources for Kimi K3: NVIDIA's API catalogue sample request (temperature 1, max_tokens 16384,
     * reasoning_effort "max", seed 0) and Moonshot AI's Kimi K3 documentation (reasoning_effort accepts
     * "low", "high" and "max"; thinking is always on; reasoning_content must be passed back in
     * multi-turn chats; sampling parameters are fixed on Moonshot's own API).
     *
     * @var array<string, array<string, mixed>>
     */
    private const PROFILES = [
        self::KIMI_K3 => [
            'name' => 'Kimi K3',
            'developer' => 'Moonshot AI',
            'reasoning_values' => ['low', 'high', 'max'],
            'always_reasons' => true,
            'reasoning_passback' => true,
            'max_tokens_limit' => 1048576,
            'context_window' => 1048576,
            'requires_verification' => true,
            'notes' => 'Kimi K3 always thinks before answering. Moonshot AI documents temperature 1.0 as fixed for this model on its own API, so other values may be rejected. NVIDIA may apply different limits; test changes with the Diagnostics tab.',
            'defaults' => [
                'temperature' => 1.0,
                'max_tokens' => 16384,
                'top_p' => null,
                'reasoning_effort' => 'max',
                'seed' => 0,
                'stream' => 1,
                'ai_client_defaults' => 1,
                'temperature_policy' => 'caller',
                'images' => 1,
                'image_urls' => 1,
                'image_max_mb' => 5,
                'image_max_count' => 4,
                'image_formats' => ['jpeg', 'png'],
            ],
        ],
    ];

    /** Readable developer names for model ID prefixes. */
    private const DEVELOPERS = [
        'meta' => 'Meta',
        'nvidia' => 'NVIDIA',
        'mistralai' => 'Mistral AI',
        'openai' => 'OpenAI',
        'qwen' => 'Qwen',
        'microsoft' => 'Microsoft',
        'google' => 'Google',
        'moonshotai' => 'Moonshot AI',
        'minimaxai' => 'MiniMax',
        'stepfun-ai' => 'StepFun',
        'z-ai' => 'Z.ai',
        'abacusai' => 'Abacus.AI',
        'bytedance' => 'ByteDance',
        'stockmark' => 'Stockmark',
        'sarvamai' => 'Sarvam AI',
        'upstage' => 'Upstage',
        'black-forest-labs' => 'Black Forest Labs',
    ];

    /** @var array<string, mixed>|null */
    private static ?array $state = null;

    /**
     * Stored model state: enabled flags, custom models, per-model settings and access status.
     *
     * @return array{enabled: array<string, bool>, custom: array<string, array<string, mixed>>, settings: array<string, array<string, mixed>>, status: array<string, array<string, mixed>>, revision: int}
     */
    private static function state(): array
    {
        if (self::$state === null) {
            $stored = get_option(self::OPTION, []);
            $stored = is_array($stored) ? $stored : [];
            self::$state = [
                'enabled' => isset($stored['enabled']) && is_array($stored['enabled']) ? $stored['enabled'] : [],
                'custom' => isset($stored['custom']) && is_array($stored['custom']) ? $stored['custom'] : [],
                'settings' => isset($stored['settings']) && is_array($stored['settings']) ? $stored['settings'] : [],
                'status' => isset($stored['status']) && is_array($stored['status']) ? $stored['status'] : [],
                'revision' => isset($stored['revision']) ? (int) $stored['revision'] : 1,
            ];
        }
        return self::$state;
    }

    /**
     * Persists the model state and bumps the revision so cached model lists are rebuilt.
     *
     * @param array<string, mixed> $state New state.
     * @param bool $bumpRevision Whether the list of models offered to the AI Client may have changed.
     */
    private static function save_state(array $state, bool $bumpRevision = true): void
    {
        if ($bumpRevision) {
            $state['revision'] = ((int) ($state['revision'] ?? 1)) + 1;
        }
        update_option(self::OPTION, $state);
        self::$state = null;
    }

    /**
     * Resets the in-memory cache.
     */
    public static function flush(): void
    {
        self::$state = null;
    }

    /**
     * Revision number that changes whenever the AI Client model list may change.
     */
    public static function revision(): int
    {
        return (int) self::state()['revision'];
    }

    /**
     * Validates the format of an NVIDIA model ID, for example "moonshotai/kimi-k3".
     *
     * @param string $id Model ID.
     */
    public static function is_valid_id(string $id): bool
    {
        return strlen($id) <= 120 && (bool) preg_match('#^[a-z0-9][a-z0-9._\-]*/[A-Za-z0-9][A-Za-z0-9._:\-]*$#', $id);
    }

    /**
     * All registered models (built-in first, in the original order, then custom models).
     *
     * @return array<string, array<string, mixed>> Descriptors keyed by model ID.
     */
    public static function all(): array
    {
        $models = [];
        foreach (self::BUILTIN as $id => $kind) {
            $models[$id] = self::describe($id, $kind, 'builtin');
        }
        foreach (self::state()['custom'] as $id => $custom) {
            if (!is_string($id) || isset($models[$id]) || !self::is_valid_id($id)) {
                continue;
            }
            $models[$id] = self::describe($id, (string) ($custom['kind'] ?? 'text'), 'custom', $custom);
        }
        return $models;
    }

    /**
     * One model descriptor, or null when the model is not registered.
     *
     * @param string $id Model ID.
     * @return array<string, mixed>|null
     */
    public static function get(string $id): ?array
    {
        $all = self::all();
        return $all[$id] ?? null;
    }

    /**
     * Enabled chat models (text and vision), with the default model first.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function enabled_chat_models(): array
    {
        $models = array_filter(self::all(), static function (array $model): bool {
            return $model['enabled'] && $model['kind'] !== 'image';
        });
        $default = (string) Settings::get('default_model');
        if (isset($models[$default])) {
            $models = [$default => $models[$default]] + $models;
        }
        return $models;
    }

    /**
     * Builds a descriptor for a model.
     *
     * @param string $id Model ID.
     * @param string $kind Model kind.
     * @param string $source builtin or custom.
     * @param array<string, mixed> $custom Custom model data.
     * @return array<string, mixed>
     */
    private static function describe(string $id, string $kind, string $source, array $custom = []): array
    {
        $kind = in_array($kind, ['text', 'vision', 'image'], true) ? $kind : 'text';
        $profile = self::PROFILES[$id] ?? [];
        $state = self::state();
        [$prefix] = explode('/', $id, 2);

        if ($source === 'custom') {
            $reasoningValues = !empty($custom['reasoning']) ? ['low', 'medium', 'high', 'max'] : [];
            $name = isset($custom['name']) && $custom['name'] !== '' ? (string) $custom['name'] : self::humanize($id);
            $developer = isset($custom['developer']) && $custom['developer'] !== '' ? (string) $custom['developer'] : (self::DEVELOPERS[$prefix] ?? $prefix);
            $requiresVerification = true;
        } else {
            $reasoningValues = $profile['reasoning_values'] ?? [];
            $name = $profile['name'] ?? self::humanize($id);
            $developer = $profile['developer'] ?? (self::DEVELOPERS[$prefix] ?? $prefix);
            $requiresVerification = !empty($profile['requires_verification']);
        }

        $capabilities = [];
        if ($kind === 'image') {
            $capabilities = ['image_generation'];
        } else {
            $capabilities = ['text', 'chat', 'streaming', 'tools'];
            if ($kind === 'vision') {
                $capabilities[] = 'vision';
            }
            if ($reasoningValues || !empty($profile['always_reasons'])) {
                $capabilities[] = 'reasoning';
            }
        }

        return [
            'id' => $id,
            'name' => $name,
            'developer' => $developer,
            'provider' => 'NVIDIA',
            'kind' => $kind,
            'source' => $source,
            'capabilities' => $capabilities,
            'reasoning_values' => $reasoningValues,
            'always_reasons' => !empty($profile['always_reasons']),
            'reasoning_passback' => !empty($profile['reasoning_passback']),
            'max_tokens_limit' => (int) ($profile['max_tokens_limit'] ?? 1048576),
            'context_window' => isset($profile['context_window']) ? (int) $profile['context_window'] : null,
            'requires_verification' => $requiresVerification,
            'notes' => (string) ($profile['notes'] ?? ''),
            'enabled' => array_key_exists($id, $state['enabled']) ? (bool) $state['enabled'][$id] : true,
            'status' => isset($state['status'][$id]) && is_array($state['status'][$id]) ? $state['status'][$id] : ['state' => 'unknown'],
        ];
    }

    /**
     * Turns a model ID into a readable name, for example "Llama 3.3 70B Instruct".
     *
     * @param string $id Model ID.
     */
    public static function humanize(string $id): string
    {
        $parts = explode('/', $id, 2);
        $slug = $parts[1] ?? $parts[0];
        $special = ['nvidia' => 'NVIDIA', 'gpt' => 'GPT', 'oss' => 'OSS', 'glm' => 'GLM', 'vl' => 'VL', 'it' => 'IT', 'm' => 'M'];
        $words = [];
        foreach (explode('-', $slug) as $token) {
            if (isset($special[$token])) {
                $words[] = $special[$token];
            } elseif (preg_match('/^\d+(\.\d+)?[bkm]$/i', $token) || preg_match('/^[a-z]\d+[a-z0-9]*$/i', $token)) {
                $words[] = strtoupper($token);
            } elseif (strpos($token, 'flux') === 0) {
                $words[] = strtoupper($token);
            } else {
                $words[] = ucfirst($token);
            }
        }
        return implode(' ', $words);
    }

    /**
     * Default settings for a model.
     *
     * @param string $id Model ID.
     * @return array<string, mixed>
     */
    public static function default_settings(string $id): array
    {
        if (isset(self::PROFILES[$id]['defaults'])) {
            return self::PROFILES[$id]['defaults'];
        }
        $model = self::get($id);
        $vision = $model !== null && $model['kind'] === 'vision';
        return [
            'temperature' => null,
            'max_tokens' => 4096,
            'top_p' => null,
            'reasoning_effort' => 'default',
            'seed' => null,
            'stream' => 1,
            'ai_client_defaults' => 0,
            'temperature_policy' => 'caller',
            'images' => $vision ? 1 : 0,
            'image_urls' => $vision ? 1 : 0,
            'image_max_mb' => 5,
            'image_max_count' => 4,
            'image_formats' => ['jpeg', 'png'],
        ];
    }

    /**
     * Effective settings for a model (saved values over defaults).
     *
     * @param string $id Model ID.
     * @return array<string, mixed>
     */
    public static function settings(string $id): array
    {
        $saved = self::state()['settings'][$id] ?? [];
        return array_merge(self::default_settings($id), is_array($saved) ? $saved : []);
    }

    /**
     * Validates and saves model settings. Nothing is saved when any value is invalid, so an
     * administrator's previous choices are never silently replaced.
     *
     * @param string $id Model ID.
     * @param array<string, mixed> $input Raw form input.
     * @return array<string, string> Validation errors keyed by field. Empty on success.
     */
    public static function save_settings(string $id, array $input): array
    {
        $model = self::get($id);
        if ($model === null) {
            return ['model' => __('Unknown model.', 'chandan-digital-ai-for-nvidia')];
        }
        [$clean, $errors] = self::validate_settings($model, $input);
        if ($errors) {
            return $errors;
        }
        $state = self::state();
        $state['settings'][$id] = $clean;
        self::save_state($state, false);
        return [];
    }

    /**
     * Validates model settings.
     *
     * @param array<string, mixed> $model Model descriptor.
     * @param array<string, mixed> $input Raw input.
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function validate_settings(array $model, array $input): array
    {
        $errors = [];
        $clean = self::settings($model['id']);

        $temperature = trim((string) ($input['temperature'] ?? ''));
        if ($temperature === '') {
            $clean['temperature'] = null;
        } elseif (!is_numeric($temperature) || (float) $temperature < 0 || (float) $temperature > 2) {
            $errors['temperature'] = __('Temperature must be a number from 0 to 2, or empty to let NVIDIA use its default.', 'chandan-digital-ai-for-nvidia');
        } else {
            $clean['temperature'] = round((float) $temperature, 3);
        }

        $maxTokens = trim((string) ($input['max_tokens'] ?? ''));
        if ($maxTokens === '') {
            $clean['max_tokens'] = null;
        } else {
            $value = filter_var($maxTokens, FILTER_VALIDATE_INT);
            if ($value === false || $value < 1 || $value > $model['max_tokens_limit']) {
                /* translators: %d: maximum number of tokens. */
                $errors['max_tokens'] = sprintf(__('Maximum output tokens must be a whole number from 1 to %d.', 'chandan-digital-ai-for-nvidia'), $model['max_tokens_limit']);
            } else {
                $clean['max_tokens'] = $value;
            }
        }

        $topP = trim((string) ($input['top_p'] ?? ''));
        if ($topP === '') {
            $clean['top_p'] = null;
        } elseif (!is_numeric($topP) || (float) $topP <= 0 || (float) $topP > 1) {
            $errors['top_p'] = __('Top P must be greater than 0 and at most 1, or empty to leave it out of the request.', 'chandan-digital-ai-for-nvidia');
        } else {
            $clean['top_p'] = round((float) $topP, 3);
        }

        $reasoning = (string) ($input['reasoning_effort'] ?? 'default');
        if ($reasoning !== 'default' && !in_array($reasoning, $model['reasoning_values'], true)) {
            $errors['reasoning_effort'] = $model['reasoning_values']
                /* translators: 1: chosen value, 2: model name, 3: list of supported values. */
                ? sprintf(__('Reasoning effort "%1$s" is not supported by %2$s. Supported values: %3$s.', 'chandan-digital-ai-for-nvidia'), $reasoning, $model['name'], implode(', ', $model['reasoning_values']))
                /* translators: %s: model name. */
                : sprintf(__('%s has no verified reasoning effort control. Keep it on Default.', 'chandan-digital-ai-for-nvidia'), $model['name']);
        } else {
            $clean['reasoning_effort'] = $reasoning;
        }

        $seed = trim((string) ($input['seed'] ?? ''));
        if ($seed === '') {
            $clean['seed'] = null;
        } else {
            $value = filter_var($seed, FILTER_VALIDATE_INT);
            if ($value === false || $value < 0 || $value > 2147483647) {
                $errors['seed'] = __('Seed must be a whole number from 0 to 2147483647, or empty to leave it out.', 'chandan-digital-ai-for-nvidia');
            } else {
                $clean['seed'] = $value;
            }
        }

        $clean['stream'] = empty($input['stream']) ? 0 : 1;
        $clean['ai_client_defaults'] = empty($input['ai_client_defaults']) ? 0 : 1;
        $policy = (string) ($input['temperature_policy'] ?? 'caller');
        $clean['temperature_policy'] = in_array($policy, ['caller', 'dashboard'], true) ? $policy : 'caller';

        if ($model['kind'] === 'vision') {
            $clean['images'] = empty($input['images']) ? 0 : 1;
            $clean['image_urls'] = empty($input['image_urls']) ? 0 : 1;
            $mb = filter_var($input['image_max_mb'] ?? $clean['image_max_mb'], FILTER_VALIDATE_INT);
            if ($mb === false || $mb < 1 || $mb > 20) {
                $errors['image_max_mb'] = __('Maximum image size must be from 1 MB to 20 MB.', 'chandan-digital-ai-for-nvidia');
            } else {
                $clean['image_max_mb'] = $mb;
            }
            $count = filter_var($input['image_max_count'] ?? $clean['image_max_count'], FILTER_VALIDATE_INT);
            if ($count === false || $count < 1 || $count > 10) {
                $errors['image_max_count'] = __('Images per request must be from 1 to 10.', 'chandan-digital-ai-for-nvidia');
            } else {
                $clean['image_max_count'] = $count;
            }
            $formats = isset($input['image_formats']) && is_array($input['image_formats']) ? array_map('strval', $input['image_formats']) : [];
            $formats = array_values(array_intersect(array_keys(self::IMAGE_FORMATS), $formats));
            if ($clean['images'] && !$formats) {
                $errors['image_formats'] = __('Choose at least one image format, or turn image input off.', 'chandan-digital-ai-for-nvidia');
            } else {
                $clean['image_formats'] = $formats;
            }
        } else {
            $clean['images'] = 0;
            $clean['image_urls'] = 0;
        }

        return [$clean, $errors];
    }

    /**
     * Enables or disables a model.
     *
     * @param string $id Model ID.
     * @param bool $enabled New state.
     */
    public static function set_enabled(string $id, bool $enabled): void
    {
        if (self::get($id) === null) {
            return;
        }
        $state = self::state();
        $state['enabled'][$id] = $enabled;
        self::save_state($state);
    }

    /**
     * Registers a custom model.
     *
     * @param string $id Model ID.
     * @param string $name Display name.
     * @param string $developer Developer name.
     * @param string $kind text or vision.
     * @param bool $reasoning Whether the model accepts reasoning_effort.
     * @return string|null Error message, or null on success.
     */
    public static function add_custom(string $id, string $name, string $developer, string $kind, bool $reasoning): ?string
    {
        $id = trim($id);
        if (!self::is_valid_id($id)) {
            return __('Enter a model ID in the form publisher/model-name, for example moonshotai/kimi-k3.', 'chandan-digital-ai-for-nvidia');
        }
        if (isset(self::BUILTIN[$id])) {
            return __('This model is already built in.', 'chandan-digital-ai-for-nvidia');
        }
        $state = self::state();
        if (count($state['custom']) >= 100) {
            return __('You can register up to 100 custom models.', 'chandan-digital-ai-for-nvidia');
        }
        $state['custom'][$id] = [
            'name' => sanitize_text_field($name),
            'developer' => sanitize_text_field($developer),
            'kind' => in_array($kind, ['text', 'vision'], true) ? $kind : 'text',
            'reasoning' => $reasoning ? 1 : 0,
            'added' => time(),
        ];
        $state['enabled'][$id] = true;
        unset($state['status'][$id]);
        self::save_state($state);
        return null;
    }

    /**
     * Removes a custom model and its settings.
     *
     * @param string $id Model ID.
     */
    public static function remove_custom(string $id): void
    {
        $state = self::state();
        unset($state['custom'][$id], $state['settings'][$id], $state['status'][$id], $state['enabled'][$id]);
        self::save_state($state);
        if (Settings::get('default_model') === $id) {
            Settings::update(['default_model' => self::KIMI_K3]);
        }
    }

    /**
     * Records the result of a model access check or a real request.
     *
     * @param string $id Model ID.
     * @param string $result verified, not_available, access_denied or error.
     * @param int $httpStatus HTTP status (0 for network errors).
     * @param string $message Short, sanitised message.
     */
    public static function set_status(string $id, string $result, int $httpStatus, string $message = ''): void
    {
        if (self::get($id) === null) {
            return;
        }
        $state = self::state();
        $previous = $state['status'][$id]['state'] ?? 'unknown';
        $state['status'][$id] = [
            'state' => $result,
            'http' => $httpStatus,
            'message' => substr($message, 0, 300),
            'time' => time(),
        ];
        // Only a change between "verified" and anything else alters the AI Client model list.
        self::save_state($state, ($previous === 'verified') !== ($result === 'verified'));
    }

    /**
     * Last refreshed NVIDIA catalogue.
     *
     * @return array{ids: list<string>, fetched_at: int}
     */
    public static function catalog(): array
    {
        $catalog = get_option(self::CATALOG_OPTION, []);
        return [
            'ids' => isset($catalog['ids']) && is_array($catalog['ids']) ? array_values(array_filter($catalog['ids'], 'is_string')) : [],
            'fetched_at' => isset($catalog['fetched_at']) ? (int) $catalog['fetched_at'] : 0,
        ];
    }

    /**
     * Stores a refreshed catalogue.
     *
     * @param list<string> $ids Model IDs returned by NVIDIA.
     */
    public static function save_catalog(array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids, static function ($id): bool {
            return is_string($id) && self::is_valid_id($id);
        })));
        sort($ids);
        update_option(self::CATALOG_OPTION, ['ids' => $ids, 'fetched_at' => time()], false);
    }
}
