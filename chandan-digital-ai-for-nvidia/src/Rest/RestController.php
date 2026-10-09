<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Rest;

use ChandanDigital\NvidiaAi\Api\ApiError;
use ChandanDigital\NvidiaAi\Api\NvidiaClient;
use ChandanDigital\NvidiaAi\Api\PayloadBuilder;
use ChandanDigital\NvidiaAi\Support\Logger;
use ChandanDigital\NvidiaAi\Support\ModelRegistry;
use ChandanDigital\NvidiaAi\Support\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST endpoints used by the plugin's admin screens.
 *
 * Every route needs a logged-in user with the right capability and a valid REST nonce (sent by the
 * admin JavaScript as X-WP-Nonce), so they cannot be called by visitors or from other sites. The
 * NVIDIA API key stays on the server; the browser only ever talks to these endpoints.
 *
 * @since 1.1.0
 */
final class RestController
{
    public const NAMESPACE = 'chandan-digital-ai/v1';

    /**
     * Registers routes.
     */
    public static function register_routes(): void
    {
        $admin = static function (): bool {
            return current_user_can('manage_options');
        };
        $playground = static function (): bool {
            return current_user_can(Settings::playground_capability());
        };
        $chatArgs = [
            'model' => ['type' => 'string', 'required' => true],
            'messages' => ['type' => 'array', 'required' => true],
            'system' => ['type' => 'string', 'default' => ''],
            'policy' => ['type' => 'boolean', 'default' => false],
            'skill' => ['type' => 'string', 'default' => ''],
            'request_id' => ['type' => 'string', 'required' => true],
        ];

        register_rest_route(self::NAMESPACE, '/connection-test', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'connection_test'],
            'permission_callback' => $admin,
            'args' => ['model' => ['type' => 'string', 'default' => '']],
        ]);
        register_rest_route(self::NAMESPACE, '/models/verify', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'verify_model'],
            'permission_callback' => $admin,
            'args' => ['model' => ['type' => 'string', 'required' => true]],
        ]);
        register_rest_route(self::NAMESPACE, '/models/refresh', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'refresh_models'],
            'permission_callback' => $admin,
        ]);
        register_rest_route(self::NAMESPACE, '/chat', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'chat'],
            'permission_callback' => $playground,
            'args' => $chatArgs,
        ]);
        register_rest_route(self::NAMESPACE, '/chat/stream', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'chat_stream'],
            'permission_callback' => $playground,
            'args' => $chatArgs,
        ]);
        register_rest_route(self::NAMESPACE, '/playground/system-prompt', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'save_system_prompt'],
            'permission_callback' => $playground,
            'args' => ['system_prompt' => ['type' => 'string', 'required' => true]],
        ]);
        register_rest_route(self::NAMESPACE, '/stream-test', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'stream_test'],
            'permission_callback' => $admin,
        ]);
    }

    /** User meta holding each user's saved Playground system prompt. */
    public const SYSTEM_PROMPT_META = 'cdnv_playground_system_prompt';

    /** Longest system prompt that can be saved, in characters. */
    private const SYSTEM_PROMPT_MAX = 20000;

    /**
     * The current user's saved Playground system prompt.
     */
    public static function saved_system_prompt(): string
    {
        $value = get_user_meta(get_current_user_id(), self::SYSTEM_PROMPT_META, true);
        return is_string($value) ? $value : '';
    }

    /**
     * Saves the Playground system prompt for the current user, so it is there next time.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function save_system_prompt(WP_REST_Request $request): WP_REST_Response
    {
        $value = trim(sanitize_textarea_field((string) $request->get_param('system_prompt')));
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length > self::SYSTEM_PROMPT_MAX) {
            /* translators: %d: maximum number of characters. */
            return self::error_response(new ApiError('invalid_input', 0, '', null, sprintf(__('The system prompt is too long. Keep it under %d characters.', 'chandan-digital-ai-for-nvidia'), self::SYSTEM_PROMPT_MAX)), 400);
        }
        if ($value === '') {
            delete_user_meta(get_current_user_id(), self::SYSTEM_PROMPT_META);
        } else {
            update_user_meta(get_current_user_id(), self::SYSTEM_PROMPT_META, $value);
        }
        return new WP_REST_Response(['ok' => true], 200);
    }

    /**
     * Tests the API key, the endpoint and access to one model, keeping key errors apart from
     * model-access errors.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function connection_test(WP_REST_Request $request): WP_REST_Response
    {
        $modelId = (string) $request->get_param('model');
        if ($modelId === '' || ModelRegistry::get($modelId) === null) {
            $modelId = (string) Settings::get('default_model');
        }
        $model = ModelRegistry::get($modelId);

        $result = [
            'endpoint' => Settings::chat_url(),
            'key_source' => Settings::key_source_label(Settings::key_source()),
            'steps' => [],
            'ok' => false,
            'summary' => '',
        ];

        $client = NvidiaClient::create();
        if ($client instanceof ApiError) {
            $result['steps'][] = self::step('api_key', __('API key', 'chandan-digital-ai-for-nvidia'), false, 0, 0, $client);
            $result['summary'] = $client->message;
            Settings::save_status(['ok' => false, 'code' => $client->code, 'http' => 0, 'message' => $client->message, 'model' => $modelId]);
            return new WP_REST_Response($result, 200);
        }

        $list = $client->list_models();
        if ($list['ok']) {
            ModelRegistry::save_catalog($list['ids']);
        }
        $catalogNote = $list['ok']
            /* translators: %d: number of models. */
            ? sprintf(__('NVIDIA listed %d models in its public catalogue.', 'chandan-digital-ai-for-nvidia'), count($list['ids']))
            : '';
        $result['steps'][] = self::step('catalogue', __('API endpoint reachable', 'chandan-digital-ai-for-nvidia'), $list['ok'], $list['status'], $list['duration_ms'], $list['error'], $catalogNote);

        $probe = $client->probe($modelId);
        $error = $probe['error'];
        if ($error === null || (!$error->is_key_error() && $probe['status'] > 0)) {
            // NVIDIA answered with something other than an authentication error, so the key works.
            $keyOk = true;
            $keyNote = __('NVIDIA accepted the API key.', 'chandan-digital-ai-for-nvidia');
        } elseif ($error->is_key_error()) {
            $keyOk = false;
            $keyNote = '';
        } else {
            // No response arrived (network problem), so the key could not be checked.
            $keyOk = null;
            $keyNote = __('Not checked, because NVIDIA could not be reached.', 'chandan-digital-ai-for-nvidia');
        }
        $result['steps'][] = self::step('api_key', __('API key accepted', 'chandan-digital-ai-for-nvidia'), $keyOk, $probe['status'], $probe['duration_ms'], $keyOk === true ? null : $error, $keyNote);
        if ($keyOk === true) {
            /* translators: %s: model name. */
            $label = sprintf(__('Access to %s', 'chandan-digital-ai-for-nvidia'), $model !== null ? $model['name'] : $modelId);
            $result['steps'][] = self::step('model', $label, $error === null, $probe['status'], $probe['duration_ms'], $error);
        }
        self::record_model_status($modelId, $probe['status'], $error);

        $result['ok'] = $error === null;
        $result['summary'] = $error === null
            ? __('Connection works: the key is valid and the model answered.', 'chandan-digital-ai-for-nvidia')
            : $error->message;

        Settings::save_status([
            'ok' => $result['ok'],
            'code' => $error !== null ? $error->code : '',
            'http' => $probe['status'],
            'message' => $result['summary'],
            'model' => $modelId,
        ]);
        Logger::log([
            'type' => 'connection_test',
            'model' => $modelId,
            'status' => $probe['status'],
            'duration_ms' => $probe['duration_ms'],
            'error_code' => $error !== null ? $error->code : '',
            'error' => $error !== null ? $error->summary() : '',
        ]);

        return new WP_REST_Response($result, 200);
    }

    /**
     * Checks that the API key can call one model.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function verify_model(WP_REST_Request $request): WP_REST_Response
    {
        $modelId = (string) $request->get_param('model');
        $model = ModelRegistry::get($modelId);
        if ($model === null) {
            return self::error_response(new ApiError('invalid_input', 0, '', null, __('Unknown model.', 'chandan-digital-ai-for-nvidia')), 400);
        }
        if ($model['kind'] === 'image') {
            return self::error_response(new ApiError('invalid_input', 0, '', null, __('Image generation models use a separate NVIDIA endpoint and cannot be checked here. They are used through the WordPress AI Client.', 'chandan-digital-ai-for-nvidia')), 400);
        }
        $client = NvidiaClient::create();
        if ($client instanceof ApiError) {
            return self::error_response($client, 400);
        }
        $probe = $client->probe($modelId);
        self::record_model_status($modelId, $probe['status'], $probe['error']);
        Logger::log([
            'type' => 'verify_model',
            'model' => $modelId,
            'status' => $probe['status'],
            'duration_ms' => $probe['duration_ms'],
            'error_code' => $probe['error'] !== null ? $probe['error']->code : '',
            'error' => $probe['error'] !== null ? $probe['error']->summary() : '',
        ]);
        ModelRegistry::flush();
        $updated = ModelRegistry::get($modelId);

        return new WP_REST_Response([
            'ok' => $probe['error'] === null,
            'model' => $modelId,
            'http' => $probe['status'],
            'duration_ms' => $probe['duration_ms'],
            'status' => $updated !== null ? $updated['status'] : [],
            'error' => $probe['error'] !== null ? $probe['error']->to_array() : null,
        ], 200);
    }

    /**
     * Refreshes NVIDIA's public model catalogue (administrator action only).
     */
    public static function refresh_models(): WP_REST_Response
    {
        $client = NvidiaClient::create();
        if ($client instanceof ApiError) {
            return self::error_response($client, 400);
        }
        $list = $client->list_models();
        Logger::log([
            'type' => 'refresh_models',
            'status' => $list['status'],
            'duration_ms' => $list['duration_ms'],
            'error_code' => $list['error'] !== null ? $list['error']->code : '',
            'error' => $list['error'] !== null ? $list['error']->summary() : '',
        ]);
        if (!$list['ok']) {
            return self::error_response($list['error'] ?? new ApiError('malformed_response'), 502);
        }
        ModelRegistry::save_catalog($list['ids']);
        $registered = array_keys(ModelRegistry::all());
        return new WP_REST_Response([
            'ok' => true,
            'count' => count($list['ids']),
            'unregistered' => array_values(array_diff(ModelRegistry::catalog()['ids'], $registered)),
            'missing' => array_values(array_filter($registered, static function (string $id) use ($list): bool {
                $model = ModelRegistry::get($id);
                return $model !== null && $model['kind'] !== 'image' && !in_array($id, $list['ids'], true);
            })),
        ], 200);
    }

    /**
     * Playground request without streaming (also the fallback when streaming is unavailable).
     *
     * @param WP_REST_Request $request Request.
     */
    public static function chat(WP_REST_Request $request): WP_REST_Response
    {
        $prepared = self::prepare_chat($request, false);
        if ($prepared instanceof WP_REST_Response) {
            return $prepared;
        }
        [$client, $model, $payload, $notices] = $prepared;

        $result = $client->chat($payload);
        self::record_model_status($model['id'], $result['status'], $result['error']);
        self::log_chat('playground', $model['id'], $payload, $result);

        if ($result['error'] !== null) {
            return self::error_response($result['error'], 502);
        }
        if (!empty($result['garbled_retries'])) {
            $notices[] = self::retry_notice();
        }
        return new WP_REST_Response([
            'ok' => true,
            'model' => $model['id'],
            'content' => $result['content'],
            'reasoning' => $result['reasoning'],
            'finish_reason' => $result['finish_reason'],
            'usage' => $result['usage'],
            'duration_ms' => $result['duration_ms'],
            'notices' => $notices,
        ], 200);
    }

    /**
     * Playground request with streaming. Responds with text/event-stream and ends the request itself.
     *
     * Events sent to the browser: meta, reasoning, content, done and error. Each data line is JSON.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response Only returned when the request is rejected before streaming starts.
     */
    public static function chat_stream(WP_REST_Request $request): WP_REST_Response
    {
        $prepared = self::prepare_chat($request, true);
        if ($prepared instanceof WP_REST_Response) {
            return $prepared;
        }
        [$client, $model, $payload, $notices] = $prepared;

        self::start_event_stream();
        self::send_event('meta', ['model' => $model['id'], 'notices' => $notices]);

        $lastOutput = microtime(true);
        $onText = static function (string $channel, string $text) use (&$lastOutput): void {
            self::send_event($channel === 'reasoning' ? 'reasoning' : 'content', ['text' => $text]);
            $lastOutput = microtime(true);
        };
        $onTick = static function () use (&$lastOutput): bool {
            if (microtime(true) - $lastOutput > 10) {
                // A comment line keeps proxies from closing an idle connection and lets PHP notice
                // when the browser has gone away.
                echo ": keep-alive\n\n";
                self::flush_output();
                $lastOutput = microtime(true);
            }
            return !connection_aborted();
        };

        $onRetry = static function (string $reason) use (&$lastOutput): void {
            // Tell the browser to throw away what it has shown before the request is sent again.
            self::send_event('reset', ['reason' => $reason, 'message' => self::retry_notice()]);
            $lastOutput = microtime(true);
        };

        $result = $client->stream($payload, $onText, $onTick, $onRetry);
        self::record_model_status($model['id'], $result['status'], $result['error']);
        self::log_chat('playground_stream', $model['id'], $payload, $result);

        if ($result['error'] !== null) {
            $error = $result['error']->to_array();
            $error['partial'] = $result['content'] !== '' || $result['reasoning'] !== '';
            self::send_event('error', $error);
        } else {
            self::send_event('done', [
                'finish_reason' => $result['finish_reason'],
                'usage' => $result['usage'],
                'duration_ms' => $result['duration_ms'],
                'live' => $result['streamed'],
            ]);
        }
        exit;
    }

    /**
     * Sends five events about 400 ms apart, without contacting NVIDIA, so the browser can measure
     * whether this server and any proxy in front of it deliver streamed output progressively.
     */
    public static function stream_test(): WP_REST_Response
    {
        self::start_event_stream();
        for ($i = 1; $i <= 5; $i++) {
            self::send_event('tick', ['n' => $i]);
            if ($i < 5) {
                usleep(400000);
            }
            if (connection_aborted()) {
                exit;
            }
        }
        self::send_event('done', ['n' => 5]);
        exit;
    }

    /**
     * Note shown when a garbled reply was thrown away and the request sent again.
     */
    private static function retry_notice(): string
    {
        return __('The first reply came back as garbled text, so the plugin threw it away and asked again.', 'chandan-digital-ai-for-nvidia');
    }

    /**
     * Validates a Playground request and builds the NVIDIA payload.
     *
     * @param WP_REST_Request $request Request.
     * @param bool $stream Whether the request will be streamed.
     * @return WP_REST_Response|array{0: NvidiaClient, 1: array<string, mixed>, 2: array<string, mixed>, 3: list<string>}
     */
    private static function prepare_chat(WP_REST_Request $request, bool $stream)
    {
        $requestId = (string) $request->get_param('request_id');
        if (!preg_match('/^[A-Za-z0-9\-]{8,64}$/', $requestId)) {
            return self::error_response(new ApiError('invalid_input', 0, '', null, __('Missing request ID.', 'chandan-digital-ai-for-nvidia')), 400);
        }
        // Each request ID is accepted once, so a double click or a resent form cannot run twice.
        $lockKey = 'cdnv_req_' . md5(get_current_user_id() . '|' . $requestId);
        if (get_transient($lockKey)) {
            return self::error_response(new ApiError('duplicate_request'), 409);
        }
        set_transient($lockKey, 1, 10 * MINUTE_IN_SECONDS);

        $model = ModelRegistry::get((string) $request->get_param('model'));
        if ($model === null || !$model['enabled'] || $model['kind'] === 'image') {
            return self::error_response(new ApiError('invalid_input', 0, '', null, __('Choose an enabled chat model.', 'chandan-digital-ai-for-nvidia')), 400);
        }

        $messages = $request->get_param('messages');
        if (is_array($messages) && self::has_uploaded_image($messages) && !current_user_can('upload_files')) {
            return self::error_response(new ApiError('invalid_input', 0, '', null, __('You need permission to upload files to send images.', 'chandan-digital-ai-for-nvidia')), 403);
        }

        $client = NvidiaClient::create();
        if ($client instanceof ApiError) {
            return self::error_response($client, 400);
        }

        $system = (string) $request->get_param('system');
        if (strlen($system) > 20000 || !wp_check_invalid_utf8($system) && $system !== '') {
            return self::error_response(new ApiError('invalid_input', 0, '', null, __('The system prompt is too long or contains invalid characters.', 'chandan-digital-ai-for-nvidia')), 400);
        }

        $builder = new PayloadBuilder();
        $payload = $builder->chat(
            $model,
            ModelRegistry::settings($model['id']),
            $messages,
            $stream,
            $system,
            (bool) $request->get_param('policy'),
            (string) $request->get_param('skill')
        );
        if ($payload instanceof ApiError) {
            return self::error_response($payload, 400);
        }

        return [$client, $model, $payload, $builder->notices];
    }

    /**
     * @param array<int, mixed> $messages Conversation.
     */
    private static function has_uploaded_image(array $messages): bool
    {
        foreach ($messages as $message) {
            foreach ((is_array($message) && isset($message['images']) && is_array($message['images'])) ? $message['images'] : [] as $image) {
                if (is_array($image) && ($image['type'] ?? '') !== 'url') {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Updates a model's access status after a real request.
     *
     * @param string $modelId Model ID.
     * @param int $status HTTP status.
     * @param ApiError|null $error Error, if any.
     */
    public static function record_model_status(string $modelId, int $status, ?ApiError $error): void
    {
        if ($error === null) {
            $model = ModelRegistry::get($modelId);
            if ($model !== null && ($model['status']['state'] ?? '') !== 'verified') {
                ModelRegistry::set_status($modelId, 'verified', $status);
            }
            return;
        }
        if (in_array($error->code, ['model_not_available', 'invalid_model'], true)) {
            ModelRegistry::set_status($modelId, 'not_available', $status, $error->summary());
        } elseif ($error->code === 'access_denied') {
            ModelRegistry::set_status($modelId, 'access_denied', $status, $error->summary());
        }
    }

    /**
     * Writes a log entry for a Playground request.
     *
     * @param string $type Log type.
     * @param string $modelId Model ID.
     * @param array<string, mixed> $payload Request payload (only the last user text is logged, and only when content logging is on).
     * @param array<string, mixed> $result Result.
     */
    private static function log_chat(string $type, string $modelId, array $payload, array $result): void
    {
        $prompt = '';
        $last = end($payload['messages']);
        if (is_array($last)) {
            if (is_string($last['content'])) {
                $prompt = $last['content'];
            } elseif (is_array($last['content'])) {
                foreach ($last['content'] as $part) {
                    if (($part['type'] ?? '') === 'text') {
                        $prompt .= (string) $part['text'];
                    } else {
                        $prompt .= ' [image]';
                    }
                }
            }
        }
        $error = $result['error'];
        Logger::log([
            'type' => $type,
            'model' => $modelId,
            'status' => $result['status'],
            'duration_ms' => $result['duration_ms'],
            'error_code' => $error instanceof ApiError ? $error->code : '',
            'error' => $error instanceof ApiError ? $error->summary() : '',
            'prompt' => $prompt,
            'response' => (string) $result['content'],
        ]);
    }

    /**
     * Builds a step entry for the connection test.
     *
     * @param string $id Step ID.
     * @param string $label Label.
     * @param bool|null $ok Result, or null when the step could not be checked.
     * @param int $http HTTP status.
     * @param int $ms Duration.
     * @param ApiError|null $error Error.
     * @param string $note Extra note.
     * @return array<string, mixed>
     */
    private static function step(string $id, string $label, ?bool $ok, int $http, int $ms, ?ApiError $error, string $note = ''): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'ok' => $ok,
            'http' => $http,
            'duration_ms' => $ms,
            'note' => $note,
            'error' => $error !== null ? $error->to_array() : null,
        ];
    }

    /**
     * @param ApiError $error Error.
     * @param int $status HTTP status for the browser.
     */
    private static function error_response(ApiError $error, int $status): WP_REST_Response
    {
        return new WP_REST_Response(['ok' => false, 'error' => $error->to_array()], $status);
    }

    /**
     * Prepares PHP and the web server for a streamed response.
     */
    private static function start_event_stream(): void
    {
        // Keep running briefly after a disconnect so the upstream request can be stopped and logged.
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit((int) Settings::get('timeout') + 60); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- not allowed on some hosts.
        }
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1'); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        @ini_set('zlib.output_compression', '0'); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.IniSet.Risky
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            status_header(200);
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('X-Accel-Buffering: no');
            header('X-Content-Type-Options: nosniff');
            header('Content-Encoding: none');
        }
        // Padding helps some proxies start forwarding immediately.
        echo ':' . str_repeat(' ', 2048) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SSE comment padding.
        self::flush_output();
    }

    /**
     * Sends one SSE event with a JSON payload.
     *
     * @param string $event Event name.
     * @param array<string, mixed> $data Data.
     */
    private static function send_event(string $event, array $data): void
    {
        $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = wp_json_encode(['text' => '']);
        }
        echo 'event: ' . $event . "\n" . 'data: ' . $json . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON in an SSE stream, rendered as text by the browser.
        self::flush_output();
    }

    private static function flush_output(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        flush();
    }
}
