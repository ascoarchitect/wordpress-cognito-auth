<?php
/**
 * Test bootstrap: an in-memory stand-in for the slice of WordPress the
 * plugin's Inbound class touches. No database, no WordPress checkout —
 * `vendor/bin/phpunit` is the whole toolchain.
 *
 * @package WP_Cognito_Auth
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_COGNITO_AUTH_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'WP_DEBUG', false );

spl_autoload_register(
	function ( $class ) {
		$prefix = 'WP_Cognito_Auth\\';
		if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$file = WP_COGNITO_AUTH_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

// ── state ──────────────────────────────────────────────────────────────────
final class WP_Fake {
	public static $options    = array();
	public static $transients = array();
	public static $roles      = array();
	public static $users      = array(); // id => WP_User
	public static $meta       = array(); // id => [key => value]
	public static $routes     = array();
	public static $next_id    = 1;

	public static function reset() {
		self::$options    = array();
		self::$transients = array();
		self::$roles      = array( 'subscriber' => 'Subscriber', 'administrator' => 'Administrator' );
		self::$users      = array();
		self::$meta       = array();
		self::$routes     = array();
		self::$next_id    = 1;
	}
}

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

class WP_User {
	public $ID; public $user_login; public $user_email; public $display_name = ''; public $roles = array();
	public function __construct( $id = 0 ) { $this->ID = (int) $id; if ( $id && isset( WP_Fake::$users[ $id ] ) ) { foreach ( get_object_vars( WP_Fake::$users[ $id ] ) as $k => $v ) { $this->$k = $v; } } }
	public function add_role( $r ) { if ( ! in_array( $r, $this->roles, true ) ) { $this->roles[] = $r; } $this->persist(); }
	public function remove_role( $r ) { $this->roles = array_values( array_diff( $this->roles, array( $r ) ) ); $this->persist(); }
	public function set_role( $r ) { $this->roles = array( $r ); $this->persist(); }
	private function persist() { WP_Fake::$users[ $this->ID ] = $this; }
}

class WP_REST_Request {
	private $headers = array(); private $body = ''; private $json = null;
	public function __construct( $body = '', $headers = array() ) { $this->body = $body; foreach ( $headers as $k => $v ) { $this->headers[ strtolower( str_replace( '-', '_', $k ) ) ] = $v; } }
	public function get_header( $k ) { $k = strtolower( str_replace( '-', '_', $k ) ); return isset( $this->headers[ $k ] ) ? $this->headers[ $k ] : null; }
	public function get_body() { return $this->body; }
	public function get_json_params() { return json_decode( $this->body, true ); }
}
function rest_ensure_response( $r ) { return $r; }
function register_rest_route( $ns, $route, $args ) { WP_Fake::$routes[ $ns . $route ] = $args; return true; }

// ── options / transients ───────────────────────────────────────────────────
function get_option( $k, $d = false ) { return array_key_exists( $k, WP_Fake::$options ) ? WP_Fake::$options[ $k ] : $d; }
function update_option( $k, $v ) { WP_Fake::$options[ $k ] = $v; return true; }
function get_transient( $k ) { return isset( WP_Fake::$transients[ $k ] ) ? WP_Fake::$transients[ $k ] : false; }
function set_transient( $k, $v, $ttl = 0 ) { WP_Fake::$transients[ $k ] = $v; return true; }
function delete_transient( $k ) { unset( WP_Fake::$transients[ $k ] ); return true; }

// ── sanitising ─────────────────────────────────────────────────────────────
function sanitize_email( $e ) { return filter_var( trim( $e ), FILTER_SANITIZE_EMAIL ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_user( $u, $strict = false ) { $u = strip_tags( (string) $u ); return $strict ? preg_replace( '/[^a-z0-9 _.\-@]/i', '', $u ) : $u; }
function wp_generate_password( $l = 12, $s = true, $e = false ) { return str_repeat( 'x', $l ); }
function wp_json_encode( $d ) { return json_encode( $d ); }
function __( $t, $d = null ) { return $t; }
function esc_html__( $t, $d = null ) { return $t; }
function add_filter( $h, $c, $p = 10, $a = 1 ) { return true; }
function remove_filter( $h, $c, $p = 10 ) { return true; }
function __return_false() { return false; }

// ── roles ──────────────────────────────────────────────────────────────────
function get_role( $slug ) { return isset( WP_Fake::$roles[ $slug ] ) ? (object) array( 'name' => $slug ) : null; }

// ── users ──────────────────────────────────────────────────────────────────
function username_exists( $login ) { foreach ( WP_Fake::$users as $u ) { if ( $u->user_login === $login ) { return $u->ID; } } return false; }
function get_userdata( $id ) { return isset( WP_Fake::$users[ $id ] ) ? new WP_User( $id ) : false; }
function get_user_by( $field, $value ) {
	foreach ( WP_Fake::$users as $u ) {
		if ( 'email' === $field && strtolower( $u->user_email ) === strtolower( $value ) ) { return new WP_User( $u->ID ); }
		if ( 'id' === $field && (int) $u->ID === (int) $value ) { return new WP_User( $u->ID ); }
	}
	return false;
}
function get_users( $args ) {
	$out = array();
	foreach ( WP_Fake::$users as $u ) {
		if ( isset( $args['meta_key'] ) && get_user_meta( $u->ID, $args['meta_key'], true ) !== $args['meta_value'] ) { continue; }
		$out[] = new WP_User( $u->ID );
		if ( isset( $args['number'] ) && count( $out ) >= $args['number'] ) { break; }
	}
	return $out;
}
function wp_insert_user( $data ) {
	if ( empty( $data['user_email'] ) || get_user_by( 'email', $data['user_email'] ) ) { return new WP_Error( 'existing_user_email', 'Sorry, that email address is already used!' ); }
	if ( username_exists( $data['user_login'] ) ) { return new WP_Error( 'existing_user_login', 'Sorry, that username already exists!' ); }
	$u = new WP_User( 0 ); $u->ID = WP_Fake::$next_id++; $u->user_login = $data['user_login']; $u->user_email = $data['user_email'];
	$u->display_name = isset( $data['display_name'] ) ? $data['display_name'] : $data['user_login'];
	$u->roles = ! empty( $data['role'] ) ? array( $data['role'] ) : array();
	WP_Fake::$users[ $u->ID ] = $u;
	if ( ! empty( $data['first_name'] ) ) { update_user_meta( $u->ID, 'first_name', $data['first_name'] ); }
	if ( ! empty( $data['last_name'] ) ) { update_user_meta( $u->ID, 'last_name', $data['last_name'] ); }
	return $u->ID;
}
function wp_update_user( $data ) {
	$id = (int) $data['ID']; if ( ! isset( WP_Fake::$users[ $id ] ) ) { return new WP_Error( 'invalid_user_id', 'Invalid user ID.' ); }
	if ( isset( $data['user_email'] ) ) { $h = get_user_by( 'email', $data['user_email'] ); if ( $h && (int) $h->ID !== $id ) { return new WP_Error( 'existing_user_email', 'This email address is already registered.' ); } WP_Fake::$users[ $id ]->user_email = $data['user_email']; }
	if ( isset( $data['display_name'] ) ) { WP_Fake::$users[ $id ]->display_name = $data['display_name']; }
	if ( isset( $data['first_name'] ) ) { update_user_meta( $id, 'first_name', $data['first_name'] ); }
	if ( isset( $data['last_name'] ) ) { update_user_meta( $id, 'last_name', $data['last_name'] ); }
	return $id;
}
function get_user_meta( $id, $key, $single = false ) { return isset( WP_Fake::$meta[ $id ][ $key ] ) ? WP_Fake::$meta[ $id ][ $key ] : ''; }
function update_user_meta( $id, $key, $value ) { WP_Fake::$meta[ $id ][ $key ] = $value; return true; }
function delete_user_meta( $id, $key ) { unset( WP_Fake::$meta[ $id ][ $key ] ); return true; }

WP_Fake::reset();
