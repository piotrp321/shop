// Price changes through the admin API, each variant of the list once: N events VariantPriceChanged for the async pipeline.
//   API, TOKEN_FILE, CHANGES_FILE (JSON [{variantId, version, gross}]), VUS, OUT
import http from 'k6/http';
import { check } from 'k6';
import exec from 'k6/execution';
import { SharedArray } from 'k6/data';

const changes = new SharedArray('changes', () => JSON.parse(open(__ENV.CHANGES_FILE)));
const token = open(__ENV.TOKEN_FILE).trim();

export const options = {
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
  scenarios: {
    main: { executor: 'shared-iterations', vus: Number(__ENV.VUS || 8), iterations: changes.length, maxDuration: '30m' },
  },
};

export default function () {
  const c = changes[exec.scenario.iterationInTest];
  const res = http.put(
    `${__ENV.API}/api/v1/admin/variants/${c.variantId}/price`,
    JSON.stringify({ gross: c.gross + 100, version: c.version }),
    { headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` } },
  );
  check(res, { 'price changed': (r) => r.status === 200 });
}

export function handleSummary(data) {
  return { [__ENV.OUT]: JSON.stringify(data) };
}
