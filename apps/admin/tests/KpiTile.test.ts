import { mount, type VueWrapper } from "@vue/test-utils";
import { Package } from "lucide-vue-next";
import { afterEach, describe, expect, it } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import KpiTile from "@/dashboard/KpiTile.vue";

let wrapper: VueWrapper | undefined;

afterEach(() => {
  wrapper?.unmount();
  wrapper = undefined;
});

async function mountTile(props: Partial<InstanceType<typeof KpiTile>["$props"]> = {}) {
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/", name: "dashboard", component: empty },
      { path: "/products", name: "products", component: empty },
    ],
  });
  await router.push("/");
  wrapper = mount(KpiTile, { props: { title: "Produkty", icon: Package, tone: "blue", value: "412", ...props }, global: { plugins: [router] } });
  return wrapper;
}

describe("KpiTile", () => {
  it("shows the title and the value", async () => {
    const tile = await mountTile();

    expect(tile.text()).toContain("Produkty");
    expect(tile.get("[data-testid=kpi-value]").text()).toBe("412");
  });

  it("is a link to the target, named by the title, when it has one", async () => {
    const tile = await mountTile({ to: { name: "products" } });

    const links = tile.findAll("a");
    expect(links).toHaveLength(1);
    expect(links[0]?.attributes("href")).toBe("/products");
    expect(links[0]?.text()).toBe("Produkty");
  });

  it("has no link without a target", async () => {
    const tile = await mountTile();

    expect(tile.find("a").exists()).toBe(false);
  });

  it("puts the icon in a circle of the soft colour of its tone, hidden from assistive technology", async () => {
    const tile = await mountTile({ tone: "violet" });

    const circle = tile.get("[data-testid=kpi-icon]");
    expect(circle.classes()).toEqual(expect.arrayContaining(["bg-kpi-violet-soft", "text-kpi-violet"]));
    expect(circle.get("svg").attributes("aria-hidden")).toBe("true");
  });

  it.each(["blue", "violet", "green", "teal", "amber"] as const)("has the dot of the footer in the %s tone", async (tone) => {
    const tile = await mountTile({ tone, footer: { label: "Bez zdjęć", value: "8" } });

    expect(tile.get("[data-testid=kpi-dot]").classes()).toContain(`bg-kpi-${tone}`);
  });

  it("shows the footer with its label and value", async () => {
    const tile = await mountTile({ footer: { label: "Bez zdjęć", value: "8" } });

    const footer = tile.get("[data-testid=kpi-footer]");
    expect(footer.text()).toContain("Bez zdjęć");
    expect(footer.text()).toContain("8");
  });

  it("shows no footer without one, and a footer with a label only", async () => {
    const tile = await mountTile();
    expect(tile.find("[data-testid=kpi-footer]").exists()).toBe(false);
    await tile.setProps({ footer: { label: "Uzupełnij zdjęcia w formularzu produktu" } });
    expect(tile.get("[data-testid=kpi-footer]").text()).toBe("Uzupełnij zdjęcia w formularzu produktu");
  });
});
