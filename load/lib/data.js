import { number, text } from './config.js';
import { apiGet, apiGetOrNull } from './http.js';

const OFFERS_AT_ONCE = 48; // the most product ids of one product-offers call

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

// The facets are read from the listing of this many categories (the ones that have products), not from the whole catalog.
const FACET_CATEGORIES = 3;
const FACET_ATTEMPTS = 20;

// Up to `count` items of the list, chosen at random, in random order.
function sample(list, count) {
  const rest = [...list];
  const chosen = [];
  while (chosen.length < count && rest.length > 0) {
    chosen.push(rest.splice(Math.floor(Math.random() * rest.length), 1)[0]);
  }
  return chosen;
}

function categoryNodes(nodes) {
  return nodes.flatMap((node) => [{ id: node.id, path: node.path }, ...categoryNodes(node.children || [])]);
}

// The keys of the chunks of the sitemap to read the products from: the ones given in DISCOVER_CHUNKS (separated with commas;
// the keys are base64url text), else DISCOVER_CHUNK_COUNT of them chosen at random from the list of the API.
function chunkKeys() {
  const given = text('DISCOVER_CHUNKS', '').split(',').map((key) => key.trim()).filter((key) => key !== '');
  if (given.length > 0) {
    return given;
  }
  const keys = apiGet('/api/v1/sitemap/product-chunks').chunks.map((chunk) => chunk.key);
  return sample(keys, Math.max(1, number('DISCOVER_CHUNK_COUNT', 3)));
}

// The products of the chunks: DISCOVER_PRODUCTS of them in all (the share of every chunk is equal), chosen at random from
// the slugs of the chunks, and for each its id, name and variants from the API (the sitemap gives only the slug).
function readProducts(keys) {
  const wanted = Math.max(1, number('DISCOVER_PRODUCTS', 60));
  const perChunk = Math.ceil(wanted / keys.length);
  const slugs = [];
  for (const key of keys) {
    let chunk;
    try {
      chunk = apiGet(`/api/v1/sitemap/products?chunk=${encodeURIComponent(key)}`);
    } catch (error) {
      throw new Error(`setup: the chunk '${key}' cannot be read (${error.message}); is it a key of GET /api/v1/sitemap/product-chunks?`);
    }
    slugs.push(...sample(chunk.items.map((item) => item.slug), perChunk));
  }
  const products = [];
  for (const slug of slugs.slice(0, wanted)) {
    // A product that vanished since the sitemap was read is skipped.
    const details = apiGetOrNull(`/api/v1/products/${encodeURIComponent(slug)}`);
    if (details !== null) {
      products.push({ id: details.id, slug: details.slug, name: details.name });
    }
  }
  return products;
}

// The options and the price range of the listings of a few categories that have products: the facets of the filters of
// the search, without reading the listing of the whole catalog.
function readFacets(categories) {
  const options = new Set();
  let price = null;
  let found = 0;
  for (const category of sample(categories, FACET_ATTEMPTS)) {
    const listing = apiGet(`/api/v1/products?category=${category.id}&perPage=1`);
    if (listing.total === 0) {
      continue;
    }
    for (const axis of listing.facets.options || []) {
      for (const value of axis.values) {
        options.add(`${axis.axis}:${value.value}`);
      }
    }
    if (listing.facets.price) {
      price = price === null ? { ...listing.facets.price } : { min: Math.min(price.min, listing.facets.price.min), max: Math.max(price.max, listing.facets.price.max) };
    }
    found += 1;
    if (found >= FACET_CATEGORIES) {
      break;
    }
  }
  return { options: [...options], price };
}

// The input of a run, read from the public API: the paths of the categories, the slugs of the products, the phrases and
// the filters of the search and the variants that can be bought now. The products come from a few random chunks of the
// sitemap (the whole catalog is never listed), the phrases from their names, the filters from the facets of a few
// categories. The options of the run (the environment of k6): DISCOVER_CHUNKS (the keys of the chunks, separated with
// commas, instead of the random ones), DISCOVER_CHUNK_COUNT (3), DISCOVER_PRODUCTS (60). Nothing is listed here by hand;
// an empty catalog stops the run.
export function discover() {
  const nodes = categoryNodes(apiGet('/api/v1/categories'));
  const categories = nodes.map((node) => node.path);
  const providers = apiGet('/api/v1/payment-providers').map((provider) => provider.code);

  const keys = chunkKeys();
  const products = readProducts(keys);

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
          variants.push({ variantId: variant.variantId, productId: offer.productId, slug: slugOf[offer.productId] });
        }
      }
    }
  }

  const facets = readFacets(nodes);

  const data = {
    categories,
    slugs: products.map((product) => product.slug),
    phrases: phrasesOf(products.map((product) => product.name)),
    options: facets.options,
    prices: priceWindows(facets.price),
    variants,
    providers,
    counts: { categories: categories.length, chunks: keys.length, products: products.length, variants: variantCount, purchasable: variants.length },
  };
  console.log(`setup: ${data.counts.products} products from ${data.counts.chunks} chunks of the sitemap, ${data.counts.purchasable} purchasable variants, ${data.options.length} options, ${data.prices.length} price windows`);
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
