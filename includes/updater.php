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

    private const GITHUB_API_URL = CB_Constants::GITHUB_API_URL;
    private const WORKER = CB_Constants::CB_WORKER_ENDPOINT;
    private const REPO = 'https://github.com/' . CB_Constants::GITHUB_REPO;
    private const API_USER_AGENT = CB_Constants::API_USER_AGENT;
    private const TOKEN_OPTION = CB_Constants::GITHUB_TOKEN_OPTION;
    private const LICENSE_OPTION = CB_Constants::OPT_LICENSE_KEY;

    private string $basename;
    private string $file;
    private string $plugin;
    private string $license;
    private string $token;


    public static function init(): void {
        CB_Audit_Log::log('init', 'updater', '', [], 'info');
        try {
            self::instance(CB_Constants::plugin_file());
            add_action('admin_init', [self::instance(), 'request_and_store_token']);
            add_action('admin_init', [self::instance(), 'check_for_updates']);
            add_action('admin_post_cb_refresh_github_token', [self::instance(), 'handle_token_refresh']);
            add_action('admin_notices', [self::instance(), 'show_admin_notices']);
            add_filter('pre_set_site_transient_update_plugins', [self::instance(), 'check_for_updates']);
            add_filter('plugins_api', [self::instance(), 'plugin_info'], 10, 3);
            add_filter('http_request_args', [self::instance(), 'inject_github_auth_header'], 10, 2);
            CB_Audit_Log::log('init_success', 'updater', '', [], 'info');
        } catch (\Exception $e) {
            CB_Audit_Log::log('init_error', 'updater', '', ['error' => $e->getMessage()], 'error');
        }
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
        CB_Audit_Log::log('constructor', 'updater', $this->plugin, ['license_set' => !empty($this->license)], 'info');

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_updates']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_filter('http_request_args', [$this, 'inject_github_auth_header'], 10, 2);

        add_action('admin_init', [$this, 'request_and_store_token']);
        add_action('admin_init', [$this, 'check_for_updates']);
        add_action('admin_post_cb_refresh_github_token', [$this, 'handle_token_refresh']);
        add_action('admin_notices', [$this, 'show_admin_notices']);
        CB_Audit_Log::log('constructor_complete', 'updater', $this->plugin, ['license_set' => !empty($this->license)], 'info');
    }

    /** Request token from Worker and store securely */
    public function request_and_store_token(): void {
        $this->license = get_option(self::LICENSE_OPTION, '');
        if (empty($this->license)) {
            CB_Audit_Log::log('missing_license', 'worker', '', [], 'error');
            return;
        }

        CB_Audit_Log::log('request', 'worker', $this->license, ['endpoint' => self::WORKER], 'info');
        $response = wp_remote_post(self::WORKER, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['license' => $this->license]),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            CB_Audit_Log::log('error', 'worker', $this->license, ['error' => $response->get_error_message()], 'error');
            return;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = wp_remote_retrieve_body($response);
        $data   = json_decode($body, true);
        CB_Audit_Log::log('response', 'worker', $this->license, ['status' => $status, 'body' => $data], $status === 200 ? 'info' : 'warning');

        if (empty($data['valid']) || empty($data['token'])) {
            CB_Audit_Log::log('invalid', 'worker', $this->license, ['data' => $data], 'error');
            set_transient('cb_token_error', __('Failed to retrieve GitHub token.', 'calendly-bookings'), 3600);
            return;
        }

        $this->token = $data['token'];
        CB_Audit_Log::log('store_token', 'worker', $this->license, ['decrypted' => !empty($this->token)], $this->token ? 'info' : 'warning');
        update_option(self::TOKEN_OPTION, $this->token, false);
    }

    /** Check for plugin updates */
    public function check_for_updates($transient) {
        // Return #1: invalid transient or plugin
        if (empty($transient->checked) || empty($this->plugin)) {
            CB_Audit_Log::log('check_for_updates', 'updater', $this->plugin, ['message' => 'Invalid transient or plugin'], 'warning');
            return $transient;
        }

        CB_Audit_Log::log('check_for_updates', 'updater', $this->plugin, ['message' => 'Checking for updates'], 'info');
        $this->request_and_store_token(); // always refresh
        $this->get_token();

        $api = $this->api_request('releases/latest');
        if (!$api || empty($api->tag_name)) {
            CB_Audit_Log::log('check_for_updates', 'updater', $this->plugin, ['message' => 'Failed to retrieve API data'], 'warning');
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
            CB_Audit_Log::log('update_available', 'updater', $this->plugin, ['new_version' => $new_version], 'info');
            $transient->response[$this->plugin] = (object)[
                'slug'        => $this->basename,
                'plugin'      => $this->plugin,
                'new_version' => $new_version,
                'url'         => self::REPO,
                'package'     => $package,
            ];
        } else {
            CB_Audit_Log::log('update_not_available', 'updater', $this->plugin, [
                'new_version'     => $new_version,
                'current_version' => $current_version,
                'package'         => $package
            ], 'info');
        }

        return $transient; // Return #3: final return
    }

    /** Retrieve latest release info from GitHub */
    private function api_request($endpoint) {
        $url = rtrim(self::GITHUB_API_URL, '/') . '/' . ltrim($endpoint, '/');
        CB_Audit_Log::log(
            'request', 'api', $url, ['message' => 'Starting API request'], 'info'
        );

        $response = wp_remote_get($url, [
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => self::API_USER_AGENT,
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            CB_Audit_Log::log(
                'error', 'api', $url, ['error' => $response->get_error_message()], 'error'
            );
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        CB_Audit_Log::log(
            'response', 'api', $url, ['status' => $status], $status === 200 ? 'info' : 'warning'
        );

        if ($status !== 200) {
            CB_Audit_Log::log(
                'failure', 'api', $url, ['body' => wp_remote_retrieve_body($response)], 'error'
            );
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        CB_Audit_Log::log(
            'success', 'api', $url, ['body' => $body], 'info'
        );

        return json_decode($body);
    }

    /** Inject decrypted token into GitHub requests */
    public function inject_github_auth_header($args, $url) {
        if (strpos($url, 'github.com') === false && strpos($url, 'api.github.com') === false) {
            return $args;
        }

        $token = get_option(self::TOKEN_OPTION, '');
        if (empty($token)) {
            CB_Audit_Log::log('auth_header', 'github', $url, ['error' => 'No token available'], 'error');
            return $args;
        }

        CB_Audit_Log::log('auth_header', 'github', $url, ['message' => 'Injecting token'], 'info');

        $args['headers']['Authorization'] = 'token ' . $token;

        if (strpos($url, '/releases/assets/') !== false || strpos($url, '/releases/download/') !== false) {
            $args['headers']['Accept'] = 'application/octet-stream';
        } else {
            $args['headers']['Accept'] = 'application/vnd.github+json';
        }

        // NEW: log the exact URL WordPress is about to request
        CB_Audit_Log::log('http_request', 'github', $url, ['headers' => $args['headers']], 'info');

        return $args;
    }

    /** Manual refresh handler */
    public function handle_token_refresh() {
        if (!current_user_can('manage_options')) wp_die(__('Unauthorized', 'calendly-bookings'));
        CB_Audit_Log::log('manual_refresh', 'github', '', ['message' => 'Manual token refresh initiated'], 'info');

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'cb_refresh_github_token')) {
            wp_die(__('Invalid nonce', 'calendly-bookings'));
            CB_Audit_Log::log('manual_refresh', 'github', '', ['error' => 'Invalid nonce'], 'error');
        }

        CB_Audit_Log::log('manual_refresh', 'github', '', ['message' => 'Nonce verified, refreshing token'], 'info');
        $this->request_and_store_token();
        set_transient('cb_token_success', __('GitHub token refreshed successfully.', 'calendly-bookings'), 30);
        CB_Audit_Log::log('manual_refresh', 'github', '', ['message' => 'Token refresh complete'], 'info');

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
            CB_Audit_Log::log('get_token', 'github', '', ['token_exists' => true], 'info');
            return $this->token;
        }

        $this->request_and_store_token();
        CB_Audit_Log::log('get_token', 'github', '', ['token_exists' => !empty($this->token)], !empty($this->token) ? 'info' : 'warning');
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

}
