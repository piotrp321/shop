import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";
import { createMemoryHistory, createRouter } from "vue-router";
import { SEARCH_HINT, SEARCH_TOO_SHORT_HINT, useOrderList } from "@/orders/useOrderList";

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

function queries(): Record<string, unknown>[] {
  return mocks.get.mock.calls.map(([, options]) => (options as { params: { query: Record<string, unknown> } }).params.query);
}

async function mountList(path: string) {
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/orders", name: "orders", component: empty },
      { path: "/products", name: "products", component: empty },
    ],
  });
  await router.push(path);
  let list!: ReturnType<typeof useOrderList>;
  const wrapper = mount(
    defineComponent({
      setup() {
        list = useOrderList();
        return () => null;
      },
    }),
    { global: { plugins: [router] } },
  );
  await flushPromises();
  return { wrapper, list, router };
}

describe("useOrderList: the search by fragment and the range of days", () => {
  let wrapper: Awaited<ReturnType<typeof mountList>>["wrapper"] | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
    mocks.get.mockResolvedValue({ data: { items: [], nextCursor: null, total: { value: 0, capped: false } }, response: { status: 200 } });
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
  });

  async function startAt(path: string) {
    const mounted = await mountList(path);
    wrapper = mounted.wrapper;
    return mounted;
  }

  describe("the search term", () => {
    it("searches a fragment of 3 characters or more, trimmed, on Enter", async () => {
      const { list, router } = await startAt("/orders");

      list.search.value = "  kow  ";
      list.submitSearch();
      await flushPromises();

      expect(queries()).toEqual([{}, { q: "kow" }]);
      expect(router.currentRoute.value.fullPath).toBe("/orders?q=kow");
    });

    it.each(["R-1", "r-100001", "jan@gm", "Łuk"])("takes %s as a search", async (term) => {
      const { list } = await startAt("/orders");

      list.search.value = term;
      list.submitSearch();
      await flushPromises();

      expect(queries().at(-1)).toEqual({ q: term });
    });

    it.each(["a", "ab", "  ab  ", "\u00A0ż\u00A0"])("does not search the 1 or 2 characters of %j: nothing is asked and the search stays as it was", async (term) => {
      const { list, router } = await startAt("/orders?q=anna");
      mocks.get.mockClear();

      list.search.value = term;
      list.submitSearch();
      await flushPromises();

      expect(mocks.get).not.toHaveBeenCalled();
      expect(router.currentRoute.value.fullPath).toBe("/orders?q=anna");
      expect(list.searchHint.value).toBe(SEARCH_TOO_SHORT_HINT);
    });

    it("says what the field takes while it is empty or has enough characters, and when it is too short while typing", async () => {
      const { list } = await startAt("/orders");
      expect(list.searchHint.value).toBe(SEARCH_HINT);
      expect(SEARCH_HINT).toBe("Numer, e-mail albo fragment (od 3 znaków) numeru, e-maila lub nazwiska. Enter wyszukuje.");

      list.search.value = "ab";
      expect(list.searchHint.value).toBe("Wpisz co najmniej 3 znaki, aby wyszukać.");
      list.search.value = "abc";
      expect(list.searchHint.value).toBe(SEARCH_HINT);
      list.search.value = "   ";
      expect(list.searchHint.value).toBe(SEARCH_HINT);
    });

    it("a blank field clears the search on Enter", async () => {
      const { list, router } = await startAt("/orders?q=anna");

      list.search.value = "   ";
      list.submitSearch();
      await flushPromises();

      expect(queries()).toEqual([{ q: "anna" }, {}]);
      expect(router.currentRoute.value.fullPath).toBe("/orders");
    });

    it("skips a search of the address that is too short and corrects the address", async () => {
      const { list, router } = await startAt("/orders?q=ab");

      expect(queries()).toEqual([{}]);
      expect(router.currentRoute.value.fullPath).toBe("/orders");
      expect(list.query.value.q).toBeNull();
    });
  });

  describe("the range of days", () => {
    it("reads the days of the address and asks with them, from the first page", async () => {
      const { list } = await startAt("/orders?placedFrom=2026-11-01&placedTo=2026-11-30");

      expect(queries()).toEqual([{ placedFrom: "2026-11-01", placedTo: "2026-11-30" }]);
      expect(list.placedFrom.value).toBe("2026-11-01");
      expect(list.placedTo.value).toBe("2026-11-30");
      expect(list.filtered.value).toBe(true);
    });

    it("writes a day to the address when it is chosen, and drops it when it is cleared", async () => {
      const { list, router } = await startAt("/orders");

      list.setPlacedFrom("2026-11-01");
      await flushPromises();
      list.setPlacedTo("2026-11-30");
      await flushPromises();
      list.setPlacedFrom("");
      await flushPromises();

      expect(queries()).toEqual([{}, { placedFrom: "2026-11-01" }, { placedFrom: "2026-11-01", placedTo: "2026-11-30" }, { placedTo: "2026-11-30" }]);
      expect(router.currentRoute.value.fullPath).toBe("/orders?placedTo=2026-11-30");
    });

    it.each([
      "/orders?placedFrom=2026-13-01",
      "/orders?placedFrom=2026-02-30",
      "/orders?placedFrom=01.11.2026",
      "/orders?placedFrom=2026-11-01T10:00",
      "/orders?placedFrom=1999-12-31",
      "/orders?placedFrom=",
    ])("skips the invalid day of %s and corrects the address", async (path) => {
      const { list, router } = await startAt(path);

      expect(queries()).toEqual([{}]);
      expect(list.placedFrom.value).toBeNull();
      expect(router.currentRoute.value.fullPath).toBe("/orders");
    });

    it("does not take a parameter of another name (placedFrom[]) as a day", async () => {
      const { list } = await startAt("/orders?placedFrom[]=2026-11-01");

      expect(queries()).toEqual([{}]);
      expect(list.placedFrom.value).toBeNull();
    });

    it.each(["0002-11-05", "0020-11-05", "0202-11-05", "1999-12-31", "3000-01-01"])(
      "keeps the day that is applied while a year is being typed (%s passes in Chrome on the way to 2026): nothing is asked, the field shows what is typed",
      async (typed) => {
        const { list, router } = await startAt("/orders?placedFrom=2026-11-01");

        list.setPlacedFrom(typed);
        await flushPromises();

        expect(queries()).toEqual([{ placedFrom: "2026-11-01" }]);
        expect(router.currentRoute.value.fullPath).toBe("/orders?placedFrom=2026-11-01");
        expect(list.placedFromField.value).toBe(typed);
        expect(list.placedFrom.value).toBe("2026-11-01");

        list.setPlacedFrom("2026-11-05");
        await flushPromises();
        expect(queries()).toEqual([{ placedFrom: "2026-11-01" }, { placedFrom: "2026-11-05" }]);
      },
    );

    it("applies the other day while a year is being typed in this one, with the day that is applied here", async () => {
      const { list } = await startAt("/orders?placedFrom=2026-11-01");

      list.setPlacedFrom("0002-11-05");
      list.setPlacedTo("2026-11-30");
      await flushPromises();

      expect(queries().at(-1)).toEqual({ placedFrom: "2026-11-01", placedTo: "2026-11-30" });
    });

    it("does not apply an end before the applied start while a year is being typed in the start", async () => {
      const { list } = await startAt("/orders?placedFrom=2026-11-01");

      list.setPlacedFrom("0202-11-05");
      list.setPlacedTo("2026-10-15");
      await flushPromises();

      expect(queries()).toEqual([{ placedFrom: "2026-11-01" }]);
      expect(list.rangeError.value).toBe("Data „Do” nie może być wcześniejsza niż data „Od”.");
    });

    it("does not apply a start after the applied end while a year is being typed in the end", async () => {
      const { list } = await startAt("/orders?placedTo=2026-10-15");

      list.setPlacedTo("0202-10-15");
      list.setPlacedFrom("2026-11-01");
      await flushPromises();

      expect(queries()).toEqual([{ placedTo: "2026-10-15" }]);
      expect(list.rangeError.value).toBe("Data „Do” nie może być wcześniejsza niż data „Od”.");
    });

    it("takes the bounds of the fields from the days they stand for, within the years the API takes", async () => {
      const { list } = await startAt("/orders?placedFrom=2026-11-01");
      expect(list.dayBounds.value).toEqual({ fromMin: "2000-01-01", fromMax: "2999-12-31", toMin: "2026-11-01", toMax: "2999-12-31" });

      list.setPlacedTo("0202-10-15");
      expect(list.dayBounds.value.fromMax).toBe("2999-12-31");
      list.setPlacedTo("2026-12-24");
      await flushPromises();
      expect(list.dayBounds.value.fromMax).toBe("2026-12-24");
    });

    it("still clears a day when its field is emptied", async () => {
      const { list } = await startAt("/orders?placedFrom=2026-11-01");

      list.setPlacedFrom("");
      await flushPromises();

      expect(queries().at(-1)).toEqual({});
    });

    it("does not apply an end before the start, and says why; fixing it applies it", async () => {
      const { list, router } = await startAt("/orders?placedFrom=2026-11-10");

      list.setPlacedTo("2026-11-01");
      await flushPromises();

      expect(queries()).toEqual([{ placedFrom: "2026-11-10" }]);
      expect(router.currentRoute.value.fullPath).toBe("/orders?placedFrom=2026-11-10");
      expect(list.rangeError.value).toBe("Data „Do” nie może być wcześniejsza niż data „Od”.");
      expect(list.placedToField.value).toBe("2026-11-01");

      list.setPlacedTo("2026-11-12");
      await flushPromises();
      expect(list.rangeError.value).toBeNull();
      expect(queries().at(-1)).toEqual({ placedFrom: "2026-11-10", placedTo: "2026-11-12" });
    });

    it("does not apply a start after the end", async () => {
      const { list } = await startAt("/orders?placedTo=2026-11-10");

      list.setPlacedFrom("2026-11-11");
      await flushPromises();

      expect(queries()).toEqual([{ placedTo: "2026-11-10" }]);
      expect(list.rangeError.value).toBe("Data „Do” nie może być wcześniejsza niż data „Od”.");
    });

    it("allows the same day at both ends", async () => {
      const { list } = await startAt("/orders");

      list.setPlacedFrom("2026-11-10");
      await flushPromises();
      list.setPlacedTo("2026-11-10");
      await flushPromises();

      expect(queries().at(-1)).toEqual({ placedFrom: "2026-11-10", placedTo: "2026-11-10" });
      expect(list.rangeError.value).toBeNull();
    });

    it("an inverted range of the address is sent as it is for the API to refuse; it is not made up by the panel", async () => {
      const { list } = await startAt("/orders?placedFrom=2026-11-10&placedTo=2026-11-01");

      expect(queries()).toEqual([{ placedFrom: "2026-11-10", placedTo: "2026-11-01" }]);
      expect(list.rangeError.value).toBe("Data „Do” nie może być wcześniejsza niż data „Od”.");
    });

    it("combines with the status, the search and the sort by placement", async () => {
      const { list } = await startAt("/orders?status=paid&q=kowalski&sort=placedAt&placedFrom=2026-11-01");

      expect(queries()).toEqual([{ status: "paid", q: "kowalski", sort: "placedAt", placedFrom: "2026-11-01" }]);
      expect(list.sort.value).toBe("placedAt");
    });

    it("drops the days with the other filters", async () => {
      const { list, router } = await startAt("/orders?placedFrom=2026-11-01&placedTo=2026-11-30&status=paid");

      list.clearFilters();
      await flushPromises();

      expect(queries().at(-1)).toEqual({});
      expect(list.placedFrom.value).toBeNull();
      expect(list.placedTo.value).toBeNull();
      expect(router.currentRoute.value.fullPath).toBe("/orders");
    });

    it("puts the days of an address that changes from outside (Back) into the fields", async () => {
      const { list, router } = await startAt("/orders?placedFrom=2026-11-01");

      await router.push("/orders?placedTo=2026-12-01");
      await flushPromises();

      expect(list.placedFromField.value).toBe("");
      expect(list.placedToField.value).toBe("2026-12-01");
      expect(queries().at(-1)).toEqual({ placedTo: "2026-12-01" });
    });

    it("sends no days with the refunds to do, which have their own order: the API refuses both", async () => {
      const { list } = await startAt("/orders?refund=pending&placedFrom=2026-11-01");

      expect(queries()).toEqual([{ refund: "pending" }]);
      expect(list.rangeDisabled.value).toBe(true);
      expect(list.rangeDisabledReason.value).toBe("Zwroty do wykonania nie łączą się z zakresem dat.");

      list.setRefundPending(false);
      await flushPromises();
      expect(queries().at(-1)).toEqual({ placedFrom: "2026-11-01" });
      expect(list.rangeDisabled.value).toBe(false);
    });

    it("disables the amount while a range is applied, says why, and falls back to the newest first", async () => {
      const { list } = await startAt("/orders?sort=-total&placedTo=2026-11-30");

      expect(queries()).toEqual([{ placedTo: "2026-11-30" }]);
      expect(list.sort.value).toBe("-placedAt");
      expect(list.sortOptions.value.map((option) => option.disabledReason)).toEqual([
        undefined,
        undefined,
        "Kwoty nie da się sortować przy zakresie dat.",
        "Kwoty nie da się sortować przy zakresie dat.",
      ]);
    });

    it("returns to the newest first when a range is chosen over the sort by the amount", async () => {
      const { list, router } = await startAt("/orders?sort=total");

      list.setPlacedFrom("2026-11-01");
      await flushPromises();

      expect(queries().at(-1)).toEqual({ placedFrom: "2026-11-01" });
      expect(router.currentRoute.value.fullPath).toBe("/orders?placedFrom=2026-11-01");
    });

    it("names the search first when both disable the amount", async () => {
      const { list } = await startAt("/orders?q=kowalski&placedTo=2026-11-30");

      expect(list.sortOptions.value[2]?.disabledReason).toBe("Kwoty nie da się sortować przy wyszukiwaniu.");
    });
  });
});
