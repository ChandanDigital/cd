<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Api;

/**
 * Incremental Server-Sent Events parser.
 *
 * Network chunks do not line up with events: one chunk can hold several events, half an event, or
 * even half of a "\r\n" line ending. The parser keeps the unfinished tail in a buffer and only
 * returns events once their blank-line terminator has arrived.
 *
 * @since 1.1.0
 */
final class SseParser
{
    /** Guard against an endless event that never terminates. */
    private const MAX_BUFFER = 4194304;

    private string $buffer = '';

    /**
     * Adds a network chunk and returns the data payloads of every event completed by it.
     *
     * @param string $chunk Raw bytes from the network.
     * @return list<string> Event data payloads (multi-line data joined with "\n").
     * @throws \RuntimeException When the buffer grows past the safety limit.
     */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;

        // Hold back a trailing "\r": the matching "\n" may arrive in the next chunk.
        $held = '';
        if (substr($this->buffer, -1) === "\r") {
            $held = "\r";
            $this->buffer = substr($this->buffer, 0, -1);
        }
        $this->buffer = str_replace(["\r\n", "\r"], "\n", $this->buffer);

        $events = [];
        while (($pos = strpos($this->buffer, "\n\n")) !== false) {
            $block = substr($this->buffer, 0, $pos);
            $this->buffer = (string) substr($this->buffer, $pos + 2);
            $data = $this->parse_block($block);
            if ($data !== null) {
                $events[] = $data;
            }
        }
        $this->buffer .= $held;

        if (strlen($this->buffer) > self::MAX_BUFFER) {
            $this->buffer = '';
            throw new \RuntimeException('SSE event exceeded the maximum buffer size.');
        }

        return $events;
    }

    /**
     * Returns the data of a final event that ended without a blank line, if any.
     *
     * @return list<string>
     */
    public function finish(): array
    {
        $block = trim(str_replace(["\r\n", "\r"], "\n", $this->buffer), "\n");
        $this->buffer = '';
        if ($block === '') {
            return [];
        }
        $data = $this->parse_block($block);
        return $data === null ? [] : [$data];
    }

    /**
     * Whether unparsed bytes remain.
     */
    public function has_pending(): bool
    {
        return trim($this->buffer) !== '';
    }

    /**
     * Extracts the data field of one event block. Comment lines (":") and other fields are ignored.
     *
     * @param string $block Event block without the terminating blank line.
     */
    private function parse_block(string $block): ?string
    {
        $data = [];
        foreach (explode("\n", $block) as $line) {
            if ($line === '' || $line[0] === ':') {
                continue;
            }
            if (strncmp($line, 'data:', 5) === 0) {
                $value = substr($line, 5);
                if ($value !== '' && $value[0] === ' ') {
                    $value = substr($value, 1);
                }
                $data[] = $value;
            } elseif ($line === 'data') {
                $data[] = '';
            }
        }
        return $data ? implode("\n", $data) : null;
    }
}
