<?php
/**
 * ExecutionMapper state mapping.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Tests;

use PayrailsWoo\Core\ExecutionMapper;
use PayrailsWoo\Core\ExecutionResult;
use PayrailsWoo\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExecutionMapperTest extends TestCase {

	public static function cases(): array {
		return array(
			'created only'                 => array( array( 'created' ), 'pending', false, 'created' ),
			'authorize requested'          => array( array( 'created', 'authorizeRequested' ), 'pending', true, 'authorizeRequested' ),
			'authorized'                   => array( array( 'created', 'authorizeRequested', 'authorizeSuccessful' ), 'authorized', true, 'authorizeSuccessful' ),
			'captured'                     => array( array( 'created', 'authorizeSuccessful', 'captureRequested', 'captureSuccessful' ), 'authorized', true, 'captureSuccessful' ),
			'declined last'                => array( array( 'created', 'authorizeRequested', 'authorizeFailed' ), 'failed', true, 'authorizeFailed' ),
			'retry after decline succeeds' => array( array( 'authorizeFailed', 'authorizeRequested', 'authorizeSuccessful' ), 'authorized', true, 'authorizeSuccessful' ),
			'failed then retry in flight'  => array( array( 'created', 'authorizeFailed', 'authorizeRequested' ), 'pending', true, 'authorizeRequested' ),
			'decline then trailing pending' => array( array( 'created', 'authorizeRequested', 'authorizeFailed', 'authorizePending' ), 'failed', true, 'authorizeFailed' ),
			'3ds pending'                  => array( array( 'created', 'authorizeRequested', 'authorizePending' ), 'pending', true, 'authorizePending' ),
			'3ds then decline'             => array( array( 'created', 'authorizeRequested', 'authorizePending', 'authorizeFailed' ), 'failed', true, 'authorizeFailed' ),
			'decline, retry, pending'      => array( array( 'created', 'authorizeRequested', 'authorizeFailed', 'authorizeRequested', 'authorizePending' ), 'pending', true, 'authorizePending' ),
			'createSuccessful is not paid' => array( array( 'createSuccessful' ), 'pending', true, 'createSuccessful' ),
			'unknown code'                 => array( array( 'created', 'somethingNew' ), 'pending', true, 'somethingNew' ),
			'empty status'                 => array( array(), 'pending', false, null ),
			'cancelled'                    => array( array( 'created', 'authorizeCancelled' ), 'failed', true, 'authorizeCancelled' ),
			'expired'                      => array( array( 'created', 'sessionExpired' ), 'failed', true, 'sessionExpired' ),
			'case-insensitive success'     => array( array( 'AUTHORIZESUCCESSFUL' ), 'authorized', true, 'AUTHORIZESUCCESSFUL' ),
		);
	}

	#[DataProvider( 'cases' )]
	public function test_mapping( array $codes, string $state, bool $attempted, ?string $last ): void {
		$r = ExecutionMapper::map( Fixtures::execution( $codes ) );
		$this->assertSame( $state, $r->state );
		$this->assertSame( $attempted, $r->attempted );
		$this->assertSame( $last, $r->last_code );
		$this->assertSame( $codes, $r->codes );
	}

	public function test_missing_status_and_junk_entries(): void {
		$this->assertSame( ExecutionResult::PENDING, ExecutionMapper::map( array( 'id' => 'x' ) )->state );
		$r = ExecutionMapper::map( array( 'status' => array( 'junk', array( 'nocode' => 1 ), array( 'code' => 5 ), array( 'code' => 'authorizeSuccessful' ) ) ) );
		$this->assertSame( ExecutionResult::AUTHORIZED, $r->state );
		$this->assertSame( array( 'authorizeSuccessful' ), $r->codes );
	}

	public function test_fields_are_extracted_and_capture_flagged(): void {
		$r = ExecutionMapper::map( Fixtures::execution( array( 'authorizeSuccessful', 'captureSuccessful' ), 'e-7', '12.50', 'EUR', 'R-9' ) );
		$this->assertSame( 'e-7', $r->id );
		$this->assertSame( '12.50', $r->amount_value );
		$this->assertSame( 'EUR', $r->amount_currency );
		$this->assertSame( 'R-9', $r->merchant_reference );
		$this->assertTrue( $r->captured );
		$this->assertFalse( ExecutionMapper::map( Fixtures::execution( array( 'authorizeSuccessful' ) ) )->captured );
	}

	public function test_real_staging_fixture_out_of_order_status_is_sorted_by_time(): void {
		$ex = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/execution-authorized.json' ), true );
		$r  = ExecutionMapper::map( $ex );
		$this->assertSame( ExecutionResult::AUTHORIZED, $r->state );
		$this->assertSame( array( 'created', 'authorizeRequested', 'authorizePending', 'authorizeSuccessful' ), $r->codes );
		$this->assertFalse( $r->captured );
		$this->assertSame( '27', $r->merchant_reference );
	}

	public function test_failure_uses_the_latest_code_by_time_not_by_position(): void {
		// Returned out of order: the decline is chronologically last.
		$ex = array(
			'status' => array(
				array( 'code' => 'authorizeFailed', 'time' => '2026-09-23T10:00:05.000000002Z' ),
				array( 'code' => 'created', 'time' => '2026-09-23T10:00:00Z' ),
				array( 'code' => 'authorizeRequested', 'time' => '2026-09-23T10:00:05.000000001Z' ),
			),
		);
		$this->assertSame( ExecutionResult::FAILED, ExecutionMapper::map( $ex )->state );
		// And a retry that is chronologically after the decline is still in flight.
		$ex['status'][] = array( 'code' => 'authorizeRequested', 'time' => '2026-09-23T10:01:00+00:00' );
		$this->assertSame( ExecutionResult::PENDING, ExecutionMapper::map( $ex )->state );
	}

	public function test_time_key(): void {
		$this->assertSame( '', ExecutionMapper::time_key( 'yesterday' ) );
		$this->assertSame( '', ExecutionMapper::time_key( '' ) );
		$this->assertLessThan( 0, strcmp( ExecutionMapper::time_key( '2026-09-23T22:25:38.230780667Z' ), ExecutionMapper::time_key( '2026-09-23T22:25:38.410334201Z' ) ) );
		$this->assertSame( ExecutionMapper::time_key( '2026-09-23T12:00:00Z' ), ExecutionMapper::time_key( '2026-09-23T14:00:00+02:00' ) );
		$this->assertLessThan( 0, strcmp( ExecutionMapper::time_key( '2026-09-23T12:00:00.9Z' ), ExecutionMapper::time_key( '2026-09-23T12:00:01Z' ) ) );
	}

	public function test_pending_3ds_action_url_is_extracted_and_host_checked(): void {
		$ex          = Fixtures::execution( array( 'created', 'authorizeRequested', 'authorizePending' ) );
		$ex['links'] = array( 'self' => 'x', '3ds' => 'https://api-pub.example.payrails.io/public/redirect/merchant/wf/abc/def' );
		$this->assertSame( 'https://api-pub.example.payrails.io/public/redirect/merchant/wf/abc/def', ExecutionMapper::map( $ex )->action_url );

		$ex['requiredAction'] = array( 'type' => 'redirect', 'href' => 'https://api-pub.example.payrails.io/public/redirect/ra' );
		$this->assertSame( 'https://api-pub.example.payrails.io/public/redirect/ra', ExecutionMapper::map( $ex )->action_url );

		foreach ( array( 'http://api-pub.example.payrails.io/x', 'https://evil.example/x', 'https://payrails.io.evil.example/x', 'https://user@api.payrails.io/x', 'javascript:alert(1)' ) as $bad ) {
			$ex = Fixtures::execution( array( 'authorizePending' ) );
			$ex['links'] = array( '3ds' => $bad );
			$this->assertNull( ExecutionMapper::map( $ex )->action_url, $bad );
		}
		// Authorized executions never carry an action.
		$ok          = Fixtures::execution( array( 'authorizePending', 'authorizeSuccessful' ) );
		$ok['links'] = array( '3ds' => 'https://api-pub.example.payrails.io/x' );
		$this->assertNull( ExecutionMapper::map( $ok )->action_url );
	}

	public function test_real_staging_decline_with_trailing_pending_is_failed(): void {
		$r = ExecutionMapper::map( json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/execution-declined.json' ), true ) );
		$this->assertSame( ExecutionResult::FAILED, $r->state );
		$this->assertSame( 'authorizeFailed', $r->last_code );
		$this->assertSame( 'GenericRejection', $r->failure_reason );
		$this->assertNull( $r->action_url, 'a declined execution must not offer 3DS' );
	}

	public function test_real_staging_3ds_success(): void {
		$r = ExecutionMapper::map( json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/execution-3ds-authorized.json' ), true ) );
		$this->assertSame( ExecutionResult::AUTHORIZED, $r->state );
		$this->assertNull( $r->action_url );
	}

	public static function later_events(): array {
		return array(
			'preAuthorizeSuccessful is not a success'  => array( array( 'created', 'authorizeRequested', 'preAuthorizeSuccessful' ), 'pending' ),
			'xAuthorizeSuccessful is not a success'     => array( array( 'xauthorizeSuccessful' ), 'pending' ),
			'captureSuccessfulish is not a success'     => array( array( 'captureSuccessfulish' ), 'pending' ),
			'success then cancelled'                    => array( array( 'created', 'authorizeRequested', 'authorizeSuccessful', 'authorizeCancelled' ), 'failed' ),
			'success then voided'                       => array( array( 'authorizeRequested', 'authorizeSuccessful', 'authorizationVoided' ), 'failed' ),
			'success then voidSuccessful'               => array( array( 'authorizeRequested', 'authorizeSuccessful', 'voidSuccessful' ), 'failed' ),
			'success then cancelSuccessful'             => array( array( 'authorizeRequested', 'authorizeSuccessful', 'cancelSuccessful' ), 'failed' ),
			'success then refundSuccessful'             => array( array( 'authorizeRequested', 'authorizeSuccessful', 'captureSuccessful', 'refundSuccessful' ), 'failed' ),
			'success then expired'                      => array( array( 'authorizeRequested', 'authorizeSuccessful', 'authorizationExpired' ), 'failed' ),
			'success then captureFailed'                => array( array( 'authorizeRequested', 'authorizeSuccessful', 'captureRequested', 'captureFailed' ), 'failed' ),
			'success then unknown code'                 => array( array( 'authorizeRequested', 'authorizeSuccessful', 'somethingNewHappened' ), 'pending' ),
			'failure then unknown code'                 => array( array( 'authorizeRequested', 'authorizeFailed', 'somethingNewHappened' ), 'pending' ),
			'success then capture in progress'          => array( array( 'authorizeRequested', 'authorizeSuccessful', 'captureRequested', 'capturePending' ), 'authorized' ),
			'failure then success (latest wins)'        => array( array( 'authorizeRequested', 'authorizeFailed', 'authorizeSuccessful' ), 'authorized' ),
			'success in an earlier attempt, new decline' => array( array( 'authorizeRequested', 'authorizeSuccessful', 'authorizeRequested', 'authorizeFailed' ), 'failed' ),
		);
	}

	#[DataProvider( 'later_events' )]
	public function test_latest_terminal_code_in_latest_attempt_decides( array $codes, string $state ): void {
		$r = ExecutionMapper::map( Fixtures::execution( $codes ) );
		$this->assertSame( $state, $r->state, implode( ',', $codes ) );
		if ( 'authorized' !== $state ) {
			$this->assertFalse( $r->is_authorized() );
			$this->assertFalse( $r->captured );
		}
	}

	public function test_success_regex_is_anchored(): void {
		foreach ( array( 'authorizeSuccessful', 'captureSuccessful', 'AuthorizeSuccessful' ) as $ok ) {
			$this->assertMatchesRegularExpression( ExecutionMapper::SUCCESS_RE, $ok );
		}
		foreach ( array( 'preAuthorizeSuccessful', 'reauthorizeSuccessful', 'authorizeSuccessfulX', 'createSuccessful', 'voidSuccessful' ) as $bad ) {
			$this->assertDoesNotMatchRegularExpression( ExecutionMapper::SUCCESS_RE, $bad );
		}
	}

	public function test_captured_only_when_capture_is_the_standing_success(): void {
		$this->assertTrue( ExecutionMapper::map( Fixtures::execution( array( 'authorizeRequested', 'authorizeSuccessful', 'captureRequested', 'captureSuccessful' ) ) )->captured );
		$this->assertFalse( ExecutionMapper::map( Fixtures::execution( array( 'authorizeRequested', 'authorizeSuccessful', 'captureRequested' ) ) )->captured );
	}
}
