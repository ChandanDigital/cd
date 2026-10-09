<?php
/**
 * NVIDIA API Settings tab.
 *
 * @package ChandanDigital\NvidiaAi
 */

defined('ABSPATH') || exit;

use ChandanDigital\NvidiaAi\Support\Settings;

$cdnv_source = Settings::key_source();
$cdnv_constant = $cdnv_source === 'constant';
$cdnv_stored = Settings::stored_key();
$cdnv_settings = Settings::all();
?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cdnv-form" autocomplete="off">
    <input type="hidden" name="action" value="cdnv_save_api">
    <?php wp_nonce_field('cdnv_save_api'); ?>

    <section class="cdnv-card">
        <h2><?php esc_html_e('API key', 'chandan-digital-ai-for-nvidia'); ?></h2>
        <p>
            <?php esc_html_e('Active key:', 'chandan-digital-ai-for-nvidia'); ?>
            <strong><?php echo esc_html(Settings::key_source_label($cdnv_source)); ?></strong>
            <?php if ($cdnv_source !== 'none') : ?>
                <code><?php echo esc_html(Settings::mask_key(Settings::api_key())); ?></code>
            <?php endif; ?>
        </p>
        <?php if (Settings::stored_key_unreadable()) : ?>
            <div class="notice notice-warning inline"><p><?php esc_html_e('A key was saved here earlier but can no longer be decrypted, usually because the security salts in wp-config.php changed. Enter the key again.', 'chandan-digital-ai-for-nvidia'); ?></p></div>
        <?php endif; ?>

        <?php if ($cdnv_constant) : ?>
            <p class="description"><?php esc_html_e('The key is defined in wp-config.php with CHANDAN_NVIDIA_API_KEY, so it cannot be changed here. Edit wp-config.php to change it.', 'chandan-digital-ai-for-nvidia'); ?></p>
        <?php else : ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="cdnv-api-key"><?php esc_html_e('NVIDIA API key', 'chandan-digital-ai-for-nvidia'); ?></label></th>
                    <td>
                        <input type="password" id="cdnv-api-key" name="api_key" class="regular-text" value="" autocomplete="new-password" spellcheck="false"
                            placeholder="<?php echo esc_attr($cdnv_stored !== '' ? Settings::mask_key($cdnv_stored) : 'nvapi-…'); ?>">
                        <p class="description"><?php esc_html_e('Leave empty to keep the current key. The key is encrypted with your site\'s secret salts before it is stored, and it is never shown again or sent to the browser.', 'chandan-digital-ai-for-nvidia'); ?></p>
                        <?php if ($cdnv_stored !== '') : ?>
                            <p><label><input type="checkbox" name="remove_key" value="1"> <?php esc_html_e('Remove the saved key', 'chandan-digital-ai-for-nvidia'); ?></label></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <details class="cdnv-details">
                <summary><?php esc_html_e('More secure option: define the key in wp-config.php', 'chandan-digital-ai-for-nvidia'); ?></summary>
                <p><?php esc_html_e('Add this line above "That\'s all, stop editing!" in wp-config.php. A key defined there takes priority over the saved key and never touches the database.', 'chandan-digital-ai-for-nvidia'); ?></p>
                <pre class="cdnv-code">define( 'CHANDAN_NVIDIA_API_KEY', 'nvapi-your-new-key' );</pre>
            </details>
        <?php endif; ?>
        <p class="description"><?php esc_html_e('The key saved here and the key in Settings > Connectors are kept the same: saving a new key in either place updates the other, so this plugin and the WordPress AI plugin always use one key.', 'chandan-digital-ai-for-nvidia'); ?></p>
        <p class="description"><?php esc_html_e('Key order: CHANDAN_NVIDIA_API_KEY constant, then the key saved here, then an NVIDIA_API_KEY environment variable or constant, then Settings > Connectors.', 'chandan-digital-ai-for-nvidia'); ?></p>
    </section>

    <section class="cdnv-card">
        <h2><?php esc_html_e('Endpoint and requests', 'chandan-digital-ai-for-nvidia'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cdnv-base-url"><?php esc_html_e('API base URL', 'chandan-digital-ai-for-nvidia'); ?></label></th>
                <td>
                    <input type="url" id="cdnv-base-url" name="base_url" class="regular-text code" value="<?php echo esc_attr((string) $cdnv_settings['base_url']); ?>">
                    <p class="description">
                        <?php esc_html_e('Chat completions endpoint used:', 'chandan-digital-ai-for-nvidia'); ?>
                        <code><?php echo esc_html(Settings::chat_url()); ?></code><br>
                        <?php
                        /* translators: %s: default URL. */
                        echo esc_html(sprintf(__('Default: %s. Only https:// is accepted.', 'chandan-digital-ai-for-nvidia'), Settings::DEFAULT_BASE_URL));
                        ?>
                    </p>
                    <?php if (!Settings::is_nvidia_host()) : ?>
                        <div class="notice notice-warning inline"><p><?php esc_html_e('This base URL is not an nvidia.com address. Your API key is sent to it with every request. Use it only for an NVIDIA NIM deployment you control.', 'chandan-digital-ai-for-nvidia'); ?></p></div>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cdnv-timeout"><?php esc_html_e('Request timeout (seconds)', 'chandan-digital-ai-for-nvidia'); ?></label></th>
                <td>
                    <input type="number" id="cdnv-timeout" name="timeout" min="10" max="600" step="1" class="small-text" value="<?php echo esc_attr((string) $cdnv_settings['timeout']); ?>">
                    <p class="description"><?php esc_html_e('Kimi K3 thinks before it answers, and at maximum reasoning effort a reply can take a few minutes. 120 to 300 seconds works well. Your host may stop PHP sooner.', 'chandan-digital-ai-for-nvidia'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cdnv-connect-timeout"><?php esc_html_e('Connection timeout (seconds)', 'chandan-digital-ai-for-nvidia'); ?></label></th>
                <td><input type="number" id="cdnv-connect-timeout" name="connect_timeout" min="3" max="60" step="1" class="small-text" value="<?php echo esc_attr((string) $cdnv_settings['connect_timeout']); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="cdnv-retries"><?php esc_html_e('Automatic retries', 'chandan-digital-ai-for-nvidia'); ?></label></th>
                <td>
                    <input type="number" id="cdnv-retries" name="max_retries" min="0" max="3" step="1" class="small-text" value="<?php echo esc_attr((string) $cdnv_settings['max_retries']); ?>">
                    <?php esc_html_e('retries, waiting at most', 'chandan-digital-ai-for-nvidia'); ?>
                    <input type="number" name="max_retry_wait" min="1" max="60" step="1" class="small-text" value="<?php echo esc_attr((string) $cdnv_settings['max_retry_wait']); ?>" aria-label="<?php esc_attr_e('Longest wait before a retry, in seconds', 'chandan-digital-ai-for-nvidia'); ?>">
                    <?php esc_html_e('seconds', 'chandan-digital-ai-for-nvidia'); ?>
                    <p class="description"><?php esc_html_e('Only temporary problems are retried: rate limits (429, respecting Retry-After), NVIDIA outages (500, 502, 503, 504) and network errors. Key, model and request errors are never retried.', 'chandan-digital-ai-for-nvidia'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Key for the WordPress AI Client', 'chandan-digital-ai-for-nvidia'); ?></th>
                <td>
                    <fieldset>
                        <label><input type="radio" name="ai_client_key_mode" value="fallback" <?php checked($cdnv_settings['ai_client_key_mode'], 'fallback'); ?>> <?php esc_html_e('Use this plugin\'s key only when WordPress has no NVIDIA key of its own (recommended)', 'chandan-digital-ai-for-nvidia'); ?></label><br>
                        <label><input type="radio" name="ai_client_key_mode" value="always" <?php checked($cdnv_settings['ai_client_key_mode'], 'always'); ?>> <?php esc_html_e('Always use this plugin\'s key', 'chandan-digital-ai-for-nvidia'); ?></label><br>
                        <label><input type="radio" name="ai_client_key_mode" value="never" <?php checked($cdnv_settings['ai_client_key_mode'], 'never'); ?>> <?php esc_html_e('Never share it; other plugins use only Settings > Connectors or NVIDIA_API_KEY', 'chandan-digital-ai-for-nvidia'); ?></label>
                    </fieldset>
                </td>
            </tr>
        </table>
    </section>

    <p class="submit">
        <?php submit_button(__('Save configuration', 'chandan-digital-ai-for-nvidia'), 'primary', 'submit', false); ?>
        <button type="button" class="button" data-cdnv-action="connection-test" data-target="#cdnv-api-test"><?php esc_html_e('Test connection', 'chandan-digital-ai-for-nvidia'); ?></button>
    </p>
    <p class="description"><?php esc_html_e('Save first, then test. The test uses the saved settings.', 'chandan-digital-ai-for-nvidia'); ?></p>
    <div id="cdnv-api-test" class="cdnv-result" aria-live="polite"></div>
</form>

<section class="cdnv-card">
    <h2><?php esc_html_e('How to get an NVIDIA API key', 'chandan-digital-ai-for-nvidia'); ?></h2>
    <ol>
        <li><?php esc_html_e('Go to build.nvidia.com and sign in with your NVIDIA account.', 'chandan-digital-ai-for-nvidia'); ?></li>
        <li><?php esc_html_e('Open the Kimi K3 model page (moonshotai/kimi-k3) and choose "Get API Key", or create a key from your account\'s API keys page.', 'chandan-digital-ai-for-nvidia'); ?></li>
        <li><?php esc_html_e('Copy the key (it starts with nvapi-), paste it above, save, and press Test connection.', 'chandan-digital-ai-for-nvidia'); ?></li>
    </ol>
    <p class="description"><?php esc_html_e('If a key was ever shared in a chat, email or screenshot, revoke it on build.nvidia.com and create a new one.', 'chandan-digital-ai-for-nvidia'); ?></p>
</section>
