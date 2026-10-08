<?php
/**
 * Privacy & Security tab.
 *
 * @package ChandanDigital\NvidiaAi
 */

defined('ABSPATH') || exit;

use ChandanDigital\NvidiaAi\Support\Logger;
use ChandanDigital\NvidiaAi\Support\Settings;

$cdnv_settings = Settings::all();
$cdnv_log_count = count(Logger::entries());
?>
<section class="cdnv-card">
    <h2><?php esc_html_e('External connection policy', 'chandan-digital-ai-for-nvidia'); ?></h2>
    <p><?php esc_html_e('This plugin connects only to NVIDIA, and only when someone makes an AI request, runs a test or refreshes models. It does not contact Chandan Digital, the original plugin developer or any analytics service.', 'chandan-digital-ai-for-nvidia'); ?></p>
    <table class="widefat striped">
        <thead><tr>
            <th scope="col"><?php esc_html_e('Host', 'chandan-digital-ai-for-nvidia'); ?></th>
            <th scope="col"><?php esc_html_e('When', 'chandan-digital-ai-for-nvidia'); ?></th>
            <th scope="col"><?php esc_html_e('What is sent', 'chandan-digital-ai-for-nvidia'); ?></th>
        </tr></thead>
        <tbody>
            <tr>
                <td><code><?php echo esc_html((string) wp_parse_url(Settings::base_url(), PHP_URL_HOST)); ?></code></td>
                <td><?php esc_html_e('Chat requests (Playground and other plugins via the WordPress AI Client), access checks, connection tests, model refresh', 'chandan-digital-ai-for-nvidia'); ?></td>
                <td><?php esc_html_e('Your API key (Authorization header), the model ID, the messages and any images you add, and the generation settings', 'chandan-digital-ai-for-nvidia'); ?></td>
            </tr>
            <tr>
                <td><code>ai.api.nvidia.com</code></td>
                <td><?php esc_html_e('Only when another plugin asks a FLUX model to generate an image through the WordPress AI Client', 'chandan-digital-ai-for-nvidia'); ?></td>
                <td><?php esc_html_e('Your API key, the image prompt and image settings', 'chandan-digital-ai-for-nvidia'); ?></td>
            </tr>
        </tbody>
    </table>
    <ul class="ul-disc">
        <li><?php esc_html_e('No telemetry, usage tracking, remote update checks, remote code or webhooks.', 'chandan-digital-ai-for-nvidia'); ?></li>
        <li><?php esc_html_e('No external scripts, fonts or styles are loaded in the dashboard.', 'chandan-digital-ai-for-nvidia'); ?></li>
        <li><?php esc_html_e('Requests to NVIDIA use a plain user agent and do not include this site\'s address.', 'chandan-digital-ai-for-nvidia'); ?></li>
        <li><?php esc_html_e('Images: uploaded files are checked in memory and sent to NVIDIA inside the request; they are not saved on this site. Image URLs are passed to NVIDIA, which downloads them; this site does not.', 'chandan-digital-ai-for-nvidia'); ?></li>
        <li><?php esc_html_e('NVIDIA\'s own terms and privacy policy apply to data sent to its API.', 'chandan-digital-ai-for-nvidia'); ?></li>
    </ul>
