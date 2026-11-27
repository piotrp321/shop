// The test of the state of the writes scenario (load/lib/write-state.js; make load-logic, load/test/write-state.sh): the prices a
// change may set, the states a variant may have after confirmed, refused and unanswered writes, the comparison with the index,
// the choice of the sample, the variants that cannot be in it and the verdict of a run. No network; any failed check fails the run
// (exit 99).
import { check } from 'k6';
import {
  afterPriceChange,
  afterReceipt,
  allowedPrices,
  chooseSample,
  expectedStates,
  ineligibility,
  matchesIndex,
  nextPrice,
  runVerdict,
  sendTolerance,
  isLate,
  tokenAction,
  catchUpStats,
  isCatchUp,
  lagBucket,
  rateBand,
  stallTolerance,
  windowStats,
  startRecord,
  writeOutcome,
} from '../lib/write-state.js';

export const options = {
  scenarios: { logic: { executor: 'per-vu-iterations', vus: 1, iterations: 1, maxDuration: '30s' } },
  thresholds: { checks: ['rate==1'] },
};

// Every case is a check of its own; a case that throws fails like a false one, and the next cases still run.
function verify(cases) {
  const safe = {};
  for (const [name, predicate] of Object.entries(cases)) {
    safe[name] = () => {
      try {
        return predicate() === true;
      } catch (error) {
        console.error(`${name}: ${error.message}`);
        return false;
      }
    };
  }
  check(null, safe);
}

const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const byPrice = (states) => [...states].sort((x, y) => x.price - y.price || Number(x.purchasable) - Number(y.purchasable));

function throws(fn, fragment) {
  try {
    fn();
  } catch (error) {
    return String(error.message).includes(fragment);
  }
  return false;
}

function prices() {
  verify({
    'the prices are the initial one and 1 grosz either side': () => same(allowedPrices(5000), [4999, 5000, 5001]),
    'no price below 1 grosz': () => same(allowedPrices(1), [1, 2]),
    'no price above the highest gross': () => same(allowedPrices(99999999), [99999998, 99999999]),
    'the next price is never the current one': () => [0, 0.49, 0.5, 0.99].every((rnd) => nextPrice(5000, 5001, rnd) !== 5001),
    'the next price stays within 1 grosz of the initial one': () =>
      [5000, 4999, 5001].every((current) => [0, 0.5, 0.99].every((rnd) => Math.abs(nextPrice(5000, current, rnd) - 5000) <= 1)),
    'from the initial price both neighbours can be chosen': () => nextPrice(5000, 5000, 0) === 4999 && nextPrice(5000, 5000, 0.99) === 5001,
    'at 1 grosz the only other price is 2 grosze': () => nextPrice(1, 1, 0.99) === 2,
  });
}

function outcomes() {
  verify({
    '2xx is a confirmed write': () => [200, 201].every((status) => writeOutcome(status) === 'confirmed'),
    'a 4xx is refused (nothing changed)': () => [400, 401, 403, 404, 409, 422].every((status) => writeOutcome(status) === 'refused'),
    'no answer and a 5xx are unanswered (it may have been saved)': () => [0, 500, 502, 503, 504].every((status) => writeOutcome(status) === 'unanswered'),
  });
}

function states() {
  const stocked = startRecord(5000, true);
  const unstocked = startRecord(7000, false);
  const changed = afterPriceChange(afterPriceChange(stocked, 5001, 'confirmed'), 5000, 'confirmed');
  const refused = afterPriceChange(stocked, 5001, 'refused');
  const unanswered = afterPriceChange(afterPriceChange(stocked, 5001, 'confirmed'), 5000, 'unanswered');
  const received = afterReceipt(unstocked, 'confirmed');
  const receiptUnanswered = afterReceipt(unstocked, 'unanswered');
  verify({
    'at the start the state is the initial price and availability': () =>
      same(expectedStates(stocked), [{ price: 5000, purchasable: true }]) && same(expectedStates(unstocked), [{ price: 7000, purchasable: false }]),
    'a new record is certain': () => stocked.uncertain === false,
    'the last confirmed price is expected': () => same(expectedStates(changed), [{ price: 5000, purchasable: true }]),
    'a refused change leaves the price': () => same(expectedStates(refused), [{ price: 5000, purchasable: true }]) && refused.uncertain === false,
    'an unanswered change: the price before it or the one sent': () =>
      same(byPrice(expectedStates(unanswered)), [{ price: 5000, purchasable: true }, { price: 5001, purchasable: true }]),
    'an unanswered change makes the variant uncertain': () => unanswered.uncertain === true,
    'a confirmed receipt makes a variant without stock purchasable': () => same(expectedStates(received), [{ price: 7000, purchasable: true }]),
    'an unanswered receipt: purchasable or not': () =>
      same(byPrice(expectedStates(receiptUnanswered)), [{ price: 7000, purchasable: false }, { price: 7000, purchasable: true }]) && receiptUnanswered.uncertain,
    'a refused receipt leaves the availability': () => same(expectedStates(afterReceipt(unstocked, 'refused')), [{ price: 7000, purchasable: false }]),
    'a receipt never takes the availability away': () => same(expectedStates(afterReceipt(stocked, 'confirmed')), [{ price: 5000, purchasable: true }]),
    'the records are not changed in place': () => same(expectedStates(stocked), [{ price: 5000, purchasable: true }]),
  });
}

