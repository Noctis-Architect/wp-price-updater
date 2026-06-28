<?php
if (! defined('ABSPATH')) exit;

/**
 * GitHub Releases self-updater for Dollar Price Updater PRO.
 * Checks Noctis-Architect/wp-price-updater releases and integrates with WP's native updater.
 */
class DPU_GitHub_Updater {

    const REPO           = 'Noctis-Architect/wp-price-updater';
    const CACHE_KEY      = 'dpu_github_latest';
    const CACHE_TTL      = 12 * HOUR_IN_SECONDS;
    const ASSET_NAME     = 'dollar-price-updater.zip';
    const PLUGIN_SLUG    = 'dollar-price-updater/dollar-price-updater.php';

    public static function init(): void {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'inject_update']);
        add_filter('plugins_api', [self::class, 'plugin_info'], 20, 3);
        add_filter('upgrader_source_selection', [self::class, 'fix_source_dir'], 10, 4);
        add_filter('auto_update_plugin', [self::class, 'maybe_auto_update'], 10, 2);
    }

    // -------------------- Public helpers --------------------

    public static function current_version(): string {
        return defined('DPU_VERSION') ? DPU_VERSION : '0.0.0';
    }

    public static function clear_cache(): void {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * @return array{version:string,tag:string,url:string,package:string,body:string,html_url:string}|null
     */
    public static function get_latest(bool $force = false): ?array {
        if (!$force) {
            $cached = get_transient(self::CACHE_KEY);
            if (is_array($cached) && !empty($cached['version'])) {
                return $cached;
            }
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::REPO . '/releases/latest',
            [
                'timeout' => 15,
                'headers' => [
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'DollarPriceUpdater/' . self::current_version(),
                ],
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['tag_name'])) {
            return null;
        }

        $version = ltrim((string) $data['tag_name'], 'vV');
        $package = self::resolve_package_url($data);

        $info = [
            'version'  => $version,
            'tag'      => (string) $data['tag_name'],
            'url'      => (string) ($data['html_url'] ?? ''),
            'package'  => $package,
            'body'     => (string) ($data['body'] ?? ''),
            'html_url' => (string) ($data['html_url'] ?? ''),
        ];

        set_transient(self::CACHE_KEY, $info, self::CACHE_TTL);

        return $info;
    }

    public static function latest_version(): ?string {
        $latest = self::get_latest();
        return $latest['version'] ?? null;
    }

    public static function has_update(): bool {
        $latest = self::get_latest();
        if (!$latest || empty($latest['version'])) {
            return false;
        }
        return version_compare($latest['version'], self::current_version(), '>');
    }

    // -------------------- WP hooks --------------------

    public static function inject_update($transient) {
        if (!is_object($transient)) {
            $transient = new stdClass();
        }

        $latest = self::get_latest();
        if (!$latest || empty($latest['version']) || empty($latest['package'])) {
            return $transient;
        }

        if (version_compare($latest['version'], self::current_version(), '<=')) {
            return $transient;
        }

        $basename = self::plugin_basename();

        $transient->response[$basename] = (object) [
            'slug'        => 'dollar-price-updater',
            'plugin'      => $basename,
            'new_version' => $latest['version'],
            'url'         => $latest['html_url'],
            'package'     => $latest['package'],
            'tested'      => '',
            'requires'    => '5.8',
            'requires_php'=> '7.4',
        ];

        return $transient;
    }

    public static function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== 'dollar-price-updater') {
            return $result;
        }

        $latest = self::get_latest();
        if (!$latest) {
            return $result;
        }

        return (object) [
            'name'          => 'Price Updater PRO',
            'slug'          => 'dollar-price-updater',
            'version'       => $latest['version'],
            'author'        => '<a href="https://github.com/Noctis-Architect">mr-noctis</a>',
            'homepage'      => 'https://github.com/' . self::REPO,
            'download_link' => $latest['package'],
            'sections'      => [
                'description' => 'به‌روز‌رسانی هوشمند قیمت محصولات ووکامرس بر اساس نرخ دلار.',
                'changelog'   => $latest['body'] !== '' ? wp_kses_post($latest['body']) : 'تغییرات در GitHub Releases.',
            ],
            'banners'       => [],
            'icons'         => [],
        ];
    }

    /**
     * Ensure extracted files land in the correct plugin directory.
     * Renames the upgrade temp folder to the plugin slug — WordPress moves it to wp-content/plugins/ itself.
     * Handles both flat zips and GitHub source zips with a wrapper folder.
     */
    public static function fix_source_dir($source, $remote_source, $upgrader, $hook_extra) {
        $basename = self::plugin_basename();
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $basename) {
            return $source;
        }

        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $slug      = self::plugin_slug();
        $main_file = 'dollar-price-updater.php';
        $source    = untrailingslashit($source);

        if ($wp_filesystem->exists(trailingslashit($source) . $main_file)) {
            return self::rename_source_dir($source, $slug);
        }

        $list = $wp_filesystem->dirlist($source);
        if (is_array($list)) {
            foreach ($list as $name => $entry) {
                if (($entry['type'] ?? '') !== 'd') {
                    continue;
                }
                $subdir = trailingslashit($source) . $name;
                if ($wp_filesystem->exists(trailingslashit($subdir) . $main_file)) {
                    $dest = trailingslashit(dirname($source)) . $slug;
                    $wp_filesystem->move($subdir, $dest, true);
                    $wp_filesystem->delete($source, true);
                    return $dest;
                }
            }
        }

        return $source;
    }

    public static function maybe_auto_update($update, $item) {
        if (!isset($item->plugin) || $item->plugin !== self::plugin_basename()) {
            return $update;
        }

        $opts = DPU_Options::get();
        return !empty($opts['enable_plugin_auto_update']);
    }

    // -------------------- Internal --------------------

    private static function plugin_basename(): string {
        return defined('DPU_PLUGIN_BASENAME') ? DPU_PLUGIN_BASENAME : self::PLUGIN_SLUG;
    }

    private static function plugin_slug(): string {
        $slug = dirname(self::plugin_basename());
        return ($slug === '.' || $slug === '') ? 'dollar-price-updater' : $slug;
    }

    private static function rename_source_dir(string $source, string $slug): string {
        global $wp_filesystem;

        if (basename($source) === $slug) {
            return $source;
        }

        $dest = trailingslashit(dirname($source)) . $slug;
        $wp_filesystem->move($source, $dest, true);
        return $dest;
    }

    /**
     * Prefer the flat release asset; fall back to zipball.
     */
    private static function resolve_package_url(array $data): string {
        if (!empty($data['assets']) && is_array($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                if (!is_array($asset)) continue;
                $name = (string) ($asset['name'] ?? '');
                if ($name === self::ASSET_NAME && !empty($asset['browser_download_url'])) {
                    return (string) $asset['browser_download_url'];
                }
            }
        }

        return (string) ($data['zipball_url'] ?? '');
    }
}
