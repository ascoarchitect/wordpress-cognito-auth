# WordPress Cognito Authentication & Sync Plugin

A comprehensive, production-ready WordPress plugin that integrates Amazon Cognito authentication with user synchronization and content restriction capabilities.

## Version 3.0 — Tenant-aware sync (current)

> **Read this before relying on anything below.** v3.0 is a substantive
> rewrite of the sync layer. The plugin is now a **dumb pipe**: each event
> sends one payload (with `tenantId` + full `wp_roles[]`) to the sync
> Lambda, which owns Cognito group reconciliation, TenantMemberships
> upserts, and StudentProfile mirroring. See `ARCHITECTURE.md` for the
> WordPress-side overview, and `yacht-charter-app/documentation/WP_COGNITO_SYNC_V3.md`
> for the full end-to-end data flow with diagrams.

**What changed:**

- **Sync Settings → Tenant ID** is required. Without it, every sync push is
  aborted (logged as an error). Set this on each WordPress install so the
  Lambda knows which tenant it is.
- **Bulk sync** now enqueues to SQS. Each click processes ≤ 100 users
  (4 batches of 25); larger user bases resume from a saved offset on the
  next click. Designed for 800+ users without hitting the WP 15s timeout
  or Cognito's ~25 RPS admin-API limit.
- **Group sync** — the per-role enable/disable UI and the group-creation
  flow are no-ops in v3.0. Cognito groups (`MyBosun_{tenantId}_{level}`)
  are created on demand by the sync Lambda, driven by the per-tenant
  `accessLevelMap` in `TenantConfig.wpSync`.
- **Sync direction** is one-way (WC → MyBosun) for owned fields. There are
  no outbound MyBosun → WP calls. This may flip in a future iteration.

The sections below describe the v2.x feature set. Anything related to
"per-role group sync", "WP_{role} groups", or "bidirectional sync" is
historical and superseded by the v3.0 model above.

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
- **One-way Sync (v3.0)**: Outbound user data sync from WordPress to the MyBosun
  Lambda (Cognito + DynamoDB). The "bidirectional" flow described in older
  versions has been removed; see the v3.0 banner above.
- **Tenant-aware Group Reconciliation**: Each sync push includes the tenant ID
  and the user's full WP roles; the Lambda reconciles
  `MyBosun_{tenantId}_{level}` group membership.
- **Profile Sync**: Sync user profile information (name, email, custom attributes)
- **SQS-buffered Bulk Sync**: Bulk operations enqueue users to SQS in batches
  of 25 with a saved resume offset (designed for 800+ users without timing
  out the WP request).
- **Real-time Updates**: Sync user data on profile and role-change events

### Group Management System (v2.x — superseded)
> The visual group-management UI and per-role enable/disable controls below
> are **no-ops in v3.0**. Cognito groups (`MyBosun_{tenantId}_{level}`) are
> created on demand by the sync Lambda, driven by the per-tenant
> `accessLevelMap` in `TenantConfig.wpSync`. The tab still renders for
> backwards compatibility but its toggles do not affect group creation.
- **Visual Interface**: Dedicated group management tab with table view of all WordPress roles
- **Individual Control**: Enable/disable group sync for each WordPress role independently
- **User Count Display**: Shows how many users are in each role for better management
- **Status Indicators**: Clear visual indicators for enabled/disabled sync state
- **Bulk Operations**: Test and full sync operations specifically for groups

### Enhanced Bulk Sync Interface
- **User Selection Options**: Sync all users or filter by specific WordPress roles
- **Progressive Disclosure**: Dynamic form elements that show/hide based on selections
- **Form Validation**: JavaScript validation to prevent invalid submissions
- **Clear Descriptions**: Each option includes explanatory text for better user experience
- **Organized Layout**: Dedicated bulk sync tab with proper sections and styling

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

## 🎛️ Admin Interface

### Navigation Tabs
The plugin provides a well-organized admin interface with dedicated tabs for different functionality:

