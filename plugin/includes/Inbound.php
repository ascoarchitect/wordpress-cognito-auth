<?php
/**
 * Inbound member push — MyBosun → WordPress
 *
 * @package WP_Cognito_Auth
 */

namespace WP_Cognito_Auth;

/**
 * Class Inbound
 *
 * The receiving end of the direction flip. Once MyBosun owns membership
 * (statusAuthority = mybosun) it pushes member records HERE — a bulk load
 * into an empty site, then one record per status change or identity edit.
 * WordPress does not decide anything about membership in this path; it
 * takes the record, finds or creates the person, and sets the role it was
 * told to set.
 *
 * ---------------------------------------------------------------------------
 * THE PLUGIN VALIDATES, NEVER INVENTS
 * ---------------------------------------------------------------------------
 * The role slug in the payload must already exist in WordPress (`get_role`).
 * The new site has no WooCommerce, so `customer` does not exist; the club
 * creates its own roles with a role-editor plugin and MyBosun only names
 * them. An unknown slug is REFUSED for that member and reported back by
 * name — it is never mapped to subscriber and the role is never created.
 *
 * ---------------------------------------------------------------------------
 * ONE MEMBERSHIP-DERIVED ROLE, REPLACED NOT APPENDED
 * ---------------------------------------------------------------------------
 * This class remembers the one role IT assigned (`cognito_assigned_role`)
 * and removes only that one when it changes. Roles it did not assign —
 * `administrator`, `editor`, anything a human granted — are never touched,
 * so a committee member who edits the site keeps that regardless of
 * membership state. Membership is never a route to admin, in either
 * direction.
 *
 * ---------------------------------------------------------------------------
 * MATCH BY SUB, THEN EMAIL, THEN CREATE
 * ---------------------------------------------------------------------------
 * The same order the login path uses. Matching by sub first is what makes an
 * email change land on the right WordPress user instead of minting a second
 * one; matching by email second is what heals a site whose users pre-date
 * the push. A record whose email is already held by a DIFFERENT WordPress
 * user is refused as `email-conflict` — that is a human decision.
 *
 * ---------------------------------------------------------------------------
 * LOOP GUARD
 * ---------------------------------------------------------------------------
 * Every write here fires WordPress's own user hooks, which the outbound sync
 * listens to. While a record is being applied, {@see Inbound::applying()} is
 * true and the outbound hooks in {@see Sync} return immediately, so a push
 * from MyBosun can never bounce straight back to MyBosun.
 */
class Inbound {

	const REST_NAMESPACE = 'wp-cognito-auth/v1';
	const REST_ROUTE     = '/members';

	/** Records accepted per request. MyBosun batches to this. */
	const MAX_BATCH = 50;

	/** Accepted clock skew on the signed timestamp, seconds. */
	const MAX_SKEW_SECONDS = 300;

	/** How long a seen signature is remembered, seconds. Must exceed the skew window. */
	const REPLAY_WINDOW_SECONDS = 600;

	const SUB_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	const HEADER_TIMESTAMP = 'X-MyBosun-Timestamp';
	const HEADER_SIGNATURE = 'X-MyBosun-Signature';

	const META_SUB              = 'cognito_user_id';
	const META_GROUPS           = 'cognito_groups';
	const META_ASSIGNED_ROLE    = 'cognito_assigned_role';
	const META_MEMBERSHIP_STATE = 'cognito_membership_state';
	const META_LAST_INBOUND     = 'cognito_last_inbound';

	const OPTION_SECRET             = 'wp_cognito_inbound_secret';
	const OPTION_JIT_ROLE_MEMBER    = 'wp_cognito_jit_role_member';
	const OPTION_JIT_ROLE_SUSPENDED = 'wp_cognito_jit_role_suspended';

	/**
	 * True while a pushed record is being written. Read by the outbound hooks.
	 *
	 * @var bool
	 */
	private static $applying = false;

	/**
	 * The API client, for its logger. Optional — the inbound path never calls out.
	 *
	 * @var API|null
	 */
	private $api;

	/**
	 * Inbound constructor.
	 *
	 * @param API|null $api API client, used only for log_message().
	 */
	public function __construct( $api = null ) {
		$this->api = $api;
	}

	/**
	 * Is an inbound record being applied right now?
	 *
	 * @return bool
	 */
	public static function applying() {
		return self::$applying;
	}

