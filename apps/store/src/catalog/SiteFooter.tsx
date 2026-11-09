import Link from "next/link";
import type { components } from "@/api/schema";
import { STORE_NAME } from "@/site";
import { categoryHref } from "./catalog-href";

type CategoryNode = components["schemas"]["CategoryNode"];

/**
 * The footer of every page: the small wordmark, the links of the departments (the top categories of the tree the
 * layout has already read for the header, so it asks for nothing) in two columns, and under a thin line one row with the
 * year, the name of the store and the source of the photos. `categories` is `null` or empty when the tree could not be
 * read (or is empty): the footer then has no links. A wide screen has the wordmark on the left and the links on the
 * right; a phone has the wordmark above the links, which are 44 px tall.
 */
export function SiteFooter({ categories }: { categories: CategoryNode[] | null }) {
  return (
    <footer data-surface="inverse" className="bg-inverse pt-7 pb-4 text-inverse-foreground lg:pt-9 lg:pb-4.5">
      <div className="mx-auto flex max-w-content flex-col gap-2 px-gutter pb-4 lg:flex-row lg:items-start lg:justify-between lg:pb-5">
        <Link href="/" className="inline-block self-start text-2xl leading-none font-bold tracking-brand lg:text-[1.75rem]">
          {STORE_NAME}
        </Link>
        {categories !== null && categories.length > 0 && (
          <nav aria-label="Linki w stopce">
            <ul className="columns-2 gap-x-6 lg:gap-x-16">
              {categories.map((category) => (
                <li key={category.id} className="break-inside-avoid">
                  <Link href={categoryHref(category.path)} className="inline-block py-2.5 underline-offset-4 hover:underline lg:py-1.25">
                    {category.name}
                  </Link>
                </li>
              ))}
            </ul>
          </nav>
        )}
      </div>
      <div className="mx-auto max-w-content px-gutter">
        <p className="border-t border-inverse-border pt-3.5 text-xs leading-snug text-inverse-muted">
          © {new Date().getFullYear()} {STORE_NAME} <span aria-hidden="true">·</span> Zdjęcia: Unsplash
        </p>
      </div>
    </footer>
  );
}
