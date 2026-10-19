<?php
use PHPUnit\Framework\TestCase;

class DownloadUrlTest extends TestCase {
	protected function setUp(): void {
		Test_WP::reset();
	}

	/**
	 * @dataProvider codeload_cases
	 */
	public function test_github_links_are_rewritten_to_codeload( $url, $expected ) {
		$this->assertSame( $expected, UCF_Plugin_Directory_Catalog::to_codeload_url( $url ) );
	}

	public function codeload_cases() {
		$sha = '6fc68d949b3a405d267f1d46b0aad624d6bdab78';

		return array(
			'api zipball'          => array( "https://api.github.com/repos/UCF/UCF-FAQ-CPT/zipball/$sha", "https://codeload.github.com/UCF/UCF-FAQ-CPT/zip/$sha" ),
			'github archive'       => array( "https://github.com/UCF/UCF-FAQ-CPT/archive/$sha.zip", "https://codeload.github.com/UCF/UCF-FAQ-CPT/zip/$sha" ),
			'tag ref'              => array( 'https://api.github.com/repos/UCF/UCF-FAQ-CPT/zipball/v2.2.1', 'https://codeload.github.com/UCF/UCF-FAQ-CPT/zip/v2.2.1' ),
			'already codeload'     => array( "https://codeload.github.com/UCF/UCF-FAQ-CPT/zip/$sha", "https://codeload.github.com/UCF/UCF-FAQ-CPT/zip/$sha" ),
			'release asset'        => array( 'https://github.com/UCF/UCF-FAQ-CPT/releases/download/v2.2.1/ucf-faq-cpt.zip', 'https://github.com/UCF/UCF-FAQ-CPT/releases/download/v2.2.1/ucf-faq-cpt.zip' ),
			'not github'           => array( 'https://example.com/repos/UCF/x/zipball/abc', 'https://example.com/repos/UCF/x/zipball/abc' ),
			'tarball is untouched' => array( 'https://api.github.com/repos/UCF/UCF-FAQ-CPT/tarball/abc', 'https://api.github.com/repos/UCF/UCF-FAQ-CPT/tarball/abc' ),
		);
	}

	public function test_rewritten_url_is_still_allowlisted() {
		$url = UCF_Plugin_Directory_Catalog::to_codeload_url( 'https://api.github.com/repos/UCF/UCF-FAQ-CPT/zipball/abc' );
		$this->assertTrue( UCF_Plugin_Directory_Upgrader::url_has_allowed_prefix( $url, UCF_Plugin_Directory_Upgrader::get_allowed_download_prefixes() ) );
	}

	public function test_download_url_filter_still_overrides_the_rewrite() {
		add_filter(
			'ucf_plugin_directory_download_url',
			function () {
				return 'https://github.com/UCF/UCF-FAQ-CPT/releases/download/v2.2.1/ucf-faq-cpt.zip';
			}
		);

		$package = UCF_Plugin_Directory_Package::from_version(
			array(
				'name'    => 'ucf/ucf-faq-cpt',
				'version' => 'v2.2.1',
				'dist'    => array( 'url' => 'https://api.github.com/repos/UCF/UCF-FAQ-CPT/zipball/abc' ),
			)
		);

		$this->assertSame( 'https://github.com/UCF/UCF-FAQ-CPT/releases/download/v2.2.1/ucf-faq-cpt.zip', UCF_Plugin_Directory_Catalog::get_download_url( $package ) );
	}
}