</section>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="cdnv_save_privacy">
    <?php wp_nonce_field('cdnv_save_privacy'); ?>
    <section class="cdnv-card">
        <h2><?php esc_html_e('Local logging', 'chandan-digital-ai-for-nvidia'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Diagnostic log', 'chandan-digital-ai-for-nvidia'); ?></th>
                <td>
                    <label><input type="checkbox" name="logging" value="1" <?php checked((bool) $cdnv_settings['logging']); ?>> <?php esc_html_e('Keep a local log of requests (off by default)', 'chandan-digital-ai-for-nvidia'); ?></label>
                    <p class="description"><?php esc_html_e('Records time, request type, model ID, HTTP status, duration and a cleaned error message. Never records API keys, Authorization headers or image data. Stored in this site\'s database only. Turning logging off deletes existing entries.', 'chandan-digital-ai-for-nvidia'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Prompts and responses', 'chandan-digital-ai-for-nvidia'); ?></th>
                <td>
                    <label><input type="checkbox" name="log_content" value="1" <?php checked((bool) $cdnv_settings['log_content']); ?>> <?php esc_html_e('Also record Playground prompts and responses (shortened to 2,000 characters)', 'chandan-digital-ai-for-nvidia'); ?></label>
                    <p class="description"><?php esc_html_e('Leave this off unless you are troubleshooting. Prompts can contain private information.', 'chandan-digital-ai-for-nvidia'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cdnv-retention"><?php esc_html_e('Keep entries for', 'chandan-digital-ai-for-nvidia'); ?></label></th>
                <td><input type="number" id="cdnv-retention" name="log_retention_days" min="1" max="90" step="1" class="small-text" value="<?php echo esc_attr((string) $cdnv_settings['log_retention_days']); ?>"> <?php esc_html_e('days (at most 500 entries)', 'chandan-digital-ai-for-nvidia'); ?></td>
            </tr>
        </table>
    </section>

    <section class="cdnv-card">
        <h2><?php esc_html_e('Access and content', 'chandan-digital-ai-for-nvidia'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Who can use the Playground', 'chandan-digital-ai-for-nvidia'); ?></th>
                <td>
                    <label><input type="radio" name="playground_access" value="administrator" <?php checked($cdnv_settings['playground_access'], 'administrator'); ?>> <?php esc_html_e('Administrators only', 'chandan-digital-ai-for-nvidia'); ?></label><br>
                    <label><input type="radio" name="playground_access" value="editor" <?php checked($cdnv_settings['playground_access'], 'editor'); ?>> <?php esc_html_e('Administrators and Editors (Editors see only the Playground; uploading images also needs the upload_files permission)', 'chandan-digital-ai-for-nvidia'); ?></label>
                    <p class="description"><?php esc_html_e('API settings, model management, diagnostics and logs are always limited to administrators.', 'chandan-digital-ai-for-nvidia'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Editorial policy for other plugins', 'chandan-digital-ai-for-nvidia'); ?></th>
                <td>
                    <label><input type="checkbox" name="editorial_policy" value="1" <?php checked((bool) $cdnv_settings['editorial_policy']); ?>> <?php esc_html_e('Add the Indian English editorial policy to every text request made through the WordPress AI Client, and reject replies that contain an em dash (behaviour from version 1.0.1, on by default)', 'chandan-digital-ai-for-nvidia'); ?></label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('When the plugin is deleted', 'chandan-digital-ai-for-nvidia'); ?></th>
                <td>
                    <label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked((bool) $cdnv_settings['delete_data_on_uninstall']); ?>> <?php esc_html_e('Also delete the saved API key and all settings', 'chandan-digital-ai-for-nvidia'); ?></label>
                    <p class="description"><?php esc_html_e('Off by default, so deleting and reinstalling keeps your configuration. Logs, cached data and temporary data are always removed. Updating by uploading a new ZIP never deletes anything.', 'chandan-digital-ai-for-nvidia'); ?></p>
                </td>
            </tr>
        </table>
    </section>
    <?php submit_button(__('Save privacy and security settings', 'chandan-digital-ai-for-nvidia')); ?>
</form>

<section class="cdnv-card">
    <h2><?php esc_html_e('Clear local logs', 'chandan-digital-ai-for-nvidia'); ?></h2>
    <p><?php
        /* translators: %d: number of log entries. */
        echo esc_html(sprintf(_n('%d entry stored.', '%d entries stored.', $cdnv_log_count, 'chandan-digital-ai-for-nvidia'), $cdnv_log_count));
    ?></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-cdnv-confirm="<?php esc_attr_e('Delete all local log entries?', 'chandan-digital-ai-for-nvidia'); ?>">
        <input type="hidden" name="action" value="cdnv_clear_logs">
        <input type="hidden" name="return_tab" value="privacy">
        <?php wp_nonce_field('cdnv_clear_logs'); ?>
        <?php submit_button(__('Clear logs', 'chandan-digital-ai-for-nvidia'), 'delete', 'submit', false, $cdnv_log_count ? [] : ['disabled' => 'disabled']); ?>
    </form>
</section>
