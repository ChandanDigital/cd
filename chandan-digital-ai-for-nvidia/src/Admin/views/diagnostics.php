<?php
/**
 * API Diagnostics tab.
 *
 * @package ChandanDigital\NvidiaAi
 */

defined('ABSPATH') || exit;

use ChandanDigital\NvidiaAi\Admin\AdminPage;
use ChandanDigital\NvidiaAi\Admin\Ui;
use ChandanDigital\NvidiaAi\Integrations\AiPluginBridge;
use ChandanDigital\NvidiaAi\Support\Logger;
use ChandanDigital\NvidiaAi\Support\ModelRegistry;
use ChandanDigital\NvidiaAi\Support\Settings;
use WordPress\AiClient\AiClient;

$cdnv_models = ModelRegistry::enabled_chat_models();
$cdnv_logs = array_reverse(Logger::entries());
$cdnv_curl = function_exists('curl_version') ? curl_version() : null;
$cdnv_blocked = defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL;
$cdnv_max_exec = (int) ini_get('max_execution_time');
$cdnv_ai = AiPluginBridge::status();
?>
<section class="cdnv-card" id="cdnv-editor-ai">
    <h2><?php esc_html_e('Editor AI check (WordPress AI plugin)', 'chandan-digital-ai-for-nvidia'); ?></h2>
    <p><?php esc_html_e('The WordPress "AI" plugin adds title, excerpt, meta description, alt text and image buttons to the editor and sends them through this plugin\'s NVIDIA connection. If those buttons fail with a 403 error, this check shows why.', 'chandan-digital-ai-for-nvidia'); ?></p>
    <?php if (!$cdnv_ai['active']) : ?>
        <p><?php echo Ui::pill(__('AI plugin not active', 'chandan-digital-ai-for-nvidia'), 'muted'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui. ?>
            <?php esc_html_e('Nothing to check. The SEO Assistant box in the editor works without it.', 'chandan-digital-ai-for-nvidia'); ?></p>
    <?php else : ?>
        <table class="widefat striped cdnv-env">
            <tbody>
                <tr><th scope="row"><?php esc_html_e('AI plugin', 'chandan-digital-ai-for-nvidia'); ?></th><td><?php echo esc_html(sprintf(/* translators: %s: version. */ __('Active, version %s', 'chandan-digital-ai-for-nvidia'), $cdnv_ai['version'])); ?></td></tr>
                <tr><th scope="row"><?php esc_html_e('Connector Approval', 'chandan-digital-ai-for-nvidia'); ?></th><td><?php echo $cdnv_ai['approval_enabled'] ? esc_html__('On: every plugin must be approved before it can use the NVIDIA connector', 'chandan-digital-ai-for-nvidia') : esc_html__('Off: nothing is blocked by it', 'chandan-digital-ai-for-nvidia'); ?></td></tr>
                <tr><th scope="row"><?php esc_html_e('Approved for NVIDIA', 'chandan-digital-ai-for-nvidia'); ?></th><td><?php echo esc_html($cdnv_ai['approved'] ? implode(', ', $cdnv_ai['approved']) : __('None', 'chandan-digital-ai-for-nvidia')); ?></td></tr>
                <tr><th scope="row"><?php esc_html_e('Blocked requests', 'chandan-digital-ai-for-nvidia'); ?></th><td>
                    <?php if (!$cdnv_ai['blocked']) : ?>
                        <?php esc_html_e('None recorded', 'chandan-digital-ai-for-nvidia'); ?>
                    <?php else : ?>
                        <?php foreach ($cdnv_ai['blocked'] as $cdnv_caller) : ?>
                            <div><?php echo Ui::pill(__('Blocked (403)', 'chandan-digital-ai-for-nvidia'), 'bad'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui. ?>
                                <strong><?php echo esc_html($cdnv_caller['name']); ?></strong> <code><?php echo esc_html($cdnv_caller['basename']); ?></code>
                                <?php echo esc_html(sprintf(/* translators: 1: number of attempts, 2: date. */ _n('%1$d attempt, last %2$s', '%1$d attempts, last %2$s', $cdnv_caller['attempts'], 'chandan-digital-ai-for-nvidia'), $cdnv_caller['attempts'], Ui::time($cdnv_caller['last_seen']))); ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td></tr>
            </tbody>
        </table>
        <?php if ($cdnv_ai['approval_enabled'] && $cdnv_ai['blocked']) : ?>
            <div class="cdnv-summary cdnv-summary--bad">
                <p><?php esc_html_e('This is why the AI buttons in posts and pages show a 403 error. WordPress stops the request before it reaches NVIDIA, so your NVIDIA key and this plugin are not the problem.', 'chandan-digital-ai-for-nvidia'); ?></p>
                <p><?php esc_html_e('To fix it, open Tools > Connector Approvals, find the blocked plugin listed above next to NVIDIA, and approve it. Or, if you do not need approvals, turn "Connector Approval" off under Settings > AI.', 'chandan-digital-ai-for-nvidia'); ?></p>
            </div>
        <?php elseif ($cdnv_ai['approval_enabled']) : ?>
            <p class="cdnv-summary cdnv-summary--warn"><?php esc_html_e('Connector Approval is on. If an AI button shows a 403 error, press it once, then reload this page: the blocked plugin will be listed here.', 'chandan-digital-ai-for-nvidia'); ?></p>
        <?php else : ?>
            <p class="cdnv-summary cdnv-summary--ok"><?php esc_html_e('Connector Approval is not blocking anything. If AI buttons still fail, turn on local logging under Privacy & Security, press the button again, and check the log below for the HTTP status and NVIDIA\'s message.', 'chandan-digital-ai-for-nvidia'); ?></p>
        <?php endif; ?>
        <p>
            <a class="button button-primary" href="<?php echo esc_url($cdnv_ai['approval_url']); ?>"><?php esc_html_e('Open Tools > Connector Approvals', 'chandan-digital-ai-for-nvidia'); ?></a>
            <a class="button" href="<?php echo esc_url($cdnv_ai['settings_url']); ?>"><?php esc_html_e('Open Settings > AI', 'chandan-digital-ai-for-nvidia'); ?></a>
        </p>
    <?php endif; ?>
</section>

<section class="cdnv-card">
    <h2><?php esc_html_e('Connection test', 'chandan-digital-ai-for-nvidia'); ?></h2>
    <p><?php esc_html_e('Checks three things separately: that the endpoint answers, that NVIDIA accepts your API key, and that your key can use the chosen model. A key problem and a model-access problem are reported differently.', 'chandan-digital-ai-for-nvidia'); ?></p>
    <p>
        <label for="cdnv-diag-model"><?php esc_html_e('Model to check', 'chandan-digital-ai-for-nvidia'); ?></label>
        <select id="cdnv-diag-model">
            <?php foreach ($cdnv_models as $cdnv_id => $cdnv_model) : ?>
                <option value="<?php echo esc_attr($cdnv_id); ?>"><?php echo esc_html($cdnv_model['name'] . ' (' . $cdnv_id . ')'); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="button button-primary" data-cdnv-action="connection-test" data-model-select="#cdnv-diag-model" data-target="#cdnv-diag-test"><?php esc_html_e('Run connection test', 'chandan-digital-ai-for-nvidia'); ?></button>
    </p>
    <div id="cdnv-diag-test" class="cdnv-result" aria-live="polite"></div>
</section>

<section class="cdnv-card">
    <h2><?php esc_html_e('Streaming test', 'chandan-digital-ai-for-nvidia'); ?></h2>
    <p><?php esc_html_e('Sends five small events from this server, about 0.4 seconds apart, without contacting NVIDIA. If they arrive one by one, streaming works here. If they arrive together, your host or a proxy buffers output; replies will still work, but turn streaming off for a smoother experience.', 'chandan-digital-ai-for-nvidia'); ?></p>
    <p><button type="button" class="button" data-cdnv-action="stream-test" data-target="#cdnv-diag-stream"><?php esc_html_e('Test streaming', 'chandan-digital-ai-for-nvidia'); ?></button></p>
    <div id="cdnv-diag-stream" class="cdnv-result" aria-live="polite"></div>
</section>

<section class="cdnv-card">
    <h2><?php esc_html_e('Environment', 'chandan-digital-ai-for-nvidia'); ?></h2>
    <table class="widefat striped cdnv-env">
        <tbody>
            <tr><th scope="row"><?php esc_html_e('Chat endpoint', 'chandan-digital-ai-for-nvidia'); ?></th><td><code><?php echo esc_html(Settings::chat_url()); ?></code></td></tr>
            <tr><th scope="row"><?php esc_html_e('API key source', 'chandan-digital-ai-for-nvidia'); ?></th><td><?php echo esc_html(Settings::key_source_label(Settings::key_source())); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('PHP', 'chandan-digital-ai-for-nvidia'); ?></th><td><?php echo esc_html(PHP_VERSION); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('cURL', 'chandan-digital-ai-for-nvidia'); ?></th><td><?php echo $cdnv_curl ? esc_html($cdnv_curl['version'] . ' / ' . $cdnv_curl['ssl_version']) : esc_html__('Not available: streaming falls back to buffered mode and Stop cannot cancel the upstream request.', 'chandan-digital-ai-for-nvidia'); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('WordPress AI Client', 'chandan-digital-ai-for-nvidia'); ?></th><td><?php echo class_exists(AiClient::class) ? esc_html(AiClient::VERSION) : esc_html__('Not available', 'chandan-digital-ai-for-nvidia'); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('Request timeout', 'chandan-digital-ai-for-nvidia'); ?></th><td><?php echo esc_html((string) Settings::get('timeout')); ?> s</td></tr>
            <tr><th scope="row"><?php esc_html_e('PHP max_execution_time', 'chandan-digital-ai-for-nvidia'); ?></th><td><?php echo esc_html($cdnv_max_exec === 0 ? __('No limit', 'chandan-digital-ai-for-nvidia') : $cdnv_max_exec . ' s'); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('Server software', 'chandan-digital-ai-for-nvidia'); ?></th><td><?php echo esc_html(isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '-'); ?></td></tr>
            <tr><th scope="row">WP_HTTP_BLOCK_EXTERNAL</th><td><?php echo esc_html($cdnv_blocked ? __('On: add integrate.api.nvidia.com to WP_ACCESSIBLE_HOSTS', 'chandan-digital-ai-for-nvidia') : __('Off', 'chandan-digital-ai-for-nvidia')); ?></td></tr>
        </tbody>
    </table>
</section>

<section class="cdnv-card">
    <h2><?php esc_html_e('Recent local log entries', 'chandan-digital-ai-for-nvidia'); ?></h2>
    <?php if (!Settings::get('logging')) : ?>
        <p><?php esc_html_e('Local logging is off.', 'chandan-digital-ai-for-nvidia'); ?> <a href="<?php echo esc_url(AdminPage::url('privacy')); ?>"><?php esc_html_e('Turn it on under Privacy & Security.', 'chandan-digital-ai-for-nvidia'); ?></a></p>
    <?php elseif (!$cdnv_logs) : ?>
        <p><?php esc_html_e('No entries yet.', 'chandan-digital-ai-for-nvidia'); ?></p>
    <?php else : ?>
        <div class="cdnv-table-wrap">
            <table class="widefat striped">
                <thead><tr>
                    <th scope="col"><?php esc_html_e('Time', 'chandan-digital-ai-for-nvidia'); ?></th>
                    <th scope="col"><?php esc_html_e('Type', 'chandan-digital-ai-for-nvidia'); ?></th>
                    <th scope="col"><?php esc_html_e('Model', 'chandan-digital-ai-for-nvidia'); ?></th>
                    <th scope="col"><?php esc_html_e('HTTP', 'chandan-digital-ai-for-nvidia'); ?></th>
                    <th scope="col"><?php esc_html_e('Duration', 'chandan-digital-ai-for-nvidia'); ?></th>
                    <th scope="col"><?php esc_html_e('Result', 'chandan-digital-ai-for-nvidia'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach (array_slice($cdnv_logs, 0, 100) as $cdnv_log) : ?>
                    <tr>
                        <td><?php echo esc_html(Ui::time((int) $cdnv_log['time'])); ?></td>
                        <td><?php echo esc_html((string) $cdnv_log['type']); ?></td>
                        <td><code><?php echo esc_html((string) $cdnv_log['model']); ?></code></td>
                        <td><?php echo esc_html((string) $cdnv_log['status']); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) $cdnv_log['duration_ms'])); ?> ms</td>
                        <td>
                            <?php echo $cdnv_log['error_code'] === '' ? esc_html__('OK', 'chandan-digital-ai-for-nvidia') : esc_html($cdnv_log['error_code'] . ': ' . $cdnv_log['error']); ?>
                            <?php if (!empty($cdnv_log['prompt']) || !empty($cdnv_log['response'])) : ?>
                                <details><summary><?php esc_html_e('Content', 'chandan-digital-ai-for-nvidia'); ?></summary>
                                    <p><strong><?php esc_html_e('Prompt:', 'chandan-digital-ai-for-nvidia'); ?></strong> <?php echo esc_html((string) ($cdnv_log['prompt'] ?? '')); ?></p>
                                    <p><strong><?php esc_html_e('Response:', 'chandan-digital-ai-for-nvidia'); ?></strong> <?php echo esc_html((string) ($cdnv_log['response'] ?? '')); ?></p>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if (Logger::entries()) : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-cdnv-confirm="<?php esc_attr_e('Delete all local log entries?', 'chandan-digital-ai-for-nvidia'); ?>">
            <input type="hidden" name="action" value="cdnv_clear_logs">
            <input type="hidden" name="return_tab" value="diagnostics">
            <?php wp_nonce_field('cdnv_clear_logs'); ?>
            <?php submit_button(__('Clear local logs', 'chandan-digital-ai-for-nvidia'), 'delete', 'submit', false); ?>
        </form>
    <?php endif; ?>
</section>
