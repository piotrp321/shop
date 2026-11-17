import type { components } from "@/api/schema";
import type { CategoryTreeNode } from "@/composables/useCategoryTree";

type CategoryNode = components["schemas"]["CategoryNode"];
type Count = components["schemas"]["BoundedCountResponse"];

/** A category of the screen: what the API gave for it (`withCounts=true`), siblings in the order of the store. */
export interface CategoryRow {
  id: string;
  name: string;
  slug: string;
  path: string;
  version: number;
  /** The products directly in the category; null when the API did not count. */
  productCount: Count | null;
  children: CategoryRow[];
}

/** A change of the place of a category: under `parentId` (null: among the roots) at `position` among the children without the category itself. */
export interface MoveCommand {
  id: string;
  parentId: string | null;
  position: number;
}

export type DropZone = "before" | "inside" | "after";

export function toRows(nodes: CategoryNode[]): CategoryRow[] {
  return nodes.map((node) => ({
    id: node.id,
    name: node.name,
    slug: node.slug,
    path: node.path,
    version: node.version,
    productCount: node.productCount,
    children: toRows(node.children),
  }));
}

export interface RowLocation {
  row: CategoryRow;
  parent: CategoryRow | null;
  siblings: CategoryRow[];
  index: number;
}

export function findRow(rows: CategoryRow[], id: string, parent: CategoryRow | null = null): RowLocation | null {
  for (const [index, row] of rows.entries()) {
    if (row.id === id) {
      return { row, parent, siblings: rows, index };
    }
    const inside = findRow(row.children, id, row);
    if (inside !== null) {
      return inside;
    }
  }
  return null;
}

/** The ids of the category and of everything below it. */
export function subtreeIds(row: CategoryRow): string[] {
  return [row.id, ...row.children.flatMap(subtreeIds)];
}

/** One place up or down among the siblings; null at the edge (the API decides on everything else). */
export function moveCommand(rows: CategoryRow[], id: string, direction: "up" | "down"): MoveCommand | null {
  const found = findRow(rows, id);
  if (found === null) {
    return null;
  }
  const position = direction === "up" ? found.index - 1 : found.index + 1;
  if (position < 0 || position >= found.siblings.length) {
    return null;
  }
  return { id, parentId: found.parent?.id ?? null, position };
}

/**
 * The command of a drop: the dragged category before or after the target (the parent of the target) or inside it (its
 * last child). The same command as the menu would give, so dragging is a shortcut and never another way to change the
 * tree. Null for a drop that changes nothing, onto the category itself or into its own subtree.
 */
export function dropCommand(rows: CategoryRow[], draggedId: string, targetId: string, zone: DropZone): MoveCommand | null {
  const dragged = findRow(rows, draggedId);
  const target = findRow(rows, targetId);
  if (dragged === null || target === null || subtreeIds(dragged.row).includes(targetId)) {
    return null;
  }
  const others = (children: CategoryRow[]) => children.filter((child) => child.id !== draggedId);
  let parent: CategoryRow | null;
  let position: number;
  if (zone === "inside") {
    parent = target.row;
    position = others(parent.children).length;
  } else {
    parent = target.parent;
    const siblings = others(parent?.children ?? rows);
    position = siblings.findIndex((sibling) => sibling.id === targetId) + (zone === "after" ? 1 : 0);
  }
  const parentId = parent?.id ?? null;
  if (parentId === (dragged.parent?.id ?? null) && position === dragged.index) {
    return null;
  }
  return { id: draggedId, parentId, position };
}

/** The tree in the shape of `CategoryCombobox`. */
export function comboboxNodes(rows: CategoryRow[]): CategoryTreeNode[] {
  return rows.map((row) => ({ key: row.id, label: row.name, children: comboboxNodes(row.children) }));
}

/** The count of products of a category as text: the number, „ponad 1000” when the API stopped counting, a dash without a count. */
export function formatProductCount(count: Count | null): string {
  if (count === null) {
    return "—";
  }
  return count.capped ? `ponad ${count.value}` : String(count.value);
}

/** Where the category goes for „Przenieś do…”: the end of the children of the chosen parent (null: of the roots). Null when nothing would change or the parent is in its own subtree. */
export function moveToCommand(rows: CategoryRow[], id: string, parentId: string | null): MoveCommand | null {
  const dragged = findRow(rows, id);
  if (dragged === null) {
    return null;
  }
  if (parentId !== null) {
    return dropCommand(rows, id, parentId, "inside");
  }
  const position = rows.filter((row) => row.id !== id).length;
  return dragged.parent === null && position === dragged.index ? null : { id, parentId: null, position };
}

/** The zone of a row a pointer is in, from its place in the row (0 top, 1 bottom): the top and bottom quarters are before and after, between them inside. */
export function zoneForRatio(ratio: number): DropZone {
  if (ratio < 0.25) {
    return "before";
  }
  return ratio > 0.75 ? "after" : "inside";
}

export const ROOT_PLACE = "Poziom główny";

/** The place of a category: the names of its parents („Turystyka › Biwak”), or the top level for a root. */
export function placeOf(rows: CategoryRow[], id: string): string {
  const names: string[] = [];
  for (let parent = findRow(rows, id)?.parent ?? null; parent !== null; parent = findRow(rows, parent.id)?.parent ?? null) {
    names.unshift(parent.name);
  }
  return names.length === 0 ? ROOT_PLACE : names.join(" › ");
}
