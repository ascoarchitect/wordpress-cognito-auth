<?php
/**
 * Push-only site mode (MS-05) — the new club site takes members from MyBosun
 * and sends nothing back.
 *
 * @package WP_Cognito_Auth
 */

use PHPUnit\Framework\TestCase;
use WP_Cognito_Auth\Inbound;
use WP_Cognito_Auth\Push_Only;

class PushOnlyTest extends TestCase {

	const SUB = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

	/** @var int Linked to Cognito. */
	private $member;

	/** @var int A local site-builder account, no Cognito link. */
	private $local;

	protected function setUp(): void {
		WP_Fake::reset();
		update_option( 'wp_cognito_features', array( 'inbound' => 1, 'push_only' => 1 ) );
		update_option( 'wp_cognito_tenant_id', 'rafsa' );

		$this->member = wp_insert_user(
			array(
				'user_login'   => 'member',
				'user_email'   => 'member@example.test',
				'display_name' => 'Pat Member',
				'first_name'   => 'Pat',
				'last_name'    => 'Member',
				'role'         => 'subscriber',
			)
		);
		WP_Fake::$users[ $this->member ]->user_pass = 'stored-hash';
		update_user_meta( $this->member, Inbound::META_SUB, self::SUB );

		$this->local = wp_insert_user(
			array(
				'user_login'   => 'builder',
				'user_email'   => 'builder@example.test',
				'display_name' => 'Site Builder',
				'role'         => 'administrator',
			)
		);
		$_POST = array();
	}

