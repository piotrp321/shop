// The state of the variants of the writes scenario (writes.js), without I/O: the prices a change may set, what a variant may
// show in the index after the writes the scenario sent, the comparison with what the variant query found, the choice of the
// sample and the variants that cannot be in it (docs/features/search/spec.md, "Weryfikacja wydajności (PERF-2 etap B)").

// The window of the results of GET /api/v1/products (index.max_result_window): a product further on cannot be found.
export const RESULT_WINDOW = 10000;
// The highest gross price the panel accepts (ChangePriceRequest).
export const MAX_GROSS = 99999999;

// The prices a variant may have during a run: its price at the start and 1 grosz either side, within the limits of a price.
export function allowedPrices(initial) {
  return [initial - 1, initial, initial + 1].filter((price) => price >= 1 && price <= MAX_GROSS);
}

// The price of the next change: one of the allowed prices other than the current one, chosen by rnd (0 <= rnd < 1).
export function nextPrice(initial, current, rnd) {
  const choices = allowedPrices(initial).filter((price) => price !== current);
  return choices[Math.min(choices.length - 1, Math.floor(rnd * choices.length))];
}

// What a write did: confirmed (2xx), refused (4xx: the API changed nothing) or unanswered (no answer or a 5xx: it may have been
// saved or not).
export function writeOutcome(status) {
  if (status >= 200 && status < 300) {
    return 'confirmed';
  }
  if (status >= 400 && status < 500) {
    return 'refused';
  }
  return 'unanswered';
}

const union = (list, value) => (list.includes(value) ? list : [...list, value]);

// A variant at the start of the run: the prices and the availabilities it may have now (one of each until a write is
// unanswered), and whether a write is unanswered (then the scenario sends it no more writes).
export function startRecord(initialPrice, purchasable) {
  return { initialPrice, prices: [initialPrice], purchasable: [purchasable], uncertain: false };
}

export function afterPriceChange(record, price, outcome) {
  if (outcome === 'confirmed') {
    return { ...record, prices: [price] };
  }
  if (outcome === 'unanswered') {
    return { ...record, prices: union(record.prices, price), uncertain: true };
  }
  return record;
}

// A receipt only adds units, so the variant is purchasable after it (it has a price: only such variants are in the sample).
export function afterReceipt(record, outcome) {
  if (outcome === 'confirmed') {
    return { ...record, purchasable: [true] };
  }
  if (outcome === 'unanswered') {
    return { ...record, purchasable: union(record.purchasable, true), uncertain: true };
  }
  return record;
}

// Every state the index may show for the variant: a price and whether it is purchasable.
export function expectedStates(record) {
  return record.prices.flatMap((price) => record.purchasable.map((purchasable) => ({ price, purchasable })));
}

// Whether the index shows one of the states. foundAt: price -> whether the variant query at that price found the product (a price
// that was not queried is unknown, so no match). The query finds the product if and only if the variant is purchasable at that
// price.
export function matchesIndex(states, foundAt) {
  return states.some((state) => Object.prototype.hasOwnProperty.call(foundAt, state.price) && foundAt[state.price] === state.purchasable);
}

// Why a variant cannot be in the sample, or null. The axes and values of the variant (Catalog) and its offer (product-offers).
export function ineligibility(axes, values, offer) {
  if (offer === undefined || offer === null) {
    return 'no offer';
  }
  if (offer.gross === null || offer.gross === undefined) {
    return 'no price';
  }
  if (offer.promotion || offer.regularGross !== offer.gross) {
    return 'in a promotion';
  }
  if (axes.some((axis) => axis.includes(':'))) {
    return 'an axis with a colon';
  }
  if (axes.length !== values.length) {
    return 'values do not match the axes';
  }
  return null;
}

function shuffled(list, rnd) {
  const rest = [...list];
  const out = [];
  while (rest.length > 0) {
    out.push(rest.splice(Math.floor(rnd() * rest.length), 1)[0]);
  }
  return out;
}

