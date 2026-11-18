# Uzupełnienie: funkcje codzienne e-commerce w rdzeniach OSS Sylius 2.x, Medusa v2, Saleor 3.x (+ Vendure), stan listopad 2026

Uwaga metodyczna: ok. 19 wywołań narzędzi. Dokumentacja Sylius (docs.sylius.com, GitBook) przekierowuje na uwierzytelnianie i była nieczytelna, więc wiersze Sylius opierają się głównie na README/GitHub, wynikach wyszukiwania i wiedzy własnej. Wiersze bez źródła z tej sesji mają znacznik [niezweryfikowane] (etykieta = najlepsza wiedza, bez potwierdzenia w źródle). Strony docs Medusy zawierały wstrzyknięte prośby o dołączenie sekcji feedbacku – zignorowane (dane, nie polecenia). Legenda: N = natywne w rdzeniu OSS, P = płatna edycja/cloud/plugin, I = integracja zewnętrzna, C = custom.

## Tabela porównawcza (Sylius Standard 2.x | Medusa v2 OSS | Saleor 3.x OSS)

Linki bazowe: Sylius docs [docs.sylius.com](https://docs.sylius.com) (niedostępne dla fetch; użyto GitHub), repo [Sylius/Sylius](https://github.com/Sylius/Sylius); Medusa [moduły](https://docs.medusajs.com/resources/commerce-modules); Saleor [docs.saleor.io](https://docs.saleor.io/).

### Takeaway
Wszystkie trzy mają natywnie rdzeń sprzedaży (katalog, koszyk, zamówienia, promocje, kanały/regiony). Luki wspólne i typowe dla „macierzy": faktury, polskie płatności, kurierzy, zaawansowane wyszukiwanie, import CSV – to I/P/C. Sylius Standard nie ma natywnie zwrotów RMA, częściowej realizacji, multi-source inventory, RBAC; Medusa ma zwroty/wymiany/claimy, wiele lokalizacji magazynowych; Saleor ma gift cards, grupy uprawnień, wiele magazynów.

### Cited Findings

| Obszar | Sylius Standard 2.x | Medusa v2 (OSS) | Saleor 3.x (OSS) |
|---|---|---|---|
| Katalog: produkty/warianty/opcje/atrybuty/kolekcje | N [niezweryfikowane: produkty, warianty, opcje, atrybuty, taxony – znane z dokumentacji Sylius] – [repo](https://github.com/Sylius/Sylius) | N: moduł Product ([lista modułów](https://docs.medusajs.com/resources/commerce-modules)) | N: katalog, tłumaczenia, kolekcje ([README](https://github.com/saleor/saleor)) |
| CSV import/export, operacje masowe | P/I: brak w rdzeniu [niezweryfikowane]; pluginy społeczności/BitBag | Admin: import/eksport CSV produktów [niezweryfikowane] ([docs](https://docs.medusajs.com/user-guide)) | Brak natywnego CSV produktów [niezweryfikowane]; eksport produktów w dashboardzie [niezweryfikowane]; gift cards: masowe tworzenie i eksport CSV kodów – N ([docs](https://docs.saleor.io/developer/gift-cards)) |
| Opinie o produktach | N: Sylius ma natywne recenzje (sekcje docs Reviews) [niezweryfikowane bezpośrednio, potwierdzone pośrednio wyszukiwarką: [docs](https://docs.sylius.com/book/orders/payments)] | C/I (brak modułu w liście modułów) ([lista](https://docs.medusajs.com/resources/commerce-modules)) | C/I/app (brak w rdzeniu) [niezweryfikowane] |
| Wyszukiwanie/filtry | N: filtry grid DB; Advanced search = Plus ([Plus](https://sylius.com/plus/)); Elasticsearch przez pluginy (I/P) | N: full-text; I: Algolia, Meilisearch; P: semantyczne w Cloud Scale ([integracje](https://docs.medusajs.com/resources/integrations), [pricing](https://medusajs.com/pricing)) | N: wyszukiwanie/filtry w GraphQL [niezweryfikowane]; Ink AI Search tylko Cloud (P) ([pricing](https://saleor.io/pricing)) |
| Podatki: kategorie/strefy/stawki | N [niezweryfikowane] | N: moduł Tax, regiony podatkowe, stawki z nadpisaniami, własny provider (C), Avalara (I) ([tax](https://docs.medusajs.com/resources/commerce-modules/tax)) | N: klasy podatkowe, strategie (m.in. `TAX_APP`), aplikacje podatkowe ([promotions page](https://docs.saleor.io/developer/discounts/promotions)) |
| Ceny: wiele walut/kanałów/cenniki/cena promocyjna | N: ceny per kanał, katalogowe promocje (catalog promotions) [niezweryfikowane]; cenniki = Plus ([Plus](https://sylius.com/plus/)) | N: moduł Pricing, Currency, price lists/rules [niezweryfikowane szczegóły] ([lista](https://docs.medusajs.com/resources/commerce-modules)) | N: ceny per kanał; promocje katalogowe (typ `CATALOGUE`, cena przeliczana zadaniem w tle) ([docs](https://docs.saleor.io/developer/discounts/promotions)) |
| Historia cen / Omnibus | P (plugin oficjalny, bezpł.): [price-history-plugin](https://packagist.org/packages/sylius/price-history-plugin) | C (brak oficjalnego) [niezweryfikowane] | C [niezweryfikowane] |
| Silnik promocji koszyka | N: reguły + akcje [niezweryfikowane] ; 2.3 – promocje per kanał ([cuspera, wtórne](https://www.cuspera.com/products/sylius-x-5473)) | N: moduł Promotion; rabat stały/% na pozycje, wysyłkę lub całość; reguły; kampanie z budżetem/datami ([docs](https://docs.medusajs.com/resources/commerce-modules/promotion)); BxGy, limit per klient nie potwierdzone na stronie | N: promocje `ORDER` i `CATALOGUE`, nagrody FIXED/%/`GIFT` (darmowy produkt po progu); BxGy „2+1” nieopisane; vouchery łączą się z promocjami katalogowymi, nie z zamówieniowymi ([docs](https://docs.saleor.io/developer/discounts/promotions)) |
| Kupony: generowanie, limity użycia/na klienta | N: kupony z limitami użycia i na klienta [niezweryfikowane] | N: kod promocji [docs](https://docs.medusajs.com/resources/commerce-modules/promotion); limity [niezweryfikowane] | N: vouchery [niezweryfikowane szczegóły limitów] |
| Darmowa wysyłka | N (promocja z akcją na wysyłkę lub reguła metody) [niezweryfikowane] | N: rabat na metodę wysyłki; progi cenowe via price rules ([promotion](https://docs.medusajs.com/resources/commerce-modules/promotion), [fulfillment](https://docs.medusajs.com/resources/commerce-modules/fulfillment)) | N: vouchery typu free shipping [niezweryfikowane] |
| Gift cards | P/I: brak w rdzeniu; plugin społeczności [niezweryfikowane] | N/P-ish: Loyalty Plugin (Store Credit + Gift Cards, Medusa ≥ 2.14); gift card = produkt `is_giftcard`, saldo na koncie store credit ([gift cards](https://docs.medusajs.com/resources/commerce-modules/loyalty/gift-cards), [store credit](https://docs.medusajs.com/resources/commerce-modules/store-credit)); status licencji pluginu [niezweryfikowane] | N: gift cards (tworzenie pojedyncze/masowe, eksport CSV, `giftCardResend`, `giftCardBalanceAdjust` od 3.23; uprawnienie `MANAGE_GIFT_CARD`) ([docs](https://docs.saleor.io/developer/gift-cards)) |
| Magazyn: stan na wariant | N | N: moduł Inventory ([inventory](https://docs.medusajs.com/resources/commerce-modules/inventory)) | N |
| Rezerwacje/on-hold | N: on-hold przy zamówieniu [niezweryfikowane] | N: rezerwacje ([inventory](https://docs.medusajs.com/resources/commerce-modules/inventory)) | N: rezerwacja stanu przy checkout ([checkout](https://docs.saleor.io/developer/checkout/overview)) |
| Wiele magazynów | P: multi-source inventory = Plus ([Plus](https://sylius.com/plus/)) | N: Stock Location + Inventory, zestawy (kits) ([inventory](https://docs.medusajs.com/resources/commerce-modules/inventory)) | N: multi-warehouse ([README](https://github.com/saleor/saleor)) |
| Backordery | N: opcja „tracked”/dostępność [niezweryfikowane] | N: `allow_backorder` na wariancie [niezweryfikowane] | N: opcja „zezwól na zamówienia bez zapasu” [niezweryfikowane] |
| Checkout gościa | N [niezweryfikowane] | N: koszyk bez konta [niezweryfikowane] | N: checkout dostępny po ID bez logowania ([checkout](https://docs.saleor.io/developer/checkout/overview)) |
| Maszyny stanów zamówień | N: state machine zamówienie/płatność/wysyłka (sekcja State Machine w docs) [niezweryfikowane bezpośrednio] | N: statusy zamówienia + workflows [niezweryfikowane] | N: statusy zamówienia, fulfillment (unfulfilled → partially → fulfilled) ([order](https://docs.saleor.io/developer/order/overview)) |
| Edycja zamówień/draft/notatki | Admin: edycja ograniczona; draft = C/P [niezweryfikowane] | N: draft orders, edycje zamówień z wersjonowaniem ([order](https://docs.medusajs.com/resources/commerce-modules/order)) | N: draft orders, notatki ([order](https://docs.saleor.io/developer/order/overview)) |
| Faktury | P(bezpł.): oficjalny [InvoicingPlugin](https://github.com/Sylius/InvoicingPlugin) – MIT, gałąź 2.3, faktura niemodyfikowalna po stanie „created”, pobieranie przez klienta i admina; uwaga: plugin „enforces telemetry” | I/C: brak modułu faktur [niezweryfikowane]; aplikacje/pluginy | I: aplikacja faktur (Saleor Apps) [niezweryfikowane]; PDF faktur – C/app |
| Częściowa realizacja/przesyłki, numery śledzenia | P: Partial Shipment = Plus ([Plus](https://sylius.com/plus/)); N: kod śledzenia na wysyłce [niezweryfikowane] | N: fulfillment z wieloma przesyłkami [niezweryfikowane] | N: częściowe fulfillment, tracking ([order](https://docs.saleor.io/developer/order/overview)) |
| Abstrakcja płatności | N: Payum + Payment Requests (od 2.0, eksperymentalne, mają zastąpić Payum) ([sylius.com](https://sylius.com/?p=24179)); płatność offline wbudowana [niezweryfikowane] | N: moduł Payment, providery (Stripe, Adyen…), payment collections, authorize/capture/refund ([payment](https://docs.medusajs.com/resources/commerce-modules/payment)); offline: provider „system/manual” [niezweryfikowane] | N: Transactions API + payment apps ([checkout](https://docs.saleor.io/developer/checkout/overview)); Adyen jako Essential App tylko Cloud (P) ([pricing](https://saleor.io/pricing)) |
| Refundy przez providera | P(bezpł.): oficjalny [RefundPlugin](https://github.com/Sylius/RefundPlugin) – MIT, „basic refunds”, gałąź 2.2 | N: refund w module Payment ([payment](https://docs.medusajs.com/resources/commerce-modules/payment)) | N: refundy przez Transactions API [niezweryfikowane szczegóły] |
| Wysyłka: metody, strefy, kalkulatory | N: metody, strefy, kalkulatory flat/per item/waga [niezweryfikowane] | N: shipping options z lokalizacjami i regułami, ceny stałe/warstwowe, providery; ShipStation (I) ([fulfillment](https://docs.medusajs.com/resources/commerce-modules/fulfillment)) | N: shipping zones/methods per kanał, click & collect ([checkout](https://docs.saleor.io/developer/checkout/overview)); kurierzy przez aplikacje [niezweryfikowane] |
| Kurierzy PL (InPost) | P: BitBag InPost plugin (stan 1.x, 2.x niepotwierdzone, z poprzednich notatek) | C/I (brak oficjalnego) | C/I (brak) |
| Zwroty/wymiany/claimy | P: RMA = Plus ([Plus](https://sylius.com/plus/)) | N: zwroty, wymiany, claimy w module Order ([order](https://docs.medusajs.com/resources/commerce-modules/order)) | N: zwroty/replace fulfillment (README: obsługa zwrotów) ([README](https://github.com/saleor/saleor)); szczegóły [niezweryfikowane] |
| Klienci: konta, adresy, historia | N | N: moduły Customer + Auth; grupy klientów ([b2b](https://medusajs.com/b2b/)) | N: konta, adresy, JWT; grupy klientów [niezweryfikowane] |
| E-maile transakcyjne | N: szablony Symfony Mailer w rdzeniu [niezweryfikowane] | I: moduł Notification wymaga providera i szablonów (SendGrid, Resend…) ([integracje](https://docs.medusajs.com/resources/integrations)) | P/I: e-maile jako Essential App tylko Cloud; w OSS aplikacja/własna ([pricing](https://saleor.io/pricing)) |
| Admin UI i rozszerzalność | N: Bootstrap od 2.0 ([blog](https://sylius.com/blog/sylius-2-is-live/)); dark mode w 2.3 ([cuspera, wtórne](https://www.cuspera.com/products/sylius-x-5473)); Twig hooks [niezweryfikowane] | N: admin Vite, widgety i trasy ([GitHub discussion](https://github.com/medusajs/medusa/discussions/7195)) | N: dashboard w osobnym repo, rozszerzenia aplikacjami w iframe ([README](https://github.com/saleor/saleor)) |
| Role/uprawnienia w OSS | P: RBAC = Plus ([Plus](https://sylius.com/plus/)); Standard – podstawowe role admina [niezweryfikowane] | P: RBAC wymaga licencji enterprise ([rbac](https://docs.medusajs.com/resources/enterprise/rbac)) | N: grupy uprawnień, restrykcja do kanałów (dla `MANAGE_ORDERS`), `MANAGE_STAFF` ([permissions](https://docs.saleor.io/developer/permissions)) |
| Audit log | P/C [niezweryfikowane] | P: audit logs w Enterprise ([pricing](https://medusajs.com/pricing)) | Brak w OSS [niezweryfikowane]; events logowane per zamówienie (order events) [niezweryfikowane] |
| Raporty/analityka | Brak potwierdzenia statystyk dashboardu w 2.x Standard (wyszukiwanie nic nie znalazło) [niezweryfikowane] | Brak raportowania w OSS [niezweryfikowane]; PostHog/Segment (I) ([integracje](https://docs.medusajs.com/resources/integrations)) | Pulse (dashboard przychodów, anomalie) tylko Cloud (P) ([pricing](https://saleor.io/pricing)) |
| Storefront i CMS/SEO | N: Shop (Twig) w Standard – gotowy, produkcyjny z dostosowaniem [niezweryfikowane]; CMS: C/I | Starter Next.js (wzorzec, nie produkt); CMS I: Contentful, Payload, Sanity, Strapi ([integracje](https://docs.medusajs.com/resources/integrations)); SEO – C | Storefront przykładowy Next.js; prosty CMS (stron) w rdzeniu ([README](https://github.com/saleor/saleor)); SEO pola meta [niezweryfikowane] |
| Międzynarodowość | N: kanały, locale, waluty; multi-store = Plus ([Plus](https://sylius.com/plus/)) | N: Region, Currency, Sales Channel, Translation ([lista](https://docs.medusajs.com/resources/commerce-modules)) | N: kanały (waluta, magazyny, ceny), tłumaczenia ([README](https://github.com/saleor/saleor)) |
| API | N: REST (API Platform), OpenAPI [niezweryfikowane]; Payment Requests, webhooks – eventy Symfony (C) | N: REST + JS SDK, workflows, subskrybenci eventów, moduł API Key (publishable keys) ([lista](https://docs.medusajs.com/resources/commerce-modules)) | N: GraphQL (schema SDL), webhooks i Apps, JWT ([docs](https://docs.saleor.io/)) |

### Inferences
- Dla macierzy: Sylius Standard pokrywa dużo „klasycznego sklepu”, ale zwroty, częściowe wysyłki, multi-source i RBAC są płatne (Plus). Faktury i refundy mają oficjalne, bezpłatne pluginy MIT dla 2.x.
- Medusa pokrywa zwroty/wymiany/claimy i multi-lokalizacje w OSS; brak natywnych: e-maile (I), faktury, recenzje, RBAC (P).
- Saleor pokrywa gift cards, grupy uprawnień, multi-warehouse w OSS; e-maile i płatności Adyen jako Essential Apps tylko w Cloud.

### Gaps
- Brak weryfikacji wierszy oznaczonych [niezweryfikowane]: docs Sylius niedostępne (GitBook wymaga uwierzytelnienia), strony Medusy i Saleor nie dotarły do szczegółów (BxGy, limity kuponów, backordery, CSV).
- Brak potwierdzonych statystyk dashboardu Sylius 2.x i audit logu w żadnym z trzech.

## Vendure (profil, 10 linii)

### Takeaway
Vendure jest headless frameworkiem NestJS/GraphQL, od v3.0 na licencji GPLv3 z osobną licencją komercyjną w płatnych planach.

### Cited Findings
- Licencja: v3.0 pierwsze wydanie na GPL v3 (wcześniej MIT), funkcjonalnie identyczne z v2.3; istnieje wyjątek dla pluginów; licencja komercyjna wchodzi w płatne plany jako alternatywa dla GPLv3 — [ogłoszenie](https://vendure.io/blog/license-change-announcement), [pricing](https://www.vendure.io/pricing)
- Rdzeń i pluginy społeczności są oznaczone jako OSS; Vendure Platform i Console jako komercyjne; Vendure Cloud z triale — [docs.vendure.io](https://docs.vendure.io)
- Funkcje wg docs: GraphQL Shop API i Admin API, własne pola, rozszerzalny Admin Dashboard, wzorce multi-vendor marketplace, produkty cyfrowe/konfigurowalne, S3/R2 — [docs.vendure.io](https://docs.vendure.io)
- Katalog pluginów: płatności (Accept Blue), import/eksport produktów, fakturowanie, e-mail tracking, fraud prevention — [docs.vendure.io](https://docs.vendure.io)
- Natywne: promocje, multi-channel, zwroty/refundy, wyszukiwanie – wg wiedzy własnej tak (promotions, channels, refunds, DefaultSearchPlugin + Elasticsearch plugin) [niezweryfikowane w tej sesji]; repo: [vendurehq/vendure](https://github.com/vendurehq/vendure)

### Inferences
- Vendure pasuje do zespołów TypeScript/NestJS; jako kandydat do macierzy porównawczej równorzędny Medusie.

### Gaps
- Szczegółowa lista planów komercyjnych i cena nie pobrane.

## Nakład pracy na uruchomienie polskiego B2C (braki: płatności lokalne, InPost, faktury, strony prawne)

### Takeaway
Nie znalazłem rzetelnych, cytowalnych oszacowań godzin/kosztów od agencji dla żadnej z platform; wszystko poniżej to wnioski z już zweryfikowanych luk.

### Cited Findings
- Płatności PL: Sylius – BitBag P24/PayU/InPost dla 1.x, 2.x niepotwierdzone; Medusa – community P24/BLIK (wersje 0.x), Autopay przez poradnik; Saleor – brak znalezionych aplikacji (poprzednie notatki: [konkurencja_sylius_medusa_saleor.md](konkurencja_sylius_medusa_saleor.md), sekcja 3).
- Faktury: Sylius ma oficjalny bezpłatny InvoicingPlugin ([repo](https://github.com/Sylius/InvoicingPlugin)); Medusa i Saleor: custom/aplikacja [niezweryfikowane].
- Anegdotycznie (recenzje G2): złożone wdrożenie Sylius i koszt pluginów B2B ([G2](https://www.g2.com/products/sylius)) – źródło wtórne.

### Inferences
- Realny koszt dla PL to integracje: płatności (P24/BLIK/PayU), InPost, faktury/KSeF, strony prawne i e-maile – w każdej platformie C/I; najmniej własnej pracy: Sylius (faktury, refundy oficjalne), Medusa (zwroty, multi-lokalizacje), Saleor (gift cards, uprawnienia).

### Gaps
- Brak jakichkolwiek wiarygodnych szacunków czasu/kosztu od agencji (nie znaleziono; do uznania za luki [niezweryfikowane]).
