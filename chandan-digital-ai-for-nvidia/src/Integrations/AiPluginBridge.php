<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Integrations;

use ChandanDigital\NvidiaAi\Admin\AdminPage;
use ChandanDigital\NvidiaAi\Plugin;
use ChandanDigital\NvidiaAi\Support\ModelRegistry;
use ChandanDigital\NvidiaAi\Support\Settings;

/**
 * Works with the WordPress "AI" plugin, which adds title, excerpt, meta description, alt text and
 * image tools to the post editor and sends its requests through the "nvidia" provider.
 *
 * - Reads the AI plugin's "Connector Approval" state, so the dashboard can explain the 403 that the
 *   plugin returns when it has not been approved to use the NVIDIA connector.
 * - Tells the AI plugin which NVIDIA models to use, instead of leaving it to guess.
 * - Tells the AI plugin that NVIDIA credentials exist when the key is stored in this plugin.
 *
 * Nothing here changes the AI plugin's approvals. Approving stays an administrator's decision on the
 * AI plugin's own screen.
 *
 * @since 1.2.0
 */
final class AiPluginBridge
{
    private const APPROVAL_FEATURE_OPTION = 'wpai_feature_connector-approval_enabled';
    private const APPROVALS_OPTION = 'wpai_connector_approvals';
    private const PENDING_OPTION = 'wpai_connector_approval_pending';

    /**
     * Registers hooks.
     */
    public static function init(): void
    {
        add_filter('wpai_has_ai_credentials', [self::class, 'has_credentials'], 10, 1);
        add_filter('wpai_preferred_text_models', [self::class, 'preferred_text_models'], 20);
        add_filter('wpai_preferred_vision_models', [self::class, 'preferred_vision_models'], 20);
        add_filter('wpai_preferred_image_models', [self::class, 'preferred_image_models'], 20);
        add_action('admin_notices', [self::class, 'blocked_notice']);
    }

    /**
     * Whether the WordPress AI plugin is active.
     */
    public static function is_active(): bool
    {
        return defined('WPAI_VERSION');
    }

    /**
     * Current state of the AI plugin's Connector Approval for the NVIDIA connector.
     *
     * @return array{active: bool, version: string, approval_enabled: bool, approved: list<string>, blocked: list<array<string, mixed>>, approval_url: string, settings_url: string}
     */
    public static function status(): array
    {
        $approved = [];
        $approvals = get_option(self::APPROVALS_OPTION, []);
        foreach (is_array($approvals) ? $approvals : [] as $caller => $connectors) {
            if (is_array($connectors) && !empty($connectors[Plugin::PROVIDER_ID])) {
                $approved[] = (string) $caller;
            }
        }

        $blocked = [];
        $pending = get_option(self::PENDING_OPTION, []);
        foreach (is_array($pending) ? $pending : [] as $entry) {
            if (!is_array($entry) || ($entry['connector_id'] ?? '') !== Plugin::PROVIDER_ID) {
                continue;
            }
            $basename = (string) ($entry['caller_basename'] ?? '');
            if (in_array($basename, $approved, true)) {
                continue;
            }
            $blocked[] = [
                'name' => (string) ($entry['caller_name'] ?? $basename),
                'basename' => $basename,
                'type' => (string) ($entry['caller_type'] ?? ''),
                'attempts' => (int) ($entry['attempts'] ?? 0),
                'last_seen' => (int) ($entry['last_seen'] ?? 0),
            ];
        }

        return [
            'active' => self::is_active(),
            'version' => self::is_active() ? (string) constant('WPAI_VERSION') : '',
            'approval_enabled' => (bool) get_option(self::APPROVAL_FEATURE_OPTION, false),
            'approved' => $approved,
            'blocked' => $blocked,
            'approval_url' => admin_url('tools.php?page=ai-connector-approval'),
            'settings_url' => admin_url('options-general.php?page=ai-wp-admin'),
        ];
    }

