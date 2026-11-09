import { listingSitemapResponse } from "@/seo/sitemap";

// Rendered on every request: the categories and STORE_URL are those of the running deployment, not of the build.
export const dynamic = "force-dynamic";

export function GET(): Promise<Response> {
  return listingSitemapResponse();
}
