import { describe, expect, it } from "vitest";
import {
  clearedFilters,
  defaultSort,
  hasFilters,
  isRefined,
  isShortPhrase,
  listingHref,
  readListingState,
  toApiQuery,
  toggleOption,
} from "./listing-query";

describe("readListingState", () => {
  it("reads every parameter of the search page, the first value of a repeated one and all values of `o`", () => {
    expect(
      readListingState(
        { q: ["kurtki", "x"], sort: "cena-rosnaco", "cena-od": "100", "cena-do": "200", dostepne: "1", o: ["Kolor:czarne", "Rozmiar:42"], kategoria: "turystyka/biwak", page: ["2", "3"] },
        "search",
      ),
    ).toEqual({
      q: "kurtki",
      sort: "cena-rosnaco",
      priceFrom: "100",
      priceTo: "200",
      available: "1",
      options: ["Kolor:czarne", "Rozmiar:42"],
      category: "turystyka/biwak",
      page: "2",
    });
  });

  it("leaves out empty values (a form without JavaScript sends every field) and unknown parameters", () => {
    expect(readListingState({ q: "", sort: "", "cena-od": "", "cena-do": "", o: ["", "Kolor:czarne"], page: "", utm_source: "x" }, "search")).toEqual({
      options: ["Kolor:czarne"],
    });
  });

  it("trims the phrase and cuts it to 100 characters", () => {
    expect(readListingState({ q: "  kurtka  " }, "search").q).toBe("kurtka");
    expect(readListingState({ q: "   " }, "search").q).toBeUndefined();
    expect(readListingState({ q: "a".repeat(150) }, "search").q).toBe("a".repeat(100));
    expect(readListingState({ q: "ą".repeat(150) }, "search").q).toBe("ą".repeat(100));
  });

  it("trims NUL like the API does (PHP trim), so a phrase of one letter and NUL is short, not an invalid filter", () => {
    expect(readListingState({ q: "\0kurtka\0 " }, "search").q).toBe("kurtka");
    expect(readListingState({ q: "\0" }, "search").q).toBeUndefined();
    const phrase = readListingState({ q: "a\0" }, "search").q;
    expect(phrase).toBe(readListingState({ q: "a " }, "search").q);
    expect(isShortPhrase(phrase)).toBe(true);
  });

  it("ignores the phrase and the category on a listing page, so they reach neither the API nor the addresses", () => {
    const state = readListingState({ q: "kurtka", kategoria: "bieganie", sort: "nazwa" }, "list");
    expect(state).toEqual({ sort: "nazwa", options: [] });
    expect(listingHref("/c/bieganie", state)).toBe("/c/bieganie?sort=nazwa");
  });
});

describe("isShortPhrase", () => {
  it("is true below 2 characters, counted as characters, not bytes", () => {
    expect(isShortPhrase(undefined)).toBe(true);
    expect(isShortPhrase("a")).toBe(true);
    expect(isShortPhrase("ą")).toBe(true);
    expect(isShortPhrase("ab")).toBe(false);
    expect(isShortPhrase("ąć")).toBe(false);
  });
});

describe("toApiQuery", () => {
  const state = (over: Partial<Parameters<typeof toApiQuery>[0]> = {}) => ({ options: [], ...over });

  it("is empty for a state without parameters", () => {
    expect(toApiQuery(state())).toEqual({});
  });

  it("maps every parameter, whole zloty to grosze and the sort to the API name", () => {
    expect(
      toApiQuery(
        state({ q: "kurtka", sort: "cena-malejaco", priceFrom: "100", priceTo: "250", available: "1", options: ["Kolor:czarne", "Kolor:biały"], page: "3" }),
        "cat-id",
      ),
    ).toEqual({
      category: "cat-id",
      q: "kurtka",
      option: ["Kolor:czarne", "Kolor:biały"],
      priceMin: "10000",
      priceMax: "25000",
      available: true,
      sort: "price_desc",
      page: "3",
    });
  });

  it("maps all four sorts", () => {
    expect(toApiQuery(state({ sort: "trafnosc" }))?.sort).toBe("relevance");
    expect(toApiQuery(state({ sort: "nazwa" }))?.sort).toBe("name");
    expect(toApiQuery(state({ sort: "cena-rosnaco" }))?.sort).toBe("price_asc");
    expect(toApiQuery(state({ sort: "cena-malejaco" }))?.sort).toBe("price_desc");
  });

  it("adds 00 only to a value of digits; anything else goes unchanged, so the API answers 400", () => {
    expect(toApiQuery(state({ priceFrom: "0" }))?.priceMin).toBe("000");
    expect(toApiQuery(state({ priceFrom: "007" }))?.priceMin).toBe("00700");
    for (const value of ["12.5", "12,5", "-3", "abc", " 10", "1e3", "１２", "10 zł"]) {
      expect(toApiQuery(state({ priceFrom: value, priceTo: value }))).toMatchObject({ priceMin: value, priceMax: value });
    }
  });

  it("is null (an invalid filter, no call to the API) for an unknown sort or a dostepne other than 1", () => {
    expect(toApiQuery(state({ sort: "popularne" }))).toBeNull();
    expect(toApiQuery(state({ sort: "price_asc" }))).toBeNull();
    expect(toApiQuery(state({ available: "0" }))).toBeNull();
    expect(toApiQuery(state({ available: "true" }))).toBeNull();
  });

  it("sends an option without a colon as it is", () => {
    expect(toApiQuery(state({ options: ["Kolor"] }))?.option).toEqual(["Kolor"]);
  });
});

