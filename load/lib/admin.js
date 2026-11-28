// The API (public and panel) and the panel session of the writes scenario (writes.js). The session is the one of a staff member
// logged in to the panel: its refresh token (public client `admin` of the realm backoffice) comes in ADMIN_REFRESH_TOKEN, and the
// script exchanges it for access tokens at the token endpoint of Keycloak, in the background before every access token expires. No account
// or client of its own (load/README.md, "Token").
import http from 'k6/http';
import { Counter } from 'k6/metrics';
import { text } from './env.js';
import { RENEW_BEFORE_MS, tokenAction } from './write-state.js';

const trimmed = (url) => url.replace(/\/+$/, '');

// Where k6 reaches the API itself (not Varnish: in compose http://api, in the cluster the host `api` of the ingress) and
// Keycloak (http://keycloak:8080; the host `auth`); the Host headers when the address is not the domain of the service.
export const apiUrl = trimmed(text('API_URL'));
const apiHost = text('API_HOST', '');
const keycloakUrl = trimmed(text('KEYCLOAK_URL'));
const keycloakHost = text('KEYCLOAK_HOST', '');
const TOKEN_PATH = '/realms/backoffice/protocol/openid-connect/token';
// After a failed renewal in the background, the next one starts no sooner than this.
const BACKGROUND_RETRY_MS = 5 * 1000;
// Successful renewals of the access token during the run: kind background (while the token was valid) or blocking (a request
// waited for it). The first token of the run is not counted.
const renewals = new Counter('token_renewals');

function headersFor(host, extra) {
  return host === '' ? { ...extra } : { Host: host, ...extra };
}

function refusal(res) {
  let body = {};
  try {
    body = res.json() || {};
  } catch (e) {
    body = {};
  }
  return [body.error, body.error_description].filter(Boolean).join(': ') || (typeof res.body === 'string' ? res.body.slice(0, 200) : '');
}

// The answer of the token endpoint, or the error that explains what to do.
function tokensOf(res) {
  if (res.status === 0) {
    throw new Error(`Keycloak does not answer at ${keycloakUrl} (${res.error}); is KEYCLOAK_URL right?`);
  }
  if (res.status === 400 || res.status === 401) {
    throw new Error(
      `Keycloak refused the refresh token (${res.status} ${refusal(res)}): it is not a refresh token of a panel session, it expired (15 min without use, 8 h at most) or the session was logged out; get a new one (load/README.md, "Token")`,
    );
  }
  if (res.status !== 200) {
    throw new Error(`Keycloak answered ${res.status} to the refresh of the token (${refusal(res)})`);
  }
  const body = res.json();
  if (!body.access_token) {
    throw new Error('Keycloak gave no access token for the refresh token');
  }
  return { access: body.access_token, refresh: body.refresh_token || null, expiresAt: Date.now() + Number(body.expires_in || 60) * 1000 };
}

function refreshRequest(refreshToken) {
  return [
    `${keycloakUrl}${TOKEN_PATH}`,
    { grant_type: 'refresh_token', client_id: 'admin', refresh_token: refreshToken },
    { headers: headersFor(keycloakHost, {}), tags: { name: 'token_refresh' }, timeout: '30s' },
  ];
}

// The session of the panel: an access token, renewed with the refresh token it came with (Keycloak may rotate the refresh token;
// the newest one is kept). Never logged.
export class PanelSession {
  constructor() {
    const refreshToken = __ENV.ADMIN_REFRESH_TOKEN;
    if (refreshToken === undefined || refreshToken.trim() === '') {
      throw new Error('ADMIN_REFRESH_TOKEN is required: the refresh token of a panel session of an administrator (load/README.md, "Token")');
    }
    this.refreshToken = refreshToken.trim();
    this.tokens = null;
    this.renewing = null;
    this.retryAt = 0;
    this.failedInRow = 0;
  }

  keep(tokens) {
    this.tokens = tokens;
    if (tokens.refresh) {
      this.refreshToken = tokens.refresh;
    }
  }

  // The Authorization header for a request of setup (synchronous).
  authorizationSync() {
    if (this.tokens === null || this.tokens.expiresAt - Date.now() < RENEW_BEFORE_MS) {
      const [url, form, params] = refreshRequest(this.refreshToken);
      this.keep(tokensOf(http.post(url, form, params)));
    }
    return `Bearer ${this.tokens.access}`;
  }

  // One renewal at a time, shared by every request that waits for it; kind (background or blocking) is counted when it succeeds,
  // a renewal without a token before it is not.
  renew(kind) {
    if (this.renewing === null) {
      const first = this.tokens === null;
      const [url, form, params] = refreshRequest(this.refreshToken);
      this.renewing = http
        .asyncRequest('POST', url, form, params)
        .then((res) => this.keep(tokensOf(res)))
        .then(
          () => {
            this.renewing = null;
            this.failedInRow = 0;
            if (!first) {
              renewals.add(1, { kind });
            }
          },
          (error) => {
            this.renewing = null;
            throw error;
          },
        );
    }
    return this.renewing;
  }

  // The Authorization header for a request of the run (lib/write-state.js, tokenAction): a token valid for longer than
  // VALID_MARGIN_MS is used at once, and in the last RENEW_BEFORE_MS it is renewed in the background, so no write waits for
  // Keycloak while the token is valid. A failed renewal in the background is tried again after BACKGROUND_RETRY_MS; once the token
  // is about to expire, the request waits for the renewal and its error is thrown.
  async authorization() {
    const now = Date.now();
    const action = tokenAction(this.tokens === null ? null : this.tokens.expiresAt, now);
    if (action === 'wait') {
      await this.renew('blocking');
    } else if (action === 'use_and_renew' && this.renewing === null && now >= this.retryAt) {
      this.renew('background').catch((error) => {
        this.retryAt = Date.now() + BACKGROUND_RETRY_MS;
        // The first failure of a series says why (the messages of tokensOf carry no token).
        if (this.failedInRow === 0) {
          console.warn(`the renewal of the access token in the background failed, tried again every ${BACKGROUND_RETRY_MS / 1000} s while the token is valid: ${error.message}`);
        }
        this.failedInRow += 1;
      });
    }
    return `Bearer ${this.tokens.access}`;
  }
}

export function apiParams(name, extra, timeoutSeconds) {
  return { headers: headersFor(apiHost, extra || {}), tags: { name }, timeout: `${timeoutSeconds || 60}s` };
}

function failure(method, path, res) {
  if (res.status === 0) {
    return new Error(`the API does not answer at ${apiUrl} (${method} ${path}: ${res.error}); is API_URL right and the stack running?`);
  }
  if (res.status === 401) {
    return new Error(`the API refused the access token (${method} ${path}: 401)`);
  }
  if (res.status === 403) {
    return new Error(`the staff member of the token may not do this (${method} ${path}: 403); the writes need an administrator`);
  }
  return new Error(`${method} ${path} answered ${res.status}`);
}

// A GET of setup, public or (with a session) of the panel: the JSON, null for a 404 when allowed, else an error.
export function getJson(path, session, allowMissing) {
  const extra = session ? { Authorization: session.authorizationSync() } : {};
  const res = http.get(`${apiUrl}${path}`, apiParams('setup', extra));
  if (res.status === 404 && allowMissing) {
    return null;
  }
  if (res.status !== 200) {
    throw failure('GET', path, res);
  }
  return res.json();
}
