<?php
/**
 * Bootstrap: wires the components together
 */
if ( ! class_exists( 'UCF_Plugin_Directory' ) ) {
	class UCF_Plugin_Directory {
		private static $catalog = null;

		/**
		 * @return UCF_Plugin_Directory_Catalog
		 */
		public static function catalog() {
			if ( null === self::$catalog ) {
				$repository    = (string) apply_filters( 'ucf_plugin_directory_repository', UCF_PLUGIN_DIRECTORY_REPOSITORY );
				self::$catalog = new UCF_Plugin_Directory_Catalog( new UCF_Plugin_Directory_Repository_Client( $repository ) );
			}

			return self::$catalog;
		}

		public static function init() {
			$catalog = self::catalog();

			add_action( UCF_Plugin_Directory_Catalog::CRON_HOOK, array( $catalog, 'refresh' ) );

			// plugins_api is needed in admin and WP-CLI; it only reads the cache.
			if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				( new UCF_Plugin_Directory_Plugins_API( $catalog ) )->register();
				( new UCF_Plugin_Directory_Settings( $catalog ) )->register();
			}

			// Update checks also run from cron. They only read the cache.
			( new UCF_Plugin_Directory_Updater( $catalog ) )->register();

			if ( wp_is_file_mod_allowed( 'ucf_plugin_directory' ) ) {
				( new UCF_Plugin_Directory_Upgrader( $catalog ) )->register();
			}

			if ( is_admin() && is_main_site() ) {
				add_action( 'admin_init', array( 'UCF_Plugin_Directory_Catalog', 'schedule' ) );
			}
		}

		public static function activate() {
			if ( is_main_site() ) {
				UCF_Plugin_Directory_Catalog::schedule();
			}
		}

		public static function deactivate() {
			UCF_Plugin_Directory_Catalog::unschedule();
		}
	}
}