#### Features Tab
- **Feature Toggles**: Enable/disable Authentication, User Sync, and Group/Role Synchronization
- **Dependency Management**: Features automatically disable dependent features when turned off
- **Clear Descriptions**: Each feature includes explanatory text about its functionality

#### Authentication Settings Tab
- **Cognito Configuration**: User Pool ID, Client ID, Client Secret, Region settings
- **Hosted UI Settings**: Domain configuration and OAuth settings
- **User Creation Settings**: Auto-create users, default roles, and authentication modes
- **Login Button Customization**: Text, colors, and branding options
- **Emergency Access**: Secure emergency login parameter generation and display

#### Sync Settings Tab (when User Sync enabled)
- **API Configuration**: API URL and key for external sync operations
- **Sync Behavior**: Configure when and how users are synchronized
- **Connection Testing**: Test API connectivity and configuration

#### Group Management Tab (when Group Sync enabled)
- **Visual Role Table**: All WordPress roles displayed with sync status
- **Individual Controls**: Enable/disable sync for each role independently
- **Automatic Group Creation**: Groups are created in Cognito when sync is enabled
- **User Count Display**: Shows how many users are in each role
- **Status Indicators**: Clear enabled/disabled status for each role
- **Current Sync Summary**: Overview of which groups are currently being synchronized

#### Bulk Sync Tab
- **User Sync Operations**:
  - Test sync to preview changes
  - Full sync with user selection options (All Users or Users by Role)
  - Dynamic role selection with form validation
- **Group Sync Operations** (when Group Sync enabled):
  - Test group sync to preview group creation and membership changes
  - Full group sync to create groups and update memberships
- **Statistics Display**: Results and progress of sync operations
- **Clear Form Layout**: Organized sections with proper validation and feedback

#### Logs Tab
- **Sync History**: Detailed logs of all synchronization operations
- **Error Tracking**: Comprehensive error logging with timestamps
- **Debug Information**: Detailed information for troubleshooting

#### Setup Guide Tab
- **Step-by-step Instructions**: Complete setup process from AWS to WordPress
- **Configuration Examples**: Real examples of settings and URLs
- **Troubleshooting Guide**: Common issues and solutions

### UI/UX Improvements in Version 2.2.0

#### Enhanced Bulk Sync Interface
**Problems Fixed**:
- Confusing radio buttons that both appeared selected
- Non-functional "Cognito → WordPress" sync option
- Poor form layout and organization
- Missing validation for role-based sync

**Solutions Implemented**:
- Removed confusing sync direction options
- Added clear user selection options with descriptions:
  - **All Users**: Sync all WordPress users to Cognito (default)
  - **Users by Role**: Sync only users with a specific WordPress role
- Dynamic role selection dropdown that appears when "Users by Role" is selected
- JavaScript form validation to ensure role is selected when required
- Improved button positioning and styling
- Enhanced admin notices for better user feedback

#### Group Management Interface
**New Features Added**:
- Clean table layout showing all WordPress roles
- Visual sync status indicators (Enabled/Disabled)
- User count display for each role
- Individual enable/disable controls for each role
- Automatic Cognito group creation when sync is enabled
- Clear summary of currently synced groups

**Technical Implementation**:
```javascript
// Dynamic form controls
$('input[name="user_selection"]').on('change', function() {
    if ($(this).val() === 'role') {
        $('#role-selection').show();
    } else {
        $('#role-selection').hide();
    }
});

// Form validation
$('form[action*="cognito_bulk_sync"]').on('submit', function(e) {
    var userSelection = $('input[name="user_selection"]:checked').val();
    if (userSelection === 'role') {
        var selectedRole = $('select[name="selected_role"]').val();
        if (!selectedRole) {
            e.preventDefault();
            alert('Please select a role to sync.');
            return false;
        }
    }
});
```

### Admin Action Handlers
The plugin handles various admin actions through dedicated methods:

