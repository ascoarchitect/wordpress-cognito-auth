<?php
/**
 * User synchronization between WordPress and Amazon Cognito
 *
 * @package WP_Cognito_Auth
 */

namespace WP_Cognito_Auth;

/**
 * Class Sync
 *
 * Handles bidirectional synchronization of users and groups between WordPress and Amazon Cognito.
 *
 * Data Mastering Configuration (v3.0):
 * - This plugin is now a "dumb pipe": every sync request includes the tenant
 *   ID and the full set of `wp_roles`. The MyBosun Lambda owns all role →
 *   Cognito group mapping (MyBosun_{tenantId}_{admin|member|suspended}) using
 *   the `accessLevelMap` configured per tenant in TenantConfig.
 * - When 'user_data_master' is set to 'wordpress' (default): WordPress user
 *   data changes are pushed to Cognito.
 * - When 'user_data_master' is set to 'cognito': Cognito user data changes
 *   are pulled into WordPress during login.
 */
class Sync {
	/**
	 * Array to track users pending group sync to avoid duplicate operations
	 *
	 * @var array
	 */
	private $pending_group_sync = array();

	/**
	 * API component instance
	 *
	 * @var API
	 */
	private $api;

	/**
	 * Constructor - Initialize sync with API component
	 *
	 * @param API $api API component instance.
	 */
	public function __construct( $api ) {
		$this->api = $api;
		
		// Hook into shutdown to process any pending group syncs
		add_action( 'shutdown', array( $this, 'process_pending_group_syncs' ), 5 );
	}

