<?php
/**
 * Bulk_Process Class.
 *
 * @class       Bulk_Process
 * @version		1.0.0
 * @author lafif <hello@lafif.me>
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Abstract bulk process class.
 */
abstract class Bulk_Process {
	/**
	 * Name of the process.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * The process id.
	 *
	 * @var string
	 */
	public $process_id;

	/**
	 * The individual batch's parameter for specifying the amount of results to return.
	 *
	 * Can / should be overwritten within the class that extends this abstract class.
	 *
	 * @var string
	 */
	public $per_batch_param = 'posts_per_page';

	/**
	 * Args for the batch query.
	 *
	 * @var array
	 */
	public $args = array();

	/**
	 * Default args for the query.
	 *
	 * Should be implemented on the classes that extend this class.
	 *
	 * @var array
	 */
	public $default_args = array();

	/**
	 * Tyoe of batch.
	 *
	 * @var array
	 */
	public $type;

	/**
	 * Callback function to run on each result of query.
	 *
	 * @var array
	 */
	public $callback;

	/**
	 * Status of current process.
	 *
	 * @var string
	 */
	public $status;

	/**
	 * Currently registered batches.
	 *
	 * @var array
	 */
	public $currently_registered = array();

	/**
	 * Current step this batch is on.
	 *
	 * @var int
	 */
	public $current_step = 0;

	/**
	 * Total number of results.
	 *
	 * @var int
	 */
	public $total_num_results;

	/**
	 * Holds difference between total from client and total from query, if one exists.
	 *
	 * @var int
	 */
	public $difference_in_result_totals = 0;

	/**
	 * Errors from results
	 *
	 * @var array
	 */
	public $result_errors = array();

	/**
	 * Get results function for the registered batch process.
	 *
	 * @return array
	 */
	abstract public function batch_get_results();

	/**
	 * Clear the result status for the registered batch process.
	 *
	 * @return bool
	 */
	abstract public function batch_clear_result_status();

	/**
	 * Get the result status for a given result item.
	 *
	 * @param mixed $result The result we are requesting status of.
	 *
	 * @return mixed
	 */
	abstract public function get_result_item_status( $result );

	/**
	 * Update the result status for a result item.
	 *
	 * @param mixed  $result The result we are updating the status of.
	 * @param string $status The status to set.
	 *
	 * @return bool
	 */
	abstract public function update_result_item_status( $result, $status );

	/**
	 * Main plugin method for querying data.
	 *
	 * We need to run the query twice for each step. The first query is run in order to properly set
	 * the total number of results retrieved from the *query*. This number is then compared to the original total
	 * from the *request*, and a new offset is calculated based on these values. Once the offset is calculated, we
	 * run the query again, this time actually pulling the results.
	 *
	 * @since 0.1
	 *
	 * @return mixed An array of data to be processed in bulk fashion.
	 */
	public function get_results() {
		$this->args = wp_parse_args( $this->args, $this->default_args );
		$this->batch_get_results();
		$this->calculate_offset();

		// Run query again, but this time with the new offset calculated.
		$results = $this->batch_get_results();
		return $results;
	}

	/**
	 * Set the total number of results
	 *
	 * Uses a number passed from the client to the server and compares it to the total objects
	 * pulled by the latest query. If the dataset is larger, we increase the total_num_results number.
	 * Otherwise, keep it at the original (to account for deletion / changes).
	 *
	 * @param int $total_from_query Total number of results from latest query.
	 */
	public function set_total_num_results( $total_from_query ) {
		// If this is past step 1, the client is passing back the total number of results.
		// This accounts for deletion / destructive actions to the data.
		$total_from_request = isset( $_POST['total_num_results'] ) ? absint( $_POST['total_num_results'] ) : 0; // Input var okay.

		// In all cases we want to ensure that we use the higher of the two results total (from client or query).
		// We go with the higher number because we want to lock the total number of steps calculated at it's highest total.
		// With a destructive action, that would be total from request. If addivitve action, it would be total from query.
		// In all other cases, these two numbers are equal, so either would work.
		if ( $total_from_query > $total_from_request ) {
			$this->total_num_results = (int) $total_from_query;
		} else {
			$this->total_num_results = (int) $total_from_request;
		}

		$this->record_change_if_totals_differ( $total_from_request, $total_from_query );
	}

