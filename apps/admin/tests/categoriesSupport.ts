import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import { type StaffMe, useAuthStore } from "@/stores/auth";
import CategoriesView from "@/views/CategoriesView.vue";

type CategoryNode = components["schemas"]["CategoryNode"];

export const TYPE = "tag:piotrp321.github.io,2026:shop/problems/";

export function node(id: string, name: string, children: CategoryNode[] = [], extra: Partial<CategoryNode> = {}): CategoryNode {
  return { id, name, slug: name.toLowerCase().replace(/ /g, "-"), path: name.toLowerCase(), version: 1, productCount: { value: 2, capped: false }, children, ...extra };
}

/** Turystyka (v3) > (Biwak > (Namioty, Śpiwory), Plecaki); Sport; Outlet. Paths are those of the real API (from the root). */
export function demoTree(): CategoryNode[] {
  return [
    node("t", "Turystyka", [
      node("b", "Biwak", [node("n", "Namioty", [], { path: "turystyka/biwak/namioty", productCount: { value: 1000, capped: true } }), node("s", "Śpiwory", [], { path: "turystyka/biwak/spiwory", slug: "spiwory" })], { path: "turystyka/biwak" }),
      node("p", "Plecaki", [], { path: "turystyka/plecaki" }),
    ], { version: 3 }),
    node("sp", "Sport", [], { productCount: null }),
    node("o", "Outlet", [], { productCount: { value: 0, capped: false } }),
  ];
}

export const saved = (id: string, name: string, path: string, parentId: string | null = null) => ({
  data: { id, name, slug: path.split("/").pop(), path, parentId, version: 2 },
  response: { status: 200 },
});
export const problem = (status: number, body: object) => ({ data: undefined, error: body, response: { status } });
export const conflict = (type: string, detail = "Powód z API.") => problem(409, { type: `${TYPE}${type}`, title: "Conflict", status: 409, detail });

export async function mountView(roles: StaffMe["roles"] = ["admin"]) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().staff = { id: "u1", username: "anna", roles };
  const empty = { template: "<div />" };
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: "/categories", name: "categories", component: empty }, { path: "/", name: "dashboard", component: empty }] });
  await router.push("/categories");
  const wrapper = mount(CategoriesView, { attachTo: document.body, global: { plugins: [pinia, router] } });
  await flushPromises();
  return wrapper;
}

export const rowOf = (wrapper: VueWrapper, name: string) => wrapper.findAll("[data-testid=category-row]").find((row) => row.get("[data-testid=category-name]").text() === name)!;
export const names = (wrapper: VueWrapper) => wrapper.findAll("[data-testid=category-name]").map((element) => element.text());
export const dialog = (testid = "category-dialog") => document.body.querySelector<HTMLElement>(`[data-testid=${testid}]`);

export async function click(element: Element | null | undefined) {
  (element as HTMLElement).click();
  await flushPromises();
}

export async function fill(id: string, value: string) {
  const input = document.getElementById(id) as HTMLInputElement;
  input.value = value;
  input.dispatchEvent(new Event("input", { bubbles: true }));
  await flushPromises();
}
