import { mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, nextTick } from "vue";
import { NAME_SEARCH_DEBOUNCE_MS, useNameSearch } from "@/composables/useNameSearch";

const TOO_SHORT = "Wpisz co najmniej 2 znaki, aby wyszukać.";
const TOO_LONG = "Wpisz najwyżej 100 znaków, aby wyszukać.";

function mountSearch(initial: string | null = null) {
  const apply = vi.fn<(term: string | null) => void>();
  let search!: ReturnType<typeof useNameSearch>;
  const wrapper = mount(
    defineComponent({
      setup() {
        search = useNameSearch({ initial, apply });
        return () => null;
      },
    }),
  );
  return { wrapper, search, apply };
}

/** Types into the field and lets the watcher of the field run. */
async function type(search: ReturnType<typeof useNameSearch>, text: string) {
  search.search.value = text;
  await nextTick();
}

describe("useNameSearch", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("starts with the field set to the term of the address and applies nothing", async () => {
    const { search, apply } = mountSearch("kawa");
    expect(search.search.value).toBe("kawa");
    await vi.advanceTimersByTimeAsync(NAME_SEARCH_DEBOUNCE_MS * 2);
    expect(apply).not.toHaveBeenCalled();
  });

  it("waits 300 ms after the last keystroke and applies the term once", async () => {
    expect(NAME_SEARCH_DEBOUNCE_MS).toBe(300);
    const { search, apply } = mountSearch();
    await type(search, "ha");
    await vi.advanceTimersByTimeAsync(200);
    await type(search, "han");
    await vi.advanceTimersByTimeAsync(299);
    expect(apply).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);
    expect(apply).toHaveBeenCalledTimes(1);
    expect(apply).toHaveBeenCalledWith("han");
  });

  it("does not apply one character: the list stays as it is and the hint asks for two", async () => {
    const { search, apply } = mountSearch();
    await type(search, "h");
    expect(search.tooShort.value).toBe(true);
    expect(search.hint.value).toBe(TOO_SHORT);
    await vi.advanceTimersByTimeAsync(NAME_SEARCH_DEBOUNCE_MS);
    expect(apply).not.toHaveBeenCalled();
  });

  it("applies two characters and shows no hint", async () => {
    const { search, apply } = mountSearch();
    await type(search, "ha");
    expect(search.tooShort.value).toBe(false);
    expect(search.hint.value).toBeNull();
    await vi.advanceTimersByTimeAsync(NAME_SEARCH_DEBOUNCE_MS);
    expect(apply).toHaveBeenCalledWith("ha");
  });

  it("counts code points, not UTF-16 units: 100 characters pass, 101 do not", async () => {
    const { search, apply } = mountSearch();
    const hundred = "😀".repeat(100);
    expect(hundred.length).toBe(200);
    await type(search, hundred);
    expect(search.hint.value).toBeNull();
    await vi.advanceTimersByTimeAsync(NAME_SEARCH_DEBOUNCE_MS);
    expect(apply).toHaveBeenCalledWith(hundred);

    apply.mockClear();
    await type(search, "😀".repeat(101));
    expect(search.hint.value).toBe(TOO_LONG);
    expect(search.tooShort.value).toBe(false);
    await vi.advanceTimersByTimeAsync(NAME_SEARCH_DEBOUNCE_MS);
    expect(apply).not.toHaveBeenCalled();
  });

  it("trims the term and counts the length after the trim", async () => {
    const { search, apply } = mountSearch();
    await type(search, "  ha ");
    await vi.advanceTimersByTimeAsync(NAME_SEARCH_DEBOUNCE_MS);
    expect(apply).toHaveBeenCalledWith("ha");

    apply.mockClear();
    await type(search, "  h  ");
    expect(search.hint.value).toBe(TOO_SHORT);
    await vi.advanceTimersByTimeAsync(NAME_SEARCH_DEBOUNCE_MS);
    expect(apply).not.toHaveBeenCalled();
  });

  it("takes only spaces for an empty search: no hint, and the filter is dropped (null)", async () => {
    const { search, apply } = mountSearch("kawa");
    await type(search, "   ");
    expect(search.hint.value).toBeNull();
    expect(search.tooShort.value).toBe(false);
    await vi.advanceTimersByTimeAsync(NAME_SEARCH_DEBOUNCE_MS);
    expect(apply).toHaveBeenCalledTimes(1);
    expect(apply).toHaveBeenCalledWith(null);
  });

  it("applies null when the field is emptied after a term was applied", async () => {
    const { search, apply } = mountSearch();
    await type(search, "hantle");
    await vi.advanceTimersByTimeAsync(NAME_SEARCH_DEBOUNCE_MS);
    apply.mockClear();
    await type(search, "");
    await vi.advanceTimersByTimeAsync(NAME_SEARCH_DEBOUNCE_MS);
    expect(apply).toHaveBeenCalledTimes(1);
    expect(apply).toHaveBeenCalledWith(null);
  });

  it("reset empties the field and drops the pending delay without applying anything", async () => {
    const { search, apply } = mountSearch();
    await type(search, "hantle");
    search.reset();
    await nextTick();
    expect(search.search.value).toBe("");
    await vi.advanceTimersByTimeAsync(NAME_SEARCH_DEBOUNCE_MS * 2);
    expect(apply).not.toHaveBeenCalled();
  });

  it("drops the pending delay when the component is unmounted", async () => {
    const { wrapper, search, apply } = mountSearch();
    await type(search, "hantle");
    await vi.advanceTimersByTimeAsync(NAME_SEARCH_DEBOUNCE_MS - 1);
    wrapper.unmount();
    await vi.advanceTimersByTimeAsync(NAME_SEARCH_DEBOUNCE_MS);
    expect(apply).not.toHaveBeenCalled();
  });

  it("a value from the address sets the field without applying it again and drops the pending delay", async () => {
    const { search, apply } = mountSearch();
    await type(search, "hantle");
    search.setFromAddress("kawa");
    await nextTick();
    expect(search.search.value).toBe("kawa");
    await vi.advanceTimersByTimeAsync(NAME_SEARCH_DEBOUNCE_MS * 2);
    expect(apply).not.toHaveBeenCalled();

    search.setFromAddress(null);
    await nextTick();
    expect(search.search.value).toBe("");
    await vi.advanceTimersByTimeAsync(NAME_SEARCH_DEBOUNCE_MS * 2);
    expect(apply).not.toHaveBeenCalled();
  });

  it("applies again what the staff member types after a value from the address", async () => {
    const { search, apply } = mountSearch();
    search.setFromAddress("kawa");
    await nextTick();
    await type(search, "kawa mielona");
    await vi.advanceTimersByTimeAsync(NAME_SEARCH_DEBOUNCE_MS);
    expect(apply).toHaveBeenCalledTimes(1);
    expect(apply).toHaveBeenCalledWith("kawa mielona");
  });
});
