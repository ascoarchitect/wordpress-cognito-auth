<?php
/**
 * Inbound member push — behaviour the MyBosun side relies on.
 *
 * @package WP_Cognito_Auth
 */

use PHPUnit\Framework\TestCase;
use WP_Cognito_Auth\Inbound;

class InboundTest extends TestCase {

	const SECRET = 'shared-secret';
	const SUB_A  = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
	const SUB_B  = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

	/** @var Inbound */
	private $inbound;

	protected function setUp(): void {
		WP_Fake::reset();
		update_option( 'wp_cognito_features', array( 'inbound' => 1 ) );
		update_option( 'wp_cognito_tenant_id', 'rafsa' );
		update_option( Inbound::OPTION_SECRET, self::SECRET );
		WP_Fake::$roles['club_member']      = 'Club member';
		WP_Fake::$roles['former_member']    = 'Former member';
		WP_Fake::$roles['suspended_member'] = 'Suspended member';
		$this->inbound = new Inbound();
	}

	private function signed( $body, $timestamp = null, $secret = self::SECRET ) {
		$timestamp = null === $timestamp ? (string) time() : (string) $timestamp;
		return new WP_REST_Request(
			$body,
			array(
				'X-MyBosun-Timestamp' => $timestamp,
				'X-MyBosun-Signature' => hash_hmac( 'sha256', $timestamp . '.' . $body, $secret ),
			)
		);
	}

	private function member( array $over = array() ) {
		return array_merge(
			array(
				'sub'             => self::SUB_A,
				'email'           => 'hermione@example.com',
				'firstName'       => 'Hermione',
				'lastName'        => 'Robson-Hemmings',
				'name'            => 'Hermione Robson-Hemmings',
				'membershipState' => 'ACTIVE',
				'groups'          => array( 'MyBosun_rafsa_member' ),
				'wpRole'          => 'club_member',
			),
			$over
		);
	}

	private function batch( array $members ) {
		return json_encode( array( 'tenantId' => 'rafsa', 'members' => $members ) );
	}

	// ── authentication ──────────────────────────────────────────────────────

	public function test_valid_signature_is_accepted_once_then_replay_refused() {
		$req = $this->signed( $this->batch( array( $this->member() ) ) );
		$this->assertTrue( $this->inbound->verify_request( $req ) );
		$again = $this->inbound->verify_request( $req );
		$this->assertInstanceOf( WP_Error::class, $again );
		$this->assertSame( 'cognito_inbound_replay', $again->get_error_code() );
	}

