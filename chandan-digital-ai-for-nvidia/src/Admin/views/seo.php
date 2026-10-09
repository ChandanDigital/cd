<?php
/**
 * SEO Assistant tab.
 *
 * @package ChandanDigital\NvidiaAi
 */

defined('ABSPATH') || exit;

use ChandanDigital\NvidiaAi\Admin\AdminPage;
use ChandanDigital\NvidiaAi\Seo\SeoSkills;
use ChandanDigital\NvidiaAi\Support\ModelRegistry;
use ChandanDigital\NvidiaAi\Support\Settings;

$cdnv_settings = Settings::all();
$cdnv_image_models = array_filter(ModelRegistry::all(), static function (array $model): bool {
    return $model['kind'] === 'image' && $model['enabled'];
});
?>
<section class="cdnv-card">
    <h2><?php esc_html_e('SEO Assistant in posts and pages', 'chandan-digital-ai-for-nvidia'); ?></h2>
    <p><?php esc_html_e('Open any post or page and you will find the "SEO Assistant (NVIDIA AI)" box under the editor. It works with this plugin\'s own NVIDIA connection, so it does not depend on the WordPress AI plugin or its approvals.', 'chandan-digital-ai-for-nvidia'); ?></p>
    <table class="widefat striped">
        <thead><tr><th scope="col"><?php esc_html_e('Button', 'chandan-digital-ai-for-nvidia'); ?></th><th scope="col"><?php esc_html_e('What it does', 'chandan-digital-ai-for-nvidia'); ?></th></tr></thead>
        <tbody>
            <tr><td><?php esc_html_e('SEO titles', 'chandan-digital-ai-for-nvidia'); ?></td><td><?php esc_html_e('Five title options of 50 to 60 characters with the focus keyword near the start. Use one as the post title or as the SEO title.', 'chandan-digital-ai-for-nvidia'); ?></td></tr>
            <tr><td><?php esc_html_e('Meta description', 'chandan-digital-ai-for-nvidia'); ?></td><td><?php esc_html_e('Three options of 150 to 160 characters, ready to use or copy.', 'chandan-digital-ai-for-nvidia'); ?></td></tr>
            <tr><td><?php esc_html_e('SEO check', 'chandan-digital-ai-for-nvidia'); ?></td><td><?php esc_html_e('A score with the most important fixes first: keyword placement, headings, E-E-A-T, readability, links and AI-search readiness.', 'chandan-digital-ai-for-nvidia'); ?></td></tr>
            <tr><td><?php esc_html_e('Improve content', 'chandan-digital-ai-for-nvidia'); ?></td><td><?php esc_html_e('A better version of the content that keeps your facts, links and images. Preview it first, then replace or copy.', 'chandan-digital-ai-for-nvidia'); ?></td></tr>
            <tr><td><?php esc_html_e('Internal links', 'chandan-digital-ai-for-nvidia'); ?></td><td><?php esc_html_e('Up to 8 links to your real published posts and pages, using phrases already in your text. Invented or already-linked pages are removed automatically.', 'chandan-digital-ai-for-nvidia'); ?></td></tr>
            <tr><td><?php esc_html_e('Featured image', 'chandan-digital-ai-for-nvidia'); ?></td><td><?php esc_html_e('Writes an image brief, creates the image with FLUX, saves it to the Media Library with SEO alt text and file name, and lets you set it as the featured image.', 'chandan-digital-ai-for-nvidia'); ?></td></tr>
        </tbody>
    </table>
    <p class="description"><?php esc_html_e('WordPress 6.6 and newer show boxes like this one in a "Meta Boxes" pane at the bottom of the block editor, and the pane starts closed. Click that bar to open it, or use the "Open SEO Assistant" button in the Post sidebar.', 'chandan-digital-ai-for-nvidia'); ?></p>
