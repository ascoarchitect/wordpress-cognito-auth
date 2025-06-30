# WordPress Cognito Authentication & Sync Plugin

A comprehensive, production-ready WordPress plugin that integrates Amazon Cognito authentication with user synchronization and content restriction capabilities.

## 🚀 Features

### Core Authentication Features
- **Amazon Cognito Integration**: Full OIDC/JWT authentication with AWS Cognito User Pools
- **Hosted UI Support**: Seamless integration with Cognito's hosted authentication UI  
- **Secure Token Handling**: JWT token validation and management with proper JWKS verification
- **Smart Login Flows**: Intelligent redirect handling for both admin and regular users
- **Emergency Access**: Secure emergency WordPress login bypass with unique access parameters
- **Auto User Creation**: Automatically create WordPress users from Cognito authentication
- **Role Mapping**: Map Cognito groups to WordPress roles

### User Synchronization Features
- **Bidirectional Sync**: Two-way user data synchronization between Cognito and WordPress
- **Group Sync**: Automatically sync Cognito group memberships to WordPress roles
- **Profile Sync**: Sync user profile information (name, email, custom attributes)
- **Background Processing**: Bulk sync operations with progress tracking
- **Real-time Updates**: Sync user data on login events

### Content Restriction System
- **Shortcode Protection**: `[cognito_restrict]` shortcodes for inline content protection
- **Page/Post Editor Integration**: Visual meta box for setting access rules in the WordPress editor
- **Role-based Access**: Restrict content based on WordPress user roles
- **Group-based Access**: Restrict content based on Cognito group memberships
- **Flexible Logic**: Support for "any" or "all" group membership requirements
- **Automatic Filtering**: Content is automatically filtered based on user permissions

### Security Features
- **JWT Validation**: Proper JWT signature verification using Cognito's JWKS
- **State Parameter Protection**: CSRF protection using WordPress nonces
- **Secure Emergency Access**: Complex random parameter for emergency WordPress login
- **Token Refresh**: Automatic token refresh and session management
- **Logout Integration**: Proper logout from both WordPress and Cognito

## 📦 Installation

1. **Upload the Plugin**
   ```
   Upload files to: /wp-content/plugins/wp-cognito-auth/
   ```

2. **Activate the Plugin**
   - Go to WordPress Admin → Plugins
   - Find "WordPress Cognito Authentication & Sync"
   - Click "Activate"

3. **Configure Features**
   - Navigate to **Cognito Auth** in WordPress admin menu
   - Enable desired features in the **Features** tab

## ⚙️ Configuration

### Step 1: AWS Cognito Setup

#### Create User Pool
1. Log into AWS Console and navigate to Amazon Cognito
2. Create a new User Pool
3. Configure sign-in options (email recommended)
4. Set up required and optional attributes:
   - **Required**: email
   - **Optional**: given_name, family_name, preferred_username
5. Configure password policies and MFA as needed

#### Configure App Client
1. Add an App Client to your User Pool
2. **Enable "Generate client secret"**
3. Configure OAuth 2.0 settings:
   - **Allowed OAuth Flows**: Authorization code grant
   - **Allowed OAuth Scopes**: openid, email, profile
   - **Callback URLs**: Add your WordPress callback URL (shown in plugin settings)
   - **Sign out URLs**: Add your WordPress site URLs

#### Set up Hosted UI
1. Configure a domain for Cognito Hosted UI
2. Customize the login/signup pages as needed
3. Note the hosted UI domain for plugin configuration

### Step 2: Plugin Configuration

#### Enable Features
Navigate to **Cognito Auth → Features** and enable:
- ✅ **Authentication**: Core Cognito login functionality
- ✅ **User Sync**: Bidirectional user synchronization (optional)
- ✅ **Group Sync**: Cognito group to WordPress role mapping (optional)

#### Authentication Settings
Go to **Cognito Auth → Settings → Authentication**:

