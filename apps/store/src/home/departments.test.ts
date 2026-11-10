import { describe, expect, it } from "vitest";
import { node } from "@/catalog/fixtures";
import { departmentTiles } from "./departments";
import { MARKETING_IMAGES } from "./marketing-images";

describe("departmentTiles", () => {
  it("gives one tile per root category of the tree, in the order of the tree, with the name and the address of the category page", () => {
    const tree = [node("bieganie", "Bieganie", [node("buty", "Buty")]), node("rower", "Rower")];
    expect(departmentTiles(tree)).toEqual([
      { id: "bieganie", name: "Bieganie", href: "/c/bieganie", imageId: "dept-bieganie" },
      { id: "rower", name: "Rower", href: "/c/rower", imageId: "dept-rower" },
    ]);
  });

  it("assigns the photo by the slug of the root category, and none to a category without one", () => {
    const tiles = departmentTiles([node("sporty-wodne", "Sporty wodne"), node("kemping", "Kemping")]);
    expect(tiles.map((tile) => tile.imageId)).toEqual(["dept-sporty-wodne", undefined]);
  });

  it("has a photo in the list for every department of the demo shop", () => {
    const ids = new Set(MARKETING_IMAGES.map((image) => image.id));
    for (const slug of ["bieganie", "rower", "turystyka", "sporty-zimowe", "fitness", "sporty-wodne", "sporty-druzynowe"]) {
      expect(departmentTiles([node(slug, slug)])[0]!.imageId, slug).toBeDefined();
      expect(ids.has(departmentTiles([node(slug, slug)])[0]!.imageId!), slug).toBe(true);
    }
  });

  it("is empty for an empty tree", () => {
    expect(departmentTiles([])).toEqual([]);
  });
});
