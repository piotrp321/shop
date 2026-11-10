// @vitest-environment jsdom
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { CatalogPagination } from "./Pagination";

afterEach(cleanup);

const hrefFor = (n: number) => (n > 1 ? `/?page=${n}` : "/");

describe("CatalogPagination", () => {
  it("renders nothing when everything fits on one page", () => {
    const { container } = render(<CatalogPagination page={1} perPage={24} total={24} hrefFor={hrefFor} />);
    expect(container.innerHTML).toBe("");
  });

  it("links only forward on the first page", () => {
    render(<CatalogPagination page={1} perPage={24} total={50} hrefFor={hrefFor} />);
    expect(screen.queryByRole("link", { name: /poprzedniej/ })).toBeNull();
    expect(screen.getByRole("link", { name: /następnej/ }).getAttribute("href")).toBe("/?page=2");
  });

  it("links both ways on a middle page", () => {
    render(<CatalogPagination page={2} perPage={24} total={60} hrefFor={hrefFor} />);
    expect(screen.getByRole("link", { name: /poprzedniej/ }).getAttribute("href")).toBe("/");
    expect(screen.getByRole("link", { name: /następnej/ }).getAttribute("href")).toBe("/?page=3");
  });

  it("links to the addresses the page builds", () => {
    render(<CatalogPagination page={2} perPage={24} total={60} hrefFor={(n) => (n > 1 ? `/c/bieganie/buty?page=${n}` : "/c/bieganie/buty")} />);
    expect(screen.getByRole("link", { name: /poprzedniej/ }).getAttribute("href")).toBe("/c/bieganie/buty");
    expect(screen.getByRole("link", { name: /następnej/ }).getAttribute("href")).toBe("/c/bieganie/buty?page=3");
  });

  it("links only back on the last page", () => {
    render(<CatalogPagination page={3} perPage={24} total={60} hrefFor={hrefFor} />);
    expect(screen.getByRole("link", { name: /poprzedniej/ }).getAttribute("href")).toBe("/?page=2");
    expect(screen.queryByRole("link", { name: /następnej/ })).toBeNull();
  });

  it("leads back to the last real page from a page past the end", () => {
    render(<CatalogPagination page={999} perPage={24} total={60} hrefFor={hrefFor} />);
    expect(screen.getByRole("link", { name: /poprzedniej/ }).getAttribute("href")).toBe("/?page=3");
    expect(screen.queryByRole("link", { name: /następnej/ })).toBeNull();
  });

  it("shows the current page and the page count", () => {
    render(<CatalogPagination page={2} perPage={24} total={60} hrefFor={hrefFor} />);
    expect(screen.getByText("Strona 2 z 3")).toBeTruthy();
  });

  it("names the links so that the name contains the text they show (WCAG 2.5.3), and still says where they lead", () => {
    render(<CatalogPagination page={2} perPage={24} total={72} hrefFor={hrefFor} />);
    for (const [name, visible] of [[/poprzedniej strony/i, "Poprzednia"], [/następnej strony/i, "Następna"]] as const) {
      const link = screen.getByRole("link", { name });
      expect(link.textContent!.trim()).toBe(visible);
      expect(link.getAttribute("aria-label")!.toLowerCase()).toContain(visible.toLowerCase());
    }
    // The E2E tests find the links by these words (a substring match, any case).
    expect(screen.getByRole("link", { name: /przejdź do następnej strony/i })).toBeTruthy();
    expect(screen.getByRole("link", { name: /przejdź do poprzedniej strony/i })).toBeTruthy();
  });
});
