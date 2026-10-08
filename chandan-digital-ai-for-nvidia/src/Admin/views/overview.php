<?php
/**
 * Overview tab.
 *
 * @package ChandanDigital\NvidiaAi
 */

defined('ABSPATH') || exit;

use ChandanDigital\NvidiaAi\Admin\AdminPage;
use ChandanDigital\NvidiaAi\Admin\Ui;
use ChandanDigital\NvidiaAi\Integrations\AiPluginBridge;
use ChandanDigital\NvidiaAi\Plugin;
use ChandanDigital\NvidiaAi\Support\ModelRegistry;
use ChandanDigital\NvidiaAi\Support\Settings;
use WordPress\AiClient\AiClient;

use const ChandanDigital\NvidiaAi\VERSION;

$cdnv_status = Settings::status();
$cdnv_source = Settings::key_source();
$cdnv_default_id = (string) Settings::get('default_model');
$cdnv_default = ModelRegistry::get($cdnv_default_id);
$cdnv_kimi = ModelRegistry::get(ModelRegistry::KIMI_K3);
$cdnv_kimi_settings = ModelRegistry::settings(ModelRegistry::KIMI_K3);
$cdnv_has_client = class_exists(AiClient::class);
$cdnv_ai_key = Plugin::ai_client_key_source();
$cdnv_ai_key_labels = [
    'constant' => __('This plugin (wp-config.php constant)', 'chandan-digital-ai-for-nvidia'),
    'plugin' => __('This plugin (saved key)', 'chandan-digital-ai-for-nvidia'),
    'wordpress' => __('WordPress (Settings > Connectors or NVIDIA_API_KEY)', 'chandan-digital-ai-for-nvidia'),
    'none' => __('No key available to the AI Client', 'chandan-digital-ai-for-nvidia'),
];
?>
<?php $cdnv_ai = AiPluginBridge::status(); ?>
<div class="cdnv-grid">
    <?php if ($cdnv_ai['approval_enabled'] && $cdnv_ai['blocked']) : ?>
        <section class="cdnv-card cdnv-card--wide cdnv-card--alert">
            <h2><?php esc_html_e('AI buttons in posts and pages are blocked (403)', 'chandan-digital-ai-for-nvidia'); ?></h2>
            <p><?php esc_html_e('The WordPress AI plugin\'s Connector Approval is stopping requests to NVIDIA from:', 'chandan-digital-ai-for-nvidia'); ?>
                <strong><?php echo esc_html(implode(', ', array_column($cdnv_ai['blocked'], 'name'))); ?></strong></p>
            <p><a class="button button-primary" href="<?php echo esc_url($cdnv_ai['approval_url']); ?>"><?php esc_html_e('Approve it in Tools > Connector Approvals', 'chandan-digital-ai-for-nvidia'); ?></a>
                <a class="button" href="<?php echo esc_url(AdminPage::url('diagnostics')); ?>#cdnv-editor-ai"><?php esc_html_e('Details', 'chandan-digital-ai-for-nvidia'); ?></a></p>
        </section>
    <?php endif; ?>
    <section class="cdnv-card">
        <h2><?php esc_html_e('Plugin', 'chandan-digital-ai-for-nvidia'); ?></h2>
        <dl class="cdnv-dl">
            <dt><?php esc_html_e('Version', 'chandan-digital-ai-for-nvidia'); ?></dt>
            <dd><?php echo esc_html(VERSION); ?></dd>
            <dt><?php esc_html_e('Updates', 'chandan-digital-ai-for-nvidia'); ?></dt>
            <dd><?php esc_html_e('Manual only. Upload a new ZIP under Plugins > Add New > Upload Plugin.', 'chandan-digital-ai-for-nvidia'); ?></dd>
            <dt><?php esc_html_e('WordPress / PHP', 'chandan-digital-ai-for-nvidia'); ?></dt>
            <dd><?php echo esc_html(get_bloginfo('version') . ' / ' . PHP_VERSION); ?></dd>
        </dl>
    </section>

    <section class="cdnv-card">
        <h2><?php esc_html_e('NVIDIA connection', 'chandan-digital-ai-for-nvidia'); ?></h2>
        <dl class="cdnv-dl">
            <dt><?php esc_html_e('Status', 'chandan-digital-ai-for-nvidia'); ?></dt>
            <dd>
                <?php
                if (!$cdnv_status) {
                    echo Ui::pill(__('Not tested yet', 'chandan-digital-ai-for-nvidia'), 'muted'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
                } else {
                    echo Ui::pill(!empty($cdnv_status['ok']) ? __('Working', 'chandan-digital-ai-for-nvidia') : __('Problem found', 'chandan-digital-ai-for-nvidia'), !empty($cdnv_status['ok']) ? 'ok' : 'bad'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
                    echo ' <span class="description">' . esc_html(Ui::time((int) ($cdnv_status['time'] ?? 0))) . '</span>';
                }
                ?>
            </dd>
            <?php if (!empty($cdnv_status['message'])) : ?>
                <dt><?php esc_html_e('Last result', 'chandan-digital-ai-for-nvidia'); ?></dt>
                <dd><?php echo esc_html((string) $cdnv_status['message']); ?><?php echo !empty($cdnv_status['http']) ? ' (HTTP ' . esc_html((string) $cdnv_status['http']) . ')' : ''; ?></dd>
            <?php endif; ?>
            <dt><?php esc_html_e('API key', 'chandan-digital-ai-for-nvidia'); ?></dt>
            <dd>
                <?php echo esc_html(Settings::key_source_label($cdnv_source)); ?>
                <?php if ($cdnv_source !== 'none') : ?>
                    <code><?php echo esc_html(Settings::mask_key(Settings::api_key())); ?></code>
                <?php endif; ?>
            </dd>
            <dt><?php esc_html_e('Endpoint', 'chandan-digital-ai-for-nvidia'); ?></dt>
            <dd><code><?php echo esc_html(Settings::chat_url()); ?></code></dd>
        </dl>
        <p>
            <button type="button" class="button button-primary" data-cdnv-action="connection-test" data-target="#cdnv-overview-test"><?php esc_html_e('Test connection', 'chandan-digital-ai-for-nvidia'); ?></button>
        </p>
        <div id="cdnv-overview-test" class="cdnv-result" aria-live="polite"></div>
    </section>

    <section class="cdnv-card">
        <h2><?php esc_html_e('Selected model', 'chandan-digital-ai-for-nvidia'); ?></h2>
        <?php if ($cdnv_default) : ?>
            <p class="cdnv-model-name"><strong><?php echo esc_html($cdnv_default['name']); ?></strong> <code><?php echo esc_html($cdnv_default['id']); ?></code></p>
            <p><?php echo esc_html($cdnv_default['developer']); ?> · <?php esc_html_e('Provider: NVIDIA', 'chandan-digital-ai-for-nvidia'); ?></p>
            <p><?php echo Ui::capability_badges($cdnv_default['capabilities']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui. ?></p>
            <p><?php echo Ui::access_pill($cdnv_default['status']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui. ?></p>
        <?php endif; ?>
        <p><a class="button" href="<?php echo esc_url(AdminPage::url('models')); ?>"><?php esc_html_e('Manage models', 'chandan-digital-ai-for-nvidia'); ?></a></p>
    </section>

    <section class="cdnv-card">
        <h2><?php esc_html_e('Active Kimi K3 configuration', 'chandan-digital-ai-for-nvidia'); ?></h2>
        <?php if ($cdnv_kimi) : ?>
            <p><code><?php echo esc_html(ModelRegistry::KIMI_K3); ?></code> <?php echo Ui::access_pill($cdnv_kimi['status']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui. ?></p>
            <p><?php echo esc_html(AdminPage::settings_summary($cdnv_kimi, $cdnv_kimi_settings)); ?></p>
            <p>
                <?php echo esc_html($cdnv_kimi_settings['stream'] ? __('Streaming on', 'chandan-digital-ai-for-nvidia') : __('Streaming off', 'chandan-digital-ai-for-nvidia')); ?> ·
                <?php echo esc_html($cdnv_kimi_settings['images'] ? __('Image input on', 'chandan-digital-ai-for-nvidia') : __('Image input off', 'chandan-digital-ai-for-nvidia')); ?> ·
                <?php echo esc_html($cdnv_kimi['enabled'] ? __('Enabled', 'chandan-digital-ai-for-nvidia') : __('Disabled', 'chandan-digital-ai-for-nvidia')); ?>
            </p>
        <?php endif; ?>
        <p><a class="button" href="<?php echo esc_url(AdminPage::url('kimi')); ?>"><?php esc_html_e('Kimi K3 settings', 'chandan-digital-ai-for-nvidia'); ?></a>
            <a class="button" href="<?php echo esc_url(AdminPage::url('playground')); ?>"><?php esc_html_e('Open Playground', 'chandan-digital-ai-for-nvidia'); ?></a></p>
    </section>

    <section class="cdnv-card cdnv-card--wide">
        <h2><?php esc_html_e('WordPress AI Client integration', 'chandan-digital-ai-for-nvidia'); ?></h2>
        <dl class="cdnv-dl">
            <dt><?php esc_html_e('AI Client', 'chandan-digital-ai-for-nvidia'); ?></dt>
            <dd><?php
                echo $cdnv_has_client
                    /* translators: %s: version number. */
                    ? esc_html(sprintf(__('Available (version %s)', 'chandan-digital-ai-for-nvidia'), AiClient::VERSION))
                    : esc_html__('Not available. Other plugins cannot use NVIDIA models until WordPress 7.0+ (or the PHP AI Client) is present. The Playground still works.', 'chandan-digital-ai-for-nvidia');
            ?></dd>
            <?php if ($cdnv_has_client) : ?>
                <dt><?php esc_html_e('"nvidia" provider', 'chandan-digital-ai-for-nvidia'); ?></dt>
                <dd><?php
                    if (Plugin::provider_is_ours()) {
                        echo Ui::pill(__('Registered by this plugin', 'chandan-digital-ai-for-nvidia'), 'ok'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
                    } elseif (Plugin::provider_conflict()) {
                        echo Ui::pill(__('Registered by another plugin', 'chandan-digital-ai-for-nvidia'), 'bad'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
                    } else {
                        echo Ui::pill(__('Not registered', 'chandan-digital-ai-for-nvidia'), 'warn'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
                    }
                ?></dd>
                <dt><?php esc_html_e('Key used by the AI Client', 'chandan-digital-ai-for-nvidia'); ?></dt>
                <dd><?php echo esc_html($cdnv_ai_key_labels[$cdnv_ai_key] ?? $cdnv_ai_key_labels['none']); ?></dd>
            <?php endif; ?>
        </dl>
        <p class="description"><?php esc_html_e('Plugins that use the WordPress AI Client can choose provider "nvidia". Kimi K3 and custom models are offered to them once their access is confirmed on the AI Models tab.', 'chandan-digital-ai-for-nvidia'); ?></p>
    </section>
</div>
