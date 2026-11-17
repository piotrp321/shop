import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";
import type { components } from "@/api/schema";
import { useCategoryScreen } from "@/categories/useCategoryScreen";

type CategoryNode = components["schemas"]["CategoryNode"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), del: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, POST: mocks.post, PUT: mocks.put, DELETE: mocks.del }) }));

const CATEGORIES = "/api/v1/admin/categories";
const CATEGORY = "/api/v1/admin/categories/{id}";
const MOVE = "/api/v1/admin/categories/{id}/move";
const TYPE = "tag:piotrp321.github.io,2026:shop/problems/";

function node(id: string, name: string, children: CategoryNode[] = [], version = 1, path = name.toLowerCase()): CategoryNode {
  return { id, name, slug: name.toLowerCase(), path, version, productCount: { value: 2, capped: false }, children };
}

// Turystyka (v3) > (Biwak, Plecaki); Sport.
const tree = (): CategoryNode[] => [node("t", "Turystyka", [node("b", "Biwak", [], 1, "turystyka/biwak"), node("p", "Plecaki", [], 1, "turystyka/plecaki")], 3), node("s", "Sport")];

function saved(id: string, name: string, path: string, parentId: string | null = null, version = 2) {
  return { data: { id, name, slug: path.split("/").pop(), path, parentId, version }, response: { status: 200 } };
}
const problem = (status: number, body: object) => ({ data: undefined, error: body, response: { status } });
const conflict = (type: string, detail = "Powód z API.") => problem(409, { type: `${TYPE}${type}`, title: "Conflict", status: 409, detail });

function mountScreen() {
  let screen!: ReturnType<typeof useCategoryScreen>;
  const wrapper = mount(
    defineComponent({
      setup() {
        screen = useCategoryScreen();
        return () => null;
      },
    }),
  );
  return { screen, wrapper };
}

async function loaded() {
  const mounted = mountScreen();
  await flushPromises();
  return mounted.screen;
}

beforeEach(() => {
  for (const mock of Object.values(mocks)) mock.mockReset();
  mocks.get.mockImplementation(async () => ({ data: tree(), response: { status: 200 } }));
});

describe("useCategoryScreen: loading", () => {
  it("loads the tree with the counts on mount", async () => {
    const screen = await loaded();

    expect(mocks.get).toHaveBeenCalledWith(CATEGORIES, { params: { query: { withCounts: "true" } } });
    expect(screen.rows.value.map((row) => row.id)).toEqual(["t", "s"]);
    expect(screen.rows.value[0]!.children.map((row) => row.id)).toEqual(["b", "p"]);
    expect(screen.loaded.value).toBe(true);
    expect(screen.loading.value).toBe(false);
    expect(screen.error.value).toBeNull();
  });

  it("is loading until the answer comes", async () => {
    let answer!: (value: unknown) => void;
    mocks.get.mockImplementation(() => new Promise((resolve) => (answer = resolve)));
    const { screen } = mountScreen();

    expect(screen.loading.value).toBe(true);
    expect(screen.loaded.value).toBe(false);
    answer({ data: tree(), response: { status: 200 } });
    await flushPromises();
    expect(screen.loading.value).toBe(false);
    expect(screen.loaded.value).toBe(true);
  });

  it("says the HTTP status when the API refuses, and a network failure in other words; both can be retried", async () => {
    mocks.get.mockResolvedValue({ data: undefined, response: { status: 500 } });
    const screen = await loaded();
    expect(screen.error.value).toBe("Nie udało się pobrać kategorii (HTTP 500).");
    expect(screen.loaded.value).toBe(false);

    mocks.get.mockRejectedValue(new Error("offline"));
    await screen.load();
    expect(screen.error.value).toBe("Nie udało się pobrać kategorii. Sprawdź połączenie z API.");

    mocks.get.mockImplementation(async () => ({ data: tree(), response: { status: 200 } }));
    await screen.load();
    expect(screen.error.value).toBeNull();
    expect(screen.loaded.value).toBe(true);
    expect(screen.rows.value).toHaveLength(2);
  });

  it("keeps the tree it has when a later reload fails", async () => {
    const screen = await loaded();
    mocks.get.mockResolvedValue({ data: undefined, response: { status: 503 } });

    await screen.load();

    expect(screen.rows.value).toHaveLength(2);
    expect(screen.error.value).toBe("Nie udało się pobrać kategorii (HTTP 503).");
  });

  it("takes only the answer of the latest request", async () => {
    const answers: ((value: unknown) => void)[] = [];
    mocks.get.mockImplementation(() => new Promise((resolve) => answers.push(resolve)));
    const { screen } = mountScreen();
    const second = screen.load();

    answers[1]!({ data: [node("new", "Nowa")], response: { status: 200 } });
    await second;
    answers[0]!({ data: tree(), response: { status: 200 } });
    await flushPromises();

    expect(screen.rows.value.map((row) => row.id)).toEqual(["new"]);
  });
});

