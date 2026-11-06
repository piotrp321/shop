// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const push = vi.hoisted(() => vi.fn());
vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));

import { SortSelect } from "./SortSelect";

beforeEach(() => push.mockReset());
afterEach(cleanup);

const select = () => screen.getByRole("combobox", { name: "Sortuj" }) as HTMLSelectElement;
const labels = () => [...select().options].map((option) => option.textContent);

describe("SortSelect", () => {
  it("lists the sorts, with „Trafność” only for a phrase", () => {
    render(<SortSelect basePath="/szukaj" state={{ q: "kurtka", options: [] }} />);
    expect(labels()).toEqual(["Trafność", "Nazwa A–Z", "Cena rosnąco", "Cena malejąco"]);
    cleanup();
    render(<SortSelect basePath="/" state={{ options: [] }} />);
    expect(labels()).toEqual(["Nazwa A–Z", "Cena rosnąco", "Cena malejąco"]);
  });

  it("selects the default sort without `sort`: relevance with a phrase, name otherwise", () => {
    render(<SortSelect basePath="/szukaj" state={{ q: "kurtka", options: [] }} />);
    expect(select().value).toBe("trafnosc");
    cleanup();
    render(<SortSelect basePath="/" state={{ options: [] }} />);
    expect(select().value).toBe("nazwa");
  });

  it("selects the sort of the address", () => {
    render(<SortSelect basePath="/" state={{ sort: "cena-malejaco", options: [] }} />);
    expect(select().value).toBe("cena-malejaco");
  });

  it("belongs to the filter form, so it is sent with „Pokaż wyniki” without JavaScript", () => {
    render(<SortSelect basePath="/" state={{ options: [] }} />);
    expect(select().getAttribute("name")).toBe("sort");
    expect(select().getAttribute("form")).toBe("listing-filters");
  });

  it("navigates at once on a change, keeping the filters and the phrase and dropping the page", () => {
    render(<SortSelect basePath="/szukaj" state={{ q: "kurtka", options: ["Kolor:czarne"], available: "1", page: "3" }} />);
    fireEvent.change(select(), { target: { value: "cena-rosnaco" } });
    expect(push).toHaveBeenCalledExactlyOnceWith("/szukaj?q=kurtka&sort=cena-rosnaco&dostepne=1&o=Kolor%3Aczarne");
  });

  it("leaves `sort` out when the default sort is chosen", () => {
    render(<SortSelect basePath="/c/bieganie" state={{ sort: "cena-rosnaco", options: [] }} />);
    fireEvent.change(select(), { target: { value: "nazwa" } });
    expect(push).toHaveBeenCalledExactlyOnceWith("/c/bieganie");
  });
});
