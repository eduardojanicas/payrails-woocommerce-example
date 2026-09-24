<?php
/**
 * Integration test of the order state machine against the local WordPress, with no
 * network and no credentials. Run: scripts/test-php.sh --integration
 *
 * The Payrails API is replaced through the plugin's testing hook, the
 * payrails_woo_http_transport filter: a FakeTransport answered by FakePayrails (an
 * in-memory stand-in whose outcome the test picks per execution). Everything above
 * the transport (client, mapper, verifier, locking, WooCommerce order transitions)
 * is the real code. Creates throwaway orders and deletes them at the end.
 *
 * @package PayrailsWoo
 */

use PayrailsWoo\Tests\Support\FakePayrails;
use PayrailsWoo\Tests\Support\FakeTransport;
use PayrailsWoo\Woo\ExecutionSession;
use PayrailsWoo\Woo\Services;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/../unit/Support/FakeTransport.php';
require_once __DIR__ . '/Support/FakePayrails.php';

$payrails  = new FakePayrails();
$transport = ( new FakeTransport() )->respond_with( $payrails );
add_filter( 'payrails_woo_http_transport', static fn() => $transport );
Services::reset();
if ( Services::config_problems() ) {
	WP_CLI::error( 'Config incomplete: run through scripts/test-php.sh --integration, which sets dummy PAYRAILS_* variables.' );
}

$failures = 0;
$checks   = 0;
$check    = static function ( bool $ok, string $label ) use ( &$failures, &$checks ) {
	++$checks;
	if ( $ok ) {
		WP_CLI::log( "  ✓ $label" );
	} else {
		++$failures;
		WP_CLI::log( "  ✗ $label" );
	}
};

$make_order = static function (): WC_Order {
	$product_id = wc_get_product_id_by_sku( 'AT-BA-002' );
	$order      = wc_create_order();
	$order->add_product( wc_get_product( $product_id ), 2 );
	$order->set_billing_email( 'it@example.test' );
	$order->set_billing_first_name( 'Inte' );
	$order->set_billing_last_name( 'Gration' );
	$order->set_billing_country( 'US' );
	$order->set_payment_method( 'payrails' );
	$order->calculate_totals();
	$order->set_status( 'pending' );
	$order->save();
	return wc_get_order( $order->get_id() );
};

$run = static function ( WC_Order $order, string $scenario ) use ( $payrails ) {
	ExecutionSession::forget_reads();
	$exec = ExecutionSession::current_id( $order );
	$payrails->set_outcome( (string) $exec, $scenario );
	$res = ExecutionSession::read( $order, (string) $exec, true );
	return ExecutionSession::settle( $order->get_id(), (string) $exec, $res );
};
$count_notes = static function ( int $id, string $needle ): int {
	return count(
		array_filter(
			wc_get_order_notes( array( 'order_id' => $id ) ),
			// payment_complete() writes "Payment via {title} ({txn})", or "Payment complete." when the order has no method title.
			static fn( $n ) => 'Payment via' === $needle ? ( false !== strpos( $n->content, 'Payment via' ) || 0 === strpos( $n->content, 'Payment complete' ) ) : false !== strpos( $n->content, $needle )
		)
	);
};

$created = array();

WP_CLI::log( 'success' );
$o         = $make_order();
$created[] = $o->get_id();
$view      = ExecutionSession::prepare( $o );
$check( 'mount' === $view['mode'] && '' !== (string) ( $view['execution_id'] ?? '' ), 'prepare() creates an execution and mounts the Drop-in' );
$again = ExecutionSession::prepare( wc_get_order( $o->get_id() ) );
$check( $again['execution_id'] === $view['execution_id'], 'second prepare() reuses the cached execution' );
$out = $run( $o, 'success' );
$o   = wc_get_order( $o->get_id() );
$check( 'authorized' === $out['state'] && $o->has_status( 'processing' ), 'success → processing' );
$check( $o->get_transaction_id() === $view['execution_id'], 'transaction id = execution id' );
$notes = count( wc_get_order_notes( array( 'order_id' => $o->get_id() ) ) );
$run( $o, 'success' );
$check( count( wc_get_order_notes( array( 'order_id' => $o->get_id() ) ) ) === $notes, 'replayed success adds no note' );

