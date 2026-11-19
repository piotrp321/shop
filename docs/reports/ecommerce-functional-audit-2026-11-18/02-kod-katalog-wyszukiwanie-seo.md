# Audyt funkcjonalny: katalog, wyszukiwanie, SEO i treść (rewizja 1aeb5076)

Metoda: statyczny odczyt kodu (bez uruchamiania stosu). Punkty wyjścia: lista endpointów z `apps/api/openapi.json`
(sklep, 27 ścieżek) i `apps/api/openapi-admin.json` (panel), trasy `apps/store/app/**`, router panelu
`apps/admin/src/router/index.ts`, domena `apps/api/src/Catalog/Domain`, testy `e2e/tests/*.spec.ts`. Braki potwierdzone
wyszukiwaniem całych słów w `apps/api/src`, `apps/store/{src,app}`, `apps/admin/src` (trafienia tylko w generatorze danych
demo `DemoData/` i `Catalog/Infrastructure/Demo/` nie liczą się jako funkcja).

Kluczowy fakt: agregat `Product` ma wyłącznie: nazwę, slug, krótki opis (≤500 znaków, czysty tekst), kategorię główną
i dodatkowe, klasę VAT, ≤10 zdjęć, ≤2 osie wariantów, warianty (id + wartości osi), status draft/published
(`apps/api/src/Catalog/Domain/Product/Product.php:19-31`, `ShortDescription.php:6`, `ProductImages.php:6`,
`VariantAxes.php:7`). `ProductVariant` to tylko `id` + `values` (`ProductVariant.php`). `Category`: nazwa, slug,
rodzic, pozycja (`Domain/Category/Category.php:17-25`). Specyfikacja świadomie wyklucza: długi opis, pola SEO, markę,
SKU/EAN, zdjęcia per wariant, import/eksport, operacje masowe, usuwanie produktu, generator kombinacji
(`docs/features/catalog-admin/spec.md:30-41`).

## 1. Katalog produktów

