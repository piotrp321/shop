// @vitest-environment jsdom
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { CatalogPagination } from "./Pagination";

afterEach(cleanup);

const hrefFor = (n: number) => (n > 1 ? `/?page=${n}` : "/");

describe("CatalogPagination", () => {
  it("renders nothing when everything fits on one page", () => {
    const { container } = render(<CatalogPagination page={1} pageCount={1} hrefFor={hrefFor} />);
    expect(container.innerHTML).toBe("");
  });

  it("links only forward on the first page", () => {
    render(<CatalogPagination page={1} pageCount={3} hrefFor={hrefFor} />);
    expect(screen.queryByRole("link", { name: /poprzedniej/ })).toBeNull();
    expect(screen.getByRole("link", { name: /następnej/ }).getAttribute("href")).toBe("/?page=2");
  });

  it("links both ways on a middle page", () => {
    render(<CatalogPagination page={2} pageCount={3} hrefFor={hrefFor} />);
    expect(screen.getByRole("link", { name: /poprzedniej/ }).getAttribute("href")).toBe("/");
    expect(screen.getByRole("link", { name: /następnej/ }).getAttribute("href")).toBe("/?page=3");
  });

  it("links to the addresses the page builds", () => {
    render(<CatalogPagination page={2} pageCount={3} hrefFor={(n) => (n > 1 ? `/c/bieganie/buty?page=${n}` : "/c/bieganie/buty")} />);
    expect(screen.getByRole("link", { name: /poprzedniej/ }).getAttribute("href")).toBe("/c/bieganie/buty");
    expect(screen.getByRole("link", { name: /następnej/ }).getAttribute("href")).toBe("/c/bieganie/buty?page=3");
  });

  it("links only back on the last page", () => {
    render(<CatalogPagination page={3} pageCount={3} hrefFor={hrefFor} />);
    expect(screen.getByRole("link", { name: /poprzedniej/ }).getAttribute("href")).toBe("/?page=2");
    expect(screen.queryByRole("link", { name: /następnej/ })).toBeNull();
  });

  it("leads back to the last real page from a page past the end", () => {
    render(<CatalogPagination page={999} pageCount={3} hrefFor={hrefFor} />);
    expect(screen.getByRole("link", { name: /poprzedniej/ }).getAttribute("href")).toBe("/?page=3");
    expect(screen.queryByRole("link", { name: /następnej/ })).toBeNull();
  });

  it("takes the number of pages from the API: 417 pages of a huge catalog, the last one without a way forward", () => {
    render(<CatalogPagination page={1} pageCount={417} hrefFor={hrefFor} />);
    expect(screen.getByText("Strona 1 z 417")).toBeTruthy();
    cleanup();
    render(<CatalogPagination page={417} pageCount={417} hrefFor={hrefFor} />);
    expect(screen.getByText("Strona 417 z 417")).toBeTruthy();
    expect(screen.queryByRole("link", { name: /następnej/ })).toBeNull();
    expect(screen.getByRole("link", { name: /poprzedniej/ }).getAttribute("href")).toBe("/?page=416");
  });

  it("leads back to the last page the API reaches from a page far past it", () => {
    render(<CatalogPagination page={500} pageCount={417} hrefFor={hrefFor} />);
    expect(screen.getByRole("link", { name: /poprzedniej/ }).getAttribute("href")).toBe("/?page=417");
    expect(screen.queryByRole("link", { name: /następnej/ })).toBeNull();
  });

  it("renders nothing without a numeric page count (an older API answer from the cache), never „z undefined”", () => {
    for (const pageCount of [undefined, null, "417", Number.NaN]) {
      const { container } = render(<CatalogPagination page={1} pageCount={pageCount as unknown as number} hrefFor={hrefFor} />);
      expect(container.innerHTML).toBe("");
      cleanup();
    }
  });

  it("renders nothing for a page count of 0 or 1", () => {
    for (const pageCount of [0, 1]) {
      const { container } = render(<CatalogPagination page={1} pageCount={pageCount} hrefFor={hrefFor} />);
      expect(container.innerHTML).toBe("");
      cleanup();
    }
  });

  it("shows the current page and the page count", () => {
    render(<CatalogPagination page={2} pageCount={3} hrefFor={hrefFor} />);
    expect(screen.getByText("Strona 2 z 3")).toBeTruthy();
  });

  it("names the links so that the name contains the text they show (WCAG 2.5.3), and still says where they lead", () => {
    render(<CatalogPagination page={2} pageCount={3} hrefFor={hrefFor} />);
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
