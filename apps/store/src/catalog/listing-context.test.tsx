// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { startTransition } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

// A navigation that does not finish, like a slow page: the transition around `push` stays pending.
const push = vi.hoisted(() => vi.fn());
vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));

import { EMPTY_FACETS } from "./fixtures";
import { ListingFilters } from "./ListingFilters";
import { ListingProvider } from "./listing-context";
import type { ListingState, ProductFacets } from "./listing-query";
import { SortSelect } from "./SortSelect";

beforeEach(() => {
  push.mockReset();
  push.mockImplementation(() => {
    startTransition(async () => {
      await new Promise(() => {});
    });
  });
});
afterEach(cleanup);

const FACETS: ProductFacets = {
  ...EMPTY_FACETS,
  options: [{ axis: "Kolor", values: [{ value: "czarne", count: 4 }] }],
  availableCount: 6,
};

function setup(state: Partial<ListingState> = {}) {
  const full: ListingState = { options: [], ...state };
  render(
    <ListingProvider basePath="/c/bieganie" state={full}>
      <ListingFilters mode="list" facets={FACETS} />
      <SortSelect />
    </ListingProvider>,
  );
}

const select = () => screen.getByRole("combobox", { name: "Sortuj" }) as HTMLSelectElement;
const priceFrom = () => screen.getByLabelText("Cena od") as HTMLInputElement;

describe("the filter panel and the sort share what was changed before the page arrives", () => {
  it("keeps the sort when a value is checked right after it", () => {
    setup();
    fireEvent.change(select(), { target: { value: "cena-rosnaco" } });
    fireEvent.click(screen.getByRole("checkbox", { name: "czarne (4)" }));
    expect(push).toHaveBeenCalledTimes(2);
    expect(push).toHaveBeenLastCalledWith("/c/bieganie?sort=cena-rosnaco&o=Kolor%3Aczarne");
  });

  it("keeps the filter when the sort is changed right after it", () => {
    setup();
    fireEvent.click(screen.getByRole("checkbox", { name: "Tylko dostępne (6)" }));
    fireEvent.change(select(), { target: { value: "cena-malejaco" } });
    expect(push).toHaveBeenCalledTimes(2);
    expect(push).toHaveBeenLastCalledWith("/c/bieganie?sort=cena-malejaco&dostepne=1");
  });

  it("keeps the price when the sort is changed right after it", () => {
    setup();
    fireEvent.change(priceFrom(), { target: { value: "100" } });
    fireEvent.blur(priceFrom());
    fireEvent.change(select(), { target: { value: "cena-rosnaco" } });
    expect(push).toHaveBeenCalledTimes(2);
    expect(push).toHaveBeenLastCalledWith("/c/bieganie?sort=cena-rosnaco&cena-od=100");
  });
});