	public function test_bad_secret_is_refused() {
		$req = $this->signed( $this->batch( array() ), null, 'wrong' );
		$err = $this->inbound->verify_request( $req );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 401, $err->get_error_data()['status'] );
	}

	public function test_stale_timestamp_is_refused() {
		$req = $this->signed( $this->batch( array() ), time() - Inbound::MAX_SKEW_SECONDS - 1 );
		$this->assertSame( 'cognito_inbound_stale', $this->inbound->verify_request( $req )->get_error_code() );
	}

	public function test_feature_off_or_no_secret_refuses_with_403() {
		update_option( 'wp_cognito_features', array() );
		$this->assertSame( 403, $this->inbound->verify_request( $this->signed( '{}' ) )->get_error_data()['status'] );
		update_option( 'wp_cognito_features', array( 'inbound' => 1 ) );
		update_option( Inbound::OPTION_SECRET, '' );
		$this->assertSame( 'cognito_inbound_unconfigured', $this->inbound->verify_request( $this->signed( '{}' ) )->get_error_code() );
	}

	// ── batch shape ─────────────────────────────────────────────────────────

	public function test_tenant_mismatch_is_refused_whole() {
		$body = json_encode( array( 'tenantId' => 'other', 'members' => array( $this->member() ) ) );
		$res  = $this->inbound->handle_members( new WP_REST_Request( $body ) );
		$this->assertSame( 'cognito_inbound_tenant_mismatch', $res->get_error_code() );
		$this->assertCount( 0, WP_Fake::$users );
	}

	public function test_oversized_batch_is_refused() {
		$members = array_fill( 0, Inbound::MAX_BATCH + 1, $this->member() );
		$res     = $this->inbound->handle_members( new WP_REST_Request( $this->batch( $members ) ) );
		$this->assertSame( 'cognito_inbound_batch_too_large', $res->get_error_code() );
	}

	// ── upsert ──────────────────────────────────────────────────────────────

	public function test_creates_a_member_with_role_and_identity_meta() {
		$res = $this->inbound->handle_members( new WP_REST_Request( $this->batch( array( $this->member() ) ) ) );
		$this->assertSame( array( 'created' => 1 ), $res['counts'] );
		$id   = $res['results'][0]['wpUserId'];
		$user = get_userdata( $id );
		$this->assertSame( 'hermione@example.com', $user->user_email );
		$this->assertSame( 'Hermione Robson-Hemmings', $user->display_name );
		$this->assertSame( array( 'club_member' ), $user->roles );
		$this->assertSame( self::SUB_A, get_user_meta( $id, Inbound::META_SUB, true ) );
		$this->assertSame( 'club_member', get_user_meta( $id, Inbound::META_ASSIGNED_ROLE, true ) );
		$this->assertSame( 'ACTIVE', get_user_meta( $id, Inbound::META_MEMBERSHIP_STATE, true ) );
		$this->assertSame( array( 'MyBosun_rafsa_member' ), get_user_meta( $id, Inbound::META_GROUPS, true ) );
		$this->assertFalse( Inbound::applying(), 'guard must be released after the write' );
	}

	public function test_second_push_matches_by_sub_and_updates_name_and_email() {
		$first = $this->inbound->upsert_member( $this->member() );
		$id    = $first['wpUserId'];
		$again = $this->inbound->upsert_member( $this->member( array( 'email' => 'new@example.com', 'name' => 'Hermione Swinger', 'lastName' => 'Swinger' ) ) );
		$this->assertSame( 'updated', $again['outcome'] );
		$this->assertSame( $id, $again['wpUserId'], 'must not mint a second user on an email change' );
		$this->assertSame( 'new@example.com', get_userdata( $id )->user_email );
		$this->assertSame( 'Hermione Swinger', get_userdata( $id )->display_name );
		$this->assertSame( 'Swinger', get_user_meta( $id, 'last_name', true ) );
		$this->assertCount( 1, WP_Fake::$users );
	}

	public function test_matches_a_pre_existing_user_by_email_and_links_the_sub() {
		$id = wp_insert_user( array( 'user_login' => 'hermione', 'user_email' => 'hermione@example.com', 'role' => 'subscriber' ) );
		$res = $this->inbound->upsert_member( $this->member() );
		$this->assertSame( 'updated', $res['outcome'] );
		$this->assertSame( $id, $res['wpUserId'] );
		$this->assertSame( self::SUB_A, get_user_meta( $id, Inbound::META_SUB, true ) );
	}

	public function test_email_held_by_a_different_user_is_refused_as_conflict() {
		$this->inbound->upsert_member( $this->member() );
		wp_insert_user( array( 'user_login' => 'other', 'user_email' => 'taken@example.com', 'role' => 'subscriber' ) );
		$res = $this->inbound->upsert_member( $this->member( array( 'email' => 'taken@example.com' ) ) );
		$this->assertSame( 'refused', $res['outcome'] );
		$this->assertSame( 'email-conflict', $res['reason'] );
		$this->assertSame( 'hermione@example.com', get_userdata( $res['wpUserId'] )->user_email, 'nothing applied on a refusal' );
	}

	public function test_unknown_role_is_refused_by_name_and_never_created() {
		$res = $this->inbound->upsert_member( $this->member( array( 'wpRole' => 'customer' ) ) );
		$this->assertSame( 'refused', $res['outcome'] );
		$this->assertSame( 'unknown-role', $res['reason'] );
		$this->assertSame( 'customer', $res['role'] );
		$this->assertArrayNotHasKey( 'customer', WP_Fake::$roles );
		$this->assertCount( 0, WP_Fake::$users );
	}

	public function test_erased_members_are_skipped_and_never_written() {
		$res = $this->inbound->upsert_member( $this->member( array( 'membershipState' => 'ERASED' ) ) );
		$this->assertSame( 'skipped', $res['outcome'] );
		$this->assertSame( 'erased', $res['reason'] );
		$this->assertCount( 0, WP_Fake::$users );
	}

	public function test_invalid_sub_or_email_is_refused() {
		$this->assertSame( 'invalid-sub', $this->inbound->upsert_member( $this->member( array( 'sub' => 'hermione@example.com' ) ) )['reason'] );
		$this->assertSame( 'invalid-email', $this->inbound->upsert_member( $this->member( array( 'email' => 'not-an-email' ) ) )['reason'] );
	}

	// ── roles ───────────────────────────────────────────────────────────────

	public function test_role_change_replaces_only_the_role_we_assigned() {
		$id = $this->inbound->upsert_member( $this->member() )['wpUserId'];
		get_userdata( $id )->add_role( 'administrator' ); // granted by a human
		$this->inbound->upsert_member( $this->member( array( 'membershipState' => 'LAPSED', 'wpRole' => 'former_member' ) ) );
		$this->assertEqualsCanonicalizing( array( 'administrator', 'former_member' ), get_userdata( $id )->roles );
		$this->assertSame( 'former_member', get_user_meta( $id, Inbound::META_ASSIGNED_ROLE, true ) );
	}

	public function test_apply_role_leaves_a_manually_granted_role_when_it_matches_the_previous_one() {
		$id = wp_insert_user( array( 'user_login' => 'ed', 'user_email' => 'ed@example.com', 'role' => 'subscriber' ) );
		// No assigned-role meta: subscriber was never ours, so it stays.
		Inbound::apply_role( $id, 'club_member' );
		$this->assertEqualsCanonicalizing( array( 'subscriber', 'club_member' ), get_userdata( $id )->roles );
	}

	// ── just-in-time login path ─────────────────────────────────────────────

	public function test_jit_role_maps_groups_to_configured_slugs_and_validates_them() {
		update_option( Inbound::OPTION_JIT_ROLE_MEMBER, 'club_member' );
		update_option( Inbound::OPTION_JIT_ROLE_SUSPENDED, 'does_not_exist' );
		$this->assertSame( 'club_member', Inbound::jit_role_for_groups( array( 'MyBosun_rafsa_member' ) ) );
		$this->assertSame( '', Inbound::jit_role_for_groups( array( 'MyBosun_rafsa_suspended' ) ), 'unknown slug is not applied' );
		$this->assertSame( '', Inbound::jit_role_for_groups( array( 'MyBosun_other_member' ) ), 'another tenant\'s group is ignored' );
	}

	public function test_jit_role_does_not_overwrite_a_role_the_push_assigned() {
		update_option( Inbound::OPTION_JIT_ROLE_SUSPENDED, 'suspended_member' );
		$id = $this->inbound->upsert_member( $this->member( array( 'membershipState' => 'LAPSED', 'wpRole' => 'former_member' ) ) )['wpUserId'];
		$this->assertSame( '', Inbound::apply_jit_role( $id, array( 'MyBosun_rafsa_suspended' ) ) );
		$this->assertSame( array( 'former_member' ), get_userdata( $id )->roles );
	}

	public function test_jit_role_applies_to_a_user_no_push_has_touched() {
		update_option( Inbound::OPTION_JIT_ROLE_MEMBER, 'club_member' );
		$id = wp_insert_user( array( 'user_login' => 'legacy', 'user_email' => 'legacy@example.com', 'role' => 'subscriber' ) );
		$this->assertSame( 'club_member', Inbound::apply_jit_role( $id, array( 'MyBosun_rafsa_member' ) ) );
		$this->assertSame( 'club_member', get_user_meta( $id, Inbound::META_ASSIGNED_ROLE, true ) );
	}

	public function test_unique_username_avoids_collisions() {
		wp_insert_user( array( 'user_login' => 'hermione', 'user_email' => 'a@example.com' ) );
		wp_insert_user( array( 'user_login' => 'hermione1', 'user_email' => 'b@example.com' ) );
		$this->assertSame( 'hermione2', Inbound::unique_username( 'hermione' ) );
		$this->assertSame( 'member', Inbound::unique_username( '' ) );
	}
}
