<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Api;

/**
 * Turns NVIDIA chat completion stream events into answer and reasoning text.
 *
 * Each event payload is a JSON chat.completion.chunk. Answer text arrives in
 * choices[0].delta.content and reasoning in choices[0].delta.reasoning_content (or
 * delta.reasoning on some backends). The stream ends with a "[DONE]" payload.
 *
 * @since 1.1.0
 */
final class StreamState
{
    public string $content = '';
    public string $reasoning = '';
    public ?string $finishReason = null;
    /** @var array<string, int>|null */
    public ?array $usage = null;
    public bool $done = false;
    public bool $receivedAny = false;
    public ?ApiError $error = null;
    public string $model = '';

    private ThinkSplitter $splitter;

    public function __construct()
    {
        $this->splitter = new ThinkSplitter();
    }

    /**
     * Handles one event payload.
     *
     * @param string $data Event data.
     * @param callable(string, string): void $emit Receives (channel, text) for new text.
     */
    public function handle(string $data, callable $emit): void
    {
        $data = trim($data);
        if ($data === '') {
            return;
        }
        if ($data === '[DONE]') {
            $this->finish($emit);
            return;
        }

        $chunk = json_decode($data, true);
        if (!is_array($chunk)) {
            $this->error = new ApiError('malformed_response', 200, 'A stream event was not valid JSON.');
            return;
        }

        if (isset($chunk['error'])) {
            $status = isset($chunk['error']['code']) && is_numeric($chunk['error']['code']) ? (int) $chunk['error']['code'] : 0;
            $this->error = $status >= 400
                ? ApiError::from_http($status, $data)
                : new ApiError('service_error', $status, ApiError::extract_detail($data));
            return;
        }

        if (isset($chunk['model']) && is_string($chunk['model'])) {
            $this->model = $chunk['model'];
        }
        if (isset($chunk['usage']) && is_array($chunk['usage'])) {
            $this->usage = self::clean_usage($chunk['usage']);
        }

        $choice = $chunk['choices'][0] ?? null;
        if (!is_array($choice)) {
            return;
        }
        $delta = isset($choice['delta']) && is_array($choice['delta']) ? $choice['delta'] : [];

        foreach (['reasoning_content', 'reasoning'] as $field) {
            if (isset($delta[$field]) && is_string($delta[$field]) && $delta[$field] !== '') {
                $this->add('reasoning', $delta[$field], $emit);
                break;
            }
        }
        if (isset($delta['content']) && is_string($delta['content']) && $delta['content'] !== '') {
            foreach ($this->splitter->feed($delta['content']) as [$channel, $text]) {
                $this->add($channel, $text, $emit);
            }
        }
        if (isset($choice['finish_reason']) && is_string($choice['finish_reason']) && $choice['finish_reason'] !== '') {
            $this->finishReason = $choice['finish_reason'];
        }
    }

    /**
     * Marks the stream complete and flushes held-back text.
     *
     * @param callable(string, string): void $emit Receives (channel, text).
     */
    public function finish(callable $emit): void
    {
        foreach ($this->splitter->flush() as [$channel, $text]) {
            $this->add($channel, $text, $emit);
        }
        $this->done = true;
    }

    /**
     * Whether the stream ended properly ("[DONE]" or a finish reason).
     */
    public function completed(): bool
    {
        return $this->done || $this->finishReason !== null;
    }

    /**
     * @param string $channel content or reasoning.
     * @param string $text Text.
     * @param callable(string, string): void $emit Receives (channel, text).
     */
    private function add(string $channel, string $text, callable $emit): void
    {
        $this->receivedAny = true;
        if ($channel === 'reasoning') {
            $this->reasoning .= $text;
        } else {
            $this->content .= $text;
        }
        $emit($channel, $text);
    }

    /**
     * Keeps only numeric token counts from a usage block.
     *
     * @param array<string, mixed> $usage Usage data.
     * @return array<string, int>
     */
    public static function clean_usage(array $usage): array
    {
        $clean = [];
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
            if (isset($usage[$key]) && is_numeric($usage[$key])) {
                $clean[$key] = (int) $usage[$key];
            }
        }
        if (isset($usage['completion_tokens_details']['reasoning_tokens']) && is_numeric($usage['completion_tokens_details']['reasoning_tokens'])) {
            $clean['reasoning_tokens'] = (int) $usage['completion_tokens_details']['reasoning_tokens'];
        }
        return $clean;
    }
}
