<?php
/**
 * Kimi K3 Settings tab.
 *
 * @package ChandanDigital\NvidiaAi
 */

defined('ABSPATH') || exit;

use ChandanDigital\NvidiaAi\Admin\AdminPage;
use ChandanDigital\NvidiaAi\Admin\Ui;
use ChandanDigital\NvidiaAi\Support\ModelRegistry;

$cdnv_kimi = ModelRegistry::get(ModelRegistry::KIMI_K3);
if ($cdnv_kimi === null) {
    return;
}
?>
<section class="cdnv-card">
    <h2><?php esc_html_e('Kimi K3 by Moonshot AI, hosted by NVIDIA', 'chandan-digital-ai-for-nvidia'); ?></h2>
    <dl class="cdnv-dl">
        <dt><?php esc_html_e('Model ID', 'chandan-digital-ai-for-nvidia'); ?></dt>
        <dd><code><?php echo esc_html(ModelRegistry::KIMI_K3); ?></code></dd>
        <dt><?php esc_html_e('Capabilities', 'chandan-digital-ai-for-nvidia'); ?></dt>
        <dd><?php echo Ui::capability_badges($cdnv_kimi['capabilities']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui. ?></dd>
        <dt><?php esc_html_e('Context window', 'chandan-digital-ai-for-nvidia'); ?></dt>
        <dd><?php esc_html_e('1,048,576 tokens (input and output together), as published for Kimi K3.', 'chandan-digital-ai-for-nvidia'); ?></dd>
        <dt><?php esc_html_e('Access with your key', 'chandan-digital-ai-for-nvidia'); ?></dt>
        <dd data-cdnv-access="<?php echo esc_attr(ModelRegistry::KIMI_K3); ?>"><?php echo Ui::access_pill($cdnv_kimi['status']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui. ?></dd>
        <dt><?php esc_html_e('Status in this plugin', 'chandan-digital-ai-for-nvidia'); ?></dt>
        <dd><?php echo esc_html($cdnv_kimi['enabled'] ? __('Enabled', 'chandan-digital-ai-for-nvidia') : __('Disabled on the AI Models tab', 'chandan-digital-ai-for-nvidia')); ?></dd>
    </dl>
    <p>
        <button type="button" class="button button-primary" data-cdnv-action="verify-model" data-model="<?php echo esc_attr(ModelRegistry::KIMI_K3); ?>"><?php esc_html_e('Check Kimi K3 access', 'chandan-digital-ai-for-nvidia'); ?></button>
        <a class="button" href="<?php echo esc_url(AdminPage::url('playground')); ?>"><?php esc_html_e('Try it in the Playground', 'chandan-digital-ai-for-nvidia'); ?></a>
    </p>
    <details class="cdnv-details">
        <summary><?php esc_html_e('What was verified, and what still needs a live test', 'chandan-digital-ai-for-nvidia'); ?></summary>
        <ul class="ul-disc">
            <li><?php esc_html_e('The request format follows NVIDIA\'s published Kimi K3 example: POST to /v1/chat/completions with model, messages (text and image_url parts), max_tokens, temperature, reasoning_effort, seed and stream.', 'chandan-digital-ai-for-nvidia'); ?></li>
            <li><?php esc_html_e('Reasoning effort values (low, high, max) and the need to send earlier reasoning back in multi-turn chats come from Moonshot AI\'s Kimi K3 documentation.', 'chandan-digital-ai-for-nvidia'); ?></li>
            <li><?php esc_html_e('Moonshot AI fixes temperature at 1.0 and top P at 0.95 for Kimi K3, and the plugin sends those values by default. NVIDIA\'s example also uses temperature 1. Other values may be rejected; the plugin shows NVIDIA\'s reason if so.', 'chandan-digital-ai-for-nvidia'); ?></li>
            <li><?php esc_html_e('NVIDIA\'s hosted Kimi K3 has a known problem where some replies come back as garbled text (mixed languages and markers such as <|close|>) or endless "!". The plugin spots this, throws the reply away, asks once more, and shows a clear message if the second reply is also broken. Nothing garbled is shown or passed to other plugins.', 'chandan-digital-ai-for-nvidia'); ?></li>
            <li><?php esc_html_e('NVIDIA\'s example sends a public image URL. Exact image size limits for this model on NVIDIA are not published; the plugin uses conservative defaults you can change below.', 'chandan-digital-ai-for-nvidia'); ?></li>
            <li><?php esc_html_e('Use "Check Kimi K3 access" and the Playground to confirm everything with your own key.', 'chandan-digital-ai-for-nvidia'); ?></li>
        </ul>
    </details>
</section>
<?php
AdminPage::view('model-settings', ['model' => $cdnv_kimi, 'returnTab' => 'kimi']);
