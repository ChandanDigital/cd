<?php
/**
 * AI Models tab (model manager).
 *
 * @package ChandanDigital\NvidiaAi
 */

defined('ABSPATH') || exit;

use ChandanDigital\NvidiaAi\Admin\AdminPage;
use ChandanDigital\NvidiaAi\Admin\Ui;
use ChandanDigital\NvidiaAi\Support\ModelRegistry;
use ChandanDigital\NvidiaAi\Support\Settings;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
$cdnv_configure = isset($_GET['configure']) ? sanitize_text_field(wp_unslash($_GET['configure'])) : '';
$cdnv_configure_model = $cdnv_configure !== '' ? ModelRegistry::get($cdnv_configure) : null;

if ($cdnv_configure_model !== null && $cdnv_configure_model['kind'] !== 'image') :
    ?>
    <p><a href="<?php echo esc_url(AdminPage::url('models')); ?>">&larr; <?php esc_html_e('Back to all models', 'chandan-digital-ai-for-nvidia'); ?></a></p>
    <?php
    AdminPage::view('model-settings', ['model' => $cdnv_configure_model, 'returnTab' => 'models']);
    return;
endif;

$cdnv_models = ModelRegistry::all();
$cdnv_catalog = ModelRegistry::catalog();
$cdnv_catalog_ids = array_flip($cdnv_catalog['ids']);
$cdnv_default = (string) Settings::get('default_model');
?>
<section class="cdnv-card cdnv-card--flush">
    <div class="cdnv-toolbar">
        <label class="screen-reader-text" for="cdnv-model-search"><?php esc_html_e('Search models', 'chandan-digital-ai-for-nvidia'); ?></label>
        <input type="search" id="cdnv-model-search" class="regular-text" placeholder="<?php esc_attr_e('Search by name, ID, developer or capability…', 'chandan-digital-ai-for-nvidia'); ?>">
        <label><input type="checkbox" id="cdnv-model-enabled-only"> <?php esc_html_e('Enabled only', 'chandan-digital-ai-for-nvidia'); ?></label>
        <span class="cdnv-toolbar__spacer"></span>
        <span class="description">
            <?php esc_html_e('Catalogue refreshed:', 'chandan-digital-ai-for-nvidia'); ?>
            <?php echo esc_html(Ui::time($cdnv_catalog['fetched_at'])); ?>
        </span>
        <button type="button" class="button" data-cdnv-action="refresh-models" data-target="#cdnv-refresh-result"><?php esc_html_e('Refresh models', 'chandan-digital-ai-for-nvidia'); ?></button>
    </div>
    <div id="cdnv-refresh-result" class="cdnv-result" aria-live="polite"></div>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="cdnv_save_models">
        <?php wp_nonce_field('cdnv_save_models'); ?>
        <div class="cdnv-table-wrap">
            <table class="widefat striped cdnv-models-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('On', 'chandan-digital-ai-for-nvidia'); ?></th>
                        <th scope="col"><?php esc_html_e('Default', 'chandan-digital-ai-for-nvidia'); ?></th>
                        <th scope="col"><?php esc_html_e('Model', 'chandan-digital-ai-for-nvidia'); ?></th>
                        <th scope="col"><?php esc_html_e('Developer / provider', 'chandan-digital-ai-for-nvidia'); ?></th>
                        <th scope="col"><?php esc_html_e('Capabilities', 'chandan-digital-ai-for-nvidia'); ?></th>
                        <th scope="col"><?php esc_html_e('Access', 'chandan-digital-ai-for-nvidia'); ?></th>
                        <th scope="col"><?php esc_html_e('In catalogue', 'chandan-digital-ai-for-nvidia'); ?></th>
                        <th scope="col"><?php esc_html_e('Actions', 'chandan-digital-ai-for-nvidia'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($cdnv_models as $cdnv_id => $cdnv_model) :
                    $cdnv_chat = $cdnv_model['kind'] !== 'image';
                    $cdnv_search = strtolower(implode(' ', [$cdnv_model['name'], $cdnv_id, $cdnv_model['developer'], implode(' ', $cdnv_model['capabilities']), $cdnv_model['source']]));
                    ?>
                    <tr data-cdnv-model-row data-search="<?php echo esc_attr($cdnv_search); ?>" data-enabled="<?php echo $cdnv_model['enabled'] ? '1' : '0'; ?>">
                        <td><input type="checkbox" name="enabled[<?php echo esc_attr($cdnv_id); ?>]" value="1" <?php checked($cdnv_model['enabled']); ?> aria-label="<?php echo esc_attr(sprintf(/* translators: %s: model name. */ __('Enable %s', 'chandan-digital-ai-for-nvidia'), $cdnv_model['name'])); ?>"></td>
                        <td><?php if ($cdnv_chat) : ?><input type="radio" name="default_model" value="<?php echo esc_attr($cdnv_id); ?>" <?php checked($cdnv_default, $cdnv_id); ?> aria-label="<?php echo esc_attr(sprintf(/* translators: %s: model name. */ __('Make %s the default model', 'chandan-digital-ai-for-nvidia'), $cdnv_model['name'])); ?>"><?php endif; ?></td>
                        <td>
                            <strong><?php echo esc_html($cdnv_model['name']); ?></strong>
                            <?php if ($cdnv_id === ModelRegistry::KIMI_K3) : ?><?php echo Ui::pill(__('New', 'chandan-digital-ai-for-nvidia'), 'info'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui. ?><?php endif; ?>
                            <?php if ($cdnv_model['source'] === 'custom') : ?><?php echo Ui::pill(__('Custom', 'chandan-digital-ai-for-nvidia'), 'info'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui. ?><?php endif; ?>
                            <br><code class="cdnv-model-id"><?php echo esc_html($cdnv_id); ?></code>
                        </td>
                        <td><?php echo esc_html($cdnv_model['developer']); ?><br><span class="description">NVIDIA</span></td>
                        <td><?php echo Ui::capability_badges($cdnv_model['capabilities']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui. ?></td>
                        <td data-cdnv-access="<?php echo esc_attr($cdnv_id); ?>">
                            <?php
                            if ($cdnv_chat) {
                                echo Ui::access_pill($cdnv_model['status']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
                                if ($cdnv_model['requires_verification'] && ($cdnv_model['status']['state'] ?? '') !== 'verified') {
                                    echo '<br><span class="description">' . esc_html__('Not offered to other plugins until confirmed.', 'chandan-digital-ai-for-nvidia') . '</span>';
                                }
                            } else {
                                echo Ui::pill(__('Via AI Client only', 'chandan-digital-ai-for-nvidia'), 'muted'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            if (!$cdnv_chat) {
                                echo '<span class="description">' . esc_html__('Separate endpoint', 'chandan-digital-ai-for-nvidia') . '</span>';
                            } elseif (!$cdnv_catalog['fetched_at']) {
                                echo '-';
                            } else {
                                echo isset($cdnv_catalog_ids[$cdnv_id]) ? esc_html__('Listed', 'chandan-digital-ai-for-nvidia') : '<span class="cdnv-text-warn">' . esc_html__('Not listed', 'chandan-digital-ai-for-nvidia') . '</span>';
                            }
                            ?>
                        </td>
                        <td class="cdnv-actions">
                            <?php if ($cdnv_chat) : ?>
                                <button type="button" class="button button-small" data-cdnv-action="verify-model" data-model="<?php echo esc_attr($cdnv_id); ?>"><?php esc_html_e('Check access', 'chandan-digital-ai-for-nvidia'); ?></button>
                                <a class="button button-small" href="<?php echo esc_url($cdnv_id === ModelRegistry::KIMI_K3 ? AdminPage::url('kimi') : AdminPage::url('models', ['configure' => $cdnv_id])); ?>"><?php esc_html_e('Settings', 'chandan-digital-ai-for-nvidia'); ?></a>
                            <?php endif; ?>
                            <?php if ($cdnv_model['source'] === 'custom') : ?>
                                <button type="submit" class="button button-small button-link-delete" form="cdnv-remove-<?php echo esc_attr(md5($cdnv_id)); ?>"><?php esc_html_e('Remove', 'chandan-digital-ai-for-nvidia'); ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="cdnv-no-results" hidden><?php esc_html_e('No models match your search.', 'chandan-digital-ai-for-nvidia'); ?></p>

        <p><label><input type="checkbox" name="auto_refresh_models" value="1" <?php checked((bool) Settings::get('auto_refresh_models')); ?>>
            <?php esc_html_e('Refresh the NVIDIA catalogue automatically once a day (off by default; uses WP-Cron).', 'chandan-digital-ai-for-nvidia'); ?></label></p>
        <?php submit_button(__('Save model choices', 'chandan-digital-ai-for-nvidia')); ?>
    </form>

    <?php foreach ($cdnv_models as $cdnv_id => $cdnv_model) :
        if ($cdnv_model['source'] !== 'custom') {
            continue;
        } ?>
        <form id="cdnv-remove-<?php echo esc_attr(md5($cdnv_id)); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cdnv-hidden-form" data-cdnv-confirm="<?php esc_attr_e('Remove this custom model and its settings?', 'chandan-digital-ai-for-nvidia'); ?>">
            <input type="hidden" name="action" value="cdnv_remove_custom">
            <input type="hidden" name="model_id" value="<?php echo esc_attr($cdnv_id); ?>">
            <?php wp_nonce_field('cdnv_remove_custom'); ?>
        </form>
    <?php endforeach; ?>
</section>

<section class="cdnv-card">
    <h2><?php esc_html_e('About model availability', 'chandan-digital-ai-for-nvidia'); ?></h2>
    <ul class="ul-disc">
        <li><?php esc_html_e('"Refresh models" downloads NVIDIA\'s public catalogue. Being listed there does not mean your API key can use a model.', 'chandan-digital-ai-for-nvidia'); ?></li>
        <li><?php esc_html_e('"Check access" sends a tiny test request (a few output tokens) with your key. Only a successful answer counts as confirmed access.', 'chandan-digital-ai-for-nvidia'); ?></li>
        <li><?php esc_html_e('Original built-in models are offered to other plugins while NVIDIA lists them. Kimi K3 and custom models are offered only after access is confirmed.', 'chandan-digital-ai-for-nvidia'); ?></li>
        <li><?php esc_html_e('Image generation (FLUX) models run on a separate NVIDIA endpoint and are used through the WordPress AI Client only.', 'chandan-digital-ai-for-nvidia'); ?></li>
    </ul>
</section>

<section class="cdnv-card" id="cdnv-add-custom">
    <h2><?php esc_html_e('Add a custom model', 'chandan-digital-ai-for-nvidia'); ?></h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="cdnv_add_custom">
        <?php wp_nonce_field('cdnv_add_custom'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cdnv-custom-id"><?php esc_html_e('Model ID', 'chandan-digital-ai-for-nvidia'); ?></label></th>
                <td><input type="text" id="cdnv-custom-id" name="model_id" class="regular-text code" required pattern="[a-z0-9][a-z0-9._\-]*/[A-Za-z0-9][A-Za-z0-9._:\-]*" placeholder="publisher/model-name" spellcheck="false">
                    <p class="description"><?php esc_html_e('Copy the exact ID from the model\'s page on build.nvidia.com.', 'chandan-digital-ai-for-nvidia'); ?></p></td>
            </tr>
            <tr>
                <th scope="row"><label for="cdnv-custom-name"><?php esc_html_e('Display name', 'chandan-digital-ai-for-nvidia'); ?></label></th>
                <td><input type="text" id="cdnv-custom-name" name="model_name" class="regular-text" maxlength="80"></td>
            </tr>
            <tr>
                <th scope="row"><label for="cdnv-custom-developer"><?php esc_html_e('Developer', 'chandan-digital-ai-for-nvidia'); ?></label></th>
                <td><input type="text" id="cdnv-custom-developer" name="model_developer" class="regular-text" maxlength="80"></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Type', 'chandan-digital-ai-for-nvidia'); ?></th>
                <td>
                    <label><input type="radio" name="model_kind" value="text" checked> <?php esc_html_e('Text chat', 'chandan-digital-ai-for-nvidia'); ?></label><br>
                    <label><input type="radio" name="model_kind" value="vision"> <?php esc_html_e('Chat with image input', 'chandan-digital-ai-for-nvidia'); ?></label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Reasoning effort', 'chandan-digital-ai-for-nvidia'); ?></th>
                <td><label><input type="checkbox" name="model_reasoning" value="1"> <?php esc_html_e('The model accepts the reasoning_effort parameter (check NVIDIA\'s documentation first)', 'chandan-digital-ai-for-nvidia'); ?></label></td>
            </tr>
        </table>
        <?php submit_button(__('Add model', 'chandan-digital-ai-for-nvidia'), 'secondary'); ?>
    </form>
</section>
