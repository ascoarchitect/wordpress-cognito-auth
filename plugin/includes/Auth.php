<?php
namespace WP_Cognito_Auth;

class Auth {
	private $user_pool_id;
	private $client_id;
	private $client_secret;
	private $region;
	private $hosted_ui_domain;

	public function __construct() {
		$this->user_pool_id     = get_option( 'wp_cognito_auth_user_pool_id' );
		$this->client_id        = get_option( 'wp_cognito_auth_client_id' );
		$this->client_secret    = get_option( 'wp_cognito_auth_client_secret' );
		$this->region           = get_option( 'wp_cognito_auth_region' );
		$this->hosted_ui_domain = get_option( 'wp_cognito_auth_hosted_ui_domain' );

		add_action( 'init', array( $this, 'handle_cognito_callback' ) );
		add_action( 'init', array( $this, 'handle_cognito_login' ) );
		add_action( 'init', array( $this, 'handle_logout' ) );
		add_action( 'wp_login_form', array( $this, 'add_cognito_login_button' ) );
		add_action( 'login_form', array( $this, 'add_cognito_login_button' ) ); // Alternative hook
		add_action( 'login_init', array( $this, 'maybe_redirect_to_cognito' ) );
		add_action( 'login_init', array( $this, 'handle_logged_in_user_on_login_page' ) );
		add_action( 'login_footer', array( $this, 'add_cognito_login_fallback' ) ); // Fallback method
		add_filter( 'authenticate', array( $this, 'authenticate_cognito_user' ), 30, 3 );
		add_filter( 'logout_url', array( $this, 'modify_logout_url' ), 10, 2 );
		add_action( 'wp_logout', array( $this, 'handle_wp_logout' ) );
	}

	public function initiate_login( $redirect_to = '' ) {
		$params = array(
			'client_id'     => $this->client_id,
			'response_type' => 'code',
			'scope'         => 'openid email profile',
			'redirect_uri'  => $this->get_callback_url(),
			'state'         => wp_create_nonce( 'cognito_auth' ) . '|' . base64_encode( $redirect_to ),
		);

		$auth_url = "https://{$this->hosted_ui_domain}/oauth2/authorize?" . http_build_query( $params );
		wp_redirect( $auth_url );
		exit;
	}

	public function handle_cognito_login() {
		if ( ! isset( $_GET['cognito_login'] ) || $_GET['cognito_login'] !== '1' ) {
			return;
		}

		// Handle re-authentication - logout current user first
		if ( isset( $_GET['reauth'] ) && $_GET['reauth'] === '1' && is_user_logged_in() ) {
			wp_logout();
		}

		// Get redirect URL from query parameter or referer
		$redirect_to = '';
		if ( isset( $_GET['redirect_to'] ) ) {
			$redirect_to = urldecode( $_GET['redirect_to'] );
		} elseif ( isset( $_SERVER['HTTP_REFERER'] ) ) {
			$referer  = $_SERVER['HTTP_REFERER'];
			$site_url = site_url();
			if ( strpos( $referer, $site_url ) === 0 && strpos( $referer, 'wp-login.php' ) === false ) {
				$redirect_to = $referer;
			}
		}

		// If no specific redirect, leave empty (will be determined after login based on user role)
		if ( empty( $redirect_to ) ) {
			$redirect_to = '';
		}

		$this->initiate_login( $redirect_to );
	}

