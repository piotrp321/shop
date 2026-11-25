# Audyt wydajności: panel administracyjny na dużych zbiorach (2026-11-25)

Rewizja: main 2b4258c7. Pomiary: `EXPLAIN (ANALYZE, BUFFERS)` na bazie `shop-perf25-postgres-1` (profil medium:
1,0 mln produktów, 3,0 mln wariantów, 1,0 mln zamówień, 98,6 tys. klientów, 180 kategorii), sesje
`SET default_transaction_read_only = on; SET statement_timeout = '60s'`. Surowe plany: `scratchpad/results/admin/*.out`
(pliki `.sql` obok to dokładne zapytania). Uwaga do pomiarów: lokalny Postgres ma `shared_buffers = 128MB` (dane
1,5 GB samej tabeli produktów nie mieszczą się w buforze), a w czasie pomiaru bazę obciążali inni audytorzy, więc
czasy „zimne” są zawyżone, a powtórzenia tego samego zapytania wahały się do 4x (np. mała kategoria: 2,3 s → 7,0 s →
9,4 s). Podaję zakres, a wnioski opieram na liczbie przeczytanych wierszy/bloków, która od szumu nie zależy.

## Podsumowanie

Panel jest zaprojektowany starannie: wszędzie paginacja kursorem (keyset), liczniki ograniczone do 10 000
(`BoundedCounter`), indeksy częściowe pod filtry (szkice, bez zdjęć, poziomy stanów, zwroty), tabela podsumowań klientów
(`ordering_customer`) i zagregowana sprzedaż godzinowa (`ordering_sales_hour`), szacunek liczby produktów z
`reltuples`. Zamówienia, klienci, dashboard, historie cen i stanów, kategorie mają na medium czasy 0,05–45 ms i plany,
które nie rosną z danymi (poza licznikami ograniczonymi z góry).

Problemem jest **jedno wspólne zapytanie listy produktów** (`DbalBackofficeProductSource::page`), z którego korzystają
cztery ekrany: Produkty, Ceny, Stany magazynowe i wybór wariantów promocji. Dwa jego filtry mają przypadki, w których
planista wybiera przejście całego indeksu `(name, id)` z filtrem na każdym wierszu (odczyt całej sterty tabeli): filtr
**małej kategorii** i **wyszukiwanie po nazwie** frazą 2‑znakową albo frazą o średniej częstości i „późnej” pozycji w
alfabecie. Koszt jest liniowy względem liczby produktów: 1–9 s na medium, prognoza 10–90 s przy 10 mln. W API nie ma
żadnego `statement_timeout` ani limitu czasu żądania, a panel nie przerywa poprzednich żądań, więc kilka takich zapytań
z pisania w polu wyszukiwania zajmuje wątki tego samego wdrożenia `api`, które obsługuje sklep.

## Tabela ekranów

