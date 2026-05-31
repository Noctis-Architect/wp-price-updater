<?php
if (! defined('ABSPATH')) exit;

class DPU_Admin {

    public static function init(): void {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        // AJAX endpoints for isolated processing (admin-ajax)
        add_action('wp_ajax_dpu_run_now', [self::class, 'ajax_run_now']);
        add_action('wp_ajax_dpu_adjust_prices', [self::class, 'ajax_adjust_prices']);
    }

    public static function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            'Dollar Price Updater',
            'Dollar Updater',
            'manage_woocommerce',
            'dpu-settings',
            [self::class, 'render_page']
        );
    }

    public static function enqueue_assets(string $hook): void {
        if ($hook !== 'woocommerce_page_dpu-settings') return;
        // inline CSS/JS داخل همین فایل است
    }

    // ======================== RENDER ========================

    public static function render_page(): void {
        if (!current_user_can('manage_woocommerce')) return;

        $opts    = DPU_Options::get();
        $notices = [];

        // ---------- پردازش فرم‌ها ----------
        $notices = array_merge($notices, self::handle_save_settings($opts));
        $notices = array_merge($notices, self::handle_run_now());
        $notices = array_merge($notices, self::handle_adjust());
        $notices = array_merge($notices, self::handle_rollback());
        $notices = array_merge($notices, self::handle_clear_cache());
        $notices = array_merge($notices, self::handle_test_api());
        $notices = array_merge($notices, self::handle_send_products());
        $notices = array_merge($notices, self::handle_take_snapshot());

        // تازه‌کردن opts بعد از ذخیره
        $opts = DPU_Options::get();

        self::render_html($opts, $notices);
    }

    // ======================== FORM HANDLERS ========================

    private static function handle_save_settings(array &$opts): array {
        if (!isset($_POST['dpu_save_settings'])) return [];
        check_admin_referer('dpu_save_settings_nonce');

        $round_ranges = [];
        if (isset($_POST['round_ranges']) && is_array($_POST['round_ranges'])) {
            foreach ($_POST['round_ranges'] as $r) {
                $max   = isset($r['max']) ? floatval($r['max']) : 0;
                $round = isset($r['round']) ? floatval($r['round']) : 0;
                if ($max > 0 && $round > 0) {
                    $round_ranges[] = ['max' => $max, 'round' => $round];
                }
            }
        }
        if (empty($round_ranges)) {
            $round_ranges = DPU_Options::defaults()['round_ranges'];
        }

        $new = [
            'api_key'              => sanitize_text_field($_POST['dpu_api_key'] ?? ''),
            'api_url'              => esc_url_raw($_POST['dpu_api_url'] ?? ''),
            'update_times'         => sanitize_text_field($_POST['dpu_update_times'] ?? ''),
            'ratio'                => floatval($_POST['dpu_ratio'] ?? 0.5),
            'manual_percent'       => floatval($_POST['dpu_manual_percent'] ?? 0),
            'limit_categories'     => sanitize_text_field($_POST['dpu_limit_categories'] ?? ''),
            'enable_log'           => isset($_POST['dpu_enable_log']) ? 1 : 0,
            'cache_ttl'            => intval($_POST['dpu_cache_ttl'] ?? 3600),
            // تلگرام
            'telegram_mode'            => sanitize_text_field($_POST['telegram_mode'] ?? 'worker'),
            'telegram_webhook'         => esc_url_raw($_POST['dpu_telegram_webhook'] ?? ''),
            'telegram_chat_id'         => sanitize_text_field($_POST['dpu_telegram_chat_id'] ?? ''),
            'telegram_bot_token'       => sanitize_text_field($_POST['dpu_telegram_bot_token'] ?? ''),
            'telegram_direct_chat_id'  => sanitize_text_field($_POST['dpu_telegram_direct_chat_id'] ?? ''),
            // رند
            'enable_rounding' => !empty($_POST['dpu_enable_rounding']) ? 1 : 0,
            'round_to'        => intval($_POST['dpu_round_to'] ?? 1000000),
            'round_ranges'    => $round_ranges,
        ];

        DPU_Options::set($new);
        DPU_Scheduler::schedule_all();

        return [['type' => 'success', 'msg' => '✅ تنظیمات با موفقیت ذخیره شد.']];
    }

    private static function handle_run_now(): array {
        ob_start();
        if (!isset($_POST['dpu_run_now'])) { @ob_end_clean(); return []; }
        check_admin_referer('dpu_run_now_nonce');

        $result = DPU_Updater::run('manual');
        @ob_end_clean();

        if (isset($result['error'])) {
            return [['type' => 'error', 'msg' => '❌ خطا در اجرا: ' . $result['error']]];
        }

        return [['type' => 'success', 'msg' => "✅ اجرای دستی کامل شد — {$result['changed']} محصول آپدیت شد."]];
    }

    private static function handle_adjust(): array {
        ob_start();
        if (!isset($_POST['dpu_adjust_prices'])) { @ob_end_clean(); return []; }
        check_admin_referer('dpu_adjust_nonce');

        $percent    = max(0, floatval($_POST['adjust_percent'] ?? 0));
        $direction  = sanitize_text_field($_POST['adjust_direction'] ?? 'increase');
        $cat_raw    = sanitize_text_field($_POST['adjust_categories'] ?? '');
        $cats       = $cat_raw ? array_filter(array_map('intval', explode(',', $cat_raw))) : [];

        if ($percent <= 0) {
            @ob_end_clean();
            return [['type' => 'error', 'msg' => '❌ درصد باید بزرگ‌تر از صفر باشد.']];
        }

        $result = DPU_Updater::run('manual_adjust', [
            'percent'    => $percent,
            'direction'  => $direction,
            'categories' => $cats,
        ]);
        @ob_end_clean();

        if (isset($result['error'])) {
            return [['type' => 'error', 'msg' => '❌ خطا: ' . $result['error']]];
        }

        $dir_label = $direction === 'decrease' ? 'کاهش' : 'افزایش';
        return [['type' => 'success', 'msg' => "✅ {$dir_label} {$percent}٪ اعمال شد — {$result['changed']} محصول تغییر کرد."]];
    }

    private static function handle_rollback(): array {
        ob_start();
        if (!isset($_POST['dpu_rollback'])) { @ob_end_clean(); return []; }
        check_admin_referer('dpu_rollback_nonce');

        $date = sanitize_text_field($_POST['rollback_date'] ?? '');
        $pids_raw = sanitize_text_field($_POST['rollback_products'] ?? '');
        $pids = $pids_raw ? array_filter(array_map('intval', explode(',', $pids_raw))) : [];

        if (!$date) {
            @ob_end_clean();
            return [['type' => 'error', 'msg' => '❌ تاریخ snapshot انتخاب نشده.']];
        }

        $result = DPU_Rollback::restore($date, $pids);
        @ob_end_clean();

        if (isset($result['error'])) {
            return [['type' => 'error', 'msg' => '❌ ' . $result['error']]];
        }

        return [['type' => 'success', 'msg' => "✅ Rollback به {$date} انجام شد — {$result['restored']} محصول بازیابی شد."]];
    }

    private static function handle_clear_cache(): array {
        if (!isset($_POST['dpu_clear_cache'])) return [];
        check_admin_referer('dpu_clear_cache_nonce');
        delete_transient('dpu_current_dollar');
        return [['type' => 'success', 'msg' => '✅ کش پاک شد.']];
    }

    private static function handle_test_api(): array {
        if (!isset($_POST['dpu_test_api'])) return [];
        check_admin_referer('dpu_test_api_nonce');

        $price = DPU_API::get_dollar_price(true);
        if ($price !== false) {
            return [['type' => 'success', 'msg' => '✅ تست API موفق: دلار = ' . number_format($price) . ' تومان']];
        }
        return [['type' => 'error', 'msg' => '❌ تست API ناموفق. لاگ را بررسی کنید.']];
    }

    private static function handle_send_products(): array {
        if (!isset($_POST['dpu_send_json_products'])) return [];
        check_admin_referer('dpu_send_json_products_nonce');

        $products = wc_get_products(['limit' => -1, 'status' => 'publish', 'return' => 'objects']);
        $list = [];
        foreach ($products as $p) {
            $price = floatval($p->get_regular_price());
            if ($price <= 0) continue;
            $list[] = [
                'title' => $p->get_name(),
                'price' => number_format($price),
                'url'   => get_permalink($p->get_id()),
            ];
        }

        if (empty($list)) {
            return [['type' => 'error', 'msg' => '❌ محصولی با قیمت معتبر یافت نشد.']];
        }

        $chunks = array_chunk($list, 10);
        $total  = count($chunks);
        foreach ($chunks as $i => $chunk) {
            $msg = "📋 <b>لیست محصولات</b> (بخش " . ($i + 1) . " از {$total})\n━━━━━━━━━━━━━━\n\n";
            foreach ($chunk as $item) {
                $msg .= "📦 <b>{$item['title']}</b>\n💵 {$item['price']} تومان\n🔗 {$item['url']}\n\n";
            }
            DPU_Telegram::send($msg);
            usleep(500000);
        }

        return [['type' => 'success', 'msg' => '✅ ' . count($list) . ' محصول در ' . $total . ' پیام ارسال شد.']];
    }

    private static function handle_take_snapshot(): array {
        if (!isset($_POST['dpu_take_snapshot'])) return [];
        check_admin_referer('dpu_take_snapshot_nonce');

        $dollar = DPU_API::get_dollar_price();
        if ($dollar === false) {
            return [['type' => 'error', 'msg' => '❌ دریافت قیمت دلار ناموفق بود.']];
        }
        DPU_Logger::take_snapshot($dollar);
        return [['type' => 'success', 'msg' => '✅ Snapshot با موفقیت ذخیره شد.']];
    }

    // ======================== AJAX HANDLERS ========================

    private static function ensure_clean_output(): void {
        // If there's any existing buffered output, persist it for debugging
        $debugPath = WP_CONTENT_DIR . '/dpu-ajax-polluted.txt';
        $collected = '';
        $level = ob_get_level();
        for ($i = 0; $i < $level; $i++) {
            $contents = @ob_get_contents();
            if ($contents !== false && $contents !== '') {
                $collected .= "--- BUFFER LEVEL {$i} ---\n" . $contents . "\n";
            }
            @ob_end_clean();
        }

        if ($collected !== '') {
            $hdrs = headers_list();
            $meta = date('c') . " | REQUEST_URI=" . ($_SERVER['REQUEST_URI'] ?? '') . "\nHeaders:\n" . implode("\n", $hdrs) . "\n\n";
            @file_put_contents($debugPath, $meta . $collected . "\n\n", FILE_APPEND | LOCK_EX);
        }

        // Disable PHP output compression for this response to avoid mismatched Content-Length
        @ini_set('zlib.output_compression', '0');

        // Remove any previously-sent Content-Length header so server recalculates it
        if (!headers_sent()) {
            header_remove('Content-Length');
            header('Content-Type: application/json; charset=utf-8');
        } else {
            header_remove('Content-Length');
        }
    }

    private static function verify_ajax_nonce(string $action): void {
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, $action)) {
            self::ensure_clean_output();
            wp_send_json_error(['message' => 'نشست منقضی شده — صفحه را رفرش کنید و دوباره تلاش کنید.']);
        }
    }

    private static function ajax_updater_batch(string $mode, string $nonce_action, array $extra = []): void {
        if (!current_user_can('manage_woocommerce')) {
            self::ensure_clean_output();
            wp_send_json_error(['message' => 'دسترسی غیرمجاز']);
        }

        self::verify_ajax_nonce($nonce_action);

        $page   = max(1, intval($_POST['dpu_batch_page'] ?? 1));
        $run_id = sanitize_text_field(wp_unslash($_POST['dpu_run_id'] ?? ''));

        ob_start();
        try {
            $result = DPU_Updater::run_batch($mode, $extra, $page, $run_id);
        } catch (\Throwable $e) {
            @ob_end_clean();
            self::ensure_clean_output();
            wp_send_json_error(['message' => 'خطای سرور هنگام آپدیت محصولات: ' . $e->getMessage()]);
        }
        @ob_end_clean();

        if (isset($result['error'])) {
            self::ensure_clean_output();
            $msg = $result['message'] ?? $result['error'];
            if ($result['error'] === 'dollar_fetch_failed') {
                $msg = 'دریافت قیمت دلار ناموفق بود.';
            }
            wp_send_json_error(['message' => $msg]);
        }

        self::ensure_clean_output();

        if (!empty($result['done'])) {
            $changed = intval($result['changed']);
            $message = $mode === 'manual_adjust'
                ? "✅ عملیات انجام شد — {$changed} محصول تغییر کرد."
                : "✅ اجرای دستی کامل شد — {$changed} محصول آپدیت شد.";
            wp_send_json_success([
                'done'    => true,
                'changed' => $changed,
                'message' => $message,
            ]);
        }

        wp_send_json_success([
            'done'          => false,
            'run_id'        => $result['run_id'],
            'next_page'     => $result['next_page'],
            'batch_changed' => intval($result['batch_changed']),
            'total_changed' => intval($result['total_changed']),
            'message'       => 'در حال پردازش محصولات... (دسته ' . intval($result['page']) . ')',
        ]);
    }

    public static function ajax_run_now(): void {
        self::ajax_updater_batch('manual', 'dpu_run_now_nonce');
    }

    public static function ajax_adjust_prices(): void {
        $percent   = max(0, floatval($_POST['adjust_percent'] ?? 0));
        $direction = sanitize_text_field($_POST['adjust_direction'] ?? 'increase');
        $cat_raw   = sanitize_text_field($_POST['adjust_categories'] ?? '');
        $cats      = $cat_raw ? array_filter(array_map('intval', explode(',', $cat_raw))) : [];

        if ($percent <= 0 && max(1, intval($_POST['dpu_batch_page'] ?? 1)) === 1) {
            self::ensure_clean_output();
            wp_send_json_error(['message' => 'درصد باید بزرگ‌تر از صفر باشد.']);
        }

        self::ajax_updater_batch('manual_adjust', 'dpu_adjust_nonce', [
            'percent'    => $percent,
            'direction'  => $direction,
            'categories' => $cats,
        ]);
    }

    // ======================== HTML ========================

    private static function render_html(array $opts, array $notices): void {
        $dollar_now = DPU_API::get_dollar_price();
        $snapshots  = DPU_Logger::list_snapshots();
        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        ?>
        <style>
        :root {
            --dpu-blue: #2271b1;
            --dpu-blue-dark: #135e96;
            --dpu-green: #00a32a;
            --dpu-red: #d63638;
            --dpu-border: #c3c4c7;
            --dpu-bg: #f6f7f7;
            --dpu-card-bg: #fff;
            --dpu-radius: 8px;
        }
        .dpu-wrap { max-width: 1100px; padding: 20px 0; }
        .dpu-wrap h1 { font-size: 22px; margin-bottom: 20px; }
        .dpu-tabs { display: flex; gap: 4px; border-bottom: 2px solid var(--dpu-border); margin-bottom: 24px; flex-wrap: wrap; }
        .dpu-tab { padding: 10px 20px; cursor: pointer; border: 1px solid transparent; border-bottom: none; border-radius: 6px 6px 0 0; font-weight: 600; font-size: 14px; background: var(--dpu-bg); color: #50575e; transition: .2s; }
        .dpu-tab:hover { background: #e8eaeb; }
        .dpu-tab.active { background: var(--dpu-card-bg); border-color: var(--dpu-border); color: var(--dpu-blue); margin-bottom: -2px; border-bottom: 2px solid #fff; }
        .dpu-panel { display: none; }
        .dpu-panel.active { display: block; }
        .dpu-card { background: var(--dpu-card-bg); border: 1px solid var(--dpu-border); border-radius: var(--dpu-radius); padding: 22px 26px; margin-bottom: 20px; }
        .dpu-card h2 { margin: 0 0 18px; font-size: 16px; color: #1d2327; border-bottom: 2px solid var(--dpu-blue); padding-bottom: 10px; }
        .dpu-card h3 { margin: 0 0 14px; font-size: 14px; color: #1d2327; }
        .dpu-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 782px) { .dpu-grid { grid-template-columns: 1fr; } .dpu-tabs { gap: 2px; } .dpu-tab { padding: 8px 12px; font-size: 13px; } }
        .form-row { display: flex; align-items: flex-start; gap: 16px; margin-bottom: 14px; }
        .form-row label { min-width: 200px; font-weight: 600; font-size: 14px; padding-top: 8px; }
        .form-row .field-wrap { flex: 1; }
        .form-row input[type="text"],
        .form-row input[type="number"],
        .form-row select { width: 100%; padding: 8px 12px; border: 1px solid var(--dpu-border); border-radius: 5px; font-size: 14px; }
        .form-row input:focus, .form-row select:focus { border-color: var(--dpu-blue); outline: none; box-shadow: 0 0 0 2px rgba(34,113,177,.15); }
        .form-row .desc { font-size: 12px; color: #646970; margin-top: 5px; }
        @media (max-width: 600px) { .form-row { flex-direction: column; gap: 6px; } .form-row label { min-width: auto; padding-top: 0; } }

        /* رادیو تلگرام */
        .tg-mode-selector { display: flex; gap: 12px; margin-bottom: 16px; }
        .tg-mode-btn { flex: 1; padding: 12px; border: 2px solid var(--dpu-border); border-radius: var(--dpu-radius); cursor: pointer; text-align: center; transition: .2s; }
        .tg-mode-btn:hover { border-color: var(--dpu-blue); }
        .tg-mode-btn.selected { border-color: var(--dpu-blue); background: #e8f0fb; }
        .tg-mode-btn input { display: none; }
        .tg-mode-btn .icon { font-size: 24px; display: block; margin-bottom: 6px; }
        .tg-mode-btn .label { font-weight: 600; font-size: 14px; }
        .tg-mode-btn .sublabel { font-size: 12px; color: #646970; }
        .tg-section { display: none; padding: 16px; background: var(--dpu-bg); border-radius: 6px; margin-bottom: 16px; }
        .tg-section.active { display: block; }

        /* worker code */
        .worker-code-wrap { position: relative; }
        .worker-code-wrap textarea { width: 100%; height: 220px; font-family: 'Consolas','Monaco',monospace; font-size: 12px; background: #1e1e1e; color: #d4d4d4; border: 1px solid #444; border-radius: 6px; padding: 12px; resize: vertical; }
        .copy-btn { position: absolute; top: 8px; left: 8px; padding: 5px 12px; background: var(--dpu-blue); color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; }
        .copy-btn:hover { background: var(--dpu-blue-dark); }

        /* جدول رند */
        .dpu-range-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .dpu-range-table th { background: var(--dpu-blue); color: #fff; padding: 10px 12px; text-align: right; }
        .dpu-range-table td { padding: 10px 12px; border-bottom: 1px solid #f0f0f1; vertical-align: middle; }
        .dpu-range-table tr:last-child td { border-bottom: none; }
        .dpu-range-table tr:nth-child(even) td { background: #f8f9fa; }
        .dpu-range-table input { width: 100%; padding: 7px 10px; border: 1px solid var(--dpu-border); border-radius: 4px; font-size: 13px; }

        /* بخش تنظیم دستی */
        .adjust-row { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
        .adjust-row .field-group { display: flex; flex-direction: column; gap: 5px; }
        .adjust-row .field-group label { font-size: 13px; font-weight: 600; color: #1d2327; }
        .adjust-row input[type="number"], .adjust-row select { padding: 9px 12px; border: 1px solid var(--dpu-border); border-radius: 5px; font-size: 14px; }

        /* snapshot جدول */
        .dpu-snapshot-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .dpu-snapshot-table th { background: var(--dpu-blue); color: #fff; padding: 10px 12px; text-align: right; }
        .dpu-snapshot-table td { padding: 10px 12px; border-bottom: 1px solid #f0f0f1; vertical-align: middle; }
        .dpu-snapshot-table tr:hover td { background: #f0f6fc; }
        .dpu-snapshot-table .rollback-btn { padding: 5px 14px; background: #ff8c00; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 600; }
        .dpu-snapshot-table .rollback-btn:hover { background: #e07800; }

        /* status badge */
        .dpu-badge { display: inline-block; padding: 5px 12px; border-radius: 20px; font-size: 13px; font-weight: 600; }
        .dpu-badge-green { background: #d4edda; color: #155724; }
        .dpu-badge-blue { background: #cce5ff; color: #004085; }
        .dpu-badge-orange { background: #fff3cd; color: #856404; }

        /* buttons */
        .dpu-btn { display: inline-block; padding: 9px 20px; border-radius: 5px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: .2s; text-decoration: none; }
        .dpu-btn:hover { transform: translateY(-1px); box-shadow: 0 3px 8px rgba(0,0,0,.15); }
        .dpu-btn-primary { background: var(--dpu-blue); color: #fff; }
        .dpu-btn-primary:hover { background: var(--dpu-blue-dark); }
        .dpu-btn-danger { background: var(--dpu-red); color: #fff; }
        .dpu-btn-danger:hover { background: #b32d2e; }
        .dpu-btn-warning { background: #ff8c00; color: #fff; }
        .dpu-btn-warning:hover { background: #e07800; }
        .dpu-btn-secondary { background: #f6f7f7; color: #1d2327; border: 1px solid var(--dpu-border); }
        .dpu-btn-secondary:hover { background: #e8eaeb; }
        .dpu-btn-group { display: flex; gap: 10px; flex-wrap: wrap; }

        /* نوتیس */
        .dpu-notice { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; font-weight: 500; }
        .dpu-notice-success { background: #d4edda; color: #155724; border-right: 4px solid var(--dpu-green); }
        .dpu-notice-error { background: #f8d7da; color: #721c24; border-right: 4px solid var(--dpu-red); }

        /* وضعیت سیستم */
        .dpu-status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; }
        .dpu-status-item { background: var(--dpu-bg); border-radius: 8px; padding: 14px 16px; border: 1px solid #e0e0e0; }
        .dpu-status-item .value { font-size: 22px; font-weight: 700; color: var(--dpu-blue); margin: 4px 0; }
        .dpu-status-item .title { font-size: 12px; color: #646970; }
        </style>

        <div class="wrap dpu-wrap">
            <h1>💰 Dollar Price Updater PRO</h1>

            <?php foreach ($notices as $n): ?>
                <div class="dpu-notice dpu-notice-<?php echo $n['type']; ?>"><?php echo $n['msg']; ?></div>
            <?php endforeach; ?>

            <!-- تب‌ها -->
            <div class="dpu-tabs">
                <div class="dpu-tab active" data-tab="dashboard">📊 داشبورد</div>
                <div class="dpu-tab" data-tab="settings">⚙️ تنظیمات</div>
                <div class="dpu-tab" data-tab="telegram">📱 تلگرام</div>
                <div class="dpu-tab" data-tab="rounding">🎯 رند کردن</div>
                <div class="dpu-tab" data-tab="adjust">🔧 تنظیم قیمت</div>
                <div class="dpu-tab" data-tab="rollback">🔙 برگشت قیمت</div>
                <div class="dpu-tab" data-tab="tools">🚀 ابزارها</div>
            </div>

            <!-- ========== داشبورد ========== -->
            <div class="dpu-panel active" id="tab-dashboard">
                <div class="dpu-card">
                    <h2>📊 وضعیت سیستم</h2>
                    <div class="dpu-status-grid">
                        <div class="dpu-status-item">
                            <div class="title">💵 قیمت دلار</div>
                            <div class="value"><?php echo $dollar_now !== false ? number_format($dollar_now) : '—'; ?></div>
                            <div class="title">تومان</div>
                        </div>
                        <div class="dpu-status-item">
                            <div class="title">📸 آخرین Snapshot</div>
                            <div class="value"><?php echo !empty($snapshots) ? $snapshots[0]['date'] : '—'; ?></div>
                            <div class="title"><?php echo !empty($snapshots) ? $snapshots[0]['count'] . ' محصول' : 'هنوز نداریم'; ?></div>
                        </div>
                        <div class="dpu-status-item">
                            <div class="title">⏰ زمان آپدیت</div>
                            <div class="value" style="font-size:16px;"><?php echo esc_html($opts['update_times']); ?></div>
                            <div class="title">ساعت تهران</div>
                        </div>
                        <div class="dpu-status-item">
                            <div class="title">📝 لاگ</div>
                            <div class="value" style="font-size:14px;"><?php echo $opts['enable_log'] ? '✅ فعال' : '❌ غیرفعال'; ?></div>
                            <div class="title"><?php echo WP_CONTENT_DIR . '/dpu-log.txt'; ?></div>
                        </div>
                    </div>
                </div>

                <div class="dpu-card">
                    <h2>🚀 اجرای سریع</h2>
                    <div class="dpu-btn-group">
                        <form method="post" class="dpu-ajax-form" data-dpu-action="dpu_run_now" style="margin:0">
                            <?php wp_nonce_field('dpu_run_now_nonce'); ?>
                            <button name="dpu_run_now" class="dpu-btn dpu-btn-primary">▶️ اجرای فوری</button>
                        </form>
                        <form method="post" style="margin:0">
                            <?php wp_nonce_field('dpu_take_snapshot_nonce'); ?>
                            <button name="dpu_take_snapshot" class="dpu-btn dpu-btn-secondary">📸 گرفتن Snapshot</button>
                        </form>
                        <form method="post" style="margin:0">
                            <?php wp_nonce_field('dpu_clear_cache_nonce'); ?>
                            <button name="dpu_clear_cache" class="dpu-btn dpu-btn-secondary">🗑️ پاک کردن کش</button>
                        </form>
                        <form method="post" style="margin:0">
                            <?php wp_nonce_field('dpu_test_api_nonce'); ?>
                            <button name="dpu_test_api" class="dpu-btn dpu-btn-secondary">🔍 تست API</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ========== تنظیمات ========== -->
            <div class="dpu-panel" id="tab-settings">
                <form method="post">
                    <?php wp_nonce_field('dpu_save_settings_nonce'); ?>

                    <div class="dpu-card">
                        <h2>🔌 تنظیمات API</h2>
                        <div class="form-row">
                            <label>API URL</label>
                            <div class="field-wrap">
                                <input type="text" name="dpu_api_url" value="<?php echo esc_attr($opts['api_url']); ?>" />
                            </div>
                        </div>
                        <div class="form-row">
                            <label>API Key</label>
                            <div class="field-wrap">
                                <input type="text" name="dpu_api_key" value="<?php echo esc_attr($opts['api_key']); ?>" />
                            </div>
                        </div>
                        <div class="form-row">
                            <label>کش API (ثانیه)</label>
                            <div class="field-wrap">
                                <input type="number" name="dpu_cache_ttl" value="<?php echo esc_attr($opts['cache_ttl']); ?>" style="max-width:160px" />
                                <div class="desc">۳۶۰۰ = یک ساعت</div>
                            </div>
                        </div>
                    </div>

                    <div class="dpu-card">
                        <h2>⏰ زمان‌بندی</h2>
                        <div class="form-row">
                            <label>زمان‌های آپدیت</label>
                            <div class="field-wrap">
                                <input type="text" name="dpu_update_times" value="<?php echo esc_attr($opts['update_times']); ?>" />
                                <div class="desc">ساعت تهران — مثال: <code>00:00,08:00,20:00</code></div>
                            </div>
                        </div>
                        <div class="form-row">
                            <label>نسبت افزایش (ضریب)</label>
                            <div class="field-wrap">
                                <input type="number" step="0.01" name="dpu_ratio" value="<?php echo esc_attr($opts['ratio']); ?>" style="max-width:160px" />
                                <div class="desc">۰.۵ = ۵۰٪ از تغییر دلار</div>
                            </div>
                        </div>
                        <div class="form-row">
                            <label>درصد دستی (اجرای فوری)</label>
                            <div class="field-wrap">
                                <input type="number" step="0.01" name="dpu_manual_percent" value="<?php echo esc_attr($opts['manual_percent']); ?>" style="max-width:160px" />
                                <div class="desc">مثلاً ۵ → افزایش ۵٪ اضافی — فقط در اجرای فوری</div>
                            </div>
                        </div>
                        <div class="form-row">
                            <label>محدودیت دسته‌ها</label>
                            <div class="field-wrap">
                                <input type="text" name="dpu_limit_categories" value="<?php echo esc_attr($opts['limit_categories']); ?>" placeholder="مثال: 12,45,78" />
                                <div class="desc">آی‌دی دسته‌ها با کاما — خالی = همه دسته‌ها</div>
                            </div>
                        </div>
                        <div class="form-row">
                            <label>لاگ</label>
                            <div class="field-wrap">
                                <label style="display:flex;align-items:center;gap:8px;font-weight:normal;padding-top:0">
                                    <input type="checkbox" name="dpu_enable_log" <?php checked(1, $opts['enable_log']); ?> />
                                    فعال کردن لاگ فعالیت‌ها
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- hidden fields برای بخش‌های دیگر تا در همان فرم باشند -->
                    <input type="hidden" name="telegram_mode" value="<?php echo esc_attr($opts['telegram_mode']); ?>" id="hidden_telegram_mode" />

                    <?php
                    // round_ranges hidden
                    $round_ranges = $opts['round_ranges'] ?? DPU_Options::defaults()['round_ranges'];
                    foreach ($round_ranges as $idx => $rr): ?>
                        <input type="hidden" name="round_ranges[<?php echo $idx; ?>][max]" value="<?php echo esc_attr($rr['max']); ?>" class="rr-max-hidden-<?php echo $idx; ?>" />
                        <input type="hidden" name="round_ranges[<?php echo $idx; ?>][round]" value="<?php echo esc_attr($rr['round']); ?>" class="rr-round-hidden-<?php echo $idx; ?>" />
                    <?php endforeach; ?>
                    <input type="hidden" name="dpu_enable_rounding" value="<?php echo esc_attr($opts['enable_rounding'] ?? 1); ?>" />

                    <p><button type="submit" name="dpu_save_settings" class="dpu-btn dpu-btn-primary button-large">💾 ذخیره تنظیمات</button></p>
                </form>
            </div>

            <!-- ========== تلگرام ========== -->
            <div class="dpu-panel" id="tab-telegram">
                <form method="post">
                    <?php wp_nonce_field('dpu_save_settings_nonce'); ?>

                    <div class="dpu-card">
                        <h2>📱 روش ارسال تلگرام</h2>

                        <div class="tg-mode-selector">
                            <label class="tg-mode-btn <?php echo $opts['telegram_mode'] === 'worker' ? 'selected' : ''; ?>">
                                <input type="radio" name="telegram_mode" value="worker" <?php checked('worker', $opts['telegram_mode']); ?> />
                                <span class="icon">☁️</span>
                                <span class="label">Cloudflare Worker</span>
                                <span class="sublabel">ارسال از طریق Worker</span>
                            </label>
                            <label class="tg-mode-btn <?php echo $opts['telegram_mode'] === 'direct' ? 'selected' : ''; ?>">
                                <input type="radio" name="telegram_mode" value="direct" <?php checked('direct', $opts['telegram_mode']); ?> />
                                <span class="icon">🤖</span>
                                <span class="label">ربات مستقیم</span>
                                <span class="sublabel">ارسال مستقیم به API تلگرام</span>
                            </label>
                        </div>

                        <!-- Worker -->
                        <div class="tg-section <?php echo $opts['telegram_mode'] === 'worker' ? 'active' : ''; ?>" id="tg-worker">
                            <h3>⚙️ تنظیمات Worker</h3>
                            <div class="form-row">
                                <label>Worker URL</label>
                                <div class="field-wrap">
                                    <input type="text" name="dpu_telegram_webhook" value="<?php echo esc_attr($opts['telegram_webhook']); ?>" placeholder="https://your-worker.workers.dev/" />
                                    <div class="desc">آدرس Cloudflare Worker شما</div>
                                </div>
                            </div>
                            <div class="form-row">
                                <label>Chat ID</label>
                                <div class="field-wrap">
                                    <input type="text" name="dpu_telegram_chat_id" value="<?php echo esc_attr($opts['telegram_chat_id']); ?>" placeholder="-1001234567890" />
                                    <div class="desc">شناسه گروه یا کانال</div>
                                </div>
                            </div>

                            <hr style="margin:20px 0;border:0;border-top:1px solid #e0e0e0" />
                            <h3>📄 کد Cloudflare Worker</h3>
                            <p style="font-size:13px;color:#646970;margin-bottom:12px">
                                این کد را در Cloudflare Worker خود قرار دهید. مقدار <code>BOT_TOKEN</code> را با توکن ربات خود جایگزین کنید.
                            </p>
                            <?php
                            $worker_code = file_get_contents(DPU_PLUGIN_DIR . 'admin/worker-template.js');
                            ?>
                            <div class="worker-code-wrap">
                                <button type="button" class="copy-btn" onclick="copyWorkerCode()">📋 کپی</button>
                                <textarea id="worker-code-textarea" readonly><?php echo esc_textarea($worker_code); ?></textarea>
                            </div>
                        </div>

                        <!-- Direct -->
                        <div class="tg-section <?php echo $opts['telegram_mode'] === 'direct' ? 'active' : ''; ?>" id="tg-direct">
                            <h3>🤖 تنظیمات ربات مستقیم</h3>
                            <div class="form-row">
                                <label>Bot Token</label>
                                <div class="field-wrap">
                                    <input type="text" name="dpu_telegram_bot_token" value="<?php echo esc_attr($opts['telegram_bot_token']); ?>" placeholder="1234567890:AAXXXXXXXXXXXXXXXXXXXXXXXX" />
                                    <div class="desc">توکن ربات از <a href="https://t.me/BotFather" target="_blank">@BotFather</a></div>
                                </div>
                            </div>
                            <div class="form-row">
                                <label>Chat ID</label>
                                <div class="field-wrap">
                                    <input type="text" name="dpu_telegram_direct_chat_id" value="<?php echo esc_attr($opts['telegram_direct_chat_id']); ?>" placeholder="-1001234567890" />
                                    <div class="desc">آی‌دی گروه یا کانال یا کاربر</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- hidden fields لازم -->
                    <?php foreach ($round_ranges as $idx => $rr): ?>
                        <input type="hidden" name="round_ranges[<?php echo $idx; ?>][max]" value="<?php echo esc_attr($rr['max']); ?>" />
                        <input type="hidden" name="round_ranges[<?php echo $idx; ?>][round]" value="<?php echo esc_attr($rr['round']); ?>" />
                    <?php endforeach; ?>
                    <input type="hidden" name="dpu_api_key" value="<?php echo esc_attr($opts['api_key']); ?>" />
                    <input type="hidden" name="dpu_api_url" value="<?php echo esc_attr($opts['api_url']); ?>" />
                    <input type="hidden" name="dpu_update_times" value="<?php echo esc_attr($opts['update_times']); ?>" />
                    <input type="hidden" name="dpu_ratio" value="<?php echo esc_attr($opts['ratio']); ?>" />
                    <input type="hidden" name="dpu_manual_percent" value="<?php echo esc_attr($opts['manual_percent']); ?>" />
                    <input type="hidden" name="dpu_limit_categories" value="<?php echo esc_attr($opts['limit_categories']); ?>" />
                    <input type="hidden" name="dpu_enable_log" value="<?php echo esc_attr($opts['enable_log']); ?>" />
                    <input type="hidden" name="dpu_cache_ttl" value="<?php echo esc_attr($opts['cache_ttl']); ?>" />
                    <input type="hidden" name="dpu_round_to" value="<?php echo esc_attr($opts['round_to'] ?? 1000000); ?>" />
                    <input type="hidden" name="dpu_enable_rounding" value="<?php echo esc_attr($opts['enable_rounding'] ?? 1); ?>" />

                    <p><button type="submit" name="dpu_save_settings" class="dpu-btn dpu-btn-primary">💾 ذخیره تنظیمات تلگرام</button></p>
                </form>
            </div>

            <!-- ========== رند کردن ========== -->
            <div class="dpu-panel" id="tab-rounding">
                <form method="post">
                    <?php wp_nonce_field('dpu_save_settings_nonce'); ?>

                    <div class="dpu-card">
                        <h2>🎯 تنظیمات رند کردن هوشمند</h2>
                        <div class="form-row" style="margin-bottom:16px">
                            <label>رند کردن</label>
                            <div class="field-wrap">
                                <label style="display:flex;align-items:center;gap:8px;font-weight:normal;padding-top:0">
                                    <input type="checkbox" name="dpu_enable_rounding" value="1" <?php checked(1, $opts['enable_rounding'] ?? 1); ?> />
                                    فعال کردن رند کردن قیمت‌ها
                                </label>
                                <div class="desc">غیرفعال = قیمت دقیق محاسبه‌شده بدون رند (نه بالا، نه پایین)</div>
                            </div>
                        </div>
                        <p style="color:#646970;font-size:13px;margin-bottom:16px">
                            برای هر بازه قیمتی مشخص کنید قیمت به چه عددی رند شود. در حالت فعال، قیمت‌ها به <strong>پایین</strong> رند می‌شوند.
                        </p>
                        <table class="dpu-range-table">
                            <thead>
                                <tr>
                                    <th>تا این قیمت (تومان)</th>
                                    <th>رند به (تومان)</th>
                                    <th>مثال</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($round_ranges as $idx => $range):
                                    $is_last = ($range['max'] === PHP_INT_MAX);
                                    $example_price = $is_last ? 650000000 : $range['max'] * 0.75;
                                    $example_result = number_format(dpu_round_price($example_price, [
                                        'enable_rounding' => $opts['enable_rounding'] ?? 1,
                                        'round_ranges'    => [$range],
                                    ]));
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($is_last): ?>
                                            <input type="hidden" name="round_ranges[<?php echo $idx; ?>][max]" value="<?php echo PHP_INT_MAX; ?>" />
                                            <span style="color:#646970;font-style:italic">بی‌نهایت</span>
                                        <?php else: ?>
                                            <input type="number" name="round_ranges[<?php echo $idx; ?>][max]" value="<?php echo esc_attr($range['max']); ?>" />
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <input type="number" name="round_ranges[<?php echo $idx; ?>][round]" value="<?php echo esc_attr($range['round']); ?>" />
                                    </td>
                                    <td style="color:#646970;font-size:13px">
                                        ≈ <code><?php echo $example_result; ?></code>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- hidden fields -->
                    <input type="hidden" name="telegram_mode" value="<?php echo esc_attr($opts['telegram_mode']); ?>" />
                    <input type="hidden" name="dpu_telegram_webhook" value="<?php echo esc_attr($opts['telegram_webhook']); ?>" />
                    <input type="hidden" name="dpu_telegram_chat_id" value="<?php echo esc_attr($opts['telegram_chat_id']); ?>" />
                    <input type="hidden" name="dpu_telegram_bot_token" value="<?php echo esc_attr($opts['telegram_bot_token']); ?>" />
                    <input type="hidden" name="dpu_telegram_direct_chat_id" value="<?php echo esc_attr($opts['telegram_direct_chat_id']); ?>" />
                    <input type="hidden" name="dpu_api_key" value="<?php echo esc_attr($opts['api_key']); ?>" />
                    <input type="hidden" name="dpu_api_url" value="<?php echo esc_attr($opts['api_url']); ?>" />
                    <input type="hidden" name="dpu_update_times" value="<?php echo esc_attr($opts['update_times']); ?>" />
                    <input type="hidden" name="dpu_ratio" value="<?php echo esc_attr($opts['ratio']); ?>" />
                    <input type="hidden" name="dpu_manual_percent" value="<?php echo esc_attr($opts['manual_percent']); ?>" />
                    <input type="hidden" name="dpu_limit_categories" value="<?php echo esc_attr($opts['limit_categories']); ?>" />
                    <input type="hidden" name="dpu_enable_log" value="<?php echo esc_attr($opts['enable_log']); ?>" />
                    <input type="hidden" name="dpu_cache_ttl" value="<?php echo esc_attr($opts['cache_ttl']); ?>" />
                    <input type="hidden" name="dpu_round_to" value="<?php echo esc_attr($opts['round_to'] ?? 1000000); ?>" />

                    <p><button type="submit" name="dpu_save_settings" class="dpu-btn dpu-btn-primary">💾 ذخیره رند کردن</button></p>
                </form>
            </div>

            <!-- ========== تنظیم دستی قیمت ========== -->
            <div class="dpu-panel" id="tab-adjust">
                <div class="dpu-card">
                    <h2>🔧 تنظیم دستی قیمت</h2>
                    <p style="color:#646970;font-size:13px;margin-bottom:20px">
                        بدون هیچ محدودیتی — می‌توانید قیمت‌ها را افزایش یا کاهش دهید. این عملیات snapshot قبلی را به‌عنوان پشتیبان ذخیره می‌کند.
                    </p>

                    <form method="post" class="dpu-ajax-form" data-dpu-action="dpu_adjust_prices">
                        <?php wp_nonce_field('dpu_adjust_nonce'); ?>

                        <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:12px 16px;margin-bottom:20px;font-size:13px;">
                            ⚠️ <strong>توجه:</strong> این عملیات قیمت واقعی محصولات را تغییر می‌دهد. قبل از اجرا یک Snapshot بگیرید.
                        </div>

                        <div class="adjust-row">
                            <div class="field-group">
                                <label>درصد تغییر</label>
                                <input type="number" name="adjust_percent" step="0.1" min="0.1" placeholder="مثال: 10" style="width:140px" required />
                            </div>
                            <div class="field-group">
                                <label>جهت</label>
                                <select name="adjust_direction" style="width:140px">
                                    <option value="increase">📈 افزایش</option>
                                    <option value="decrease">📉 کاهش</option>
                                </select>
                            </div>
                            <div class="field-group" style="flex:1">
                                <label>محدودیت دسته (اختیاری)</label>
                                <input type="text" name="adjust_categories" placeholder="آی‌دی دسته‌ها: 12,45 — خالی = همه" />
                            </div>
                        </div>

                        <div style="margin-top:20px">
                            <h3 style="font-size:14px;margin-bottom:10px">یا دسته را از لیست انتخاب کنید:</h3>
                            <div style="display:flex;flex-wrap:wrap;gap:8px;max-height:200px;overflow-y:auto;padding:10px;background:#f6f7f7;border-radius:6px;border:1px solid #ddd">
                                <?php if (!is_wp_error($categories)): ?>
                                    <?php foreach ($categories as $cat): ?>
                                        <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;background:#fff;padding:5px 10px;border-radius:4px;border:1px solid #ddd">
                                            <input type="checkbox" class="cat-checkbox" value="<?php echo esc_attr($cat->term_id); ?>" />
                                            <?php echo esc_html($cat->name); ?>
                                            <span style="color:#646970">(<?php echo $cat->count; ?>)</span>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <p style="font-size:12px;color:#646970;margin-top:6px">انتخاب دسته‌ها فیلد "محدودیت دسته" بالا را پر می‌کند</p>
                        </div>

                        <div style="margin-top:20px">
                            <button type="submit" name="dpu_adjust_prices" class="dpu-btn dpu-btn-warning"
                                onclick="return confirmAdjust(this.form)">
                                ✅ اعمال تغییر قیمت
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ========== برگشت قیمت ========== -->
            <div class="dpu-panel" id="tab-rollback">
                <div class="dpu-card">
                    <h2>🔙 برگشت به قیمت‌های قبلی</h2>
                    <p style="color:#646970;font-size:13px;margin-bottom:20px">
                        هر روز قبل از اعمال تغییرات یک Snapshot خودکار گرفته می‌شود. می‌توانید به هر روز برگردید.
                    </p>

                    <?php if (empty($snapshots)): ?>
                        <div style="background:#f8f9fa;border:1px solid #ddd;border-radius:6px;padding:20px;text-align:center;color:#646970">
                            📭 هنوز هیچ Snapshot‌ای وجود ندارد. پس از اولین اجرای آپدیت، snapshot ذخیره می‌شود.
                        </div>
                    <?php else: ?>
                        <table class="dpu-snapshot-table">
                            <thead>
                                <tr>
                                    <th>تاریخ</th>
                                    <th>قیمت دلار</th>
                                    <th>تعداد محصول</th>
                                    <th>آخرین به‌روزرسانی</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($snapshots as $snap): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($snap['date']); ?></strong></td>
                                    <td><?php echo $snap['dollar'] ? number_format($snap['dollar']) . ' ت' : '—'; ?></td>
                                    <td><span class="dpu-badge dpu-badge-blue"><?php echo $snap['count']; ?> محصول</span></td>
                                    <td style="font-size:12px;color:#646970"><?php echo esc_html($snap['updated']); ?></td>
                                    <td>
                                        <form method="post" style="margin:0;display:inline">
                                            <?php wp_nonce_field('dpu_rollback_nonce'); ?>
                                            <input type="hidden" name="rollback_date" value="<?php echo esc_attr($snap['date']); ?>" />
                                            <input type="hidden" name="rollback_products" value="" />
                                            <button type="submit" name="dpu_rollback" class="rollback-btn"
                                                onclick="return confirm('آیا مطمئنید؟ قیمت‌ها به <?php echo esc_attr($snap['date']); ?> برمی‌گردند.')">
                                                🔙 بازیابی کامل
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="dpu-card">
                    <h2>📸 گرفتن Snapshot دستی</h2>
                    <p style="font-size:13px;color:#646970;margin-bottom:14px">همین الان یک نسخه پشتیبان از قیمت‌های فعلی بگیرید.</p>
                    <form method="post" style="margin:0">
                        <?php wp_nonce_field('dpu_take_snapshot_nonce'); ?>
                        <button name="dpu_take_snapshot" class="dpu-btn dpu-btn-secondary">📸 ذخیره Snapshot</button>
                    </form>
                </div>
            </div>

            <!-- ========== ابزارها ========== -->
            <div class="dpu-panel" id="tab-tools">
                <div class="dpu-card">
                    <h2>🚀 ابزارهای سریع</h2>
                    <div class="dpu-btn-group">
                        <form method="post" class="dpu-ajax-form" data-dpu-action="dpu_run_now" style="margin:0">
                            <?php wp_nonce_field('dpu_run_now_nonce'); ?>
                            <button name="dpu_run_now" class="dpu-btn dpu-btn-primary">▶️ اجرای فوری</button>
                        </form>
                        <form method="post" style="margin:0">
                            <?php wp_nonce_field('dpu_test_api_nonce'); ?>
                            <button name="dpu_test_api" class="dpu-btn dpu-btn-secondary">🔍 تست API دلار</button>
                        </form>
                        <form method="post" style="margin:0">
                            <?php wp_nonce_field('dpu_clear_cache_nonce'); ?>
                            <button name="dpu_clear_cache" class="dpu-btn dpu-btn-secondary">🗑️ پاک کردن کش</button>
                        </form>
                        <form method="post" style="margin:0">
                            <?php wp_nonce_field('dpu_send_json_products_nonce'); ?>
                            <button name="dpu_send_json_products" class="dpu-btn dpu-btn-secondary">📤 ارسال لیست محصولات به تلگرام</button>
                        </form>
                    </div>
                </div>

                <div class="dpu-card">
                    <h2>📖 راهنمای سریع</h2>
                    <ul style="list-style:disc;padding-right:20px;font-size:14px;line-height:1.9;color:#1d2327">
                        <li><strong>نسبت افزایش:</strong> ۰.۵ = اگر دلار ۱۰٪ رشد کرد، قیمت ۵٪ بالا می‌رود</li>
                        <li><strong>رند هوشمند:</strong> بازه‌های مختلف → رند متفاوت</li>
                        <li><strong>کاهش قیمت:</strong> در حالت خودکار هرگز کاهش نمی‌یابد — از بخش "تنظیم دستی" استفاده کنید</li>
                        <li><strong>Snapshot:</strong> قبل از هر تغییر بزرگ یک نسخه پشتیبان بگیرید</li>
                        <li><strong>Rollback:</strong> هر زمان می‌توانید به هر روز قبل برگردید</li>
                        <li><strong>تلگرام Worker:</strong> کد Worker را در Cloudflare Workers قرار دهید و BOT_TOKEN را تنظیم کنید</li>
                    </ul>
                </div>
            </div>

        </div><!-- .dpu-wrap -->

        <script>
        // ---- تب‌ها ----
        document.querySelectorAll('.dpu-tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.dpu-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.dpu-panel').forEach(p => p.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
                // persist tab
                localStorage.setItem('dpu_active_tab', tab.dataset.tab);
            });
        });
        // بازگردانی تب از localStorage
        (function() {
            var saved = localStorage.getItem('dpu_active_tab');
            if (saved) {
                var t = document.querySelector('.dpu-tab[data-tab="' + saved + '"]');
                if (t) t.click();
            }
        })();

        // ---- تلگرام: نمایش/پنهان بخش‌ها ----
        document.querySelectorAll('input[name="telegram_mode"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                document.querySelectorAll('.tg-mode-btn').forEach(b => b.classList.remove('selected'));
                this.closest('.tg-mode-btn').classList.add('selected');
                document.getElementById('tg-worker').classList.toggle('active', this.value === 'worker');
                document.getElementById('tg-direct').classList.toggle('active', this.value === 'direct');
            });
        });

        // ---- کپی کد Worker ----
        function copyWorkerCode() {
            var ta = document.getElementById('worker-code-textarea');
            ta.select();
            document.execCommand('copy');
            var btn = document.querySelector('.copy-btn');
            btn.textContent = '✅ کپی شد!';
            setTimeout(function() { btn.textContent = '📋 کپی'; }, 2000);
        }

        // ---- چک‌باکس دسته‌ها → فیلد adjust_categories ----
        document.querySelectorAll('.cat-checkbox').forEach(function(cb) {
            cb.addEventListener('change', function() {
                var selected = Array.from(document.querySelectorAll('.cat-checkbox:checked')).map(c => c.value);
                document.querySelector('input[name="adjust_categories"]').value = selected.join(',');
            });
        });

        // ---- تأییدیه تنظیم قیمت ----
        function confirmAdjust(form) {
            var pct = form.adjust_percent.value;
            var dir = form.adjust_direction.value === 'decrease' ? 'کاهش' : 'افزایش';
            var cats = form.adjust_categories.value;
            var scope = cats ? 'دسته‌های ' + cats : 'همه محصولات';
            return confirm('آیا مطمئنید؟\n' + dir + ' ' + pct + '٪ برای ' + scope + ' اعمال می‌شود.');
        }

        // ---- AJAX form submission to admin-ajax (batched processing) ----
        (function(){
            function showNotice(type, msg) {
                var wrap = document.querySelector('.dpu-wrap');
                if (!wrap) return;
                var n = document.createElement('div');
                n.className = 'dpu-notice dpu-notice-' + (type === 'error' ? 'error' : (type === 'info' ? 'success' : 'success'));
                n.textContent = msg;
                wrap.insertBefore(n, wrap.firstChild);
                setTimeout(function(){ if (n && n.parentNode) n.parentNode.removeChild(n); }, type === 'info' ? 3000 : 8000);
            }

            function parseJsonResponse(res, text) {
                var ct = (res.headers.get('content-type') || '').toLowerCase();
                if (text && text.charAt(0) !== '{' && text.charAt(0) !== '[') {
                    var preview = text.replace(/\s+/g, ' ').substring(0, 120);
                    throw new Error('پاسخ سرور JSON نیست (HTTP ' + res.status + '): ' + preview);
                }
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error('JSON.parse: ' + e.message);
                }
            }

            function setFormBusy(form, busy) {
                form.querySelectorAll('button, input[type="submit"]').forEach(function(btn){
                    btn.disabled = busy;
                });
            }

            document.querySelectorAll('.dpu-ajax-form').forEach(function(form){
                form.addEventListener('submit', function(e){
                    e.preventDefault();
                    if (typeof form.onsubmit === 'function') {
                        try { if (form.onsubmit() === false) return; } catch (err) {}
                    }
                    var action = form.getAttribute('data-dpu-action');
                    if (!action) return;

                    var url = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                    var baseFd = new FormData(form);
                    var page = 1;
                    var runId = '';
                    setFormBusy(form, true);

                    function runBatch() {
                        var fd = new FormData();
                        baseFd.forEach(function(value, key){ fd.append(key, value); });
                        fd.set('dpu_batch_page', String(page));
                        if (runId) fd.set('dpu_run_id', runId);

                        return fetch(url + '?action=' + encodeURIComponent(action), {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {'X-Requested-With': 'XMLHttpRequest'},
                            body: fd
                        }).then(function(res){
                            return res.text().then(function(text){
                                return { res: res, json: parseJsonResponse(res, text) };
                            });
                        }).then(function(payload){
                            var json = payload.json;
                            if (!json || !json.success) {
                                var msg = 'خطا در عملیات';
                                if (json && json.data && json.data.message) msg = json.data.message;
                                throw new Error(msg);
                            }

                            var data = json.data || {};
                            if (data.done) {
                                showNotice('success', data.message || 'عملیات موفق');
                                return;
                            }

                            runId = data.run_id || runId;
                            page = data.next_page || (page + 1);
                            showNotice('info', data.message || ('در حال پردازش... ' + (data.total_changed || 0) + ' محصول تغییر کرد'));
                            return runBatch();
                        });
                    }

                    runBatch().catch(function(err){
                        showNotice('error', 'خطای شبکه: ' + (err && err.message ? err.message : err));
                    }).finally(function(){
                        setFormBusy(form, false);
                    });
                });
            });
        })();
        </script>
        <?php
    }
}

// فراخوانی init
DPU_Admin::init();
