import { flushPromises, type VueWrapper } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, type MockInstance, vi } from "vitest";
import { toast } from "vue-sonner";
import { click, conflict, demoTree, dialog, fill, mountView, names, node, problem, rowOf, saved } from "./categoriesSupport";

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), del: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, POST: mocks.post, PUT: mocks.put, DELETE: mocks.del }) }));

const CATEGORIES = "/api/v1/admin/categories";
const CATEGORY = "/api/v1/admin/categories/{id}";
const MOVE = "/api/v1/admin/categories/{id}/move";

let wrapper: VueWrapper | undefined;
let successToast: MockInstance<typeof toast.success>;

beforeEach(() => {
  for (const mock of Object.values(mocks)) mock.mockReset();
  mocks.get.mockImplementation(async () => ({ data: demoTree(), response: { status: 200 } }));
  successToast = vi.spyOn(toast, "success").mockImplementation(() => 1);
});

afterEach(() => {
  wrapper?.unmount();
  wrapper = undefined;
  document.body.innerHTML = "";
  vi.restoreAllMocks();
});

const actionsOf = (name: string) => rowOf(wrapper!, name).findAll("[data-testid^=action-]").map((button) => button.attributes("data-testid"));

describe("CategoriesView: the screen and its states", () => {
  it("has the H1 „Kategorie” and the crumbs „Pulpit › Sklep › Katalog › Kategorie”", async () => {
    wrapper = await mountView();

    expect(wrapper.get("h1").text()).toBe("Kategorie");
    const crumbs = wrapper.get("nav[aria-label=Okruszki]");
    expect(crumbs.text()).toMatch(/^Pulpit.*Sklep.*Katalog.*Kategorie$/);
    expect(crumbs.get("[aria-current=page]").text()).toBe("Kategorie");
    expect(mocks.get).toHaveBeenCalledWith(CATEGORIES, { params: { query: { withCounts: "true" } } });
  });

  it("is a tree named „Drzewo kategorii” with a treeitem per category, its level, name, slug and the count of products", async () => {
    wrapper = await mountView();

    const tree = wrapper.get("[role=tree]");
    expect(tree.attributes("aria-label")).toBe("Drzewo kategorii");
    expect(names(wrapper)).toEqual(["Turystyka", "Biwak", "Namioty", "Śpiwory", "Plecaki", "Sport", "Outlet"]);
    expect(wrapper.findAll("[role=treeitem]").map((item) => item.attributes("aria-level"))).toEqual(["1", "2", "3", "3", "2", "1", "1"]);
    expect(rowOf(wrapper, "Śpiwory").get("[data-testid=category-slug]").text()).toBe("spiwory");
    expect(rowOf(wrapper, "Turystyka").get("[data-testid=category-count]").text()).toBe("Produkty: 2");
    expect(rowOf(wrapper, "Namioty").get("[data-testid=category-count]").text()).toBe("Produkty: ponad 1000");
    expect(rowOf(wrapper, "Sport").get("[data-testid=category-count]").text()).toBe("Produkty: —");
    expect(rowOf(wrapper, "Outlet").get("[data-testid=category-count]").text()).toBe("Produkty: 0");
  });

  it("says it is loading until the tree is there", async () => {
    mocks.get.mockImplementation(() => new Promise(() => {}));
    wrapper = await mountView();

    expect(wrapper.get("[data-testid=categories-loading]").text()).toContain("Wczytywanie kategorii");
    expect(wrapper.find("[role=tree]").exists()).toBe(false);
  });

  it("says the tree is empty and offers the first category to those who may add one", async () => {
    mocks.get.mockImplementation(async () => ({ data: [], response: { status: 200 } }));
    wrapper = await mountView();

    expect(wrapper.get("[data-testid=empty]").text()).toContain("Nie ma jeszcze żadnych kategorii.");
    expect(wrapper.find("[data-testid=empty-add-category]").exists()).toBe(true);
    wrapper.unmount();
    wrapper = await mountView(["support"]);
    expect(wrapper.find("[data-testid=empty]").exists()).toBe(true);
    expect(wrapper.find("[data-testid=empty-add-category]").exists()).toBe(false);
  });

  it("says the load failed in an alert with „Spróbuj ponownie”, and loads again", async () => {
    mocks.get.mockImplementationOnce(async () => ({ data: undefined, response: { status: 500 } }));
    wrapper = await mountView();

    const alert = wrapper.get("[data-testid=categories-error]");
    expect(alert.attributes("role")).toBe("alert");
    expect(alert.text()).toContain("Nie udało się pobrać kategorii (HTTP 500).");
    await click(wrapper.get("[data-testid=retry]").element);
    expect(wrapper.find("[data-testid=categories-error]").exists()).toBe(false);
    expect(names(wrapper)).toContain("Turystyka");
  });
});

