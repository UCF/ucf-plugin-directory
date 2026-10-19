<?php
/**
 * In-memory WordPress stand-ins for unit tests
 */
class Test_WP {
	public static $filters  = array();
	public static $options  = array();
	public static $plugins  = array();
	public static $requests = array();

	/**
	 * Callable( $url, $args ) returning a response array or WP_Error.
	 */
	public static $http = null;

	public static function reset() {
		self::$filters  = array();
		self::$options  = array();
		self::$plugins  = array();
		self::$requests = array();
		self::$http     = null;
	}

	public static function response( $code, $body = '', array $headers = array() ) {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => $body,
			'headers'  => array_change_key_case( $headers ),
		);
	}

	/**
	 * Serves fixtures for the packagist.org and Satis URLs the client uses.
	 * Answers 304 whenever If-Modified-Since matches the fixture's date.
	 */
	public static function fixture_router( $last_modified = 'Mon, 12 Oct 2026 19:21:43 GMT' ) {
		return function ( $url, $args ) use ( $last_modified ) {
			$path = null;

			if ( 0 === strpos( $url, 'https://packagist.org/packages/list.json' ) ) {
				$path = UCF_TEST_FIXTURES . '/list.json';
			} elseif ( 'https://ucf.github.io/ucf-packagist/packages.json' === $url ) {
				$path = UCF_TEST_FIXTURES . '/packages.json';
			} elseif ( preg_match( '#^https://(?:repo\.packagist\.org|ucf\.github\.io/ucf-packagist)/p2/(.+\.json)$#', $url, $m ) ) {
				$path = UCF_TEST_FIXTURES . '/p2/' . $m[1];
			}

			if ( ! $path || ! file_exists( $path ) ) {
				return Test_WP::response( 404 );
			}

			if ( isset( $args['headers']['If-Modified-Since'] ) && $args['headers']['If-Modified-Since'] === $last_modified ) {
				return Test_WP::response( 304 );
			}

			return Test_WP::response( 200, file_get_contents( $path ), array( 'Last-Modified' => $last_modified ) );
		};
	}
}

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	Test_WP::$filters[ $hook ][] = array( $callback, $accepted_args );
	return true;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

function apply_filters( $hook, $value, ...$args ) {
	if ( empty( Test_WP::$filters[ $hook ] ) ) {
		return $value;
	}

	foreach ( Test_WP::$filters[ $hook ] as $filter ) {
		$value = call_user_func_array( $filter[0], array_slice( array_merge( array( $value ), $args ), 0, $filter[1] ) );
	}

	return $value;
}

function do_action( $hook, ...$args ) {
	if ( empty( Test_WP::$filters[ $hook ] ) ) {
		return;
	}

	foreach ( Test_WP::$filters[ $hook ] as $filter ) {
		call_user_func_array( $filter[0], array_slice( $args, 0, $filter[1] ) );
	}
}

function get_site_option( $name, $default = false ) {
	return array_key_exists( $name, Test_WP::$options ) ? Test_WP::$options[ $name ] : $default;
}

function update_site_option( $name, $value ) {
	Test_WP::$options[ $name ] = $value;
	return true;
}

function delete_site_transient( $name ) {
	unset( Test_WP::$options[ '_site_transient_' . $name ] );
	return true;
}

function get_site_transient( $name ) {
	return get_site_option( '_site_transient_' . $name );
}

function wp_remote_get( $url, $args = array() ) {
	Test_WP::$requests[] = array( $url, $args );

	if ( ! Test_WP::$http ) {
		return new WP_Error( 'http_request_failed', 'No HTTP in tests' );
	}

	return call_user_func( Test_WP::$http, $url, $args );
}

function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) ? $response['response']['code'] : '';
}

function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) ? $response['body'] : '';
}

function wp_remote_retrieve_header( $response, $header ) {
	$header = strtolower( $header );
	return isset( $response['headers'][ $header ] ) ? $response['headers'][ $header ] : '';
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
}

function add_query_arg( array $args, $url ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}

function get_bloginfo( $show = '' ) {
	return '6.8';
}

function wp_rand( $min = 0, $max = 0 ) {
	return mt_rand( $min, $max ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return esc_html( $text );
}

function esc_url( $url ) {
	return (string) $url;
}

function esc_html_x( $text, $context ) {
	return esc_html( $text );
}

function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
}

function trailingslashit( $value ) {
	return rtrim( $value, '/\\' ) . '/';
}

function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}

function get_plugins() {
	return Test_WP::$plugins;
}