| Funkcja | Status | Co działa / czego brakuje | Dowód | Znaczenie braku |
|---|---|---|---|---|
| Tworzenie i edycja produktu w panelu | KOMPLETNA (wąska) | Formularz: nazwa, slug (auto z nazwy), krótki opis, kategoria główna + dodatkowe, klasa VAT, zdjęcia, warianty; optimistic locking; ostrzeżenie o niezapisanych zmianach; role admin/marketing, support tylko odczyt. E2E: utworzenie → publikacja → cena → przyjęcie stanu → edycja → wycofanie | `apps/admin/src/views/ProductFormView.vue:283-405`; `POST/PUT /api/v1/admin/products`; `e2e/tests/catalog-admin.spec.ts:60,160,181` | – |
| Status publikacji | KOMPLETNA | Szkic/opublikowany, osobne akcje publish/unpublish; publikacja wymaga zdjęcia; sklep nie widzi szkiców (ES, strona 404). Brak publikacji zaplanowanej (od daty) i widoczności per kanał | `Product.php:126-156`; `POST /admin/products/{id}/publish` | Planowanie publikacji: OPCJONALNE |
| Podgląd szkicu w sklepie | BRAK | „Podgląd w sklepie” tylko dla opublikowanych; szkic ma 404 | `ProductsView.vue:67`; spec catalog-admin:41 | OPCJONALNE (marketing chce zobaczyć kartę przed publikacją) |
| Usuwanie produktu | BRAK (decyzja K1) | Tylko wycofanie publikacji; produktów się nie usuwa | spec catalog-admin K1 | OPCJONALNE (lista panelu rośnie o śmieci; filtr statusu łagodzi) |
| Duplikowanie produktu | BRAK | Brak akcji i endpointu | openapi-admin.json (brak) | ISTOTNE: przy podobnych produktach (te same osie, kategorie) oszczędza minuty na każdym |
| Długi opis / rich text | BRAK | Tylko krótki opis 500 znaków, czysty tekst z `\n`; brak HTML/Markdown, tabel, sekcji | `ShortDescription.php:6`; `ProductFormView.vue:387-392` | BLOKER: realna karta produktu potrzebuje opisu, parametrów, składu, instrukcji; bez tego słabe SEO i konwersja |
| Atrybuty/cechy (parametry techniczne) | BRAK | Jedyne „cechy” to max 2 osie wariantów (np. Kolor, Rozmiar); brak np. materiału, wagi, wodoodporności | `VariantAxes.php:7`; brak modelu atrybutów | BLOKER dla sklepu sportowego/technicznego (porównanie produktów, filtry, Google Merchant); ISTOTNE dla prostego sklepu |
| Marka / producent | BRAK | Marka tylko w nazwie (generator demo skleja „typ marka model”); brak pola, strony marki, filtra | `DemoData/Catalog/ProductGenerator.php:88-89`; spec search:28 | ISTOTNE (filtr marki to jeden z najczęściej używanych filtrów; GPSR wymaga danych producenta → dla UE raczej BLOKER prawny) |
| SKU / EAN / GTIN | BRAK | Wariant nie ma kodu; magazyn, ceny i zamówienia identyfikują wariant UUID-em i etykietą „czarne, 42” | `ProductVariant.php`; zero trafień `sku`/`ean`/`gtin` jako słów | BLOKER: kompletacja, integracja z ERP/hurtownią/kurierem, feedy porównywarek wymagają kodów |
| Waga i wymiary | BRAK | Brak pól (trafienia „weight” tylko w generatorze demo) | grep | BLOKER przy realnej wysyłce (koszt dostawy, etykiety), gdy pojawi się moduł dostaw |
| Warianty | KOMPLETNA (z limitami) | Do 2 osi, wiersze dodawane pojedynczo, reguły tożsamości po publikacji, blokada usunięcia wariantu ze stanem; cena i stan per wariant z karty produktu (okna cen i stanów) | `ProductVariants.vue`; `ProductPriceStock.vue:17-23`; spec catalog-admin K5 | Brak generatora kombinacji: ISTOTNE (koszulka 5 kolorów × 6 rozmiarów = 30 ręcznych wierszy); limit 2 osi: OPCJONALNE |
| Zdjęcia | KOMPLETNA | Upload (JPEG/PNG/WebP/AVIF/HEIC, 64 MB, 50 MP, walidacja treści), alt, kolejność, usuwanie, ≤10 zdjęć; imgproxy WebP srcset; galeria z miniaturami i powiększeniem w sklepie | `UploadProductImageController.php`; `ProductImages.vue:203-268`; `ProductGallery.tsx`; `e2e/tests/product-gallery.spec.ts` | – |
| Zdjęcia per wariant | BRAK (decyzja E2) | Wybór koloru nie zmienia zdjęcia | spec product-page:33 | ISTOTNE w modzie/sporcie (klient chce zobaczyć wybrany kolor) |
| Wideo | BRAK | – | grep | OPCJONALNE |
| Kategorie: drzewo w panelu | KOMPLETNA | Dodanie, zmiana nazwy/sluga, przeniesienie (menu i drag&drop), usunięcie pustej; obsługa klawiaturą; przekierowanie 308 starych adresów po zmianie sluga | `apps/admin/src/views/CategoriesView.vue`; `POST /admin/categories/{id}/move`; `GET /api/v1/categories/redirect`; `e2e/tests/catalog-admin.spec.ts:234,348,440,513` | – |
| Kategorie: opis, zdjęcie, SEO, ukrycie | BRAK | Kategoria ma tylko nazwę i slug; strona kategorii ma generowany opis „Produkty z kategorii X…” | `Category.php:17-25`; `apps/store/src/catalog/page-metadata.ts:33` | ISTOTNE dla SEO (tekst kategorii to główne źródło ruchu organicznego w e-commerce) |
| Lista produktów w panelu | KOMPLETNA | Szukanie po nazwie, filtr kategorii, statusu, „bez zdjęć”, sortowanie nazwa/ostatnia zmiana, stronicowanie, wybór liczby na stronę | `apps/admin/src/views/ProductsView.vue:96-170`; `useProductList.ts:89,189` | – |
| Masowa edycja / operacje masowe | BRAK | Brak zaznaczania wierszy, masowej publikacji, zmiany kategorii, cen | `ProductsView.vue` (brak checkboxów); spec catalog-admin:35 | ISTOTNE (przy 10 tys. produktów każda zmiana asortymentu to klik po kliku); masowa zmiana cen = ISTOTNE przy wyprzedażach |
| Import / eksport CSV/XML | BRAK | Jedyne źródło masowe: seed demo z CLI (`SeedDemoCatalogCommand`, `DemoData/`); trafienia „csv” w API to nazwa klasy | `UI/Console/SeedDemoCatalogCommand.php`; openapi-admin.json | BLOKER: sprzedawca z istniejącym asortymentem (hurtownia, Excel, migracja z innej platformy) nie wprowadzi tysięcy produktów ręcznie |
| Produkty cyfrowe | BRAK | – | grep `digital` 0 | NIEWARTE teraz (sklep sportowy) |
| Zestawy / bundle | BRAK | trafienia „bundle” to Symfony bundles | grep | OPCJONALNE |
| Powiązane produkty, cross-sell, up-sell | BRAK | Karta produktu nie ma sekcji „podobne”/„kup razem” | `ProductDetailsView.tsx`; brak w API | ISTOTNE (średnia wartość koszyka) |
| Historia zmian produktu | CZĘŚCIOWA | Tylko id pracownika ostatniej zmiany | spec catalog-admin:40 | OPCJONALNE |