- `cognito_toggle_group_sync`: Enable/disable sync for individual WordPress roles
- `cognito_test_group_sync`: Preview group sync operations without making changes
- `cognito_full_group_sync`: Execute full group synchronization
- `cognito_bulk_sync`: Handle user synchronization with improved selection options
- `cognito_test_sync`: Test user synchronization

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
| **Login Button Text** | Customize the text shown on Cognito login buttons | `Member Login`, `RAFSA Login`, etc. |
| **Login Button Color** | Choose a custom color for the login button | Any hex color (e.g., `#007cba`, `#28a745`) |

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
1. Go to **Cognito Auth → Bulk Sync**
2. Choose sync type:
   - **User Sync**: Synchronize WordPress users with Cognito
   - **Group Sync**: Synchronize WordPress roles with Cognito groups (when enabled)
3. For User Sync, select scope:
   - **All Users**: Sync all WordPress users to Cognito
   - **Users by Role**: Sync only users with a specific WordPress role
4. Click "Start Bulk Sync" or "Test Sync" to preview changes

#### Group Management and Synchronization
The plugin provides comprehensive group management functionality for syncing WordPress roles with Cognito groups.

##### Setting Up Group Sync
1. **Enable Feature**: Go to **Cognito Auth → Features** and enable "Group/Role Synchronization"
2. **Configure API**: Go to **Cognito Auth → Sync Settings** and configure API URL and Key
3. **Manage Groups**: Go to **Cognito Auth → Group Management**

##### Group Management Interface
The Group Management tab provides a visual interface for managing which WordPress roles should sync with Cognito groups:

**Features**:
- **Role Overview Table**: Shows all WordPress roles with their sync status
- **User Count Display**: Shows how many users are assigned to each role
- **Individual Controls**: Enable/disable sync for each role independently
- **Status Indicators**: Clear visual indicators for enabled/disabled state
- **Automatic Group Creation**: When you enable sync for a role, the corresponding Cognito group is automatically created

**How It Works**:
1. **Enable Sync for a Role**: Click "Enable Sync" next to any WordPress role (e.g., "editor")
2. **Automatic Group Creation**: System creates a corresponding group in Cognito (e.g., "WP_editor")
3. **User Assignment**: Users with that WordPress role are automatically added to the Cognito group
4. **Ongoing Sync**: Group memberships are kept in sync during user logins and bulk sync operations

##### Group Sync Operations
**Test Group Sync**:
- Preview which groups would be created in Cognito
- Shows which users would be added/removed from groups
- No actual changes are made - preview only

**Full Group Sync**:
- Creates all enabled groups in Cognito
- Updates all user group memberships
- Synchronizes current WordPress role assignments with Cognito groups
- Provides statistics of operations performed

##### Group Naming Convention
- WordPress roles are mapped to Cognito groups with the prefix "WP_"
- Examples:
  - WordPress role "editor" → Cognito group "WP_editor"
  - WordPress role "subscriber" → Cognito group "WP_subscriber"
  - WordPress role "custom_member" → Cognito group "WP_custom_member"

##### Automatic Group Membership Management
When group sync is enabled, the plugin automatically manages group memberships:

1. **User Login**: When a user logs in, their group memberships are updated based on their WordPress roles
2. **Role Changes**: When a user's WordPress role changes, their Cognito group memberships are updated
3. **Bulk Sync**: During bulk sync operations, all group memberships are reconciled
4. **New Users**: When new users are created, they're automatically added to appropriate groups

#### Automatic Sync
- Enable "Sync on Login" to automatically sync user data when they authenticate
- Configure which WordPress roles should be synced as Cognito groups
- Real-time group membership updates based on role changes

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

### Login Button Customization
The plugin provides built-in customization options for login buttons to match your site's branding and context:

#### Admin Settings
Go to **Cognito Auth → Authentication → Settings** to customize:

- **Button Text**: Change from "Login with Cognito" to contextual text like:
  - `Member Login`
  - `RAFSA Login`
  - `Staff Portal`
  - `Secure Access`

