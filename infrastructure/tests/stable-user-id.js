#!/usr/bin/env node
// GL-906 — the stable platform user id (custom:uid ?? sub) in the sync Lambda.
//
// After a Cognito pool rebuild (a disaster restore) a member is recreated with
// a NEW sub and custom:uid = their OLD id. MyBosun's records and WordPress's
// cognito_user_id meta still hold the old id. Every path here must key tenant
// data on, and hand back to WordPress, that old id: the platform id. Normal
// users (no custom:uid) must behave exactly as before.
//
// Same harness as identity-resolution.js: the Lambda is lifted straight out of
// the CloudFormation template and the AWS SDK is stubbed in-process, against
// an in-memory user pool.
//
// The N cases are regressions: they pass on the Lambda before GL-906 too.
//
//   node infrastructure/tests/stable-user-id.js
'use strict';
const fs = require('fs');
const os = require('os');
const path = require('path');
const Module = require('module');

// ── lift the Lambda out of the template ─────────────────────────────────────
const template = fs.readFileSync(path.join(__dirname, '..', 'cognito-sync-cloudformation.yaml'), 'utf8').split('\n');
const start = template.findIndex((l) => l.trim() === 'ZipFile: |');
if (start < 0) throw new Error('ZipFile block not found');
const indent = template[start + 1].length - template[start + 1].trimStart().length;
const body = [];
for (const line of template.slice(start + 1)) {
  if (line.trim() === '') { body.push(''); continue; }
  if (line.length - line.trimStart().length < indent) break;
  body.push(line.slice(indent));
}
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'wp-cognito-sync-'));
const src = path.join(tmp, 'lambda.js');
fs.writeFileSync(src, body.join('\n'));

// ── stub the SDK ────────────────────────────────────────────────────────────
const cmds = (names) => Object.fromEntries(names.map((n) => [n, class { constructor(input) { this.input = input; this.name = n; } }]));
const stubs = {
  '@aws-sdk/client-cognito-identity-provider': { ...cmds(['AdminCreateUserCommand','AdminUpdateUserAttributesCommand','AdminDeleteUserCommand','AdminGetUserCommand','ListUsersCommand','ListUsersInGroupCommand','AdminSetUserPasswordCommand','AdminAddUserToGroupCommand','AdminRemoveUserFromGroupCommand','AdminListGroupsForUserCommand','GetGroupCommand','CreateGroupCommand','AdminDisableUserCommand','AdminEnableUserCommand']), CognitoIdentityProviderClient: class { send(c) { return global.__cognitoSend(c); } } },
  '@aws-sdk/client-sqs': { ...cmds(['SendMessageCommand','SendMessageBatchCommand']), SQSClient: class { send(c) { return global.__sqsSend(c); } } },
  '@aws-sdk/client-dynamodb': { DynamoDBClient: class {} },
  '@aws-sdk/lib-dynamodb': { ...cmds(['GetCommand','PutCommand','UpdateCommand','QueryCommand','DeleteCommand']), DynamoDBDocumentClient: class { static from() { return new this(); } send(c) { return global.__ddbSend(c); } } },
};
const realLoad = Module._load;
Module._load = function (request, ...rest) { return stubs[request] || realLoad.call(this, request, ...rest); };

// ── fixtures ────────────────────────────────────────────────────────────────
process.env.USER_POOL_ID = 'pool'; process.env.TENANT_CONFIG_TABLE = 'cfg';
process.env.TENANT_MEMBERSHIPS_TABLE = 'tm'; process.env.STUDENT_PROFILES_TABLE = 'sp'; process.env.SYNC_QUEUE_URL = 'q';
const OLD = '11111111-1111-4111-8111-111111111111'; // the platform id: old sub, now custom:uid
const NEW = '22222222-2222-4222-8222-222222222222'; // the sub the rebuilt pool issued
const OTHER = '33333333-3333-4333-8333-333333333333';
const EMAIL = 'member@example.com';
const err = (name) => Object.assign(new Error(name), { name });

