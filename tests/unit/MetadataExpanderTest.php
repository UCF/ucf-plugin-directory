<?php
use PHPUnit\Framework\TestCase;

class MetadataExpanderTest extends TestCase {
	private function fixture( $path ) {
		return json_decode( file_get_contents( UCF_TEST_FIXTURES . '/' . $path ), true );
	}

	public function test_expansion_matches_hand_expanded_output() {
		$versions = UCF_Plugin_Directory_Metadata_Expander::versions_from_document( $this->fixture( 'p2/ucf/test-minified.json' ), 'ucf/test-minified' );

		$this->assertCount( 4, $versions );

		// v2.10.0 overrides description, unsets homepage, and inherits the rest.
		$this->assertSame( 'ucf/test-minified', $versions[1]['name'] );
		$this->assertSame( 'wordpress-plugin', $versions[1]['type'] );
		$this->assertSame( 'Stable description', $versions[1]['description'] );
		$this->assertArrayNotHasKey( 'homepage', $versions[1] );
		$this->assertSame( 'UCF Test Minified', $versions[1]['extra']['wordpress']['name'] );
		$this->assertSame( 'https://api.github.com/repos/UCF/Test-Minified/zipball/bbb', $versions[1]['dist']['url'] );

		// 2.9.0 inherits from the expanded v2.10.0 (so no homepage) and unsets extra.
		$this->assertSame( 'Stable description', $versions[2]['description'] );
		$this->assertArrayNotHasKey( 'homepage', $versions[2] );
		$this->assertArrayNotHasKey( 'extra', $versions[2] );
		$this->assertSame( 'https://github.com/UCF/Test-Minified.git', $versions[2]['source']['url'] );
	}

	public function test_real_packagist_file_expands_every_version() {
		$document = $this->fixture( 'p2-packagist-composer-installers.json' );
		$this->assertSame( 'composer/2.0', $document['minified'] );

		$versions = UCF_Plugin_Directory_Metadata_Expander::versions_from_document( $document, 'composer/installers' );

		$this->assertGreaterThan( 10, count( $versions ) );

		// The newest releases are composer-plugin; the oldest override that with composer-installer.
		$this->assertSame( 'composer-plugin', $versions[0]['type'] );
		$this->assertSame( 'composer-installer', end( $versions )['type'] );

		foreach ( $versions as $version ) {
			$this->assertSame( 'composer/installers', $version['name'] );
			$this->assertContains( $version['type'], array( 'composer-plugin', 'composer-installer' ) );
			$this->assertNotContains( '__unset', $version, true );
			$this->assertNotEmpty( $version['dist']['url'] );
		}
	}

	public function test_unminified_satis_file_passes_through() {
		$document = $this->fixture( 'p2/ucf/ucf-faq-cpt.json' );
		$versions = UCF_Plugin_Directory_Metadata_Expander::versions_from_document( $document, 'ucf/ucf-faq-cpt' );

		$this->assertSame( $document['packages']['ucf/ucf-faq-cpt'], $versions );
	}

	public function test_missing_package_returns_null() {
		$this->assertNull( UCF_Plugin_Directory_Metadata_Expander::versions_from_document( array( 'packages' => array() ), 'ucf/nope' ) );
		$this->assertNull( UCF_Plugin_Directory_Metadata_Expander::versions_from_document( 'garbage', 'ucf/nope' ) );
	}
}