	/**
	 * Handle user creation event for Cognito sync
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public function on_user_create( $user_id ) {
		$features = get_option( 'wp_cognito_features', array() );
		if ( empty( $features['sync'] ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$user_data = $this->prepare_user_data( $user_id, $user );
		$this->api->create_user( $user_data );

		if ( ! empty( $features['group_sync'] ) ) {
			$this->sync_user_groups( $user_id );
		}
	}

	/**
	 * Handle user update event for Cognito sync
	 *
	 * @param int     $user_id       WordPress user ID.
	 * @param WP_User $old_user_data Previous user data (unused).
	 */
	public function on_user_update( $user_id, $old_user_data = null ) {
		$features = get_option( 'wp_cognito_features', array() );
		if ( empty( $features['sync'] ) ) {
			return;
		}

		// Skip WordPress->Cognito sync if we're in the middle of Cognito authentication
		// This prevents conflicts where we sync WordPress roles to Cognito while
		// Cognito is updating WordPress user data (which shouldn't change roles)
		if ( get_transient( 'cognito_auth_in_progress_' . $user_id ) ) {
			$this->api->log_message( "Skipping WordPress to Cognito sync for user {$user_id}: Cognito authentication in progress", 'info' );
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$cognito_user_id = get_user_meta( $user_id, 'cognito_user_id', true );
		$user_data       = $this->prepare_user_data( $user_id, $user );

		if ( ! empty( $cognito_user_id ) ) {
			$user_data['cognito_user_id'] = $cognito_user_id;
			$this->api->update_user( $user_data );
		} else {
			$this->api->create_user( $user_data );
		}

		// Use batched group sync instead of immediate sync to avoid duplicates with role change hooks
		if ( ! empty( $features['group_sync'] ) ) {
			$this->schedule_group_sync( $user_id );
		}
	}

	/**
	 * Handle user deletion event for Cognito sync
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public function on_user_delete( $user_id ) {
		$features = get_option( 'wp_cognito_features', array() );
		if ( empty( $features['sync'] ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$cognito_user_id = get_user_meta( $user_id, 'cognito_user_id', true );
		if ( empty( $cognito_user_id ) ) {
			return;
		}

		$user_data = array(
			'wp_user_id'      => $user_id,
			'email'           => $user->user_email,
			'username'        => $user->user_login,
			'cognito_user_id' => $cognito_user_id,
		);

		$this->api->delete_user( $user_data );
	}

	/**
	 * Handle user role change event for group sync
	 *
	 * @param int    $user_id   WordPress user ID.
	 * @param string $new_role  New user role (unused).
	 * @param array  $old_roles Previous user roles (unused).
	 */
	public function on_user_role_change( $user_id, $new_role, $old_roles ) {
		$features = get_option( 'wp_cognito_features', array() );
		if ( empty( $features['group_sync'] ) ) {
			return;
		}

		$this->schedule_group_sync( $user_id );
	}

	/**
	 * Handle user role addition event for group sync
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $role    Added role (unused).
	 */
	public function on_user_role_added( $user_id, $role ) {
		$features = get_option( 'wp_cognito_features', array() );
		if ( empty( $features['group_sync'] ) ) {
			return;
		}

		$this->schedule_group_sync( $user_id );
	}

	/**
	 * Handle user role removal event for group sync
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $role    Removed role (unused).
	 */
	public function on_user_role_removed( $user_id, $role ) {
		$features = get_option( 'wp_cognito_features', array() );
		if ( empty( $features['group_sync'] ) ) {
			return;
		}

		$this->schedule_group_sync( $user_id );
	}

	/**
	 * Schedule a user for group sync (deferred until shutdown)
	 *
	 * @param int $user_id WordPress user ID.
	 */
	private function schedule_group_sync( $user_id ) {
		// Add user to pending sync if not already scheduled
		if ( ! in_array( $user_id, $this->pending_group_sync, true ) ) {
			$this->pending_group_sync[] = $user_id;
		}
	}

	/**
	 * Process all pending group syncs in a single batch
	 * Called during WordPress shutdown hook
	 */
	public function process_pending_group_syncs() {
		if ( empty( $this->pending_group_sync ) ) {
			return;
		}

		$features = get_option( 'wp_cognito_features', array() );
		if ( empty( $features['group_sync'] ) ) {
			return;
		}

		$user_count = count( $this->pending_group_sync );
		$this->api->log_message( "Processing batched group sync for {$user_count} user(s): " . implode( ', ', $this->pending_group_sync ), 'info' );

		foreach ( $this->pending_group_sync as $user_id ) {
			$this->sync_user_groups( $user_id );
		}

		// Clear the pending array
		$this->pending_group_sync = array();
	}

	/**
	 * Prepare user data for Cognito synchronization
	 *
	 * Delegates to the shared {@see User_Data} helper so the canonical
	 * "dumb pipe" payload (tenantId + wp_roles + extras) is built in exactly
	 * one place.
	 *
	 * @param int     $user_id WordPress user ID.
	 * @param WP_User $user    WordPress user object.
	 * @return array Formatted user data for API.
	 */
	private function prepare_user_data( $user_id, $user ) {
		return User_Data::prepare( $user_id, $user );
	}

	/**
	 * Synchronize user groups by re-pushing the user to the Lambda.
	 *
	 * In v3.0 the WP plugin no longer manages individual Cognito group
	 * memberships. Each push carries the full `wp_roles` array, and the
	 * MyBosun Lambda reconciles `MyBosun_{tenantId}_{level}` group membership
	 * using the tenant's configured `accessLevelMap`. This method simply
	 * triggers a regular update so the Lambda can re-derive group state.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool Success status.
	 */
	public function sync_user_groups( $user_id ) {
		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			$this->api->log_message( "Cannot sync groups: User ID {$user_id} not found", 'error' );
			return false;
		}

		$cognito_user_id = get_user_meta( $user_id, 'cognito_user_id', true );
		$user_data       = $this->prepare_user_data( $user_id, $user );

		if ( ! empty( $cognito_user_id ) ) {
			$user_data['cognito_user_id'] = $cognito_user_id;
			$result                       = $this->api->update_user( $user_data );
		} else {
			$result = $this->api->create_user( $user_data );
		}

		if ( $result ) {
			$this->api->log_message( "Group reconciliation push sent for user {$user->user_login} (roles: " . implode( ',', (array) $user->roles ) . ')' );
			return true;
		}

		$this->api->log_message( "Group reconciliation push failed for user {$user->user_login}", 'error' );
		return false;
	}

	/**
	 * Maximum payloads per HTTP call to the Lambda (matches Lambda-side cap).
	 */
	const ENQUEUE_BATCH_SIZE = 25;

	/**
	 * Maximum number of batches sent in a single WP request, to stay safely
	 * under the 15s `wp_remote_post` timeout. 4 batches × 25 users = 100 users
	 * per admin click / cron tick. Multiple ticks resume via the offset
	 * transient.
	 */
	const MAX_BATCHES_PER_REQUEST = 4;

	/**
	 * Transient holding the resumable offset for an in-flight bulk run.
	 */
	const OFFSET_TRANSIENT = 'wp_cognito_bulk_sync_offset';

