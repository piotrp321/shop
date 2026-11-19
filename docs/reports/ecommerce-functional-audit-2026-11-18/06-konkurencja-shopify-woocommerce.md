# Shopify i WooCommerce – zestaw funkcji (stan: listopad 2026)

Uwaga metodyczna: badanie ograniczone do ok. 15 wywołań narzędzi. Pełne zweryfikowane dane są dla cen, limitu wariantów, płatności w Polsce, Omnibus, zwrotów, B2B, Markets i wydań WooCommerce. Pozostałe obszary (1–14) mają w sekcji „Luki” oznaczone elementy z wiedzy własnej, NIEzweryfikowane źródłem – raport końcowy nie powinien ich traktować jako faktów bez sprawdzenia. Legenda: N = natywne/w planie bazowym lub core, P = płatna aplikacja/wtyczka, I = zewnętrzna usługa/integracja, C = custom development.

## 1. Ceny i koszty (plany, prowizje, typowy stos)

### Takeaway
Shopify w PL: Basic 109 zł/mies. (79 zł przy rocznym), Grow 319/239, Advanced 1630/1220, Plus od 2100 EUR; WooCommerce core jest darmowy, ale hosting (25–350 USD/mies. wg Woo) i płatne rozszerzenia (roczne subskrypcje) tworzą koszt. Obie platformy mają ukryte koszty: aplikacje (Shopify) i utrzymanie/dev (Woo) – źródła porównawcze to głównie blogi agencji (stronnicze).

