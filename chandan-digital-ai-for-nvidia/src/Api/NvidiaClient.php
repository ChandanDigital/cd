<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Api;

use ChandanDigital\NvidiaAi\Support\Logger;
use ChandanDigital\NvidiaAi\Support\Settings;

use const ChandanDigital\NvidiaAi\VERSION;

/**
 * Direct NVIDIA API client used by the dashboard (Playground, model checks and diagnostics).
 *
 * All requests go through the WordPress HTTP API, so proxy settings, SSL certificates and
 * WP_HTTP_BLOCK_EXTERNAL rules apply. The API key is only ever placed in the Authorization header
 * of requests to the configured NVIDIA base URL. Redirects are not followed, so the key cannot be
 * forwarded to another host.
 *
 * Streaming uses the documented Requests "request.progress" hook, which hands over each body chunk
 * as it arrives. When the cURL transport is in use, the "http_api_curl" action also adds a progress
 * callback so a cancelled browser request stops the upstream transfer. Without cURL, the body is
 * parsed after it has fully arrived (a buffered fallback).
 *
 * @since 1.1.0
 */
final class NvidiaClient
{
    private const MARKER_ARG = 'cdnv_request_marker';
    private const NON_STREAM_LIMIT = 16777216;
    private const STREAM_LIMIT = 4194304;
    private const ERROR_BODY_LIMIT = 65536;

    /** How many times a garbled reply is sent again automatically. */
    private const GARBLED_RETRIES = 1;

    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $connectTimeout;
    private int $maxRetries;
    private int $maxRetryWait;