describe("CategoriesView: who may change the tree", () => {
  it.each([["admin"], ["marketing"]] as const)("gives %s „Dodaj kategorię” and the actions of a node", async (role) => {
    wrapper = await mountView([role]);

    expect(wrapper.find("[data-testid=add-category]").exists()).toBe(true);
    expect(actionsOf("Sport")).toEqual(["action-add", "action-edit", "action-move", "action-up", "action-down", "action-delete"]);
    expect(rowOf(wrapper, "Sport").get("[data-testid=action-edit]").attributes("aria-label")).toBe("Edytuj: Sport");
    expect(rowOf(wrapper, "Sport").get("[data-testid=action-move]").attributes("aria-label")).toBe("Przenieś do…: Sport");
    expect(rowOf(wrapper, "Sport").attributes("draggable")).toBe("true");
  });

  it("leaves out „W górę” for the first sibling and „W dół” for the last", async () => {
    wrapper = await mountView();

    expect(actionsOf("Turystyka")).toEqual(["action-add", "action-edit", "action-move", "action-down", "action-delete"]);
    expect(actionsOf("Outlet")).toEqual(["action-add", "action-edit", "action-move", "action-up", "action-delete"]);
    expect(actionsOf("Namioty")).toEqual(["action-add", "action-edit", "action-move", "action-down", "action-delete"]);
  });

  it.each([["support"], ["warehouse"]] as const)("shows %s the tree and counts, without „Dodaj kategorię”, actions or dragging", async (role) => {
    wrapper = await mountView([role]);

    expect(names(wrapper)).toHaveLength(7);
    expect(wrapper.find("[data-testid=add-category]").exists()).toBe(false);
    expect(wrapper.findAll("[data-testid^=action-]")).toHaveLength(0);
    expect(rowOf(wrapper, "Biwak").attributes("draggable")).not.toBe("true");
  });
});