	public function handle_cognito_callback() {
		if ( ! isset( $_GET['cognito_callback'] ) || $_GET['cognito_callback'] !== '1' ) {
			return;
		}

		try {
			if ( ! isset( $_GET['code'] ) || ! isset( $_GET['state'] ) ) {
				wp_die( __( 'Missing authentication parameters', 'wp-cognito-auth' ) );
			}

			// Verify state parameter
			$state_parts = explode( '|', $_GET['state'], 2 );
			if ( ! wp_verify_nonce( $state_parts[0], 'cognito_auth' ) ) {
				wp_die( __( 'Invalid authentication state', 'wp-cognito-auth' ) );
			}

			$code = sanitize_text_field( $_GET['code'] );

			// Handle redirect URL from state parameter
			$redirect_to = '';
			if ( isset( $state_parts[1] ) && ! empty( $state_parts[1] ) ) {
				$decoded_redirect = base64_decode( $state_parts[1] );
				if ( ! empty( $decoded_redirect ) ) {
					// Validate that the redirect URL is from our site
					$parsed_url = parse_url( $decoded_redirect );
					$site_host  = parse_url( home_url(), PHP_URL_HOST );

					if ( $parsed_url && isset( $parsed_url['host'] ) && $parsed_url['host'] === $site_host ) {
						$redirect_to = $decoded_redirect;
					} elseif ( strpos( $decoded_redirect, '/' ) === 0 ) {
						// Relative URL, make it absolute
						$redirect_to = home_url( $decoded_redirect );
					}
				}
			}

			// If no valid redirect URL was preserved, determine appropriate default
			if ( empty( $redirect_to ) ) {
				// For users with admin capabilities, go to admin
				// For regular users, go to homepage
				$redirect_to = home_url(); // Default to homepage for all users
			}

			// Exchange code for tokens
			$tokens = $this->exchange_code_for_tokens( $code );
			if ( ! $tokens ) {
				wp_die( __( 'Failed to authenticate with Cognito. Please check your configuration and try again.', 'wp-cognito-auth' ) );
			}

			// Validate and decode ID token
			$user_data = $this->validate_and_decode_token( $tokens['id_token'] );
			if ( ! $user_data ) {
				wp_die( __( 'Invalid token received from Cognito. Please try again.', 'wp-cognito-auth' ) );
			}

			// Create or update WordPress user
			$wp_user = $this->create_or_update_wp_user( $user_data );
			if ( ! $wp_user ) {
				wp_die( __( 'Failed to create WordPress user. Please contact the administrator.', 'wp-cognito-auth' ) );
			}

			// Log the user in
			wp_set_current_user( $wp_user->ID );
			wp_set_auth_cookie( $wp_user->ID, true );
			do_action( 'wp_login', $wp_user->user_login, $wp_user );

			// Now that user is logged in, refine redirect logic based on user capabilities
			if ( empty( $redirect_to ) || $redirect_to === home_url() ) {
				// If we're using default redirect, check user capabilities
				if ( user_can( $wp_user->ID, 'edit_posts' ) || user_can( $wp_user->ID, 'manage_options' ) ) {
					// Admin users go to dashboard
					$redirect_to = admin_url();
				} else {
					// Regular users go to homepage
					$redirect_to = home_url();
				}
			}

			// Store redirect URL in session for fallback
			if ( ! session_id() ) {
				session_start();
			}
			$_SESSION['cognito_redirect_to'] = $redirect_to;

			// Try JavaScript redirect as a fallback
			?>
			<!DOCTYPE html>
			<html>
			<head>
				<title><?php _e( 'Login Successful', 'wp-cognito-auth' ); ?></title>
				<script>
					window.location.href = <?php echo json_encode( $redirect_to ); ?>;
				</script>
				<meta http-equiv="refresh" content="0;url=<?php echo esc_attr( $redirect_to ); ?>">
			</head>
			<body>
				<p><?php _e( 'Login successful! Redirecting...', 'wp-cognito-auth' ); ?></p>
				<p><a href="<?php echo esc_url( $redirect_to ); ?>"><?php _e( 'Click here if not redirected automatically', 'wp-cognito-auth' ); ?></a></p>
			</body>
			</html>
			<?php
			exit;

		} catch ( Exception $e ) {
			// Show a user-friendly error page
			wp_die(
				__( 'Authentication failed. Please try again.', 'wp-cognito-auth' ) .
				'<br><br><a href="' . esc_url( wp_login_url() ) . '">' . __( 'Try Again', 'wp-cognito-auth' ) . '</a>',
				__( 'Authentication Error', 'wp-cognito-auth' ),
				array( 'back_link' => true )
			);
		}
	}

