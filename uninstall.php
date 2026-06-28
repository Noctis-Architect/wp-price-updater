<?php
/**
 * Fired when the plugin is deleted from WordPress.
 * Cleans up options, transients, cron jobs, and plugin data files.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Options
delete_option('dpu_options');

// Transients
delete_transient('dpu_current_dollar');
delete_transient('dpu_github_latest');

// Scheduled cron events
$hook = 'dpu_update_products_price_daily';
$crons = _get_cron_array();
if (is_array($crons)) {
    foreach ($crons as $timestamp => $tasks) {
        foreach ($tasks as $cron_hook => $args) {
            if (strpos($cron_hook, $hook) !== false) {
                wp_unschedule_event($timestamp, $cron_hook, reset($args)['args'] ?? []);
            }
        }
    }
}

// Deferred report hook
wp_clear_scheduled_hook('dpu_send_deferred_report');

// Log and debug files
$files = [
    WP_CONTENT_DIR . '/dpu-log.txt',
    WP_CONTENT_DIR . '/dpu-ajax-polluted.txt',
];

foreach ($files as $file) {
    if (file_exists($file)) {
        @unlink($file);
    }
}

// Snapshot directory
$snapshot_dir = WP_CONTENT_DIR . '/dpu-snapshots';
if (is_dir($snapshot_dir)) {
    $items = glob(trailingslashit($snapshot_dir) . '*');
    if (is_array($items)) {
        foreach ($items as $item) {
            if (is_file($item)) {
                @unlink($item);
            }
        }
    }
    @rmdir($snapshot_dir);
}
