<?php
if (! defined('ABSPATH')) exit;

class DPU_Options {

    public static function defaults(): array {
        return [
            // API
            'api_key'        => '',
            'api_url'        => 'https://brsapi.ir/Api/Market/Gold_Currency.php',
            'cache_ttl'      => 3600,

            // زمان‌بندی
            'enable_auto_update' => 1,
            'enable_plugin_auto_update' => 0,
            'update_times'   => '00:00',

            // محاسبه
            'ratio'          => 0.5,
            'manual_percent' => 0,

            // دسته‌ها
            'limit_categories' => '',

            // لاگ
            'enable_log'     => 1,

            // تلگرام
            'telegram_mode'     => 'worker',   // 'direct' | 'worker'
            // حالت worker
            'telegram_webhook'  => '',
            'telegram_chat_id'  => '',
            // حالت direct
            'telegram_bot_token'     => '',
            'telegram_direct_chat_id' => '',

            // رند هوشمند
            'enable_rounding' => 1,
            'round_ranges' => [
                ['max' => 5000000,   'round' => 100000],
                ['max' => 10000000,  'round' => 500000],
                ['max' => 50000000,  'round' => 1000000],
                ['max' => 100000000, 'round' => 2000000],
                ['max' => 500000000, 'round' => 5000000],
                ['max' => PHP_INT_MAX, 'round' => 10000000],
            ],
        ];
    }

    public static function get(): array {
        return wp_parse_args(get_option('dpu_options', []), self::defaults());
    }

    public static function set(array $opts): void {
        update_option('dpu_options', $opts);
    }

    public static function update(string $key, $value): void {
        $opts = self::get();
        $opts[$key] = $value;
        self::set($opts);
    }
}
