import type { MetadataRoute } from "next";
import { buildRobots } from "@/seo/robots-rules";
import { readStoreUrl } from "@/seo/store-url";

// A metadata route is static by default and would freeze the STORE_URL of the build into the file.
export const dynamic = "force-dynamic";

export default function robots(): MetadataRoute.Robots {
  return buildRobots(readStoreUrl());
}