- **Button Color**: Choose any hex color to match your brand:
  - Corporate blue: `#007cba`
  - Professional green: `#28a745`
  - Custom brand colors: `#ff6b35`, `#4ecdc4`, etc.

- **Button Text Color**: Customize the text color for optimal contrast:
  - White text: `#ffffff` (default, works with most backgrounds)
  - Black text: `#000000` (good for light backgrounds)
  - Custom colors: `#333333` for dark gray, `#ffffff` for pure white

#### Real-time Preview
The admin interface includes a live preview that updates as you change the text and color, showing exactly how your customized button will appear to users.

#### Where Customization Applies
Your custom button text and color are applied to:
- Login form buttons (when not using Force Cognito mode)
- Content restriction messages via `[cognito_restrict]` shortcode
- Page/post access restriction prompts
- User re-authentication prompts

#### Example Use Cases
- **Membership Sites**: Use "Member Login" with your brand colors
- **Professional Organizations**: "RAFSA Login" for association members
- **Corporate Intranets**: "Staff Portal" with corporate brand colors
- **Educational Platforms**: "Student Access" with institutional colors

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
- **Version**: 2.3.2
- **Author**: Adam Scott
- **Tested up to**: WordPress 6.6
- **Stable tag**: 2.3.2

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

### Version 3.0.5
- **Sync Lambda — email-based sub resolution on update**: After the v3.0 shift
  to an asynchronous SQS path, the plugin never receives the Cognito `sub`
  back from a create, so every subsequent `update` arrives at the Lambda with
  no `cognito_user_id`. The old fallback path called `createUser` in that
  case and raised `UsernameExistsException` against the already-created
  Cognito record, pushing the message through SQS retries into the DLQ.
  `updateUser` now resolves the sub by `AdminGetUser(Username: email)` first,
  recurses into the normal update flow, and only falls through to `createUser`
  on `UserNotFoundException`. Downstream `TenantMemberships` upserts now run
  consistently on every update, so post-auth role-sync no longer mirrors
  stale state.
- **Sync Lambda — new `resolve_subs` action**: Synchronous batch lookup
  endpoint (`POST /sync` with `action: "resolve_subs"`) that takes up to 100
  `{ wp_user_id, email }` pairs and returns `{ mappings, notFound, errored }`.
  Used by the plugin to rebuild `cognito_user_id` user meta after bulk sync,
  restoring the v2.x local record without reintroducing an outbound call from
  the Lambda to WordPress.
- **Plugin — "Backfill Cognito IDs" admin control**: New card on the Bulk
  Sync tab that walks every WordPress user missing `cognito_user_id` meta,
  calls `resolve_subs` in 50-user batches, and writes the sub back via
  `update_user_meta`. Resumable via a transient offset like the bulk sync
  runner; safe to re-run — only unlinked users are queried.

### Version 2.3.2
- **Enhanced Security**: Replaced all `wp_redirect()` calls with `wp_safe_redirect()` for improved security
- **Allowed Redirect Hosts**: Added Cognito hosted UI domain to WordPress allowed redirect hosts filter
- **Secure External Redirects**: Maintained functionality for Cognito authentication and logout flows while using secure redirect functions
- **Code Quality**: Removed outdated comments about using unsafe redirects for external URLs
- **Security Best Practices**: Now follows WordPress security standards for redirect handling

### Version 3.0.4
- **Infrastructure — producer/consumer concurrency partitioning**: The sync
  Lambda is invoked on two paths that previously fought for the same
  concurrency pool: the API Gateway producer (`bulk_sync_enqueue`) and the
  SQS trigger (per-message consumer). Under bulk load the consumer would
  scale up to the full `ReservedConcurrentExecutions`, starve the producer,
  and API Gateway would return generic `500 "Internal server error"` to the
  plugin for every batch whose enqueue arrived while the queue was draining.
  Fixed by raising `ReservedConcurrentExecutions` to 10 and capping the SQS
  trigger at `ScalingConfig.MaximumConcurrency: 3`, guaranteeing 7 slots for
  the producer at all times. SQS now does the buffering it was intended to
  do; the plugin never sees a concurrency-driven 500.
