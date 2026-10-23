<?php
/**
 * Unit test bootstrap: minimal stand-ins for the WordPress functions the
 * plugin's classes use, so the catalog logic can be tested without a
 * database. Integration behavior is checked against a real site.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPINC', 'wp-includes' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'UCF_PLUGIN_DIRECTORY__VERSION', '0.1.0-test' );
define( 'UCF_PLUGIN_DIRECTORY__PLUGIN_URL', 'https://example.test/wp-content/plugins/ucf-plugin-directory' );
define( 'UCF_TEST_FIXTURES', __DIR__ . '/fixtures' );

require_once __DIR__ . '/class-test-wp.php';
require_once __DIR__ . '/class-test-wp-cli.php';

foreach ( array( 'metadata-expander', 'package', 'repository-client', 'catalog', 'plugins-api', 'updater', 'upgrader', 'cli' ) as $file ) {
	require_once dirname( __DIR__ ) . '/includes/class-' . $file . '.php';
}
