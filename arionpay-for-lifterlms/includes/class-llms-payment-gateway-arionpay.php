<?php
/**
 * ArionPay payment gateway for LifterLMS.
 *
 * @license MIT
 */

defined( 'ABSPATH' ) || exit;

class LLMS_Payment_Gateway_ArionPay extends LLMS_Payment_Gateway {

	const CURRENCIES = array( 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'TRY', 'AED', 'INR', 'BRL', 'RUB', 'CNY', 'SGD', 'HKD', 'KRW', 'MXN', 'PLN', 'SEK', 'NOK', 'ZAR' );
	const OPEN       = array( 'pending', 'paid_partial', 'claim_pending' );
	const META       = '_arionpay_payment';
	const MAX_BODY   = 262144;

	public function __construct() {
		$this->id                   = 'arionpay';
		$this->admin_title          = __( 'ArionPay (crypto)', 'arionpay-for-lifterlms' );
		$this->admin_description    = __( 'Bitcoin, USDT, Litecoin, Ethereum, TRON and more, paid straight to your own wallet.', 'arionpay-for-lifterlms' );
		$this->title                = __( 'Crypto (Bitcoin, USDT and more)', 'arionpay-for-lifterlms' );
		$this->description          = __( 'You will pay on the secure ArionPay page. Your access starts as soon as the network confirms the payment.', 'arionpay-for-lifterlms' );
		$this->test_mode_title      = __( 'Test mode', 'arionpay-for-lifterlms' );
		$this->test_mode_description = __( 'Test invoices: no real money moves. Turn it off before you take real payments.', 'arionpay-for-lifterlms' );
		$this->supports             = array(
			'checkout_fields'    => false,
			'refunds'            => false,
			'single_payments'    => true,
			'recurring_payments' => true,
			'recurring_retry'    => false,
			'test_mode'          => true,
		);
		add_filter( 'llms_get_gateway_settings_fields', array( $this, 'settings_fields' ), 10, 2 );
		add_action( 'lifterlms_before_view_order_table', array( $this, 'order_page' ) );
	}

	// ------------------------------------------------------------------ settings

	public function settings_fields( $fields, $gateway_id ) {
		if ( $this->id !== $gateway_id ) {
			return $fields;
		}
		$fields[] = array(
			'id'    => $this->get_option_name( 'api_key' ),
			'title' => __( 'API key', 'arionpay-for-lifterlms' ),
			'desc'  => '<br>' . __( 'ArionPay dashboard > your store > API key (starts with pk_).', 'arionpay-for-lifterlms' ),
			'type'  => 'text',
		);
		$fields[] = array(
			'id'    => $this->get_option_name( 'api_secret' ),
			'title' => __( 'API secret', 'arionpay-for-lifterlms' ),
			'desc'  => '<br>' . __( 'ArionPay dashboard > your store > API secret (starts with sk_). Or define LLMS_ARIONPAY_API_SECRET in wp-config.php.', 'arionpay-for-lifterlms' ),
			'type'  => 'password',
			'obfuscate' => true, // the page shows a partly hidden value; saving it unchanged keeps the secret
			'secure_option' => 'LLMS_ARIONPAY_API_SECRET',
		);
		$fields[] = array(
			'id'    => $this->get_option_name( 'webhook_secret' ),
			'title' => __( 'Webhook signing secret', 'arionpay-for-lifterlms' ),
			'desc'  => '<br>' . __( 'ArionPay dashboard > Webhooks > signing secret (starts with whsec_). Or define LLMS_ARIONPAY_WEBHOOK_SECRET in wp-config.php.', 'arionpay-for-lifterlms' ),
			'type'  => 'password',
			'obfuscate' => true,
			'secure_option' => 'LLMS_ARIONPAY_WEBHOOK_SECRET',
		);
		$fields[] = array(
			'id'    => $this->get_option_name( 'base_url' ),
			'title' => __( 'ArionPay address', 'arionpay-for-lifterlms' ),
			'desc'  => '<br>' . __( 'Leave empty unless ArionPay tells you otherwise.', 'arionpay-for-lifterlms' ),
			'type'  => 'text',
		);
		$fields[] = array(
			'id'    => $this->get_option_name( 'webhook_info' ),
			'type'  => 'custom-html',
			'value' => '<p><strong>' . esc_html__( 'Payment updates', 'arionpay-for-lifterlms' ) . ':</strong> <code>' . esc_html( self::webhook_url() ) . '</code><br>' . esc_html__( 'Nothing to paste: every invoice tells ArionPay to send its updates here.', 'arionpay-for-lifterlms' ) . '</p>',
		);
		return $fields;
	}