- **Plugin — removed the pause-on-throttle client logic shipped in 3.0.3**:
  With the root cause fixed at the infrastructure layer, the
  `last_error` / transient-status-code classification in `send_api_request`,
  the `throttled` stats branch in `Sync::bulk_sync_users`, and the
  exponential-backoff `scheduleThrottleResume` loop in the admin JS are all
  gone. They papered over the real problem and, in practice, stalled bulk
  runs in a backoff loop because every tick hit the same starved producer.

### Version 3.0.3
- **Superseded by 3.0.4.** The pause-on-throttle plugin-side workaround
  introduced in this release mis-diagnosed the root cause and led to stalled
  bulk runs under sustained load. All 3.0.3 behaviour has been reverted;
  3.0.4 fixes the real issue at the infrastructure layer. Do not deploy 3.0.3.

### Version 3.0.2
- **Bulk sync — JS-driven auto-continuation with progress bar**: Bulk sync no
  longer requires the admin to re-click the button between batches. The admin
  page now intercepts the form submit, runs an AJAX tick loop against a new
  `wp_ajax_cognito_bulk_sync_step` handler, and renders a live progress bar
  (processed / total, enqueued, failed). Includes a Stop button so the run can
  be paused and resumed from the saved offset on the next click.
- **Bulk sync — accept any 2xx response**: `send_api_request` was hardcoded to
  reject anything other than HTTP 200, causing the SQS-enqueue endpoint's
  legitimate `202 Accepted` to be reported as "all failing". Relaxed to
  `>= 200 && < 300`.
- **Bulk sync — unwrap the Lambda `result` envelope**: `bulk_sync_enqueue` now
  reads `$response['result']['enqueuedCount']` correctly (previously it looked
  one level too high and reported zero enqueues even on success).

### Version 3.0.1
- **Sync Lambda — UUID-validate `cognito_user_id` from WP payload**: the
  plugin's "create -> already exists -> update" fallback path passes the
  user's email as `cognito_user_id`. The Lambda used to trust this verbatim
  and write the email into TenantMembership as the `userId` PK — leaving the
  post-auth Lambda unable to find the membership at login. Now validates the
  passed value against the Cognito sub format (UUIDv4) and falls back to
  `AdminGetUser` when it's not a real sub.
- **Sync Lambda — return resolved sub on update path**: `AdminUpdateUserAttributes`
  doesn't return a `User` object, so the WP plugin's existing extractor never
  saw the sub on update responses and `cognito_user_id` user-meta was never
  repopulated after an admin clicked "Unlink". Lambda now decorates the
  response with `result.User.Attributes` containing the resolved sub on both
  create and update paths.
- **Plugin — `update_user` self-heals legacy meta**: the previous guard only
  wrote new meta when the returned sub differed from the request payload's
  `cognito_user_id`. The check now compares against the **stored** user-meta,
  so unlink-then-sync repopulates correctly and any legacy record carrying
  email-as-sub is corrected on the next sync.
- **Infrastructure — `MyBosunDynamoDBKMSKeyArn` parameter added to the
  cognito-sync stack**: the Lambda execution role now gets `kms:Decrypt`,
  `kms:GenerateDataKey`, and `kms:DescribeKey` on the customer-managed CMK
  encrypting the MyBosun tenant tables. Without this, every TenantMemberships
  / StudentProfile DDB call fails with `KMS AccessDeniedException`.

### Version 3.0.0
- **Tenant-aware sync**: Every payload now carries a required `tenantId`. The
  plugin aborts (with a logged error) if Sync Settings → Tenant ID is empty.
- **Plugin is a dumb pipe**: Cognito group reconciliation, TenantMemberships
  upserts, and StudentProfile mirroring all moved to the sync Lambda. The
  plugin no longer owns `WP_{role}` group naming or per-role group ownership.