| Ekran | Żądania (front) | SQL (plik:linia) | Czas na medium | Plan | Prognoza 10 mln prod. / 5 mln zam. / 1 mln klientów | Waga |
|---|---|---|---|---|---|---|
| Produkty – lista bez filtrów, każde sortowanie | 1× `GET /products` (+ drzewo kategorii raz); kursor; total tylko na 1. stronie | `Catalog/Infrastructure/Persistence/Doctrine/DbalBackofficeProductSource.php:69` | 0,04–0,25 ms; total ≤10 000: 4 ms | Index Scan `(name,id)` / `(updated_at,id)` + LIMIT | stałe | OK |
| Produkty – daleka strona (kursor „Zupa…”) | jw. | :64 (warunek wierszowy) | 0,14 ms | Index Cond na kursorze | stałe | OK |
| Produkty – podsumowanie strony (slug, kategoria, liczba wariantów, zdjęcie) | to samo żądanie, 2. zapytanie | :95 | 1,0 ms (25 id) | PK + indeks wariantów | stałe | OK |
| Produkty – szukaj „termos” (12,9 tys. trafień, alfabetycznie na „T”), sort `name` | debounce 300 ms, bez anulowania | :29, :69 | **2,35 s zimno / 1,07 s ciepło**; 931 tys. bloków | Index Scan `(name,id)`, Filter ILIKE, Rows Removed 918 460 | **10–25 s** (liniowo) | **Wysoka** |
| Produkty – szukaj 2 znaki bez trafień „qx”, sort `name` | jw. | :29 | **0,72–1,45 s** | Bitmap GIN trgm zwraca 617 tys. kandydatów (z 2 znaków brak użytecznego trigramu), recheck 1 mln | **7–15 s** | **Wysoka** |
| Produkty – 2 znaki „zx”, sort `-updatedAt` | jw. | :29 | 1,10 s | Index Scan Backward `(updated_at,id)`, Rows Removed 1 000 191 | 11 s | Wysoka |
| Produkty – total dla „qx” | jw. (1. strona) | `Shared/Infrastructure/Persistence/BoundedCounter.php:54` | 204 ms | Parallel Index Only Scan `(name,id)` całego indeksu | 2 s | Średnia |
| Produkty – szukaj częste „pro”/„ka”, rzadkie „zupa”, „ravotar”, pełna nazwa | jw. | :29 | 0,8–39 ms; total 11–38 ms | walk z szybkim trafieniem albo GIN + top‑N sort | stałe/log (do ~100 ms) | OK |
| Produkty – szukaj „termos”, sort `-updatedAt` | jw. | :29 | 3,4 ms | walk `(updated_at,id)`, trafienia rozproszone | stałe | OK (zależy od losu) |
| Produkty – kategoria główna (Turystyka) | 1× GET | :35–42 | 0,2 ms; total 91 ms | walk `(name,id)` + hashed SubPlan | stałe; total ~100 ms | OK |
| Produkty – liść 21 tys. produktów | jw. | :35–42 | 3,8–9,7 ms; total 116 ms (Seq Scan do 10 001) | jw. | stałe/~120 ms | OK |
| Produkty – **mała kategoria** (20 produktów) sort `name` | jw. | :35–42 | **2,3 s zimno; 7,0–9,4 s pod obciążeniem**; 1,0 mln bloków | walk `(name,id)`, Filter hashed SubPlan, Rows Removed 1 000 171 | **23–90 s** | **Wysoka** |
| Produkty – mała kategoria sort `-updatedAt` / total | jw. | :35–42 | 602 ms / 310 ms (Seq Scan) | walk / Seq Scan całej tabeli | 6 s / 3 s | Wysoka |
| Produkty – liść + „pro”; liść + bez zdjęć | jw. | :29+:35 | 180 ms; 162 ms | walk z dwoma filtrami | ~1,5–2 s | Średnia |
| Produkty – szkice, opublikowane, bez zdjęć (+ total) | jw. | :147, :32 | 0,04–2,3 ms; total 12 ms | indeksy częściowe | stałe | OK |
| Ceny (`/prices`), Stany (`/stock`), wybór wariantów promocji | `pricePage.ts`, `stockPage.ts`, `PromotionVariantPicker.vue` | to samo zapytanie przez `Pricing/Infrastructure/Catalog/QueryBusProductDirectory.php:48` i `Inventory/Infrastructure/Catalog/QueryBusProductDirectory.php:46` | jak Produkty | jak Produkty | jak Produkty | **Wysoka** (dziedziczona) |
| Stany – filtr poziomu (brak / niski) + total | 1× GET | `Inventory/Infrastructure/Persistence/Doctrine/DbalStockLevelProducts.php:24-35`, :68 | total 0,65–0,76 ms (limit 1000) | indeks częściowy po `product_id` | stałe | OK |
| Historia ceny / ruchy magazynowe wariantu | 1× GET przy otwarciu dialogu | `Pricing/.../DbalPriceHistoryReader.php:34`, `Inventory/.../DbalStockHistoryReader.php:36` | indeks `(variant_id, changed_at DESC, id)` [KOD] | keyset po wariancie | stałe | OK |
| Edycja produktu – odczyt | `GET /products/{id}` + `GET /prices?productId` + `GET /stock?productId` równolegle + drzewo + stawki VAT | ORM `find` + zapytania po id | [KOD] kilka zapytań po PK | stałe | stałe | OK |
| Edycja produktu – zapis (≤100 wariantów, ≤10 zdjęć, ≤10 kategorii) | 1× PUT | `Catalog/Infrastructure/Persistence/Doctrine/DoctrineProductRepository.php:43-140` | [KOD] ORM: UPDATE tylko zmienionych wariantów, jedna transakcja; zdarzenia przez outbox | O(wariantów) | stałe | OK |
| Kategorie z licznikami | 1× `GET /categories?withCounts=true` | `Catalog/Infrastructure/Persistence/Doctrine/DbalCategoryProductCounts.php:43` | 23 ms (180 kat. × licznik ≤1001) | UNION ALL na indeksach kategorii | liniowo z liczbą kategorii, nie produktów | OK |
| Kategorie – przeniesienie/zmiana nazwy (skutek uboczny) | 1× POST/PUT | `Catalog/Application/Command/ReindexCategoryBatchHandler.php:41-60`, `Catalog/Application/Search/ProductIndexing.php:43` | [KOD] asynchronicznie, partie po 500, ale **reindeks pojedynczo** (odczyt + oferty + 1 żądanie ES na produkt) | N × (DB + HTTP ES) | korzeń przy 10 mln ≈ 1,4 mln pojedynczych zapisów ES [SZACUNEK] | Średnia |
| Zamówienia – lista, każdy status, sort data/kwota, daleka strona | 1× GET; wyszukiwanie wysyłane przyciskiem (bez debounce, bez wyścigów) | `Ordering/Infrastructure/Persistence/Doctrine/DbalBackofficeOrderReader.php:78` | 0,05–1,9 ms; total 1,6–2,6 ms | indeksy `(status, placed_at DESC, id DESC)`, `(total_gross,id)` | stałe | OK |
| Zamówienia – fragment (trigram) „kowal”, „ann”, „r-1000”, „ryszard”+status, zakres dat, e‑mail, numer | jw. | :40 | 0,05–27 ms; total 7–43 ms | GIN wyrażeniowy albo walk po `placed_at` | stałe; total ≤ ~100 ms | OK |
| Zamówienia – zwroty do wykonania / przeterminowane | jw. + dashboard | :35, :36 | 0,6–1,2 ms | indeks częściowy | stałe | OK |
| Szczegóły zamówienia, zmiana statusu | 1× GET; 1× POST | agregat po PK | [KOD] | stałe | stałe | OK |
| Klienci – lista, 4 sortowania, fragment, total | 1× GET | `Ordering/Infrastructure/Persistence/Doctrine/DbalCustomerReader.php:66`, :32 | 0,1–3 ms; total 18 ms | tabela podsumowań + indeksy sortów + trigramy | stałe | OK |
| Karta klienta (adresy, zamówienia klienta) | 2× GET | :113; `DbalBackofficeOrderReader.php:44` | 0,07–1,5 ms | indeks częściowy `(customer_id, placed_at DESC, id DESC)` | stałe | OK |
| Kody rabatowe – lista | 1× GET (debounce 300 ms) | `Pricing/Infrastructure/Persistence/Doctrine/DbalDiscountCodeReader.php:43` | **176 ms zimno / 15,6 ms ciepło** (17 kodów, 73 tys. użyć) | na wiersz `count(*)` po indeksie częściowym aktywnych użyć, bez limitu | liniowo z użyciami: ~365 tys. użyć przy 5 mln zam. → ~80 ms ciepło / ~1 s zimno [SZACUNEK] | Średnia |
| Promocje – lista, formularz (≤1000 wariantów) | 1× GET; PUT | `DbalPromotionReader.php:40`; `PlanPromotionHandler.php` | [KOD] walidacja wariantów zbiorczo (`findMany`), globalna blokada doradcza zapisu promocji | stałe | stałe | OK |
| Dostawy | 1× GET | 2 wiersze | – | – | stałe | OK |
| Dashboard | `GET /dashboard` + `GET /orders?perPage=5` przy montowaniu i **co 30 s** (pauza przy ukrytej karcie) | `Backoffice/Application/Query/GetDashboardHandler.php:34` | sprzedaż 60 dni z `ordering_sales_hour` 3,6 ms; kolejki 0,6–1,7 ms; bez zdjęć 1,9 ms; ostatnie zamówienia 0,08 ms; liczba produktów z `reltuples` | tabela zagregowana (16 shardów/godz.) + liczniki ≤1000 | stałe (sprzedaż: 30 dni × 24 × 16 = 11,5 tys. wierszy niezależnie od 5 czy 50 mln zamówień) | OK |
| Stan systemu | `GET /dashboard` co 30 s z sondami 8 usług, licznikiem ES i kolejkami RabbitMQ | `GetDashboardHandler.php:46-52` | [KOD] równoległe sondy HTTP | stałe | stałe | Niska |
| Bundle panelu | – | `apps/admin/src/router/index.ts:14-31` | [KOD] wszystkie widoki importowane statycznie (brak `() => import()`), jeden chunk | – | rośnie z liczbą ekranów, nie z danymi | Niska |
| Operacje masowe | brak w `openapi-admin.json` | – | – | – | – | n/d |

