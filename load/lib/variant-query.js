// The variant query of the writes scenario (docs/features/search/spec.md, "Weryfikacja wydajności (PERF-2 etap B)"): GET
// /api/v1/products of the API itself (not Varnish) with the main category of the product, the value of every axis of the variant
// (`option=<axis>:<value>`) and priceMin = priceMax = the price, read page by page to the last one. The product is in the results if
// and only if this variant is purchasable at that price in the index: the conditions hold for one variant, the price range
// counts only purchasable variants and the values of the axes are unique in a product.
import http from 'k6/http';
import { apiParams, apiUrl } from './admin.js';
import { RESULT_WINDOW } from './write-state.js';

const PER_PAGE = 48;

function path(variant, price, page) {
  const options = variant.options.map(([axis, value]) => `&option=${encodeURIComponent(`${axis}:${value}`)}`).join('');
  return `/api/v1/products?category=${variant.categoryId}${options}&priceMin=${price}&priceMax=${price}&perPage=${PER_PAGE}&page=${page}`;
}

// A cache between k6 and the API would answer an old state: Varnish sets X-Cache and Age, Cloudflare Cf-Cache-Status.
function cachedBy(res) {
  const status = res.headers['Cf-Cache-Status'];
  if (res.headers['X-Cache'] !== undefined) {
    return `X-Cache ${res.headers['X-Cache']}`;
  }
  if (Number(res.headers.Age || 0) > 0) {
    return `Age ${res.headers.Age}`;
  }
  if (status !== undefined && ['HIT', 'STALE', 'UPDATING', 'REVALIDATED'].includes(status)) {
    return `Cf-Cache-Status ${status}`;
  }
  return null;
}

// The result of one page: an error (status, cache) or the page.
function readPage(res) {
  if (res.status !== 200) {
    return { error: `GET /api/v1/products answered ${res.status}${res.status === 0 ? ` (${res.error})` : ''}` };
  }
  const cache = cachedBy(res);
  if (cache !== null) {
    return { error: `the variant query went through a cache (${cache}): API_URL must reach the API itself, not Varnish or a CDN`, cached: true };
  }
  const body = res.json();
  return { total: body.total, pageCount: body.pageCount, ids: body.items.map((item) => item.id) };
}

// {found, total, beyondWindow} or {error}: whether the product of the variant is in the results at that price. Not found while
// the total is above the window is beyondWindow: the product may be past the pages that can be read.
function result(found, total) {
  return { found, total, beyondWindow: !found && total > RESULT_WINDOW };
}

export function findSync(variant, price) {
  for (let page = 1; ; page += 1) {
    const read = readPage(http.get(`${apiUrl}${path(variant, price, page)}`, apiParams('variant_query')));
    if (read.error) {
      return read;
    }
    if (read.ids.includes(variant.productId)) {
      return result(true, read.total);
    }
    if (page >= read.pageCount) {
      return result(false, read.total);
    }
  }
}

export async function find(variant, price) {
  for (let page = 1; ; page += 1) {
    const read = readPage(await http.asyncRequest('GET', `${apiUrl}${path(variant, price, page)}`, null, apiParams('variant_query')));
    if (read.error) {
      return read;
    }
    if (read.ids.includes(variant.productId)) {
      return result(true, read.total);
    }
    if (page >= read.pageCount) {
      return result(false, read.total);
    }
  }
}
