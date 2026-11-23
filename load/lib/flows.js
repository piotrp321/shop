import { check } from 'k6';
import exec from 'k6/execution';
import { MIX } from './config.js';
import { pick, pickWeighted } from './data.js';
import { storeGet, storePost } from './http.js';
import { iterationsDone, ordersPlaced, paymentsStarted } from './metrics.js';

// A flow is what one visitor does: one page, or the steps of a cart. Every request carries the tags flow (what it is)
// and kind (read: the pages of the catalog, cart, checkout), which the summaries group by.

function ok(res, flow, kind, expected) {
  const statuses = expected || [200];
  return check(res, { [`${flow} answers ${statuses.join(' or ')}`]: (r) => statuses.includes(r.status) }, { flow, kind });
}

export function home(ctx) {
  ok(storeGet(ctx, '/', 'home', 'read'), 'home', 'read');
}

export function category(ctx, data) {
  ok(storeGet(ctx, `/c/${pick(data.categories)}`, 'category', 'read'), 'category', 'read');
}

export function product(ctx, data) {
  ok(storeGet(ctx, `/p/${pick(data.slugs)}`, 'product', 'read'), 'product', 'read');
}

const SORTS = ['trafnosc', 'nazwa', 'cena-rosnaco', 'cena-malejaco'];

// The search with a phrase from the catalog and, when filtered, one of: an option from the facets, a price window, only
// the available ones, a sort or the second page.
export function search(ctx, data, filtered) {
  let query = `q=${encodeURIComponent(pick(data.phrases))}`;
  if (filtered) {
    const filters = ['available', 'sort', 'page'];
    if (data.options.length > 0) {
      filters.push('option');
    }
    if (data.prices.length > 0) {
      filters.push('price');
    }
    switch (pick(filters)) {
      case 'option':
        query += `&o=${encodeURIComponent(pick(data.options))}`;
        break;
      case 'price': {
        const [from, to] = pick(data.prices);
        query += `&cena-od=${from}&cena-do=${to}`;
        break;
      }
      case 'available':
        query += '&dostepne=1';
        break;
      case 'sort':
        query += `&sort=${pick(SORTS)}`;
        break;
      default:
        query += '&page=2';
    }
  }
  ok(storeGet(ctx, `/szukaj?${query}`, filtered ? 'search_filtered' : 'search', 'read'), filtered ? 'search_filtered' : 'search', 'read');
}

function addToCart(ctx, data, flow, kind) {
  const res = storePost(ctx, '/api/cart/items', flow, kind, { variantId: pick(data.variants).variantId, quantity: 1 });
  return ok(res, flow, kind, [200, 201]);
}

// The cart: an addition through the BFF (the first one makes the guest cart), the cart as the page asks for it, and the page.
export function cart(ctx, data) {
  addToCart(ctx, data, 'cart_add', 'cart');
  ok(storeGet(ctx, '/api/cart', 'cart_read', 'cart'), 'cart_read', 'cart');
  ok(storeGet(ctx, '/cart', 'cart_page', 'cart'), 'cart_page', 'cart');
}

let counter = 0;

const CART_CHANGED = 'problems/cart-changed';

// A refusal that no load changes: the API took the list of the order for invalid (422) or for another cart than the one
// read just before (409 cart-changed). Either is a bug of this script, so it ends the run and says what the shop answered
// (status, type, detail and the fields of the violations) instead of hiding in a counter of 4xx or 409.
function abortOnRefusedOrder(res) {
  let body = {};
  try {
    body = res.json() || {};
  } catch (e) {
    body = {};
  }
  const type = typeof body.type === 'string' ? body.type : '';
  if (res.status !== 422 && !(res.status === 409 && type.includes(CART_CHANGED))) {
    return;
  }
  const fields = (Array.isArray(body.violations) ? body.violations : []).map((violation) => violation.propertyPath).join(', ');
  exec.test.abort(
    `the checkout order was refused: status ${res.status}, type ${type || '(none)'}, detail ${body.detail || '(none)'}, violations ${fields || '(none)'}`,
  );
}

// The guest checkout through POST /api/checkout of the store: one unit of a purchasable variant, then the order with the
// first delivery method of the cart and the start of its payment at a provider. The cart steps carry the kind checkout too, so the checkout scenario is one set of
// numbers. Returns true when the order was placed.
export function checkout(ctx, data) {
  const added = addToCart(ctx, data, 'checkout_cart', 'checkout');
  const cart = storeGet(ctx, '/api/cart', 'checkout_cart', 'checkout');
  ok(cart, 'checkout_cart', 'checkout');
  if (!added || cart.status !== 200) {
    return false;
  }
  // The lines the order must be placed for: the ones of the cart just read (variant and quantity), as the checkout of the
  // store sends the lines it shows.
  const lines = (cart.json('lines') || []).map((line) => ({ variantId: line.variantId, quantity: line.quantity }));
  const read = check(lines, { 'checkout_cart has lines': (l) => l.length > 0 }, { flow: 'checkout_cart', kind: 'checkout' });
  if (!read) {
    return false;
  }
  // The first delivery method the cart offers (the seed has two); its total with the delivery is the expected total.
  const option = (cart.json('delivery.options') || [])[0];
  const offered = check(option, { 'checkout_cart offers a delivery method': (o) => o !== undefined }, { flow: 'checkout_cart', kind: 'checkout' });
  if (!offered) {
    return false;
  }
  counter += 1;
  const key = `load-${exec.vu.idInTest}-${exec.vu.iterationInInstance}-${counter}-${Date.now()}`;
  const res = storePost(
    ctx,
    '/api/checkout',
    'checkout',
    'checkout',
    {
      // Unique in every checkout: the API allows 3 unpaid orders per email (ABUSE-LIMITS, L6).
      email: `${key}@example.com`,
      fullName: 'Jan Kowalski',
      phone: '600100200',
      street: 'Długa 5/3',
      postalCode: '00-950',
      city: 'Warszawa',
      deliveryMethodId: option.methodId,
      expectedLines: lines,
      expectedTotalGross: option.totalGross,
      provider: pick(data.providers),
    },
    { 'Idempotency-Key': key },
  );
  abortOnRefusedOrder(res);
  const placed = ok(res, 'checkout', 'checkout', [200, 201]);
  if (placed) {
    const body = res.json();
    ordersPlaced.add(1, { flow: 'checkout', kind: 'checkout' });
    if (typeof body.redirectUrl === 'string') {
      paymentsStarted.add(1, { flow: 'checkout', kind: 'checkout' });
    }
  }
  return placed;
}

// One iteration of the read and cart scenarios: one kind of the MIX by its weight.
export function browse(ctx, data) {
  switch (pickWeighted(MIX)) {
    case 'home':
      home(ctx);
      break;
    case 'category':
      category(ctx, data);
      break;
    case 'product':
      product(ctx, data);
      break;
    case 'search':
      search(ctx, data, Math.random() < 0.5);
      break;
    default:
      cart(ctx, data);
  }
  iterationsDone.add(1, ctx.step === undefined ? {} : { step: String(ctx.step) });
}
