<?php
/**
 * Shared user-data preparation helpers for Cognito sync
 *
 * @package WP_Cognito_Auth
 */

namespace WP_Cognito_Auth;

/**
 * Class User_Data
 *
 * Builds the canonical "dumb pipe" payload sent to the MyBosun sync Lambda.
 *
 * Payload contract (v3.0):
 *   {
 *     tenantId:    "tenantId",
 *     wp_user_id:  123,
 *     email:       "user@example.com",
 *     username:    "user_login",
 *     firstName:   "Jane",
 *     lastName:    "Doe",
 *     name:        "Jane Doe",
 *     wp_roles:    ["subscriber", "wc_member"],
 *     extra:       { ... custom per-tenant fields ... }
 *   }
 *
 * Tenant-specific extras (e.g. `wpuef_cid_c6` / `wpuef_cid_c10`) are no longer
 * hard-coded — they are added via the `wp_cognito_user_extra_fields` filter so
 * each WP install can attach its own metadata.
 */
class User_Data {

	/**
	 * Get the configured tenant ID.
	 *
	 * @return string Tenant ID, or empty string if unconfigured.
	 */
	public static function get_tenant_id() {
		return (string) get_option( 'wp_cognito_tenant_id', '' );
	}

	/**
	 * Build the canonical payload for a WordPress user.
	 *
	 * @param int      $user_id WordPress user ID.
	 * @param \WP_User $user    WordPress user object.
	 * @return array Payload ready to send to the Lambda.
	 */
	public static function prepare( $user_id, $user ) {
		$first_name = get_user_meta( $user_id, 'first_name', true );
		$last_name  = get_user_meta( $user_id, 'last_name', true );

		$full_name = trim( $first_name . ' ' . $last_name );
		if ( empty( $full_name ) ) {
			$full_name = $user->display_name ?: $user->user_login;
		}

		$cognito_user_id = get_user_meta( $user_id, 'cognito_user_id', true );

		// Default extras include the legacy custom fields for backward
		// compatibility — gated behind a filter so other tenants can opt out
		// or replace them entirely.
		$default_extras = array(
			'wp_memberrank'     => get_user_meta( $user_id, 'wpuef_cid_c6', true ),
			'wp_membercategory' => get_user_meta( $user_id, 'wpuef_cid_c10', true ),
		);

		/**
		 * Filter the tenant-specific extra fields included in the sync payload.
		 *
		 * @param array    $default_extras Default extras (legacy custom fields).
		 * @param int      $user_id        WordPress user ID.
		 * @param \WP_User $user           WordPress user object.
		 */
		$extras = apply_filters( 'wp_cognito_user_extra_fields', $default_extras, $user_id, $user );

		$payload = array(
			'tenantId'   => self::get_tenant_id(),
			'wp_user_id' => $user_id,
			'email'      => $user->user_email,
			'username'   => $user->user_login,
			'firstName'  => $first_name,
			'lastName'   => $last_name,
			'name'       => $full_name,
			'wp_roles'   => array_values( (array) $user->roles ),
			'extra'      => is_array( $extras ) ? $extras : array(),
		);

		if ( ! empty( $cognito_user_id ) ) {
			$payload['cognito_user_id'] = $cognito_user_id;
		}

		return $payload;
	}
}
