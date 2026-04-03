<?php
namespace Calendly_Bookings;

if (!defined('ABSPATH')) {
    exit;
}

use Calendly_Bookings\CB_Constants;

/**
 * Handles secure GitHub update checks and token management.
 */
final class CB_GitHub_Updater
{
    private static $instance = null;

    private $file;
    private $plugin;
    private $basename;
    private $repo;
    private $worker_endpoint;
    private $license_option;
    private $token_option;

    /** Singleton accessor */
    public static function instance($file = null)
    {
        if (self::$instance === null && $file !== null) {
            self::$instance = new self($file);
        }
        return self::$instance;
    }

    /** Private constructor */
    private function __construct($file)
    {
        $this->file     = $file;
        $this->plugin   = plugin_basename($file);
        $this->basename = dirname($this->plugin);

        $this->repo            = 'https://github.com/' . CB_Constants::GITHUB_REPO;
        $this->worker_endpoint = CB_Constants::CB_WORKER_ENDPOINT;
        $this->license_option  = CB_Constants::OPT_LICENSE_KEY;
        $this->token_option    = CB_Constants::GITHUB_TOKEN_OPTION;

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_filter('http_request_args', [$this, 'inject_github_auth_header'], 10, 2);

        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_cb_refresh_github_token', [$this, 'handle_token_refresh']);
        add_action('admin_notices', [$this, 'maybe_show_notice']);
    }