describe("CategoriesView: add and edit", () => {
  it("adds a root: the window has Nazwa and Slug with the format hint; a save sends it, toasts, reloads and closes", async () => {
    wrapper = await mountView();
    await click(wrapper.get("[data-testid=add-category]").element);

    const window = dialog()!;
    expect(window.getAttribute("role")).toBe("dialog");
    expect(window.textContent).toContain("Dodaj kategorię");
    expect(document.querySelector("label[for=category-name-input]")?.textContent).toBe("Nazwa");
    expect(document.querySelector("label[for=category-slug-input]")?.textContent).toBe("Slug");
    expect(document.getElementById("category-slug-input")?.getAttribute("aria-describedby")).toBeTruthy();
    expect(document.activeElement?.id).toBe("category-name-input");
    mocks.post.mockResolvedValue(saved("new", "Rowery", "rowery"));
    await fill("category-name-input", "Rowery");
    await fill("category-slug-input", "rowery");
    await click(document.querySelector("[data-testid=save-category]"));

    expect(mocks.post).toHaveBeenCalledWith(CATEGORIES, { body: { name: "Rowery", slug: "rowery", parentId: null } });
    expect(successToast).toHaveBeenCalledWith("Dodano kategorię: Rowery");
    expect(mocks.get).toHaveBeenCalledTimes(2);
    expect(dialog()).toBeNull();
  });

  it("asks for both fields before it sends anything, and puts the focus in the first empty one", async () => {
    wrapper = await mountView();
    await click(wrapper.get("[data-testid=add-category]").element);
    await fill("category-name-input", "   ");

    await click(document.querySelector("[data-testid=save-category]"));

    expect(document.querySelector("[data-testid=name-error]")?.textContent).toBe("Podaj nazwę kategorii.");
    expect(document.querySelector("[data-testid=slug-error]")?.textContent).toBe("Podaj slug kategorii.");
    expect(document.activeElement?.id).toBe("category-name-input");
    expect(document.getElementById("category-name-input")?.getAttribute("aria-invalid")).toBe("true");
    expect(mocks.post).not.toHaveBeenCalled();
  });

  it("adds a subcategory under its node and opens the node", async () => {
    mocks.get.mockImplementation(async () => ({ data: demoTree(), response: { status: 200 } }));
    wrapper = await mountView();
    await click(rowOf(wrapper, "Sport").get("[data-testid=action-add]").element);
    expect(dialog()!.textContent).toContain("Dodaj podkategorię");
    expect(dialog()!.textContent).toContain("Sport");
    mocks.post.mockResolvedValue(saved("w", "Bieganie", "sport/bieganie", "sp"));
    mocks.get.mockImplementation(async () => ({ data: [...demoTree().slice(0, 1), node("sp", "Sport", [node("w", "Bieganie", [], { path: "sport/bieganie" })], { productCount: null }), ...demoTree().slice(2)], response: { status: 200 } }));
    await fill("category-name-input", "Bieganie");
    await fill("category-slug-input", "bieganie");
    await click(document.querySelector("[data-testid=save-category]"));

    expect(mocks.post).toHaveBeenCalledWith(CATEGORIES, { body: { name: "Bieganie", slug: "bieganie", parentId: "sp" } });
    expect(names(wrapper)).toContain("Bieganie");
    expect(rowOf(wrapper, "Sport").attributes("aria-expanded")).toBe("true");
  });

  it("edits a category: the window has its name and slug, a new slug says in the toast that the old address redirects", async () => {
    wrapper = await mountView();
    await click(rowOf(wrapper, "Plecaki").get("[data-testid=action-edit]").element);

    expect((document.getElementById("category-name-input") as HTMLInputElement).value).toBe("Plecaki");
    expect((document.getElementById("category-slug-input") as HTMLInputElement).value).toBe("plecaki");
    mocks.put.mockResolvedValue(saved("p", "Plecaki turystyczne", "turystyka/plecaki-turystyczne", "t"));
    await fill("category-name-input", "Plecaki turystyczne");
    await fill("category-slug-input", "plecaki-turystyczne");
    await click(document.querySelector("[data-testid=save-category]"));

    expect(mocks.put).toHaveBeenCalledWith(CATEGORY, { params: { path: { id: "p" } }, body: { name: "Plecaki turystyczne", slug: "plecaki-turystyczne", version: 1 } });
    expect(successToast).toHaveBeenCalledWith("Zapisano kategorię: Plecaki turystyczne. Stary adres przekierowuje na nowy.");
    expect(dialog()).toBeNull();
  });

  it("gives the focus back to the button that opened the window", async () => {
    wrapper = await mountView();
    const edit = rowOf(wrapper, "Plecaki").get("[data-testid=action-edit]").element as HTMLElement;
    edit.focus();
    await click(edit);
    mocks.put.mockResolvedValue(saved("p", "Plecaki", "turystyka/plecaki", "t"));

    await click(document.querySelector("[data-testid=save-category]"));
    await flushPromises();

    expect(document.activeElement).toBe(rowOf(wrapper, "Plecaki").get("[data-testid=action-edit]").element);
  });
});

describe("CategoriesView: a refusal of the API is said where it belongs", () => {
  async function openEdit() {
    wrapper = await mountView();
    await click(rowOf(wrapper, "Plecaki").get("[data-testid=action-edit]").element);
  }

  it("puts a taken slug at the Slug field, with the focus in it", async () => {
    await openEdit();
    mocks.put.mockResolvedValue(conflict("category-slug-taken", "Kategoria o tym adresie już istnieje na tym poziomie."));

    await click(document.querySelector("[data-testid=save-category]"));

    expect(document.querySelector("[data-testid=slug-error]")?.textContent).toBe("Kategoria o tym adresie już istnieje na tym poziomie.");
    expect(document.getElementById("category-slug-input")?.getAttribute("aria-invalid")).toBe("true");
    expect(document.activeElement?.id).toBe("category-slug-input");
    expect(dialog()).not.toBeNull();
    expect(successToast).not.toHaveBeenCalled();
  });

  it("puts the violations of 422 at their fields", async () => {
    await openEdit();
    mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "name", title: "Nazwa może mieć najwyżej 100 znaków." }] }));

    await click(document.querySelector("[data-testid=save-category]"));

    expect(document.querySelector("[data-testid=name-error]")?.textContent).toBe("Nazwa może mieć najwyżej 100 znaków.");
    expect(document.activeElement?.id).toBe("category-name-input");
  });

  it("says a version conflict in the window and reloads the tree", async () => {
    await openEdit();
    mocks.put.mockResolvedValue(conflict("category-modified"));

    await click(document.querySelector("[data-testid=save-category]"));

    const alert = document.querySelector("[data-testid=dialog-error]")!;
    expect(alert.textContent).toContain("Kategoria została w międzyczasie zmieniona – odśwież");
    expect(document.activeElement).toBe(alert);
    expect(mocks.get).toHaveBeenCalledTimes(2);
  });

  it("says a tree that would be too deep in the window", async () => {
    wrapper = await mountView();
    await click(rowOf(wrapper, "Namioty").get("[data-testid=action-add]").element);
    mocks.post.mockResolvedValue(conflict("category-too-deep", "Drzewo kategorii może mieć najwyżej 4 poziomy."));
    await fill("category-name-input", "Duże");
    await fill("category-slug-input", "duze");

    await click(document.querySelector("[data-testid=save-category]"));

    expect(document.querySelector("[data-testid=dialog-error]")?.textContent).toContain("Drzewo kategorii może mieć najwyżej 4 poziomy.");
    expect(mocks.get).toHaveBeenCalledTimes(1);
  });

  it("says a failed request in the window instead of throwing", async () => {
    await openEdit();
    mocks.put.mockRejectedValue(new Error("offline"));

    await click(document.querySelector("[data-testid=save-category]"));

    expect(document.querySelector("[data-testid=dialog-error]")?.textContent).toContain("Nie udało się zapisać zmiany kategorii. Sprawdź połączenie z API.");
  });
});

