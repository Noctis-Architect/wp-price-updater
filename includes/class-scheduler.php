<?php
if (! defined('ABSPATH')) exit;

class DPU_Scheduler {

    const HOOK = 'dpu_update_products_price_daily';

    public static function clear_all(): void {
        $crons = _get_cron_array();
        if (!is_array($crons)) return;

        foreach ($crons as $timestamp => $tasks) {
            foreach ($tasks as $hook => $args) {
                if (strpos($hook, self::HOOK) !== false) {
                    wp_unschedule_event($timestamp, $hook, reset($args)['args'] ?? []);
                }
            }
        }
    }

    public static function schedule_all(): void {
        self::clear_all();

        $opts  = DPU_Options::get();
        $times = array_map('trim', explode(',', $opts['update_times']));
        $tz    = new DateTimeZone('Asia/Tehran');

        foreach ($times as $t) {
            if (empty($t)) continue;
            if (!preg_match('/^(\d{1,2}):(\d{2})$/', $t, $m)) continue;

            $now       = new DateTime('now', $tz);
            $candidate = new DateTime('now', $tz);
            $candidate->setTime((int) $m[1], (int) $m[2], 0);
            if ($candidate <= $now) $candidate->modify('+1 day');

            $timestamp = $candidate->getTimestamp();

            if (!wp_next_scheduled(self::HOOK, [$t])) {
                wp_schedule_event($timestamp, 'daily', self::HOOK, [$t]);
                DPU_Logger::log("Scheduled update at {$t} (timestamp: {$timestamp})");
            }
        }
    }
}

// اجرای هوک کرون
add_action(DPU_Scheduler::HOOK, function(string $scheduled_time) {
    DPU_Logger::log("Cron triggered for: {$scheduled_time}");
    DPU_Updater::run('auto');
}, 10, 1);