    /**
     * @param string $apiKey API key.
     * @param string $baseUrl Base URL without trailing slash.
     * @param int $timeout Total request timeout in seconds.
     * @param int $connectTimeout Connection timeout in seconds (cURL only).
     * @param int $maxRetries Maximum automatic retries for temporary failures.
     * @param int $maxRetryWait Longest wait before a retry, in seconds.
     */
    public function __construct(string $apiKey, string $baseUrl, int $timeout, int $connectTimeout, int $maxRetries, int $maxRetryWait)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = untrailingslashit($baseUrl);
        $this->timeout = max(5, $timeout);
        $this->connectTimeout = max(1, $connectTimeout);
        $this->maxRetries = max(0, min(3, $maxRetries));
        $this->maxRetryWait = max(0, $maxRetryWait);
    }

    /**
     * Creates a client from the saved settings.
     *
     * @return self|ApiError
     */
    public static function create()
    {
        $key = Settings::api_key();
        if ($key === '') {
            return new ApiError('missing_api_key');
        }
        return new self(
            $key,
            Settings::base_url(),
            (int) Settings::get('timeout'),
            (int) Settings::get('connect_timeout'),
            (int) Settings::get('max_retries'),
            (int) Settings::get('max_retry_wait')
        );
    }

    /**
     * Applies the connection timeout to this plugin's cURL requests. Hooked to http_api_curl.
     *
     * @param mixed $handle cURL handle.
     * @param array<string, mixed> $args Request arguments.
     */
    public static function configure_curl($handle, $args): void
    {
        if (!is_array($args) || empty($args[self::MARKER_ARG]) || empty($args['cdnv_connect_timeout'])) {
            return;
        }
        if (function_exists('curl_setopt') && defined('CURLOPT_CONNECTTIMEOUT')) {
            curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, (int) $args['cdnv_connect_timeout']);
        }
    }

    /**
     * Lists model IDs from NVIDIA's /models endpoint.
     *
     * NVIDIA returns its whole public catalogue here, whatever the key. Being listed does not prove
     * that a key can call a model; use probe() for that.
     *
     * @return array{ok: bool, status: int, ids: list<string>, duration_ms: int, error: ApiError|null}
     */
    public function list_models(): array
    {
        $result = $this->send($this->baseUrl . '/models', $this->args('GET', null, false), 'models');
        $out = ['ok' => false, 'status' => $result['status'], 'ids' => [], 'duration_ms' => $result['duration_ms'], 'error' => $result['error']];
        if ($result['error'] !== null) {
            return $out;
        }
        $data = json_decode($result['body'], true);
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
            $out['error'] = new ApiError('malformed_response', $result['status'], ApiError::extract_detail($result['body']));
            return $out;
        }
        foreach ($data['data'] as $model) {
            if (is_array($model) && isset($model['id']) && is_string($model['id'])) {
                $out['ids'][] = $model['id'];
            }
        }
        $out['ok'] = true;
        return $out;
    }

    /**
     * Generation settings per FLUX model, matching the original plugin's image model.
     */
    private const FLUX_PROFILES = [
        'black-forest-labs/flux.1-schnell' => ['cfg_scale' => 0.0, 'steps' => 4],
        'black-forest-labs/flux.1-dev' => ['cfg_scale' => 3.5, 'steps' => 28],
        'black-forest-labs/flux.2-klein-4b' => ['cfg_scale' => 1.0, 'steps' => 4],
    ];

    /**
     * Generates one image with a FLUX model through NVIDIA's GenAI endpoint.
     *
     * @since 1.2.0
     *
     * @param string $modelId FLUX model ID.
     * @param string $prompt Image prompt.
     * @param int $width Width: 1024, 1344 or 896.
     * @param int $height Height: 1024, 896 or 1344.
     * @return array{ok: bool, status: int, bytes: string, duration_ms: int, error: ApiError|null}
     */
    public function generate_image(string $modelId, string $prompt, int $width = 1344, int $height = 896): array
    {
        $out = ['ok' => false, 'status' => 0, 'bytes' => '', 'duration_ms' => 0, 'error' => null];
        $profile = self::FLUX_PROFILES[$modelId] ?? ['cfg_scale' => 3.5, 'steps' => 25];
        $body = wp_json_encode([
            'prompt' => $prompt,
            'cfg_scale' => $profile['cfg_scale'],
            'width' => $width,
            'height' => $height,
            'steps' => $profile['steps'],
        ]);
        if ($body === false) {
            $out['error'] = new ApiError('invalid_input');
            return $out;
        }
        $args = $this->args('POST', $body, false);
        // Image generation is slower than chat, including cold starts.
        $args['timeout'] = max($this->timeout, 60);
        $result = $this->send(Settings::genai_base_url() . '/' . $modelId, $args, 'chat');
        $out['status'] = $result['status'];
        $out['duration_ms'] = $result['duration_ms'];
        if ($result['error'] !== null) {
            $out['error'] = $result['error'];
            return $out;
        }
        $data = json_decode($result['body'], true);
        $artifact = is_array($data) && isset($data['artifacts'][0]) && is_array($data['artifacts'][0]) ? $data['artifacts'][0] : null;
        if ($artifact === null || !isset($artifact['base64']) || !is_string($artifact['base64'])) {
            $out['error'] = new ApiError('malformed_response', $result['status'], ApiError::extract_detail($result['body']));
            return $out;
        }
        if (isset($artifact['finishReason']) && is_string($artifact['finishReason']) && stripos($artifact['finishReason'], 'filter') !== false) {
            $out['error'] = new ApiError('invalid_request', $result['status'], 'The image was blocked by NVIDIA\'s content filter.', null, __('NVIDIA\'s safety filter blocked this image. Change the prompt and try again.', 'chandan-digital-ai-for-nvidia'));
            return $out;
        }
        $bytes = base64_decode($artifact['base64'], true);
        if ($bytes === false || @getimagesizefromstring($bytes) === false) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- invalid data is reported below.
            $out['error'] = new ApiError('malformed_response', $result['status'], 'The image data could not be read.');
            return $out;
        }
        $out['ok'] = true;
        $out['bytes'] = $bytes;
        return $out;
    }

    /**
     * Checks that the API key can call a model, with a tiny request (a few output tokens).
     *
     * @param string $modelId Model ID.
     * @return array<string, mixed> Result from chat().
     */
    public function probe(string $modelId): array
    {
        return $this->chat([
            'model' => $modelId,
            'messages' => [['role' => 'user', 'content' => 'Reply with the single word OK.']],
            'max_tokens' => 16,
        ]);
    }

    /**
     * Sends a non-streamed chat completion request.
     *
     * @param array<string, mixed> $payload Request body.
     * @return array{ok: bool, status: int, content: string, reasoning: string, finish_reason: string|null, usage: array<string, int>|null, model: string, duration_ms: int, error: ApiError|null}
     */
    public function chat(array $payload): array
    {
        $payload['stream'] = false;
        $out = [
            'ok' => false,
            'status' => 0,
            'content' => '',
            'reasoning' => '',
            'finish_reason' => null,
            'usage' => null,
            'model' => (string) ($payload['model'] ?? ''),
            'duration_ms' => 0,
            'error' => null,
            'garbled_retries' => 0,
        ];
        $body = wp_json_encode($payload);
        if ($body === false) {
            $out['error'] = new ApiError('invalid_input', 0, '', null, __('The request contains characters that cannot be sent. Remove unusual characters and try again.', 'chandan-digital-ai-for-nvidia'));
            return $out;
        }

        $start = microtime(true);
        for ($garbledRetries = 0; ; $garbledRetries++) {
            $result = $this->send($this->baseUrl . '/chat/completions', $this->args('POST', $body, false), 'chat');
            $out['status'] = $result['status'];
            $out['duration_ms'] = self::ms($start);
            $out['garbled_retries'] = $garbledRetries;
            if ($result['error'] !== null) {
                $out['error'] = $result['error'];
                return $out;
            }

            $parsed = array_merge($out, self::parse_completion($result['body'], $result['status']), [
                'status' => $result['status'],
                'duration_ms' => self::ms($start),
                'garbled_retries' => $garbledRetries,
            ]);
            // A garbled reply is usually a one-off on NVIDIA's side, so ask once more.
            if ($parsed['error'] !== null && $parsed['error']->code === 'garbled_output' && $garbledRetries < self::GARBLED_RETRIES) {
                continue;
            }
            $parsed['ok'] = $parsed['error'] === null;
            return $parsed;
        }
    }

    /**
     * Sends a streamed chat completion request and reports text as it arrives.
     *
     * @param array<string, mixed> $payload Request body.
     * @param callable(string, string): void $onText Receives (channel, text); channel is content or reasoning.
     * @param callable(): bool $onTick Called regularly while waiting; return false to cancel the request.
     * @param callable(string): void|null $onRetry Called before the request is sent again after a garbled
     *                                          reply, so the caller can clear what it has shown.
     * @return array<string, mixed> Same shape as chat(), plus "streamed" (whether chunks arrived live).
     */
    public function stream(array $payload, callable $onText, callable $onTick, ?callable $onRetry = null): array
    {
        $payload['stream'] = true;
        $body = wp_json_encode($payload);
        $out = [
            'ok' => false,
            'status' => 0,
            'content' => '',
            'reasoning' => '',
            'finish_reason' => null,
            'usage' => null,
            'model' => (string) ($payload['model'] ?? ''),
            'duration_ms' => 0,
            'error' => null,
            'streamed' => false,
            'garbled_retries' => 0,
        ];
        if ($body === false) {
            $out['error'] = new ApiError('invalid_input', 0, '', null, __('The request contains characters that cannot be sent. Remove unusual characters and try again.', 'chandan-digital-ai-for-nvidia'));
            return $out;
        }

        $start = microtime(true);
        $garbledRetries = 0;
        for ($attempt = 0; ; $attempt++) {
            $result = $this->stream_once($body, $onText, $onTick);
            $error = $result['error'];
            // A garbled reply is usually a one-off on NVIDIA's side, so ask once more straight away.
            if ($error instanceof ApiError && $error->code === 'garbled_output' && $garbledRetries < self::GARBLED_RETRIES) {
                $garbledRetries++;
                if ($onRetry !== null) {
                    $onRetry('garbled_output');
                }
                if ($onTick() === false) {
                    $result['error'] = new ApiError('cancelled');
                    break;
                }
                continue;
            }
            $canRetry = $error instanceof ApiError
                && $error->is_retryable()
                && !$result['received_any']
                && $attempt < $this->maxRetries;
            if (!$canRetry) {
                break;
            }
            $wait = $error->retryAfter ?? (int) pow(2, $attempt);
            if ($wait > $this->maxRetryWait || (microtime(true) - $start) + $wait > $this->timeout) {
                break;
            }
            sleep(max(1, $wait));
            if ($onTick() === false) {
                $result['error'] = new ApiError('cancelled');
                break;
            }
        }

        $out = array_merge($out, $result);
        $out['garbled_retries'] = $garbledRetries;
        unset($out['received_any']);
        $out['duration_ms'] = (int) round((microtime(true) - $start) * 1000);
        $out['ok'] = $out['error'] === null;
        return $out;
    }

    /**
     * One streaming attempt.
     *
     * @param string $body JSON body.
     * @param callable(string, string): void $onText Text callback.
     * @param callable(): bool $onTick Tick callback.
     * @return array<string, mixed>
     */
    private function stream_once(string $body, callable $onText, callable $onTick): array
    {
        $state = new StreamState();
        $parser = new SseParser();
        $marker = wp_generate_uuid4();
        $handle = null;
        $mode = null;
        $raw = '';
        $aborted = false;
        $live = false;

        $curlHook = static function ($curl, $args) use ($marker, &$handle, &$aborted, $onTick, $state): void {
            if (!is_array($args) || ($args[self::MARKER_ARG] ?? '') !== $marker) {
                return;
            }
            $handle = $curl;
            if (defined('CURLOPT_NOPROGRESS') && defined('CURLOPT_PROGRESSFUNCTION')) {
                curl_setopt($curl, CURLOPT_NOPROGRESS, false);
                curl_setopt($curl, CURLOPT_PROGRESSFUNCTION, static function () use (&$aborted, $onTick, $state): int {
                    if ($state->error !== null) {
                        return 1;
                    }
                    if ($onTick() === false) {
                        $aborted = true;
                        return 1;
                    }
                    return 0;
                });
            }
        };

        // Requests passes only the chunk to this hook. It is attached just for this call and only acts
        // once the marked cURL handle above has been seen, so it cannot pick up other requests.
        $progressHook = static function ($data) use (&$handle, &$mode, &$raw, &$live, $parser, $state, $onText): void {
            if (!is_string($data) || $data === '') {
                return;
            }
            if ($handle === null) {
                // Not the cURL transport: chunks may still carry transfer encoding, so parse the decoded body later.
                return;
            }
            if ($mode === null) {
                $status = function_exists('curl_getinfo') ? (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) : 0;
                $peek = ltrim($raw . $data);
                if ($peek === '') {
                    $raw .= $data;
                    return;
                }
                // Error responses and non-streamed replies are JSON (or HTML from a proxy), not SSE.
                $mode = ($status >= 400 || in_array($peek[0], ['{', '[', '<'], true)) ? 'raw' : 'sse';
                $data = $raw . $data;
                $raw = '';
            }
            if ($mode === 'raw') {
                if (strlen($raw) < self::NON_STREAM_LIMIT) {
                    $raw .= $data;
                }
                return;
            }
            $live = true;
            try {
                foreach ($parser->push($data) as $event) {
                    $state->handle($event, $onText);
                }
            } catch (\RuntimeException $e) {
                $state->error = new ApiError('malformed_response', 200, 'Stream event too large.');
            }
        };

        add_action('http_api_curl', $curlHook, 20, 2);
        add_action('requests-request.progress', $progressHook, 10, 1);
        $args = $this->args('POST', $body, true, [
            self::MARKER_ARG => $marker,
            'limit_response_size' => self::STREAM_LIMIT,
        ]);
        $response = wp_remote_request($this->baseUrl . '/chat/completions', $args);
        remove_action('http_api_curl', $curlHook, 20);
        remove_action('requests-request.progress', $progressHook, 10);

        $result = [
            'status' => is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response),
            'content' => '',
            'reasoning' => '',
            'finish_reason' => null,
            'usage' => null,
            'error' => null,
            'streamed' => $live,
            'received_any' => false,
        ];

        if ($aborted) {
            $result['error'] = new ApiError('cancelled');
        } elseif ($state->error !== null) {
            // The stream handler stopped the transfer itself (garbled or unreadable output).
            $result['error'] = $state->error;
        } elseif (is_wp_error($response)) {
            $result['error'] = $state->receivedAny ? new ApiError('stream_interrupted', 0, Logger::redact($response->get_error_message(), 300)) : ApiError::from_wp_error($response);
        } elseif ($result['status'] >= 400) {
            $errorBody = $raw !== '' ? $raw : (string) wp_remote_retrieve_body($response);
            $result['error'] = ApiError::from_http($result['status'], substr($errorBody, 0, self::ERROR_BODY_LIMIT), self::header($response, 'retry-after'));
        } elseif ($mode === 'raw' || ($mode === null && $handle === null)) {
            // A non-streamed JSON reply, or a buffered body from a non-cURL transport.
            $fullBody = $mode === 'raw' ? $raw : (string) wp_remote_retrieve_body($response);
            if (strpos(ltrim($fullBody), '{') === 0) {
                $parsed = self::parse_completion($fullBody, $result['status']);
                if ($parsed['error'] === null) {
                    if ($parsed['reasoning'] !== '') {
                        $onText('reasoning', $parsed['reasoning']);
                    }
                    if ($parsed['content'] !== '') {
                        $onText('content', $parsed['content']);
                    }
                }
                return array_merge($result, $parsed, ['received_any' => true]);
            }
            foreach (array_merge($parser->push($fullBody), $parser->finish()) as $event) {
                $state->handle($event, $onText);
            }
        } else {
            foreach ($parser->finish() as $event) {
                $state->handle($event, $onText);
            }
        }

        if ($result['error'] === null) {
            if ($state->error !== null) {
                $result['error'] = $state->error;
            } elseif (!$state->completed()) {
                $result['error'] = $state->receivedAny ? new ApiError('stream_interrupted') : new ApiError('empty_response', $result['status']);
            } elseif (!$state->done) {
                // A finish reason arrived but "[DONE]" did not; flush any held-back text.
                $state->finish($onText);
            }
        }

        $result['content'] = $state->content;
        $result['reasoning'] = $state->reasoning;
        $result['finish_reason'] = $state->finishReason;
        $result['usage'] = $state->usage;
        $result['received_any'] = $state->receivedAny;
        return $result;
    }

    /**
     * Parses a non-streamed chat completion body.
     *
     * @param string $body Response body.
     * @param int $status HTTP status.
     * @return array{content: string, reasoning: string, finish_reason: string|null, usage: array<string, int>|null, model: string, error: ApiError|null}
     */
    public static function parse_completion(string $body, int $status): array
    {
        $out = ['content' => '', 'reasoning' => '', 'finish_reason' => null, 'usage' => null, 'model' => '', 'error' => null];
        if (trim($body) === '') {
            $out['error'] = new ApiError('empty_response', $status);
            return $out;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $out['error'] = new ApiError('malformed_response', $status, ApiError::extract_detail($body));
            return $out;
        }
        if (isset($data['error'])) {
            $out['error'] = new ApiError('service_error', $status, ApiError::extract_detail($body));
            return $out;
        }
        $choice = $data['choices'][0] ?? null;
        if (!is_array($choice) || !isset($choice['message']) || !is_array($choice['message'])) {
            $out['error'] = new ApiError('empty_response', $status);
            return $out;
        }
        $message = $choice['message'];
        foreach (['reasoning_content', 'reasoning'] as $field) {
            if (isset($message[$field]) && is_string($message[$field]) && trim($message[$field]) !== '') {
                $out['reasoning'] = trim($message[$field]);
                break;
            }
        }
        $content = isset($message['content']) && is_string($message['content']) ? $message['content'] : '';
        if (strpos($content, '<think>') !== false) {
            $splitter = new ThinkSplitter();
            $pieces = array_merge($splitter->feed($content), $splitter->flush());
            $content = '';
            foreach ($pieces as [$channel, $text]) {
                if ($channel === 'reasoning') {
                    $out['reasoning'] .= ($out['reasoning'] !== '' ? "\n" : '') . trim($text);
                } else {
                    $content .= $text;
                }
            }
        }
        if (OutputGuard::is_garbled($content) || OutputGuard::is_garbled($out['reasoning'])) {
            $out['reasoning'] = '';
            $out['error'] = new ApiError('garbled_output', $status);
            return $out;
        }
        $out['content'] = trim($content);
        $out['finish_reason'] = isset($choice['finish_reason']) && is_string($choice['finish_reason']) ? $choice['finish_reason'] : null;
        $out['usage'] = isset($data['usage']) && is_array($data['usage']) ? StreamState::clean_usage($data['usage']) : null;
        $out['model'] = isset($data['model']) && is_string($data['model']) ? $data['model'] : '';
        return $out;
    }

    /**
     * Sends a request with bounded retries for temporary failures.
     *
     * @param string $url URL.
     * @param array<string, mixed> $args Request arguments.
     * @param string $context chat or models (affects 404 classification).
     * @return array{status: int, body: string, duration_ms: int, error: ApiError|null, attempts: int}
     */
    private function send(string $url, array $args, string $context): array
    {
        $start = microtime(true);
        $attempt = 0;
        while (true) {
            $attemptStart = microtime(true);
            $response = wp_remote_request($url, $args);
            $status = 0;
            $body = '';
            if (is_wp_error($response)) {
                $error = ApiError::from_wp_error($response);
            } else {
                $status = (int) wp_remote_retrieve_response_code($response);
                $body = (string) wp_remote_retrieve_body($response);
                if ($status >= 200 && $status < 300) {
                    return ['status' => $status, 'body' => $body, 'duration_ms' => self::ms($start), 'error' => null, 'attempts' => $attempt + 1];
                }
                $error = ApiError::from_http($status, substr($body, 0, self::ERROR_BODY_LIMIT), self::header($response, 'retry-after'), $context);
            }

            $slowTimeout = $error->code === 'timeout' && (microtime(true) - $attemptStart) > 30;
            if (!$error->is_retryable() || $slowTimeout || $attempt >= $this->maxRetries) {
                break;
            }
            $wait = $error->retryAfter ?? (int) pow(2, $attempt);
            if ($wait > $this->maxRetryWait || (microtime(true) - $start) + $wait > $this->timeout) {
                break;
            }
            sleep(max(1, $wait));
            $attempt++;
        }
        return ['status' => $status, 'body' => '', 'duration_ms' => self::ms($start), 'error' => $error, 'attempts' => $attempt + 1];
    }

    /**
     * Request arguments for the WordPress HTTP API.
     *
     * @param string $method HTTP method.
     * @param string|null $body JSON body.
     * @param bool $stream Whether SSE is expected.
     * @param array<string, mixed> $extra Extra arguments.
     * @return array<string, mixed>
     */
    private function args(string $method, ?string $body, bool $stream, array $extra = []): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => $stream ? 'text/event-stream' : 'application/json',
        ];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        return array_merge([
            'method' => $method,
            'timeout' => $this->timeout,
            'redirection' => 0,
            'httpversion' => '1.1',
            // A plain user agent: WordPress' default would also send this site's address.
            'user-agent' => 'ChandanDigitalAIforNVIDIA/' . VERSION,
            'headers' => $headers,
            'body' => $body,
            'limit_response_size' => self::NON_STREAM_LIMIT,
            self::MARKER_ARG => 'plain',
            'cdnv_connect_timeout' => $this->connectTimeout,
        ], $extra);
    }

    /**
     * @param array<string, mixed> $response WordPress HTTP response.
     * @param string $name Header name.
     */
    private static function header(array $response, string $name): ?string
    {
        $value = wp_remote_retrieve_header($response, $name);
        if (is_array($value)) {
            $value = reset($value);
        }
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param float $start Start time.
     */
    private static function ms(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}

