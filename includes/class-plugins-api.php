<?php
/**
 * Adds the UCF tab to Plugins → Add New and answers plugins_api for UCF slugs
 */
if ( ! class_exists( 'UCF_Plugin_Directory_Plugins_API' ) ) {
	class UCF_Plugin_Directory_Plugins_API {
		const TAB    = 'ucf';
		const MARKER = 'ucf_plugin_directory';
		const AUTHOR = 'UCF Web Communications';

		private $catalog;

		public function __construct( UCF_Plugin_Directory_Catalog $catalog ) {
			$this->catalog = $catalog;
		}

		public function register() {
			add_filter( 'install_plugins_tabs', array( $this, 'add_tab' ) );
			add_filter( 'install_plugins_table_api_args_' . self::TAB, array( $this, 'table_api_args' ) );
			add_action( 'install_plugins_pre_' . self::TAB, array( $this->catalog, 'maybe_fetch_when_empty' ) );
			add_action( 'install_plugins_' . self::TAB, array( $this, 'render_tab' ) );
			add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
			add_filter( 'plugin_install_action_links', array( $this, 'action_links' ), 10, 2 );
			add_action( 'admin_head-plugin-install.php', array( $this, 'tab_styles' ) );
		}

		public function add_tab( $tabs ) {
			// Put the UCF tab first.
			return array( self::TAB => 'UCF' ) + $tabs;
		}

		public function table_api_args( $args ) {
			$args = is_array( $args ) ? $args : array();

			$args[ self::MARKER ] = true;
			$args['page']         = isset( $_REQUEST['paged'] ) ? max( 1, absint( $_REQUEST['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['per_page']     = 36;
			$args['search']       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			return $args;
		}

		/**
		 * Renders the search box and the native plugin cards for the UCF tab.
		 */
		public function render_tab() {
			$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$status = $this->catalog->get_status();
			?>
			<form class="ucf-plugin-directory-search" method="get">
				<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB ); ?>">
				<label class="screen-reader-text" for="ucf-plugin-directory-search-input">Search UCF plugins</label>
				<input type="search" id="ucf-plugin-directory-search-input" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search UCF plugins…">
				<?php submit_button( 'Search', '', '', false ); ?>
				<span class="description">
					<?php
					if ( $status['last_refresh'] ) {
						printf(
							'Catalog from %1$s, updated %2$s ago.',
							esc_html( wp_parse_url( $this->catalog->get_client()->get_repository_url(), PHP_URL_HOST ) ),
							esc_html( human_time_diff( $status['last_refresh'] ) )
						);
					}
					?>
				</span>
			</form>
			<?php
			if ( $this->catalog->is_empty() ) {
				echo '<div class="notice notice-warning inline"><p>The UCF plugin catalog could not be loaded yet.';
				if ( $status['last_error'] ) {
					echo ' Last error: <code>' . esc_html( $status['last_error'] ) . '</code>';
				}
				echo '</p></div>';
			}

			display_plugins_table();
		}

		/**
		 * Hides the wordpress.org-only columns (ratings, install counts,
		 * compatibility) that have no data for UCF plugins.
		 */
		public function tab_styles() {
			if ( ! isset( $_GET['tab'] ) || self::TAB !== $_GET['tab'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}
			?>
			<style>
				.ucf-plugin-directory-search { margin: 1em 0; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
				.ucf-plugin-directory-search input[type=search] { min-width: 280px; }
				.plugin-card .column-rating,
				.plugin-card .column-downloaded,
				.plugin-card .column-compatibility { display: none; }
			</style>
			<?php
		}

		/**
		 * Answers plugins_api for the UCF tab and for UCF slugs. Everything
		 * else passes through to wordpress.org untouched.
		 */
		public function plugins_api( $result, $action, $args ) {
			$args = (object) $args;

			if ( 'query_plugins' === $action && ! empty( $args->{ self::MARKER } ) ) {
				return $this->query_plugins( $args );
			}

			if ( 'plugin_information' === $action && ! empty( $args->slug ) ) {
				$package = $this->catalog->get_plugin_by_slug( $args->slug );
				if ( $package ) {
					return $this->plugin_information( $package );
				}
			}

			return $result;
		}

		private function query_plugins( $args ) {
			$search   = isset( $args->search ) ? trim( (string) $args->search ) : '';
			$page     = isset( $args->page ) ? max( 1, (int) $args->page ) : 1;
			$per_page = isset( $args->per_page ) ? max( 1, (int) $args->per_page ) : 36;

			$packages = array_values( $this->catalog->get_plugins() );

			if ( '' !== $search ) {
				$packages = array_values( array_filter( $packages, $this->make_search_callback( $search ) ) );
			}

			usort(
				$packages,
				function ( $a, $b ) {
					return strcasecmp( $a->get_display_name(), $b->get_display_name() );
				}
			);

			$total = count( $packages );
			$items = array_slice( $packages, ( $page - 1 ) * $per_page, $per_page );

			return (object) array(
				'info'    => array(
					'page'    => $page,
					'pages'   => (int) ceil( $total / $per_page ),
					'results' => $total,
				),
				'plugins' => array_map( array( $this, 'card_data' ), $items ),
			);
		}

		/**
		 * Every search term must appear in the name, slug, or description.
		 */
		public function make_search_callback( $search ) {
			$terms = preg_split( '/\s+/', strtolower( $search ) );

			return function ( $package ) use ( $terms ) {
				$haystack = strtolower( $package->get_display_name() . ' ' . $package->get_slug() . ' ' . $package->get_description() );
				foreach ( $terms as $term ) {
					if ( false === strpos( $haystack, $term ) ) {
						return false;
					}
				}
				return true;
			};
		}

		/**
		 * The array shape WP_Plugin_Install_List_Table expects for a card.
		 */
		public function card_data( UCF_Plugin_Directory_Package $package ) {
			return array(
				'name'              => esc_html( $package->get_display_name() ),
				'slug'              => $package->get_slug(),
				'version'           => $package->get_version(),
				'author'            => self::AUTHOR,
				'short_description' => wp_strip_all_tags( $package->get_description() ),
				'description'       => wp_strip_all_tags( $package->get_description() ),
				'icons'             => array( 'default' => self::icon_url() ),
				'rating'            => 0,
				'num_ratings'       => 0,
				'active_installs'   => 0,
				'last_updated'      => $package->get_time(),
				'homepage'          => $package->get_homepage(),
				'requires'          => $package->get_requires(),
				'tested'            => $package->get_tested(),
				'requires_php'      => $package->get_requires_php(),
				'requires_plugins'  => array(),
				'download_link'     => UCF_Plugin_Directory_Catalog::get_download_url( $package ),
			);
		}

		/**
		 * Response for plugin_information requests; also powers the "More Details" modal.
		 */
		public function plugin_information( UCF_Plugin_Directory_Package $package ) {
			$description = '<p>' . esc_html( $package->get_description() ? $package->get_description() : 'No description provided.' ) . '</p>';

			if ( $package->is_abandoned() ) {
				$replacement  = is_string( $package->get_abandoned() ) ? $package->get_abandoned() : '';
				$description .= '<p><strong>This package is abandoned and is no longer maintained.</strong>';
				if ( $replacement ) {
					$description .= ' Use <code>' . esc_html( $replacement ) . '</code> instead.';
				}
				$description .= '</p>';
			}

			$details = sprintf(
				'<ul><li>Composer package: <code>%1$s</code></li><li>Source: <a href="%2$s" target="_blank" rel="noopener">%3$s</a></li></ul>',
				esc_html( $package->get_name() ),
				esc_url( $package->get_repository() ),
				esc_html( $package->get_github_repo() ? $package->get_github_repo() : $package->get_repository() )
			);

			$changelog = '';
			if ( $package->get_github_repo() ) {
				$changelog = sprintf(
					'<p>See the <a href="%s" target="_blank" rel="noopener">release notes on GitHub</a>.</p>',
					esc_url( 'https://github.com/' . $package->get_github_repo() . '/releases' )
				);
			}

			$info = array(
				'name'            => esc_html( $package->get_display_name() ),
				'slug'            => $package->get_slug(),
				'version'         => $package->get_version(),
				'author'          => self::AUTHOR,
				'author_profile'  => 'https://github.com/UCF',
				'homepage'        => $package->get_homepage(),
				'requires'        => $package->get_requires(),
				'tested'          => $package->get_tested(),
				'requires_php'    => $package->get_requires_php(),
				'last_updated'    => $package->get_time(),
				'download_link'   => UCF_Plugin_Directory_Catalog::get_download_url( $package ),
				'sections'        => array_filter(
					array(
						'description' => $description . $details,
						'changelog'   => $changelog,
					)
				),
				'icons'           => array( 'default' => self::icon_url() ),
				'banners'         => array(),
				'rating'          => 0,
				'ratings'         => array(),
				'num_ratings'     => 0,
				'active_installs' => 0,
				'contributors'    => array(),
				'tags'            => array(),
				'versions'        => array(),
			);

			return (object) $info;
		}

		/**
		 * Replaces "Install Now" with a disabled "Installed" button when the
		 * plugin is already installed under a different directory name (for
		 * example UCF-FAQ-CPT/ instead of ucf-faq-cpt/), so we never install
		 * a second copy.
		 */
		public function action_links( $links, $plugin ) {
			$package = isset( $plugin['slug'] ) ? $this->catalog->get_plugin_by_slug( $plugin['slug'] ) : null;
			if ( ! $package ) {
				return $links;
			}

			$status = install_plugin_install_status( $plugin );
			if ( 'update_available' === $status['status'] ) {
				return $links;
			}

			// Core looks for the plugin in <slug>/ only. On case-insensitive
			// filesystems it finds UCF-FAQ-CPT/ that way but builds links with
			// the wrong path, so compare the real directory exactly.
			$installed = UCF_Plugin_Directory_Upgrader::find_installed_plugin( $this->catalog, $package );
			if ( ! $installed || dirname( $installed ) === $package->get_slug() ) {
				return $links;
			}

			$links[0] = sprintf(
				'<button type="button" class="button button-disabled" disabled="disabled" title="%1$s">%2$s</button>',
				esc_attr( sprintf( 'Installed in %s/', dirname( $installed ) ) ),
				esc_html_x( 'Installed', 'plugin' )
			);

			return $links;
		}

		public static function icon_url() {
			return UCF_PLUGIN_DIRECTORY__PLUGIN_URL . '/assets/icon.svg';
		}
	}
}
