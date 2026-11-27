// The writes scenario (docs/features/search/spec.md, "Weryfikacja wydajności (PERF-2 etap B)"; criterion 3 of PERF-2 in
// docs/features/performance-program/plan.md): price changes and stock receipts through the API of the panel at a given rate
// (open model) on a sample of variants chosen at the start, the freshness of the index (W16: the time from the answer of a write
// to the moment the variant query sees it) and, after the writes, the consistency of the index with the confirmed writes (W13).
// Run by load/writes.sh (load/README.md, "Scenariusz zapisów"). The effects stay: nothing is undone.
//
// One virtual user drives everything with asynchronous requests, so the state of every variant (its version, the prices and the
// availability it may have) is in one place: the open model sends a write every 1/WRITE_RATE s whatever the answers, up to
// WRITE_MAX_IN_FLIGHT at once (a write that cannot start is dropped and counted); the price probes and the availability probes run
// next to it; the consistency check follows when every write has its answer.
//
//   WRITE_RATE (required)              writes per second of the open model (price changes and receipts of the bulk)
//   WRITE_SECONDS (60)                 how long the writes go on
//   WRITE_SAMPLE (40)                  variants in the sample
//   WRITE_UNSTOCKED_SHARE (0.1)        the share of the sample without available stock at the start (0 to 1)
//   WRITE_PRICE_PROBES (3)             purchasable variants of the sample that measure the freshness of the price
//   WRITE_PRICE_SHARE (0.5)            the share of price changes among the writes of the open model (0 to 1)
//   WRITE_MAX_IN_FLIGHT (100)          writes of the open model waiting for an answer at once
//   WRITE_DISCOVER_PRODUCTS (3 x WRITE_SAMPLE, at least 60)  products read to find the sample, from each source
//   FRESHNESS_POLL_MS (250)            the pause between two variant queries of a probe
//   FRESHNESS_TIMEOUT_SECONDS (120)    a change not seen this long is a timeout of its probe, which then stops
//   CHECK_TIMEOUT_SECONDS (120)        how long the consistency check may wait for the index to show the expected states
//   CHECK_POLL_SECONDS (2)             the pause between two rounds of the check
//   WRITE_TIMEOUT_SECONDS (60)         a write without an answer this long is unanswered (it may have been saved or not)
//   WRITE_SEND_TOLERANCE_MS (1000)     a write of the open model sent later than this after its time is late
//   WRITES_RESULT                     the file of the result (JSON), e.g. /out/writes.json
//   API_URL, API_HOST, KEYCLOAK_URL, KEYCLOAK_HOST, ADMIN_REFRESH_TOKEN   lib/admin.js
//
// Exit code (k6): 0 a measurement at the rate whose check passed; 99 no such measurement (lib/write-state.js, runVerdict: a write
// not sent at the rate, without an answer or with a 5xx, a change not seen within the freshness timeout, a refused write, a mismatch
// of the index, an error of the run such as a variant query beyond the window or through a cache or a token that cannot be renewed,
// a consistency check that did not finish); 107 the run could not start (no or a refused token, the API or Keycloak not answering,
// an empty sample, settings out of range); 105 interrupted. Every case says why, and the result has the verdict with its reasons.
import http from 'k6/http';
import { Counter, Gauge, Trend } from 'k6/metrics';
import { PanelSession, apiParams, apiUrl, getJson } from './lib/admin.js';
import { number, text } from './lib/env.js';
import { find } from './lib/variant-query.js';
import { INDEX_DIFFERS, discoverSample } from './lib/write-sample.js';
import {
  afterPriceChange,
  afterReceipt,
  allowedPrices,
  expectedStates,
  matchesIndex,
  isLate,
  nextPrice,
  runVerdict,
  sendTolerance,
  startRecord,
  writeOutcome,
} from './lib/write-state.js';

function share(name, fallback) {
  const value = number(name, fallback);
  if (value > 1) {
    throw new Error(`${name} must be between 0 and 1, got ${value}`);
  }
  return value;
}