	/**
	 * Bulk synchronize users to Cognito via the SQS enqueue endpoint.
	 *
	 * Processes up to {@see MAX_BATCHES_PER_REQUEST} × {@see ENQUEUE_BATCH_SIZE}
	 * users per call. If more remain, the caller can re-invoke and the run
	 * will resume from the saved offset.
	 *
	 * @param string $role Optional role slug to filter on. Empty = all users.
	 * @return array Aggregate stats: total, enqueued, failed, errors, complete, next_offset.
	 */
	public function bulk_sync_users( $role = '' ) {
		$query_args = array(
			'fields'  => 'all',
			'orderby' => 'ID',
			'order'   => 'ASC',
			'number'  => self::ENQUEUE_BATCH_SIZE,
		);

		if ( ! empty( $role ) ) {
			$query_args['role'] = $role;
		}

		$total_args                 = $query_args;
		$total_args['fields']       = 'ID';
		$total_args['number']       = -1;
		$total_args['offset']       = 0;
		$total_args['count_total']  = false;
		$total_query                = new \WP_User_Query( $total_args );
		$total_users                = (int) count( $total_query->get_results() );

		$offset = (int) get_transient( self::OFFSET_TRANSIENT );

		$stats = array(
			'total'       => $total_users,
			'processed'   => 0,
			'enqueued'    => 0,
			'failed'      => 0,
			'errors'      => array(),
			'start'       => $offset,
			'next_offset' => $offset,
			'complete'    => false,
		);

		for ( $i = 0; $i < self::MAX_BATCHES_PER_REQUEST; $i++ ) {
			$query_args['offset'] = $offset;
			$users                = get_users( $query_args );

			if ( empty( $users ) ) {
				$stats['complete'] = true;
				delete_transient( self::OFFSET_TRANSIENT );
				break;
			}

			$batch = array();
			foreach ( $users as $user ) {
				try {
					$batch[] = $this->prepare_user_data( $user->ID, $user );
				} catch ( \Exception $e ) {
					++$stats['failed'];
					$stats['errors'][] = sprintf(
						'Error preparing user %s (%d): %s',
						$user->user_email,
						$user->ID,
						$e->getMessage()
					);
				}
			}

			$result = $this->api->bulk_sync_enqueue( $batch );

			if ( is_array( $result ) ) {
				$stats['enqueued'] += isset( $result['enqueuedCount'] ) ? (int) $result['enqueuedCount'] : 0;
				$stats['failed']   += isset( $result['failedCount'] ) ? (int) $result['failedCount'] : 0;
				if ( ! empty( $result['failed'] ) && is_array( $result['failed'] ) ) {
					foreach ( $result['failed'] as $failure ) {
						$stats['errors'][] = is_string( $failure )
							? $failure
							: wp_json_encode( $failure );
					}
				}
			} else {
				$stats['failed'] += count( $batch );
				$stats['errors'][] = sprintf( 'Enqueue request failed at offset %d', $offset );
			}

			$stats['processed'] += count( $users );
			$offset             += count( $users );
			$stats['next_offset'] = $offset;

			if ( count( $users ) < self::ENQUEUE_BATCH_SIZE ) {
				$stats['complete'] = true;
				delete_transient( self::OFFSET_TRANSIENT );
				break;
			}
		}

		if ( ! $stats['complete'] ) {
			set_transient( self::OFFSET_TRANSIENT, $offset, HOUR_IN_SECONDS );
		}

		$this->api->log_message(
			sprintf(
				'Bulk enqueue tick: processed %d (offset %d → %d of %d), enqueued %d, failed %d, complete=%s',
				$stats['processed'],
				$stats['start'],
				$stats['next_offset'],
				$stats['total'],
				$stats['enqueued'],
				$stats['failed'],
				$stats['complete'] ? 'yes' : 'no'
			)
		);

		return $stats;
	}

	/**
	 * Bulk synchronize users by specific role via the SQS enqueue endpoint.
	 *
	 * @param string $role WordPress user role slug.
	 * @return array Sync results — see {@see bulk_sync_users()}.
	 */
	public function bulk_sync_users_by_role( $role ) {
		return $this->bulk_sync_users( $role );
	}

	/**
	 * Reset the bulk-sync resume offset (admin "Restart from beginning").
	 */
	public function reset_bulk_sync_offset() {
		delete_transient( self::OFFSET_TRANSIENT );
	}

	/**
	 * Batch size for a single Cognito sub backfill tick. Kept below the Lambda
	 * cap (100) and sized to comfortably clear the 15s wp_remote_post window
	 * given that AdminGetUser runs serially inside the Lambda.
	 */
	const BACKFILL_BATCH_SIZE = 50;

