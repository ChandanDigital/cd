<?php
/**
 * Model-specific settings form, used by the AI Models and Kimi K3 tabs.
 *
 * @package ChandanDigital\NvidiaAi
 *
 * @var array<string, mixed> $model     Model descriptor.
 * @var string               $returnTab Tab to return to after saving.
 */

defined('ABSPATH') || exit;

use ChandanDigital\NvidiaAi\Admin\Ui;
use ChandanDigital\NvidiaAi\Support\ModelRegistry;

$cdnv_s = ModelRegistry::settings($model['id']);
$cdnv_defaults = ModelRegistry::default_settings($model['id']);
$cdnv_vision = $model['kind'] === 'vision';
$cdnv_reasoning_labels = [
    'default' => __('Default (do not send the parameter)', 'chandan-digital-ai-for-nvidia'),
    'low' => __('Low', 'chandan-digital-ai-for-nvidia'),
    'medium' => __('Medium', 'chandan-digital-ai-for-nvidia'),
    'high' => __('High', 'chandan-digital-ai-for-nvidia'),
    'max' => __('Max', 'chandan-digital-ai-for-nvidia'),
];
$cdnv_value = static function ($value): string {
    return $value === null ? '' : (string) $value;
};
?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cdnv-form">
    <input type="hidden" name="action" value="cdnv_save_model_settings">
    <input type="hidden" name="model_id" value="<?php echo esc_attr($model['id']); ?>">
    <input type="hidden" name="return_tab" value="<?php echo esc_attr($returnTab); ?>">
    <?php wp_nonce_field('cdnv_save_model_settings'); ?>

    <section class="cdnv-card">
        <h2>
            <?php
            /* translators: %s: model name. */
            echo esc_html(sprintf(__('%s: generation settings', 'chandan-digital-ai-for-nvidia'), $model['name']));
            ?>
        </h2>
        <p><code><?php echo esc_html($model['id']); ?></code> · <?php echo esc_html($model['developer']); ?> · <?php echo Ui::access_pill($model['status']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui. ?></p>
        <?php if ($model['notes'] !== '') : ?>
            <div class="notice notice-info inline"><p><?php echo esc_html($model['notes']); ?></p></div>
        <?php endif; ?>
        <p class="description"><?php esc_html_e('Leave a number field empty to leave that parameter out of the request, so NVIDIA\'s own default applies. Values are checked when you save; nothing is changed silently.', 'chandan-digital-ai-for-nvidia'); ?></p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cdnv-temperature"><?php esc_html_e('Temperature', 'chandan-digital-ai-for-nvidia'); ?></label></th>
                <td>
                    <input type="number" id="cdnv-temperature" name="temperature" min="0" max="2" step="0.01" class="small-text" value="<?php echo esc_attr($cdnv_value($cdnv_s['temperature'])); ?>">
                    <span class="description"><?php
                        /* translators: %s: default value. */
                        echo esc_html(sprintf(__('0 to 2. Default for this model: %s.', 'chandan-digital-ai-for-nvidia'), $cdnv_defaults['temperature'] === null ? __('not sent', 'chandan-digital-ai-for-nvidia') : (string) $cdnv_defaults['temperature']));
                    ?></span>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cdnv-max-tokens"><?php esc_html_e('Maximum output tokens', 'chandan-digital-ai-for-nvidia'); ?></label></th>
                <td>
                    <input type="number" id="cdnv-max-tokens" name="max_tokens" min="1" max="<?php echo esc_attr((string) $model['max_tokens_limit']); ?>" step="1" class="small-text cdnv-wide-number" value="<?php echo esc_attr($cdnv_value($cdnv_s['max_tokens'])); ?>">
                    <span class="description"><?php
                        /* translators: 1: default value, 2: limit. */
                        echo esc_html(sprintf(__('Default: %1$s. Accepted here: 1 to %2$s.', 'chandan-digital-ai-for-nvidia'), $cdnv_defaults['max_tokens'] === null ? __('not sent', 'chandan-digital-ai-for-nvidia') : number_format_i18n((int) $cdnv_defaults['max_tokens']), number_format_i18n((int) $model['max_tokens_limit'])));
                    ?></span>
                    <?php if ($model['always_reasons']) : ?>
                        <p class="description"><?php esc_html_e('Reasoning tokens count towards this limit. If replies stop early, raise it.', 'chandan-digital-ai-for-nvidia'); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cdnv-top-p"><?php esc_html_e('Top P', 'chandan-digital-ai-for-nvidia'); ?></label></th>
                <td>
                    <input type="number" id="cdnv-top-p" name="top_p" min="0.01" max="1" step="0.01" class="small-text" value="<?php echo esc_attr($cdnv_value($cdnv_s['top_p'])); ?>">
                    <span class="description"><?php esc_html_e('Optional. Empty means it is not sent.', 'chandan-digital-ai-for-nvidia'); ?></span>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cdnv-reasoning"><?php esc_html_e('Reasoning effort', 'chandan-digital-ai-for-nvidia'); ?></label></th>
                <td>
                    <select id="cdnv-reasoning" name="reasoning_effort">
                        <?php foreach (ModelRegistry::REASONING_OPTIONS as $cdnv_option) :
                            $cdnv_allowed = $cdnv_option === 'default' || in_array($cdnv_option, $model['reasoning_values'], true); ?>
                            <option value="<?php echo esc_attr($cdnv_option); ?>" <?php selected($cdnv_s['reasoning_effort'], $cdnv_option); ?> <?php disabled(!$cdnv_allowed); ?>>
                                <?php echo esc_html($cdnv_reasoning_labels[$cdnv_option] . ($cdnv_allowed ? '' : ' - ' . __('not supported by this model', 'chandan-digital-ai-for-nvidia'))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php
                        if ($model['reasoning_values']) {
                            /* translators: %s: list of values. */
                            echo esc_html(sprintf(__('Values this model accepts: %s. "Default" leaves the parameter out so the model uses its own default.', 'chandan-digital-ai-for-nvidia'), implode(', ', $model['reasoning_values'])));
                        } else {
                            esc_html_e('This model has no verified reasoning effort control, so the parameter is never sent.', 'chandan-digital-ai-for-nvidia');
                        }
                        ?>
                    </p>
                    <?php if ($model['id'] === ModelRegistry::KIMI_K3) : ?>
                        <p class="description"><?php esc_html_e('Moonshot AI documents low, high and max (default max) for Kimi K3. Medium is not documented, so it is disabled. Kimi K3 always reasons; this only changes how much.', 'chandan-digital-ai-for-nvidia'); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cdnv-seed"><?php esc_html_e('Seed', 'chandan-digital-ai-for-nvidia'); ?></label></th>
                <td>
                    <input type="number" id="cdnv-seed" name="seed" min="0" max="2147483647" step="1" class="small-text cdnv-wide-number" value="<?php echo esc_attr($cdnv_value($cdnv_s['seed'])); ?>">
                    <span class="description"><?php esc_html_e('Optional. The same seed can make replies more repeatable. Empty means it is not sent.', 'chandan-digital-ai-for-nvidia'); ?></span>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Streaming', 'chandan-digital-ai-for-nvidia'); ?></th>
                <td>
                    <label><input type="checkbox" name="stream" value="1" <?php checked(!empty($cdnv_s['stream'])); ?>> <?php esc_html_e('Stream replies in the Playground (text appears as it is generated)', 'chandan-digital-ai-for-nvidia'); ?></label>
                    <p class="description"><?php esc_html_e('If your host buffers output, turn this off; the Playground then waits for the full reply. Use API Diagnostics > Test streaming to check.', 'chandan-digital-ai-for-nvidia'); ?></p>
                </td>
            </tr>
        </table>
    </section>

    <?php if ($cdnv_vision) : ?>
        <section class="cdnv-card">
            <h2><?php esc_html_e('Image support', 'chandan-digital-ai-for-nvidia'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Image input', 'chandan-digital-ai-for-nvidia'); ?></th>
                    <td>
                        <label><input type="checkbox" name="images" value="1" <?php checked(!empty($cdnv_s['images'])); ?>> <?php esc_html_e('Allow images in the Playground', 'chandan-digital-ai-for-nvidia'); ?></label><br>
                        <label><input type="checkbox" name="image_urls" value="1" <?php checked(!empty($cdnv_s['image_urls'])); ?>> <?php esc_html_e('Allow public https:// image URLs (NVIDIA downloads them; this site does not)', 'chandan-digital-ai-for-nvidia'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Allowed formats', 'chandan-digital-ai-for-nvidia'); ?></th>
                    <td>
                        <?php foreach (array_keys(ModelRegistry::IMAGE_FORMATS) as $cdnv_format) : ?>
                            <label class="cdnv-inline-check"><input type="checkbox" name="image_formats[]" value="<?php echo esc_attr($cdnv_format); ?>" <?php checked(in_array($cdnv_format, (array) $cdnv_s['image_formats'], true)); ?>> <?php echo esc_html(strtoupper($cdnv_format)); ?></label>
                        <?php endforeach; ?>
                        <p class="description"><?php esc_html_e('NVIDIA documents JPEG, PNG and GIF for its hosted Kimi vision models. JPEG and PNG are on by default. Turn on WebP only after a successful test.', 'chandan-digital-ai-for-nvidia'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cdnv-image-mb"><?php esc_html_e('Maximum image size', 'chandan-digital-ai-for-nvidia'); ?></label></th>
                    <td><input type="number" id="cdnv-image-mb" name="image_max_mb" min="1" max="20" step="1" class="small-text" value="<?php echo esc_attr((string) $cdnv_s['image_max_mb']); ?>"> MB
                        <p class="description"><?php esc_html_e('Uploads are checked in memory and are never saved to this site. Large images make requests slower; NVIDIA returns HTTP 413 if a request is too large.', 'chandan-digital-ai-for-nvidia'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="cdnv-image-count"><?php esc_html_e('Images per request', 'chandan-digital-ai-for-nvidia'); ?></label></th>
                    <td><input type="number" id="cdnv-image-count" name="image_max_count" min="1" max="10" step="1" class="small-text" value="<?php echo esc_attr((string) $cdnv_s['image_max_count']); ?>">
                        <p class="description"><?php esc_html_e('NVIDIA\'s vision services default to 4 images per request. When a conversation holds more, the oldest images are left out and you are told.', 'chandan-digital-ai-for-nvidia'); ?></p></td>
                </tr>
            </table>
        </section>
    <?php endif; ?>

    <section class="cdnv-card">
        <h2><?php esc_html_e('WordPress AI Client requests', 'chandan-digital-ai-for-nvidia'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Use these settings for other plugins', 'chandan-digital-ai-for-nvidia'); ?></th>
                <td>
                    <label><input type="checkbox" name="ai_client_defaults" value="1" <?php checked(!empty($cdnv_s['ai_client_defaults'])); ?>> <?php esc_html_e('When another plugin uses this model through the WordPress AI Client and leaves a setting unset, use the value from this page. The request timeout above is also applied.', 'chandan-digital-ai-for-nvidia'); ?></label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Temperature from other plugins', 'chandan-digital-ai-for-nvidia'); ?></th>
                <td>
                    <label><input type="radio" name="temperature_policy" value="caller" <?php checked($cdnv_s['temperature_policy'], 'caller'); ?>> <?php esc_html_e('Send the temperature the other plugin asks for (recommended)', 'chandan-digital-ai-for-nvidia'); ?></label><br>
                    <label><input type="radio" name="temperature_policy" value="dashboard" <?php checked($cdnv_s['temperature_policy'], 'dashboard'); ?>> <?php esc_html_e('Always send the temperature set on this page (compatibility option for models with a fixed temperature)', 'chandan-digital-ai-for-nvidia'); ?></label>
                </td>
            </tr>
        </table>
    </section>

    <?php submit_button(__('Save model settings', 'chandan-digital-ai-for-nvidia')); ?>
</form>
