// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { renderToStaticMarkup } from "react-dom/server";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const push = vi.hoisted(() => vi.fn());
vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));

import { EMPTY_FACETS } from "./fixtures";
import { ListingFilters } from "./ListingFilters";
import { ListingProvider } from "./listing-context";
import type { ListingState, ProductFacets } from "./listing-query";

beforeEach(() => push.mockReset());
afterEach(cleanup);

const FACETS: ProductFacets = {
  categories: [
    { id: "00000000-0000-4000-8000-000000000001", name: "Buty", slug: "buty", path: "bieganie/buty", count: 7 },
    { id: "00000000-0000-4000-8000-000000000002", name: "Odzież", slug: "odziez", path: "bieganie/odziez", count: 3 },
  ],
  options: [
    { axis: "Kolor", values: [{ value: "czarne", count: 4 }, { value: "białe", count: 0 }] },
    { axis: "Rozmiar", values: [{ value: "42", count: 5 }] },
  ],
  price: { min: 24900, max: 49900 },
  availableCount: 6,
};

function setup(state: Partial<ListingState> = {}, facets: ProductFacets = FACETS, over: { basePath?: string; mode?: "list" | "search" } = {}) {
  const full: ListingState = { options: [], ...state };
  render(
    <ListingProvider basePath={over.basePath ?? "/c/bieganie"} state={full}>
      <ListingFilters mode={over.mode ?? "list"} facets={facets} />
    </ListingProvider>,
  );
}

const box = (name: string) => screen.getByRole("checkbox", { name }) as HTMLInputElement;
const priceFrom = () => screen.getByLabelText("Cena od") as HTMLInputElement;
const priceTo = () => screen.getByLabelText("Cena do") as HTMLInputElement;

describe("ListingFilters: the panel", () => {
  it("lists the subcategories with their counts as links to their pages, keeping the filters", () => {
    setup({ options: ["Kolor:czarne"], sort: "cena-rosnaco", page: "2" });
    const nav = screen.getByRole("navigation", { name: "Kategorie" });
    expect(within(nav).getAllByRole("link").map((link) => [link.textContent, link.getAttribute("href")])).toEqual([
      ["Buty (7)", "/c/bieganie/buty?sort=cena-rosnaco&o=Kolor%3Aczarne"],
      ["Odzież (3)", "/c/bieganie/odziez?sort=cena-rosnaco&o=Kolor%3Aczarne"],
    ]);
  });

  it("links the categories of a search to the same search with `kategoria`", () => {
    setup({ q: "kurtka", available: "1" }, FACETS, { basePath: "/szukaj", mode: "search" });
    const links = within(screen.getByRole("navigation", { name: "Kategorie" })).getAllByRole("link");
    expect(links[0].getAttribute("href")).toBe("/szukaj?q=kurtka&dostepne=1&kategoria=bieganie%2Fbuty");
  });

  it("shows a group of checkboxes with the counts for every axis, the selected values checked, a value with 0 included", () => {
    setup({ options: ["Kolor:czarne"] });
    expect(screen.getByRole("group", { name: "Kolor" })).toBeTruthy();
    expect(screen.getByRole("group", { name: "Rozmiar" })).toBeTruthy();
    expect(box("czarne (4)").checked).toBe(true);
    expect(box("białe (0)").checked).toBe(false);
    expect(box("42 (5)").checked).toBe(false);
    expect(box("czarne (4)").getAttribute("name")).toBe("o");
    expect(box("czarne (4)").getAttribute("value")).toBe("Kolor:czarne");
    expect(box("42 (5)").getAttribute("value")).toBe("Rozmiar:42");
  });

  it("shows the price fields with the values of the address, the range from the API as a hint and „Tylko dostępne” with its count", () => {
    setup({ priceFrom: "250", priceTo: "400", available: "1" });
    expect(priceFrom().value).toBe("250");
    expect(priceTo().value).toBe("400");
    expect(priceFrom().getAttribute("name")).toBe("cena-od");
    expect(priceTo().getAttribute("name")).toBe("cena-do");
    expect(screen.getByText(/249,00\s*zł/).textContent).toMatch(/499,00\s*zł/);
    expect(box("Tylko dostępne (6)").checked).toBe(true);
    expect(box("Tylko dostępne (6)").getAttribute("name")).toBe("dostepne");
    expect(box("Tylko dostępne (6)").getAttribute("value")).toBe("1");
  });

  it("leaves out the sections the API has nothing for", () => {
    setup({}, { ...EMPTY_FACETS });
    expect(screen.queryByRole("navigation", { name: "Kategorie" })).toBeNull();
    expect(screen.queryAllByRole("group")).toHaveLength(1); // the price fields stay: they work without hints
    expect(screen.queryByText(/zł$/)).toBeNull();
  });

  it("is a GET form to the listing address carrying the phrase and the category of the search", () => {
    const html = renderToStaticMarkup(
      <ListingProvider basePath="/szukaj" state={{ q: "kurtka zimowa", options: [], category: "bieganie/buty" }}>
        <ListingFilters mode="search" facets={FACETS} />
      </ListingProvider>,
    );
    expect(html).toMatch(/<form[^>]*id="listing-filters"/);
    expect(html).toMatch(/<form[^>]*method="get"/);
    expect(html).toMatch(/<form[^>]*action="\/szukaj"/);
    expect(html).toContain('<input type="hidden" name="q" value="kurtka zimowa"/>');
    expect(html).toContain('<input type="hidden" name="kategoria" value="bieganie/buty"/>');
  });

  it("has the „Pokaż wyniki” button for a browser without JavaScript, and no hidden phrase on a listing", () => {
    const html = renderToStaticMarkup(
      <ListingProvider basePath="/c/bieganie" state={{ options: [] }}>
        <ListingFilters mode="list" facets={FACETS} />
      </ListingProvider>,
    );
    expect(html).toMatch(/<noscript>.*<button[^>]*type="submit"[^>]*>Pokaż wyniki<\/button>.*<\/noscript>/);
    expect(html).not.toContain('name="q"');
    expect(html).not.toContain('name="kategoria"');
  });
});