WP_CLI::log( 'duplicate completion: pay-page render with a stale order object after another request completed it' );
$o         = $make_order();
$created[] = $o->get_id();
$view      = ExecutionSession::prepare( $o );
$stale     = wc_get_order( $o->get_id() );            // A pay-page request loaded the order while it was pending...
$check( $stale->has_status( 'pending' ), 'stale copy is pending' );
$out = $run( $o, 'success' );                          // ...then a confirm completed it.
$check( 'authorized' === $out['state'], 'confirm completes the order' );
ExecutionSession::forget_reads();
$again = ExecutionSession::prepare( $stale );          // The pay page now renders with its stale copy.
$check( 'paid' === $again['mode'], 'stale render reports paid' );
$check( 1 === $count_notes( $o->get_id(), 'Payment via' ), 'exactly one "Payment via" note (payment_complete ran once)' );
$check( 1 === $count_notes( $o->get_id(), 'Payrails authorized' ), 'exactly one "Payrails authorized" note' );
$check( 1 === $count_notes( $o->get_id(), 'Processing order' ), 'exactly one "Processing order" email' );
foreach ( range( 1, 3 ) as $i ) {
	ExecutionSession::forget_reads();
	ExecutionSession::prepare( $stale );
	$run( $stale, 'success' );
}
$check( 1 === $count_notes( $o->get_id(), 'Payment via' ), 'still one completion after 3 more stale renders + confirms' );

WP_CLI::log( 'the order lock' );
$o         = $make_order();
$created[] = $o->get_id();
ExecutionSession::prepare( $o );
$token = \PayrailsWoo\Woo\OrderLock::acquire( $o->get_id() );
$check( is_string( $token ) && strlen( $token ) >= 32, 'acquire returns an owner token' );
$check( null === \PayrailsWoo\Woo\OrderLock::acquire( $o->get_id() ), 'a second acquire is refused' );
$out = $run( $o, 'success' );
$check( ! empty( $out['locked'] ) && 'pending' === $out['state'] && ! wc_get_order( $o->get_id() )->is_paid(), 'settle while locked → pending, nothing written' );
\PayrailsWoo\Woo\OrderLock::release( $o->get_id(), 'not-the-owner-token' );
$check( null === \PayrailsWoo\Woo\OrderLock::acquire( $o->get_id() ), 'release with a foreign token does not release' );
\PayrailsWoo\Woo\OrderLock::release( $o->get_id(), $token );
$out = $run( $o, 'success' );
$check( 'authorized' === $out['state'] && 1 === $count_notes( $o->get_id(), 'Payment via' ), 'after the owner releases, settle completes once' );
$check( \PayrailsWoo\Woo\OrderLock::TTL >= 120, 'lock TTL is at least 120 s' );

WP_CLI::log( 'a second authorized execution on a paid order is noted once' );
ExecutionSession::forget_reads();
$paid   = wc_get_order( $o->get_id() );
$second = Services::client()->client_init( ( new \PayrailsWoo\Core\ClientInitBuilder() )->build( \PayrailsWoo\Woo\SnapshotFactory::from( $paid ), 'payment-acceptance', null, array() ), 'second-' . $paid->get_id() )['execution_id'];
$paid->update_meta_data( ExecutionSession::META_ALL, wp_json_encode( array_merge( ExecutionSession::known_ids( $paid ), array( $second ) ) ) );
$paid->save();
$payrails->set_outcome( $second, 'success' );
foreach ( range( 1, 2 ) as $i ) {
	$r = ExecutionSession::settle( $paid->get_id(), $second, ExecutionSession::read( $paid, $second, true ) );
}
$check( 'authorized' === $r['state'] && 1 === $count_notes( $paid->get_id(), 'second authorization' ), 'one "second authorization" note, order untouched' );
$check( 1 === $count_notes( $paid->get_id(), 'Payment via' ), 'second authorization does not complete again' );