## 2. Wyszukiwanie, filtrowanie i odkrywanie produktów

| Funkcja | Status | Co działa / czego brakuje | Dowód | Znaczenie braku |
|---|---|---|---|---|
| Wyszukiwanie pełnotekstowe | KOMPLETNA | ES: polska odmiana (`polish_stem`), bez polskich znaków (`asciifolding`), literówki (fuzziness AUTO, słabsza gałąź), pola: nazwa^3, kategorie^2, krótki opis; fraza 2–100 znaków; pole w nagłówku każdej strony, działa bez JS | `Infrastructure/Search/ProductIndexDefinition.php:165-174`; `ProductSearchQuery.php:79-100`; `ListProductsQuery.php:22`; `e2e/tests/search.spec.ts:15-112` | – |
| Synonimy | BRAK | Brak filtra synonimów w analizatorze („kurtka” ≠ „wiatrówka”) | `ProductIndexDefinition.php:165-174` | OPCJONALNE |
| Autocomplete / podpowiedzi / „czy chodziło ci o” | BRAK (poza zakresem) | – | spec search:25 | ISTOTNE (duży udział wyszukiwań zaczyna się od podpowiedzi; Shopify/Shoper mają to w standardzie) |
| Wyszukiwanie po kodzie (SKU/EAN) | BRAK | Brak kodów | – | ISTOTNE po dodaniu kodów |
| Fasety | CZĘŚCIOWA | Podkategorie z liczbami, wartości osi wariantów (do 10 osi × 30 wartości) z liczbami, cena od–do, „Tylko dostępne”; aktywne filtry z usuwaniem pojedynczo i hurtem; bez JS jako formularz. Brak faset atrybutów i marki (bo brak danych) | `ProductSearchQuery.php:111-188`; `ListingFilters.tsx`, `ActiveFilters.tsx`; `e2e/tests/listing-filters.spec.ts:40-284` | Filtr marki/parametrów: ISTOTNE |
| Sortowanie | CZĘŚCIOWA | Trafność (tylko z frazą), nazwa, cena rosnąco/malejąco. Brak: nowości, popularność/bestsellery, promocje, oceny | `ListProductsQuery.php:13-16`; spec search:29 | „Nowości” i „popularne”: ISTOTNE (domyślne sortowanie alfabetyczne jest nietypowe dla sklepu) |
| Paginacja | CZĘŚCIOWA | Tylko „poprzednia/następna”, bez numerów stron i skoku na stronę; 24 na stronę (API do 48) | `apps/store/src/catalog/Pagination.tsx:4-6` | OPCJONALNE (UX przy dużych kategoriach) |
| Pusty wynik | KOMPLETNA | Komunikat + „Wyczyść filtry”/wyjście z wyszukiwania | `EmptyResults.tsx`; `listing-filters.spec.ts:206` | Brak propozycji alternatyw: OPCJONALNE |
| Mega menu, breadcrumbs | KOMPLETNA | Mega menu działów z zagnieżdżonymi podkategoriami; breadcrumbs na listingu i karcie (z JSON-LD BreadcrumbList) | `MegaMenu.tsx`; `Breadcrumbs.tsx`; `e2e/tests/site-header.spec.ts` | – |
| Strona główna | CZĘŚCIOWA | Hero + kafelki działów + lista wszystkich produktów; treść i zdjęcia zaszyte w kodzie (brak CMS) | `apps/store/src/home/home-content.ts:1-2`; `app/page.tsx` | Edycja z panelu: ISTOTNE (zmiana banera = deploy programisty) |
| Bestsellery, nowości, polecane, ostatnio oglądane, rekomendacje | BRAK | Brak sekcji i danych | grep 0 | ISTOTNE (bestsellery/nowości) / OPCJONALNE (rekomendacje, ostatnio oglądane) |
| Oznaczenie promocji na kafelku | KOMPLETNA | Odznaka „Promocja” + cena Omnibus (najniższa z 30 dni) na kafelku i karcie | `PromotionNotice.tsx:6-18`; `TileOffer.tsx:58` | – |
| Wydajność listingu | Do potwierdzenia | Varnish przed API, TTL list 60 s, unieważnianie tagami; przygotowane pod 10 mln produktów | spec search S11; `docs/vision.md:105-130` | – |

