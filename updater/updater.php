<?php

namespace RM_GitHub_Plugin;

class Updater {

    // Update these four values for your plugin
    private $repo_owner   = 'Jared-Nolt';
    private $repo_name    = 'rm-github-plugin';
    private $plugin_file  = 'rm-github-plugin.php'; // main plugin file name
    private $plugin_name  = 'RM GitHub Plugin';

    public $plugin_slug;
    public $version;
    public $cache_key;
    public $cache_allowed;

    private $auth_token;
    private $basename;

    public function __construct() {

        // Optional: disable SSL checks in dev if you set RM_GITHUB_PLUGIN_DEV_MODE
        if ( defined( 'RM_GITHUB_PLUGIN_DEV_MODE' ) ) {
            add_filter( 'https_ssl_verify', '__return_false' );
            add_filter( 'https_local_ssl_verify', '__return_false' );
            add_filter( 'http_request_host_is_external', '__return_true' );
        }

        $this->plugin_slug   = dirname( plugin_basename( __DIR__ ) );
        $this->version       = defined( 'RM_GITHUB_PLUGIN_VERSION' ) ? \RM_GITHUB_PLUGIN_VERSION : '1.0.0';
        $this->cache_key     = 'rm_github_plugin_updater';
        $this->cache_allowed = true; // cache GitHub responses to avoid rate limits
        $this->basename      = $this->plugin_slug . '/' . $this->plugin_file;
        $this->auth_token    = defined( 'RM_GITHUB_PLUGIN_TOKEN' ) ? RM_GITHUB_PLUGIN_TOKEN : '';

        add_filter( 'plugins_api', [ $this, 'info' ], 20, 3 );
        add_filter( 'site_transient_update_plugins', [ $this, 'update' ] );
        add_action( 'upgrader_process_complete', [ $this, 'purge' ], 10, 2 );

        // --- Optional Manual check helpers (removable for production UI): auth headers + manual "Check for updates" link
        add_filter( 'http_request_args', [ $this, 'maybe_authenticate_download' ], 10, 2 );
        add_filter( "plugin_action_links_{$this->basename}", [ $this, 'add_check_link' ] );
        add_action( 'admin_init', [ $this, 'process_manual_check' ] );
        // --- END Optional Manual check helpers (removable for production UI): auth headers + manual "Check for updates" link
    }

    private function request() {
        $remote = get_transient( $this->cache_key );

        if ( false === $remote || ! $this->cache_allowed ) {
            $remote = wp_remote_get( 'https://api.github.com/repos/' . $this->repo_owner . '/' . $this->repo_name . '/releases/latest', [
                    'timeout' => 10,
                    'headers' => [
                        'Accept'     => 'application/vnd.github.v3+json',
                        'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
                        'Authorization' => $this->auth_token ? 'token ' . $this->auth_token : '',
                    ],
                ]
            );

            if ( is_wp_error( $remote ) || 200 !== wp_remote_retrieve_response_code( $remote ) || empty( wp_remote_retrieve_body( $remote ) ) ) {
                return false;
            }

            set_transient( $this->cache_key, $remote, 6 * HOUR_IN_SECONDS );
        }

        return json_decode( wp_remote_retrieve_body( $remote ) );
    }

    public function info( $response, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $response;
        }

        if ( empty( $args->slug ) || $this->plugin_slug !== $args->slug ) {
            return $response;
        }

        $remote = $this->request();

        if ( ! $remote ) {
            return $response;
        }

        $response = new \stdClass();
        $remote_version = isset( $remote->tag_name ) ? ltrim( $remote->tag_name, 'vV' ) : $this->version;
        $zip_url = isset( $remote->tag_name ) ? 'https://github.com/' . $this->repo_owner . '/' . $this->repo_name . '/archive/refs/tags/' . $remote->tag_name . '.zip' : '';

        $response->name          = $this->plugin_name;
        $response->slug          = $this->plugin_slug;
        $response->version       = $remote_version;
        $response->author        = $this->repo_owner;
        $response->homepage      = 'https://github.com/' . $this->repo_owner . '/' . $this->repo_name;
        $response->download_link = $zip_url;
        $response->trunk         = $zip_url;
        $response->last_updated  = isset( $remote->published_at ) ? $remote->published_at : '';

        $response->sections = [
            'description'  => $this->plugin_name . ' auto-updates from GitHub releases.',
            'installation' => 'Install as a standard WordPress plugin.',
            'changelog'    => isset( $remote->body ) ? $remote->body : '',
        ];

        return $response;
    }

    public function update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $remote = $this->request();

        if ( $remote && isset( $remote->tag_name ) ) {
            $remote_version = ltrim( $remote->tag_name, 'vV' );
            if ( version_compare( $this->version, $remote_version, '<' ) ) {
                $response              = new \stdClass();
                $response->slug        = $this->plugin_slug;
                $response->plugin      = $this->plugin_slug . '/' . $this->plugin_file;
                $response->new_version = $remote_version;
                $response->package     = 'https://github.com/' . $this->repo_owner . '/' . $this->repo_name . '/archive/refs/tags/' . $remote->tag_name . '.zip';

                $transient->response[ $response->plugin ] = $response;
            }
        }

        return $transient;
    }

    public function purge( $upgrader, $options ) {
        if ( $this->cache_allowed && 'update' === $options['action'] && 'plugin' === $options['type'] ) {
            delete_transient( $this->cache_key );
        }
    }

    /**
     * Add auth headers to GitHub API and package downloads when a token is configured.
     */
    public function maybe_authenticate_download( $args, $url ) {
        if ( ! $this->auth_token ) {
            return $args;
        }

        $is_github = strpos( $url, 'github.com' ) !== false || strpos( $url, 'api.github.com' ) !== false;
        if ( ! $is_github ) {
            return $args;
        }

        if ( empty( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
            $args['headers'] = [];
        }

        $args['headers']['Authorization'] = 'token ' . $this->auth_token;
        $args['headers']['User-Agent']    = $args['headers']['User-Agent'] ?? 'WordPress/' . get_bloginfo( 'version' );

        return $args;
    }

    // --- Manual check helpers ---
    public function add_check_link( $links ) {
        $check_url = add_query_arg(
            [
                'gh_check' => $this->plugin_slug,
                'nonce'    => wp_create_nonce( 'gh_check' ),
            ],
            admin_url( 'plugins.php' )
        );

        $links['rm_gh_check'] = '<a href="' . esc_url( $check_url ) . '">Check for updates</a>';
        return $links;
    }

    // --- END Manual check helpers ---

    // --- Manual check helpers ---
    public function process_manual_check() {
        $gh_check = isset( $_GET['gh_check'] ) ? sanitize_text_field( wp_unslash( $_GET['gh_check'] ) ) : '';
        $nonce    = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';

        if ( $gh_check !== $this->plugin_slug ) {
            return;
        }

        if ( ! current_user_can( 'update_plugins' ) || ! wp_verify_nonce( $nonce, 'gh_check' ) ) {
            return;
        }

        delete_site_transient( 'update_plugins' );
        wp_safe_redirect( admin_url( 'plugins.php?rm_gh_checked=1' ) );
        exit;
    }

    // --- END Manual check helpers ---
}

?>