| Setting | Description | Example |
|---------|-------------|---------|
| **User Pool ID** | Your Cognito User Pool identifier | `us-east-1_xxxxxxxxx` |
| **App Client ID** | OAuth App Client ID from Cognito | `1234567890abcdef` |
| **App Client Secret** | OAuth App Client Secret from Cognito | `secret123...` |
| **AWS Region** | AWS region where your User Pool is located | `us-east-1` |
| **Hosted UI Domain** | Cognito Hosted UI domain (without https://) | `yourapp.auth.us-east-1.amazoncognito.com` |
| **Auto-create Users** | ✅ Automatically create WordPress users from Cognito | Recommended: Enabled |
| **Default Role** | Default WordPress role for new users | `subscriber` |
| **Force Cognito Auth** | Skip WordPress login form entirely | Optional |

#### Emergency Access Configuration
⚠️ **Important**: When "Force Cognito Authentication" is enabled, save the emergency access URL shown in the settings. This is your only way to access WordPress admin if Cognito fails.

Example emergency URL:
```
https://yoursite.com/wp-login.php?lkjnveriwnfg45845ong4ng8n45ovn4598n34n92=1
```

#### Callback URL Setup
Add this URL to your Cognito App Client's "Allowed callback URLs":
```
https://yoursite.com/wp-login.php?cognito_callback=1
```

**AWS Console Path**: Cognito → User Pools → Your Pool → App integration → Your App Client → Edit Hosted UI

### Step 3: User Sync Configuration (Optional)

If you enabled User Sync, configure these settings in **Cognito Auth → Settings → Sync**:

| Setting | Description |
|---------|-------------|
| **Sync API URL** | Your Lambda/API Gateway endpoint for user sync |
| **API Key** | API Gateway API Key for authentication |
| **Sync on Login** | Automatically sync user data when they login |
| **Synced Groups** | WordPress roles to sync with Cognito groups |

### Step 4: Test the Setup

1. **Test Connection**: Use the "Test Cognito Connection" button in Authentication settings
2. **Test Login**: Try logging in via Cognito
3. **Verify User Creation**: Check that WordPress users are created correctly
4. **Test Emergency Access**: Verify the emergency access URL works

## 🎯 Usage

### Basic Authentication Flow

1. **User visits login page**: `/wp-login.php`
2. **Clicks "Login with Cognito"** or is auto-redirected (if forced)
3. **Redirected to Cognito Hosted UI** for authentication
4. **After successful authentication**, redirected back to WordPress
5. **WordPress user created/updated** automatically
6. **User logged into WordPress** with appropriate role

### Content Restriction

#### Using Shortcodes
Restrict content to specific Cognito groups:
```html
[cognito_restrict groups="premium,vip"]
This content is only visible to premium and vip users.
[/cognito_restrict]
```

Restrict content to specific WordPress roles:
```html
[cognito_restrict roles="editor,administrator"]
This content is only visible to editors and administrators.
[/cognito_restrict]
```

Require ALL groups/roles instead of ANY:
```html
[cognito_restrict groups="premium,verified" require_all="true"]
User must be in BOTH premium AND verified groups.
[/cognito_restrict]
```

#### Using Page/Post Editor
1. Edit any page or post
2. Find the "Cognito Content Restrictions" meta box
3. Set access rules using the visual interface
4. Publish/update the content

#### Programmatic Usage
```php
// Check if user has access to specific groups
if (wp_cognito_user_has_groups(['premium', 'vip'])) {
    // Show premium content
}

// Get user's Cognito groups
$groups = get_user_meta(get_current_user_id(), 'cognito_groups', true);
```

### User Synchronization

#### Manual Bulk Sync
1. Go to **Cognito Auth → User Sync**
2. Choose sync direction (WordPress → Cognito or Cognito → WordPress)
3. Select users (All, Unlinked only, or by Role)
4. Click "Start Bulk Sync"

#### Automatic Sync
- Enable "Sync on Login" to automatically sync user data when they authenticate
- Configure which WordPress roles should be synced as Cognito groups

## 🔧 Advanced Configuration

### Custom Redirects
Control where users go after login:
```php
// Redirect premium users to special dashboard
add_filter('wp_cognito_login_redirect', function($redirect_url, $user) {
    $groups = get_user_meta($user->ID, 'cognito_groups', true);
    if (in_array('premium', $groups)) {
        return '/premium-dashboard/';
    }
    return $redirect_url;
}, 10, 2);
```

### Custom User Attribute Mapping
```php
// Map custom Cognito attributes to WordPress user meta
add_action('wp_cognito_user_updated', function($user_id, $cognito_data) {
    if (isset($cognito_data['custom:department'])) {
        update_user_meta($user_id, 'department', $cognito_data['custom:department']);
    }
}, 10, 2);
```

### Content Filter Customization
```php
// Add custom logic to content filtering
add_filter('wp_cognito_restrict_content', function($show_content, $required_groups, $user_groups) {
    // Custom access logic here
    return $show_content;
}, 10, 3);
```

## 🛠️ Troubleshooting

### Common Issues

#### "Login redirects to error page"
- ✅ Check that callback URL is correctly configured in Cognito
- ✅ Verify client secret is correct and hasn't been regenerated
- ✅ Ensure OAuth flows are properly configured in Cognito
- ✅ Check WordPress error logs for detailed information

#### "Users not being created"
- ✅ Verify "Auto-create users" is enabled
- ✅ Check that default role is set correctly
- ✅ Ensure email attribute is being provided by Cognito
- ✅ Check WordPress user creation permissions

#### "Login with Cognito button not showing"
- ✅ Ensure Authentication feature is enabled
- ✅ Verify "Force Cognito authentication" is disabled if you want to show the button
- ✅ Check for theme/plugin conflicts affecting login form hooks
- ✅ Plugin includes JavaScript fallback for theme compatibility

#### "First/Last names not being set"
- ✅ Enable `given_name` and `family_name` attributes in Cognito User Pool
- ✅ Configure these as readable attributes in your App Client
- ✅ Plugin will split the `name` field as fallback if individual names aren't available

#### "Logout not working properly"
- ✅ Configure logout URLs in Cognito Hosted UI settings
- ✅ Verify Hosted UI domain is set correctly in plugin settings
- ✅ Check that sign out URLs include your WordPress site

#### "Emergency access not working"
- ✅ Use the exact emergency URL from the plugin settings
- ✅ Emergency parameter is case-sensitive and must match exactly
- ✅ If lost, deactivate and reactivate plugin to generate new parameter

### Debug Information

Enable WordPress debug mode for detailed logs:
```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check logs in: `/wp-content/debug.log`

### Plugin Conflicts
Some known compatibility considerations:
- **Security plugins**: May interfere with JWT token validation
- **Caching plugins**: May cache authentication states incorrectly  
- **Custom login plugins**: May conflict with login form modifications

## 📋 Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 7.2 or higher
- **AWS Cognito**: User Pool with App Client
- **HTTPS**: Required for production (Cognito requirement)
- **cURL**: For API communications

## 📄 License

GPL v3 or later

## 🆘 Support

### Documentation Resources
- [AWS Cognito Documentation](https://docs.aws.amazon.com/cognito/)
- [Cognito Hosted UI Guide](https://docs.aws.amazon.com/cognito/latest/developerguide/cognito-user-pools-app-integration.html)
- [OAuth 2.0 Flows](https://docs.aws.amazon.com/cognito/latest/developerguide/authorization-endpoint.html)

### Plugin Information
- **Version**: 2.1.0
- **Author**: Adam Scott
- **Tested up to**: WordPress 6.6
- **Stable tag**: 2.1.0

For technical support, please check the troubleshooting section above and WordPress debug logs for specific error messages.

## 🔌 Hooks and Filters

### Actions
```php
// Fired when user successfully authenticates with Cognito
do_action('wp_cognito_user_authenticated', $user_id, $cognito_data);

// Fired when user data is synced
do_action('wp_cognito_user_synced', $user_id, $sync_data);

// Fired when user is created from Cognito
do_action('wp_cognito_user_created', $user_id, $cognito_data);
```

### Filters
```php
// Filter login redirect URL
apply_filters('wp_cognito_login_redirect', $redirect_url, $user);

// Filter content access permissions
apply_filters('wp_cognito_restrict_content', $show_content, $required_groups, $user_groups);

// Filter user role mapping from Cognito groups
apply_filters('wp_cognito_map_groups_to_roles', $roles, $groups);

// Filter user creation data
apply_filters('wp_cognito_user_creation_data', $user_data, $cognito_data);
```

## 📁 Plugin Structure

```
wp-cognito-auth/
├── wp-cognito-auth.php          # Main plugin file and activation hooks
├── includes/
│   ├── Plugin.php               # Core plugin initialization
│   ├── Admin.php                # WordPress admin interface
│   ├── Auth.php                 # Cognito authentication logic
│   ├── API.php                  # External API integration for sync
│   ├── Sync.php                 # User synchronization engine
│   ├── User.php                 # User management and profile updates
│   ├── JWT.php                  # JWT token validation and handling
│   ├── ContentFilter.php        # Content restriction system
│   └── MetaBox.php              # Post/page editor integration
├── assets/
│   ├── admin.css                # Admin interface styling
│   ├── admin.js                 # Admin JavaScript functionality
│   └── icon.svg                 # Plugin menu icon
└── languages/
    └── wp-cognito-auth.pot      # Translation template
```

## 🔐 Security Considerations

- **HTTPS Required**: Cognito requires HTTPS for token exchange in production
- **Secure Storage**: Client secrets and API keys are stored in WordPress options (consider using wp-config.php constants for enhanced security)
- **Token Validation**: All JWT tokens are validated against Cognito's JWKS endpoint
- **Emergency Access**: Unique emergency access parameter prevents predictable bypass URLs
- **CSRF Protection**: State parameters include WordPress nonces for additional security
- **Regular Rotation**: Rotate Cognito client secrets and API keys regularly

## 📝 Changelog

### Version 2.1.0
- **Enhanced Emergency Access**: Replaced simple `wp_login=1` with complex random parameter
- **Improved Security**: More secure emergency WordPress access method
- **Documentation Update**: Comprehensive README with full feature coverage
- **Code Cleanup**: Removed all debug code for production readiness
- **Smart Redirects**: Better handling of logged-in users visiting login page

### Version 2.0.0
- **Content Restriction System**: Full shortcode and meta box integration
- **Robust Login Flows**: Improved redirect handling and fallback mechanisms
- **Name Field Mapping**: Smart handling of name attributes from Cognito
- **Multi-layer Login Button**: Multiple hooks and JavaScript fallback for theme compatibility
- **Group Synchronization**: Cognito groups mapped to WordPress user meta
- **Logout Integration**: Proper Cognito and WordPress logout handling

### Version 1.0.0
- Initial release with core Cognito authentication
- Basic user synchronization
- JWT token handling
- WordPress admin interface
