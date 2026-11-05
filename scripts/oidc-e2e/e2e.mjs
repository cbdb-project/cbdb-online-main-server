// 以標準 OIDC 函式庫（openid-client）對本專案的 OIDC Provider 做端到端驗證。
// 由 run.sh 啟動隔離的伺服器與資料庫後呼叫：node e2e.mjs <workdir>/e2e.json
//
// 為什麼要有這個：Provider 的其餘測試都是我們自己寫的，驗證的是「我們以為的 OIDC」。
// 只有讓下游真正會用的那個函式庫走一遍，才能證明 discovery、issuer 比對、JWKS 驗章、PKCE、nonce 實際互通。

import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import * as client from 'openid-client';

const cfg = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const workdir = path.dirname(process.argv[2]);
const issuer = new URL(cfg.issuer);

// 精確的錯誤斷言：只有「伺服器以 OAuth 錯誤拒絕」或「函式庫自己的驗證失敗」才算數。
// 沒有型別檢查的 assert.rejects 會讓網路故障（TypeError: fetch failed）之類無關的錯誤也通過。
const serverRejects = (code) => (error) =>
  error instanceof client.ResponseBodyError && error.error === code;
const libraryRejects = (error) => error instanceof client.ClientError;

let passed = 0;
async function check(name, fn) {
  try {
    await fn();
    passed += 1;
    console.log(`  ✔ ${name}`);
  } catch (error) {
    console.error(`  ✘ ${name}\n    ${String(error?.stack ?? error).split('\n').join('\n    ')}`);
    process.exitCode = 1;
    throw error; // 前面的失敗會讓後面的步驟失去意義
  }
}

