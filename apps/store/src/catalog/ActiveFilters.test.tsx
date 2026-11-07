// @vitest-environment jsdom
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { ActiveFilters } from "./ActiveFilters";

afterEach(cleanup);

const hrefOf = (name: string) => screen.getByRole("link", { name }).getAttribute("href");

describe("ActiveFilters", () => {
  it("renders nothing without filters (a sort and a phrase are not filters)", () => {
    const { container } = render(<ActiveFilters basePath="/szukaj" state={{ q: "kurtka", sort: "nazwa", options: [], page: "2" }} />);
    expect(container.innerHTML).toBe("");
  });

  it("shows a removal link for every filter, named after what it removes", () => {
    render(
      <ActiveFilters
        basePath="/c/bieganie"
        state={{ options: ["Kolor:czarne", "Rozmiar:42"], priceFrom: "100", priceTo: "250", available: "1" }}
      />,
    );
    const list = screen.getByRole("list", { name: "Aktywne filtry" });
    expect(list.textContent).toContain("Kolor: czarne");
    expect(list.textContent).toContain("Rozmiar: 42");
    expect(list.textContent).toContain("Cena od 100 zł");
    expect(list.textContent).toContain("Cena do 250 zł");
    expect(list.textContent).toContain("Tylko dostępne");
    expect(screen.getAllByRole("link", { name: /^Usuń filtr: / })).toHaveLength(5);
  });

  it("removes one filter per link, keeps the others and the sort, and drops the page", () => {
    render(
      <ActiveFilters
        basePath="/c/bieganie"
        state={{ sort: "cena-rosnaco", options: ["Kolor:czarne", "Rozmiar:42"], priceFrom: "100", priceTo: "250", available: "1", page: "3" }}
      />,
    );
    expect(hrefOf("Usuń filtr: Kolor: czarne")).toBe("/c/bieganie?sort=cena-rosnaco&cena-od=100&cena-do=250&dostepne=1&o=Rozmiar%3A42");
    expect(hrefOf("Usuń filtr: Cena od 100 zł")).toBe("/c/bieganie?sort=cena-rosnaco&cena-do=250&dostepne=1&o=Kolor%3Aczarne&o=Rozmiar%3A42");
    expect(hrefOf("Usuń filtr: Cena do 250 zł")).toBe("/c/bieganie?sort=cena-rosnaco&cena-od=100&dostepne=1&o=Kolor%3Aczarne&o=Rozmiar%3A42");
    expect(hrefOf("Usuń filtr: Tylko dostępne")).toBe("/c/bieganie?sort=cena-rosnaco&cena-od=100&cena-do=250&o=Kolor%3Aczarne&o=Rozmiar%3A42");
  });

  it("clears all the filters with one link that keeps the phrase and the sort", () => {
    render(<ActiveFilters basePath="/szukaj" state={{ q: "kurtka", sort: "cena-rosnaco", options: ["Kolor:czarne"], available: "1", category: "bieganie", page: "2" }} categoryName="Bieganie" />);
    expect(hrefOf("Wyczyść filtry")).toBe("/szukaj?q=kurtka&sort=cena-rosnaco");
  });

  it("shows the category of a search with its name and removes it like any filter", () => {
    render(<ActiveFilters basePath="/szukaj" state={{ q: "kurtka", options: [], category: "turystyka/biwak" }} categoryName="Biwak" />);
    expect(screen.getByRole("list", { name: "Aktywne filtry" }).textContent).toContain("Kategoria: Biwak");
    expect(hrefOf("Usuń filtr: Kategoria: Biwak")).toBe("/szukaj?q=kurtka");
  });

  it("splits an option at the first colon only", () => {
    render(<ActiveFilters basePath="/" state={{ options: ["Model:A:B"] }} />);
    expect(screen.getByRole("list", { name: "Aktywne filtry" }).textContent).toContain("Model: A:B");
  });
});
