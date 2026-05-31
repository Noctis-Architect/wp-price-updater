<?php
if (! defined('ABSPATH')) exit;

class DPU_Updater {

    /**
     * اجرای به‌روزرسانی قیمت
     *
     * @param string $mode  'auto' | 'manual'
     * @param array  $extra ['percent' => float, 'categories' => int[], 'direction' => 'increase'|'decrease']
     *                      در حالت 'manual_adjust' تنها درصد و جهت اعمال می‌شود (بدون منطق دلار)
     */
    /**
     * اجرای کامل (کرون و POST معمولی)
     */
    public static function run(string $mode = 'auto', array $extra = []): array {
        $ctx = self::init_run_context($mode, $extra);
        if (isset($ctx['error'])) {
            return $ctx;
        }

        $accumulated = self::new_accumulator();
        $page        = 1;

        while (true) {
            $batch = self::process_batch_page($ctx, $page, $accumulated);
            if ($batch['empty']) {
                break;
            }
            $page++;
        }

        self::finalize_run($ctx, $accumulated);

        return [
            'changed'   => count($accumulated['changed']),
            'unchanged' => $accumulated['unchanged'],
            'dollar'    => $ctx['current_dollar'],
            'products'  => $accumulated['changed'],
        ];
    }

    /**
     * پردازش یک batch برای AJAX — از timeout جلوگیری می‌کند
     */
    public static function run_batch(string $mode, array $extra, int $page, string $run_id = ''): array {
        @set_time_limit(120);
        @ini_set('max_execution_time', '120');

        if ($page <= 1) {
            $ctx = self::init_run_context($mode, $extra, false);
            if (isset($ctx['error'])) {
                return $ctx;
            }
            $run_id      = 'dpu_' . wp_generate_password(16, false);
            $accumulated = self::new_accumulator();
        } else {
            $saved = get_transient($run_id);
            if (!$saved || !is_array($saved)) {
                return [
                    'error'   => 'run_expired',
                    'message' => 'نشست پردازش منقضی شد — لطفاً دوباره تلاش کنید.',
                ];
            }
            $ctx         = $saved['ctx'];
            $accumulated = $saved['accumulated'];
        }

        $batch = self::process_batch_page($ctx, $page, $accumulated);

        if ($batch['empty']) {
            delete_transient($run_id);
            self::finalize_run($ctx, $accumulated, true);

            return [
                'done'      => true,
                'run_id'    => $run_id,
                'changed'   => count($accumulated['changed']),
                'unchanged' => $accumulated['unchanged'],
            ];
        }

        set_transient($run_id, [
            'ctx'         => $ctx,
            'accumulated' => $accumulated,
        ], HOUR_IN_SECONDS);

        return [
            'done'          => false,
            'run_id'        => $run_id,
            'page'          => $page,
            'next_page'     => $page + 1,
            'batch_changed' => $batch['batch_changed'],
            'total_changed' => count($accumulated['changed']),
            'processed'     => $batch['processed'],
        ];
    }

    private static function new_accumulator(): array {
        return ['changed' => [], 'unchanged' => 0];
    }

    private static function init_run_context(string $mode, array $extra, bool $with_snapshot = true): array {
        $opts      = DPU_Options::get();
        $is_manual = ($mode === 'manual');
        $is_adjust = ($mode === 'manual_adjust');

        $current_dollar = 0.0;
        if (!$is_adjust) {
            $current_dollar = DPU_API::get_dollar_price();
            if ($current_dollar === false) {
                DPU_Logger::log('Abort: could not fetch dollar.');
                DPU_Telegram::send("❌ DPU Alert\nقیمت دلار دریافت نشد.");
                return ['error' => 'dollar_fetch_failed'];
            }
            if ($with_snapshot) {
                DPU_Logger::take_snapshot($current_dollar);
            }
        }

        $manual_percent = 0;
        if ($is_manual) {
            $manual_percent = max(0, floatval($opts['manual_percent']));
        }
        if ($is_adjust && isset($extra['percent'])) {
            $manual_percent = floatval($extra['percent']);
        }

        return [
            'mode'           => $mode,
            'current_dollar' => $current_dollar,
            'ratio'          => floatval($opts['ratio']),
            'is_manual'      => $is_manual,
            'is_adjust'      => $is_adjust,
            'manual_percent' => $manual_percent,
            'direction'      => $extra['direction'] ?? 'increase',
            'cat_ids'        => self::resolve_categories($opts, $extra),
            'batch_size'     => max(20, intval($opts['batch_size'] ?? 50)),
        ];
    }

