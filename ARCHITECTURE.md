# WordPress Cognito Auth — Architecture (v3.1)

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

## What changed in v3.1 — the membership block

v3.1 adds the plugin's **first WooCommerce-aware behaviour**. Everything else
about the dumb pipe is unchanged.

MyBosun is taking ownership of membership from WooCommerce, and needs every
member's paid-through date to stay accurate right up to the cutover. Two facts
from the live database shaped this:

1. **The authoritative paid-through date is the linked subscription's
   `_schedule_next_payment`, not the membership `_end_date`.** The membership
   end date is empty on 716 of 717 active memberships; the subscription's next
   payment date is set on 922 of 924 subscriptions. These are subscription-tied
   memberships. Reading `_end_date` would have exported almost the whole club
   with no renewal date.
2. **Renewals roll all year** — every month of the next twelve carries between
   20 and 88 of them. A one-off export goes stale within weeks, so the date has
   to arrive by continuous sync, not a snapshot.

### Payload addition

`User_Data::prepare()` now adds a **top-level** `membership` block, built by
`includes/Membership_Data.php`:

```json
"membership": {
  "plan": "members", "planName": "Members", "status": "active",
  "startDate": "2019-04-01T00:00:00+00:00",
  "endDate": null,
  "nextPaymentDate": "2027-04-01T00:00:00+00:00",
  "autoRenew": true,
  "wcMembershipId": 123, "wcSubscriptionId": 456,
  "subscriptionStatus": "active",
  "amount": 1500, "currency": "GBP",
  "billingInterval": 1, "billingPeriod": "year",
  "membershipCount": 1, "otherMemberships": [],
  "memberRank": "...", "memberCategory": "...",
  "capturedAt": "2026-09-20T09:00:00+00:00"
}
```

It sits beside `extra`, **not inside it**. The Lambda has always read
`wp_memberrank` / `wp_membercategory` from the top level while the plugin
nested them under `extra` — which is why `custom:wp_memberrank` and
`custom:wp_membercategory` are unset on every user in the live pool. The block
carries both fields itself, and the Lambda now resolves them from the block,
the top level or `extra`, so the attributes start populating.

The block is **omitted entirely** when WooCommerce Memberships is not active,
so a non-WooCommerce install sends the exact v3.0 payload. It can be replaced
or suppressed per install through the `wp_cognito_user_membership_block`
filter.

### New triggers

Until v3.1 the plugin listened to WordPress core user/role hooks only, and a
renewal that merely extends an existing membership changes no role — so it
produced no sync at all. v3.1 registers, only when the relevant extension is
present:

| Hook | Why |
|---|---|
| `wc_memberships_user_membership_created` / `_saved` | plan or membership edited |
| `wc_memberships_user_membership_status_changed` | active ⇄ paused ⇄ cancelled |
| `woocommerce_subscription_status_updated` | on-hold, cancelled, reactivated |
| `woocommerce_subscription_date_updated` (`next_payment`, `end`) | **the renewal signal** |

All four queue the user and push once on WordPress `shutdown` (priority 6),
de-duplicated against the group-sync batch (priority 5), so a renewal that
fires three hooks sends one payload.

### Lambda side

- `wpMembership` (the block plus a `syncedAt` stamp) is persisted onto the
  existing TenantMemberships upsert. The previously status-only update becomes
  status + `wpMembership`; nothing else on the row is touched, so MyBosun-owned
  `featureRoles` still survive every push.
- **`TenantConfig.membership.statusAuthority` now gates the whole write.** At
  `wordpress` (the default, and every tenant today) behaviour is unchanged. At
  `mybosun` — post-cutover — the Lambda drops to an identity pipe: it skips the
  status write, the Cognito group reconciliation, `wpMembership` and the
  StudentProfile status mirror, and a WordPress user deletion no longer revokes
  MyBosun access. A brand-new user still gets a TenantMemberships row, seeded
  `SUSPENDED`, because post-cutover access is granted by the membership module
  when someone pays, never by a WordPress account appearing.
- Resolution is **fail-closed towards WordPress**: anything other than a literal
  `mybosun` means WordPress still owns status, so a typo in TenantConfig can
  never silently stop the sync writing the bit Gate 1 enforces.

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
  `wp_user_id`, `email`, `name`, `wp_roles`, `membership`, `extra`). One place
  defines the contract; everything else delegates.
- `includes/Membership_Data.php` — (v3.1) the WooCommerce Memberships /
  Subscriptions reader behind the `membership` block. Every WC call is guarded
  by `method_exists` and the whole build is wrapped, so a WooCommerce upgrade
  or a missing extension degrades to "no block" and never breaks identity
  sync.
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
