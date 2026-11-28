// The panel of the shop under load (performance program, PERF-7): W7 (the lists, the searches and the filters of the panel at
// the rate of its users) and P4 (the same, with the shop working next to it, to see what the panel costs the shop). The
// requests come from a mix of the data (load/panel_mix.py), the token from load/panel_token.py; `load/panel.sh` runs both and
// this script; see load/README.md. Writes /out/panel.json.
//
//   PANEL_MIX (required)         the file of the mix (panel_mix.py), e.g. /out/mix.json
//   PANEL_TOKEN (required)       the file of the token (panel_token.py), e.g. /out/token.json
//   KEYCLOAK_URL (required)      where k6 reaches Keycloak to renew the token (5 minutes), e.g. http://keycloak:8080
//   PANEL_RATE (5)               requests of the mix per second (the open model, as the users of the panel come); 0: no panel
//   PANEL_SECONDS (300)          the length of the run
//   PANEL_WORST_VUS (0)          threads that repeat the heaviest classes of the mix without a pause (P4: 8)
//   BOUNDARIES_EVERY (0)         seconds between two reads of the boundaries of the sitemap (the shell purges their cache)
//   SHOP_LISTING_RATE (0)        cold listings of the shop per second (the addresses of baseline.py urls in SHOP_URLS)
//   SHOP_CART_RATE (0)           carts of the shop per second: create, add, read, change (W5)
//   SHOP_URLS                    the file of baseline.py urls, with SHOP_LISTING_RATE
//   SHOP_URL_OFFSET (0)          where in that file the run starts (a later run must not repeat the addresses of an earlier one: they would be warm)
//   PANEL_VUS (50), PANEL_MAX_VUS (400)   the virtual users of the open scenarios
import http from 'k6/http';
import exec from 'k6/execution';
import { check } from 'k6';
import { SharedArray } from 'k6/data';
import { number, text, apiUrl } from './lib/config.js';
import { discover, pick } from './lib/data.js';
import { baseOptions, nextIp } from './lib/http.js';
import { record } from './lib/metrics.js';
import { availableOf, variantsWithUnits } from './lib/stock.js';
import { figures, reportOn, table } from './lib/summary.js';

const mix = JSON.parse(open(text('PANEL_MIX')));
const token = JSON.parse(open(text('PANEL_TOKEN')));
const keycloak = text('KEYCLOAK_URL').replace(/\/+$/, '');
const rate = number('PANEL_RATE', 5);
const seconds = number('PANEL_SECONDS', 300);
const worstVus = number('PANEL_WORST_VUS', 0);
const boundariesEvery = number('BOUNDARIES_EVERY', 0);
const listingRate = number('SHOP_LISTING_RATE', 0);
const cartRate = number('SHOP_CART_RATE', 0);
const offset = number('SHOP_URL_OFFSET', 0);
const shopUrls = listingRate > 0 ? new SharedArray('shop', () => JSON.parse(open(text('SHOP_URLS'))).entries.filter((e) => e.target === 'api' && e.kind !== 'product').map((e) => e.path)) : [];
if (listingRate > 0 && shopUrls.length < offset + Math.ceil(listingRate * seconds)) {
  throw new Error(`SHOP_URLS has ${shopUrls.length} listings, the run needs ${offset + Math.ceil(listingRate * seconds)} different ones (one per request, so that they are cold); generate more with baseline.py urls --listings`);
}

if (typeof mix.classes !== 'object' || mix.classes === null || Object.keys(mix.classes).length === 0 || typeof mix.weights !== 'object') {
  throw new Error(`${text('PANEL_MIX')} is not a mix of panel_mix.py: it has no classes of requests with weights`);
}
if (typeof token.refresh_token !== 'string') {
  throw new Error(`${text('PANEL_TOKEN')} is not the output of panel_token.py: it has no refresh_token`);
}
const classNames = Object.keys(mix.classes);
const classWeights = classNames.map((name) => [name, mix.weights[name]]);
const worstClasses = (mix.worst || []).filter((name) => mix.classes[name]);
const SHOP_CLASSES = ['shop_listing', 'shop_cart_create', 'shop_cart_add', 'shop_cart_get', 'shop_cart_change'];

function weighted(pairs) {
  let point = Math.random() * pairs.reduce((sum, [, weight]) => sum + weight, 0);
  for (const [name, weight] of pairs) {
    point -= weight;
    if (point < 0) {
      return name;
    }
  }
  return pairs[pairs.length - 1][0];
}