	/**
	 * If the amount of total records has changed, the amount is recorded so that it can
	 * be applied to the offeset when it is calculated. This ensures that the offset takes into
	 * account if new objects have been added or removed from the query.
	 *
	 * @param  int $total_from_request    Total number of results passed up from client.
	 * @param  int $total_from_query      Total number of results retreived from query.
	 */
	public function record_change_if_totals_differ( $total_from_request, $total_from_query ) {
		if ( $total_from_query !== $total_from_request && $total_from_request > 0 ) {
			$this->difference_in_result_totals = $total_from_request - $total_from_query;
		}
	}

	/**
	 * Calculate the offset for the current query.
	 */
	public function calculate_offset() {
		if ( 1 !== $this->current_step ) {
			// Example: step 2: 1 * 10 = offset of 10, step 3: 2 * 10 = offset of 20.
			// The difference in result totals is used in case of additive or destructive actions.
			// if 5 posts were deleted in step 1 (20 - 15 = 5) then the offset should remain at 0 ( offset of 5 - 5) in step 2.
			$this->args['offset'] = ( ( $this->current_step - 1 ) * $this->args[ $this->per_batch_param ] ) - $this->difference_in_result_totals;
		}
	}

	/**
	 * Setup our Batch object to have everything it needs (callback, name, process_id,
	 * etc).
	 *
	 * @todo Research the best way to handle exceptions.
	 *
	 * @param  array $args Array of args for register.
	 * @throws Exception Type must be provided.
	 * @return true|exception
	 */
	public function setup_args( $process_id, $args ) {

		$this->process_id = $process_id;

		if ( empty( $args['name'] ) ) {
			throw new Exception( __( 'Process name must be defined.', 'wpbp' ) );
		} else {
			$this->name = $args['name'];
		}

		if ( empty( $args['type'] ) ) {
			throw new Exception( __( 'Batch type must be defined.', 'wpbp' ) );
		} else {
			$this->type = $args['type'];
		}

		if ( empty( $args['args'] ) || ! is_array( $args['args'] ) ) {
			$this->args = array();
		} else {
			$this->args = $args['args'];
		}

		if ( empty( $args['callback'] ) ) {
			throw new Exception( __( 'A callback must be defined.', 'wpbp' ) );
		} else {
			$this->callback = $args['callback'];
		}

		add_action( 'wpbp_process_' . $this->process_id, array( $this, 'run_ajax' ) );
		add_action( 'wpbp_reset_' . $this->process_id, array( $this, 'clear_result_status' ) );

		return true;
	}

	/**
	 * Return JSON for AJAX requests to run.
	 *
	 * @param int $current_step Current step.
	 */
	public function run_ajax( $current_step ) {
		wp_send_json( $this->run( $current_step ) );
	}

	/**
	 * Run this batch process (query for the data and process the results).
	 *
	 * @param int $current_step Current step.
	 */
	public function run( $current_step ) {
		$this->current_step = $current_step;

		$results = $this->get_results();

		if ( empty( $results ) ) {
			$this->update_status( 'noresult' );

			wpbp_add_notice_message( __( 'No results found.', 'wpbp' ) );

			return $this->format_ajax_details( array(
				'success' => true
			) );
		}

		$this->process_results( $results );

		$per_page = get_option( 'posts_per_page' );
		if ( isset( $this->per_batch_param ) ) {
			$per_page = $this->args[ $this->per_batch_param ];
		}

		/**
		 * Filter the per_page number used to calculate total number of steps. You would get use
		 * out of this if you had a custom $wpdb query that didn't paginate in one of the default
		 * ways supported by the plugin.
		 *
		 * @param int $per_page The number of results per page.
		 */
		$per_page = apply_filters( 'wpbp_process_' . $this->process_id . '_per_page', $per_page );

		$total_steps = ceil( $this->total_num_results / $per_page );

		if ( (int) $this->current_step === (int) $total_steps ) {

			// The difference here calcuates the gap between the original total and the most recent query.
			// In the case of a deletion process the final step will have a number exactly equal to the posts_per_page.
			// If 20 total, then the last step would have 4 for instance.
			// In all other cases, the difference would be the same as the total number of results (20 - 0 = 20).
			// The exception is a deletion process where a new object is added during the process.
			// In this case, then the final step would have less then the posts_per_page but never more (so <=).
			// We check this difference and compare it before saying that we are finished. If not, we run the last step over.
			$difference = $this->total_num_results - $this->difference_in_result_totals;
			if ( $difference <= $per_page || $difference === $this->total_num_results ) {

				wpbp_add_success_message( __('Finished', 'wpbp') );

				$this->update_status( 'finished' );
			} else {
				$this->current_step = $this->current_step - 1;
				$this->update_status( 'running' );
			}
		} else {
			$this->update_status( 'running' );
		}

		$progress = ( 0 === (int) $total_steps ) ? 100 : round( ( $this->current_step / $total_steps ) * 100 );

		// If there are errors, return the error variable as true so front-end can handle.
		if ( is_array( $this->result_errors ) && count( $this->result_errors ) > 0  ) {

			foreach ($this->result_errors as $error ) {
				wpbp_add_error_message( $error );
			}

			return $this->format_ajax_details( array(
				'success'         => false,
				'total_steps'   => $total_steps,
				'query_results' => $results,
				'progress'      => $progress
			) );
		}

		return $this->format_ajax_details( array(
			'total_steps'   => $total_steps,
			'query_results' => $results,
			'progress'      => $progress,
		) );
	}

