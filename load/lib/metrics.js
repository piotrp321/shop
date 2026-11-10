import { Counter, Rate } from 'k6/metrics';

// 5xx and errors of the network (status 0): the errors of the saturation criterion. Tagged like the requests.
export const serverErrors = new Rate('server_errors');
// 409 and 429 are answers of the shop, not failures of the system: counted apart (and left out of http_req_failed by
// expectBusinessStatuses). Every 429 of a measurement is a finding of the report.
export const conflicts = new Counter('conflicts_409');
export const rateLimited = new Counter('rate_limited_429');
// Any other 4xx: a bug of the script or of the data, never expected.
export const otherClientErrors = new Counter('other_4xx');
// Iterations finished, to get the achieved rate of a step.
export const iterationsDone = new Counter('iterations_done');
export const ordersPlaced = new Counter('orders_placed');
export const paymentsStarted = new Counter('payments_started');

// Counts the answer under the tags of its request (flow, kind and, in a ramp, step).
export function record(res, tags) {
  const status = res.status;
  serverErrors.add(status === 0 || status >= 500, tags);
  if (status === 409) {
    conflicts.add(1, tags);
  } else if (status === 429) {
    rateLimited.add(1, tags);
  } else if (status >= 400 && status < 500) {
    otherClientErrors.add(1, tags);
  }
}

// The metrics a summary reports for a selector (`kind:read`, `flow:product`, `scenario:checkout`, `step:3`).
export const REPORTED = ['http_req_duration', 'http_reqs', 'server_errors', 'conflicts_409', 'rate_limited_429', 'other_4xx'];