## Znaleziska

### A1. Filtr kategorii listy produktów skanuje cały katalog dla małych kategorii — Wysoka

- Dowód: [POMIAR] `results/admin/products-cat.out` (C3), `products-cat2.out`, `worst-warm.out` (W3). Kategoria
  „Biwak” z poddrzewem 5 kategorii (20 produktów: 16 z kategorią główną w poddrzewie, 4 przez kategorie dodatkowe): `Index Scan using catalog_product_name_id_idx`,
  `Filter: (ANY (primary_category_id = (hashed SubPlan 1)) OR (ANY (id = (hashed SubPlan 3))))`, `Rows Removed by
  Filter: 1 000 171`, 1 015 477 bloków, 2,3 s (zimno, bez obciążenia) do 9,4 s (pod równoległym obciążeniem innych
  audytorów). Sort `-updatedAt`: 602 ms; total (Seq Scan całej tabeli, bo 10 001 wierszy nigdy nie zbiera się):
  310 ms. [KOD] `DbalBackofficeProductSource.php:35-42`.
- Przyczyna źródłowa: błąd kodu zapytania (kształt warunku). Planista nie umie oszacować selektywności
  `IN (SELECT … path LIKE …) OR EXISTS (…)` i zawsze zakłada ~757 tys. wierszy (75% tabeli), więc niezależnie od
  rozmiaru kategorii wybiera przejście indeksu sortowania z filtrem. Dla dużej kategorii to dobry plan (0,2 ms), dla
  małej/pustej – pełny odczyt sterty.
