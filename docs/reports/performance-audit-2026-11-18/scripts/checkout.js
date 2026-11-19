// Guest checkout straight against the API (no store, no Varnish): a new guest cart, one or more lines, the cart read
// and POST /api/v1/orders. No payment (the simulator runs inside the same API and would measure itself).
//   API           http://api-prod | http://api-prodw
//   VARIANTS_FILE JSON array of purchasable variant ids
//   HOT           when set, every order takes this one variant (contention on one stock row)
//   LINES         lines per order (default 1)
//   CODE          discount code sent with the order (optional)
//   VUS, DURATION, OUT
import http from 'k6/http';
import { check } from 'k6';
import exec from 'k6/execution';
import { Counter, Trend } from 'k6/metrics';

const variants = JSON.parse(open(__ENV.VARIANTS_FILE));
const api = __ENV.API;
const lines = Number(__ENV.LINES || 1);
const hot = __ENV.HOT || '';
const code = __ENV.CODE || '';

const placed = new Counter('orders_placed');
const conflicts = new Counter('orders_409');
const limited = new Counter('orders_429');
const tOrder = new Trend('t_order', true);
const tAdd = new Trend('t_cart_add', true);
const tCart = new Trend('t_cart_read', true);
const tCreate = new Trend('t_cart_create', true);

export const options = {
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
  scenarios: { main: { executor: 'constant-vus', vus: Number(__ENV.VUS || 1), duration: __ENV.DURATION || '20s' } },
};

http.setResponseCallback(http.expectedStatuses({ min: 200, max: 399 }, 409, 429));

export default function () {
  const n = exec.scenario.iterationInTest;
  const ip = `2001:db8:${(0x1900 + ((n >> 16) & 255)).toString(16)}:${(n & 65535).toString(16)}::1`;
  const headers = { 'Content-Type': 'application/json', 'CF-Connecting-IP': ip };

  let res = http.post(`${api}/api/v1/guest-carts`, null, { headers });
  tCreate.add(res.timings.duration);
  if (!check(res, { 'cart created': (r) => r.status === 201 })) return;
  const cartId = res.json('id');

  for (let i = 0; i < lines; i++) {
    const variantId = hot !== '' && i === 0 ? hot : variants[Math.floor(Math.random() * variants.length)];
    res = http.post(`${api}/api/v1/guest-carts/${cartId}/items`, JSON.stringify({ variantId, quantity: 1 }), { headers });
    tAdd.add(res.timings.duration);
    check(res, { 'line added': (r) => r.status === 200 || r.status === 201 });
  }

  res = http.get(`${api}/api/v1/guest-carts/${cartId}${code ? `?discountCode=${code}` : ''}`, { headers });
  tCart.add(res.timings.duration);
  if (!check(res, { 'cart read': (r) => r.status === 200 })) return;
  const gross = res.json('totals.gross');

  const body = {
    cartId,
    email: `perf-${exec.vu.idInTest}@example.com`,
    fullName: 'Jan Kowalski',
    phone: '600100200',
    street: 'Długa 5/3',
    postalCode: '00-950',
    city: 'Warszawa',
    expectedTotalGross: gross,
  };
  if (code) body.discountCode = code;
  res = http.post(`${api}/api/v1/orders`, JSON.stringify(body), {
    headers: { ...headers, 'Idempotency-Key': `perf-${exec.vu.idInTest}-${exec.vu.iterationInInstance}-${Date.now()}` },
  });
  tOrder.add(res.timings.duration);
  if (res.status === 201 || res.status === 200) placed.add(1);
  else if (res.status === 409) conflicts.add(1);
  else if (res.status === 429) limited.add(1);
  check(res, { 'order placed': (r) => r.status === 201 || r.status === 200 });
}

export function handleSummary(data) {
  return { [__ENV.OUT]: JSON.stringify(data) };
}
