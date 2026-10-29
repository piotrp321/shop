// @vitest-environment jsdom
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { CategoryTree } from "./CategoryTree";
import { TREE } from "./fixtures";

afterEach(cleanup);

const detailsOf = (name: string) => screen.getByRole("link", { name }).closest("details");

describe("CategoryTree", () => {
  it("marks the active category with aria-current", () => {
    render(<CategoryTree categories={TREE} activeId="buty" />);
    expect(screen.getByRole("link", { name: "Buty" }).getAttribute("aria-current")).toBe("page");
    expect(screen.getByRole("link", { name: "Bieganie" }).hasAttribute("aria-current")).toBe(false);
    expect(screen.getByRole("link", { name: "Odzież" }).hasAttribute("aria-current")).toBe(false);
  });

  it("expands the ancestors of the active category and the active category itself", () => {
    render(<CategoryTree categories={TREE} activeId="trailowe" />);
    expect(detailsOf("Bieganie")?.open).toBe(true);
    expect(detailsOf("Buty")?.open).toBe(true);
  });

  it("keeps the other branches collapsed", () => {
    render(<CategoryTree categories={TREE} activeId="trailowe" />);
    expect(detailsOf("Rower")?.open).toBe(false);
  });

  it("collapses everything when no category is active", () => {
    render(<CategoryTree categories={TREE} />);
    expect(detailsOf("Bieganie")?.open).toBe(false);
    expect(detailsOf("Rower")?.open).toBe(false);
    expect(document.querySelector("[aria-current]")).toBeNull();
  });

  it("collapses everything for an unknown active category", () => {
    render(<CategoryTree categories={TREE} activeId="missing" />);
    expect(detailsOf("Bieganie")?.open).toBe(false);
    expect(document.querySelector("[aria-current]")).toBeNull();
  });

  it("links to ?category=<id>", () => {
    render(<CategoryTree categories={TREE} />);
    expect(screen.getByRole("link", { name: "Kaski" }).getAttribute("href")).toBe("/?category=kaski");
    expect(screen.getByRole("link", { name: "Bieganie" }).getAttribute("href")).toBe("/?category=bieganie");
  });

  it("links to the whole catalogue", () => {
    render(<CategoryTree categories={TREE} />);
    expect(screen.getByRole("link", { name: "Wszystkie produkty" }).getAttribute("href")).toBe("/");
  });
});
