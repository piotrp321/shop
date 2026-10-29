// @vitest-environment jsdom
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { CatalogPagination } from "./Pagination";

afterEach(cleanup);

describe("CatalogPagination", () => {
  it("renders nothing when everything fits on one page", () => {
    const { container } = render(<CatalogPagination page={1} perPage={24} total={24} />);
    expect(container.innerHTML).toBe("");
  });

  it("links only forward on the first page", () => {
    render(<CatalogPagination page={1} perPage={24} total={50} />);
    expect(screen.queryByRole("link", { name: /poprzedniej/ })).toBeNull();
    expect(screen.getByRole("link", { name: /następnej/ }).getAttribute("href")).toBe("/?page=2");
  });

  it("links both ways on a middle page and keeps the category", () => {
    render(<CatalogPagination page={2} perPage={24} total={60} category="abc" />);
    expect(screen.getByRole("link", { name: /poprzedniej/ }).getAttribute("href")).toBe("/?category=abc");
    expect(screen.getByRole("link", { name: /następnej/ }).getAttribute("href")).toBe("/?category=abc&page=3");
  });

  it("links only back on the last page", () => {
    render(<CatalogPagination page={3} perPage={24} total={60} />);
    expect(screen.getByRole("link", { name: /poprzedniej/ }).getAttribute("href")).toBe("/?page=2");
    expect(screen.queryByRole("link", { name: /następnej/ })).toBeNull();
  });

  it("leads back to the last real page from a page past the end", () => {
    render(<CatalogPagination page={999} perPage={24} total={60} />);
    expect(screen.getByRole("link", { name: /poprzedniej/ }).getAttribute("href")).toBe("/?page=3");
    expect(screen.queryByRole("link", { name: /następnej/ })).toBeNull();
  });

  it("shows the current page and the page count", () => {
    render(<CatalogPagination page={2} perPage={24} total={60} />);
    expect(screen.getByText("Strona 2 z 3")).toBeTruthy();
  });
});
