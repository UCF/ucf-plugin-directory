<?php
/**
 * Offers updates for installed UCF plugins
 */
if ( ! class_exists( 'UCF_Plugin_Directory_Updater' ) ) {
	class UCF_Plugin_Directory_Updater {
		const FALLBACK_OPTION = 'ucf_plugin_directory_fallback_matching';

		private $catalog;

		public function __construct( UCF_Plugin_Directory_Catalog $catalog ) {
			$this->catalog = $catalog;
		}

		public function register() {
			add_filter( 'extra_plugin_headers', array( $this, 'extra_plugin_headers' ) );
			add_filter( 'update_plugins_packagist.org', array( $this, 'update_by_uri' ), 10, 2 );
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'add_fallback_updates' ) );
			add_action( 'ucf_plugin_directory_refreshed', array( __CLASS__, 'flush_update_check' ) );
		}

		/**
		 * UCF plugins already declare "GitHub Plugin URI: UCF/<Repo>"; read it
		 * so fallback matching can use it.
		 */
		public function extra_plugin_headers( $headers ) {
			$headers[] = 'GitHub Plugin URI';
			return $headers;
		}

		public static function is_fallback_enabled() {
			return (bool) get_site_option( self::FALLBACK_OPTION, false );
		}

		/**
		 * Handles plugins with "Update URI: https://packagist.org/packages/<vendor>/<package>".
		 * Returns $update unchanged (false) for vendors outside the allowlist
		 * or packages we don't know, so core behaves normally.
		 */
		public function update_by_uri( $update, $plugin_data ) {
			if ( empty( $plugin_data['UpdateURI'] ) ) {
				return $update;
			}

			$package = $this->catalog->get_package_by_update_uri( $plugin_data['UpdateURI'] );
			if ( ! $package || ! $package->is_plugin() ) {
				return $update;
			}

			return $this->update_data( $package );
		}

		/**
		 * Opt-in: offer updates to UCF plugins that don't declare an Update
		 * URI, matched by GitHub Plugin URI header or directory name.
		 */
		public function add_fallback_updates( $transient ) {
			if ( ! self::is_fallback_enabled() || ! is_object( $transient ) || empty( $transient->checked ) ) {
				return $transient;
			}

			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			foreach ( get_plugins() as $plugin_file => $plugin_data ) {
				if ( ! empty( $plugin_data['UpdateURI'] ) ) {
					// Core (or another updater) owns plugins that declare one.
					continue;
				}

				$package = $this->catalog->match_installed_plugin( $plugin_file, $plugin_data );
				if ( ! $package ) {
					continue;
				}

				$update = (object) $this->update_data( $package );

				$update->id          = $package->get_name();
				$update->plugin      = $plugin_file;
				$update->new_version = $update->version;

				if ( ! isset( $transient->response ) ) {
					$transient->response = array();
				}
				if ( ! isset( $transient->no_update ) ) {
					$transient->no_update = array();
				}

				unset( $transient->response[ $plugin_file ], $transient->no_update[ $plugin_file ] );

				if ( version_compare( $package->get_version(), $plugin_data['Version'], '>' ) ) {
					$transient->response[ $plugin_file ] = $update;
				} else {
					$transient->no_update[ $plugin_file ] = $update;
				}
			}

			return $transient;
		}

		private function update_data( UCF_Plugin_Directory_Package $package ) {
			$data = array(
				'slug'    => $package->get_slug(),
				'version' => $package->get_version(),
				'url'     => $package->get_homepage(),
				'package' => UCF_Plugin_Directory_Catalog::get_download_url( $package ),
				'icons'   => array( 'default' => UCF_Plugin_Directory_Plugins_API::icon_url() ),
			);

			if ( $package->get_tested() ) {
				$data['tested'] = $package->get_tested();
			}
			if ( $package->get_requires_php() ) {
				$data['requires_php'] = $package->get_requires_php();
			}

			return $data;
		}

		/**
		 * Forces WordPress to recheck plugin updates on the next admin load,
		 * so a catalog refresh shows up right away.
		 */
		public static function flush_update_check() {
			delete_site_transient( 'update_plugins' );
		}
	}
}