	// ------------------------------------------------------------------ checkout

	/** Checkout: the order is pending; make its first invoice and send the student to the ArionPay page. */
	public function handle_pending_order( $order, $plan, $student, $coupon = false ) {
		$amount = (float) $order->get_initial_price( array(), 'float' );
		if ( $amount <= 0 ) {
			// A free first payment (for example a free trial): nothing to pay now.
			$order->record_transaction(
				array(
					'amount'             => 0,
					'source_description' => __( 'Free', 'arionpay-for-lifterlms' ),
					'transaction_id'     => uniqid( 'free-' ),
					'status'             => 'llms-txn-succeeded',
					'payment_gateway'    => $this->id,
					'payment_type'       => $order->has_trial() ? 'trial' : 'single',
				)
			);
			return $this->complete_transaction( $order );
		}
		$type = $order->has_trial() ? 'trial' : ( $order->is_recurring() ? 'recurring' : 'single' );
		try {
			$url = $this->start_payment( $order, $amount, $type, 'first' );
		} catch ( \Throwable $e ) {
			$this->log( 'ArionPay: could not start the payment for order ' . $order->get( 'id' ) . ': ' . $e->getMessage() );
			llms_add_notice( __( 'The crypto payment could not be started right now. Please try again in a minute.', 'arionpay-for-lifterlms' ), 'error' );
			return;
		}
		do_action( 'lifterlms_handle_pending_order_complete', $order );
		llms_redirect_and_exit( $url ? $url : $order->get_view_link(), array( 'safe' => false ) );
	}

	/**
	 * A recurring payment is due: crypto cannot charge by itself, so make an invoice, put the order on hold (access
	 * pauses) and send the student the payment link. Paying it makes the order active again.
	 */
	public function handle_recurring_transaction( $order ) {
		$amount = (float) $order->get_price( 'total', array(), 'float' );
		if ( $amount <= 0 ) {
			return;
		}
		$due = $order->get_next_payment_due_date( 'Ymd' );
		try {
			$url = $this->start_payment( $order, $amount, 'recurring', 'renewal-' . $due );
		} catch ( \Throwable $e ) {
			$this->log( 'ArionPay: could not create the renewal invoice for order ' . $order->get( 'id' ) . ': ' . $e->getMessage() );
			$order->set_status( 'on-hold' );
			return;
		}
		$order->set_status( 'on-hold' );
		if ( $url ) {
			/* translators: %s: payment page address */
			$order->add_note( sprintf( __( 'Renewal due. ArionPay payment page sent to the student: %s', 'arionpay-for-lifterlms' ), $url ) );
			$email = $order->get( 'billing_email' );
			if ( $email ) {
				wp_mail(
					$email,
					/* translators: %s: site name */
					sprintf( __( '[%s] Your next payment is due', 'arionpay-for-lifterlms' ), get_bloginfo( 'name' ) ),
					/* translators: 1: amount, 2: payment page address, 3: order page address */
					sprintf( __( "Your next payment of %1\$s is due. Pay with crypto here:\n%2\$s\n\nYour order: %3\$s", 'arionpay-for-lifterlms' ), $order->get_price( 'total', array(), 'float' ) . ' ' . $order->get( 'currency' ), $url, $order->get_view_link() )
				);
			}
		}
	}

