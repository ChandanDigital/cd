<?php
/**
 * AI Playground tab. The interface is driven by assets/js/playground.js.
 *
 * @package ChandanDigital\NvidiaAi
 */

defined('ABSPATH') || exit;
?>
<div class="cdnv-playground" id="cdnv-playground">
    <section class="cdnv-card cdnv-playground__controls">
        <div class="cdnv-field">
            <label for="cdnv-pg-model"><strong><?php esc_html_e('Model', 'chandan-digital-ai-for-nvidia'); ?></strong></label>
            <select id="cdnv-pg-model"></select>
            <p class="description" id="cdnv-pg-model-summary"></p>
        </div>
        <div class="cdnv-field cdnv-field--inline">
            <label><input type="checkbox" id="cdnv-pg-stream"> <?php esc_html_e('Stream the reply', 'chandan-digital-ai-for-nvidia'); ?></label>
            <label><input type="checkbox" id="cdnv-pg-show-reasoning" checked> <?php esc_html_e('Show reasoning', 'chandan-digital-ai-for-nvidia'); ?></label>
            <label><input type="checkbox" id="cdnv-pg-policy"> <?php esc_html_e('Apply Indian English editorial policy', 'chandan-digital-ai-for-nvidia'); ?></label>
        </div>
        <details class="cdnv-details">
            <summary><?php esc_html_e('System prompt (optional)', 'chandan-digital-ai-for-nvidia'); ?></summary>
            <label for="cdnv-pg-system" class="screen-reader-text"><?php esc_html_e('System prompt', 'chandan-digital-ai-for-nvidia'); ?></label>
            <textarea id="cdnv-pg-system" rows="3" class="large-text" maxlength="20000" placeholder="<?php esc_attr_e('For example: You are a helpful assistant for a Kolkata digital marketing agency.', 'chandan-digital-ai-for-nvidia'); ?>"></textarea>
        </details>
    </section>

    <section class="cdnv-card cdnv-chat" aria-label="<?php esc_attr_e('Conversation', 'chandan-digital-ai-for-nvidia'); ?>">
        <div id="cdnv-pg-log" class="cdnv-chat__log" aria-live="polite">
            <p class="cdnv-chat__empty"><?php esc_html_e('Ask a question, paste some code, or add an image to start.', 'chandan-digital-ai-for-nvidia'); ?></p>
        </div>

        <form id="cdnv-pg-form" class="cdnv-chat__composer">
            <label for="cdnv-pg-prompt" class="screen-reader-text"><?php esc_html_e('Prompt', 'chandan-digital-ai-for-nvidia'); ?></label>
            <textarea id="cdnv-pg-prompt" rows="4" class="large-text" placeholder="<?php esc_attr_e('Type your prompt. Press Ctrl+Enter to send.', 'chandan-digital-ai-for-nvidia'); ?>"></textarea>

            <div id="cdnv-pg-images" class="cdnv-chat__images" hidden>
                <div class="cdnv-field--inline">
                    <label class="button" for="cdnv-pg-file" id="cdnv-pg-file-label"><?php esc_html_e('Upload image', 'chandan-digital-ai-for-nvidia'); ?></label>
                    <input type="file" id="cdnv-pg-file" class="screen-reader-text" accept="image/jpeg,image/png">
                    <span id="cdnv-pg-url-wrap">
                        <label for="cdnv-pg-url" class="screen-reader-text"><?php esc_html_e('Image URL', 'chandan-digital-ai-for-nvidia'); ?></label>
                        <input type="url" id="cdnv-pg-url" class="regular-text" placeholder="https://example.com/photo.jpg">
                        <button type="button" class="button" id="cdnv-pg-url-add"><?php esc_html_e('Add URL', 'chandan-digital-ai-for-nvidia'); ?></button>
                    </span>
                </div>
                <ul id="cdnv-pg-pending" class="cdnv-chat__pending"></ul>
                <p class="description" id="cdnv-pg-image-help"></p>
            </div>

            <div class="cdnv-chat__actions">
                <button type="submit" class="button button-primary" id="cdnv-pg-send"><?php esc_html_e('Generate response', 'chandan-digital-ai-for-nvidia'); ?></button>
                <button type="button" class="button" id="cdnv-pg-stop" disabled><?php esc_html_e('Stop', 'chandan-digital-ai-for-nvidia'); ?></button>
                <button type="button" class="button" id="cdnv-pg-clear"><?php esc_html_e('Clear conversation', 'chandan-digital-ai-for-nvidia'); ?></button>
                <span id="cdnv-pg-status" class="cdnv-chat__status" role="status"></span>
            </div>
        </form>
    </section>
    <p class="description"><?php esc_html_e('Prompts and images are sent to NVIDIA to generate the reply. The conversation is kept only in this browser tab and is not saved on this site.', 'chandan-digital-ai-for-nvidia'); ?></p>
</div>
