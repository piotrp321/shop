import { productChunkSitemapResponse } from "@/seo/sitemap";

// Rendered on every request: the products and STORE_URL are those of the running deployment, not of the build.
export const dynamic = "force-dynamic";

/** `file` is `<chunk key>.xml`; the key is not decoded here, the API checks it. */
export async function GET(_request: Request, { params }: { params: Promise<{ file: string }> }): Promise<Response> {
  const { file } = await params;
  return productChunkSitemapResponse(file);
}