	/**
	 * The ArionPay page for a payment of this order: the open invoice when it still fits, otherwise a new one.
	 * Null when ArionPay already has it as paid (it is then recorded).
	 */
	private function start_payment( $order, $amount, $type, $key ) {
		$currency = strtoupper( (string) $order->get( 'currency' ) );
		if ( ! in_array( $currency, self::CURRENCIES, true ) ) {
			throw new \RuntimeException( sprintf( 'ArionPay does not take %s.', $currency ) );
		}
		$money    = number_format( $amount, 2, '.', '' );
		$order_id = (int) $order->get( 'id' );
		$test     = 'test' === $order->get( 'gateway_api_mode' );
		$current  = (array) get_post_meta( $order_id, self::META, true );
		$attempt  = ( ( $current['key'] ?? '' ) === $key ) ? max( 1, (int) ( $current['attempt'] ?? 1 ) ) : 1;
		$client   = $this->client();

		if ( ( $current['key'] ?? '' ) === $key && ! empty( $current['invoice_id'] ) ) {
			try {
				$read = $client->getInvoice( $current['invoice_id'] );
			} catch ( \ArionPay\ApiException $e ) {
				if ( 404 !== $e->getCode() ) {
					throw $e;
				}
				$read = array( 'status' => 'missing', 'raw' => array() );
			}
			if ( 'paid' === $read['status'] ) {
				self::record_paid( $order_id, $read, 'signed read' );
				return null;
			}
			$expires = isset( $read['raw']['expiresAt'] ) ? strtotime( (string) $read['raw']['expiresAt'] ) : 0;
			if ( ( in_array( $read['status'], self::OPEN, true ) || ( 'new' === $read['status'] && ( 0 === $expires || $expires > time() + 300 ) ) ) && ( $current['amount'] ?? '' ) === $money ) {
				return $current['url'];
			}
			$attempt++;
		}

		for ( $tries = 0; $tries < 20; $tries++, $attempt++ ) {
			$created = $client->createInvoice(
				array(
					'amount'         => $money,
					'currency'       => $currency,
					'orderId'        => (string) $order_id,
					'description'    => mb_substr( sprintf( '%s - %s', get_bloginfo( 'name' ), $order->get( 'product_title' ) ), 0, 500 ),
					'successUrl'     => $order->get_view_link(),
					'cancelUrl'      => $order->get_view_link(),
					'callbackUrl'    => self::webhook_url(),
					'metadata'       => array(
						'llms_order_id'     => $order_id,
						'llms_payment_type' => $type,
						'llms_payment_key'  => $key,
						'llms_amount'       => $money,
						'llms_currency'     => $currency,
						'llms_site'         => self::site_id(),
						'platform'          => 'lifterlms',
					),
					'idempotencyKey' => 'llms-' . self::site_id() . '-' . $order_id . '-' . $key . '-' . str_replace( '.', '', $money ) . $currency . ( $test ? '-test' : '' ) . '-' . $attempt,
					'test'           => $test,
				)
			);
			if ( 'paid' === $created['status'] ) {
				return null;
			}
			$expires = $created['expiresAt'] ? strtotime( (string) $created['expiresAt'] ) : 0;
			if ( in_array( $created['status'], self::OPEN, true ) || ( 'new' === $created['status'] && ( 0 === $expires || $expires > time() + 300 ) ) ) {
				update_post_meta(
					$order_id,
					self::META,
					array(
						'key'        => $key,
						'type'       => $type,
						'invoice_id' => $created['id'],
						'url'        => $created['invoiceUrl'],
						'amount'     => $money,
						'currency'   => $currency,
						'test'       => $test,
						'attempt'    => $attempt,
					)
				);
				return $created['invoiceUrl'];
			}
		}
		throw new \RuntimeException( 'Too many finished ArionPay invoices for this payment.' );
	}

	/**
	 * The student's order page: while a payment is open, check it with ArionPay (a lost webhook is caught here) and
	 * show a "Pay with crypto" button.
	 */
	public function order_page() {
		global $wp;
		if ( empty( $wp->query_vars['orders'] ) ) {
			return;
		}
		$order = new LLMS_Order( (int) $wp->query_vars['orders'] );
		if ( $order->get( 'payment_gateway' ) !== $this->id || ! in_array( $order->get( 'status' ), array( 'llms-pending', 'llms-on-hold' ), true ) ) {
			return;
		}
		$current = (array) get_post_meta( $order->get( 'id' ), self::META, true );
		if ( empty( $current['invoice_id'] ) ) {
			return;
		}
		try {
			$url = $this->start_payment( $order, (float) $current['amount'], (string) $current['type'], (string) $current['key'] );
		} catch ( \Throwable $e ) {
			$url = '';
		}
		if ( null === $url ) {
			echo '<div class="llms-notice llms-success">' . esc_html__( 'Payment received, thank you! Your access is active.', 'arionpay-for-lifterlms' ) . '</div>';
			return;
		}
		if ( $url ) {
			echo '<div class="llms-notice llms-info"><p>' . esc_html__( 'This order waits for your crypto payment. If you already paid, it is confirmed as soon as the network confirms it.', 'arionpay-for-lifterlms' ) . '</p><p><a class="llms-button-primary" id="arionpay-pay" href="' . esc_url( $url ) . '">' . esc_html__( 'Pay with crypto', 'arionpay-for-lifterlms' ) . '</a></p></div>';
		}
	}