const settings = {
  rate: number('WRITE_RATE'),
  seconds: number('WRITE_SECONDS', 60),
  size: Math.floor(number('WRITE_SAMPLE', 40)),
  unstockedShare: share('WRITE_UNSTOCKED_SHARE', 0.1),
  priceProbes: Math.floor(number('WRITE_PRICE_PROBES', 3)),
  priceShare: share('WRITE_PRICE_SHARE', 0.5),
  maxInFlight: Math.max(1, Math.floor(number('WRITE_MAX_IN_FLIGHT', 100))),
  pollMs: number('FRESHNESS_POLL_MS', 250),
  freshnessTimeoutSeconds: number('FRESHNESS_TIMEOUT_SECONDS', 120),
  checkTimeoutSeconds: number('CHECK_TIMEOUT_SECONDS', 120),
  checkPollSeconds: number('CHECK_POLL_SECONDS', 2),
  writeTimeoutSeconds: Math.max(1, number('WRITE_TIMEOUT_SECONDS', 60)),
};
const toleranceSetting = text('WRITE_SEND_TOLERANCE_MS', '');
settings.sendToleranceMs = settings.rate > 0 ? sendTolerance(toleranceSetting === '' ? undefined : number('WRITE_SEND_TOLERANCE_MS')) : null;
const tolerance = settings.sendToleranceMs;
settings.discoverProducts = Math.floor(number('WRITE_DISCOVER_PRODUCTS', Math.max(60, 3 * settings.size)));
const resultFile = text('WRITES_RESULT', '');

const sent = new Counter('writes_sent');
const changes = new Counter('changes_made');
const refused = new Counter('writes_refused');
const unanswered = new Counter('writes_unanswered');
const dropped = new Counter('writes_dropped');
const late = new Counter('writes_late');
const sendLag = new Trend('send_lag', true);
const freshnessPrice = new Trend('freshness_price', true);
const freshnessAvailability = new Trend('freshness_availability', true);
const freshnessTimeouts = new Counter('freshness_timeouts');
const mismatches = new Counter('consistency_mismatches');
const uncertainVariants = new Gauge('consistency_uncertain_variants');
const consistencySeconds = new Gauge('consistency_seconds');
const runErrors = new Counter('run_errors');
const queryErrors = new Counter('query_errors');
const finished = new Gauge('run_finished');
const writeSeconds = new Gauge('write_phase_seconds');

// The writes and the check finish within this; the setup reads a bounded number of products.
const maxSeconds = settings.seconds + settings.freshnessTimeoutSeconds + settings.writeTimeoutSeconds + settings.checkTimeoutSeconds + 300;

export const options = {
  scenarios: { writes: { executor: 'per-vu-iterations', vus: 1, iterations: 1, maxDuration: `${maxSeconds}s` } },
  setupTimeout: '10m',
  userAgent: 'shop-load-k6',
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max', 'count'],
  // The counts of runVerdict (lib/write-state.js): any of them fails the run. run_finished is 0 from the start of the iteration and 1
  // only after the consistency check, so a check cut off by maxDuration fails too.
  thresholds: {
    writes_dropped: ['count==0'],
    writes_late: ['count==0'],
    writes_unanswered: ['count==0'],
    freshness_timeouts: ['count==0'],
    writes_refused: ['count==0'],
    consistency_mismatches: ['count==0'],
    run_errors: ['count==0'],
    run_finished: ['value==1'],
    // Reported apart (no limit).
    'changes_made{action:price}': [],
    'changes_made{action:receipt}': [],
    'consistency_mismatches{kind:price}': [],
    'consistency_mismatches{kind:availability}': [],
    'freshness_timeouts{kind:price}': [],
    'freshness_timeouts{kind:availability}': [],
    'http_req_duration{name:price_change}': [],
    'http_req_duration{name:receipt}': [],
    'http_req_duration{name:variant_query}': [],
  },
};