	/**
	 * Max batches per admin click / cron tick for the Cognito sub backfill.
	 */
	const BACKFILL_MAX_BATCHES_PER_REQUEST = 4;

	/**
	 * Transient holding the resumable offset for an in-flight Cognito sub
	 * backfill.
	 */
	const BACKFILL_OFFSET_TRANSIENT = 'wp_cognito_backfill_subs_offset';

	/**
	 * Backfill `cognito_user_id` user meta for users whose sub is missing.
	 *
	 * v3.0's async SQS path never returns the Cognito sub to WordPress, so
	 * after a bulk sync we have to query for it. This runs one tick at a time,
	 * resumable via {@see BACKFILL_OFFSET_TRANSIENT}, and is safe to run
	 * repeatedly — only users missing meta are queried.
	 *
	 * @return array Aggregate stats: total, processed, resolved, notFound, errored, complete, next_offset.
	 */
	public function backfill_cognito_ids() {
		$query_base = array(
			'fields'     => 'all',
			'orderby'    => 'ID',
			'order'      => 'ASC',
			'number'     => self::BACKFILL_BATCH_SIZE,
			'meta_query' => array(
				'relation' => 'OR',
				array(
					'key'     => 'cognito_user_id',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => 'cognito_user_id',
					'value'   => '',
					'compare' => '=',
				),
			),
		);

		$total_args                = $query_base;
		$total_args['fields']      = 'ID';
		$total_args['number']      = -1;
		$total_args['offset']      = 0;
		$total_args['count_total'] = false;
		$total_query               = new \WP_User_Query( $total_args );
		$total_missing             = (int) count( $total_query->get_results() );

		$offset = (int) get_transient( self::BACKFILL_OFFSET_TRANSIENT );

		$stats = array(
			'total'       => $total_missing,
			'processed'   => 0,
			'resolved'    => 0,
			'notFound'    => 0,
			'errored'     => 0,
			'errors'      => array(),
			'start'       => $offset,
			'next_offset' => $offset,
			'complete'    => false,
		);

		for ( $i = 0; $i < self::BACKFILL_MAX_BATCHES_PER_REQUEST; $i++ ) {
			$query_args           = $query_base;
			$query_args['offset'] = $offset;
			$users                = get_users( $query_args );

			if ( empty( $users ) ) {
				$stats['complete'] = true;
				delete_transient( self::BACKFILL_OFFSET_TRANSIENT );
				break;
			}

			$lookups = array();
			foreach ( $users as $user ) {
				if ( empty( $user->user_email ) ) {
					continue;
				}
				$lookups[] = array(
					'wp_user_id' => $user->ID,
					'email'      => $user->user_email,
				);
			}

			$batch_count = count( $users );

			if ( empty( $lookups ) ) {
				$stats['processed'] += $batch_count;
				$offset             += $batch_count;
				$stats['next_offset'] = $offset;
				if ( $batch_count < self::BACKFILL_BATCH_SIZE ) {
					$stats['complete'] = true;
					delete_transient( self::BACKFILL_OFFSET_TRANSIENT );
					break;
				}
				continue;
			}

			$result = $this->api->resolve_subs( $lookups );

			if ( is_array( $result ) ) {
				if ( ! empty( $result['mappings'] ) && is_array( $result['mappings'] ) ) {
					foreach ( $result['mappings'] as $wp_user_id => $sub ) {
						update_user_meta( (int) $wp_user_id, 'cognito_user_id', $sub );
						++$stats['resolved'];
					}
				}
				if ( ! empty( $result['notFound'] ) && is_array( $result['notFound'] ) ) {
					$stats['notFound'] += count( $result['notFound'] );
				}
				if ( ! empty( $result['errored'] ) && is_array( $result['errored'] ) ) {
					$stats['errored'] += count( $result['errored'] );
					foreach ( $result['errored'] as $errored ) {
						$stats['errors'][] = is_string( $errored )
							? $errored
							: wp_json_encode( $errored );
					}
				}
			} else {
				$stats['errored'] += count( $lookups );
				$stats['errors'][] = sprintf( 'Backfill request failed at offset %d', $offset );
			}

			$stats['processed'] += $batch_count;
			$offset             += $batch_count;
			$stats['next_offset'] = $offset;

			if ( $batch_count < self::BACKFILL_BATCH_SIZE ) {
				$stats['complete'] = true;
				delete_transient( self::BACKFILL_OFFSET_TRANSIENT );
				break;
			}
		}

		if ( ! $stats['complete'] ) {
			set_transient( self::BACKFILL_OFFSET_TRANSIENT, $offset, HOUR_IN_SECONDS );
		}

		$this->api->log_message(
			sprintf(
				'Backfill tick: processed %d (offset %d → %d of %d missing), resolved %d, notFound %d, errored %d, complete=%s',
				$stats['processed'],
				$stats['start'],
				$stats['next_offset'],
				$stats['total'],
				$stats['resolved'],
				$stats['notFound'],
				$stats['errored'],
				$stats['complete'] ? 'yes' : 'no'
			)
		);

		return $stats;
	}

