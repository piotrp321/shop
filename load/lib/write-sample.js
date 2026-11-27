// The sample of the writes scenario (writes.js, setup): variants with a price in published products, chosen at the start of the
// run. Purchasable ones come from random chunks of the sitemap (one variant per product), the ones without available stock also
// from the stock list of the panel (`level=out_of_stock`). A candidate enters the sample only when its price version is known,
// the index shows it as the offer does (the variant query at its price finds the product if and only if it is purchasable) and
// the query at every price it may get has its total in the window. The reads are bounded by WRITE_DISCOVER_PRODUCTS, not by the
// size of the catalog.
import { getJson } from './admin.js';
import { findSync } from './variant-query.js';
import { RESULT_WINDOW, allowedPrices, chooseSample, ineligibility } from './write-state.js';

const OFFERS_AT_ONCE = 48;
const STOCK_PAGE = 100;
// The reason of a candidate whose index differs from its offer at the start: maybe a mismatch left by a previous run (W13).
export const INDEX_DIFFERS = 'the index differs from the offer at the start';

function shuffled(list) {
  const rest = [...list];
  const out = [];
  while (rest.length > 0) {
    out.push(rest.splice(Math.floor(Math.random() * rest.length), 1)[0]);
  }
  return out;
}

const count = (skipped, reason) => {
  skipped[reason] = (skipped[reason] || 0) + 1;
};

// The offers of the products: productId -> variantId -> offer.
function offersOf(productIds) {
  const offers = {};
  for (let from = 0; from < productIds.length; from += OFFERS_AT_ONCE) {
    const ids = productIds.slice(from, from + OFFERS_AT_ONCE).map((id) => `productId=${id}`).join('&');
    for (const product of getJson(`/api/v1/product-offers?${ids}`).items) {
      offers[product.productId] = Object.fromEntries(product.variants.map((variant) => [variant.variantId, variant]));
    }
  }
  return offers;
}

// The candidates of products {productId, categoryId, axes, variants: [{variantId, values}]}: the eligible variants, purchasable
// ones (one per product) and the ones without available stock (every one).
function candidatesOf(products, skipped) {
  const offers = offersOf(products.map((product) => product.productId));
  const stocked = [];
  const unstocked = [];
  for (const product of products) {
    const eligible = [];
    for (const variant of product.variants) {
      const offer = (offers[product.productId] || {})[variant.variantId];
      const reason = ineligibility(product.axes, variant.values, offer);
      if (reason !== null) {
        count(skipped, reason);
        continue;
      }
      eligible.push({
        variantId: variant.variantId,
        productId: product.productId,
        categoryId: product.categoryId,
        options: product.axes.map((axis, i) => [axis, variant.values[i]]),
        initialPrice: offer.gross,
        purchasable: offer.purchasable,
      });
    }
    const purchasable = eligible.filter((variant) => variant.purchasable);
    if (purchasable.length > 0) {
      stocked.push(purchasable[Math.floor(Math.random() * purchasable.length)]);
    }
    unstocked.push(...eligible.filter((variant) => !variant.purchasable));
  }
  return { stocked, unstocked };
}

// Published products from random chunks of the sitemap, up to `limit`.
function sitemapProducts(limit) {
  const slugs = [];
  for (const chunk of shuffled(getJson('/api/v1/sitemap/product-chunks').chunks)) {
    slugs.push(...shuffled(getJson(`/api/v1/sitemap/products?chunk=${encodeURIComponent(chunk.key)}`).items.map((item) => item.slug)));
    if (slugs.length >= limit) {
      break;
    }
  }
  const products = [];
  for (const slug of slugs.slice(0, limit)) {
    const details = getJson(`/api/v1/products/${encodeURIComponent(slug)}`, null, true);
    if (details !== null && details.breadcrumb.length > 0) {
      products.push({
        productId: details.id,
        categoryId: details.breadcrumb[details.breadcrumb.length - 1].id,
        axes: details.variantAxes,
        variants: details.variants.map((variant) => ({ variantId: variant.variantId, values: variant.values })),
      });
    }
  }
  return products;
}

