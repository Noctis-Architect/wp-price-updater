<?php
if (! defined('ABSPATH')) exit;

/**
 * لاگ + Snapshot روزانه قیمت‌ها
 * Snapshot‌ها در: wp-content/dpu-snapshots/YYYY-MM-DD.json ذخیره می‌شوند
 */
class DPU_Logger {

    const LOG_FILE      = 'dpu-log.txt';
    const SNAPSHOT_DIR  = 'dpu-snapshots';

    // -------------------- لاگ عمومی --------------------

    public static function log(string $msg): void {
        $opts = DPU_Options::get();
        if (empty($opts['enable_log'])) return;

        self::write_line($msg);
    }

    /**
     * نوشتن در فایل لاگ — بدون بررسی enable_log (برای خطاهای بحرانی)
     */
    public static function log_critical(string $msg): void {
        self::write_line($msg);
    }

    private static function write_line(string $msg): void {
        $line = date('Y-m-d H:i:s') . ' — ' . $msg . PHP_EOL;
        @file_put_contents(
            WP_CONTENT_DIR . '/' . self::LOG_FILE,
            $line,
            FILE_APPEND | LOCK_EX
        );
    }

    // -------------------- Snapshot روزانه --------------------

    /**
     * ذخیره snapshot قیمت تمام محصولات با قیمت‌دار
     * فرمت: [ product_id => [ 'title'=>..., 'price'=>... ], ... ]
     */
    public static function take_snapshot(float $dollar_price): string {
        global $wpdb;

        $dir = WP_CONTENT_DIR . '/' . self::SNAPSHOT_DIR;
        if (!file_exists($dir)) {
            @mkdir($dir, 0755, true);
        }

        $date     = date('Y-m-d');
        $time     = date('H:i:s');
        $filepath = $dir . '/' . $date . '.json';

        // preserve created timestamp if exists
        $created = date('Y-m-d H:i:s');
        if (file_exists($filepath)) {
            $existing = json_decode(@file_get_contents($filepath), true) ?: [];
            if (!empty($existing['created'])) $created = $existing['created'];
        }

        // Stream JSON to a temporary file in batches to avoid memory spikes
        $tmp = $filepath . '.tmp';
        $fh = fopen($tmp, 'w');
        if (!$fh) {
            self::log("Snapshot: could not open temp file {$tmp}");
            return $filepath;
        }

        // Write header
        fwrite($fh, '{"date":"' . $date . '","created":"' . $created . '","updated":"' . date('Y-m-d H:i:s') . '","dollar":' . json_encode($dollar_price) . ',"products":{');

        $batch = 500;
        $offset = 0;
        $first = true;
        $count = 0;

        // Query product IDs, titles and regular price directly from DB in batches for both simple products and variations
        $posts_table = $wpdb->posts;
        $pm_table    = $wpdb->postmeta;

        // We'll fetch posts of type 'product' and 'product_variation' that have a non-empty _regular_price > 0
        while (true) {
            $sql = $wpdb->prepare(
                "SELECT p.ID, p.post_title, pm.meta_value AS price
                 FROM {$posts_table} p
                 INNER JOIN {$pm_table} pm ON pm.post_id = p.ID AND pm.meta_key = %s
                 WHERE p.post_type IN ('product','product_variation')
                   AND p.post_status = 'publish'
                   AND CAST(pm.meta_value AS DECIMAL) > 0
                 ORDER BY p.ID ASC
                 LIMIT %d OFFSET %d",
                '_regular_price',
                $batch,
                $offset
            );

            $rows = $wpdb->get_results($sql, ARRAY_A);
            if (empty($rows)) break;

            foreach ($rows as $r) {
                $pid = intval($r['ID']);
                $price = is_null($r['price']) ? 0.0 : floatval($r['price']);
                if ($price <= 0) continue;

                $title = $r['post_title'] ?? '';

                if (!$first) fwrite($fh, ',');
                $first = false;

                // write product entry preserving original structure: [ id => { 'title': ..., 'price': ... } ]
                $entry = '"' . $pid . '":{' . '"title":' . json_encode($title) . ',"price":' . json_encode($price) . '}';
                fwrite($fh, $entry);
                $count++;
            }

            $offset += $batch;
        }

        // close products object and root
        fwrite($fh, '}}');
        fclose($fh);

        // replace final file atomically
        @rename($tmp, $filepath);

        // Count products for log by reading file and counting occurrences of ':{"price"' — lightweight
        $content = @file_get_contents($filepath);
        $count = 0;
        if ($content !== false) {
            $count = preg_match_all('/:\{"price"/', $content, $m);
        }

        self::log("Snapshot saved: {$date} | {$count} products | dollar={$dollar_price}");

        return $filepath;
    }

    /**
     * لیست تمام snapshot‌های موجود (نزولی)
     */
    public static function list_snapshots(): array {
        $dir = WP_CONTENT_DIR . '/' . self::SNAPSHOT_DIR;
        if (!is_dir($dir)) return [];

        $files = glob($dir . '/*.json');
        if (empty($files)) return [];

        $list = [];
        foreach ($files as $f) {
            $data = json_decode(file_get_contents($f), true);
            if (!$data) continue;
            $list[] = [
                'date'     => $data['date'] ?? basename($f, '.json'),
                'dollar'   => $data['dollar'] ?? 0,
                'count'    => isset($data['products']) ? count($data['products']) : 0,
                'updated'  => $data['updated'] ?? '',
                'file'     => $f,
            ];
        }

        usort($list, fn($a, $b) => strcmp($b['date'], $a['date']));
        return $list;
    }

    /**
     * بارگزاری یک snapshot خاص
     */
    public static function load_snapshot(string $date): ?array {
        $file = WP_CONTENT_DIR . '/' . self::SNAPSHOT_DIR . '/' . $date . '.json';
        if (!file_exists($file)) return null;

        $data = json_decode(file_get_contents($file), true);
        return $data ?: null;
    }

    public static function log_file_path(): string {
        return WP_CONTENT_DIR . '/' . self::LOG_FILE;
    }
}
