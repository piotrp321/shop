import http from 'k6/http';
import exec from 'k6/execution';
import { apiHost, apiUrl, storeHost, storeOrigin, storeUrl } from './config.js';
import { record } from './metrics.js';

// The options every script shares. The url tag is left out of the system tags: /p/<slug> has a value per product.
export const baseOptions = {
  systemTags: ['proto', 'status', 'method', 'name', 'group', 'check', 'error', 'error_code', 'scenario', 'expected_response'],
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max', 'count'],
  noConnectionReuse: false,
  userAgent: 'shop-load-k6',
};

// 409 and 429 are answers of the shop that the report counts apart: they are no failed requests. What stays failed
// (http_req_failed) is every other 4xx, 5xx and the network errors. Not for the smoke run: there any of them is a failure.
export function expectBusinessStatuses() {
  http.setResponseCallback(http.expectedStatuses({ min: 200, max: 399 }, 409, 429));
}

// A pool of test addresses (2001:db8::/32, the range of RFC 3849 for documentation): a new one for every iteration of a
// scenario, so that the limits count by client address as in production and the test does not hit the limit of one. The
// API and the store count an IPv6 address by its /64 prefix, so the address differs in the prefix: the pool (18 for reads,
// 19 for the checkout) and the number of the iteration fill the third and the fourth hextet, and no address repeats
// within 16 million iterations of a scenario.
export function nextIp(pool) {
  const n = exec.scenario.iterationInTest;
  return `2001:db8:${(pool * 256 + ((n >> 16) & 255)).toString(16)}:${(n & 65535).toString(16)}::1`;
}

// The state of one iteration: the address of the client, a cookie jar of its own (a new browser) and the step of the ramp.
export function newContext(pool, step) {
  return { ip: nextIp(pool), jar: new http.CookieJar(), step };
}

function tagsOf(ctx, flow, kind) {
  const tags = { flow, kind };
  if (ctx.step !== undefined) {
    tags.step = String(ctx.step);
  }
  return tags;
}

function send(method, base, host, ctx, path, flow, kind, body, extra) {
  const headers = { 'CF-Connecting-IP': ctx.ip, ...extra };
  if (host !== '') {
    headers.Host = host;
  }
  const tags = tagsOf(ctx, flow, kind);
  const params = { headers, jar: ctx.jar, tags: { ...tags, name: `${flow}` }, timeout: '60s' };
  const res = method === 'GET' ? http.get(base + path, params) : http.post(base + path, body, params);
  record(res, tags);
  return res;
}

// A page or an endpoint of the store, as the browser asks for it.
export function storeGet(ctx, path, flow, kind) {
  return send('GET', storeUrl, storeHost, ctx, path, flow, kind, null, { Accept: 'text/html,application/xhtml+xml,application/json' });
}

// A write of the BFF of the store: JSON with the Origin of the store (it refuses any other).
export function storePost(ctx, path, flow, kind, payload, extra) {
  const headers = { 'Content-Type': 'application/json', Origin: storeOrigin, ...extra };
  return send('POST', storeUrl, storeHost, ctx, path, flow, kind, JSON.stringify(payload), headers);
}

// The public API, for the data of the run (setup).
export function apiGet(path) {
  const headers = apiHost === '' ? {} : { Host: apiHost };
  const res = http.get(apiUrl + path, { headers, tags: { name: 'setup' }, timeout: '60s' });
  if (res.status !== 200) {
    throw new Error(`setup: GET ${path} answered ${res.status}`);
  }
  return res.json();
}