describe("useCategoryScreen: create", () => {
  it("creates a root with the parent null and reloads the tree", async () => {
    const screen = await loaded();
    mocks.post.mockResolvedValue(saved("n", "Outlet", "outlet", null, 1));

    const outcome = await screen.create({ name: "Outlet", slug: "outlet", parentId: null });

    expect(mocks.post).toHaveBeenCalledWith(CATEGORIES, { body: { name: "Outlet", slug: "outlet", parentId: null } });
    expect(outcome).toEqual({ status: "saved", message: "Dodano kategorię: Outlet", created: { id: "n", parentId: null } });
    expect(mocks.get).toHaveBeenCalledTimes(2);
  });

  it("creates a subcategory under its parent", async () => {
    const screen = await loaded();
    mocks.post.mockResolvedValue(saved("n", "Namioty", "turystyka/namioty", "t", 1));

    await screen.create({ name: "Namioty", slug: "namioty", parentId: "t" });

    expect(mocks.post).toHaveBeenCalledWith(CATEGORIES, { body: { name: "Namioty", slug: "namioty", parentId: "t" } });
  });

  it("gives a taken slug and the other refusals back without reloading", async () => {
    const screen = await loaded();
    mocks.post.mockResolvedValue(conflict("category-slug-taken", "Adres zajęty."));

    expect(await screen.create({ name: "Outlet", slug: "sport", parentId: null })).toEqual({ status: "problem", problem: { kind: "slug-taken", message: "Adres zajęty." } });
    mocks.post.mockResolvedValue(conflict("category-too-deep", "Za głęboko."));
    expect(await screen.create({ name: "X", slug: "x", parentId: "b" })).toEqual({ status: "problem", problem: { kind: "blocked", message: "Za głęboko." } });
    mocks.post.mockResolvedValue(problem(422, { violations: [{ propertyPath: "name", title: "Nazwa nie może być pusta." }] }));
    expect(await screen.create({ name: " ", slug: "x", parentId: null })).toMatchObject({ status: "problem", problem: { kind: "invalid", fields: { name: ["Nazwa nie może być pusta."] } } });
    expect(mocks.get).toHaveBeenCalledTimes(1);
  });
});

describe("useCategoryScreen: revise", () => {
  it("sends the name, slug and the version of the row, and says the old address redirects when the path changed", async () => {
    const screen = await loaded();
    mocks.put.mockResolvedValue(saved("t", "Turystyka górska", "turystyka-gorska"));

    const outcome = await screen.revise("t", { name: "Turystyka górska", slug: "turystyka-gorska" });

    expect(mocks.put).toHaveBeenCalledWith(CATEGORY, { params: { path: { id: "t" } }, body: { name: "Turystyka górska", slug: "turystyka-gorska", version: 3 } });
    expect(outcome).toEqual({ status: "saved", message: "Zapisano kategorię: Turystyka górska. Stary adres przekierowuje na nowy." });
    expect(mocks.get).toHaveBeenCalledTimes(2);
  });

  it("does not mention a redirect when only the name changed", async () => {
    const screen = await loaded();
    mocks.put.mockResolvedValue(saved("t", "Turystyka i biwak", "turystyka"));

    expect(await screen.revise("t", { name: "Turystyka i biwak", slug: "turystyka" })).toEqual({ status: "saved", message: "Zapisano kategorię: Turystyka i biwak" });
  });

  it("reloads the tree after a version conflict and after a category that is gone", async () => {
    const screen = await loaded();
    mocks.put.mockResolvedValue(conflict("category-modified"));

    expect(await screen.revise("t", { name: "A", slug: "a" })).toEqual({
      status: "problem",
      problem: { kind: "modified", message: "Kategoria została w międzyczasie zmieniona – odśwież" },
    });
    expect(mocks.get).toHaveBeenCalledTimes(2);

    mocks.put.mockResolvedValue(problem(404, { detail: "Kategoria nie istnieje." }));
    expect(await screen.revise("t", { name: "A", slug: "a" })).toMatchObject({ status: "problem", problem: { kind: "missing" } });
    expect(mocks.get).toHaveBeenCalledTimes(3);
  });

  it("does not send a request for a category the tree no longer has", async () => {
    const screen = await loaded();

    expect(await screen.revise("gone", { name: "A", slug: "a" })).toMatchObject({ status: "problem", problem: { kind: "missing" } });
    expect(mocks.put).not.toHaveBeenCalled();
  });
});