	/**
	 * Register the REST route. Hooked to `rest_api_init`.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_members' ),
				'permission_callback' => array( $this, 'verify_request' ),
			)
		);
	}

	/**
	 * Authenticate the request: feature on, secret set, HMAC valid, fresh, unseen.
	 *
	 * Signature = hex HMAC-SHA256( secret, timestamp . "." . raw body ).
	 * The timestamp is bound into the signature so a captured request cannot
	 * be re-dated; the signature itself is remembered so it cannot be replayed
	 * inside the skew window either.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return true|\WP_Error
	 */
	public function verify_request( $request ) {
		$features = get_option( 'wp_cognito_features', array() );
		if ( empty( $features['inbound'] ) ) {
			return new \WP_Error( 'cognito_inbound_disabled', 'Inbound sync is not enabled on this site.', array( 'status' => 403 ) );
		}

		$secret = (string) get_option( self::OPTION_SECRET, '' );
		if ( '' === $secret ) {
			return new \WP_Error( 'cognito_inbound_unconfigured', 'No shared secret is configured for inbound sync.', array( 'status' => 403 ) );
		}

		$timestamp = trim( (string) $request->get_header( self::HEADER_TIMESTAMP ) );
		$signature = strtolower( trim( (string) $request->get_header( self::HEADER_SIGNATURE ) ) );
		if ( '' === $timestamp || '' === $signature ) {
			return new \WP_Error( 'cognito_inbound_unsigned', 'Missing signature headers.', array( 'status' => 401 ) );
		}
		if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > self::MAX_SKEW_SECONDS ) {
			return new \WP_Error( 'cognito_inbound_stale', 'Request timestamp is outside the accepted window.', array( 'status' => 401 ) );
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . (string) $request->get_body(), $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			$this->log( 'Inbound request rejected: bad signature', 'warning' );
			return new \WP_Error( 'cognito_inbound_bad_signature', 'Signature does not match.', array( 'status' => 401 ) );
		}

		$replay_key = 'cognito_inbound_sig_' . md5( $signature );
		if ( get_transient( $replay_key ) ) {
			$this->log( 'Inbound request rejected: replayed signature', 'warning' );
			return new \WP_Error( 'cognito_inbound_replay', 'This request has already been processed.', array( 'status' => 401 ) );
		}
		set_transient( $replay_key, 1, self::REPLAY_WINDOW_SECONDS );