- Skala: liniowa z liczbą produktów; 10 mln → 23–90 s [SZACUNEK]. Współbieżność: każde takie żądanie czyta całą
  tabelę (1,5 GB na medium, ~15 GB przy 10 mln), wypychając gorące dane sklepu z bufora.
- Dotyczy też ekranów Ceny i Stany (filtr kategorii w `/prices`, `/stock`).
- Rekomendacja (S–M): (1) rozwiązać poddrzewo na identyfikatory w PHP (drzewo kategorii jest małe i już ładowane),
  (2) najpierw policzyć ograniczony total (zapytanie UNION ALL po `primary_category_id = ANY(:ids)` i
  `catalog_product_category.category_id = ANY(:ids)`, jak w `DbalCategoryProductCounts` – 23 ms dla wszystkich 180
  kategorii), (3) jeśli trafień ≤ ~10 000 – strona przez `p.id IN (UNION ALL …) ORDER BY name LIMIT` (zmierzone
  4,6 ms dla małej kategorii, `category-alt.out` ALT1), w przeciwnym razie obecne przejście indeksu (trafienia gęste,
  szybkie). Sama wersja `IN (UNION ALL)` bez tego przełącznika jest zła dla dużych kategorii (liść 21 tys.: 1,1 s,
  korzeń: 4,6 s – ALT2/ALT3), dlatego wybór planu po zliczeniu jest konieczny. Handler już liczy total na 1. stronie
  (`SearchBackofficeProductsHandler.php:76`), więc wystarczy policzyć go przed stroną.