	public function handle_logout() {
		if ( ! isset( $_GET['cognito_logout'] ) || $_GET['cognito_logout'] !== '1' ) {
			return;
		}

		$redirect_to = isset( $_GET['redirect_to'] ) ? $_GET['redirect_to'] : home_url();

		// Logout from WordPress first
		wp_logout();

		// Redirect to Cognito logout
		$logout_params = array(
			'client_id'  => $this->client_id,
			'logout_uri' => $redirect_to,
		);

		$logout_url = "https://{$this->hosted_ui_domain}/logout?" . http_build_query( $logout_params );
		wp_redirect( $logout_url );
		exit;
	}

	/**
	 * Modify the logout URL to include Cognito logout
	 */
	public function modify_logout_url( $logout_url, $redirect ) {
		if ( empty( $this->hosted_ui_domain ) || empty( $this->client_id ) ) {
			return $logout_url;
		}

		$args = array(
			'cognito_logout' => '1',
			'redirect_to'    => $redirect ? $redirect : home_url(),
		);

		return add_query_arg( $args, site_url( '/wp-login.php' ) );
	}

	/**
	 * Handle WordPress logout action
	 */
	public function handle_wp_logout() {
		// Clear any Cognito-specific session data
		if ( isset( $_SESSION ) ) {
			unset( $_SESSION['cognito_user_data'] );
			unset( $_SESSION['cognito_access_token'] );
		}
	}

	/**
	 * Maybe redirect to Cognito if force authentication is enabled
	 */
	public function maybe_redirect_to_cognito() {
		// Don't redirect if not on login page
		if ( ! isset( $GLOBALS['pagenow'] ) || $GLOBALS['pagenow'] !== 'wp-login.php' ) {
			return;
		}

		// Don't redirect if user is already logged in
		if ( is_user_logged_in() ) {
			return;
		}

		// Check if Cognito authentication is enabled and forced
		$features = get_option( 'wp_cognito_features', array() );
		if ( empty( $features['authentication'] ) || ! get_option( 'wp_cognito_auth_force_cognito', false ) ) {
			return;
		}

		// Allow emergency WordPress login with emergency access parameter
		$emergency_param = get_option( 'wp_cognito_emergency_access_param' );
		if ( $emergency_param && isset( $_GET[ $emergency_param ] ) ) {
			return;
		}

		// Don't redirect if this is a logout action
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'logout' ) {
			return;
		}

		// Don't redirect if this is already a Cognito login request
		if ( isset( $_GET['cognito_login'] ) && $_GET['cognito_login'] === '1' ) {
			return;
		}

		// Don't redirect if this is a Cognito callback
		if ( isset( $_GET['cognito_callback'] ) ) {
			return;
		}

		// Don't redirect if this is a password reset or other special action
		$allowed_actions = array( 'rp', 'resetpass', 'lostpassword', 'retrievepassword' );
		if ( isset( $_GET['action'] ) && in_array( $_GET['action'], $allowed_actions ) ) {
			return;
		}

		// Get redirect URL (where user wanted to go originally)
		$redirect_to = '';
		if ( isset( $_GET['redirect_to'] ) ) {
			$redirect_to = urldecode( $_GET['redirect_to'] );
		} elseif ( isset( $_POST['redirect_to'] ) ) {
			$redirect_to = urldecode( $_POST['redirect_to'] );
		} elseif ( isset( $_SERVER['HTTP_REFERER'] ) ) {
			// If no explicit redirect, try to use the referring page
			$referer  = $_SERVER['HTTP_REFERER'];
			$site_url = site_url();
			if ( strpos( $referer, $site_url ) === 0 && strpos( $referer, 'wp-login.php' ) === false ) {
				$redirect_to = $referer;
			}
		}

		// Validate redirect URL is from our site
		if ( ! empty( $redirect_to ) ) {
			$parsed_url = parse_url( $redirect_to );
			$site_host  = parse_url( home_url(), PHP_URL_HOST );

			if ( ! $parsed_url || ! isset( $parsed_url['host'] ) || $parsed_url['host'] !== $site_host ) {
				// If it's a relative URL, make it absolute
				if ( strpos( $redirect_to, '/' ) === 0 ) {
					$redirect_to = home_url( $redirect_to );
				} else {
					// Invalid redirect URL, clear it
					$redirect_to = '';
				}
			}
		}

