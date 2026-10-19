<?php
use PHPUnit\Framework\TestCase;

class CatalogTest extends TestCase {
	const PACKAGIST = 'https://repo.packagist.org/';
	const SATIS     = 'https://ucf.github.io/ucf-packagist/';

	protected function setUp(): void {
		Test_WP::reset();
		Test_WP::$http = Test_WP::fixture_router();
	}

	private function catalog( $repository = self::PACKAGIST ) {
		return new UCF_Plugin_Directory_Catalog( new UCF_Plugin_Directory_Repository_Client( $repository ) );
	}

	public function test_packagist_catalog_filters_types_exclusions_and_flags_abandoned() {
		$catalog = $this->catalog();
		$this->assertTrue( $catalog->refresh() );

		$packages = $catalog->get_packages();

		$this->assertArrayHasKey( 'ucf/ucf-faq-cpt', $packages );
		$this->assertArrayHasKey( 'ucf/colleges-theme', $packages );
		$this->assertArrayNotHasKey( 'ucf/ucf-cli-tools', $packages, 'wp-cli-package is filtered out' );
		$this->assertArrayNotHasKey( 'ucf/github-action-testing', $packages, 'excluded by default' );

		$abandoned = $packages['ucf/section-menus-shortcode'];
		$this->assertTrue( $abandoned->is_abandoned() );
		$this->assertSame( 'ucf/ucf-section-plugin', $abandoned->get_abandoned() );
		$this->assertArrayNotHasKey( 'ucf/section-menus-shortcode', $catalog->get_plugins(), 'hidden from Add New' );
		$this->assertArrayHasKey( 'ucf/section-menus-shortcode', $catalog->get_plugins( true ), 'still known for updates' );

		$this->assertTrue( $packages['ucf/colleges-theme']->is_theme() );
		$this->assertSame( '', $catalog->get_status()['last_error'] );
	}

	public function test_packagist_requests_are_well_formed() {
		$this->catalog()->refresh();

		$urls = array_column( Test_WP::$requests, 0 );
		$this->assertStringContainsString( 'vendor=ucf', $urls[0] );
		$this->assertStringContainsString( 'fields[]=type&fields[]=abandoned&fields[]=repository', $urls[0] );
		$this->assertContains( 'https://repo.packagist.org/p2/ucf/ucf-faq-cpt.json', $urls );

		foreach ( Test_WP::$requests as $request ) {
			$this->assertStringNotContainsString( '~dev', $request[0] );
			$this->assertStringNotContainsString( 'packagist.org/packages/ucf/', $request[0], 'never the dynamic package endpoint' );
			$this->assertStringContainsString( 'mailto=webcom@ucf.edu', $request[1]['user-agent'] );
		}
	}

	public function test_satis_catalog_uses_available_packages_and_metadata_url() {
		$catalog = $this->catalog( self::SATIS );
		$this->assertTrue( $catalog->refresh() );

		$urls = array_column( Test_WP::$requests, 0 );
		$this->assertContains( 'https://ucf.github.io/ucf-packagist/p2/ucf/ucf-faq-cpt.json', $urls );
		$this->assertNotContains( 'https://ucf.github.io/ucf-packagist/p2/other/not-ours.json', $urls, 'other vendors are never fetched' );

		$faq = $catalog->get_package( 'ucf/ucf-faq-cpt' );
		$this->assertSame( '2.2.1', $faq->get_version(), 'leading v is stripped' );
		$this->assertSame( 'ucf-faq-cpt', $faq->get_slug() );
		$this->assertSame( 'UCF/UCF-FAQ-CPT', $faq->get_github_repo() );
		$this->assertSame( 'UCF FAQ CPT', $faq->get_display_name() );

		// Satis has no list.json, so type comes from the resolved version.
		$this->assertTrue( $catalog->get_package( 'ucf/main-site-utilities-plugin' )->is_plugin() );
		$this->assertNull( $catalog->get_package( 'ucf/ucf-cli-tools' ) );
	}

	public function test_minified_package_uses_extra_wordpress_and_resolves_stable() {
		$catalog = $this->catalog();
		$catalog->refresh();

		$package = $catalog->get_package( 'ucf/test-minified' );
		$this->assertSame( '2.10.0', $package->get_version() );
		$this->assertSame( 'Stable description', $package->get_description() );
		$this->assertSame( 'UCF Test Minified', $package->get_display_name() );
		$this->assertSame( '6.8', $package->get_tested() );
		$this->assertSame( '7.4', $package->get_requires_php() );
		$this->assertSame( 'https://github.com/UCF/Test-Minified', $package->get_homepage(), 'homepage was __unset, falls back to repo' );
	}

