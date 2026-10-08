<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Api;

/**
 * Separates inline <think>...</think> reasoning from answer text in a stream.
 *
 * Some NVIDIA-hosted models put their reasoning inside <think> tags in the normal content instead of
 * a separate reasoning field. A tag can be split across two chunks, so a possible partial tag at the
 * end of a chunk is held back until the next chunk shows whether it really is a tag.
 *
 * @since 1.1.0
 */
final class ThinkSplitter
{
    private bool $inThink = false;
    private string $pending = '';

    /**
     * Feeds content text and returns the pieces that are safe to emit.
     *
     * @param string $text New content text.
     * @return list<array{0: string, 1: string}> Pairs of [channel, text]; channel is "content" or "reasoning".
     */
    public function feed(string $text): array
    {
        $buffer = $this->pending . $text;
        $this->pending = '';
        $out = [];

        while ($buffer !== '') {
            $tag = $this->inThink ? '</think>' : '<think>';
            $pos = strpos($buffer, $tag);
            if ($pos !== false) {
                if ($pos > 0) {
                    $out[] = [$this->channel(), substr($buffer, 0, $pos)];
                }
                $buffer = (string) substr($buffer, $pos + strlen($tag));
                $this->inThink = !$this->inThink;
                continue;
            }
            $hold = $this->partial_tag_length($buffer, $tag);
            $emit = substr($buffer, 0, strlen($buffer) - $hold);
            if ($emit !== '') {
                $out[] = [$this->channel(), $emit];
            }
            $this->pending = (string) substr($buffer, strlen($buffer) - $hold);
            break;
        }

        return $out;
    }

    /**
     * Returns any held-back text at the end of the stream.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function flush(): array
    {
        if ($this->pending === '') {
            return [];
        }
        $out = [[$this->channel(), $this->pending]];
        $this->pending = '';
        return $out;
    }

    private function channel(): string
    {
        return $this->inThink ? 'reasoning' : 'content';
    }

    /**
     * Length of the longest suffix of $buffer that is a prefix of $tag.
     *
     * @param string $buffer Text.
     * @param string $tag Tag being searched for.
     */
    private function partial_tag_length(string $buffer, string $tag): int
    {
        $max = min(strlen($tag) - 1, strlen($buffer));
        for ($len = $max; $len > 0; $len--) {
            if (substr($buffer, -$len) === substr($tag, 0, $len)) {
                return $len;
            }
        }
        return 0;
    }
}
