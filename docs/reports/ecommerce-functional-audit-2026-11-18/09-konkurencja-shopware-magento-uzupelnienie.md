# Uzupełnienie: natywne możliwości Shopware 6 CE i Magento Open Source 2.4.x (stan: listopad 2026)

Legenda: N = natywnie w darmowym rdzeniu; P = płatna edycja (Shopware Rise/Evolve/Beyond; Adobe Commerce) lub płatne rozszerzenie; I = integracja zewnętrzna; C = custom development.
Oznaczenia pewności: **[W]** = zweryfikowane w tej sesji na oficjalnej stronie lub w jej wyniku wyszukiwania; **[niezweryfikowane]** = najlepsza wiedza, bez potwierdzenia w tej sesji. Linki oznaczone „(URL niepobrany)" to adresy oficjalnych portali podane z wiedzy, strony nie otwarto.
Budżet narzędzi (~10 wywołań) i częste 404 na stronach Adobe/Shopware sprawiły, że większość wierszy to [niezweryfikowane]. Zweryfikowano: wiersze oznaczone [W].

## Ważne ostrzeżenie metodologiczne

### Takeaway
Oficjalna tabela „Commercial Features" Shopware wymienia Roles & permissions, Flow Builder i CMS jako funkcje planów Rise+, ale wiadomo (wiedza ogólna), że ich podstawy są w CE. Traktować te wiersze jako „N (podstawy), P (rozszerzenia)", dopóki nie sprawdzi się w instalacji CE.

