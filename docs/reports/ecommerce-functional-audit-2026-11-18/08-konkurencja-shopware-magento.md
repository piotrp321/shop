# Konkurencja: Shopware 6 i Magento / Adobe Commerce – zakres funkcjonalny (stan: listopad 2026)

Legenda: (N) natywnie w rdzeniu open source / planie bazowym; (P) płatna edycja lub płatne rozszerzenie; (I) zewnętrzna integracja/usługa; (C) wymaga custom developmentu. Etykiety w nawiasach `[?]` oznaczają, że przypisanie wynika z wnioskowania, a nie z cytowalnego źródła.

Uwaga metodologiczna: badanie ograniczone budżetem ~15 wywołań narzędzi. Część stron oficjalnych Adobe zwróciła 404/ograniczone treści, więc wiele cech Magento opiera się na blogach agencji (oznaczone jako wtórne). Ceny Adobe Commerce nie są publiczne – liczby poniżej to szacunki agencji.

## 1. Plany, licencje, ceny i zmiany 2025–2026

### Takeaway
Shopware: darmowa Community Edition (N) + płatne plany Rise (od 600 EUR/mies.), Evolve (od 2 400 EUR/mies.), Beyond (indywidualnie), wycena zależna od GMV; funkcje komercyjne są bramkowane planem. Magento Open Source jest darmowe (hosting i rozwój po stronie sklepu), Adobe Commerce jest licencjonowane wg GMV (publicznie niepotwierdzone widełki, według agencji od ok. 22 tys. USD/rok on-prem), a Adobe rozwija SaaS (Adobe Commerce as a Cloud Service) z Edge Delivery Services.

