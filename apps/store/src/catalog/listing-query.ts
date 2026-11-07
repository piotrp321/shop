import type { components } from "@/api/schema";
import { categoryHref } from "./catalog-href";

type ProductSort = NonNullable<
  NonNullable<import("@/api/schema").operations["listProducts"]["parameters"]["query"]>["sort"]
>;

export type SearchParams = Record<string, string | string[] | undefined>;

/** `list` is `/` and `/c/<path>` (the category is in the path, a phrase is ignored), `search` is `/szukaj`. */
export type ListingMode = "list" | "search";

/**
 * The parameters of a listing as they stand in the URL, with the URL's own names and values (whole zloty, URL sort
 * names). Nothing is validated here except what the shape needs; the API judges the values.
 */
export type ListingState = {
  /** `q`: trimmed, at most 100 characters. Only on `/szukaj`. */
  q?: string;
  sort?: string;
  /** `cena-od`, whole zloty as typed. */
  priceFrom?: string;
  /** `cena-do`, whole zloty as typed. */
  priceTo?: string;
  /** `dostepne` (`1` when valid). */
  available?: string;
  /** `o`, repeated: `<axis>:<value>`. */
  options: string[];
  /** `kategoria`: the slug path of a category. Only on `/szukaj`. */
  category?: string;
  page?: string;
};

/** The query of `GET /api/v1/products`. Numbers stay strings: the API judges them, the client only forwards them. */
export type ApiListQuery = {
  category?: string;
  q?: string;
  option?: string[];
  priceMin?: string;
  priceMax?: string;
  available?: true;
  sort?: ProductSort;
  page?: string;
};

export type ProductFacets = components["schemas"]["ProductFacets"];

const MAX_PHRASE = 100;
const MIN_PHRASE = 2;

const SORTS: Record<string, ProductSort> = {
  trafnosc: "relevance",
  nazwa: "name",
  "cena-rosnaco": "price_asc",
  "cena-malejaco": "price_desc",
};

// A repeated parameter (?page=1&page=2) counts as its first value; an empty one as absent (a form sends every field).
function first(value: string | string[] | undefined): string | undefined {
  const one = Array.isArray(value) ? value[0] : value;
  return one === undefined || one === "" ? undefined : one;
}

function all(value: string | string[] | undefined): string[] {
  return (Array.isArray(value) ? value : [value]).filter((item): item is string => item !== undefined && item !== "");
}

// The API trims the phrase with PHP `trim`, which also strips NUL; `String.trim` does not, so a phrase the front counts
// as 2 characters would be 1 for the API (400, an invalid filter instead of the message about 2 characters).
function trimPhrase(phrase: string | undefined): string | undefined {
  return phrase?.replace(/^[\s\0]+|[\s\0]+$/g, "");
}

export function readListingState(query: SearchParams, mode: ListingMode): ListingState {
  const state: ListingState = { options: all(query.o) };
  const sort = first(query.sort);
  const priceFrom = first(query["cena-od"]);
  const priceTo = first(query["cena-do"]);
  const available = first(query.dostepne);
  const page = first(query.page);
  if (sort !== undefined) state.sort = sort;
  if (priceFrom !== undefined) state.priceFrom = priceFrom;
  if (priceTo !== undefined) state.priceTo = priceTo;
  if (available !== undefined) state.available = available;
  if (page !== undefined) state.page = page;
  if (mode === "search") {
    const phrase = trimPhrase(first(query.q));
    if (phrase) state.q = [...phrase].slice(0, MAX_PHRASE).join("");
    const category = first(query.kategoria);
    if (category !== undefined) state.category = category;
  }
  return state;
}

/** A phrase of fewer than 2 characters (or none) is not searched; the page says so without asking for products. */
export function isShortPhrase(phrase: string | undefined): boolean {
  return phrase === undefined || [...phrase].length < MIN_PHRASE;
}

