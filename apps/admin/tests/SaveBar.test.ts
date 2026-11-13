import { mount, type VueWrapper } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import SaveBar from "@/components/SaveBar.vue";

let wrapper: VueWrapper | undefined;

afterEach(() => {
  wrapper?.unmount();
  wrapper = undefined;
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  document.documentElement.style.scrollPaddingBottom = "";
});

function render(props: Record<string, unknown> = {}) {
  wrapper = mount(SaveBar, { props: { newLabel: "Nowy produkt", ...props }, attachTo: document.body });
  return wrapper;
}

const button = (name: string) => [...document.body.querySelectorAll("button")].find((candidate) => candidate.textContent?.trim() === name);

describe("SaveBar", () => {
  it("says „Niezapisane zmiany” with „Odrzuć” and „Zapisz” for an existing object", () => {
    render();

    expect(document.body.textContent).toContain("Niezapisane zmiany");
    expect(button("Odrzuć")).toBeDefined();
    expect(button("Zapisz")).toBeDefined();
    expect(button("Anuluj")).toBeUndefined();
  });

  it("says the label of a new object with „Anuluj” instead of „Odrzuć”", () => {
    render({ creating: true });

    expect(document.body.textContent).toContain("Nowy produkt");
    expect(document.body.textContent).not.toContain("Niezapisane zmiany");
    expect(button("Anuluj")).toBeDefined();
    expect(button("Odrzuć")).toBeUndefined();
  });

  it("emits save, discard and cancel", async () => {
    const bar = render();
    button("Zapisz")?.click();
    button("Odrzuć")?.click();
    await bar.setProps({ creating: true });
    button("Anuluj")?.click();

    expect(bar.emitted("save")).toHaveLength(1);
    expect(bar.emitted("discard")).toHaveLength(1);
    expect(bar.emitted("cancel")).toHaveLength(1);
  });

  it("disables „Zapisz” when saving is not possible and shows the request in progress", async () => {
    const bar = render({ saveDisabled: true });
    expect(button("Zapisz")?.disabled).toBe(true);

    await bar.setProps({ saveDisabled: false, busy: true });
    expect(button("Zapisz")?.getAttribute("aria-busy")).toBe("true");
    expect(button("Zapisz")?.disabled).toBe(true);
  });

  it("does not let „Odrzuć” run while saving is not possible (a request or an upload is running)", async () => {
    const bar = render({ saveDisabled: true });
    expect(button("Odrzuć")?.disabled).toBe(true);

    await bar.setProps({ saveDisabled: false });
    expect(button("Odrzuć")?.disabled).toBe(false);
  });

  describe("the room it takes at the bottom of the page", () => {
    beforeEach(() => {
      vi.spyOn(HTMLElement.prototype, "offsetHeight", "get").mockReturnValue(64);
    });

    it("sets scroll-padding-bottom of the page to its height, so a focused field is never under it", () => {
      render();

      expect(document.documentElement.style.scrollPaddingBottom).toBe("64px");
    });

    it("follows a change of its height", () => {
      let notify: () => void = () => {};
      vi.stubGlobal(
        "ResizeObserver",
        class {
          constructor(callback: () => void) {
            notify = callback;
          }
          observe() {}
          disconnect() {}
        },
      );
      render();
      vi.spyOn(HTMLElement.prototype, "offsetHeight", "get").mockReturnValue(96);

      notify();

      expect(document.documentElement.style.scrollPaddingBottom).toBe("96px");
    });

    it("gives the room back when it goes away", () => {
      const bar = render();
      bar.unmount();
      wrapper = undefined;

      expect(document.documentElement.style.scrollPaddingBottom).toBe("");
    });
  });
});