### Cited Findings
**Shopware**
- Community Edition: 0 EUR, zawiera m.in. wizualny page builder, zarządzanie produktami i zamówieniami, Shopware Payments, wsparcie społeczności; Rise od 600 EUR/mies. (pierwsze 30 dni gratis), Evolve od 2 400 EUR/mies., Beyond wycena indywidualna (ceny netto) — [Shopware Pricing](https://www.shopware.com/en/pricing/)
- Plany płatne wyceniane na podstawie GMV i innych czynników; hosting, wdrożenie, utrzymanie, płatne rozszerzenia i prowizje płatności to osobne koszty — [Shopware Pricing](https://www.shopware.com/en/pricing/)
- Fair Usage Policy: dostęp do Shopware Account i store wymaga płatnego planu, gdy powiązane sklepy przekroczą 1 mln EUR GMV w ostatnich 365 dniach; oprogramowanie Community można nadal używać samodzielnie — [Shopware Pricing](https://www.shopware.com/en/pricing/)
- Modele hostingu: SaaS, PaaS, self-hosted — [Shopware Pricing](https://www.shopware.com/en/pricing/)
- Wsparcie: Rise – tickety, reakcja 8 h pn–pt; Evolve – 4 h, hotline, do 2 ticketów deweloperskich/mies.; Beyond – 24/7, reakcja 1 h, account manager — [Shopware Pricing](https://www.shopware.com/en/pricing/)
- Shopware Intelligence+ (dodatek): +29 USD/mies. dla Community, +19 USD/mies. dla Rise/Evolve/Beyond; AI Copilot agentic, data insights, generowanie 3D, CAD-to-3D — [Shopware Pricing](https://www.shopware.com/en/pricing/)
- Shopware Nexus (integracje ERP/CRM/PIM): limity wykonań 100/1 000/2 000/5 000 mies. wg planu, nadwyżka 10 EUR za 1 000 — [Shopware Pricing](https://www.shopware.com/en/pricing/)
- Marketplace (ChannelEngine, 1 300+ marketplace’ów) i Nosto AI search to dodatki płatne — [Shopware Pricing](https://www.shopware.com/en/pricing/) (strona spłaszczona, mapowanie niepewne)
- Shopware 6.7.0.0 wydane 14.05.2025; nacisk: wydajność, dostępność (EAA), nowy system cache, stack Vite/Vue 3/Pinia — wyniki wyszukiwania, m.in. [burgdigital](https://www.burgdigital.de/en/news/shopware-6.7), [Shopware updates](https://www.shopware.com/en/products/updates/)
- 2026: 6.7.8 (01.04.2026) Individual Pricing (B2B), edycja Evolve; 6.7.14.1 (23.09.2026) Copilot agentic z bety do stabilnej wersji (także dla Advanced Search, B2B Budget Management, Subscriptions); 6.7.15.0 (10.06.2026 wg tabeli) Nexus – własne integracje — [Shopware product updates](https://www.shopware.com/en/products/updates/) (daty w tabeli nie są chronologicznie spójne – flagowane jako niepewne)
- Lipiec 2026: 6.7.12.1 (6.7.12.0 miało błąd krytyczny); poprawiona relewancja wyszukiwania, natywne pliki llms.txt/agents.md na kanał sprzedaży, nowy warunek Rule Buildera „Quantity per item”, Google Ads Enhanced Conversions; Shopware Payments od 10.06 dostępne we wszystkich planach łącznie z Community, na razie DE i AT — [Shopware release news July 2026](https://www.shopware.com/en/news/shopware-6-release-news-july-2026/)

**Magento / Adobe**
- Trzy modele Adobe Commerce: ACCS (w pełni zarządzany SaaS), Adobe Commerce on cloud infrastructure (PaaS), on-premises; Commerce Optimizer to osobny SaaS (storefront EDS, katalog, wyszukiwanie, merchandising) działający przed istniejącym backendem, obecnie nie integruje się z ACCS — [Adobe Experience League – platforms](https://experienceleague.adobe.com/en/docs/commerce/information-center/explore-platforms-and-services/platform-and-services)
- W ACCS podstawowe B2B (zarządzanie firmami, wyceny) jest domyślnie; w PaaS/on-prem B2B to osobne rozszerzenie z osobną licencją — [Adobe Experience League – platforms](https://experienceleague.adobe.com/en/docs/commerce/information-center/explore-platforms-and-services/platform-and-services)
- ACCS: jedno środowisko produkcyjne i dwa sandboxy; storefront na Edge Delivery Services, limit 1 TB — [Adobe product description (via search)](https://helpx.adobe.com/be_en/legal/product-descriptions/adobe-commerce-cloud-service.html) (treść strony nie pobrana bezpośrednio, tylko z wyniku wyszukiwania)
- Open Source jest darmowe; hosting 400–3 000+ USD/mies., rozszerzenia 500–5 000+ USD/rok, retainer deweloperski 20–60 h/mies. (szacunek agencji) — [scandiweb](https://scandiweb.com/blog/adobe-commerce-vs-magento-open-source/)
- Szacowane licencje Adobe Commerce wg GMV: 1–5 mln USD ok. 22 tys. USD/rok on-prem / ok. 40 tys. cloud; 5–10 mln ok. 49 tys./80 tys.; 25 mln+ ok. 125 tys.+/190 tys.+; TCO zwykle 2–3× licencja — [scandiweb](https://scandiweb.com/blog/adobe-commerce-vs-magento-open-source/) (szacunek agencji; artykuł jest niespójny wewnętrznie co do mnożnika cloud/on-prem); inna agencja podaje 122–450+ tys. USD/rok łącznie — [mgt-commerce](https://www.mgt-commerce.com/blog/magento-2-commerce-vs-magento-2-open-source/)
- Magento Open Source 2.4.8: PHP 8.4 (usunięto 8.1), OpenSearch 2.19, Elasticsearch oznaczone jako deprecated, Valkey 8, MariaDB 11.4, MySQL 8.4, domyślnie indeksery „Update by Schedule”, 582+ poprawek — [MageComp](https://magecomp.com/blog/magento-open-source-2-4-8-release-notes/)
- Magento 2.4.9 wydane 12.05.2026; 2.4.6 EOL 11.08.2026; koniec wsparcia 2.4.8 wg większości źródeł kwiecień 2028 (źródła rozbieżne) — [wyniki wyszukiwania: ontapgroup, mgt-commerce, privatedevops](https://www.mgt-commerce.com/blog/magento-versions-history/)
- Mage-OS: 3.0 wydane 18.05.2026 na bazie Magento Open Source 2.4.9; wspierana tylko najnowsza gałąź — [Mage-OS 3.2.0 release](https://newreleases.io/project/github/mage-os/mageos-magento2/release/3.2.0), [ontapgroup – Mage-OS 3.0](https://www.ontapgroup.com/blog/mage-os-3-0)

### Inferences
- Realny próg wejścia płatnego Shopware (>=7,2 tys. EUR/rok netto dla Rise) jest wielokrotnie niższy niż Adobe Commerce, ale próg Fair Usage 1 mln EUR GMV de facto zmusza większe sklepy do płatnego planu, by zachować konto/Store.
- Polski sklep w skali średniej: Open Source Magento/Shopware CE = koszt głównie wdrożenia i utrzymania, nie licencji.

### Gaps
- Brak oficjalnej, publicznej ceny Adobe Commerce (tylko szacunki agencji); brak ceny ACCS.
- Nie zweryfikowano oficjalnie daty EOL 2.4.8 na stronie Adobe lifecycle.
- Brak oficjalnej strony „compare editions” Adobe (URL 404).

## 2. Katalog produktów (obszar 1) i wyszukiwanie/odkrywanie (obszar 2)

### Takeaway
Oba systemy natywnie obsługują warianty/konfigurowalne produkty, kategorie, atrybuty. Shopware: bundle'e w Evolve (P), product streams (dynamiczne grupy) natywnie; zaawansowane wyszukiwanie (Advanced Search 2.0) w Evolve+. Magento: konfigurowalne, grupowane, bundle i wirtualne/pobieralne produkty natywnie [?]; Live Search/rekomendacje oparte o Sensei to usługi Adobe.

### Cited Findings
- Shopware Evolve zawiera: Advanced Search, B2B Components, CMS extensions, CMS rules, Dynamic Access, Publisher, webhook actions w Flow Builder, Bundles — [Shopware Docs – Evolve](https://docs.shopware.com/en/shopware-6-en/features/shopware-evolve) (czyli bundle = P, Evolve+)
- Advanced Search 2.0: tylko Evolve/Beyond (Rise: nie); Advanced Search = konfigurowalne dopasowanie wielowyrazowe, „leather jacket” = „leatherjacket” — [Shopware Commercial Features](https://docs.shopware.com/en/shopware-6-en/features/commercial), [Shopware July 2026](https://www.shopware.com/en/news/shopware-6-release-news-july-2026/)
- Shopware 6.7.12: poprawiona obsługa kodów produktów, rozmiarów, jednostek; AI Copilot „Search by context” w Rise+ — [Shopware July 2026](https://www.shopware.com/en/news/shopware-6-release-news-july-2026/), [Commercial Features](https://docs.shopware.com/en/shopware-6-en/features/commercial)
- Nosto AI search jako płatny dodatek (I/P) — [Shopware Pricing](https://www.shopware.com/en/pricing/)
- Magento: Open Source ma „podstawowe” wyszukiwanie, Adobe Commerce – Live Search (Sensei), Visual Merchandiser i rekomendacje AI — [mgt-commerce](https://www.mgt-commerce.com/blog/magento-2-commerce-vs-magento-2-open-source/); [scandiweb](https://scandiweb.com/blog/adobe-commerce-vs-magento-open-source/)
- Magento 2.4.8 używa OpenSearch 2.19 (N, self-hosted) — [MageComp](https://magecomp.com/blog/magento-open-source-2-4-8-release-notes/)
- W ACCS Catalog Service, Live Search i Product Recommendations są instalowane i konfigurowane automatycznie — [Adobe Experience League](https://experienceleague.adobe.com/en/docs/commerce/information-center/explore-platforms-and-services/platform-and-services)
- GraphQL w SaaS zastępuje bazowe zapytania `products`/`categories` wersjami opartymi na serwisach (niekompatybilne wstecz) — [Adobe GraphQL docs](https://developer.adobe.com/commerce/webapi/graphql/)

### Inferences
- Product streams Shopware (dynamiczne grupy) i import/eksport CSV (profile importu) to rdzeń CE `[?]` – nie zweryfikowane w tej sesji; warianty, kategorie, właściwości i media są natywne w obu platformach na podstawie wiedzy ogólnej, nie źródła.

### Gaps
- Nie zweryfikowano źródłowo: warianty/konfigurowalne, bulk edit, import/export, produkty cyfrowe w CE Shopware i Magento OS; bundle w Magento (natywny typ produktu – wiedza ogólna); product streams.

## 3. Ceny, podatki, promocje, Omnibus (obszar 3)

### Takeaway
Shopware: Rule Builder (rdzeń; „Roles/Rule Builder” w tabeli komercyjnej Rise+ – rozbieżność, patrz niżej) oraz dwa natywne pola „najniższa cena z 30 dni” wypełniane ręcznie; automatyzacja Omnibus = wtyczka (P). Magento: reguły koszyka/katalogu w rdzeniu (wg GraphQL 2.4.8), karty podarunkowe, store credit i reward points tylko Adobe Commerce lub rozszerzenia.

### Cited Findings
- Rule Builder: w oficjalnej tabeli Commercial Features figuruje we wszystkich planach płatnych (Rise/Evolve/Beyond) wraz z preview i share rules; strona cenowa wymienia Rule Builder bez bramkowania — [Commercial Features](https://docs.shopware.com/en/shopware-6-en/features/commercial), [Pricing](https://www.shopware.com/en/pricing/); (rdzeń Rule Buildera jest w CE – [?], rozszerzenia preview/share to P)
- Shopware: dwa natywne pola produktu na najniższą cenę z 30 dni (brutto/netto), wypełniane ręcznie; brak automatycznego obliczania w rdzeniu — [Shopware release news April 2022](https://www.shopware.com/en/news/shopware-6-release-news-april-2022/), wyniki wyszukiwania Store
- Automatyzacje Omnibus (P/I): wtyczki w Shopware Store, m.in. [automatic calculation PAngV §11](https://store.shopware.com/en/pixup93076399460m/automatic-calculation-of-the-lowest-price-according-to-11-pangv.html), [automated cheapest price](https://store.shopware.com/en/ott9357549275186/automated-cheapest-price.html) (6.6/6.7, tylko self-hosted), darmowa wtyczka polskiej Makolab: [Omnibus lowest price](https://store.shopware.com/en/makol73529386666m/omnibus-lowest-price.html)
- Prawo: najniższa cena z 30 dni obowiązuje, gdy sprzedawca reklamuje obniżkę — [Gleiss Lutz](https://www.gleisslutz.com/en/New_Price_Indication_Ordinance.html) (dotyczy PAngV, DE; polska ustawa analogiczna – nie sprawdzono)
- Magento 2.4.8 GraphQL: reguły koszyka i katalogu, nowe pola cen, gift wrap z podatkiem, zero-total → metoda „Free” — [MageComp](https://magecomp.com/blog/magento-open-source-2-4-8-release-notes/)
- Magento Open Source: reward points/lojalność i gift cards przez rozszerzenia; Adobe Commerce wbudowane — [mgt-commerce](https://www.mgt-commerce.com/blog/magento-2-commerce-vs-magento-2-open-source/), [scandiweb](https://scandiweb.com/blog/adobe-commerce-vs-magento-open-source/)
- Shopware Individual Pricing (reguły rabatów per klient/organizacja): wg tabeli updates – Evolve (od 6.7.8) — [Shopware updates](https://www.shopware.com/en/products/updates/); wg tabeli commercial features „Customer-specific pricing” – tylko Beyond — [Commercial Features](https://docs.shopware.com/en/shopware-6-en/features/commercial); **rozbieżność źródeł**
- Dla Magento Omnibus: nie znaleziono źródła; stan: brak natywnego mechanizmu `[?]` (C/I – rozszerzenie lub custom)

### Inferences
- Dla polskiego rynku (ustawa Omnibus) żadna platforma nie daje w pełni automatycznego mechanizmu w rdzeniu – to realna przewaga, jeśli własna platforma ma historię cen i auto-obliczenie.

### Gaps
- VAT OSS, waluty, kody rabatowe, gift cards Shopware (czy N w CE, czy wtyczka) – niezweryfikowane.
- Brak źródła dla Omnibus w Magento.

## 4. Magazyn (obszar 4)

### Takeaway
Shopware: multi-warehouse (Multi-Inventory) tylko Beyond (P); CE ma jeden stan magazynowy. Magento: MSI jest w rdzeniu (Open Source) wg wiedzy ogólnej [?], źródeł nie potwierdzono w tej sesji, ale 2.4.8 zawiera poprawki InventoryIndexer.

### Cited Findings
- Shopware Multi-inventory: tylko plan Beyond — [Commercial Features](https://docs.shopware.com/en/shopware-6-en/features/commercial); dokumentacja deweloperska: „part of the Commercial plugin along with Beyond” — [developer.shopware.com multi-inventory](https://developer.shopware.com/docs/v6.6/guides/plugins/plugins/api/multi-inventory.html)
- Pickware (ERP/WMS) jest niekompatybilny z funkcjami komercyjnymi Return Management i Multi Inventory — [Pickware help](https://help.pickware.com/en/articles/659873-which-shopware-features-are-not-compatible-with-pickware)
- Magento 2.4.8: usunięto ukryte zależności w InventoryIndexer, poprawa synchronizacji stanu — [MageComp](https://magecomp.com/blog/magento-open-source-2-4-8-release-notes/) (artykuł nie wymienia MSI z nazwy)

### Gaps
- Potwierdzenie źródłowe MSI w Open Source, rezerwacje, alerty low-stock, backorders w obu systemach – brak.

## 5. Koszyk, checkout, płatności (obszar 5) – rynek polski

### Takeaway
Polskie metody płatności są dostępne w obu ekosystemach jako wtyczki dostawców (I): P24/BLIK/PayU/Tpay/Autopay. Shopware: P24 i PayU w Store; Magento: P24, dpay, Autopay (BlueMedia), Tpay, InPost Pay.

### Cited Findings
- Shopware Store: [Przelewy24 gateway](https://store.shopware.com/en/kodef94657448840f/przelewy24-gateway.html) (BLIK, P24Now, przelewy bankowe, karty); [PayU](https://store.shopware.com/en/crehl78082473062f/payu.html) (darmowa wtyczka partnera Gold; wzmianka o BLIK niepotwierdzona); osobnej wtyczki BLIK nie znaleziono
- Magento (Adobe Commerce Marketplace): [Przelewy24 – oficjalny moduł](https://commercemarketplace.adobe.com/przelewy24-magento2-przelewy24.html) (BLIK, raty), [Autopay/BlueMedia](https://commercemarketplace.adobe.com/bm-bluepayment.html); [dpay.pl](https://docs.dpay.pl/en/integrations/e-commerce/magento2) (BLIK Level 0); [Tpay](https://docs-api.tpay.com/magento2/); PayU dla Magento – nie potwierdzono
- Shopware Payments (z PayPal) – dostępne we wszystkich planach, ale tylko DE/AT — [Shopware July 2026](https://www.shopware.com/en/news/shopware-6-release-news-july-2026/) (nieprzydatne w PL)
- Magento 2.4.8 usunął Sofort i Giropay; wbudowane Braintree z express checkout — [MageComp](https://magecomp.com/blog/magento-open-source-2-4-8-release-notes/)

### Gaps
- Edycja zamówień w panelu, zamówienia tworzone przez admina, checkout gościa – niezweryfikowane źródłowo.

## 6. Dostawa (obszar 6)

### Cited Findings
- Shopware – InPost: darmowa wtyczka open-source BitBag (Packagist), wybór paczkomatu w checkout; zgodność z wersją 6.4 – wymaga sprawdzenia dla 6.7 (brak oferty InPost w Shopware Store w wynikach) — [BitBag shopware-inpost-plugin](https://root.packagist.org/packages/bitbag/shopware-inpost-plugin)
- Magento – InPost: oficjalne moduły InPost (paczkomaty v1.16.1, InPost Pay 2.6.1 z marca 2026, wymaga Magento OS 2.4.6+/PHP 8.2+, wspiera Community i Commerce), moduł InPost International z Hyvä Checkout — [InPost docs Magento](https://dokumentacja-inpost.atlassian.net/wiki/spaces/PL/pages/359956481/ENG+InPost+Pay+-+Magento), [InPost Magento 2 International](https://dokumentacja-inpost.atlassian.net/wiki/spaces/PL/pages/929628161/ENG+Magento+2+International)
- Shopware: reguły kosztów wysyłki przez Rule Builder — [Shopware Pricing](https://www.shopware.com/en/pricing/)

### Gaps
- Etykiety kurierskie, śledzenie, integracje przewoźników w rdzeniu – niezweryfikowane.

## 7. Zwroty, reklamacje, RMA (obszar 7)

### Takeaway
Shopware: Returns Management w planach płatnych (Rise+), nie w CE. Magento: RMA – w Adobe Commerce (na podstawie wiedzy ogólnej, w tej sesji nie potwierdzone źródłowo); GraphQL 2.4.8 obsługuje zwroty dla zamówień gości.

### Cited Findings
- Shopware Returns management: Rise/Evolve/Beyond — [Commercial Features](https://docs.shopware.com/en/shopware-6-en/features/commercial), [docs – Returns management](https://docs.shopware.com/en/shopware-6-en/orders/returns-management); inne rozwiązanie: wtyczka [Return management / credit notes](https://store.shopware.com/en/okueh12040366736m/return-management-returns-credit-notes.html)
- Magento 2.4.8 GraphQL: „returns support guest orders” — [MageComp](https://magecomp.com/blog/magento-open-source-2-4-8-release-notes/)

### Gaps
- Źródło potwierdzające RMA/credit memo/store credit w Open Source vs Adobe Commerce; częściowe refundacje.

## 8–10. Konta klientów, dokumenty, panel, raportowanie (obszary 8, 9, 10)

### Cited Findings
- Shopware: Roles & Permissions i Flow Builder w tabeli komercyjnej (Rise+), webhook actions Evolve+, delayed actions Beyond; Enhanced roles Evolve+ — [Commercial Features](https://docs.shopware.com/en/shopware-6-en/features/commercial) (podstawowe ACL i Flow Builder istnieją w CE – [?] niezweryfikowane; tabela może dotyczyć rozszerzeń)
- AI Copilot (Rise+): opisy produktów, właściwości, klasyfikacja klientów, asystent eksportu, podsumowanie opinii, tłumaczenie opinii — [Commercial Features](https://docs.shopware.com/en/shopware-6-en/features/commercial)
- Magento: Adobe Commerce Intelligence (dashboardy) i segmentacja klientów w Adobe Commerce; w OS przez rozszerzenia — [scandiweb](https://scandiweb.com/blog/adobe-commerce-vs-magento-open-source/)

### Gaps
- Faktury/dokumenty (dowody dostawy, korekty) w Shopware CE oraz Magento OS; maile transakcyjne; raportowanie wbudowane; grupy klientów – brak źródeł w tej sesji.

## 11. Marketing, SEO, CMS (obszar 11)

### Cited Findings
- Shopware Shopping Experiences (CMS): tabela commercial wymienia we wszystkich planach; CE ma „wizualny page builder” — [Pricing](https://www.shopware.com/en/pricing/), [Commercial Features](https://docs.shopware.com/en/shopware-6-en/features/commercial); Publisher (wersjonowanie/szkice) i CMS rules – Evolve+ — [Evolve docs](https://docs.shopware.com/en/shopware-6-en/features/shopware-evolve)
- Shopware: natywne llms.txt i agents.md na kanał sprzedaży (6.7.12) — [July 2026](https://www.shopware.com/en/news/shopware-6-release-news-july-2026/)
- Magento Page Builder: wg scandiweb w obu edycjach; wg mgt-commerce jako ekskluzywny dla Adobe Commerce (**rozbieżność**); content staging, dynamiczne bloki – tylko Adobe Commerce — [scandiweb](https://scandiweb.com/blog/adobe-commerce-vs-magento-open-source/), [mgt-commerce](https://www.mgt-commerce.com/blog/magento-2-commerce-vs-magento-2-open-source/)

### Gaps
- Blog, newsletter, opinie, feedy produktowe – niezweryfikowane.

## 12. Wielojęzyczność, multi-store, marketplace, POS (obszar 12)

### Cited Findings
- Shopware: marketplace’y przez ChannelEngine (dodatek) — [Pricing](https://www.shopware.com/en/pricing/)
- Magento Open Source: pojedyncza baza może zwalniać przy wielu sklepach; Adobe Commerce wspiera wiele baz — [scandiweb](https://scandiweb.com/blog/adobe-commerce-vs-magento-open-source/), [mgt-commerce](https://www.mgt-commerce.com/blog/magento-2-commerce-vs-magento-2-open-source/)

### Gaps
- Kanały sprzedaży/języki/waluty natywnie, POS – brak źródeł (wiedza ogólna: oba mają wiele języków/walut/sklepów w rdzeniu – niezweryfikowane).

## 13. B2B i enterprise (obszar 13)

### Cited Findings
- Shopware B2B (P): Evolve+ – quote management, sales reps, budget & approvals, quick ordering, order list, enhanced roles; Beyond – customer-specific pricing, subscription, multi-inventory — [Commercial Features](https://docs.shopware.com/en/shopware-6-en/features/commercial)
- Magento: Open Source bez natywnego B2B (rozszerzenia); Adobe Commerce: konta firmowe, ceny kontraktowe, wyceny, zamówienia zakupowe; B2B w PaaS/on-prem to osobna licencja — [scandiweb](https://scandiweb.com/blog/adobe-commerce-vs-magento-open-source/), [Adobe Experience League](https://experienceleague.adobe.com/en/docs/commerce/information-center/explore-platforms-and-services/platform-and-services)
- Zbudowanie funkcji B2B w Open Source to wg agencji 50–150 tys. USD — [mgt-commerce](https://www.mgt-commerce.com/blog/magento-2-commerce-vs-magento-2-open-source/) (szacunek agencji)
- Staging treści: Adobe Commerce – natywny, Open Source – brak; segmentacja klientów – tylko Adobe Commerce — [mgt-commerce](https://www.mgt-commerce.com/blog/magento-2-commerce-vs-magento-2-open-source/)

## 14. Headless, dostępność, rozszerzalność (obszar 14)

### Cited Findings
- Shopware 6.7: duży nacisk na dostępność w związku z EAA — [burgdigital](https://www.burgdigital.de/en/news/shopware-6.7) (poziom zgodności nieweryfikowany)
- Shopware Store API/Composable Frontends: źródeł nie pobrano `[?]`
- Adobe Commerce GraphQL (PaaS: osobne schematy core/B2B/Catalog Service/Live Search/Recommendations, spinane przez API Mesh; SaaS: supergraph) — [Adobe GraphQL docs](https://developer.adobe.com/commerce/webapi/graphql/)
- Adobe Commerce Storefront na Edge Delivery Services w ACCS; Luma, Hyvä i PWA nie migrują bezpośrednio – wymagają przebudowy; przebudowa storefrontu to 35–50% nakładu migracji (szacunek agencji); cart/checkout w Optimizer przez API Mesh i App Builder — [Adobe Experience League](https://experienceleague.adobe.com/en/docs/commerce/information-center/explore-platforms-and-services/platform-and-services), wyniki wyszukiwania [elogic](https://elogic.co/blog/adobe-commerce-saas/), [scandiweb Optimizer vs SaaS](https://scandiweb.com/blog/adobe-commerce-optimizer-vs-saas-paas-on-prem/)

### Gaps
- Composable Frontends, PWA Studio status, webhooks/Apps System, EAA zgodność storefrontu – niezweryfikowane.

## 15. Segmenty, słabości, doświadczenia (pytania kluczowe)

### Cited Findings
- G2: Shopware 4,2/5 (~197 opinii), Adobe Commerce 4,0/5 (576) — [G2: Adobe Commerce vs Shopware](https://www.g2.com/compare/adobe-commerce-formerly-magento-commerce-vs-shopware); G2 pokazuje cenę Adobe jako „Contact us”, Rise od 600 USD/mies.
- Opinie Shopware: zrównoważone – moc funkcji vs wymagana ekspertyza; Magento: aktualizacje wymagają okien serwisowych, hosting self-hosted zależy od operatora — [RFP.wiki](https://www.rfp.wiki/vendors/magento-adobe-commerce/shopware) (agregator, niska pewność 58–70%)
- Open Source: wsparcie tylko społeczność, łatki stosuje sklep; Adobe Commerce Cloud: Fastly, WAF, New Relic, SLA 99,99%, PCI L1 — [scandiweb](https://scandiweb.com/blog/adobe-commerce-vs-magento-open-source/)
- Migracja do ACCS = przebudowa, nie lift-and-shift — [elogic](https://elogic.co/blog/adobe-commerce-saas/) (blog agencji)

### Inferences
- Shopware: średni sklepy DE/EU i B2B-lite; gating edycji (Evolve/Beyond) zmusza do wyższych planów dla B2B/multi-inventory. Magento/Adobe: duże sklepy z budżetem na agencję i hosting; OS = duża elastyczność za cenę wymagań operacyjnych.

### Gaps
- Brak rzetelnych, cytowalnych opinii kupców (Reddit/forum) o zależności od wtyczek i kosztach aktualizacji; oparto się na agregatorach i blogach agencji (anegdotycznie).
- Sprawdzono mało obszarów szczegółowo (obszary 1, 4, 5–10 w CE/OS) – wymagają dopełnienia przed użyciem w macierzy.