describe("CategoriesView: move to…", () => {
  const options = () => [...document.body.querySelectorAll<HTMLElement>("[role=option]")];
  const choose = async (label: string) => {
    document.querySelector<HTMLInputElement>("[role=combobox]")!.dispatchEvent(new MouseEvent("click", { bubbles: true }));
    await flushPromises();
    options().find((option) => option.getAttribute("aria-label") === label)!.click();
    await flushPromises();
  };

  it("offers the root and every category, and disables the category itself and its subtree", async () => {
    wrapper = await mountView();
    await click(rowOf(wrapper, "Biwak").get("[data-testid=action-move]").element);
    document.querySelector<HTMLInputElement>("[role=combobox]")!.dispatchEvent(new MouseEvent("click", { bubbles: true }));
    await flushPromises();

    expect(dialog("move-dialog")!.textContent).toContain("Obecnie: Turystyka");
    expect(options().map((option) => option.getAttribute("aria-label"))).toEqual([
      "Poziom główny",
      "Turystyka",
      "Turystyka › Biwak",
      "Turystyka › Biwak › Namioty",
      "Turystyka › Biwak › Śpiwory",
      "Turystyka › Plecaki",
      "Sport",
      "Outlet",
    ]);
    expect(options().filter((option) => option.getAttribute("aria-disabled") === "true").map((option) => option.getAttribute("aria-label"))).toEqual([
      "Turystyka › Biwak",
      "Turystyka › Biwak › Namioty",
      "Turystyka › Biwak › Śpiwory",
    ]);
  });

  it("moves under the chosen category, last, and tells that the old address redirects", async () => {
    wrapper = await mountView();
    await click(rowOf(wrapper, "Plecaki").get("[data-testid=action-move]").element);
    mocks.post.mockResolvedValue(saved("p", "Plecaki", "sport/plecaki", "sp"));

    await choose("Sport");
    await click(document.querySelector("[data-testid=move-category]"));

    expect(mocks.post).toHaveBeenCalledWith(MOVE, { params: { path: { id: "p" } }, body: { parentId: "sp", position: 0, version: 1 } });
    expect(successToast).toHaveBeenCalledWith("Przeniesiono kategorię: Plecaki. Stary adres przekierowuje na nowy.");
    expect(dialog("move-dialog")).toBeNull();
  });

  it("moves to the top level with the parent null", async () => {
    wrapper = await mountView();
    await click(rowOf(wrapper, "Plecaki").get("[data-testid=action-move]").element);
    mocks.post.mockResolvedValue(saved("p", "Plecaki", "plecaki"));

    await choose("Poziom główny");
    await click(document.querySelector("[data-testid=move-category]"));

    expect(mocks.post).toHaveBeenCalledWith(MOVE, { params: { path: { id: "p" } }, body: { parentId: null, position: 3, version: 1 } });
  });

  it("needs a chosen place before it moves", async () => {
    wrapper = await mountView();
    await click(rowOf(wrapper, "Plecaki").get("[data-testid=action-move]").element);

    expect((document.querySelector("[data-testid=move-category]") as HTMLButtonElement).disabled).toBe(true);
  });

  it("says in the window what the API refuses (a tree too deep) and keeps it open", async () => {
    wrapper = await mountView();
    await click(rowOf(wrapper, "Plecaki").get("[data-testid=action-move]").element);
    mocks.post.mockResolvedValue(conflict("category-too-deep", "Drzewo kategorii może mieć najwyżej 4 poziomy."));

    await choose("Turystyka › Biwak › Namioty");
    await click(document.querySelector("[data-testid=move-category]"));

    expect(dialog("move-dialog")!.querySelector("[data-testid=dialog-error]")?.textContent).toContain("Drzewo kategorii może mieć najwyżej 4 poziomy.");
  });
});

