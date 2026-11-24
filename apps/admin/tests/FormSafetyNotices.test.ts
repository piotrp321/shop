import { flushPromises, mount } from "@vue/test-utils";
import { type Mock, afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import FormSafetyNotices from "@/components/FormSafetyNotices.vue";
import { REQUEST_LIMIT_MS, type ReadResult } from "@/composables/formRequests";
import { useFormSafety } from "@/composables/useFormSafety";

interface Doc {
  id: string;
  version: number;
  name: string;
}

describe("FormSafetyNotices", () => {
  let state: { id: string | null; draft: { name: string } };
  let read: Mock<(id: string) => Promise<ReadResult<Doc>>>;
  let mounted: ReturnType<typeof mount> | undefined;

  function create() {
    return useFormSafety<Doc, { name: string }>({
      id: () => state.id,
      read,
      version: () => 4,
      draftRequest: () => state.draft,
      servedRequest: (view) => ({ name: view.name }),
      adopt: (view) => {
        state.draft = { name: view.name };
      },
      onLateSuccess: () => {},
      newObject: { unknownMessage: "Nie wiemy, czy obiekt został utworzony.", ownNote: "Możliwe, że utworzył go Twój wcześniejszy zapis." },
    });
  }

  async function show(safety: ReturnType<typeof create>) {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: "/list", name: "list", component: { template: "<div />" } }] });
    await router.push("/list");
    mounted = mount(FormSafetyNotices, {
      attachTo: document.body,
      props: { safety, listTo: { name: "list" }, listLabel: "Lista obiektów" },
      global: { plugins: [router] },
    });
    await flushPromises();
    return mounted;
  }

  const failing = () => Promise.resolve({ data: undefined, error: {}, response: { status: 502 } });

  beforeEach(() => {
    vi.useFakeTimers();
    state = { id: "d1", draft: { name: "Pierwsza" } };
    read = vi.fn();
  });

  afterEach(() => {
    mounted?.unmount();
    mounted = undefined;
    document.body.innerHTML = "";
    vi.useRealTimers();
  });

  it("has an empty status region and nothing else while there is nothing to say", async () => {
    const wrapper = await show(create());

    expect(wrapper.get("[data-testid=safety-status]").attributes("role")).toBe("status");
    expect(wrapper.get("[data-testid=safety-status]").text()).toBe("");
    expect(wrapper.find("[role=alert]").exists()).toBe(false);
  });

  it("shows the message of an unknown result of a save in an alert with „Sprawdź stan” and moves the focus to it", async () => {
    const safety = create();
    const wrapper = await show(safety);

    await safety.write(() => failing() as never, { onSuccess: vi.fn(), onRefused: vi.fn() });
    await flushPromises();

    const alert = wrapper.get("[data-testid=unknown-result]");
    expect(alert.attributes("role")).toBe("alert");
    expect(alert.text()).toContain("Nie wiemy, czy zapis się powiódł. Twoje zmiany zostają w formularzu.");
    expect(alert.get("[data-testid=check-state]").text()).toBe("Sprawdź stan");
    expect(alert.find("[data-testid=unblock-form]").exists()).toBe(false);
    expect(document.activeElement).toBe(alert.element);
  });

  it("„Sprawdź stan” reads the object, is off while it reads, and a match says so in the status region", async () => {
    const safety = create();
    const wrapper = await show(safety);
    await safety.write(() => failing() as never, { onSuccess: vi.fn(), onRefused: vi.fn() });
    await flushPromises();
    let release!: (value: ReadResult<Doc>) => void;
    read.mockReturnValue(new Promise((resolve) => (release = resolve)));

    await wrapper.get("[data-testid=check-state]").trigger("click");

    expect(wrapper.get("[data-testid=safety-status]").text()).toBe("Odświeżamy…");
    expect(wrapper.get("[data-testid=check-state]").attributes("disabled")).toBeDefined();
    release({ data: { id: "d1", version: 5, name: "Pierwsza" } });
    await flushPromises();

    expect(wrapper.find("[data-testid=unknown-result]").exists()).toBe(false);
    expect(wrapper.get("[data-testid=safety-status]").text()).toBe("Stan na serwerze odpowiada formularzowi.");
    expect(document.activeElement).toBe(wrapper.get("[data-testid=safety-status]").element);
  });

  it("shows „Odblokuj formularz” for a new object after the limit, and a link to the list in a new tab after the unlock", async () => {
    state.id = null;
    const safety = create();
    const wrapper = await show(safety);
    void safety.write(() => new Promise(() => {}), { onSuccess: vi.fn(), onRefused: vi.fn() });
    await vi.advanceTimersByTimeAsync(REQUEST_LIMIT_MS);
    await flushPromises();

    expect(wrapper.find("[data-testid=check-state]").exists()).toBe(false);
    expect(wrapper.find("[data-testid=list-link]").exists()).toBe(false);
    await wrapper.get("[data-testid=unblock-form]").trigger("click");

    expect(wrapper.find("[data-testid=unblock-form]").exists()).toBe(false);
    expect(wrapper.get("[data-testid=unknown-result]").text()).toContain("Sprawdź listę przed ponownym zapisem.");
    const link = wrapper.get("[data-testid=list-link]");
    expect(link.attributes("href")).toBe("/list");
    expect(link.attributes("target")).toBe("_blank");
    expect(link.attributes("rel")).toContain("noopener");
    expect(link.text()).toContain("Lista obiektów");
    expect(link.text()).toContain("otwiera się w nowej karcie");
  });

  it("offers „Odrzuć moje zmiany i pokaż stan z serwera” on its own when only the 409 asks for it, and says it was done", async () => {
    const safety = create();
    const wrapper = await show(safety);
    safety.offerRestore("Na serwerze jest inna wersja niż ta, którą edytujesz.");
    await flushPromises();
    read.mockResolvedValue({ data: { id: "d1", version: 6, name: "Z serwera" } });

    await wrapper.get("[data-testid=restore-state]").trigger("click");
    await flushPromises();

    expect(wrapper.emitted("restored")).toHaveLength(1);
    expect(wrapper.find("[data-testid=restore-state]").exists()).toBe(false);
    expect(state.draft).toEqual({ name: "Z serwera" });
  });

  // jsdom has no layout: the classes are the contract that the long label wraps inside the message at 320 px (WCAG 1.4.10),
  // measured in a browser when they were chosen (the default button never wraps and sticks out of the message).
  it("lets the label of „Odrzuć moje zmiany i pokaż stan z serwera” wrap inside the message in each of its three places", async () => {
    const wrapsInside = (button: { classes: () => string[] }) => {
      const classes = button.classes();
      expect(classes).toEqual(expect.arrayContaining(["whitespace-normal", "h-auto", "max-w-full"]));
      expect(classes).not.toContain("whitespace-nowrap");
    };

    const alone = create();
    const first = await show(alone);
    alone.offerRestore("Na serwerze jest inna wersja niż ta, którą edytujesz.");
    await flushPromises();
    wrapsInside(first.get("[data-testid=restore-state]"));
    first.unmount();

    const discarded = create();
    const second = await show(discarded);
    discarded.offerRestore("Rozpoczętej promocji nie można zmienić.");
    discarded.draftDiscarded();
    await flushPromises();
    wrapsInside(second.get("[data-testid=restore-state]"));
    second.unmount();

    const unknown = create();
    const third = await show(unknown);
    await unknown.write(() => failing() as never, { onSuccess: vi.fn(), onRefused: vi.fn() });
    read.mockResolvedValue({ data: { id: "d1", version: 6, name: "Cudza" } });
    await unknown.check();
    await flushPromises();
    wrapsInside(third.get("[data-testid=restore-state]"));
  });

  it("does not say it was restored when the read failed, and shows the message to try again with the focus on it", async () => {
    const safety = create();
    const wrapper = await show(safety);
    safety.offerRestore("Na serwerze jest inna wersja niż ta, którą edytujesz.");
    await flushPromises();
    read.mockRejectedValue(new TypeError("network down"));

    await wrapper.get("[data-testid=restore-state]").trigger("click");
    await flushPromises();

    expect(wrapper.emitted("restored")).toBeUndefined();
    const alert = wrapper.get("[data-testid=read-failed]");
    expect(alert.attributes("role")).toBe("alert");
    expect(alert.text()).toContain("Nie udało się odczytać stanu. Spróbuj ponownie.");
    expect(document.activeElement).toBe(alert.element);
    read.mockResolvedValue({ data: { id: "d1", version: 6, name: "Z serwera" } });
    await alert.get("[data-testid=retry-read]").trigger("click");
    await flushPromises();
    expect(wrapper.find("[data-testid=read-failed]").exists()).toBe(false);
    expect(state.draft).toEqual({ name: "Z serwera" });
  });

  it("keeps the label of the button after the draft is discarded, with the context of the 409 and the focus on it", async () => {
    const safety = create();
    const wrapper = await show(safety);
    safety.offerRestore("Rozpoczętej promocji nie można zmienić.");
    await flushPromises();
    expect(wrapper.get("[data-testid=restore-state]").text()).toBe("Odrzuć moje zmiany i pokaż stan z serwera");
    expect(wrapper.find("[data-testid=restore-note]").exists()).toBe(false);

    safety.draftDiscarded();
    await flushPromises();

    const note = wrapper.get("[data-testid=restore-note]");
    expect(note.attributes("role")).toBe("alert");
    expect(note.text()).toContain("Rozpoczętej promocji nie można zmienić.");
    expect(wrapper.find("[data-testid=check-state]").exists()).toBe(false);
    expect(wrapper.find("[data-testid=unknown-result]").exists()).toBe(false);
    expect(wrapper.findAll("[data-testid=restore-state]")).toHaveLength(1);
    expect(wrapper.get("[data-testid=restore-state]").text()).toBe("Odrzuć moje zmiany i pokaż stan z serwera");
    expect(document.activeElement).toBe(note.element);
  });

  it("moves the focus to the message of an unknown result when the draft is discarded", async () => {
    const safety = create();
    const wrapper = await show(safety);
    await safety.write(() => failing() as never, { onSuccess: vi.fn(), onRefused: vi.fn() });
    await flushPromises();
    (document.body as HTMLElement).focus();

    safety.draftDiscarded();
    await flushPromises();

    const alert = wrapper.get("[data-testid=unknown-result]");
    expect(alert.text()).toContain("Nie wiemy, czy zapis się powiódł.");
    expect(alert.text()).not.toContain("Twoje zmiany zostają");
    expect(document.activeElement).toBe(alert.element);
  });

  describe("the focus does not depend on the text changing", () => {
    const blur = () => (document.activeElement as HTMLElement | null)?.blur();

    it("goes to the context of a 409 each time the draft is discarded, also with the same text", async () => {
      const safety = create();
      const wrapper = await show(safety);
      safety.offerRestore("Rozpoczętej promocji nie można zmienić.");
      safety.draftDiscarded();
      await flushPromises();
      expect(document.activeElement).toBe(wrapper.get("[data-testid=restore-note]").element);
      blur();

      safety.draftDiscarded();
      await flushPromises();

      expect(document.activeElement).toBe(wrapper.get("[data-testid=restore-note]").element);
    });

    it("goes to the message of an unknown result each time the draft is discarded", async () => {
      const safety = create();
      const wrapper = await show(safety);
      await safety.write(() => failing() as never, { onSuccess: vi.fn(), onRefused: vi.fn() });
      safety.draftDiscarded();
      await flushPromises();
      blur();

      safety.draftDiscarded();
      await flushPromises();

      expect(document.activeElement).toBe(wrapper.get("[data-testid=unknown-result]").element);
    });

    it("goes to what „Sprawdź stan” found each time, also when it finds the same", async () => {
      const safety = create();
      const wrapper = await show(safety);
      await safety.write(() => failing() as never, { onSuccess: vi.fn(), onRefused: vi.fn() });
      read.mockResolvedValue({ data: { id: "d1", version: 4, name: "Stara" } });
      await safety.check();
      await flushPromises();
      expect(wrapper.get("[data-testid=unknown-result]").text()).toContain("Zapisu nie widać na serwerze");
      blur();

      await safety.check();
      await flushPromises();

      expect(document.activeElement).toBe(wrapper.get("[data-testid=unknown-result]").element);
    });

    it("goes to the message of a read that failed again with the same text", async () => {
      const safety = create();
      const wrapper = await show(safety);
      read.mockRejectedValue(new TypeError("network down"));
      await safety.restore();
      await flushPromises();
      expect(document.activeElement).toBe(wrapper.get("[data-testid=read-failed]").element);
      blur();

      await safety.retryRead();
      await flushPromises();

      expect(document.activeElement).toBe(wrapper.get("[data-testid=read-failed]").element);
    });
  });

  describe("where the focus is while a request of a button runs, and after it", () => {
    const focusOn = (wrapper: ReturnType<typeof mount>, testid: string) => {
      const element = wrapper.get(`[data-testid=${testid}]`).element as HTMLElement;
      element.focus();
      return element;
    };
    const held = () => {
      let release!: (value: { data: Doc } | { status: number } | null) => void;
      const promise = new Promise<{ data: Doc } | { status: number } | null>((resolve) => (release = resolve));
      return { promise, release };
    };
    const unknownResult = async (safety: ReturnType<typeof create>) => {
      await safety.write(() => failing() as never, { onSuccess: vi.fn(), onRefused: vi.fn() });
      await flushPromises();
    };

    it("„Sprawdź stan”: on the status region „Odświeżamy…” while it reads, then on what it found", async () => {
      const safety = create();
      const wrapper = await show(safety);
      await unknownResult(safety);
      const answer = held();
      read.mockReturnValue(answer.promise);
      focusOn(wrapper, "check-state");

      await wrapper.get("[data-testid=check-state]").trigger("click");
      await flushPromises();

      expect(document.activeElement).toBe(wrapper.get("[data-testid=safety-status]").element);
      expect(wrapper.get("[data-testid=safety-status]").text()).toBe("Odświeżamy…");
      answer.release({ data: { id: "d1", version: 4, name: "Stara" } });
      await flushPromises();
      expect(document.activeElement).toBe(wrapper.get("[data-testid=unknown-result]").element);
    });

    it("„Sprawdź stan”: on the message of a read that failed", async () => {
      const safety = create();
      const wrapper = await show(safety);
      await unknownResult(safety);
      read.mockRejectedValue(new TypeError("network down"));
      focusOn(wrapper, "check-state");

      await wrapper.get("[data-testid=check-state]").trigger("click");
      await flushPromises();

      expect(document.activeElement).toBe(wrapper.get("[data-testid=read-failed]").element);
    });

    it("„Sprawdź stan”: on the status region after a match, which says so", async () => {
      const safety = create();
      const wrapper = await show(safety);
      await unknownResult(safety);
      read.mockResolvedValue({ data: { id: "d1", version: 5, name: "Pierwsza" } });
      focusOn(wrapper, "check-state");

      await wrapper.get("[data-testid=check-state]").trigger("click");
      await flushPromises();

      expect(document.activeElement).toBe(wrapper.get("[data-testid=safety-status]").element);
      expect(wrapper.get("[data-testid=safety-status]").text()).toBe("Stan na serwerze odpowiada formularzowi.");
    });

    it("„Odblokuj formularz”: on the message that replaces it", async () => {
      state.id = null;
      const safety = create();
      const wrapper = await show(safety);
      void safety.write(() => new Promise(() => {}), { onSuccess: vi.fn(), onRefused: vi.fn() });
      await vi.advanceTimersByTimeAsync(REQUEST_LIMIT_MS);
      await flushPromises();
      focusOn(wrapper, "unblock-form");

      await wrapper.get("[data-testid=unblock-form]").trigger("click");
      await flushPromises();

      expect(document.activeElement).toBe(wrapper.get("[data-testid=unknown-result]").element);
    });

    it("„Odrzuć moje zmiany…”: on the status region while it reads, and `restored` after the answer 200", async () => {
      const safety = create();
      const wrapper = await show(safety);
      safety.offerRestore("Na serwerze jest inna wersja niż ta, którą edytujesz.");
      await flushPromises();
      const answer = held();
      read.mockReturnValue(answer.promise);
      focusOn(wrapper, "restore-state");

      await wrapper.get("[data-testid=restore-state]").trigger("click");
      await flushPromises();

      expect(document.activeElement).toBe(wrapper.get("[data-testid=safety-status]").element);
      expect(wrapper.emitted("restored")).toBeUndefined();
      answer.release({ data: { id: "d1", version: 6, name: "Z serwera" } });
      await flushPromises();
      expect(wrapper.emitted("restored")).toHaveLength(1);
    });

    it("„Odrzuć moje zmiany…”: on the message of a read that failed, and `restored` only after the retry succeeds", async () => {
      const safety = create();
      const wrapper = await show(safety);
      safety.offerRestore("Na serwerze jest inna wersja niż ta, którą edytujesz.");
      await flushPromises();
      read.mockRejectedValueOnce(new TypeError("network down"));
      focusOn(wrapper, "restore-state");
      await wrapper.get("[data-testid=restore-state]").trigger("click");
      await flushPromises();
      expect(document.activeElement).toBe(wrapper.get("[data-testid=read-failed]").element);
      expect(wrapper.emitted("restored")).toBeUndefined();
      read.mockResolvedValue({ data: { id: "d1", version: 6, name: "Z serwera" } });
      focusOn(wrapper, "retry-read");

      await wrapper.get("[data-testid=retry-read]").trigger("click");
      await flushPromises();

      expect(wrapper.emitted("restored")).toHaveLength(1);
      expect(wrapper.find("[data-testid=read-failed]").exists()).toBe(false);
    });

    it("„Spróbuj ponownie” after a failed „Sprawdź stan”: on the status region while it reads, then on what it found, without `restored`", async () => {
      const safety = create();
      const wrapper = await show(safety);
      await unknownResult(safety);
      read.mockRejectedValueOnce(new TypeError("network down"));
      await safety.check();
      await flushPromises();
      const answer = held();
      read.mockReturnValue(answer.promise);
      focusOn(wrapper, "retry-read");

      await wrapper.get("[data-testid=retry-read]").trigger("click");
      await flushPromises();
      expect(document.activeElement).toBe(wrapper.get("[data-testid=safety-status]").element);
      answer.release({ data: { id: "d1", version: 5, name: "Pierwsza" } });
      await flushPromises();

      expect(document.activeElement).toBe(wrapper.get("[data-testid=safety-status]").element);
      expect(wrapper.get("[data-testid=safety-status]").text()).toBe("Stan na serwerze odpowiada formularzowi.");
      expect(wrapper.emitted("restored")).toBeUndefined();
    });

    it("„Odrzuć moje zmiany…” whose answer comes after the form moved to another object: no `restored`", async () => {
      const safety = create();
      const wrapper = await show(safety);
      safety.offerRestore("Na serwerze jest inna wersja niż ta, którą edytujesz.");
      await flushPromises();
      const answer = held();
      read.mockReturnValue(answer.promise);
      await wrapper.get("[data-testid=restore-state]").trigger("click");
      await flushPromises();

      safety.reset();
      answer.release({ data: { id: "d1", version: 6, name: "Spóźniona" } });
      await flushPromises();

      expect(wrapper.emitted("restored")).toBeUndefined();
    });
  });

  it("puts the button of the server state inside the alert of a version that differs, once", async () => {
    const safety = create();
    const wrapper = await show(safety);
    await safety.write(() => failing() as never, { onSuccess: vi.fn(), onRefused: vi.fn() });
    read.mockResolvedValue({ data: { id: "d1", version: 9, name: "Cudza" } });

    await safety.check();
    await flushPromises();

    expect(wrapper.get("[data-testid=unknown-result]").text()).toContain("Na serwerze jest inna wersja niż ta, którą edytujesz.");
    expect(wrapper.get("[data-testid=unknown-result]").findAll("[data-testid=restore-state]")).toHaveLength(1);
    expect(wrapper.findAll("[data-testid=restore-state]")).toHaveLength(1);
  });
});