- Efekt: mała kategoria z 2–9 s do <10 ms, stały koszt przy 10 mln.
- Potwierdzenie: `EXPLAIN (ANALYZE, BUFFERS)` z `results/admin/products-cat.sql` (C3) i `category-alt.sql` (ALT1–3).

### A2. Wyszukiwanie po nazwie (`ILIKE '%…%'` + `ORDER BY name LIMIT`) ma przypadki pełnego skanu — Wysoka

- Dowód: [POMIAR] `products-list.out` P5, P7; `products-list2.out` P7, P13, P14; `worst-warm.out`.
  - „termos” (12 920 trafień, wszystkie na „T…”): planista szacuje 20 396 trafień, wybiera przejście `(name, id)` i
    odrzuca 918 460 wierszy przed znalezieniem 26 → 2,35 s zimno / 1,07 s ciepło. Ta sama fraza z sortem
    `-updatedAt`: 3,4 ms; „zupa” (szacunek 101) idzie przez GIN: 39 ms. Wybór planu zależy od statystyk, nie od
    danych zapytania – drobna zmiana częstości przestawia plan.
  - Frazy 2‑znakowe (API przyjmuje od 2 znaków, `BackofficeProductsQuery.php:34`): z `'%qx%'` pg_trgm nie wyciąga
    trigramu ograniczającego (`show_trgm('qx')` daje tylko trigramy z dopełnieniem krawędzi słowa, które w `LIKE '%…%'`
    nie zawężają), GIN zwraca 617 tys. kandydatów, recheck 1 mln wierszy: 0,7–1,45 s; z `-updatedAt` 1,1 s; total
    204 ms. Komentarz w kodzie (`DbalBackofficeProductSource.php:21-23`) zakłada skan indeksu `(name, id)` – w
    praktyce planista wybiera też bitmapę GIN; oba są pełnym skanem.
- Przyczyna: architektura zapytania (podciąg + sortowanie alfabetyczne + LIMIT to kombinacja, której żaden pojedynczy
  indeks nie obsługuje dla wszystkich fraz) i dopuszczenie 2 znaków.
- Skala: liniowa; 10 mln → 10–25 s dla frazy typu „termos”, 7–15 s dla 2 znaków [SZACUNEK].
- Dotyczy: Produkty, Ceny, Stany, wybór wariantów promocji (to samo zapytanie).
- Rekomendacja (S): wybór planu po ograniczonym totalu, który handler i tak liczy: jeśli trafień ≤ 10 000 –
  `WITH m AS MATERIALIZED (… WHERE name ILIKE … LIMIT 10001) SELECT … ORDER BY name, id LIMIT 26` (zmierzone 117 ms dla
  „termos”, ALT4; kursor dalszych stron to warunek wierszowy w tym samym CTE); w przeciwnym razie obecne przejście
  indeksu (trafienia gęste – zmierzone 0,8–1,6 ms dla „pro”, „ka”). Dla 2 znaków: albo minimum 3 znaki (zmiana
  kontraktu, decyzja właściciela), albo dla 2 znaków tylko dopasowanie prefiksu słowa. Bez nowych usług. Alternatywa
  ES (indeks już jest) wymaga dodania szkiców do indeksu – większy koszt (M–L), nieuzasadniony, jeśli powyższe wystarczy.
- Potwierdzenie: `products-list.sql`, `products-list2.sql`, `category-alt.sql` ALT4; dodatkowo zestaw 50 losowych
  słów z nazw (marki, modele) z pomiarem maksimum.

### A3. Brak limitu czasu zapytań i brak anulowania żądań panelu wzmacnia A1/A2 — Wysoka (wzmacniacz)

- [KOD] W `apps/api/config`, `apps/api/Dockerfile`, `infra/docker-compose.yml` i chartcie `infra/k8s/charts/shop` nie
  ma `statement_timeout`, `max_execution_time` ani limitu czasu żądania (grep: tylko limity klienta ES). Rola bazy nie
  ma ustawień (`pg_roles.rolconfig` puste). Panel i sklep obsługuje to samo wdrożenie `api` (`templates/api.yaml`).