describe("CategoriesView: up and down", () => {
  it("moves one place up with the same commands as the menu, says it in a toast and keeps the focus on the button", async () => {
    wrapper = await mountView();
    const up = rowOf(wrapper, "Śpiwory").get("[data-testid=action-up]").element as HTMLElement;
    up.focus();
    mocks.post.mockResolvedValue(saved("s", "Śpiwory", "turystyka/biwak/spiwory", "b"));

    await click(up);

    expect(mocks.post).toHaveBeenCalledWith(MOVE, { params: { path: { id: "s" } }, body: { parentId: "b", position: 0, version: 1 } });
    expect(successToast).toHaveBeenCalledWith("Zmieniono kolejność: Śpiwory");
    expect(mocks.get).toHaveBeenCalledTimes(2);
    expect(document.activeElement?.getAttribute("data-testid")).toBe("action-up");
  });

  it("moves one place down", async () => {
    wrapper = await mountView();
    mocks.post.mockResolvedValue(saved("t", "Turystyka", "turystyka"));

    await click(rowOf(wrapper, "Turystyka").get("[data-testid=action-down]").element);

    expect(mocks.post).toHaveBeenCalledWith(MOVE, { params: { path: { id: "t" } }, body: { parentId: null, position: 1, version: 3 } });
  });

  it("says a refusal in an alert above the tree", async () => {
    wrapper = await mountView();
    mocks.post.mockResolvedValue(conflict("category-modified"));

    await click(rowOf(wrapper, "Turystyka").get("[data-testid=action-down]").element);

    const alert = wrapper.get("[data-testid=change-error]");
    expect(alert.attributes("role")).toBe("alert");
    expect(alert.text()).toContain("Kategoria została w międzyczasie zmieniona – odśwież");
    expect(mocks.get).toHaveBeenCalledTimes(2);
  });
});

describe("CategoriesView: delete", () => {
  async function openDelete(name: string) {
    wrapper = await mountView();
    const button = rowOf(wrapper, name).get("[data-testid=action-delete]").element as HTMLElement;
    button.focus();
    await click(button);
  }

  it("asks first, names the category and says what stays", async () => {
    await openDelete("Outlet");

    const confirm = document.querySelector("[role=alertdialog]")!;
    expect(confirm.textContent).toContain("Usunąć kategorię Outlet?");
    expect(mocks.del).not.toHaveBeenCalled();
  });

  it("deletes with the version, toasts, reloads and gives the focus to the H1 because the row is gone", async () => {
    await openDelete("Outlet");
    mocks.del.mockResolvedValue({ data: undefined, response: { status: 204 } });
    mocks.get.mockImplementation(async () => ({ data: demoTree().slice(0, 2), response: { status: 200 } }));

    const confirmButton = [...document.querySelectorAll<HTMLButtonElement>("[role=alertdialog] button")].find((button) => button.textContent?.trim() === "Usuń kategorię")!;
    await click(confirmButton);
    await flushPromises();

    expect(mocks.del).toHaveBeenCalledWith(CATEGORY, { params: { path: { id: "o" }, query: { version: 1 } } });
    expect(successToast).toHaveBeenCalledWith("Usunięto kategorię: Outlet");
    expect(document.querySelector("[role=alertdialog]")).toBeNull();
    expect(names(wrapper!)).not.toContain("Outlet");
    expect(document.activeElement).toBe(wrapper!.get("h1").element);
  });

  it("says in the dialog why a category that is not empty stays, and keeps the dialog open", async () => {
    await openDelete("Turystyka");
    mocks.del.mockResolvedValue(conflict("category-not-empty", "Przenieś produkty do innej kategorii, zanim usuniesz tę."));

    const confirmButton = [...document.querySelectorAll<HTMLButtonElement>("[role=alertdialog] button")].find((button) => button.textContent?.trim() === "Usuń kategorię")!;
    await click(confirmButton);

    const confirm = document.querySelector("[role=alertdialog]")!;
    expect(confirm.querySelector("[role=alert]")?.textContent).toContain("Przenieś produkty do innej kategorii, zanim usuniesz tę.");
    expect(successToast).not.toHaveBeenCalled();
  });
});
