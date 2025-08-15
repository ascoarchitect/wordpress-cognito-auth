# WordPress Cognito Auth - Architecture Overview

## Data Mastering Configuration

The plugin now supports configurable data mastering to optimize sync performance and avoid circular dependencies.

### Configuration Options

**WordPress Masters User Data (Default)**
- WordPress is the authoritative source for user profile data
- Changes to names, email, custom fields are synced TO Cognito via existing hooks
- During authentication, only `cognito_groups` metadata is updated from Cognito
- Minimal API overhead during login

**Cognito Masters User Data** 
- Cognito is the authoritative source for user profile data
- Changes to profile data are synced FROM Cognito during login
- WordPress profile updates should be disabled or restricted

## Current Implementation

### Authentication Flow
1. User authenticates via Cognito OAuth2/OIDC
2. JWT token is validated and decoded
3. WordPress user is created/updated based on data mastering configuration:
   - **WordPress Master**: Only `cognito_groups` metadata updated
   - **Cognito Master**: Full profile sync (names, display name, custom attributes)
4. User is logged into WordPress

### Sync Architecture
- **WordPress → Cognito**: Triggered by WordPress hooks (profile_update, role changes)
- **Cognito → WordPress**: Currently during authentication only
- **Conflict Prevention**: Transient flags prevent circular sync during authentication

## Future Enhancements

### Webhook-Based Sync (Recommended for Cognito Master Mode)

**Benefits:**
- Eliminates API overhead during login
- Real-time sync when Cognito data changes
- Improved login performance
- Decouples authentication from data synchronization

**Implementation Plan:**
1. Create AWS Lambda function to handle Cognito user pool events
2. Lambda triggers WordPress webhook endpoint when user data changes
3. Remove login-time sync for Cognito master mode
4. Add webhook endpoint in WordPress to receive and process updates

**Lambda Trigger Events:**
- User attribute updates in Cognito
- User status changes (enabled/disabled)
- Custom attribute modifications

**WordPress Webhook Endpoint:**
- Authenticate requests from Lambda (HMAC signature)
- Update user profile data based on received payload
- Log sync activities for debugging

### Configuration Migration

When webhook functionality is implemented:
- Add new feature flag: `cognito_webhook_sync`
- Deprecate login-time sync for Cognito master mode
- Provide migration tools for existing installations

## Current Files Modified

- `Auth.php`: Added configurable data mastering in `update_user_from_cognito()`
- `Admin.php`: Added UI for data mastering configuration
- `Sync.php`: Enhanced documentation and architecture comments

## Performance Impact

**WordPress Master Mode (Current Default):**
- Minimal login overhead (only cognito_groups update)
- Existing hook-based sync maintains data consistency

**Cognito Master Mode (Current):**
- Higher login overhead due to profile data sync
- Future webhook implementation will eliminate this overhead
