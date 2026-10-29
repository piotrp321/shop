import Link from "next/link";
import type { components } from "@/api/schema";
import { cn } from "@/lib/utils";
import { catalogHref } from "./catalog-href";
import { findCategoryPath } from "./category-path";

type CategoryNode = components["schemas"]["CategoryNode"];

const linkClass = "block rounded-md px-2 py-1 text-sm hover:bg-muted aria-[current=page]:bg-muted aria-[current=page]:font-medium";

function Branch({ category, activeId, expanded }: { category: CategoryNode; activeId?: string; expanded: Set<string> }) {
  const link = (
    <Link href={catalogHref({ category: category.id })} aria-current={category.id === activeId ? "page" : undefined} className={linkClass}>
      {category.name}
    </Link>
  );

  if (category.children.length === 0) {
    return <li>{link}</li>;
  }
  // Every branch is in the HTML for crawlers; `open` shows the active category and its ancestors.
  return (
    <li>
      <details open={expanded.has(category.id)} className="group">
        <summary className="flex cursor-pointer list-none items-center gap-1 [&::-webkit-details-marker]:hidden">
          <span aria-hidden className="text-muted-foreground transition-transform group-open:rotate-90">
            ›
          </span>
          <span className="flex-1">{link}</span>
        </summary>
        <ul className="ml-3 border-l border-border pl-2">
          {category.children.map((child) => (
            <Branch key={child.id} category={child} activeId={activeId} expanded={expanded} />
          ))}
        </ul>
      </details>
    </li>
  );
}

/** Category tree as links `?category=<id>`. The active category is marked and its ancestors are expanded. */
export function CategoryTree({ categories, activeId, className }: { categories: CategoryNode[]; activeId?: string; className?: string }) {
  const expanded = new Set(findCategoryPath(categories, activeId).map((category) => category.id));
  return (
    <nav aria-label="Kategorie" className={cn(className)}>
      <ul className="flex flex-col gap-0.5">
        <li>
          <Link href="/" className={linkClass}>
            Wszystkie produkty
          </Link>
        </li>
        {categories.map((category) => (
          <Branch key={category.id} category={category} activeId={activeId} expanded={expanded} />
        ))}
      </ul>
    </nav>
  );
}