export function setup() {
  const session = new PanelSession();
  session.authorizationSync();
  const me = getJson('/api/v1/admin/me', session);
  if (!me.roles.includes('admin')) {
    throw new Error(`the token is of '${me.username}' with the roles ${me.roles.join(', ') || '(none)'}: price changes need an administrator`);
  }
  if (settings.rate > 0 && settings.size - Math.round(settings.size * settings.unstockedShare) <= settings.priceProbes) {
    throw new Error('the open model needs purchasable variants beyond the price probes: raise WRITE_SAMPLE or lower WRITE_PRICE_PROBES');
  }
  const sample = discoverSample(session, settings);
  const parts = [['price_probe', sample.probes], ['bulk', sample.bulk], ['unstocked', sample.unstocked]];
  const variants = parts.flatMap(([part, list]) => list.map((variant) => ({ ...variant, part })));
  console.log(
    `setup: sample of ${variants.length} variants (${sample.bulk.length} bulk, ${sample.probes.length} price probes, ${sample.unstocked.length} without stock); left out: ${JSON.stringify(sample.skipped)}`,
  );
  console.log(`setup: sample ${JSON.stringify(variants.map((variant) => variant.variantId))}`);
  return { runId: `writes-${Date.now().toString(36)}-${Math.floor(Math.random() * 1e6).toString(36)}`, variants, skipped: sample.skipped };
}

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, Math.max(0, ms)));

function problemOf(res) {
  try {
    const body = res.json() || {};
    return `${body.type || ''} ${body.detail || ''}`.trim();
  } catch (e) {
    return '';
  }
}

// The run of one virtual user: the records of the variants, the writes, the probes and the check.
class Run {
  constructor(data) {
    this.data = data;
    this.session = new PanelSession();
    this.records = new Map(data.variants.map((variant) => [variant.variantId, startRecord(variant.initialPrice, variant.purchasable)]));
    this.versions = new Map(data.variants.map((variant) => [variant.variantId, variant.priceVersion]));
    this.busy = new Set();
    this.keys = 0;
    this.refusals = 0;
    this.stopped = null;
    this.endAt = 0;
  }

  stop(reason) {
    if (this.stopped === null) {
      this.stopped = reason;
      runErrors.add(1, { kind: 'stopped' });
      console.error(`writes stopped: ${reason}`);
    }
  }

  // The Authorization header, or null when the token cannot be renewed (then the writes stop).
  async authorization() {
    try {
      return await this.session.authorization();
    } catch (error) {
      this.stop(error.message);
      return null;
    }
  }

  outcome(res, action, part) {
    const outcome = writeOutcome(res.status);
    if (outcome === 'confirmed') {
      changes.add(1, { action });
    } else if (outcome === 'refused') {
      refused.add(1, { action, status: String(res.status) });
      this.refusals += 1;
      if (this.refusals <= 10) {
        console.error(`refused ${action} (${part}): ${res.status} ${problemOf(res)}`);
      }
    } else {
      unanswered.add(1, { action });
    }
    return outcome;
  }

  // A write of the open model leaves now: its lag behind the time it was due (`due`, ms) goes to send_lag, and a lag above the
  // tolerance (lib/write-state.js, sendTolerance) counts it late. The probes have no due time.
  sending(due) {
    if (due === undefined) {
      return;
    }
    const now = Date.now();
    sendLag.add(now - due);
    if (isLate(due, now, tolerance)) {
      late.add(1);
    }
  }

