<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Admin;

/**
 * Small escaped HTML helpers shared by the admin views.
 *
 * @since 1.1.0
 */
final class Ui
{
    /**
     * Status pill for a model access state.
     *
     * @param array<string, mixed> $status Status data.
     */
    public static function access_pill(array $status): string
    {
        $state = (string) ($status['state'] ?? 'unknown');
        $labels = [
            'verified' => [__('Access confirmed', 'chandan-digital-ai-for-nvidia'), 'ok'],
            'not_available' => [__('Not available to this key', 'chandan-digital-ai-for-nvidia'), 'bad'],
            'access_denied' => [__('Access denied', 'chandan-digital-ai-for-nvidia'), 'bad'],
            'error' => [__('Check failed', 'chandan-digital-ai-for-nvidia'), 'warn'],
            'unknown' => [__('Not checked', 'chandan-digital-ai-for-nvidia'), 'muted'],
        ];
        [$label, $tone] = $labels[$state] ?? $labels['unknown'];
        $title = '';
        if (!empty($status['time'])) {
            /* translators: 1: date and time, 2: HTTP status. */
            $title = sprintf(__('Last checked %1$s (HTTP %2$s)', 'chandan-digital-ai-for-nvidia'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $status['time']), (string) ($status['http'] ?? '0'));
            if (!empty($status['message'])) {
                $title .= ' - ' . $status['message'];
            }
        }
        return sprintf(
            '<span class="cdnv-pill cdnv-pill--%s" data-cdnv-status%s>%s</span>',
            esc_attr($tone),
            $title !== '' ? ' title="' . esc_attr($title) . '"' : '',
            esc_html($label)
        );
    }

    /**
     * Generic pill.
     *
     * @param string $label Text.
     * @param string $tone ok, bad, warn, muted or info.
     */
    public static function pill(string $label, string $tone = 'muted'): string
    {
        return sprintf('<span class="cdnv-pill cdnv-pill--%s">%s</span>', esc_attr($tone), esc_html($label));
    }

    /**
     * Capability badges for a model.
     *
     * @param list<string> $capabilities Capability IDs.
     */
    public static function capability_badges(array $capabilities): string
    {
        $labels = [
            'text' => __('Text', 'chandan-digital-ai-for-nvidia'),
            'chat' => __('Chat', 'chandan-digital-ai-for-nvidia'),
            'vision' => __('Image input', 'chandan-digital-ai-for-nvidia'),
            'reasoning' => __('Reasoning', 'chandan-digital-ai-for-nvidia'),
            'streaming' => __('Streaming', 'chandan-digital-ai-for-nvidia'),
            'tools' => __('Tools', 'chandan-digital-ai-for-nvidia'),
            'image_generation' => __('Image generation', 'chandan-digital-ai-for-nvidia'),
        ];
        $out = '';
        foreach ($capabilities as $capability) {
            if (isset($labels[$capability])) {
                $out .= '<span class="cdnv-cap cdnv-cap--' . esc_attr($capability) . '">' . esc_html($labels[$capability]) . '</span>';
            }
        }
        return $out;
    }

    /**
     * Formats a timestamp or returns a dash.
     *
     * @param int $time Unix timestamp.
     */
    public static function time(int $time): string
    {
        return $time > 0 ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $time) : '-';
    }
}