describe("useCategoryScreen: move", () => {
  it("sends the parent, the position and the version; a new parent that changes the address says the old one redirects", async () => {
    const screen = await loaded();
    mocks.post.mockResolvedValue(saved("b", "Biwak", "sport/biwak", "s"));

    const outcome = await screen.move({ id: "b", parentId: "s", position: 0 });

    expect(mocks.post).toHaveBeenCalledWith(MOVE, { params: { path: { id: "b" } }, body: { parentId: "s", position: 0, version: 1 } });
    expect(outcome).toEqual({ status: "saved", message: "Przeniesiono kategorię: Biwak. Stary adres przekierowuje na nowy.", moved: { id: "b", parentId: "s" } });
  });

  it("says a new order without a note about addresses", async () => {
    const screen = await loaded();
    mocks.post.mockResolvedValue(saved("p", "Plecaki", "turystyka/plecaki", "t"));

    const outcome = await screen.move({ id: "p", parentId: "t", position: 0 });

    expect(outcome).toEqual({ status: "saved", message: "Zmieniono kolejność: Plecaki", moved: { id: "p", parentId: "t" } });
  });

  it("gives a cycle or a depth refusal back as it is, and reloads after a version conflict", async () => {
    const screen = await loaded();
    mocks.post.mockResolvedValue(conflict("category-cycle", "Nie do własnego poddrzewa."));
    expect(await screen.move({ id: "t", parentId: "b", position: 0 })).toEqual({ status: "problem", problem: { kind: "blocked", message: "Nie do własnego poddrzewa." } });
    expect(mocks.get).toHaveBeenCalledTimes(1);

    mocks.post.mockResolvedValue(conflict("category-modified"));
    expect(await screen.move({ id: "t", parentId: null, position: 1 })).toMatchObject({ status: "problem", problem: { kind: "modified" } });
    expect(mocks.get).toHaveBeenCalledTimes(2);
  });
});

describe("useCategoryScreen: remove", () => {
  it("deletes with the version of the row and reloads the tree", async () => {
    const screen = await loaded();
    mocks.del.mockResolvedValue({ data: undefined, response: { status: 204 } });

    const outcome = await screen.remove("t");

    expect(mocks.del).toHaveBeenCalledWith(CATEGORY, { params: { path: { id: "t" }, query: { version: 3 } } });
    expect(outcome).toEqual({ status: "saved", message: "Usunięto kategorię: Turystyka" });
    expect(mocks.get).toHaveBeenCalledTimes(2);
  });

  it("gives the not-empty refusal with the detail of the API and leaves the tree as it is", async () => {
    const screen = await loaded();
    mocks.del.mockResolvedValue(conflict("category-not-empty", "Przenieś produkty do innej kategorii, zanim usuniesz tę."));

    expect(await screen.remove("t")).toEqual({
      status: "problem",
      problem: { kind: "blocked", message: "Przenieś produkty do innej kategorii, zanim usuniesz tę." },
    });
    expect(mocks.get).toHaveBeenCalledTimes(1);
  });
});

describe("useCategoryScreen: failures and concurrency", () => {
  it("says a network failure in Polish instead of throwing", async () => {
    const screen = await loaded();
    mocks.put.mockRejectedValue(new Error("offline"));

    expect(await screen.revise("t", { name: "A", slug: "a" })).toEqual({
      status: "problem",
      problem: { kind: "failed", message: "Nie udało się zapisać zmiany kategorii. Sprawdź połączenie z API." },
    });
    expect(screen.busy.value).toBe(false);
  });

  it("does not start a second change while one is running", async () => {
    const screen = await loaded();
    let answer!: (value: unknown) => void;
    mocks.put.mockImplementation(() => new Promise((resolve) => (answer = resolve)));

    const first = screen.revise("t", { name: "A", slug: "a" });
    expect(screen.busy.value).toBe(true);
    const second = await screen.move({ id: "p", parentId: "t", position: 0 });
    answer(saved("t", "A", "a"));
    await first;

    expect(second).toEqual({ status: "problem", problem: { kind: "failed", message: "Poprzednia zmiana jeszcze trwa. Spróbuj za chwilę." } });
    expect(mocks.post).not.toHaveBeenCalled();
    expect(screen.busy.value).toBe(false);
  });
});
