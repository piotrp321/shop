// The choice of the variants for the step of the cart scenario of load/panel.js that raises the quantity from 1 to 2 (make load-panel-check,
// load/test/panel.sh): the cart answers 409 to a raise above the available units, which the public API never tells, so the scenario takes only
// the variants with at least 2 units of the admin stock list. A variant that the stock list does not know, or that has 1 unit, is left out.
import { check } from 'k6';
import { availableOf, variantsWithUnits } from '../lib/stock.js';

export const options = {
  scenarios: { cart_variants: { executor: 'per-vu-iterations', vus: 1, iterations: 1, maxDuration: '10s' } },
  thresholds: { checks: ['rate==1'] },
};

const stockPage = {
  items: [
    { productId: 'p1', variants: [{ variantId: 'v-one', stock: { available: 1 } }, { variantId: 'v-two', stock: { available: 2 } }] },
    { productId: 'p2', variants: [{ variantId: 'v-many', stock: { available: 50 } }, { variantId: 'v-none', stock: { available: 0 } }] },
  ],
};

export default function () {
  const available = availableOf(stockPage);
  check(available, {
    'the available units of every variant of the page are read': (a) => a['v-one'] === 1 && a['v-two'] === 2 && a['v-many'] === 50 && a['v-none'] === 0,
    'an empty page has no variants': () => Object.keys(availableOf({ items: [] })).length === 0 && Object.keys(availableOf({})).length === 0,
  });
  const variants = [{ variantId: 'v-one' }, { variantId: 'v-two' }, { variantId: 'v-many' }, { variantId: 'v-none' }, { variantId: 'v-unknown' }];
  const chosen = variantsWithUnits(variants, available, 2).map((v) => v.variantId);
  check(chosen, {
    'only the variants with at least 2 units are chosen': (c) => c.length === 2 && c.includes('v-two') && c.includes('v-many'),
    'a variant with 1 unit, with none or unknown to the stock list is left out': (c) => !c.includes('v-one') && !c.includes('v-none') && !c.includes('v-unknown'),
  });
  console.log('cart variants: checked');
}
