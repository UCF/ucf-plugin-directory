<?php
use PHPUnit\Framework\TestCase;

class CliTest extends TestCase {
	private $catalog;
	private $cli;

	protected function setUp(): void {
		Test_WP::reset();
		WP_CLI::reset();
		Test_WP::$http = Test_WP::fixture_router();

		$this->catalog = new UCF_Plugin_Directory_Catalog( new UCF_Plugin_Directory_Repository_Client( 'https://repo.packagist.org/' ) );
		$this->catalog->refresh();
		$this->cli = new UCF_Plugin_Directory_CLI( $this->catalog );

		WP_CLI::reset();
		Test_WP::$requests = array();
	}

	/**
	 * Rows passed to format_items() by the last command.
	 */
	private function rows() {
		$last = end( WP_CLI::$items );
		return $last ? $last['items'] : null;
	}

	private function slugs() {
		return array_column( $this->rows(), 'slug' );
	}

	public function test_list_shows_plugins_and_themes_with_default_fields() {
		$this->cli->list_( array(), array() );

		$this->assertSame( array( 'section-menus-shortcode', 'test-minified', 'ucf-faq-cpt', 'ucf-lazyload-video-plugin', 'colleges-theme' ), $this->slugs() );
		$this->assertSame( UCF_Plugin_Directory_CLI::DEFAULT_FIELDS, WP_CLI::$items[0]['fields'] );
		$this->assertSame( 'table', WP_CLI::$items[0]['format'] );
		$this->assertSame( array(), Test_WP::$requests, 'listing reads the cache only' );
	}

	public function test_list_filters_by_type() {
		$this->cli->list_( array(), array( 'type' => 'theme' ) );
		$this->assertSame( array( 'colleges-theme' ), $this->slugs() );

		$this->cli->list_( array(), array( 'type' => 'plugin' ) );
		$this->assertNotContains( 'colleges-theme', $this->slugs() );
	}

	public function test_list_marks_abandoned_packages_with_their_replacement() {
		$this->cli->list_( array(), array() );

		$rows = array_column( $this->rows(), null, 'slug' );
		$this->assertSame( 'yes, use ucf/ucf-section-plugin', $rows['section-menus-shortcode']['abandoned'] );
		$this->assertSame( '', $rows['ucf-faq-cpt']['abandoned'] );
	}

	public function test_installed_plugins_and_themes_show_their_real_folder_and_version() {
		Test_WP::$plugins = array(
			'UCF-FAQ-CPT/ucf-faq.php' => array( 'Version' => '2.0.0', 'UpdateURI' => '', 'GitHub Plugin URI' => 'UCF/UCF-FAQ-CPT' ),
			'akismet/akismet.php'     => array( 'Version' => '5.0', 'UpdateURI' => '' ),
		);
		Test_WP::$themes  = array(
			'Colleges-Theme'   => new Test_WP_Theme( array( 'Version' => 'v1.4.0' ) ),
			'twentytwentyfive' => new Test_WP_Theme( array( 'Version' => '1.0' ) ),
		);

		$this->cli->list_( array(), array( 'installed' => true ) );

		$rows = array_column( $this->rows(), null, 'slug' );
		$this->assertSame( array( 'ucf-faq-cpt', 'colleges-theme' ), array_keys( $rows ) );
		$this->assertSame( 'UCF-FAQ-CPT/', $rows['ucf-faq-cpt']['installed'] );
		$this->assertSame( '2.0.0', $rows['ucf-faq-cpt']['installed_version'] );
		$this->assertSame( '2.2.1', $rows['ucf-faq-cpt']['version'] );
		$this->assertSame( 'Colleges-Theme/', $rows['colleges-theme']['installed'] );
		$this->assertSame( 'v1.4.0', $rows['colleges-theme']['installed_version'] );
	}

	public function test_fields_and_format_are_passed_through() {
		$this->cli->list_( array(), array( 'fields' => 'slug, version', 'format' => 'json' ) );

		$this->assertSame( array( 'slug', 'version' ), WP_CLI::$items[0]['fields'] );
		$this->assertSame( 'json', WP_CLI::$items[0]['format'] );
	}

	public function test_search_matches_every_word_across_name_and_description() {
		$this->cli->search( array( 'faq' ), array() );
		$this->assertSame( array( 'ucf-faq-cpt' ), $this->slugs() );

		$this->cli->search( array( 'custom', 'post', 'type' ), array() );
		$this->assertSame( array( 'ucf-faq-cpt' ), $this->slugs(), 'words are matched in the description' );

		$this->cli->search( array( 'faq', 'theme' ), array( 'format' => 'json' ) );
		$this->assertSame( array(), $this->slugs(), 'every word must match' );
	}