  // The variant is busy from the call on (before any await), so no second price change of it starts before this one has its answer.
  async changePrice(variant, price, part, due) {
    const id = variant.variantId;
    this.busy.add(id);
    const authorization = await this.authorization();
    if (authorization === null) {
      this.busy.delete(id);
      return null;
    }
    this.sending(due);
    sent.add(1, { action: 'price', part });
    const body = JSON.stringify({ gross: price, version: this.versions.get(id) });
    const res = await http.asyncRequest(
      'PUT',
      `${apiUrl}/api/v1/admin/variants/${id}/price`,
      body,
      apiParams('price_change', { Authorization: authorization, 'Content-Type': 'application/json' }, settings.writeTimeoutSeconds),
    );
    const at = Date.now();
    const outcome = this.outcome(res, 'price', part);
    this.records.set(id, afterPriceChange(this.records.get(id), price, outcome));
    if (outcome === 'confirmed') {
      this.versions.set(id, res.json('version'));
    }
    this.busy.delete(id);
    return { outcome, at };
  }

  async receive(variant, part, due) {
    const authorization = await this.authorization();
    if (authorization === null) {
      return null;
    }
    const id = variant.variantId;
    this.keys += 1;
    this.sending(due);
    sent.add(1, { action: 'receipt', part });
    const res = await http.asyncRequest(
      'POST',
      `${apiUrl}/api/v1/admin/variants/${id}/stock/receipts`,
      JSON.stringify({ quantity: 1, note: null }),
      apiParams('receipt', { Authorization: authorization, 'Content-Type': 'application/json', 'Idempotency-Key': `${this.data.runId}-${this.keys}` }, settings.writeTimeoutSeconds),
    );
    const at = Date.now();
    const outcome = this.outcome(res, 'receipt', part);
    this.records.set(id, afterReceipt(this.records.get(id), outcome));
    return { outcome, at };
  }

  // Waits until the variant query at the price finds the product; the freshness from `at` (the answer of the write) to the answer
  // of that query goes to the trend. False after the timeout or an error of the run.
  async seen(variant, price, at, trend, kind) {
    const limit = at + settings.freshnessTimeoutSeconds * 1000;
    while (Date.now() < limit) {
      const query = await find(variant, price);
      if (query.error) {
        queryErrors.add(1);
        if (query.cached) {
          this.stop(query.error);
          return false;
        }
      } else if (query.found) {
        trend.add(Date.now() - at);
        return true;
      } else if (query.beyondWindow) {
        runErrors.add(1, { kind: 'beyond_window' });
        console.error(`variant ${variant.variantId}: not found while the total ${query.total} is beyond the window`);
        return false;
      }
      await wait(settings.pollMs);
    }
    freshnessTimeouts.add(1, { kind });
    return false;
  }

  // A price probe: the next change of its variant starts only once the previous one is seen (the prices repeat).
  async priceProbe(variant) {
    while (Date.now() < this.endAt && this.stopped === null) {
      const record = this.records.get(variant.variantId);
      if (record.uncertain) {
        return;
      }
      const price = nextPrice(record.initialPrice, record.prices[0], Math.random());
      const write = await this.changePrice(variant, price, 'price_probe');
      if (write === null || write.outcome !== 'confirmed' || !(await this.seen(variant, price, write.at, freshnessPrice, 'price'))) {
        return;
      }
    }
  }

  // The availability probes: the first receipt of every variant without stock, spread evenly over the run, and the time until the
  // variant query at its price finds the product.
  async availabilityProbes(start) {
    const list = this.data.variants.filter((variant) => variant.part === 'unstocked');
    const pending = [];
    for (let i = 0; i < list.length; i += 1) {
      await wait(start + (i * settings.seconds * 1000) / list.length - Date.now());
      if (Date.now() >= this.endAt || this.stopped !== null) {
        break;
      }
      pending.push(
        this.receive(list[i], 'availability_probe').then((write) =>
          write !== null && write.outcome === 'confirmed' ? this.seen(list[i], list[i].initialPrice, write.at, freshnessAvailability, 'availability') : false,
        ),
      );
    }
    await Promise.all(pending);
  }