// A restored member, and a normal one (no custom:uid).
const restored = () => ({ Username: NEW, email: EMAIL, attrs: { sub: NEW, email: EMAIL, 'custom:uid': OLD } });
const normal = () => ({ Username: OLD, email: EMAIL, attrs: { sub: OLD, email: EMAIL } });

async function run({ pool, payload, statusAuthority = 'wordpress', profile = true }) {
  const users = pool.map((u) => ({ ...u, attrs: { ...u.attrs } }));
  const find = (name) => users.find((u) => u.Username === name || u.email === name);
  const record = (u) => ({ Username: u.Username, UserAttributes: Object.entries(u.attrs).map(([Name, Value]) => ({ Name, Value })) });
  const calls = [];
  const deleted = []; // usernames actually deleted
  const tenantKeys = []; // every userId a TenantMemberships / StudentProfile read or write used
  global.__cognitoSend = async (cmd) => {
    const i = cmd.input || {};
    calls.push({ name: cmd.name, Username: i.Username });
    switch (cmd.name) {
      case 'AdminGetUserCommand': { const u = find(i.Username); if (!u) throw err('UserNotFoundException'); return record(u); }
      case 'AdminUpdateUserAttributesCommand': { const u = find(i.Username); if (!u) throw err('UserNotFoundException'); return {}; }
      case 'AdminDeleteUserCommand': { const u = find(i.Username); if (!u) throw err('UserNotFoundException'); users.splice(users.indexOf(u), 1); deleted.push(i.Username); return {}; }
      case 'AdminCreateUserCommand': {
        if (find(i.Username)) throw err('UsernameExistsException');
        const u = { Username: OTHER, email: i.Username, attrs: { sub: OTHER, email: i.Username } };
        users.push(u);
        return { User: { Username: u.Username, Attributes: record(u).UserAttributes } };
      }
      default: return {};
    }
  };
  global.__ddbSend = async (cmd) => {
    const i = cmd.input || {};
    if (cmd.name === 'GetCommand' && i.TableName === 'cfg') return { Item: { wpSync: { isActive: true, accessLevelMap: { subscriber: 'member' }, newUserDefaults: {} }, membership: { statusAuthority } } };
    if (i.TableName === 'tm' || i.TableName === 'sp') {
      const key = (i.Item && i.Item.userId) || (i.Key && i.Key.userId) || (i.ExpressionAttributeValues && i.ExpressionAttributeValues[':userId']);
      if (key) tenantKeys.push(key);
    }
    if (cmd.name === 'GetCommand') return { Item: null };
    if (cmd.name === 'QueryCommand') return { Items: profile ? [{ studentId: 'stu-1', userId: OLD, tenantId: 'rafsa' }] : [] };
    return {};
  };
  global.__sqsSend = async () => ({});
  const warns = []; const saved = ['log', 'error', 'info', 'warn'].map((k) => [k, console[k]]);
  ['log', 'error', 'info'].forEach((k) => (console[k] = () => {})); console.warn = (...a) => warns.push(a.join(' '));
  try {
    delete require.cache[require.resolve(src)];
    const lambda = require(src);
    const res = await lambda.handler({ body: JSON.stringify({ tenantId: 'rafsa', ...payload }) });
    const parsed = JSON.parse(res.body);
    const result = parsed.result || {};
    const subAttr = result.User && (result.User.Attributes || []).find((a) => a.Name === 'sub');
    return {
      status: res.statusCode,
      error: parsed.error || null,
      result,
      tenantKeys: [...new Set(tenantKeys)],
      returnedPlatformId: result.platformUserId || null,
      returnedSub: subAttr ? subAttr.Value : null,
      created: calls.some((c) => c.name === 'AdminCreateUserCommand') && users.some((u) => u.Username === OTHER),
      deleted,
      users,
      warns,
    };
  } finally {
    saved.forEach(([k, f]) => (console[k] = f));
  }
}

const member = { email: EMAIL, username: 'hermione', wp_user_id: 5175, wp_roles: ['subscriber'], membership: { plan: 'members', status: 'active' } };
const eq = (a, b) => JSON.stringify(a) === JSON.stringify(b);

