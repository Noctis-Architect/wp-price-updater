<?php
if (! defined('ABSPATH')) exit;

/**
 * ارسال پیام تلگرام
 * دو حالت: direct (ربات مستقیم) | worker (Cloudflare Worker)
 */
class DPU_Telegram {

    const CHUNK_LIMIT = 3500;

    /**
     * ارسال پیام — حالت را از تنظیمات می‌خواند
     */
    public static function send(string $text): bool {
        $opts = DPU_Options::get();
        $mode = $opts['telegram_mode'] ?? 'worker';

        if ($mode === 'direct') {
            return self::send_direct($text, $opts);
        }
        return self::send_via_worker($text, $opts);
    }

    /**
     * ارسال متن بلند به چند پیام تقسیم شده
     */
    public static function send_chunked(string $text): bool {
        $len = mb_strlen($text, 'UTF-8');
        if ($len <= self::CHUNK_LIMIT) {
            return self::send($text);
        }

        $offset = 0;
        $part   = 1;
        while ($offset < $len) {
            $chunk = mb_substr($text, $offset, self::CHUNK_LIMIT, 'UTF-8');
            self::send("Part {$part}:\n" . $chunk);
            $offset += self::CHUNK_LIMIT;
            $part++;
        }
        return true;
    }

    // -------------------- حالت Worker --------------------

    private static function send_via_worker(string $text, array $opts): bool {
        $url     = trim($opts['telegram_webhook'] ?? '');
        $chat_id = trim($opts['telegram_chat_id'] ?? '');

        if (empty($url) || empty($chat_id)) {
            DPU_Logger::log('Telegram worker: URL یا chat_id تنظیم نشده.');
            return false;
        }

        $payload = wp_json_encode(['chat_id' => $chat_id, 'text' => $text]);

        return self::do_curl($url, $payload, ['Content-Type: application/json']);
    }

    // -------------------- حالت Direct Bot --------------------

    private static function send_direct(string $text, array $opts): bool {
        $token   = trim($opts['telegram_bot_token'] ?? '');
        $chat_id = trim($opts['telegram_direct_chat_id'] ?? '');

        if (empty($token) || empty($chat_id)) {
            DPU_Logger::log('Telegram direct: token یا chat_id تنظیم نشده.');
            return false;
        }

        $url     = "https://api.telegram.org/bot{$token}/sendMessage";
        $payload = wp_json_encode([
            'chat_id'    => $chat_id,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);

        return self::do_curl($url, $payload, ['Content-Type: application/json']);
    }

    // -------------------- cURL مشترک --------------------

    private static function do_curl(string $url, string $payload, array $headers): bool {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $err      = curl_error($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            DPU_Logger::log('Telegram cURL error: ' . $err);
            return false;
        }

        DPU_Logger::log("Telegram HTTP {$status}: " . substr($response, 0, 200));
        return $status >= 200 && $status < 300;
    }
}
