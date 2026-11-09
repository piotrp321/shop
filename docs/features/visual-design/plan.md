# VISUAL-DESIGN – plan zadań

Specyfikacja: `docs/features/visual-design/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | VISUAL-DESIGN-1, VISUAL-DESIGN-2, VISUAL-DESIGN-3, VISUAL-DESIGN-10 | |
| 2 | VISUAL-DESIGN-4 (pytanie blokujące do właściciela) | |
| 3 | VISUAL-DESIGN-5, VISUAL-DESIGN-11 | |
| 4 | VISUAL-DESIGN-6, VISUAL-DESIGN-8, VISUAL-DESIGN-9 | |
| 5 | VISUAL-DESIGN-7 | |

Stan prób zadań zgłoszonych do AO: `ao status`. Kolumnę „Status” (PR, zmergowane) uzupełnia użytkownik przy
integracji zadań fali.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "VISUAL-DESIGN",
  "spec": "docs/features/visual-design/spec.md",
  "tasks": [
    {
      "id": "VISUAL-DESIGN-1",
      "title": "Three visual direction mockups with screenshots",
      "scope": "docs only: docs/features/visual-design/mockups/ with kierunek-a.html, kierunek-b.html, kierunek-c.html (static HTML, CSS in the file, no JavaScript), local OFL WOFF2 fonts with latin-ext in mockups/fonts/, 18 full-height PNG screenshots (3 directions × home, listing, product page × 1440 and 375 px) and README.md (idea of each direction, OKLCH palette, contrast table, fonts, type scale, radii, spacing, comparison table of composition areas, the full screenshot script for make e2e-agent and how to view the mockups). Spec sections „Kierunek (wspólny dla wszystkich wariantów)”, „Makiety (VISUAL-DESIGN-1)”, decisions D3, D7. Content and interface texts as in the store (demo data from apps/api/fixtures, name Worf, button „Do koszyka”), photos from apps/api/fixtures/images by relative paths, product photos 1:1. Not included: any change in apps/ (the store keeps the temporary theme), choosing a direction (VISUAL-DESIGN-4).",
      "acceptance_criteria": [
        "Three mockups each with home, listing and product page in 1440 and 375 px, with the states listed in „Makiety” (unavailable variant value, „Niedostępny” tile, „Ostatnie sztuki”, active filters, keyboard focus, open mega menu, mobile department menu, open photo enlargement)",
        "Every two directions differ in at least four composition areas from the list in „Makiety”, only with variants that do not change page behaviour (hero full width, filters as a column or an expandable section, product photos 1:1); README.md has the comparison table",
        "README.md contrast table: every text/background pair meets WCAG 2.2 AA (4.5:1 text, 3:1 large text and UI elements, including text on the hero photo)",
        "The screenshot script from README.md run through make e2e-agent opens every mockup from file:// without console errors or missing resources, waits for document.fonts.ready and fails when document.fonts.check does not confirm the direction fonts; it produced the 18 committed PNG files",
        "Fonts are SIL OFL with Polish characters, at most two families per direction; the mockups load nothing from the network"
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "VISUAL-DESIGN-2",
      "title": "Rename the store to Worf",
      "scope": "Whole repo: the store name in apps/store (layout title and description, page and product metadata „<name> | Worf”, Open Graph, header wordmark text) and its tests, MAILER_FROM „Worf <zamowienia@worf.localhost>” in infra/docker-compose.yml and apps/api/phpunit.dist.xml with the API tests that check it (apps/api/tests/Integration/Ordering), e2e/tests asserting titles, README.md, docs/vision.md („Nazwa sklepu: „Worf” (domena worf.pl)”), apps/store/CLAUDE.md, apps/api/CLAUDE.md and the feature specs listed in „Zadania dokumentacji”. Spec section „Nazwa sklepu (VISUAL-DESIGN-2)”, decision D12. Not included: any appearance change (temporary theme stays), the worf.pl address in the demo environment (DEPLOY #175).",
      "acceptance_criteria": [
        "git grep -n -w -i rytm -- ':!docs/features/visual-design' returns nothing",
        "Store page titles end with „| Worf” (listing, category, search, product page) and the layout metadata names Worf",
        "The order confirmation e-mail in Mailpit has the sender „Worf <zamowienia@worf.localhost>”",
        "make -C apps/api check, make -C apps/api test, make -C apps/store check, make -C apps/store test and make e2e pass"
      ],
      "risk": "FAST",
      "depends_on": []
    },
    {
      "id": "VISUAL-DESIGN-3",
      "title": "Zoom image size and zoomImages in product details",
      "scope": "apps/api Catalog: ImageSize zoom (resize fit, no fixed aspect ratio, height 0 in the imgproxy URL, widths 1280, 1920, 2560, WebP, no enlargement), URL generation for it in the existing signing service, ZoomImageView (alt, size, variants, no aspectRatio), ProductDetails.zoomImages built in GetProductBySlugHandler from the same keys and alt texts as images (no extra database read); OpenAPI schema ZoomImage in nelmio_api_doc.yaml with ProductImage unchanged; openapi.json and apps/store/src/api/schema.d.ts regenerated; docs. Spec section „Rozmiar zdjęć `zoom` (VISUAL-DESIGN-3)”, decision D6. Not included: the enlargement UI (VISUAL-DESIGN-8), a thumbnail size, any change in Cart or in the listing and product sizes.",
      "acceptance_criteria": [
        "Unit tests of zoom URLs: imgproxy path rs:fit:<width>:0 for 1280, 1920 and 2560, WebP, signed like the other sizes; listing and product URLs unchanged",
        "Functional tests of GET /api/v1/products/{slug}: zoomImages has the same order, count and alt texts as images; a product without images has an empty zoomImages; Cache-Control and xkey unchanged",
        "Schema ProductImage (list, details, cart) is unchanged with required aspectRatio; openapi.json has ZoomImage and ProductDetails.zoomImages; openapi-admin.json is unchanged; the store client is regenerated and make -C apps/store check passes",
        "docs/vision.md („Zdjęcia produktów”), apps/api/CLAUDE.md and docs/features/product-page/spec.md describe the zoom size and zoomImages",
        "make -C apps/api check and make -C apps/api test pass"
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "VISUAL-DESIGN-4",
      "title": "Owner's choice of the visual direction (blocking question)",
      "scope": "docs only: docs/features/visual-design/mockups/ and docs/features/visual-design/spec.md. Hard stop: without the owner's answer in the prompt the task returns BLOCKED (business) before any change in the repo, with the question „Który kierunek wdrażamy i z jakimi poprawkami?”, options A, B, C and „nowa runda kierunków” with consequences, a recommendation that is not a choice and the paths of all 18 screenshots, the three mockups and README.md on origin/epic/visual-design. After the answer: choice (with corrections applied to kierunek.html; corrections that change composition get new screenshots and one more BLOCKED for confirmation), new round (new directions committed on the task branch without a PR, BLOCKED again) or an ambiguous answer (BLOCKED again). After the confirmed choice: only kierunek.html, its six screenshots and the shortened README.md stay, the rejected files are removed, the spec gets „Wybrany kierunek” (qualitative, values only in tokens.css after VISUAL-DESIGN-5) and „Makieta” instead of „Makiety” and „Wybór kierunku”, criterion 1 gets its final wording. Spec section „Wybór kierunku: bezwzględny punkt zatrzymania (VISUAL-DESIGN-4)”, decisions D1, D2, D11. Not included: any change in apps/ or e2e/.",
      "acceptance_criteria": [
        "Without the owner's answer the task ends with BLOCKED (business) with the question, options, recommendation and the paths of all 18 screenshots, with no commit and no PR",
        "A new round produces new directions on the task branch and another BLOCKED without changes in apps/; corrections changing composition end with BLOCKED asking for confirmation with new screenshots",
        "After the confirmed choice the repo has kierunek.html, kierunek-glowna-1440.png … kierunek-produkt-375.png (six files), README.md of the chosen direction and the spec section „Wybrany kierunek” matching the owner's words; the rejected directions' files are removed",
        "The PR changes only docs/features/visual-design/"
      ],
      "risk": "STANDARD",
      "depends_on": ["VISUAL-DESIGN-1"]
    },
    {
      "id": "VISUAL-DESIGN-5",
      "title": "Tokens, fonts, components, header, footer and 404 of the chosen direction",
      "scope": "apps/store: src/styles/tokens.css with the chosen palette, semantic layer (including success, warning, hero overlay), radius, shadows and type scale; local WOFF2 fonts (latin-ext) through next/font/local in src/styles/fonts/; apps/store/THIRD_PARTY_NOTICES.md (OFL text and font files, MIT for vercel/shop files if any are adapted); src/components/ui restyled and every shadcn component the epic needs added through the shadcn CLI (including dialog for the gallery); SiteHeader, MegaMenu, SearchBox, CartBadge, AccountMenu per the mockup with unchanged behaviour (below 1024 px two rows: wordmark, „Menu”, cart and account, then the full-width search; focus order follows the rows); new compact SiteFooter in the layout (small wordmark, department links in two columns, one „© <year> Worf · Zdjęcia: Unsplash” line; department links from the same tree read, no extra request); app/not-found.tsx „Nie znaleźliśmy tej strony.” with „Wróć do sklepu”; docs. Spec sections „Tokeny i fundament (VISUAL-DESIGN-5)”, „Kierunek”, decisions D4, D10. Not included: home page content (VISUAL-DESIGN-6), listing (VISUAL-DESIGN-7), product page and gallery (VISUAL-DESIGN-8), other screens (VISUAL-DESIGN-9).",
      "acceptance_criteria": [
        "tokens.css contains only the chosen direction's palette and the semantic layer the mockup needs; its comment no longer says temporary; make -C apps/store check passes without disabled rules and bg-[#000], text-red-500 and a colour in style still fail ESLint",
        "An E2E test collects browser requests on the home page, listing and product page and every one goes to a stack host; the CI production build passes with fonts from files in the repo; Polish characters render in the direction font; THIRD_PARTY_NOTICES.md has the OFL licence",
        "Header and mega menu match the mockup at 1440 and 375 px (two rows on a phone); at 375 px the tab order is wordmark, „Menu”, cart, account, then the search (E2E); existing header, mega menu and search tests pass unchanged except header assertions the epic changes; no horizontal scroll at 375 px",
        "The footer matches the mockup at 1440 and 375 px, shows department links from the tree without an extra API request (page.requests test), and no links when the tree fails",
        "An unknown address and an unknown product show „Nie znaleźliśmy tej strony.” with a link to /, the store header and footer and noindex (E2E)",
        "axe has no serious or critical violations on the 404 page at 1440 and 375 px",
        "apps/store/CLAUDE.md („Wygląd”, structure), docs/vision.md („Wygląd sklepu”) and docs/features/catalog-page-metadata/spec.md (404) describe the current state",
        "make -C apps/store check, make -C apps/store test and make e2e pass"
      ],
      "risk": "STANDARD",
      "depends_on": ["VISUAL-DESIGN-4", "VISUAL-DESIGN-2"]
    },
    {
      "id": "VISUAL-DESIGN-6",
      "title": "Home page hero and department tiles with responsive marketing photos",
      "scope": "apps/store: src/home/ (hero text from the mockup, department photo mapping, marketing-images.ts data list, generated images.generated.ts with static imports, WebP files in src/home/images/), scripts/marketing-images.mjs (sharp from Next.js, validation before writing, budgets, no enlargement) with tests in apps/store/tests, the read-only mount ./apps/api/fixtures/images:/fixtures/images:ro for the store service in infra/docker-compose.yml, app/page.tsx and CatalogListing for the default state of / (hero and tiles, headings h1/h2/h3, priority only for the hero), e2e/support listing helpers made independent of the heading level and the E2E tests that go through /; docs (CLAUDE.md „Obrazy”, apps/store/CLAUDE.md, docs/vision.md, docs/features/search/spec.md, docs/features/walking-skeleton/spec.md). Spec sections „Strona główna `/` (VISUAL-DESIGN-6)”, „Zdjęcia strony głównej (D5)”, decision D5. Not included: listing restyle (VISUAL-DESIGN-7).",
      "acceptance_criteria": [
        "/ in the default state (also with ?utm_source=x and ?page=1) has the hero and tiles of every root category; /?page=2, / with a filter or sort do not; one h1 and the heading hierarchy of the spec; only the hero has loading priority; title, canonical and noindex unchanged; tree failure: hero without a link, no tiles; no extra API request (page.requests test)",
        "At 375 px (DPR 2 and 3) the page downloads the phone crop of the hero at most 828 px wide and no wide-screen hero file (E2E on image requests); every image has srcset, sizes, width and height; files are within the budget; tile images have alt=\"\" and the hero has its alt text",
        "Running the script again gives the same file names and dimensions within the budget; without sharp, without a source file or the /fixtures/images mount, with a width wider than the crop and over the budget it exits non-zero with a message (tests)",
        "Existing tests pass; only the assertions listed in criterion 9 of the spec for headings on / and the e2e/support grid helper change, each with a reason in the PR",
        "axe has no serious or critical violations on / at 1440 and 375 px and there is no horizontal scroll at 375 px",
        "CLAUDE.md („Obrazy”), apps/store/CLAUDE.md, docs/vision.md, docs/features/search/spec.md and docs/features/walking-skeleton/spec.md describe the current state",
        "make -C apps/store check, make -C apps/store test and make e2e pass"
      ],
      "risk": "STANDARD",
      "depends_on": ["VISUAL-DESIGN-5"]
    },
    {
      "id": "VISUAL-DESIGN-7",
      "title": "Listing in the chosen direction",
      "scope": "apps/store src/catalog: CatalogListing, Breadcrumbs, ListingFilters (on desktop an expandable section behind „Filtry” only when the mockup shows it, with today's mobile behaviour), ActiveFilters, SortSelect, ProductGrid, ProductTile, TileOffer, Pagination, EmptyResults, InvalidFilter per the mockup, including the tile variant choice (one active „Wybierz wariant”, then the variants of the offer (ProductOfferResponse.variants: label and purchasable) in offer order, an unpurchasable one marked „niedostępny” and not choosable; for a single-axis product they are the axis values as buttons like in the mockup, for a product with two or more axes the form of the list is decided by this task (it fits 375 px without horizontal scroll for e.g. 14 variants and is keyboard accessible) and recorded in the PR section „Decyzje bez pytania”, with no parsing of the label into axes; then the chosen variant's price and availability and an active „Do koszyka”; a product with one variant has „Do koszyka” directly; same on home page tiles; no API change); promotion badge and Omnibus line styled with tokens if PROMOTIONS is on main; e2e/support filter helper only if the filters panel needs opening on desktop; e2e/tests/cart-variants.spec.ts, e2e/support/variants.ts and the Vitest tests of TileOffer and ProductTile adjusted to the tile variant choice. Spec section „Listing (VISUAL-DESIGN-7)”, decision D9. Not included: behaviour other than the tile variant choice, texts, URLs, the no-JavaScript form and the number of API requests (unchanged), palette changes.",
      "acceptance_criteria": [
        "Listing, category and search pages match kierunek.html at 1440 and 375 px; store screenshots made with make e2e-agent stay outside the repo and the PR lists their paths",
        "A tile of a product with several variants has one active „Wybierz wariant”; after it the variants of the offer appear in offer order (an unpurchasable one says „niedostępny” and cannot be chosen), after a choice the chosen variant's price and availability and an active „Do koszyka”; a product with one variant has „Do koszyka” directly; the same on home page tiles; no API change (tests)",
        "After „Wybierz wariant” the focus moves to the first available (choosable) variant and the expansion and the availability change are announced in aria-live (Vitest and E2E)",
        "The tile choice works for a single-axis product and for a product with two axes (e.g. a demo product with Kolor × Rozmiar), at 375 px without horizontal scroll (Vitest and E2E)",
        "Existing Vitest and E2E listing tests pass; only assertions allowed by criterion 9 of the spec change (including the tile variant choice), each with a reason in the PR",
        "No classes outside tokens; availability labels use semantic tokens",
        "axe has no serious or critical violations on a category page and on /szukaj at 1440 and 375 px and there is no horizontal scroll at 375 px",
        "make -C apps/store check, make -C apps/store test and make e2e pass"
      ],
      "risk": "STANDARD",
      "depends_on": ["VISUAL-DESIGN-6", "VISUAL-DESIGN-11"]
    },
    {
      "id": "VISUAL-DESIGN-8",
      "title": "Product page with gallery and enlargement",
      "scope": "apps/store: ProductDetailsView, VariantChoice, AddToCartButton per the mockup; gallery from images and zoomImages: server-rendered scroll-snap strip with ids, thumbnail links #zdjecie-n (smallest product width, lazy, alt=\"\"), counter, same layout before and after hydration; JavaScript adds aria-current, live counter, previous/next buttons, smooth scroll without URL change, ← →; enlargement in the shadcn dialog added by VISUAL-DESIGN-5 with zoomImages, sizes=100vw, object-fit contain, keyboard, touch, focus return; alt texts from the API in the enlarge button name; promotion and Omnibus styled with tokens if PROMOTIONS is on main; docs (apps/store/CLAUDE.md „Strona produktu”, docs/features/product-page/spec.md). Spec section „Karta produktu `/p/[slug]` (VISUAL-DESIGN-8)”, decision D6. Not included: new image sizes, magnifier, pinch zoom, carousel library.",
      "acceptance_criteria": [
        "Thumbnails, buttons and enlargement work with mouse, keyboard (Tab, Enter, Space, ← →, Escape, focus return) and touch (swipe in the strip and in the enlargement changes the photo; E2E with a touch device at 375 px using CDP Input.synthesizeScrollGesture); a thumbnail click with JavaScript does not change the URL",
        "Every photo has the alt text from the API and the enlarge button has it in its name; thumbnails have alt=\"\" inside a named link; without JavaScript all photos and thumbnails are in the HTML and work",
        "The position of h1 and „Do koszyka” is the same before and after hydration; the ISR HTML does not depend on the session or gallery state; no hydration error",
        "The enlargement at 375 px does not download the 2560 px file; one photo: no thumbnails, counter or arrows, with enlargement; no photos: PhotoPlaceholder without enlargement",
        "Component tests (Vitest) and E2E on the demo product with several photos, Narty skiturowe Stavro Grań 95 (three photos from VISUAL-DESIGN-11); existing product page tests pass with only the gallery assertions changed",
        "axe has no serious or critical violations on the product page, also with the enlargement open, at 1440 and 375 px; no horizontal scroll at 375 px; the page matches kierunek.html (screenshot paths in the PR)",
        "apps/store/CLAUDE.md and docs/features/product-page/spec.md describe the gallery and enlargement",
        "make -C apps/store check, make -C apps/store test and make e2e pass"
      ],
      "risk": "STANDARD",
      "depends_on": ["VISUAL-DESIGN-5", "VISUAL-DESIGN-3", "VISUAL-DESIGN-11"]
    },
    {
      "id": "VISUAL-DESIGN-9",
      "title": "Cart, checkout, confirmation, account and error screens in the chosen direction",
      "scope": "apps/store: CartPage, CheckoutPage, PaymentConfirmation, account order history and details (if ORDER-MANAGEMENT is on main), app/error.tsx: tokens, typography and shared components (buttons, inputs, cards, role=alert messages) instead of own equivalents. Spec section „Pozostałe ekrany (VISUAL-DESIGN-9)”, decision D9. Not included: texts, layout structure and behaviour (unchanged).",
      "acceptance_criteria": [
        "No classes outside tokens and shared components instead of own equivalents on these screens; texts and behaviour unchanged (existing Vitest and E2E pass without assertion changes)",
        "axe has no serious or critical violations on the cart and checkout at 1440 and 375 px and there is no horizontal scroll at 375 px",
        "make -C apps/store check, make -C apps/store test and make e2e pass"
      ],
      "risk": "FAST",
      "depends_on": ["VISUAL-DESIGN-5"]
    },
    {
      "id": "VISUAL-DESIGN-10",
      "title": "UX agent assesses responsiveness, consistency and visual quality",
      "scope": "Repo tooling and docs only: .claude/agents/ux-reviewer.md, docs/features/agent-tester-ux/spec.md, docs/features/agentic-software-house/plan.md (step 9). The UX agent assesses, in /sweep and in /integration of epics with store front-end changes, besides usability and accessibility: responsiveness (375, 768, 1440 px), accessibility (keyboard, focus, names, alt, contrast, axe), component consistency and visual quality against „Wybrany kierunek” and the tokens, with the mockup as an illustration, not a pixel reference; in /integration of this epic it compares store screens with the mockup screenshots. Spec section „agent UX (VISUAL-DESIGN-10)”, decision D8. Not included: changes to /sweep or /integration skills.",
      "acceptance_criteria": [
        "ux-reviewer.md and docs/features/agent-tester-ux/spec.md describe the assessment of responsiveness, accessibility, component consistency and visual quality from the spec and no longer say that design conformance is out of scope",
        "docs/features/agentic-software-house/plan.md step 9 no longer limits the UX agent to usability and accessibility until the mockups",
        "The repo tooling script tests pass (CI scope for .claude/** changes)"
      ],
      "risk": "FAST",
      "depends_on": []
    },
    {
      "id": "VISUAL-DESIGN-11",
      "title": "Demo product photos for Kajaki and Stavro Grań 95",
      "scope": "apps/api: own photos of a demo product in apps/api/fixtures/catalog.yaml (assigned to a product = brand + model of a template, not to the whole template or model, so Vilmar Grań 95 keeps its photos) for the four products of sporty-wodne/kajaki (Kajak turystyczny Vilmar Rzeka 1, Vilmar Rzeka 2, Dravik Rzeka 1, Dravik Rzeka 2) and Narty skiturowe Stavro Grań 95; new JPEG files from Unsplash (Unsplash License, about 1600 px on the longer side) in apps/api/fixtures/images with rows in its CREDITS.md and alt texts describing what the photo shows; the loader in apps/api/src/Catalog/Infrastructure/Demo (definition, YamlDemoCatalogSource, DemoCatalogExpander) takes a product's own photos in file order while every other product keeps pool[index % count(pool)] and the imageless rule unchanged, with unit tests in apps/api/tests/Unit/Catalog/Demo; docs/features/walking-skeleton/spec.md („Dane demo”); in docs/features/visual-design the first paragraph of the spec section „Zdjęcia produktów w danych demo (VISUAL-DESIGN-11)” and deviation (2) in mockups/README.md, which describe the demo data before this task, are rewritten to the current state. Spec section „Zdjęcia produktów w danych demo (VISUAL-DESIGN-11)”, decision D13. Not included: variant images (a photo depending on the chosen variant: it would change the Catalog model and the API), other categories and products, any change in the Catalog model, the database schema, the API or apps/store. If no licensed photo meets a requirement the task returns BLOCKED instead of a weaker photo.",
      "acceptance_criteria": [
        "In the category Kajaki each of the four models has its own photo, different from the others, showing a green kayak; with the filter „Kolor: zielony” the tile photo shows the green variant, and the photo of the two-person kayak (Rzeka 2) shows one kayak with two seats (Rzeka 1: one kayak with one seat)",
        "On the product page of Narty skiturowe Stavro Grań 95 the gallery shows, in this order, the whole pair of skis in the chosen blue variant, a detail of this model and the gear in use; make seed on a fresh stack gives the product three images and three zoomImages in that order",
        "Every other product keeps the same photos and the set of imageless products is unchanged (unit test on the real catalog.yaml); Narty skiturowe Vilmar Grań 95 keeps its photos",
        "A reference to an unknown product (brand or model outside the template) and an empty own-photo list end with an error with a message (unit tests)",
        "The new files are JPEG of about 1600 px on the longer side with rows in apps/api/fixtures/images/CREDITS.md (file, page, author, Unsplash License) and with alt texts that describe what the photo shows",
        "docs/features/walking-skeleton/spec.md describes the own photos of a product and the number of photo files; the first paragraph of the VISUAL-DESIGN-11 section in docs/features/visual-design/spec.md and deviation (2) in mockups/README.md describe the current demo data, not the data before the task",
        "make -C apps/api check, make -C apps/api test and make e2e pass"
      ],
      "risk": "STANDARD",
      "depends_on": ["VISUAL-DESIGN-4"]
    }
  ]
}
```
