<?php
/**
 * Expands Composer v2 ("p2") package metadata
 */
if ( ! class_exists( 'UCF_Plugin_Directory_Metadata_Expander' ) ) {
	class UCF_Plugin_Directory_Metadata_Expander {
		const MINIFIED_FORMAT = 'composer/2.0';
		const UNSET_VALUE     = '__unset';

		/**
		 * Returns the fully expanded list of versions for a package from a
		 * decoded p2 document. Packagist minifies its p2 files; Satis does not.
		 * Both are handled here.
		 *
		 * @param array  $document Decoded p2 JSON
		 * @param string $name     Package name, e.g. ucf/ucf-faq-cpt
		 * @return array|null List of version arrays, or null if the package is missing
		 */
		public static function versions_from_document( $document, $name ) {
			if ( ! is_array( $document ) || ! isset( $document['packages'][ $name ] ) || ! is_array( $document['packages'][ $name ] ) ) {
				return null;
			}

			$versions = $document['packages'][ $name ];

			if ( isset( $document['minified'] ) && self::MINIFIED_FORMAT === $document['minified'] ) {
				$versions = self::expand( $versions );
			}

			return $versions;
		}

		/**
		 * Expands a minified version list. Each version inherits every field
		 * from the version before it unless it overrides that field, and the
		 * value "__unset" removes an inherited field.
		 *
		 * @param array $versions Minified versions, in the order given by the repository
		 * @return array
		 */
		public static function expand( array $versions ) {
			$expanded = array();
			$previous = null;

			foreach ( $versions as $version ) {
				if ( ! is_array( $version ) ) {
					continue;
				}

				$current = ( null === $previous ) ? array() : $previous;

				foreach ( $version as $key => $value ) {
					if ( self::UNSET_VALUE === $value ) {
						unset( $current[ $key ] );
					} else {
						$current[ $key ] = $value;
					}
				}

				$expanded[] = $current;
				$previous   = $current;
			}

			return $expanded;
		}
	}
}
