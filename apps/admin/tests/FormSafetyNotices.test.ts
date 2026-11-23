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
    safety.offerRestore();
    await flushPromises();
    read.mockResolvedValue({ data: { id: "d1", version: 6, name: "Z serwera" } });

    await wrapper.get("[data-testid=restore-state]").trigger("click");
    await flushPromises();

    expect(wrapper.emitted("restored")).toHaveLength(1);
    expect(wrapper.find("[data-testid=restore-state]").exists()).toBe(false);
    expect(state.draft).toEqual({ name: "Z serwera" });
  });

  it("does not say it was restored when the read failed, and shows the message to try again with the focus on it", async () => {
    const safety = create();
    const wrapper = await show(safety);
    safety.offerRestore();
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
