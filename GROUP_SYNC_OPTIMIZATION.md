# Group Sync Optimization

## Problem Identified

The original implementation was extremely inefficient for role changes, causing multiple redundant API calls for a single user role change:

### Before Optimization:
```
2025-08-15 13:11:45	Info	Group sync completed for user: Removed from WP_administrator, Added to WP_subscriber, Removed from WP_customer
2025-08-15 13:11:44	Info	Group sync completed for user: Removed from WP_administrator, Removed from WP_subscriber, Removed from WP_customer  
2025-08-15 13:11:42	Info	Group sync completed for user: Removed from WP_administrator, Removed from WP_subscriber, Added to WP_customer
2025-08-15 13:11:42	Info	Group sync completed for user: Removed from WP_administrator, Removed from WP_subscriber, Removed from WP_customer
```

**Root Cause:** Multiple WordPress hooks firing for a single role change:
- `remove_user_role` (removes old role)
- `add_user_role` (adds new role)  
- `set_user_role` (overall change notification)

Each hook triggered a **full group sync**, processing ALL configured groups and making unnecessary API calls.

## Solution: Deferred/Batched Group Sync

### Implementation Changes:

1. **Added Pending Sync Array**
   ```php
   private $pending_group_sync = array();
   ```

2. **Modified Hook Handlers**
   - Instead of immediate `sync_user_groups()` calls
   - Now calls `schedule_group_sync()` to defer the operation

3. **Deferred Processing**
   - Uses WordPress `shutdown` hook to process all pending syncs
   - Deduplicates users (same user ID won't sync multiple times)
   - Batches all operations at the end of the request

4. **Enhanced Logging**
   - Shows batched operation details
   - Clearer indication of optimization in action

### After Optimization:
```
2025-08-15 14:30:12	Info	Processing batched group sync for 1 user(s): 123
2025-08-15 14:30:12	Info	Group sync completed for user: Removed from WP_administrator, Added to WP_subscriber
```

## Benefits

### Performance Improvements:
- **Eliminated Redundant API Calls**: Single role change = single group sync operation
- **Reduced Network Overhead**: Fewer Cognito API requests
- **Faster Role Changes**: No more multiple sequential sync operations
- **Server Resource Savings**: Less CPU and memory usage during role changes

### Architecture Improvements:
- **Deduplication**: Same user won't sync multiple times in one request
- **Batching**: All group syncs happen together at request end
- **Better Logging**: Clear visibility into batched operations
- **Maintainable Code**: Centralized sync logic

## Technical Details

### Hook Registration (unchanged):
```php
add_action( 'set_user_role', array( $this->sync, 'on_user_role_change' ), 10, 3 );
add_action( 'add_user_role', array( $this->sync, 'on_user_role_added' ), 10, 2 );
add_action( 'remove_user_role', array( $this->sync, 'on_user_role_removed' ), 10, 2 );
```

### New Flow:
1. **WordPress Role Change** → Multiple hooks fire
2. **schedule_group_sync()** → Adds user ID to pending array (deduplicated)
3. **WordPress shutdown** → `process_pending_group_syncs()` executes
4. **Single sync_user_groups()** → One API call per user
5. **Cognito Groups Updated** → Efficient and clean

### Edge Cases Handled:
- **Multiple role changes in single request**: Only final state synced
- **Same user multiple operations**: Deduplicated automatically  
- **No group sync enabled**: Early exit, no processing overhead
- **Empty pending array**: No unnecessary processing

## Backward Compatibility

- ✅ **No breaking changes**: All existing functionality preserved
- ✅ **Same hook registration**: WordPress hooks unchanged
- ✅ **Same API interface**: Public methods remain identical
- ✅ **Same configuration**: No admin interface changes needed

## Testing Scenarios

1. **Single Role Change**: Administrator → Subscriber
2. **Multiple Role Operations**: Add role + Remove role in same request
3. **Bulk Role Changes**: Multiple users changed simultaneously
4. **Role Addition Only**: User gains additional role
5. **Role Removal Only**: User loses a role

All scenarios should now show batched processing in logs with significantly fewer API calls.
