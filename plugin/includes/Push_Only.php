<?php
/**
 * Push-only site mode — MyBosun is the only writer of members (MS-05)
 *
 * @package WP_Cognito_Auth
 */

namespace WP_Cognito_Auth;

/**
 * Class Push_Only
 *
 * The new club site after the switchover (switchover design SW-D5): it signs
 * members in with Cognito and receives them by push from MyBosun, and it
 * tells MyBosun nothing back. Identity is edited in MyBosun, applications
 * arrive in MyBosun and passwords live in Cognito, so a WordPress-side edit
 * could only ever be overwritten by the next push.
 *
 * One feature flag (`wp_cognito_features[push_only]`) turns on four things:
 *
 *   1. OUTBOUND SYNC OFF. `sync` and `group_sync` read as off wherever the
 *      features option is read, and are dropped when the Features tab saves,
 *      so nothing is ever sent to MyBosun or Cognito from this site.
 *   2. SELF-REGISTRATION OFF. `users_can_register` reads as 0, which is what
 *      closes wp-login.php?action=register in core. Administrators can still
 *      add accounts by hand (site builders).
 *   3. MEMBER PROFILE FIELDS READ-ONLY. For a user linked to Cognito (has a
 *      `cognito_user_id`), email, first and last name, display name and
 *      password cannot be changed in WordPress: not on the profile screen,
 *      not by an administrator, not over the REST API. Writes made by the
 *      push ({@see Inbound::applying()}) and by the login path
 *      ({@see Push_Only::as_system()}) still land. Password reset is refused
 *      for linked users; their password is Cognito's.
 *   4. The just-in-time group → role map is the existing one on the MyBosun
 *      Push tab ({@see Inbound::jit_role_for_groups()}); nothing changes here.
 *
 * Users with no Cognito link (local site-builder accounts) are not locked.
 */
class Push_Only {

	const FEATURE = 'push_only';

	/** The outbound features push-only mode switches off. */
	const OUTBOUND_FEATURES = array( 'sync', 'group_sync' );

	/** The wp_users columns MyBosun owns for a linked user. */
	const LOCKED_USER_COLUMNS = array( 'user_email', 'user_pass', 'display_name' );

	/** Profile meta MyBosun owns for a linked user. */
	const LOCKED_USER_META = array( 'first_name', 'last_name' );

	/** Profile form fields reset to their stored values before core saves them. */
	const LOCKED_FORM_FIELDS = array(
		'email'        => 'user_email',
		'display_name' => 'display_name',
		'first_name'   => 'first_name',
		'last_name'    => 'last_name',
	);

	/**
	 * True while the plugin itself writes a linked user's identity (login path).
	 *
	 * @var bool
	 */
	private static $system_write = false;