- **One-way sync direction**: WC → MyBosun for owned fields. No outbound
  MyBosun → WP calls (the dropped `wpEndpointUrl`/`wpApiKeyParam` fields will
  reappear if/when ownership flips in a future release).
- **SQS-buffered bulk sync**: Bulk runner paginates in 25-user batches, up to
  4 batches per click (~100 users), with a saved offset for resumability.
  Designed for 800+ user installs without hitting WP's 15s timeout or
  Cognito's ~25 RPS admin-API limit.
- **Group sync UI is no-op**: The per-role enable/disable toggles still render
  but no longer drive Cognito group creation. Groups are created on demand by
  the sync Lambda using `MyBosun_{tenantId}_{admin|member|suspended}`.
- **Restricted WC → MyBosun ownership**: WC may only set `member` /
  `suspended`; the `admin` access level is MyBosun-owned and never touched by
  inbound sync.
- **Multi-tenant post-auth mirror**: Cognito post-auth Lambda now derives all
  tenants the user belongs to from group membership and mirrors approles +
  status into each per-tenant StudentProfile.
- **Documentation**: `ARCHITECTURE.md` rewritten as the WordPress-side
  companion to `yacht-charter-app/documentation/WP_COGNITO_SYNC_V3.md`.
  `GROUP_SYNC_OPTIMIZATION.md` carries a "superseded by v3.0" banner.

### Version 2.3.1
- **Enhanced Role Synchronization**: Added immediate synchronization for secondary role changes (add_user_role/remove_user_role hooks)
- **Comprehensive Role Hook Coverage**: Now captures all WordPress role changes including:
  - Primary role changes via `set_user_role` (existing)
  - Secondary role additions via `add_user_role` (new)
  - Secondary role removals via `remove_user_role` (new)
- **Improved Sync Logging**: Enhanced logging in `sync_user_groups()` with detailed action tracking:
  - Shows current WordPress roles and configured sync groups
  - Logs each group membership action (added/removed/failed)
  - Provides warning messages when users aren't linked or groups aren't configured
- **Real-time Group Updates**: Role changes now trigger immediate Cognito group membership updates instead of waiting for login/manual sync
- **Enhanced Documentation**: Updated help text and admin interface to reflect comprehensive role synchronization coverage
- **Better Error Handling**: More specific error messages and logging levels (info, warning, error) for sync operations

### Version 2.3.0
- **Enhanced Error Handling**: Graceful handling of "User already exists" errors during sync operations
- **Automatic Recovery**: When user creation fails due to existing account, automatically attempts update instead
- **Improved Sync Logic**: Added detailed logging to identify why users are created vs. updated
- **Debug Tools**: New methods for formatted log viewing and searching specific error patterns
- **UI Cleanup**: Removed non-functional "Sync this user to Cognito automatically" checkbox that wasn't being used
- **Better Logging**: Enhanced sync decision logging to help troubleshoot sync issues
- **Code Quality**: Fixed trailing whitespace and improved code consistency

### Version 2.2.0
- **Complete Group Management System**: Added comprehensive group management functionality with visual interface
- **Enhanced Bulk Sync Interface**: Completely redesigned bulk sync with better user selection options and form validation
- **Individual Group Control**: Enable/disable sync for each WordPress role independently with automatic Cognito group creation
- **Improved Admin Interface**: New tabbed navigation with dedicated sections for Group Management and Bulk Sync
- **Better User Experience**: Clear descriptions, status indicators, user counts, and progressive disclosure in forms
- **Advanced Form Validation**: JavaScript validation for role-based sync operations
- **Enhanced Error Handling**: Comprehensive error messages and logging for all operations
- **Documentation Updates**: Complete documentation consolidation with detailed usage instructions
- **UI/UX Improvements**:
  - Removed confusing radio buttons from bulk sync interface
  - Added clear user selection options (All Users vs Users by Role)
  - Dynamic role selection with proper validation
  - Visual status indicators for group sync status
  - Organized admin interface with dedicated tabs
  - Mobile-responsive design improvements

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