/** The only thing the front does with a number: whole zloty to grosze. Anything but digits goes on unchanged. */
function toGrosze(zloty: string | undefined): string | undefined {
  return zloty !== undefined && /^\d+$/.test(zloty) ? `${zloty}00` : zloty;
}

/**
 * The state as the query of the API; `categoryId` is the category the page resolved. `null` means the state holds a
 * value the URL does not allow (an unknown sort, a `dostepne` other than 1): an invalid filter, no call to the API.
 * The keys come in a fixed order, which the per-request cache key relies on.
 */
export function toApiQuery(state: ListingState, categoryId?: string): ApiListQuery | null {
  const sort = state.sort === undefined ? undefined : SORTS[state.sort];
  if (state.sort !== undefined && sort === undefined) return null;
  if (state.available !== undefined && state.available !== "1") return null;
  const query: ApiListQuery = {};
  if (categoryId !== undefined) query.category = categoryId;
  if (state.q !== undefined) query.q = state.q;
  if (state.options.length > 0) query.option = state.options;
  if (state.priceFrom !== undefined) query.priceMin = toGrosze(state.priceFrom);
  if (state.priceTo !== undefined) query.priceMax = toGrosze(state.priceTo);
  if (state.available !== undefined) query.available = true;
  if (sort !== undefined) query.sort = sort;
  if (state.page !== undefined) query.page = state.page;
  return query;
}

/** The address of a listing: `basePath` and the state, changed by `patch`. Every change leaves `page` out. */
export function listingHref(basePath: string, state: ListingState, patch: Partial<ListingState> = {}): string {
  const next = { ...state, page: undefined, ...patch };
  const params = new URLSearchParams();
  if (next.q !== undefined) params.append("q", next.q);
  if (next.sort !== undefined) params.append("sort", next.sort);
  if (next.priceFrom !== undefined) params.append("cena-od", next.priceFrom);
  if (next.priceTo !== undefined) params.append("cena-do", next.priceTo);
  if (next.available !== undefined) params.append("dostepne", next.available);
  for (const option of next.options) params.append("o", option);
  if (next.category !== undefined) params.append("kategoria", next.category);
  if (next.page !== undefined) params.append("page", next.page);
  const search = params.toString();
  return search ? `${basePath}?${search}` : basePath;
}

export function toggleOption(state: ListingState, option: string): ListingState {
  const options = state.options.includes(option) ? state.options.filter((item) => item !== option) : [...state.options, option];
  return { ...state, options, page: undefined };
}

/** No filters, but the phrase and the sort stay: they are not filters. */
export function clearedFilters(state: ListingState): ListingState {
  const cleared: ListingState = { options: [] };
  if (state.q !== undefined) cleared.q = state.q;
  if (state.sort !== undefined) cleared.sort = state.sort;
  return cleared;
}

/** Filters (the ones „Wyczyść filtry” removes): axis values, price, availability, the category of the search. */
export function hasFilters(state: ListingState): boolean {
  return state.options.length > 0 || state.priceFrom !== undefined || state.priceTo !== undefined || state.available !== undefined || state.category !== undefined;
}

/** A sort or a filter other than the category: the page is `noindex` and its canonical address is the plain listing. */
export function isRefined(state: ListingState): boolean {
  return state.sort !== undefined || state.options.length > 0 || state.priceFrom !== undefined || state.priceTo !== undefined || state.available !== undefined;
}

/** The sort the list has without `sort`: by relevance with a phrase, by name otherwise (what the API does too). */
export function defaultSort(state: ListingState): "trafnosc" | "nazwa" {
  return state.q !== undefined ? "trafnosc" : "nazwa";
}

/** The address of a category link of the panel: the category page on a listing, the same search with `kategoria` on `/szukaj`. */
export function categoryFacetHref(mode: ListingMode, path: string, state: ListingState): string {
  return mode === "search" ? listingHref("/szukaj", state, { category: path }) : listingHref(categoryHref(path), state);
}
