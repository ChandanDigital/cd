<?php
/**
 * Brand header.
 *
 * @package ChandanDigital\NvidiaAi
 *
 * @var string $version Plugin version.
 */

defined('ABSPATH') || exit;
?>
<header class="cdnv-brand">
    <div class="cdnv-brand__text">
        <h1 class="cdnv-brand__title"><?php esc_html_e('Chandan Digital AI for NVIDIA', 'chandan-digital-ai-for-nvidia'); ?></h1>
        <p class="cdnv-brand__meta">
            <?php esc_html_e('Developer:', 'chandan-digital-ai-for-nvidia'); ?> <strong>Chandan Digital</strong>
            <span aria-hidden="true">·</span>
            <?php esc_html_e('Website:', 'chandan-digital-ai-for-nvidia'); ?>
            <a href="https://chandandigital.com/" target="_blank" rel="noopener noreferrer">chandandigital.com</a>
        </p>
    </div>
    <span class="cdnv-pill cdnv-pill--version"><?php
        /* translators: %s: plugin version. */
        echo esc_html(sprintf(__('Version %s', 'chandan-digital-ai-for-nvidia'), $version));
    ?></span>
</header>
