<?php
/**
 * Stand-ins for the WP-CLI runtime, recording what commands print.
 */
namespace {
	class Test_WP_CLI_Halt extends Exception {}

	class WP_CLI {
		public static $log   = array();
		public static $items = array();

		public static function reset() {
			self::$log   = array();
			self::$items = array();
		}

		public static function log( $message ) {
			self::$log[] = array( 'log', $message );
		}

		public static function success( $message ) {
			self::$log[] = array( 'success', $message );
		}

		public static function warning( $message ) {
			self::$log[] = array( 'warning', $message );
		}

		/**
		 * The real WP_CLI::error() exits; throw so tests can observe it.
		 */
		public static function error( $message ) {
			self::$log[] = array( 'error', $message );
			throw new Test_WP_CLI_Halt( $message );
		}

		public static function messages( $type ) {
			return array_column(
				array_filter(
					self::$log,
					function ( $entry ) use ( $type ) {
						return $entry[0] === $type;
					}
				),
				1
			);
		}
	}

	class Test_WP_Theme {
		private $headers;

		public function __construct( array $headers ) {
			$this->headers = $headers;
		}

		public function get( $header ) {
			return isset( $this->headers[ $header ] ) ? $this->headers[ $header ] : false;
		}
	}

	function wp_get_themes() {
		return Test_WP::$themes;
	}
}

namespace WP_CLI\Utils {
	function format_items( $format, $items, $fields ) {
		\WP_CLI::$items[] = array(
			'format' => $format,
			'items'  => $items,
			'fields' => $fields,
		);
	}
}
