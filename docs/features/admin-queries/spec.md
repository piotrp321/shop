# PERF-7. Zapytania panelu i mapy strony

Epik programu wydajności ([`docs/features/performance-program/plan.md`](../performance-program/plan.md), PERF-7).
Cel: listy, wyszukiwanie i filtry panelu oraz granice mapy strony z kosztem, który nie rośnie z wielkością katalogu,
przy 10 mln produktów, 1 mln klientów i 5 mln zamówień (profil `server`). Rodzaj: optymalizacja zapytań i indeksów
PostgreSQL. Zakres funkcji bez zmian, bez nowych usług, bez cache'u nad wolnym zapytaniem.

Materiał dowodowy (rewizja `2b4258c7`, profil `medium`: 1 mln produktów, 1 mln zamówień, 98,6 tys. klientów):
[`findings/admin.md`](../../reports/performance-audit-2026-11-25/findings/admin.md) (A1–A7),
[`findings/postgres.md`](../../reports/performance-audit-2026-11-25/findings/postgres.md) (K2, K4–K6, tabela
zapytań #9–#34), plany `EXPLAIN (ANALYZE, BUFFERS)` w `results/admin/` i `results/pg/` tego katalogu. Spec nie
powtarza tych pomiarów, tylko je wskazuje.

## Stan obecny

| Obszar | Wynik audytu na `medium` | Przy 10 mln | Przyczyna |
|---|---|---|---|
| zamówienia, klienci, dashboard, historie cen i stanów, drzewo kategorii z licznikami | 0,05–45 ms, plany stałe | stałe | keyset, `BoundedCounter`, indeksy częściowe, modele odczytu (`ordering_customer`, `ordering_sales_hour`) |
| produkty: mała kategoria (#14–#16, `products-cat.out`) | 2,2–9,4 s, 1 mln buforów | 20–90 s [SZACUNEK] | warunek `IN (…) OR EXISTS (…)` bez oszacowania selektywności; planista zawsze przechodzi indeks sortowania z filtrem |
| produkty: fraza „termos” z sortem po nazwie (`products-list.out` P5) | 1,1–2,4 s | 10–25 s [SZACUNEK] | trafienia skupione na „T”, przejście `(name, id)` odrzuca 918 tys. wierszy |
| produkty: fraza 2-znakowa „qx”, fraza z samej interpunkcji `#!#` (#10–#12) | 0,3–1,5 s, licznik do 0,85 s | 4–15 s [SZACUNEK] | pg_trgm nie wyciąga trygramu: GIN zwraca 617 tys. kandydatów albo pełne przejście |
| zamówienia i klienci: fraza `#!#` (#23, #24, #28) | 0,33–0,41 s i 40–52 ms | ok. 2 s przy 5 mln zamówień [SZACUNEK] | jak wyżej, minimum 3 znaki nie gwarantuje trygramu |
| kody rabatowe: aktywne użycia na wiersz (A4) | 15,6 ms ciepło, 176 ms zimno | ok. 80 ms ciepło, ok. 1 s zimno [SZACUNEK] | `count(*)` bez limitu na wiersz strony |
| granice porcji mapy strony (#33, K6) | 0,55–1,3 s | 6–13 s [SZACUNEK] | `row_number()` po całym kluczu głównym; wynik trzyma pula `cache.sitemap` przez godzinę |
| front panelu (A3) | `useCursorPager` ignoruje spóźnione odpowiedzi, ale ich nie przerywa | – | brak `AbortController` w panelu |

Cztery ekrany (Produkty, Ceny, Stany, wybór wariantów promocji) korzystają z jednego zapytania
`DbalBackofficeProductSource::page` (Ceny i Stany przez `QueryBusProductDirectory` w `Pricing` i `Inventory`), więc
poprawka w jednym miejscu obejmuje wszystkie.

### Ustalenie tej specyfikacji: próg z audytu nie wystarcza przy 10 mln

Audyt proponuje wybór planu po ograniczonym liczniku: do 10 000 trafień zbiór, potem sortowanie; powyżej obecne
przejście indeksu sortowania, „bo trafienia są gęste”. To założenie nie działa, gdy trafienia są skorelowane
z porządkiem sortowania:

- W danych demo nazwa to `<typ> <marka> <model>`, a typ wyznacza liść kategorii głównej
  (`DemoData/Catalog/ProductGenerator.php:89`, `:120`). W prawdziwym katalogu jest podobnie („Termos …” w „Termosach”).
- Przy 10 mln produktów i obecnym drzewie liść ma średnio kilkadziesiąt tysięcy produktów, a fraza „termos” ok. 129
  tys. trafień [SZACUNEK: 10 razy wynik z `medium`]. Oba przypadki są ponad progiem, więc trafiają do przejścia
  indeksu `(name, id)`, które dla typu z końca alfabetu czyta większość tabeli, zanim znajdzie pierwszy wiersz.
- Na `medium` liść 21 tys. kosztował 3,8–9,7 ms tylko dlatego, że jego typ jest na początku alfabetu [HIPOTEZA:
  duży liść z typem na końcu alfabetu jest wolny już na `medium`; sprawdza ją zadanie 1].
- Kategorie dodatkowe (20% produktów w danych demo, `ADDITIONAL_CATEGORY_SHARE`) nie mają w
  `catalog_product_category` klucza sortowania. Dla działu przy 10 mln to ok. 280 tys. członkostw [SZACUNEK], które
  przy obecnym modelu trzeba posortować przy każdym żądaniu.

Dlatego rozwiązanie musi mieć koszt ograniczony bez względu na rozkład nazw, a nie tylko dla przypadków z audytu.

## Wymagania

| ID | Wymaganie | Miara |
|---|---|---|
| P1 | W7 dla panelu: p50 100 ms, p95 500 ms, maksimum 2000 ms | mieszanka panelu (niżej) na `server`; lokalnie `medium` do porównań przed i po |
| P2 | Każdy przypadek macierzy (niżej) w czasie maksimum W7 na `server`; przypadki z audytu (#10–#16, #23, #24, #28, „termos”) obowiązkowo | maksimum z 20 powtórzeń zimnych i ciepłych |
| P3 | Koszt przypadków macierzy nie rośnie z liczbą produktów, poza klasami o koszcie ograniczonym jawnie (tabela strategii) | liczba buforów z `EXPLAIN (ANALYZE, BUFFERS)` dla tych samych przypadków na dwóch wolumenach (`small` i `medium`) różni się nie więcej niż wynika z klasy |
| P4 | Równoległa praca panelu nie zmienia W1 i W5 | W1 i W5 przy obciążeniu nominalnym bez panelu i z 8 równoległymi wątkami najgorszych przypadków panelu: różnica median w granicach rozrzutu trzech przebiegów |
| P5 | Granice mapy strony mają koszt stały względem liczby produktów | zimne `GET /api/v1/sitemap/product-chunks`: liczba buforów stała między `small` i `medium`, czas na `server` do 100 ms; każda porcja ma najwyżej `app.sitemap.row_limit` produktów |
| P6 | Panel przerywa żądanie listy, które zastąpiło nowsze, i żądania odmontowanego widoku | test jednostkowy pagera: sygnał poprzedniego żądania jest przerwany, przerwanie nie daje komunikatu błędu |
| P7 | Wyniki bez zmian: te same wiersze, kolejność, kursory i liczniki co przed zmianą | test równoważności z zapytaniem referencyjnym (obecny SQL) na danych z kategoriami dodatkowymi, polskimi znakami i duplikatami nazw |

Mieszanka panelu do W7 jest generowana z danych i zapisana z wynikiem: listy domyślne we wszystkich sortowaniach,
strony głębokie, frazy według częstości (słowa z nazw, marki, bigramy rzadkie i częste, brak trafień), kategorie
według rozmiaru i pozycji typu w alfabecie, kombinacje z filtrami, Ceny, Stany, zamówienia, klienci, kody rabatowe,
dashboard. Tempo panelu do W7: propozycja 5 żądań na sekundę (kilkanaście osób pracujących naraz); wartość zatwierdza
właściciel razem z W1–W20 po PERF-0.

### Macierz przypadków

Każdy przypadek dla sortów `name` i `-updatedAt`, pierwszej i dalszej strony, z licznikiem.

| Grupa | Przypadki |
|---|---|
| kategoria | pusta; 4–20 produktów (Biwak, #14); średnia ok. 1–5 tys.; duży liść z typem na początku i na końcu alfabetu; dział; kategoria, w której większość produktów ma ją jako dodatkową |
| fraza | brak trafień; rzadka; „termos” (skorelowana z sortem); częsta nieskorelowana (marka); bardzo częsta (`pro`, `ka`); 2 znaki rzadkie (`qx`) i częste; sama interpunkcja (`#!#`); polskie znaki i wielkość liter |
| kombinacje | kategoria + fraza częsta globalnie i rzadka w kategorii; szkice; bez zdjęć; szkice + kategoria; bez zdjęć + fraza |
| zamówienia i klienci | przypadki #19–#28 z audytu i fraza bez trzech kolejnych liter lub cyfr |
| inne | kody rabatowe (A4), drzewo kategorii z licznikami, dashboard |

## Rozwiązanie

### Strategia listy produktów

Jedno miejsce wyboru planu w `DbalBackofficeProductSource` (albo klasie, której używa), z tą samą semantyką wyników co
dziś. Plan wybiera liczba trafień zmierzona ograniczonym licznikiem, nie szacunek planisty.

1. **Licznik selektywnych filtrów.** Dla każdego obecnego filtra, który ma własny indeks, ograniczony licznik do progu
   T: fraza (GIN trygramów albo bigramów), kategoria (tabela członkostwa, niżej), szkice (indeks częściowy), bez zdjęć
   (indeks częściowy). Koszt każdego licznika: najwyżej T + 1 wpisów indeksu. T ustala pomiar w zadaniu 1 (zakres
   1–10 tys.); nie jest to limit wyświetlanego licznika (ten zostaje 10 000).
2. **Zbiór kandydatów** (najmniejszy filtr ma najwyżej T trafień): kandydaci z jego indeksu, pozostałe filtry i kursor
   jako warunki, sortowanie i `LIMIT`. Koszt najwyżej T wierszy. Licznik wyniku liczony z tego samego zbioru, więc
   dokładny.
3. **Gałęzie kategorii** (filtr kategorii ponad T): dla każdej kategorii poddrzewa przejście indeksu
   `(category_id, klucz sortowania, product_id)` tabeli członkostwa od kursora, scalenie gałęzi i usunięcie
   powtórzeń (produkt w kilku kategoriach poddrzewa). Koszt rzędu liczba kategorii poddrzewa × rozmiar strony,
   niezależny od nazw i od wielkości katalogu. Pozostałe filtry sprawdzane na wierszach tabeli członkostwa (ma nazwę,
   status i znacznik zdjęć), bez odczytu tabeli produktów.
4. **Przejście okienkowe** (tylko fraza ponad T, bez kategorii): przejście indeksu sortowania oknami po W wierszy
   (każde okno to osobne, ograniczone zapytanie od ostatniego przeczytanego klucza) do budżetu B wierszy. Okno jest
   prefiksem porządku sortowania, więc znalezione trafienia są dokładnie pierwszymi wynikami. Gdy budżet się wyczerpie,
   a strona nie jest pełna (trafienia skupione dalej w alfabecie, np. „termos”), wynik daje zbiór kandydatów z GIN po
   wszystkich trafieniach frazy. Koszt: min(przejście nieskorelowane, B + liczba trafień frazy).
5. **Bez filtrów selektywnych:** jak dziś (przejście indeksu sortowania, 0,04–0,25 ms).

| Klasa | Ograniczenie kosztu | Przykład z macierzy |
|---|---|---|
| zbiór kandydatów | T wierszy | mała kategoria, „termos” na `medium`, szkice, bez zdjęć |
| gałęzie kategorii | kategorie poddrzewa × strona | duży liść, dział, kategorie dodatkowe |
| przejście okienkowe | B wierszy indeksu | częsta fraza nieskorelowana |
| przejście okienkowe + kandydaci | B + trafienia frazy | „termos” przy 10 mln (R1) |
| gałęzie kategorii z rzadkim przecięciem | rozmiar poddrzewa w wąskiej tabeli | dział + fraza rzadka w dziale (R2) |

Licznik pierwszej strony: z klasy kandydatów dokładny; w gałęziach kategorii liczony po kluczu głównym tabeli
członkostwa `(category_id, product_id)` scaleniem gałęzi z usunięciem powtórzeń i limitem 10 001; dla frazy przez GIN
z limitem 10 001. Plan zapytania licznika wynika z kształtu zapytania i testów planu, nie z ustawień `enable_*`.

Kursor: format bez zmian dla klienta (nieprzezroczysty). Wybór planu na dalszych stronach jest powtarzany albo
zapisany w kursorze; decyduje implementacja po pomiarze kosztu licznika.

### Tabela członkostwa kategorii (D1)

`catalog_product_category_listing`: jeden wiersz na produkt i każdą jego kategorię (główną i dodatkowe):
`category_id`, `product_id`, `name`, `updated_at`, `status`, `without_images`; klucz główny
`(category_id, product_id)`, indeksy `(category_id, name, product_id)` i `(category_id, updated_at, product_id)`,
klucze obce z `ON DELETE CASCADE`. To model odczytu panelu w module `Catalog`, utrzymywany w tej samej transakcji
co zapis produktu, więc zawsze aktualny:

- `DoctrineProductRepository::save` po zapisie produktu zastępuje wiersze tego produktu (najwyżej 11);
- każde inne miejsce, które zmienia `catalog_product` albo `catalog_product_category` z pominięciem repozytorium
  (generator `DemoData` przez `COPY`, `DemoData/Photo/ProductPhotos.php` dla zdjęć, migracje), uzupełnia tabelę
  zbiorczo w tym samym kroku; zadanie wyszukuje takie miejsca przez `git grep` nazw obu tabel;
- przeniesienie albo zmiana nazwy kategorii nie zmienia tabeli (wiersze trzymają identyfikator kategorii, poddrzewo
  liczymy ze ścieżek przy zapytaniu);
- migracja tworzy tabelę i wypełnia ją jednym `INSERT … SELECT`.

Rozmiar przy 10 mln produktów: ok. 12 mln wierszy, ok. 1,2 GB tabeli i ok. 1,5 GB indeksów [SZACUNEK]; pomiar
w zadaniu 3. Test zgodności porównuje tabelę z danymi wyliczonymi z `catalog_product` i `catalog_product_category`
po każdym rodzaju zapisu.

Odrzucone warianty:

- **Same indeksy `(primary_category_id, name, id)` i `(primary_category_id, updated_at, id)`** bez zmiany modelu:
  kategorie dodatkowe nie mają klucza sortowania, więc dział przy 10 mln sortuje ok. 280 tys. członkostw przy każdym
  żądaniu [SZACUNEK].
- **Klucze sortowania w `catalog_product_category`** (wariant do wyboru w D1): ten sam koszt odczytu, ale zmienia
  tabelę asocjacji agregatu (dziś `ManyToMany` Doctrine) i wymaga dodatkowo dwóch indeksów na `catalog_product`.
- **Elasticsearch dla listy panelu:** indeks nie ma szkiców, odświeża się asynchronicznie (lista po zapisie
  pokazałaby stan sprzed zapisu), a kod wyszukiwarki zmienia w tym czasie PERF-2.

### Frazy krótkie i bez trygramu

- **Produkty (2 znaki i więcej, bez zmiany minimum):** indeks GIN bigramów na wyrażeniu
  `catalog_name_bigrams(name)` (funkcja SQL `IMMUTABLE`, małe litery, zbiór dwuznaków nazwy). Fraza, z której pg_trgm
  nie wyciąga trygramu, szuka kandydatów przez `catalog_name_bigrams(name) @> <bigramy frazy>`; `name ILIKE` zostaje
  jako warunek dokładny. Test równoważności z samym `ILIKE` obejmuje polskie znaki i wielkość liter (kandydaci muszą
  być nadzbiorem). Rozmiar indeksu przy 10 mln: ok. 2 GB [SZACUNEK, jak trygramy: 214 MB na 1 mln]; pomiar w zadaniu 2.
  Fraza 1-znakowa zostaje niedozwolona, jak dziś.
- **Zamówienia i klienci (D2):** fragment (fraza zamówień, która nie jest numerem `R-…` ani pełnym adresem e-mail, bo te
  wyszukiwania są dokładne i zostają bez zmian, `OrderSearchTerm`) musi zawierać co najmniej trzy kolejne litery lub
  cyfry. Taki ciąg zawsze daje
  trygram użyteczny w `LIKE '%…%'`, więc wyszukiwanie zawsze idzie przez istniejący GIN. Fraza bez takiego ciągu daje
  400 z polem `q` i komunikatem „Wpisz co najmniej 3 litery lub cyfry obok siebie.”; panel pokazuje tę samą
  wskazówkę przed wysłaniem. Bez nowych indeksów na tabelach zamówień i klientów, które są często aktualizowane.

### Granice mapy strony (D3)

Granice z histogramu statystyk Postgresa zamiast `row_number()` po całym kluczu:

- `ALTER TABLE catalog_product ALTER COLUMN id SET STATISTICS <n>` tak, żeby histogram miał co najmniej tyle przedziałów,
  ile porcji potrzeba przy docelowej skali (10 mln / 10 000 = 1000);
- granice to co k-ta wartość `pg_stats.histogram_bounds` dla `catalog_product.id`, k dobrane z `reltuples` i
  `app.sitemap.chunk_size`; koszt jednego odczytu katalogu systemowego;
- produkty z identyfikatorem większym niż ostatnia granica histogramu (UUID v7 rośnie w czasie, więc nowe produkty od
  ostatniego `ANALYZE`) dzieli zapytanie keyset od ostatniej granicy: `WHERE id > :prev ORDER BY id OFFSET :n LIMIT 1`,
  koszt proporcjonalny do produktów dodanych od `ANALYZE`;
- bez statystyk (świeża baza, testy) cały zakres dzieli to samo zapytanie keyset.

Porcja ma około N produktów zamiast dokładnie N (błąd próbkowania `ANALYZE`); limit `row_limit` (50 000) daje
pięciokrotny zapas. Zmienia to decyzję D1 w `docs/features/seo/spec.md` („dokładnie N kolejnych `id`” na „około N”);
kontrakt API (`product-chunks`, `products`) bez zmian. Pula `cache.sitemap` zostaje jako ochrona przed powtórzeniami,
nie jako warunek wydajności.

### Front panelu

`useCursorPager` tworzy `AbortController` dla każdego żądania i przerywa poprzednie przy nowym zapytaniu, zmianie
strony i odmontowaniu; `PageRequest` przekazuje `signal`, każde `fetchPage` przekazuje go do klienta `openapi-fetch`.
Przerwane żądanie nie zmienia stanu i nie pokazuje błędu. Debounce 300 ms zostaje. Przerwanie po stronie przeglądarki
nie zatrzymuje zapytania w PHP [HIPOTEZA A3; zadanie 1 sprawdza ją w `pg_stat_activity`], więc ochroną sklepu jest
ograniczony koszt zapytań (P2, P3) i `statement_timeout` z PERF-1, a anulowanie zmniejsza liczbę żądań w toku
i wyścigi w widoku.

### Wpływ na sklep

Panel i sklep obsługuje to samo wdrożenie `api` i ta sama baza. Po zmianie żadne zapytanie panelu z macierzy nie czyta
całej tabeli, więc nie wypycha gorących danych sklepu z pamięci i nie trzyma wątku dłużej niż maksimum W7. P4 mierzy to
wprost. Operacje masowe panelu (zmiana kategorii z reindeksem, masowe zmiany cen) należą do PERF-6, a ich wpływ na W1
mierzy W18.

## Decyzje właściciela

| ID | Pytanie | Rekomendacja | Alternatywa |
|---|---|---|---|
| D1 | model odczytu kategorii dla listy produktów | osobna tabela członkostwa `catalog_product_category_listing` | klucze sortowania w `catalog_product_category` i dwa indeksy na `catalog_product` |
| D2 | frazy zamówień i klientów bez trzech kolejnych liter lub cyfr | 400 z polskim komunikatem (wyszukiwanie po samej interpunkcji nie wskazuje zamówienia) | indeksy bigramów na `ordering_order` i `ordering_customer` (więcej zapisu przy każdym zamówieniu i płatności) |
| D3 | granice porcji mapy strony | histogram statystyk, porcje około N | keyset od granicy do granicy: dokładnie N, koszt liniowy (ok. 6 s przy 10 mln raz na godzinę [SZACUNEK]), każde zapytanie krótkie |

## Wymagane przed startem

- Raport PERF-0 i zatwierdzone W1–W20 (w tym tempo panelu do W7).
- Decyzje D1–D3.
- `statement_timeout` i obsługę przerwanego zapytania ustawia PERF-1; PERF-7 ich nie zmienia. Jeśli PERF-1 nie jest
  jeszcze na `main`, pomiary PERF-7 przed i po idą na tej samej konfiguracji Postgresa.
- Lokalne pomiary PERF-7 (stos `medium` i `small`) nie nakładają się na ciężkie pomiary PERF-2.

## Ryzyka

| ID | Ryzyko | Sprawdzenie | Gdy się potwierdzi |
|---|---|---|---|
| R1 | Fraza bardzo częsta i skorelowana z sortem („termos” przy 10 mln: ok. 129 tys. trafień [SZACUNEK]) kosztuje B + liczba trafień; na `medium` zbiór 12,9 tys. trwał 117 ms (ALT4), liniowo ok. 1,2 s przy 10 mln | przypadek w macierzy na `server` | decyzja właściciela: wąska tabela nazw do wyszukiwania albo wyjątek W7 dla tej klasy z liczbami |
| R2 | Kategoria i filtr, które osobno mają ponad T trafień, a razem mało: koszt rośnie z rozmiarem poddrzewa (wąska tabela, bez tabeli produktów) | przypadek „dział + fraza rzadka w dziale” w macierzy | przejście okienkowe także w gałęziach kategorii |
| R3 | Poddrzewo z tysiącami kategorii: koszt kategorie × strona | pomiar z liczbą kategorii z profilu `server` | scalenie gałęzi przez `UNION ALL` z `Merge Append` zamiast `LATERAL` |
| R4 | Rozmiar nowych indeksów (ok. 3,5 GB przy 10 mln [SZACUNEK]) wobec pamięci węzła i W20 | rozmiary po `generate` i czas generatora | decyzja o pamięci w PERF-1 |

## Poza zakresem

- Licznik aktywnych użyć kodu rabatowego (A4): ten sam licznik jest na ścieżce zamówienia pod blokadą kodu, więc
  przechowywany licznik należy do PERF-8. PERF-7 mierzy listę kodów w macierzy i przekazuje wynik.
- Reindeks poddrzewa po zmianie kategorii (A5): PERF-6.
- Leniwe ładowanie tras panelu (A6): porządki, nie zależy od danych.
- `statement_timeout`, konfiguracja pamięci Postgresa: PERF-1.

## Kryteria akceptacji

1. P7: test równoważności (wiersze, kolejność, kursory, liczniki) przechodzi dla wszystkich klas strategii na danych
   testowych z kategoriami dodatkowymi, polskimi znakami i powtarzającymi się nazwami.
2. Testy planów w CI na ok. 100 tys. wierszy generowanych w teście, bez sterowania planistą: dla każdej klasy strategii
   asercja, że liczba przeczytanych wierszy jest ograniczona przez klasę (np. mała kategoria najwyżej T + strona),
   a nie przez rozmiar tabeli.
3. P3: tabela buforów macierzy na `small` i `medium` w raporcie; żaden przypadek nie rośnie ponad swoją klasę.
4. P1, P2: W7 na `medium` lokalnie przed i po (trzy przebiegi, rozrzut); na `server` na worf.pl po wdrożeniu, tylko
   żądaniami GET, za osobną zgodą właściciela. Przypadki z audytu w maksimum W7.
5. P4: W1 i W5 z równoległym panelem i bez, trzy przebiegi każdy, różnica median w granicach rozrzutu.
6. P5: granice mapy strony ze stałą liczbą buforów na `small` i `medium`; każda porcja do `row_limit`; test granic
   bez statystyk (keyset) i ze statystykami; test produktów dodanych po `ANALYZE`.
7. P6: test pagera (przerwanie poprzedniego żądania, brak błędu po przerwaniu, przerwanie przy odmontowaniu) dla
   wszystkich list używających `useCursorPager`.
8. D2: fraza bez trzech kolejnych liter lub cyfr w zamówieniach i klientach daje 400 z polem i polskim komunikatem;
   `ab-cd`, `R-1000`, `ąęś` i e-mail przechodzą.
9. Tabela członkostwa zgodna z danymi źródłowymi po zapisie produktu, zmianie kategorii dodatkowych, zmianie zdjęć,
   usunięciu produktu i po generatorze.
10. `make check` i testy `apps/api` i `apps/admin` przechodzą; dokumentacja (`docs/features/seo/spec.md` dla D3,
    specyfikacje list panelu dla D2) opisuje stan po zmianie.
11. Raport w `docs/reports/` z wynikami przed i po, poleceniami do powtórzenia i listą niespełnionych wymagań
    z przyczyną.