// Published products with a variant without available stock, from the stock list of the panel, up to `limit`.
function outOfStockProducts(session, limit) {
  const ids = [];
  let after = null;
  do {
    const page = getJson(`/api/v1/admin/stock?level=out_of_stock&perPage=${STOCK_PAGE}${after ? `&after=${encodeURIComponent(after)}` : ''}`, session);
    ids.push(...page.items.map((item) => item.productId));
    after = page.next;
  } while (after && ids.length < limit);
  const products = [];
  for (const id of shuffled(ids).slice(0, limit)) {
    const product = getJson(`/api/v1/admin/products/${id}`, session, true);
    if (product !== null && product.status === 'published') {
      products.push({
        productId: product.id,
        categoryId: product.mainCategoryId,
        axes: product.variantAxes,
        variants: product.variants.map((variant) => ({ variantId: variant.variantId, values: variant.values })),
      });
    }
  }
  return products;
}

// Whether the candidate can be in the sample (its price version, the index at the start, the window at every price it may get);
// the reason when not, a run error (an error of the API or a cache) thrown.
function verify(candidate, session, skipped) {
  const prices = getJson(`/api/v1/admin/prices?productId=${candidate.productId}`, session).items;
  const listed = prices.length > 0 ? prices[0].variants.find((variant) => variant.variantId === candidate.variantId) : undefined;
  if (!listed || !listed.price || listed.price.gross !== candidate.initialPrice || listed.price.vatRateOutdated) {
    count(skipped, 'the price of the panel differs from the offer');
    return null;
  }
  const atStart = findSync(candidate, candidate.initialPrice);
  if (atStart.error) {
    throw new Error(`setup: ${atStart.error}`);
  }
  if (atStart.total >= RESULT_WINDOW || atStart.found !== candidate.purchasable) {
    count(skipped, atStart.total >= RESULT_WINDOW ? 'the query is beyond the window' : INDEX_DIFFERS);
    return null;
  }
  // Only a purchasable variant gets other prices.
  for (const price of candidate.purchasable ? allowedPrices(candidate.initialPrice).filter((p) => p !== candidate.initialPrice) : []) {
    const other = findSync(candidate, price);
    if (other.error) {
      throw new Error(`setup: ${other.error}`);
    }
    if (other.total >= RESULT_WINDOW) {
      count(skipped, 'the query is beyond the window');
      return null;
    }
  }
  return { ...candidate, priceVersion: listed.price.version };
}

function verified(candidates, wanted, session, skipped) {
  const out = [];
  for (const candidate of shuffled(candidates)) {
    if (out.length >= wanted) {
      break;
    }
    const checked = verify(candidate, session, skipped);
    if (checked !== null) {
      out.push(checked);
    }
  }
  return out;
}

// The sample {probes, bulk, unstocked} and the counts of the variants left out by reason. settings: size, unstockedShare,
// priceProbes, discoverProducts.
export function discoverSample(session, settings) {
  const skipped = {};
  const unstockedWanted = Math.round(settings.size * settings.unstockedShare);
  const stockedWanted = settings.size - unstockedWanted;
  const fromSitemap = candidatesOf(sitemapProducts(settings.discoverProducts), skipped);
  let unstocked = fromSitemap.unstocked;
  if (unstockedWanted > 0) {
    const known = new Set(unstocked.map((variant) => variant.variantId));
    const more = candidatesOf(outOfStockProducts(session, settings.discoverProducts), skipped).unstocked.filter((variant) => !known.has(variant.variantId));
    unstocked = [...unstocked, ...more];
  }
  const candidates = {
    stocked: verified(fromSitemap.stocked, stockedWanted, session, skipped),
    unstocked: verified(unstocked, unstockedWanted, session, skipped),
  };
  if (skipped[INDEX_DIFFERS] > 0) {
    console.warn(
      `setup: WARNING ${skipped[INDEX_DIFFERS]} candidates left out because the index differs from the offer at the start: the index may lag or be inconsistent with the database (W13), e.g. after an interrupted run; check the queue and \`failed\` (load/README.md)`,
    );
  }
  return { ...chooseSample(candidates, settings, Math.random), skipped };
}
