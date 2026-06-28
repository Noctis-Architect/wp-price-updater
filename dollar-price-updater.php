<?php
/*
Plugin Name: Price Updater PRO
Description: به‌روز‌رسانی هوشمند قیمت محصولات ووکامرس با سیستم رند کردن پیشرفته، اعلان‌های تلگرام، snapshot روزانه و قابلیت rollback
Version: 3.0.1
Author: mr-noctis
Text Domain: dollar-price-updater
Domain Path: /languages
*/

if (! defined('ABSPATH')) exit;

define('DPU_VERSION',    '3.0.1');
define('DPU_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DPU_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once DPU_PLUGIN_DIR . 'includes/class-options.php';
require_once DPU_PLUGIN_DIR . 'includes/class-logger.php';
require_once DPU_PLUGIN_DIR . 'includes/class-api.php';
require_once DPU_PLUGIN_DIR . 'includes/class-telegram.php';
require_once DPU_PLUGIN_DIR . 'includes/class-updater.php';
require_once DPU_PLUGIN_DIR . 'includes/class-rollback.php';
require_once DPU_PLUGIN_DIR . 'includes/class-scheduler.php';
require_once DPU_PLUGIN_DIR . 'admin/class-admin.php';

// Debug: register shutdown handler to capture fatal errors that may cause HTTP 500
register_shutdown_function(function() {
    $err = error_get_last();
    // Only handle fatal error types — ignore warnings, notices, deprecations, user notices
    if ($err && is_array($err) && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $msg = sprintf("Fatal error: type=%d message=%s file=%s line=%d", $err['type'] ?? 0, $err['message'] ?? '', $err['file'] ?? '', $err['line'] ?? 0);
        DPU_Logger::log_critical($msg);
    }
});

// ---------------------- Hooks ----------------------

register_activation_hook(__FILE__, function() {
    if (!get_option('dpu_options')) {
        update_option('dpu_options', DPU_Options::defaults());
    }
    DPU_Scheduler::schedule_all();
});

register_deactivation_hook(__FILE__, function() {
    DPU_Scheduler::clear_all();
});

add_action('dpu_send_deferred_report', function(string $report_id) {
    DPU_Updater::send_deferred_report($report_id);
}, 10, 1);

// نوار ادمین - قیمت دلار
add_action('admin_bar_menu', function($bar) {
    $price = DPU_API::get_dollar_price();
    $title = $price !== false
        ? '💵 دلار: ' . number_format($price) . ' ت'
        : '💵 دلار: نامشخص';
    $bar->add_node([
        'id'    => 'dpu_dollar_price',
        'title' => $title,
        'href'  => admin_url('admin.php?page=dpu-settings'),
    ]);
}, 100);

// ذخیره قیمت پایه هنگام ثبت محصول
add_action('save_post_product', function($post_id, $post, $update) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (get_post_meta($post_id, '_dpu_base_price', true)) return;

    $product = wc_get_product($post_id);
    if (!$product) return;

    $base = $product->get_regular_price();
    if ($base === '' || floatval($base) <= 0) return;

    update_post_meta($post_id, '_dpu_base_price', floatval($base));

    $dollar = DPU_API::get_dollar_price();
    if ($dollar !== false) {
        update_post_meta($post_id, '_dpu_upload_dollar', $dollar);
        DPU_Logger::log("Saved base={$base}, dollar={$dollar} for product {$post_id}");
    }
}, 10, 3);