	/**
	 * Reset the backfill resume offset (admin "Restart from beginning").
	 */
	public function reset_backfill_offset() {
		delete_transient( self::BACKFILL_OFFSET_TRANSIENT );
	}

	/**
	 * Legacy group-creation entry point (no-op in v3.0).
	 *
	 * The Lambda creates `MyBosun_{tenantId}_{level}` groups on demand, so the
	 * plugin no longer pre-creates groups in Cognito. Retained for backward
	 * compatibility with the admin UI.
	 *
	 * @return array Empty stats.
	 */
	public function sync_groups() {
		$this->api->log_message( 'sync_groups() is a no-op in v3.0 — Cognito groups are created on demand by the sync Lambda.' );

		return array(
			'processed' => 0,
			'created'   => 0,
			'failed'    => 0,
			'errors'    => array(),
		);
	}

	/**
	 * Test sync functionality and return preview of actions
	 *
	 * @param int $limit Number of users to include in preview.
	 * @return array Preview data.
	 */
	public function test_sync_preview( $limit = 10 ) {
		$users = get_users(
			array(
				'number' => $limit,
				'fields' => 'all',
			)
		);

		$preview = array(
			'users_to_create'      => array(),
			'users_to_update'      => array(),
			'users_already_synced' => array(),
		);

		foreach ( $users as $user ) {
			$cognito_id = get_user_meta( $user->ID, 'cognito_user_id', true );

			$user_info = array(
				'id'         => $user->ID,
				'email'      => $user->user_email,
				'username'   => $user->user_login,
				'cognito_id' => $cognito_id,
			);

			if ( empty( $cognito_id ) ) {
				$preview['users_to_create'][] = $user_info;
			} else {
				$preview['users_to_update'][] = $user_info;
			}
		}

		return $preview;
	}

	/**
	 * Get current synchronization status and statistics
	 *
	 * @return array Sync status information.
	 */
	public function get_sync_status() {
		$total_users  = count( get_users( array( 'fields' => 'ID' ) ) );
		$synced_users = count(
			get_users(
				array(
					'meta_key'     => 'cognito_user_id',
					'meta_compare' => 'EXISTS',
					'fields'       => 'ID',
				)
			)
		);

		$synced_groups   = get_option( 'wp_cognito_sync_groups', array() );
		$available_roles = get_editable_roles();

		return array(
			'users'  => array(
				'total'      => $total_users,
				'synced'     => $synced_users,
				'unsynced'   => $total_users - $synced_users,
				'percentage' => $total_users > 0 ? round( ( $synced_users / $total_users ) * 100, 2 ) : 0,
			),
			'groups' => array(
				'available_roles' => count( $available_roles ),
				'synced_roles'    => count( $synced_groups ),
				'enabled_groups'  => $synced_groups,
			),
		);
	}

	/**
	 * Force synchronization of a specific user to Cognito
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array Sync result with success status and message.
	 */
	public function force_sync_user( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array(
				'success' => false,
				'message' => __( 'User not found', 'wp-cognito-auth' ),
			);
		}

		try {
			$cognito_id = get_user_meta( $user_id, 'cognito_user_id', true );
			$user_data  = $this->prepare_user_data( $user_id, $user );

			if ( empty( $cognito_id ) ) {
				$result = $this->api->create_user( $user_data );
				$action = 'created';
			} else {
				$user_data['cognito_user_id'] = $cognito_id;
				$result                       = $this->api->update_user( $user_data );
				$action                       = 'updated';
			}

			if ( $result ) {
				$features = get_option( 'wp_cognito_features', array() );
				if ( ! empty( $features['group_sync'] ) ) {
					$this->sync_user_groups( $user_id );
				}

				return array(
					'success' => true,
					'message' => sprintf( __( 'User %s in Cognito', 'wp-cognito-auth' ), $action ),
				);
			} else {
				return array(
					'success' => false,
					'message' => __( 'Failed to sync user to Cognito', 'wp-cognito-auth' ),
				);
			}
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}
}
