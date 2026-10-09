<?php

/**
 * Uninstall handler for Chandan Digital AI for NVIDIA.
 *
 * Runs only when the plugin is deleted from the Plugins screen, never during a manual ZIP update.
 *
 * Always removed: local logs, the cached catalogue, transients, scheduled events and the per-user
 * notice dismissal. The saved API key and settings are removed only when the administrator turned on
 * "Also delete the saved API key and all settings" under Privacy & Security, so deleting and
 * reinstalling keeps the configuration by default.
 *
 * Keys defined in wp-config.php and the WordPress Settings > Connectors key are never touched.
 *
 * @package ChandanDigital\NvidiaAi
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Removes this plugin's data from the current site.
 */
function cdnv_uninstall_site(): void
{
    global $wpdb;

    $settings = get_option('cdnv_settings', []);
    $deleteAll = is_array($settings) && !empty($settings['delete_data_on_uninstall']);

    delete_option('cdnv_logs');
    delete_option('cdnv_catalog');
    delete_option('cdnv_connection_status');
    wp_clear_scheduled_hook('cdnv_refresh_models');

    // Transients: cached catalogues, request locks and one-time admin notices.
    $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_cdnv\\_%' OR option_name LIKE '\\_transient\\_timeout\\_cdnv\\_%'"
    );

    if ($deleteAll) {
        delete_option('cdnv_settings');
        delete_option('cdnv_api_key');
        delete_option('cdnv_models');
        delete_option('cdnv_version');
        delete_option('cdnv_writing_style');
        delete_post_meta_by_key('_cdnv_focus_keyword');
        delete_metadata('user', 0, 'cdnv_playground_system_prompt', '', true);
    }
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $cdnvSiteId) {
        switch_to_blog((int) $cdnvSiteId);
        cdnv_uninstall_site();
        restore_current_blog();
    }
} else {
    cdnv_uninstall_site();
}

// Remove the per-user dismissal flag of the "AI Client missing" notice.
delete_metadata('user', 0, 'cdnv_notice_dismissed', '', true);