    /** Retrieve latest release info from GitHub */
    private function api_request($endpoint)
    {
        $url = rtrim(CB_Constants::GITHUB_API_URL, '/') . '/' . ltrim($endpoint, '/');
        $response = wp_remote_get($url, [
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => CB_Constants::API_USER_AGENT,
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) return false;
        if (wp_remote_retrieve_response_code($response) !== 200) return false;

        return json_decode(wp_remote_retrieve_body($response));
    }

    /** Check for plugin updates */
    public function check_update($transient)
    {
        if (empty($transient->checked) || empty($this->plugin)) return $transient;

        $this->get_token(); // ensure token exists

        $api = $this->api_request('releases/latest');
        if (!$api || empty($api->tag_name)) return $transient;

        $new_version     = ltrim($api->tag_name, 'v');
        $current_version = $transient->checked[$this->plugin] ?? null;

        if (!$current_version || version_compare($new_version, $current_version, '<=')) return $transient;

        $package = $api->zipball_url ?? '';
        if (empty($package)) return $transient;

        $transient->response[$this->plugin] = (object)[
            'slug'        => $this->basename,
            'plugin'      => $this->plugin,
            'new_version' => $new_version,
            'url'         => $this->repo,
            'package'     => $package,
        ];

        return $transient;
    }

    /** Retrieve or refresh token */
    private function get_token()
    {
        $token = get_option($this->token_option, '');
        if (!empty($token)) return $token;

        $this->request_and_store_token();
        return get_option($this->token_option, '');
    }

    /** Request token from Worker and store securely */
    private function request_and_store_token()
    {
        if (empty($this->worker_endpoint)) return;
        $license = get_option($this->license_option, '');
        if (empty($license)) return;

        $response = wp_remote_post($this->worker_endpoint, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['license' => $license]),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) return;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['valid']) || empty($data['token'])) {
            set_transient('cb_token_error', __('Failed to retrieve GitHub token.', 'calendly-bookings'), 3600);
            return;
        }

        $key = get_option(CB_Constants::OPT_ENCRYPTION_KEY);
        $token = !empty($key) ? cb_decrypt_token($data['token'], $key) : $data['token'];
        update_option($this->token_option, $token ?: $data['token'], false);
    }

    /** Inject decrypted token into GitHub requests */
    public function inject_github_auth_header($args, $url)
    {
        if (strpos($url, 'github.com') === false && strpos($url, 'api.github.com') === false) return $args;

        $token = get_option($this->token_option, '');
        $key   = get_option(CB_Constants::OPT_ENCRYPTION_KEY);

        if (!empty($key) && !empty($token)) {
            $decrypted = cb_decrypt_token($token, $key);
            if ($decrypted) $token = $decrypted;
        }

        if (empty($token)) return $args;

        $args['headers']['Authorization'] = 'token ' . $token;
        $args['headers']['Accept']        = 'application/vnd.github+json';
        return $args;
    }

    /** Manual refresh handler */
    public function handle_token_refresh()
    {
        if (!current_user_can('manage_options')) wp_die(__('Unauthorized', 'calendly-bookings'));
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'cb_refresh_github_token')) {
            wp_die(__('Invalid nonce', 'calendly-bookings'));
        }

        $this->request_and_store_token();
        set_transient('cb_token_success', __('GitHub token refreshed successfully.', 'calendly-bookings'), 30);
        wp_safe_redirect(add_query_arg('page', 'calendly-bookings', admin_url('options-general.php')));
        exit;
    }

    /** Settings page */
    public function add_settings_page()
    {
        add_options_page(
            __('Calendly Bookings', 'calendly-bookings'),
            __('Calendly Bookings', 'calendly-bookings'),
            'manage_options',
            'calendly-bookings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings()
    {
        register_setting('calendly_bookings', $this->license_option, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);
    }

    /** Render settings UI */
    public function render_settings_page()
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Calendly Bookings Settings', 'calendly-bookings'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('calendly_bookings');
                $license = get_option($this->license_option, '');
                $latest  = $this->get_latest_plugin_version();
                $current = $this->get_current_plugin_version();
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('License Key', 'calendly-bookings'); ?></th>
                        <td>
                            <input type="text"
                                   name="<?php echo esc_attr($this->license_option); ?>"
                                   value="<?php echo esc_attr($license); ?>"
                                   class="regular-text" />
                            <p class="description">
                                <?php esc_html_e('Enter your license key to enable private GitHub updates.', 'calendly-bookings'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Update Status', 'calendly-bookings'); ?></th>
                        <td>
                            <?php if (!empty($latest) && version_compare($latest, $current, '>')): ?>
                                <p style="color:#0073aa;">
                                    <strong><?php esc_html_e('New version available:', 'calendly-bookings'); ?></strong>
                                    <?php echo esc_html($latest); ?>
                                    <?php esc_html_e('(current:', 'calendly-bookings'); ?>
                                    <?php echo esc_html($current); ?>)
                                </p>
                            <?php else: ?>
                                <p style="color:#008000;">
                                    ✓ <?php esc_html_e('You have the latest version installed.', 'calendly-bookings'); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /** Admin notices */
    public function maybe_show_notice()
    {
        if (!current_user_can('manage_options')) return;

        $license = get_option($this->license_option);
        $token   = get_option($this->token_option);

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

    /** Get current plugin version from plugin header */
    private function get_current_plugin_version(): string
    {
        $data = get_plugin_data($this->file, false, false);
        return $data['Version'] ?? '0.0.0';
    }

    /** Get latest plugin version from GitHub */
    private function get_latest_plugin_version(): ?string
    {
        $api = $this->api_request('releases/latest');
        if (!$api || empty($api->tag_name)) {
            return null;
        }
        return ltrim($api->tag_name, 'v');
    }

}


/**
 * Decrypt AES-GCM encrypted token from Worker.
 * @param string $encrypted Base64-encoded JSON string from Worker.
 * @param string $secret    Encryption key (must be 16, 24, or 32 bytes).
 * @return string|null      Decrypted GitHub installation token or null on failure.
 */
function cb_decrypt_token($encrypted, $secret)
{
    if (empty($secret) || empty($encrypted)) {
        return null;
    }

    try {
        $decoded = base64_decode($encrypted, true);
        if ($decoded === false) return null;

        $json = json_decode($decoded, true);
        if (!$json || !isset($json['iv'], $json['data'], $json['tag'])) return null;

        $iv   = pack('C*', ...$json['iv']);
        $data = pack('C*', ...$json['data']);
        $tag  = pack('C*', ...$json['tag']);

        return openssl_decrypt($data, 'aes-256-gcm', $secret, OPENSSL_RAW_DATA, $iv, $tag) ?: null;
    } catch (\Exception $e) {
        error_log('CB Token Decrypt Error: ' . $e->getMessage());
        return null;
    }
}