  // One write of the open model: a price change of a bulk variant without a write in flight, or a receipt of a purchasable one.
  write(due) {
    const usable = (variant) => !this.records.get(variant.variantId).uncertain;
    if (Math.random() < settings.priceShare) {
      const free = this.data.variants.filter((variant) => variant.part === 'bulk' && usable(variant) && !this.busy.has(variant.variantId));
      if (free.length === 0) {
        dropped.add(1, { reason: 'no_free_variant' });
        return Promise.resolve();
      }
      const variant = free[Math.floor(Math.random() * free.length)];
      const record = this.records.get(variant.variantId);
      return this.changePrice(variant, nextPrice(record.initialPrice, record.prices[0], Math.random()), 'open', due);
    }
    const stocked = this.data.variants.filter((variant) => variant.part !== 'unstocked' && usable(variant));
    if (stocked.length === 0) {
      dropped.add(1, { reason: 'no_free_variant' });
      return Promise.resolve();
    }
    return this.receive(stocked[Math.floor(Math.random() * stocked.length)], 'open', due);
  }

  async openModel(start) {
    if (settings.rate <= 0) {
      return;
    }
    const inFlight = new Set();
    for (let i = 0; ; i += 1) {
      const due = start + (i * 1000) / settings.rate;
      if (due >= this.endAt || this.stopped !== null) {
        break;
      }
      await wait(due - Date.now());
      if (inFlight.size >= settings.maxInFlight) {
        dropped.add(1, { reason: 'in_flight' });
        continue;
      }
      const write = this.write(due).then(
        () => inFlight.delete(write),
        (error) => {
          inFlight.delete(write);
          this.stop(`a write failed: ${error.message}`);
        },
      );
      inFlight.add(write);
    }
    await Promise.all([...inFlight]);
  }

  // Whether the index shows one of the states the variant may have: the variant query at each of their prices.
  async evaluate(variant) {
    const states = expectedStates(this.records.get(variant.variantId));
    const foundAt = {};
    for (const price of [...new Set(states.map((state) => state.price))]) {
      const query = await find(variant, price);
      if (query.error) {
        queryErrors.add(1);
        return { ok: false, error: query.error };
      }
      if (query.beyondWindow) {
        return { ok: false, error: `not found at ${price} while the total ${query.total} is beyond the window` };
      }
      foundAt[price] = query.found;
      if (matchesIndex(states, foundAt)) {
        return { ok: true };
      }
    }
    return { ok: false };
  }

  // What the index shows: the prices (of the start and 1 grosz either side) at which the variant query finds the product.
  async shown(variant) {
    const found = [];
    for (const price of allowedPrices(variant.initialPrice)) {
      const query = await find(variant, price);
      if (query.error) {
        return `the query failed (${query.error})`;
      }
      if (query.found) {
        found.push(price);
      }
    }
    return found.length > 0 ? `purchasable at ${found.join(', ')}` : `not purchasable at ${allowedPrices(variant.initialPrice).join(', ')}`;
  }

  // The consistency check: the variant queries are repeated until every variant shows a state it may have (the queue of the
  // reindexing may lag), at most CHECK_TIMEOUT_SECONDS; then every variant left is a mismatch (or an error of the run).
  async check() {
    const started = Date.now();
    const deadline = started + settings.checkTimeoutSeconds * 1000;
    uncertainVariants.add(this.data.variants.filter((variant) => expectedStates(this.records.get(variant.variantId)).length > 1).length);
    let pending = this.data.variants;
    let last = new Map();
    while (true) {
      const still = [];
      last = new Map();
      for (let from = 0; from < pending.length; from += 8) {
        const batch = pending.slice(from, from + 8);
        const results = await Promise.all(batch.map((variant) => this.evaluate(variant)));
        batch.forEach((variant, i) => {
          if (!results[i].ok) {
            still.push(variant);
            last.set(variant.variantId, results[i]);
          }
        });
      }
      pending = still;
      if (pending.length === 0 || Date.now() >= deadline) {
        break;
      }
      await wait(settings.checkPollSeconds * 1000);
    }
    if (pending.length === 0) {
      consistencySeconds.add((Date.now() - started) / 1000);
    }
    for (const variant of pending) {
      const result = last.get(variant.variantId);
      if (result.error) {
        runErrors.add(1, { kind: 'check' });
        console.error(`consistency: variant ${variant.variantId} (product ${variant.productId}) cannot be checked: ${result.error}`);
        continue;
      }
      const kind = variant.part === 'unstocked' ? 'availability' : 'price';
      mismatches.add(1, { kind });
      const expected = expectedStates(this.records.get(variant.variantId))
        .map((state) => `${state.purchasable ? 'purchasable' : 'not purchasable'} at ${state.price}`)
        .join(' or ');
      console.error(
        `consistency: mismatch of variant ${variant.variantId} (product ${variant.productId}, ${variant.part}): expected ${expected}, the index shows ${await this.shown(variant)}`,
      );
    }
    console.log(`consistency: ${this.data.variants.length - pending.length} of ${this.data.variants.length} variants match the index`);
  }

