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
    const RELEASE_CACHE_KEY = 'bible_github_latest_release_v2';
    const MAX_PACKAGE_SIZE = 52428800;

    public function __construct() {
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_plugin_updates' ) );
        add_filter( 'plugins_api', array( $this, 'filter_plugin_information' ), 20, 3 );
        add_filter( 'upgrader_pre_download', array( $this, 'download_private_release' ), 10, 4 );
    }

    private function token() {
        if ( ! defined( 'BIBLE_GITHUB_TOKEN' ) || ! is_string( BIBLE_GITHUB_TOKEN ) ) {
            return '';
        }

        return trim( BIBLE_GITHUB_TOKEN );
    }

    /**
     * GitHub API headers. The repository is public, so the token is optional:
     * it only raises the API rate limit (or restores access if the repository
     * becomes private) and is only ever sent to api.github.com.
     */
    private function api_headers( $accept ) {
        $headers = array(
            'Accept'               => $accept,
            'X-GitHub-Api-Version' => self::API_VERSION,
        );
        $token = $this->token();
        if ( '' !== $token ) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    private function latest_release() {
        $cached = get_site_transient( self::RELEASE_CACHE_KEY );
        if ( is_array( $cached ) ) {
            return empty( $cached['error'] ) ? $cached : null;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
            array(
                'timeout'             => 15,
                'redirection'         => 0,
                'limit_response_size' => 1048576,
                'reject_unsafe_urls'  => true,
                'headers'             => $this->api_headers( 'application/vnd.github+json' ),
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
                    && $browser_url === 'https://github.com/' . self::REPOSITORY . '/releases/download/' . rawurlencode( $release['tag_name'] ) . '/bible.zip'
                    && $size > 0
                    && $size <= self::MAX_PACKAGE_SIZE
                ) {
                    $asset = $candidate;
                    break;
                }
            }
        }

        if ( ! $asset || ( $release['html_url'] ?? '' ) !== 'https://github.com/' . self::REPOSITORY . '/releases/tag/' . rawurlencode( $release['tag_name'] ) ) {
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

        $release = $this->latest_release();
        if ( ! $release || $package !== $release['asset']['browser_download_url'] ) {
            return new WP_Error(
                'bible_github_release_unavailable',
                __( 'The Bible plugin release could not be verified with GitHub. Please check the update again later.', 'bible' )
            );
        }

        $temporary_file = wp_tempnam( 'bible-update.zip' );
        if ( ! $temporary_file ) {
            return new WP_Error( 'bible_github_tempfile_failed', 'Could not create an update temporary file.' );
        }
        $keep_file = false;
        try {
            $asset = $release['asset'];
            $options = array(
                'timeout' => 60, 'redirection' => 0, 'reject_unsafe_urls' => true,
                'stream' => true, 'filename' => $temporary_file,
                'limit_response_size' => min( self::MAX_PACKAGE_SIZE, intval( $asset['size'] ) ) + 1,
                'headers' => $this->api_headers( 'application/octet-stream' ),
            );
            $response = wp_remote_get(
                'https://api.github.com/repos/' . self::REPOSITORY . '/releases/assets/' . absint( $asset['id'] ),
                $options
            );
            if ( ! is_wp_error( $response ) && 302 === wp_remote_retrieve_response_code( $response ) ) {
                $url = wp_remote_retrieve_header( $response, 'location' );
                $parts = wp_parse_url( $url );
                if ( ! is_array( $parts ) || ( $parts['scheme'] ?? '' ) !== 'https' ||
                    ! in_array( strtolower( $parts['host'] ?? '' ), array( 'release-assets.githubusercontent.com', 'objects.githubusercontent.com' ), true ) ||
                    isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ||
                    ! wp_http_validate_url( $url ) ) {
                    return new WP_Error( 'bible_github_redirect_invalid', 'GitHub returned an unexpected download host.' );
                }
                // No token and no further redirects on the signed CDN request.
                unset( $options['headers'] );
                $response = wp_remote_get( $url, $options );
            }
            if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
                return new WP_Error( 'bible_github_download_failed', 'Could not download the GitHub release asset.' );
            }
            clearstatcache( true, $temporary_file );
            $digest = $asset['digest'] ?? '';
            if ( filesize( $temporary_file ) !== intval( $asset['size'] ) ||
                file_get_contents( $temporary_file, false, null, 0, 4 ) !== "PK\x03\x04" ||
                ( $digest !== '' && ( ! is_string( $digest ) || ! preg_match( '/^sha256:[a-f0-9]{64}$/D', $digest ) ||
                    ! hash_equals( substr( $digest, 7 ), hash_file( 'sha256', $temporary_file ) ) ) ) ) {
                return new WP_Error( 'bible_github_package_invalid', 'The release package size, ZIP header, or SHA-256 checksum is invalid.' );
            }
            // WordPress core validates and unpacks the archive after this hook.
            $keep_file = true;
            return $temporary_file;
        } finally {
            if ( ! $keep_file ) wp_delete_file( $temporary_file );
        }
    }
}