function comparison() {
  const one = [{ price: 5001, purchasable: true }];
  const either = [{ price: 5000, purchasable: true }, { price: 5001, purchasable: true }];
  const unstocked = [{ price: 7000, purchasable: false }];
  verify({
    'found at the expected price matches': () => matchesIndex(one, { 5001: true }),
    'not found at the expected price is a mismatch': () => !matchesIndex(one, { 5001: false, 5000: true }),
    'a price that was not queried is no match': () => !matchesIndex(one, { 5000: true }),
    'an uncertain variant matches either state': () => matchesIndex(either, { 5000: true, 5001: false }) && matchesIndex(either, { 5000: false, 5001: true }),
    'an uncertain variant found at neither price is a mismatch': () => !matchesIndex(either, { 5000: false, 5001: false }),
    'a variant that must not be purchasable matches when not found': () => matchesIndex(unstocked, { 7000: false }),
    'a variant that must not be purchasable is a mismatch when found': () => !matchesIndex(unstocked, { 7000: true }),
  });
}

function eligibility() {
  const offer = { gross: 5000, regularGross: 5000, promotion: null, purchasable: true };
  verify({
    'a variant with a price and axes without a colon is eligible': () => ineligibility(['Kolor', 'Rozmiar'], ['czarny', '42'], offer) === null,
    'a product without axes is eligible': () => ineligibility([], [], offer) === null,
    'a value with a colon is eligible (the filter splits on the first one)': () => ineligibility(['Wersja'], ['2:1'], offer) === null,
    'an axis with a colon is not': () => ineligibility(['Kolor', 'Typ: A'], ['czarny', 'x'], offer) !== null,
    'a variant without a price is not': () => ineligibility([], [], { ...offer, gross: null, regularGross: null }) !== null,
    'a variant in a promotion is not': () =>
      ineligibility([], [], { ...offer, gross: 4000, promotion: { endsAt: '2026-12-01T00:00:00+00:00' } }) !== null &&
      ineligibility([], [], { ...offer, gross: 4000, regularGross: 5000 }) !== null,
    'a variant without an offer is not': () => ineligibility([], [], undefined) !== null,
    'values that do not match the axes are not': () => ineligibility(['Kolor'], [], offer) !== null,
  });
}

