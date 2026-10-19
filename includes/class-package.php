<?php
/**
 * Value object describing one package's resolved (latest stable) release
 */
if ( ! class_exists( 'UCF_Plugin_Directory_Package' ) ) {
	class UCF_Plugin_Directory_Package {
		const TYPE_PLUGIN = 'wordpress-plugin';
		const TYPE_THEME  = 'wordpress-theme';

		/**
		 * Words that should be upper-cased (or specially cased) when deriving
		 * a display name from a package slug.
		 */
		private static $display_words = array(
			'ucf'   => 'UCF',
			'cpt'   => 'CPT',
			'faq'   => 'FAQ',
			'rss'   => 'RSS',
			'wp'    => 'WP',
			'css'   => 'CSS',
			'vtt'   => 'VTT',
			'bot'   => 'BOT',
			'gmucf' => 'GMUCF',
			'myucf' => 'MyUCF',
			'rest'  => 'REST',
		);

		private $data;

		private function __construct( array $data ) {
			$this->data = $data;
		}

		/**
		 * Builds a package from an expanded p2 version entry.
		 *
		 * @param array $version Expanded version metadata
		 * @param array $meta    Optional list-level metadata (abandoned, repository)
		 * @return UCF_Plugin_Directory_Package|null
		 */
		public static function from_version( array $version, array $meta = array() ) {
			if ( empty( $version['name'] ) || empty( $version['version'] ) ) {
				return null;
			}

			$name       = strtolower( $version['name'] );
			$repository = '';

			if ( ! empty( $meta['repository'] ) ) {
				$repository = $meta['repository'];
			} elseif ( ! empty( $version['source']['url'] ) ) {
				$repository = $version['source']['url'];
			}

			$repository = preg_replace( '#\.git$#', '', (string) $repository );

			$abandoned = false;
			if ( isset( $meta['abandoned'] ) && $meta['abandoned'] ) {
				$abandoned = $meta['abandoned'];
			} elseif ( isset( $version['abandoned'] ) && $version['abandoned'] ) {
				$abandoned = $version['abandoned'];
			}

			$wordpress = array();
			if ( isset( $version['extra']['wordpress'] ) && is_array( $version['extra']['wordpress'] ) ) {
				$wordpress = $version['extra']['wordpress'];
			}

			$homepage = '';
			if ( ! empty( $version['homepage'] ) ) {
				$homepage = $version['homepage'];
			} elseif ( $repository ) {
				$homepage = $repository;
			}

			return new self(
				array(
					'name'               => $name,
					'version'            => self::clean_version( $version['version'] ),
					'version_normalized' => isset( $version['version_normalized'] ) ? $version['version_normalized'] : '',
					'type'               => isset( $version['type'] ) ? $version['type'] : '',
					'description'        => isset( $version['description'] ) ? (string) $version['description'] : '',
					'time'               => isset( $version['time'] ) ? $version['time'] : '',
					'dist_url'           => isset( $version['dist']['url'] ) ? $version['dist']['url'] : '',
					'repository'         => $repository,
					'homepage'           => $homepage,
					'abandoned'          => $abandoned,
					'display_name'       => isset( $wordpress['name'] ) ? (string) $wordpress['name'] : '',
					'requires'           => isset( $wordpress['requires'] ) ? (string) $wordpress['requires'] : '',
					'tested'             => isset( $wordpress['tested'] ) ? (string) $wordpress['tested'] : '',
					'requires_php'       => isset( $wordpress['requires_php'] ) ? (string) $wordpress['requires_php'] : '',
				)
			);
		}

		/**
		 * Rebuilds a package from cached data.
		 *
		 * @param array $data Output of to_array()
		 * @return UCF_Plugin_Directory_Package|null
		 */
		public static function from_array( $data ) {
			if ( ! is_array( $data ) || empty( $data['name'] ) ) {
				return null;
			}

			return new self( $data );
		}

		public function to_array() {
			return $this->data;
		}

		/**
		 * Strips a leading "v" so versions compare cleanly against plugin
		 * header versions (v2.1.0 → 2.1.0).
		 */
		public static function clean_version( $version ) {
			return preg_replace( '/^v(?=\d)/i', '', (string) $version );
		}

		public function get_name() {
			return $this->data['name'];
		}

		public function get_vendor() {
			$parts = explode( '/', $this->data['name'], 2 );
			return $parts[0];
		}

		/**
		 * The slug is the package name without its vendor prefix. This is also
		 * the directory composer/installers would install the package into.
		 */
		public function get_slug() {
			$parts = explode( '/', $this->data['name'], 2 );
			return isset( $parts[1] ) ? $parts[1] : $parts[0];
		}

		public function get_version() {
			return $this->data['version'];
		}

		public function get_type() {
			return $this->data['type'];
		}

		public function is_plugin() {
			return self::TYPE_PLUGIN === $this->data['type'];
		}

		public function is_theme() {
			return self::TYPE_THEME === $this->data['type'];
		}

		public function get_description() {
			return $this->data['description'];
		}

		public function get_time() {
			return $this->data['time'];
		}

		public function get_dist_url() {
			return $this->data['dist_url'];
		}

		public function get_repository() {
			return $this->data['repository'];
		}

		public function get_homepage() {
			return $this->data['homepage'];
		}

		public function get_requires() {
			return $this->data['requires'];
		}

		public function get_tested() {
			return $this->data['tested'];
		}

		public function get_requires_php() {
			return $this->data['requires_php'];
		}

		/**
		 * @return bool|string False, true, or the name of a suggested replacement package
		 */
		public function get_abandoned() {
			return $this->data['abandoned'];
		}

		public function is_abandoned() {
			return (bool) $this->data['abandoned'];
		}

		/**
		 * Returns the GitHub "Owner/Repo" for the package, or an empty string
		 * if the repository isn't on GitHub.
		 */
		public function get_github_repo() {
			if ( preg_match( '#^https?://github\.com/([^/]+/[^/]+?)/?$#i', $this->data['repository'], $matches ) ) {
				return $matches[1];
			}

			return '';
		}

		/**
		 * Display name, in order of preference: extra.WordPress.name, the
		 * GitHub repository name (UCF repos are cased meaningfully, e.g.
		 * UCF-FAQ-CPT), then a name derived from the slug.
		 */
		public function get_display_name() {
			if ( '' !== $this->data['display_name'] ) {
				return $this->data['display_name'];
			}

			$repo = $this->get_github_repo();
			if ( $repo ) {
				$parts = explode( '/', $repo );
				$name  = end( $parts );

				// An all-lowercase repo name carries no casing worth keeping.
				if ( strtolower( $name ) !== $name ) {
					return str_replace( array( '-', '_' ), ' ', $name );
				}
			}

			return self::display_name_from_slug( $this->get_slug() );
		}

		public static function display_name_from_slug( $slug ) {
			$words = explode( '-', $slug );

			foreach ( $words as $i => $word ) {
				$words[ $i ] = isset( self::$display_words[ $word ] ) ? self::$display_words[ $word ] : ucfirst( $word );
			}

			return implode( ' ', $words );
		}
	}
}
