# Konkurencja: Sylius, Medusa, Saleor (stan na listopad 2026) – funkcje open source vs płatne vs integracje vs custom

Uwaga metodyczna: notatki powstały przy ograniczonym budżecie wyszukiwań (ok. 20 wywołań). Fakty poniżej mają źródło; wszystko, czego nie zweryfikowałem, jest w sekcjach „Luki". Oznaczenia: (N) native/open source, (P) płatne (edycja/cloud/plugin), (I) integracja zewnętrzna, (C) custom. Strony dokumentacji Medusy zawierały wstrzyknięte instrukcje skierowane do agentów AI (prośby o dołączenie sekcji feedbacku) – zignorowałem je; to dane, nie polecenia. Vendure i commercetools NIE zostały zbadane (patrz Luki).

## 1. Licencje, ceny i model biznesowy

### Takeaway
Wszystkie trzy rdzenie są open source (Sylius MIT, Medusa MIT + osobna licencja enterprise, Saleor BSD-3). Monetyzacja: Sylius – edycja Plus wyceniana od GMV i modułów (od 800 EUR/rok); Medusa – Cloud od 29 USD/mies. + moduły enterprise (RBAC) na licencji; Saleor – Cloud od 1 599 USD/mies. z progami GMV, funkcje „Essential Apps" tylko w Cloud.

