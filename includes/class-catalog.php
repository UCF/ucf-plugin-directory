<?php
/**
 * Cached, resolved catalog of UCF packages
 */
if ( ! class_exists( 'UCF_Plugin_Directory_Catalog' ) ) {
	class UCF_Plugin_Directory_Catalog {
		const CACHE_OPTION  = 'ucf_plugin_directory_catalog';
		const STATUS_OPTION = 'ucf_plugin_directory_status';
		const CRON_HOOK     = 'ucf_plugin_directory_refresh';

		/**
		 * After a failed blocking fetch, wait this long before an admin page
		 * tries again on its own.
		 */
		const RETRY_AFTER = 300;

		private $client;
		private $packages = null;

		public function __construct( UCF_Plugin_Directory_Repository_Client $client ) {
			$this->client = $client;
		}

		public function get_client() {
			return $this->client;
		}

		/**
		 * @return string[]
		 */
		public static function get_vendors() {
			$vendors = (array) apply_filters( 'ucf_plugin_directory_vendors', array( 'ucf' ) );
			return array_values( array_unique( array_map( 'strtolower', array_filter( $vendors, 'is_string' ) ) ) );
		}

		/**
		 * @return string[]
		 */
		public static function get_excluded_packages() {
			$excluded = (array) apply_filters( 'ucf_plugin_directory_excluded_packages', array( 'ucf/github-action-testing' ) );
			return array_map( 'strtolower', array_filter( $excluded, 'is_string' ) );
		}

		/**
		 * All usable packages (plugins and themes) from the cache, keyed by
		 * package name. Abandoned packages are included; callers decide
		 * whether to show them. Never makes an HTTP request.
		 *
		 * @return UCF_Plugin_Directory_Package[]
		 */
		public function get_packages() {
			if ( null !== $this->packages ) {
				return $this->packages;
			}

			$this->packages = array();
			$cache          = $this->get_cache();
			$vendors        = self::get_vendors();
			$excluded       = self::get_excluded_packages();

			foreach ( $cache['packages'] as $name => $entry ) {
				if ( empty( $entry['package'] ) ) {
					continue;
				}

				$package = UCF_Plugin_Directory_Package::from_array( $entry['package'] );

				if (
					! $package
					|| ! in_array( $package->get_vendor(), $vendors, true )
					|| in_array( $package->get_name(), $excluded, true )
					|| ! ( $package->is_plugin() || $package->is_theme() )
				) {
					continue;
				}

				$this->packages[ $package->get_name() ] = $package;
			}

			ksort( $this->packages );

			return $this->packages;
		}

		/**
		 * @param bool $include_abandoned
		 * @return UCF_Plugin_Directory_Package[]
		 */
		public function get_plugins( $include_abandoned = false ) {
			return array_filter(
				$this->get_packages(),
				function ( $package ) use ( $include_abandoned ) {
					return $package->is_plugin() && ( $include_abandoned || ! $package->is_abandoned() );
				}
			);
		}

		/**
		 * @return UCF_Plugin_Directory_Package|null
		 */
		public function get_package( $name ) {
			$packages = $this->get_packages();
			$name     = strtolower( $name );

			return isset( $packages[ $name ] ) ? $packages[ $name ] : null;
		}

		/**
		 * Finds a plugin package by slug across the allowed vendors.
		 *
		 * @return UCF_Plugin_Directory_Package|null
		 */
		public function get_plugin_by_slug( $slug ) {
			foreach ( $this->get_packages() as $package ) {
				if ( $package->is_plugin() && $package->get_slug() === $slug ) {
					return $package;
				}
			}

			return null;
		}

		/**
		 * Looks up a package from an Update URI such as
		 * https://packagist.org/packages/ucf/ucf-faq-cpt. Returns null for
		 * other hosts and for vendors outside the allowlist.
		 *
		 * @return UCF_Plugin_Directory_Package|null
		 */
		public function get_package_by_update_uri( $uri ) {
			$parts = wp_parse_url( (string) $uri );

			if ( empty( $parts['host'] ) || 'packagist.org' !== strtolower( $parts['host'] ) || empty( $parts['path'] ) ) {
				return null;
			}

			if ( ! preg_match( '#^/packages/([a-z0-9_.-]+/[a-z0-9_.-]+?)/?$#i', $parts['path'], $matches ) ) {
				return null;
			}

			// get_package() only knows allowlisted vendors.
			return $this->get_package( $matches[1] );
		}

		/**
		 * Matches an installed plugin that has no Update URI to a catalog
		 * package. Tries the "GitHub Plugin URI" header that UCF plugins
		 * already carry, then the plugin's directory name (against both the
		 * package slug and the GitHub repo name, case-insensitively).
		 *
		 * @param string $plugin_file Plugin basename, e.g. UCF-FAQ-CPT/ucf-faq.php
		 * @param array  $plugin_data Plugin headers from get_plugins()
		 * @return UCF_Plugin_Directory_Package|null
		 */
		public function match_installed_plugin( $plugin_file, array $plugin_data ) {
			$plugins = $this->get_plugins( true );

			$github = '';
			if ( ! empty( $plugin_data['GitHub Plugin URI'] ) ) {
				$github = strtolower( trim( preg_replace( '#^https?://github\.com/#i', '', $plugin_data['GitHub Plugin URI'] ), '/' ) );
				$github = preg_replace( '#\.git$#', '', $github );
			}

			if ( $github ) {
				foreach ( $plugins as $package ) {
					if ( strtolower( $package->get_github_repo() ) === $github ) {
						return $package;
					}
				}

				// The header names a repo that isn't in our catalog.
				return null;
			}

			$directory = strtolower( dirname( $plugin_file ) );
			if ( '.' === $directory ) {
				return null;
			}

			foreach ( $plugins as $package ) {
				$repo = strtolower( $package->get_github_repo() );
				$repo = $repo ? substr( $repo, strpos( $repo, '/' ) + 1 ) : '';

				if ( $package->get_slug() === $directory || ( $repo && $repo === $directory ) ) {
					return $package;
				}
			}

			return null;
		}

		/**
		 * Finds which catalog package (if any) a download URL belongs to.
		 *
		 * @return UCF_Plugin_Directory_Package|null
		 */
		public function get_package_by_download_url( $url ) {
			foreach ( $this->get_packages() as $package ) {
				if ( self::get_download_url( $package ) === $url ) {
					return $package;
				}
			}

			return null;
		}

		/**
		 * The download URL we hand to WordPress for a package.
		 */
		public static function get_download_url( UCF_Plugin_Directory_Package $package ) {
			return (string) apply_filters( 'ucf_plugin_directory_download_url', $package->get_dist_url(), $package );
		}

		public function is_empty() {
			$cache = $this->get_cache();
			return empty( $cache['packages'] );
		}

		/**
		 * Fetches the catalog from the repository. Packages whose p2 file is
		 * unchanged (HTTP 304) keep their cached data. Any failure leaves the
		 * last good data in place and is recorded for diagnostics.
		 *
		 * @param bool $force Ignore Last-Modified and refetch every package
		 * @return true|WP_Error
		 */
		public function refresh( $force = false ) {
			$cache   = $this->get_cache();
			$status  = $this->get_status();
			$started = time();

			$status['last_attempt'] = $started;

			$discovery = $this->client->discover( self::get_vendors() );

			if ( is_wp_error( $discovery ) ) {
				$status['last_error'] = $discovery->get_error_message();
				$this->save_status( $status );
				return $discovery;
			}

			$excluded = self::get_excluded_packages();
			$new      = array(
				'source'    => $this->client->get_repository_url(),
				'refreshed' => $started,
				'packages'  => array(),
			);
			$errors   = array();
			$stats    = array(
				'fetched'   => 0,
				'unchanged' => 0,
			);

			foreach ( $discovery['packages'] as $name => $meta ) {
				if ( in_array( $name, $excluded, true ) ) {
					continue;
				}

				$old    = isset( $cache['packages'][ $name ] ) ? $cache['packages'][ $name ] : null;
				$since  = ( ! $force && $old && ! empty( $old['last_modified'] ) ) ? $old['last_modified'] : '';
				$result = $this->client->fetch_package( $discovery['metadata_url'], $name, $since );

				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
					if ( $old ) {
						$new['packages'][ $name ] = $old;
					}
					continue;
				}

				if ( 304 === $result['status'] && $old ) {
					// list.json may have changed the abandoned flag without touching p2.
					if ( ! empty( $old['package'] ) && array_key_exists( 'abandoned', $meta ) ) {
						$old['package']['abandoned'] = $meta['abandoned'] ? $meta['abandoned'] : false;
					}
					$new['packages'][ $name ] = $old;
					++$stats['unchanged'];
					continue;
				}

				$versions = UCF_Plugin_Directory_Metadata_Expander::versions_from_document( $result['document'], $name );
				if ( null === $versions ) {
					$errors[] = sprintf( 'No metadata for %s in its p2 file', $name );
					if ( $old ) {
						$new['packages'][ $name ] = $old;
					}
					continue;
				}

				$latest  = self::resolve_latest( $versions );
				$package = $latest ? UCF_Plugin_Directory_Package::from_version( $latest, $meta ) : null;

				$new['packages'][ $name ] = array(
					'package'       => $package ? $package->to_array() : null,
					'last_modified' => $result['last_modified'],
				);
				++$stats['fetched'];
			}

			$this->save_cache( $new );

			$status['last_refresh'] = $started;
			$status['last_error']   = $errors ? implode( "\n", array_slice( $errors, 0, 10 ) ) : '';
			$status['stats']        = $stats;
			$this->save_status( $status );

			/**
			 * Fires after the catalog has been refreshed.
			 *
			 * @param UCF_Plugin_Directory_Catalog $catalog
			 */
			do_action( 'ucf_plugin_directory_refreshed', $this );

			return true;
		}

		/**
		 * One-time blocking fetch for admin screens when nothing is cached.
		 * Backs off for a few minutes after a failure.
		 */
		public function maybe_fetch_when_empty() {
			if ( ! $this->is_empty() ) {
				return;
			}

			$status = $this->get_status();
			if ( ! empty( $status['last_attempt'] ) && time() - $status['last_attempt'] < self::RETRY_AFTER ) {
				return;
			}

			$this->refresh();
		}

		/**
		 * Picks the highest stable tagged release.
		 *
		 * @param array $versions Expanded versions
		 * @return array|null
		 */
		public static function resolve_latest( array $versions ) {
			$latest     = null;
			$latest_key = '';

			foreach ( $versions as $version ) {
				if ( ! is_array( $version ) || empty( $version['version'] ) || ! self::is_stable( $version ) ) {
					continue;
				}

				$key = ! empty( $version['version_normalized'] ) ? $version['version_normalized'] : UCF_Plugin_Directory_Package::clean_version( $version['version'] );

				if ( null === $latest || version_compare( $key, $latest_key, '>' ) ) {
					$latest     = $version;
					$latest_key = $key;
				}
			}

			return $latest;
		}

		/**
		 * Dev branches and alpha/beta/RC tags are not stable.
		 */
		public static function is_stable( array $version ) {
			$raw        = strtolower( (string) $version['version'] );
			$normalized = isset( $version['version_normalized'] ) ? strtolower( (string) $version['version_normalized'] ) : $raw;

			if ( 0 === strpos( $raw, 'dev-' ) || '-dev' === substr( $raw, -4 ) || '-dev' === substr( $normalized, -4 ) ) {
				return false;
			}

			// Composer normalizes v1.0.0-beta.2 to 1.0.0.0-beta2; check both forms.
			foreach ( array( $raw, $normalized ) as $candidate ) {
				if ( preg_match( '/-(alpha|beta|rc|a|b)(?:[.\d]|$)/', $candidate ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Schedules the background refresh twice a day, at a random minute so
		 * UCF sites don't all hit the repository at :00.
		 */
		public static function schedule() {
			if ( wp_next_scheduled( self::CRON_HOOK ) ) {
				return;
			}

			$next_hour = ( (int) floor( time() / HOUR_IN_SECONDS ) + 1 ) * HOUR_IN_SECONDS;
			$start     = $next_hour + wp_rand( 1, 59 ) * MINUTE_IN_SECONDS + wp_rand( 0, 59 );

			wp_schedule_event( $start, 'twicedaily', self::CRON_HOOK );
		}

		public static function unschedule() {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}

		/**
		 * @return array {
		 *     @type string $source
		 *     @type int    $refreshed
		 *     @type array  $packages Package name => array( package, last_modified )
		 * }
		 */
		public function get_cache() {
			$cache = get_site_option( self::CACHE_OPTION );

			// A cache from a different repository is stale.
			if (
				! is_array( $cache )
				|| ! isset( $cache['packages'] )
				|| ! is_array( $cache['packages'] )
				|| ! isset( $cache['source'] )
				|| $cache['source'] !== $this->client->get_repository_url()
			) {
				return array(
					'source'    => $this->client->get_repository_url(),
					'refreshed' => 0,
					'packages'  => array(),
				);
			}

			return $cache;
		}

		private function save_cache( array $cache ) {
			update_site_option( self::CACHE_OPTION, $cache );
			$this->packages = null;
		}

		public function get_status() {
			$status = get_site_option( self::STATUS_OPTION );

			return wp_parse_args(
				is_array( $status ) ? $status : array(),
				array(
					'last_attempt' => 0,
					'last_refresh' => 0,
					'last_error'   => '',
					'stats'        => array(),
				)
			);
		}

		private function save_status( array $status ) {
			update_site_option( self::STATUS_OPTION, $status );
		}
	}
}
