<?php
/**
 * Builds the client-init request body. Pure: no WordPress, no network.
 *
 * A card-acceptance body: amount, merchant and holder references, customer,
 * order lines and client context. Absent optional fields are omitted, never
 * sent as empty strings.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core;

/**
 * Builder.
 */
final class ClientInitBuilder {

	/**
	 * Warnings from the last build (for example "lines_omitted").
	 *
	 * @var string[]
	 */
	public array $warnings = array();

	/**
	 * Payrails integration — step 4 (body): amount, merchantReference = order number,
	 * holderReference, and order lines that sum exactly to the amount (else omitted).
	 *
	 * Builds the body.
	 *
	 * @param OrderSnapshot         $s              Snapshot.
	 * @param string                $workflow_code  Workflow code.
	 * @param string|null           $workspace_id   Workspace id.
	 * @param array<string, string> $client_context ipAddress, origin, userAgent, returnUrl.
	 * @return array<string, mixed>
	 */
	public function build( OrderSnapshot $s, string $workflow_code, ?string $workspace_id, array $client_context ): array {
		$this->warnings = array();
		$exp            = $s->exponent();
		$money          = static function ( int $minor ) use ( $s, $exp ): array {
			return array(
				'value'    => Money::from_minor( $minor, $exp ),
				'currency' => $s->currency,
			);
		};

		$customer = self::present(
			array(
				'reference' => $s->holder_reference,
				'email'     => $s->email,
				'name'      => $s->name,
			)
		);
		if ( '' !== $s->country ) {
			$customer['country'] = array( 'code' => $s->country );
		}

		$order = self::present(
			array(
				'reference'   => $s->order_number,
				'description' => $s->description,
			)
		);
		$lines = self::lines( $s );
		if ( null === $lines ) {
			$this->warnings[] = 'lines_omitted';
		} else {
			$order['lines'] = array();
			foreach ( $lines as $l ) {
				$order['lines'][] = array(
					'id'        => $l['id'],
					'name'      => $l['name'],
					'quantity'  => $l['quantity'],
					'unitPrice' => $money( $l['unit_minor'] ),
				);
			}
		}

		// clientContext.origin and returnUrl must use a host name: a client-init with an
		// IPv4 literal there (e.g. http://127.0.0.1) is refused with 403. Such values are
		// omitted rather than failing the whole request.
		foreach ( array( 'origin', 'returnUrl' ) as $k ) {
			if ( isset( $client_context[ $k ] ) && self::has_ipv4_host( (string) $client_context[ $k ] ) ) {
				unset( $client_context[ $k ] );
				$this->warnings[] = $k . '_ip_literal_omitted';
			}
		}

		$ctx = self::present(
			array(
				'ipAddress' => $client_context['ipAddress'] ?? '',
				'origin'    => $client_context['origin'] ?? '',
				'osType'    => 'web',
				'userAgent' => substr( (string) ( $client_context['userAgent'] ?? '' ), 0, 255 ),
				'returnUrl' => $client_context['returnUrl'] ?? '',
			)
		);

		$body = array(
			'type'              => 'dropIn',
			'holderReference'   => $s->holder_reference,
			'merchantReference' => $s->order_number,
			'workflowCode'      => $workflow_code,
			'amount'            => $money( $s->amount_minor ),
		);
		if ( null !== $workspace_id && '' !== $workspace_id ) {
			$body['workspaceId'] = $workspace_id;
		}
		$body['meta'] = array(
			'CIT'           => true,
			'customer'      => $customer,
			'order'         => $order,
			'clientContext' => $ctx,
		);
		return $body;
	}

	/**
	 * Converts raw lines to {id,name,quantity,unit_minor}, such that
	 * Σ(quantity × unit_minor) === amount_minor. Returns null when that cannot hold
	 * (the caller then omits lines, because a mismatch rejects the whole init).
	 *
	 * @param OrderSnapshot $s Snapshot.
	 * @return array<int, array{id:string, name:string, quantity:int, unit_minor:int}>|null
	 */
	public static function lines( OrderSnapshot $s ): ?array {
		if ( ! $s->lines ) {
			return null;
		}
		$out = array();
		$sum = 0;
		foreach ( $s->lines as $l ) {
			$qty   = max( 1, (int) $l['quantity'] );
			$total = (int) $l['total_minor'];
			if ( $total <= 0 ) {
				return null;
			}
			$name = (string) $l['name'];
			if ( 0 === $total % $qty ) {
				$unit = intdiv( $total, $qty );
			} else {
				$name .= ' × ' . $qty;
				$unit  = $total;
				$qty   = 1;
			}
			$sum  += $unit * $qty;
			$out[] = array(
				'id'         => (string) $l['id'],
				'name'       => '' === $name ? 'Item' : $name,
				'quantity'   => $qty,
				'unit_minor' => $unit,
			);
		}
		return $sum === $s->amount_minor ? $out : null;
	}

	/**
	 * True when the URL's host is an IPv4 literal.
	 *
	 * @param string $url URL.
	 */
	public static function has_ipv4_host( string $url ): bool {
		$host = parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) && false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
	}

	/**
	 * Drops null and blank-string values (absent fields are omitted, never sent empty).
	 *
	 * @param array<string, mixed> $fields Fields.
	 * @return array<string, mixed>
	 */
	private static function present( array $fields ): array {
		return array_filter(
			$fields,
			static function ( $v ): bool {
				return null !== $v && ! ( is_string( $v ) && '' === trim( $v ) );
			}
		);
	}
}
