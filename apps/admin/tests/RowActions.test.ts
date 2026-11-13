import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { Image, Pencil } from "lucide-vue-next";
import { afterEach, describe, expect, it } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import RowActions, { type RowAction } from "@/components/list/RowActions.vue";

let wrapper: VueWrapper | undefined;

afterEach(() => {
  wrapper?.unmount();
  wrapper = undefined;
  document.body.innerHTML = "";
});

async function mountActions(actions: RowAction[]) {
  const empty = { template: "<div />" };
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: "/products/:id", name: "product", component: empty }, { path: "/", component: empty }] });
  await router.push("/");
  wrapper = mount(RowActions, { props: { name: "Buty trailowe Norvane Grań 300", actions }, global: { plugins: [router] }, attachTo: document.body });
  return { wrapper, router };
}

const edit = { key: "edit", label: "Edytuj", icon: Pencil, to: { name: "product", params: { id: "p1" } } };
const preview = { key: "preview", label: "Podgląd w sklepie", icon: Image, href: "http://store.test/p/buty", external: true };

describe("RowActions", () => {
  it("names each action with the name of the object", async () => {
    await mountActions([edit]);

    const link = document.body.querySelector("a")!;
    expect(link.getAttribute("aria-label")).toBe("Edytuj: Buty trailowe Norvane Grań 300");
    expect(link.getAttribute("href")).toBe("/products/p1");
    expect(link.querySelector("svg")!.getAttribute("aria-hidden")).toBe("true");
  });

  it("navigates in the router and leaves a click with a modifier to the browser", async () => {
    const { router } = await mountActions([edit]);
    const link = document.body.querySelector("a")!;

    link.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true, ctrlKey: true }));
    await flushPromises();
    expect(router.currentRoute.value.path).toBe("/");

    link.click();
    await flushPromises();
    expect(router.currentRoute.value.path).toBe("/products/p1");
  });

  it("opens an external action in a new tab, safely, and says so in its name", async () => {
    await mountActions([preview]);

    const link = document.body.querySelector("a")!;
    expect(link.getAttribute("href")).toBe("http://store.test/p/buty");
    expect(link.getAttribute("target")).toBe("_blank");
    expect(link.getAttribute("rel")).toBe("noopener");
    expect(link.getAttribute("aria-label")).toBe("Podgląd w sklepie: Buty trailowe Norvane Grań 300 (otwiera się w nowej karcie)");
  });

  it("is a square button of 34 px", async () => {
    await mountActions([edit]);

    expect(document.body.querySelector("a")!.className).toContain("size-8.5");
  });

  it("shows the name of the action as a tooltip on focus", async () => {
    await mountActions([edit]);

    document.body.querySelector("a")!.focus();
    await flushPromises();

    const tooltip = document.body.querySelector("[role=tooltip]");
    expect(tooltip?.textContent).toContain("Edytuj: Buty trailowe Norvane Grań 300");
  });

  it("renders nothing for an empty list of actions", async () => {
    await mountActions([]);

    expect(document.body.querySelector("a")).toBeNull();
  });
});
