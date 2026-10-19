<?php
/**
 * Settings and diagnostics screen
 */
if ( ! class_exists( 'UCF_Plugin_Directory_Settings' ) ) {
	class UCF_Plugin_Directory_Settings {
		const PAGE = 'ucf-plugin-directory';

		private $catalog;

		public function __construct( UCF_Plugin_Directory_Catalog $catalog ) {
			$this->catalog = $catalog;
		}

		public function register() {
			add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( $this, 'add_page' ) );
			add_action( 'admin_post_ucf_plugin_directory_save', array( $this, 'handle_save' ) );
			add_action( 'admin_post_ucf_plugin_directory_refresh', array( $this, 'handle_refresh' ) );
		}

		public static function capability() {
			return is_multisite() ? 'manage_network_options' : 'manage_options';
		}

		public static function page_url( $args = array() ) {
			$base = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' );
			return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), $base );
		}

		public function add_page() {
			add_submenu_page(
				is_multisite() ? 'settings.php' : 'options-general.php',
				'UCF Plugin Directory',
				'UCF Plugin Directory',
				self::capability(),
				self::PAGE,
				array( $this, 'render' )
			);
		}

		public function render() {
			if ( ! current_user_can( self::capability() ) ) {
				return;
			}

			$status   = $this->catalog->get_status();
			$packages = $this->catalog->get_packages();
			$counts   = array(
				'plugins'   => 0,
				'themes'    => 0,
				'abandoned' => 0,
			);

			foreach ( $packages as $package ) {
				++$counts[ $package->is_plugin() ? 'plugins' : 'themes' ];
				if ( $package->is_abandoned() ) {
					++$counts['abandoned'];
				}
			}

			$message  = isset( $_GET['ucf-pd-message'] ) ? sanitize_key( $_GET['ucf-pd-message'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$messages = array(
				'saved'          => array( 'success', 'Settings saved.' ),
				'refreshed'      => array( 'success', 'Catalog refreshed.' ),
				'refresh-failed' => array( 'error', 'The refresh failed. See the last error below.' ),
			);
			?>
			<div class="wrap">
				<h1>UCF Plugin Directory</h1>

				<?php if ( isset( $messages[ $message ] ) ) : ?>
					<div class="notice notice-<?php echo esc_attr( $messages[ $message ][0] ); ?> is-dismissible"><p><?php echo esc_html( $messages[ $message ][1] ); ?></p></div>
				<?php endif; ?>

				<h2>Status</h2>
				<table class="widefat striped" style="max-width: 900px;">
					<tbody>
						<tr><th scope="row">Repository</th><td><code><?php echo esc_html( $this->catalog->get_client()->get_repository_url() ); ?></code></td></tr>
						<tr><th scope="row">Last refresh</th><td><?php echo $status['last_refresh'] ? esc_html( sprintf( '%s ago', human_time_diff( $status['last_refresh'] ) ) ) : 'Never'; ?></td></tr>
						<tr><th scope="row">Next scheduled refresh</th><td>
						<?php
						$next = wp_next_scheduled( UCF_Plugin_Directory_Catalog::CRON_HOOK );
						echo $next ? esc_html( wp_date( 'Y-m-d H:i:s T', $next ) ) : 'Not scheduled';
						?>
						</td></tr>
						<tr><th scope="row">Last error</th><td><?php echo $status['last_error'] ? '<pre style="white-space: pre-wrap; margin: 0;">' . esc_html( $status['last_error'] ) . '</pre>' : 'None'; ?></td></tr>
						<tr><th scope="row">Catalog</th><td><?php echo esc_html( sprintf( '%d plugins, %d themes (%d abandoned)', $counts['plugins'], $counts['themes'], $counts['abandoned'] ) ); ?></td></tr>
						<?php if ( ! empty( $status['stats'] ) ) : ?>
						<tr><th scope="row">Last fetch</th><td><?php echo esc_html( sprintf( '%d package files downloaded, %d unchanged (HTTP 304)', $status['stats']['fetched'], $status['stats']['unchanged'] ) ); ?></td></tr>
						<?php endif; ?>
						<tr><th scope="row">GitHub token</th><td><?php echo ( defined( 'UCF_PLUGIN_DIRECTORY_GITHUB_TOKEN' ) && UCF_PLUGIN_DIRECTORY_GITHUB_TOKEN ) ? 'Configured' : 'Not configured (not normally needed; downloads use codeload.github.com, which is not subject to the GitHub API limit)'; ?></td></tr>
						<tr><th scope="row">File modifications</th><td><?php echo wp_is_file_mod_allowed( 'ucf_plugin_directory' ) ? 'Allowed' : 'Disabled (DISALLOW_FILE_MODS); the catalog is read-only on this site'; ?></td></tr>
					</tbody>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 1em;">
					<input type="hidden" name="action" value="ucf_plugin_directory_refresh">
					<?php wp_nonce_field( 'ucf_plugin_directory_refresh' ); ?>
					<?php submit_button( 'Refresh now', 'secondary', 'submit', false ); ?>
				</form>

				<h2>Settings</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ucf_plugin_directory_save">
					<?php wp_nonce_field( 'ucf_plugin_directory_save' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">Plugins without an Update URI</th>
							<td>
								<label>
									<input type="checkbox" name="fallback_matching" value="1" <?php checked( UCF_Plugin_Directory_Updater::is_fallback_enabled() ); ?>>
									Offer updates to installed UCF plugins that don't declare <code>Update URI</code>
								</label>
								<p class="description">Matches by the plugin's <code>GitHub Plugin URI</code> header, or by its folder name when that header is missing. Plugins that declare an <code>Update URI</code> are always handled.</p>
							</td>
						</tr>
					</table>
					<?php submit_button(); ?>
				</form>
			</div>
			<?php
		}

		public function handle_save() {
			if ( ! current_user_can( self::capability() ) ) {
				wp_die( 'You are not allowed to change these settings.', 403 );
			}

			check_admin_referer( 'ucf_plugin_directory_save' );

			update_site_option( UCF_Plugin_Directory_Updater::FALLBACK_OPTION, ! empty( $_POST['fallback_matching'] ) );
			UCF_Plugin_Directory_Updater::flush_update_check();

			wp_safe_redirect( self::page_url( array( 'ucf-pd-message' => 'saved' ) ) );
			exit;
		}

		public function handle_refresh() {
			if ( ! current_user_can( self::capability() ) ) {
				wp_die( 'You are not allowed to refresh the catalog.', 403 );
			}

			check_admin_referer( 'ucf_plugin_directory_refresh' );

			$result = $this->catalog->refresh();

			wp_safe_redirect( self::page_url( array( 'ucf-pd-message' => is_wp_error( $result ) ? 'refresh-failed' : 'refreshed' ) ) );
			exit;
		}
	}
}
