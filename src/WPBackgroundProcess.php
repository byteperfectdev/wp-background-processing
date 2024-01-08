<?php
/**
 * Abstract WPBackgroundProcess class.
 *
 * @package byteperfect\wp-background-processing
 */

namespace byteperfect;

use Exception;
use stdClass;
use WP_Error;

/**
 * Abstract WPBackgroundProcess class.
 *
 * @package byteperfect\wp-background-processing
 */
abstract class WPBackgroundProcess extends WPAsyncRequest {
	/**
	 * Action
	 *
	 * @var string
	 */
	protected string $action = 'background_process';

	/**
	 * Start time of current process.
	 *
	 * @var int
	 */
	protected int $start_time = 0;

	/**
	 * Cron_hook_identifier
	 *
	 * @var string
	 */
	protected string $cron_hook_identifier;

	/**
	 * Cron_interval_identifier
	 *
	 * @var string
	 */
	protected string $cron_interval_identifier;

	/**
	 * The status set when process is cancelling.
	 *
	 * @var int
	 */
	const STATUS_CANCELLED = 1;

	/**
	 * The status set when process is paused or pausing.
	 *
	 * @var int;
	 */
	const STATUS_PAUSED = 2;

	/**
	 * Initiate new background process.
	 */
	public function __construct() {
		parent::__construct();

		$this->cron_hook_identifier     = $this->identifier . '_cron';
		$this->cron_interval_identifier = $this->identifier . '_cron_interval';

		add_action( $this->cron_hook_identifier, array( $this, 'handle_cron_healthcheck' ) );
		add_filter( 'cron_schedules', array( $this, 'schedule_cron_healthcheck' ) );
	}

	/**
	 * Schedule the cron healthcheck and dispatch an async request to start processing the queue.
	 *
	 * @return array<string|mixed>|WP_Error The response or WP_Error on failure.
	 */
	public function dispatch() {
		if ( $this->is_processing() ) {
			return new WP_Error( 'already_processing', 'Already processing.' );
		}

		// Schedule the cron healthcheck.
		$this->schedule_event();

		// Perform remote post.
		return parent::dispatch();
	}

	/**
	 * Push to the queue.
	 *
	 * Note, save must be called in order to persist queued items to a batch for processing.
	 *
	 * @param mixed $data Data.
	 *
	 * @return $this
	 */
	public function push_to_queue( $data ): WPBackgroundProcess {
		$this->data[] = $data;

		return $this;
	}

	/**
	 * Save the queued items for future processing.
	 *
	 * @return $this
	 */
	public function save(): WPBackgroundProcess {
		$key = $this->generate_key();

		if ( count( $this->data ) > 0 ) {
			update_site_option( $key, $this->data );
		}

		// Clean out data so that new data isn't prepended with closed session's data.
		$this->data = array();

		return $this;
	}

	/**
	 * Update a batch's queued items.
	 *
	 * @param string       $key  Key.
	 * @param array<mixed> $data Data.
	 *
	 * @return $this
	 */
	public function update( string $key, array $data ): WPBackgroundProcess {
		if ( count( $data ) > 0 ) {
			update_site_option( $key, $data );
		}

		return $this;
	}

	/**
	 * Delete a batch of queued items.
	 *
	 * @param string $key Key.
	 *
	 * @return $this
	 */
	public function delete( string $key ): WPBackgroundProcess {
		delete_site_option( $key );

		return $this;
	}

	/**
	 * Delete entire job queue.
	 *
	 * @return void
	 */
	public function delete_all(): void {
		$batches = $this->get_batches();

		foreach ( $batches as $batch ) {
			$this->delete( $batch->key );
		}

		delete_site_option( $this->get_status_key() );

		$this->cancelled();
	}

	/**
	 * Cancel job on next batch.
	 *
	 * @return array<string|mixed>|WP_Error The response or WP_Error on failure.
	 */
	public function cancel() {
		update_site_option( $this->get_status_key(), self::STATUS_CANCELLED );

		// Just in case the job was paused at the time.
		return $this->dispatch();
	}

