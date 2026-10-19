<?php
/**
 * Download checks, GitHub token, and folder renaming for UCF packages
 */
if ( ! class_exists( 'UCF_Plugin_Directory_Upgrader' ) ) {
	class UCF_Plugin_Directory_Upgrader {
		private $catalog;

		/**
		 * The catalog package currently being downloaded and installed.
		 *
		 * @var UCF_Plugin_Directory_Package|null
		 */
		private $current = null;

		public function __construct( UCF_Plugin_Directory_Catalog $catalog ) {
			$this->catalog = $catalog;
		}

		public function register() {
			add_filter( 'upgrader_pre_download', array( $this, 'pre_download' ), 10, 4 );
			add_filter( 'upgrader_source_selection', array( $this, 'source_selection' ), 10, 4 );
			add_filter( 'http_request_args', array( $this, 'add_github_token' ), 10, 2 );
		}

		/**
		 * @return string[] URL prefixes downloads may come from
		 */
		public static function get_allowed_download_prefixes() {
			return (array) apply_filters(
				'ucf_plugin_directory_allowed_download_hosts',
				array(
					'https://api.github.com/repos/UCF/',
					'https://codeload.github.com/UCF/',
					'https://github.com/UCF/',
				)
			);
		}

		/**
		 * @return string[] URL prefixes a package's source repository must match
		 */
		public static function get_allowed_repository_prefixes() {
			return (array) apply_filters( 'ucf_plugin_directory_allowed_repositories', array( 'https://github.com/UCF/' ) );
		}

		public static function url_has_allowed_prefix( $url, array $prefixes ) {
			foreach ( $prefixes as $prefix ) {
				// GitHub owner names are case-insensitive.
				if ( is_string( $prefix ) && '' !== $prefix && 0 === stripos( (string) $url, $prefix ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Validates downloads of catalog packages before WordPress fetches
		 * them, and remembers the package so its folder can be renamed.
		 */
		public function pre_download( $reply, $url, $upgrader, $hook_extra = array() ) {
			$this->current = null;

			// Identify updates by the plugin being updated, not just the URL: the
			// URL in the update transient can predate the latest catalog refresh.
			$package = null;
			if ( ! empty( $hook_extra['plugin'] ) ) {
				$package = $this->get_package_for_installed_plugin( $hook_extra['plugin'] );
			}
			if ( ! $package ) {
				$package = $this->catalog->get_package_by_download_url( $url );
			}
			if ( ! $package ) {
				return $reply;
			}

			if ( ! self::url_has_allowed_prefix( $url, self::get_allowed_download_prefixes() ) ) {
				return new WP_Error(
					'ucf_plugin_directory_download_not_allowed',
					sprintf( 'Refusing to download %1$s: %2$s is not an allowed UCF download location.', $package->get_name(), esc_html( $url ) )
				);
			}

			if ( ! self::url_has_allowed_prefix( $package->get_repository(), self::get_allowed_repository_prefixes() ) ) {
				return new WP_Error(
					'ucf_plugin_directory_repository_not_allowed',
					sprintf( 'Refusing to install %1$s: its source repository %2$s is not in the UCF GitHub organization.', $package->get_name(), esc_html( $package->get_repository() ) )
				);
			}

			$is_install = empty( $hook_extra['plugin'] ) && empty( $hook_extra['theme'] );

			if ( $is_install && $package->is_plugin() ) {
				$installed = self::find_installed_plugin( $this->catalog, $package );
				if ( $installed ) {
					return new WP_Error(
						'ucf_plugin_directory_already_installed',
						sprintf( '%1$s is already installed in %2$s/. Update it from the Plugins screen instead.', $package->get_display_name(), dirname( $installed ) )
					);
				}
			}

			$this->current = $package;

			return $reply;
		}

		/**
		 * GitHub zipballs extract to "UCF-Repo-Name-abc1234/". Rename that to
		 * the directory the plugin already lives in (updates), or to the slug
		 * Composer would use (fresh installs).
		 */
		public function source_selection( $source, $remote_source, $upgrader, $hook_extra = array() ) {
			if ( ! $this->current || is_wp_error( $source ) ) {
				return $source;
			}

			$package       = $this->current;
			$this->current = null;

			if ( ! empty( $hook_extra['plugin'] ) ) {
				$target = dirname( $hook_extra['plugin'] );
				if ( '.' === $target ) {
					// Single-file plugin in the plugins root; nothing to rename to.
					return $source;
				}
			} elseif ( ! empty( $hook_extra['theme'] ) ) {
				$target = $hook_extra['theme'];
			} else {
				$target = $package->get_slug();
			}

			$target = self::rename_source( $source, $remote_source, $target );

			/**
			 * Fires after a UCF package's extracted folder has been selected.
			 *
			 * @param string|WP_Error              $target  New source path
			 * @param UCF_Plugin_Directory_Package $package
			 * @param array                        $hook_extra
			 */
			do_action( 'ucf_plugin_directory_source_selected', $target, $package, $hook_extra );

			return $target;
		}

		/**
		 * @return string|WP_Error The renamed source path, with trailing slash
		 */
		public static function rename_source( $source, $remote_source, $target ) {
			global $wp_filesystem;

			if ( untrailingslashit( basename( $source ) ) === $target ) {
				return $source;
			}

			$new_source = trailingslashit( $remote_source ) . $target;

			if ( ! $wp_filesystem || ! $wp_filesystem->move( untrailingslashit( $source ), $new_source, true ) ) {
				return new WP_Error(
					'ucf_plugin_directory_rename_failed',
					sprintf( 'Could not rename the downloaded folder to %s/.', $target )
				);
			}

			return trailingslashit( $new_source );
		}

		/**
		 * Adds the optional GitHub token (defined in wp-config.php) to
		 * requests for allowlisted GitHub download URLs only.
		 */
		public function add_github_token( $args, $url ) {
			if ( ! defined( 'UCF_PLUGIN_DIRECTORY_GITHUB_TOKEN' ) || ! UCF_PLUGIN_DIRECTORY_GITHUB_TOKEN ) {
				return $args;
			}

			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( ! in_array( $host, array( 'api.github.com', 'codeload.github.com', 'github.com' ), true ) ) {
				return $args;
			}

			if ( ! self::url_has_allowed_prefix( $url, self::get_allowed_download_prefixes() ) ) {
				return $args;
			}

			if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
				$args['headers'] = array();
			}

			$args['headers']['Authorization'] = 'Bearer ' . UCF_PLUGIN_DIRECTORY_GITHUB_TOKEN;

			return $args;
		}

		/**
		 * The catalog package an installed plugin is updated from, using the
		 * same rules as the updater: Update URI always, fallback matching only
		 * when it's enabled.
		 *
		 * @return UCF_Plugin_Directory_Package|null
		 */
		private function get_package_for_installed_plugin( $plugin_file ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$plugins = get_plugins();
			if ( ! isset( $plugins[ $plugin_file ] ) ) {
				return null;
			}

			$plugin_data = $plugins[ $plugin_file ];

			if ( ! empty( $plugin_data['UpdateURI'] ) ) {
				return $this->catalog->get_package_by_update_uri( $plugin_data['UpdateURI'] );
			}

			if ( UCF_Plugin_Directory_Updater::is_fallback_enabled() ) {
				return $this->catalog->match_installed_plugin( $plugin_file, $plugin_data );
			}

			return null;
		}

		/**
		 * Finds where (if anywhere) a catalog plugin is installed, whatever
		 * directory it's in.
		 *
		 * @return string|null Plugin basename, e.g. UCF-FAQ-CPT/ucf-faq.php
		 */
		public static function find_installed_plugin( UCF_Plugin_Directory_Catalog $catalog, UCF_Plugin_Directory_Package $package ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			foreach ( get_plugins() as $plugin_file => $plugin_data ) {
				if ( ! empty( $plugin_data['UpdateURI'] ) ) {
					$match = $catalog->get_package_by_update_uri( $plugin_data['UpdateURI'] );
				} else {
					$match = $catalog->match_installed_plugin( $plugin_file, $plugin_data );
				}

				if ( $match && $match->get_name() === $package->get_name() ) {
					return $plugin_file;
				}
			}

			return null;
		}
	}
}
