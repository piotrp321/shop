import { mount, type VueWrapper } from "@vue/test-utils";
import { afterEach, describe, expect, it } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import PageHeader from "@/components/PageHeader.vue";

let wrapper: VueWrapper | undefined;

afterEach(() => {
  wrapper?.unmount();
  wrapper = undefined;
});

async function mountHeader(props: InstanceType<typeof PageHeader>["$props"]) {
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/", name: "dashboard", component: empty },
      { path: "/products", name: "products", component: empty },
    ],
  });
  await router.push("/products");
  wrapper = mount(PageHeader, { props, global: { plugins: [router] }, attachTo: document.body });
  return wrapper;
}

describe("PageHeader", () => {
  it("has the title as the only H1", async () => {
    const header = await mountHeader({ title: "Buty trailowe Norvane Grań 300", crumbs: [{ label: "Produkty", to: { name: "products" } }, { label: "Buty trailowe Norvane Grań 300" }] });

    expect(header.findAll("h1").map((heading) => heading.text())).toEqual(["Buty trailowe Norvane Grań 300"]);
  });

  it("has the crumbs Okruszki starting with a link to Pulpit and ending with the current page without a link", async () => {
    const header = await mountHeader({
      title: "Buty trailowe Norvane Grań 300",
      crumbs: [{ label: "Katalog" }, { label: "Produkty", to: { name: "products" } }, { label: "Buty trailowe Norvane Grań 300" }],
    });

    const nav = header.get("nav");
    expect(nav.attributes("aria-label")).toBe("Okruszki");
    const items = nav.findAll("li:not([role=presentation])");
    expect(items.map((item) => item.text())).toEqual(["Pulpit", "Katalog", "Produkty", "Buty trailowe Norvane Grań 300"]);
    expect(nav.findAll("a").map((link) => [link.text(), link.attributes("href")])).toEqual([
      ["Pulpit", "/"],
      ["Produkty", "/products"],
    ]);
    expect(nav.findAll("[aria-current]").map((item) => `${item.text()}|${item.attributes("aria-current")}`)).toEqual(["Buty trailowe Norvane Grań 300|page"]);
  });

  it("marks the only crumb after Pulpit as the current page", async () => {
    const header = await mountHeader({ title: "Ceny", crumbs: [{ label: "Ceny" }] });

    expect(header.findAll("a").map((link) => link.text())).toEqual(["Pulpit"]);
    expect(header.get("[aria-current=page]").text()).toBe("Ceny");
  });

  it("gives the focus to the H1 on focus(), for the end of an action that removed the button", async () => {
    const header = await mountHeader({ title: "Nowy produkt", crumbs: [{ label: "Nowy produkt" }] });

    (header.vm as unknown as { focus(): void }).focus();

    expect(document.activeElement).toBe(header.get("h1").element);
    expect(header.get("h1").attributes("tabindex")).toBe("-1");
  });
});
