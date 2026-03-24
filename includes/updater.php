<?php
if (!defined('ABSPATH')) {
    exit;
}

class CB_GitHub_Updater
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

        $this->repo            = trim($this->get_header('GitHub Plugin URI'));
        $this->worker_endpoint = defined('CB_WORKER_ENDPOINT') ? CB_WORKER_ENDPOINT : '';
        $this->license_option  = defined('CB_LICENSE_OPTION') ? CB_LICENSE_OPTION : 'calendly_bookings_license_key';
        $this->token_option    = defined('CB_TOKEN_OPTION') ? CB_TOKEN_OPTION : 'calendly_bookings_token';

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_filter('http_request_args', [$this, 'inject_github_auth_header'], 10, 2);

        // Settings page + notices
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'maybe_handle_notice_dismiss']);
        add_action('admin_notices', [$this, 'maybe_show_notice']);
    }

    private function get_header($header)
    {
        $data = get_file_data($this->file, [$header => $header]);
        return $data[$header] ?? '';
    }

    private function api_request($endpoint)
    {
        if (empty($this->repo)) {
            error_log('CB Updater: Repo is empty');
            return false;
        }

        $url = rtrim($this->repo, '/') . '/' . ltrim($endpoint, '/');
        error_log('CB Updater: Making API request to: ' . $url);

        $response = wp_remote_get($url, [
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'calendly-bookings-updater',
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            error_log('CB Updater: API request error: ' . $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        error_log('CB Updater: API response status: ' . $status_code);

        if ($status_code !== 200) {
            error_log('CB Updater: API request failed with status: ' . $status_code);
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        error_log('CB Updater: API response body length: ' . strlen($body));

        return json_decode($body);
    }

    public function check_update($transient)
    {
        if (empty($transient->checked) || empty($this->plugin)) {
            error_log('CB Updater: Skipping update check - transient or plugin empty');
            return $transient;
        }

        error_log('CB Updater: Starting update check for plugin: ' . $this->plugin);

        $this->get_token(); // ensure we have auth for GitHub API when needed

        $api = $this->api_request('releases/latest');
        if (!$api || empty($api->tag_name)) {
            error_log('CB Updater: API request failed or no tag_name found');
            return $transient;
        }

        $new_version     = ltrim($api->tag_name, 'v');
        $current_version = $transient->checked[$this->plugin] ?? null;

        error_log('CB Updater: Current version: ' . $current_version . ', New version: ' . $new_version);

        if (!$current_version || version_compare($new_version, $current_version, '<=')) {
            error_log('CB Updater: No update needed');
            return $transient;
        }

        $package = $api->zipball_url ?? '';
        if (empty($package)) {
            error_log('CB Updater: No package URL found');
            return $transient;
        }

        error_log('CB Updater: Update available, adding to transient');

        $transient->response[$this->plugin] = (object) [
            'slug'        => $this->basename,
            'plugin'      => $this->plugin,
            'new_version' => $new_version,
            'url'         => $this->repo,
            'package'     => $package,
        ];

        return $transient;
    }

    public function maybe_handle_notice_dismiss()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (isset($_GET['cb_dismiss_license_notice']) && check_admin_referer('cb_dismiss_license_notice')) {
            update_option('cb_hide_license_notice', '1', false);
            wp_safe_redirect(remove_query_arg(['cb_dismiss_license_notice', '_wpnonce'], wp_get_referer() ?: admin_url()));
            exit;
        }
    }

    private function get_current_plugin_version(): string
    {
        $data = get_plugin_data($this->file, false, false);
        return $data['Version'] ?? '0.0.0';
    }

    private function get_latest_plugin_version(): ?string
    {
        $api = $this->api_request('releases/latest');
        if (!$api || empty($api->tag_name)) {
            return null;
        }
        return ltrim($api->tag_name, 'v');
    }

    public function plugin_info($result, $action, $args)
    {
        if ($action !== 'plugin_information' || $args->slug !== $this->basename) return $result;

        $api = $this->api_request('releases/latest');
        if (!$api || empty($api->tag_name)) return $result;

        return (object) [
            'name'          => $this->basename,
            'slug'          => $this->basename,
            'version'       => ltrim($api->tag_name, 'v'),
            'author'        => 'Wafiq Harris-Ashby',
            'homepage'      => $this->repo,
            'download_link' => $api->zipball_url ?? '',
            'sections'      => [
                'description' => $api->body ?? 'No description.',
                'changelog'   => $api->body ?? '',
            ],
        ];
    }

    public function inject_github_auth_header($args, $url)
    {
        if (empty($this->repo)) return $args;
        if (strpos($url, 'github.com') === false && strpos($url, 'api.github.com') === false) return $args;

        $token = $this->get_token();
        if (empty($token)) return $args;

        $args['headers']['Authorization'] = 'token ' . $token;
        $args['headers']['Accept']        = 'application/vnd.github+json';
        return $args;
    }

    /** Public entry point for manual refresh */
    public function refresh_token()
    {
        $this->request_and_store_token();
        // Set success transient if token was set
        if (!empty(get_option($this->token_option, ''))) {
            set_transient('cb_token_success', __('GitHub token refreshed successfully.', 'calendly-bookings'), 30);
        }
    }

    private function get_token()
    {
        $token = get_option($this->token_option, '');
        if (!empty($token)) return $token;

        $this->request_and_store_token();
        return get_option($this->token_option, '');
    }

    private function request_and_store_token()
    {
        if (empty($this->worker_endpoint)) {
            error_log('CB Updater: Worker endpoint is empty');
            return;
        }
        $license = get_option($this->license_option, '');
        if (empty($license)) {
            error_log('CB Updater: License key is empty');
            return;
        }

        error_log('CB Updater: Requesting token for license: ' . substr($license, 0, 8) . '...');

        $response = wp_remote_post($this->worker_endpoint, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['license' => $license]),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            error_log('CB Updater: Worker request failed: ' . $response->get_error_message());
            return;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        error_log('CB Updater: Worker response status: ' . $status_code);

        $body = wp_remote_retrieve_body($response);
        error_log('CB Updater: Worker response body: ' . substr($body, 0, 200) . '...');

        $data = json_decode($body, true);
        if (!empty($data['valid']) && !empty($data['token'])) {
            error_log('CB Updater: Token received, attempting decryption');
            $decrypted = cb_decrypt_token($data['token'], LICENSE_SECRET);
            if ($decrypted) {
                update_option($this->token_option, $decrypted, false);
                error_log('CB Updater: Token decrypted and stored successfully');
            } else {
                update_option($this->token_option, '', false);
                set_transient('cb_token_error', 'Failed to decrypt GitHub token. Check LICENSE_SECRET.', 3600);
                error_log('CB Updater: Token decryption failed - check LICENSE_SECRET');
            }
        } else {
            error_log('CB Updater: Invalid response from worker - missing valid/token fields');
        }
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
        register_setting('calendly_bookings', CB_LICENSE_OPTION, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);
    }

    public function render_settings_page()
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Calendly Bookings Settings', 'calendly-bookings'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('calendly_bookings');
                $value = get_option(CB_LICENSE_OPTION, '');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('License Key', 'calendly-bookings'); ?></th>
                        <td>
                            <input type="text" name="<?php echo esc_attr(CB_LICENSE_OPTION); ?>"
                                   value="<?php echo esc_attr($value); ?>" class="regular-text"/>
                            <p class="description">
                                <?php esc_html_e('Enter your license key to enable private GitHub updates.', 'calendly-bookings'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /** Notices */
    public function maybe_show_notice()
    {
        if (!current_user_can('manage_options')) return;
        if (get_option('cb_hide_license_notice', false)) return;

        $license = get_option(CB_LICENSE_OPTION);
        $token   = get_option(CB_TOKEN_OPTION);

        $dismiss_url = wp_nonce_url(add_query_arg('cb_dismiss_license_notice', '1', admin_url()), 'cb_dismiss_license_notice');

        if (!empty($license) && empty($token)) {
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=cb_refresh_github_token'),
                'cb_refresh_github_token'
            );
            echo '<div class="notice notice-info notice-dismissible"><p>'
                . esc_html__('Calendly Bookings license is set.', 'calendly-bookings')
                . ' <a href="' . esc_url($url) . '">' 
                . esc_html__('Refresh GitHub token now', 'calendly-bookings')
                . '</a> '
                . ' <a href="' . esc_url($dismiss_url) . '">' . esc_html__('Dismiss', 'calendly-bookings') . '</a>'
                . '</p></div>';
            return;
        }

        // Check for new release version
        $latest_version = $this->get_latest_plugin_version();
        $current_version = $this->get_current_plugin_version();

        if (!empty($latest_version) && version_compare($latest_version, $current_version, '>')) {
            $update_url = admin_url('update-core.php');
            echo '<div class="notice notice-warning notice-dismissible"><p>'
                . sprintf(
                    esc_html__('Calendly Bookings new version available: %1$s (current %2$s).', 'calendly-bookings'),
                    esc_html($latest_version),
                    esc_html($current_version)
                )
                . ' <a href="' . esc_url($update_url) . '">' . esc_html__('Update now', 'calendly-bookings') . '</a>'
                . ' <a href="' . esc_url($dismiss_url) . '">' . esc_html__('Dismiss', 'calendly-bookings') . '</a>'
                . '</p></div>';
            return;
        }

        $error = get_transient('cb_token_error');
        if ($error) {
            echo '<div class="notice notice-error notice-dismissible"><p>' . esc_html($error) . '</p>'
                . ' <a href="' . esc_url($dismiss_url) . '">' . esc_html__('Dismiss', 'calendly-bookings') . '</a>'
                . '</p></div>';
            delete_transient('cb_token_error');
        }

        $success = get_transient('cb_token_success');
        if ($success) {
            echo '<div class="notice notice-success notice-dismissible"><p>' . esc_html($success) . '</p>'
                . ' <a href="' . esc_url($dismiss_url) . '">' . esc_html__('Dismiss', 'calendly-bookings') . '</a>'
                . '</p></div>';
            delete_transient('cb_token_success');
        }
    }
}

/**
 * Decrypt AES-GCM encrypted token from Worker.
 *
 * @param string $encrypted Base64-encoded JSON string from Worker.
 * @param string $secret    LICENSE_SECRET (must be 16, 24, or 32 bytes).
 * @return string|null      Decrypted GitHub installation token or null on failure.
 */
function cb_decrypt_token($encrypted, $secret)
{
    $decoded = base64_decode($encrypted);
    $json    = json_decode($decoded, true);

    if (!$json || !isset($json['iv'], $json['data'], $json['tag'])) return null;

    $iv   = pack('C*', ...$json['iv']);
    $data = pack('C*', ...$json['data']);
    $tag  = pack('C*', ...$json['tag']);

    return openssl_decrypt($data, 'aes-256-gcm', $secret, OPENSSL_RAW_DATA, $iv, $tag) ?: null;
}