// ── 極簡 cookie jar：模擬瀏覽器的 session ──────────────────────────────────────
class Jar {
  cookies = new Map();
  absorb(response) {
    for (const line of response.headers.getSetCookie?.() ?? []) {
      const [pair] = line.split(';');
      const index = pair.indexOf('=');
      this.cookies.set(pair.slice(0, index).trim(), pair.slice(index + 1).trim());
    }
  }
  header() {
    return [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; ');
  }
}

async function request(url, { method = 'GET', jar, form } = {}) {
  const headers = { Accept: 'text/html,application/json' };
  if (jar?.cookies.size) headers.Cookie = jar.header();
  let body;
  if (form) {
    headers['Content-Type'] = 'application/x-www-form-urlencoded';
    body = new URLSearchParams(form);
  }
  const response = await fetch(url, { method, headers, body, redirect: 'manual' });
  jar?.absorb(response);
  return response;
}

// ── 瀏覽器流程：未登入 → /login → 登入 → 回跳授權端 → 帶著 code 回到 RP ──────────
async function browserAuthorize(authUrl, jar, email) {
  const first = await request(authUrl, { jar });
  assert.equal(first.status, 302, `授權端應回 302，實際 ${first.status}`);
  const location = new URL(first.headers.get('location'), issuer);

  // 這個 session 已經登入：授權端直接帶著 code 重導向到 RP（沒有經過登入頁）。
  if (location.pathname !== '/login') {
    return { callback: location };
  }

  const login = await request(new URL('/login', issuer), { method: 'POST', jar, form: { email, password: cfg.password } });
  assert.equal(login.status, 302, '登入應回 302');
  return { intended: new URL(login.headers.get('location'), issuer) };
}

async function followToCallback(url, jar) {
  const response = await request(url, { jar });
  assert.equal(response.status, 302, `授權端應回 302，實際 ${response.status}`);
  return new URL(response.headers.get('location'));
}

async function newAuthorization(config, jar, email, { scope = 'openid profile email', nonceOverride } = {}) {
  const verifier = client.randomPKCECodeVerifier();
  const state = client.randomState();
  const nonce = client.randomNonce();
  const authUrl = client.buildAuthorizationUrl(config, {
    redirect_uri: cfg.redirect_uri,
    scope,
    state,
    nonce: nonceOverride ?? nonce,
    code_challenge: await client.calculatePKCECodeChallenge(verifier),
    code_challenge_method: 'S256',
  });
  const result = await browserAuthorize(authUrl, jar, email);
  let callback = result.callback; // session 已登入：直接拿到回調網址
  if (!callback) {
    // 登入後回跳的必須是「原本那條完整的授權網址」（state、nonce、code_challenge 全部保留）。
    // 比較的是「參數集合」而不是原始字串：Laravel 保存 url.intended 時會把查詢串規範化（按字母序、空格編成 %20），
    // 語意完全相同。重點是 state、nonce、code_challenge 一個都不能丟、也不能被改。
    assert.deepEqual(
      Object.fromEntries(result.intended.searchParams),
      Object.fromEntries(authUrl.searchParams),
      '登入回跳後授權請求的參數必須完整保留',
    );
    callback = await followToCallback(result.intended, jar);
  }
  return { callback, verifier, state, nonce };
}

// ───────────────────────────────────────────────────────────────────────────────
console.log(`OIDC e2e against ${issuer.origin}`);

let config;

console.log('\n[1] Discovery');
await check('openid-client 完成 discovery（issuer 與設定逐字相符）', async () => {
  config = await client.discovery(issuer, cfg.client_id, cfg.client_secret, undefined, { execute: [client.allowInsecureRequests] });
  assert.equal(config.serverMetadata().issuer, cfg.issuer);
});
await check('discovery 只宣稱真正支援的東西', async () => {
  const meta = config.serverMetadata();
  assert.deepEqual(meta.grant_types_supported, ['authorization_code']);
  assert.deepEqual(meta.code_challenge_methods_supported, ['S256']);
  assert.deepEqual(meta.response_types_supported, ['code']);
  assert.deepEqual(meta.id_token_signing_alg_values_supported, ['RS256']);
  assert.deepEqual(meta.scopes_supported, ['openid', 'profile', 'email']);
  for (const absent of ['userinfo_endpoint', 'end_session_endpoint', 'registration_endpoint', 'revocation_endpoint']) {
    assert.equal(meta[absent], undefined, `不應宣稱 ${absent}`);
  }
});
await check('偽造的 Host 標頭不會改變 discovery 的任何端點', async () => {
  const spoofed = await new Promise((resolve, reject) => {
    const req = http.request({ host: issuer.hostname, port: issuer.port, path: '/.well-known/openid-configuration', headers: { Host: 'evil.example' } }, (res) => {
      let data = '';
      res.on('data', (chunk) => (data += chunk));
      res.on('end', () => resolve(JSON.parse(data)));
    });
    req.on('error', reject);
    req.end();
  });
  assert.equal(spoofed.issuer, cfg.issuer);
  for (const key of ['authorization_endpoint', 'token_endpoint', 'jwks_uri']) {
    assert.ok(spoofed[key].startsWith(cfg.issuer), `${key} 不可跟著 Host 走：${spoofed[key]}`);
  }
});

console.log('\n[2] 授權碼流程（真實的瀏覽器路徑）');
let happy;
await check('登入、回跳、取得授權碼；openid-client 驗證 id_token（簽章／iss／aud／exp／nonce／state）', async () => {
  const jar = new Jar();
  const auth = await newAuthorization(config, jar, cfg.users.active.email);
  assert.ok(auth.callback.searchParams.get('code'));
  assert.equal(auth.callback.searchParams.get('state'), auth.state);

  // authorizationCodeGrant 會自己抓 JWKS、驗章，並驗 iss／aud／exp／nonce／state。
  const tokens = await client.authorizationCodeGrant(config, auth.callback, {
    pkceCodeVerifier: auth.verifier,
    expectedState: auth.state,
    expectedNonce: auth.nonce,
    idTokenExpected: true,
  });
  happy = { tokens, auth, jar };

  const claims = tokens.claims();
  assert.equal(claims.iss, cfg.issuer);
  assert.deepEqual([claims.aud].flat(), [cfg.client_id]);
  assert.equal(claims.nonce, auth.nonce);
  assert.equal(typeof claims.sub, 'string', 'sub 必須是字串');
  assert.equal(claims.sub, String(cfg.users.active.id));
  assert.equal(claims.name, cfg.users.active.name);
  assert.equal(claims.email, cfg.users.active.email);
  assert.equal(claims.email_verified, false);
});
await check('id_token 只有白名單內的 claim，沒有任何帳號欄位', async () => {
  const claims = happy.tokens.claims();
  const allowed = new Set(['iss', 'aud', 'exp', 'iat', 'nbf', 'jti', 'sub', 'nonce', 'name', 'email', 'email_verified']);
  const extra = Object.keys(claims).filter((k) => !allowed.has(k));
  assert.deepEqual(extra, [], `出現白名單以外的 claim：${extra}`);
  const flat = JSON.stringify(claims);
  for (const forbidden of ['is_admin', 'is_active', 'confirmation_token', 'password', 'Jon Snow']) {
    assert.ok(!flat.includes(forbidden), `不應洩漏 ${forbidden}`);
  }
});
await check('不簽發 refresh token；access token 為 Bearer、壽命很短', async () => {
  assert.equal(happy.tokens.refresh_token, undefined);
  assert.equal(happy.tokens.token_type, 'bearer');
  assert.ok(happy.tokens.expires_in > 0 && happy.tokens.expires_in <= 600, `expires_in=${happy.tokens.expires_in}`);
});
await check('id_token 頭部的 kid 與 JWKS 公布的那把鑰一致', async () => {
  const header = JSON.parse(Buffer.from(happy.tokens.id_token.split('.')[0], 'base64url').toString());
  const jwks = await (await fetch(config.serverMetadata().jwks_uri)).json();
  assert.equal(jwks.keys.length, 1);
  assert.equal(header.alg, 'RS256');
  assert.equal(header.kid, jwks.keys[0].kid);
  assert.deepEqual(Object.keys(jwks.keys[0]).sort(), ['alg', 'e', 'kid', 'kty', 'n', 'use'], 'JWKS 不可含私鑰成員');
});

console.log('\n[3] 必須失敗的情況');
await check('同一張授權碼不能兌換第二次', async () => {
  await assert.rejects(
    client.authorizationCodeGrant(config, happy.auth.callback, { pkceCodeVerifier: happy.auth.verifier, expectedState: happy.auth.state, expectedNonce: happy.auth.nonce, idTokenExpected: true }),
    serverRejects('invalid_grant'),
  );
});
await check('錯的 code_verifier 被拒絕', async () => {
  const auth = await newAuthorization(config, happy.jar, cfg.users.active.email);
  await assert.rejects(
    client.authorizationCodeGrant(config, auth.callback, { pkceCodeVerifier: client.randomPKCECodeVerifier(), expectedState: auth.state, expectedNonce: auth.nonce, idTokenExpected: true }),
    serverRejects('invalid_grant'),
  );
});
await check('state 被竄改時函式庫拒絕', async () => {
  const auth = await newAuthorization(config, happy.jar, cfg.users.active.email);
  await assert.rejects(
    client.authorizationCodeGrant(config, auth.callback, { pkceCodeVerifier: auth.verifier, expectedState: 'tampered', expectedNonce: auth.nonce, idTokenExpected: true }),
    libraryRejects,
  );
});
await check('nonce 不符時函式庫拒絕（防重放）', async () => {
  const auth = await newAuthorization(config, happy.jar, cfg.users.active.email);
  await assert.rejects(
    client.authorizationCodeGrant(config, auth.callback, { pkceCodeVerifier: auth.verifier, expectedState: auth.state, expectedNonce: client.randomNonce(), idTokenExpected: true }),
    libraryRejects,
  );
});
await check('沒有 PKCE 的授權請求被直接拒絕，不重導向', async () => {
  const url = new URL(config.serverMetadata().authorization_endpoint);
  url.search = new URLSearchParams({ response_type: 'code', client_id: cfg.client_id, redirect_uri: cfg.redirect_uri, scope: 'openid', state: 'x' }).toString();
  const response = await request(url, { jar: happy.jar });
  assert.equal(response.status, 400);
  assert.equal(response.headers.get('location'), null);
});
await check('沒有 openid scope 的授權請求被拒絕', async () => {
  const verifier = client.randomPKCECodeVerifier();
  const url = client.buildAuthorizationUrl(config, { redirect_uri: cfg.redirect_uri, scope: 'profile', code_challenge: await client.calculatePKCECodeChallenge(verifier), code_challenge_method: 'S256' });
  const response = await request(url, { jar: happy.jar });
  assert.equal(response.status, 400);
});
await check('未註冊的 redirect_uri 不會被導向', async () => {
  const verifier = client.randomPKCECodeVerifier();
  const url = client.buildAuthorizationUrl(config, { redirect_uri: 'http://evil.example/cb', scope: 'openid', code_challenge: await client.calculatePKCECodeChallenge(verifier), code_challenge_method: 'S256' });
  const response = await request(url, { jar: happy.jar });
  assert.notEqual(response.status, 302);
  assert.equal(response.headers.get('location'), null);
});
await check('不開放的 scope（phone、mcp:use）被拒絕', async () => {
  for (const scope of ['openid phone', 'openid mcp:use']) {
    const verifier = client.randomPKCECodeVerifier();
    const url = client.buildAuthorizationUrl(config, { redirect_uri: cfg.redirect_uri, scope, state: 's', code_challenge: await client.calculatePKCECodeChallenge(verifier), code_challenge_method: 'S256' });
    const response = await request(url, { jar: happy.jar });
    assert.equal(response.status, 302);
    assert.equal(new URL(response.headers.get('location')).searchParams.get('error'), 'invalid_scope', scope);
  }
});
await check('其他 grant（refresh_token、client_credentials）一律 400 unsupported_grant_type', async () => {
  for (const grant of ['refresh_token', 'client_credentials', 'password']) {
    const response = await fetch(config.serverMetadata().token_endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ grant_type: grant, client_id: cfg.client_id, client_secret: cfg.client_secret }),
    });
    assert.equal(response.status, 400, grant);
    assert.equal((await response.json()).error, 'unsupported_grant_type', grant);
  }
});

