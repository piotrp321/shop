import { describe, expect, it } from "vitest";
import type { components } from "@/api/schema";
import { comboboxNodes, dropCommand, findRow, formatProductCount, moveCommand, moveToCommand, placeOf, subtreeIds, toRows, zoneForRatio } from "@/categories/tree";

type CategoryNode = components["schemas"]["CategoryNode"];

function node(id: string, name: string, children: CategoryNode[] = [], productCount: CategoryNode["productCount"] = { value: 3, capped: false }): CategoryNode {
  return { id, name, slug: name.toLowerCase(), path: name.toLowerCase(), version: 1, productCount, children };
}

// Turystyka > (Biwak > (Namioty, Śpiwory), Plecaki); Sport > Bieganie; Outlet.
const api: CategoryNode[] = [
  node("t", "Turystyka", [node("b", "Biwak", [node("n", "Namioty"), node("s", "Śpiwory")]), node("p", "Plecaki")]),
  node("sp", "Sport", [node("r", "Bieganie")]),
  node("o", "Outlet", [], null),
];
const rows = toRows(api);

describe("toRows", () => {
  it("keeps the name, slug, path, version, count and the order of the siblings", () => {
    expect(rows.map((row) => row.id)).toEqual(["t", "sp", "o"]);
    expect(rows[0]).toMatchObject({ id: "t", name: "Turystyka", slug: "turystyka", path: "turystyka", version: 1, productCount: { value: 3, capped: false } });
    expect(rows[0]!.children.map((child) => child.id)).toEqual(["b", "p"]);
    expect(rows[2]!.productCount).toBeNull();
  });
});

describe("findRow", () => {
  it("gives the row with its parent, siblings and index at any level", () => {
    const found = findRow(rows, "s");
    expect(found?.row.id).toBe("s");
    expect(found?.parent?.id).toBe("b");
    expect(found?.siblings.map((row) => row.id)).toEqual(["n", "s"]);
    expect(found?.index).toBe(1);
  });

  it("gives a root with no parent and null for an unknown id", () => {
    expect(findRow(rows, "sp")?.parent).toBeNull();
    expect(findRow(rows, "sp")?.index).toBe(1);
    expect(findRow(rows, "nope")).toBeNull();
  });
});

describe("moveCommand (up and down among the siblings)", () => {
  it("moves one place up and down under the same parent", () => {
    expect(moveCommand(rows, "s", "up")).toEqual({ id: "s", parentId: "b", position: 0 });
    expect(moveCommand(rows, "n", "down")).toEqual({ id: "n", parentId: "b", position: 1 });
    expect(moveCommand(rows, "t", "down")).toEqual({ id: "t", parentId: null, position: 1 });
    expect(moveCommand(rows, "o", "up")).toEqual({ id: "o", parentId: null, position: 1 });
  });

  it("has no command at the edge of the siblings or for an unknown id", () => {
    expect(moveCommand(rows, "n", "up")).toBeNull();
    expect(moveCommand(rows, "s", "down")).toBeNull();
    expect(moveCommand(rows, "t", "up")).toBeNull();
    expect(moveCommand(rows, "o", "down")).toBeNull();
    expect(moveCommand(rows, "nope", "up")).toBeNull();
  });
});

