import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import { defineComponent, nextTick, type Ref, ref } from "vue";
import { type PageRequest, type PageResult, useCursorPager } from "@/composables/useCursorPager";

interface Query {
  q: string;
  perPage: number;
}

type Row = { name: string };

const rows = (...names: string[]): Row[] => names.map((name) => ({ name }));

/** Pages by the `after` cursor ("" for the first page); an entry that is an error result fails that page. */
function serve(pages: Record<string, PageResult<Row>>) {
  return vi.fn(async ({ after }: PageRequest<Query>): Promise<PageResult<Row>> => pages[after ?? ""] ?? { error: "Brak strony." });
}

function mountPager(fetchPage: (request: PageRequest<Query>) => Promise<PageResult<Row>>, options: { ready?: Ref<boolean> } = {}) {
  const query = ref<Query>({ q: "", perPage: 25 });
  let pager!: ReturnType<typeof useCursorPager<Row, Query>>;
  const wrapper = mount(
    defineComponent({
      setup() {
        pager = useCursorPager<Row, Query>(fetchPage, query, options);
        return () => null;
      },
    }),
  );
  return { wrapper, pager, query };
}

const names = (pager: { items: Ref<Row[]> }) => pager.items.value.map((row) => row.name);

describe("useCursorPager", () => {
  let wrapper: ReturnType<typeof mountPager>["wrapper"] | undefined;

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
  });

  async function start(fetchPage: ReturnType<typeof serve>, options: { ready?: Ref<boolean> } = {}) {
    const mounted = mountPager(fetchPage, options);
    wrapper = mounted.wrapper;
    await flushPromises();
    return mounted;
  }

  it("loads the first page of the query on mount", async () => {
    const fetchPage = serve({ "": { items: rows("A", "B"), next: null } });
    const { pager } = await start(fetchPage);

    expect(fetchPage).toHaveBeenCalledTimes(1);
    expect(fetchPage).toHaveBeenCalledWith({ query: { q: "", perPage: 25 }, after: null });
    expect(names(pager)).toEqual(["A", "B"]);
    expect(pager.loaded.value).toBe(true);
    expect(pager.loading.value).toBe(false);
    expect(pager.hasNext.value).toBe(false);
    expect(pager.hasPrevious.value).toBe(false);
    expect(pager.pageNumber.value).toBe(1);
  });

  it("pages forward and back with the cursors, keeping the stack of the visited ones", async () => {
    const fetchPage = serve({
      "": { items: rows("A"), next: "c1" },
      c1: { items: rows("B"), next: "c2" },
      c2: { items: rows("C"), next: null },
    });
    const { pager } = await start(fetchPage);

    await pager.goNext();
    await pager.goNext();
    expect(names(pager)).toEqual(["C"]);
    expect(pager.pageNumber.value).toBe(3);
    expect(pager.hasNext.value).toBe(false);

    await pager.goPrevious();
    expect(names(pager)).toEqual(["B"]);
    await pager.goPrevious();
    expect(names(pager)).toEqual(["A"]);
    expect(fetchPage.mock.calls.map(([request]) => request.after)).toEqual([null, "c1", "c2", "c1", null]);
    expect(pager.hasPrevious.value).toBe(false);
  });

  it("does not ask for a page that does not exist", async () => {
    const fetchPage = serve({ "": { items: rows("A"), next: null } });
    const { pager } = await start(fetchPage);

    await pager.goNext();
    await pager.goPrevious();

    expect(fetchPage).toHaveBeenCalledTimes(1);
  });

  it("numbers the rows of the page from the page number, the page size and the rows of the answer", async () => {
    const full = Array.from({ length: 25 }, (_, index) => `P${index}`);
    const fetchPage = serve({
      "": { items: rows(...full), next: "c1" },
      c1: { items: rows(...full), next: "c2" },
      c2: { items: rows("X", "Y"), next: null },
    });
    const { pager } = await start(fetchPage);
    expect(pager.range.value).toEqual({ from: 1, to: 25 });

    await pager.goNext();
    expect(pager.range.value).toEqual({ from: 26, to: 50 });

    await pager.goNext();
    expect(pager.range.value).toEqual({ from: 51, to: 52 });
  });

  it("has no range for an empty page", async () => {
    const { pager } = await start(serve({ "": { items: [], next: null } }));

    expect(pager.range.value).toBeNull();
  });

  it("goes back to the first page when the query changes, dropping the cursors, with one request", async () => {
    const fetchPage = serve({ "": { items: rows("A"), next: "c1" }, c1: { items: rows("B"), next: null } });
    const { pager, query } = await start(fetchPage);
    await pager.goNext();
    expect(pager.hasPrevious.value).toBe(true);

    query.value = { q: "x", perPage: 25 };
    await flushPromises();

    expect(fetchPage).toHaveBeenCalledTimes(3);
    expect(fetchPage).toHaveBeenLastCalledWith({ query: { q: "x", perPage: 25 }, after: null });
    expect(pager.hasPrevious.value).toBe(false);
    expect(pager.pageNumber.value).toBe(1);
    expect(names(pager)).toEqual(["A"]);
  });

  it("does not ask again for a query that is equal to the one it has", async () => {
    const fetchPage = serve({ "": { items: rows("A"), next: null } });
    const { query } = await start(fetchPage);

    query.value = { q: "", perPage: 25 };
    await flushPromises();

    expect(fetchPage).toHaveBeenCalledTimes(1);
  });

  it("numbers the rows by the page size of the page that is shown", async () => {
    const fetchPage = serve({ "": { items: rows("A", "B"), next: "c1" }, c1: { items: rows("C"), next: null } });
    const { pager, query } = await start(fetchPage);
    await pager.goNext();
    expect(pager.range.value).toEqual({ from: 26, to: 26 });

    query.value = { q: "", perPage: 10 };
    await flushPromises();
    expect(fetchPage).toHaveBeenLastCalledWith({ query: { q: "", perPage: 10 }, after: null });
    expect(pager.range.value).toEqual({ from: 1, to: 2 });
  });

  it("ignores an older response that arrives after a newer request", async () => {
    let finishFirst!: (value: PageResult<Row>) => void;
    const fetchPage = vi
      .fn<(request: PageRequest<Query>) => Promise<PageResult<Row>>>()
      .mockImplementationOnce(() => new Promise((resolve) => (finishFirst = resolve)))
      .mockImplementation(async () => ({ items: rows("Nowy"), next: null }));
    const { pager, query } = await start(fetchPage);

    query.value = { q: "x", perPage: 25 };
    await flushPromises();
    finishFirst({ items: rows("Stary"), next: null });
    await flushPromises();

    expect(names(pager)).toEqual(["Nowy"]);
    expect(pager.loading.value).toBe(false);
  });

  it("ignores a response that arrives after the query changed during a page change", async () => {
    let finishNext!: (value: PageResult<Row>) => void;
    const fetchPage = vi
      .fn<(request: PageRequest<Query>) => Promise<PageResult<Row>>>()
      .mockImplementationOnce(async () => ({ items: rows("A"), next: "c1" }))
      .mockImplementationOnce(() => new Promise((resolve) => (finishNext = resolve)))
      .mockImplementation(async () => ({ items: rows("Nowy"), next: null }));
    const { pager, query } = await start(fetchPage);

    void pager.goNext();
    query.value = { q: "x", perPage: 25 };
    await flushPromises();
    finishNext({ items: rows("B"), next: null });
    await flushPromises();

    expect(names(pager)).toEqual(["Nowy"]);
    expect(pager.pageNumber.value).toBe(1);
  });

  describe("a failed change of the query (#251 F10)", () => {
    it("shows no rows and no pager, only the error, until a retry succeeds", async () => {
      const pages: Record<string, PageResult<Row>> = { "": { items: rows("A"), next: "c1" } };
      const fetchPage = serve(pages);
      const { pager, query } = await start(fetchPage);

      pages[""] = { error: "Nie udało się." };
      query.value = { q: "x", perPage: 25 };
      await flushPromises();

      expect(pager.error.value).toBe("Nie udało się.");
      expect(pager.items.value).toEqual([]);
      expect(pager.loaded.value).toBe(false);
      expect(pager.hasNext.value).toBe(false);
      expect(pager.hasPrevious.value).toBe(false);
      expect(pager.range.value).toBeNull();

      pages[""] = { items: rows("Nowy"), next: null };
      await pager.retry();
      expect(pager.error.value).toBeNull();
      expect(names(pager)).toEqual(["Nowy"]);
      expect(pager.loaded.value).toBe(true);
      expect(fetchPage).toHaveBeenLastCalledWith({ query: { q: "x", perPage: 25 }, after: null });
    });

    it("also hides the rows when the very first load fails", async () => {
      const { pager } = await start(serve({ "": { error: "Nie udało się." } }));

      expect(pager.loaded.value).toBe(false);
      expect(pager.error.value).toBe("Nie udało się.");
    });

    it("keeps the old rows while the new query is loading", async () => {
      let finish!: (value: PageResult<Row>) => void;
      const fetchPage = vi
        .fn<(request: PageRequest<Query>) => Promise<PageResult<Row>>>()
        .mockImplementationOnce(async () => ({ items: rows("A"), next: null }))
        .mockImplementationOnce(() => new Promise((resolve) => (finish = resolve)));
      const { pager, query } = await start(fetchPage);

      query.value = { q: "x", perPage: 25 };
      await flushPromises();
      expect(pager.loading.value).toBe(true);
      expect(names(pager)).toEqual(["A"]);

      finish({ items: rows("B"), next: null });
      await flushPromises();
      expect(names(pager)).toEqual(["B"]);
    });

    it("turns a thrown error into a failure too", async () => {
      const fetchPage = vi.fn().mockRejectedValue(new Error("offline"));
      const { pager } = await start(fetchPage);

      expect(pager.error.value).not.toBeNull();
      expect(pager.loaded.value).toBe(false);
    });
  });

  describe("a failed change of the page", () => {
    it("keeps the page that is shown with its pager and retries the page that failed", async () => {
      const pages: Record<string, PageResult<Row>> = { "": { items: rows("A"), next: "c1" }, c1: { error: "Nie udało się." } };
      const { pager } = await start(serve(pages));

      await pager.goNext();
      expect(pager.error.value).toBe("Nie udało się.");
      expect(names(pager)).toEqual(["A"]);
      expect(pager.loaded.value).toBe(true);
      expect(pager.pageNumber.value).toBe(1);
      expect(pager.hasNext.value).toBe(true);

      pages.c1 = { items: rows("B"), next: null };
      await pager.retry();
      expect(pager.error.value).toBeNull();
      expect(names(pager)).toEqual(["B"]);
      expect(pager.pageNumber.value).toBe(2);
    });

    it("keeps the page when going back fails", async () => {
      const pages: Record<string, PageResult<Row>> = {
        "": { items: rows("A"), next: "c1" },
        c1: { items: rows("B"), next: null },
      };
      const { pager } = await start(serve(pages));
      await pager.goNext();

      pages[""] = { error: "Nie udało się." };
      await pager.goPrevious();

      expect(pager.error.value).toBe("Nie udało się.");
      expect(names(pager)).toEqual(["B"]);
      expect(pager.pageNumber.value).toBe(2);
    });
  });

  describe("a failed refresh of the current page", () => {
    it("reloads the page that is shown and keeps it when the reload fails", async () => {
      const pages: Record<string, PageResult<Row>> = { "": { items: rows("A"), next: "c1" }, c1: { items: rows("B"), next: null } };
      const fetchPage = serve(pages);
      const { pager } = await start(fetchPage);
      await pager.goNext();

      pages.c1 = { items: rows("B", "B2"), next: null };
      await pager.refresh();
      expect(names(pager)).toEqual(["B", "B2"]);
      expect(pager.pageNumber.value).toBe(2);
      expect(fetchPage).toHaveBeenLastCalledWith({ query: { q: "", perPage: 25 }, after: "c1" });

      pages.c1 = { error: "Nie udało się." };
      await pager.refresh();
      expect(pager.error.value).toBe("Nie udało się.");
      expect(names(pager)).toEqual(["B", "B2"]);
      expect(pager.pageNumber.value).toBe(2);
    });
  });

  describe("403", () => {
    it("is reported as forbidden, and a later success clears it", async () => {
      const pages: Record<string, PageResult<Row>> = { "": { error: "Nie masz dostępu.", forbidden: true } };
      const { pager } = await start(serve(pages));

      expect(pager.forbidden.value).toBe(true);
      expect(pager.error.value).toBe("Nie masz dostępu.");

      pages[""] = { items: rows("A"), next: null };
      await pager.retry();
      expect(pager.forbidden.value).toBe(false);
      expect(pager.error.value).toBeNull();
    });
  });

  describe("ready", () => {
    it("asks for nothing until the query is ready, then once for the newest query", async () => {
      const fetchPage = serve({ "": { items: rows("A"), next: null } });
      const ready = ref(false);
      const mounted = mountPager(fetchPage, { ready });
      wrapper = mounted.wrapper;
      await flushPromises();
      expect(fetchPage).not.toHaveBeenCalled();
      expect(mounted.pager.loaded.value).toBe(false);

      mounted.query.value = { q: "x", perPage: 25 };
      await flushPromises();
      expect(fetchPage).not.toHaveBeenCalled();

      ready.value = true;
      await flushPromises();
      expect(fetchPage).toHaveBeenCalledTimes(1);
      expect(fetchPage).toHaveBeenCalledWith({ query: { q: "x", perPage: 25 }, after: null });
    });
  });

  it("ignores a response that arrives after the pager is gone", async () => {
    let finish!: (value: PageResult<Row>) => void;
    const fetchPage = vi.fn(() => new Promise<PageResult<Row>>((resolve) => (finish = resolve)));
    const mounted = mountPager(fetchPage);
    wrapper = mounted.wrapper;
    await nextTick();

    mounted.wrapper.unmount();
    wrapper = undefined;
    finish({ items: rows("A"), next: null });
    await flushPromises();

    expect(mounted.pager.items.value).toEqual([]);
    expect(mounted.pager.loaded.value).toBe(false);
  });
});
