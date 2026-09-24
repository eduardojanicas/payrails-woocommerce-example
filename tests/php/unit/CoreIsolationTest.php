<?php
/**
 * The Core tree must stay WordPress-free (that is what makes it unit-testable).
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Tests;

use PHPUnit\Framework\TestCase;

final class CoreIsolationTest extends TestCase {

	public function test_core_makes_no_wordpress_calls(): void {
		$dir   = __DIR__ . '/../../../plugin/payrails-woo/includes/Core';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		$count = 0;
		foreach ( $files as $f ) {
			if ( 'php' !== $f->getExtension() ) {
				continue;
			}
			++$count;
			$code = preg_replace( '#(/\*.*?\*/|//[^\n]*|\#[^\n\[]*)#s', '', (string) file_get_contents( $f->getPathname() ) );
			$this->assertDoesNotMatchRegularExpression( '/\b(get_option|update_option|get_transient|set_transient|wp_[a-z_]+\s*\(|WC\(|wc_[a-z_]+\s*\(|add_action|add_filter|apply_filters|do_action|esc_[a-z]+\s*\(|__\s*\()/', $code, $f->getFilename() . ' calls WordPress' );
		}
		$this->assertGreaterThan( 15, $count );
	}
}
