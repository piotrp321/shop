// The smoke run (make load-smoke): one virtual user, one iteration of every scenario against the running stack of
// compose: the pages of the catalog, the search with and without a filter, the cart and a guest checkout of one unit. It
// keeps the scripts working; it measures nothing and has no limits of time. Any answer other than the expected one, any
// request that fails and a checkout that placed no order with a started payment fail the run (exit 99).
import { check } from 'k6';
import { discover } from './lib/data.js';
import { cart, category, checkout, home, product, search } from './lib/flows.js';
import { baseOptions, newContext } from './lib/http.js';

export const options = {
  ...baseOptions,
  scenarios: {
    smoke: { executor: 'per-vu-iterations', vus: 1, iterations: 1, maxDuration: '30s' },
  },
  thresholds: {
    checks: ['rate==1'],
    http_req_failed: ['rate==0'],
    server_errors: ['rate==0'],
    orders_placed: ['count==1'],
    payments_started: ['count==1'],
  },
};

export function setup() {
  return discover();
}

export default function (data) {
  const ctx = newContext(18);
  home(ctx);
  category(ctx, data);
  product(ctx, data);
  search(ctx, data, false);
  search(ctx, data, true);
  cart(ctx, data);
  const placed = checkout(newContext(19), data);
  check(placed, { 'the checkout placed the order': (value) => value === true });
}
