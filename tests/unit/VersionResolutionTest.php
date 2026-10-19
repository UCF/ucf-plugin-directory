<?php
use PHPUnit\Framework\TestCase;

class VersionResolutionTest extends TestCase {
	private function versions( $name ) {
		$document = json_decode( file_get_contents( UCF_TEST_FIXTURES . '/p2/' . $name . '.json' ), true );
		return UCF_Plugin_Directory_Metadata_Expander::versions_from_document( $document, $name );
	}

	public function test_highest_stable_wins_over_prerelease_and_dev() {
		$latest = UCF_Plugin_Directory_Catalog::resolve_latest( $this->versions( 'ucf/test-minified' ) );

		// 2.10.0 beats 2.9.0 numerically; 3.0.0-beta.1 and dev-main are skipped.
		$this->assertSame( 'v2.10.0', $latest['version'] );
	}

	public function test_v_prefixed_and_unprefixed_tags_compare_together() {
		// main-site-utilities-plugin mixes "1.0.0" with "v3.x" tags, and its
		// type changed from wp-cli-package to wordpress-plugin along the way.
		$latest = UCF_Plugin_Directory_Catalog::resolve_latest( $this->versions( 'ucf/main-site-utilities-plugin' ) );

		$this->assertSame( 'v3.3.0', $latest['version'] );
		$this->assertSame( 'wordpress-plugin', $latest['type'] );
	}

	public function test_unordered_satis_versions_resolve() {
		// Satis lists versions oldest first.
		$latest = UCF_Plugin_Directory_Catalog::resolve_latest( $this->versions( 'ucf/ucf-faq-cpt' ) );
		$this->assertSame( 'v2.2.1', $latest['version'] );
	}

	/**
	 * @dataProvider stability_cases
	 */
	public function test_stability( $version, $normalized, $stable ) {
		$this->assertSame( $stable, UCF_Plugin_Directory_Catalog::is_stable( array( 'version' => $version, 'version_normalized' => $normalized ) ) );
	}

	public function stability_cases() {
		return array(
			array( 'v1.2.3', '1.2.3.0', true ),
			array( '1.2.3', '1.2.3.0', true ),
			array( 'v1.0.0-alpha.1', '1.0.0.0-alpha1', false ),
			array( 'v1.0.0-beta.6', '1.0.0.0-beta6', false ),
			array( 'v0.1.0-alpha', '0.1.0.0-alpha', false ),
			array( '2.0.0-RC1', '2.0.0.0-RC1', false ),
			array( '1.0.0-patch1', '1.0.0.0-patch1', true ),
			array( 'dev-master', 'dev-master', false ),
			array( '1.x-dev', '1.9999999.9999999.9999999-dev', false ),
		);
	}

	public function test_no_stable_release_returns_null() {
		$this->assertNull(
			UCF_Plugin_Directory_Catalog::resolve_latest(
				array( array( 'version' => 'v1.0.0-beta.1', 'version_normalized' => '1.0.0.0-beta1' ) )
			)
		);
	}
}
