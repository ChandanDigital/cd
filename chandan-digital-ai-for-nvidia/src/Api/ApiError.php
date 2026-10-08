<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Api;

use ChandanDigital\NvidiaAi\Support\Logger;

/**
 * A classified, administrator-safe NVIDIA API error.
 *
 * Errors are classified from the HTTP status and NVIDIA's own error text, so that a bad API key, a
 * model the key cannot use, an unsupported parameter and a temporary outage each get a different,
 * accurate message. Upstream text is shortened and scrubbed of keys before anyone sees it.
 *
 * @since 1.1.0
 */
final class ApiError
{
    public string $code;
    public string $message;
    public int $status;
    public ?int $retryAfter;
    public string $detail;

    /**
     * @param string $code Error code.
     * @param int $status HTTP status, or 0 when no response arrived.
     * @param string $detail Sanitised upstream detail.
     * @param int|null $retryAfter Seconds to wait before retrying, if known.
     * @param string|null $message Custom message. Defaults to the standard message for the code.
     */
    public function __construct(string $code, int $status = 0, string $detail = '', ?int $retryAfter = null, ?string $message = null)
    {
        $this->code = $code;
        $this->status = $status;
        $this->detail = $detail;
        $this->retryAfter = $retryAfter;
        $this->message = $message ?? self::message_for($code);
    }

    /**
     * Whether a bounded automatic retry is appropriate.
     */
    public function is_retryable(): bool
    {
        return in_array($this->code, ['rate_limited', 'service_unavailable', 'service_error', 'timeout', 'network_error'], true);
    }

    /**
     * Whether the error is about the API key rather than the model or request.
     */
    public function is_key_error(): bool
    {
        return in_array($this->code, ['missing_api_key', 'invalid_api_key'], true);
    }

