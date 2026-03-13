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

    public function __construct($file)
    {
        $this->file     = $file;
        $this->plugin   = plugin_basename($file);
        $this->basename = dirname($this->plugin);
        $this->active   = is_plugin_active($this->plugin);

        $this->repo = trim($this->get_header('GitHub Plugin URI'));

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_filter('upgrader_pre_download', [$this, 'download_package'], 10, 4);
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
            'name'         => $this->basename,
            'slug'         => $this->basename,
            'version'      => ltrim($api->tag_name, 'v'),
            'author'       => 'Wafiq Harris-Ashby',
            'homepage'     => $this->repo,
            'download_link'=> $this->get_zip_url($api),
            'sections'     => [
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
}