	/**
	 * Is push-only mode on? Reads the stored option directly.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$features = get_option( 'wp_cognito_features', array() );
		return is_array( $features ) && ! empty( $features[ self::FEATURE ] );
	}

	/**
	 * Register the hooks. Called once at plugin load, before anything reads
	 * the features option, so the outbound flags are already off by then.
	 */
	public static function register() {
		add_filter( 'option_wp_cognito_features', array( __CLASS__, 'normalise_features' ) );

		if ( ! self::enabled() ) {
			return;
		}

		add_filter( 'pre_option_users_can_register', array( __CLASS__, 'registration_closed' ) );

		add_filter( 'wp_pre_insert_user_data', array( __CLASS__, 'filter_user_data' ), 10, 4 );
		add_filter( 'insert_user_meta', array( __CLASS__, 'filter_user_meta' ), 10, 4 );
		add_filter( 'send_email_change_email', array( __CLASS__, 'filter_change_email' ), 10, 3 );
		add_filter( 'send_password_change_email', array( __CLASS__, 'filter_change_email' ), 10, 3 );
		add_filter( 'allow_password_reset', array( __CLASS__, 'filter_allow_password_reset' ), 10, 2 );
		add_filter( 'show_password_fields', array( __CLASS__, 'filter_show_password_fields' ), 10, 2 );

		// Priority 1: before core's send_confirmation_on_profile_email (10) and
		// before edit_user() reads $_POST.
		add_action( 'personal_options_update', array( __CLASS__, 'neutralise_profile_post' ), 1 );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'neutralise_profile_post' ), 1 );

		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_notice' ), 1 );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile_notice' ), 1 );
	}

	/**
	 * Strip the outbound features from a features array when push-only is on.
	 * Hooked to `option_wp_cognito_features`, so every reader sees them off.
	 *
	 * @param mixed $features Stored features option.
	 * @return mixed
	 */
	public static function normalise_features( $features ) {
		if ( ! is_array( $features ) || empty( $features[ self::FEATURE ] ) ) {
			return $features;
		}
		foreach ( self::OUTBOUND_FEATURES as $flag ) {
			unset( $features[ $flag ] );
		}
		return $features;
	}

	/**
	 * `pre_option_users_can_register`: registration is closed.
	 *
	 * @return int
	 */
	public static function registration_closed() {
		return 0;
	}

	/**
	 * Run a write the plugin itself makes to a linked user's identity.
	 *
	 * @param callable $write The write.
	 * @return mixed What $write returns.
	 */
	public static function as_system( callable $write ) {
		$previous           = self::$system_write;
		self::$system_write = true;
		try {
			return $write();
		} finally {
			self::$system_write = $previous;
		}
	}

	/**
	 * Is this user's identity owned by MyBosun right now?
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public static function is_locked( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! self::enabled() ) {
			return false;
		}
		$locked = '' !== (string) get_user_meta( $user_id, Inbound::META_SUB, true );
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters whether a user's profile fields are locked on a push-only site.
			 *
			 * @param bool $locked  True when the user is linked to Cognito.
			 * @param int  $user_id WordPress user ID.
			 */
			$locked = (bool) apply_filters( 'wp_cognito_profile_locked', $locked, $user_id );
		}
		return $locked;
	}

	/**
	 * Is this write one the lock must refuse?
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	private static function guards( $user_id ) {
		return ! self::$system_write && ! Inbound::applying() && self::is_locked( $user_id );
	}

	/**
	 * `wp_pre_insert_user_data`: keep a linked user's email, password and
	 * display name as stored. Inserts are untouched.
	 *
	 * @param array    $data     Columns about to be written.
	 * @param bool     $update   Whether this is an update.
	 * @param int|null $user_id  User ID on update.
	 * @param array    $userdata Raw userdata passed in.
	 * @return array
	 */
	public static function filter_user_data( $data, $update = false, $user_id = null, $userdata = array() ) {
		if ( ! $update || ! is_array( $data ) || ! self::guards( $user_id ) ) {
			return $data;
		}
		$current = get_userdata( (int) $user_id );
		if ( ! $current ) {
			return $data;
		}
		foreach ( self::LOCKED_USER_COLUMNS as $column ) {
			if ( array_key_exists( $column, $data ) && isset( $current->$column ) ) {
				$data[ $column ] = $current->$column;
			}
		}
		return $data;
	}

	/**
	 * `insert_user_meta`: keep a linked user's first and last name as stored.
	 *
	 * @param array    $meta     Meta about to be written.
	 * @param \WP_User $user     The user.
	 * @param bool     $update   Whether this is an update.
	 * @param array    $userdata Raw userdata passed in.
	 * @return array
	 */
	public static function filter_user_meta( $meta, $user = null, $update = false, $userdata = array() ) {
		$user_id = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : 0;
		if ( ! $update || ! is_array( $meta ) || ! self::guards( $user_id ) ) {
			return $meta;
		}
		foreach ( self::LOCKED_USER_META as $key ) {
			if ( array_key_exists( $key, $meta ) ) {
				$meta[ $key ] = (string) get_user_meta( $user_id, $key, true );
			}
		}
		return $meta;
	}

	/**
	 * `send_email_change_email` / `send_password_change_email`: a refused
	 * change is not a change, so nobody is told it happened.
	 *
	 * @param bool  $send     Whether to send.
	 * @param array $user     The user as it was.
	 * @param array $userdata The requested data.
	 * @return bool
	 */
	public static function filter_change_email( $send, $user = array(), $userdata = array() ) {
		$user_id = is_array( $user ) && isset( $user['ID'] ) ? (int) $user['ID'] : 0;
		return self::guards( $user_id ) ? false : $send;
	}

	/**
	 * `allow_password_reset`: a linked user's password is Cognito's.
	 *
	 * @param bool|\WP_Error $allow   Whether to allow.
	 * @param int            $user_id WordPress user ID.
	 * @return bool|\WP_Error
	 */
	public static function filter_allow_password_reset( $allow, $user_id = 0 ) {
		return self::guards( $user_id ) ? false : $allow;
	}

	/**
	 * `show_password_fields`: no password fields for a linked user.
	 *
	 * @param bool     $show        Whether to show.
	 * @param \WP_User $profileuser The user being edited.
	 * @return bool
	 */
	public static function filter_show_password_fields( $show, $profileuser = null ) {
		$user_id = is_object( $profileuser ) && isset( $profileuser->ID ) ? (int) $profileuser->ID : 0;
		return self::guards( $user_id ) ? false : $show;
	}

	/**
	 * Profile form save, before core reads it: put the stored values back for
	 * the locked fields and drop any new password. Without this core would
	 * email a confirmation for an address change that can never apply.
	 *
	 * @param int $user_id WordPress user ID being saved.
	 */
	public static function neutralise_profile_post( $user_id ) {
		if ( ! self::guards( $user_id ) ) {
			return;
		}
		$current = get_userdata( (int) $user_id );
		if ( ! $current ) {
			return;
		}
		foreach ( self::LOCKED_FORM_FIELDS as $field => $source ) {
			if ( ! array_key_exists( $field, $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verified the nonce before this action.
				continue;
			}
			$value           = in_array( $source, self::LOCKED_USER_META, true )
				? (string) get_user_meta( (int) $user_id, $source, true )
				: (string) $current->$source;
			$_POST[ $field ] = $value; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		unset( $_POST['pass1'], $_POST['pass2'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * A notice above the profile form, and the locked inputs made read-only.
	 *
	 * @param \WP_User $user The user being edited.
	 */
	public static function render_profile_notice( $user ) {
		if ( ! is_object( $user ) || ! self::is_locked( $user->ID ) ) {
			return;
		}
		?>
		<div class="notice notice-info inline">
			<p><?php esc_html_e( 'Name, email address and password for this account are managed in MyBosun and cannot be changed here. Changes made in MyBosun reach this site automatically.', 'wp-cognito-auth' ); ?></p>
		</div>
		<script>
			document.addEventListener( 'DOMContentLoaded', function () {
				[ 'email', 'first_name', 'last_name' ].forEach( function ( id ) {
					var el = document.getElementById( id );
					if ( el ) { el.readOnly = true; }
				} );
				var dn = document.getElementById( 'display_name' );
				if ( dn ) { dn.style.pointerEvents = 'none'; dn.setAttribute( 'aria-readonly', 'true' ); dn.tabIndex = -1; }
			} );
		</script>
		<?php
	}
}
