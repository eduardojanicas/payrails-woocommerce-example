<?php
/**
 * Unit-test bootstrap: only the plugin autoloader. No WordPress functions are
 * defined, so any WP call from includes/Core fails loudly (see CoreIsolationTest).
 *
 * @package PayrailsWoo
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
require __DIR__ . '/../../plugin/payrails-woo/includes/autoload.php';
require __DIR__ . '/unit/Support/FakeTransport.php';
require __DIR__ . '/unit/Support/Fixtures.php';
require __DIR__ . '/unit/Support/ArrayTokenCache.php';