	/**
	 * Has the process been cancelled?
	 *
	 * @return bool
	 */
	public function is_cancelled(): bool {
		$status = get_site_option( $this->get_status_key(), 0 );

		if ( absint( $status ) === self::STATUS_CANCELLED ) {
			return true;
		}

		return false;
	}

	/**
	 * Called when background process has been cancelled.
	 *
	 * @return void
	 */
	protected function cancelled(): void {
		do_action( $this->identifier . '_cancelled' );
	}

	/**
	 * Pause job on next batch.
	 *
	 * @return void
	 */
	public function pause(): void {
		update_site_option( $this->get_status_key(), self::STATUS_PAUSED );
	}

	/**
	 * Is the job paused?
	 *
	 * @return bool
	 */
	public function is_paused(): bool {
		$status = get_site_option( $this->get_status_key(), 0 );

		if ( absint( $status ) === self::STATUS_PAUSED ) {
			return true;
		}

		return false;
	}

	/**
	 * Called when background process has been paused.
	 *
	 * @return void
	 */
	protected function paused(): void {
		do_action( $this->identifier . '_paused' );
	}

	/**
	 * Resume job.
	 *
	 * @return array<string|mixed>|WP_Error The response or WP_Error on failure.
	 */
	public function resume() {
		delete_site_option( $this->get_status_key() );

		$this->schedule_event();

		$result = $this->dispatch();
		if ( ! is_wp_error( $result ) ) {
			$this->resumed();
		}

		return $result;
	}

	/**
	 * Called when background process has been resumed.
	 *
	 * @return void
	 */
	protected function resumed(): void {
		do_action( $this->identifier . '_resumed' );
	}

	/**
	 * Is queued?
	 *
	 * @return bool
	 */
	public function is_queued(): bool {
		return ! $this->is_queue_empty();
	}

	/**
	 * Is the tool currently active, e.g. starting, working, paused or cleaning up?
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return $this->is_queued() || $this->is_processing() || $this->is_paused() || $this->is_cancelled();
	}

	/**
	 * Generate key for a batch.
	 *
	 * Generates a unique key based on microtime. Queue items are
	 * given a unique key so that they can be merged upon save.
	 *
	 * @param int $length Optional max length to trim key to, defaults to 64 characters.
	 *
	 * @return string
	 */
	protected function generate_key( int $length = 64 ): string {
		$unique  = md5( microtime() . wp_rand() );
		$prepend = $this->identifier . '_batch_';

		return substr( $prepend . $unique, 0, $length );
	}

	/**
	 * Get the status key.
	 *
	 * @return string
	 */
	protected function get_status_key(): string {
		return $this->identifier . '_status';
	}