console.log('\n[4] 停用帳號');
await check('已停用的帳號無法登入，因此拿不到授權碼', async () => {
  const jar = new Jar();
  const verifier = client.randomPKCECodeVerifier();
  const authUrl = client.buildAuthorizationUrl(config, { redirect_uri: cfg.redirect_uri, scope: 'openid', state: 's', code_challenge: await client.calculatePKCECodeChallenge(verifier), code_challenge_method: 'S256' });
  await request(authUrl, { jar });
  const login = await request(new URL('/login', issuer), { method: 'POST', jar, form: { email: cfg.users.inactive.email, password: cfg.password } });
  // 不斷言登入失敗後導向哪裡：ValidationException 會 back() 到 Referer，這個腳本沒帶 Referer（瀏覽器會帶），
  // 所以會落到 `/`。真正的安全性質是「登入失敗之後仍然是未登入」，下面驗證的是這個結果。
  assert.equal(login.status, 302);
  const again = await request(authUrl, { jar });
  assert.equal(again.status, 302);
  assert.equal(new URL(again.headers.get('location'), issuer).pathname, '/login', '登入失敗後必須仍是未登入，且拿不到授權碼');
  assert.equal(new URL(again.headers.get('location'), issuer).searchParams.get('code'), null);
});
await check('授權後、兌換前帳號被停用：兌換失敗、不給任何 token', async () => {
  const jar = new Jar();
  const auth = await newAuthorization(config, jar, cfg.users.active.email);
  execFileSync('php', [path.resolve(import.meta.dirname, 'seed.php'), workdir, 'deactivate'], { stdio: 'pipe', env: process.env });
  await assert.rejects(
    client.authorizationCodeGrant(config, auth.callback, { pkceCodeVerifier: auth.verifier, expectedState: auth.state, expectedNonce: auth.nonce, idTokenExpected: true }),
    serverRejects('access_denied'),
  );
});
await check('帳號被停用後，仍有效的瀏覽器 session 拿不到新的授權碼', async () => {
  // happy.jar 是停用之前登入的 session；帳號現在已被停用。
  const verifier = client.randomPKCECodeVerifier();
  const authUrl = client.buildAuthorizationUrl(config, { redirect_uri: cfg.redirect_uri, scope: 'openid', state: 's', code_challenge: await client.calculatePKCECodeChallenge(verifier), code_challenge_method: 'S256' });
  const response = await request(authUrl, { jar: happy.jar });
  assert.equal(response.status, 302);
  const location = new URL(response.headers.get('location'), issuer);
  assert.equal(location.pathname, '/login', '停用帳號的舊 session 必須被當成未登入');
  assert.equal(location.searchParams.get('code'), null);
});

console.log(`\n${passed} 項檢查通過`);
