<?php
// Run only in a disposable WordPress database named bible_audit:
// wp eval-file tests/security.php
if ( ! defined( 'ABSPATH' ) || ! defined( 'DB_NAME' ) || DB_NAME !== 'bible_audit' ) {
    throw new RuntimeException( 'Use a disposable WordPress installation with DB_NAME=bible_audit.' );
}
define( 'BIBLE_GITHUB_TOKEN', 'fake-test-token-never-a-credential' );
require dirname( __DIR__ ) . '/bible.php';
global $wpdb, $checks;
$checks = 0;
function bible_check( $condition, $message ) {
    global $checks;
    if ( ! $condition ) throw new RuntimeException( $message );
    ++$checks;
    echo "PASS: $message\n";
}
function bible_private( $class, $method, ...$args ) {
    $r = new ReflectionMethod( $class, $method );
    $r->setAccessible( true );
    return $r->invoke( null, ...$args );
}
bible_plugin_activate();
bible_check( intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bible_verses" ) ) === 31165, 'Bundled SQLite imports all 31,165 verses into MySQL' );
update_option( 'bible_custom_aliases', array( array( 'alias' => 'Custom', 'book_number' => 10 ) ) );
bible_plugin_activate();
bible_check( get_option( 'bible_custom_aliases' )[0]['alias'] === 'Custom', 'Reactivation preserves custom aliases' );

$payloads = array(
    '<span onmouseover="alert(1)" style="color:red">verse</span>',
    '<i onclick=alert(1)>verse</i><img src=x onerror=alert(2)>',
    '<svg onload=alert(1)><strong onfocus=alert(2)>verse</strong></svg>',
    '<span ONCLICK="&#97;lert(1)" tabindex=0>verse</span>',
);
foreach ( $payloads as $payload ) {
    $clean = bible_private( 'Bible_Ajax', 'clean_verse_text', $payload );
    bible_check( ! preg_match( '/<(?:img|svg)|onmouse|onclick|onfocus|onload|onerror|style=|tabindex/i', $clean ), 'KSES removes hostile tags and attributes' );
}
bible_check( bible_private( 'Bible_Ajax', 'clean_verse_text', '<i>verse</i><strong>bold</strong>' ) === '<i>verse</i><strong>bold</strong>', 'Safe verse formatting preserved' );
bible_check( count( Bible_DB::get_chapter_range( 10, 1, 50 ) ) === 81, 'Chapter range is bounded in SQL' );
bible_check( count( Bible_DB::get_cross_chapter_verses( 10, 1, 1, 50, 26 ) ) === 81, 'Cross chapter query is bounded in SQL' );
bible_check( count( Bible_DB::get_verses( 230, 119 ) ) <= 81, 'Single chapter query is bounded in SQL' );
$before = $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(text ORDER BY id)) FROM {$wpdb->prefix}bible_verses WHERE id < 10" );
$bad = wp_tempnam();
file_put_contents( $bad, 'not sqlite' );
bible_check( is_wp_error( Bible_DB::import_sqlite_module( $bad ) ), 'Non SQLite module rejected' );
wp_delete_file( $bad );
$bad = wp_tempnam();
$db = new SQLite3( $bad );
$db->exec( 'CREATE TABLE books (book_number,short_name,long_name,book_color); CREATE TABLE verses(book_number,chapter,verse,text)' );
$db->exec( "INSERT INTO books VALUES(10,'Gen','Genesis','#fff'); INSERT INTO verses VALUES(10,1,1,'one'); INSERT INTO verses VALUES(10,1,1,'duplicate')" );
$db->close();
bible_check( is_wp_error( Bible_DB::import_sqlite_module( $bad ) ), 'Duplicate module verses rejected before deleting live data' );
wp_delete_file( $bad );
bible_check( intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bible_verses" ) ) === 31165, 'Rejected imports preserve installed verses' );
$fault = function( $sql ) {
    return strpos( $sql, "INSERT INTO {$GLOBALS['wpdb']->prefix}bible_verses" ) === 0 ? 'INVALID SQL FOR ROLLBACK TEST' : $sql;
};
$wpdb->suppress_errors( true );
add_filter( 'query', $fault );
$result = Bible_DB::import_sqlite_module( BIBLE_MODULES_DIR . 'LTRK.SQLite3' );
remove_filter( 'query', $fault );
$wpdb->suppress_errors( false );
bible_check( is_wp_error( $result ), 'Mid import database failure is reported' );
bible_check( intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bible_verses" ) ) === 31165 &&
    $before === $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(text ORDER BY id)) FROM {$wpdb->prefix}bible_verses WHERE id < 10" ), 'MySQL transaction rolls back deleted data and failed writes' );
$wpdb->query( "ALTER TABLE {$wpdb->prefix}bible_books ENGINE=MyISAM" );
$result = Bible_DB::import_sqlite_module( BIBLE_MODULES_DIR . 'LTRK.SQLite3' );
$wpdb->query( "ALTER TABLE {$wpdb->prefix}bible_books ENGINE=InnoDB" );
bible_check( is_wp_error( $result ) && $result->get_error_code() === 'module_engine', 'Non transactional tables rejected safely' );

$admin = new Bible_Admin();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array( 'bible_save_settings' => 1, 'enabled' => '0' );
$settings = get_option( 'bible_settings' );
wp_set_current_user( 0 );
$admin->handle_actions();
bible_check( get_option( 'bible_settings' ) === $settings, 'Anonymous admin action cannot change settings' );
$subscriber = wp_insert_user( array( 'user_login' => 'bible_subscriber', 'user_pass' => 'local-only', 'role' => 'subscriber' ) );
if ( is_wp_error( $subscriber ) ) $subscriber = username_exists( 'bible_subscriber' );
wp_set_current_user( $subscriber );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'bible_settings_nonce' );
$admin->handle_actions();
bible_check( get_option( 'bible_settings' ) === $settings, 'Subscriber cannot change plugin settings' );
bible_check( is_wp_error( bible_private( 'Bible_Admin', 'normalize_aliases', array( array( 'alias' => array(), 'book_number' => 10 ) ) ) ), 'Malformed alias JSON rejected' );
bible_check( is_wp_error( bible_private( 'Bible_Admin', 'uploaded_file', 'missing', 'json', 1048576 ) ), 'Absent upload rejected' );
$normalized = bible_private( 'Bible_Admin', 'normalize_settings', array( 'popup_trigger' => array(), 'popup_maxwidth' => 99999, 'enabled' => array(), 'extra' => 'discard' ) );
bible_check( $normalized === array( 'enabled' => '0', 'popup_trigger' => 'hover', 'popup_maxwidth' => 800 ), 'Settings types, bounds and allowed keys enforced' );
$aliases = bible_private( 'Bible_Admin', 'normalize_aliases', array( array( 'alias' => 'Gen', 'book_number' => 10 ), array( 'alias' => 'Gen', 'book_number' => '10' ) ) );
bible_check( count( $aliases ) === 1, 'Aliases deduplicated after normalization' );

