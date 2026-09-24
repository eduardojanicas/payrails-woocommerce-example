<?php
/**
 * Config: INI parsing, precedence, redaction, and presence reporting.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Tests;

use PayrailsWoo\Core\Config;
use PayrailsWoo\Core\Exception\ConfigException;
use PayrailsWoo\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/payrails-woo-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
		touch( $this->dir . '/client.crt' );
		touch( $this->dir . '/client.key' );
	}

	protected function tearDown(): void {
		array_map( 'unlink', glob( $this->dir . '/*' ) );
		rmdir( $this->dir );
	}

	private function ini( string $body ): string {
		file_put_contents( $this->dir . '/payrails.env', $body );
		return $this->dir . '/payrails.env';
	}

	public function test_ini_with_dollar_quotes_and_relative_paths(): void {
		$f = $this->ini(
			"PAYRAILS_API_URL=https://api.example.payrails.io/\n" .
			"PAYRAILS_CLIENT_ID=\"client-123\"\n" .
			'PAYRAILS_CLIENT_SECRET=' . Fixtures::SECRET . "\n" .
			"PAYRAILS_WORKFLOW_CODE='my-flow'\n" .
			"PAYRAILS_WORKSPACE_ID=ws-test\nPAYRAILS_CERT_PATH=client.crt\n" .
			"PAYRAILS_KEY_PATH={$this->dir}/client.key\n"
		);
		$c = Config::load( $f, array() );
		$this->assertSame( 'https://api.example.payrails.io', $c->api_url );
		$this->assertSame( 'client-123', $c->client_id );
		$this->assertSame( Fixtures::SECRET, $c->client_secret() );
		$this->assertSame( 'my-flow', $c->workflow_code );
		$this->assertSame( 'ws-test', $c->workspace_id );
		$this->assertSame( $this->dir . '/client.crt', $c->cert_path );
		$this->assertSame( $this->dir . '/client.key', $c->key_path );
		$this->assertSame( 'file', $c->source );
	}

	public function test_defaults(): void {
		$f = $this->ini( "PAYRAILS_CLIENT_ID=a\nPAYRAILS_CLIENT_SECRET=b\nPAYRAILS_WORKSPACE_ID=ws-test\nPAYRAILS_API_URL=https://api.example.payrails.io\nPAYRAILS_CERT_PATH=client.crt\nPAYRAILS_KEY_PATH=client.key\n" );
		$c = Config::load( $f, array() );
		$this->assertSame( 'https://api.example.payrails.io', $c->api_url );
		$this->assertSame( 'payment-acceptance', $c->workflow_code );
	}

	public function test_complete_env_wins_over_file(): void {
		$f   = $this->ini( "PAYRAILS_CLIENT_ID=file\nPAYRAILS_CLIENT_SECRET=file\nPAYRAILS_WORKSPACE_ID=ws-test\nPAYRAILS_API_URL=https://api.example.payrails.io\nPAYRAILS_CERT_PATH=client.crt\nPAYRAILS_KEY_PATH=client.key\n" );
		$env = array(
			'PAYRAILS_CLIENT_ID'     => 'env-id',
			'PAYRAILS_CLIENT_SECRET' => 'env-secret',
			'PAYRAILS_API_URL'       => 'https://api.example.payrails.io',
			'PAYRAILS_WORKSPACE_ID'  => 'ws-env',
			'PAYRAILS_CERT_PATH'     => $this->dir . '/client.crt',
			'PAYRAILS_KEY_PATH'      => $this->dir . '/client.key',
		);
		$c   = Config::load( $f, $env );
		$this->assertSame( 'env-id', $c->client_id );
		$this->assertSame( 'env', $c->source );
	}

	public function test_missing_key_names_the_key_but_no_value(): void {
		$f = $this->ini( 'PAYRAILS_CLIENT_SECRET=' . Fixtures::SECRET . "\nPAYRAILS_WORKSPACE_ID=ws-test\nPAYRAILS_API_URL=https://api.example.payrails.io\nPAYRAILS_CERT_PATH=client.crt\nPAYRAILS_KEY_PATH=client.key\n" );
		try {
			Config::load( $f, array() );
			$this->fail( 'expected ConfigException' );
		} catch ( ConfigException $e ) {
			$this->assertSame( array( 'PAYRAILS_CLIENT_ID' ), $e->problems );
			$this->assertStringContainsString( 'PAYRAILS_CLIENT_ID', $e->getMessage() );
			$this->assertStringNotContainsString( Fixtures::SECRET, $e->getMessage() );
			$this->assertSame( 'PR-CONFIG', $e->public_code() );
			$this->assertSame( 503, $e->confirm_status() );
		}
	}

	public function test_missing_workspace_id_is_a_config_problem(): void {
		$f = $this->ini( "PAYRAILS_API_URL=https://api.example.payrails.io\nPAYRAILS_CLIENT_ID=a\nPAYRAILS_CLIENT_SECRET=b\nPAYRAILS_CERT_PATH=client.crt\nPAYRAILS_KEY_PATH=client.key\n" );
		try {
			Config::load( $f, array() );
			$this->fail( 'expected ConfigException' );
		} catch ( ConfigException $e ) {
			$this->assertSame( array( 'PAYRAILS_WORKSPACE_ID' ), $e->problems );
			$this->assertSame( 'PR-CONFIG', $e->public_code() );
		}
	}

	public function test_api_url_has_no_default(): void {
		$f = $this->ini( "PAYRAILS_CLIENT_ID=a\nPAYRAILS_CLIENT_SECRET=b\nPAYRAILS_WORKSPACE_ID=ws-test\nPAYRAILS_CERT_PATH=client.crt\nPAYRAILS_KEY_PATH=client.key\n" );
		try {
			Config::load( $f, array() );
			$this->fail( 'expected ConfigException' );
		} catch ( ConfigException $e ) {
			$this->assertSame( array( 'PAYRAILS_API_URL' ), $e->problems );
		}
	}

	public function test_unreadable_cert_is_a_config_problem(): void {
		$f = $this->ini( "PAYRAILS_CLIENT_ID=a\nPAYRAILS_CLIENT_SECRET=b\nPAYRAILS_WORKSPACE_ID=ws-test\nPAYRAILS_API_URL=https://api.example.payrails.io\nPAYRAILS_CERT_PATH=missing.crt\nPAYRAILS_KEY_PATH=client.key\n" );
		$this->expectException( ConfigException::class );
		$this->expectExceptionMessage( 'PAYRAILS_CERT_PATH (not readable)' );
		Config::load( $f, array() );
	}

	public function test_missing_file(): void {
		$this->expectException( ConfigException::class );
		$this->expectExceptionMessage( 'PAYRAILS_SECRETS_FILE (not readable)' );
		Config::load( $this->dir . '/nope.env', array() );
	}

	public function test_inspect_reports_presence_only(): void {
		$f = $this->ini( 'PAYRAILS_CLIENT_ID=client-123' . "\nPAYRAILS_CLIENT_SECRET=" . Fixtures::SECRET . "\nPAYRAILS_WORKSPACE_ID=ws-test\nPAYRAILS_API_URL=https://api.example.payrails.io\nPAYRAILS_CERT_PATH=client.crt\n" );
		$r = Config::inspect( $f, array() );
		$this->assertTrue( $r['keys']['PAYRAILS_CLIENT_ID'] );
		$this->assertTrue( $r['keys']['PAYRAILS_CLIENT_SECRET'] );
		$this->assertFalse( $r['keys']['PAYRAILS_KEY_PATH'] );
		$this->assertTrue( $r['cert_readable'] );
		$this->assertFalse( $r['key_readable'] );
		$this->assertSame( 'api.example.payrails.io', $r['api_host'] );
		$flat = json_encode( $r );
		$this->assertStringNotContainsString( Fixtures::SECRET, $flat );
		$this->assertStringNotContainsString( 'client-123', $flat );
	}

	public function test_config_redacts_itself_and_refuses_unserialize(): void {
		$c = Fixtures::config();
		foreach ( array( print_r( $c, true ), json_encode( $c ), serialize( $c ), var_export( ( function () use ( $c ) { ob_start(); var_dump( $c ); return ob_get_clean(); } )(), true ) ) as $out ) {
			$this->assertStringNotContainsString( Fixtures::SECRET, $out );
		}
		$this->expectException( \LogicException::class );
		unserialize( serialize( $c ) );
	}

	public function test_api_url_must_be_an_https_payrails_host(): void {
		foreach ( array( 'https://api.example.payrails.io', 'https://api.payrails.io/', 'https://payrails.io' ) as $ok ) {
			$this->assertTrue( Config::is_payrails_url( $ok ), $ok );
		}
		// Never-resolving test hosts: only with the test switch (PAYRAILS_WOO_TESTING).
		$this->assertFalse( defined( 'PAYRAILS_WOO_TESTING' ), 'unit tests run without the test constant' );
		$this->assertFalse( Config::is_payrails_url( 'https://unreachable.payrails.invalid:9' ), 'refused in production' );
		$this->assertTrue( Config::is_payrails_url( 'https://unreachable.payrails.invalid:9', true ) );
		$this->assertFalse( Config::is_payrails_url( 'https://payrails.invalid.evil.example', true ) );
		foreach ( array( 'http://api.example.payrails.io', 'https://evil.example', 'https://payrails.io.evil.example', 'https://evilpayrails.io', 'https://127.0.0.1:9', 'https://user:pw@api.payrails.io', 'ftp://api.payrails.io', 'api.payrails.io', '' ) as $bad ) {
			$this->assertFalse( Config::is_payrails_url( $bad ), $bad );
		}
		$f = $this->ini( "PAYRAILS_API_URL=https://evil.example\nPAYRAILS_CLIENT_ID=a\nPAYRAILS_CLIENT_SECRET=" . Fixtures::SECRET . "\nPAYRAILS_WORKSPACE_ID=ws-test\nPAYRAILS_CERT_PATH=client.crt\nPAYRAILS_KEY_PATH=client.key\n" );
		try {
			Config::load( $f, array() );
			$this->fail( 'a non-Payrails API URL must be refused (the client secret would be sent there)' );
		} catch ( ConfigException $e ) {
			$this->assertSame( array( 'PAYRAILS_API_URL (not an https://*.payrails.io URL)' ), $e->problems );
			$this->assertStringNotContainsString( Fixtures::SECRET, $e->getMessage() );
		}
		$this->assertNotNull( Config::inspect( $f, array() )['error'] );
		// Env override is checked too.
		$env = array( 'PAYRAILS_API_URL' => 'https://127.0.0.1:9', 'PAYRAILS_CLIENT_ID' => 'a', 'PAYRAILS_CLIENT_SECRET' => 'b', 'PAYRAILS_WORKSPACE_ID' => 'ws-test', 'PAYRAILS_CERT_PATH' => $this->dir . '/client.crt', 'PAYRAILS_KEY_PATH' => $this->dir . '/client.key' );
		$this->expectException( ConfigException::class );
		Config::load( null, $env );
	}
}