  async go() {
    if ((await this.authorization()) === null) {
      return;
    }
    const start = Date.now();
    this.endAt = start + settings.seconds * 1000;
    const probes = this.data.variants.filter((variant) => variant.part === 'price_probe');
    await Promise.all([this.openModel(start), this.availabilityProbes(start), ...probes.map((variant) => this.priceProbe(variant))]);
    // From the first write to the last answer (of a write or of a freshness query).
    writeSeconds.add((Date.now() - start) / 1000);
    await this.check();
    finished.add(1);
  }
}

export default async function (data) {
  // 0 until the check is done (threshold run_finished: a run cut off by maxDuration fails).
  finished.add(0);
  await new Run(data).go();
}

const values = (summary, name) => (summary.metrics[name] ? summary.metrics[name].values : {});
const countOf = (summary, name) => values(summary, name).count || 0;
const round = (value) => (value === undefined || value === null ? null : Math.round(value));

function trendOf(summary, name) {
  const v = values(summary, name);
  return { count: v.count || 0, p50_ms: round(v.med), p95_ms: round(v['p(95)']), p99_ms: round(v['p(99)']), max_ms: round(v.max) };
}

// The result, also of an interrupted run: the sample (from setup) and the changes made so far.
export function handleSummary(summary) {
  const data = summary.setup_data || { variants: [], skipped: {} };
  const made = countOf(summary, 'changes_made');
  const result = {
    finished: values(summary, 'run_finished').value === 1,
    settings: { ...settings, apiUrl },
    sample: {
      size: data.variants.length,
      variants: data.variants.map((variant) => ({
        variantId: variant.variantId,
        productId: variant.productId,
        part: variant.part,
        initialPrice: variant.initialPrice,
        purchasableAtStart: variant.purchasable,
      })),
      left_out: data.skipped,
    },
    changes: {
      made,
      prices: countOf(summary, 'changes_made{action:price}'),
      receipts: countOf(summary, 'changes_made{action:receipt}'),
      // Over WRITE_SECONDS, the time the writes were sent; null when the run ended before (interrupted).
      per_second: values(summary, 'write_phase_seconds').value !== undefined && settings.seconds > 0 ? Number((made / settings.seconds).toFixed(2)) : null,
      write_phase_seconds: values(summary, 'write_phase_seconds').value === undefined ? null : Number(values(summary, 'write_phase_seconds').value.toFixed(1)),
      sent: countOf(summary, 'writes_sent'),
      refused: countOf(summary, 'writes_refused'),
      unanswered: countOf(summary, 'writes_unanswered'),
      dropped: countOf(summary, 'writes_dropped'),
      late: countOf(summary, 'writes_late'),
      send_lag: trendOf(summary, 'send_lag'),
      send_tolerance_ms: settings.sendToleranceMs,
      duration: { price_change: trendOf(summary, 'http_req_duration{name:price_change}'), receipt: trendOf(summary, 'http_req_duration{name:receipt}') },
    },
    freshness: {
      price: trendOf(summary, 'freshness_price'),
      availability: trendOf(summary, 'freshness_availability'),
      timeouts: { price: countOf(summary, 'freshness_timeouts{kind:price}'), availability: countOf(summary, 'freshness_timeouts{kind:availability}') },
      // A change not seen within FRESHNESS_TIMEOUT_SECONDS is not in the percentiles, so with a timeout they are no result of W16.
      percentiles_valid: countOf(summary, 'freshness_timeouts') === 0,
    },
    consistency: {
      mismatches: countOf(summary, 'consistency_mismatches'),
      price: countOf(summary, 'consistency_mismatches{kind:price}'),
      availability: countOf(summary, 'consistency_mismatches{kind:availability}'),
      uncertain_variants: values(summary, 'consistency_uncertain_variants').value || 0,
      seconds_to_consistent: values(summary, 'consistency_seconds').value === undefined ? null : values(summary, 'consistency_seconds').value,
      run_errors: countOf(summary, 'run_errors'),
      query_errors: countOf(summary, 'query_errors'),
    },
  };
  const c = result.changes;
  const f = result.freshness;
  const k = result.consistency;
  // The same counts as the thresholds of the options: the verdict says why a run that fails is no measurement.
  result.verdict = runVerdict({
    finished: result.finished,
    dropped: c.dropped,
    late: c.late,
    unanswered: c.unanswered,
    freshnessTimeouts: f.timeouts.price + f.timeouts.availability,
    refused: c.refused,
    mismatches: k.mismatches,
    runErrors: k.run_errors,
  });
  const indexDiffered = (data.skipped || {})[INDEX_DIFFERS] || 0;
  const text =
    `\nWrites: ${result.finished ? 'finished' : 'NOT finished (interrupted or stopped before the end of the check)'}\n` +
    `verdict: ${result.verdict.passed ? 'a measurement at the rate, the index consistent' : `NO MEASUREMENT: ${result.verdict.reasons.join('; ')}`}\n` +
    (indexDiffered > 0 ? `WARNING: ${indexDiffered} candidates left out because the index differed from the offer at the start (W13; a previous run?)\n` : '') +
    `sample ${result.sample.size} variants; changes made ${c.made} (${c.prices} prices, ${c.receipts} receipts, ${c.per_second}/s), sent ${c.sent}, refused ${c.refused}, unanswered ${c.unanswered}, dropped ${c.dropped}, late ${c.late}\n` +
    `send lag of the open model: p50 ${c.send_lag.p50_ms} ms, p95 ${c.send_lag.p95_ms} ms, p99 ${c.send_lag.p99_ms} ms, max ${c.send_lag.max_ms} ms (tolerance ${c.send_tolerance_ms} ms)\n` +
    `freshness${f.percentiles_valid ? '' : ' (NOT a result of W16: changes not seen within the timeout are left out)'}:\n` +
    `freshness of the price: ${f.price.count} samples, p50 ${f.price.p50_ms} ms, p95 ${f.price.p95_ms} ms, p99 ${f.price.p99_ms} ms, max ${f.price.max_ms} ms, timeouts ${f.timeouts.price}\n` +
    `freshness of the availability: ${f.availability.count} samples, p50 ${f.availability.p50_ms} ms, p95 ${f.availability.p95_ms} ms, max ${f.availability.max_ms} ms, timeouts ${f.timeouts.availability}\n` +
    `consistency: ${k.mismatches} mismatches (${k.price} price, ${k.availability} availability), ${k.uncertain_variants} variants checked against several states, consistent after ${k.seconds_to_consistent} s, run errors ${k.run_errors}\n` +
    `variants of the sample: ${result.sample.variants.map((variant) => variant.variantId).join(' ')}\n`;
  const out = { stdout: text };
  if (resultFile !== '') {
    out[resultFile] = JSON.stringify(result, null, 2);
  }
  return out;
}