describe("listingHref", () => {
  it("writes the parameters in a fixed order with the URL names", () => {
    expect(
      listingHref("/szukaj", {
        q: "kurtka zimowa",
        sort: "cena-rosnaco",
        priceFrom: "100",
        priceTo: "200",
        available: "1",
        options: ["Kolor:czarne", "Rozmiar:42"],
        category: "turystyka/biwak",
      }, { page: "2" }),
    ).toBe("/szukaj?q=kurtka+zimowa&sort=cena-rosnaco&cena-od=100&cena-do=200&dostepne=1&o=Kolor%3Aczarne&o=Rozmiar%3A42&kategoria=turystyka%2Fbiwak&page=2");
  });

  it("is the bare path without parameters", () => {
    expect(listingHref("/", { options: [] })).toBe("/");
    expect(listingHref("/c/bieganie/buty", { options: [] })).toBe("/c/bieganie/buty");
  });

  it("removes page on every change, unless the change sets it", () => {
    const state = { options: ["Kolor:czarne"], page: "4" };
    expect(listingHref("/", state, { sort: "nazwa" })).toBe("/?sort=nazwa&o=Kolor%3Aczarne");
    expect(listingHref("/", state, { options: [] })).toBe("/");
    expect(listingHref("/", state, { page: "5" })).toBe("/?o=Kolor%3Aczarne&page=5");
  });

  it("encodes values that could break the address", () => {
    expect(listingHref("/szukaj", { q: "a&b=c#d", options: ["Kolor:x&y"] })).toBe("/szukaj?q=a%26b%3Dc%23d&o=Kolor%3Ax%26y");
  });
});

describe("toggleOption, clearedFilters and the filter predicates", () => {
  it("adds a missing option and removes a present one, keeping the order of the others", () => {
    const state = { options: ["Kolor:czarne", "Rozmiar:42"], page: "2" };
    expect(toggleOption(state, "Kolor:biały").options).toEqual(["Kolor:czarne", "Rozmiar:42", "Kolor:biały"]);
    expect(toggleOption(state, "Kolor:czarne").options).toEqual(["Rozmiar:42"]);
    expect(toggleOption(state, "Kolor:czarne").page).toBeUndefined();
  });

  it("clears the filters but keeps the phrase and the sort", () => {
    expect(
      clearedFilters({ q: "kurtka", sort: "cena-rosnaco", priceFrom: "1", priceTo: "2", available: "1", options: ["a:b"], category: "x", page: "3" }),
    ).toEqual({ q: "kurtka", sort: "cena-rosnaco", options: [] });
  });

  it("knows when filters are active (the sort and the phrase are not filters) and when the listing is refined (the sort is)", () => {
    expect(hasFilters({ options: [] })).toBe(false);
    expect(hasFilters({ q: "x", sort: "nazwa", options: [], page: "2" })).toBe(false);
    expect(hasFilters({ options: ["a:b"] })).toBe(true);
    expect(hasFilters({ priceFrom: "1", options: [] })).toBe(true);
    expect(hasFilters({ priceTo: "1", options: [] })).toBe(true);
    expect(hasFilters({ available: "1", options: [] })).toBe(true);
    expect(hasFilters({ category: "x", options: [] })).toBe(true);
    expect(isRefined({ options: [], page: "2" })).toBe(false);
    expect(isRefined({ q: "x", options: [], category: "x" })).toBe(false);
    expect(isRefined({ sort: "nazwa", options: [] })).toBe(true);
    expect(isRefined({ options: ["a:b"] })).toBe(true);
    expect(isRefined({ available: "1", options: [] })).toBe(true);
    expect(isRefined({ priceFrom: "1", options: [] })).toBe(true);
    expect(isRefined({ priceTo: "1", options: [] })).toBe(true);
  });

  it("sorts by relevance by default with a phrase and by name otherwise", () => {
    expect(defaultSort({ q: "kurtka", options: [] })).toBe("trafnosc");
    expect(defaultSort({ options: [] })).toBe("nazwa");
  });
});