const thresholds = {};
reportOn(thresholds, [...classNames, ...SHOP_CLASSES, 'boundaries'].map((name) => `class:${name}`));
reportOn(thresholds, ['scenario:panel', 'scenario:shop_listing', 'scenario:shop_cart', 'scenario:worst', 'kind:panel', 'kind:shop']);
thresholds['dropped_iterations'] = [];
thresholds['dropped_iterations{scenario:panel}'] = [];
thresholds['dropped_iterations{scenario:shop_listing}'] = [];
thresholds['dropped_iterations{scenario:shop_cart}'] = [];

const open_ = (executor) => ({ executor, timeUnit: '1s', duration: `${seconds}s`, preAllocatedVUs: number('PANEL_VUS', 50), maxVUs: number('PANEL_MAX_VUS', 400) });
const scenarios = {};
if (rate > 0) {
  scenarios.panel = { ...open_('constant-arrival-rate'), rate, exec: 'panelRequest' };
}
if (worstVus > 0 && worstClasses.length > 0) {
  scenarios.worst = { executor: 'constant-vus', vus: worstVus, duration: `${seconds}s`, exec: 'worstRequest' };
}
if (boundariesEvery > 0) {
  scenarios.boundaries = { executor: 'constant-arrival-rate', rate: 1, timeUnit: `${boundariesEvery}s`, duration: `${seconds}s`, preAllocatedVUs: 2, maxVUs: 4, exec: 'boundaries' };
}
if (listingRate > 0) {
  scenarios.shop_listing = { ...open_('constant-arrival-rate'), rate: listingRate, exec: 'shopListing' };
}
if (cartRate > 0) {
  scenarios.shop_cart = { ...open_('constant-arrival-rate'), rate: cartRate, exec: 'shopCart' };
}

export const options = { ...baseOptions, scenarios, thresholds };

// The state of one virtual user: the token it sends and when it expires.
let session = null;

// Every virtual user renews the token with the refresh token of the file when it has none or it nears its end (the access token
// lives 5 minutes, the run longer); a refusal ends the run, as measured numbers of a panel that answers 401 mean nothing.
function bearer() {
  if (session === null || Date.now() > session.expires - 30000) {
    const res = http.post(
      `${keycloak}/realms/backoffice/protocol/openid-connect/token`,
      { grant_type: 'refresh_token', client_id: 'admin', refresh_token: session === null ? token.refresh_token : session.refresh },
      { tags: { name: 'token' } },
    );
    if (res.status !== 200) {
      exec.test.abort(`the token could not be renewed (Keycloak answered ${res.status}); is the stack running and the login still valid?`);
    }
    const body = res.json();
    session = { access: body.access_token, refresh: body.refresh_token, expires: Date.now() + body.expires_in * 1000 };
  }
  return session.access;
}

function panelGet(cls, path) {
  const tags = { class: cls, kind: 'panel' };
  const res = http.get(apiUrl + path, {
    headers: { Authorization: `Bearer ${bearer()}`, Accept: 'application/json', 'CF-Connecting-IP': nextIp(20) },
    tags: { ...tags, name: cls },
    timeout: '60s',
  });
  record(res, tags);
  check(res, { [`${cls} answers 200`]: (r) => r.status === 200 }, tags);
  return res;
}

// The step of the cart that raises the quantity from 1 to 2 gets 409 from the API for a variant that has fewer than 2 units available (the cart answers
// `Nie mamy tylu sztuk tego produktu.`; measured: 5 of 5 variants with 1 unit, none of 159 with 2 or more), so the scenario takes only the variants with at least
// 2 available units, read from the admin stock list of their products (the public API never tells the units). The 409 of the earlier measurement were that.
export function setup() {
  // The shop side needs the variants that can be bought; the panel does not (and a run without a shop reads nothing).
  if (cartRate <= 0) {
    return null;
  }
  const data = discover();
  const available = {};
  for (const productId of [...new Set(data.variants.map((variant) => variant.productId))]) {
    const res = http.get(`${apiUrl}/api/v1/admin/stock?productId=${productId}`, { headers: { Authorization: `Bearer ${bearer()}`, Accept: 'application/json' }, tags: { name: 'setup' }, timeout: '60s' });
    if (res.status !== 200) {
      exec.test.abort(`the stock of the product ${productId} could not be read (status ${res.status}); the cart scenario needs the available units of the variants`);
    }
    Object.assign(available, availableOf(res.json()));
  }
  data.variants = variantsWithUnits(data.variants, available, 2);
  if (data.variants.length === 0) {
    exec.test.abort('no purchasable variant has 2 or more units available: the cart scenario cannot raise a quantity');
  }
  console.log(`setup: ${data.variants.length} variants with at least 2 available units for the cart scenario`);
  return data;
}

