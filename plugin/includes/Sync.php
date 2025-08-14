<?php
namespace WP_Cognito_Auth;

class Sync {
	private $api;

	public function __construct( $api ) {
		$this->api = $api;
	}

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

	public function on_user_update( $user_id, $old_user_data = null ) {
		$features = get_option( 'wp_cognito_features', array() );
		if ( empty( $features['sync'] ) ) {
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

		if ( ! empty( $features['group_sync'] ) ) {
			$this->sync_user_groups( $user_id );
		}
	}

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

	public function on_user_role_change( $user_id, $new_role, $old_roles ) {
		$features = get_option( 'wp_cognito_features', array() );
		if ( empty( $features['group_sync'] ) ) {
			return;
		}

		$this->sync_user_groups( $user_id );
	}

	public function on_user_role_added( $user_id, $role ) {
		$features = get_option( 'wp_cognito_features', array() );
		if ( empty( $features['group_sync'] ) ) {
			return;
		}

		$this->sync_user_groups( $user_id );
	}

	public function on_user_role_removed( $user_id, $role ) {
		$features = get_option( 'wp_cognito_features', array() );
		if ( empty( $features['group_sync'] ) ) {
			return;
		}

		$this->sync_user_groups( $user_id );
	}

	private function prepare_user_data( $user_id, $user ) {
		$first_name = get_user_meta( $user_id, 'first_name', true );
		$last_name  = get_user_meta( $user_id, 'last_name', true );

		$full_name = trim( $first_name . ' ' . $last_name );
		if ( empty( $full_name ) ) {
			$full_name = $user->display_name ?: $user->user_login;
		}

		return array(
			'wp_user_id'        => $user_id,
			'email'             => $user->user_email,
			'username'          => $user->user_login,
			'firstName'         => $first_name,
			'lastName'          => $last_name,
			'name'              => $full_name,
			'wp_memberrank'     => get_user_meta( $user_id, 'wpuef_cid_c6', true ),
			'wp_membercategory' => get_user_meta( $user_id, 'wpuef_cid_c10', true ),
		);
	}

	public function sync_user_groups( $user_id ) {
		$synced_groups = get_option( 'wp_cognito_sync_groups', array() );
		$user          = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			$this->api->log_message( "Cannot sync groups: User ID {$user_id} not found", 'error' );
			return false;
		}

		$cognito_user_id = get_user_meta( $user_id, 'cognito_user_id', true );
		if ( empty( $cognito_user_id ) ) {
			$this->api->log_message( "Cannot sync groups for user {$user->user_login}: No Cognito user ID found", 'warning' );
			return false;
		}

		if ( empty( $synced_groups ) ) {
			$this->api->log_message( 'No groups configured for synchronization', 'info' );
			return true;
		}

		$success       = true;
		$actions_taken = array();

		foreach ( $synced_groups as $group_name ) {
			if ( in_array( $group_name, $user->roles ) ) {
				// User has this role, ensure they're in the Cognito group
				if ( $this->api->update_group_membership( $cognito_user_id, $group_name, 'add' ) ) {
					$actions_taken[] = "Added to WP_{$group_name}";
				} else {
					$success         = false;
					$actions_taken[] = "Failed to add to WP_{$group_name}";
				}
			} else {
				// User doesn't have this role, ensure they're removed from the Cognito group
				if ( $this->api->update_group_membership( $cognito_user_id, $group_name, 'remove' ) ) {
					$actions_taken[] = "Removed from WP_{$group_name}";
				} else {
					$success         = false;
					$actions_taken[] = "Failed to remove from WP_{$group_name}";
				}
			}
		}

		if ( ! empty( $actions_taken ) ) {
			$this->api->log_message( "Group sync completed for user {$user->user_login}: " . implode( ', ', $actions_taken ) );
		}

		return $success;
	}

	public function bulk_sync_users( $limit = 50, $offset = 0 ) {
		$users = get_users(
			array(
				'number' => $limit,
				'offset' => $offset,
				'fields' => 'all',
			)
		);

		return $this->process_users_for_sync( $users );
	}

	public function bulk_sync_users_by_role( $role ) {
		$users = get_users(
			array(
				'role'   => $role,
				'fields' => 'all',
			)
		);

		return $this->process_users_for_sync( $users );
	}

	private function process_users_for_sync( $users ) {
		$stats = array(
			'processed' => 0,
			'created'   => 0,
			'updated'   => 0,
			'failed'    => 0,
			'errors'    => array(),
		);

		foreach ( $users as $user ) {
			++$stats['processed'];

			try {
				$cognito_id = get_user_meta( $user->ID, 'cognito_user_id', true );
				$user_data  = $this->prepare_user_data( $user->ID, $user );

				if ( empty( $cognito_id ) ) {
					$result = $this->api->create_user( $user_data );
					if ( $result ) {
						++$stats['created'];
					} else {
						++$stats['failed'];
					}
				} else {
					$user_data['cognito_user_id'] = $cognito_id;
					$result                       = $this->api->update_user( $user_data );
					if ( $result ) {
						++$stats['updated'];
					} else {
						++$stats['failed'];
					}
				}
			} catch ( \Exception $e ) {
				++$stats['failed'];
				$stats['errors'][] = sprintf(
					'Error processing user %s (%d): %s',
					$user->user_email,
					$user->ID,
					$e->getMessage()
				);
			}
		}

		$this->api->log_message(
			sprintf(
				'Bulk sync completed. Processed: %d, Created: %d, Updated: %d, Failed: %d',
				$stats['processed'],
				$stats['created'],
				$stats['updated'],
				$stats['failed']
			)
		);

		return $stats;
	}

	public function sync_groups() {
		$synced_groups = get_option( 'wp_cognito_sync_groups', array() );
		$stats         = array(
			'processed' => 0,
			'created'   => 0,
			'failed'    => 0,
			'errors'    => array(),
		);

		foreach ( $synced_groups as $group_name ) {
			++$stats['processed'];

			try {
				$result = $this->api->create_group( $group_name );
				if ( $result ) {
					++$stats['created'];
				} else {
					++$stats['failed'];
				}
			} catch ( \Exception $e ) {
				++$stats['failed'];
				$stats['errors'][] = sprintf(
					'Error creating group %s: %s',
					$group_name,
					$e->getMessage()
				);
			}
		}

		return $stats;
	}

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
