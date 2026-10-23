<?php
/**
 * `wp ucf` command: list, search, and refresh the UCF package catalog
 */
if ( ! class_exists( 'UCF_Plugin_Directory_CLI' ) ) {
	/**
	 * Lists, searches, and refreshes the UCF plugin and theme catalog.
	 *
	 * To install or update UCF plugins, use `wp plugin install <slug>` and
	 * `wp plugin update`; they work for UCF slugs while this plugin is active.
	 */
	class UCF_Plugin_Directory_CLI {
		const DEFAULT_FIELDS = array( 'slug', 'title', 'type', 'version', 'installed', 'installed_version', 'abandoned' );

		private $catalog;

		public function __construct( ?UCF_Plugin_Directory_Catalog $catalog = null ) {
			$this->catalog = $catalog ? $catalog : UCF_Plugin_Directory::catalog();
		}

		/**
		 * Lists UCF packages in the catalog.
		 *
		 * ## OPTIONS
		 *
		 * [--type=<type>]
		 * : Only show one kind of package.
		 * ---
		 * options:
		 *   - plugin
		 *   - theme
		 * ---
		 *
		 * [--installed]
		 * : Only show packages installed on this site.
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields to show. Available: name, slug, title,
		 * type, version, installed, installed_version, abandoned, updated,
		 * repository.
		 *
		 * [--format=<format>]
		 * : Output format.
		 * ---
		 * default: table
		 * options:
		 *   - table
		 *   - csv
		 *   - json
		 *   - yaml
		 *   - count
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     # Every UCF plugin, and where it's installed on this site
		 *     $ wp ucf list --type=plugin
		 *
		 *     # Installed UCF plugins with a newer release available
		 *     $ wp ucf list --installed --format=json
		 *
		 * @subcommand list
		 */
		public function list_( $args, $assoc_args ) {
			$this->maybe_fetch();

			$rows = $this->get_rows( $this->catalog->get_packages(), $assoc_args );
			$this->output( $rows, $assoc_args );
		}

		/**
		 * Searches UCF packages by name, slug, and description.
		 *
		 * Every word in the search must match. Abandoned packages are included
		 * and marked, so existing installs can still be found.
		 *
		 * ## OPTIONS
		 *
		 * <search>
		 * : Words to search for.
		 *
		 * [--type=<type>]
		 * : Only show one kind of package.
		 * ---
		 * options:
		 *   - plugin
		 *   - theme
		 * ---
		 *
		 * [--fields=<fields>]
		 * : Comma-separated fields to show. See `wp ucf list` for the list.
		 *
		 * [--format=<format>]
		 * : Output format.
		 * ---
		 * default: table
		 * options:
		 *   - table
		 *   - csv
		 *   - json
		 *   - yaml
		 *   - count
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     $ wp ucf search degree
		 *     $ wp ucf search "news block" --type=theme
		 */
		public function search( $args, $assoc_args ) {
			$this->maybe_fetch();

			$packages = array_filter(
				$this->catalog->get_packages(),
				UCF_Plugin_Directory_Plugins_API::make_search_callback( trim( implode( ' ', $args ) ) )
			);

			$this->output( $this->get_rows( $packages, $assoc_args ), $assoc_args );
		}

		/**
		 * Refreshes the catalog from the package repository.
		 *
		 * Unchanged packages answer HTTP 304 and keep their cached data. If the
		 * refresh fails, the last good catalog stays in place.
		 *
		 * ## OPTIONS
		 *
		 * [--force]
		 * : Re-download every package's metadata, ignoring Last-Modified.
		 *
		 * ## EXAMPLES
		 *
		 *     $ wp ucf refresh
		 *     $ wp ucf refresh --force
		 */
		public function refresh( $args, $assoc_args ) {
			$force = ! empty( $assoc_args['force'] );

			WP_CLI::log( sprintf( 'Refreshing from %s...', $this->catalog->get_client()->get_repository_url() ) );

			$started = microtime( true );
			$result  = $this->catalog->refresh( $force );
			$status  = $this->catalog->get_status();

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( sprintf( 'Refresh failed; the last good catalog was kept. %s', $result->get_error_message() ) );
				return;
			}

			if ( '' !== $status['last_error'] ) {
				foreach ( explode( "\n", $status['last_error'] ) as $error ) {
					WP_CLI::warning( $error );
				}
			}

			$counts = $this->count_packages();

			WP_CLI::success(
				sprintf(
					'%d downloaded, %d unchanged in %.1fs. Catalog: %d plugins, %d themes (%d abandoned).',
					isset( $status['stats']['fetched'] ) ? $status['stats']['fetched'] : 0,
					isset( $status['stats']['unchanged'] ) ? $status['stats']['unchanged'] : 0,
					microtime( true ) - $started,
					$counts['plugins'],
					$counts['themes'],
					$counts['abandoned']
				)
			);
		}

		/**
		 * Builds output rows, applying the --type and --installed filters.
		 * Private so WP-CLI doesn't register it as a subcommand.
		 *
		 * @param UCF_Plugin_Directory_Package[] $packages
		 * @param array                          $assoc_args
		 * @return array[]
		 */
		private function get_rows( array $packages, array $assoc_args ) {
			$type           = isset( $assoc_args['type'] ) ? $assoc_args['type'] : '';
			$installed_only = ! empty( $assoc_args['installed'] );
			$rows           = array();

			foreach ( $packages as $package ) {
				if ( ( 'plugin' === $type && ! $package->is_plugin() ) || ( 'theme' === $type && ! $package->is_theme() ) ) {
					continue;
				}

				$installed = $package->is_plugin() ? $this->find_installed_plugin( $package ) : $this->find_installed_theme( $package );

				if ( $installed_only && ! $installed ) {
					continue;
				}

				$abandoned = $package->get_abandoned();

				$rows[] = array(
					'name'              => $package->get_name(),
					'slug'              => $package->get_slug(),
					'title'             => $package->get_display_name(),
					'type'              => $package->is_plugin() ? 'plugin' : 'theme',
					'version'           => $package->get_version(),
					'installed'         => $installed ? $installed['location'] : '',
					'installed_version' => $installed ? $installed['version'] : '',
					'abandoned'         => is_string( $abandoned ) ? 'yes, use ' . $abandoned : ( $abandoned ? 'yes' : '' ),
					'updated'           => $package->get_time() ? substr( $package->get_time(), 0, 10 ) : '',
					'repository'        => $package->get_repository(),
				);
			}

			usort(
				$rows,
				function ( $a, $b ) {
					return strcmp( $a['type'] . $a['slug'], $b['type'] . $b['slug'] );
				}
			);

			return $rows;
		}

		/**
		 * @return array|null { location, version }
		 */
		private function find_installed_plugin( UCF_Plugin_Directory_Package $package ) {
			$file = UCF_Plugin_Directory_Upgrader::find_installed_plugin( $this->catalog, $package );
			if ( ! $file ) {
				return null;
			}

			$plugins = get_plugins();

			return array(
				'location' => dirname( $file ) . '/',
				'version'  => isset( $plugins[ $file ]['Version'] ) ? $plugins[ $file ]['Version'] : '',
			);
		}

		/**
		 * Themes are matched by folder name against the package slug or the
		 * GitHub repo name, ignoring case (e.g. Colleges-Theme/).
		 *
		 * @return array|null { location, version }
		 */
		private function find_installed_theme( UCF_Plugin_Directory_Package $package ) {
			$repo  = strtolower( $package->get_github_repo() );
			$names = array_filter( array( $package->get_slug(), $repo ? substr( $repo, strpos( $repo, '/' ) + 1 ) : '' ) );

			foreach ( wp_get_themes() as $stylesheet => $theme ) {
				if ( in_array( strtolower( $stylesheet ), $names, true ) ) {
					return array(
						'location' => $stylesheet . '/',
						'version'  => (string) $theme->get( 'Version' ),
					);
				}
			}

			return null;
		}

		private function count_packages() {
			$counts = array(
				'plugins'   => 0,
				'themes'    => 0,
				'abandoned' => 0,
			);

			foreach ( $this->catalog->get_packages() as $package ) {
				++$counts[ $package->is_plugin() ? 'plugins' : 'themes' ];
				if ( $package->is_abandoned() ) {
					++$counts['abandoned'];
				}
			}

			return $counts;
		}

		/**
		 * The CLI may fetch (unlike front-end requests), so fill an empty
		 * cache rather than printing nothing.
		 */
		private function maybe_fetch() {
			if ( ! $this->catalog->is_empty() ) {
				return;
			}

			WP_CLI::log( 'The catalog cache is empty; fetching it now...' );

			$result = $this->catalog->refresh();
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( sprintf( 'Could not load the catalog: %s', $result->get_error_message() ) );
			}
		}

		private function output( array $rows, array $assoc_args ) {
			$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
			$fields = isset( $assoc_args['fields'] ) ? array_map( 'trim', explode( ',', $assoc_args['fields'] ) ) : self::DEFAULT_FIELDS;

			// An empty table prints only its header, which reads like an error.
			if ( ! $rows && 'table' === $format ) {
				WP_CLI::log( 'No packages matched.' );
				return;
			}

			WP_CLI\Utils\format_items( $format, $rows, $fields );
		}
	}
}
