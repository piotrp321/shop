// @vitest-environment jsdom
import { cleanup, render, screen, within } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));

import { CatalogListing } from "./CatalogListing";
import { EMPTY_FACETS, makeOffer, makeProduct } from "./fixtures";
import type { ListingState } from "./listing-query";

afterEach(cleanup);

const listing = (total: number, items = [makeProduct(1)], page = 1, facets = EMPTY_FACETS) => ({
  kind: "ok" as const,
  products: { items, page, perPage: 24, total, facets },
  offers: { [makeProduct(1).id]: makeOffer(1) },
});

const NO_FILTERS: ListingState = { options: [] };

describe("CatalogListing", () => {
  const heading = () => screen.getByRole("heading", { level: 1 });

  it("keeps the title alone in the h1 and shows the number of products next to it, with the Polish form", () => {
    render(<CatalogListing title="Buty" basePath="/c/bieganie/buty" state={NO_FILTERS} mode="list" listing={listing(1)} />);
    expect(screen.getByRole("heading", { level: 1, name: "Buty" })).toBeTruthy();
    expect(screen.getByText("1 produkt")).toBeTruthy();
    expect(heading().contains(screen.getByText("1 produkt"))).toBe(false);
    cleanup();
    render(<CatalogListing title="Buty" basePath="/c/bieganie/buty" state={NO_FILTERS} mode="list" listing={listing(23)} />);
    expect(screen.getByText("23 produkty")).toBeTruthy();
    cleanup();
    render(<CatalogListing title="Buty" basePath="/c/bieganie/buty" state={NO_FILTERS} mode="list" listing={listing(12)} />);
    expect(screen.getByText("12 produktów")).toBeTruthy();
  });

  it("counts all the products, not the ones on the page", () => {
    render(<CatalogListing title="Wszystkie produkty" basePath="/" state={NO_FILTERS} mode="list" listing={listing(60)} />);
    expect(screen.getByRole("heading", { level: 1, name: "Wszystkie produkty" })).toBeTruthy();
    expect(screen.getByText("60 produktów")).toBeTruthy();
  });

  it("with an intro (the home page) puts it first, makes „Wszystkie produkty” an h2 and the names of the products h3, with no h1 of its own", () => {
    render(<CatalogListing title="Wszystkie produkty" basePath="/" state={NO_FILTERS} mode="list" listing={listing(60)} intro={<h1>Hero</h1>} />);
    expect(screen.getAllByRole("heading", { level: 1 }).map((item) => item.textContent)).toEqual(["Hero"]);
    expect(screen.getByRole("heading", { level: 2, name: "Wszystkie produkty" })).toBeTruthy();
    expect(screen.getByText("60 produktów")).toBeTruthy();
    expect(screen.getByRole("heading", { level: 3, name: "Produkt 1" })).toBeTruthy();
    const main = screen.getByRole("main");
    expect(main.firstElementChild!.textContent).toBe("Hero");
  });

  it("shows the products as tiles", () => {
    render(<CatalogListing title="Buty" basePath="/c/bieganie/buty" state={NO_FILTERS} mode="list" listing={listing(1)} />);
    expect(screen.getAllByRole("link", { name: "Produkt 1" }).length).toBeGreaterThan(0);
  });

  it("shows the breadcrumbs it is given and none otherwise", () => {
    render(<CatalogListing title="Buty" basePath="/c/bieganie/buty" state={NO_FILTERS} mode="list" crumbs={[{ name: "Wszystkie produkty", href: "/" }, { name: "Buty" }]} listing={listing(1)} />);
    const nav = screen.getByRole("navigation", { name: "Ścieżka" });
    expect(within(nav).getAllByRole("link").map((link) => link.getAttribute("href"))).toEqual(["/"]);
    cleanup();
    render(<CatalogListing title="Wszystkie produkty" basePath="/" state={NO_FILTERS} mode="list" listing={listing(1)} />);
    expect(screen.queryByRole("navigation", { name: "Ścieżka" })).toBeNull();
  });

  it("paginates in the addresses of the category path", () => {
    render(<CatalogListing title="Buty" basePath="/c/bieganie/buty" state={NO_FILTERS} mode="list" listing={listing(60, [makeProduct(1)], 2)} />);
    expect(screen.getByRole("link", { name: /następnej/ }).getAttribute("href")).toBe("/c/bieganie/buty?page=3");
    expect(screen.getByRole("link", { name: /poprzedniej/ }).getAttribute("href")).toBe("/c/bieganie/buty");
  });

  it("says there are no products on an empty page and still counts them", () => {
    render(<CatalogListing title="Buty" basePath="/c/bieganie/buty" state={NO_FILTERS} mode="list" listing={listing(0, [])} />);
    expect(screen.getByText("Nie znaleźliśmy produktów.")).toBeTruthy();
    expect(screen.getByText("0 produktów")).toBeTruthy();
    expect(screen.queryByRole("link", { name: "Wyczyść filtry" })).toBeNull();
    expect(screen.queryByRole("link", { name: "Zobacz wszystkie produkty" })).toBeNull();
  });

  it("offers „Wyczyść filtry” on an empty result with filters", () => {
    render(<CatalogListing title="Buty" basePath="/c/bieganie/buty" state={{ options: ["Kolor:czarne"], sort: "nazwa" }} mode="list" listing={listing(0, [])} />);
    expect(screen.getAllByRole("link", { name: "Wyczyść filtry" }).map((link) => link.getAttribute("href"))).toEqual(["/c/bieganie/buty?sort=nazwa", "/c/bieganie/buty?sort=nazwa"]);
  });

  it("offers a link to all the products on an empty search", () => {
    render(<CatalogListing title="Wyniki dla „xyz”" basePath="/szukaj" state={{ q: "xyz", options: [] }} mode="search" listing={listing(0, [])} />);
    expect(screen.getByRole("link", { name: "Zobacz wszystkie produkty" }).getAttribute("href")).toBe("/");
  });

  it("shows the filter panel from the facets of the API and the sort list", () => {
    const facets = { ...EMPTY_FACETS, options: [{ axis: "Kolor", values: [{ value: "czarne", count: 2 }] }] };
    render(<CatalogListing title="Buty" basePath="/c/bieganie/buty" state={NO_FILTERS} mode="list" listing={listing(2, [makeProduct(1)], 1, facets)} />);
    expect(screen.getByRole("checkbox", { name: "czarne (2)" })).toBeTruthy();
    expect(screen.getByRole("combobox", { name: "Sortuj" })).toBeTruthy();
    expect(screen.queryByRole("list", { name: "Aktywne filtry" })).toBeNull();
  });

  it("shows the active filters above the results", () => {
    render(<CatalogListing title="Buty" basePath="/c/bieganie/buty" state={{ options: ["Kolor:czarne"] }} mode="list" listing={listing(2)} />);
    expect(screen.getByRole("list", { name: "Aktywne filtry" }).textContent).toContain("Kolor: czarne");
  });

  it("keeps every parameter in the pagination links and starts the first page without a page", () => {
    render(
      <CatalogListing
        title="Wyniki dla „kurtka”"
        basePath="/szukaj"
        state={{ q: "kurtka", sort: "cena-rosnaco", options: ["Kolor:czarne"], priceFrom: "100", available: "1", category: "bieganie", page: "2" }}
        mode="search"
        listing={listing(60, [makeProduct(1)], 2)}
      />,
    );
    const query = "q=kurtka&sort=cena-rosnaco&cena-od=100&dostepne=1&o=Kolor%3Aczarne&kategoria=bieganie";
    expect(screen.getByRole("link", { name: /następnej/ }).getAttribute("href")).toBe(`/szukaj?${query}&page=3`);
    expect(screen.getByRole("link", { name: /poprzedniej/ }).getAttribute("href")).toBe(`/szukaj?${query}`);
  });
});
