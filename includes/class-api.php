<?php
if (! defined('ABSPATH')) exit;

class DPU_API {

    /**
     * دریافت قیمت دلار (با کش transient)
     */
    public static function get_dollar_price(bool $bypass_cache = false): float|false {
        $opts = DPU_Options::get();

        if (!$bypass_cache) {
            $cached = get_transient('dpu_current_dollar');
            if ($cached !== false) {
                DPU_Logger::log('Returning cached dollar price: ' . $cached);
                return (float) $cached;
            }
        }

        $api_url = trim($opts['api_url'] ?? '');
        $api_key = trim($opts['api_key'] ?? '');

        if (empty($api_url)) {
            $api_url = 'https://api.wp-hub.org/api/v1/usd';
        }

        $url = $api_url;
        $headers = [
            'User-Agent: Mozilla/5.0 (compatible; DollarPriceUpdater/' . DPU_VERSION . ')',
            'Accept: application/json',
        ];

        if (!empty($api_key)) {
            $headers[] = 'X-API-Key: ' . $api_key;
            // Also append query parameter for maximum compatibility (?key= or &key=)
            if (strpos($url, 'key=') === false && strpos($url, 'api_key=') === false) {
                $separator = (strpos($url, '?') !== false) ? '&' : '?';
                $url .= $separator . 'key=' . urlencode($api_key);
            }
        }

        DPU_Logger::log('Fetching dollar price from: ' . $url);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $result    = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err       = curl_error($ch);
        curl_close($ch);

        if ($err) {
            DPU_Logger::log('cURL error: ' . $err);
            DPU_Telegram::send("❌ DPU Alert\nخطا در دریافت قیمت دلار: " . $err);
            return false;
        }

        if ($http_code !== 200) {
            DPU_Logger::log('API HTTP code: ' . $http_code);
            DPU_Telegram::send("❌ DPU Alert\nکد HTTP ناموفق: " . $http_code);
            return false;
        }

        if (empty($result)) {
            DPU_Logger::log('Empty result from API');
            DPU_Telegram::send("❌ DPU Alert\nAPI پاسخ خالی داد.");
            return false;
        }

        $data = json_decode($result, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            DPU_Logger::log('JSON error: ' . json_last_error_msg());
            DPU_Telegram::send("❌ DPU Alert\nJSON decode error: " . json_last_error_msg());
            return false;
        }

        $price = self::parse_usd($data);
        if ($price !== false) {
            if (!$bypass_cache) {
                $ttl = !empty($opts['cache_ttl']) ? (int) $opts['cache_ttl'] : 1800;
                set_transient('dpu_current_dollar', $price, $ttl);
            }
            DPU_Logger::log('Dollar price fetched: ' . $price);
            return $price;
        }

        DPU_Logger::log('USD not found in API response.');
        DPU_Telegram::send("❌ DPU Alert\nUSD در پاسخ API یافت نشد.");
        return false;
    }

    /**
     * تجزیه هوشمند نرخ دلار از ساختارهای مختلف JSON
     */
    private static function parse_usd(array $data): float|false {
        // 1. فرمت اختصاصی سرور WP-Hub یا APIهای مستقیم (Top-level price)
        if (isset($data['price']) && is_numeric($data['price'])) {
            $p = floatval($data['price']);
            if ($p > 0) return $p;
        }

        // 2. فرمت بسته‌بندی شده در data (مثال: {"data": {"price": ...}})
        if (isset($data['data']) && is_array($data['data'])) {
            if (isset($data['data']['price']) && is_numeric($data['data']['price'])) {
                $p = floatval($data['data']['price']);
                if ($p > 0) return $p;
            }
        }

        // 3. فرمت آرایه‌ای / لیست مارکت (BRS API و سایر سرویس‌ها)
        $sources = [$data];
        if (isset($data['currency']) && is_array($data['currency'])) {
            $sources[] = $data['currency'];
        }
        if (isset($data['sources']) && is_array($data['sources'])) {
            $sources[] = array_values($data['sources']);
        }

        foreach ($sources as $list) {
            if (!is_array($list)) continue;
            foreach ($list as $item) {
                if (!is_array($item)) continue;

                // مطابقت کلید symbol با USD
                if (
                    isset($item['symbol'], $item['price']) &&
                    strtoupper($item['symbol']) === 'USD'
                ) {
                    $p = floatval($item['price']);
                    if ($p > 0) return $p;
                }

                // مطابقت با price_toman
                if (isset($item['price_toman']) && is_numeric($item['price_toman'])) {
                    $p = floatval($item['price_toman']);
                    if ($p > 0) return $p;
                }
            }
        }

        return false;
    }
}