WP_CLI::log( 'guest holder reference' );
$g1        = $make_order();
$g2        = $make_order();                           // same billing e-mail as $g1
$created[] = $g1->get_id();
$created[] = $g2->get_id();
$h1        = \PayrailsWoo\Woo\SnapshotFactory::holder_reference( $g1 );
$h2        = \PayrailsWoo\Woo\SnapshotFactory::holder_reference( $g2 );
$check( 1 === preg_match( '/^wc-guest-[0-9a-f]{32}$/', $h1 ), 'guest holder is random-format' );
$check( $h1 !== $h2, 'same e-mail, different orders → different holders' );
$check( $h1 === \PayrailsWoo\Woo\SnapshotFactory::holder_reference( wc_get_order( $g1->get_id() ) ), 'stable for the same order (stored in meta)' );
$check( false === strpos( $h1, md5( 'it@example.test' ) ) && $h1 === \PayrailsWoo\Woo\SnapshotFactory::from( wc_get_order( $g1->get_id() ) )->holder_reference, 'snapshot uses the stored random holder' );

WP_CLI::log( 'decline, then retry on the same order' );
$o         = $make_order();
$created[] = $o->get_id();
$first     = ExecutionSession::prepare( $o );
$out       = $run( $o, 'decline' );
$o         = wc_get_order( $o->get_id() );
$check( 'failed' === $out['state'] && $o->has_status( 'failed' ), 'decline → failed' );
$check( '' === $o->get_transaction_id(), 'declined order has no transaction id' );
ExecutionSession::forget_reads();
$second = ExecutionSession::prepare( $o );
$check( $second['execution_id'] !== $first['execution_id'], 'pay page after a decline creates a new execution' );
$check( 2 === count( ExecutionSession::known_ids( wc_get_order( $o->get_id() ) ) ), 'both executions are on the allow-list' );
$out = $run( wc_get_order( $o->get_id() ), 'success' );
$check( 'authorized' === $out['state'] && wc_get_order( $o->get_id() )->has_status( 'processing' ), 'retry success → processing' );

WP_CLI::log( 'pending' );
$o         = $make_order();
$created[] = $o->get_id();
ExecutionSession::prepare( $o );
$out = $run( $o, 'pending' );
$check( 'pending' === $out['state'] && wc_get_order( $o->get_id() )->has_status( 'pending' ), 'pending leaves the order pending' );
ExecutionSession::forget_reads();
$view = ExecutionSession::prepare( wc_get_order( $o->get_id() ) );
$check( 'pending' === $view['mode'], 'reload during an in-flight authorize shows "pending", not a second form' );

WP_CLI::log( 'amount mismatch' );
$o         = $make_order();
$created[] = $o->get_id();
ExecutionSession::prepare( $o );
$out = $run( $o, 'mismatch' );
$o   = wc_get_order( $o->get_id() );
$check( 'review' === $out['state'] && $o->has_status( 'on-hold' ) && ! $o->is_paid(), 'mismatch → on-hold, never paid' );

WP_CLI::log( 'order total changed after init' );
$o         = $make_order();
$created[] = $o->get_id();
ExecutionSession::prepare( $o );
$o->set_total( (string) ( (float) $o->get_total() + 10 ) );
$o->save();
$out = $run( wc_get_order( $o->get_id() ), 'success' );
$check( 'review' === $out['state'] && ! wc_get_order( $o->get_id() )->is_paid(), 'authorized for the old amount → review, not paid' );