- [KOD] `apps/admin/src/composables/useCursorPager.ts`: późniejsza odpowiedź zastępuje wcześniejszą (licznik
  `latest`), ale żądanie nie jest przerywane (brak `AbortController` w całym panelu). `useNameSearch.ts:61` – debounce
  300 ms: pisanie „ter…termos” z pauzami wysyła kilka żądań, każde po kilka sekund.
- [HIPOTEZA] Nawet przerwane po stronie przeglądarki żądanie biegnie w PHP do końca (PDO blokuje się na zapytaniu,
  przerwanie połączenia wykrywa dopiero zapis odpowiedzi), więc trzyma wątek FrankenPHP i połączenie z Postgresem.
  Do potwierdzenia: na medium wysłać 5 równoległych `GET /api/v1/admin/products?category=<mała>` i jednocześnie mierzyć
  czas odpowiedzi strony kategorii sklepu oraz `pg_stat_activity` (czy zapytania żyją po zamknięciu klienta).
- Rekomendacja (S): `SET statement_timeout` dla połączeń API (np. 5 s, w DSN `options=-c statement_timeout=…` albo
  `ALTER ROLE`), z mapowaniem błędu 57014 na 503/400 z polskim komunikatem w panelu; `AbortController` w
  `useCursorPager` (anulowanie poprzedniego żądania listy). Nie leczy przyczyny – A1/A2 trzeba poprawić.

### A4. Lista kodów rabatowych liczy aktywne użycia bez limitu — Średnia

- [POMIAR] `discount.out`: na wiersz `count(*)` po `pricing_discount_code_usage_active_idx`, średnio 4 118 wierszy
  na kod, 176 ms zimno / 15,6 ms ciepło dla 17 kodów. [KOD] `DbalDiscountCodeReader.php:43`.
- Skala: liniowa z liczbą użyć kodów na stronie (do 25 kodów). Przy 5 mln zamówień i obecnym udziale 7,3% zamówień z
  kodem: ~365 tys. użyć → ~80 ms ciepło / ~1 s zimno [SZACUNEK]; popularny kod (np. kampania) – większość z tego na
  jednym wierszu.
- [KOD] To samo `ACTIVE_USES` (`DoctrineDiscountCodeUsageRepository.php:24`) jest na ścieżce realizacji kodu w
  zamówieniu – do sprawdzenia przez audytora checkoutu (licznik pod blokadą przy każdym użyciu popularnego kodu).
- Rekomendacja (S): licznik `active_uses` przechowywany w `pricing_discount_code` (aktualizowany w tej samej
  transakcji co wstawienie/zwolnienie użycia, które i tak blokuje kod) albo w panelu liczba ograniczona do
  `usage_limit + 1`. Potwierdzenie: `discount.sql` na danych z większą liczbą użyć (profil server/full).

### A5. Reindeks poddrzewa po zmianie kategorii pojedynczymi zapisami — Średnia

- [KOD] `ReindexCategoryBatchHandler.php:41-60`: partie po 500 produktów, ale każdy produkt osobno:
  `ProductIndexing::reindex` → odczyt snapshotu z DB, `offers->of([id])` (ceny/stany), jedno żądanie zapisu do ES
  (`ProductIndexing.php:43-55`). Na końcu `invalidateCatalog()` – unieważnienie cache katalogu sklepu.
- [SZACUNEK] Przeniesienie/zmiana nazwy kategorii głównej przy 10 mln produktów (~1/7 katalogu) to ~1,4 mln
  pojedynczych cykli DB+HTTP; przy 3–5 ms na cykl 1–2 h pracy workera, w czasie której te same produkty mają w sklepie
  stare okruszki/ścieżki, a potem pełne unieważnienie cache.
- Rekomendacja (M): w partii jeden odczyt snapshotów (`find` dla listy id), jedno `offers->of(ids)` i jeden `_bulk` do
  ES (porty zbiorcze już są – `BulkResult` w `Catalog/Application/Search`). Do potwierdzenia: czas jednej partii na
  medium (log workera dla `ReindexCategoryBatch`) – tę część powinien zweryfikować audytor ES/workera.

