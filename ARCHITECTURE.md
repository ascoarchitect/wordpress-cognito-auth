# WordPress Cognito Auth — Architecture (v3.0)

> The canonical, end-to-end data-flow doc (with before/after diagrams and the
> full ownership boundaries table) lives in the MyBosun repo:
> `yacht-charter-app/documentation/WP_COGNITO_SYNC_V3.md`. This file is the
> WordPress-side companion.

## What changed in v3.0

The plugin became a **dumb pipe**. It no longer reasons about Cognito groups,
membership levels, or role priorities — every event simply pushes the user's
canonical payload (tenant ID, WP user ID, email, name, full `wp_roles[]`,
optional extras) to the sync Lambda. The Lambda is now the single source of
truth for:

- Cognito group reconciliation (`MyBosun_{tenantId}_{admin|member|suspended}`)
- TenantMemberships upsert (status from WC; tenantRoles/featureRoles owned by
  MyBosun and set on create only)
- StudentProfile mirroring (status + approles)

This collapses the v2.x "N API calls per role change" problem to a single
push, eliminates the in-plugin role-priority map, and makes the whole flow
multi-tenant safe — the same WP install can serve a different tenant just by
changing the configured Tenant ID.

## Direction

One-way: **WooCommerce Memberships → MyBosun**, for the fields WC owns
(access level, membership status). MyBosun-owned fields (admin grants, tenant
roles, feature roles) are never touched by inbound sync. There are no
outbound MyBosun → WP calls; just-in-time freshness on login is handled by
the post-auth Lambda reading TenantMemberships, not by pinging WP.

A future iteration may flip ownership (MyBosun becomes membership master, WP
becomes the consumer). When that lands, the dropped `wpEndpointUrl` /
`wpApiKeyParam` config fields will be reintroduced. They are deliberately
absent from v3.0 to avoid carrying dead config.

## WordPress-side responsibilities (v3.0)

| Concern | v2.x | v3.0 |
|---|---|---|
| Authentication (OIDC/JWT) | Plugin | Plugin (unchanged) |
| WP user create / update / delete sync | Plugin → Lambda | Plugin → Lambda (now with `tenantId`) |
| Role → Cognito group mapping | Hardcoded `WP_{role}` in plugin | `accessLevelMap` per tenant in TenantConfig |
| Group creation in Cognito | Plugin (`create_group` action) | Lambda (on demand) |
| Group membership reconciliation | Plugin (one call per role) | Lambda (one call per user) |
| Bulk sync transport | `wp_remote_post` per user (15s timeout risk) | SQS-buffered via `bulk_sync_enqueue` |
| Tenant ID | n/a (single-tenant) | Required on every payload |

## Files of note

- `includes/User_Data.php` — canonical payload builder (`tenantId`,
  `wp_user_id`, `email`, `name`, `wp_roles`, `extra`). One place defines the
  contract; everything else delegates.
- `includes/API.php` — HTTP wrapper. `send_to_lambda()` injects `tenantId`
  and aborts if it isn't configured. Adds `bulk_sync_enqueue($users_batch)`
  for the SQS path.
- `includes/Sync.php` — event hooks (`profile_update`, role changes) and
  `bulk_sync_users($role)` runner. Bulk runner paginates in 25-user batches
  and persists a resume offset transient so an interrupted run continues from
  where it stopped.
- `includes/Admin.php` — Sync Settings → Tenant ID input, bulk-sync UI with
  "restart from beginning" toggle and resume-offset display.

## What this plugin no longer does

- Manages Cognito groups directly (`create_group`, `update_group_membership`
  actions are no-ops on the v3 Lambda — kept only to avoid 5xx during a
  rolling plugin upgrade).
- Owns the role → group mapping. The mapping is per-tenant config in
  TenantConfig, because different WP installs use different role slugs
  (`wpuef_member` vs `wc_subscriber` vs custom membership plugins).
- Maintains `cognito_groups` user meta as a sync target. Group state lives in
  Cognito; WP no longer mirrors it.

## Performance / scale

- Bulk sync of 800+ users: WP enqueues in batches of 25, four batches per
  admin click (≈100 users/tick), saved offset for resumability. The sync
  Lambda fans onto SQS via `SendMessageBatch` (10 messages/call). The SQS
  consumer runs at `ReservedConcurrentExecutions: 5` (~10–15 RPS — under
  Cognito's ~25 RPS admin-API limit). End-to-end estimate for 800 users:
  ~55s once enqueued.
- Failed messages retry twice (`maxReceiveCount: 3`), then land in the
  dead-letter queue for manual redrive after fixing root cause.
