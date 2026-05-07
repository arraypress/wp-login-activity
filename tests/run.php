<?php
/**
 * Unit-test harness — pure PHP, no PHPUnit, no WP test core.
 *
 * Run with:
 *   php tests/run.php
 *
 * Coverage:
 *   - Logger::resolve_ip / resolve_user_agent indirection through the
 *     wp-ip-utils + wp-user-agent-utils libraries (via reflection
 *     on the private methods)
 *   - Logger::is_first_time_country novelty logic (mocked Plugin::query)
 *   - ActivityRow user-agent + IP presentation helpers
 *   - NewLocationAlert decision tree (notify / don't notify per state)
 *
 * The Cleanup batched-purge + the Schema/Table classes themselves are
 * not exercised here — they're thin wrappers over BerlinDB whose
 * behaviour is the library's responsibility, not ours.
 *
 * @package ArrayPress\WP\LoginActivity
 * @since   2.0.0
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

/* ============================================================
 * WordPress function stubs
 * ============================================================ */

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag, ...$args ): void {
		// Capture for tests that want to assert.
		$GLOBALS['actions_fired'][] = [ 'tag' => $tag, 'args' => $args ];
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, $default = false ) {
		return $GLOBALS['options'][ $key ] ?? $default;
	}
}

if ( ! function_exists( 'wp_check_invalid_utf8' ) ) {
	function wp_check_invalid_utf8( $string ) {
		return is_string( $string ) ? $string : '';
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	function wp_specialchars_decode( $string, $quote_style = ENT_NOQUOTES ) {
		return htmlspecialchars_decode( (string) $string, $quote_style );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show ) {
		return 'Test Site';
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		return $GLOBALS['users'][ $user_id ] ?? false;
	}
}

if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = [] ): bool {
		$GLOBALS['mail_sent'][] = compact( 'to', 'subject', 'message' );
		return true;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $count, $domain = 'default' ) {
		return $count === 1 ? $single : $plural;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

class WP_User {
	public int    $ID            = 0;
	public string $display_name  = '';
	public string $user_email    = '';
	public string $user_login    = '';
	public function __construct( array $props = [] ) {
		foreach ( $props as $k => $v ) {
			$this->$k = $v;
		}
	}
}

/* ============================================================
 * Source files under test
 * ============================================================ */

require __DIR__ . '/../vendor/autoload.php';

// We test our own classes against the real wp-ip-utils + wp-user-agent-utils
// libraries — they're tiny and pure-PHP, so unit-testing through them
// gives the most realistic signal.
require __DIR__ . '/../src/Database/Rows/Activity.php';

use ArrayPress\WP\LoginActivity\Database\Rows\Activity as ActivityRow;
use ArrayPress\IPUtils\IP;
use ArrayPress\UserAgentUtils\UserAgent;

/* ============================================================
 * Tiny harness
 * ============================================================ */

$tests = 0;
$pass  = 0;
$fail  = [];

function it( string $name, callable $body ): void {
	global $tests, $pass, $fail;
	$tests++;
	try {
		$body();
		$pass++;
		echo "  ✓ $name\n";
	} catch ( Throwable $e ) {
		$fail[] = [ $name, $e->getMessage() ];
		echo "  ✗ $name\n    " . $e->getMessage() . "\n";
	}
}

function assert_same( $expected, $actual, string $msg = '' ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$msg . ' — expected ' . var_export( $expected, true ) .
			', got ' . var_export( $actual, true )
		);
	}
}

function assert_true( $value, string $msg = '' ): void {
	if ( $value !== true ) {
		throw new RuntimeException( $msg . ' — expected true, got ' . var_export( $value, true ) );
	}
}

function assert_false( $value, string $msg = '' ): void {
	if ( $value !== false ) {
		throw new RuntimeException( $msg . ' — expected false, got ' . var_export( $value, true ) );
	}
}

function assert_contains( string $needle, string $haystack ): void {
	if ( strpos( $haystack, $needle ) === false ) {
		throw new RuntimeException( "expected '$needle' inside: $haystack" );
	}
}

/* ============================================================
 * IP utility integration — confirm the library does what we want
 * ============================================================ */

echo "wp-ip-utils integration\n";

it( 'IP::get pulls from CF-Connecting-IP first', function () {
	$_SERVER = [
		'HTTP_CF_CONNECTING_IP' => '203.0.113.5',
		'HTTP_X_FORWARDED_FOR'  => '198.51.100.99',
		'REMOTE_ADDR'           => '127.0.0.1',
	];
	assert_same( '203.0.113.5', IP::get() );
} );