WP_CLI::log( 'logged-in customer holder reference' );
$uid = username_exists( 'holder_test_customer' ) ?: wc_create_new_customer( 'holder.test@example.test', 'holder_test_customer', wp_generate_password( 20 ) );
delete_user_meta( $uid, '_payrails_holder_reference' );
$c1 = $make_order();
$c1->set_customer_id( $uid );
$c1->save();
$c2 = $make_order();
$c2->set_customer_id( $uid );
$c2->save();
$created[] = $c1->get_id();
$created[] = $c2->get_id();
$hc1       = \PayrailsWoo\Woo\SnapshotFactory::holder_reference( wc_get_order( $c1->get_id() ) );
$hc2       = \PayrailsWoo\Woo\SnapshotFactory::holder_reference( wc_get_order( $c2->get_id() ) );
$check( 1 === preg_match( '/^wc-customer-[0-9a-f]{32}$/', $hc1 ) && 'wc-customer-' . $uid !== $hc1, 'customer holder is random, not the user id' );
$check( $hc1 === $hc2 && $hc1 === get_user_meta( $uid, '_payrails_holder_reference', true ), 'stable across that customer\'s orders (stored once in user meta)' );
wp_delete_user( $uid );

WP_CLI::log( 'a decline on an already-failed order is noted once' );
$o         = $make_order();
$created[] = $o->get_id();
ExecutionSession::prepare( $o );
$run( $o, 'decline' );                                  // pending → failed (first note)
ExecutionSession::forget_reads();
ExecutionSession::prepare( wc_get_order( $o->get_id() ) ); // new execution for the failed order
$run( wc_get_order( $o->get_id() ), 'decline' );        // second decline, order already failed
$run( wc_get_order( $o->get_id() ), 'decline' );        // same execution confirmed again
$check( wc_get_order( $o->get_id() )->has_status( 'failed' ) && 2 === $count_notes( $o->get_id(), 'authorizeFailed' ), 'one note per declined execution (2), none for the repeat' );

WP_CLI::log( 'session expired: dropping the cache starts a fresh execution' );
$o         = $make_order();
$created[] = $o->get_id();
$v1        = ExecutionSession::prepare( $o );
ExecutionSession::forget_reads();
$v2 = ExecutionSession::prepare( wc_get_order( $o->get_id() ) );
ExecutionSession::drop_cache( wc_get_order( $o->get_id() ) );
ExecutionSession::forget_reads();
$v3 = ExecutionSession::prepare( wc_get_order( $o->get_id() ) );
$check( $v1['execution_id'] === $v2['execution_id'] && $v3['execution_id'] !== $v1['execution_id'] && 'mount' === $v3['mode'], 'cached session reused; after drop_cache a new execution is mounted' );

WP_CLI::log( 'a success later cancelled is not paid' );
$o         = $make_order();
$created[] = $o->get_id();
ExecutionSession::prepare( $o );
$out = $run( $o, 'cancelled_after_success' );
$check( 'failed' === $out['state'] && ! wc_get_order( $o->get_id() )->is_paid(), 'authorizeSuccessful then authorizeCancelled → failed, never paid' );

WP_CLI::log( 'test seam' );
$hosts = array_unique( array_map( static fn( $r ) => (string) parse_url( $r->url, PHP_URL_HOST ), $transport->requests ) );
$check( count( $transport->requests ) > 20 && array( 'api.payrails.invalid' ) === array_values( $hosts ), 'every Payrails call went through the injected FakeTransport (' . count( $transport->requests ) . ' requests)' );

if ( getenv( "KEEP_ORDERS" ) ) { $created = array(); WP_CLI::log( "kept orders" ); }
foreach ( $created as $id ) {
	$order = wc_get_order( $id );
	if ( $order ) {
		$order->delete( true );
	}
}

if ( $failures ) {
	WP_CLI::error( "$failures of $checks integration checks failed." );
}
WP_CLI::success( "$checks integration checks passed (orders cleaned up)." );
