// @vitest-environment jsdom
import { cleanup, render, screen, within } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { CatalogListing } from "./CatalogListing";
import { makeOffer, makeProduct } from "./fixtures";

afterEach(cleanup);

const listing = (total: number, items = [makeProduct(1)], page = 1) => ({
  kind: "ok" as const,
  products: { items, page, perPage: 24, total },
  offers: { [makeProduct(1).id]: makeOffer(1) },
});

describe("CatalogListing", () => {
  it("shows the title and the number of products in the h1, with the Polish form", () => {
    render(<CatalogListing title="Buty" listing={listing(1)} />);
    expect(screen.getByRole("heading", { level: 1 }).textContent).toBe("Buty 1 produkt");
    cleanup();
    render(<CatalogListing title="Buty" listing={listing(23)} />);
    expect(screen.getByRole("heading", { level: 1 }).textContent).toBe("Buty 23 produkty");
    cleanup();
    render(<CatalogListing title="Buty" listing={listing(12)} />);
    expect(screen.getByRole("heading", { level: 1 }).textContent).toBe("Buty 12 produktów");
  });

  it("counts all the products, not the ones on the page", () => {
    render(<CatalogListing title="Wszystkie produkty" listing={listing(60)} />);
    expect(screen.getByRole("heading", { level: 1 }).textContent).toBe("Wszystkie produkty 60 produktów");
  });

  it("shows the products as tiles", () => {
    render(<CatalogListing title="Buty" listing={listing(1)} />);
    expect(screen.getAllByRole("link", { name: "Produkt 1" }).length).toBeGreaterThan(0);
  });

  it("shows the breadcrumbs it is given and none otherwise", () => {
    render(<CatalogListing title="Buty" crumbs={[{ name: "Wszystkie produkty", href: "/" }, { name: "Buty" }]} listing={listing(1)} />);
    const nav = screen.getByRole("navigation", { name: "Ścieżka" });
    expect(within(nav).getAllByRole("link").map((link) => link.getAttribute("href"))).toEqual(["/"]);
    cleanup();
    render(<CatalogListing title="Wszystkie produkty" listing={listing(1)} />);
    expect(screen.queryByRole("navigation", { name: "Ścieżka" })).toBeNull();
  });

  it("paginates in the addresses of the category path", () => {
    render(<CatalogListing title="Buty" categoryPath="bieganie/buty" listing={listing(60, [makeProduct(1)], 2)} />);
    expect(screen.getByRole("link", { name: /następnej/ }).getAttribute("href")).toBe("/c/bieganie/buty?page=3");
    expect(screen.getByRole("link", { name: /poprzedniej/ }).getAttribute("href")).toBe("/c/bieganie/buty");
  });

  it("says there are no products on an empty page and still counts them", () => {
    render(<CatalogListing title="Buty" listing={listing(0, [])} />);
    expect(screen.getByText("Brak produktów do wyświetlenia.")).toBeTruthy();
    expect(screen.getByRole("heading", { level: 1 }).textContent).toBe("Buty 0 produktów");
  });
});
