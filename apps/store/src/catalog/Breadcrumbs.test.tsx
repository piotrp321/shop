// @vitest-environment jsdom
import { cleanup, render, screen, within } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { Breadcrumbs } from "./Breadcrumbs";

afterEach(cleanup);

describe("Breadcrumbs", () => {
  it("is a navigation named Ścieżka with a link for each item that has an address", () => {
    render(<Breadcrumbs items={[{ name: "Wszystkie produkty", href: "/" }, { name: "Bieganie", href: "/c/bieganie" }]} />);
    const nav = screen.getByRole("navigation", { name: "Ścieżka" });
    expect(within(nav).getAllByRole("link").map((link) => [link.textContent, link.getAttribute("href")])).toEqual([
      ["Wszystkie produkty", "/"],
      ["Bieganie", "/c/bieganie"],
    ]);
  });

  it("shows an item without an address as the current page, not as a link", () => {
    render(<Breadcrumbs items={[{ name: "Wszystkie produkty", href: "/" }, { name: "Bieganie" }]} />);
    const nav = screen.getByRole("navigation", { name: "Ścieżka" });
    expect(within(nav).getAllByRole("link")).toHaveLength(1);
    expect(within(nav).getByText("Bieganie").getAttribute("aria-current")).toBe("page");
    expect(within(nav).getByText("Bieganie").closest("a")).toBeNull();
  });
});