describe("ListingFilters: navigation", () => {
  it("navigates at once when a value is checked, keeping the rest and dropping the page", () => {
    setup({ options: ["Kolor:czarne"], sort: "nazwa", page: "3" });
    fireEvent.click(box("42 (5)"));
    expect(push).toHaveBeenCalledExactlyOnceWith("/c/bieganie?sort=nazwa&o=Kolor%3Aczarne&o=Rozmiar%3A42");
  });

  it("navigates at once when a value is unchecked", () => {
    setup({ options: ["Kolor:czarne", "Rozmiar:42"] });
    fireEvent.click(box("czarne (4)"));
    expect(push).toHaveBeenCalledExactlyOnceWith("/c/bieganie?o=Rozmiar%3A42");
  });

  it("navigates at once on „Tylko dostępne”, both ways", () => {
    setup({ page: "2" });
    fireEvent.click(box("Tylko dostępne (6)"));
    expect(push).toHaveBeenLastCalledWith("/c/bieganie?dostepne=1");
    cleanup();
    setup({ available: "1" });
    fireEvent.click(box("Tylko dostępne (6)"));
    expect(push).toHaveBeenLastCalledWith("/c/bieganie");
  });

  it("applies the price on Enter, in whole zloty as typed, from both fields, without submitting the form", () => {
    setup({ options: ["Kolor:czarne"], page: "2" });
    fireEvent.change(priceFrom(), { target: { value: "100" } });
    fireEvent.change(priceTo(), { target: { value: " 250 " } });
    expect(push).not.toHaveBeenCalled(); // typing does not navigate
    const notPrevented = fireEvent.keyDown(priceTo(), { key: "Enter" });
    expect(notPrevented).toBe(false); // the default action (submitting the form) is cancelled
    expect(push).toHaveBeenCalledExactlyOnceWith("/c/bieganie?cena-od=100&cena-do=250&o=Kolor%3Aczarne");
  });

  it("applies the price when the form is submitted too (a submit button, an assistive technology)", () => {
    setup();
    fireEvent.change(priceFrom(), { target: { value: "100" } });
    expect(fireEvent.submit(priceFrom().form!)).toBe(false);
    expect(push).toHaveBeenCalledExactlyOnceWith("/c/bieganie?cena-od=100");
  });

  it("ignores the other keys in the price fields", () => {
    setup();
    fireEvent.change(priceFrom(), { target: { value: "100" } });
    fireEvent.keyDown(priceFrom(), { key: "a" });
    fireEvent.keyDown(priceFrom(), { key: "Tab" });
    expect(push).not.toHaveBeenCalled();
  });

  it("applies the price when a field loses focus", () => {
    setup();
    fireEvent.change(priceFrom(), { target: { value: "100" } });
    fireEvent.blur(priceFrom());
    expect(push).toHaveBeenCalledExactlyOnceWith("/c/bieganie?cena-od=100");
  });

  it("does not navigate when the focus moves from one price field to the other, nor when nothing changed", () => {
    setup({ priceFrom: "100" });
    fireEvent.blur(priceFrom());
    expect(push).not.toHaveBeenCalled();
    fireEvent.change(priceFrom(), { target: { value: "120" } });
    fireEvent.blur(priceFrom(), { relatedTarget: priceTo() });
    expect(push).not.toHaveBeenCalled();
    fireEvent.change(priceTo(), { target: { value: "300" } });
    fireEvent.blur(priceTo());
    expect(push).toHaveBeenCalledExactlyOnceWith("/c/bieganie?cena-od=120&cena-do=300");
  });

  it("removes a price that was cleared", () => {
    setup({ priceFrom: "100", priceTo: "200" });
    fireEvent.change(priceFrom(), { target: { value: "" } });
    fireEvent.blur(priceFrom());
    expect(push).toHaveBeenCalledExactlyOnceWith("/c/bieganie?cena-do=200");
  });

  it("sends a value that is not whole zloty as typed, for the API to reject", () => {
    setup();
    fireEvent.change(priceFrom(), { target: { value: "12,5" } });
    fireEvent.blur(priceFrom());
    expect(push).toHaveBeenCalledExactlyOnceWith("/c/bieganie?cena-od=12%2C5");
  });

  it("keeps the phrase and the category of a search in every address", () => {
    setup({ q: "kurtka", category: "bieganie" }, FACETS, { basePath: "/szukaj", mode: "search" });
    fireEvent.click(box("42 (5)"));
    expect(push).toHaveBeenCalledExactlyOnceWith("/szukaj?q=kurtka&o=Rozmiar%3A42&kategoria=bieganie");
  });
});

describe("ListingFilters: narrow screens", () => {
  it("has a „Filtry” button that shows and hides the panel", () => {
    setup();
    const button = screen.getByRole("button", { name: "Filtry" });
    expect(button.getAttribute("aria-expanded")).toBe("false");
    expect(button.getAttribute("aria-controls")).toBe("listing-filters");
    fireEvent.click(button);
    expect(button.getAttribute("aria-expanded")).toBe("true");
    fireEvent.click(button);
    expect(button.getAttribute("aria-expanded")).toBe("false");
  });
});
