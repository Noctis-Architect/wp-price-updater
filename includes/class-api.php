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

        $url = $opts['api_url'] . '?key=' . urlencode($opts['api_key']);
        DPU_Logger::log('Fetching dollar price from: ' . $url);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; DollarPriceUpdater/' . DPU_VERSION . ')',
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
                set_transient('dpu_current_dollar', $price, (int) $opts['cache_ttl']);
            }
            DPU_Logger::log('Dollar price fetched: ' . $price);
            return $price;
        }

        DPU_Logger::log('USD not found in API response.');
        DPU_Telegram::send("❌ DPU Alert\nUSD در پاسخ API یافت نشد.");
        return false;
    }

    private static function parse_usd(array $data): float|false {
        $sources = [$data];
        if (isset($data['currency']) && is_array($data['currency'])) {
            $sources[] = $data['currency'];
        }

        foreach ($sources as $list) {
            if (!is_array($list)) continue;
            foreach ($list as $item) {
                if (!is_array($item)) continue;
                if (
                    isset($item['symbol'], $item['price']) &&
                    strtoupper($item['symbol']) === 'USD'
                ) {
                    $p = floatval($item['price']);
                    if ($p > 0) return $p;
                }
            }
        }

        return false;
    }
}
