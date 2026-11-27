// The state of the variants of the writes scenario (writes.js), without I/O.

export const RESULT_WINDOW = 10000;
export const MAX_GROSS = 99999999;

export function allowedPrices(initial) {}

export function nextPrice(initial, current, rnd) {}

export function writeOutcome(status) {}

export function startRecord(initialPrice, purchasable) {}

export function afterPriceChange(record, price, outcome) {}

export function afterReceipt(record, outcome) {}

export function expectedStates(record) {}

export function matchesIndex(states, foundAt) {}

export function ineligibility(axes, values, offer) {}

export function chooseSample(candidates, settings, rnd) {}
