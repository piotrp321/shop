// Generic k6 script of the performance audit: requests picked at random from a list of targets.
//   TARGETS_FILE  JSON: [{method, url, body, headers, expect}]
//   MODE          vus (closed model: VUS users, no think time) | rate (open model: RATE iterations/s)
//   VUS, DURATION, RATE, MAX_VUS, OUT (summary JSON)
import http from 'k6/http';
import { check } from 'k6';

const targets = JSON.parse(open(__ENV.TARGETS_FILE));
const mode = __ENV.MODE || 'vus';

export const options = {
  discardResponseBodies: true,
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
  scenarios: {
    main:
      mode === 'rate'
        ? {
            executor: 'constant-arrival-rate',
            rate: Number(__ENV.RATE),
            timeUnit: '1s',
            duration: __ENV.DURATION || '20s',
            preAllocatedVUs: Number(__ENV.VUS || 50),
            maxVUs: Number(__ENV.MAX_VUS || 1000),
          }
        : { executor: 'constant-vus', vus: Number(__ENV.VUS || 1), duration: __ENV.DURATION || '20s' },
  },
};

export default function () {
  const t = targets[Math.floor(Math.random() * targets.length)];
  const res = http.request(t.method || 'GET', t.url, t.body || null, { headers: t.headers || {}, redirects: 0 });
  check(res, { ok: (r) => r.status === (t.expect || 200) });
}

export function handleSummary(data) {
  return { [__ENV.OUT]: JSON.stringify(data) };
}
