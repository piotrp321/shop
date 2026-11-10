// The warm-up before the measurement (make -C infra/k8s load): a low steady rate of the read and cart scenarios and a few
// checkouts, so that the caches (opcache, the pages of the store, Varnish, Elasticsearch) and the connection pools are
// warm. Its numbers are not kept.
//
//   WARMUP_RATE (5)      iterations per second of the read and cart scenario
//   WARMUP_SECONDS (60)  the length
//   WARMUP_CHECKOUTS (5) checkouts of one virtual user
import { number } from './lib/config.js';
import { discover } from './lib/data.js';
import { browse, checkout } from './lib/flows.js';
import { baseOptions, expectBusinessStatuses, newContext } from './lib/http.js';

export const options = {
  ...baseOptions,
  scenarios: {
    read_warmup: {
      executor: 'constant-arrival-rate',
      rate: number('WARMUP_RATE', 5),
      timeUnit: '1s',
      duration: `${number('WARMUP_SECONDS', 60)}s`,
      preAllocatedVUs: 20,
      maxVUs: 200,
      exec: 'readWarmup',
    },
    checkout_warmup: {
      executor: 'per-vu-iterations',
      vus: 1,
      iterations: number('WARMUP_CHECKOUTS', 5),
      maxDuration: '5m',
      exec: 'checkoutWarmup',
    },
  },
};

export function setup() {
  expectBusinessStatuses();
  return discover();
}

export function readWarmup(data) {
  expectBusinessStatuses();
  browse(newContext(18), data);
}

export function checkoutWarmup(data) {
  expectBusinessStatuses();
  checkout(newContext(19), data);
}