		// If still no redirect, leave empty (will be determined after login based on user role)
		if ( empty( $redirect_to ) ) {
			$redirect_to = '';
		}

		// Initiate Cognito login
		$this->initiate_login( $redirect_to );
	}

	public function handle_logged_in_user_on_login_page() {
		// Don't redirect if not on login page
		if ( ! isset( $GLOBALS['pagenow'] ) || $GLOBALS['pagenow'] !== 'wp-login.php' ) {
			return;
		}

		// Only handle if user is already logged in
		if ( ! is_user_logged_in() ) {
			return;
		}

		// Don't redirect if this is a logout action
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'logout' ) {
			return;
		}

		// Don't redirect if this is a password reset or other special action
		$allowed_actions = array( 'rp', 'resetpass', 'lostpassword', 'retrievepassword', 'register' );
		if ( isset( $_GET['action'] ) && in_array( $_GET['action'], $allowed_actions ) ) {
			return;
		}

		// Don't redirect if this is a Cognito callback
		if ( isset( $_GET['cognito_callback'] ) ) {
			return;
		}

		// Get current user
		$current_user = wp_get_current_user();

		// Determine where to redirect based on redirect_to parameter or user capabilities
		$redirect_to = '';
		if ( isset( $_GET['redirect_to'] ) && ! empty( $_GET['redirect_to'] ) ) {
			$redirect_to = urldecode( $_GET['redirect_to'] );

			// Validate redirect URL is from our site
			$parsed_url = parse_url( $redirect_to );
			$site_host  = parse_url( home_url(), PHP_URL_HOST );

			if ( ! $parsed_url || ! isset( $parsed_url['host'] ) || $parsed_url['host'] !== $site_host ) {
				// If it's a relative URL, make it absolute
				if ( strpos( $redirect_to, '/' ) === 0 ) {
					$redirect_to = home_url( $redirect_to );
				} else {
					// Invalid redirect URL, use default
					$redirect_to = '';
				}
			}
		}

		// If no valid redirect URL, determine based on user capabilities
		if ( empty( $redirect_to ) ) {
			if ( user_can( $current_user->ID, 'edit_posts' ) || user_can( $current_user->ID, 'manage_options' ) ) {
				// Admin users go to dashboard
				$redirect_to = admin_url();
			} else {
				// Regular users go to homepage
				$redirect_to = home_url();
			}
		}

		// Redirect the already logged-in user
		wp_safe_redirect( $redirect_to );
		exit;
	}

	private function exchange_code_for_tokens( $code ) {
		$token_url = "https://{$this->hosted_ui_domain}/oauth2/token";

		$body = array(
			'grant_type'    => 'authorization_code',
			'client_id'     => $this->client_id,
			'client_secret' => $this->client_secret,
			'code'          => $code,
			'redirect_uri'  => $this->get_callback_url(),
		);

		$response = wp_remote_post(
			$token_url,
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => http_build_query( $body ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = wp_remote_retrieve_body( $response );
		$data          = json_decode( $body, true );

		if ( $response_code !== 200 || ! isset( $data['access_token'] ) ) {
			return false;
		}

		return $data;
	}

	private function validate_and_decode_token( $id_token ) {
		$jwks = $this->get_cognito_jwks();
		if ( ! $jwks ) {
			return false;
		}

		try {
			$decoded = JWT::decode( $id_token, $jwks );
			// Convert object to array if needed
			return is_array( $decoded ) ? $decoded : (array) $decoded;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	private function create_or_update_wp_user( $cognito_user_data ) {
		$email       = $cognito_user_data['email'];
		$cognito_sub = $cognito_user_data['sub'];

		// Check if user exists by Cognito sub
		$existing_user = get_users(
			array(
				'meta_key'   => 'cognito_user_id',
				'meta_value' => $cognito_sub,
				'number'     => 1,
			)
		);

		if ( ! empty( $existing_user ) ) {
			$user = $existing_user[0];
			$this->update_user_from_cognito( $user->ID, $cognito_user_data );
			return $user;
		}

		// Check if user exists by email
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			update_user_meta( $user->ID, 'cognito_user_id', $cognito_sub );
			$this->update_user_from_cognito( $user->ID, $cognito_user_data );
			return $user;
		}

		// Create new user if auto-creation is enabled
		if ( ! get_option( 'wp_cognito_auth_auto_create_users', true ) ) {
			return false;
		}

		$username = $this->generate_username( $email, $cognito_user_data );
		$user_id  = wp_create_user( $username, wp_generate_password(), $email );

		if ( is_wp_error( $user_id ) ) {
			return false;
		}

		// Set default role
		$default_role = get_option( 'wp_cognito_auth_default_role', 'subscriber' );
		$user         = new \WP_User( $user_id );
		$user->set_role( $default_role );

		// Store Cognito ID and update user data
		update_user_meta( $user_id, 'cognito_user_id', $cognito_sub );
		$this->update_user_from_cognito( $user_id, $cognito_user_data );

		return get_user_by( 'id', $user_id );
	}

	private function update_user_from_cognito( $user_id, $cognito_data ) {
		$updates = array();

		// Handle first name and last name with intelligent fallback
		$first_name = null;
		$last_name  = null;

		// Priority 1: Use given_name and family_name if available
		if ( isset( $cognito_data['given_name'] ) && ! empty( $cognito_data['given_name'] ) ) {
			$first_name = $cognito_data['given_name'];
		}
		if ( isset( $cognito_data['family_name'] ) && ! empty( $cognito_data['family_name'] ) ) {
			$last_name = $cognito_data['family_name'];
		}

		// Priority 2: If given_name/family_name not available, try custom fields
		if ( ! $first_name && isset( $cognito_data['custom:first_name'] ) ) {
			$first_name = $cognito_data['custom:first_name'];
		}
		if ( ! $last_name && isset( $cognito_data['custom:last_name'] ) ) {
			$last_name = $cognito_data['custom:last_name'];
		}

		// Priority 3: If still no first/last name, intelligently split the 'name' field
		if ( ( ! $first_name || ! $last_name ) && isset( $cognito_data['name'] ) && ! empty( $cognito_data['name'] ) ) {
			$full_name  = trim( $cognito_data['name'] );
			$name_parts = explode( ' ', $full_name, 2 ); // Split into max 2 parts

			if ( ! $first_name ) {
				$first_name = $name_parts[0];
			}
			if ( ! $last_name && count( $name_parts ) > 1 ) {
				$last_name = $name_parts[1];
			}
		}

		// Update WordPress user meta with names
		if ( $first_name ) {
			update_user_meta( $user_id, 'first_name', $first_name );
		}

		if ( $last_name ) {
			update_user_meta( $user_id, 'last_name', $last_name );
		}

		// Update display name - priority: existing 'name' field, then constructed from first/last
		if ( isset( $cognito_data['name'] ) && ! empty( $cognito_data['name'] ) ) {
			$updates['display_name'] = $cognito_data['name'];
		} elseif ( $first_name && $last_name ) {
			$updates['display_name'] = trim( $first_name . ' ' . $last_name );
		} elseif ( $first_name ) {
			$updates['display_name'] = $first_name;
		}

		// Store Cognito groups for content restriction
		if ( isset( $cognito_data['cognito:groups'] ) ) {
			update_user_meta( $user_id, 'cognito_groups', $cognito_data['cognito:groups'] );
		}

		// Update custom attributes
		if ( isset( $cognito_data['custom:wp_memberrank'] ) ) {
			update_user_meta( $user_id, 'wpuef_cid_c6', $cognito_data['custom:wp_memberrank'] );
		}

		if ( isset( $cognito_data['custom:wp_membercategory'] ) ) {
			update_user_meta( $user_id, 'wpuef_cid_c10', $cognito_data['custom:wp_membercategory'] );
		}

		if ( ! empty( $updates ) ) {
			$updates['ID'] = $user_id;
			wp_update_user( $updates );
		}

		// Handle group memberships
		$this->sync_user_groups_from_cognito( $user_id, $cognito_data );
	}

	private function sync_user_groups_from_cognito( $user_id, $cognito_data ) {
		$cognito_groups = isset( $cognito_data['cognito:groups'] ) ? $cognito_data['cognito:groups'] : array();

		$user          = new \WP_User( $user_id );
		$current_roles = $user->roles;
		$synced_groups = get_option( 'wp_cognito_sync_groups', array() );

		foreach ( $synced_groups as $wp_role ) {
			$cognito_group_name = "WP_{$wp_role}";

			if ( in_array( $cognito_group_name, $cognito_groups ) ) {
				if ( ! in_array( $wp_role, $current_roles ) ) {
					$user->add_role( $wp_role );
				}
			} elseif ( in_array( $wp_role, $current_roles ) ) {
					$user->remove_role( $wp_role );
			}
		}
	}

	public function add_cognito_login_button() {
		// Only show on login page and when not logged in
		if ( is_user_logged_in() ) {
			return;
		}

		$features = get_option( 'wp_cognito_features', array() );
		if ( empty( $features['authentication'] ) ) {
			return;
		}

		// Don't show button if force mode is enabled (since it auto-redirects)
		if ( get_option( 'wp_cognito_auth_force_cognito', false ) ) {
			return;
		}

		$login_url = add_query_arg( 'cognito_login', '1', wp_login_url() );

		// Preserve redirect_to parameter if present
		if ( isset( $_GET['redirect_to'] ) ) {
			$login_url = add_query_arg( 'redirect_to', urlencode( $_GET['redirect_to'] ), $login_url );
		}

		// Get customizable options
		$button_text  = get_option( 'wp_cognito_auth_login_button_text', 'Login with Cognito' );
		$button_color = get_option( 'wp_cognito_auth_login_button_color', '#ff9900' );
		$text_color   = get_option( 'wp_cognito_auth_login_button_text_color', '#ffffff' );

		// Calculate hover color (darker version)
		$hover_color = $this->darken_hex_color( $button_color, 20 );
		?>
		<div class="cognito-login-section">
			<p style="text-align: center; margin: 20px 0;">
				<a href="<?php echo esc_url( $login_url ); ?>" 
					class="button button-large wp-cognito-login"
					style="width: 100%; text-align: center; background-color: <?php echo esc_attr( $button_color ); ?>; border-color: <?php echo esc_attr( $button_color ); ?>; color: <?php echo esc_attr( $text_color ); ?>; text-decoration: none; display: inline-block; padding: 10px;">
					<?php echo esc_html( $button_text ); ?>
				</a>
			</p>
			<div style="text-align: center; margin: 10px 0;">
				<span style="color: #666;">— <?php _e( 'or', 'wp-cognito-auth' ); ?> —</span>
			</div>
			<style>
				.wp-cognito-login:hover {
					background-color: <?php echo esc_attr( $hover_color ); ?> !important;
					border-color: <?php echo esc_attr( $hover_color ); ?> !important;
					color: <?php echo esc_attr( $text_color ); ?> !important;
				}
				.cognito-login-section {
					margin: 20px 0;
				}
			</style>
		</div>
		<?php
	}

	public function add_cognito_login_fallback() {
		// Only show on login page
		if ( ! isset( $GLOBALS['pagenow'] ) || $GLOBALS['pagenow'] !== 'wp-login.php' ) {
			return;
		}

		// Don't show if user is already logged in
		if ( is_user_logged_in() ) {
			return;
		}

		$features = get_option( 'wp_cognito_features', array() );
		if ( empty( $features['authentication'] ) ) {
			return;
		}

		// Don't show button if force mode is enabled (since it auto-redirects)
		if ( get_option( 'wp_cognito_auth_force_cognito', false ) ) {
			return;
		}

		$login_url = add_query_arg( 'cognito_login', '1', wp_login_url() );

		// Preserve redirect_to parameter if present
		if ( isset( $_GET['redirect_to'] ) ) {
			$login_url = add_query_arg( 'redirect_to', urlencode( $_GET['redirect_to'] ), $login_url );
		}

		// Get customizable options
		$button_text  = get_option( 'wp_cognito_auth_login_button_text', 'Login with Cognito' );
		$button_color = get_option( 'wp_cognito_auth_login_button_color', '#ff9900' );
		$text_color   = get_option( 'wp_cognito_auth_login_button_text_color', '#ffffff' );
		$hover_color  = $this->darken_hex_color( $button_color, 20 );
		?>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			// Check if Cognito button already exists
			if ($('.wp-cognito-login').length > 0) {
				return; // Button already added by hook
			}
			
			// Find the login form
			var $form = $('#loginform');
			if ($form.length === 0) {
				$form = $('form[name="loginform"]');
			}
			
			if ($form.length > 0) {
				// Create the Cognito login button
				var cognitoButton = '<div class="cognito-login-section" style="margin: 20px 0;">' +
					'<p style="text-align: center; margin: 20px 0;">' +
					'<a href="<?php echo esc_js( $login_url ); ?>" class="button button-large wp-cognito-login" ' +
					'style="width: 100%; text-align: center; background-color: <?php echo esc_js( $button_color ); ?>; border-color: <?php echo esc_js( $button_color ); ?>; color: <?php echo esc_js( $text_color ); ?>; text-decoration: none; display: inline-block; padding: 10px;">' +
					'<?php echo esc_js( $button_text ); ?>' +
					'</a>' +
					'</p>' +
					'<div style="text-align: center; margin: 10px 0;">' +
					'<span style="color: #666;">— <?php _e( 'or', 'wp-cognito-auth' ); ?> —</span>' +
					'</div>' +
					'</div>';
				
				// Insert before the form
				$form.before(cognitoButton);
				
				// Add hover effects
				$('.wp-cognito-login').hover(
					function() {
						$(this).css({
							'background-color': '<?php echo esc_js( $hover_color ); ?>',
							'border-color': '<?php echo esc_js( $hover_color ); ?>'
						});
					},
					function() {
						$(this).css({
							'background-color': '<?php echo esc_js( $button_color ); ?>',
							'border-color': '<?php echo esc_js( $button_color ); ?>'
						});
					}
				);
			}
		});
		</script>
		<?php
	}

	/**
	 * Helper function to darken a hex color by a percentage
	 */
	private function darken_hex_color( $hex, $percent ) {
		// Remove # if present
		$hex = str_replace( '#', '', $hex );

		// Validate hex color
		if ( strlen( $hex ) !== 6 ) {
			return '#ff9900'; // Return default if invalid
		}

		// Parse RGB values
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );

		// Darken by percentage
		$factor = ( 100 - $percent ) / 100;
		$r      = max( 0, min( 255, floor( $r * $factor ) ) );
		$g      = max( 0, min( 255, floor( $g * $factor ) ) );
		$b      = max( 0, min( 255, floor( $b * $factor ) ) );

		// Convert back to hex
		return sprintf( '#%02x%02x%02x', $r, $g, $b );
	}

	public function authenticate_cognito_user( $user, $username, $password ) {
		if ( is_a( $user, 'WP_User' ) ) {
			return $user;
		}
		return $user;
	}

	private function get_cognito_jwks() {
		$cache_key   = 'cognito_jwks_' . md5( $this->user_pool_id );
		$cached_jwks = get_transient( $cache_key );

		if ( $cached_jwks !== false ) {
			return $cached_jwks;
		}

		$jwks_url = "https://cognito-idp.{$this->region}.amazonaws.com/{$this->user_pool_id}/.well-known/jwks.json";

		$response = wp_remote_get( $jwks_url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$jwks = json_decode( $body, true );

		if ( ! $jwks || ! isset( $jwks['keys'] ) ) {
			return false;
		}

		set_transient( $cache_key, $jwks, HOUR_IN_SECONDS );
		return $jwks;
	}

	private function get_callback_url() {
		return add_query_arg( 'cognito_callback', '1', home_url( '/wp-login.php' ) );
	}

	private function generate_username( $email, $cognito_data ) {
		$username = isset( $cognito_data['preferred_username'] )
			? $cognito_data['preferred_username']
			: explode( '@', $email )[0];

		$original_username = $username;
		$counter           = 1;

		while ( username_exists( $username ) ) {
			$username = $original_username . $counter;
			++$counter;
		}

		return $username;
	}
}