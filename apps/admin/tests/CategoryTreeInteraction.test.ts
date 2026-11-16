import { flushPromises, type VueWrapper } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { toast } from "vue-sonner";
import { conflict, demoTree, mountView, names, rowOf, saved } from "./categoriesSupport";

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), del: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, POST: mocks.post, PUT: mocks.put, DELETE: mocks.del }) }));

const MOVE = "/api/v1/admin/categories/{id}/move";

let wrapper: VueWrapper | undefined;

beforeEach(() => {
  for (const mock of Object.values(mocks)) mock.mockReset();
  mocks.get.mockImplementation(async () => ({ data: demoTree(), response: { status: 200 } }));
  vi.spyOn(toast, "success").mockImplementation(() => 1);
});

afterEach(() => {
  wrapper?.unmount();
  wrapper = undefined;
  document.body.innerHTML = "";
  vi.restoreAllMocks();
});

/** A drag event of jsdom, which has no DragEvent: the parts the tree reads (`dataTransfer`, `clientY`). */
function drag(type: string, target: Element, clientY = 0) {
  const event = new Event(type, { bubbles: true, cancelable: true });
  Object.assign(event, { dataTransfer: { setData: vi.fn(), effectAllowed: "", dropEffect: "" }, clientY });
  target.dispatchEvent(event);
  return event;
}

/** jsdom has no layout: every row is 100 px high from the top, so `clientY` is the percent of the row. */
function layOut(row: Element) {
  vi.spyOn(row, "getBoundingClientRect").mockReturnValue({ top: 0, height: 100, bottom: 100, left: 0, right: 100, width: 100, x: 0, y: 0, toJSON: () => ({}) });
}

async function dragTo(source: string, target: string, clientY: number) {
  const from = rowOf(wrapper!, source).element;
  const to = rowOf(wrapper!, target).element;
  layOut(to);
  drag("dragstart", from);
  const over = drag("dragover", to, clientY);
  await flushPromises();
  const zone = to.getAttribute("data-drop");
  drag("drop", to, clientY);
  await flushPromises();
  return { allowed: over.defaultPrevented, zone };
}

describe("CategoryTree: dragging as a shortcut for the menu", () => {
  it("drops a node before another and sends the command of „before”: the parent of the target and its place", async () => {
    wrapper = await mountView();
    mocks.post.mockResolvedValue(saved("n", "Namioty", "turystyka/namioty", "t"));

    const result = await dragTo("Namioty", "Plecaki", 10);

    expect(result).toEqual({ allowed: true, zone: "before" });
    expect(mocks.post).toHaveBeenCalledWith(MOVE, { params: { path: { id: "n" } }, body: { parentId: "t", position: 1, version: 1 } });
  });

  it("drops a node after another", async () => {
    wrapper = await mountView();
    mocks.post.mockResolvedValue(saved("o", "Outlet", "outlet"));

    const result = await dragTo("Outlet", "Turystyka", 90);

    expect(result.zone).toBe("after");
    expect(mocks.post).toHaveBeenCalledWith(MOVE, { params: { path: { id: "o" } }, body: { parentId: null, position: 1, version: 1 } });
  });

  it("drops a node inside another as its last child, and opens the node", async () => {
    wrapper = await mountView();
    mocks.post.mockResolvedValue(saved("n", "Namioty", "sport/namioty", "sp"));

    const result = await dragTo("Namioty", "Sport", 50);

    expect(result.zone).toBe("inside");
    expect(mocks.post).toHaveBeenCalledWith(MOVE, { params: { path: { id: "n" } }, body: { parentId: "sp", position: 0, version: 1 } });
  });

  it("does not allow a drop onto the node itself or into its own subtree, and sends nothing", async () => {
    wrapper = await mountView();

    const onSubtree = await dragTo("Biwak", "Namioty", 50);
    const onItself = await dragTo("Biwak", "Biwak", 50);

    expect(onSubtree).toEqual({ allowed: false, zone: null });
    expect(onItself).toEqual({ allowed: false, zone: null });
    expect(mocks.post).not.toHaveBeenCalled();
  });

  it("does not allow a drop that changes nothing", async () => {
    wrapper = await mountView();

    const result = await dragTo("Namioty", "Śpiwory", 10);

    expect(result.allowed).toBe(false);
    expect(mocks.post).not.toHaveBeenCalled();
  });

  it("marks the zone while the node is over and clears the mark when the drag ends", async () => {
    wrapper = await mountView();
    const from = rowOf(wrapper, "Namioty").element;
    const to = rowOf(wrapper, "Sport").element;
    layOut(to);

    drag("dragstart", from);
    drag("dragover", to, 50);
    await flushPromises();
    expect(to.getAttribute("data-drop")).toBe("inside");
    drag("dragend", from);
    await flushPromises();

    expect(to.hasAttribute("data-drop")).toBe(false);
  });

  it("says a refusal of a drop in the alert above the tree", async () => {
    wrapper = await mountView();
    mocks.post.mockResolvedValue(conflict("category-too-deep", "Drzewo kategorii może mieć najwyżej 4 poziomy."));

    await dragTo("Outlet", "Namioty", 50);

    expect(wrapper.get("[data-testid=change-error]").text()).toContain("Drzewo kategorii może mieć najwyżej 4 poziomy.");
  });
});

describe("CategoryTree: the keyboard", () => {
  const key = async (target: Element, name: string) => {
    target.dispatchEvent(new KeyboardEvent("keydown", { key: name, bubbles: true, cancelable: true }));
    await flushPromises();
  };

  it("opens and closes a node with the arrows", async () => {
    wrapper = await mountView();
    const turystyka = rowOf(wrapper, "Turystyka").element as HTMLElement;
    expect(turystyka.getAttribute("aria-expanded")).toBe("true");
    turystyka.focus();

    await key(turystyka, "ArrowLeft");
    expect(turystyka.getAttribute("aria-expanded")).toBe("false");
    expect(names(wrapper)).toEqual(["Turystyka", "Sport", "Outlet"]);

    await key(turystyka, "ArrowRight");
    expect(turystyka.getAttribute("aria-expanded")).toBe("true");
    expect(names(wrapper)).toContain("Biwak");
  });

  it("keeps the node a leaf has without aria-expanded", async () => {
    wrapper = await mountView();

    expect(rowOf(wrapper, "Outlet").attributes("aria-expanded")).toBeUndefined();
  });

  it("names a treeitem by the category, its slug and its count, not by its buttons", async () => {
    wrapper = await mountView();

    expect(rowOf(wrapper, "Śpiwory").attributes("aria-label")).toBe("Śpiwory, slug spiwory, produkty: 2");
  });
});
