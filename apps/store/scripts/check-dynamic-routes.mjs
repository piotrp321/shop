// Checks that `/robots.txt`, `/sitemap.xml` and the routes under `/sitemaps/` are rendered at the request on the
// production build: a prerendered route would freeze the STORE_URL and the catalogue of the build into the file.
// Reads `<dist>/prerender-manifest.json` (dist: first argument, default `.next`). Run it in the store container.
// Exit codes: 0 all dynamic; 1 a route is prerendered (listed on stderr); 2 the check cannot say: STORE_MODE of the
// container is not `production` (a manifest left by an earlier build is then not the one the store runs) or the
// manifest is missing or unreadable.
import { readFileSync } from "node:fs";
import { join } from "node:path";

const mustBeDynamic = (route) => route === "/robots.txt" || route === "/sitemap.xml" || route.startsWith("/sitemaps/");

function cannot(message) {
  console.error(`check-dynamic-routes: ${message}`);
  process.exit(2);
}

if (process.env.STORE_MODE !== "production") {
  cannot(`STORE_MODE is "${process.env.STORE_MODE ?? ""}", not "production": the manifest is not of the build the store runs.`);
}

const file = join(process.argv[2] ?? ".next", "prerender-manifest.json");
let manifest;
try {
  manifest = JSON.parse(readFileSync(file, "utf8"));
} catch (error) {
  cannot(`cannot read ${file} (${error instanceof Error ? error.message : "unknown error"}); run a production build first.`);
}
if (typeof manifest !== "object" || manifest === null || typeof manifest.routes !== "object" || manifest.routes === null) {
  cannot(`${file} has no "routes".`);
}

const prerendered = [...Object.keys(manifest.routes), ...Object.keys(manifest.dynamicRoutes ?? {})].filter(mustBeDynamic);
if (prerendered.length > 0) {
  console.error(`check-dynamic-routes: prerendered at build time, must be dynamic: ${prerendered.join(", ")}`);
  process.exit(1);
}
console.log("check-dynamic-routes: /robots.txt, /sitemap.xml and /sitemaps/* are dynamic.");