	public function test_display_name_falls_back_to_slug_for_lowercase_repo_names() {
		$catalog = $this->catalog();
		$catalog->refresh();

		$this->assertSame( 'UCF Lazyload Video Plugin', $catalog->get_package( 'ucf/ucf-lazyload-video-plugin' )->get_display_name() );
	}

	public function test_304_keeps_cached_package_without_reparsing() {
		$catalog = $this->catalog();
		$catalog->refresh();
		$before = get_site_option( UCF_Plugin_Directory_Catalog::CACHE_OPTION );

		Test_WP::$requests = array();
		$catalog->refresh();

		$after = get_site_option( UCF_Plugin_Directory_Catalog::CACHE_OPTION );
		$this->assertSame( $before['packages'], $after['packages'] );

		$status = $catalog->get_status();
		$this->assertSame( 0, $status['stats']['fetched'] );
		$this->assertGreaterThan( 0, $status['stats']['unchanged'] );

		foreach ( Test_WP::$requests as $request ) {
			if ( false !== strpos( $request[0], '/p2/' ) ) {
				$this->assertSame( 'Mon, 12 Oct 2026 19:21:43 GMT', $request[1]['headers']['If-Modified-Since'] );
			}
		}
	}

	public function test_304_still_applies_abandoned_flag_from_list() {
		$catalog = $this->catalog();
		$catalog->refresh();
		$this->assertFalse( $catalog->get_package( 'ucf/ucf-faq-cpt' )->is_abandoned() );

		$router        = Test_WP::fixture_router();
		Test_WP::$http = function ( $url, $args ) use ( $router ) {
			$response = $router( $url, $args );
			if ( false !== strpos( $url, 'list.json' ) ) {
				$list = json_decode( $response['body'], true );
				$list['packages']['ucf/ucf-faq-cpt']['abandoned'] = true;
				$response['body'] = json_encode( $list );
			}
			return $response;
		};

		$catalog->refresh();
		$this->assertTrue( $catalog->get_package( 'ucf/ucf-faq-cpt' )->is_abandoned() );
	}

	public function test_failed_discovery_keeps_last_good_catalog() {
		$catalog = $this->catalog();
		$catalog->refresh();
		$count = count( $catalog->get_packages() );

		Test_WP::$http = function () {
			return new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
		};

		$result = $catalog->refresh();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertCount( $count, $catalog->get_packages() );
		$this->assertStringContainsString( 'timed out', $catalog->get_status()['last_error'] );
	}

	public function test_malformed_package_file_keeps_last_good_package() {
		$catalog = $this->catalog();
		$catalog->refresh();

		$router        = Test_WP::fixture_router();
		Test_WP::$http = function ( $url, $args ) use ( $router ) {
			if ( false !== strpos( $url, 'ucf-faq-cpt.json' ) ) {
				return Test_WP::response( 200, '{"packages": {not json' );
			}
			return $router( $url, $args );
		};

		$this->assertTrue( $catalog->refresh( true ) );
		$this->assertSame( '2.2.1', $catalog->get_package( 'ucf/ucf-faq-cpt' )->get_version() );
		$this->assertStringContainsString( 'Malformed JSON', $catalog->get_status()['last_error'] );
	}

	public function test_reading_the_catalog_never_makes_http_requests() {
		$this->catalog()->refresh();
		Test_WP::$requests = array();

		// A fresh instance, as on a new page load.
		$catalog = $this->catalog();
		$catalog->get_packages();
		$catalog->get_plugins();
		$catalog->get_plugin_by_slug( 'ucf-faq-cpt' );
		$catalog->get_package_by_update_uri( 'https://packagist.org/packages/ucf/ucf-faq-cpt' );
		$catalog->is_empty();

		$this->assertSame( array(), Test_WP::$requests );
	}

	public function test_cache_from_another_repository_is_ignored() {
		$this->catalog( self::SATIS )->refresh();
		$this->assertTrue( $this->catalog( self::PACKAGIST )->is_empty() );
	}

	public function test_empty_vendor_on_packagist_is_not_an_error() {
		Test_WP::$http = function ( $url ) {
			return Test_WP::response( 200, '{"packages":[]}' );
		};

		$catalog = $this->catalog();
		$this->assertTrue( $catalog->refresh() );
		$this->assertTrue( $catalog->is_empty() );
	}
}
