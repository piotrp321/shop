import { STORE_NAME } from "@/site";

/**
 * Structured data of the home page (schema.org `Organization` and `WebSite`): the store name and the address of the
 * home page, nothing else (no logo, `sameAs`, contact data or search action). `storeUrl` has no trailing slash.
 */
export function buildHomeJsonLd(storeUrl: string) {
  const common = { "@context": "https://schema.org", name: STORE_NAME, url: `${storeUrl}/` };
  return [
    { ...common, "@type": "Organization" },
    { ...common, "@type": "WebSite" },
  ];
}
