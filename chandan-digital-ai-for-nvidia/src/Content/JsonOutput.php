<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Content;

use WordPress\AiClient\Common\Exception\RuntimeException;

/** Remove transport wrappers without rewriting JSON values or guessing missing data. */
final class JsonOutput
{
    public static function isValid(string $text): bool
    {
        json_decode($text);
        return json_last_error() === JSON_ERROR_NONE;
    }

    public static function normalize(string $text, string $finishReason = 'stop'): string
    {
        if ($finishReason === 'length') {
            throw new RuntimeException(
                'JSON generation reached the output token limit. Increase max_tokens or request shorter content.'
            );
        }
        if ($finishReason === 'content_filter') {
            throw new RuntimeException('The model filtered the JSON response. Review the request before trying again.');
        }
        $text = trim($text);
        if (substr($text, 0, 3) === "\xEF\xBB\xBF") {
            $text = trim(substr($text, 3));
        }
        // Valid JSON always wins: never remove <think> tags inside JSON string values.
        if (self::isValid($text)) {
            return $text;
        }
        // Only accept complete leading reasoning blocks, never arbitrary prose extraction.
        while (preg_match('/\A<think>.*?<\/think>\s*/s', $text, $match)) {
            $text = trim(substr($text, strlen($match[0])));
        }
        if (preg_match('/\A```(?:json)?\s*\R(.*?)\R```\z/is', $text, $match)) {
            $text = trim($match[1]);
        }
        if (self::isValid($text)) {
            return $text;
        }
        // No regex comma/quote repair: that can silently change article text and numbers.
        json_decode($text);
        throw new RuntimeException(
            'The model returned invalid JSON: ' . json_last_error_msg()
            . '. Generate again, or use a model that supports the requested JSON format.'
        );
    }
}