    private static function process_batch_page(array $ctx, int $page, array &$accumulated): array {
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        wp_defer_term_counting(true);
        wp_suspend_cache_addition(true);

        $opts = DPU_Options::get();
        $page_products = wc_get_products([
            'limit'  => $ctx['batch_size'],
            'page'   => $page,
            'status' => 'publish',
            'return' => 'objects',
        ]);

        if (empty($page_products)) {
            return ['empty' => true, 'batch_changed' => 0, 'processed' => 0];
        }

        if (!empty($ctx['cat_ids'])) {
            $page_products = array_filter($page_products, function($p) use ($ctx) {
                $terms = wp_get_post_terms($p->get_id(), 'product_cat', ['fields' => 'ids']);
                return (bool) array_intersect($terms, $ctx['cat_ids']);
            });
        }

        $batch_changed = 0;
        $processed     = 0;

        foreach ($page_products as $product) {
            $processed++;
            $result = self::process_product(
                $product,
                $ctx['current_dollar'],
                $ctx['ratio'],
                $ctx['manual_percent'],
                $ctx['direction'],
                $ctx['is_adjust'],
                $ctx['is_manual'],
                $opts
            );

            if ($result === null) {
                $accumulated['unchanged']++;
                continue;
            }

            $accumulated['changed'][] = $result;
            $batch_changed++;
            if (function_exists('wc_free_memory')) {
                wc_free_memory();
            }
        }

        wp_suspend_cache_addition(false);
        wp_defer_term_counting(false);

        return [
            'empty'         => false,
            'batch_changed' => $batch_changed,
            'processed'     => $processed,
        ];
    }

    private static function finalize_run(array $ctx, array $accumulated, bool $defer_report = false): void {
        if ($defer_report) {
            self::queue_report($ctx, $accumulated);
            return;
        }

        self::send_report(
            $accumulated['changed'],
            $accumulated['unchanged'],
            $ctx['current_dollar'],
            $ctx['mode'],
            $ctx['manual_percent'],
            $ctx['direction']
        );
    }

