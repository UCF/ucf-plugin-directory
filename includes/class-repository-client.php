<?php
/**
 * HTTP client for a Composer v2 repository: packagist.org or a Satis index
 */
if ( ! class_exists( 'UCF_Plugin_Directory_Repository_Client' ) ) {
	class UCF_Plugin_Directory_Repository_Client {
		const PACKAGIST_LIST_URL     = 'https://packagist.org/packages/list.json';
		const PACKAGIST_METADATA_URL = 'https://repo.packagist.org/p2/%package%.json';
		const DEFAULT_CONTACT        = 'webcom@ucf.edu';
		const TIMEOUT                = 15;

		private $repository_url;

		/**
		 * @param string $repository_url Base URL of the Composer repository
		 */
		public function __construct( $repository_url ) {
			$this->repository_url = rtrim( $repository_url, '/' ) . '/';
		}

		public function get_repository_url() {
			return $this->repository_url;
		}

		/**
		 * Whether the configured repository is packagist.org, which is
		 * discovered through list.json rather than packages.json.
		 */
		public function is_packagist() {
			$host = strtolower( (string) wp_parse_url( $this->repository_url, PHP_URL_HOST ) );
			return in_array( $host, array( 'packagist.org', 'repo.packagist.org' ), true );
		}

		/**
		 * Discovers the packages that exist for the given vendors.
		 *
		 * @param string[] $vendors Vendor names, e.g. array( 'ucf' )
		 * @return array|WP_Error {
		 *     @type array  $packages     Package name => list-level metadata (type, abandoned, repository)
		 *     @type string $metadata_url p2 URL template containing %package%
		 * }
		 */
		public function discover( array $vendors ) {
			return $this->is_packagist() ? $this->discover_packagist( $vendors ) : $this->discover_composer_repository( $vendors );
		}

		private function discover_packagist( array $vendors ) {
			$packages = array();

			foreach ( $vendors as $vendor ) {
				$url = add_query_arg(
					array( 'vendor' => rawurlencode( $vendor ) ),
					self::PACKAGIST_LIST_URL
				) . '&fields[]=type&fields[]=abandoned&fields[]=repository';

				$json = $this->get_json( $url );
				if ( is_wp_error( $json ) ) {
					return $json;
				}

				if ( ! isset( $json['packages'] ) || ! is_array( $json['packages'] ) ) {
					return new WP_Error( 'ucf_plugin_directory_bad_list', sprintf( 'Unexpected response from %s', $url ) );
				}

				foreach ( $json['packages'] as $name => $meta ) {
					if ( is_string( $name ) ) {
						$packages[ strtolower( $name ) ] = is_array( $meta ) ? $meta : array();
					}
				}
			}

			return array(
				'packages'     => $packages,
				'metadata_url' => self::PACKAGIST_METADATA_URL,
			);
		}

		private function discover_composer_repository( array $vendors ) {
			$url  = $this->repository_url . 'packages.json';
			$json = $this->get_json( $url );

			if ( is_wp_error( $json ) ) {
				return $json;
			}

			if ( empty( $json['metadata-url'] ) || ! isset( $json['available-packages'] ) || ! is_array( $json['available-packages'] ) ) {
				return new WP_Error( 'ucf_plugin_directory_bad_repository', sprintf( '%s does not list available-packages and a Composer v2 metadata-url', $url ) );
			}

			$packages = array();
			foreach ( $json['available-packages'] as $name ) {
				$name = strtolower( (string) $name );
				if ( in_array( self::vendor_of( $name ), $vendors, true ) ) {
					$packages[ $name ] = array();
				}
			}

			return array(
				'packages'     => $packages,
				'metadata_url' => $this->resolve_url( $json['metadata-url'] ),
			);
		}

		/**
		 * Fetches one package's p2 file (tagged releases only, never ~dev),
		 * sending If-Modified-Since when we have a previous Last-Modified.
		 *
		 * @return array|WP_Error {
		 *     @type int        $status        200 or 304
		 *     @type array|null $document      Decoded JSON (null on 304)
		 *     @type string     $last_modified Last-Modified header to store
		 * }
		 */
		public function fetch_package( $metadata_url, $name, $last_modified = '' ) {
			$url     = str_replace( '%package%', $name, $metadata_url );
			$headers = array();

			if ( $last_modified ) {
				$headers['If-Modified-Since'] = $last_modified;
			}

			$response = wp_remote_get( $url, $this->request_args( $headers ) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 304 === $code ) {
				return array(
					'status'        => 304,
					'document'      => null,
					'last_modified' => $last_modified,
				);
			}

			if ( 200 !== $code ) {
				return new WP_Error( 'ucf_plugin_directory_http', sprintf( 'HTTP %d from %s', $code, $url ) );
			}

			$document = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $document ) ) {
				return new WP_Error( 'ucf_plugin_directory_bad_json', sprintf( 'Malformed JSON from %s', $url ) );
			}

			return array(
				'status'        => 200,
				'document'      => $document,
				'last_modified' => (string) wp_remote_retrieve_header( $response, 'last-modified' ),
			);
		}

		private function get_json( $url ) {
			$response = wp_remote_get( $url, $this->request_args() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				return new WP_Error( 'ucf_plugin_directory_http', sprintf( 'HTTP %d from %s', $code, $url ) );
			}

			$json = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $json ) ) {
				return new WP_Error( 'ucf_plugin_directory_bad_json', sprintf( 'Malformed JSON from %s', $url ) );
			}

			return $json;
		}

		private function request_args( array $headers = array() ) {
			return array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => self::user_agent(),
				'headers'    => array_merge( array( 'Accept' => 'application/json' ), $headers ),
			);
		}

		/**
		 * Packagist asks clients to identify themselves with a contact address.
		 */
		public static function user_agent() {
			$contact = apply_filters( 'ucf_plugin_directory_user_agent_contact', self::DEFAULT_CONTACT );
			$ua      = sprintf( 'UCF-Plugin-Directory/%s (WordPress/%s', UCF_PLUGIN_DIRECTORY__VERSION, get_bloginfo( 'version' ) );

			if ( $contact ) {
				$ua .= '; mailto=' . $contact;
			}

			return $ua . ')';
		}

		/**
		 * Resolves a metadata-url (which Satis gives as a root-relative path)
		 * against the repository URL.
		 */
		private function resolve_url( $url ) {
			if ( preg_match( '#^https?://#i', $url ) ) {
				return $url;
			}

			$parts  = wp_parse_url( $this->repository_url );
			$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

			if ( 0 === strpos( $url, '/' ) ) {
				return $origin . $url;
			}

			return $this->repository_url . $url;
		}

		public static function vendor_of( $name ) {
			$parts = explode( '/', $name, 2 );
			return $parts[0];
		}
	}
}
