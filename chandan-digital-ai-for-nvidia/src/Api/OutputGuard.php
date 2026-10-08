<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Api;

/**
 * Spots corrupted model output before anyone sees it or saves it.
 *
 * NVIDIA's hosted Kimi K3 sometimes returns "token salad": random words in several scripts mixed
 * with the model's internal chat-template markers (for example <|close|> or <|sep|>), or long runs
 * of "!" characters. Normal answers never contain those markers, so they are a reliable sign that
 * the reply is broken.
 *
 * @since 1.1.2
 */
final class OutputGuard
{
    /** Chat-template control markers that must never appear in a real answer. */
    private const CONTROL_MARKERS = '/<\|(?:open|close|sep|reserved_token_\d+|im_start|im_end|im_middle|im_user|im_assistant|im_system)\|>/';

    /** How much earlier text is kept to catch a marker or run that is split across chunks. */
    public const WINDOW = 64;

    /**
     * Whether a piece of model output looks corrupted.
     *
     * @param string $text Text to check (a whole reply, or a recent window of a stream).
     */
    public static function is_garbled(string $text): bool
    {
        if ($text === '') {
            return false;
        }
        if (preg_match(self::CONTROL_MARKERS, $text)) {
            return true;
        }
        if (preg_match('/!{40,}/', $text)) {
            return true;
        }
        // Several Unicode replacement characters mean the model produced broken byte sequences.
        return substr_count($text, "\u{FFFD}") >= 3;
    }
}