    /**
     * گزارش تلگرام را بعد از پاسخ AJAX در پس‌زمینه ارسال می‌کند
     */
    private static function queue_report(array $ctx, array $accumulated): void {
        $report_id = 'dpu_rpt_' . wp_generate_password(12, false);
        set_transient($report_id, [
            'changed'        => $accumulated['changed'],
            'unchanged'      => $accumulated['unchanged'],
            'current_dollar' => $ctx['current_dollar'],
            'mode'           => $ctx['mode'],
            'manual_percent' => $ctx['manual_percent'],
            'direction'      => $ctx['direction'],
        ], DAY_IN_SECONDS);

        if (!wp_next_scheduled('dpu_send_deferred_report', [$report_id])) {
            wp_schedule_single_event(time() + 5, 'dpu_send_deferred_report', [$report_id]);
        }

        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    public static function send_deferred_report(string $report_id): void {
        $data = get_transient($report_id);
        delete_transient($report_id);
        if (!$data || !is_array($data)) {
            return;
        }

        self::send_report(
            $data['changed'] ?? [],
            intval($data['unchanged'] ?? 0),
            floatval($data['current_dollar'] ?? 0),
            $data['mode'] ?? 'auto',
            floatval($data['manual_percent'] ?? 0),
            $data['direction'] ?? 'increase'
        );
    }

    /**
     * نوشتن مستقیم postmeta بدون fire شدن hookهای وردپرس/ووکامرس
     */
    private static function set_product_meta(int $pid, string $key, $value): void {
        global $wpdb;

        $table    = $wpdb->postmeta;
        $meta_val = is_scalar($value) ? (string) $value : wp_json_encode($value);

        $updated = $wpdb->update(
            $table,
            ['meta_value' => $meta_val],
            ['post_id' => $pid, 'meta_key' => $key]
        );

        if ($updated === 0) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_id FROM {$table} WHERE post_id = %d AND meta_key = %s",
                $pid,
                $key
            ));
            if (empty($exists)) {
                $wpdb->insert($table, [
                    'post_id'    => $pid,
                    'meta_key'   => $key,
                    'meta_value' => $meta_val,
                ]);
            }
        }
    }

    // -------------------- پردازش یک محصول --------------------

    private static function process_product(
        WC_Product $product,
        float $current_dollar,
        float $ratio,
        float $manual_percent,
        string $direction,
        bool $is_adjust,
        bool $is_manual,
        array $opts
    ): ?array {

        $pid                 = $product->get_id();
        $current_saved_price = floatval($product->get_regular_price());

        if ($current_saved_price <= 0) return null; // بدون قیمت، رد کن

        // ---- حالت adjust محض: فقط درصد + جهت ----
        if ($is_adjust) {
            if ($manual_percent <= 0) return null;

            $delta = $current_saved_price * ($manual_percent / 100);
            $new_price = $direction === 'decrease'
                ? $current_saved_price - $delta
                : $current_saved_price + $delta;

            $new_price = max(0, dpu_round_price($new_price, $opts));

            if (abs($new_price - $current_saved_price) < 1) return null;

            // Use direct DB updates to avoid triggering theme/plugin filters
            global $wpdb;
            $pm_table = $wpdb->postmeta;
            $meta_val = (string) $new_price;

            $updated1 = $wpdb->update(
                $pm_table,
                ['meta_value' => $meta_val],
                ['post_id' => $pid, 'meta_key' => '_regular_price']
            );
            $updated2 = $wpdb->update(
                $pm_table,
                ['meta_value' => $meta_val],
                ['post_id' => $pid, 'meta_key' => '_price']
            );

            // If meta rows did not exist, insert them
            if ($updated1 === 0) {
                $exists = $wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$pm_table} WHERE post_id = %d AND meta_key = %s", $pid, '_regular_price'));
                if (empty($exists)) {
                    $wpdb->insert($pm_table, ['post_id' => $pid, 'meta_key' => '_regular_price', 'meta_value' => $meta_val]);
                }
            }
            if ($updated2 === 0) {
                $exists = $wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$pm_table} WHERE post_id = %d AND meta_key = %s", $pid, '_price'));
                if (empty($exists)) {
                    $wpdb->insert($pm_table, ['post_id' => $pid, 'meta_key' => '_price', 'meta_value' => $meta_val]);
                }
            }

            // Clear caches so WooCommerce recognizes the new prices
            clean_post_cache($pid);
            self::set_product_meta($pid, '_dpu_last_update_time', current_time('mysql'));

            DPU_Logger::log("Adjust product {$pid}: {$current_saved_price} -> {$new_price} ({$direction} {$manual_percent}%)");

            return [
                'id'    => $pid,
                'title' => $product->get_name(),
                'old'   => $current_saved_price,
                'new'   => $new_price,
                'diff'  => $new_price - $current_saved_price,
            ];
        }

        // ---- حالت auto / manual: منطق دلار ----
        $base_price   = get_post_meta($pid, '_dpu_base_price', true);
        $upload_dollar = get_post_meta($pid, '_dpu_upload_dollar', true);

        if (!$base_price || !$upload_dollar) {
            // فقط متا بساز، قیمت تغییر نده
            self::set_product_meta($pid, '_dpu_base_price', $current_saved_price);
            self::set_product_meta($pid, '_dpu_upload_dollar', $current_dollar);
            DPU_Logger::log("Initialized meta for product {$pid}");
            return null;
        }

        $base_price    = floatval($base_price);
        $upload_dollar = floatval($upload_dollar);

        $dollar_change_percent = ($current_dollar - $upload_dollar) / $upload_dollar;
        if ($dollar_change_percent < 0) $dollar_change_percent = 0;

        $final_percent  = $dollar_change_percent * $ratio;
        $price_increase = $base_price * $final_percent;

        if ($is_manual && $manual_percent > 0) {
            $price_increase += $base_price * ($manual_percent / 100);
        }

        $new_price = dpu_round_price($base_price + $price_increase, $opts);

        // هرگز کاهش نده (در حالت auto/manual)
        if ($new_price < $current_saved_price) {
            $new_price = $current_saved_price;
        }

        if ($current_saved_price == $new_price) return null;

        // Use direct DB updates to avoid triggering theme/plugin filters
        global $wpdb;
        $pm_table = $wpdb->postmeta;
        $meta_val = (string) $new_price;

        $updated1 = $wpdb->update(
            $pm_table,
            ['meta_value' => $meta_val],
            ['post_id' => $pid, 'meta_key' => '_regular_price']
        );
        $updated2 = $wpdb->update(
            $pm_table,
            ['meta_value' => $meta_val],
            ['post_id' => $pid, 'meta_key' => '_price']
        );

        // If meta rows did not exist, insert them
        if ($updated1 === 0) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$pm_table} WHERE post_id = %d AND meta_key = %s", $pid, '_regular_price'));
            if (empty($exists)) {
                $wpdb->insert($pm_table, ['post_id' => $pid, 'meta_key' => '_regular_price', 'meta_value' => $meta_val]);
            }
        }
        if ($updated2 === 0) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$pm_table} WHERE post_id = %d AND meta_key = %s", $pid, '_price'));
            if (empty($exists)) {
                $wpdb->insert($pm_table, ['post_id' => $pid, 'meta_key' => '_price', 'meta_value' => $meta_val]);
            }
        }

        // Clear caches so WooCommerce recognizes the new prices
        clean_post_cache($pid);
        self::set_product_meta($pid, '_dpu_last_update_time', current_time('mysql'));

        DPU_Logger::log("Updated product {$pid}: {$current_saved_price} -> {$new_price}");

        return [
            'id'    => $pid,
            'title' => $product->get_name(),
            'old'   => $current_saved_price,
            'new'   => $new_price,
            'diff'  => $new_price - $current_saved_price,
        ];
    }

    // -------------------- دسته‌بندی‌ها --------------------

    private static function resolve_categories(array $opts, array $extra): array {
        // اولویت با extra (از پنل adjust)
        if (!empty($extra['categories'])) {
            return array_filter(array_map('intval', (array) $extra['categories']));
        }

        if (!empty($opts['limit_categories'])) {
            return array_filter(array_map('intval', explode(',', $opts['limit_categories'])));
        }

        return [];
    }

    // -------------------- گزارش تلگرام --------------------

    private static function send_report(
        array $changed,
        int $unchanged,
        float $current_dollar,
        string $mode,
        float $manual_percent,
        string $direction
    ): void {

        $total_diff = array_sum(array_column($changed, 'diff'));
        $mode_label = match($mode) {
            'manual'        => 'اجرای دستی ✅',
            'manual_adjust' => 'تنظیم دستی ' . ($direction === 'decrease' ? '🔻' : '🔺'),
            default         => 'اتومات 🔁',
        };

        $lines   = [];
        $lines[] = "🗓 تاریخ: " . date_i18n('Y/m/d');
        $lines[] = "⏰ ساعت: " . date_i18n('H:i');
        $lines[] = "💰 دلار: <b>" . number_format($current_dollar) . " تومان</b>";
        if ($mode === 'manual_adjust' && $manual_percent > 0) {
            $lines[] = "📐 درصد تنظیم: <b>" . $manual_percent . "% " . ($direction === 'decrease' ? 'کاهش' : 'افزایش') . "</b>";
        }
        $lines[] = "📊 خلاصه:";
        $lines[] = "  آپدیت: <b>" . count($changed) . " محصول</b>";
        $lines[] = "  بی‌تغییر: <b>" . $unchanged . " محصول</b>";
        $lines[] = "  مجموع Δ: <b>" . ($total_diff >= 0 ? '+' : '') . number_format($total_diff) . " تومان</b>";
        $lines[] = "  حالت: " . $mode_label;
        $lines[] = "────────────────────";

        foreach ($changed as $p) {
            $pct = $p['old'] > 0 ? round($p['diff'] / $p['old'] * 100, 1) : 0;
            $lines[] = "📦 <b>" . esc_html($p['title']) . "</b>";
            $lines[] = "قبلی: <code>" . number_format($p['old']) . "</code>  →  جدید: <code>" . number_format($p['new']) . "</code>";
            $lines[] = "Δ: <code>" . ($p['diff'] >= 0 ? '+' : '') . number_format($p['diff']) . "</code>  |  " . $pct . "% " . ($p['diff'] > 0 ? '🔺' : '🔻');
            $lines[] = "";
        }

        if (empty($changed)) {
            $lines[] = "🟢 هیچ تغییری لازم نبود.";
        }

        DPU_Telegram::send_chunked(implode("\n", $lines));
    }
}

/**
 * تابع کمکی رند کردن قیمت بر اساس بازه‌ها
 */
function dpu_round_price(float $price, array $opts): float {
    if (empty($opts['enable_rounding'])) {
        return $price;
    }

    $ranges = $opts['round_ranges'] ?? [];

    if (empty($ranges)) {
        $r = floatval($opts['round_to'] ?? 1000000);
        return $r > 0 ? floor($price / $r) * $r : $price;
    }

    $round_value = 1000000;
    foreach ($ranges as $range) {
        if ($price <= $range['max']) {
            $round_value = floatval($range['round']);
            break;
        }
    }

    return $round_value > 0 ? floor($price / $round_value) * $round_value : $price;
}