function sampling() {
  const stocked = Array.from({ length: 10 }, (_, i) => ({ id: `s${i}` }));
  const unstocked = Array.from({ length: 4 }, (_, i) => ({ id: `u${i}` }));
  const rnd = () => 0.3;
  const chosen = chooseSample({ stocked, unstocked }, { size: 10, unstockedShare: 0.2, priceProbes: 3 }, rnd) || { bulk: [], probes: [], unstocked: [] };
  const ids = (list) => list.map((item) => item.id);
  const all = [...ids(chosen.bulk), ...ids(chosen.probes), ...ids(chosen.unstocked)];
  verify({
    'the share of variants without stock is taken from the size': () => chosen.unstocked.length === 2,
    'the price probes come from the stocked part': () => chosen.probes.length === 3 && ids(chosen.probes).every((id) => id.startsWith('s')),
    'the rest of the stocked part is the bulk': () => chosen.bulk.length === 5 && ids(chosen.bulk).every((id) => id.startsWith('s')),
    'no variant is in two parts': () => new Set(all).size === all.length,
    'a share of 0 takes no variant without stock': () => chooseSample({ stocked, unstocked: [] }, { size: 4, unstockedShare: 0, priceProbes: 1 }, rnd).unstocked.length === 0,
    'a share above 0 without variants without stock is an error': () =>
      throws(() => chooseSample({ stocked, unstocked: [] }, { size: 10, unstockedShare: 0.2, priceProbes: 1 }, rnd), 'without stock'),
    'too few variants without stock is an error': () =>
      throws(() => chooseSample({ stocked, unstocked: unstocked.slice(0, 1) }, { size: 10, unstockedShare: 0.2, priceProbes: 1 }, rnd), 'without stock'),
    'too few stocked variants is an error': () =>
      throws(() => chooseSample({ stocked: stocked.slice(0, 2), unstocked }, { size: 10, unstockedShare: 0.2, priceProbes: 1 }, rnd), 'purchasable'),
    'more price probes than stocked variants is an error': () =>
      throws(() => chooseSample({ stocked, unstocked }, { size: 5, unstockedShare: 0.2, priceProbes: 5 }, rnd), 'probes'),
    'an empty sample is an error': () => throws(() => chooseSample({ stocked: [], unstocked: [] }, { size: 10, unstockedShare: 0, priceProbes: 0 }, rnd), 'empty'),
    'a sample of size 0 is an error': () => throws(() => chooseSample({ stocked, unstocked }, { size: 0, unstockedShare: 0, priceProbes: 0 }, rnd), 'empty'),
  });
}

function verdicts() {
  const clean = { finished: true, dropped: 0, unanswered: 0, freshnessTimeouts: 0, refused: 0, mismatches: 0, runErrors: 0, late: 0, windowsOutside: 0, catchUpWrites: 0 };
  const failsWith = (counts, fragment) => {
    const verdict = runVerdict({ ...clean, ...counts });
    return verdict.passed === false && verdict.reasons.length === 1 && verdict.reasons[0].includes(fragment);
  };
  verify({
    'a finished run without a failure passes': () => same(runVerdict(clean), { passed: true, reasons: [] }),
    'a write not sent at the rate fails the run (it is no measurement at the rate)': () => failsWith({ dropped: 1 }, 'not sent at the rate'),
    'a write sent later than the tolerance fails the run (not sent at the rate)': () => failsWith({ late: 2 }, 'later than'),
    'a window of 1 s off the rate fails the run (a hole or a burst)': () => failsWith({ windowsOutside: 2 }, 'windows of 1 s'),
    'any catch-up write fails the run (a stall above the tolerance)': () => failsWith({ catchUpWrites: 1 }, 'catch-up'),
    'a write without an answer or with a 5xx fails the run': () => failsWith({ unanswered: 2 }, 'without an answer'),
    'a change not seen within the freshness timeout fails the run (the percentiles leave it out)': () =>
      failsWith({ freshnessTimeouts: 1 }, 'not seen within'),
    'a refused write fails the run': () => failsWith({ refused: 1 }, 'refused'),
    'a mismatch of the index fails the run': () => failsWith({ mismatches: 3 }, 'mismatch'),
    'an error of the run fails it': () => failsWith({ runErrors: 1 }, 'error'),
    'a consistency check that did not finish fails the run': () => failsWith({ finished: false }, 'did not finish'),
    'an interrupted run has one reason for the rate, not the rules of the rate': () => {
      const verdict = runVerdict({ ...clean, finished: false, windowsOutside: undefined, catchUpWrites: undefined });
      return verdict.passed === false && verdict.reasons.length === 1 && verdict.reasons[0].includes('no proof of the rate');
    },
    'a finished run without the counts of the rate fails': () => runVerdict({ ...clean, windowsOutside: undefined }).passed === false,
    'the reasons give the numbers': () => runVerdict({ ...clean, dropped: 4 }).reasons[0].includes('4'),
    'every failure is a reason of its own': () => runVerdict({ ...clean, finished: false, dropped: 1, late: 1, unanswered: 1, freshnessTimeouts: 1 }).reasons.length === 5,
    'a missing count fails the run': () => runVerdict({ finished: true }).passed === false,
  });
}