		return true;
	}

	/**
	 * Apply a batch of member records. One outcome per record, never a
	 * partial silence: a refused member is named with the reason so the
	 * MyBosun run report can say who, not how many.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_members( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'cognito_inbound_bad_body', 'Body must be a JSON object.', array( 'status' => 400 ) );
		}

		$tenant = (string) get_option( 'wp_cognito_tenant_id', '' );
		$sent   = isset( $body['tenantId'] ) ? (string) $body['tenantId'] : '';
		if ( '' === $tenant || $sent !== $tenant ) {
			$this->log( "Inbound request rejected: tenant mismatch (sent '{$sent}')", 'warning' );
			return new \WP_Error( 'cognito_inbound_tenant_mismatch', 'tenantId does not match this site.', array( 'status' => 403 ) );
		}

		$members = isset( $body['members'] ) ? $body['members'] : null;
		if ( ! is_array( $members ) || empty( $members ) ) {
			return new \WP_Error( 'cognito_inbound_no_members', 'members must be a non-empty array.', array( 'status' => 400 ) );
		}
		if ( count( $members ) > self::MAX_BATCH ) {
			return new \WP_Error( 'cognito_inbound_batch_too_large', sprintf( 'At most %d members per request.', self::MAX_BATCH ), array( 'status' => 400 ) );
		}

		$results = array();
		foreach ( $members as $member ) {
			$results[] = $this->upsert_member( is_array( $member ) ? $member : array() );
		}

		$counts = array_count_values( array_column( $results, 'outcome' ) );
		$this->log( 'Inbound batch applied: ' . wp_json_encode( $counts ), 'info' );

		return rest_ensure_response(
			array(
				'tenantId'   => $tenant,
				'receivedAt' => gmdate( 'c' ),
				'counts'     => $counts,
				'results'    => $results,
			)
		);
	}

	/**
	 * Find-or-create one member and set their role.
	 *
	 * @param array $m One member record from the payload.
	 * @return array {sub, outcome, reason, wpUserId}
	 */
	public function upsert_member( array $m ) {
		$sub    = isset( $m['sub'] ) ? trim( (string) $m['sub'] ) : '';
		$result = array(
			'sub'      => $sub,
			'outcome'  => 'refused',
			'reason'   => null,
			'wpUserId' => null,
		);

		if ( ! preg_match( self::SUB_PATTERN, $sub ) ) {
			$result['reason'] = 'invalid-sub';
			return $result;
		}

		$state = isset( $m['membershipState'] ) ? strtoupper( preg_replace( '/[^A-Za-z_]/', '', (string) $m['membershipState'] ) ) : '';
		if ( 'ERASED' === $state ) {
			// Erasure means no identity crosses. MyBosun should never send
			// one; if it does, the answer is still no.
			$result['outcome'] = 'skipped';
			$result['reason']  = 'erased';
			return $result;
		}

		$email = isset( $m['email'] ) ? sanitize_email( (string) $m['email'] ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			$result['reason'] = 'invalid-email';
			return $result;
		}

		$role = isset( $m['wpRole'] ) ? sanitize_key( (string) $m['wpRole'] ) : '';
		if ( '' === $role || ! get_role( $role ) ) {
			$result['reason'] = 'unknown-role';
			$result['role']   = $role;
			return $result;
		}

		$groups = array();
		if ( isset( $m['groups'] ) && is_array( $m['groups'] ) ) {
			$groups = array_values( array_filter( array_map( 'sanitize_text_field', $m['groups'] ) ) );
		}
		$first = isset( $m['firstName'] ) ? sanitize_text_field( (string) $m['firstName'] ) : '';
		$last  = isset( $m['lastName'] ) ? sanitize_text_field( (string) $m['lastName'] ) : '';
		$name  = isset( $m['name'] ) ? sanitize_text_field( (string) $m['name'] ) : '';
		if ( '' === $name ) {
			$name = trim( $first . ' ' . $last );
		}

		$user = self::find_by_sub( $sub );
		if ( ! $user ) {
			$user = get_user_by( 'email', $email );
		}

		// Another WordPress user already holds this address. Not ours to resolve.
		$holder = get_user_by( 'email', $email );
		if ( $holder && $user && (int) $holder->ID !== (int) $user->ID ) {
			$result['reason']   = 'email-conflict';
			$result['wpUserId'] = (int) $user->ID;
			return $result;
		}

		self::$applying = true;
		// WordPress emails the OLD address on an email change and the user on a
		// password change. Neither is wanted for a system-driven identity update
		// — MyBosun already verified the new address before it got here.
		add_filter( 'send_email_change_email', '__return_false' );
		add_filter( 'send_password_change_email', '__return_false' );
		try {
			if ( ! $user ) {
				$user_id = wp_insert_user(
					array(
						'user_login'   => self::unique_username( strstr( $email, '@', true ) ),
						'user_email'   => $email,
						'user_pass'    => wp_generate_password( 32, true, true ),
						'first_name'   => $first,
						'last_name'    => $last,
						'display_name' => '' !== $name ? $name : $email,
						'role'         => $role,
					)
				);
				if ( is_wp_error( $user_id ) ) {
					$result['reason'] = 'create-failed: ' . $user_id->get_error_message();
					return $result;
				}
				$result['outcome'] = 'created';
			} else {
				$user_id = (int) $user->ID;
				$updates = array();
				if ( $user->user_email !== $email ) {
					$updates['user_email'] = $email;
				}
				if ( '' !== $first && get_user_meta( $user_id, 'first_name', true ) !== $first ) {
					$updates['first_name'] = $first;
				}
				if ( '' !== $last && get_user_meta( $user_id, 'last_name', true ) !== $last ) {
					$updates['last_name'] = $last;
				}
				if ( '' !== $name && $user->display_name !== $name ) {
					$updates['display_name'] = $name;
				}
				if ( ! empty( $updates ) ) {
					$updates['ID'] = $user_id;
					$updated       = wp_update_user( $updates );
					if ( is_wp_error( $updated ) ) {
						$result['reason'] = 'update-failed: ' . $updated->get_error_message();
						return $result;
					}
				}
				$result['outcome'] = 'updated';
			}

			if ( get_user_meta( $user_id, self::META_SUB, true ) !== $sub ) {
				update_user_meta( $user_id, self::META_SUB, $sub );
			}
			update_user_meta( $user_id, self::META_GROUPS, $groups );
			update_user_meta( $user_id, self::META_MEMBERSHIP_STATE, $state );
			update_user_meta( $user_id, self::META_LAST_INBOUND, gmdate( 'c' ) );
			self::apply_role( $user_id, $role );

			$result['wpUserId'] = (int) $user_id;
			return $result;
		} finally {
			remove_filter( 'send_email_change_email', '__return_false' );
			remove_filter( 'send_password_change_email', '__return_false' );
			self::$applying = false;
		}
	}

	/**
	 * Set the membership-derived role, replacing only the one we set before.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $role    Role slug (must exist).
	 * @return bool
	 */
	public static function apply_role( $user_id, $role ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! get_role( $role ) ) {
			return false;
		}
		$previous = (string) get_user_meta( $user_id, self::META_ASSIGNED_ROLE, true );
		$roles    = (array) $user->roles;
		if ( '' !== $previous && $previous !== $role && in_array( $previous, $roles, true ) ) {
			$user->remove_role( $previous );
		}
		if ( ! in_array( $role, $roles, true ) ) {
			$user->add_role( $role );
		}
		update_user_meta( $user_id, self::META_ASSIGNED_ROLE, $role );
		return true;
	}

	/**
	 * Remember that a role was assigned by this plugin, without changing roles.
	 * Used by the login path after `set_role()` so a later push can replace it.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $role    Role slug.
	 */
	public static function record_assigned_role( $user_id, $role ) {
		update_user_meta( $user_id, self::META_ASSIGNED_ROLE, sanitize_key( $role ) );
	}

	/**
	 * The role the just-in-time login path should give a member, from their
	 * Cognito groups. Groups carry only member/suspended, so this is coarser
	 * than the state-level map MyBosun pushes; the next push refines it.
	 *
	 * @param string[] $groups Cognito groups from the ID token.
	 * @return string Role slug, or '' when no group matches or the mapped role does not exist.
	 */
	public static function jit_role_for_groups( $groups ) {
		$tenant = (string) get_option( 'wp_cognito_tenant_id', '' );
		if ( '' === $tenant || ! is_array( $groups ) ) {
			return '';
		}
		$map = array(
			'MyBosun_' . $tenant . '_member'    => (string) get_option( self::OPTION_JIT_ROLE_MEMBER, '' ),
			'MyBosun_' . $tenant . '_suspended' => (string) get_option( self::OPTION_JIT_ROLE_SUSPENDED, '' ),
		);
		// member wins if both are somehow present — the Lambda keeps exactly one.
		foreach ( $map as $group => $role ) {
			if ( in_array( $group, $groups, true ) && '' !== $role && get_role( $role ) ) {
				return $role;
			}
		}
		return '';
	}

	/**
	 * Apply the just-in-time group role to an EXISTING user — only if no push
	 * has spoken yet. Once MyBosun has assigned a state-level role, the
	 * coarser group map must not overwrite it at the next login.
	 *
	 * @param int      $user_id WordPress user ID.
	 * @param string[] $groups  Cognito groups from the ID token.
	 * @return string The role applied, or '' when nothing was done.
	 */
	public static function apply_jit_role( $user_id, $groups ) {
		if ( '' !== (string) get_user_meta( $user_id, self::META_ASSIGNED_ROLE, true ) ) {
			return '';
		}
		$role = self::jit_role_for_groups( $groups );
		if ( '' === $role ) {
			return '';
		}
		return self::apply_role( $user_id, $role ) ? $role : '';
	}

	/**
	 * Find the WordPress user carrying this Cognito sub.
	 *
	 * @param string $sub Cognito sub.
	 * @return \WP_User|null
	 */
	public static function find_by_sub( $sub ) {
		$users = get_users(
			array(
				'meta_key'   => self::META_SUB, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $sub, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
			)
		);
		return ! empty( $users ) ? $users[0] : null;
	}

	/**
	 * A login that does not collide, from a preferred base.
	 *
	 * @param string $base Preferred login (e.g. the local part of the email).
	 * @return string
	 */
	public static function unique_username( $base ) {
		$username = sanitize_user( (string) $base, true );
		if ( '' === $username ) {
			$username = 'member';
		}
		$original = $username;
		$counter  = 1;
		while ( username_exists( $username ) ) {
			$username = $original . $counter;
			++$counter;
		}
		return $username;
	}

	/**
	 * Log through the sync logger when there is one, else PHP's.
	 *
	 * @param string $message Message.
	 * @param string $level   Level.
	 */
	private function log( $message, $level = 'info' ) {
		if ( $this->api && method_exists( $this->api, 'log_message' ) ) {
			$this->api->log_message( $message, $level );
			return;
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[wp-cognito-auth inbound] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
