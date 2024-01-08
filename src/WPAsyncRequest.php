<?php
/**
 * Abstract WPAsyncRequest class.
 *
 * @package byteperfect\wp-background-processing
 */

namespace byteperfect;

use Exception;
use WP_Error;

/**
 * Abstract WPAsyncRequest class.
 *
 * @package byteperfect\wp-background-processing
 */
abstract class WPAsyncRequest {
	/**
	 * Prefix.
	 *
	 * @var string
	 */
	protected string $prefix = 'wp';

	/**
	 * Action.
	 *
	 * @var string
	 */
	protected string $action = 'async_request';

	/**
	 * Identifier.
	 *
	 * @var string
	 */
	protected string $identifier;

	/**
	 * Data.
	 *
	 * @var array<mixed>
	 */
	protected array $data = array();

	/**
	 * Initiate new async request.
	 */
	public function __construct() {
		$this->identifier = $this->prefix . '_' . $this->action;

		add_action( 'wp_ajax_' . $this->identifier, array( $this, 'maybe_handle' ) );
		add_action( 'wp_ajax_nopriv_' . $this->identifier, array( $this, 'maybe_handle' ) );
	}

	/**
	 * Set data used during the request.
	 *
	 * @param array<mixed> $data Data.
	 *
	 * @return WPAsyncRequest
	 */
	public function data( array $data ): WPAsyncRequest {
		$this->data = $data;

		return $this;
	}

	/**
	 * Dispatch the async request.
	 *
	 * @return array<string|mixed>|WP_Error The response or WP_Error on failure.
	 */
	public function dispatch() {
		$url  = add_query_arg( $this->get_query_args(), $this->get_query_url() );
		$args = $this->get_post_args();

		return wp_remote_post( esc_url_raw( $url ), $args );
	}

	/**
	 * Get query args.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_query_args(): array {
		if ( property_exists( $this, 'query_args' ) ) {
			return $this->query_args;
		}

		$args = array(
			'action' => $this->identifier,
			'nonce'  => wp_create_nonce( $this->identifier ),
		);

		/**
		 * Filters the post arguments used during an async request.
		 *
		 * @param array $url
		 */
		return apply_filters( $this->identifier . '_query_args', $args );
	}

	/**
	 * Get query URL.
	 *
	 * @return string
	 */
	protected function get_query_url(): string {
		if ( property_exists( $this, 'query_url' ) ) {
			return $this->query_url;
		}

		$url = admin_url( 'admin-ajax.php' );

		/**
		 * Filters the post arguments used during an async request.
		 *
		 * @param string $url
		 */
		return apply_filters( $this->identifier . '_query_url', $url );
	}

	/**
	 * Get post args.
	 *
	 * @return array
	 */
	protected function get_post_args(): array {
		if ( property_exists( $this, 'post_args' ) ) {
			return $this->post_args;
		}

		$args = array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'body'      => $this->data,
			'cookies'   => $_COOKIE, // Passing cookies ensures request is performed as initiating user.
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // Local requests, fine to pass false.
		);

		/**
		 * Filters the post arguments used during an async request.
		 *
		 * @param array $args
		 */
		return apply_filters( $this->identifier . '_post_args', $args );
	}

	/**
	 * Maybe handle a dispatched request.
	 *
	 * Check for correct nonce and pass to handler.
	 *
	 * @return void
	 */
	public function maybe_handle(): void {
		// Don't lock up other requests while processing.
		session_write_close();

		check_ajax_referer( $this->identifier, 'nonce' );

		try {
			$this->handle();
		} catch ( Exception $exception ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $exception->getMessage() );
		}

		wp_die();
	}

	/**
	 * Handle a dispatched request.
	 *
	 * @return void
	 */
	abstract protected function handle(): void;
}
