// The ramp to saturation (make -C infra/k8s load): the read and cart scenarios, no checkout, at a rate of iterations that
// grows by a step every RAMP_STEP_SECONDS until the p95 of the responses of a step is above 2 s or the 5xx and network
// errors of a step above 1% (SATURATION_*), which ends the run (a threshold with abortOnFail per step). The result is the
// maximum measured throughput of the system and the rate of the steady run (70% of it). Writes /out/ramp.json and
// /out/steady-rate; the exit code of k6 is 99 when the run ended on saturation, which is the normal end.
//
//   RAMP_START_RATE (10)   iterations per second of the first step
//   RAMP_RATE_STEP (10)    the rise of the rate per step
//   RAMP_STEPS (30)        the most steps
//   RAMP_STEP_SECONDS (30) the length of a step
//   RAMP_VUS (100), RAMP_MAX_VUS (1000)   the virtual users k6 starts with and may use; iterations that find none are dropped
import { SATURATION_ERRORS, SATURATION_P95_MS, number } from './lib/config.js';
import { discover } from './lib/data.js';
import { browse } from './lib/flows.js';
import { baseOptions, expectBusinessStatuses, newContext } from './lib/http.js';
import { counter, figures, reportOn, table } from './lib/summary.js';
import exec from 'k6/execution';

const steps = number('RAMP_STEPS', 30);
const stepSeconds = number('RAMP_STEP_SECONDS', 30);
const startRate = number('RAMP_START_RATE', 10);
const rateStep = number('RAMP_RATE_STEP', 10);
const rateOf = (step) => startRate + step * rateStep;

const stages = [];
for (let step = 0; step < steps; step++) {
  // The first second raises the rate to the one of the step, the rest holds it.
  stages.push({ target: rateOf(step), duration: '1s' });
  stages.push({ target: rateOf(step), duration: `${stepSeconds - 1}s` });
}

const thresholds = {};
const selectors = ['kind:read', 'kind:cart', 'scenario:ramp'];
for (let step = 0; step < steps; step++) {
  const delayAbortEval = `${step * stepSeconds + Math.min(10, Math.ceil(stepSeconds / 2))}s`;
  thresholds[`http_req_duration{step:${step}}`] = [{ threshold: `p(95)<${SATURATION_P95_MS}`, abortOnFail: true, delayAbortEval }];
  thresholds[`server_errors{step:${step}}`] = [{ threshold: `rate<${SATURATION_ERRORS}`, abortOnFail: true, delayAbortEval }];
  selectors.push(`step:${step}`);
  thresholds[`iterations_done{step:${step}}`] = [];
}
for (const flow of ['home', 'category', 'product', 'search', 'search_filtered', 'cart_add', 'cart_read', 'cart_page']) {
  selectors.push(`flow:${flow}`);
}
reportOn(thresholds, selectors);
thresholds['dropped_iterations{scenario:ramp}'] = [];

export const options = {
  ...baseOptions,
  scenarios: {
    ramp: {
      executor: 'ramping-arrival-rate',
      startRate: rateOf(0),
      timeUnit: '1s',
      preAllocatedVUs: number('RAMP_VUS', 100),
      maxVUs: number('RAMP_MAX_VUS', 1000),
      stages,
    },
  },
  thresholds,
};

export function setup() {
  expectBusinessStatuses();
  return discover();
}

export default function (data) {
  expectBusinessStatuses();
  const step = Math.min(steps - 1, Math.floor((Date.now() - exec.scenario.startTime) / (stepSeconds * 1000)));
  browse(newContext(18, step), data);
}

export function handleSummary(summary) {
  const perStep = [];
  let saturatedAt = null;
  let sustainable = null;
  for (let step = 0; step < steps; step++) {
    const f = figures(summary, `step:${step}`, stepSeconds);
    if (f.requests === 0) {
      continue;
    }
    const achieved = counter(summary, 'iterations_done', `step:${step}`) / stepSeconds;
    const reason = f.p95_ms > SATURATION_P95_MS ? 'p95' : f.server_error_rate > SATURATION_ERRORS ? 'errors' : null;
    perStep.push({ step, target_iterations_per_second: rateOf(step), achieved_iterations_per_second: Number(achieved.toFixed(2)), saturated: reason, ...f });
    if (reason !== null && saturatedAt === null) {
      saturatedAt = { step, reason };
    }
    if (saturatedAt === null) {
      sustainable = Math.max(sustainable === null ? 0 : sustainable, achieved);
    }
  }
  // The throughput of the system: the highest rate of iterations a step held without saturation. Without a step that held
  // it, the first target is what the system could not hold, and the steady run starts from 70% of that.
  const maximum = sustainable !== null ? sustainable : rateOf(0);
  const steadyRate = Math.max(1, Math.floor(maximum * 0.7));
  const seconds = perStep.length * stepSeconds;
  const result = {
    saturation_criterion: { p95_ms_above: SATURATION_P95_MS, server_error_rate_above: SATURATION_ERRORS },
    saturated: saturatedAt,
    max_sustained_iterations_per_second: Number(maximum.toFixed(2)),
    steady_iterations_per_second: steadyRate,
    steps: perStep,
    dropped_iterations: counter(summary, 'dropped_iterations', 'scenario:ramp'),
    scenario: figures(summary, 'scenario:ramp', seconds),
    kinds: { read: figures(summary, 'kind:read', seconds), cart: figures(summary, 'kind:cart', seconds) },
    flows: Object.fromEntries(
      ['home', 'category', 'product', 'search', 'search_filtered', 'cart_add', 'cart_read', 'cart_page'].map((flow) => [flow, figures(summary, `flow:${flow}`, seconds)]),
    ),
  };
  const stepRows = perStep.map((s) => [`step ${s.step} (${s.target_iterations_per_second}/s)`, s]);
  const text =
    table('Ramp: by step', stepRows) +
    table('Ramp: reads and carts', [['read', result.kinds.read], ['cart', result.kinds.cart], ...Object.entries(result.flows)]) +
    `\nsaturated: ${saturatedAt === null ? 'no, the ramp ended first' : `step ${saturatedAt.step} (${saturatedAt.reason})`}; ` +
    `max sustained ${result.max_sustained_iterations_per_second} iterations/s; steady rate ${steadyRate}; dropped iterations ${result.dropped_iterations}\n`;
  return { stdout: text, '/out/ramp.json': JSON.stringify(result, null, 2), '/out/steady-rate': `${steadyRate}\n` };
}
