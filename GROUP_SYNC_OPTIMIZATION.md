# Group Sync Optimization

> **Superseded by v3.0.** The optimisation described below — deferring
> per-role-change group syncs to WordPress shutdown so a single role flip
> doesn't fire three sync calls — was a v2.x patch around the fact that the
> plugin owned individual `WP_{role}` group memberships in Cognito.
>
> In v3.0 the plugin no longer manages Cognito groups at all. Each event
> pushes the user's full `wp_roles[]` to the sync Lambda, which reconciles
> `MyBosun_{tenantId}_{admin|member|suspended}` membership in one go using
> the tenant's `accessLevelMap`. The "N calls per role change" problem is
> structurally eliminated — there is now one HTTP call per role change, full
> stop, regardless of how many WP hooks fire.
>
> The deferred-sync code path is still useful (it deduplicates redundant
> pushes when several role hooks fire in the same request), but the
> "optimisation" framing here no longer applies. See `ARCHITECTURE.md` for
> the v3.0 model.

---

## Historical context (v2.x)

The original implementation was extremely inefficient for role changes,
causing multiple redundant API calls for a single user role change.

### Before optimization

```
2025-08-15 13:11:45  Info  Group sync completed: Removed WP_administrator, Added WP_subscriber, Removed WP_customer
2025-08-15 13:11:44  Info  Group sync completed: Removed WP_administrator, Removed WP_subscriber, Removed WP_customer
2025-08-15 13:11:42  Info  Group sync completed: Removed WP_administrator, Removed WP_subscriber, Added WP_customer
```

**Root cause:** Multiple WordPress hooks fire for a single role change
(`remove_user_role`, `add_user_role`, `set_user_role`), each triggering a
full group sync that processed every configured group.

### v2.x fix: deferred batched sync

1. Added `pending_group_sync` array.
2. Hook handlers call `schedule_group_sync()` instead of syncing immediately.
3. WordPress `shutdown` hook processes the deduplicated set in one pass.

### After optimization

```
2025-08-15 14:30:12  Info  Processing batched group sync for 1 user(s): 123
2025-08-15 14:30:12  Info  Group sync completed: Removed WP_administrator, Added WP_subscriber
```

This reduced the per-role-change blast radius from 3+ syncs to 1, but each
sync still issued one Cognito API call per configured group.

## Why v3.0 obsoletes this

| Concern | v2.x (after this optimisation) | v3.0 |
|---|---|---|
| Hook fan-out | Deduplicated to 1 sync per user per request | Deduplicated to 1 sync per user per request (kept) |
| API calls per sync | One per configured group (`adminAddUserToGroup` × N) | One — Lambda receives `wp_roles[]` and reconciles |
| Group ownership | Plugin | Lambda |
| Tenant awareness | None | `tenantId` on every payload |

The deduplication logic in `Sync::process_pending_group_syncs` still runs
and is still worth keeping — it just isn't load-bearing the way it was in
v2.x.
