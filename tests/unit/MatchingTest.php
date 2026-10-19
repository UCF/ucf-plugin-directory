<?php
use PHPUnit\Framework\TestCase;

class MatchingTest extends TestCase {
	private $catalog;

	protected function setUp(): void {
		Test_WP::reset();
		Test_WP::$http = Test_WP::fixture_router();
		$this->catalog = new UCF_Plugin_Directory_Catalog( new UCF_Plugin_Directory_Repository_Client( 'https://repo.packagist.org/' ) );
		$this->catalog->refresh();
	}

	public function test_update_uri_resolves_allowed_vendor_only() {
		$this->assertSame( 'ucf/ucf-faq-cpt', $this->catalog->get_package_by_update_uri( 'https://packagist.org/packages/ucf/ucf-faq-cpt' )->get_name() );
		$this->assertNull( $this->catalog->get_package_by_update_uri( 'https://packagist.org/packages/someone-else/ucf-faq-cpt' ) );
		$this->assertNull( $this->catalog->get_package_by_update_uri( 'https://example.com/packages/ucf/ucf-faq-cpt' ) );
		$this->assertNull( $this->catalog->get_package_by_update_uri( 'https://packagist.org/packages/ucf/not-a-package' ) );
	}

	public function test_updater_answers_update_uri_and_ignores_other_vendors() {
		$updater = new UCF_Plugin_Directory_Updater( $this->catalog );

		$update = $updater->update_by_uri( false, array( 'UpdateURI' => 'https://packagist.org/packages/ucf/ucf-faq-cpt' ), 'UCF-FAQ-CPT/ucf-faq.php' );
		$this->assertSame( '2.2.1', $update['version'] );
		$this->assertSame( 'ucf-faq-cpt', $update['slug'] );
		$this->assertStringStartsWith( 'https://api.github.com/repos/UCF/UCF-FAQ-CPT/zipball/', $update['package'] );

		$this->assertFalse( $updater->update_by_uri( false, array( 'UpdateURI' => 'https://packagist.org/packages/someone-else/foo' ), 'foo/foo.php' ) );
	}

	public function test_github_plugin_uri_header_matches_repo_case_insensitively() {
		$match = $this->catalog->match_installed_plugin( 'whatever-folder/ucf-faq.php', array( 'GitHub Plugin URI' => 'ucf/ucf-faq-cpt' ) );
		$this->assertSame( 'ucf/ucf-faq-cpt', $match->get_name() );

		$match = $this->catalog->match_installed_plugin( 'x/x.php', array( 'GitHub Plugin URI' => 'https://github.com/UCF/UCF-FAQ-CPT' ) );
		$this->assertSame( 'ucf/ucf-faq-cpt', $match->get_name() );
	}

	public function test_github_header_for_unknown_repo_does_not_fall_back_to_folder() {
		$this->assertNull( $this->catalog->match_installed_plugin( 'ucf-faq-cpt/ucf-faq.php', array( 'GitHub Plugin URI' => 'SomeoneElse/Fork' ) ) );
	}

	public function test_folder_name_matches_slug_or_repo_name() {
		$this->assertSame( 'ucf/ucf-faq-cpt', $this->catalog->match_installed_plugin( 'ucf-faq-cpt/ucf-faq.php', array() )->get_name() );
		$this->assertSame( 'ucf/ucf-faq-cpt', $this->catalog->match_installed_plugin( 'UCF-FAQ-CPT/ucf-faq.php', array() )->get_name() );
		$this->assertNull( $this->catalog->match_installed_plugin( 'akismet/akismet.php', array() ) );
		$this->assertNull( $this->catalog->match_installed_plugin( 'hello.php', array() ) );
		$this->assertNull( $this->catalog->match_installed_plugin( 'colleges-theme/x.php', array() ), 'themes never match plugins' );
	}

	public function test_fallback_updates_only_when_enabled() {
		Test_WP::$plugins = array(
			'UCF-FAQ-CPT/ucf-faq.php' => array( 'Version' => '2.0.0', 'UpdateURI' => '', 'GitHub Plugin URI' => 'UCF/UCF-FAQ-CPT' ),
			'akismet/akismet.php'     => array( 'Version' => '5.0', 'UpdateURI' => '' ),
		);
		$updater   = new UCF_Plugin_Directory_Updater( $this->catalog );
		$transient = (object) array( 'checked' => array( 'UCF-FAQ-CPT/ucf-faq.php' => '2.0.0' ), 'response' => array(), 'no_update' => array() );

		$result = $updater->add_fallback_updates( clone $transient );
		$this->assertSame( array(), $result->response );

		update_site_option( UCF_Plugin_Directory_Updater::FALLBACK_OPTION, true );
		$result = $updater->add_fallback_updates( clone $transient );

		$this->assertArrayHasKey( 'UCF-FAQ-CPT/ucf-faq.php', $result->response );
		$this->assertSame( '2.2.1', $result->response['UCF-FAQ-CPT/ucf-faq.php']->new_version );
		$this->assertSame( 'UCF-FAQ-CPT/ucf-faq.php', $result->response['UCF-FAQ-CPT/ucf-faq.php']->plugin );
		$this->assertArrayNotHasKey( 'akismet/akismet.php', $result->response );
	}
}
