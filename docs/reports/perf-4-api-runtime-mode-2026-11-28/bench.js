import http from 'k6/http';
import { check } from 'k6';
const BASE = __ENV.BASE;
const KIND = __ENV.KIND;
const SLUGS = __ENV.SLUGS.split(',');
const VARIANT = __ENV.VARIANT;
const CATEGORY = __ENV.CATEGORY;
const VUS = parseInt(__ENV.VUS);
export const options = {
  scenarios: { s: { executor: 'constant-vus', vus: VUS, duration: __ENV.DUR || '30s' } },
  summaryTrendStats: ['med', 'p(95)', 'p(99)', 'count'],
  setupTimeout: '120s',
};
const J = { headers: { 'Content-Type': 'application/json' } };
export function setup() {
  const carts = [];
  if (KIND.startsWith('cart')) {
    for (let i = 0; i < VUS; i++) {
      const r = http.post(`${BASE}/api/v1/guest-carts`, '{}', J);
      const id = r.json('id');
      http.post(`${BASE}/api/v1/guest-carts/${id}/items`, JSON.stringify({ variantId: VARIANT, quantity: 1 }), J);
      carts.push(id);
    }
  }
  return { carts };
}
let n = 0;
export default function (data) {
  n++;
  let r;
  if (KIND === 'product') r = http.get(`${BASE}/api/v1/products/${SLUGS[n % SLUGS.length]}`);
  else if (KIND === 'listing') r = http.get(`${BASE}/api/v1/products?category=${CATEGORY}&page=${1 + (n % 3)}`);
  else if (KIND === 'cart_read') r = http.get(`${BASE}/api/v1/guest-carts/${data.carts[(__VU - 1) % data.carts.length]}`);
  else if (KIND === 'cart_write') r = http.put(`${BASE}/api/v1/guest-carts/${data.carts[(__VU - 1) % data.carts.length]}/items/${VARIANT}`, JSON.stringify({ quantity: 1 + (n % 2) }), J);
  check(r, { ok: (x) => x.status === 200 });
}