	/**
	 * Maybe process a batch of queued items.
	 *
	 * Checks whether data exists within the queue and that
	 * the process is not already running.
	 *
	 * @return void
	 */
	public function maybe_handle(): void {
		// Don't lock up other requests while processing.
		session_write_close();

		if ( $this->is_processing() ) {
			// Background process already running.
			wp_die();
		}

		if ( $this->is_cancelled() ) {
			$this->clear_scheduled_event();
			$this->delete_all();

			wp_die();
		}

		if ( $this->is_paused() ) {
			$this->clear_scheduled_event();
			$this->paused();

			wp_die();
		}

		if ( $this->is_queue_empty() ) {
			// No data to process.
			wp_die();
		}

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
	 * Is queue empty?
	 *
	 * @return bool
	 */
	protected function is_queue_empty(): bool {
		try {
			$batch = $this->get_batch();

			$is_queue_empty = ( 0 === count( $batch->data ) );
		} catch ( Exception $exception ) {
			$is_queue_empty = true;
		}

		return $is_queue_empty;
	}

	/**
	 * Is the background process currently running?
	 *
	 * @return bool
	 */
	public function is_processing(): bool {
		return false !== get_site_transient( $this->identifier . '_process_lock' );
	}

	/**
	 * Lock process.
	 *
	 * Lock the process so that multiple instances can't run simultaneously.
	 * Override if applicable, but the duration should be greater than that
	 * defined in the time_exceeded() method.
	 *
	 * @return void
	 */
	protected function lock_process(): void {
		$this->start_time = time(); // Set start time of current process.

		$lock_duration = ( property_exists( $this, 'queue_lock_time' ) ) ? $this->queue_lock_time : 60; // 1 minute
		$lock_duration = apply_filters( $this->identifier . '_queue_lock_time', $lock_duration );

		set_site_transient( $this->identifier . '_process_lock', microtime(), $lock_duration );
	}

	/**
	 * Unlock process.
	 *
	 * Unlock the process so that other instances can spawn.
	 *
	 * @return $this
	 */
	protected function unlock_process(): WPBackgroundProcess {
		delete_site_transient( $this->identifier . '_process_lock' );

		return $this;
	}

	/**
	 * Get batch.
	 *
	 * @return stdClass
	 */
	protected function get_batch(): stdClass {
		$batches = $this->get_batches( 1 );

		if ( 0 === count( $batches ) ) {
			$batch = array(
				'key'  => '',
				'data' => array(),
			);
		} else {
			$batch = array_shift( $batches );
		}

		return (object) $batch;
	}

	/**
	 * Get batches.
	 *
	 * @param int $limit Number of batches to return, defaults to all.
	 *
	 * @return array<stdClass>
	 */
	public function get_batches( int $limit = 0 ): array {
		global $wpdb;

		if ( is_multisite() ) {
			$table        = $wpdb->sitemeta;
			$column       = 'meta_key';
			$key_column   = 'meta_id';
			$value_column = 'meta_value';
		} else {
			$table        = $wpdb->options;
			$column       = 'option_name';
			$key_column   = 'option_id';
			$value_column = 'option_value';
		}

		$key = $wpdb->esc_like( $this->identifier . '_batch_' ) . '%';

		$sql = "SELECT * FROM $table WHERE $column LIKE %s ORDER BY $key_column ASC";

		$args = array( $key );

		$limit = absint( $limit );
		if ( 0 !== $limit ) {
			$sql .= ' LIMIT %d';

			$args[] = $limit;
		}

		$items = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB

		$batches = array();

		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				$batches[] = (object) array(
					'key'  => (string) $item->$column,
					'data' => (array) maybe_unserialize( $item->$value_column ),
				);
			}
		}

