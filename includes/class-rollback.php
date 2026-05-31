<?php
if (! defined('ABSPATH')) exit;

/**
 * برگشت قیمت‌ها به snapshot انتخاب‌شده
 */
class DPU_Rollback {

    /**
     * اعمال rollback از یک snapshot
     *
     * @param string $date       تاریخ snapshot به فرمت Y-m-d
     * @param array  $product_ids آرایه آی‌دی محصولات برای rollback (خالی = همه)
     * @return array ['restored'=>int, 'skipped'=>int, 'errors'=>int]
     */
    public static function restore(string $date, array $product_ids = []): array {
        $snapshot = DPU_Logger::load_snapshot($date);

        if (!$snapshot || empty($snapshot['products'])) {
            DPU_Logger::log("Rollback failed: snapshot {$date} not found or empty.");
            return ['error' => 'snapshot_not_found'];
        }

        $snap_dollar = $snapshot['dollar'] ?? 0;
        $products    = $snapshot['products'];

        // فیلتر بر اساس product_ids اگر داده شده
        if (!empty($product_ids)) {
            $products = array_intersect_key($products, array_flip($product_ids));
        }

        $restored = 0;
        $skipped  = 0;
        $errors   = 0;
        $log_rows = [];

        foreach ($products as $pid => $data) {
            $pid   = (int) $pid;
            $price = floatval($data['price']);

            if ($price <= 0) { $skipped++; continue; }

            $product = wc_get_product($pid);
            if (!$product) { $errors++; continue; }

            $old_price = floatval($product->get_regular_price());

            // Update directly in DB to avoid triggering filters in heavy themes/plugins
            global $wpdb;
            $pm_table = $wpdb->postmeta;
            $meta_val = (string) $price;

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

            // Clear caches and update plugin meta
            clean_post_cache($pid);
            update_post_meta($pid, '_dpu_last_update_time', current_time('mysql'));
            update_post_meta($pid, '_dpu_base_price', $price);
            update_post_meta($pid, '_dpu_upload_dollar', $snap_dollar);

            $restored++;
            $log_rows[] = [
                'id'    => $pid,
                'title' => $data['title'],
                'old'   => $old_price,
                'new'   => $price,
                'diff'  => $price - $old_price,
            ];

            DPU_Logger::log("Rollback product {$pid}: {$old_price} -> {$price} (from snapshot {$date})");
        }

        // گزارش تلگرام
        self::send_rollback_report($date, $snap_dollar, $log_rows, $skipped, $errors);

        return [
            'restored' => $restored,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'rows'     => $log_rows,
        ];
    }

    private static function send_rollback_report(
        string $date,
        float $snap_dollar,
        array $rows,
        int $skipped,
        int $errors
    ): void {
        $total_diff = array_sum(array_column($rows, 'diff'));

        $lines   = [];
        $lines[] = "🔙 <b>Rollback انجام شد</b>";
        $lines[] = "📅 بازگشت به: <b>{$date}</b>";
        $lines[] = "💰 دلار snapshot: <b>" . number_format($snap_dollar) . " تومان</b>";
        $lines[] = "⏰ زمان اجرا: " . date_i18n('H:i');
        $lines[] = "📊 نتیجه:";
        $lines[] = "  بازیابی‌شده: <b>" . count($rows) . "</b>";
        $lines[] = "  رد شده: <b>{$skipped}</b>";
        $lines[] = "  خطا: <b>{$errors}</b>";
        $lines[] = "  مجموع Δ: <b>" . ($total_diff >= 0 ? '+' : '') . number_format($total_diff) . " تومان</b>";
        $lines[] = "────────────────────";

        foreach ($rows as $p) {
            $pct = $p['old'] > 0 ? round($p['diff'] / $p['old'] * 100, 1) : 0;
            $lines[] = "📦 <b>" . esc_html($p['title']) . "</b>";
            $lines[] = "قبلی: <code>" . number_format($p['old']) . "</code>  →  جدید: <code>" . number_format($p['new']) . "</code>";
            $lines[] = "Δ: <code>" . ($p['diff'] >= 0 ? '+' : '') . number_format($p['diff']) . "</code>  |  {$pct}% " . ($p['diff'] < 0 ? '🔻' : '🔺');
            $lines[] = "";
        }

        DPU_Telegram::send_chunked(implode("\n", $lines));
    }
}
