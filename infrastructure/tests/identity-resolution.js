#!/usr/bin/env node
// Identity-resolution regression test for the inline sync Lambda.
//
// Replays the 2026-09-21 identity split (a WordPress user whose stored
// cognito_user_id points at a DELETED Cognito user) and the bulk-push case
// (a `create` for someone who already exists), plus two paths that must not
// regress. No AWS SDK needed: the four SDK modules are stubbed in-process and
// the Lambda source is lifted straight out of the CloudFormation template, so
// what runs here is byte-for-byte what deploys.
//
//   node infrastructure/tests/identity-resolution.js
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
const OLD = '11111111-1111-4111-8111-111111111111'; // the sub WordPress still remembers
const NEW = '22222222-2222-4222-8222-222222222222'; // the sub Cognito actually holds
const EMAIL = 'member@example.com';
const err = (name) => Object.assign(new Error(name), { name });

function scenario(name, { poolHasOld, poolHasNew, payload, env = {} }) {
  const ddbWrites = [];
  const attrs = new Set(); // every Cognito attribute name the Lambda tried to write
  delete process.env.WRITE_WP_MEMBER_ATTRIBUTES; Object.assign(process.env, env);
  let created = false;
  const userOf = (sub) => ({ Username: sub, UserAttributes: [{ Name: 'sub', Value: sub }, { Name: 'email', Value: EMAIL }] });
  global.__cognitoSend = async (cmd) => {
    const i = cmd.input || {};
    (i.UserAttributes || []).forEach((a) => attrs.add(a.Name));
    switch (cmd.name) {
      case 'AdminUpdateUserAttributesCommand':
        if (i.Username === OLD && poolHasOld) return {};
        if (i.Username === NEW && (poolHasNew || created)) return {};
        if (i.Username === EMAIL && (poolHasNew || created)) return {};
        throw err('UserNotFoundException');
      case 'AdminCreateUserCommand':
        if (poolHasNew || created) throw err('UsernameExistsException');
        created = true; return { User: { Username: NEW, Attributes: userOf(NEW).UserAttributes } };
      case 'AdminGetUserCommand':
        if (i.Username === EMAIL && (poolHasNew || created)) return userOf(NEW);
        if (i.Username === OLD && poolHasOld) return userOf(OLD);
        throw err('UserNotFoundException');
      case 'AdminSetUserPasswordCommand': case 'AdminAddUserToGroupCommand': case 'AdminRemoveUserFromGroupCommand': case 'GetGroupCommand': return {};
      default: return {};
    }
  };
  global.__ddbSend = async (cmd) => {
    const i = cmd.input || {};
    if (cmd.name === 'GetCommand' && i.TableName === 'cfg') return { Item: { wpSync: { isActive: true, accessLevelMap: { subscriber: 'member', customer: 'suspended' }, newUserDefaults: {} }, membership: { statusAuthority: 'wordpress' } } };
    if (cmd.name === 'GetCommand' && i.TableName === 'tm') return { Item: null };
    if (cmd.name === 'QueryCommand') return { Items: [] };
    if (cmd.name === 'PutCommand' || cmd.name === 'UpdateCommand') { ddbWrites.push({ table: i.TableName, userId: i.Item ? i.Item.userId : i.Key && i.Key.userId }); return {}; }
    return {};
  };
  global.__sqsSend = async () => ({});
  const warn = []; const origWarn = console.warn; console.warn = (...a) => warn.push(a.join(' '));
  const quiet = ['log', 'error', 'info'].map(k => [k, console[k]]); quiet.forEach(([k]) => (console[k] = () => {}));
  delete require.cache[require.resolve(src)];
  const lambda = require(src);
  return lambda.handler({ body: JSON.stringify({ tenantId: 'rafsa', ...payload }) })
    .then((res) => {
      console.warn = origWarn; quiet.forEach(([k, f]) => (console[k] = f));
      const tm = ddbWrites.filter(w => w.table === 'tm').map(w => w.userId);
      const body = JSON.parse(res.body);
      const returned = body.result && body.result.User && (body.result.User.Attributes || []).find(a => a.Name === 'sub');
      return { name, status: res.statusCode, tmKeyedOn: [...new Set(tm)], returnedSub: returned ? returned.Value : null, created, staleWarn: warn.some(w => /Stale cognito_user_id/.test(w)), attrs: [...attrs], error: body.error || null };
    });
}

(async () => {
  const user = { email: EMAIL, username: 'hermione', wp_user_id: 5175, wp_roles: ['subscriber'], membership: { plan: 'members', status: 'active', memberRank: 'Flt Lt', memberCategory: 'Serving' } };
  const results = [];
  results.push(await scenario('A. update, WP meta points at a deleted Cognito user', { poolHasOld: false, poolHasNew: false, payload: { action: 'update', user: { ...user, cognito_user_id: OLD } } }));
  results.push(await scenario('B. bulk create for a user who already exists', { poolHasOld: false, poolHasNew: true, payload: { action: 'create', user } }));
  results.push(await scenario('C. update with a live sub (regression)', { poolHasOld: true, poolHasNew: false, payload: { action: 'update', user: { ...user, cognito_user_id: OLD } } }));
  results.push(await scenario('D. update with email stand-in id (regression)', { poolHasOld: false, poolHasNew: true, payload: { action: 'update', user: { ...user, cognito_user_id: EMAIL } } }));
  // MS-04: a pool with no custom:wp_* attributes (the production pool).
  results.push(await scenario('E. create into a pool without custom:wp_* attributes', { poolHasOld: false, poolHasNew: false, payload: { action: 'create', user }, env: { WRITE_WP_MEMBER_ATTRIBUTES: 'false' } }));
  results.push(await scenario('F. update into a pool without custom:wp_* attributes', { poolHasOld: true, poolHasNew: false, payload: { action: 'update', user: { ...user, cognito_user_id: OLD } }, env: { WRITE_WP_MEMBER_ATTRIBUTES: 'false' } }));
  const expect = {
    'A': { tm: NEW, status: 200, staleWarn: true },
    'B': { tm: NEW, status: 200 },
    'C': { tm: OLD, status: 200 },
    'D': { tm: NEW, status: 200 },
    'E': { tm: NEW, status: 200, noWpAttrs: true },
    'F': { tm: OLD, status: 200, noWpAttrs: true },
  };
  let failed = 0;
  for (const r of results) {
    const e = expect[r.name[0]];
    // MS-04: custom:wp_user_id is never written; with the member attributes
    // switched off, no custom:wp_* attribute is written at all. With them on
    // (the default), rank and category still reach the pool.
    const wpAttrs = r.attrs.filter(a => a.startsWith('custom:wp_'));
    const attrsOk = !r.attrs.includes('custom:wp_user_id') && (e.noWpAttrs ? wpAttrs.length === 0 : wpAttrs.includes('custom:wp_memberrank') && wpAttrs.includes('custom:wp_membercategory'));
    const ok = attrsOk && r.status === e.status && r.tmKeyedOn.length === 1 && r.tmKeyedOn[0] === e.tm && (e.staleWarn === undefined || r.staleWarn === e.staleWarn);
    if (!ok) failed += 1;
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${r.name}  → status ${r.status}, tenant rows keyed on ${r.tmKeyedOn.join(',') || '(none)'}, wp attrs [${r.attrs.filter(a => a.startsWith('custom:wp_')).join(',')}]${r.error ? ', error ' + r.error : ''}`);
  }
  process.exit(failed ? 1 : 0);
})().catch(e => { console.error('HARNESS FAILED', e); process.exit(1); });
