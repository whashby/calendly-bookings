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
        add_action('admin_notices', [$this, 'maybe_show_notice']);
    }

    private function get_header($header)
    {
        $data = get_file_data($this->file, [$header => $header]);
        return $data[$header] ?? '';
    }

    private function api_request($endpoint)
    {
        if (empty($this->repo)) return false;

        $url = rtrim($this->repo, '/') . '/' . ltrim($endpoint, '/');
        $response = wp_remote_get($url, [
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'calendly-bookings-updater',
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) return false;
        if (wp_remote_retrieve_response_code($response) !== 200) return false;

        return json_decode(wp_remote_retrieve_body($response));
    }

    public function check_update($transient)
    {
        if (empty($transient->checked) || empty($this->plugin)) return $transient;

        $api = $this->api_request('releases/latest');
        if (!$api || empty($api->tag_name)) return $transient;

        $new_version     = ltrim($api->tag_name, 'v');
        $current_version = $transient->checked[$this->plugin] ?? null;

        if (!$current_version || version_compare($new_version, $current_version, '<=')) return $transient;

        $package = $api->zipball_url ?? '';
        if (empty($package)) return $transient;

        $transient->response[$this->plugin] = (object) [
            'slug'        => $this->basename,
            'plugin'      => $this->plugin,
            'new_version' => $new_version,
            'url'         => $this->repo,
            'package'     => $package,
        ];

        return $transient;
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
        if (!empty($data['valid']) && !empty($data['token'])) {
            $decrypted = cb_decrypt_token($data['token'], LICENSE_SECRET);
            if ($decrypted) {
                update_option($this->token_option, $decrypted, false);
            } else {
                update_option($this->token_option, '', false);
                set_transient('cb_token_error', 'Failed to decrypt GitHub token. Check LICENSE_SECRET.', 3600);
            }
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

        $license = get_option(CB_LICENSE_OPTION);
        $token   = get_option(CB_TOKEN_OPTION);

        if (!empty($license) && empty($token)) {
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=cb_refresh_github_token'),
                'cb_refresh_github_token'
            );
            echo '<div class="notice notice-info"><p>'
                . esc_html__('Calendly Bookings license is set.', 'calendly-bookings')
                . ' <a href="' . esc_url($url) . '">'
                . esc_html__('Refresh GitHub token now', 'calendly-bookings')
                . '</a></p></div>';
        }

        $error = get_transient('cb_token_error');
        if ($error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
            delete_transient('cb_token_error');
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
