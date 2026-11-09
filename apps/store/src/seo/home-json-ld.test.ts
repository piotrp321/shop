import { describe, expect, it } from "vitest";
import { STORE_NAME } from "@/site";
import { buildHomeJsonLd } from "./home-json-ld";

describe("buildHomeJsonLd", () => {
  it("describes the organization and the web site with the store name and the address of the home page", () => {
    expect(buildHomeJsonLd("http://store.test")).toEqual([
      { "@context": "https://schema.org", "@type": "Organization", name: STORE_NAME, url: "http://store.test/" },
      { "@context": "https://schema.org", "@type": "WebSite", name: STORE_NAME, url: "http://store.test/" },
    ]);
  });

  it("has no logo, sameAs, search action or contact data", () => {
    for (const data of buildHomeJsonLd("http://store.test")) {
      expect(Object.keys(data).sort()).toEqual(["@context", "@type", "name", "url"]);
    }
  });
});
