<?php
use PHPUnit\Framework\TestCase;

class UpgraderTest extends TestCase {
	private $catalog;
	private $upgrader;

	protected function setUp(): void {
		Test_WP::reset();
		Test_WP::$http = Test_WP::fixture_router();
		$this->catalog = new UCF_Plugin_Directory_Catalog( new UCF_Plugin_Directory_Repository_Client( 'https://repo.packagist.org/' ) );
		$this->catalog->refresh();
		$this->upgrader = new UCF_Plugin_Directory_Upgrader( $this->catalog );
	}

	private function faq_url() {
		return $this->catalog->get_package( 'ucf/ucf-faq-cpt' )->get_dist_url();
	}

	public function test_allowlisted_catalog_download_passes_through() {
		$this->assertFalse( $this->upgrader->pre_download( false, $this->faq_url(), null, array( 'type' => 'plugin', 'action' => 'install' ) ) );
	}

	public function test_non_allowlisted_download_is_refused_with_readable_error() {
		add_filter(
			'ucf_plugin_directory_download_url',
			function ( $url, $package ) {
				return 'ucf-faq-cpt' === $package->get_slug() ? 'https://evil.example.com/ucf-faq-cpt.zip' : $url;
			},
			10,
			2
		);

		$result = $this->upgrader->pre_download( false, 'https://evil.example.com/ucf-faq-cpt.zip', null, array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ucf_plugin_directory_download_not_allowed', $result->get_error_code() );
		$this->assertStringContainsString( 'not an allowed UCF download location', $result->get_error_message() );
	}

	public function test_update_with_stale_url_is_still_validated_by_installed_plugin() {
		Test_WP::$plugins = array(
			'UCF-FAQ-CPT/ucf-faq.php' => array( 'Version' => '2.0.0', 'UpdateURI' => 'https://packagist.org/packages/ucf/ucf-faq-cpt' ),
		);

		$result = $this->upgrader->pre_download( false, 'https://evil.example.com/old.zip', null, array( 'plugin' => 'UCF-FAQ-CPT/ucf-faq.php' ) );
		$this->assertInstanceOf( WP_Error::class, $result );

		$stale = 'https://api.github.com/repos/UCF/UCF-FAQ-CPT/zipball/0000000';
		$this->assertFalse( $this->upgrader->pre_download( false, $stale, null, array( 'plugin' => 'UCF-FAQ-CPT/ucf-faq.php' ) ) );
	}

	public function test_install_is_refused_when_already_installed_in_another_folder() {
		Test_WP::$plugins = array(
			'UCF-FAQ-CPT/ucf-faq.php' => array( 'Version' => '2.2.1', 'UpdateURI' => '', 'GitHub Plugin URI' => 'UCF/UCF-FAQ-CPT' ),
		);

		$result = $this->upgrader->pre_download( false, $this->faq_url(), null, array( 'type' => 'plugin', 'action' => 'install' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'UCF-FAQ-CPT/', $result->get_error_message() );
	}

	public function test_repository_outside_ucf_org_is_refused() {
		add_filter(
			'ucf_plugin_directory_allowed_repositories',
			function () {
				return array( 'https://github.com/SomeoneElse/' );
			}
		);

		$result = $this->upgrader->pre_download( false, $this->faq_url(), null, array() );
		$this->assertSame( 'ucf_plugin_directory_repository_not_allowed', $result->get_error_code() );
	}

	/**
	 * @dataProvider rename_cases
	 */
	public function test_source_folder_is_renamed_to_the_right_target( $hook_extra, $expected ) {
		global $wp_filesystem;
		$wp_filesystem = new class() {
			public $moves = array();
			public function move( $from, $to ) {
				$this->moves[] = array( $from, $to );
				return true;
			}
		};

		$this->upgrader->pre_download( false, $this->faq_url(), null, $hook_extra );
		$result = $this->upgrader->source_selection( '/tmp/upgrade/abc/UCF-UCF-FAQ-CPT-6fc68d9/', '/tmp/upgrade/abc', null, $hook_extra );

		$this->assertSame( $expected, $result );
	}

	public function rename_cases() {
		return array(
			'fresh install uses the slug'             => array( array( 'type' => 'plugin', 'action' => 'install' ), '/tmp/upgrade/abc/ucf-faq-cpt/' ),
			'update keeps the existing folder'        => array( array( 'plugin' => 'UCF-FAQ-CPT/ucf-faq.php' ), '/tmp/upgrade/abc/UCF-FAQ-CPT/' ),
			'update keeps a hand-picked folder name'  => array( array( 'plugin' => 'faq-manual/ucf-faq.php' ), '/tmp/upgrade/abc/faq-manual/' ),
		);
	}

	public function test_already_correct_folder_is_left_alone() {
		global $wp_filesystem;
		$wp_filesystem = null;

		$this->upgrader->pre_download( false, $this->faq_url(), null, array( 'type' => 'plugin', 'action' => 'install' ) );
		$this->assertSame( '/tmp/upgrade/abc/ucf-faq-cpt/', $this->upgrader->source_selection( '/tmp/upgrade/abc/ucf-faq-cpt/', '/tmp/upgrade/abc', null, array() ) );
	}

	public function test_unrelated_downloads_are_not_touched() {
		$this->assertFalse( $this->upgrader->pre_download( false, 'https://downloads.wordpress.org/plugin/akismet.zip', null, array() ) );
		$this->assertSame( '/tmp/x/akismet/', $this->upgrader->source_selection( '/tmp/x/akismet/', '/tmp/x', null, array() ) );
	}

	public function test_github_token_is_added_only_for_ucf_github_urls() {
		if ( ! defined( 'UCF_PLUGIN_DIRECTORY_GITHUB_TOKEN' ) ) {
			define( 'UCF_PLUGIN_DIRECTORY_GITHUB_TOKEN', 'test-token' );
		}

		$args = $this->upgrader->add_github_token( array(), $this->faq_url() );
		$this->assertSame( 'Bearer test-token', $args['headers']['Authorization'] );

		$this->assertArrayNotHasKey( 'headers', $this->upgrader->add_github_token( array(), 'https://repo.packagist.org/p2/ucf/ucf-faq-cpt.json' ) );
		$this->assertArrayNotHasKey( 'headers', $this->upgrader->add_github_token( array(), 'https://api.github.com/repos/SomeoneElse/x/zipball/a' ) );
	}
}
