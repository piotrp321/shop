// @vitest-environment jsdom
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import NotFound from "./not-found";

afterEach(cleanup);

describe("the 404 page", () => {
  it("says the page was not found in Polish, in the only h1", () => {
    render(<NotFound />);
    expect(screen.getByRole("heading", { level: 1 }).textContent).toBe("Nie znaleźliśmy tej strony.");
  });

  it("links back to the store home page", () => {
    render(<NotFound />);
    expect(screen.getByRole("link", { name: "Wróć do sklepu" }).getAttribute("href")).toBe("/");
  });

  it("is the main landmark of the page", () => {
    render(<NotFound />);
    expect(screen.getByRole("main")).toBeTruthy();
  });
});