	/**
	 * Get details for Ajax requests.
	 *
	 * @param  array $details Array of details to send via Ajax.
	 */
	private function format_ajax_details( $details = array() ) {
		return wp_parse_args( $details, array(
			'success'           => true,
			'current_step'      => $this->current_step,
			'callback'          => $this->callback,
			'status'            => $this->status,
			'batch'             => $this->name,
			'total_num_results' => $this->total_num_results,
			'messages' 			=> wpbp_get_messages(),
		) );
	}

	/**
	 * Update batch timestamps.
	 *
	 * @param  string $status Status of batch process.
	 */
	private function update_status( $status ) {
		update_option( 'wpbp_process_' . $this->process_id, array(
			'status' => $status,
			'messages' => wpbp_get_messages(),
			'timestamp' => current_time( 'timestamp' ),
		) );

		$this->status = $status;
	}

	/**
	 * Loop over an array of results (posts, pages, etc) and run the callback
	 * function that was passed through when this batch was registered.
	 *
	 * @param array $results Array of results from the query.
	 */
	public function process_results( $results ) {
		/**
		 * The key used to define the status of whether or not a result was processed successfully.
		 *
		 * @param string $string_text 'success'
		 */
		$success_status = apply_filters( 'wpbp_process_success_status', 'success' );

		/**
		 * The key used to define the status of whether or not a result was not able to be processed.
		 *
		 * @param string $string_text 'failed'
		 */
		$failed_status = apply_filters( 'wpbp_process_failed_status', 'failed' );

		foreach ( $results as $result ) {
			// If this result item has been processed already, skip it.
			if ( $success_status === $this->get_result_status( $result ) ) {
				continue;
			}

			try {
				call_user_func_array( $this->callback, array( $result ) );
				$this->update_result_status( $result, $success_status );
			} catch ( Exception $e ) {
				$this->update_status( $failed_status );
				$this->update_result_status( $result, $failed_status );
				$this->result_errors[] = array(
					'item' => $result->ID,
					'message' => $e->getMessage(),
				);

			}
		}
	}

	/**
	 * Update the meta info on a result.
	 *
	 * @param mixed  $result The result we want to track meta data on.
	 * @param string $status  Status of this result in the batch.
	 */
	public function update_result_status( $result, $status ) {
		/**
		 * Action to hook into when a result gets processed and it's status is updated.
		 *
		 * @param mixed  $result The current result.
		 * @param string $status The status to set on a result.
		 */
		do_action( 'wpbp_process_' . $this->process_id . '_update_result_status', $result, $status );

		return $this->update_result_item_status( $result, $status );
	}

	/**
	 * Get the status of a result.
	 *
	 * @param mixed $result The result we want to get status of.
	 */
	public function get_result_status( $result ) {
		/**
		 * Action to hook into when a result is being checked for whether or not
		 * it was updated.
		 *
		 * @param mixed $result The current result which is getting it's status checked.
		 */
		do_action( 'wpbp_process_' . $this->process_id . '_get_result_status', $result );

		return $this->get_result_item_status( $result );
	}

	/**
	 * Clear the result status for a batch.
	 */
	public function clear_result_status() {
		/**
		 * Action to hook into when the 'reset' button is clicked in the admin UI.
		 *
		 * @param Batch $this The current batch object.
		 */
		do_action( 'wpbp_process_' . $this->process_id . '_clear', $this );

		$this->batch_clear_result_status();
		$this->update_status( 'reset' );

		wpbp_add_success_message( sprintf(__('Successfully reset process %s', 'wpbp'), $this->process_id) );

		wp_send_json($this->format_ajax_details());
	}
}