const cases = [
  ['R1. restored user, update with the platform id WP stores (old sub)', { pool: [restored()], payload: { action: 'update', user: { ...member, cognito_user_id: OLD } } },
    (r) => r.status === 200 && eq(r.tenantKeys, [OLD]) && r.returnedPlatformId === OLD && r.returnedSub === OLD && !r.created && r.warns.length === 0],
  ['R2. restored user, update with the new sub an older plugin stored', { pool: [restored()], payload: { action: 'update', user: { ...member, cognito_user_id: NEW } } },
    (r) => r.status === 200 && eq(r.tenantKeys, [OLD]) && r.returnedPlatformId === OLD && !r.created],
  ['R3. restored user, bulk create with no stored id', { pool: [restored()], payload: { action: 'create', user: member } },
    (r) => r.status === 200 && eq(r.tenantKeys, [OLD]) && r.returnedPlatformId === OLD && r.returnedSub === OLD && !r.created],
  ['R4. restored user, update with no stored id (email lookup)', { pool: [restored()], payload: { action: 'update', user: member } },
    (r) => r.status === 200 && eq(r.tenantKeys, [OLD]) && r.returnedPlatformId === OLD && !r.created],
  ['R5. restored user, statusAuthority=mybosun identity upsert', { pool: [restored()], statusAuthority: 'mybosun', payload: { action: 'update', user: { ...member, cognito_user_id: OLD } } },
    (r) => r.status === 200 && eq(r.tenantKeys, [OLD]) && r.returnedPlatformId === OLD],
  ['R6. resolve_subs backfill returns the platform id', { pool: [restored()], payload: { action: 'resolve_subs', lookups: [{ wp_user_id: 5175, email: EMAIL }] } },
    (r) => r.status === 200 && eq(r.result.mappings, { 5175: OLD })],
  ['R7. restored user, delete with the platform id', { pool: [restored()], payload: { action: 'delete', user: { ...member, cognito_user_id: OLD } } },
    (r) => r.status === 200 && eq(r.deleted, [NEW]) && r.users.length === 0 && eq(r.tenantKeys, [OLD])],
  ['R8. delete never lands on an account with another platform id', { pool: [{ ...restored(), attrs: { ...restored().attrs, 'custom:uid': OTHER } }], payload: { action: 'delete', user: { ...member, cognito_user_id: OLD } } },
    (r) => r.status === 200 && r.deleted.length === 0 && r.users.length === 1],
  ['N1. normal user, update by sub (regression)', { pool: [normal()], payload: { action: 'update', user: { ...member, cognito_user_id: OLD } } },
    (r) => r.status === 200 && eq(r.tenantKeys, [OLD]) && r.returnedSub === OLD && !r.created],
  ['N2. normal user, resolve_subs returns the sub (regression)', { pool: [normal()], payload: { action: 'resolve_subs', lookups: [{ wp_user_id: 5175, email: EMAIL }] } },
    (r) => r.status === 200 && eq(r.result.mappings, { 5175: OLD })],
  ['N3. normal user, delete by sub (regression)', { pool: [normal()], payload: { action: 'delete', user: { ...member, cognito_user_id: OLD } } },
    (r) => r.status === 200 && eq(r.deleted, [OLD]) && eq(r.tenantKeys, [OLD])],
  ['N4. brand-new user is created and keyed on the new sub (regression)', { pool: [], payload: { action: 'create', user: member } },
    (r) => r.status === 200 && r.created && eq(r.tenantKeys, [OTHER]) && r.returnedSub === OTHER],
];

(async () => {
  let failed = 0;
  for (const [name, input, check] of cases) {
    const r = await run(input);
    const ok = check(r);
    if (!ok) failed += 1;
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}  → status ${r.status}, tenant keys [${r.tenantKeys.join(',')}], returned platformUserId ${r.returnedPlatformId}, sub ${r.returnedSub}${r.deleted.length ? `, deleted ${r.deleted.join(',')}` : ''}${r.result && r.result.mappings ? `, mappings ${JSON.stringify(r.result.mappings)}` : ''}${r.error ? `, error ${r.error}` : ''}`);
  }
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error('HARNESS FAILED', e); process.exit(1); });
