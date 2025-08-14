<?php
/**
 * Admin interface and settings management for Cognito authentication
 *
 * @package WP_Cognito_Auth
 */

namespace WP_Cognito_Auth;

/**
 * Class Admin
 *
 * Handles WordPress admin interface, settings pages, and administrative functionality.
 */
class Admin {
	private $api;

	/**
	 * Constructor - Set up admin hooks
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_cognito_bulk_sync', array( $this, 'handle_bulk_sync' ) );
		add_action( 'admin_post_cognito_test_sync', array( $this, 'handle_test_sync' ) );
		add_action( 'admin_post_cognito_sync_test_form', array( $this, 'handle_sync_test_form' ) );
		add_action( 'admin_post_cognito_toggle_group_sync', array( $this, 'handle_toggle_group_sync' ) );
		add_action( 'admin_post_cognito_test_group_sync', array( $this, 'handle_test_group_sync' ) );
		add_action( 'admin_post_cognito_full_group_sync', array( $this, 'handle_full_group_sync' ) );
		add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
		add_action( 'admin_notices', array( $this, 'show_cognito_setup_notices' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_cognito_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_cognito_test_sync_connection', array( $this, 'ajax_test_sync_connection' ) );
		add_action( 'wp_ajax_cognito_test_wp_http', array( $this, 'ajax_test_wp_http' ) );
	}

	/**
	 * Add admin menu pages
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'Cognito Auth', 'wp-cognito-auth' ),
			__( 'Cognito Auth', 'wp-cognito-auth' ),
			'manage_options',
			'wp-cognito-auth',
			array( $this, 'render_main_page' ),
			'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#a0a5aa" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>' ),
			30
		);

		// Settings (Features tab)
		add_submenu_page(
			'wp-cognito-auth',
			__( 'Settings', 'wp-cognito-auth' ),
			__( 'Settings', 'wp-cognito-auth' ),
			'manage_options',
			'wp-cognito-auth',
			array( $this, 'render_main_page' )
		);

		// Authentication Settings.
		add_submenu_page(
			'wp-cognito-auth',
			__( 'Authentication', 'wp-cognito-auth' ),
			__( 'Authentication', 'wp-cognito-auth' ),
			'manage_options',
			'wp-cognito-auth&tab=authentication',
			array( $this, 'render_main_page' )
		);

		// Sync Settings.
		add_submenu_page(
			'wp-cognito-auth',
			__( 'Sync Settings', 'wp-cognito-auth' ),
			__( 'Sync Settings', 'wp-cognito-auth' ),
			'manage_options',
			'wp-cognito-auth&tab=sync',
			array( $this, 'render_main_page' )
		);

		// Bulk Sync.
		add_submenu_page(
			'wp-cognito-auth',
			__( 'Bulk Sync', 'wp-cognito-auth' ),
			__( 'Bulk Sync', 'wp-cognito-auth' ),
			'manage_options',
			'wp-cognito-auth&tab=bulk-sync',
			array( $this, 'render_main_page' )
		);

		// Group Management.
		add_submenu_page(
			'wp-cognito-auth',
			__( 'Group Management', 'wp-cognito-auth' ),
			__( 'Group Management', 'wp-cognito-auth' ),
			'manage_options',
			'wp-cognito-auth&tab=groups',
			array( $this, 'render_main_page' )
		);

		// Logs.
		add_submenu_page(
			'wp-cognito-auth',
			__( 'Logs', 'wp-cognito-auth' ),
			__( 'Logs', 'wp-cognito-auth' ),
			'manage_options',
			'wp-cognito-auth&tab=logs',
			array( $this, 'render_main_page' )
		);

		// Setup Guide.
		add_submenu_page(
			'wp-cognito-auth',
			__( 'Setup Guide', 'wp-cognito-auth' ),
			__( 'Setup Guide', 'wp-cognito-auth' ),
			'manage_options',
			'wp-cognito-auth&tab=help',
			array( $this, 'render_main_page' )
		);
	}

	/**
	 * Register plugin settings with WordPress
	 */
	public function register_settings() {
		// Feature toggles.
		register_setting(
			'wp_cognito_auth_features',
			'wp_cognito_features',
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize_features' ),
			)
		);

		// Authentication settings.
		register_setting( 'wp_cognito_auth_settings', 'wp_cognito_auth_user_pool_id' );
		register_setting( 'wp_cognito_auth_settings', 'wp_cognito_auth_client_id' );
		register_setting( 'wp_cognito_auth_settings', 'wp_cognito_auth_client_secret' );
		register_setting( 'wp_cognito_auth_settings', 'wp_cognito_auth_region' );
		register_setting( 'wp_cognito_auth_settings', 'wp_cognito_auth_hosted_ui_domain' );
		register_setting( 'wp_cognito_auth_settings', 'wp_cognito_auth_auto_create_users' );
		register_setting( 'wp_cognito_auth_settings', 'wp_cognito_auth_default_role' );
		register_setting( 'wp_cognito_auth_settings', 'wp_cognito_auth_force_cognito' );
		register_setting( 'wp_cognito_auth_settings', 'wp_cognito_auth_login_button_text' );
		register_setting( 'wp_cognito_auth_settings', 'wp_cognito_auth_login_button_color' );
		register_setting( 'wp_cognito_auth_settings', 'wp_cognito_auth_login_button_text_color' );