## 3. SEO i treść

| Funkcja | Status | Co działa / czego brakuje | Dowód | Znaczenie braku |
|---|---|---|---|---|
| Przyjazne adresy | KOMPLETNA | `/p/<slug>`, `/c/<ścieżka slugów>`, przekierowanie 308 starych adresów kategorii i `/?category=<id>` | `app/c/[...path]/page.tsx`; `app/page.tsx` (permanentRedirect); `catalog-admin.spec.ts:234` | – |
| Zmiana sluga produktu + 301 | BRAK (decyzja K4) | Slug zablokowany od pierwszej publikacji, więc 301 niepotrzebne; ale literówki w slugu po publikacji nie da się poprawić | spec catalog-admin K4 | OPCJONALNE |
| Ręczne przekierowania (np. po migracji z innej platformy) | BRAK | – | openapi-admin.json | ISTOTNE przy migracji istniejącego sklepu (utrata pozycji w Google) |
| Metadane | CZĘŚCIOWA | Tytuł, opis (= krótki opis), canonical, Open Graph ze zdjęciem; noindex,follow dla filtrów/sortowania i `/szukaj`. Brak własnych pól SEO (title/description) edytowalnych w panelu dla produktu i kategorii | `apps/store/src/catalog/product-metadata.ts:14-29`; `page-metadata.ts:18-76`; spec catalog-admin:36 | ISTOTNE |
| Sitemap | KOMPLETNA | Indeks map, mapa listingu, porcje po 10 tys. produktów bez ładowania katalogu do pamięci; bez zdjęć w mapie | `app/sitemap.xml/route.ts`; `app/sitemaps/**`; `GET /api/v1/sitemap/*`; `e2e/tests/seo.spec.ts:35-68` | – |
| robots.txt | KOMPLETNA | Blokuje koszyk/kasę/konto/auth/api, wskazuje sitemap | `apps/store/src/seo/robots-rules.ts` | – |
| JSON-LD | CZĘŚCIOWA | `Product` (nazwa, opis, zdjęcia, Offer per wariant z ceną, dostępnością, priceValidUntil), `BreadcrumbList`, `Organization`+`WebSite` na `/`. Brak `sku`, `gtin`, `brand`, `aggregateRating`, `ItemList` | `apps/store/src/catalog/product-json-ld.ts`; `seo/home-json-ld.ts` | ISTOTNE (Google Merchant/Rich Results zgłaszają brak brand/gtin) |
| Strony statyczne / CMS (regulamin, polityka prywatności, kontakt, o nas, dostawa, zwroty) | BRAK | Brak tras treści; stopka zawiera tylko linki działów i „Zdjęcia: Unsplash” | lista `apps/store/app/**`; `apps/store/src/catalog/SiteFooter.tsx:15-41` | BLOKER prawny (regulamin, dane sprzedawcy, informacja o odstąpieniu, polityka prywatności są w PL obowiązkowe) |
| Blog | BRAK | – | grep 0 | OPCJONALNE |
| Banery / treści strony głównej z panelu | BRAK | Hero i kafelki w `home-content.ts` („There is no CMS, so this is code”) | `apps/store/src/home/home-content.ts:1-2` | ISTOTNE |
| Opinie i oceny produktów | BRAK | – | grep `rating` 0 | ISTOTNE (dowód społeczny, rich snippets; przy wdrożeniu wymagana weryfikacja opinii wg Omnibus) |
| Lista życzeń | BRAK | – | grep 0 | OPCJONALNE |
| Porównywarka | BRAK | – | grep 0 | NIEWARTE teraz (bez atrybutów nie ma czego porównać) |
| Newsletter | BRAK | – | grep 0 | OPCJONALNE (zwykle przez zewnętrzne narzędzie) |
| Powiadomienie o dostępności | BRAK | – | grep 0 | OPCJONALNE |
| Feedy produktowe (Google Merchant, Ceneo) | BRAK | Brak endpointu feedu; brak też danych wymaganych przez feed (GTIN, marka) | grep `ceneo`/`feed` 0 | ISTOTNE (w PL Ceneo i Google Shopping to główne kanały pozyskania) |
| Tagi analityczne (GA4/GTM/Pixel) | BRAK | Jest tylko Sentry/obserwowalność techniczna | `apps/store/src/observability/`; grep `gtag`/`googletagmanager` 0 | ISTOTNE (właściciel nie zmierzy konwersji ani kampanii) |
| Cookie consent | BRAK | Brak banera; dziś brak też narzędzi śledzących, więc formalnie wystarczy informacja w polityce (której też brak) | grep `consent` 0 | Stanie się BLOKEREM w chwili dodania analityki/pikseli |

