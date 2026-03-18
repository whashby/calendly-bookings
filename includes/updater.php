<?php
// includes/updater.php

if (!defined('ABSPATH')) {
    exit;
}

class CB_GitHub_Updater
{
    private $file;
    private $plugin;
    private $basename;
    private $active;
    private $repo;

    private $worker_endpoint;
    private $license_option;
    private $token_option;

    public function __construct($file)
    {
        $this->file     = $file;
        $this->plugin   = plugin_basename($file);
        $this->basename = dirname($this->plugin);
        $this->active   = is_plugin_active($this->plugin);

        $this->repo           = trim($this->get_header('GitHub Plugin URI'));
        $this->worker_endpoint = defined('CB_WORKER_ENDPOINT') ? CB_WORKER_ENDPOINT : '';
        $this->license_option  = defined('CB_LICENSE_OPTION') ? CB_LICENSE_OPTION : 'calendly_bookings_license_key';
        $this->token_option    = defined('CB_TOKEN_OPTION') ? CB_TOKEN_OPTION : 'calendly_bookings_encrypted_token';

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_filter('upgrader_pre_download', [$this, 'download_package'], 10, 4);

        // Inject GitHub App installation token into GitHub requests.
        add_filter('http_request_args', [$this, 'inject_github_auth_header'], 10, 2);
    }

    private function get_header($header)
    {
        $data = get_file_data($this->file, [$header => $header]);
        return $data[$header] ?? '';
    }

    private function api_request($endpoint)
    {
        if (empty($this->repo)) {
            return false;
        }

        $url = rtrim($this->repo, '/') . '/' . ltrim($endpoint, '/');

        $response = wp_remote_get($url, [
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'calendly-bookings-updater',
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return false;
        }

        return json_decode(wp_remote_retrieve_body($response));
    }

    private function get_zip_url($api)
    {
        if (!empty($api->assets)) {
            foreach ($api->assets as $asset) {
                if (!empty($asset->name) && str_ends_with($asset->name, '.zip')) {
                    return $asset->browser_download_url;
                }
            }
        }

        return $api->zipball_url ?? '';
    }

    public function check_update($transient)
    {
        if (empty($transient->checked) || empty($this->plugin)) {
            return $transient;
        }

        $api = $this->api_request('releases/latest');
        if (!$api || empty($api->tag_name)) {
            return $transient;
        }

        $new_version     = ltrim($api->tag_name, 'v');
        $current_version = $transient->checked[$this->plugin] ?? null;

        if (!$current_version || version_compare($new_version, $current_version, '<=')) {
            return $transient;
        }

        $package = $this->get_zip_url($api);
        if (empty($package)) {
            return $transient;
        }

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
        if ($action !== 'plugin_information' || $args->slug !== $this->basename) {
            return $result;
        }

        $api = $this->api_request('releases/latest');
        if (!$api || empty($api->tag_name)) {
            return $result;
        }

        return (object) [
            'name'          => $this->basename,
            'slug'          => $this->basename,
            'version'       => ltrim($api->tag_name, 'v'),
            'author'        => 'Wafiq Harris-Ashby',
            'homepage'      => $this->repo,
            'download_link' => $this->get_zip_url($api),
            'sections'      => [
                'description' => $api->body ?? 'No description.',
                'changelog'   => $api->body ?? '',
            ],
        ];
    }

    public function download_package($reply, $package, $upgrader, $hook_extra)
    {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin) {
            return $reply;
        }

        // Let WordPress handle the actual download; we just ensure the URL is correct.
        return $package;
    }

    /**
     * Inject Authorization header into GitHub requests for this repo.
     */
    public function inject_github_auth_header($args, $url)
    {
        if (empty($this->repo)) {
            return $args;
        }

        if (strpos($url, 'github.com') === false && strpos($url, 'api.github.com') === false) {
            return $args;
        }

        // Only touch requests related to this repo.
        $repo_path = parse_url($this->repo, PHP_URL_PATH);
        if ($repo_path && strpos($url, trim($repo_path, '/')) === false) {
            return $args;
        }

        $token = $this->get_token();
        if (empty($token)) {
            return $args;
        }

        if (!isset($args['headers']) || !is_array($args['headers'])) {
            $args['headers'] = [];
        }

        $args['headers']['Authorization'] = 'token ' . $token;
        $args['headers']['Accept']        = 'application/vnd.github+json';

        return $args;
    }

    /**
     * Public entry point for manual refresh from admin-post.
     */
    public function refresh_token()
    {
        $this->request_and_store_token();
    }

    /**
     * Get the current token, requesting a fresh one if missing.
     */
    private function get_token()
    {
        $token = get_option($this->token_option, '');
        if (!empty($token)) {
            return $token;
        }

        $this->request_and_store_token();
        $token = get_option($this->token_option, '');

        return $token ?: '';
    }

    /**
     * Call the Cloudflare Worker and store the token.
     * Assumes Worker returns JSON: { "valid": true, "token": "<installation_token>" }
     */
    private function request_and_store_token()
    {
        if (empty($this->worker_endpoint)) {
            return;
        }

        $license = get_option($this->license_option, '');
        if (empty($license)) {
            return;
        }

        $response = wp_remote_post(
            $this->worker_endpoint,
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body'    => wp_json_encode(['license' => $license]),
                'timeout' => 20,
            ]
        );

        if (is_wp_error($response)) {
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code === 200 && !empty($data['valid']) && !empty($data['token'])) {
            // Decrypt before storing
            $decrypted = cb_decrypt_token($data['token'], LICENSE_SECRET);
            if (!empty($decrypted)) {
                update_option($this->token_option, $decrypted, false);
            }
        }

    }
    /**
     * Decrypt AES-GCM encrypted token from Worker.
     *
     * @param string $encrypted Base64-encoded JSON string from Worker.
     * @param string $secret    LICENSE_SECRET (must be 16, 24, or 32 chars).
     * @return string|null      Decrypted GitHub installation token, or null on failure.
     */
    private function cb_decrypt_token($encrypted, $secret) {
        $decoded = base64_decode($encrypted);
        $json = json_decode($decoded, true);

        if (!$json || !isset($json['iv'], $json['data'])) {
            return null;
        }

        $iv   = pack('C*', ...$json['iv']);
        $data = pack('C*', ...$json['data']);

        // OpenSSL expects key length of 16, 24, or 32 bytes
        if (!in_array(strlen($secret), [16, 24, 32], true)) {
            return null;
        }

        // NOTE: Worker currently embeds the GCM tag in ciphertext.
        // If you modify Worker to return {iv, data, tag}, pass $tag separately here.
        $token = openssl_decrypt(
            $data,
            'aes-'.(strlen($secret) * 8).'-gcm',
            $secret,
            OPENSSL_RAW_DATA,
            $iv,
            $tag = null
        );

        return $token ?: null;
    }

}
