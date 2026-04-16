<?php
namespace Calendly_Bookings;

if (!defined('ABSPATH')) {
    exit;
}

use Calendly_Bookings\CB_Constants;
use Calendly_Bookings\Modules\CB_Audit_Log;

/**
 * Handles secure GitHub update checks and token management.
 */
final class CB_GitHub_Updater
{
    private static $instance = null;

    private const WORKER = CB_Constants::CB_WORKER_ENDPOINT;
    private const REPO = CB_Constants::GITHUB_REPO_URL;
    private const GITHUB_API_REPO_URL = CB_Constants::GITHUB_API_REPO_URL;
    private const API_USER_AGENT = CB_Constants::API_USER_AGENT;
    private const TOKEN_OPTION = CB_Constants::OPT_GITHUB_TOKEN;
    private const LICENSE_OPTION = CB_Constants::OPT_LICENSE_KEY;

    private string $basename;
    private string $file;
    private string $plugin;
    private string $license;
    private string $token;


    public static function init(): void {
        self::instance(CB_Constants::plugin_file());
        add_action('admin_init', [self::instance(), 'request_and_store_token']);
        add_action('admin_init', [self::instance(), 'check_for_updates']);
        add_action('admin_post_cb_refresh_github_token', [self::instance(), 'handle_token_refresh']);
        add_action('admin_notices', [self::instance(), 'show_admin_notices']);
        add_filter('pre_set_site_transient_update_plugins', [self::instance(), 'check_for_updates']);
        add_filter('plugins_api', [self::instance(), 'plugin_info'], 10, 3);
        add_filter('http_request_args', [self::instance(), 'inject_github_auth_header'], 10, 2);
    }

    /** Singleton accessor */
    public static function instance($file = null): self {
        if (self::$instance === null && $file !== null) {
            self::$instance = new self($file);
        }
        return self::$instance;
    }

    /** Private constructor */
    private function __construct($file) {
        $this->file = $file;
        $this->plugin   = plugin_basename($file);
        $this->basename = dirname($this->plugin);
        $this->license = get_option(self::LICENSE_OPTION) ?? '';
        $this->token   = get_option(self::TOKEN_OPTION) ?? '';

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_updates']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_filter('http_request_args', [$this, 'inject_github_auth_header'], 10, 2);

        add_action('admin_init', [$this, 'request_and_store_token']);
        add_action('admin_init', [$this, 'check_for_updates']);
        add_action('admin_post_cb_refresh_github_token', [$this, 'handle_token_refresh']);
        add_action('admin_notices', [$this, 'show_admin_notices']);
    }

    /** Request token from Worker and store securely */
    public function request_and_store_token(): void {
        $this->license = get_option(self::LICENSE_OPTION, '');
        if (empty($this->license)) {
            return;
        }

        $response = wp_remote_post(self::WORKER, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['license' => $this->license]),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = wp_remote_retrieve_body($response);
        $data   = json_decode($body, true);

        if (empty($data['valid']) || empty($data['token'])) {
            set_transient('cb_token_error', __('Failed to retrieve GitHub token.', 'calendly-bookings'), 3600);
            return;
        }

        $this->token = $data['token'];
        update_option(self::TOKEN_OPTION, $this->token, false);
    }

    /** Check for plugin updates */
    public function check_for_updates($transient) {
        // Return #1: invalid transient or plugin
        if (empty($transient->checked) || empty($this->plugin)) {
            return $transient;
        }

        $this->request_and_store_token(); // always refresh
        $this->get_token();

        $api = $this->api_request('releases/latest');
        if (!$api || empty($api->tag_name)) {
            return $transient; // Return #2: API failure
        }

        $new_version     = ltrim($api->tag_name, 'v');
        $current_version = $transient->checked[$this->plugin] ?? null;

        $package = '';
        if (!empty($api->assets) && is_array($api->assets)) {
            foreach ($api->assets as $asset) {
                if (!empty($asset->id) && $asset->name === 'calendly-bookings.zip') {
                    // Use the GitHub API release asset endpoint
                    $package = "https://api.github.com/repos/whashby/calendly-bookings/releases/assets/{$asset->id}";
                    break;
                }
            }
        }

        // Fallback only if asset not found
        if (empty($package)) {
            $package = $api->zipball_url ?? '';
        }

        $update_available = ($current_version && version_compare($new_version, $current_version, '>') && !empty($package));

        if ($update_available) {
            $transient->response[$this->plugin] = (object)[
                'slug'        => $this->basename,
                'plugin'      => $this->plugin,
                'new_version' => $new_version,
                'url'         => self::REPO,
                'package'     => $package,
            ];
        } else {
            unset($transient->response[$this->plugin]);
        }

        return $transient; // Return #3: final return
    }