## Mocne strony

- Wyszukiwarka po polsku zrobiona poprawnie: stemming Stempel, wersja bez znaków diakrytycznych, literówki, fasety
  z liczbami liczonymi „bez własnego warunku” (wartości innych opcji nie znikają po wyborze), działanie bez JS
  (`ProductSearchQuery.php:150-182`). Lepiej niż domyślne wyszukiwanie WooCommerce/Shoper.
- Dojrzałe SEO techniczne: SSR/ISR, canonical, noindex,follow dla kombinacji filtrów, sitemap w porcjach pod 10 mln
  produktów, 308 po zmianie sluga kategorii, unieważnianie stron 404 po publikacji, JSON-LD z ofertą per wariant
  i `priceValidUntil` promocji.
- Omnibus na kafelku i karcie (najniższa cena z 30 dni przed obniżką) – wymóg prawny spełniony w standardzie.
- Spójność danych: optimistic locking w formularzu, reguły tożsamości wariantu po publikacji, blokada usunięcia wariantu
  ze stanem, zmiana klasy VAT przelicza ceny z zachowaniem brutto i wpisem do historii.
- Panel kategorii z drag&drop, pełną obsługą klawiatury i przekierowaniami – lepsze niż w wielu SaaS.
- Obsługa zdjęć: walidacja po treści, HEIC, podpisany imgproxy, WebP srcset, galeria z powiększeniem.
- Gruntowne E2E ścieżek katalogu, wyszukiwania, filtrów i SEO (`e2e/tests/*`).

## Niewygodne w codziennym użyciu

- Brak importu/eksportu i operacji masowych: przy 10 tys. produktów każda zmiana (publikacja, kategoria, cena) to
  pojedyncze kliknięcia; wprowadzenie asortymentu tylko ręcznie lub seedem z CLI.
- Warianty dodawane wierszami pojedynczo, bez generatora kombinacji.
- Szukanie w panelu tylko po nazwie (brak kodów).
- Brak duplikowania produktu i podglądu szkicu.
- Domyślne sortowanie listingu po nazwie (A→Z); brak „nowości”/„popularne”.
- Paginacja tylko poprzednia/następna – na kategorii z setkami stron klient nie przeskoczy dalej.
- Każda zmiana banera/hero wymaga programisty i wdrożenia.
- Literówki w slugu nie da się poprawić po pierwszej publikacji.

## Nadmiarowe względem potrzeb realnego sklepu (na tym etapie)

- Architektura pod 10 mln produktów (porcjowana sitemap, ES z aliasami i reindeksacją w partiach, Varnish z tagami xkey,
  ISR + purge) przy katalogu, którego nie da się nawet zaimportować inaczej niż seedem. Sama w sobie poprawna, ale
  wyprzedza braki podstawowe (opis, kody, import).
- Bardzo rozbudowane reguły tożsamości wariantów (klucz porównania, blokady osi po publikacji) – uzasadnione spójnością
  koszyka/magazynu, ale skomplikowane dla redaktora (komunikaty błędów trzeba zrozumieć).
- Fasety do 10 osi × 30 wartości przy limicie 2 osi na produkt.

## Czego nie udało się rozstrzygnąć z samego kodu (do sprawdzenia w przeglądarce)

1. Czy formularz produktu jest zrozumiały dla nietechnicznej osoby (slug, klasa VAT, osie wariantów, komunikaty K5 po
   publikacji) i ile czasu zajmuje dodanie produktu z 30 wariantami.
2. Jak wygląda karta produktu z samym 500-znakowym opisem (czy „wygląda na pustą”) i strona kategorii bez tekstu.
3. Jakość wyszukiwania na danych demo: odmiana, brak ogonków, literówki, frazy z marką (marka tylko w nazwie).
4. Działanie mega menu przy głębokim drzewie i na 375 px; paginacja na dużej kategorii.
5. Czy upload kilku zdjęć naraz jest wygodny (pliki wysyłane kolejno) i jak działa zmiana kolejności.
6. Czy publikacja/zmiana ceny widoczna w sklepie mieści się w deklarowanych 60–90 s (TTL list).
7. Wynik Rich Results Test / walidatora schema.org dla karty produktu (ostrzeżenia brand/gtin/sku).
8. Zachowanie przeciągania kategorii z produktami (reindeksacja) i komunikat przy usuwaniu niepustej kategorii.