</section>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="cdnv_save_seo">
    <?php wp_nonce_field('cdnv_save_seo'); ?>
    <section class="cdnv-card">
        <h2><?php esc_html_e('Settings', 'chandan-digital-ai-for-nvidia'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('SEO Assistant', 'chandan-digital-ai-for-nvidia'); ?></th>
                <td><label><input type="checkbox" name="seo_assistant" value="1" <?php checked((bool) $cdnv_settings['seo_assistant']); ?>> <?php esc_html_e('Show the SEO Assistant box in the post and page editor', 'chandan-digital-ai-for-nvidia'); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Who can use it', 'chandan-digital-ai-for-nvidia'); ?></th>
                <td>
                    <label><input type="radio" name="seo_assistant_access" value="administrator" <?php checked($cdnv_settings['seo_assistant_access'], 'administrator'); ?>> <?php esc_html_e('Administrators', 'chandan-digital-ai-for-nvidia'); ?></label><br>
                    <label><input type="radio" name="seo_assistant_access" value="editor" <?php checked($cdnv_settings['seo_assistant_access'], 'editor'); ?>> <?php esc_html_e('Administrators and Editors', 'chandan-digital-ai-for-nvidia'); ?></label><br>
                    <label><input type="radio" name="seo_assistant_access" value="author" <?php checked($cdnv_settings['seo_assistant_access'], 'author'); ?>> <?php esc_html_e('Administrators, Editors and Authors', 'chandan-digital-ai-for-nvidia'); ?></label>
                    <p class="description"><?php esc_html_e('People can only use it on posts they are allowed to edit. Creating images also needs permission to upload files.', 'chandan-digital-ai-for-nvidia'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cdnv-seo-model-setting"><?php esc_html_e('Default model', 'chandan-digital-ai-for-nvidia'); ?></label></th>
                <td>
                    <select id="cdnv-seo-model-setting" name="seo_model">
                        <option value=""><?php esc_html_e('Same as the dashboard default model', 'chandan-digital-ai-for-nvidia'); ?></option>
                        <?php foreach (ModelRegistry::enabled_chat_models() as $cdnv_id => $cdnv_model) : ?>
                            <option value="<?php echo esc_attr($cdnv_id); ?>" <?php selected($cdnv_settings['seo_model'], $cdnv_id); ?>><?php echo esc_html($cdnv_model['name'] . ' (' . $cdnv_id . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('Can be changed for each request in the editor box. Fast models such as GLM-5.3 Flash, DeepSeek V4.1 Flash or Llama 3.3 70B suit titles and descriptions; Kimi K3 thinks longer and suits content work. The assistant raises maximum output tokens to at least 2,048 (8,192 for "Improve content") for these tasks.', 'chandan-digital-ai-for-nvidia'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cdnv-seo-image-model"><?php esc_html_e('Image model', 'chandan-digital-ai-for-nvidia'); ?></label></th>
                <td>
                    <select id="cdnv-seo-image-model" name="seo_image_model">
                        <?php foreach ($cdnv_image_models as $cdnv_id => $cdnv_model) : ?>
                            <option value="<?php echo esc_attr($cdnv_id); ?>" <?php selected($cdnv_settings['seo_image_model'], $cdnv_id); ?>><?php echo esc_html($cdnv_model['name'] . ' (' . $cdnv_id . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('FLUX.1 Schnell is the fastest. Images are created through NVIDIA\'s ai.api.nvidia.com service with your API key.', 'chandan-digital-ai-for-nvidia'); ?></p>
                </td>
            </tr>
        </table>
    </section>
    <?php submit_button(__('Save SEO Assistant settings', 'chandan-digital-ai-for-nvidia')); ?>
</form>

<section class="cdnv-card">
    <h2><?php esc_html_e('SEO skills used', 'chandan-digital-ai-for-nvidia'); ?></h2>
    <p>
        <?php esc_html_e('The SEO rules the model follows are adapted from claude-seo by AgriciDaniel (MIT License). The original skills are made for Claude Code and also run scripts and paid SEO data tools; only the on-page writing and checking rules that work inside WordPress are used here. They are also available in the AI Playground.', 'chandan-digital-ai-for-nvidia'); ?>
        <a href="<?php echo esc_url(SeoSkills::SOURCE_URL); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('claude-seo on GitHub', 'chandan-digital-ai-for-nvidia'); ?></a>
    </p>
    <?php foreach (SeoSkills::all() as $cdnv_skill) : ?>
        <details class="cdnv-details">
            <summary><?php echo esc_html($cdnv_skill['label']); ?> <span class="description"><?php echo esc_html($cdnv_skill['description']); ?></span></summary>
            <pre class="cdnv-code cdnv-code--wrap"><?php echo esc_html($cdnv_skill['instruction']); ?></pre>
        </details>
    <?php endforeach; ?>
</section>

<p><a href="<?php echo esc_url(AdminPage::url('diagnostics')); ?>#cdnv-editor-ai"><?php esc_html_e('AI buttons from the WordPress AI plugin showing a 403 error? See the editor AI check on the Diagnostics tab.', 'chandan-digital-ai-for-nvidia'); ?></a></p>
