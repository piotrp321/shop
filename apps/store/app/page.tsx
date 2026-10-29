import Link from "next/link";
import { CategoryTree } from "@/catalog/CategoryTree";
import { findCategoryPath } from "@/catalog/category-path";
import { loadCatalog } from "@/catalog/load-catalog";
import { CatalogPagination } from "@/catalog/Pagination";
import { ProductGrid } from "@/catalog/ProductGrid";

type SearchParams = Record<string, string | string[] | undefined>;

// A repeated parameter (?page=1&page=2) counts as its first value.
const first = (value: string | string[] | undefined) => (Array.isArray(value) ? value[0] : value);

export default async function Home({ searchParams }: PageProps<"/">) {
  const query: SearchParams = await searchParams;
  const category = first(query.category);
  const catalog = await loadCatalog({ category, page: first(query.page) });

  return (
    <>
      <header className="border-b border-border">
        <div className="mx-auto flex max-w-7xl items-center px-4 py-4">
          <Link href="/" className="text-xl font-semibold">
            Rytm
          </Link>
        </div>
      </header>
      <main className="mx-auto max-w-7xl px-4 py-6">
        {catalog.kind === "invalid-filter" ? (
          <div className="flex flex-col items-start gap-2">
            <h1 className="text-2xl font-semibold">Nieprawidłowy filtr</h1>
            <Link href="/" className="text-sm underline underline-offset-4">
              Zobacz cały katalog
            </Link>
          </div>
        ) : (
          <div className="flex flex-col gap-6 md:flex-row">
            <aside className="md:w-64 md:shrink-0">
              <CategoryTree categories={catalog.categories} activeId={category} />
            </aside>
            <div className="flex min-w-0 flex-1 flex-col gap-6">
              <h1 className="text-2xl font-semibold">{findCategoryPath(catalog.categories, category).at(-1)?.name ?? "Wszystkie produkty"}</h1>
              <ProductGrid products={catalog.products.items} />
              <CatalogPagination
                page={catalog.products.page}
                perPage={catalog.products.perPage}
                total={catalog.products.total}
                category={category}
              />
            </div>
          </div>
        )}
      </main>
    </>
  );
}