describe("dropCommand (the same commands as the menu)", () => {
  it("drops before a node: its parent and the place of the node among the siblings without the dragged one", () => {
    expect(dropCommand(rows, "n", "p", "before")).toEqual({ id: "n", parentId: "t", position: 1 });
    expect(dropCommand(rows, "o", "t", "before")).toEqual({ id: "o", parentId: null, position: 0 });
    expect(dropCommand(rows, "s", "n", "before")).toEqual({ id: "s", parentId: "b", position: 0 });
  });

  it("drops after a node", () => {
    expect(dropCommand(rows, "n", "s", "after")).toEqual({ id: "n", parentId: "b", position: 1 });
    expect(dropCommand(rows, "o", "t", "after")).toEqual({ id: "o", parentId: null, position: 1 });
  });

  it("drops inside a node: it becomes the last child, a root becomes a child", () => {
    expect(dropCommand(rows, "n", "sp", "inside")).toEqual({ id: "n", parentId: "sp", position: 1 });
    expect(dropCommand(rows, "o", "r", "inside")).toEqual({ id: "o", parentId: "r", position: 0 });
    expect(dropCommand(rows, "n", "b", "inside")).toEqual({ id: "n", parentId: "b", position: 1 });
  });

  it("gives a subcategory a place among the roots", () => {
    expect(dropCommand(rows, "r", "o", "before")).toEqual({ id: "r", parentId: null, position: 2 });
  });

  it("has no command when nothing would change", () => {
    expect(dropCommand(rows, "s", "n", "after")).toBeNull();
    expect(dropCommand(rows, "n", "s", "before")).toBeNull();
    expect(dropCommand(rows, "s", "b", "inside")).toBeNull();
  });

  it("has no command for the node itself, its own subtree or an unknown node", () => {
    expect(dropCommand(rows, "b", "b", "inside")).toBeNull();
    expect(dropCommand(rows, "b", "n", "inside")).toBeNull();
    expect(dropCommand(rows, "t", "s", "after")).toBeNull();
    expect(dropCommand(rows, "nope", "t", "inside")).toBeNull();
    expect(dropCommand(rows, "n", "nope", "inside")).toBeNull();
  });
});

describe("subtreeIds and comboboxNodes", () => {
  it("lists the node and all its descendants", () => {
    expect(subtreeIds(rows[0]!).sort()).toEqual(["b", "n", "p", "s", "t"]);
    expect(subtreeIds(rows[2]!)).toEqual(["o"]);
  });

  it("gives the tree for the category picker", () => {
    expect(comboboxNodes(rows)[1]).toEqual({ key: "sp", label: "Sport", children: [{ key: "r", label: "Bieganie", children: [] }] });
  });
});

describe("formatProductCount", () => {
  it("shows the count, „ponad” when the API stopped counting and a dash without a count", () => {
    expect(formatProductCount({ value: 12, capped: false })).toBe("12");
    expect(formatProductCount({ value: 0, capped: false })).toBe("0");
    expect(formatProductCount({ value: 1000, capped: true })).toBe("ponad 1000");
    expect(formatProductCount(null)).toBe("—");
  });
});

describe("moveToCommand („Przenieś do…”: the end of the chosen parent)", () => {
  it("puts the category last under the chosen parent", () => {
    expect(moveToCommand(rows, "n", "sp")).toEqual({ id: "n", parentId: "sp", position: 1 });
    expect(moveToCommand(rows, "o", "r")).toEqual({ id: "o", parentId: "r", position: 0 });
    expect(moveToCommand(rows, "n", "b")).toEqual({ id: "n", parentId: "b", position: 1 });
  });

  it("puts the category last among the roots for the root", () => {
    expect(moveToCommand(rows, "n", null)).toEqual({ id: "n", parentId: null, position: 3 });
    expect(moveToCommand(rows, "t", null)).toEqual({ id: "t", parentId: null, position: 2 });
  });

  it("has no command when the category is already last there, or the parent is its own subtree", () => {
    expect(moveToCommand(rows, "o", null)).toBeNull();
    expect(moveToCommand(rows, "s", "b")).toBeNull();
    expect(moveToCommand(rows, "t", "s")).toBeNull();
    expect(moveToCommand(rows, "t", "t")).toBeNull();
    expect(moveToCommand(rows, "nope", null)).toBeNull();
  });
});

describe("zoneForRatio (where in the row a node was dropped)", () => {
  it("is before in the top quarter, after in the bottom quarter and inside between", () => {
    expect(zoneForRatio(0)).toBe("before");
    expect(zoneForRatio(0.24)).toBe("before");
    expect(zoneForRatio(0.25)).toBe("inside");
    expect(zoneForRatio(0.5)).toBe("inside");
    expect(zoneForRatio(0.75)).toBe("inside");
    expect(zoneForRatio(0.76)).toBe("after");
    expect(zoneForRatio(1)).toBe("after");
  });
});

describe("placeOf (where a category is, in words)", () => {
  it("names the path of the parents, or the top level for a root", () => {
    expect(placeOf(rows, "n")).toBe("Turystyka › Biwak");
    expect(placeOf(rows, "b")).toBe("Turystyka");
    expect(placeOf(rows, "o")).toBe("Poziom główny");
    expect(placeOf(rows, "nope")).toBe("Poziom główny");
  });
});
