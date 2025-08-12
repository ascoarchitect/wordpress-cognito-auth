<?php
namespace WP_Cognito_Auth;

class Admin {
    private $api;

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_post_cognito_bulk_sync', array($this, 'handle_bulk_sync'));
        add_action('admin_post_cognito_test_sync', array($this, 'handle_test_sync'));
        add_action('admin_notices', array($this, 'show_admin_notices'));
        add_action('admin_notices', array($this, 'show_cognito_setup_notices'));
    }

    public function add_admin_menu() {
        add_menu_page(
            __('Cognito Auth', 'wp-cognito-auth'),
            __('Cognito Auth', 'wp-cognito-auth'),
            'manage_options',
            'wp-cognito-auth',
            array($this, 'render_main_page'),
            'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#a0a5aa" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>'),
            30
        );

        add_submenu_page(
            'wp-cognito-auth',
            __('Settings', 'wp-cognito-auth'),
            __('Settings', 'wp-cognito-auth'),
            'manage_options',
            'wp-cognito-auth',
            array($this, 'render_main_page')
        );

        add_submenu_page(
            'wp-cognito-auth',
            __('User Sync', 'wp-cognito-auth'),
            __('User Sync', 'wp-cognito-auth'),
            'manage_options',
            'wp-cognito-auth-sync',
            array($this, 'render_sync_page')
        );

        add_submenu_page(
            'wp-cognito-auth',
            __('Logs', 'wp-cognito-auth'),
            __('Logs', 'wp-cognito-auth'),
            'manage_options',
            'wp-cognito-auth-logs',
            array($this, 'render_logs_page')
        );
    }

    public function register_settings() {
        // Feature toggles
        register_setting('wp_cognito_auth_features', 'wp_cognito_features', array(
            'type' => 'array',
            'default' => array(),
            'sanitize_callback' => array($this, 'sanitize_features')
        ));
        
        // Authentication settings
        register_setting('wp_cognito_auth_settings', 'wp_cognito_auth_user_pool_id');
        register_setting('wp_cognito_auth_settings', 'wp_cognito_auth_client_id');
        register_setting('wp_cognito_auth_settings', 'wp_cognito_auth_client_secret');
        register_setting('wp_cognito_auth_settings', 'wp_cognito_auth_region');
        register_setting('wp_cognito_auth_settings', 'wp_cognito_auth_hosted_ui_domain');
        register_setting('wp_cognito_auth_settings', 'wp_cognito_auth_auto_create_users');
        register_setting('wp_cognito_auth_settings', 'wp_cognito_auth_default_role');
        register_setting('wp_cognito_auth_settings', 'wp_cognito_auth_force_cognito');
        register_setting('wp_cognito_auth_settings', 'wp_cognito_auth_login_button_text');
        register_setting('wp_cognito_auth_settings', 'wp_cognito_auth_login_button_color');
        register_setting('wp_cognito_auth_settings', 'wp_cognito_auth_login_button_text_color');
        
        // Sync settings
        register_setting('wp_cognito_sync_settings', 'wp_cognito_sync_api_url');
        register_setting('wp_cognito_sync_settings', 'wp_cognito_sync_api_key');
        register_setting('wp_cognito_sync_settings', 'wp_cognito_sync_on_login');
        register_setting('wp_cognito_sync_settings', 'wp_cognito_sync_groups');
    }

    public function sanitize_features($features) {
        if (!is_array($features)) {
            return array();
        }
        
        $allowed_features = array('authentication', 'sync', 'group_sync');
        return array_intersect_key($features, array_flip($allowed_features));
    }

    public function render_main_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'features';
        ?>
        <div class="wrap">
            <h1><?php _e('Cognito Authentication & Sync', 'wp-cognito-auth'); ?></h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=wp-cognito-auth&tab=features" 
                   class="nav-tab <?php echo $active_tab === 'features' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Features', 'wp-cognito-auth'); ?>
                </a>
                <a href="?page=wp-cognito-auth&tab=authentication" 
                   class="nav-tab <?php echo $active_tab === 'authentication' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Authentication Settings', 'wp-cognito-auth'); ?>
                </a>
                <a href="?page=wp-cognito-auth&tab=sync" 
                   class="nav-tab <?php echo $active_tab === 'sync' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Sync Settings', 'wp-cognito-auth'); ?>
                </a>
                <a href="?page=wp-cognito-auth&tab=help" 
                   class="nav-tab <?php echo $active_tab === 'help' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Setup Guide', 'wp-cognito-auth'); ?>
                </a>
            </h2>

            <div class="tab-content">
                <?php
                switch ($active_tab) {
                    case 'authentication':
                        $this->render_authentication_tab();
                        break;
                    case 'sync':
                        $this->render_sync_settings_tab();
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
        $features = get_option('wp_cognito_features', array());
        ?>
        <div class="cognito-features">                <div class="cognito-card">
                    <h2><?php _e('Enable Features', 'wp-cognito-auth'); ?></h2>
                    <p><?php _e('Choose which Cognito features to enable for your site. You must enable Authentication first to configure Cognito settings.', 'wp-cognito-auth'); ?></p>
                    
                    <form method="post" action="options.php">
                        <?php settings_fields('wp_cognito_auth_features'); ?>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Authentication', 'wp-cognito-auth'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" 
                                               name="wp_cognito_features[authentication]" 
                                               value="1" 
                                               <?php checked(!empty($features['authentication'])); ?>>
                                        <strong><?php _e('Enable Cognito Authentication (JWT/OIDC)', 'wp-cognito-auth'); ?></strong>
                                    </label>
                                    <p class="description">
                                        <?php _e('Allows users to login using Amazon Cognito hosted UI. Enable this first to configure Cognito settings.', 'wp-cognito-auth'); ?>
                                    </p>
                                </td>
                            </tr>
                        <tr>
                            <th scope="row"><?php _e('User Sync', 'wp-cognito-auth'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           name="wp_cognito_features[sync]" 
                                           value="1" 
                                           <?php checked(!empty($features['sync'])); ?>>
                                    <?php _e('Enable User Synchronization', 'wp-cognito-auth'); ?>
                                </label>
                                <p class="description">
                                    <?php _e('Sync WordPress users with Cognito user pool', 'wp-cognito-auth'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Group Sync', 'wp-cognito-auth'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           name="wp_cognito_features[group_sync]" 
                                           value="1" 
                                           <?php checked(!empty($features['group_sync'])); ?>>
                                    <?php _e('Enable Group/Role Synchronization', 'wp-cognito-auth'); ?>
                                </label>
                                <p class="description">
                                    <?php _e('Sync WordPress roles with Cognito groups', 'wp-cognito-auth'); ?>
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
        $features = get_option('wp_cognito_features', array());
        if (empty($features['authentication'])) {
            echo '<div class="notice notice-warning"><p>' . 
                 __('Authentication feature is not enabled. Please enable it in the Features tab first.', 'wp-cognito-auth') . 
                 '</p></div>';
            return;
        }
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('wp_cognito_auth_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="wp_cognito_auth_user_pool_id"><?php _e('User Pool ID', 'wp-cognito-auth'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                               name="wp_cognito_auth_user_pool_id"
                               id="wp_cognito_auth_user_pool_id"
                               value="<?php echo esc_attr(get_option('wp_cognito_auth_user_pool_id')); ?>"
                               class="regular-text"
                               placeholder="us-east-1_xxxxxxxxx">
                        <p class="description"><?php _e('Your Cognito User Pool ID', 'wp-cognito-auth'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wp_cognito_auth_client_id"><?php _e('App Client ID', 'wp-cognito-auth'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                               name="wp_cognito_auth_client_id"
                               id="wp_cognito_auth_client_id"
                               value="<?php echo esc_attr(get_option('wp_cognito_auth_client_id')); ?>"
                               class="regular-text">
                        <p class="description"><?php _e('OAuth App Client ID', 'wp-cognito-auth'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wp_cognito_auth_client_secret"><?php _e('App Client Secret', 'wp-cognito-auth'); ?></label>
                    </th>
                    <td>
                        <input type="password" 
                               name="wp_cognito_auth_client_secret"
                               id="wp_cognito_auth_client_secret"
                               value="<?php echo esc_attr(get_option('wp_cognito_auth_client_secret')); ?>"
                               class="regular-text">
                        <p class="description"><?php _e('OAuth App Client Secret', 'wp-cognito-auth'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wp_cognito_auth_region"><?php _e('AWS Region', 'wp-cognito-auth'); ?></label>
                    </th>
                    <td>
                        <select name="wp_cognito_auth_region" id="wp_cognito_auth_region">
                            <?php
                            $current_region = get_option('wp_cognito_auth_region', 'us-east-1');
                            $regions = array(
                                'us-east-1' => 'US East (N. Virginia)',
                                'us-east-2' => 'US East (Ohio)',
                                'us-west-1' => 'US West (N. California)',
                                'us-west-2' => 'US West (Oregon)',
                                'eu-west-1' => 'Europe (Ireland)',
                                'eu-west-2' => 'Europe (London)',
                                'eu-central-1' => 'Europe (Frankfurt)',
                                'ap-south-1' => 'Asia Pacific (Mumbai)',
                                'ap-southeast-1' => 'Asia Pacific (Singapore)',
                                'ap-southeast-2' => 'Asia Pacific (Sydney)',
                                'ap-northeast-1' => 'Asia Pacific (Tokyo)',
                            );
                            foreach ($regions as $code => $name) {
                                echo '<option value="' . esc_attr($code) . '"' . selected($current_region, $code, false) . '>' . esc_html($name) . '</option>';
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wp_cognito_auth_hosted_ui_domain"><?php _e('Hosted UI Domain', 'wp-cognito-auth'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                               name="wp_cognito_auth_hosted_ui_domain"
                               id="wp_cognito_auth_hosted_ui_domain"
                               value="<?php echo esc_attr(get_option('wp_cognito_auth_hosted_ui_domain')); ?>"
                               class="regular-text"
                               placeholder="your-domain.auth.us-east-1.amazoncognito.com">
                        <p class="description"><?php _e('Cognito Hosted UI domain (without https://)', 'wp-cognito-auth'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('User Creation', 'wp-cognito-auth'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="wp_cognito_auth_auto_create_users"
                                   value="1"
                                   <?php checked(get_option('wp_cognito_auth_auto_create_users', true)); ?>>
                            <?php _e('Auto-create WordPress users from Cognito', 'wp-cognito-auth'); ?>
                        </label>
                        <p class="description">
                            <?php _e('Automatically create WordPress accounts for new Cognito users', 'wp-cognito-auth'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wp_cognito_auth_default_role"><?php _e('Default Role', 'wp-cognito-auth'); ?></label>
                    </th>
                    <td>
                        <select name="wp_cognito_auth_default_role" id="wp_cognito_auth_default_role">
                            <?php
                            $current_role = get_option('wp_cognito_auth_default_role', 'subscriber');
                            $roles = get_editable_roles();
                            foreach ($roles as $role_name => $role_info) {
                                echo '<option value="' . esc_attr($role_name) . '"' . selected($current_role, $role_name, false) . '>' . esc_html($role_info['name']) . '</option>';
                            }
                            ?>
                        </select>
                        <p class="description"><?php _e('Default role for new users created via Cognito', 'wp-cognito-auth'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Login Behavior', 'wp-cognito-auth'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="wp_cognito_auth_force_cognito"
                                   value="1"
                                   <?php checked(get_option('wp_cognito_auth_force_cognito', false)); ?>>
                            <?php _e('Force Cognito authentication (skip WordPress login form)', 'wp-cognito-auth'); ?>
                        </label>
                        <p class="description">
                            <?php _e('When enabled, users will be automatically redirected to Cognito for authentication instead of seeing the WordPress login form. The WordPress login form will still be accessible via the emergency access parameter shown below.', 'wp-cognito-auth'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wp_cognito_auth_login_button_text"><?php _e('Login Button Text', 'wp-cognito-auth'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                               name="wp_cognito_auth_login_button_text"
                               id="wp_cognito_auth_login_button_text"
                               value="<?php echo esc_attr(get_option('wp_cognito_auth_login_button_text', 'Login with Cognito')); ?>"
                               class="regular-text"
                               placeholder="Login with Cognito">
                        <p class="description"><?php _e('Customize the text displayed on the Cognito login button (e.g., "Member Login", "RAFSA Login")', 'wp-cognito-auth'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wp_cognito_auth_login_button_color"><?php _e('Login Button Color', 'wp-cognito-auth'); ?></label>
                    </th>
                    <td>
                        <input type="color" 
                               name="wp_cognito_auth_login_button_color"
                               id="wp_cognito_auth_login_button_color"
                               value="<?php echo esc_attr(get_option('wp_cognito_auth_login_button_color', '#ff9900')); ?>"
                               class="color-picker"
                               style="width: 100px;">
                        <input type="text" 
                               id="wp_cognito_auth_login_button_color_text"
                               value="<?php echo esc_attr(get_option('wp_cognito_auth_login_button_color', '#ff9900')); ?>"
                               class="regular-text color-text-field"
                               placeholder="#ff9900"
                               readonly
                               style="margin-left: 10px; width: 100px;">
                        <p class="description"><?php _e('Choose a color for the Cognito login button to match your site\'s branding', 'wp-cognito-auth'); ?></p>
                        
                        <div class="cognito-button-customization">
                            <h4><?php _e('Preview', 'wp-cognito-auth'); ?></h4>
                            <button type="button" class="cognito-login-preview" id="login-button-preview" style="background-color: <?php echo esc_attr(get_option('wp_cognito_auth_login_button_color', '#ff9900')); ?> !important; border-color: <?php echo esc_attr(get_option('wp_cognito_auth_login_button_color', '#ff9900')); ?> !important; color: <?php echo esc_attr(get_option('wp_cognito_auth_login_button_text_color', '#ffffff')); ?> !important; padding: 10px 20px !important; border: 1px solid <?php echo esc_attr(get_option('wp_cognito_auth_login_button_color', '#ff9900')); ?> !important; border-radius: 3px !important; text-decoration: none !important; display: inline-block !important; font-size: 14px !important; font-weight: normal !important; text-shadow: none !important; box-shadow: none !important; line-height: normal !important; min-height: auto !important; text-align: center !important; cursor: pointer;">
                                <?php echo esc_html(get_option('wp_cognito_auth_login_button_text', 'Login with Cognito')); ?>
                            </button>
                            <p class="description" style="margin-bottom: 0;">
                                <?php _e('This preview updates in real-time as you change the settings above.', 'wp-cognito-auth'); ?>
                            </p>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wp_cognito_auth_login_button_text_color"><?php _e('Login Button Text Color', 'wp-cognito-auth'); ?></label>
                    </th>
                    <td>
                        <input type="color" 
                               name="wp_cognito_auth_login_button_text_color"
                               id="wp_cognito_auth_login_button_text_color"
                               value="<?php echo esc_attr(get_option('wp_cognito_auth_login_button_text_color', '#ffffff')); ?>"
                               class="color-picker"
                               style="width: 100px;">
                        <input type="text" 
                               id="wp_cognito_auth_login_button_text_color_text"
                               value="<?php echo esc_attr(get_option('wp_cognito_auth_login_button_text_color', '#ffffff')); ?>"
                               class="regular-text color-text-field"
                               placeholder="#ffffff"
                               readonly
                               style="margin-left: 10px; width: 100px;">
                        <p class="description"><?php _e('Choose the text color for the login button (white works well with most background colors)', 'wp-cognito-auth'); ?></p>
                    </td>
                </tr>
            </table>
            
            <div class="cognito-emergency-access-info" style="background: #fff2cc; border: 1px solid #ffd966; padding: 15px; margin: 20px 0; border-radius: 5px;">
                <h3><?php _e('Emergency WordPress Access', 'wp-cognito-auth'); ?></h3>
                <p><?php _e('If Force Cognito Authentication is enabled, you can still access the WordPress login form using this emergency URL:', 'wp-cognito-auth'); ?></p>
                <?php 
                $emergency_param = get_option('wp_cognito_emergency_access_param');
                if ($emergency_param): 
                ?>
                <code style="background: white; padding: 10px; display: block; border: 1px solid #ddd; margin: 10px 0; word-break: break-all;">
                    <?php echo esc_html(add_query_arg($emergency_param, '1', wp_login_url())); ?>
                </code>
                <p class="description">
                    <strong style="color: #d63638;"><?php _e('Important:', 'wp-cognito-auth'); ?></strong> 
                    <?php _e('Save this URL in a secure location. You will need it to access WordPress admin if Cognito authentication fails. This parameter is unique to your installation and cannot be recovered if lost.', 'wp-cognito-auth'); ?>
                </p>
                <?php else: ?>
                <p style="color: #d63638;">
                    <?php _e('Emergency access parameter not found. Please deactivate and reactivate the plugin to generate one.', 'wp-cognito-auth'); ?>
                </p>
                <?php endif; ?>
            </div>
            
            <div class="cognito-callback-info" style="background: #f0f8ff; border: 1px solid #b3d9ff; padding: 15px; margin: 20px 0; border-radius: 5px;">
                <h3><?php _e('Callback URL Configuration', 'wp-cognito-auth'); ?></h3>
                <p><?php _e('Add this URL to your Cognito App Client\'s "Allowed callback URLs":', 'wp-cognito-auth'); ?></p>
                <code style="background: white; padding: 10px; display: block; border: 1px solid #ddd; margin: 10px 0;">
                    <?php echo esc_html(add_query_arg('cognito_callback', '1', home_url('/wp-login.php'))); ?>
                </code>
                <p class="description">
                    <?php _e('Go to AWS Console → Cognito → User Pools → Your Pool → App integration → Your App Client → Edit Hosted UI → Add this URL to "Allowed callback URLs"', 'wp-cognito-auth'); ?>
                </p>
            </div>
            
            <div class="cognito-test-section">
                <h3><?php _e('Test Connection', 'wp-cognito-auth'); ?></h3>
                <button type="button" id="test-cognito-connection" class="button button-secondary">
                    <?php _e('Test Cognito Connection', 'wp-cognito-auth'); ?>
                </button>
                <div id="test-results" style="margin-top: 10px;"></div>
            </div>
            
            <?php submit_button(); ?>
        </form>
        <?php
    }

    private function render_sync_settings_tab() {
        $features = get_option('wp_cognito_features', array());
        if (empty($features['sync'])) {
            echo '<div class="notice notice-warning"><p>' . 
                 __('User sync feature is not enabled. Please enable it in the Features tab first.', 'wp-cognito-auth') . 
                 '</p></div>';
            return;
        }
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('wp_cognito_sync_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="wp_cognito_sync_api_url"><?php _e('Sync API URL', 'wp-cognito-auth'); ?></label>
                    </th>
                    <td>
                        <input type="url" 
                               name="wp_cognito_sync_api_url"
                               id="wp_cognito_sync_api_url"
                               value="<?php echo esc_attr(get_option('wp_cognito_sync_api_url')); ?>"
                               class="regular-text"
                               placeholder="https://your-api-gateway.execute-api.region.amazonaws.com/prod">
                        <p class="description"><?php _e('Lambda/API Gateway URL for user synchronization', 'wp-cognito-auth'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wp_cognito_sync_api_key"><?php _e('API Key', 'wp-cognito-auth'); ?></label>
                    </th>
                    <td>
                        <input type="password" 
                               name="wp_cognito_sync_api_key"
                               id="wp_cognito_sync_api_key"
                               value="<?php echo esc_attr(get_option('wp_cognito_sync_api_key')); ?>"
                               class="regular-text">
                        <p class="description"><?php _e('API Gateway API Key for authentication', 'wp-cognito-auth'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Sync on Login', 'wp-cognito-auth'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="wp_cognito_sync_on_login"
                                   value="1"
                                   <?php checked(get_option('wp_cognito_sync_on_login', false)); ?>>
                            <?php _e('Automatically sync user data when they login', 'wp-cognito-auth'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wp_cognito_sync_groups"><?php _e('Synced Groups', 'wp-cognito-auth'); ?></label>
                    </th>
                    <td>
                        <?php
                        $synced_groups = get_option('wp_cognito_sync_groups', array());
                        $roles = get_editable_roles();
                        foreach ($roles as $role_name => $role_info) {
                            $checked = in_array($role_name, $synced_groups);
                            echo '<label style="display: block; margin-bottom: 5px;">';
                            echo '<input type="checkbox" name="wp_cognito_sync_groups[]" value="' . esc_attr($role_name) . '"' . checked($checked, true, false) . '> ';
                            echo esc_html($role_info['name']) . ' <code>(WP_' . esc_html($role_name) . ')</code>';
                            echo '</label>';
                        }
                        ?>
                        <p class="description"><?php _e('WordPress roles to sync with Cognito groups (groups will be named WP_rolename)', 'wp-cognito-auth'); ?></p>
                    </td>
                </tr>
            </table>
            
            <div class="cognito-test-section">
                <h3><?php _e('Test Sync Connection', 'wp-cognito-auth'); ?></h3>
                <button type="button" id="test-sync-connection" class="button button-secondary">
                    <?php _e('Test Sync API Connection', 'wp-cognito-auth'); ?>
                </button>
                <div id="sync-test-results" style="margin-top: 10px;"></div>
            </div>
            
            <?php submit_button(); ?>
        </form>
        <?php
    }

    public function render_sync_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('User Synchronization', 'wp-cognito-auth'); ?></h1>
            
            <div class="cognito-sync-tools">
                <div class="cognito-card">
                    <h2><?php _e('Bulk Sync Operations', 'wp-cognito-auth'); ?></h2>
                    
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="cognito_bulk_sync">
                        <?php wp_nonce_field('cognito_bulk_sync'); ?>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Sync Direction', 'wp-cognito-auth'); ?></th>
                                <td>
                                    <label>
                                        <input type="radio" name="sync_direction" value="wp_to_cognito" checked>
                                        <?php _e('WordPress to Cognito', 'wp-cognito-auth'); ?>
                                    </label><br>
                                    <label>
                                        <input type="radio" name="sync_direction" value="cognito_to_wp">
                                        <?php _e('Cognito to WordPress', 'wp-cognito-auth'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('User Selection', 'wp-cognito-auth'); ?></th>
                                <td>
                                    <label>
                                        <input type="radio" name="user_selection" value="all" checked>
                                        <?php _e('All Users', 'wp-cognito-auth'); ?>
                                    </label><br>
                                    <label>
                                        <input type="radio" name="user_selection" value="unlinked">
                                        <?php _e('Unlinked Users Only', 'wp-cognito-auth'); ?>
                                    </label><br>
                                    <label>
                                        <input type="radio" name="user_selection" value="role">
                                        <?php _e('By Role:', 'wp-cognito-auth'); ?>
                                        <select name="selected_role">
                                            <?php
                                            $roles = get_editable_roles();
                                            foreach ($roles as $role_name => $role_info) {
                                                echo '<option value="' . esc_attr($role_name) . '">' . esc_html($role_info['name']) . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </label>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <button type="submit" class="button button-primary cognito-bulk-sync">
                                <?php _e('Start Bulk Sync', 'wp-cognito-auth'); ?>
                            </button>
                        </p>
                    </form>
                </div>
                
                <div class="cognito-card">
                    <h2><?php _e('Sync Statistics', 'wp-cognito-auth'); ?></h2>
                    <?php $this->render_sync_stats(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_logs_page() {
        $logs = get_option('wp_cognito_sync_logs', array());
        ?>
        <div class="wrap">
            <h1><?php _e('Synchronization Logs', 'wp-cognito-auth'); ?></h1>
            
            <div class="cognito-logs">
                <div class="logs-controls">
                    <label>
                        <input type="checkbox" class="logs-auto-refresh">
                        <?php _e('Auto-refresh (every 30 seconds)', 'wp-cognito-auth'); ?>
                    </label>
                    
                    <button type="button" class="button cognito-clear-logs">
                        <?php _e('Clear Logs', 'wp-cognito-auth'); ?>
                    </button>
                </div>
                
                <div class="logs-container">
                    <?php if (empty($logs)): ?>
                        <p><?php _e('No synchronization logs available.', 'wp-cognito-auth'); ?></p>
                    <?php else: ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th scope="col"><?php _e('Timestamp', 'wp-cognito-auth'); ?></th>
                                    <th scope="col"><?php _e('Level', 'wp-cognito-auth'); ?></th>
                                    <th scope="col"><?php _e('Message', 'wp-cognito-auth'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr class="log-level-<?php echo esc_attr($log['level']); ?>">
                                        <td><?php echo esc_html($log['timestamp']); ?></td>
                                        <td>
                                            <span class="log-level-badge log-level-<?php echo esc_attr($log['level']); ?>">
                                                <?php echo esc_html(ucfirst($log['level'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo esc_html($log['message']); ?></td>
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
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wp-cognito-auth'));
        }
        
        check_admin_referer('cognito_bulk_sync');
        
        $sync_direction = sanitize_text_field($_POST['sync_direction'] ?? 'wp_to_cognito');
        $user_selection = sanitize_text_field($_POST['user_selection'] ?? 'all');
        $selected_role = sanitize_text_field($_POST['selected_role'] ?? '');
        
        // Initialize background sync process
        set_transient('cognito_bulk_sync_status', array(
            'status' => 'running',
            'direction' => $sync_direction,
            'selection' => $user_selection,
            'role' => $selected_role,
            'started' => current_time('mysql')
        ), HOUR_IN_SECONDS);
        
        // Redirect with success message
        wp_redirect(add_query_arg(array(
            'page' => 'wp-cognito-auth-sync',
            'message' => 'sync_started'
        ), admin_url('admin.php')));
        exit;
    }

    public function handle_test_sync() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wp-cognito-auth'));
        }
        
        check_admin_referer('cognito_test_sync');
        
        // Test sync with current user
        $current_user = wp_get_current_user();
        
        // Initialize API if needed
        if (!$this->api) {
            $features = get_option('wp_cognito_features', []);
            if (!empty($features['sync'])) {
                $this->api = new API();
            }
        }
        
        if ($this->api) {
            $result = $this->api->create_user(array(
                'wp_user_id' => $current_user->ID,
                'email' => $current_user->user_email,
                'username' => $current_user->user_login,
                'first_name' => $current_user->first_name,
                'last_name' => $current_user->last_name
            ));
            
            if ($result) {
                $message = 'test_sync_success';
            } else {
                $message = 'test_sync_failed';
            }
        } else {
            $message = 'sync_not_configured';
        }
        
        wp_redirect(add_query_arg(array(
            'page' => 'wp-cognito-auth-sync',
            'message' => $message
        ), admin_url('admin.php')));
        exit;
    }

    private function render_sync_stats() {
        global $wpdb;
        
        // Get user statistics
        $total_users = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
        $linked_users = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'cognito_user_id' AND meta_value != ''");
        $unlinked_users = $total_users - $linked_users;
        
        ?>
        <table class="wp-list-table widefat">
            <tbody>
                <tr>
                    <td><?php _e('Total WordPress Users', 'wp-cognito-auth'); ?></td>
                    <td><strong><?php echo esc_html($total_users); ?></strong></td>
                </tr>
                <tr>
                    <td><?php _e('Linked to Cognito', 'wp-cognito-auth'); ?></td>
                    <td><strong><?php echo esc_html($linked_users); ?></strong></td>
                </tr>
                <tr>
                    <td><?php _e('Not Linked', 'wp-cognito-auth'); ?></td>
                    <td><strong><?php echo esc_html($unlinked_users); ?></strong></td>
                </tr>
                <tr>
                    <td><?php _e('Last Sync', 'wp-cognito-auth'); ?></td>
                    <td><?php echo esc_html(get_option('wp_cognito_last_sync', __('Never', 'wp-cognito-auth'))); ?></td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    public function ajax_test_connection() {
        check_ajax_referer('wp_cognito_auth_ajax', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(-1);
        }
        
        $user_pool_id = get_option('wp_cognito_auth_user_pool_id');
        $region = get_option('wp_cognito_auth_region');
        
        if (empty($user_pool_id) || empty($region)) {
            wp_send_json_error(__('Please configure User Pool ID and Region first', 'wp-cognito-auth'));
        }
        
        // Test JWKS endpoint
        $jwks_url = "https://cognito-idp.{$region}.amazonaws.com/{$user_pool_id}/.well-known/jwks.json";
        $response = wp_remote_get($jwks_url, ['timeout' => 10]);
        
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            wp_send_json_error(__('Invalid User Pool ID or Region', 'wp-cognito-auth'));
        }
        
        wp_send_json_success(__('Connection successful!', 'wp-cognito-auth'));
    }

    /**
     * Show admin notices for Cognito authentication setup
     */
    public function show_cognito_setup_notices() {
        $features = get_option('wp_cognito_features', []);
        
        // Only show on admin pages
        if (!is_admin()) {
            return;
        }
        
        // Only show to users who can manage options
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check if authentication is enabled but not configured
        if (!empty($features['authentication'])) {
            $user_pool_id = get_option('wp_cognito_auth_user_pool_id');
            $client_id = get_option('wp_cognito_auth_client_id');
            $hosted_ui_domain = get_option('wp_cognito_auth_hosted_ui_domain');
            
            if (empty($user_pool_id) || empty($client_id) || empty($hosted_ui_domain)) {
                ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php _e('Cognito Authentication Incomplete', 'wp-cognito-auth'); ?></strong><br>
                        <?php _e('Cognito authentication is enabled but not fully configured. Please complete the setup in the', 'wp-cognito-auth'); ?>
                        <a href="<?php echo admin_url('admin.php?page=wp-cognito-auth&tab=authentication'); ?>">
                            <?php _e('Authentication Settings', 'wp-cognito-auth'); ?>
                        </a>
                    </p>
                </div>
                <?php
            } else {
                // Show information about login behavior
                $force_cognito = get_option('wp_cognito_auth_force_cognito', false);
                if ($force_cognito) {
                    $emergency_param = get_option('wp_cognito_emergency_access_param');
                    ?>
                    <div class="notice notice-info">
                        <p>
                            <strong><?php _e('Cognito Login Active', 'wp-cognito-auth'); ?></strong><br>
                            <?php _e('Users will be automatically redirected to Cognito for authentication. For emergency WordPress login, use the emergency access URL found in the', 'wp-cognito-auth'); ?>
                            <a href="<?php echo admin_url('admin.php?page=wp-cognito-auth&tab=authentication'); ?>">
                                <?php _e('Authentication Settings', 'wp-cognito-auth'); ?>
                            </a>
                        </p>
                    </div>
                    <?php
                }
            }
        }
    }

    public function show_admin_notices() {
        // Show configuration warnings
        $features = get_option('wp_cognito_features', array());
        
        if (!empty($features['authentication'])) {
            $required_settings = array(
                'wp_cognito_auth_user_pool_id',
                'wp_cognito_auth_client_id',
                'wp_cognito_auth_client_secret',
                'wp_cognito_auth_hosted_ui_domain'
            );
            
            $missing_settings = array();
            foreach ($required_settings as $setting) {
                if (empty(get_option($setting))) {
                    $missing_settings[] = $setting;
                }
            }
            
            if (!empty($missing_settings)) {
                echo '<div class="notice notice-warning"><p>';
                echo __('Cognito Authentication is enabled but missing required settings. Please configure: ', 'wp-cognito-auth');
                echo implode(', ', $missing_settings);
                echo '</p></div>';
            }
        }
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our plugin pages
        if (strpos($hook, 'wp-cognito-auth') === false) {
            return;
        }
        
        wp_enqueue_script(
            'wp-cognito-auth-admin',
            WP_COGNITO_AUTH_PLUGIN_URL . 'assets/admin.js',
            array('jquery'),
            WP_COGNITO_AUTH_VERSION,
            true
        );
        
        wp_enqueue_style(
            'wp-cognito-auth-admin',
            WP_COGNITO_AUTH_PLUGIN_URL . 'assets/admin.css',
            array(),
            WP_COGNITO_AUTH_VERSION
        );
        
        // Localize script for AJAX
        wp_localize_script('wp-cognito-auth-admin', 'wpCognitoAuth', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_cognito_auth_nonce'),
            'strings' => array(
                'testing' => __('Testing...', 'wp-cognito-auth'),
                'success' => __('Connection successful!', 'wp-cognito-auth'),
                'error' => __('Connection failed:', 'wp-cognito-auth')
            )
        ));
    }
    
    private function render_help_tab() {
        ?>
        <div class="wrap">
            <h2><?php _e('Help & Documentation', 'wp-cognito-auth'); ?></h2>
            
            <div class="card">
                <h3><?php _e('Plugin Setup Guide', 'wp-cognito-auth'); ?></h3>
                <p><?php _e('Follow these steps to configure AWS Cognito with your WordPress site:', 'wp-cognito-auth'); ?></p>
                
                <ol>
                    <li><strong><?php _e('Create AWS Cognito User Pool', 'wp-cognito-auth'); ?></strong>
                        <ul>
                            <li><?php _e('Log into AWS Console and navigate to Cognito', 'wp-cognito-auth'); ?></li>
                            <li><?php _e('Create a new User Pool', 'wp-cognito-auth'); ?></li>
                            <li><?php _e('Configure sign-in options (email recommended)', 'wp-cognito-auth'); ?></li>
                        </ul>
                    </li>
                    
                    <li><strong><?php _e('Configure App Client', 'wp-cognito-auth'); ?></strong>
                        <ul>
                            <li><?php _e('Add an App client in your User Pool', 'wp-cognito-auth'); ?></li>
                            <li><?php _e('Enable "Generate client secret"', 'wp-cognito-auth'); ?></li>
                            <li><?php _e('Configure OAuth flows: Authorization code grant', 'wp-cognito-auth'); ?></li>
                            <li><?php _e('Add callback URL:', 'wp-cognito-auth'); ?> <code><?php echo esc_url(home_url('/wp-login.php?cognito_callback=1')); ?></code></li>
                        </ul>
                    </li>
                    
                    <li><strong><?php _e('Set up Hosted UI Domain', 'wp-cognito-auth'); ?></strong>
                        <ul>
                            <li><?php _e('Configure a domain for Cognito Hosted UI', 'wp-cognito-auth'); ?></li>
                            <li><?php _e('Add sign out URLs including your WordPress site URL', 'wp-cognito-auth'); ?></li>
                        </ul>
                    </li>
                    
                    <li><strong><?php _e('Configure Plugin Settings', 'wp-cognito-auth'); ?></strong>
                        <ul>
                            <li><?php _e('Go to Settings > Authentication tab', 'wp-cognito-auth'); ?></li>
                            <li><?php _e('Enter your User Pool ID, Client ID, Client Secret, and Region', 'wp-cognito-auth'); ?></li>
                            <li><?php _e('Test the connection using the "Test Connection" button', 'wp-cognito-auth'); ?></li>
                        </ul>
                    </li>
                </ol>
            </div>
            
            <div class="card">
                <h3><?php _e('Features Overview', 'wp-cognito-auth'); ?></h3>
                
                <h4><?php _e('Authentication', 'wp-cognito-auth'); ?></h4>
                <p><?php _e('Allows users to log in using AWS Cognito instead of WordPress authentication.', 'wp-cognito-auth'); ?></p>
                <ul>
                    <li><?php _e('Option to force all logins through Cognito', 'wp-cognito-auth'); ?></li>
                    <li><?php _e('Automatic user creation from Cognito data', 'wp-cognito-auth'); ?></li>
                    <li><?php _e('Configurable default user role', 'wp-cognito-auth'); ?></li>
                </ul>
                
                <h4><?php _e('User Sync', 'wp-cognito-auth'); ?></h4>
                <p><?php _e('Synchronizes user data and group memberships between Cognito and WordPress.', 'wp-cognito-auth'); ?></p>
                <ul>
                    <li><?php _e('Maps Cognito groups to WordPress roles', 'wp-cognito-auth'); ?></li>
                    <li><?php _e('Syncs user profile information', 'wp-cognito-auth'); ?></li>
                    <li><?php _e('Custom field mapping support', 'wp-cognito-auth'); ?></li>
                </ul>
                
                <h4><?php _e('Content Restriction', 'wp-cognito-auth'); ?></h4>
                <p><?php _e('Restrict content access based on Cognito group membership.', 'wp-cognito-auth'); ?></p>
                <ul>
                    <li><?php _e('Use shortcodes: [cognito_restrict groups="group1,group2"]Content[/cognito_restrict]', 'wp-cognito-auth'); ?></li>
                    <li><?php _e('Configure restrictions in post/page editor', 'wp-cognito-auth'); ?></li>
                    <li><?php _e('Automatic content filtering', 'wp-cognito-auth'); ?></li>
                </ul>
            </div>
            
            <div class="card">
                <h3><?php _e('Troubleshooting', 'wp-cognito-auth'); ?></h3>
                
                <h4><?php _e('Common Issues', 'wp-cognito-auth'); ?></h4>
                
                <p><strong><?php _e('Login redirects to error page', 'wp-cognito-auth'); ?></strong></p>
                <ul>
                    <li><?php _e('Check that callback URL is correctly configured in Cognito', 'wp-cognito-auth'); ?></li>
                    <li><?php _e('Verify client secret is correct', 'wp-cognito-auth'); ?></li>
                    <li><?php _e('Enable WP_DEBUG to see detailed error logs', 'wp-cognito-auth'); ?></li>
                </ul>
                
                <p><strong><?php _e('Users not being created', 'wp-cognito-auth'); ?></strong></p>
                <ul>
                    <li><?php _e('Check if "Auto-create users" is enabled', 'wp-cognito-auth'); ?></li>
                    <li><?php _e('Verify default role is set correctly', 'wp-cognito-auth'); ?></li>
                    <li><?php _e('Check WordPress error logs for user creation failures', 'wp-cognito-auth'); ?></li>
                </ul>
                
                <p><strong><?php _e('First/Last names not being set', 'wp-cognito-auth'); ?></strong></p>
                <ul>
                    <li><?php _e('Check error logs to see what name fields Cognito is providing', 'wp-cognito-auth'); ?></li>
                    <li><?php _e('Ensure given_name and family_name attributes are enabled in Cognito', 'wp-cognito-auth'); ?></li>
                    <li><?php _e('Plugin will split the "name" field if given_name/family_name are not available', 'wp-cognito-auth'); ?></li>
                </ul>
                
                <p><strong><?php _e('Login with Cognito button not showing', 'wp-cognito-auth'); ?></strong></p>
                <ul>
                    <li><?php _e('Check that Authentication feature is enabled', 'wp-cognito-auth'); ?></li>
                    <li><?php _e('Ensure "Force Cognito authentication" is disabled if you want to show the button', 'wp-cognito-auth'); ?></li>
                    <li><?php _e('Check for theme/plugin conflicts that might interfere with login form hooks', 'wp-cognito-auth'); ?></li>
                    <li><?php _e('The plugin includes a JavaScript fallback that should add the button automatically', 'wp-cognito-auth'); ?></li>
                </ul>
                
                <p><strong><?php _e('Logout not working properly', 'wp-cognito-auth'); ?></strong></p>
                <ul>
                    <li><?php _e('Ensure logout URLs are configured in Cognito', 'wp-cognito-auth'); ?></li>
                    <li><?php _e('Check that Hosted UI domain is set correctly', 'wp-cognito-auth'); ?></li>
                </ul>
                
                <h4><?php _e('Debug Information', 'wp-cognito-auth'); ?></h4>
                <p><?php _e('Enable WordPress debug mode to see detailed logs:', 'wp-cognito-auth'); ?></p>
                <pre><code>define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);</code></pre>
                <p><?php _e('Check logs in:', 'wp-cognito-auth'); ?> <code>/wp-content/debug.log</code></p>
            </div>
            
            <div class="card">
                <h3><?php _e('Support', 'wp-cognito-auth'); ?></h3>
                <p><?php _e('For additional support and documentation:', 'wp-cognito-auth'); ?></p>
                <ul>
                    <li><a href="https://docs.aws.amazon.com/cognito/" target="_blank"><?php _e('AWS Cognito Documentation', 'wp-cognito-auth'); ?></a></li>
                    <li><?php _e('Plugin version:', 'wp-cognito-auth'); ?> <strong>1.0.0</strong></li>
                </ul>
            </div>
        </div>
        <?php
    }
}