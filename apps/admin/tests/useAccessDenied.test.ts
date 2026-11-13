import { mount } from "@vue/test-utils";
import { afterEach, describe, expect, it } from "vitest";
import { defineComponent, h } from "vue";
import { useAccessDenied } from "@/layout/useAccessDenied";

const Probe = defineComponent({
  setup() {
    const denied = useAccessDenied();
    return () => h("p", { "data-denied": String(denied.value) });
  },
});

describe("useAccessDenied", () => {
  afterEach(() => window.history.replaceState(null, ""));

  it("is true when the history entry was marked by the route guard, and keeps the other state of the entry", () => {
    window.history.replaceState({ position: 3, denied: true }, "");

    const wrapper = mount(Probe);

    expect(wrapper.get("p").attributes("data-denied")).toBe("true");
    expect(window.history.state.position).toBe(3);
    wrapper.unmount();
  });

  it("is false once the entry was shown: a reload keeps history.state, so the mark must not stay", () => {
    window.history.replaceState({ position: 3, denied: true }, "");
    mount(Probe).unmount();

    expect(window.history.state.denied).toBe(false);
    const reloaded = mount(Probe);

    expect(reloaded.get("p").attributes("data-denied")).toBe("false");
    reloaded.unmount();
  });

  it.each([[null], [{}], [{ denied: "yes" }]])("is false for the state %j", (state) => {
    window.history.replaceState(state, "");

    const wrapper = mount(Probe);

    expect(wrapper.get("p").attributes("data-denied")).toBe("false");
    wrapper.unmount();
  });
});
