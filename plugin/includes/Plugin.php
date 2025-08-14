<?php
namespace WP_Cognito_Auth;

class Plugin {
	private $admin;
	private $api;
	private $auth;
	private $sync;
	private $user;
	private $content_filter;
	private $meta_box;

	public function init() {
		try {
			// Test if we can create the Admin class.
			if ( ! class_exists( 'WP_Cognito_Auth\Admin' ) ) {
				return;
			}

			// Load text domain.
			add_action( 'init', array( $this, 'load_textdomain' ) );

			// Initialize core components.
			$this->admin = new Admin();

			if ( ! $this->admin ) {
				return;
			}

			$this->user = new User();

			// Initialize meta box for content restrictions.
			if ( class_exists( 'WP_Cognito_Auth\MetaBox' ) ) {
				$this->meta_box = new MetaBox();
			}

			// Get enabled features.
			$features = get_option( 'wp_cognito_features', array() );

			// Initialize sync functionality if enabled.
			if ( ! empty( $features['sync'] ) ) {
				$this->api  = new API();
				$this->sync = new Sync( $this->api );

				// Set sync reference in user object after both are initialized.
				if ( $this->user && method_exists( $this->user, 'set_sync' ) ) {
					$this->user->set_sync( $this->sync );
				}
			}

			// Initialize authentication if enabled.
			if ( ! empty( $features['authentication'] ) ) {
				$this->auth = new Auth();

				// Initialize content filter for role-based restrictions.
				if ( class_exists( 'WP_Cognito_Auth\ContentFilter' ) ) {
					$this->content_filter = new ContentFilter();
				}
			}

			// Setup hooks.
			$this->setup_hooks();

		} catch ( \Exception $e ) {
			add_action(
				'admin_notices',
				function () use ( $e ) {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'WP Cognito Auth initialization error. Please check configuration.', 'wp-cognito-auth' );
					echo '</p></div>';
				}
			);
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain(
			'wp-cognito-auth',
			false,
			dirname( plugin_basename( WP_COGNITO_AUTH_PLUGIN_FILE ) ) . '/languages/'
		);
	}

	private function setup_hooks() {
		// Sync hooks (if sync is enabled)
		if ( $this->sync ) {
			add_action( 'user_register', array( $this->sync, 'on_user_create' ), 10, 1 );
			add_action( 'profile_update', array( $this->sync, 'on_user_update' ), 10, 2 );
			add_action( 'delete_user', array( $this->sync, 'on_user_delete' ), 10, 1 );
			add_action( 'set_user_role', array( $this->sync, 'on_user_role_change' ), 10, 3 );
			add_action( 'add_user_role', array( $this->sync, 'on_user_role_added' ), 10, 2 );
			add_action( 'remove_user_role', array( $this->sync, 'on_user_role_removed' ), 10, 2 );
		}

		// Authentication hooks (if auth is enabled)
		if ( $this->auth ) {
			add_action( 'wp_login', array( $this->user, 'handle_user_login' ), 10, 2 );
			add_action( 'init', array( $this, 'handle_cognito_requests' ) );
			add_filter( 'wp_logout_url', array( $this, 'modify_logout_url' ), 10, 2 );
		}

		// Admin hooks.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'wp_ajax_cognito_test_connection', array( $this->admin, 'ajax_test_connection' ) );
	}

	public function handle_cognito_requests() {
		// Handle Cognito login initiation.
		if ( isset( $_GET['cognito_login'] ) && $_GET['cognito_login'] === '1' ) {
			if ( ! $this->auth ) {
				wp_die( __( 'Cognito authentication is not enabled', 'wp-cognito-auth' ) );
			}

			$redirect_to = isset( $_GET['redirect_to'] ) ? $_GET['redirect_to'] : '';
			$this->auth->initiate_login( $redirect_to );
		}

		// Handle logout requests.
		if ( isset( $_GET['cognito_logout'] ) && $_GET['cognito_logout'] === '1' ) {
			if ( $this->auth ) {
				$this->auth->handle_logout();
			}
		}
	}

	public function modify_logout_url( $logout_url, $redirect ) {
		if ( ! $this->auth || ! is_user_logged_in() ) {
			return $logout_url;
		}

		$user_id         = get_current_user_id();
		$cognito_user_id = get_user_meta( $user_id, 'cognito_user_id', true );

		if ( ! empty( $cognito_user_id ) ) {
			return add_query_arg(
				array(
					'cognito_logout' => '1',
					'redirect_to'    => $redirect,
				),
				home_url( '/' )
			);
		}

		return $logout_url;
	}

	public function enqueue_admin_scripts( $hook ) {
		if ( strpos( $hook, 'cognito-auth' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'wp-cognito-auth-admin',
			WP_COGNITO_AUTH_PLUGIN_URL . 'assets/admin.css',
			array(),
			WP_COGNITO_AUTH_VERSION
		);

		wp_enqueue_script(
			'wp-cognito-auth-admin',
			WP_COGNITO_AUTH_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			WP_COGNITO_AUTH_VERSION,
			true
		);

		wp_localize_script(
			'wp-cognito-auth-admin',
			'wpCognitoAuth',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_cognito_auth_ajax' ),
			)
		);
	}
}