	private function set_applying( $value ) {
		$prop = new ReflectionProperty( Inbound::class, 'applying' );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop->setAccessible( true );
		}
		$prop->setValue( null, $value );
	}

	private function attempted_change() {
		return array(
			'user_pass'    => 'new-hash',
			'user_email'   => 'changed@example.test',
			'display_name' => 'Someone Else',
			'user_url'     => 'https://example.test',
		);
	}

	// ── outbound sync off ───────────────────────────────────────────────────

	public function test_outbound_features_read_as_off_when_push_only() {
		$features = Push_Only::normalise_features(
			array( 'sync' => 1, 'group_sync' => 1, 'inbound' => 1, 'authentication' => 1, 'push_only' => 1 )
		);
		$this->assertArrayNotHasKey( 'sync', $features );
		$this->assertArrayNotHasKey( 'group_sync', $features );
		$this->assertSame( 1, $features['inbound'] );
		$this->assertSame( 1, $features['authentication'] );
	}

	public function test_outbound_features_untouched_without_push_only() {
		$in = array( 'sync' => 1, 'group_sync' => 1 );
		$this->assertSame( $in, Push_Only::normalise_features( $in ) );
		$this->assertSame( '', Push_Only::normalise_features( '' ) );
	}

	// ── registration off ────────────────────────────────────────────────────

	public function test_registration_reads_as_closed() {
		$this->assertSame( 0, Push_Only::registration_closed() );
	}

	// ── profile fields read-only ────────────────────────────────────────────

	public function test_linked_user_identity_columns_are_kept_on_update() {
		$data = Push_Only::filter_user_data( $this->attempted_change(), true, $this->member, array() );
		$this->assertSame( 'member@example.test', $data['user_email'] );
		$this->assertSame( 'stored-hash', $data['user_pass'] );
		$this->assertSame( 'Pat Member', $data['display_name'] );
		// Not an identity field MyBosun owns.
		$this->assertSame( 'https://example.test', $data['user_url'] );
	}

	public function test_linked_user_names_are_kept_on_update() {
		$meta = Push_Only::filter_user_meta(
			array( 'first_name' => 'Changed', 'last_name' => 'Name', 'description' => 'bio' ),
			new WP_User( $this->member ),
			true,
			array()
		);
		$this->assertSame( 'Pat', $meta['first_name'] );
		$this->assertSame( 'Member', $meta['last_name'] );
		$this->assertSame( 'bio', $meta['description'] );
	}

	public function test_local_account_is_not_locked() {
		$change = $this->attempted_change();
		$this->assertSame( $change, Push_Only::filter_user_data( $change, true, $this->local, array() ) );
		$this->assertTrue( Push_Only::filter_allow_password_reset( true, $this->local ) );
	}

	public function test_inserts_are_not_locked() {
		$change = $this->attempted_change();
		$this->assertSame( $change, Push_Only::filter_user_data( $change, false, null, array() ) );
	}

	public function test_nothing_is_locked_when_push_only_is_off() {
		update_option( 'wp_cognito_features', array( 'inbound' => 1 ) );
		$change = $this->attempted_change();
		$this->assertSame( $change, Push_Only::filter_user_data( $change, true, $this->member, array() ) );
		$this->assertFalse( Push_Only::is_locked( $this->member ) );
	}

	public function test_the_push_itself_can_write_a_linked_user() {
		$change = $this->attempted_change();
		$this->set_applying( true );
		try {
			$this->assertSame( $change, Push_Only::filter_user_data( $change, true, $this->member, array() ) );
		} finally {
			$this->set_applying( false );
		}
	}

	public function test_the_login_path_can_write_a_linked_user() {
		$change = $this->attempted_change();
		$data   = Push_Only::as_system(
			function () use ( $change ) {
				return Push_Only::filter_user_data( $change, true, $this->member, array() );
			}
		);
		$this->assertSame( $change, $data );
		// And the bypass ends with the call.
		$after = Push_Only::filter_user_data( $change, true, $this->member, array() );
		$this->assertSame( 'member@example.test', $after['user_email'] );
	}

	public function test_password_reset_and_fields_refused_for_linked_user() {
		$this->assertFalse( Push_Only::filter_allow_password_reset( true, $this->member ) );
		$this->assertFalse( Push_Only::filter_show_password_fields( true, new WP_User( $this->member ) ) );
		$this->assertTrue( Push_Only::filter_show_password_fields( true, new WP_User( $this->local ) ) );
	}

	public function test_no_change_notification_for_a_refused_change() {
		$this->assertFalse( Push_Only::filter_change_email( true, array( 'ID' => $this->member ), array() ) );
		$this->assertTrue( Push_Only::filter_change_email( true, array( 'ID' => $this->local ), array() ) );
	}

	public function test_profile_form_post_is_put_back_before_core_reads_it() {
		$_POST = array(
			'email'        => 'changed@example.test',
			'first_name'   => 'Changed',
			'last_name'    => 'Name',
			'display_name' => 'Someone Else',
			'pass1'        => 'secret',
			'pass2'        => 'secret',
			'description'  => 'bio',
		);
		Push_Only::neutralise_profile_post( $this->member );
		$this->assertSame( 'member@example.test', $_POST['email'] );
		$this->assertSame( 'Pat', $_POST['first_name'] );
		$this->assertSame( 'Member', $_POST['last_name'] );
		$this->assertSame( 'Pat Member', $_POST['display_name'] );
		$this->assertArrayNotHasKey( 'pass1', $_POST );
		$this->assertArrayNotHasKey( 'pass2', $_POST );
		$this->assertSame( 'bio', $_POST['description'] );
	}

	public function test_profile_form_post_untouched_for_local_account() {
		$_POST = array( 'email' => 'new@example.test', 'pass1' => 'x' );
		Push_Only::neutralise_profile_post( $this->local );
		$this->assertSame( array( 'email' => 'new@example.test', 'pass1' => 'x' ), $_POST );
	}

	// ── JIT group → role map (shipped in 3.2, still the login path's map) ──

	public function test_jit_map_still_applies_on_a_push_only_site() {
		WP_Fake::$roles['club_member'] = 'Club member';
		update_option( Inbound::OPTION_JIT_ROLE_MEMBER, 'club_member' );
		$this->assertSame( 'club_member', Inbound::jit_role_for_groups( array( 'MyBosun_rafsa_member' ) ) );
	}
}