### Cited Findings
- Shopify (cennik w PLN, Plus w EUR): Basic 109 zł/mies., 79 zł/mies. przy rocznym; Grow 319/239 zł; Advanced 1 630/1 220 zł; Plus od 2 100 EUR. Stawki kart online: 1,95% + 1,20 zł (Basic), 1,8% + 1,20 zł (Grow), 1,65% + 1,20 zł (Advanced), 1,45% + 1,20 zł (Plus). Opłata za zewnętrzną bramkę: 2% / 1% / 0,6% / 0,2%. — [shopify.com/pricing](https://www.shopify.com/pricing)
- Konta personelu: Basic brak dodatkowych, Grow 5, Advanced 15, Plus bez limitu; lokalizacje magazynowe 10 (Basic–Advanced), 200 (Plus). — [shopify.com/pricing](https://www.shopify.com/pricing)
- Grow: do 3 katalogów B2B; Advanced: stawki przewoźników na żywo od zewnętrznych (live third-party shipping rates), 1 sklep headless, 2x limity API; Plus: w pełni konfigurowalny checkout, nielimitowane katalogi B2B, do 25 sklepów headless, 10x limity API, wsparcie telefoniczne, aplikacje Plus Certified. — [shopify.com/pricing](https://www.shopify.com/pricing)
- Wszystkie plany: nielimitowane produkty, sprzedaż w social/marketplace, asystent AI Sidekick, czat 24/7. POS Pro 339 zł/mies. za lokalizację. — [shopify.com/pricing](https://www.shopify.com/pricing)
- WooCommerce core: darmowy, „no monthly subscription”; hosting wybierany osobno, typowo 25–350 USD/mies. — [woocommerce.com/pricing](https://woocommerce.com/pricing/)
- Ceny rozszerzeń Woo (roczne, USD): Subscriptions 279, Product Bundles 79, Composite Products 149, Memberships 199, Bookings 249, Product Add-Ons 79, Table Rate Shipping 119, Min/Max Quantities 49, Smart Coupons 99. WooPayments – darmowy download. — [woocommerce.com/products](https://woocommerce.com/products/)
- Typowe koszty ukryte (źródła agencyjne/porównawcze, niskiej wiarygodności): Shopify – aplikacje 100–200 USD/mies. lub ok. 185 USD średnio; Woo – utrzymanie 1 000–5 000 USD/rok. — [agregat wyszukiwania: elementor](https://elementor.com/resources/how-to/shopify-vs-woocommerce-2/), [mantrabrain](https://mantrabrain.com/blog/woocommerce-vs-shopify/), [costbench](https://costbench.com/compare/shopify-vs-woocommerce/) (fragmenty z wyszukiwania, strony nie czytane w całości)
- Aplikacje Omnibus na Shopify: OmniProof Pro 14,99 USD/mies.; Omnibus Price Compliance 16,99 USD/mies. — [OmniProof](https://apps.shopify.com/omniproof), [Omnibus Price Compliance](https://apps.shopify.com/omnibus-price-compliance?locale=pl)
- Aplikacje InPost na Shopify: Codie „InPost Paczkomaty Orlen Paczka” od 9 USD (Starter) / 15 USD (Pro, paczkomaty w checkoucie, wymaga CCS); InPost Pro 19 USD/mies. — [InPost Paczkomaty Orlen Paczka](https://apps.shopify.com/inpost-paczkomaty-1), [InPost Pro](https://apps.shopify.com/inpost-pro?locale=pl)

### Inferences
- Realistyczny mały sklep PL na Shopify: plan 79–109 zł + 2–4 aplikacje PL (InPost ~15 USD, Omnibus ~15 USD, bramka PL, ew. faktury) ≈ ok. 150–250 zł/mies. aplikacji dodatkowo – szacunek własny z cen powyżej, nie ze źródła.
- Realistyczny Woo: hosting + 3–6 rozszerzeń rocznych (np. Bundles 79 + Add-Ons 79 + Table Rate 119 USD) ⇒ koszt rozłożony na rok, ale większy udział pracy (aktualizacje, bezpieczeństwo).

### Gaps
- Typowy zweryfikowany stos kosztów mid-size (np. wyszukiwarka Algolia/Klaviyo/recenzje) – brak źródła pierwotnego; nie sprawdzono cen Klaviyo, Judge.me, Searchanise itd.
- Ceny hostingu Woo (Woo Express/Hosting) – strona nie podała; nie zweryfikowano. Polskie ceny hostingu nieczytane.
- Cena Shopify Plus „od 2100 EUR” – wzór opłaty za procent GMV nie zweryfikowany.
- Ceny WP Desk (InPost, Omnibus, faktury) – brak.

## 2. Mapa funkcji N/P/I/C (obszary 1–14)

### Takeaway
Zweryfikowane źródłami: warianty (N, 2048), Omnibus (P na obu), zwroty self-service (N Shopify), B2B (N od Basic, szczegóły wg planu), Markets (N), płatności PL (P/I, patrz sekcja 3), checkout Plus-only. Reszta obszarów – patrz Gaps (wiedza własna, niezweryfikowana).

### Cited Findings
**1. Katalog**
- Shopify: limit wariantów podniesiony z 100 do 2 048 dla wszystkich merchantów (Shopify changelog podaje 15.10.2025); nadal max 3 opcje produktu i 250 mediów na produkt (wg streszczenia wyszukiwania, nie sprawdzone na oficjalnej stronie pomocy). — [changelog.shopify.com](https://changelog.shopify.com/posts/we-ve-increased-the-product-variant-limit-to-2048), [shopify.dev changelog](https://shopify.dev/changelog/the-product-variant-limit-is-now-2048-for-all-merchants); inne źródło trzecie datuje na 2024 (sprzeczność z Shopify) — [craftshift](https://craftshift.com/shopify-2048-variant-limit-explained-2026/)
- Shopify: Combined Listings (Plus) – listing może mieć do 3 opcji + opcje produktów składowych. — [help.shopify.com](https://help.shopify.com/en/manual/products/variants)
- Woo: wersja 10.9 (23.06.2026) dodaje m.in. atrybut Color/Image (próbniki) w beta; galerie wariantów, swatche, listy zakupowe w beta. — [developer.woocommerce.com 10.9](https://developer.woocommerce.com/2026/06/23/woocommerce-10-9/) (swatche: wg wyszukiwania, [WooCommerce 10.x](https://developer.woocommerce.com/archive/page/87))
- Woo: Bundles 79 USD/rok i Composite 149 USD/rok (P) dla zestawów. — [woocommerce.com/products](https://woocommerce.com/products/)
**3. Ceny/promocje/Omnibus**
- Shopify: brak natywnego Omnibus w źródłach; dostępne aplikacje (P): OmniProof (Pro 14,99 USD, reguły DE/PL/FR), Omnibus Pricing, Omnibus by Netkodo, Omnibus Price On Autopilot itd. — [OmniProof](https://apps.shopify.com/omniproof), [Omnibus by Netkodo](https://apps.shopify.com/omnibus-by-netkodo?locale=pl)
- Woo: wtyczki (P/free): WC Price History, Omnibus (wordpress.org, część recenzji zgłasza błędne liczenie), WPDesk Omnibus (płatna), WooCommerce Omnibus check. Wiele buduje historię dopiero od aktywacji. — [WC Price History](https://pl.wordpress.org/plugins/wc-price-history/), [Omnibus](https://wordpress.org/plugins/omnibus/), [WPDesk Omnibus](https://plugintheme.net/pl/product/wp-desk-omnibus)
- Woo: Smart Coupons 99 USD/rok (P). — [woocommerce.com/products](https://woocommerce.com/products/)
**5. Płatności** – patrz sekcja 3.
**6. Wysyłka**
- Shopify: stawki przewoźników na żywo (live third-party shipping rates) tylko od Advanced (nie Basic/Grow). — [shopify.com/pricing](https://www.shopify.com/pricing)
- Shopify: InPost przez aplikacje (P) lub integrację z Base.com (I, wliczona w abonament Base.com). Oficjalna aplikacja InPost ma słabe oceny (~2,7–2,8). — [Base.com](https://base.com/pl-PL/integracje/shopify/paczkomaty/), [InPost oficjalna](https://apps.shopify.com/inpost-oficjalna-aplikacja?locale=pl)
- Woo: Table Rate Shipping 119 USD/rok (P); InPost WooCommerce to wtyczka WP Desk, nie InPost (P). — [woocommerce.com/products](https://woocommerce.com/products/), [wpdesk.pl](https://www.wpdesk.pl/?p=6131)
**7. Zwroty**
- Shopify (N): zwroty, wymiany, etykiety zwrotne, częściowe i pełne refundy, self-service zwroty/anulacje ze strony statusu zamówienia, reguły kwalifikowalności. Plan nieustalony ze strony pomocy. — [help.shopify.com returns](https://help.shopify.com/en/manual/fulfillment/managing-orders/returns)
**9. Admin/automatyzacja**
- Woo 10.9: logowanie wysyłek e-mail w Status > Logs (N); MCP abilities dla produktów i zamówień. — [developer.woocommerce.com 10.9](https://developer.woocommerce.com/2026/06/23/woocommerce-10-9/)
**12. Międzynarodowo**
- Shopify Markets (N): per rynek waluta, język, ceny, dostępność, domeny, podatki, wysyłka; zniżki i karty podarunkowe działają między rynkami. Szczegóły cła/OSS niepotwierdzone. — [help.shopify.com/markets](https://help.shopify.com/en/manual/markets)
**13. B2B**
- Shopify B2B dostępne w Basic, Grow, Advanced, Plus (nie Starter/Retail); zakres zależy od planu (Grow do 3 katalogów, Plus bez limitu). — [help.shopify.com/b2b](https://help.shopify.com/en/manual/b2b), [shopify.com/pricing](https://www.shopify.com/pricing)
**14. Checkout / headless / API**
- Checkout Extensibility: dostosowanie kroków informacji/wysyłki/płatności i Branding API tylko Plus; nie-Plus: branding w edytorze, aplikacje na stronach thank-you/status zamówienia; struktura checkoutu stała. Skrypty/checkout.liquid wygaszone (daty wg źródeł wtórnych: Plus 28.08.2025, nie-Plus 26.08.2026). — [shopify.dev checkout](https://shopify.dev/docs/apps/build/checkout), [changelog](https://changelog.shopify.com/posts/the-checkout-liquid-theme-file-is-being-deprecated), [blackbeltcommerce](https://www.blackbeltcommerce.com/shopify-plus-checkout-customization/)
- Headless: Advanced 1 sklep, Plus do 25 (Hydrogen/Storefront API wg ogólnej wiedzy; plan wg cennika). — [shopify.com/pricing](https://www.shopify.com/pricing)
- Woo: Store API; w 10.7 sync-on-read HPOS wyłączony domyślnie, cache priming usuwa N+1 w /wc/v4/orders; 10.9 opóźnia tworzenie draft order. — [10.7](https://developer.woocommerce.com/2026/04/15/woocommerce-10-7/), [10.9](https://developer.woocommerce.com/2026/06/23/woocommerce-10-9/)

### Inferences
- Dla macierzy: „Omnibus” = P (Shopify i Woo), jakość zależna od wtyczki; „zwroty self-service” = N na Shopify, na Woo brak potwierdzenia w źródłach (zwykle P/C).
- „InPost w checkout” = P na obu platformach; na Shopify wymaga zwykle Checkout Customization Support (CCS) wg opisu aplikacji Codie.

### Gaps (wiedza własna, NIEzweryfikowane w tym badaniu – do sprawdzenia przed użyciem)
- Obszary 2 (facety/wyszukiwanie: Shopify Search & Discovery N, Woo – natywne ograniczone, Elasticsearch/Algolia I), 3 (VAT/OSS/waluty: Shopify automatyczne podatki UE; Woo ręczne stawki lub WooCommerce Tax/dodatki), 4 (Shopify multi-location N, transfery N; Woo – magazyny wielolokalizacyjne tylko P), 5 (draft orders/edycja zamówień Shopify N), 8 (konta klientów, powiadomienia), 10 (raporty: Shopify zależne od planu; Woo Analytics N), 11 (SEO, blog, porzucone koszyki, recenzje, Google Merchant), 13 (Flow – dostępność wg planu niezweryfikowana), 14 (dostępność/WCAG motywów) – żadne z tych nie zostało potwierdzone w oficjalnych źródłach w tej sesji.
- Brak weryfikacji limitu 3 opcji / 250 mediów na oficjalnej stronie Shopify (tylko streszczenie wyszukiwarki).
- Plan dostępności self-service zwrotów Shopify oraz wsparcie Omnibus natywne w Shopify – nie ustalono.
- Brak sprawdzonej strony „Shopify B2B features by plan” (company accounts, net terms).

## 3. Płatności w Polsce (BLIK, Przelewy24, PayU)

### Takeaway
Shopify Payments jest na liście krajów dla merchantów z Polską, ale lokalne metody BLIK i Przelewy24 NIE są obsługiwane przez Shopify Payments dla sprzedawców z Polski (wg changelogu z 30.05.2026); trzeba użyć aplikacji/bramki zewnętrznej z dopłatą 0,2–2% zależnie od planu. W Woo moduły P24/PayU/Tpay są oferowane przez operatorów (BLIK wewnątrz tych modułów).

### Cited Findings
- Poland jest na liście krajów Shopify Payments. — [help.shopify.com supported countries](https://help.shopify.com/en/manual/payments/shopify-payments/supported-countries)
- Changelog „local payment methods in additional countries” (30.05.2026): Polska wymieniona dla MobilePay i TWINT; Polska nie jest na liście BLIK ani Przelewy24 (wg odczytu strony; interpretacja: te metody dotyczą sprzedawców z innych krajów). — [changelog.shopify.com](https://changelog.shopify.com/posts/more-local-payment-methods-are-now-available-in-additional-countries); sprzeczne: blog Attomy twierdzi, że BLIK jest dostępny przez Shopify Payments dla PL i PLN — [attomy](https://www.attomy.io/en/post/blik-shopify-a-comprehensive-guide-for-polish-online-shops)
- Aplikacja Przelew24/Blik w Shopify App Store: roczna opłata minimalna + opłaty od wolumenu w USD, jedna opinia 1,0. — [apps.shopify.com/przelew24-blik](https://apps.shopify.com/przelew24-blik?locale=pl)
- Bramki zewnętrzne dodają opłatę Shopify 2%/1%/0,6%/0,2% (Basic–Plus). — [shopify.com/pricing](https://www.shopify.com/pricing)
- Woo: Przelewy24 udostępnia moduł WooCommerce do pobrania z BLIK w sklepie; PayU i Tpay mają własne moduły; BLIK jest częścią modułów P24/PayU, nie osobną wtyczką. — [przelewy24.pl/wtyczki/woocommerce](https://www.przelewy24.pl/wtyczki/woocommerce), [semtak.pl](https://semtak.pl/en/blog/ecommerce/woocommerce-payment-gateways/)
- Woo: WooPayments darmowy do pobrania; dostępność w Polsce niezweryfikowana. — [woocommerce.com/products](https://woocommerce.com/products/)

### Inferences
- Dla macierzy: BLIK/P24/PayU na Shopify = P/I + dopłata za bramkę zewnętrzną; na Woo = P (moduł operatora, często darmowy, prowizja u operatora).

### Gaps
- Czy Shopify Payments faktycznie obsługuje Polskę dla BLIK – źródła sprzeczne; zweryfikować w panelu Shopify.
- Ceny modułów Woo operatorów i dostępność WooPayments w PL.
- Wsparcie Apple Pay/Google Pay/Shop Pay w Polsce – niezweryfikowane.

## 4. Zmiany 2025–2026

### Takeaway
Shopify: wariant 2048 (10.2025), Editions Winter '26 mocno AI (Sidekick, Rollouts, SimGym, Agentic Storefronts), wygaszanie checkout.liquid/Scripts. WooCommerce: cykl wydań 10.x (10.9 w czerwcu 2026), HPOS, zmiany Store API, wycofanie block-based product editor, 11.0 w przygotowaniu.

### Cited Findings
- Shopify Winter '26 (“RenAIssance Edition”, 150+ aktualizacji): Sidekick wykonuje zadania (aplikacje, segmenty, motywy), Shopify Rollouts (natywne A/B), SimGym, Product Network, Agentic Storefronts powiązane z Universal Commerce Protocol; część w early access. — [ecommerce-platforms.com](https://ecommerce-platforms.com/shopify/shopify-editions-winter-2026), [meetanshi](https://meetanshi.com/blog/shopify-editions-winter-2026/) (blogi, nie oficjalna strona shopify.com/editions/winter2026 – nie odczytana)
- Woo 10.1 przebudował ładowanie Checkout block; 10.4 poprawki typografii; 10.6 lazy load obrazów; 10.7 HPOS sync-on-read off, cache priming; 10.9 (23.06.2026) wyżej. Edytor produktu block-based oznaczony jako przestarzały (daty sprzeczne: 10.1 vs 10.9; usunięcie w 11.0 wg Digital Applied). — [developer.woocommerce.com](https://developer.woocommerce.com/archive/page/87), [10.7](https://developer.woocommerce.com/2026/04/15/woocommerce-10-7/), [digitalapplied 11.0](https://digitalapplied.com/blog/woocommerce-11-pre-release-checkout-recovery-hpos-2026)

### Inferences
- Dla audytu: Shopify rozwija AI i agentic commerce, Woo – stabilizację checkoutu/HPOS i MCP.

### Gaps
- Oficjalne strony shopify.com/editions (Summer '26) nieczytane; zmiany Woo.com cennika/Woo Express nieustalone; Shopify Flow, Hydrogen nowości niesprawdzone.

## 5. Segmenty, słabości, czas do pierwszej sprzedaży

### Takeaway
Źródła (blogi agencyjne, anegdotyczne) wskazują: Shopify – szybki start i przewidywalny abonament, ale koszt aplikacji i ograniczenia checkoutu poza Plus; Woo – elastyczność i brak abonamentu, ale utrzymanie, wydajność i jakość wtyczek.

### Cited Findings
- Shopify: koszty aplikacji i upgrade planu jako główne „zaskoczenia”; Woo: koszty pracy/utrzymania; większość źródeł to blogi z interesem handlowym. — [elementor](https://elementor.com/resources/how-to/shopify-vs-woocommerce-2/), [mantrabrain](https://mantrabrain.com/blog/woocommerce-vs-shopify/)
- Woo: ekosystem 60 000+ wtyczek WP, jakość zróżnicowana. — j.w.
- Shopify: struktura checkoutu stała nawet na Plus; funkcje checkoutu Plus-only. — [blackbeltcommerce](https://www.blackbeltcommerce.com/shopify-plus-checkout-customization/)
- Opinie na App Store o polskich aplikacjach: oficjalna aplikacja InPost 2,7–2,8, InPost Pro 2,5 – jakość lokalnych integracji niska (oceny z wyników wyszukiwania). — [InPost Pro](https://apps.shopify.com/inpost-pro?locale=pl)

### Inferences
- Shopify – mały/średni D2C, szybki start; Woo – sklepy z potrzebą kontroli/lokalnych integracji; obie wymagają płatnych dodatków dla PL (InPost, Omnibus, faktury).
- Czas do pierwszej sprzedaży: Shopify – godziny/dni; Woo – dni (hosting, wtyczki PL) – ocena własna, bez źródła.

### Gaps
- Brak źródeł Reddit/G2/Capterra (nie przeszukano); brak twardych danych o wydajności Woo przy skali.
- Czas do pierwszej sprzedaży – brak źródła.
- Faktury VAT/KSeF, NIP, e-fakturowanie – nie sprawdzano (krytyczne w PL).