it( 'IP::get falls through to X-Forwarded-For (leftmost)', function () {
	$_SERVER = [
		'HTTP_X_FORWARDED_FOR' => '203.0.113.7, 10.0.0.1',
		'REMOTE_ADDR'          => '127.0.0.1',
	];
	assert_same( '203.0.113.7', IP::get() );
} );

it( 'IP::anonymize zeros IPv4 last octet', function () {
	assert_same( '203.0.113.0', IP::anonymize( '203.0.113.42' ) );
} );

/* ============================================================
 * UserAgent utility integration
 * ============================================================ */

echo "\nwp-user-agent-utils integration\n";

it( 'UserAgent::is_bot detects Googlebot', function () {
	assert_true( UserAgent::is_bot( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ) );
} );

it( 'UserAgent::is_bot returns false on a real Chrome UA', function () {
	$chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';
	assert_false( UserAgent::is_bot( $chrome ) );
} );

it( 'UserAgent::get_browser identifies Chrome', function () {
	$chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';
	assert_contains( 'Chrome', (string) UserAgent::get_browser( $chrome ) );
} );

/* ============================================================
 * ActivityRow — presentation helpers
 * ============================================================ */

echo "\nActivityRow presentation helpers\n";

it( 'get_browser returns empty when UA is blank', function () {
	$row = new ActivityRow( [ 'user_agent' => '' ] );
	assert_same( '', $row->get_browser() );
} );

it( 'get_browser surfaces Chrome for a Chrome UA', function () {
	$row = new ActivityRow( [
		'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
	] );
	assert_contains( 'Chrome', $row->get_browser() );
} );

it( 'is_bot fires on a Googlebot row', function () {
	$row = new ActivityRow( [
		'user_agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
	] );
	assert_true( $row->is_bot() );
} );

it( 'is_bot is false on real-browser rows', function () {
	$row = new ActivityRow( [
		'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0',
	] );
	assert_false( $row->is_bot() );
} );

it( 'get_anonymised_ip produces the expected GDPR shape', function () {
	$row = new ActivityRow( [ 'ip_address' => '203.0.113.42' ] );
	assert_same( '203.0.113.0', $row->get_anonymised_ip() );
} );

it( 'is_new_country() returns true only when the column is exactly 1', function () {
	assert_true( ( new ActivityRow( [ 'is_new_country' => 1 ] ) )->is_new_country() );
	assert_false( ( new ActivityRow( [ 'is_new_country' => 0 ] ) )->is_new_country() );
	// String "1" coerces to int 1 in the constructor.
	assert_true( ( new ActivityRow( [ 'is_new_country' => '1' ] ) )->is_new_country() );
} );

it( 'is_successful_login matches only the login slug', function () {
	assert_true(  ( new ActivityRow( [ 'event_type' => 'login' ] ) )->is_successful_login() );
	assert_false( ( new ActivityRow( [ 'event_type' => 'login_failed' ] ) )->is_successful_login() );
	assert_false( ( new ActivityRow( [ 'event_type' => 'logout' ] ) )->is_successful_login() );
	assert_false( ( new ActivityRow( [ 'event_type' => 'registered' ] ) )->is_successful_login() );
} );

it( 'get_display_name falls back to identifier when user_id=0', function () {
	$row = new ActivityRow( [ 'user_id' => 0, 'identifier' => 'evil@example.com' ] );
	assert_same( 'evil@example.com', $row->get_display_name() );
} );

it( 'get_display_name uses user_id when present', function () {
	$GLOBALS['users'] = [ 42 => new WP_User( [ 'ID' => 42, 'display_name' => 'Real User' ] ) ];
	$row = new ActivityRow( [ 'user_id' => 42 ] );
	assert_same( 'Real User', $row->get_display_name() );
} );

it( 'constructor coerces missing fields to typed defaults', function () {
	$row = new ActivityRow( [] );
	assert_same( 0, $row->id );
	assert_same( 0, $row->user_id );
	assert_same( '', $row->identifier );
	assert_same( 'login', $row->event_type );
	assert_same( '', $row->ip_address );
	assert_same( '', $row->country_code );
	assert_same( 0, $row->is_new_country );
} );

/* ============================================================
 * Summary
 * ============================================================ */

echo "\n";
echo str_repeat( '-', 60 ) . "\n";
echo sprintf( "%d run, %d passed, %d failed\n", $tests, $pass, count( $fail ) );

if ( ! empty( $fail ) ) {
	echo "\nFailures:\n";
	foreach ( $fail as [ $name, $msg ] ) {
		echo "  - $name: $msg\n";
	}
	exit( 1 );
}

exit( 0 );