### A6. Panel bez lazy-loadingu tras — Niska

- [KOD] `apps/admin/src/router/index.ts:14-31`: 18 widoków importowanych statycznie → jeden chunk JS ładowany przy
  wejściu. Nie zależy od danych; panel dla pracowników. Rekomendacja (S): `component: () => import(...)`. Rozmiaru
  bundla nie mierzyłem (zakaz `npm` na hoście i używania kontenerów perf25 poza bazą) – [HIPOTEZA], pomiar:
  `make -C apps/admin shell` → `npx vite build` i rozmiary w `dist/assets`.

### A7. Dashboard odpytuje co 30 s – w porządku — Niska

- [KOD] `useDashboard.ts:71`: `GET /dashboard` i `GET /orders?perPage=5` co 30 s na otwartą, widoczną kartę; „Stan
  systemu” dodatkowo sondy 8 usług i liczba dokumentów ES. [POMIAR] część SQL: łącznie < 10 ms. Przy kilkunastu
  pracownikach to pomijalne. Nie ruszać.

## Co jest zrobione dobrze (nie ruszać)

- Keyset we wszystkich listach (produkty, zamówienia, klienci, kody, promocje, stany) – daleka strona 0,14–0,23 ms.
- `BoundedCounter` (liczniki do 10 000 / 1 000) zamiast `count(*)` – dobry wzorzec; total tylko na pierwszej stronie.
- Indeksy częściowe z warunkiem wstawianym jako literał (szkice, bez zdjęć, poziomy stanów, zwroty) – 0,04–2 ms.
- `ordering_customer` (podsumowania klientów) i `ordering_sales_hour` (16 shardów/godz.) – dashboard i klienci
  nie skanują zamówień; koszt sprzedaży nie rośnie z liczbą zamówień (5 czy 50 mln – te same ~11,5 tys. wierszy).
- Liczba produktów na dashboardzie z `reltuples` zamiast `count(*)`.
- Zamówienia: wyrażeniowy indeks trigramowy (numer, e‑mail, nazwisko), wyszukiwanie po zatwierdzeniu (bez debounce),
  walidacja kombinacji filtrów, które nie mają indeksu (`BackofficeOrderListQuery.php:156-173`) – najgorszy zmierzony
  przypadek 43 ms.
- Podsumowanie strony produktów jednym zapytaniem po id (1 ms), walidacja wariantów promocji zbiorczo.
- Brak operacji masowych w panelu – nie ma tu ryzyka dużych transakcji.

## Co można uprościć

- Ceny, Stany i wybór wariantów promocji korzystają z jednego zapytania katalogu – poprawka A1/A2 w jednym miejscu
  (`DbalBackofficeProductSource`) naprawia cztery ekrany; nie trzeba osobnych indeksów per ekran.
- Komentarz `DbalBackofficeProductSource.php:21-23` opisuje plan, którego baza nie wybiera (2 znaki idą przez GIN) –
  po poprawce opis planu powinien wynikać z testu planu, nie z założenia.

## Pomiary do wykonania przez sesję prowadzącą (najważniejsze najpierw)

1. A1: na medium `GET /api/v1/admin/products?category=<id kategorii „Biwak”>` (i `/prices`, `/stock` z tym filtrem) –
   czas odpowiedzi; równolegle 5 takich żądań i czas strony kategorii sklepu (potwierdza wpływ na sklep i A3).
2. A2: lista 50 słów z nazw produktów (marki, typy, modele) jako `q` – rozkład czasów; spodziewane kilka przypadków > 1 s.
3. A3: po przerwaniu klienta (`curl --max-time 1`) sprawdzić w `pg_stat_activity`, czy zapytanie dalej trwa.
4. A1/A2 rozwiązanie: prototyp „total → wybór planu” na zapytaniach z `category-alt.sql` dla 20 kategorii różnej
   wielkości i 50 fraz – maksimum < 150 ms.
5. A5: czas partii `ReindexCategoryBatch` (500 produktów) w logach workera na medium.
6. A4: `discount.sql` po wygenerowaniu profilu z większą liczbą zamówień z kodami.