    /**
     * Tells the AI plugin that NVIDIA credentials exist when the key lives in this plugin.
     *
     * @param mixed $has Current value.
     * @return mixed
     */
    public static function has_credentials($has)
    {
        if ($has || !Plugin::provider_is_ours()) {
            return $has;
        }
        return in_array(Settings::key_source(), ['constant', 'plugin'], true) ? true : $has;
    }

    /**
     * Adds the dashboard's default NVIDIA chat model after the AI plugin's own preferences, so it is
     * used when those providers are not set up.
     *
     * @param mixed $models Preferred models as [provider, model] pairs.
     * @return mixed
     */
    public static function preferred_text_models($models)
    {
        return self::append($models, self::offered_chat_models(false));
    }

    /**
     * Adds NVIDIA models that accept images (for alt text and image questions).
     *
     * @param mixed $models Preferred models.
     * @return mixed
     */
    public static function preferred_vision_models($models)
    {
        return self::append($models, self::offered_chat_models(true));
    }

    /**
     * Adds NVIDIA's FLUX image models.
     *
     * @param mixed $models Preferred models.
     * @return mixed
     */
    public static function preferred_image_models($models)
    {
        $ids = [];
        foreach (['black-forest-labs/flux.1-schnell', 'black-forest-labs/flux.1-dev', 'black-forest-labs/flux.2-klein-4b'] as $id) {
            $model = ModelRegistry::get($id);
            if ($model !== null && $model['enabled']) {
                $ids[] = $id;
            }
        }
        return self::append($models, $ids);
    }

    /**
     * Shows a warning on the dashboard, Plugins and this plugin's screens while the AI plugin is
     * blocking requests to NVIDIA.
     */
    public static function blocked_notice(): void
    {
        if (!current_user_can('manage_options') || !self::is_active()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id = $screen ? (string) $screen->id : '';
        if (!in_array($id, ['dashboard', 'plugins'], true) && strpos($id, AdminPage::SLUG) === false) {
            return;
        }
        $status = self::status();
        if (!$status['approval_enabled'] || !$status['blocked']) {
            return;
        }
        $names = implode(', ', array_map(static function (array $caller): string {
            return $caller['name'];
        }, $status['blocked']));
        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
            esc_html__('AI tools in posts and pages are blocked (HTTP 403).', 'chandan-digital-ai-for-nvidia'),
            /* translators: %s: plugin names. */
            esc_html(sprintf(__('The AI plugin\'s Connector Approval has not approved %s to use the NVIDIA connector, so WordPress stops those requests before they reach NVIDIA.', 'chandan-digital-ai-for-nvidia'), $names)),
            esc_url($status['approval_url']),
            esc_html__('Approve it in Tools > Connector Approvals', 'chandan-digital-ai-for-nvidia')
        );
    }

    /**
     * Chat models currently offered to the AI Client, default model first.
     *
     * @param bool $visionOnly Only models that accept images.
     * @return list<string>
     */
    private static function offered_chat_models(bool $visionOnly): array
    {
        $ids = [];
        foreach (ModelRegistry::enabled_chat_models() as $id => $model) {
            if ($visionOnly && $model['kind'] !== 'vision') {
                continue;
            }
            // Same rule as the provider's model list: new models only after their access is confirmed.
            if ($model['requires_verification'] && ($model['status']['state'] ?? '') !== 'verified') {
                continue;
            }
            $ids[] = $id;
            if (count($ids) >= 3) {
                break;
            }
        }
        return $ids;
    }

    /**
     * Appends NVIDIA models to a preference list without duplicates.
     *
     * @param mixed $models Existing list.
     * @param list<string> $ids NVIDIA model IDs.
     * @return mixed
     */
    private static function append($models, array $ids)
    {
        if (!is_array($models) || !Plugin::provider_is_ours()) {
            return $models;
        }
        foreach ($ids as $id) {
            $pair = [Plugin::PROVIDER_ID, $id];
            if (!in_array($pair, $models, true)) {
                $models[] = $pair;
            }
        }
        return $models;
    }
}