		// Sync settings.
		register_setting( 'wp_cognito_sync_settings', 'wp_cognito_sync_api_url' );
		register_setting( 'wp_cognito_sync_settings', 'wp_cognito_sync_api_key' );
		register_setting( 'wp_cognito_sync_settings', 'wp_cognito_sync_on_login' );
		register_setting( 'wp_cognito_sync_settings', 'wp_cognito_sync_groups' );
	}

	/**
	 * Sanitize features settings input
	 *
	 * @param array $features Raw features data.
	 * @return array Sanitized settings.
	 */
	public function sanitize_features( $features ) {
		if ( ! is_array( $features ) ) {
			return array();
		}

		$allowed_features = array( 'authentication', 'sync', 'group_sync' );
		return array_intersect_key( $features, array_flip( $allowed_features ) );
	}

	public function render_main_page() {
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'features';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cognito Authentication & Sync', 'wp-cognito-auth' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<a href="?page=wp-cognito-auth&tab=features"
					class="nav-tab <?php echo $active_tab === 'features' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Features', 'wp-cognito-auth' ); ?>
				</a>
				<a href="?page=wp-cognito-auth&tab=authentication"
					class="nav-tab <?php echo $active_tab === 'authentication' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Authentication Settings', 'wp-cognito-auth' ); ?>
				</a>
				<a href="?page=wp-cognito-auth&tab=sync"
					class="nav-tab <?php echo $active_tab === 'sync' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Sync Settings', 'wp-cognito-auth' ); ?>
				</a>
				<a href="?page=wp-cognito-auth&tab=bulk-sync"
					class="nav-tab <?php echo $active_tab === 'bulk-sync' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Bulk Sync', 'wp-cognito-auth' ); ?>
				</a>
				<a href="?page=wp-cognito-auth&tab=groups"
					class="nav-tab <?php echo $active_tab === 'groups' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Group Management', 'wp-cognito-auth' ); ?>
				</a>
				<a href="?page=wp-cognito-auth&tab=logs"
					class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Logs', 'wp-cognito-auth' ); ?>
				</a>
				<a href="?page=wp-cognito-auth&tab=help"
					class="nav-tab <?php echo $active_tab === 'help' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Setup Guide', 'wp-cognito-auth' ); ?>
				</a>
			</h2>

			<div class="tab-content">
				<?php
				switch ( $active_tab ) {
					case 'authentication':
						$this->render_authentication_tab();
						break;
					case 'sync':
						$this->render_sync_settings_tab();
						break;
					case 'bulk-sync':
						$this->render_bulk_sync_tab();
						break;
					case 'groups':
						$this->render_groups_tab();
						break;
					case 'logs':
						$this->render_logs_page();
						break;
					case 'help':
						$this->render_help_tab();
						break;
					default:
						$this->render_features_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	private function render_features_tab() {
		$features = get_option( 'wp_cognito_features', array() );
		?>
		<div class="cognito-features">                <div class="cognito-card">
					<h2><?php esc_html_e( 'Enable Features', 'wp-cognito-auth' ); ?></h2>
					<p><?php esc_html_e( 'Choose which Cognito features to enable for your site. You must enable Authentication first to configure Cognito settings.', 'wp-cognito-auth' ); ?></p>

					<form method="post" action="options.php">
						<?php settings_fields( 'wp_cognito_auth_features' ); ?>

						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Authentication', 'wp-cognito-auth' ); ?></th>
								<td>
									<label>
										<input type="checkbox"
												name="wp_cognito_features[authentication]"
												value="1"
												<?php checked( ! empty( $features['authentication'] ) ); ?>>
										<strong><?php esc_html_e( 'Enable Cognito Authentication (JWT/OIDC)', 'wp-cognito-auth' ); ?></strong>
									</label>
									<p class="description">
										<?php esc_html_e( 'Allows users to login using Amazon Cognito hosted UI. Enable this first to configure Cognito settings.', 'wp-cognito-auth' ); ?>
									</p>
								</td>
							</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'User Sync', 'wp-cognito-auth' ); ?></th>
							<td>
								<label>
									<input type="checkbox"
											name="wp_cognito_features[sync]"
											value="1"
											<?php checked( ! empty( $features['sync'] ) ); ?>>
									<?php esc_html_e( 'Enable User Synchronization', 'wp-cognito-auth' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Sync WordPress users with Cognito user pool', 'wp-cognito-auth' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Group Sync', 'wp-cognito-auth' ); ?></th>
							<td>
								<label>
									<input type="checkbox"
											name="wp_cognito_features[group_sync]"
											value="1"
											<?php checked( ! empty( $features['group_sync'] ) ); ?>>
									<?php esc_html_e( 'Enable Group/Role Synchronization', 'wp-cognito-auth' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Sync WordPress roles with Cognito groups', 'wp-cognito-auth' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<?php submit_button(); ?>
				</form>
			</div>
		</div>
		<?php
	}

	private function render_authentication_tab() {
		$features = get_option( 'wp_cognito_features', array() );
		if ( empty( $features['authentication'] ) ) {
			echo '<div class="notice notice-warning"><p>' .
				__( 'Authentication feature is not enabled. Please enable it in the Features tab first.', 'wp-cognito-auth' ) .
				'</p></div>';
			return;
		}
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'wp_cognito_auth_settings' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="wp_cognito_auth_user_pool_id"><?php esc_html_e( 'User Pool ID', 'wp-cognito-auth' ); ?></label>
					</th>
					<td>
						<input type="text"
								name="wp_cognito_auth_user_pool_id"
								id="wp_cognito_auth_user_pool_id"
								value="<?php echo esc_attr( get_option( 'wp_cognito_auth_user_pool_id' ) ); ?>"
								class="regular-text"
								placeholder="us-east-1_xxxxxxxxx">
						<p class="description"><?php esc_html_e( 'Your Cognito User Pool ID', 'wp-cognito-auth' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wp_cognito_auth_client_id"><?php esc_html_e( 'App Client ID', 'wp-cognito-auth' ); ?></label>
					</th>
					<td>
						<input type="text"
								name="wp_cognito_auth_client_id"
								id="wp_cognito_auth_client_id"
								value="<?php echo esc_attr( get_option( 'wp_cognito_auth_client_id' ) ); ?>"
								class="regular-text">
						<p class="description"><?php esc_html_e( 'OAuth App Client ID', 'wp-cognito-auth' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wp_cognito_auth_client_secret"><?php esc_html_e( 'App Client Secret', 'wp-cognito-auth' ); ?></label>
					</th>
					<td>
						<input type="password"
								name="wp_cognito_auth_client_secret"
								id="wp_cognito_auth_client_secret"
								value="<?php echo esc_attr( get_option( 'wp_cognito_auth_client_secret' ) ); ?>"
								class="regular-text">
						<p class="description"><?php esc_html_e( 'OAuth App Client Secret', 'wp-cognito-auth' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wp_cognito_auth_region"><?php esc_html_e( 'AWS Region', 'wp-cognito-auth' ); ?></label>
					</th>
					<td>
						<select name="wp_cognito_auth_region" id="wp_cognito_auth_region">
							<?php
							$current_region = get_option( 'wp_cognito_auth_region', 'us-east-1' );
							$regions        = array(
								'us-east-1'      => 'US East (N. Virginia)',
								'us-east-2'      => 'US East (Ohio)',
								'us-west-1'      => 'US West (N. California)',
								'us-west-2'      => 'US West (Oregon)',
								'eu-west-1'      => 'Europe (Ireland)',
								'eu-west-2'      => 'Europe (London)',
								'eu-central-1'   => 'Europe (Frankfurt)',
								'ap-south-1'     => 'Asia Pacific (Mumbai)',
								'ap-southeast-1' => 'Asia Pacific (Singapore)',
								'ap-southeast-2' => 'Asia Pacific (Sydney)',
								'ap-northeast-1' => 'Asia Pacific (Tokyo)',
							);
							foreach ( $regions as $code => $name ) {
								echo '<option value="' . esc_attr( $code ) . '"' . selected( $current_region, $code, false ) . '>' . esc_html( $name ) . '</option>';
							}
							?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wp_cognito_auth_hosted_ui_domain"><?php esc_html_e( 'Hosted UI Domain', 'wp-cognito-auth' ); ?></label>
					</th>
					<td>
						<input type="text"
								name="wp_cognito_auth_hosted_ui_domain"
								id="wp_cognito_auth_hosted_ui_domain"
								value="<?php echo esc_attr( get_option( 'wp_cognito_auth_hosted_ui_domain' ) ); ?>"
								class="regular-text"
								placeholder="your-domain.auth.us-east-1.amazoncognito.com">
						<p class="description"><?php esc_html_e( 'Cognito Hosted UI domain (without https://)', 'wp-cognito-auth' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'User Creation', 'wp-cognito-auth' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
									name="wp_cognito_auth_auto_create_users"
									value="1"
									<?php checked( get_option( 'wp_cognito_auth_auto_create_users', true ) ); ?>>
							<?php esc_html_e( 'Auto-create WordPress users from Cognito', 'wp-cognito-auth' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Automatically create WordPress accounts for new Cognito users', 'wp-cognito-auth' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wp_cognito_auth_default_role"><?php esc_html_e( 'Default Role', 'wp-cognito-auth' ); ?></label>
					</th>
					<td>
						<select name="wp_cognito_auth_default_role" id="wp_cognito_auth_default_role">
							<?php
							$current_role = get_option( 'wp_cognito_auth_default_role', 'subscriber' );
							$roles        = get_editable_roles();
							foreach ( $roles as $role_name => $role_info ) {
								echo '<option value="' . esc_attr( $role_name ) . '"' . selected( $current_role, $role_name, false ) . '>' . esc_html( $role_info['name'] ) . '</option>';
							}
							?>
						</select>
						<p class="description"><?php esc_html_e( 'Default role for new users created via Cognito', 'wp-cognito-auth' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Login Behavior', 'wp-cognito-auth' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
									name="wp_cognito_auth_force_cognito"
									value="1"
									<?php checked( get_option( 'wp_cognito_auth_force_cognito', false ) ); ?>>
							<?php esc_html_e( 'Force Cognito authentication (skip WordPress login form)', 'wp-cognito-auth' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When enabled, users will be automatically redirected to Cognito for authentication instead of seeing the WordPress login form. The WordPress login form will still be accessible via the emergency access parameter shown below.', 'wp-cognito-auth' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wp_cognito_auth_login_button_text"><?php esc_html_e( 'Login Button Text', 'wp-cognito-auth' ); ?></label>
					</th>
					<td>
						<input type="text"
								name="wp_cognito_auth_login_button_text"
								id="wp_cognito_auth_login_button_text"
								value="<?php echo esc_attr( get_option( 'wp_cognito_auth_login_button_text', 'Login with Cognito' ) ); ?>"
								class="regular-text"
								placeholder="Login with Cognito">
						<p class="description"><?php esc_html_e( 'Customize the text displayed on the Cognito login button (e.g., "Member Login", "RAFSA Login")', 'wp-cognito-auth' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wp_cognito_auth_login_button_color"><?php esc_html_e( 'Login Button Color', 'wp-cognito-auth' ); ?></label>
					</th>
					<td>
						<input type="color"
								name="wp_cognito_auth_login_button_color"
								id="wp_cognito_auth_login_button_color"
								value="<?php echo esc_attr( get_option( 'wp_cognito_auth_login_button_color', '#ff9900' ) ); ?>"
								class="color-picker"
								style="width: 100px;">
						<input type="text"
								id="wp_cognito_auth_login_button_color_text"
								value="<?php echo esc_attr( get_option( 'wp_cognito_auth_login_button_color', '#ff9900' ) ); ?>"
								class="regular-text color-text-field"
								placeholder="#ff9900"
								readonly
								style="margin-left: 10px; width: 100px;">
						<p class="description"><?php esc_html_e( 'Choose a color for the Cognito login button to match your site\'s branding', 'wp-cognito-auth' ); ?></p>

						<div class="cognito-button-customization">
							<h4><?php esc_html_e( 'Preview', 'wp-cognito-auth' ); ?></h4>
							<button type="button" class="cognito-login-preview" id="login-button-preview" style="background-color: <?php echo esc_attr( get_option( 'wp_cognito_auth_login_button_color', '#ff9900' ) ); ?> !important; border-color: <?php echo esc_attr( get_option( 'wp_cognito_auth_login_button_color', '#ff9900' ) ); ?> !important; color: <?php echo esc_attr( get_option( 'wp_cognito_auth_login_button_text_color', '#ffffff' ) ); ?> !important; padding: 10px 20px !important; border: 1px solid <?php echo esc_attr( get_option( 'wp_cognito_auth_login_button_color', '#ff9900' ) ); ?> !important; border-radius: 3px !important; text-decoration: none !important; display: inline-block !important; font-size: 14px !important; font-weight: normal !important; text-shadow: none !important; box-shadow: none !important; line-height: normal !important; min-height: auto !important; text-align: center !important; cursor: pointer;">
								<?php echo esc_html( get_option( 'wp_cognito_auth_login_button_text', 'Login with Cognito' ) ); ?>
							</button>
							<p class="description" style="margin-bottom: 0;">
								<?php esc_html_e( 'This preview updates in real-time as you change the settings above.', 'wp-cognito-auth' ); ?>
							</p>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wp_cognito_auth_login_button_text_color"><?php esc_html_e( 'Login Button Text Color', 'wp-cognito-auth' ); ?></label>
					</th>
					<td>
						<input type="color"
								name="wp_cognito_auth_login_button_text_color"
								id="wp_cognito_auth_login_button_text_color"
								value="<?php echo esc_attr( get_option( 'wp_cognito_auth_login_button_text_color', '#ffffff' ) ); ?>"
								class="color-picker"
								style="width: 100px;">
						<input type="text"
								id="wp_cognito_auth_login_button_text_color_text"
								value="<?php echo esc_attr( get_option( 'wp_cognito_auth_login_button_text_color', '#ffffff' ) ); ?>"
								class="regular-text color-text-field"
								placeholder="#ffffff"
								readonly
								style="margin-left: 10px; width: 100px;">
						<p class="description"><?php esc_html_e( 'Choose the text color for the login button (white works well with most background colors)', 'wp-cognito-auth' ); ?></p>
					</td>
				</tr>
			</table>

			<div class="cognito-emergency-access-info" style="background: #fff2cc; border: 1px solid #ffd966; padding: 15px; margin: 20px 0; border-radius: 5px;">
				<h3><?php esc_html_e( 'Emergency WordPress Access', 'wp-cognito-auth' ); ?></h3>
				<p><?php esc_html_e( 'If Force Cognito Authentication is enabled, you can still access the WordPress login form using this emergency URL:', 'wp-cognito-auth' ); ?></p>
				<?php
				$emergency_param = get_option( 'wp_cognito_emergency_access_param' );
				if ( $emergency_param ) :
					?>
				<code style="background: white; padding: 10px; display: block; border: 1px solid #ddd; margin: 10px 0; word-break: break-all;">
					<?php echo esc_html( add_query_arg( $emergency_param, '1', wp_login_url() ) ); ?>
				</code>
				<p class="description">
					<strong style="color: #d63638;"><?php esc_html_e( 'Important:', 'wp-cognito-auth' ); ?></strong>
					<?php esc_html_e( 'Save this URL in a secure location. You will need it to access WordPress admin if Cognito authentication fails. This parameter is unique to your installation and cannot be recovered if lost.', 'wp-cognito-auth' ); ?>
				</p>
				<?php else : ?>
				<p style="color: #d63638;">
					<?php esc_html_e( 'Emergency access parameter not found. Please deactivate and reactivate the plugin to generate one.', 'wp-cognito-auth' ); ?>
				</p>
				<?php endif; ?>
			</div>

			<div class="cognito-callback-info" style="background: #f0f8ff; border: 1px solid #b3d9ff; padding: 15px; margin: 20px 0; border-radius: 5px;">
				<h3><?php esc_html_e( 'Callback URL Configuration', 'wp-cognito-auth' ); ?></h3>
				<p><?php esc_html_e( 'Add this URL to your Cognito App Client\'s "Allowed callback URLs":', 'wp-cognito-auth' ); ?></p>
				<code style="background: white; padding: 10px; display: block; border: 1px solid #ddd; margin: 10px 0;">
					<?php echo esc_html( add_query_arg( 'cognito_callback', '1', home_url( '/wp-login.php' ) ) ); ?>
				</code>
				<p class="description">
					<?php esc_html_e( 'Go to AWS Console → Cognito → User Pools → Your Pool → App integration → Your App Client → Edit Hosted UI → Add this URL to "Allowed callback URLs"', 'wp-cognito-auth' ); ?>
				</p>
			</div>

			<div class="cognito-test-section">
				<h3><?php esc_html_e( 'Test Connection', 'wp-cognito-auth' ); ?></h3>
				<button type="button" id="test-cognito-connection" class="button button-secondary">
					<?php esc_html_e( 'Test Cognito Connection', 'wp-cognito-auth' ); ?>
				</button>
				<div id="test-results" style="margin-top: 10px;"></div>
			</div>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	private function render_sync_settings_tab() {
		$features = get_option( 'wp_cognito_features', array() );
		if ( empty( $features['sync'] ) ) {
			echo '<div class="notice notice-warning"><p>' .
				__( 'User sync feature is not enabled. Please enable it in the Features tab first.', 'wp-cognito-auth' ) .
				'</p></div>';
			return;
		}
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'wp_cognito_sync_settings' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="wp_cognito_sync_api_url"><?php esc_html_e( 'Sync API URL', 'wp-cognito-auth' ); ?></label>
					</th>
					<td>
						<input type="url"
								name="wp_cognito_sync_api_url"
								id="wp_cognito_sync_api_url"
								value="<?php echo esc_attr( get_option( 'wp_cognito_sync_api_url' ) ); ?>"
								class="regular-text"
								placeholder="https://your-api-gateway.execute-api.region.amazonaws.com/prod">
						<p class="description">
							<?php esc_html_e( 'Lambda/API Gateway URL for user synchronization. Can end with either /sync or just the stage name (e.g., /prod). The plugin will handle the correct endpoint routing.', 'wp-cognito-auth' ); ?>
							<br>
							<strong><?php esc_html_e( 'Example:', 'wp-cognito-auth' ); ?></strong>
							<code>https://abc123def4.execute-api.us-west-2.amazonaws.com/prod</code>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wp_cognito_sync_api_key"><?php esc_html_e( 'API Key', 'wp-cognito-auth' ); ?></label>
					</th>
					<td>
						<input type="password"
								name="wp_cognito_sync_api_key"
								id="wp_cognito_sync_api_key"
								value="<?php echo esc_attr( get_option( 'wp_cognito_sync_api_key' ) ); ?>"
								class="regular-text">
						<p class="description"><?php esc_html_e( 'API Gateway API Key for authentication', 'wp-cognito-auth' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sync on Login', 'wp-cognito-auth' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
									name="wp_cognito_sync_on_login"
									value="1"
									<?php checked( get_option( 'wp_cognito_sync_on_login', false ) ); ?>>
							<?php esc_html_e( 'Automatically sync user data when they login', 'wp-cognito-auth' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<div class="cognito-test-section">
				<h3><?php esc_html_e( 'Test Sync Connection', 'wp-cognito-auth' ); ?></h3>

				<!-- AJAX Test Button -->
				<button type="button" id="test-sync-connection" class="button button-secondary">
					<?php esc_html_e( 'Test Sync API Connection (AJAX)', 'wp-cognito-auth' ); ?>
				</button>
				<div id="sync-test-results" style="margin-top: 10px;"></div>

				<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
				<div class="debug-info" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">
					<h4><?php esc_html_e( 'Debug Information', 'wp-cognito-auth' ); ?></h4>
					<?php
					if ( ! $this->api ) {
						$this->api = new API();
					}
					$debug_info = $this->api->get_config_debug_info();
					?>
					<table class="widefat">
						<tbody>
							<tr>
								<th><?php esc_html_e( 'API URL Setting', 'wp-cognito-auth' ); ?></th>
								<td><code><?php echo esc_html( $debug_info['base_url_setting'] ?: 'Not set' ); ?></code></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Calculated Base URL', 'wp-cognito-auth' ); ?></th>
								<td><code><?php echo esc_html( $debug_info['calculated_base_url'] ?: 'Not calculated' ); ?></code></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Test URL', 'wp-cognito-auth' ); ?></th>
								<td><code><?php echo esc_html( $debug_info['test_url'] ?: 'Not available' ); ?></code></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Sync URL', 'wp-cognito-auth' ); ?></th>
								<td><code><?php echo esc_html( $debug_info['sync_url'] ?: 'Not available' ); ?></code></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'API Key Status', 'wp-cognito-auth' ); ?></th>
								<td><?php echo $debug_info['api_key_set'] ? '<span style="color: green;">✓ Set</span>' : '<span style="color: red;">✗ Not set</span>'; ?></td>
							</tr>
							<?php if ( $debug_info['api_key_set'] ) : ?>
							<tr>
								<th><?php esc_html_e( 'API Key Preview', 'wp-cognito-auth' ); ?></th>
								<td><code><?php echo esc_html( $debug_info['api_key_prefix'] ); ?></code></td>
							</tr>
							<?php endif; ?>
						</tbody>
					</table>
					<p class="description">
						<?php esc_html_e( 'This debug information is only shown when WP_DEBUG is enabled. Check the sync logs below for detailed connection attempt information.', 'wp-cognito-auth' ); ?>
					</p>
				</div>
				<?php endif; ?>

				<p class="description" style="margin-top: 15px;">
					<?php esc_html_e( 'For group management and bulk synchronization operations, use the dedicated tabs above.', 'wp-cognito-auth' ); ?>
				</p>
			</div>

			<?php submit_button(); ?>
		</form>

		<!-- Separate Form-based Test (outside main form to avoid nesting) -->
		<div class="cognito-test-section" style="margin-top: 20px;">
			<h3><?php esc_html_e( 'Alternative Test Method', 'wp-cognito-auth' ); ?></h3>
			<p class="description"><?php esc_html_e( 'If the AJAX test above doesn\'t work, try this form-based test:', 'wp-cognito-auth' ); ?></p>
			<form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="margin-bottom: 20px;">
				<?php wp_nonce_field( 'cognito_sync_test_form' ); ?>
				<input type="hidden" name="action" value="cognito_sync_test_form">
				<button type="submit" class="button button-secondary">
					<?php esc_html_e( 'Test Sync API Connection (Form)', 'wp-cognito-auth' ); ?>
				</button>
			</form>

			<?php
			// Show form test results inline if we have them.
			if ( isset( $_GET['message'] ) && in_array( $_GET['message'], array( 'sync_test_success_form', 'sync_test_failed_form' ) ) ) {
				$result = get_transient( 'cognito_sync_test_result' );
				if ( $result ) {
					$is_success   = $_GET['message'] === 'sync_test_success_form';
					$notice_class = $is_success ? 'notice-success' : 'notice-error';
					$icon         = $is_success ? '✓' : '✗';
					?>
					<div class="notice <?php echo $notice_class; ?> inline" style="margin: 10px 0; padding: 12px;">
						<p style="margin: 0;">
							<strong><?php echo $icon; ?> <?php echo $is_success ? __( 'Success:', 'wp-cognito-auth' ) : __( 'Failed:', 'wp-cognito-auth' ); ?></strong>
							<?php echo esc_html( $result['message'] ); ?>
						</p>
					</div>
					<?php
					// Clean up the transient and URL.
					delete_transient( 'cognito_sync_test_result' );
					?>
					<script>
					// Clean up the URL to remove the message parameter.
					if (window.history && window.history.replaceState) {
						const url = new URL(window.location);
						url.searchParams.delete('message');
						window.history.replaceState({}, '', url);
					}
					</script>
					<?php
				}
			}
			?>
		</div>
		<?php
	}

	public function render_logs_page() {
		$logs = get_option( 'wp_cognito_sync_logs', array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Synchronization Logs', 'wp-cognito-auth' ); ?></h1>

			<div class="cognito-logs">
				<div class="logs-controls">
					<label>
						<input type="checkbox" class="logs-auto-refresh">
						<?php esc_html_e( 'Auto-refresh (every 30 seconds)', 'wp-cognito-auth' ); ?>
					</label>

					<button type="button" class="button cognito-clear-logs">
						<?php esc_html_e( 'Clear Logs', 'wp-cognito-auth' ); ?>
					</button>
				</div>

				<div class="logs-container">
					<?php if ( empty( $logs ) ) : ?>
						<p><?php esc_html_e( 'No synchronization logs available.', 'wp-cognito-auth' ); ?></p>
					<?php else : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Timestamp', 'wp-cognito-auth' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Level', 'wp-cognito-auth' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Message', 'wp-cognito-auth' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $logs as $log ) : ?>
									<tr class="log-level-<?php echo esc_attr( $log['level'] ); ?>">
										<td><?php echo esc_html( $log['timestamp'] ); ?></td>
										<td>
											<span class="log-level-badge log-level-<?php echo esc_attr( $log['level'] ); ?>">
												<?php echo esc_html( ucfirst( $log['level'] ) ); ?>
											</span>
										</td>
										<td><?php echo esc_html( $log['message'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	public function handle_bulk_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Insufficient permissions', 'wp-cognito-auth' ) );
		}

		check_admin_referer( 'cognito_bulk_sync' );

		$sync_direction = sanitize_text_field( $_POST['sync_direction'] ?? 'wp_to_cognito' );
		$user_selection = sanitize_text_field( $_POST['user_selection'] ?? 'all' );
		$selected_role  = sanitize_text_field( $_POST['selected_role'] ?? '' );

		// Validate role selection if specified.
		if ( $user_selection === 'role' && empty( $selected_role ) ) {
			wp_redirect(
				add_query_arg(
					array(
						'page'    => 'wp-cognito-auth',
						'tab'     => 'bulk-sync',
						'message' => 'role_not_selected',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Initialize API if needed.
		if ( ! $this->api ) {
			$this->api = new API();
		}

		// Perform the bulk sync based on user selection.
		if ( $user_selection === 'role' ) {
			$result = $this->api->bulk_sync_users_by_role( $selected_role );
		} else {
			$result = $this->api->bulk_sync_users();
		}

		// Set results in transient for display.
		set_transient( 'cognito_bulk_sync_results', $result, HOUR_IN_SECONDS );

		$message = $result && ! empty( $result['processed'] ) ? 'sync_completed' : 'sync_failed';

		// Redirect with success message.
		wp_redirect(
			add_query_arg(
				array(
					'page'    => 'wp-cognito-auth',
					'tab'     => 'bulk-sync',
					'message' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_test_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Insufficient permissions', 'wp-cognito-auth' ) );
		}

		check_admin_referer( 'cognito_test_sync' );

		// Test sync with current user.
		$current_user = wp_get_current_user();

		// Initialize API if needed.
		if ( ! $this->api ) {
			$features = get_option( 'wp_cognito_features', array() );
			if ( ! empty( $features['sync'] ) ) {
				$this->api = new API();
			}
		}

		if ( $this->api ) {
			$result = $this->api->create_user(
				array(
					'wp_user_id' => $current_user->ID,
					'email'      => $current_user->user_email,
					'username'   => $current_user->user_login,
					'first_name' => $current_user->first_name,
					'last_name'  => $current_user->last_name,
				)
			);

			if ( $result ) {
				$message = 'test_sync_success';
			} else {
				$message = 'test_sync_failed';
			}
		} else {
			$message = 'sync_not_configured';
		}

		wp_redirect(
			add_query_arg(
				array(
					'page'    => 'wp-cognito-auth',
					'tab'     => 'bulk-sync',
					'message' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function render_sync_stats() {
		global $wpdb;

		// Get user statistics.
		$total_users    = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
		$linked_users   = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'cognito_user_id' AND meta_value != ''" );
		$unlinked_users = $total_users - $linked_users;

		?>
		<table class="wp-list-table widefat">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Total WordPress Users', 'wp-cognito-auth' ); ?></td>
					<td><strong><?php echo esc_html( $total_users ); ?></strong></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Linked to Cognito', 'wp-cognito-auth' ); ?></td>
					<td><strong><?php echo esc_html( $linked_users ); ?></strong></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Not Linked', 'wp-cognito-auth' ); ?></td>
					<td><strong><?php echo esc_html( $unlinked_users ); ?></strong></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Last Sync', 'wp-cognito-auth' ); ?></td>
					<td><?php echo esc_html( get_option( 'wp_cognito_last_sync', __( 'Never', 'wp-cognito-auth' ) ) ); ?></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	public function ajax_test_connection() {
		check_ajax_referer( 'wp_cognito_auth_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}

		$user_pool_id = get_option( 'wp_cognito_auth_user_pool_id' );
		$region       = get_option( 'wp_cognito_auth_region' );

		if ( empty( $user_pool_id ) || empty( $region ) ) {
			wp_send_json_error( __( 'Please configure User Pool ID and Region first', 'wp-cognito-auth' ) );
		}

		// Test JWKS endpoint.
		$jwks_url = "https://cognito-idp.{$region}.amazonaws.com/{$user_pool_id}/.well-known/jwks.json";
		$response = wp_remote_get( $jwks_url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code !== 200 ) {
			wp_send_json_error( __( 'Invalid User Pool ID or Region', 'wp-cognito-auth' ) );
		}

		wp_send_json_success( __( 'Connection successful!', 'wp-cognito-auth' ) );
	}

	public function ajax_test_sync_connection() {
		// Check nonce - but provide specific error message if it fails.
		if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'wp_cognito_auth_nonce' ) ) {
			wp_send_json_error( 'Security check failed. Please refresh the page and try again.' );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
			return;
		}

		// Initialize API if not already done.
		if ( ! $this->api ) {
			$this->api = new API();
		}

		// Use the API's test_connection method.
		$result = $this->api->test_connection();

		if ( $result['success'] ) {
			wp_send_json_success( $result['message'] );
		} else {
			wp_send_json_error( $result['message'] );
		}
	}

	/**
	 * Show admin notices for Cognito authentication setup
	 */
	public function show_cognito_setup_notices() {
		$features = get_option( 'wp_cognito_features', array() );

		// Only show on admin pages.
		if ( ! is_admin() ) {
			return;
		}

		// Only show to users who can manage options.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Check if authentication is enabled but not configured.
		if ( ! empty( $features['authentication'] ) ) {
			$user_pool_id     = get_option( 'wp_cognito_auth_user_pool_id' );
			$client_id        = get_option( 'wp_cognito_auth_client_id' );
			$hosted_ui_domain = get_option( 'wp_cognito_auth_hosted_ui_domain' );

			if ( empty( $user_pool_id ) || empty( $client_id ) || empty( $hosted_ui_domain ) ) {
				?>
				<div class="notice notice-warning">
					<p>
						<strong><?php esc_html_e( 'Cognito Authentication Incomplete', 'wp-cognito-auth' ); ?></strong><br>
						<?php esc_html_e( 'Cognito authentication is enabled but not fully configured. Please complete the setup in the', 'wp-cognito-auth' ); ?>
						<a href="<?php echo admin_url( 'admin.php?page=wp-cognito-auth&tab=authentication' ); ?>">
							<?php esc_html_e( 'Authentication Settings', 'wp-cognito-auth' ); ?>
						</a>
					</p>
				</div>
				<?php
			} else {
				// Show information about login behavior.
				$force_cognito = get_option( 'wp_cognito_auth_force_cognito', false );
				if ( $force_cognito ) {
					$emergency_param = get_option( 'wp_cognito_emergency_access_param' );
					?>
					<div class="notice notice-info">
						<p>
							<strong><?php esc_html_e( 'Cognito Login Active', 'wp-cognito-auth' ); ?></strong><br>
							<?php esc_html_e( 'Users will be automatically redirected to Cognito for authentication. For emergency WordPress login, use the emergency access URL found in the', 'wp-cognito-auth' ); ?>
							<a href="<?php echo admin_url( 'admin.php?page=wp-cognito-auth&tab=authentication' ); ?>">
								<?php esc_html_e( 'Authentication Settings', 'wp-cognito-auth' ); ?>
							</a>
						</p>
					</div>
					<?php
				}
			}
		}
	}

	public function show_admin_notices() {
		// Show configuration warnings.
		$features = get_option( 'wp_cognito_features', array() );

		if ( ! empty( $features['authentication'] ) ) {
			$required_settings = array(
				'wp_cognito_auth_user_pool_id',
				'wp_cognito_auth_client_id',
				'wp_cognito_auth_client_secret',
				'wp_cognito_auth_hosted_ui_domain',
			);

			$missing_settings = array();
			foreach ( $required_settings as $setting ) {
				if ( empty( get_option( $setting ) ) ) {
					$missing_settings[] = $setting;
				}
			}

			if ( ! empty( $missing_settings ) ) {
				echo '<div class="notice notice-warning"><p>';
				echo __( 'Cognito Authentication is enabled but missing required settings. Please configure: ', 'wp-cognito-auth' );
				echo implode( ', ', $missing_settings );
				echo '</p></div>';
			}
		}

		// Show group sync messages.
		if ( isset( $_GET['message'] ) ) {
			switch ( $_GET['message'] ) {
				case 'group_sync_enabled':
					echo '<div class="notice notice-success is-dismissible"><p>';
					echo __( 'Group sync enabled and Cognito group created successfully.', 'wp-cognito-auth' );
					echo '</p></div>';
					break;
				case 'group_sync_disabled':
					echo '<div class="notice notice-success is-dismissible"><p>';
					echo __( 'Group sync disabled successfully.', 'wp-cognito-auth' );
					echo '</p></div>';
					break;
				case 'group_creation_failed':
					echo '<div class="notice notice-error is-dismissible"><p>';
					echo __( 'Group sync enabled but failed to create Cognito group. Check the logs for details.', 'wp-cognito-auth' );
					echo '</p></div>';
					break;
				case 'sync_started':
					echo '<div class="notice notice-info is-dismissible"><p>';
					echo __( 'Bulk sync process has been started. Check the logs for progress and results.', 'wp-cognito-auth' );
					echo '</p></div>';
					break;
				case 'sync_completed':
					$results = get_transient( 'cognito_bulk_sync_results' );
					if ( $results ) {
						echo '<div class="notice notice-success is-dismissible"><p>';
						printf(
							__( 'Bulk sync completed successfully. Processed: %1$d, Created: %2$d, Updated: %3$d, Failed: %4$d', 'wp-cognito-auth' ),
							$results['processed'] ?? 0,
							$results['created'] ?? 0,
							$results['updated'] ?? 0,
							$results['failed'] ?? 0
						);
						echo '</p></div>';
						delete_transient( 'cognito_bulk_sync_results' );
					} else {
						echo '<div class="notice notice-success is-dismissible"><p>';
						echo __( 'Bulk sync completed successfully. Check the logs for detailed results.', 'wp-cognito-auth' );
						echo '</p></div>';
					}
					break;
				case 'sync_failed':
					echo '<div class="notice notice-error is-dismissible"><p>';
					echo __( 'Bulk sync failed. Please check your sync configuration and logs for details.', 'wp-cognito-auth' );
					echo '</p></div>';
					break;
				case 'role_not_selected':
					echo '<div class="notice notice-error is-dismissible"><p>';
					echo __( 'Please select a role when choosing "Users by Role" option.', 'wp-cognito-auth' );
					echo '</p></div>';
					break;
				case 'test_sync_success':
					echo '<div class="notice notice-success is-dismissible"><p>';
					echo __( 'Test sync completed successfully. Check the logs for details.', 'wp-cognito-auth' );
					echo '</p></div>';
					break;
				case 'test_sync_failed':
					echo '<div class="notice notice-error is-dismissible"><p>';
					echo __( 'Test sync failed. Check the logs and API configuration.', 'wp-cognito-auth' );
					echo '</p></div>';
					break;
				case 'sync_not_configured':
					echo '<div class="notice notice-warning is-dismissible"><p>';
					echo __( 'Sync is not properly configured. Please check your API settings.', 'wp-cognito-auth' );
					echo '</p></div>';
					break;
			}
		}
	}

	/**
	 * Enqueue admin assets
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only load on our plugin pages.
		if ( strpos( $hook, 'wp-cognito-auth' ) === false ) {
			return;
		}

		wp_enqueue_script(
			'wp-cognito-auth-admin',
			WP_COGNITO_AUTH_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			WP_COGNITO_AUTH_VERSION,
			true
		);

		wp_enqueue_style(
			'wp-cognito-auth-admin',
			WP_COGNITO_AUTH_PLUGIN_URL . 'assets/admin.css',
			array(),
			WP_COGNITO_AUTH_VERSION
		);

		// Localize script for AJAX.
		wp_localize_script(
			'wp-cognito-auth-admin',
			'wpCognitoAuth',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_cognito_auth_nonce' ),
				'strings' => array(
					'testing' => __( 'Testing...', 'wp-cognito-auth' ),
					'success' => __( 'Connection successful!', 'wp-cognito-auth' ),
					'error'   => __( 'Connection failed:', 'wp-cognito-auth' ),
				),
			)
		);
	}

	private function render_help_tab() {
		?>
		<div class="wrap">
			<h2><?php esc_html_e( 'Help & Documentation', 'wp-cognito-auth' ); ?></h2>

			<div class="card">
				<h3><?php esc_html_e( 'Plugin Setup Guide', 'wp-cognito-auth' ); ?></h3>
				<p><?php esc_html_e( 'Follow these steps to configure AWS Cognito with your WordPress site:', 'wp-cognito-auth' ); ?></p>

				<ol>
					<li><strong><?php esc_html_e( 'Create AWS Cognito User Pool', 'wp-cognito-auth' ); ?></strong>
						<ul>
							<li><?php esc_html_e( 'Log into AWS Console and navigate to Cognito', 'wp-cognito-auth' ); ?></li>
							<li><?php esc_html_e( 'Create a new User Pool', 'wp-cognito-auth' ); ?></li>
							<li><?php esc_html_e( 'Configure sign-in options (email recommended)', 'wp-cognito-auth' ); ?></li>
						</ul>
					</li>

					<li><strong><?php esc_html_e( 'Configure App Client', 'wp-cognito-auth' ); ?></strong>
						<ul>
							<li><?php esc_html_e( 'Add an App client in your User Pool', 'wp-cognito-auth' ); ?></li>
							<li><?php esc_html_e( 'Enable "Generate client secret"', 'wp-cognito-auth' ); ?></li>
							<li><?php esc_html_e( 'Configure OAuth flows: Authorization code grant', 'wp-cognito-auth' ); ?></li>
							<li><?php esc_html_e( 'Add callback URL:', 'wp-cognito-auth' ); ?> <code><?php echo esc_url( home_url( '/wp-login.php?cognito_callback=1' ) ); ?></code></li>
						</ul>
					</li>

					<li><strong><?php esc_html_e( 'Set up Hosted UI Domain', 'wp-cognito-auth' ); ?></strong>
						<ul>
							<li><?php esc_html_e( 'Configure a domain for Cognito Hosted UI', 'wp-cognito-auth' ); ?></li>
							<li><?php esc_html_e( 'Add sign out URLs including your WordPress site URL', 'wp-cognito-auth' ); ?></li>
						</ul>
					</li>

					<li><strong><?php esc_html_e( 'Configure Plugin Settings', 'wp-cognito-auth' ); ?></strong>
						<ul>
							<li><?php esc_html_e( 'Go to Settings > Authentication tab', 'wp-cognito-auth' ); ?></li>
							<li><?php esc_html_e( 'Enter your User Pool ID, Client ID, Client Secret, and Region', 'wp-cognito-auth' ); ?></li>
							<li><?php esc_html_e( 'Test the connection using the "Test Connection" button', 'wp-cognito-auth' ); ?></li>
						</ul>
					</li>
				</ol>
			</div>

			<div class="card">
				<h3><?php esc_html_e( 'Features Overview', 'wp-cognito-auth' ); ?></h3>

				<h4><?php esc_html_e( 'Authentication', 'wp-cognito-auth' ); ?></h4>
				<p><?php esc_html_e( 'Allows users to log in using AWS Cognito instead of WordPress authentication.', 'wp-cognito-auth' ); ?></p>
				<ul>
					<li><?php esc_html_e( 'Option to force all logins through Cognito', 'wp-cognito-auth' ); ?></li>
					<li><?php esc_html_e( 'Automatic user creation from Cognito data', 'wp-cognito-auth' ); ?></li>
					<li><?php esc_html_e( 'Configurable default user role', 'wp-cognito-auth' ); ?></li>
				</ul>

				<h4><?php esc_html_e( 'User Sync', 'wp-cognito-auth' ); ?></h4>
				<p><?php esc_html_e( 'Synchronizes user data and group memberships between Cognito and WordPress.', 'wp-cognito-auth' ); ?></p>
				<ul>
					<li><?php esc_html_e( 'Maps Cognito groups to WordPress roles', 'wp-cognito-auth' ); ?></li>
					<li><?php esc_html_e( 'Syncs user profile information', 'wp-cognito-auth' ); ?></li>
					<li><?php esc_html_e( 'Custom field mapping support', 'wp-cognito-auth' ); ?></li>
					<li><?php esc_html_e( 'Automatic real-time role synchronization when users are added/removed to WordPress roles', 'wp-cognito-auth' ); ?></li>
					<li><?php esc_html_e( 'Supports both primary role changes (set_user_role) and secondary role changes (add_user_role/remove_user_role)', 'wp-cognito-auth' ); ?></li>
				</ul>

				<h4><?php esc_html_e( 'Content Restriction', 'wp-cognito-auth' ); ?></h4>
				<p><?php esc_html_e( 'Restrict content access based on Cognito group membership.', 'wp-cognito-auth' ); ?></p>
				<ul>
					<li><?php esc_html_e( 'Use shortcodes: [cognito_restrict groups="group1,group2"]Content[/cognito_restrict]', 'wp-cognito-auth' ); ?></li>
					<li><?php esc_html_e( 'Configure restrictions in post/page editor', 'wp-cognito-auth' ); ?></li>
					<li><?php esc_html_e( 'Automatic content filtering', 'wp-cognito-auth' ); ?></li>
				</ul>
			</div>

			<div class="card">
				<h3><?php esc_html_e( 'Troubleshooting', 'wp-cognito-auth' ); ?></h3>

				<h4><?php esc_html_e( 'Common Issues', 'wp-cognito-auth' ); ?></h4>

				<p><strong><?php esc_html_e( 'Login redirects to error page', 'wp-cognito-auth' ); ?></strong></p>
				<ul>
					<li><?php esc_html_e( 'Check that callback URL is correctly configured in Cognito', 'wp-cognito-auth' ); ?></li>
					<li><?php esc_html_e( 'Verify client secret is correct', 'wp-cognito-auth' ); ?></li>
					<li><?php esc_html_e( 'Enable WP_DEBUG to see detailed error logs', 'wp-cognito-auth' ); ?></li>
				</ul>

				<p><strong><?php esc_html_e( 'Users not being created', 'wp-cognito-auth' ); ?></strong></p>
				<ul>
					<li><?php esc_html_e( 'Check if "Auto-create users" is enabled', 'wp-cognito-auth' ); ?></li>
					<li><?php esc_html_e( 'Verify default role is set correctly', 'wp-cognito-auth' ); ?></li>
					<li><?php esc_html_e( 'Check WordPress error logs for user creation failures', 'wp-cognito-auth' ); ?></li>
				</ul>

				<p><strong><?php esc_html_e( 'First/Last names not being set', 'wp-cognito-auth' ); ?></strong></p>
				<ul>
					<li><?php esc_html_e( 'Check error logs to see what name fields Cognito is providing', 'wp-cognito-auth' ); ?></li>
					<li><?php esc_html_e( 'Ensure given_name and family_name attributes are enabled in Cognito', 'wp-cognito-auth' ); ?></li>
					<li><?php esc_html_e( 'Plugin will split the "name" field if given_name/family_name are not available', 'wp-cognito-auth' ); ?></li>
				</ul>

				<p><strong><?php esc_html_e( 'Login with Cognito button not showing', 'wp-cognito-auth' ); ?></strong></p>
				<ul>
					<li><?php esc_html_e( 'Check that Authentication feature is enabled', 'wp-cognito-auth' ); ?></li>
					<li><?php esc_html_e( 'Ensure "Force Cognito authentication" is disabled if you want to show the button', 'wp-cognito-auth' ); ?></li>
					<li><?php esc_html_e( 'Check for theme/plugin conflicts that might interfere with login form hooks', 'wp-cognito-auth' ); ?></li>
					<li><?php esc_html_e( 'The plugin includes a JavaScript fallback that should add the button automatically', 'wp-cognito-auth' ); ?></li>
				</ul>

				<p><strong><?php esc_html_e( 'Logout not working properly', 'wp-cognito-auth' ); ?></strong></p>
				<ul>
					<li><?php esc_html_e( 'Ensure logout URLs are configured in Cognito', 'wp-cognito-auth' ); ?></li>
					<li><?php esc_html_e( 'Check that Hosted UI domain is set correctly', 'wp-cognito-auth' ); ?></li>
				</ul>

				<h4><?php esc_html_e( 'Debug Information', 'wp-cognito-auth' ); ?></h4>
				<p><?php esc_html_e( 'Enable WordPress debug mode to see detailed logs:', 'wp-cognito-auth' ); ?></p>
				<pre><code>define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);</code></pre>
				<p><?php esc_html_e( 'Check logs in:', 'wp-cognito-auth' ); ?> <code>/wp-content/debug.log</code></p>
			</div>

			<div class="card">
				<h3><?php esc_html_e( 'Support', 'wp-cognito-auth' ); ?></h3>
				<p><?php esc_html_e( 'For additional support and documentation:', 'wp-cognito-auth' ); ?></p>
				<ul>
					<li><a href="https://docs.aws.amazon.com/cognito/" target="_blank"><?php esc_html_e( 'AWS Cognito Documentation', 'wp-cognito-auth' ); ?></a></li>
					<li><?php esc_html_e( 'Plugin version:', 'wp-cognito-auth' ); ?> <strong>2.3.1</strong></li>
				</ul>
			</div>
		</div>
		<?php
	}

	private function render_bulk_sync_tab() {
		$features = get_option( 'wp_cognito_features', array() );
		if ( empty( $features['sync'] ) ) {
			echo '<div class="notice notice-warning"><p>' .
				__( 'User sync feature is not enabled. Please enable it in the Features tab first.', 'wp-cognito-auth' ) .
				'</p></div>';
			return;
		}
		?>
		<div class="cognito-bulk-sync-container">
			<div class="cognito-card">
				<h2><?php esc_html_e( 'User Sync Management', 'wp-cognito-auth' ); ?></h2>

				<div class="sync-actions">
					<div class="sync-action-box">
						<h3><?php esc_html_e( 'Test User Sync', 'wp-cognito-auth' ); ?></h3>
						<p><?php esc_html_e( 'Preview which users would be created or updated in Cognito without making any changes.', 'wp-cognito-auth' ); ?></p>
						<form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
							<?php wp_nonce_field( 'cognito_test_sync' ); ?>
							<input type="hidden" name="action" value="cognito_test_sync">
							<button type="submit" class="button button-secondary">
								<?php esc_html_e( 'Run Test Sync', 'wp-cognito-auth' ); ?>
							</button>
						</form>
					</div>

					<div class="sync-action-box">
						<h3><?php esc_html_e( 'Full User Sync', 'wp-cognito-auth' ); ?></h3>
						<p><?php esc_html_e( 'Synchronize all WordPress users with Cognito. Creates new users in Cognito and updates existing ones. This operation cannot be undone.', 'wp-cognito-auth' ); ?></p>

						<form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
							<?php wp_nonce_field( 'cognito_bulk_sync' ); ?>
							<input type="hidden" name="action" value="cognito_bulk_sync">
							<input type="hidden" name="sync_direction" value="wp_to_cognito">

							<div class="sync-options">
								<h4><?php esc_html_e( 'User Selection:', 'wp-cognito-auth' ); ?></h4>
								<label style="display: block; margin-bottom: 8px;">
									<input type="radio" name="user_selection" value="all" checked>
									<?php esc_html_e( 'All Users', 'wp-cognito-auth' ); ?>
									<span class="description" style="display: block; margin-left: 20px; font-style: italic; color: #666;">
										<?php esc_html_e( 'Sync all WordPress users to Cognito', 'wp-cognito-auth' ); ?>
									</span>
								</label>

								<label style="display: block; margin-bottom: 8px;">
									<input type="radio" name="user_selection" value="role">
									<?php esc_html_e( 'Users by Role', 'wp-cognito-auth' ); ?>
									<span class="description" style="display: block; margin-left: 20px; font-style: italic; color: #666;">
										<?php esc_html_e( 'Sync users with a specific WordPress role', 'wp-cognito-auth' ); ?>
									</span>
								</label>

								<div id="role-selection" style="margin-left: 20px; margin-top: 10px; display: none;">
									<select name="selected_role">
										<option value=""><?php esc_html_e( 'Select a role...', 'wp-cognito-auth' ); ?></option>
										<?php
										$roles = get_editable_roles();
										foreach ( $roles as $role_name => $role_info ) {
											echo '<option value="' . esc_attr( $role_name ) . '">' . esc_html( $role_info['name'] ) . '</option>';
										}
										?>
									</select>
								</div>
							</div>

							<p style="margin-top: 15px;">
								<button type="submit" class="button button-primary">
									<?php esc_html_e( 'Start Full Sync', 'wp-cognito-auth' ); ?>
								</button>
							</p>
						</form>
					</div>
				</div>
			</div>

			<?php if ( ! empty( $features['group_sync'] ) ) : ?>
			<div class="cognito-card">
				<h2><?php esc_html_e( 'Wordpress -> Cognito Group Sync Management', 'wp-cognito-auth' ); ?></h2>

				<div class="sync-actions">
					<div class="sync-action-box">
						<h3><?php esc_html_e( 'Test Group Sync', 'wp-cognito-auth' ); ?></h3>
						<p><?php esc_html_e( 'Preview which groups would be created and which memberships would be updated.', 'wp-cognito-auth' ); ?></p>
						<form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
							<?php wp_nonce_field( 'cognito_test_group_sync' ); ?>
							<input type="hidden" name="action" value="cognito_test_group_sync">
							<button type="submit" class="button button-secondary">
								<?php esc_html_e( 'Run Group Test Sync', 'wp-cognito-auth' ); ?>
							</button>
						</form>
					</div>

					<div class="sync-action-box">
						<h3><?php esc_html_e( 'Full Group Sync', 'wp-cognito-auth' ); ?></h3>
						<p><?php esc_html_e( 'Create missing groups in Cognito and synchronize all group memberships.', 'wp-cognito-auth' ); ?></p>
						<form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
							<?php wp_nonce_field( 'cognito_full_group_sync' ); ?>
							<input type="hidden" name="action" value="cognito_full_group_sync">
							<button type="submit" class="button button-primary">
								<?php esc_html_e( 'Start Full Group Sync', 'wp-cognito-auth' ); ?>
							</button>
						</form>
					</div>
				</div>
			</div>
			<?php endif; ?>

			<div class="cognito-card">
				<h2><?php esc_html_e( 'Sync Statistics', 'wp-cognito-auth' ); ?></h2>
				<?php $this->render_sync_stats(); ?>
			</div>
		</div>

		<style>
			.sync-actions {
				display: flex;
				gap: 20px;
				margin-bottom: 20px;
			}
			.sync-action-box {
				background: #f9f9f9;
				padding: 20px;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				flex: 1;
			}
			.sync-action-box h3 {
				margin-top: 0;
			}
			.sync-options h4 {
				margin: 15px 0 10px 0;
				font-size: 14px;
				color: #23282d;
			}
		</style>

		<script>
		jQuery(document).ready(function($) {
			// Handle role selection toggle.
			$('input[name="user_selection"]').on('change', function() {
				if ($(this).val() === 'role') {
					$('#role-selection').show();
				} else {
					$('#role-selection').hide();
				}
			});

			// Validate form submission.
			$('form[action*="cognito_bulk_sync"]').on('submit', function(e) {
				var userSelection = $('input[name="user_selection"]:checked').val();
				if (userSelection === 'role') {
					var selectedRole = $('select[name="selected_role"]').val();
					if (!selectedRole) {
						e.preventDefault();
						alert('<?php esc_js( __( 'Please select a role to sync.', 'wp-cognito-auth' ) ); ?>');
						return false;
					}
				}
			});
		});
		</script>
		<?php
	}

	private function render_groups_tab() {
		$features = get_option( 'wp_cognito_features', array() );
		if ( empty( $features['group_sync'] ) ) {
			echo '<div class="notice notice-warning"><p>' .
				__( 'Group sync feature is not enabled. Please enable it in the Features tab first.', 'wp-cognito-auth' ) .
				'</p></div>';
			return;
		}

		$synced_groups = get_option( 'wp_cognito_sync_groups', array() );
		if ( ! is_array( $synced_groups ) ) {
			$synced_groups = array();
			update_option( 'wp_cognito_sync_groups', $synced_groups );
		}

		$groups = get_editable_roles();
		?>
		<div class="group-management-container">
			<div class="cognito-card">
				<h2><?php esc_html_e( 'Group Synchronization Management', 'wp-cognito-auth' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Select which WordPress roles should be synchronized with Cognito groups. When you enable sync for a role, the corresponding Cognito group will be created automatically. Role changes are synchronized immediately when users are added to or removed from WordPress roles.', 'wp-cognito-auth' ); ?>
				</p>

				<table class="widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'WordPress Role', 'wp-cognito-auth' ); ?></th>
							<th><?php esc_html_e( 'Cognito Group Name', 'wp-cognito-auth' ); ?></th>
							<th><?php esc_html_e( 'User Count', 'wp-cognito-auth' ); ?></th>
							<th><?php esc_html_e( 'Sync Status', 'wp-cognito-auth' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'wp-cognito-auth' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $groups as $role_name => $role_info ) : ?>
							<?php
							$user_count = count( get_users( array( 'role' => $role_name ) ) );
							$is_synced  = in_array( $role_name, $synced_groups );
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $role_info['name'] ); ?></strong>
									<br>
									<code><?php echo esc_html( $role_name ); ?></code>
								</td>
								<td><code>WP_<?php echo esc_html( $role_name ); ?></code></td>
								<td><?php echo $user_count; ?></td>
								<td>
									<?php if ( $is_synced ) : ?>
										<span class="sync-status enabled"><?php esc_html_e( 'Enabled', 'wp-cognito-auth' ); ?></span>
									<?php else : ?>
										<span class="sync-status disabled"><?php esc_html_e( 'Disabled', 'wp-cognito-auth' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline;">
										<?php wp_nonce_field( 'cognito_group_sync' ); ?>
										<input type="hidden" name="action" value="cognito_toggle_group_sync">
										<input type="hidden" name="group_id" value="<?php echo esc_attr( $role_name ); ?>">
										<input type="hidden" name="enabled" value="<?php echo $is_synced ? '0' : '1'; ?>">
										<button type="submit" class="button <?php echo $is_synced ? 'button-secondary' : 'button-primary'; ?>">
											<?php
											echo $is_synced ?
												__( 'Disable Sync', 'wp-cognito-auth' ) :
												__( 'Enable Sync', 'wp-cognito-auth' );
											?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( ! empty( $synced_groups ) ) : ?>
					<div class="group-sync-info">
						<h3><?php esc_html_e( 'Currently Synced Groups', 'wp-cognito-auth' ); ?></h3>
						<p><?php printf( __( 'You have %d WordPress roles configured for sync with Cognito groups:', 'wp-cognito-auth' ), count( $synced_groups ) ); ?></p>
						<ul>
							<?php foreach ( $synced_groups as $group_name ) : ?>
								<li><code>WP_<?php echo esc_html( $group_name ); ?></code> (<?php echo esc_html( $groups[ $group_name ]['name'] ); ?>)</li>
							<?php endforeach; ?>
						</ul>
						<p class="description">
							<?php esc_html_e( 'Users are automatically added to/removed from these Cognito groups based on their WordPress role when sync occurs.', 'wp-cognito-auth' ); ?>
						</p>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<style>
			.sync-status {
				padding: 3px 8px;
				border-radius: 3px;
				font-size: 12px;
				font-weight: bold;
				text-transform: uppercase;
			}
			.sync-status.enabled {
				background-color: #d4edda;
				color: #155724;
			}
			.sync-status.disabled {
				background-color: #f8d7da;
				color: #721c24;
			}
			.group-sync-info {
				margin-top: 20px;
				padding: 15px;
				background: #f9f9f9;
				border: 1px solid #ddd;
				border-radius: 4px;
			}
		</style>
		<?php
	}

	public function handle_toggle_group_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Insufficient permissions', 'wp-cognito-auth' ) );
		}

		check_admin_referer( 'cognito_group_sync' );

		$group_name = sanitize_key( $_POST['group_id'] );
		$enabled    = isset( $_POST['enabled'] ) && $_POST['enabled'] === '1';

		// Validate against actual WordPress roles.
		$roles = get_editable_roles();
		if ( ! array_key_exists( $group_name, $roles ) ) {
			wp_die( __( 'Invalid role specified', 'wp-cognito-auth' ) );
		}

		$synced_groups = get_option( 'wp_cognito_sync_groups', array() );
		if ( ! is_array( $synced_groups ) ) {
			$synced_groups = array();
		}

		if ( $enabled ) {
			if ( ! in_array( $group_name, $synced_groups ) ) {
				$synced_groups[] = $group_name;

				// Initialize API and Sync.
				if ( ! $this->api ) {
					$this->api = new API();
				}

				try {
					// Create the group in Cognito.
					$this->api->create_group( $group_name );
					$message = 'group_sync_enabled';
				} catch ( \Exception $e ) {
					// Log the error but still enable sync locally.
					if ( $this->api ) {
						$this->api->log_message( "Failed to create group {$group_name}: " . $e->getMessage(), 'error' );
					}
					$message = 'group_creation_failed';
				}
			}
		} else {
			$synced_groups = array_diff( $synced_groups, array( $group_name ) );
			$message       = 'group_sync_disabled';
		}

		$synced_groups = array_values( array_filter( $synced_groups ) );
		update_option( 'wp_cognito_sync_groups', $synced_groups );

		wp_redirect(
			add_query_arg(
				array(
					'page'    => 'wp-cognito-auth',
					'tab'     => 'groups',
					'updated' => '1',
					'message' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_test_group_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Insufficient permissions', 'wp-cognito-auth' ) );
		}

		check_admin_referer( 'cognito_test_group_sync' );

		$stats = array(
			'total'                 => 0,
			'to_create'             => 0,
			'memberships_to_update' => 0,
			'groups'                => array(),
			'timestamp'             => current_time( 'mysql' ),
		);

		$synced_groups = get_option( 'wp_cognito_sync_groups', array() );
		foreach ( $synced_groups as $group_name ) {
			++$stats['total'];
			$users_in_role = get_users( array( 'role' => $group_name ) );
			$member_count  = count( $users_in_role );

			$stats['groups'][] = array(
				'name'              => "WP_{$group_name}",
				'action'            => 'create_if_needed',
				'members_to_update' => $member_count,
			);

			++$stats['to_create'];
			$stats['memberships_to_update'] += $member_count;
		}

		set_transient( 'cognito_sync_group_test_results', $stats, HOUR_IN_SECONDS );

		wp_redirect(
			add_query_arg(
				array(
					'page'                => 'wp-cognito-auth',
					'tab'                 => 'bulk-sync',
					'group-test-complete' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_full_group_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Insufficient permissions', 'wp-cognito-auth' ) );
		}

		check_admin_referer( 'cognito_full_group_sync' );

		// Initialize API and Sync if not already done.
		if ( ! $this->api ) {
			$this->api = new API();
		}

		// Use Sync class for proper group synchronization.
		$sync  = new \WP_Cognito_Auth\Sync( $this->api );
		$stats = $sync->sync_groups();

		// Also sync user group memberships.
		$synced_groups    = get_option( 'wp_cognito_sync_groups', array() );
		$membership_stats = array(
			'memberships_updated' => 0,
			'memberships_failed'  => 0,
		);

		foreach ( $synced_groups as $group_name ) {
			$users = get_users( array( 'role' => $group_name ) );
			foreach ( $users as $user ) {
				$cognito_id = get_user_meta( $user->ID, 'cognito_user_id', true );
				if ( ! empty( $cognito_id ) ) {
					if ( $this->api->update_group_membership( $cognito_id, $group_name, 'add' ) ) {
						++$membership_stats['memberships_updated'];
					} else {
						++$membership_stats['memberships_failed'];
					}
				}
			}
		}

		// Merge stats.
		$final_stats              = array_merge( $stats, $membership_stats );
		$final_stats['timestamp'] = current_time( 'mysql' );

		set_transient( 'cognito_sync_group_results', $final_stats, HOUR_IN_SECONDS );

		wp_redirect(
			add_query_arg(
				array(
					'page'                => 'wp-cognito-auth',
					'tab'                 => 'bulk-sync',
					'group-sync-complete' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_sync_test_form() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Insufficient permissions', 'wp-cognito-auth' ) );
		}

		check_admin_referer( 'cognito_sync_test_form' );

		// Initialize API if needed.
		if ( ! $this->api ) {
			$this->api = new API();
		}

		// Run the test.
		$result = $this->api->test_connection();

		// Set result in transient for display.
		set_transient( 'cognito_sync_test_result', $result, MINUTE_IN_SECONDS * 5 );

		$message = $result['success'] ? 'sync_test_success_form' : 'sync_test_failed_form';

		// Redirect back with result.
		wp_redirect(
			add_query_arg(
				array(
					'page'    => 'wp-cognito-auth',
					'tab'     => 'sync',
					'message' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}