### Cited Findings
- Strona Commercial Features opisuje wyłącznie plany płatne; nie wskazuje, co jest w Community. Zawiera: Returns management (Rise+), Multi-inventory (tylko Beyond), Enhanced roles (Evolve+), Flow Builder webhook actions (Evolve+), delayed actions (Beyond), Advanced Search 2.0 (Evolve+), B2B components (Evolve+), Customer-specific pricing (Beyond). Brak wzmianki o gift cards, reward points, action logs, newsletterze, product streams — [Shopware Commercial Features](https://docs.shopware.com/en/shopware-6-en/features/commercial) [W]

### Inferences
- Brak gift cards/reward points/action logs na stronie komercyjnej oznacza tylko, że nie są tam opisane, nie że są w CE.

### Gaps
- Brak oficjalnej listy „co jest w CE" (strona cenowa wymienia ogólnie).

## Tabela klasyfikacji: katalog

### Takeaway
Katalog (warianty, atrybuty, kategorie, CSV, cross-selling, opinie) jest natywny w obu systemach; różnice: Shopware ma dynamiczne grupy produktów i producentów jako encje, Magento nie; bundle w Shopware to Evolve+ (P), w Magento natywny typ produktu.

### Cited Findings

| Capability | Shopware 6 CE | Magento Open Source | Źródło |
|---|---|---|---|
| Warianty / produkty konfigurowalne | N [niezweryfikowane] – warianty z właściwości, generator wariantów | N [niezweryfikowane] – typ configurable (atrybuty) | [Shopware catalogues](https://docs.shopware.com/en/shopware-6-en/catalogues) [W – tylko spis]; [Adobe product types](https://experienceleague.adobe.com/en/docs/commerce-admin/catalog/products/product-create) (URL niepobrany) |
| Właściwości / atrybuty i zestawy atrybutów | N – właściwości (Properties) i pola własne; Magento: N – atrybuty i attribute sets [niezweryfikowane obie] | j.w. | [Shopware Catalogues](https://docs.shopware.com/en/shopware-6-en/catalogues) |
| Kategorie | N [W źródło istnieje] | N [niezweryfikowane] | [Shopware Categories](https://docs.shopware.com/en/shopware-6-en/catalogues/categories) |
| Dynamiczne grupy produktów (product streams) | N – Catalogues > Dynamic product groups: warunki AND/OR, podgląd, użycie w kategoriach, sliderach, cross-sellingu, feedach; boost w Advanced Search 2.0 [W] | brak odpowiednika. Reguły katalogu tylko dla cen; Visual Merchandiser (reguły kategorii) = Adobe Commerce [niezweryfikowane] – P/C | [Shopware dynamic product groups](https://docs.shopware.com/en/shopware-6-de/Catalogues/Dynamicproductgroups) [W] |
| Producenci/marki | N – encja Manufacturer [niezweryfikowane] | C/rozszerzenie – brak encji; zwykle atrybut `manufacturer` [niezweryfikowane] | [Shopware catalogues](https://docs.shopware.com/en/shopware-6-en/catalogues) |
| Galeria mediów / wideo | N – media manager; wideo przez element CMS [niezweryfikowane] | N – galeria i wideo YouTube/Vimeo na produkcie [niezweryfikowane] | [Shopware catalogues](https://docs.shopware.com/en/shopware-6-en/catalogues) |
| Masowa edycja (bulk edit) | N – Bulk edit na liście produktów/zamówień [niezweryfikowane] | N – „Update attributes" (mass action w gridzie) [niezweryfikowane] | (URL niepobrany) |
| Import/eksport CSV (profile) | N – Settings > Shop > Import/Export, profile standardowe i własne; CSV [W] | N – System > Data Transfer (Import/Export), CSV [niezweryfikowane] | [Shopware Import/Export](https://docs.shopware.com/en/shopware-en/settings/importexport) [W] |
| Produkty cyfrowe/pobierane | N – typ „digital" z plikami do pobrania [niezweryfikowane] | N – typ downloadable [niezweryfikowane] (wg Adobe RMA nie dotyczy zwrotów, więc typ istnieje: [Returns](https://experienceleague.adobe.com/en/docs/commerce-admin/stores-sales/order-management/returns/returns) [W]) | j.w. |
| Pakiety (bundle) / produkty grupowane | **P** – Bundles = Evolve (wcześniejsze notatki: [Evolve docs](https://docs.shopware.com/en/shopware-6-en/features/shopware-evolve)); grupowane: brak typu, C/wtyczka [niezweryfikowane] | N – typy bundle i grouped [niezweryfikowane] (RMA działa dla simple, grouped, configurable, bundle: potwierdza istnienie typów [W]) | [Adobe Returns](https://experienceleague.adobe.com/en/docs/commerce-admin/stores-sales/order-management/returns/returns) [W] |
| Cross-selling / related / up-sell | N – cross-selling (ręczny i z dynamicznych grup) [W dla powiązania z grupami] | N – related, up-sell, cross-sell [niezweryfikowane] | [Shopware dynamic groups](https://docs.shopware.com/en/shopware-6-de/Catalogues/Dynamicproductgroups) [W] |
| Opinie o produktach | N – moderacja w panelu [niezweryfikowane]; AI Copilot streszczanie opinii = P (Rise+) [W, wcześniejsze notatki] | N – reviews & ratings [niezweryfikowane] | (URL niepobrany) |

### Inferences
- Product streams to element wyróżniający Shopware CE wobec Magento OS.

### Gaps
- Bulk edit, producenci, typ digital i opinie nie zostały potwierdzone na stronach oficjalnych.

## Tabela klasyfikacji: wyszukiwanie

### Takeaway
Oba systemy w CE/OS mają podstawowe wyszukiwanie z filtrami; Magento wymaga OpenSearch (Elasticsearch deprecated), Shopware działa na MySQL (OpenSearch opcjonalny). Zaawansowane (typo, synonimy, boosting) = P u Shopware (Advanced Search 2.0, Evolve+) i P/SaaS u Adobe (Live Search).

### Cited Findings

| Capability | Shopware 6 CE | Magento Open Source | Źródło |
|---|---|---|---|
| Silnik | N – MySQL domyślnie; OpenSearch/Elasticsearch opcjonalnie (głównie wydajność katalogu) [niezweryfikowane] | N – OpenSearch 2.19 wymagany w 2.4.8, Elasticsearch deprecated [W, wcześniejsze notatki: MageComp](https://magecomp.com/blog/magento-open-source-2-4-8-release-notes/) | – |
| Nawigacja warstwowa/filtry | N [niezweryfikowane] | N – layered navigation [niezweryfikowane] | (URL niepobrany) |
| Sortowanie | N [niezweryfikowane] | N [niezweryfikowane] | – |
| Autocomplete/suggest | N – podstawowe suggest w storefroncie [niezweryfikowane] | N – podstawowe przez OpenSearch [niezweryfikowane]; Live Search P/SaaS | – |
| Tolerancja literówek | P – Advanced Search 2.0 (Evolve+) [W: [Advanced Search 2.0](https://docs.shopware.com/en/shopware-6-en/settings/advanced-search-2-0)]; w CE ograniczona [niezweryfikowane] | częściowo N (fuzzy OpenSearch) [niezweryfikowane]; pełna = Live Search P | – |
| Synonimy | P/N [niezweryfikowane] – konfiguracja synonimów w Advanced Search (Evolve+) [W źródło]; czy w CE – nie ustalono | N – Marketing > Search Synonyms (OpenSearch) [niezweryfikowane] | – |

### Gaps
- Dokładny zakres wyszukiwania w CE Shopware (fuzzy, synonimy) niepotwierdzony.

## Tabela klasyfikacji: ceny, podatki, promocje

### Cited Findings

| Capability | Shopware 6 CE | Magento Open Source | Źródło |
|---|---|---|---|
| Netto/brutto | N – ceny netto i brutto, wybór per grupa klientów/kanał [niezweryfikowane] | N – ceny wg konfiguracji podatków (z/bez podatku) [niezweryfikowane] | – |
| Podatki per kraj | N – reguły podatkowe per kraj [niezweryfikowane] | N – Tax Zones & Rates, Tax Rules [niezweryfikowane] | – |
| Ceny per grupa klientów | N – ceny zaawansowane z regułami (Rule Builder) [niezweryfikowane] | N – customer group price/ tier price [niezweryfikowane] | – |
| Ceny progowe (tier) | N – ceny zaawansowane (progi ilości) [niezweryfikowane] | N – tier price [niezweryfikowane] | – |
| Multi-waluta | N [niezweryfikowane] | N – waluty i kursy [niezweryfikowane] | – |
| Cena przekreślona (list price) | N – pole „cena listowa" [niezweryfikowane]; | N – special price (cena przekreślona w Luma) [niezweryfikowane] | – |
| Zaplanowane zmiany cen | C/P – brak natywnego harmonogramu w CE [niezweryfikowane]; (reguły z zakresem dat przez promocje N) | N – special price from/to; pełny content/price staging = Adobe Commerce P [niezweryfikowane] | – |
| Omnibus | P/I – pola ręczne N, automatyzacja = wtyczki (wcześniejsze notatki, zweryfikowane) | brak źródła; zakładamy P/C – rozszerzenia firm trzecich [niezweryfikowane] | – |
| Promocje koszyka (Rule Builder) | N – Promotions z Rule Builderem (nowy warunek „Quantity per item" w 6.7.12) [W, wcześniejsze notatki] | N – Cart Price Rules [niezweryfikowane] | [Shopware July 2026](https://www.shopware.com/en/news/shopware-6-release-news-july-2026/) |
| Kody kuponów (generowanie, limity) | N – kody stałe i generowane, limity użycia [niezweryfikowane] | N – coupon generation, limit użyć na kupon i na klienta [niezweryfikowane] | – |
| Reguły cen katalogu | N/C – promocje na produkty [niezweryfikowane] | N – Catalog Price Rules [niezweryfikowane] | – |
| Darmowa dostawa wg reguł | N – reguły dostawy (Rule Builder) [W: [Pricing](https://www.shopware.com/en/pricing/)] | N – akcja cart rule „free shipping" i metoda Free Shipping [niezweryfikowane] | – |
| Karty podarunkowe | brak w rdzeniu; wtyczka [niezweryfikowane] – P/I | **P** – Adobe Commerce; w OS rozszerzenia (wcześniejsze notatki [W wtórne]) | [mgt-commerce](https://www.mgt-commerce.com/blog/magento-2-commerce-vs-magento-2-open-source/) |
| Punkty lojalnościowe | brak w rdzeniu; wtyczka [niezweryfikowane] – P/I | **P** – Adobe Commerce; w OS rozszerzenia (wtórne [W]) | [scandiweb](https://scandiweb.com/blog/adobe-commerce-vs-magento-open-source/) |

### Gaps
- Brak zweryfikowanych oficjalnych dokumentów dla większości wierszy cenowych i promocyjnych.

## Tabela klasyfikacji: magazyn

### Cited Findings

| Capability | Shopware 6 CE | Magento Open Source | Źródło |
|---|---|---|---|
| Stan per wariant | N – stock na produkcie/wariancie [niezweryfikowane] | N – MSI/ Legacy stock per SKU [W: MSI jest w OS] | [Adobe Inventory intro](https://experienceleague.adobe.com/en/docs/commerce-admin/inventory/introduction) [W] |
| Multi-source (MSI) / multi-warehouse | **P** – Multi-inventory tylko Beyond [W: [Commercial Features](https://docs.shopware.com/en/shopware-6-en/features/commercial)] | **N** – Inventory Management (sources, stocks) instalowane z OS, funkcje włączone domyślnie [W]; jedna „stock" per kanał (website) [W] | [Adobe Inventory intro](https://experienceleague.adobe.com/en/docs/commerce-admin/inventory/introduction) [W] |
| Rezerwacje | brak (stan odejmowany przy zamówieniu) [niezweryfikowane] | N – rezerwacje zmniejszają salable quantity, zdejmowane przy wysyłce [W] | j.w. |
| Backorders | N/C – „clearance sale"/is_closeout (sprzedaż do wyczerpania) i dostępność [niezweryfikowane] | N – backorders [niezweryfikowane] (strona Adobe nie opisuje [W]) | – |
| Powiadomienia o niskim stanie | C/I [niezweryfikowane] | N – raport/notyfikacja Low Stock [niezweryfikowane] (strona nie opisuje [W]) | – |

### Gaps
- Low-stock i backorders niepotwierdzone w obu systemach.

## Tabela klasyfikacji: checkout, zamówienia, dokumenty

### Cited Findings

| Capability | Shopware 6 CE | Magento Open Source | Źródło |
|---|---|---|---|
| Checkout gościa | N [niezweryfikowane] | N [niezweryfikowane] | – |
| Statusy zamówień/state machine | N – state machine zamówienia, płatności i dostawy [niezweryfikowane] | N – state/status zamówienia [niezweryfikowane] | – |
| Edycja zamówienia | N – edycja zamówień w admin [niezweryfikowane] | N/C – Magento ma „Edit" jako anulowanie i utworzenie nowego zamówienia [niezweryfikowane] | – |
| Zamówienia tworzone przez admina | N [niezweryfikowane] | N [niezweryfikowane] | [Adobe Sales menu](https://experienceleague.adobe.com/en/docs/commerce-admin/stores-sales/order-management/sales-menu) (URL tylko z wyników) |
| Faktury, listy przewozowe, korekty PDF | N – szablony: delivery note, invoice, credit note, cancellation invoice; Document V2 + Flow Builder, załączniki do maili; v2 w 6.7.15 oznaczone „experimental" [W] | N – invoice, shipment, credit memo z PDF [niezweryfikowane] | [Shopware Documents](https://docs.shopware.com/en/shopware-6-en/settings/documents) [W]; [Orders overview](https://docs.shopware.com/en/shopware-6-en/orders/overview) [W] |
| Wysyłki częściowe | N – status „shipped partially" [niezweryfikowane] | N – częściowe shipment/invoice [niezweryfikowane] | – |
| Numery śledzenia i maile | N – kody śledzenia, mail wysyłki przez Flow Builder [niezweryfikowane] | N – tracking numbers, maile shipment [niezweryfikowane] | – |
| Eksport zamówień | N – eksport CSV (Import/Export) [niezweryfikowane]; | N – eksport gridu CSV/XML [niezweryfikowane] | [Shopware Import/Export](https://docs.shopware.com/en/shopware-en/settings/importexport) [W] |
| Operacje masowe na zamówieniach | N – bulk edit, masowe dokumenty [niezweryfikowane] | N – mass actions w gridzie [niezweryfikowane] | – |

## Tabela klasyfikacji: płatności, dostawa, zwroty

### Cited Findings

| Capability | Shopware 6 CE | Magento Open Source | Źródło |
|---|---|---|---|
| Metody offline | N – pobranie, przedpłata, faktura (domyślne) [niezweryfikowane] | N – Check/Money order, Bank Transfer, COD, Purchase Order [niezweryfikowane] | – |
| Bramki online | I – wtyczki; Shopware Payments tylko DE/AT [W, wcześniejsze notatki]; Magento: Braintree wbudowane (2.4.8) [W] | j.w. | [Shopware July 2026](https://www.shopware.com/en/news/shopware-6-release-news-july-2026/) |
| Zwrot środków | N/I – refund przez wtyczkę płatności [niezweryfikowane]; **P** zarządzanie zwrotami (Rise+) [W] | N – credit memo, online refund zależny od bramki [niezweryfikowane] | [Shopware Returns](https://docs.shopware.com/en/shopware-6-en/orders/returns-management) |
| Metody dostawy, macierze, progi | N – reguły (Rule Builder), cena wg wagi/koszyka, darmowa dostawa [W: [Pricing](https://www.shopware.com/en/pricing/)] | N – Table Rates, Flat Rate, Free Shipping [niezweryfikowane] | – |
| Przewoźnicy w rdzeniu | brak (I – wtyczki; własna aplikacja Shopware Shipping [niezweryfikowane]) | N – UPS, USPS, FedEx, DHL [niezweryfikowane] | – |
| Etykiety wysyłkowe | I/P – wtyczki/aplikacje [niezweryfikowane] | N – generowanie etykiet dla wbudowanych przewoźników (Adobe: Shipping labels) [W istnienie strony] | [Adobe Shipping labels](https://experienceleague.adobe.com/en/docs/commerce-admin/stores-sales/delivery/shipping-labels/shipping-labels) |
| Zwroty / RMA | **P** – Returns management (Rise+) [W] | **P** – RMA tylko Adobe Commerce („Adobe Commerce only"; statusy, etykiety zwrotne; nie dla virtual/downloadable/gift card) [W]; w OS: C/rozszerzenie | [Adobe Returns](https://experienceleague.adobe.com/en/docs/commerce-admin/stores-sales/order-management/returns/returns) [W] |

## Tabela klasyfikacji: klienci, powiadomienia, admin, raporty

### Cited Findings

| Capability | Shopware 6 CE | Magento Open Source | Źródło |
|---|---|---|---|
| Konta, książka adresowa, grupy klientów, historia | N [niezweryfikowane] | N [niezweryfikowane] | – |
| Guest-to-account | N – rejestracja po zamówieniu [niezweryfikowane] | N [niezweryfikowane] | – |
| GDPR/RODO | N – zgoda, cookie consent, anonimizacja częściowo [niezweryfikowane] | N – eksport/usuwanie danych klienta [niezweryfikowane] | – |
| Szablony maili w panelu | N – Settings > Email templates; przypisanie do zdarzeń przez Flow Builder [W] | N – Marketing > Email Templates, nadpisanie szablonu [niezweryfikowane] | [Shopware Email templates](https://docs.shopware.com/en/shopware-6-en/settings/email-templates) [W] |
| Newsletter | N – zapisy i podstawowa wysyłka; kampanie = I [niezweryfikowane] | N – subskrypcje i kolejka newsletter; zaawansowane = I [niezweryfikowane] | – |
| ACL / role | N – podstawowe role; enhanced roles (B2B) = Evolve+ [W: Commercial Features; uwaga: podstawowe „Roles & permissions" jest w tabeli komercyjnej – rozbieżność] | N – User Roles [niezweryfikowane] | [Commercial Features](https://docs.shopware.com/en/shopware-6-en/features/commercial) |
| Dziennik działań admina | brak w CE [niezweryfikowane] – P/C | **P** – Admin Actions Log w Adobe Commerce [niezweryfikowane] | – |
| Automatyzacja | N – Flow Builder (rdzeń od 6.4); webhook actions Evolve+, delayed actions Beyond [W: Commercial Features] | brak odpowiednika; C/I (Adobe Commerce webhooks/ I/O Events) [niezweryfikowane] | [Commercial Features](https://docs.shopware.com/en/shopware-6-en/features/commercial) |
| Raporty | N – podstawowe statystyki w dashboardzie; zaawansowane = I/P [niezweryfikowane] | N – menu Reports (sprzedaż, produkty, klienci, wyszukiwania, recenzje) [niezweryfikowane]; Adobe Commerce Intelligence = P | – |

## Tabela klasyfikacji: treści, SEO, międzynarodowość, B2B

### Cited Findings

| Capability | Shopware 6 CE | Magento Open Source | Źródło |
|---|---|---|---|
| CMS/page builder | N – Shopping Experiences w CE (wizualny page builder) [W: Pricing]; Publisher/CMS rules = Evolve+ [W] | N – CMS strony/bloki; **Page Builder wbudowany w OS od 2.4.3** (podstawowy zestaw), staging/preview/personalizacja = Adobe Commerce [W] | [Adobe 2.4.3 notes](https://experienceleague.adobe.com/en/docs/commerce-operations/release/notes/magento-open-source/2-4-3) [W]; [Page Builder intro](https://experienceleague.adobe.com/en/docs/commerce-admin/page-builder/introduction) [W] |
| Blog | brak natywnego (wtyczka) [niezweryfikowane] – P/I | brak natywnego (rozszerzenie) [niezweryfikowane] – P/I | – |
| URL SEO, sitemap, canonical, przekierowania | N [niezweryfikowane] | N – URL Rewrites, sitemap XML, canonical, przekierowania [niezweryfikowane] | – |
| Dane strukturalne | N/częściowo (schema.org w szablonach) [niezweryfikowane] | częściowo (Luma: schema.org) [niezweryfikowane] | – |
| Cookie consent | N – wbudowany Cookie consent manager [niezweryfikowane] | częściowo – „cookie restriction mode"; pełny CMP = I [niezweryfikowane] | – |
| Wiele języków / walut / kanałów | N – sales channels, języki, waluty [niezweryfikowane] | N – websites/stores/store views, języki, waluty [niezweryfikowane]; w OS pojedyncza baza [W wtórne: scandiweb] | [scandiweb](https://scandiweb.com/blog/adobe-commerce-vs-magento-open-source/) |
| B2B w rdzeniu | N – grupy klientów, ceny netto; komponenty B2B = Evolve+, ceny per klient = Beyond [W] | N – grupy klientów, ceny per grupa; konta firmowe, wyceny, zamówienia zakupowe = Adobe Commerce, osobna licencja w PaaS/on-prem [W: [Adobe platforms](https://experienceleague.adobe.com/en/docs/commerce/information-center/explore-platforms-and-services/platform-and-services)] | [Commercial Features](https://docs.shopware.com/en/shopware-6-en/features/commercial) |

## Tabela klasyfikacji: headless, API, dostępność

### Cited Findings

| Capability | Shopware 6 CE | Magento Open Source | Źródło |
|---|---|---|---|
| API | N – Store API i Admin API (OpenAPI) [niezweryfikowane] | N – REST i GraphQL [W: [Adobe GraphQL](https://developer.adobe.com/commerce/webapi/graphql/)] | [developer.shopware.com](https://developer.shopware.com) (URL niepobrany) |
| Frontend headless | N – Composable Frontends (Vue/Nuxt, open source) [niezweryfikowane] | PWA Studio – wsparcie ograniczone/nieaktywne [niezweryfikowane]; Hyvä (P, licencja) lub EDS tylko Commerce SaaS [W wcześniejsze] | – |
| App system, webhooks | N – app system i webhooks (rdzeń); Flow Builder webhook actions Evolve+ [W] | webhooks Adobe Commerce (moduł, Adobe I/O Events) [niezweryfikowane] – N/P | – |
| Dostępność | 6.7 duży nacisk na dostępność (EAA) [W wtórne: [burgdigital](https://www.burgdigital.de/en/news/shopware-6.7)]; zgodność WCAG niezweryfikowana | Luma: ograniczona [niezweryfikowane]; Hyvä: lepsza, ale brak oficjalnej deklaracji [niezweryfikowane] | – |

### Inferences
- Magento OS ma realną przewagę nad Shopware CE w magazynie (MSI) i przewoźnikach w rdzeniu; Shopware CE przewagę w dynamicznych grupach produktów, Flow Builder, dokumentach i wielokanałowości.
- RMA i zwroty są P w obu systemach; nie ma darmowej, natywnej obsługi zwrotów.

### Gaps
- Większość wierszy „[niezweryfikowane]" wymaga potwierdzenia w oficjalnej dokumentacji (strony Adobe `/commerce-admin/...` i `docs.shopware.com` często zwracały 404 lub brak treści w narzędziu). Priorytet: gift cards/reward points/Admin Actions Log (Adobe), low-stock/backorders (obie), typ digital, bulk edit, scheduled prices, typo tolerance w CE, PWA Studio, WCAG deklaracje.
