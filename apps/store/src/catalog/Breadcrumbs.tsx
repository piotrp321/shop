import Link from "next/link";

/** A step of the path; the one without `href` is the current page (shown as text). */
export type BreadcrumbItem = { name: string; href?: string };

export function Breadcrumbs({ items }: { items: BreadcrumbItem[] }) {
  return (
    <nav aria-label="Ścieżka">
      <ol className="flex flex-wrap items-center gap-x-2 text-sm text-muted-foreground [&_a]:underline">
        {items.map((item, index) => (
          <li key={`${index}-${item.name}`} className="flex items-center gap-x-2 py-1.25">
            {index > 0 && <span aria-hidden="true">/</span>}
            {item.href === undefined ? (
              <span aria-current="page">{item.name}</span>
            ) : (
              <Link href={item.href} className="underline-offset-4 hover:underline">
                {item.name}
              </Link>
            )}
          </li>
        ))}
      </ol>
    </nav>
  );
}
