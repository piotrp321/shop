import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { CategoryTree } from "@/catalog/CategoryTree";
import { findCategoryPath } from "@/catalog/category-path";
import { loadCatalogForRequest } from "@/catalog/load-catalog";
import { CatalogPagination } from "@/catalog/Pagination";
import { buildCatalogMetadata } from "@/catalog/page-metadata";
import { ProductGrid } from "@/catalog/ProductGrid";

type SearchParams = Record<string, string | string[] | undefined>;

// A repeated parameter (?page=1&page=2) counts as its first value.
const first = (value: string | string[] | undefined) => (Array.isArray(value) ? value[0] : value);

export async function generateMetadata({ searchParams }: PageProps<"/">): Promise<Metadata> {
  const query: SearchParams = await searchParams;
  const category = first(query.category);
  const page = first(query.page);
  // Shares the request with the page through `loadCatalogForRequest`, so the metadata adds no API calls.
  const catalog = await loadCatalogForRequest(category, page);
  if (catalog.kind === "unknown-category") {
    return {}; // The page answers 404.
  }
  const storeUrl = process.env.STORE_URL;
  if (!storeUrl) {
    throw new Error("STORE_URL is not set.");
  }
  return buildCatalogMetadata(catalog, { category, page }, storeUrl);
}

export default async function Home({ searchParams }: PageProps<"/">) {
  const query: SearchParams = await searchParams;
  const category = first(query.category);
  const catalog = await loadCatalogForRequest(category, first(query.page));
  if (catalog.kind === "unknown-category") {
    notFound();
  }

  return (
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
  );
}