		return $batches;
	}

	/**
	 * Handle a dispatched request.
	 *
	 * Pass each queue item to the task handler, while remaining
	 * within server memory and time limit constraints.
	 *
	 * @throws Exception Exception.
	 */
	protected function handle(): void {
		$this->lock_process();

		$batch = $this->get_batch();

		while ( true ) {
			$value = array_shift( $batch->data );

			$value = $this->task( $value );

			if ( false !== $value ) {
				array_unshift( $batch->data, $value );
			}

			if ( count( $batch->data ) > 0 ) {
				// Keep the batch up to date while processing it.
				$this->update( $batch->key, $batch->data );
			} else {
				// Delete current batch if fully processed.
				$this->delete( $batch->key );

				break;
			}

			// Let the server breathe a little.
			if ( property_exists( $this, 'seconds_between_batches' ) ) {
				sleep( $this->seconds_between_batches );
			}

			// Batch limits reached, or pause or cancel request.
			if ( $this->time_exceeded() || $this->memory_exceeded() || $this->is_paused() || $this->is_cancelled() ) {
				break;
			}
		}

		$this->unlock_process();

		// Complete process or start next batch.
		if ( $this->is_queue_empty() ) {
			$this->complete();
		} else {
			$this->dispatch();
		}

		wp_die();
	}

	/**
	 * Memory exceeded?
	 *
	 * Ensures the batch process never exceeds 90%
	 * of the maximum WordPress memory.
	 *
	 * @return bool
	 */
	protected function memory_exceeded(): bool {
		$memory_limit   = $this->get_memory_limit() * 0.9; // 90% of max memory
		$current_memory = memory_get_usage( true );
		$return         = false;

		if ( $current_memory >= $memory_limit ) {
			$return = true;
		}

		return apply_filters( $this->identifier . '_memory_exceeded', $return );
	}

	/**
	 * Get memory limit in bytes.
	 *
	 * @return int
	 */
	protected function get_memory_limit(): int {
		if ( function_exists( 'ini_get' ) ) {
			$memory_limit = ini_get( 'memory_limit' );
		} else {
			// Sensible default.
			$memory_limit = '128M';
		}

		if ( 0 === intval( $memory_limit ) ) {
			// Unlimited, set to 32GB.
			$memory_limit = '32000M';
		}

		return wp_convert_hr_to_bytes( $memory_limit );
	}

	/**
	 * Time limit exceeded?
	 *
	 * Ensures the batch never exceeds a sensible time limit.
	 * A timeout limit of 30s is common on shared hosting.
	 *
	 * @return bool
	 */
	protected function time_exceeded(): bool {
		$finish = $this->start_time + apply_filters( $this->identifier . '_default_time_limit', 20 ); // 20 seconds
		$return = false;

		if ( time() >= $finish ) {
			$return = true;
		}

		return apply_filters( $this->identifier . '_time_exceeded', $return );
	}

	/**
	 * Complete processing.
	 *
	 * Override if applicable, but ensure that the below actions are
	 * performed, or, call parent::complete().
	 *
	 * @return void
	 */
	protected function complete(): void {
		delete_site_option( $this->get_status_key() );

		// Remove the cron healthcheck job from the cron schedule.
		$this->clear_scheduled_event();

		$this->completed();
	}

	/**
	 * Called when background process has completed.
	 *
	 * @return void
	 */
	protected function completed(): void {
		do_action( $this->identifier . '_completed' );
	}

	/**
	 * Schedule the cron healthcheck job.
	 *
	 * @param array<string, mixed> $schedules Schedules.
	 *
	 * @return array<string, mixed>
	 */
	public function schedule_cron_healthcheck( array $schedules ): array {
		$interval = apply_filters( $this->cron_interval_identifier, 5 );

		if ( property_exists( $this, 'cron_interval' ) ) {
			$interval = apply_filters( $this->cron_interval_identifier, $this->cron_interval );
		}

		if ( 1 === $interval ) {
			$display = __( 'Every Minute' );
		} else {
			// translators: %d - interval.
			$display = sprintf( __( 'Every %d Minutes' ), $interval );
		}

		// Adds an "Every NNN Minute(s)" schedule to the existing cron schedules.
		$schedules[ $this->cron_interval_identifier ] = array(
			'interval' => MINUTE_IN_SECONDS * $interval,
			'display'  => $display,
		);

		return $schedules;
	}

	/**
	 * Handle cron healthcheck event.
	 *
	 * Restart the background process if not already running
	 * and data exists in the queue.
	 *
	 * @return void
	 * @throws Exception Exception.
	 */
	public function handle_cron_healthcheck(): void {
		if ( $this->is_processing() ) {
			// Background process already running.
			exit;
		}

		if ( $this->is_queue_empty() ) {
			// No data to process.
			$this->clear_scheduled_event();
			exit;
		}

		$this->dispatch();
	}

	/**
	 * Schedule the cron healthcheck event.
	 *
	 * @return void
	 */
	protected function schedule_event(): void {
		$timestamp = wp_next_scheduled( $this->cron_hook_identifier );

		if ( false !== $timestamp ) {
			wp_schedule_event( time(), $this->cron_interval_identifier, $this->cron_hook_identifier );
		}
	}

	/**
	 * Clear scheduled cron healthcheck event.
	 *
	 * @return void
	 */
	protected function clear_scheduled_event(): void {
		$timestamp = wp_next_scheduled( $this->cron_hook_identifier );

		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, $this->cron_hook_identifier );
		}
	}

	/**
	 * Perform task with queued item.
	 *
	 * Override this method to perform any actions required on each
	 * queue item. Return the modified item for further processing
	 * in the next pass through. Or, return false to remove the
	 * item from the queue.
	 *
	 * @param mixed $item Queue item to iterate over.
	 *
	 * @return mixed
	 */
	abstract protected function task( $item );
}