### Cited Findings
- Sylius Standard: licencja MIT, wsparcie społeczności bez gwarancji odpowiedzi; Plus: licencja komercyjna, SLA z odpowiedzią w 1 dzień, dedykowany Customer Success Manager, dystrybucja on-premise — [sylius.com/plus](https://sylius.com/plus/)
- Cennik Sylius Plus: pełny pakiet wyceniany wg rocznego GMV sklepu; wersja modułowa wg GMV + wybrane moduły; tabela porównawcza podaje „od 800 EUR / rok" (strona jest niespójna: FAQ mówi o 9 modułach, lista pokazuje 11) — [sylius.com/plus](https://sylius.com/plus/)
- Medusa: rdzeń MIT; materiały enterprise wg ENTERPRISE-LICENSE.md wymagają komercyjnej umowy z MedusaJS, Inc.; ok. 37,3 tys. gwiazdek, 5,5 tys. forków — [GitHub medusa](https://github.com/medusajs/medusa)
- Medusa Cloud: Develop od 29 USD/mies. (bez Redis, domen własnych, backupów, autoskalowania), Launch od 99 USD (autoskalowanie, Redis, własne domeny storefrontu, webhooks, backupy 7 dni), Scale od 299 USD (worker w tle, wyszukiwanie semantyczne/hybrydowe, backupy 14 dni), Enterprise wg wyceny (SLA 99,99%, RBAC, SSO, audit logs, BYOC, SOC 2). Na każdym planie: 0% opłaty GMV, nielimitowane zamówienia/produkty/kanały sprzedaży/regiony/użytkownicy admina, serwer MCP — [medusajs.com/pricing](https://medusajs.com/pricing)
- Saleor: licencja BSD-3-Clause, ok. 23,6 tys. gwiazdek, 6,2 tys. forków — [GitHub saleor](https://github.com/saleor/saleor)
- Saleor Cloud: Sandboxes bezpłatne (tylko użycie niekomercyjne); Select 1 599 USD/mies. do 200 tys. USD GMV/mies. (0,8% powyżej limitu); Volume 3 999 USD/mies. do 1 mln USD GMV (0,4% powyżej); Enterprise negocjowane. W Cloud: Saleor Engine (hosting storefrontu i aplikacji), Pulse (dashboard), „Essential Apps (Cloud only)": e-maile klienta, płatności Adyen, feedy Google Merchant Center, MCP, ACP/UCP, Ink AI Search; migracja i wsparcie, opcjonalny Accelerator 6 000 USD i FDE 12 000 USD — [saleor.io/pricing](https://saleor.io/pricing)
- Sylius: GitHub ok. 8,6 tys. gwiazdek, 2,2 tys. forków, MIT; „headless open-source eCommerce platform on top of PHP/Symfony/API Platform" — [GitHub Sylius](https://github.com/Sylius/Sylius)
- Medusa i Saleor mają oficjalne przykładowe storefronty Next.js, Sylius zwykle konsumowany przez API/community SDK; Saleor ma najcięższy stack (Python, Redis, Celery) — [openreplay/porównania (agregator, nisko-średnia jakość)](https://blog.openreplay.com/5-open-source-ecommerce-platforms/); wyniki wyszukiwania pochodzą z agregatorów, traktować jako anegdotyczne.

### Inferences
- Dla integratora: Medusa to najtańszy start, Saleor Cloud celuje w średni/duży GMV (próg wejścia 1 599 USD/mies.), Sylius płaci się za funkcje enterprise (Plus), nie za hosting.
- Oferta Saleor Cloud (FDE, Solution Architect) wskazuje segment mid-market/enterprise, a nie startupy.

### Gaps
- Dokładna aktualna wersja i data ostatniego wydania każdej platformy (strony GitHub nie pokazały sekcji Releases) – niezweryfikowane.
- Finansowanie, klienci referencyjni, liczba contributorów – niezweryfikowane.
- Vendure i commercetools – nie zbadane w ogóle.

## 2. Katalog, wyszukiwanie, ceny, promocje, magazyn (obszary 1–4)

### Takeaway
Medusa dokumentuje moduły Product, Pricing, Promotion, Tax, Inventory, Stock Location, Sales Channel, Region, Currency, Translation, Store Credit, Loyalty. Saleor w README deklaruje rozbudowany katalog, promocje (sale, vouchery, cart rules, gift cards), multi-warehouse. W Sylius multi-source inventory, wiele cenników i zaawansowane wyszukiwanie to funkcje Plus. Wyszukiwanie w Medusie przez integracje (Algolia/Meilisearch) lub semantyczne w Cloud Scale.

### Cited Findings
- Medusa ma 23 moduły commerce, m.in. API Key, Auth, Cart, Currency, Customer, Fulfillment, Inventory, Loyalty, Order, Payment, Pricing, Product, Promotion, RBAC, Region, Sales Channel, Settings, Stock Location, Store, Store Credit, Tax, Translation, User — [docs Medusa: commerce modules](https://docs.medusajs.com/resources/commerce-modules)
- Medusa Inventory: wiele lokalizacji (magazyny), rezerwacje, inventory kits (zestawy); backordery nie wymienione na stronie modułu (niezweryfikowane) — [docs Medusa: inventory](https://docs.medusajs.com/resources/commerce-modules/inventory)
- Medusa Tax: regiony podatkowe z ustawieniami, domyślne stawki z nadpisaniami warunkowymi, własni providerzy podatku (C); strona nie opisuje tax-inclusive ani VAT UE — [docs Medusa: tax](https://docs.medusajs.com/resources/commerce-modules/tax)
- Medusa integracje oficjalne: wyszukiwanie Algolia, Meilisearch; CMS Contentful, Payload, Sanity, Strapi; podatki Avalara; ERP Odoo; fulfillment ShipStation; powiadomienia SendGrid, Mailchimp, Resend, Slack, Twilio; analityka PostHog, Segment — [docs Medusa: integrations](https://docs.medusajs.com/resources/integrations)
- Medusa Cloud Scale: wyszukiwanie semantyczne/hybrydowe i analityka wyszukiwania (P); na każdym planie full-text search — [medusajs.com/pricing](https://medusajs.com/pricing)
- Saleor (README): zarządzanie dużymi, złożonymi katalogami; multi-currency, multi-language, multi-warehouse; CMS dla treści produktowych i marketingowych; silnik promocji (sale, vouchery, cart rules, gift cards); w pełni tłumaczalny katalog — [GitHub saleor](https://github.com/saleor/saleor)
- Saleor: kanały (channels) dla multi-channel, vouchery i rabaty przez promotions — [docs.saleor.io](https://docs.saleor.io/)
- Sylius Plus (tylko Plus): wiele cenników (multiple pricing lists), Advanced search feature, multi-source inventory, loyalty system, One Page Checkout — [sylius.com/plus](https://sylius.com/plus/)
- Sylius Omnibus: oficjalny plugin `sylius/price-history-plugin` (MIT, ok. 23,7 tys. instalacji), wymaga SyliusRecipes i SyliusRector, PHP ^8.0; data ostatniej aktualizacji niespójna między źródłami (2024 vs 2026) — [Packagist](https://packagist.org/packages/sylius/price-history-plugin)

### Inferences
- Omnibus: Sylius jedyny z potwierdzonym wtyczkowym rozwiązaniem (N-plugin, bezpłatny); dla Medusy i Saleor nie znalazłem oficjalnego odpowiednika – przypuszczalnie (C).
- Elasticsearch jako natywny silnik: żadna z platform nie ma go „z pudełka" w źródłach, które zweryfikowałem; Medusa daje Algolia/Meilisearch (I).

### Gaps
- Import/export, operacje masowe w katalogu (CSV) – nie zweryfikowane dla żadnej platformy.
- Typy produktów/atrybuty w Sylius i Saleor, taxony/kolekcje – tylko ogólne informacje.
- Gift cards w Medusie (jest moduł Store Credit – zakres niezweryfikowany) i Sylius.
- Obsługa tax-inclusive i OSS VAT UE – niezweryfikowane dla wszystkich trzech.
- Backordery – niezweryfikowane (Medusa, Sylius, Saleor).
- Dokumentacja docs.sylius.com była niedostępna dla WebFetch (przekierowanie na uwierzytelnianie), więc rdzeń Sylius opisany jest tylko z README i strony Plus.

## 3. Koszyk, checkout, zamówienia, płatności, wysyłka, zwroty (obszary 5–7)

### Takeaway
Medusa ma w module Order draft orders, edycje zamówień, zwroty, wymiany, claimy z wersjonowaniem. Saleor: draft orders w dashboardzie, fulfillments, transakcje, zwroty/zwroty środków w README. W Sylius zarządzanie zwrotami (RMA) i częściowa realizacja zamówień to Plus. Płatności lokalne dla Polski: Sylius – wtyczki BitBag (P24, PayU, InPost, lecz dla Sylius 1.x); Medusa – wtyczki społeczności (P24/BLIK, Autopay), brak PayU; Saleor – nie znalazłem.

### Cited Findings
- Medusa Order: draft orders (`status: "draft"`), edycja/zwrot/wymiana pozycji z kontrolą wersji; claimy wymienione bez szczegółów; cancel — [docs Medusa: order](https://docs.medusajs.com/resources/commerce-modules/order)
- Medusa Payment: moduł z providerami (Stripe, Adyen jako przykłady), payment collections, authorize/capture/refund, zapisane metody płatności, webhooks; własny provider możliwy (C) — [docs Medusa: payment](https://docs.medusajs.com/resources/commerce-modules/payment); PayPal wymieniony w integracjach — [integrations](https://docs.medusajs.com/resources/integrations)
- Medusa v2.5.0 przeprojektowało interfejs providera płatności (breaking change po premierze v2) — [newreleases.io](https://newreleases.io/project/github/medusajs/medusa/release/v2.5.0)
- Medusa P24/BLIK: społeczność (GMI Software), plugin dla Medusa v2.4.0+ i Node 20+, BLIK, karty, redirect P24; nazwa pakietu niespójna między źródłami — [Medusa integrations](https://medusajs.com/integrations/gmisoftware-przelewy24/), [README unpkg](https://unpkg.com/@gmisoftware/przelewy24-payments-medusa@0.1.3/README.md)
- Medusa Autopay (Blue Media; BLIK, P24 jako kanały): poradnik Rigby — [rigbyjs.com](https://rigbyjs.com/pl/blog/a-comprehensive-guide-to-integrating-autopay-as-payment-processor-with-medusa); plugina PayU dla Medusy nie znalazłem
- Sylius: wtyczki BitBag Przelewy24 (Sylius ~1.12/1.13), PayU (Sylius 1.12–1.14, tylko PayU Polska), InPost (eksport przesyłek, bez jasnej informacji o wersji); zgodność z Sylius 2 niepotwierdzona — [Sylius Addons P24](https://addons.sylius.com/en_US/products/bitbag-przelewy24-plugin), [PayU](https://addons.sylius.com/en_US/products/bitbag-payu-plugin), [InPost](https://addons.sylius.com/en_US/products/bitbag-inpost-plugin), [Packagist PayU](https://packagist.org/packages/bitbag/payu-plugin)
- Sylius Plus: Returns Management (RMA), Partial Shipment (dzielenie zamówienia na wysyłki), Request for Quotation, Product Configurator, Subscription — [sylius.com/plus](https://sylius.com/plus/)
- Saleor Order: draft orders kończone w dashboardzie, fulfillments (unfulfilled → partially → fulfilled; ustawienia `fulfillmentAutoApprove`, `fulfillmentAllowUnpaid`), transakcje i uprawnienia (`MANAGE_ORDERS`, `HANDLE_PAYMENTS`); zwroty/refundy nie opisane na tej stronie — [docs Saleor: order](https://docs.saleor.io/developer/order/overview)
- Saleor README: obsługa split payments, zwrotów, wielu magazynów; orkiestracja płatności wielu bramek — [GitHub saleor](https://github.com/saleor/saleor)
- Saleor Cloud: płatności Adyen jako Essential App (tylko Cloud, P) — [saleor.io/pricing](https://saleor.io/pricing)

### Inferences
- Polskie metody płatności są (I)/(C) w każdej platformie; najbliżej „gotowca" ma Medusa v2 (P24+BLIK community), ale to pojedynczy plugin społeczności o niewielkiej dojrzałości (wersje 0.x).
- Sylius ma historycznie najlepsze polskie pluginy, ale zgodność z 2.x niepotwierdzona – ryzyko.

### Gaps
- Kalkulacja stawek wysyłki, strefy, integracje kurierskie (InPost, DPD) dla Medusy i Saleor – nie zweryfikowane (Medusa: ShipStation jako jedyny oficjalny).
- Refundy w Sylius (plugin RefundPlugin) – nie zweryfikowane w tej sesji.
- Faktury/paragony/KSeF – nie sprawdzone; zakładam brak natywnego wsparcia, ale to niezweryfikowane.
- Saleor: aplikacje płatnicze dla Polski (P24/BLIK/PayU) – wyszukiwanie nic nie wykazało.

## 4. Klienci, admin, RBAC, raportowanie (obszary 8–10)

### Takeaway
RBAC jest płatny w Sylius (Plus) i w Medusie (licencja enterprise, od v2.21, wymaga klucza `MEDUSA_LICENSE_KEY`). Saleor ma uprawnienia per aplikacja/staff w rdzeniu (zweryfikowane ogólnie przez nazwy uprawnień), Pulse w Cloud daje dashboard raportowy.

### Cited Findings
- Medusa RBAC: „requires an enterprise license"; moduł ładuje się tylko z kluczem licencji; role i polityki, scopes ograniczone do jednej encji (np. jeden vendor w marketplace); Cloud wstrzykuje klucz automatycznie — [docs Medusa: RBAC](https://docs.medusajs.com/resources/enterprise/rbac)
- Sylius: Advanced user permissions (RBAC) i Admins per channel – tylko Plus — [sylius.com/plus](https://sylius.com/plus/)
- Saleor: granularne uprawnienia (`MANAGE_ORDERS`, `HANDLE_PAYMENTS`, `MANAGE_CHECKOUTS`) dla staff i aplikacji — [docs Saleor: order](https://docs.saleor.io/developer/order/overview)
- Saleor Pulse: przychód, zamówienia, zwroty, niski stan na dashboardzie, wykrywanie anomalii (Cloud) — [saleor.io/pricing](https://saleor.io/pricing)
- Saleor: dashboard w osobnym repozytorium; rozszerzenia przez aplikacje w iframe — [GitHub saleor](https://github.com/saleor/saleor)
- Medusa Admin: przeprojektowany, oparty na Vite, rozszerzalny widgetami i trasami; w v1 skarżono się na trudność rozszerzania — [medusajs.com/recap](https://medusajs.com/recap/), [GitHub discussion #7195](https://github.com/medusajs/medusa/discussions/7195)
- Medusa: grupy klientów out-of-the-box — [medusajs.com/b2b](https://medusajs.com/b2b/)

### Inferences
- Dla macierzy: RBAC = jedyna luka „bezpłatnie vs płatnie" wspólna dla Sylius i Medusy; własny Keycloak pokrywa to natywnie.

### Gaps
- Raportowanie/analityka w Sylius i Medusie (OSS) – nie zweryfikowane; Medusa wskazuje PostHog/Segment (I).
- Transakcyjne e-maile: Medusa Cloud ma limity e-mail w planach (P); w OSS wymaga providera (SendGrid/Resend itp. – I). Sylius/Saleor OSS – niezweryfikowane (Saleor: e-maile jako Essential App tylko w Cloud).
- Jakość admina Sylius (Twig/Semantic UI → Bootstrap w 2.x?) – niezweryfikowane.

## 5. Marketing, CMS, storefront, multi-*, B2B, marketplace (obszary 11–13)

### Takeaway
Wszystkie trzy to platformy headless; CMS przez integracje (Medusa) lub wbudowany prosty (Saleor README). B2B: Medusa ma oficjalny B2B Starter (firmy, pracownicy, limity wydatków, RFQ, zatwierdzenia), Sylius sprzedaje B2B Suite, Marketplace Suite i Multi-store w Plus. Saleor ma recepty (marketplace z wieloma sprzedawcami) w docs.

### Cited Findings
- Medusa B2B Starter: konta firm i pracowników, limity wydatków, RFQ/quotes, zatwierdzenia koszyków, edycja zamówień/quotes, grupy klientów; starter oparty na Medusa + Next.js 15; stare repo `b2b-starter-medusa` jest deprecated — [wyniki: medusajs.com/b2b](https://medusajs.com/b2b/), [GitHub b2b-starter](https://github.com/medusajs/b2b-starter)
- Sylius Plus: B2B Suite, Marketplace Suite (onboarding vendorów, split płatności, raportowanie), Advanced Multi-store, Loyalty, Subscription — [sylius.com/plus](https://sylius.com/plus/)
- Saleor docs: recepty multi-region, produkty cyfrowe, custom shipping, click and collect, marketplace z wieloma sprzedawcami, rozszerzenia dashboardu; narzędzia AI do kodowania — [docs.saleor.io](https://docs.saleor.io/)
- Medusa: sales channels, regiony i waluty w rdzeniu (nielimitowane również w Cloud) — [medusajs.com/pricing](https://medusajs.com/pricing); moduł Translation w liście modułów — [docs Medusa](https://docs.medusajs.com/resources/commerce-modules)
- Saleor Cloud: Essential Apps obejmują MCP, ACP/UCP (agentic commerce), Ink AI Search; Medusa Cloud: MCP server i Development Agent na każdym planie — [saleor.io/pricing](https://saleor.io/pricing), [medusajs.com/pricing](https://medusajs.com/pricing)
- Marketplace na Medusie: Mercur (warstwa społeczności) — [docs.mercurjs.com](https://docs.mercurjs.com/migration/overview)

### Inferences
- Sylius: prawie cała „enterprise" funkcjonalność (multi-store, B2B, marketplace) jest za bramką Plus; w Medusie B2B jest bezpłatny jako starter (N/C – kod startera, nie rdzeń).
- SEO/CMS: wszystkie wymagają własnego storefrontu lub starterów; nie znalazłem w źródłach wbudowanego modułu SEO.

### Gaps
- Funkcje marketingowe (newslettery, kampanie, SEO meta) – nie zweryfikowane.
- Sylius Shop (Twig) i jego aktualna jakość/wiek UI – niezweryfikowane.
- Sylius B2B kit/Elesto, Dafré (marketplace starter) – pojawiły się w wynikach ([sylius.com/?p=28738](https://sylius.com/?p=28738), [sylius.com/?p=29143](https://sylius.com/?p=29143)), treść niezbadana.

## 6. Doświadczenie developera, dojrzałość, zmiany 2025–2026, słabości

### Takeaway
Medusa: TypeScript/Node, moduły + workflows + REST, szybki wzrost, ale v2 bez automatycznej migracji z v1 i z breaking changes w minorach. Saleor: GraphQL + Python/Django, aplikacje i webhooks, najcięższy stack. Sylius: Symfony/API Platform (REST), najbliższy architektonicznie do naszego stacku, z Plus jako bramką funkcji.

### Cited Findings
- Sylius 2.x: seria 2.2 wypuszczana w 2026 (v2.2.6 z 2 czerwca, v2.2.8 z 31 lipca – wg agregatów), rdzeń przeszedł na 2.3.0-dev w sierpniu; Standard wymaga Symfony 7.4; tabela cyklu wydań w oficjalnych docs jest nieaktualna; brak oficjalnej pełnej listy funkcji 2.2 w wynikach — [docs.sylius.com/release-cycle](https://docs.sylius.com/release-cycle), [sylius.com/blog](https://sylius.com/blog/); wersje z agregatorów (niezweryfikowane bezpośrednio)
- Sylius 2 Live (ogłoszenie): [sylius.com/blog/sylius-2-is-live](https://sylius.com/blog/sylius-2-is-live/) – treść nie została przeczytana
- Medusa: v2 wydane bez automatycznej ścieżki migracji z v1 (źródło: konkurent Swell, nastawienie stronnicze), kolejne minory zawierały breaking changes (v2.5.0 – payment provider); produkty zaczęły wymagać shipping profile — [swell.is](https://www.swell.is/content/medusa-limitations), [newreleases v2.5.0](https://newreleases.io/project/github/medusajs/medusa/release/v2.5.0)
- Medusa: workflows, API keys (moduł), moduł Auth, MCP i Development Agent, GitHub push-to-deploy w Cloud — [docs Medusa](https://docs.medusajs.com/resources/commerce-modules), [pricing](https://medusajs.com/pricing)
- Saleor: GraphQL API jako główny interfejs, aplikacje i marketplace aplikacji, dashboard rozszerzalny — [docs.saleor.io](https://docs.saleor.io/)
- Saleor: hosting samodzielny wymaga Pythona, Redis i Celery — [openreplay (agregator)](https://blog.openreplay.com/5-open-source-ecommerce-platforms/)
- Popularność wg agregatora: Medusa 37,1 tys. gwiazdek, Saleor 23,5 tys., Sylius ok. 8,3 tys. — [StackShare/porównania](https://stackshare.io/medusajs/vs/sylius); bezpośrednio z GitHuba: 37,3k / 23,6k / 8,6k (linki w sekcji 1)

### Inferences
- „Table stakes" platform headless wynikające ze źródeł: API-first (REST/GraphQL), moduły/pluginy, webhooks/events, kanały/regiony/waluty, rozszerzalny admin, klient/storefront starter, hosting zarządzany jako opcja.
- Słabość Sylius (odczucie, niepotwierdzone źródłem): mały ekosystem, wtyczki PL na 1.x; Medusa: niestabilność API między minorami; Saleor: zależność od GraphQL i Cloud-only apps.

### Gaps
- Dane o webhookach/eventach Sylius i Saleor, systemie pluginów Sylius 2 (Symfony bundle) – tylko pośrednio.
- Anegdoty deweloperów (HN/Reddit) o słabościach – nie znalazłem rzetelnych źródeł; wyniki to agregatory/porównania SEO o niskiej wiarygodności.
- Notable customers, funding, cadence releasów – niezweryfikowane.
- Vendure i commercetools – nie zbadane (brak budżetu wyszukiwań).
- Zmiany Saleor 3.2x (np. 3.20–3.22) – niezweryfikowane (próba pobrania roadmapy: 404).