export function panelRequest() {
  const cls = weighted(classWeights);
  panelGet(cls, pick(mix.classes[cls]));
}

export function worstRequest() {
  const cls = pick(worstClasses);
  panelGet(cls, pick(mix.classes[cls]));
}

export function boundaries() {
  const tags = { class: 'boundaries', kind: 'panel' };
  const res = http.get(`${apiUrl}/api/v1/sitemap/product-chunks`, { headers: { 'CF-Connecting-IP': nextIp(21) }, tags: { ...tags, name: 'boundaries' }, timeout: '120s' });
  record(res, tags);
  check(res, { 'boundaries answers 200': (r) => r.status === 200 }, tags);
}

function shopGet(cls, path, ip) {
  const tags = { class: cls, kind: 'shop' };
  const res = http.get(apiUrl + path, { headers: { 'CF-Connecting-IP': ip }, tags: { ...tags, name: cls }, timeout: '60s' });
  record(res, tags);
  check(res, { [`${cls} answers 200`]: (r) => r.status === 200 }, tags);
  return res;
}

export function shopListing() {
  shopGet('shop_listing', shopUrls[(offset + exec.scenario.iterationInTest) % shopUrls.length], nextIp(22));
}

function shopSend(method, cls, path, body, ip) {
  const tags = { class: cls, kind: 'shop' };
  const res = http.request(method, apiUrl + path, body === null ? null : JSON.stringify(body), {
    headers: { 'Content-Type': 'application/json', 'CF-Connecting-IP': ip },
    tags: { ...tags, name: cls },
    timeout: '60s',
  });
  record(res, tags);
  check(res, { [`${cls} answers 2xx`]: (r) => r.status >= 200 && r.status < 300 }, tags);
  return res;
}

// W5: a cart is made, a variant (one with at least 2 available units, see setup) added, the cart read and the quantity changed.
export function shopCart(data) {
  const ip = nextIp(23);
  const created = shopSend('POST', 'shop_cart_create', '/api/v1/guest-carts', null, ip);
  if (created.status !== 201) {
    return;
  }
  const id = created.json('id');
  const variantId = pick(data.variants).variantId;
  const added = shopSend('POST', 'shop_cart_add', `/api/v1/guest-carts/${id}/items`, { variantId, quantity: 1 }, ip);
  if (added.status !== 200) {
    return;
  }
  shopGet('shop_cart_get', `/api/v1/guest-carts/${id}`, ip);
  shopSend('PUT', 'shop_cart_change', `/api/v1/guest-carts/${id}/items/${variantId}`, { quantity: 2 }, ip);
}

export function handleSummary(summary) {
  const classes = Object.fromEntries([...classNames, ...SHOP_CLASSES, 'boundaries'].map((name) => [name, figures(summary, `class:${name}`, seconds)]).filter(([, f]) => f.requests > 0));
  const result = {
    panel_rate: rate,
    seconds,
    worst_vus: worstVus,
    shop_listing_rate: listingRate,
    shop_cart_rate: cartRate,
    boundaries_every: boundariesEvery,
    mix_generated_at: mix.generated_at,
    panel: figures(summary, 'scenario:panel', seconds),
    worst: worstVus > 0 ? figures(summary, 'scenario:worst', seconds) : null,
    shop_listing: listingRate > 0 ? figures(summary, 'scenario:shop_listing', seconds) : null,
    shop_cart: cartRate > 0 ? figures(summary, 'scenario:shop_cart', seconds) : null,
    shop_kind: figures(summary, 'kind:shop', seconds),
    dropped: {
      panel: summary.metrics['dropped_iterations{scenario:panel}'] ? summary.metrics['dropped_iterations{scenario:panel}'].values.count : 0,
      shop_listing: summary.metrics['dropped_iterations{scenario:shop_listing}'] ? summary.metrics['dropped_iterations{scenario:shop_listing}'].values.count : 0,
      shop_cart: summary.metrics['dropped_iterations{scenario:shop_cart}'] ? summary.metrics['dropped_iterations{scenario:shop_cart}'].values.count : 0,
    },
    classes,
  };
  const rows = [['panel (mix)', result.panel]];
  if (result.worst) rows.push(['worst threads', result.worst]);
  if (result.shop_listing) rows.push(['shop listings', result.shop_listing]);
  if (result.shop_cart) rows.push(['shop carts', result.shop_cart]);
  const text_ = table('W7 / P4 run', rows) + table('by class', Object.entries(classes));
  return { stdout: text_, '/out/panel.json': JSON.stringify(result, null, 2) };
}