// The sample of a run from the eligible candidates: `size` variants, round(size * unstockedShare) of them without available stock
// at the start, the rest purchasable; `priceProbes` of the purchasable ones measure the freshness of the price, the others (the
// bulk) take the writes of the open model. Not enough candidates of a part is an error, as is an empty sample.
export function chooseSample(candidates, settings, rnd) {
  const { size, unstockedShare, priceProbes } = settings;
  const unstockedWanted = Math.round(size * unstockedShare);
  const stockedWanted = size - unstockedWanted;
  if (size <= 0 || candidates.stocked.length + candidates.unstocked.length === 0) {
    throw new Error('the sample is empty: no variant of a published product with a price fits (load/README.md, "Próba")');
  }
  if (unstockedShare > 0 && candidates.unstocked.length === 0) {
    throw new Error(`the share of variants without stock is ${unstockedShare}, but no variant without stock fits`);
  }
  if (candidates.unstocked.length < unstockedWanted) {
    throw new Error(`${unstockedWanted} variants without stock wanted, ${candidates.unstocked.length} fit; lower the size or the share`);
  }
  if (candidates.stocked.length < stockedWanted) {
    throw new Error(`${stockedWanted} purchasable variants wanted, ${candidates.stocked.length} fit; lower the size`);
  }
  if (priceProbes > stockedWanted) {
    throw new Error(`${priceProbes} price probes need as many purchasable variants, the sample has ${stockedWanted}`);
  }
  const stocked = shuffled(candidates.stocked, rnd).slice(0, stockedWanted);
  return {
    probes: stocked.slice(0, priceProbes),
    bulk: stocked.slice(priceProbes),
    unstocked: shuffled(candidates.unstocked, rnd).slice(0, unstockedWanted),
  };
}

// Whether a run is a measurement at the rate with a consistent index, and why not. A write not sent at the rate or sent late, one without an
// answer or with a 5xx (docs/features/performance-program/plan.md, "Co liczymy") and a change not seen within the freshness timeout
// (it is missing from the percentiles of W16) make the run no measurement, as a refused write, a mismatch, an error of the run and a
// consistency check that did not finish do. The thresholds of writes.js fail the run (exit 99) on the same counts. counts:
// finished, dropped, late, unanswered, freshnessTimeouts, refused, mismatches, runErrors; a missing count fails the run.
export function runVerdict(counts) {
  const rules = [
    ['dropped', (n) => `${n} writes were not sent at the rate (in flight up to the limit or no free variant): no measurement at the rate`],
    ['late', (n) => `${n} writes of the open model sent later than the tolerance after their time (lib/write-state.js, sendTolerance): no measurement at the rate`],
    ['unanswered', (n) => `${n} writes without an answer or with a 5xx: no measurement at the rate`],
    ['freshnessTimeouts', (n) => `${n} changes not seen within the freshness timeout: the percentiles of the freshness leave them out`],
    ['refused', (n) => `${n} writes refused (4xx)`],
    ['mismatches', (n) => `${n} variants mismatch the index`],
    ['runErrors', (n) => `${n} errors of the run`],
  ];
  const reasons = [];
  if (counts.finished !== true) {
    reasons.push('the consistency check did not finish (interrupted or cut off by maxDuration)');
  }
  for (const [name, reason] of rules) {
    const n = counts[name];
    if (typeof n !== 'number') {
      reasons.push(`the count ${name} is missing`);
    } else if (n > 0) {
      reasons.push(reason(n));
    }
  }
  return { passed: reasons.length === 0, reasons };
}

// How late a write of the open model may leave the generator: DEFAULT_SEND_TOLERANCE_MS whatever the rate, unless the settings give
// another. A generator that does not keep the rate falls behind without bound and soon passes it, while the jitter of the event loop
// and the scheduler is orders of magnitude smaller than a second; a delay up to a second changes neither the rate within the windows
// of a measurement nor the freshness (W16 counts from the answer of the write). A later write counts like one not sent at the rate (docs/features/performance-program/plan.md, "Co liczymy").
export const DEFAULT_SEND_TOLERANCE_MS = 1000;

export function sendTolerance(overrideMs) {
  return overrideMs === undefined ? DEFAULT_SEND_TOLERANCE_MS : overrideMs;
}

export function isLate(dueMs, sentMs, toleranceMs) {
  return sentMs - dueMs > toleranceMs;
}

// The access token is renewed in the background this long before it expires, while the writes go on with it.
export const RENEW_BEFORE_MS = 60 * 1000;
// A token valid for less than this is renewed before the request: it might expire on the way.
export const VALID_MARGIN_MS = 10 * 1000;

// What a request does with the access token that expires at expiresAt (ms; null without one): use it, use it and renew it in the
// background, or wait for the renewal.
export function tokenAction(expiresAt, now) {
  if (expiresAt === null || expiresAt - now <= VALID_MARGIN_MS) {
    return 'wait';
  }
  return expiresAt - now < RENEW_BEFORE_MS ? 'use_and_renew' : 'use';
}

export function lagBucket(lagMs) {}

export function rateBand(rate) {}

export function windowStats(counts, rate) {}

export function isCatchUp(dueMs, sentMs, rate) {}

export function catchUpStats(perWindow) {}