$die = function() { return function() { throw new RuntimeException( 'expected-wp-die' ); }; };
define( 'DOING_AJAX', true );
add_filter( 'wp_die_ajax_handler', $die );
foreach ( array( array( 'book_number' => array( 10 ), 'chapter' => 1 ), array( 'book_number' => 10, 'chapter' => -1 ), array( 'book_number' => 10, 'chapter' => 1, 'mode' => array() ) ) as $params ) {
    $_GET = $params;
    ob_start();
    try { ( new Bible_Ajax() )->get_verse(); } catch ( RuntimeException $e ) {
        if ( $e->getMessage() !== 'expected-wp-die' ) throw $e;
    }
    $json = json_decode( ob_get_clean(), true );
    bible_check( isset( $json['success'] ) && $json['success'] === false, 'Malformed public AJAX input rejected' );
}
$_GET = array();
remove_filter( 'wp_die_ajax_handler', $die );

// Mock only HTTP transport; use real WordPress hooks, transients and temp files.
$body = "PK\x03\x04" . str_repeat( 'fixture', 12 );
$release = array(
    'tag_name' => 'v1.2.0', 'html_url' => 'https://github.com/kiritoshiro/wp-bible/releases/tag/v1.2.0',
    'assets' => array( array(
        'id' => 123, 'name' => 'bible.zip', 'size' => strlen( $body ), 'digest' => 'sha256:' . hash( 'sha256', $body ),
        'url' => 'https://api.github.com/repos/kiritoshiro/wp-bible/releases/assets/123',
        'browser_download_url' => 'https://github.com/kiritoshiro/wp-bible/releases/download/v1.2.0/bible.zip',
    ) ),
);
$scenario = 'success';
$tempfiles = array();
$requests = array();
$mock = function( $pre, $args, $url ) use ( &$release, &$scenario, &$tempfiles, &$requests, $body ) {
    $requests[] = array( $url, $args );
    if ( substr( $url, -16 ) === '/releases/latest' ) {
        return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $release ), 'headers' => array() );
    }
    if ( strpos( $url, 'https://api.github.com/' ) === 0 ) {
        $tempfiles[] = $args['filename'];
        return array( 'response' => array( 'code' => 302 ), 'body' => '', 'headers' => array(
            'location' => $scenario === 'bad-host' ? 'https://attacker.example/a.zip' :
                ( $scenario === 'http' ? 'http' : 'https' ) . '://release-assets.githubusercontent.com/package.zip',
        ) );
    }
    file_put_contents( $args['filename'], $scenario === 'truncated' ? substr( $body, 0, 10 ) : $body );
    return array( 'response' => array( 'code' => 200 ), 'body' => '', 'headers' => array() );
};
add_filter( 'pre_http_request', $mock, 10, 3 );
add_filter( 'http_request_host_is_external', '__return_true' );
$updater = new Bible_GitHub_Updater();
delete_site_transient( Bible_GitHub_Updater::RELEASE_CACHE_KEY );
$updates = $updater->filter_plugin_updates( (object) array() );
bible_check( $updates->response[plugin_basename( BIBLE_PLUGIN_FILE )]->new_version === '1.2.0', 'Valid release becomes a native WordPress update' );
$package = $release['assets'][0]['browser_download_url'];
$result = $updater->download_private_release( false, $package, null, array() );
bible_check( is_string( $result ) && file_get_contents( $result ) === $body, 'Streamed release bytes verified by size and checksum' );
wp_delete_file( $result );
foreach ( $requests as list( $url, $args ) ) {
    bible_check( $args['redirection'] === 0 && isset( $args['limit_response_size'] ), 'HTTP responses bounded and automatic redirects disabled' );
    if ( strpos( $url, 'https://release-assets.' ) === 0 ) {
        bible_check( empty( $args['headers']['Authorization'] ), 'Repository token never sent to CDN' );
    }
}
foreach ( array( 'bad-host', 'http', 'truncated' ) as $scenario ) {
    $result = $updater->download_private_release( false, $package, null, array() );
    bible_check( is_wp_error( $result ), 'Reject download scenario: ' . $scenario );
    bible_check( ! file_exists( end( $tempfiles ) ), 'Failed download temp file removed' );
}
$scenario = 'success';
$release['assets'][0]['digest'] = 'sha256:' . str_repeat( '0', 64 );
delete_site_transient( Bible_GitHub_Updater::RELEASE_CACHE_KEY );
$result = $updater->download_private_release( false, $package, null, array() );
bible_check( is_wp_error( $result ) && ! file_exists( end( $tempfiles ) ), 'Checksum mismatch rejected and cleaned up' );
remove_filter( 'pre_http_request', $mock );
delete_site_transient( Bible_GitHub_Updater::RELEASE_CACHE_KEY );
echo "Passed $checks security regression checks.\n";
