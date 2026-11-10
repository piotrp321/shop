// The steady run: the read and cart scenarios at a constant rate (STEADY_RATE iterations per second, 70% of the saturation
// found by saturate.js) with a checkout of constant, limited concurrency running next to them (CHECKOUT_VUS virtual
// users, each starting the next checkout when the previous one ends). The concurrency is below half of the threads of
// FrankenPHP in the API (D18): the payment simulator runs in the API container, and the webhook of a payment is delivered
// to the same server that waits for the checkout. The result of the checkout at this concurrency is not a benchmark of the
// checkout and not a throughput of the system. Writes /out/steady.json.
//
//   STEADY_RATE (required)     iterations per second of the read and cart scenario
//   CHECKOUT_VUS (required)    virtual users of the checkout scenario
//   STEADY_SECONDS (300)       the length of the run
//   STEADY_VUS (100), STEADY_MAX_VUS (1000)   virtual users of the read scenario
//   CHECKOUT_THINK_SECONDS (0) a pause between two checkouts of one virtual user
import { sleep } from 'k6';
import { number } from './lib/config.js';
import { discover } from './lib/data.js';
import { browse, checkout } from './lib/flows.js';
import { baseOptions, expectBusinessStatuses, newContext } from './lib/http.js';
import { iterationsDone } from './lib/metrics.js';
import { counter, figures, reportOn, table } from './lib/summary.js';

const rate = number('STEADY_RATE');
const checkoutVus = number('CHECKOUT_VUS');
const seconds = number('STEADY_SECONDS', 300);
const think = number('CHECKOUT_THINK_SECONDS', 0);

const FLOWS = ['home', 'category', 'product', 'search', 'search_filtered', 'cart_add', 'cart_read', 'cart_page', 'checkout_cart', 'checkout'];
const thresholds = {};
reportOn(thresholds, ['scenario:read_steady', 'scenario:checkout', 'kind:read', 'kind:cart', 'kind:checkout', ...FLOWS.map((flow) => `flow:${flow}`)]);
thresholds['dropped_iterations{scenario:read_steady}'] = [];
thresholds['orders_placed{scenario:checkout}'] = [];
thresholds['payments_started{scenario:checkout}'] = [];
thresholds['iterations_done{scenario:checkout}'] = [];

export const options = {
  ...baseOptions,
  scenarios: {
    read_steady: {
      executor: 'constant-arrival-rate',
      rate,
      timeUnit: '1s',
      duration: `${seconds}s`,
      preAllocatedVUs: number('STEADY_VUS', 100),
      maxVUs: number('STEADY_MAX_VUS', 1000),
      exec: 'readSteady',
    },
    checkout: {
      executor: 'constant-vus',
      vus: checkoutVus,
      duration: `${seconds}s`,
      exec: 'checkoutLoop',
    },
  },
  thresholds,
};

export function setup() {
  expectBusinessStatuses();
  return discover();
}

export function readSteady(data) {
  expectBusinessStatuses();
  browse(newContext(18), data);
}

export function checkoutLoop(data) {
  expectBusinessStatuses();
  checkout(newContext(19), data);
  iterationsDone.add(1);
  if (think > 0) {
    sleep(think);
  }
}

export function handleSummary(summary) {
  const result = {
    steady_iterations_per_second: rate,
    checkout_virtual_users: checkoutVus,
    seconds,
    read_and_cart: {
      scenario: figures(summary, 'scenario:read_steady', seconds),
      dropped_iterations: counter(summary, 'dropped_iterations', 'scenario:read_steady'),
      kinds: { read: figures(summary, 'kind:read', seconds), cart: figures(summary, 'kind:cart', seconds) },
    },
    checkout: {
      scenario: figures(summary, 'scenario:checkout', seconds),
      iterations: counter(summary, 'iterations_done', 'scenario:checkout'),
      orders_placed: counter(summary, 'orders_placed', 'scenario:checkout'),
      payments_started: counter(summary, 'payments_started', 'scenario:checkout'),
      flows: { checkout_cart: figures(summary, 'flow:checkout_cart', seconds), checkout: figures(summary, 'flow:checkout', seconds) },
    },
    flows: Object.fromEntries(FLOWS.filter((flow) => flow !== 'checkout_cart' && flow !== 'checkout').map((flow) => [flow, figures(summary, `flow:${flow}`, seconds)])),
  };
  const text =
    table('Steady run: read and cart (maximum-throughput side)', [['scenario read_steady', result.read_and_cart.scenario], ['read', result.read_and_cart.kinds.read], ['cart', result.read_and_cart.kinds.cart], ...Object.entries(result.flows)]) +
    table(`Checkout at a limited concurrency of ${checkoutVus} (not a benchmark)`, [['scenario checkout', result.checkout.scenario], ['checkout_cart', result.checkout.flows.checkout_cart], ['checkout', result.checkout.flows.checkout]]) +
    `\norders placed ${result.checkout.orders_placed}, payments started ${result.checkout.payments_started}, dropped iterations of the read scenario ${result.read_and_cart.dropped_iterations}\n`;
  return { stdout: text, '/out/steady.json': JSON.stringify(result, null, 2) };
}
