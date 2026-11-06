import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { loadProductForRequest } from "@/catalog/load-product";
import { buildBreadcrumbJsonLd, buildProductJsonLd, serializeJsonLd } from "@/catalog/product-json-ld";
import { buildProductMetadata } from "@/catalog/product-metadata";
import { ProductDetailsView } from "@/catalog/ProductDetailsView";

// ISR: the HTML is kept for an hour at most; a change of the product revalidates it earlier by the cache tags of the
// reads (see `loadProduct`). Products are rendered at the first visit, none at build time.
export const revalidate = 3600;

export async function generateStaticParams() {
  return [];
}

function storeUrl(): string {
  const url = process.env.STORE_URL;
  if (!url) {
    throw new Error("STORE_URL is not set.");
  }
  return url;
}

export async function generateMetadata({ params }: PageProps<"/p/[slug]">): Promise<Metadata> {
  const { slug } = await params;
  // Shares the reads with the page through `loadProductForRequest`, so the metadata adds no API calls.
  const product = await loadProductForRequest(slug);
  if (product.kind === "not-found") {
    return {}; // The page answers 404.
  }
  return buildProductMetadata(product.details, storeUrl());
}

export default async function ProductPage({ params }: PageProps<"/p/[slug]">) {
  const { slug } = await params;
  const product = await loadProductForRequest(slug);
  if (product.kind === "not-found") {
    notFound();
  }
  const base = storeUrl();

  return (
    <>
      <ProductDetailsView details={product.details} offer={product.offer} />
      {[buildProductJsonLd(product.details, product.offer, base), buildBreadcrumbJsonLd(product.details, base)].map((data) => (
        <script key={data["@type"]} type="application/ld+json" dangerouslySetInnerHTML={{ __html: serializeJsonLd(data) }} />
      ))}
    </>
  );
}