    /**
     * Array form for JSON responses.
     *
     * @return array<string, mixed>
     */
    public function to_array(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'status' => $this->status,
            'retry_after' => $this->retryAfter,
            'detail' => $this->detail,
        ];
    }

    /**
     * One line for logs and stored status.
     */
    public function summary(): string
    {
        return $this->message . ($this->detail !== '' ? ' (' . $this->detail . ')' : '');
    }

    /**
     * Classifies an HTTP error response.
     *
     * @param int $status HTTP status.
     * @param string $body Response body (may be JSON or text).
     * @param string|null $retryAfterHeader Retry-After header value.
     * @param string $context chat or models.
     */
    public static function from_http(int $status, string $body, ?string $retryAfterHeader = null, string $context = 'chat'): self
    {
        $detail = self::extract_detail($body);
        $lower = strtolower($detail);
        $retryAfter = self::parse_retry_after($retryAfterHeader);

        switch (true) {
            case $status === 401:
                return new self('invalid_api_key', $status, $detail);
            case $status === 403:
                if (preg_match('/expired|revoked|invalid (api )?key|api key|unauthori[sz]ed|authenticat/', $lower)) {
                    return new self('invalid_api_key', $status, $detail);
                }
                return new self('access_denied', $status, $detail);
            case $status === 404:
                if ($context === 'models') {
                    return new self('endpoint_not_found', $status, $detail);
                }
                if (preg_match('/function|account/', $lower)) {
                    return new self('model_not_available', $status, $detail);
                }
                if (preg_match('/model/', $lower)) {
                    return new self('invalid_model', $status, $detail);
                }
                return new self('model_not_available', $status, $detail);
            case $status === 408:
                return new self('timeout', $status, $detail);
            case $status === 413:
                return new self('payload_too_large', $status, $detail);
            case $status === 429:
                return new self('rate_limited', $status, $detail, $retryAfter);
            case $status === 400 || $status === 422:
                return new self(self::classify_bad_request($lower), $status, $detail);
            case $status === 502 || $status === 503:
                return new self('service_unavailable', $status, $detail, $retryAfter);
            case $status === 504:
                return new self('timeout', $status, $detail, $retryAfter);
            case $status >= 500:
                return new self('service_error', $status, $detail, $retryAfter);
            default:
                return new self('invalid_request', $status, $detail);
        }
    }

    /**
     * Classifies a transport-level WordPress HTTP error.
     *
     * @param \WP_Error $error WordPress error.
     */
    public static function from_wp_error(\WP_Error $error): self
    {
        $text = (string) $error->get_error_message();
        $lower = strtolower($text);
        $detail = Logger::redact($text, 300);
        if ($error->get_error_code() === 'http_request_not_executed' || strpos($lower, 'blocked requests') !== false) {
            return new self('blocked_by_wordpress', 0, $detail);
        }
        if (preg_match('/curl error 28|timed out|timeout/', $lower)) {
            return new self('timeout', 0, $detail);
        }
        if (preg_match('/curl error (35|51|58|60|77)|ssl|certificate/', $lower)) {
            return new self('tls_error', 0, $detail);
        }
        return new self('network_error', 0, $detail);
    }

    /**
     * Picks a code for a 400/422 response from NVIDIA's error text.
     *
     * @param string $lower Lower-case detail text.
     */
    private static function classify_bad_request(string $lower): string
    {
        if (preg_match('/context length|context window|maximum context|too many tokens|token limit|exceeds the (maximum|max)|max_tokens|max_completion_tokens/', $lower)) {
            return 'token_limit';
        }
        if (preg_match('/image|image_url|vision|multimodal/', $lower)) {
            return 'invalid_image';
        }
        if (preg_match('/reasoning_effort|temperature|top_p|seed|presence_penalty|frequency_penalty|unsupported|not supported|unrecognized|unknown (field|parameter)|extra (fields|inputs)|not permitted|unexpected (field|keyword|argument)/', $lower)) {
            return 'unsupported_parameter';
        }
        if (preg_match('/model/', $lower) && preg_match('/not found|does not exist|unknown|invalid/', $lower)) {
            return 'invalid_model';
        }
        return 'invalid_request';
    }

    /**
     * Pulls a readable error description out of an NVIDIA error body.
     *
     * NVIDIA uses several shapes: {"error": {"message": ...}}, {"error": "..."},
     * {"detail": "..."}, {"detail": [{"msg": ...}]}, {"title": ..., "detail": ...} or plain text.
     *
     * @param string $body Raw body.
     */
    public static function extract_detail(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }
        $data = json_decode($body, true);
        $parts = [];
        if (is_array($data)) {
            if (isset($data['error'])) {
                if (is_array($data['error'])) {
                    $parts[] = (string) ($data['error']['message'] ?? '');
                    if (isset($data['error']['param']) && is_string($data['error']['param'])) {
                        $parts[] = 'param: ' . $data['error']['param'];
                    }
                } elseif (is_string($data['error'])) {
                    $parts[] = $data['error'];
                }
            }
            if (isset($data['title']) && is_string($data['title'])) {
                $parts[] = $data['title'];
            }
            if (isset($data['detail'])) {
                if (is_string($data['detail'])) {
                    $parts[] = $data['detail'];
                } elseif (is_array($data['detail'])) {
                    foreach (array_slice($data['detail'], 0, 3) as $item) {
                        if (is_array($item) && isset($item['msg'])) {
                            $loc = isset($item['loc']) && is_array($item['loc']) ? implode('.', array_map('strval', $item['loc'])) . ': ' : '';
                            $parts[] = $loc . (string) $item['msg'];
                        } elseif (is_string($item)) {
                            $parts[] = $item;
                        }
                    }
                }
            }
            if (!$parts && isset($data['message']) && is_string($data['message'])) {
                $parts[] = $data['message'];
            }
        }
        if (!$parts) {
            $parts[] = $body;
        }
        $text = implode(' - ', array_unique(array_filter(array_map('trim', $parts))));
        return Logger::redact((string) preg_replace('/\s+/', ' ', $text), 300);
    }

    /**
     * Parses a Retry-After header (seconds or an HTTP date).
     *
     * @param string|null $value Header value.
     */
    public static function parse_retry_after(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (ctype_digit($value)) {
            return min((int) $value, 86400);
        }
        $time = strtotime($value);
        if ($time === false) {
            return null;
        }
        return max(0, min($time - time(), 86400));
    }

    /**
     * Standard administrator-facing message for an error code.
     *
     * @param string $code Error code.
     */
    public static function message_for(string $code): string
    {
        $messages = [
            'missing_api_key' => __('No NVIDIA API key is configured. Add one on the NVIDIA API Settings tab or define CHANDAN_NVIDIA_API_KEY in wp-config.php.', 'chandan-digital-ai-for-nvidia'),
            'invalid_api_key' => __('NVIDIA rejected the API key (HTTP 401). The key may be wrong, expired or revoked. Create a new key at build.nvidia.com and save it again.', 'chandan-digital-ai-for-nvidia'),
            'access_denied' => __('The API key works, but NVIDIA refused access to this model or feature (HTTP 403). Check that your NVIDIA account can use this model.', 'chandan-digital-ai-for-nvidia'),
            'model_not_available' => __('This model is not available to your NVIDIA account (HTTP 404). Your API key may be fine; NVIDIA has not enabled this model for it, or the model was retired.', 'chandan-digital-ai-for-nvidia'),
            'invalid_model' => __('NVIDIA does not recognise this model ID. Check the spelling in the AI Models tab.', 'chandan-digital-ai-for-nvidia'),
            'unsupported_parameter' => __('NVIDIA rejected one of the generation settings for this model. See the detail below, then change that setting.', 'chandan-digital-ai-for-nvidia'),
            'invalid_request' => __('NVIDIA could not process the request. See the detail below.', 'chandan-digital-ai-for-nvidia'),
            'invalid_image' => __('NVIDIA could not use the image. Check that the format is supported and that a URL is public and reachable.', 'chandan-digital-ai-for-nvidia'),
            'payload_too_large' => __('The request was too large for NVIDIA (HTTP 413). Use a smaller image or a shorter conversation.', 'chandan-digital-ai-for-nvidia'),
            'token_limit' => __('The request goes over the model\'s token limit. Reduce maximum output tokens or shorten the conversation.', 'chandan-digital-ai-for-nvidia'),
            'rate_limited' => __('NVIDIA is rate limiting this API key (HTTP 429). Wait a little and try again.', 'chandan-digital-ai-for-nvidia'),
            'timeout' => __('The request timed out. The model may be busy or still thinking; try again, or raise the request timeout on the NVIDIA API Settings tab.', 'chandan-digital-ai-for-nvidia'),
            'service_unavailable' => __('NVIDIA\'s service is temporarily unavailable. Try again in a few minutes.', 'chandan-digital-ai-for-nvidia'),
            'service_error' => __('NVIDIA\'s service returned an internal error. Try again shortly.', 'chandan-digital-ai-for-nvidia'),
            'network_error' => __('WordPress could not reach the NVIDIA API. Check the server\'s internet connection, firewall and DNS.', 'chandan-digital-ai-for-nvidia'),
            'tls_error' => __('A secure (TLS) connection to NVIDIA could not be made. The server\'s SSL certificates may be out of date.', 'chandan-digital-ai-for-nvidia'),
            'blocked_by_wordpress' => __('WordPress blocked the outgoing request (WP_HTTP_BLOCK_EXTERNAL). Add the NVIDIA API host to WP_ACCESSIBLE_HOSTS.', 'chandan-digital-ai-for-nvidia'),
            'endpoint_not_found' => __('The API base URL did not respond like an NVIDIA API (HTTP 404). Check the base URL.', 'chandan-digital-ai-for-nvidia'),
            'empty_response' => __('NVIDIA returned an empty response. Try again.', 'chandan-digital-ai-for-nvidia'),
            'malformed_response' => __('NVIDIA returned a response that could not be read. Try again.', 'chandan-digital-ai-for-nvidia'),
            'stream_interrupted' => __('The streamed response stopped before it finished. The text shown so far may be incomplete.', 'chandan-digital-ai-for-nvidia'),
            'cancelled' => __('The request was cancelled.', 'chandan-digital-ai-for-nvidia'),
            'duplicate_request' => __('This request is already being processed.', 'chandan-digital-ai-for-nvidia'),
            'invalid_input' => __('The request contains invalid input.', 'chandan-digital-ai-for-nvidia'),
        ];
        return $messages[$code] ?? __('An unexpected error occurred while talking to NVIDIA.', 'chandan-digital-ai-for-nvidia');
    }
}