	/**
	 * WP-CLI checks arguments against the docblock before calling the
	 * method, so calling search() directly can't catch a mismatch. The
	 * search term must be declared repeating ("<search>...") or unquoted
	 * multi-word searches are rejected with "Too many positional arguments".
	 */
	public function test_search_synopsis_accepts_multiple_words() {
		$doc = ( new ReflectionMethod( 'UCF_Plugin_Directory_CLI', 'search' ) )->getDocComment();

		$this->assertMatchesRegularExpression( '/^\s*\* <search>\.\.\.$/m', $doc );
	}

	public function test_search_with_no_matches_says_so_in_a_table() {
		$this->cli->search( array( 'zzzz' ), array() );

		$this->assertSame( array( 'No packages matched.' ), WP_CLI::messages( 'log' ) );
		$this->assertSame( array(), WP_CLI::$items, 'no empty table is printed' );

		$this->cli->search( array( 'zzzz' ), array( 'format' => 'json' ) );
		$this->assertSame( array(), $this->rows(), 'machine-readable formats still get an empty list' );
	}

	public function test_refresh_reports_downloaded_and_unchanged_counts() {
		$this->cli->refresh( array(), array() );

		$success = WP_CLI::messages( 'success' );
		$this->assertCount( 1, $success );
		$this->assertMatchesRegularExpression( '/^0 downloaded, 6 unchanged in [\d.]+s\. Catalog: 4 plugins, 1 themes \(1 abandoned\)\.$/', $success[0] );

		WP_CLI::reset();
		$this->cli->refresh( array(), array( 'force' => true ) );
		$this->assertStringStartsWith( '6 downloaded, 0 unchanged', WP_CLI::messages( 'success' )[0] );
	}

	public function test_refresh_failure_errors_and_keeps_the_last_good_catalog() {
		Test_WP::$http = function () {
			return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
		};

		try {
			$this->cli->refresh( array(), array() );
			$this->fail( 'WP_CLI::error() should have been called' );
		} catch ( Test_WP_CLI_Halt $e ) {
			$this->assertStringContainsString( 'the last good catalog was kept', $e->getMessage() );
			$this->assertStringContainsString( 'Could not resolve host', $e->getMessage() );
		}

		$this->assertNotNull( $this->catalog->get_package( 'ucf/ucf-faq-cpt' ) );
	}

	public function test_refresh_reports_individual_package_errors_as_warnings() {
		$router        = Test_WP::fixture_router();
		Test_WP::$http = function ( $url, $args ) use ( $router ) {
			return false !== strpos( $url, 'ucf-faq-cpt.json' ) ? Test_WP::response( 500 ) : $router( $url, $args );
		};

		$this->cli->refresh( array(), array( 'force' => true ) );

		$warnings = WP_CLI::messages( 'warning' );
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'HTTP 500', $warnings[0] );
		$this->assertCount( 1, WP_CLI::messages( 'success' ), 'a partial failure still succeeds' );
	}

	public function test_empty_cache_is_fetched_before_listing() {
		Test_WP::$options = array();
		$cli              = new UCF_Plugin_Directory_CLI( new UCF_Plugin_Directory_Catalog( new UCF_Plugin_Directory_Repository_Client( 'https://repo.packagist.org/' ) ) );

		$cli->list_( array(), array() );

		$this->assertSame( array( 'The catalog cache is empty; fetching it now...' ), WP_CLI::messages( 'log' ) );
		$this->assertNotEmpty( Test_WP::$requests );
		$this->assertCount( 5, $this->rows() );
	}

	public function test_empty_cache_that_cannot_be_fetched_is_an_error() {
		Test_WP::$options = array();
		Test_WP::$http    = function () {
			return new WP_Error( 'http_request_failed', 'timed out' );
		};
		$cli = new UCF_Plugin_Directory_CLI( new UCF_Plugin_Directory_Catalog( new UCF_Plugin_Directory_Repository_Client( 'https://repo.packagist.org/' ) ) );

		$this->expectException( Test_WP_CLI_Halt::class );
		$this->expectExceptionMessage( 'Could not load the catalog: timed out' );

		$cli->list_( array(), array() );
	}
}
