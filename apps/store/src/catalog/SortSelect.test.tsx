// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { startTransition } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const push = vi.hoisted(() => vi.fn());
vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));

import { ListingProvider } from "./listing-context";
import type { ListingState } from "./listing-query";
import { SortSelect } from "./SortSelect";

beforeEach(() => push.mockReset());
afterEach(cleanup);

const select = () => screen.getByRole("combobox", { name: "Sortuj" }) as HTMLSelectElement;
const setup = (basePath: string, state: Partial<ListingState> = {}) =>
  render(
    <ListingProvider basePath={basePath} state={{ options: [], ...state }}>
      <SortSelect />
    </ListingProvider>,
  );
const labels = () => [...select().options].map((option) => option.textContent);

describe("SortSelect", () => {
  it("lists the sorts, with „Trafność” only for a phrase", () => {
    setup("/szukaj", { q: "kurtka", options: [] });
    expect(labels()).toEqual(["Trafność", "Nazwa A–Z", "Cena rosnąco", "Cena malejąco"]);
    cleanup();
    setup("/", { options: [] });
    expect(labels()).toEqual(["Nazwa A–Z", "Cena rosnąco", "Cena malejąco"]);
  });

  it("selects the default sort without `sort`: relevance with a phrase, name otherwise", () => {
    setup("/szukaj", { q: "kurtka", options: [] });
    expect(select().value).toBe("trafnosc");
    cleanup();
    setup("/", { options: [] });
    expect(select().value).toBe("nazwa");
  });

  it("selects the sort of the address", () => {
    setup("/", { sort: "cena-malejaco", options: [] });
    expect(select().value).toBe("cena-malejaco");
  });

  it("belongs to the filter form, so it is sent with „Pokaż wyniki” without JavaScript", () => {
    setup("/", { options: [] });
    expect(select().getAttribute("name")).toBe("sort");
    expect(select().getAttribute("form")).toBe("listing-filters");
  });

  it("navigates at once on a change, keeping the filters and the phrase and dropping the page", () => {
    setup("/szukaj", { q: "kurtka", options: ["Kolor:czarne"], available: "1", page: "3" });
    fireEvent.change(select(), { target: { value: "cena-rosnaco" } });
    expect(push).toHaveBeenCalledExactlyOnceWith("/szukaj?q=kurtka&sort=cena-rosnaco&dostepne=1&o=Kolor%3Aczarne");
  });

  it("leaves `sort` out when the default sort is chosen", () => {
    setup("/c/bieganie", { sort: "cena-rosnaco", options: [] });
    fireEvent.change(select(), { target: { value: "nazwa" } });
    expect(push).toHaveBeenCalledExactlyOnceWith("/c/bieganie");
  });

  it("shows the chosen sort at once, while the navigation has not finished", () => {
    // A navigation that does not finish, like a slow page: the transition around `push` stays pending.
    push.mockImplementation(() => {
      startTransition(async () => {
        await new Promise(() => {});
      });
    });
    setup("/c/bieganie", {});
    expect(select().value).toBe("nazwa");
    fireEvent.change(select(), { target: { value: "cena-malejaco" } });
    expect(select().value).toBe("cena-malejaco");
    fireEvent.change(select(), { target: { value: "nazwa" } });
    expect(select().value).toBe("nazwa");
  });
});