    /** Retrieve latest release info from GitHub */
    private function api_request($endpoint) {
        $url = rtrim(self::GITHUB_API_REPO_URL, '/') . '/' . ltrim($endpoint, '/');

        $response = wp_remote_get($url, [
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => self::API_USER_AGENT,
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);

        if ($status !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);

        return json_decode($body);
    }

    /** Inject decrypted token into GitHub requests */
    public function inject_github_auth_header($args, $url) {
        if (strpos($url, 'github.com') === false && strpos($url, 'api.github.com') === false) {
            return $args;
        }

        $token = get_option(self::TOKEN_OPTION, '');
        if (empty($token)) {
            return $args;
        }


        $args['headers']['Authorization'] = 'token ' . $token;

        if (strpos($url, '/releases/assets/') !== false || strpos($url, '/releases/download/') !== false) {
            $args['headers']['Accept'] = 'application/octet-stream';
        } else {
            $args['headers']['Accept'] = 'application/vnd.github+json';
        }

        return $args;
    }

    /** Manual refresh handler */
    public function handle_token_refresh() {
        if (!current_user_can('manage_options')) wp_die(__('Unauthorized', 'calendly-bookings'));

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'cb_refresh_github_token')) {
            wp_die(__('Invalid nonce', 'calendly-bookings'));
        }

        $this->request_and_store_token();
        set_transient('cb_token_success', __('GitHub token refreshed successfully.', 'calendly-bookings'), 30);

        wp_safe_redirect(add_query_arg('page', 'calendly-bookings', admin_url('options-general.php')));
        exit;
    }

    /** Admin notices */
    public function show_admin_notices() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $license = get_option($this->license);
        $token   = get_option($this->token);

        if (!empty($license) && empty($token)) {
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=cb_refresh_github_token'),
                'cb_refresh_github_token'
            );
            echo '<div class="notice notice-info notice-dismissible"><p>'
                . esc_html__('Calendly Bookings license is set.', 'calendly-bookings')
                . ' <a href="' . esc_url($url) . '">'
                . esc_html__('Refresh GitHub token now', 'calendly-bookings')
                . '</a></p></div>';
            return;
        }

        $latest_version  = $this->get_latest_plugin_version();
        $current_version = $this->get_current_plugin_version();

        if (!empty($latest_version) && version_compare($latest_version, $current_version, '>')) {
            $update_url = admin_url('update-core.php');
            echo '<div class="notice notice-warning notice-dismissible"><p>'
                . sprintf(
                    esc_html__('Calendly Bookings update available: %1$s (current %2$s).', 'calendly-bookings'),
                    esc_html($latest_version),
                    esc_html($current_version)
                )
                . ' <a href="' . esc_url($update_url) . '">' . esc_html__('Update now', 'calendly-bookings') . '</a>'
                . '</p></div>';
            return;
        }

        $error = get_transient('cb_token_error');
        if ($error) {
            echo '<div class="notice notice-error notice-dismissible"><p>' . esc_html($error) . '</p></div>';
            delete_transient('cb_token_error');
        }

        $success = get_transient('cb_token_success');
        if ($success) {
            echo '<div class="notice notice-success notice-dismissible"><p>' . esc_html($success) . '</p></div>';
            delete_transient('cb_token_success');
        }
    }

    /** Retrieve or refresh token */
    private function get_token() {
        $this->token = get_option(self::TOKEN_OPTION, '');
        if(!empty($this->token)) {
            return $this->token;
        }

        $this->request_and_store_token();
        return get_option(self::TOKEN_OPTION, '');
    }

    /** Get current plugin version from plugin header */
    private function get_current_plugin_version(): string {
        $data = get_plugin_data($this->file, false, false);
        return $data['Version'] ?? '0.0.0';
    }

    /** Get latest plugin version from GitHub */
    private function get_latest_plugin_version(): ?string {
        $api = $this->api_request('releases/latest');
        if (!$api || empty($api->tag_name)) {
            return null;
        }
        return ltrim($api->tag_name, 'v');
    }

    private function plugin_info($res, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== $this->basename) {
            return $res;
        }

        $api = $this->api_request('releases/latest');
        if (!$api || empty($api->tag_name)) {
            return false;
        }

        $res = new \stdClass();
        $res->name = $api->name ?? 'Calendly Bookings';
        $res->version = ltrim($api->tag_name, 'v');
        $res->author = '<a href="https://whashby.github.io">Wafiq Harris-Ashby</a>';
        $res->homepage = self::REPO;
        $res->requires = '5.0';
        $res->tested = '6.4';
        $res->download_link = '';
        if (!empty($api->assets) && is_array($api->assets)) {
            foreach ($api->assets as $asset) {
                if (!empty($asset->id) && $asset->name === 'calendly-bookings.zip') {
                    $res->download_link = "https://api.github.com/repos/whashby/calendly-bookings/releases/assets/{$asset->id}";
                    break;
                }
            }
        }
        if (empty($res->download_link)) {
            $res->download_link = $api->zipball_url ?? '';
        }
        $res->sections = [
            'description' => wp_kses_post($api->body ?? ''),
            'changelog'   => wp_kses_post($api->body ?? ''),
        ];

        return $res;
    }

}
