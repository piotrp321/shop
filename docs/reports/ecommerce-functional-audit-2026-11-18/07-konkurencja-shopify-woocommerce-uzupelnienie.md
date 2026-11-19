# Uzupełnienie: funkcje natywne Shopify (Basic) i WooCommerce (core) – stan: listopad 2026

Legenda: N = natywne, P = płatna aplikacja/rozszerzenie/wyższy plan, I = integracja zewnętrzna, C = custom. Oznaczenie [W] = potwierdzone odczytem oficjalnej strony w tej sesji; [niezweryfikowane] = wiedza własna, strony nie odczytano lub odczyt nie potwierdził wiersza. Linki: jeden oficjalny na wiersz; część adresów dla wierszy [niezweryfikowane] to adresy z pamięci i mogą wymagać poprawki. Zakres ograniczony do ok. 15 wywołań, stąd duży udział [niezweryfikowane].

## Takeaway

Shopify Basic ma natywnie większość codziennych funkcji (CSV, zniżki z buy X get Y, transfery magazynowe, zwroty self-service, nowe konta bez hasła, Flow na wszystkich planach). WooCommerce core jest wyraźnie uboższy w promocje (brak natywnego BOGO), magazyny wielolokalizacyjne, zwroty self-service, B2B, multi-currency i multi-language, ale ma natywnie raporty z eksportem CSV, zwroty z wywołaniem bramki i narzędzia RODO.

## Cited Findings – tabela

### Katalog