	// ------------------------------------------------------------------ webhook

	public static function register_routes() {
		register_rest_route(
			'arionpay-lifterlms/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true', // trusted only by the ArionPay signature checked below
			)
		);
	}

	public static function webhook( WP_REST_Request $request ) {
		$gateway = self::instance();
		$secret  = $gateway ? trim( (string) $gateway->get_option( 'webhook_secret', 'LLMS_ARIONPAY_WEBHOOK_SECRET' ) ) : '';
		if ( '' === $secret ) {
			return new WP_REST_Response( array( 'error' => 'webhook secret not set' ), 503 );
		}
		$raw = (string) $request->get_body();
		if ( strlen( $raw ) > self::MAX_BODY ) {
			return new WP_REST_Response( array( 'error' => 'too large' ), 413 );
		}
		try {
			$event = \ArionPay\Webhook::verify( $raw, $request->get_header( 'arionpay_signature' ), $secret );
		} catch ( \ArionPay\SignatureException $e ) {
			$gateway->log( 'ArionPay webhook refused: ' . $e->getMessage() );
			return new WP_REST_Response( array( 'error' => 'invalid signature' ), 401 );
		}
		if ( \ArionPay\Webhook::isTestEvent( $event ) ) {
			return new WP_REST_Response( array( 'received' => true, 'test' => true ), 200 );
		}
		$invoice  = $event['data']['invoice'];
		$meta     = (array) ( $invoice['metadata'] ?? array() );
		$order_id = (int) ( $meta['llms_order_id'] ?? 0 );
		if ( ! $order_id || ( $meta['llms_site'] ?? '' ) !== self::site_id() || 'llms_order' !== get_post_type( $order_id ) ) {
			return new WP_REST_Response( array( 'received' => true, 'ignored' => 'not an order of this site' ), 200 );
		}
		$order = new LLMS_Order( $order_id );
		if ( (bool) ( $invoice['test'] ?? false ) !== ( 'test' === $order->get( 'gateway_api_mode' ) ) ) {
			return new WP_REST_Response( array( 'error' => 'test/live mismatch' ), 409 );
		}
		if ( 'invoice.paid' !== $event['type'] ) {
			$texts = array(
				'invoice.pending'      => __( 'ArionPay: payment seen for invoice %s, waiting for network confirmations.', 'arionpay-for-lifterlms' ),
				'invoice.partial'      => __( 'ArionPay: partial payment for invoice %s.', 'arionpay-for-lifterlms' ),
				'invoice.needs_review' => __( 'ArionPay flagged invoice %s for review (Payment Radar). Decide in your ArionPay dashboard.', 'arionpay-for-lifterlms' ),
				'invoice.expired'      => __( 'ArionPay invoice %s expired without full payment. A late payment still counts.', 'arionpay-for-lifterlms' ),
			);
			if ( isset( $texts[ $event['type'] ] ) && self::once( $order_id, 'evt-' . $event['id'] ) ) {
				$order->add_note( sprintf( $texts[ $event['type'] ], $invoice['id'] ) );
			}
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}
		$result = self::record_paid( $order_id, $invoice, 'webhook' );
		return new WP_REST_Response( array( 'received' => true, 'result' => $result ), 'locked' === $result ? 503 : 200 );
	}

	/**
	 * Record a paid invoice as a LifterLMS transaction (LifterLMS then enrolls / reactivates). Safe to call many
	 * times. Returns recorded | duplicate | mismatch | locked.
	 */
	public static function record_paid( $order_id, array $invoice, $source ) {
		global $wpdb;
		$meta = (array) ( $invoice['metadata'] ?? array() );
		$paid = is_numeric( $invoice['amountFiat'] ?? null ) ? number_format( (float) $invoice['amountFiat'], 2, '.', '' ) : '';
		if ( (int) ( $meta['llms_order_id'] ?? 0 ) !== (int) $order_id || ( $meta['llms_site'] ?? '' ) !== self::site_id()
			|| '' === $paid || $paid !== (string) ( $meta['llms_amount'] ?? '' ) || strtoupper( (string) ( $invoice['currency'] ?? '' ) ) !== strtoupper( (string) ( $meta['llms_currency'] ?? '' ) ) ) {
			if ( self::once( $order_id, 'mismatch-' . ( $invoice['id'] ?? '' ) ) ) {
				( new LLMS_Order( $order_id ) )->add_note( sprintf( 'ArionPay invoice %s is paid, but its amount or currency does not match this payment. NOT recorded: check it in your ArionPay dashboard.', $invoice['id'] ?? '' ) );
			}
			return 'mismatch';
		}
		$lock = 'arionpay_llms_' . (int) $order_id;
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 20)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return 'locked';
		}
		try {
			// One transaction per ArionPay invoice. (A direct query: LifterLMS transaction statuses are hidden from
			// get_posts( 'post_status' => 'any' ), and transactions point to their order by meta, not post_parent.)
			$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_llms_order_id' AND o.meta_value = %s
					 JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_llms_gateway_transaction_id' AND t.meta_value = %s
					 WHERE p.post_type = 'llms_transaction' LIMIT 1",
					(string) (int) $order_id,
					(string) $invoice['id']
				)
			);
			if ( $existing ) {
				return 'duplicate';
			}
			$order  = new LLMS_Order( $order_id );
			$source_text = trim( ( $invoice['paidCrypto'] ?? '' ) . ' ' . ( $invoice['asset'] ?? '' ) . ( ! empty( $invoice['network'] ) ? ' on ' . $invoice['network'] : '' ) );
			$order->record_transaction(
				array(
					'amount'             => (float) $paid,
					'transaction_id'     => (string) $invoice['id'],
					'source_description' => $source_text ? $source_text : 'ArionPay',
					'status'             => 'llms-txn-succeeded',
					'payment_gateway'    => 'arionpay',
					'payment_type'       => in_array( $meta['llms_payment_type'] ?? '', array( 'single', 'recurring', 'trial' ), true ) ? $meta['llms_payment_type'] : 'single',
				)
			);
			$flags = array();
			foreach ( array( 'late' => 'paid late', 'underpaidWithinTolerance' => 'slightly under, inside your payment tolerance', 'overpaid' => 'overpaid', 'manual' => 'accepted by hand in ArionPay' ) as $flag => $text ) {
				if ( ! empty( $invoice[ $flag ] ) ) {
					$flags[] = $text;
				}
			}
			$order->add_note( ( ! empty( $invoice['test'] ) ? '[TEST - no real money] ' : '' ) . sprintf( 'ArionPay invoice %s is paid (%s %s)%s [%s].', $invoice['id'], $paid, $invoice['currency'], $flags ? '; ' . implode( '; ', $flags ) : '', $source ) );
			return 'recorded';
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	// ------------------------------------------------------------------ helpers

	public function client() {
		$base = trim( (string) $this->get_option( 'base_url' ) );
		return new \ArionPay\Client(
			trim( (string) $this->get_option( 'api_key' ) ),
			trim( (string) $this->get_option( 'api_secret', 'LLMS_ARIONPAY_API_SECRET' ) ),
			array(
				'baseUrl' => '' !== $base ? $base : \ArionPay\Client::DEFAULT_BASE_URL,
				'appInfo' => 'lifterlms/' . ( defined( 'LLMS_VERSION' ) ? LLMS_VERSION : '?' ) . ' arionpay-lifterlms/' . ARIONPAY_LLMS_VERSION,
			)
		);
	}

	public static function webhook_url() {
		return rest_url( 'arionpay-lifterlms/v1/webhook' );
	}

	private static function instance() {
		return function_exists( 'llms' ) ? llms()->payment_gateways()->get_gateway_by_id( 'arionpay' ) : null;
	}

	/** Random id of this site (made once), so two sites on one ArionPay store never touch each other's orders. */
	public static function site_id() {
		$id = (string) get_option( 'arionpay_llms_site_id', '' );
		if ( ! preg_match( '/^[a-z0-9]{12}$/', $id ) ) {
			$id = strtolower( wp_generate_password( 12, false, false ) );
			if ( ! add_option( 'arionpay_llms_site_id', $id, '', false ) ) {
				$id = (string) get_option( 'arionpay_llms_site_id', $id );
			}
		}
		return $id;
	}

	/** True the first time a key is seen for an order (so repeated deliveries add one note). */
	private static function once( $order_id, $key ) {
		$seen = (array) get_post_meta( $order_id, '_arionpay_seen', true );
		if ( in_array( $key, $seen, true ) ) {
			return false;
		}
		$seen[] = $key;
		update_post_meta( $order_id, '_arionpay_seen', array_slice( $seen, -100 ) );
		return true;
	}
}