function windows() {
  verify({
    'the lag buckets are closed at the top': () =>
      [[0, 'le_1'], [1, 'le_1'], [1.5, 'le_5'], [5, 'le_5'], [20, 'le_20'], [21, 'le_100'], [100, 'le_100'], [1000, 'le_1000'], [1001, 'gt_1000']].every(
        ([lag, bucket]) => lagBucket(lag) === bucket,
      ),
    'the band of a window holds the writes a stall of the tolerance can move across its edge': () =>
      same(rateBand(50, 100), { low: 45, high: 55 }) && same(rateBand(12, 100), { low: 10, high: 14 }) && same(rateBand(100, 100), { low: 90, high: 110 }),
    'the band is at least one write either side': () => same(rateBand(2.5, 100), { low: 1, high: 4 }) && same(rateBand(50, 0), { low: 49, high: 51 }),
    'windows at the rate are inside the band': () => {
      const stats = windowStats([50, 50, 45, 55, 50], 50, 100);
      return stats.windows === 5 && stats.min === 45 && stats.max === 55 && stats.p50 === 50 && stats.below === 0 && stats.above === 0;
    },
    'a hole and a burst are a window below and one above the band': () => {
      const stats = windowStats([50, 44, 56, 50], 50, 100);
      return stats.below === 1 && stats.above === 1 && stats.min === 44 && stats.max === 56;
    },
    'the percentiles of the windows are nearest-rank': () => {
      const stats = windowStats([70, 50, 30, 50], 50, 100);
      return stats.p5 === 30 && stats.p50 === 50 && stats.p95 === 70;
    },
    'no window gives no figures and nothing outside': () => same(windowStats([], 50, 100), { windows: 0, min: null, p5: null, p50: null, p95: null, max: null, below: 0, above: 0 }),
    'a stall up to the tolerance is no catch-up, whatever the rate': () =>
      isCatchUp(1000, 1100, 50, 100) === false && isCatchUp(1000, 1100, 100, 100) === false && isCatchUp(1000, 1100, 12, 100) === false,
    'a write sent later than the stall tolerance is a catch-up': () => isCatchUp(1000, 1101, 50, 100) === true && isCatchUp(1000, 1101, 100, 100) === true,
    'at a slow rate a catch-up is later than one interval': () => isCatchUp(1000, 1500, 2, 100) === false && isCatchUp(1000, 1501, 2, 100) === true,
    'the stall tolerance comes from the settings': () => stallTolerance(undefined) === 100 && stallTolerance(250) === 250,
    'the catch-up writes and the largest series in a window': () => same(catchUpStats([0, 3, 1, 0]), { writes: 4, maxInWindow: 3 }),
    'no window gives no catch-up': () => same(catchUpStats([]), { writes: 0, maxInWindow: 0 }),
  });
}

function timing() {
  const now = 1_000_000;
  verify({
    'the tolerance of a send is 1000 ms whatever the rate': () => sendTolerance(undefined) === 1000,
    'a tolerance given in the settings replaces it': () => sendTolerance(100) === 100,
    'the jitter of the timer is not late (max 20 ms at 50/s over 320 s, measured)': () => isLate(now, now + 20, sendTolerance(undefined)) === false,
    'a backlog of more than a second is late': () => isLate(now, now + 1001, sendTolerance(undefined)) === true,
    'a write sent on time is not late': () => isLate(now, now, 20) === false,
    'a write sent up to the tolerance after its time is not late': () => isLate(now, now + 20, 20) === false,
    'a write sent later than the tolerance is late': () => isLate(now, now + 21, 20) === true,
    'a token valid for longer than the renewal window is used as it is': () => tokenAction(now + 120_000, now) === 'use',
    'a token in the renewal window is used and renewed in the background (no write waits for Keycloak)': () =>
      tokenAction(now + 59_000, now) === 'use_and_renew' && tokenAction(now + 11_000, now) === 'use_and_renew',
    'a token about to expire is renewed before the request': () => tokenAction(now + 10_000, now) === 'wait' && tokenAction(now + 1_000, now) === 'wait',
    'an expired token or none is renewed before the request': () => tokenAction(now - 1, now) === 'wait' && tokenAction(null, now) === 'wait',
  });
}

export default function () {
  prices();
  outcomes();
  states();
  comparison();
  eligibility();
  sampling();
  verdicts();
  timing();
  windows();
}