| Funkcja | Shopify (Basic) | WooCommerce (core) |
|---|---|---|
| Import/eksport CSV produktów | N – import/eksport CSV, limit pliku 15 MB, brak historii importów, CSV nie usuwa produktów [W]. [Link](https://help.shopify.com/en/manual/products/import-export/import-products) | N – importer/eksporter CSV w Produkty > Import/Eksport [niezweryfikowane; strona „managing products” go nie opisuje]. [Link](https://woocommerce.com/document/product-csv-importer-exporter/) |
| Edytor zbiorczy | N – bulk edit w arkuszu w adminie i akcje zbiorcze [W częściowo]. [Link](https://help.shopify.com/en/manual/products/details/bulk-edit-products) [niezweryfikowane] | N – edycja zbiorcza cen, stanów, kategorii [W]. [Link](https://woocommerce.com/document/managing-products/) |
| Kategorie/kolekcje ręczne i automatyczne | N – kolekcje ręczne i automatyczne (warunki). [Link](https://help.shopify.com/en/manual/products/collections) [niezweryfikowane] | N – kategorie i tagi hierarchiczne (ręczne); brak kolekcji automatycznych wg reguł (P/C) [niezweryfikowane]. [Link](https://woocommerce.com/document/managing-products/) |
| Atrybuty własne / metafields | N – metafields i metaobjects. [Link](https://help.shopify.com/en/manual/custom-data/metafields) [niezweryfikowane] | N – atrybuty produktu (globalne i własne) [W]; dowolne pola meta = C/P (ACF). [Link](https://woocommerce.com/document/managing-products/) |
| Produkty cyfrowe | N – aplikacja Digital Downloads (darmowa, first-party) [niezweryfikowane]. [Link](https://apps.shopify.com/digital-downloads) | N – produkt „downloadable/virtual” [W]. [Link](https://woocommerce.com/document/managing-products/) |
| Zestawy (bundles) | N – aplikacja Shopify Bundles (darmowa, first-party) [niezweryfikowane]. [Link](https://apps.shopify.com/shopify-bundles) | P – Product Bundles 79 USD/rok (z poprzedniej notatki); „grouped product” w core to tylko lista [W]. [Link](https://woocommerce.com/products/product-bundles/) |
| Duplikowanie produktu | N [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/products/add-update-products) | N – „duplicate” wbudowane [W]. [Link](https://woocommerce.com/document/managing-products/) |
| SKU / barcode (GTIN) | N – pola SKU i barcode (ISBN/UPC/GTIN) na wariant [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/products/details/product-pricing) | N – SKU [W]; pole GTIN/UPC/EAN/ISBN w core od ok. 9.2 [niezweryfikowane]. [Link](https://woocommerce.com/document/managing-products/) |
| Waga / wymiary | N – waga na wariant; wymiary tylko dla etykiet/stawek aplikacji [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/products/details/product-pricing) | N – waga i wymiary w zakładce Wysyłka [niezweryfikowane; strona potwierdza tylko, że to kwestia wysyłki]. [Link](https://woocommerce.com/document/managing-products/) |

### Wyszukiwanie i odkrywanie

| Funkcja | Shopify | WooCommerce |
|---|---|---|
| Wyszukiwanie sklepu, tolerancja literówek | N – wyszukiwanie wbudowane, tolerancja literówek podstawowa [niezweryfikowane]; synonimy i boosty w Search & Discovery. [Link](https://help.shopify.com/en/manual/online-store/storefront-search) | N – wyszukiwanie WP po tytule/opisie, bez tolerancji literówek i bez rankingu trafności; lepsze = P/I (Elasticsearch, Algolia) [niezweryfikowane]. [Link](https://woocommerce.com/document/woocommerce-widgets/) |
| Predictive search / autocomplete | N – predictive search w motywach Online Store (Search API) [niezweryfikowane]. [Link](https://shopify.dev/docs/api/ajax/reference/predictive-search) | P/C – brak w core; wtyczki lub motyw [niezweryfikowane]. [Link](https://wordpress.org/plugins/woocommerce/) |
| Filtry/facety | N – Search & Discovery (darmowa, first-party) [niezweryfikowane]. [Link](https://apps.shopify.com/search-and-discovery) | N częściowo – bloki „Active filters”, filtr cena/atrybut/stan/ocena [niezweryfikowane]; facety zaawansowane = P/I. [Link](https://woocommerce.com/document/woocommerce-product-filters/) |
| Sortowanie | N [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/products/collections) | N – popularność, ocena, najnowsze, cena [niezweryfikowane]. [Link](https://woocommerce.com/document/managing-products/) |
| Rekomendacje produktów | N – Search & Discovery: rekomendacje powiązane/komplementarne [niezweryfikowane]. [Link](https://apps.shopify.com/search-and-discovery) | N częściowo – up-sells/cross-sells ręczne [niezweryfikowane]; automatyczne = P/I. [Link](https://woocommerce.com/document/related-products-up-sells-and-cross-sells/) |

Uwaga: strona help.shopify.com „search-and-discovery” nie opisuje aplikacji (odczyt [W] nie potwierdził funkcji); wiersze Shopify w tej sekcji to wiedza własna.

### Podatki

| Funkcja | Shopify | WooCommerce |
|---|---|---|
| Stawki VAT UE | N – domyślne stawki aktualizowane regularnie, nadpisywalne [W]; Shopify Tax lub Manual Tax w UE/UK [W]. [Link](https://help.shopify.com/en/manual/taxes/eu-vat) | N/C – stawki wpisywane ręcznie lub importem CSV [W]; „Automated taxes” przez rozszerzenie WooCommerce Tax [W] (dostępność w PL [niezweryfikowane]). [Link](https://woocommerce.com/document/setting-up-taxes-in-woocommerce/) |
| Ceny brutto | N – „include taxes in product prices” [W link wymieniony]. [Link](https://help.shopify.com/en/manual/taxes/eu-vat) | N – „Prices entered with tax” [W]. [Link](https://woocommerce.com/document/setting-up-taxes-in-woocommerce/) |
| OSS | N/P – odczyt nie potwierdził OSS ani na Shopify, ani w Woo [W: obie strony go nie opisują]; Shopify: strona „EU taxes” [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/taxes/eu-vat) | C/P – brak w dokumentacji core; ręczne stawki krajowe lub rozszerzenie [niezweryfikowane]. [Link](https://woocommerce.com/document/setting-up-taxes-in-woocommerce/) |
| Klasy podatkowe/nadpisania | N – tax overrides i exemptions [W]. [Link](https://help.shopify.com/en/manual/taxes/eu-vat) | N – klasy podatkowe z własnymi tabelami stawek [W]. [Link](https://woocommerce.com/document/setting-up-taxes-in-woocommerce/) |

### Promocje

| Funkcja | Shopify | WooCommerce |
|---|---|---|
| Rabaty automatyczne | N – automatic discounts [W: wspomniane]. [Link](https://help.shopify.com/en/manual/discounts) | P/C – core ma tylko kupony; rabaty automatyczne przez rozszerzenia [niezweryfikowane]. [Link](https://woocommerce.com/document/coupon-management/) |
| Kody (limity, per klient, minimum koszyka) | N – kody; limity, minima [niezweryfikowane; strona ich nie opisuje]. [Link](https://help.shopify.com/en/manual/discounts) | N – limit użyć łącznie i na użytkownika, min/max wydatki, data wygaśnięcia [W]. [Link](https://woocommerce.com/document/coupon-management/) |
| Darmowa dostawa | N – kod/rabat free shipping [W]. [Link](https://help.shopify.com/en/manual/discounts) | N – „Allow free shipping” + metoda free shipping [W]. [Link](https://woocommerce.com/document/coupon-management/) |
| Buy X get Y | N – kody buy X get Y [W]. [Link](https://help.shopify.com/en/manual/discounts) | P/C – brak w core [W: strona kuponów go nie opisuje]. [Link](https://woocommerce.com/document/coupon-management/) |
| Karty podarunkowe | N – gift cards [niezweryfikowane; plan: wg pamięci dostępne od Basic]. [Link](https://help.shopify.com/en/manual/products/gift-card-products) | P – brak w core; „Gift Cards” to rozszerzenie [niezweryfikowane]. [Link](https://woocommerce.com/products/gift-cards/) |
| Łączenie rabatów | N – reguły łączenia (combining) [W: sekcja istnieje, treści nie odczytano]. [Link](https://help.shopify.com/en/manual/discounts) | N – wiele kuponów, ograniczenie „individual use only” [niezweryfikowane]. [Link](https://woocommerce.com/document/coupon-management/) |
| Cena promocyjna / compare-at | N – compare-at price [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/products/details/product-pricing) | N – cena promocyjna z datami od/do (wyprzedaże zaplanowane) [niezweryfikowane]. [Link](https://woocommerce.com/document/managing-products/) |
| Zaplanowane promocje | N – harmonogram rabatów (Rollouts do planowania aktywacji) [W]. [Link](https://help.shopify.com/en/manual/discounts) | N – daty ceny promocyjnej; kupony z datą wygaśnięcia [W dla kuponów]. [Link](https://woocommerce.com/document/coupon-management/) |

### Magazyn

| Funkcja | Shopify | WooCommerce |
|---|---|---|
| Stan per wariant | N [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/products/inventory) | N – zarządzanie stanem na produkt i wariację [niezweryfikowane]. [Link](https://woocommerce.com/document/managing-products/) |
| Wiele lokalizacji | N – do 10 lokalizacji (Basic–Advanced), 200 (Plus) (z poprzedniej notatki). [Link](https://www.shopify.com/pricing) | P/C – brak w core [niezweryfikowane]. [Link](https://woocommerce.com/document/managing-products/) |
| Powiadomienia o niskim stanie | N – alerty/powiadomienie o niskim stanie [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/products/inventory) | N – e-mail o niskim stanie i braku towaru (progi w ustawieniach) [niezweryfikowane]. [Link](https://woocommerce.com/document/configuring-woocommerce-settings/) |
| Backorders / overselling | N – „continue selling when out of stock” [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/products/inventory) | N – backorders: nie/tak/tak z powiadomieniem [niezweryfikowane]. [Link](https://woocommerce.com/document/managing-products/) |
| Historia i powody korekt | N – historia zmian stanów, powody korekt [niezweryfikowane]; raport zmian z transferów [W]. [Link](https://help.shopify.com/en/manual/products/inventory/transfers) | Brak natywnej historii/powodów (notatki w zamówieniach) [niezweryfikowane]; raport Stock tylko stan bieżący [W]. [Link](https://woocommerce.com/document/woocommerce-analytics/) |
| Transfery | N – transfery między lokalizacjami, częściowy odbiór, tracking [W]; plan: strona nie podaje. [Link](https://help.shopify.com/en/manual/products/inventory/transfers) | P/C – brak (brak multi-location w core) [niezweryfikowane]. [Link](https://woocommerce.com/document/managing-products/) |

### Zamówienia

| Funkcja | Shopify | WooCommerce |
|---|---|---|
| Statusy, częściowa realizacja | N – fulfilment częściowy [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/fulfillment/managing-orders) | N – statusy zamówień [W]; częściowa realizacja nie jest opisana (brak natywnej) [W: strona nie wspomina]. [Link](https://woocommerce.com/document/managing-orders/) |
| Numer śledzenia + e-mail | N – tracking w fulfilment, e-mail wysyłki [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/fulfillment/managing-orders) | P – tracking nie jest w core [W: strona nie wspomina]; rozszerzenie Shipment Tracking. [Link](https://woocommerce.com/products/shipment-tracking/) |
| Edycja zamówienia po złożeniu | N – edycja pozycji, rozliczenie różnicy [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/fulfillment/managing-orders/editing-orders) | N – edycja pozycji, opłat i sum w adminie [W]. [Link](https://woocommerce.com/document/managing-orders/) |
| Zamówienia ręczne / draft | N – draft orders [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/fulfillment/managing-orders/create-orders) | N – ręczne tworzenie zamówień [W]. [Link](https://woocommerce.com/document/managing-orders/) |
| Notatki / oś czasu | N – timeline zamówienia [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/fulfillment/managing-orders) | N – notatki zamówienia (także dla klienta) [W]. [Link](https://woocommerce.com/document/managing-orders/) |
| Tagi | N [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/fulfillment/managing-orders) | P/C – brak tagów zamówień w core [niezweryfikowane]. [Link](https://woocommerce.com/document/managing-orders/) |
| Akcje zbiorcze | N [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/fulfillment/managing-orders) | N – zbiorcza zmiana statusu [W częściowo]. [Link](https://woocommerce.com/document/managing-orders/) |
| Packing slips | N – szablon packing slip/faktura w adminie [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/fulfillment/setup/packing-slips) | P – brak w core [W: strona nie wspomina]; wtyczki PDF Invoices. [Link](https://woocommerce.com/document/managing-orders/) |
| Eksport CSV | N – eksport zamówień do CSV [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/fulfillment/managing-orders/exporting-orders) | N – przez Analytics > Orders > Download CSV [W]; surowy eksport zamówień poza Analytics [niezweryfikowane]. [Link](https://woocommerce.com/document/woocommerce-analytics/) |

### Płatności

| Funkcja | Shopify | WooCommerce |
|---|---|---|
| Płatności ręczne (przelew, pobranie) | N – manual payment methods (bank deposit, COD) [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/payments/manual-payments) | N – BACS, czek, płatność przy odbiorze w core [niezweryfikowane; strona zwrotów wspomina COD/czeki]. [Link](https://woocommerce.com/document/woocommerce-refunds/) |
| Autoryzacja i przechwycenie | N – tryb authorize, capture ręczny [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/payments/shopify-payments/manage-orders/capture-payments) | Zależy od bramki (nie core) [niezweryfikowane]. [Link](https://woocommerce.com/document/woocommerce-refunds/) |
| Zwroty pełne/częściowe z wywołaniem bramki | N – refund z admina [W częściowo]. [Link](https://help.shopify.com/en/manual/fulfillment/managing-orders/returns) | N – „Refund via [gateway]” przy bramce wspierającej zwroty, częściowe zwroty, ręczne dla COD [W]. [Link](https://woocommerce.com/document/woocommerce-refunds/) |
| Przywrócenie stanu po zwrocie | N – restock przy zwrocie [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/fulfillment/managing-orders/returns) | N – opcja „Restock refunded items” [W]. [Link](https://woocommerce.com/document/woocommerce-refunds/) |

### Wysyłka

| Funkcja | Shopify | WooCommerce |
|---|---|---|
| Strefy i stawki (flat, waga/cena, próg darmowej) | N – strefy i stawki [niezweryfikowane]; stawki przewoźników na żywo od Advanced (z poprzedniej notatki). [Link](https://help.shopify.com/en/manual/fulfillment/setup/shipping-rates) | N – strefy, flat rate, free shipping z warunkiem minimalnej kwoty [niezweryfikowane]; rates tabelaryczne = P (Table Rate 119 USD). [Link](https://woocommerce.com/document/setting-up-shipping-zones/) |
| Odbiór osobisty | N – local pickup [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/fulfillment/setup/delivery-methods/pickup-in-store) | N – metoda Local pickup [niezweryfikowane]. [Link](https://woocommerce.com/document/local-pickup/) |
| Etykiety wysyłkowe | N/I – Shopify Shipping (etykiety) tylko w wybranych krajach; w PL zwykle I/P [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/fulfillment/setup/shipping-labels) | I/P – WooCommerce Shipping obejmuje głównie USA; w PL wtyczki przewoźników [niezweryfikowane]. [Link](https://woocommerce.com/products/woocommerce-shipping/) |

### Zwroty

| Funkcja | Shopify | WooCommerce |
|---|---|---|
| Zgłoszenie z konta/status zamówienia | N – self-service ze strony statusu zamówienia i z konta klienta [W]. [Link](https://help.shopify.com/en/manual/fulfillment/managing-orders/returns) | P/C – brak w core; rozszerzenie Returns and Warranty Requests [niezweryfikowane]. [Link](https://woocommerce.com/products/warranty-requests/) |
| Akceptacja, refund | N – reguły kwalifikowalności, akceptacja, refund [W]. [Link](https://help.shopify.com/en/manual/fulfillment/managing-orders/returns) | N – refund ręczny z admina [W]; workflow akceptacji = P. [Link](https://woocommerce.com/document/woocommerce-refunds/) |
| Store credit | N – store credit dostępny w adminie (nowe konta); odczyt strony kont go nie potwierdził [W: brak wzmianki]. [Link](https://help.shopify.com/en/manual/customers/customer-accounts/new-customer-accounts) | P – brak w core; Store Credit / Smart Coupons (99 USD/rok) [niezweryfikowane]. [Link](https://woocommerce.com/products/smart-coupons/) |

### Klienci

| Funkcja | Shopify | WooCommerce |
|---|---|---|
| Konta, logowanie | N – nowe konta: logowanie kodem 6-cyfrowym z e-maila, sesja do 365 dni; Shop sign-in wymaga Shop Pay; własny IdP tylko Plus [W]. [Link](https://help.shopify.com/en/manual/customers/customer-accounts/new-customer-accounts) | N – konta z hasłem, „Moje konto” [niezweryfikowane]. [Link](https://woocommerce.com/document/woocommerce-customer-account/) |
| Książka adresowa, historia zamówień | N – historia zamówień i akcje (zwrot, reorder) [W]. [Link](https://help.shopify.com/en/manual/customers/customer-accounts/new-customer-accounts) | N – adresy, zamówienia, pliki do pobrania [niezweryfikowane]. [Link](https://woocommerce.com/document/woocommerce-customer-account/) |
| Notatki / tagi / segmenty | N – tagi, notatki, segmenty klientów [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/customers/customer-segmentation) | N częściowo – lista klientów w Analytics [W]; segmentacja = P/I [niezweryfikowane]. [Link](https://woocommerce.com/document/woocommerce-analytics/) |
| RODO: eksport/usunięcie danych | N – żądania danych klienta i usuwanie [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/privacy-and-security/privacy) | N – narzędzia eksportu/usuwania danych WordPress + retencja Woo [niezweryfikowane; strona nie odczytana – 404]. [Link](https://woocommerce.com/document/privacy-shop/) |

### Powiadomienia

| Funkcja | Shopify | WooCommerce |
|---|---|---|
| Szablony transakcyjne | N – edytowalne szablony powiadomień (Liquid) [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/fulfillment/managing-orders/notifications) | N – wbudowane e-maile (potwierdzenie, wysyłka, zwrot) edytowalne w ustawieniach, edycja szablonów też w edytorze [niezweryfikowane; strona 404]. [Link](https://woocommerce.com/document/email-faq/) |
| Odzyskiwanie porzuconych koszyków | N – abandoned checkout e-mail [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/fulfillment/managing-orders/abandoned-checkouts) | P/I – brak w core; wtyczka lub usługa mailingowa [niezweryfikowane]. [Link](https://woocommerce.com/products/woocommerce-abandoned-cart/) |

### Administracja

| Funkcja | Shopify | WooCommerce |
|---|---|---|
| Konta personelu i role | N – role i uprawnienia [W]; limity kont: Basic bez dodatkowych, Grow 5, Advanced 15 (z poprzedniej notatki). [Link](https://help.shopify.com/en/manual/your-account/staff-accounts) | N – role WordPress (Shop manager); granularne uprawnienia = P/C [niezweryfikowane]. [Link](https://woocommerce.com/document/roles-and-permissions/) |
| Log aktywności | N – „store activity log” (wspomniany na stronie importu produktów) [W]. [Link](https://help.shopify.com/en/manual/products/import-export/import-products) | P/C – brak w core; wtyczki audit log [niezweryfikowane]. [Link](https://wordpress.org/plugins/woocommerce/) |
| Aplikacja mobilna | N – Shopify app (iOS/Android) [niezweryfikowane]. [Link](https://www.shopify.com/mobile) | N – aplikacja WooCommerce (iOS/Android) [niezweryfikowane]. [Link](https://woocommerce.com/mobile/) |
| Automatyzacja | N – Shopify Flow, darmowa na Basic, Grow, Advanced i Plus [W]. [Link](https://help.shopify.com/en/manual/shopify-flow) | P/I – brak w core; Zapier/AutomateWoo (P) [niezweryfikowane]. [Link](https://woocommerce.com/products/automatewoo/) |

### Raporty

| Funkcja | Shopify | WooCommerce |
|---|---|---|
| Raporty/dashboard | N – główne funkcje Analytics na każdym planie; własne raporty przez eksploracje i karty [W]; pełny podział raportów według planu nie jest na stronie [W]. [Link](https://help.shopify.com/en/manual/reports-and-analytics/shopify-reports) | N – Analytics: kategorie, kupony, klienci, pliki, zamówienia, atrybucja, produkty, wariacje, przychód, stan, podatki [W]. [Link](https://woocommerce.com/document/woocommerce-analytics/) |
| Eksport | N – eksport raportów do CSV [niezweryfikowane; strona go nie wspomina]. [Link](https://help.shopify.com/en/manual/reports-and-analytics/shopify-reports) | N – CSV z każdego raportu, duże eksporty mailem (link ważny tydzień) [W]. [Link](https://woocommerce.com/document/woocommerce-analytics/) |

### Treści / SEO

| Funkcja | Shopify | WooCommerce |
|---|---|---|
| Strony prawne, generator polityk | N – generator polityk i strony [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/checkout-settings/refund-privacy-tos) | N – strony WordPress; polityki ręcznie [niezweryfikowane]. [Link](https://woocommerce.com/document/woocommerce-pages/) |
| Blog | N [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/online-store/blogs) | N – WordPress [niezweryfikowane]. [Link](https://wordpress.org/plugins/woocommerce/) |
| Menu nawigacji | N – custom menus [W wspomniane]. [Link](https://help.shopify.com/en/manual/online-store/menus-and-links) | N – menu WordPress [niezweryfikowane]. [Link](https://wordpress.org/plugins/woocommerce/) |
| Meta title/description | N [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/promoting-marketing/seo) | P – rozszerzenia SEO (Yoast, Rank Math) [niezweryfikowane]. [Link](https://wordpress.org/plugins/woocommerce/) |
| sitemap.xml | N – generowany automatycznie [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/promoting-marketing/seo/no-index) | N – sitemap WordPress od 5.5 (bez produktowych szczegółów) [niezweryfikowane]. [Link](https://wordpress.org/plugins/woocommerce/) |
| Przekierowania | N – URL redirects [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/online-store/menus-and-links/url-redirect) | P – brak w core; wtyczki [niezweryfikowane]. [Link](https://wordpress.org/plugins/woocommerce/) |
| Structured data | N – JSON-LD produktu w motywach [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/promoting-marketing/seo) | N – JSON-LD Product w core [niezweryfikowane]. [Link](https://developer.woocommerce.com/) |
| Baner cookies | N – Customer Privacy (banner cookies) [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/privacy-and-security/privacy/customer-privacy-settings) | P – brak w core [niezweryfikowane]. [Link](https://wordpress.org/plugins/woocommerce/) |
| Opinie o produktach | P – brak natywnych; Shopify Product Reviews wycofany, aplikacje (np. Judge.me) [niezweryfikowane]. [Link](https://apps.shopify.com/categories/store-design-social-proof-product-reviews) | N – natywne opinie i oceny produktów [niezweryfikowane]. [Link](https://woocommerce.com/document/managing-products/) |
| Listy życzeń | P – aplikacje [niezweryfikowane]. [Link](https://apps.shopify.com/) | P – rozszerzenie lub wtyczka (w 10.9 beta „listy zakupowe” z poprzedniej notatki). [Link](https://developer.woocommerce.com/2026/06/23/woocommerce-10-9/) |

### Międzynarodowe i B2B

| Funkcja | Shopify | WooCommerce |
|---|---|---|
| Wielowalutowość | N – Markets, ceny/waluty per rynek (z poprzedniej notatki) [W tam]. [Link](https://help.shopify.com/en/manual/markets) | P – brak w core; rozszerzenia (np. Multi-Currency) [niezweryfikowane]. [Link](https://woocommerce.com/products/woocommerce-multi-currency/) |
| Wielojęzyczność | N – tłumaczenia treści (Translate & Adapt, darmowa) [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/markets/languages) | P – WPML/Polylang [niezweryfikowane]. [Link](https://wordpress.org/plugins/polylang/) |
| Markets / cła | N – Markets [W tam]; cła/duties: strona nie sprawdzona [niezweryfikowane]. [Link](https://help.shopify.com/en/manual/markets) | C/P – brak [niezweryfikowane]. [Link](https://woocommerce.com/products/) |
| B2B: konta firmowe, cenniki | N – B2B od Basic; Grow do 3 katalogów, Plus bez limitu (z poprzedniej notatki). [Link](https://help.shopify.com/en/manual/b2b) | P/C – brak w core; hurt przez rozszerzenia [niezweryfikowane]. [Link](https://woocommerce.com/products/) |

### Deweloperskie

| Funkcja | Shopify | WooCommerce |
|---|---|---|
| REST/GraphQL | N – Admin GraphQL i Storefront API; REST Admin jest legacy [niezweryfikowane]. Limity API zależne od planu (z poprzedniej notatki). [Link](https://shopify.dev/docs/api) | N – REST API (WC/v3), Store API; w 10.7 /wc/v4/orders (z poprzedniej notatki). [Link](https://developer.woocommerce.com/docs/apis/) [niezweryfikowane] |
| Webhooki | N – webhooki przez API/aplikację [niezweryfikowane]. [Link](https://shopify.dev/docs/apps/build/webhooks) | N – webhooki w ustawieniach zaawansowanych [niezweryfikowane]. [Link](https://woocommerce.com/document/webhooks/) |
| Klucze API / model aplikacji | N – aplikacje (OAuth), custom apps z tokenem [niezweryfikowane]. [Link](https://shopify.dev/docs/apps) | N – klucze consumer key/secret, wtyczki WP [niezweryfikowane]. [Link](https://developer.woocommerce.com/docs/apis/) |

## Inferences

- Największe luki Woo core względem Shopify Basic: BOGO i rabaty automatyczne, multi-location i transfery, tracking, packing slips, tagi zamówień, self-service zwroty, store credit, B2B, multi-currency, multi-language, abandoned cart, redirects, cookie banner.
- Największe przewagi Woo core: natywne opinie, CSV Analytics z każdego raportu, zwroty z wywołaniem bramki, edycja zamówień, lokalne tabele podatkowe i klasy, brak limitów planu.
- Shopify Basic: Flow darmowy od Basic [W]; limity kont personelu (Basic brak dodatkowych) ograniczają role.

## Gaps

- Nie odczytano stron: Shopify Search & Discovery, discount limits (limity per klient, minimum), kart podarunkowych, planu dla store credit, raportów wg planu, OSS/cła; Woo: emaile (404), prywatność (404), pole GTIN, CSV importer.
- Większość linków oznaczonych [niezweryfikowane] pochodzi z pamięci; ich istnienie nie było sprawdzane i część może zwracać 404 – do sprawdzenia przed publikacją raportu.
- Nie sprawdzono wiarygodnie, czy Woo core ma w 2026 natywne BOGO, listy życzeń lub zaplanowane rabaty automatyczne (wiersze P/C na podstawie dokumentacji kuponów [W] i wiedzy własnej).
