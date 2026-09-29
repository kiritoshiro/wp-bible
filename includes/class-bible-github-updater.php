<?php
/**
 * Checks private GitHub releases and integrates them with WordPress updates.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Bible_GitHub_Updater {
    const REPOSITORY = 'kiritoshiro/wp-bible';
    const API_VERSION = '2026-03-10';
    const RELEASE_CACHE_KEY = 'bible_github_latest_release_v1';
    const MAX_PACKAGE_SIZE = 52428800;

    public function __construct() {
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_plugin_updates' ) );
        add_filter( 'plugins_api', array( $this, 'filter_plugin_information' ), 20, 3 );
        add_filter( 'upgrader_pre_download', array( $this, 'download_private_release' ), 10, 4 );
        add_action( 'admin_notices', array( $this, 'show_configuration_notice' ) );
    }

    private function token() {
        if ( ! defined( 'BIBLE_GITHUB_TOKEN' ) || ! is_string( BIBLE_GITHUB_TOKEN ) ) {
            return '';
        }

        return trim( BIBLE_GITHUB_TOKEN );
    }

    private function latest_release() {
        $cached = get_site_transient( self::RELEASE_CACHE_KEY );
        if ( is_array( $cached ) ) {
            return empty( $cached['error'] ) ? $cached : null;
        }

        $token = $this->token();
        if ( '' === $token ) {
            set_site_transient( self::RELEASE_CACHE_KEY, array( 'error' => true ), 5 * MINUTE_IN_SECONDS );
            return null;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
            array(
                'timeout'             => 15,
                'redirection'         => 2,
                'reject_unsafe_urls'  => true,
                'headers'             => array(
                    'Accept'                => 'application/vnd.github+json',
                    'Authorization'         => 'Bearer ' . $token,
                    'X-GitHub-Api-Version'  => self::API_VERSION,
                ),
            )
        );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            set_site_transient( self::RELEASE_CACHE_KEY, array( 'error' => true ), 5 * MINUTE_IN_SECONDS );
            return null;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $release ) || empty( $release['tag_name'] ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
            set_site_transient( self::RELEASE_CACHE_KEY, array( 'error' => true ), 5 * MINUTE_IN_SECONDS );
            return null;
        }

        $version = preg_replace( '/^v/i', '', (string) $release['tag_name'] );
        if ( ! preg_match( '/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version ) ) {
            set_site_transient( self::RELEASE_CACHE_KEY, array( 'error' => true ), 5 * MINUTE_IN_SECONDS );
            return null;
        }

        $asset = null;
        if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
            foreach ( $release['assets'] as $candidate ) {
                if ( ! is_array( $candidate ) || 'bible.zip' !== ( $candidate['name'] ?? '' ) ) {
                    continue;
                }

                $asset_id = isset( $candidate['id'] ) ? absint( $candidate['id'] ) : 0;
                $expected_api_url = 'https://api.github.com/repos/' . self::REPOSITORY . '/releases/assets/' . $asset_id;
                $browser_url = isset( $candidate['browser_download_url'] ) ? (string) $candidate['browser_download_url'] : '';
                $browser_parts = wp_parse_url( $browser_url );
                $size = isset( $candidate['size'] ) ? absint( $candidate['size'] ) : 0;

                if (
                    $asset_id > 0
                    && isset( $candidate['url'] )
                    && $expected_api_url === $candidate['url']
                    && is_array( $browser_parts )
                    && 'https' === ( $browser_parts['scheme'] ?? '' )
                    && 'github.com' === ( $browser_parts['host'] ?? '' )
                    && '/kiritoshiro/wp-bible/releases/download/' . rawurlencode( $release['tag_name'] ) . '/bible.zip' === ( $browser_parts['path'] ?? '' )
                    && $size > 0
                    && $size <= self::MAX_PACKAGE_SIZE
                ) {
                    $asset = $candidate;
                    break;
                }
            }
        }

        if ( ! $asset || empty( $release['html_url'] ) ) {
            set_site_transient( self::RELEASE_CACHE_KEY, array( 'error' => true ), 5 * MINUTE_IN_SECONDS );
            return null;
        }

        $release['version'] = $version;
        $release['asset'] = $asset;
        set_site_transient( self::RELEASE_CACHE_KEY, $release, 10 * MINUTE_IN_SECONDS );

        return $release;
    }

    public function filter_plugin_updates( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }

        $plugin_file = plugin_basename( BIBLE_PLUGIN_FILE );
        if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            $transient->response = array();
        }
        if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
            $transient->no_update = array();
        }

        // Keep an unrelated WordPress.org plugin with the generic "bible" slug from replacing this plugin.
        unset( $transient->response[ $plugin_file ], $transient->no_update[ $plugin_file ] );

        $release = $this->latest_release();
        if ( ! $release ) {
            return $transient;
        }

        $update = (object) array(
            'id'           => 'https://github.com/' . self::REPOSITORY,
            'slug'         => dirname( $plugin_file ),
            'plugin'       => $plugin_file,
            'new_version'  => $release['version'],
            'url'          => $release['html_url'],
            'package'      => $release['asset']['browser_download_url'],
            'requires'     => '5.0',
            'requires_php' => '7.4',
        );

        if ( version_compare( $release['version'], BIBLE_PLUGIN_VERSION, '>' ) ) {
            $transient->response[ $plugin_file ] = $update;
        } else {
            $transient->no_update[ $plugin_file ] = $update;
        }

        return $transient;
    }

    public function filter_plugin_information( $result, $action, $args ) {
        if (
            'plugin_information' !== $action
            || ! is_object( $args )
            || empty( $args->slug )
            || dirname( plugin_basename( BIBLE_PLUGIN_FILE ) ) !== $args->slug
        ) {
            return $result;
        }

        $release = $this->latest_release();
        if ( ! $release ) {
            return $result;
        }

        $description = 'Automatically detects Bible references and shows the verse text in a popup.';
        $changelog = ! empty( $release['body'] ) ? wpautop( esc_html( $release['body'] ) ) : '<p>No release notes were provided.</p>';

        return (object) array(
            'name'          => 'Bible',
            'slug'          => dirname( plugin_basename( BIBLE_PLUGIN_FILE ) ),
            'version'       => $release['version'],
            'author'        => 'Bible Plugin',
            'homepage'      => 'https://github.com/' . self::REPOSITORY,
            'requires'      => '5.0',
            'requires_php'  => '7.4',
            'last_updated'  => isset( $release['published_at'] ) ? $release['published_at'] : '',
            'download_link' => $release['asset']['browser_download_url'],
            'sections'      => array(
                'description' => '<p>' . esc_html( $description ) . '</p>',
                'changelog'   => $changelog,
            ),
        );
    }

    public function download_private_release( $reply, $package, $upgrader, $hook_extra ) {
        if ( false !== $reply || 0 !== strpos( $package, 'https://github.com/' . self::REPOSITORY . '/releases/download/' ) ) {
            return $reply;
        }

        $token = $this->token();
        if ( '' === $token ) {
            return new WP_Error(
                'bible_github_token_missing',
                __( 'GitHub updates for the Bible plugin are not configured. Add BIBLE_GITHUB_TOKEN to wp-config.php and try again.', 'bible' )
            );
        }

        $release = $this->latest_release();
        if ( ! $release || $package !== $release['asset']['browser_download_url'] ) {
            return new WP_Error(
                'bible_github_release_unavailable',
                __( 'The Bible plugin release could not be verified with GitHub. Please check the update again later.', 'bible' )
            );
        }

        $asset_id = absint( $release['asset']['id'] );
        $asset_url = 'https://api.github.com/repos/' . self::REPOSITORY . '/releases/assets/' . $asset_id;
        $response = wp_remote_get(
            $asset_url,
            array(
                'timeout'             => 60,
                'redirection'         => 0,
                'reject_unsafe_urls'  => true,
                'headers'             => array(
                    'Accept'                => 'application/octet-stream',
                    'Authorization'         => 'Bearer ' . $token,
                    'X-GitHub-Api-Version'  => self::API_VERSION,
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'bible_github_download_failed', __( 'WordPress could not connect to GitHub to download the Bible plugin update.', 'bible' ) );
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( 302 === $status ) {
            $download_url = wp_remote_retrieve_header( $response, 'location' );
            $parts = wp_parse_url( $download_url );
            $host = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
            $trusted_cdn = 'release-assets.githubusercontent.com' === $host
                || 'objects.githubusercontent.com' === $host
                || ( strlen( $host ) > strlen( '.githubusercontent.com' ) && '.githubusercontent.com' === substr( $host, -strlen( '.githubusercontent.com' ) ) );

            if ( ! $trusted_cdn || ! wp_http_validate_url( $download_url ) ) {
                return new WP_Error( 'bible_github_redirect_invalid', __( 'GitHub returned an invalid download link for the Bible plugin update.', 'bible' ) );
            }

            // The short-lived CDN URL is fetched without the repository token.
            $response = wp_remote_get(
                $download_url,
                array(
                    'timeout'             => 60,
                    'redirection'         => 3,
                    'reject_unsafe_urls'  => true,
                )
            );

            if ( is_wp_error( $response ) ) {
                return new WP_Error( 'bible_github_download_failed', __( 'WordPress could not download the Bible plugin release asset from GitHub.', 'bible' ) );
            }

            $status = wp_remote_retrieve_response_code( $response );
        }

        if ( 200 !== $status ) {
            return new WP_Error( 'bible_github_download_failed', __( 'GitHub did not return the Bible plugin update package.', 'bible' ) );
        }

        $body = wp_remote_retrieve_body( $response );
        if ( ! is_string( $body ) || 2 > strlen( $body ) || 'PK' !== substr( $body, 0, 2 ) || strlen( $body ) > self::MAX_PACKAGE_SIZE ) {
            return new WP_Error( 'bible_github_package_invalid', __( 'The downloaded Bible plugin package is not a valid ZIP file.', 'bible' ) );
        }

        $temporary_file = wp_tempnam( 'bible-update.zip' );
        if ( ! $temporary_file ) {
            return new WP_Error( 'bible_github_tempfile_failed', __( 'WordPress could not create a temporary file for the Bible plugin update.', 'bible' ) );
        }

        $written = file_put_contents( $temporary_file, $body, LOCK_EX );
        if ( false === $written || strlen( $body ) !== $written ) {
            wp_delete_file( $temporary_file );
            return new WP_Error( 'bible_github_package_write_failed', __( 'WordPress could not save the Bible plugin update package.', 'bible' ) );
        }

        return $temporary_file;
    }

    public function show_configuration_notice() {
        if ( ! current_user_can( 'manage_options' ) || '' !== $this->token() ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! is_object( $screen ) || ! in_array( $screen->id, array( 'plugins', 'update-core' ), true ) ) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__( 'GitHub updates for the Bible plugin need a read-only token because its repository is private. Add this line to wp-config.php:', 'bible' );
        echo ' <code>define( \'BIBLE_GITHUB_TOKEN\', \'your_read_only_token\' );</code>';
        echo '</p></div>';
    }
}
