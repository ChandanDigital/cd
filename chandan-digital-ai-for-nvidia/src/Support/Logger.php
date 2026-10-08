<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Support;

/**
 * Optional local diagnostic log, stored in the WordPress database only.
 *
 * Logging is off by default. When on, it records the time, model ID, request type, HTTP status,
 * duration and a sanitised error. Prompts and responses are recorded only when the administrator
 * separately turns on content logging, and even then they are shortened. API keys, Authorization
 * headers and image data are never recorded. Nothing is ever sent to a remote service.
 *
 * @since 1.1.0
 */
final class Logger
{
    public const OPTION = 'cdnv_logs';
    private const MAX_ENTRIES = 500;
    private const MAX_CONTENT = 2000;

    /**
     * Adds a log entry when logging is enabled.
     *
     * @param array<string, mixed> $entry Entry data: type, model, status, duration_ms, error_code, error, prompt, response.
     */
    public static function log(array $entry): void
    {
        if (!Settings::get('logging')) {
            return;
        }

        $clean = [
            'time' => time(),
            'type' => sanitize_key((string) ($entry['type'] ?? 'request')),
            'model' => substr(preg_replace('#[^A-Za-z0-9._:/\-]#', '', (string) ($entry['model'] ?? '')) ?? '', 0, 120),
            'status' => (int) ($entry['status'] ?? 0),
            'duration_ms' => (int) ($entry['duration_ms'] ?? 0),
            'error_code' => sanitize_key((string) ($entry['error_code'] ?? '')),
            'error' => self::redact((string) ($entry['error'] ?? '')),
        ];

        if (Settings::get('log_content')) {
            if (isset($entry['prompt'])) {
                $clean['prompt'] = self::redact((string) $entry['prompt'], self::MAX_CONTENT);
            }
            if (isset($entry['response'])) {
                $clean['response'] = self::redact((string) $entry['response'], self::MAX_CONTENT);
            }
        }

        $logs = self::prune(self::entries());
        $logs[] = $clean;
        if (count($logs) > self::MAX_ENTRIES) {
            $logs = array_slice($logs, -self::MAX_ENTRIES);
        }
        self::store($logs);
    }

    /**
     * Returns stored entries, newest last, after applying the retention period.
     *
     * @return list<array<string, mixed>>
     */
    public static function entries(): array
    {
        $logs = get_option(self::OPTION, []);
        return is_array($logs) ? array_values(array_filter($logs, 'is_array')) : [];
    }

    /**
     * Removes entries older than the retention period and stores the result.
     */
    public static function apply_retention(): void
    {
        $logs = self::entries();
        $pruned = self::prune($logs);
        if (count($pruned) !== count($logs)) {
            self::store($pruned);
        }
    }

    /**
     * Deletes every log entry.
     */
    public static function clear(): void
    {
        delete_option(self::OPTION);
    }

    /**
     * Removes secrets from free text: NVIDIA keys, bearer tokens and data URIs.
     *
     * @param string $text Text to clean.
     * @param int $max Maximum length of the result.
     */
    public static function redact(string $text, int $max = 500): string
    {
        $text = (string) preg_replace('/nvapi-[A-Za-z0-9_\-]{6,}/', 'nvapi-[redacted]', $text);
        $text = (string) preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [redacted]', $text);
        $text = (string) preg_replace('#data:image/[a-z0-9.+\-]+;base64,[A-Za-z0-9+/=]+#i', 'data:image/[omitted]', $text);
        $key = Settings::api_key();
        if ($key !== '' && strlen($key) >= 8) {
            $text = str_replace($key, '[redacted]', $text);
        }
        $text = wp_strip_all_tags($text);
        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }

    /**
     * @param list<array<string, mixed>> $logs Entries.
     * @return list<array<string, mixed>>
     */
    private static function prune(array $logs): array
    {
        $cutoff = time() - DAY_IN_SECONDS * max(1, (int) Settings::get('log_retention_days'));
        return array_values(array_filter($logs, static function (array $log) use ($cutoff): bool {
            return (int) ($log['time'] ?? 0) >= $cutoff;
        }));
    }

    /**
     * @param list<array<string, mixed>> $logs Entries.
     */
    private static function store(array $logs): void
    {
        if (get_option(self::OPTION, null) === null) {
            add_option(self::OPTION, $logs, '', false);
        } else {
            update_option(self::OPTION, $logs, false);
        }
    }
}
