import { number } from './config.js';
import { apiGet } from './http.js';

const PER_PAGE = 48; // the largest page of the API
const OFFERS_AT_ONCE = 48; // the most product ids of one product-offers call

function categoryPaths(nodes) {
  return nodes.flatMap((node) => [node.path, ...categoryPaths(node.children || [])]);
}

// Words of the product names (4 letters or more) and the first two words of a name: the phrases of the search.
function phrasesOf(names) {
  const phrases = new Set();
  for (const name of names) {
    const words = name.toLowerCase().split(/[\s,.\-()/]+/).filter((word) => word.length >= 4);
    for (const word of words) {
      phrases.add(word);
    }
    if (words.length >= 2) {
      phrases.add(`${words[0]} ${words[1]}`);
    }
  }
  return [...phrases];
}

// Windows of the price filter (whole zloty), inside the range the API reports for the catalog.
function priceWindows(range) {
  if (!range || range.max <= range.min) {
    return [];
  }
  const zloty = (grosze) => Math.max(0, Math.round(grosze / 100));
  const span = range.max - range.min;
  return [
    [zloty(range.min), zloty(range.min + span * 0.1)],
    [zloty(range.min), zloty(range.min + span * 0.4)],
    [zloty(range.min + span * 0.05), zloty(range.min + span * 0.25)],
  ];
}

// The input of a run, read from the public API: the paths of the categories, the slugs of the products, the phrases and
// the filters of the search (from the names and the facets of the catalog) and the variants that can be bought now.
// Nothing is listed here by hand; an empty catalog stops the run.
export function discover() {
  const categories = categoryPaths(apiGet('/api/v1/categories'));
  const providers = apiGet('/api/v1/payment-providers').map((provider) => provider.code);

  const maxPages = number('DISCOVER_MAX_PAGES', 100);
  const products = [];
  let facets = null;
  let total = 0;
  for (let page = 1; page <= maxPages; page++) {
    const listing = apiGet(`/api/v1/products?perPage=${PER_PAGE}&page=${page}`);
    if (page === 1) {
      facets = listing.facets;
    }
    total = listing.total;
    products.push(...listing.items.map((item) => ({ id: item.id, slug: item.slug, name: item.name })));
    if (page * PER_PAGE >= total || listing.items.length === 0) {
      break;
    }
  }

  const slugOf = {};
  for (const product of products) {
    slugOf[product.id] = product.slug;
  }
  const variants = [];
  let variantCount = 0;
  for (let from = 0; from < products.length; from += OFFERS_AT_ONCE) {
    const ids = products.slice(from, from + OFFERS_AT_ONCE).map((product) => `productId=${product.id}`).join('&');
    for (const offer of apiGet(`/api/v1/product-offers?${ids}`).items) {
      variantCount += offer.variants.length;
      for (const variant of offer.variants) {
        if (variant.purchasable) {
          variants.push({ variantId: variant.variantId, slug: slugOf[offer.productId] });
        }
      }
    }
  }

  const options = [];
  for (const axis of (facets && facets.options) || []) {
    for (const value of axis.values) {
      options.push(`${axis.axis}:${value.value}`);
    }
  }

  const data = {
    categories,
    slugs: products.map((product) => product.slug),
    phrases: phrasesOf(products.map((product) => product.name)),
    options,
    prices: priceWindows(facets && facets.price),
    variants,
    providers,
    counts: { categories: categories.length, products: products.length, catalogTotal: total, variants: variantCount, purchasable: variants.length },
  };
  for (const [what, list] of [['categories', data.categories], ['products', data.slugs], ['search phrases', data.phrases], ['purchasable variants', data.variants], ['payment providers', data.providers]]) {
    if (list.length === 0) {
      throw new Error(`setup: the API returned no ${what}; is the demo data loaded?`);
    }
  }
  return data;
}

export function pick(list) {
  return list[Math.floor(Math.random() * list.length)];
}

// One of the [name, weight] pairs, by weight.
export function pickWeighted(pairs) {
  const sum = pairs.reduce((total, pair) => total + pair[1], 0);
  let rest = Math.random() * sum;
  for (const [name, weight] of pairs) {
    rest -= weight;
    if (rest < 0) {
      return name;
    }
  }
  return pairs[pairs.length - 1][0];
}
