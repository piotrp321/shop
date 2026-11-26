# PERF-7. Zapytania panelu i mapy strony

Epik programu wydajności ([`docs/features/performance-program/plan.md`](../performance-program/plan.md), PERF-7).
Cel: listy, wyszukiwanie i filtry panelu oraz granice mapy strony spełniają W7 i nie pogarszają sklepu przy 10 mln
produktów, 1 mln klientów i 5 mln zamówień (profil `server`). Rodzaj: optymalizacja zapytań i indeksów PostgreSQL.
Zakres funkcji i semantyka wyszukiwania bez zmian, bez nowych usług, bez cache'u nad wolnym zapytaniem. Kolejność
pracy: najpierw dowody (zadanie 1), potem najprostszy wariant, który spełnia wymagania; złożoność dokładamy tylko tam,
gdzie pomiar pokazuje, że prostszy wariant nie wystarcza.

Materiał dowodowy (rewizja `2b4258c7`, profil `medium`: 1 mln produktów, 1 mln zamówień, 98,6 tys. klientów):
[`findings/admin.md`](../../reports/performance-audit-2026-11-25/findings/admin.md) (A1–A7),
[`findings/postgres.md`](../../reports/performance-audit-2026-11-25/findings/postgres.md) (K2, K4–K6, tabela
zapytań #9–#34), plany `EXPLAIN (ANALYZE, BUFFERS)` w `results/admin/` i `results/pg/` tego katalogu. Spec nie
powtarza tych pomiarów, tylko je wskazuje. Liczby oznaczone [SZACUNEK] to ekstrapolacja z `medium`, nie wynik.

## Stan obecny

| Obszar | Wynik audytu na `medium` | Przy 10 mln | Przyczyna |
|---|---|---|---|
| zamówienia, klienci, dashboard, historie cen i stanów, drzewo kategorii z licznikami | 0,05–45 ms | bez wzrostu dla przypadków z audytu [SZACUNEK] | keyset, `BoundedCounter`, indeksy częściowe, modele odczytu (`ordering_customer`, `ordering_sales_hour`) |
| produkty: mała kategoria (#14–#16, `products-cat.out`) | 2,2–9,4 s, 1 mln buforów | 20–90 s [SZACUNEK] | warunek `IN (…) OR EXISTS (…)` bez oszacowania selektywności; planista zawsze przechodzi indeks sortowania z filtrem |
| produkty: fraza „termos” z sortem po nazwie (`products-list.out` P5) | 1,1–2,4 s | 10–25 s [SZACUNEK] | trafienia skupione na „T”, przejście `(name, id)` odrzuca 918 tys. wierszy |
| produkty: fraza 2-znakowa „qx”, fraza z samej interpunkcji `#!#` (#10–#12) | 0,3–1,5 s, licznik do 0,85 s | 4–15 s [SZACUNEK] | pg_trgm nie wyciąga trygramu: GIN zwraca 617 tys. kandydatów albo pełne przejście |
| zamówienia i klienci: fraza `#!#` (#23, #24, #28) | 0,33–0,41 s i 40–52 ms | ok. 2 s przy 5 mln zamówień, ok. 0,5 s przy 1 mln klientów [SZACUNEK] | jak wyżej: fragment ma 3 znaki, ale pg_trgm nie wyciąga z niego trygramu |
| kody rabatowe: aktywne użycia na wiersz (A4) | 15,6 ms ciepło, 176 ms zimno | ok. 80 ms ciepło, ok. 1 s zimno [SZACUNEK] | `count(*)` bez limitu na wiersz strony |
| granice porcji mapy strony (#33, K6) | 0,55–1,3 s | 6–13 s [SZACUNEK] | `row_number()` po całym kluczu głównym; wynik trzyma pula `cache.sitemap` przez godzinę |
| front panelu (A3) | `useCursorPager` ignoruje spóźnione odpowiedzi, ale ich nie przerywa | – | brak `AbortController` w panelu |

Indeksy `catalog_product` dziś: `(name, id)`, `(updated_at, id)`, te same dwa częściowe dla szkiców, częściowy
`(id) WHERE images = '[]'`, `(primary_category_id)`, GIN trygramów na `name`. Zamówienia: GIN trygramów na
`lower(number || email || full_name)`; klienci: GIN trygramów na `lower(email)` i `lower(full_name)`. PostgreSQL 18.

Cztery ekrany (Produkty, Ceny, Stany, wybór wariantów promocji) korzystają z jednego zapytania
`DbalBackofficeProductSource::page` (Ceny i Stany przez `QueryBusProductDirectory` w `Pricing` i `Inventory`), więc
poprawka w jednym miejscu obejmuje wszystkie.

### Ustalenie: próg z audytu nie wystarcza przy 10 mln

Audyt proponuje wybór planu po ograniczonym liczniku: do 10 000 trafień zbiór, potem sortowanie; powyżej obecne
przejście indeksu sortowania, „bo trafienia są gęste”. To założenie nie działa, gdy trafienia są skorelowane
z porządkiem sortowania:

- W danych demo nazwa to `<typ> <marka> <model>`, a typ wyznacza liść kategorii głównej
  (`DemoData/Catalog/ProductGenerator.php:89`, `:120`). W prawdziwym katalogu jest podobnie („Termos …” w „Termosach”).
- Przy 10 mln produktów liść ma średnio kilkadziesiąt tysięcy produktów, a fraza „termos” ok. 129 tys. trafień
  [SZACUNEK: 10 razy wynik z `medium`]. Oba przypadki są ponad progiem, więc trafiają do przejścia indeksu
  `(name, id)`, które dla typu z końca alfabetu czyta większość tabeli, zanim znajdzie pierwszy wiersz.
- Na `medium` liść 21 tys. kosztował 3,8–9,7 ms tylko dlatego, że jego typ jest na początku alfabetu [HIPOTEZA H1:
  duży liść z typem na końcu alfabetu jest wolny już na `medium`; rozstrzyga ją zadanie 1].
- Kategorie dodatkowe (20% produktów w danych demo, `ADDITIONAL_CATEGORY_SHARE`) nie mają w
  `catalog_product_category` klucza sortowania; dział przy 10 mln ma ok. 280 tys. takich członkostw [SZACUNEK].

## Co ogranicza koszt, a co nie

Te zasady wyznaczają, które klasy przypadków mogą mieć koszt niezależny od wielkości danych. Specyfikacja nie
deklaruje stałego kosztu tam, gdzie żadna z nich go nie daje.

1. **Indeks btree przechodzony w porządku sortowania** zwraca wiersze po kolei, więc `LIMIT` zatrzymuje go po
   stronie wyników. Koszt jest ograniczony stroną tylko wtedy, gdy pozostałe filtry przepuszczają większość
   przechodzonych wierszy. Gdy filtr jest rzadki w przechodzonym porządku albo trafienia są skupione dalej (np.
   „termos”), przejście czyta wszystkie wiersze przed pierwszymi trafieniami: koszt liniowy względem ich liczby.
   Częściowy btree, którego predykat jest filtrem (dziś szkice), czyta tylko pasujące wiersze, więc daje stronę
   i licznik do 10 001 z kosztem ograniczonym konstrukcją.
2. **Indeks GIN** (trygramy, bigramy) obsługuje wyłącznie skan bitmapowy. `Bitmap Index Scan` czyta całe listy
   pozycji kluczy zapytania (przy kilku kluczach z pomijaniem, ale nie mniej niż najrzadsza lista) i buduje bitmapę,
   zanim zwróci pierwszy wiersz. `LIMIT` i ograniczony licznik zatrzymują dopiero fazę tabeli (sprawdzenie wiersza
   i warunku), nie budowę bitmapy. Koszt kandydatów z GIN rośnie więc z długością list pozycji (częstość
   n-gramów frazy w danych), a nie z progiem T. Bitmapa większa niż `work_mem` traci dokładność (strony zamiast
   wierszy), co zwiększa sprawdzanie w tabeli.
3. **Sortowanie kandydatów** kosztuje O(c log c) dla c kandydatów, a powyżej `work_mem` pisze pliki tymczasowe.
4. **Licznik pierwszej strony** (`BoundedCounter`, limit 10 001) ma koszt ograniczony tylko na drodze z zasady 1
   z gęstymi trafieniami albo na częściowym btree. Liczony przez GIN płaci pełny koszt z zasady 2; liczony
   przejściem z rzadkim filtrem płaci koszt liniowy z zasady 1.
5. **Rzadkie przecięcie filtrów** (np. duży dział i fraza częsta w katalogu, a rzadka w dziale) nie ma drogi
   o koszcie ograniczonym w żadnym wariancie bez indeksu złożonego dokładnie dla tego przecięcia. Najlepszy możliwy
   koszt to mniejszy z dwóch liniowych: przejście kategorii albo kandydaci frazy.

Wniosek: dla każdego przypadku macierzy wariant podaje zmienną, z którą rośnie jego koszt (tabela „Klasy kosztu”),
a spełnienie wymagań rozstrzyga pomiar na `server`, nie klasa.

## Wymagania

| ID | Wymaganie | Miara |
|---|---|---|
| P1 | W7 dla panelu: p50 100 ms, p95 500 ms, maksimum 2000 ms | mieszanka panelu (niżej) na `server` |
| P2 | Każdy przypadek macierzy w maksimum W7 na `server`; przypadki z audytu (#10–#16, #23, #24, #28, „termos”) obowiązkowo | maksimum z 20 powtórzeń zimnych i ciepłych, z licznikiem pierwszej strony |
| P3 | Koszt każdego przypadku zgodny z jego klasą | `EXPLAIN (ANALYZE, BUFFERS)` na `small`, `medium` i `server`: przypadki z klasą ograniczoną konstrukcją nie rosną z wolumenem; pozostałe rosną najwyżej proporcjonalnie do nazwanej zmiennej (trafienia, rozmiar kategorii, lista pozycji), której wartość raport podaje |
| P4 | Praca panelu nie pogarsza sklepu | niżej, „Wpływ na sklep” |
| P5 | Mapa strony: twardy limit, kompletność, poprawność przy nieaktualnych statystykach i zmianach danych | niżej, „Granice mapy strony”, gwarancje G1–G3; czas zimnego obliczenia granic na `server` w raporcie |
| P6 | Panel przerywa żądanie listy, które zastąpiło nowsze, i żądania odmontowanego widoku | test jednostkowy pagera: sygnał poprzedniego żądania jest przerwany, przerwanie nie daje komunikatu błędu |
| P7 | Wyniki bez zmian: te same wiersze, kolejność, kursory i liczniki co przed zmianą, także dla zamówień i klientów | test równoważności z zapytaniem referencyjnym (obecny SQL) |
| P8 | Koszt zapisu nowych indeksów i struktur jest zmierzony i zaakceptowany | WAL i czas na zapis produktu, cykl zamówienia (złożenie, płatność, zmiana statusu) i zapis klienta, przed i po; W6 i W11 z nowymi indeksami |

**Akceptacja tylko na `server`.** P1, P2, P4 i czas z P5 uznajemy za spełnione wyłącznie na podstawie pomiaru na
skali `server` (10 mln produktów, 1 mln klientów, 5 mln zamówień): na worf.pl za osobną zgodą właściciela albo
lokalnie na profilu `server`, jeśli pozwalają zasoby. `small` i `medium` służą do wyboru wariantu i porównań przed
i po; ich wynik nie jest akceptacją. Bez pomiaru na `server` raport oznacza te wymagania jako niepotwierdzone, a plan
programu nie pokazuje PERF-7 jako spełnionego.

Mieszanka panelu do W7 jest generowana z danych i zapisana z wynikiem: listy domyślne we wszystkich sortowaniach,
strony głębokie, frazy według częstości (słowa z nazw, marki, bigramy rzadkie i częste, brak trafień), kategorie
według rozmiaru i pozycji typu w alfabecie, kombinacje z filtrami, Ceny, Stany, zamówienia, klienci, kody rabatowe,
dashboard. Tempo panelu: propozycja 5 żądań na sekundę (kilkanaście osób pracujących naraz); wartość zatwierdza
właściciel razem z W1–W20 po PERF-0.

### Macierz przypadków

Każdy przypadek dla sortów `name` i `-updatedAt`, pierwszej i dalszej strony, z licznikiem.

| Grupa | Przypadki |
|---|---|
| kategoria | pusta; 4–20 produktów (Biwak, #14); średnia ok. 1–5 tys.; duży liść z typem na początku i na końcu alfabetu; dział; kategoria, w której większość produktów ma ją jako dodatkową |
| fraza | brak trafień; rzadka; „termos” (skorelowana z sortem); częsta nieskorelowana (marka); bardzo częsta (`pro`, `ka`); 2 znaki rzadkie (`qx`) i częste; sama interpunkcja (`#!#`); polskie znaki i wielkość liter |
| kombinacje | kategoria + fraza częsta globalnie i rzadka w kategorii; szkice; bez zdjęć; szkice + kategoria; bez zdjęć + fraza; bez zdjęć w dużym dziale |
| zamówienia i klienci | przypadki #19–#28 z audytu; fragmenty `#!#`, `---`, `a.b`, `ab-cd`, `ąęś`, `kowal`; numer `R-…`; pełny e-mail |
| inne | kody rabatowe (A4), drzewo kategorii z licznikami, dashboard |

## Warianty

Dla każdego obszaru warianty są ułożone od najprostszego. Zadanie 1 mierzy je po kolei na prototypach i wybiera
pierwszy, który spełnia P2 i P8 dla przypadków swojego obszaru (na `medium` z ekstrapolacją po zmiennej kosztu,
potwierdzenie na `server`). Porównanie obszaru kończy się na tym wariancie: bardziej złożonych nie prototypujemy.
Wybór idzie do właściciela przed falą 2 (plan, bramka po zadaniu 1).

### Filtr kategorii w liście produktów

| Wariant | Zmiana | Koszt odczytu | Koszt zapisu i spójność |
|---|---|---|---|
| K0 | przepisanie warunku `IN … OR EXISTS` na dwie gałęzie (kategoria główna przez `catalog_product_primary_category_id_idx`, dodatkowe przez `catalog_product_category`) połączone `UNION`, sort i `LIMIT` | liniowy w liczbie produktów poddrzewa (wszystkie czytane i sortowane); niezależny od pozycji typu w alfabecie i od rozmiaru katalogu | bez zmian schematu |
| K1 | K0 + btree `(primary_category_id, name, id)` i `(primary_category_id, updated_at, id)`; gałąź kategorii głównej osobno dla każdej kategorii poddrzewa z `ORDER BY … LIMIT` (scalenie przez `Merge Append`) | kategoria główna: kategorie poddrzewa × strona przy gęstych pozostałych filtrach (zasada 1); kategorie dodatkowe: liniowy w liczbie członkostw dodatkowych poddrzewa | dwa indeksy na `catalog_product`; zapis produktu zmienia `updated_at`, który jest indeksowany, więc każda aktualizacja jest już nie-HOT i dopisze wpis do obu nowych indeksów (WAL, P8); spójność zapewnia PostgreSQL |
| K2 | K1 + kopia kluczy sortowania i filtrów (`name`, `updated_at`, `status`, znacznik zdjęć) w `catalog_product_category` z indeksami `(category_id, klucz, product_id)` | także kategorie dodatkowe: kategorie poddrzewa × strona przy gęstych filtrach | każdy zapis produktu aktualizuje jego wiersze członkostw (do 10, bo zmienia się `updated_at`): więcej wierszy, wpisów indeksów i WAL na zapis; zmiana mapowania asocjacji agregatu (dziś `ManyToMany` Doctrine); wymagania spójności niżej |
| K3 | osobna tabela członkostwa `(category_id, product_id)` z kluczami sortowania i filtrami, także dla kategorii głównej, zamiast indeksów K1 | jak K2 | jak K2, bez zmiany mapowania asocjacji, ale z nową tabelą do utrzymania; wymagania spójności niżej |

We wszystkich wariantach przypadek „duży dział + rzadki filtr” (fraza rzadka w dziale, bez zdjęć w dziale) ma koszt
liniowy (zasada 5); zadanie 1 mierzy go osobno.

Wymagania spójności dla K2 i K3 (gdyby pomiar je wybrał): kopia jest zapisywana w tej samej transakcji co wiersz
produktu i po jego aktualizacji, więc blokada wiersza produktu porządkuje równoległe zapisy tego samego produktu;
każde miejsce, które zmienia `catalog_product` albo `catalog_product_category` z pominięciem repozytorium
(generator `DemoData` przez `COPY`, `DemoData/Photo/ProductPhotos.php`, migracje), utrzymuje kopię w tym samym kroku
(wyszukane przez `git grep` nazw obu tabel); test zgodności kopii z danymi źródłowymi po każdym rodzaju zapisu;
pomiar W18 (przeniesienie działu) i zapisu masowego bez zakleszczeń. Zmiana nazwy albo przeniesienie kategorii nie
zmienia kopii (wiersze trzymają identyfikator, poddrzewo liczymy ze ścieżek przy zapytaniu).

Odrzucone: Elasticsearch dla listy panelu (indeks nie ma szkiców, odświeża się asynchronicznie, a kod wyszukiwarki
zmienia w tym czasie PERF-2).

### Fraza w liście produktów

| Wariant | Zmiana | Koszt odczytu | Koszt zapisu |
|---|---|---|---|
| F0 | dziś: planista wybiera GIN trygramów albo przejście indeksu sortowania z filtrem | błędny wybór przy korelacji z sortem i przy frazie bez trygramu (stan obecny) | – |
| F1 | najpierw przejście indeksu sortowania z budżetem B wierszy (zasada 1); gdy strona się nie zapełni, kandydaci z GIN trygramów, sort, `LIMIT` | fraza gęsta w prefiksie porządku: najwyżej B; w przeciwnym razie B + listy pozycji frazy + sprawdzenie i sort kandydatów: liniowy w liczbie trafień („termos” przy 10 mln ok. 129 tys. [SZACUNEK]) | bez nowych indeksów |
| F2 | F1 + GIN bigramów na wyrażeniu `catalog_name_bigrams(name)` dla fraz, z których pg_trgm nie wyciąga trygramu (2 znaki, sama interpunkcja); `name ILIKE` zostaje warunkiem dokładnym | rzadka fraza 2-znakowa: listy pozycji jej bigramów; częsta: budżet B z F1 | nowy GIN: wpis przy każdej nie-HOT aktualizacji produktu (czyli każdym zapisie), rozmiar ok. 2 GB przy 10 mln [SZACUNEK, jak trygramy: 214 MB na 1 mln] |

Licznik pierwszej strony liczy ta sama droga co strona (zasada 4), więc jego koszt jest kosztem tej drogi do
10 001 trafień; zadanie 1 mierzy go osobno, bo na `medium` licznik bywał droższy od strony (#10–#12). Fraza
1-znakowa zostaje niedozwolona, jak dziś. F2 tylko wtedy, gdy F1 nie spełnia P2 dla fraz bez trygramu.

Szkice mają częściowy btree w porządku sortowania (koszt ograniczony konstrukcją). Bez zdjęć ma dziś częściowy
indeks `(id)`, bez porządku sortowania; zadanie 1 sprawdza, czy wystarcza (filtr rzadki: kandydaci, sort), zanim
pojawi się propozycja częściowego `(name, id)` i `(updated_at, id)` z predykatem bez zdjęć.

Kursor: format bez zmian dla klienta (nieprzezroczysty). Wybór drogi na dalszych stronach jest powtarzany albo
zapisany w kursorze; decyduje implementacja po pomiarze.

### Wyszukiwanie zamówień i klientów

Semantyka bez zmian: numer `R-…` i pełny e-mail dokładnie, inny fragment 3–100 znaków (`OrderSearchTerm`,
`BackofficeCustomerSearchTerm`) w numerze, e-mailu i nazwie. `ab-cd`, `a.b`, `#!#` i `---` są dziś poprawnymi
fragmentami i takimi zostają; P7 wymaga dla nich tych samych wyników co dziś. Problemem jest klasa fragmentów, z
których pg_trgm nie wyciąga trygramu (dla `#!#` potwierdzona w audycie): wtedy GIN nie pomaga i zapytanie czyta całą
tabelę. Zadanie 1 ustala planem zapytania, które fragmenty z macierzy należą do tej klasy.

| Wariant | Zmiana | Koszt odczytu | Koszt zapisu |
|---|---|---|---|
| Z0 | bez zmian | fragment bez trygramu: liniowy, ok. 2 s przy 5 mln zamówień i ok. 0,5 s przy 1 mln klientów [SZACUNEK] | – |
| Z1 | GIN bigramów na tych samych wyrażeniach co dzisiejsze GIN trygramów (zamówienia: jedno, klienci: dwa); fraza bez trygramu szuka kandydatów po bigramach, `LIKE` zostaje warunkiem dokładnym | listy pozycji bigramów frazy (rzadkie dla interpunkcji, długie dla częstych bigramów) | wpis GIN przy wstawieniu i każdej nie-HOT aktualizacji wiersza: zamówienie przy złożeniu, płatności i zmianach statusu; mierzone W6, W11 i WAL na cykl zamówienia (P8) |

Wybór: Z0, jeśli na `server` spełnia P2 dla zamówień i klientów i nie narusza P4; inaczej Z1, jeśli koszt zapisu
z P8 jest akceptowalny; jeśli żaden, decyzja właściciela z liczbami obu wariantów. Odrzucone: ograniczanie dozwolonych
fraz (zmienia funkcję), rozszerzenie `pg_bigm` (nowa zależność i własny obraz Postgresa), przejście `placed_at`
z budżetem (fragmenty bez trygramu mają zwykle mało trafień, więc i tak czyta całą tabelę).

### Granice mapy strony

Gwarancje, niezależne od wybranego wariantu i od statystyk:

- **G1, twardy limit:** odpowiedź porcji nigdy nie ma więcej niż `app.sitemap.row_limit` (50 000) produktów. Dziś
  zapewnia to `LIMIT` w `DbalSitemapProductSource::publishedInRange` i tak zostaje.
- **G2, kompletność w chwili cięcia:** granice dzielą całą przestrzeń kluczy (pierwsza porcja bez początku, ostatnia
  bez końca), więc każdy produkt należy do dokładnie jednej porcji; każda porcja ma w chwili cięcia najwyżej
  `row_limit` produktów, sprawdzone odczytem kluczy, nie oszacowaniem. Cięcie złożone z kilku zapytań (M1, M2)
  idzie w jednej transakcji tylko do odczytu na poziomie `REPEATABLE READ`, więc wszystkie zapytania widzą ten sam
  stan danych mimo równoległych wstawień i usunięć; granice są ściśle rosnące, a granicą może być identyfikator
  produktu usuniętego później (przedziały są po wartościach, nie po istniejących wierszach). Test: równoległe
  wstawienia i usunięcia w trakcie cięcia, potem każdy produkt ze stanu transakcji w dokładnie jednej porcji
  i każda porcja do `row_limit`.
- **G3, zmiany po cięciu:** porcja, która między cięciem a odczytem urosła ponad `row_limit`, jest dziś obcinana
  z ostrzeżeniem w logu do następnego cięcia (do godziny). Po zmianie takie przepełnienie dodatkowo unieważnia
  wpis granic w `cache.sitemap`, więc następne pobranie indeksu mapy tnie od nowa. Test: przepełnienie po dodaniu
  produktów do jednej porcji, potem pełna mapa bez braków.

Sprawdzenie G2 czyta wszystkie klucze, więc każdy poprawny wariant ma koszt liniowy w liczbie produktów (indeks
klucza głównego). Histogram statystyk może tylko zmniejszyć liczbę albo długość zapytań, nie koszt asymptotyczny.

| Wariant | Zmiana | Koszt |
|---|---|---|
| M0 | dziś: `row_number()` po całym kluczu głównym w jednym zapytaniu | liniowy, jedno długie zapytanie (6–13 s przy 10 mln [SZACUNEK]) |
| M1 | keyset od granicy do granicy: `WHERE id > :prev ORDER BY id OFFSET :n - 1 LIMIT 1` aż do końca | liniowy, dokładnie N na porcję, każde zapytanie krótkie (ok. 10 tys. wpisów indeksu) |
| M2 | granice kandydujące z `pg_stats.histogram_bounds` (`SET STATISTICS` dla `catalog_product.id`), każda porcja sprawdzona licznikiem `LIMIT row_limit + 1` (odczyt samego indeksu); porcja ponad limit dzielona keysetem jak w M1 | liniowy jak M1; mniej zapytań; porcje około N zamiast dokładnie N |

Wybór: najprostszy wariant, którego zimne obliczenie granic na `server` mieści się w budżecie zatwierdzonym przez
właściciela (propozycja: maksimum W7, 2000 ms) i nie narusza P4. Jeśli żaden się nie mieści, decyzja właściciela
z liczbami (np. budżet dla żądania robota raz na godzinę). M2 zmienia decyzję D1 w `docs/features/seo/spec.md`
(„dokładnie N” na „około N, najwyżej `row_limit`”); kontrakt API (`product-chunks`, `products`) bez zmian w każdym
wariancie. Pula `cache.sitemap` zostaje jako ochrona przed powtórzeniami, nie jako warunek wydajności.

### Front panelu

`useCursorPager` tworzy `AbortController` dla każdego żądania i przerywa poprzednie przy nowym zapytaniu, zmianie
strony i odmontowaniu; `PageRequest` przekazuje `signal`, każde `fetchPage` przekazuje go do klienta `openapi-fetch`.
Przerwane żądanie nie zmienia stanu i nie pokazuje błędu. Debounce 300 ms zostaje. Przerwanie po stronie przeglądarki
nie zatrzymuje zapytania w PHP [HIPOTEZA H2, A3; zadanie 1 sprawdza ją w `pg_stat_activity`], więc anulowanie
zmniejsza liczbę żądań w toku i wyścigi w widoku, a nie koszt w bazie. Koszt w bazie ogranicza wybór wariantów (P2)
i `statement_timeout` z PERF-1.

### Wpływ na sklep (P4)

Panel i sklep obsługuje to samo wdrożenie `api` i ta sama baza. Pomiar: W1 i W5 przy obciążeniu nominalnym (model
otwarty, metodologia programu) w dwóch wariantach tła: bez panelu i z równoległą pracą panelu (mieszanka W7 w tempie
nominalnym plus 8 równoległych wątków najgorszych przypadków macierzy i zimne obliczenie granic mapy strony). Trzy
przebiegi każdego wariantu.

| Miara | Kryterium |
|---|---|
| p50, p95, p99 W1 i W5 | różnica median przebiegów z panelem i bez nie większa niż rozrzut przebiegów bez panelu; p95 i p99 w normie W1 i W5 |
| przepustowość sklepu | zadane tempo utrzymane w obu wariantach (żądania niewysłane w tempie są niepowodzeniem, jak w metodologii) |
| błędy sklepu | 0 niepowodzeń (5xx, przerwane, zdegradowane, niewysłane) w obu wariantach |
| zasoby PostgreSQL | raport podaje dla obu wariantów: CPU bazy, odczyty z dysku i trafienia w `shared_buffers` (`pg_stat_database`, `pg_stat_io`), `temp_bytes`, połączenia aktywne i oczekujące na blokady (`pg_stat_activity`), WAL; różnicę ponad rozrzut wyjaśnia zapytanie, które ją powoduje; W15 spełnione |

Operacje masowe panelu (zmiana kategorii z reindeksem, masowe zmiany cen) należą do PERF-6; ich wpływ mierzą W11
i W18.

## Decyzje

Podjęte przez właściciela:

| ID | Decyzja |
|---|---|
| D1 | Model odczytu kategorii (K2, K3) tylko wtedy, gdy zadanie 1 pokaże, że K0 i K1 nie spełniają P2; porównanie obejmuje koszt zapisu, WAL, współbieżność i spójność. Wybrany wariant zatwierdza właściciel przed falą 2. |
| D2 | Semantyka wyszukiwania zamówień i klientów bez zmian; wariant według kosztu (Z0, Z1). |
| D3 | Histogram dopuszczalny wyłącznie jako optymalizacja; gwarancje G1–G3 obowiązują w każdym wariancie. |

Po zadaniu 1 właściciel zatwierdza wybrane warianty K, F, Z i M z liczbami albo decyduje o przypadkach, których
żaden wariant nie spełnia (wyjątek W7 z liczbami albo dalsza praca).

## Wymagane przed startem

- Raport PERF-0 i zatwierdzone W1–W20 (w tym tempo panelu do W7).
- `statement_timeout` i obsługę przerwanego zapytania ustawia PERF-1; PERF-7 ich nie zmienia. Jeśli PERF-1 nie jest
  jeszcze na `main`, pomiary PERF-7 przed i po idą na tej samej konfiguracji Postgresa.
- Lokalne pomiary PERF-7 nie nakładają się na ciężkie pomiary PERF-2.
- Środowisko `server` do akceptacji: worf.pl za zgodą właściciela albo lokalny profil `server`.

## Ryzyka

| ID | Ryzyko | Sprawdzenie | Gdy się potwierdzi |
|---|---|---|---|
| R1 | Fraza częsta i skorelowana z sortem („termos” przy 10 mln: ok. 129 tys. trafień [SZACUNEK]) ma koszt liniowy w trafieniach; na `medium` zbiór 12,9 tys. trwał 117 ms (ALT4), liniowo ok. 1,2 s przy 10 mln | przypadek w macierzy na `server` | decyzja właściciela z liczbami: wyjątek W7 dla tej klasy albo dalsza praca |
| R2 | Rzadkie przecięcie filtrów (zasada 5) ma koszt liniowy w każdym wariancie | przypadki kombinacji w macierzy na `server` | jak R1 |
| R3 | Licznik pierwszej strony przez GIN dla frazy częstej czyta całe listy pozycji (zasada 2) | licznik mierzony osobno w macierzy | licznik tą drogą, której koszt jest niższy w pomiarze; bez zmiany limitu 10 000 |
| R4 | Koszt zapisu nowych indeksów (K1, F2, Z1) i rozmiar (ok. 2 GB na indeks GIN przy 10 mln [SZACUNEK]) wobec W6, W11, W20 i pamięci węzła | P8, rozmiary po `generate` | rezygnacja z wariantu albo decyzja właściciela z liczbami; pamięć w PERF-1 |
| R5 | Brak środowiska `server` do pomiaru akceptacyjnego | przed zadaniem 7 | wymagania wydajnościowe zostają niepotwierdzone, PERF-7 nie jest oznaczony jako spełniony |

## Poza zakresem

- Licznik aktywnych użyć kodu rabatowego (A4): ten sam licznik jest na ścieżce zamówienia pod blokadą kodu, więc
  przechowywany licznik należy do PERF-8. PERF-7 mierzy listę kodów w macierzy i przekazuje wynik.
- Reindeks poddrzewa po zmianie kategorii (A5): PERF-6.
- Leniwe ładowanie tras panelu (A6): porządki, nie zależy od danych.
- `statement_timeout`, konfiguracja pamięci Postgresa: PERF-1.

## Kryteria akceptacji

1. P7: test równoważności (wiersze, kolejność, kursory, liczniki) z obecnym SQL dla każdej zmienionej drogi, na
   danych testowych z kategoriami dodatkowymi, polskimi znakami i powtarzającymi się nazwami; dla zamówień i klientów
   także fragmenty `#!#`, `---`, `a.b`, `ab-cd`, `ąęś`, numer `R-…` i pełny e-mail, a fragment 2-znakowy dalej daje
   400 jak dziś.
2. Testy planów w CI na ok. 100 tys. wierszy generowanych w teście, bez sterowania planistą: dla każdej zmienionej
   drogi asercja, że liczba przeczytanych wierszy jest ograniczona zmienną jej klasy (strona, budżet B, liczba
   trafień, rozmiar kategorii), a nie rozmiarem tabeli tam, gdzie zmienna jest mniejsza.
3. P3: tabela buforów i wierszy macierzy na `small`, `medium` i `server` w raporcie, ze zmienną kosztu każdego
   przypadku.
4. P1, P2: wynik na `server` (worf.pl za osobną zgodą właściciela, tylko żądaniami GET, albo lokalny `server`);
   `medium` przed i po jako porównanie. Bez pomiaru na `server` wymagania są niepotwierdzone (R5).
5. P4: tabela z sekcji „Wpływ na sklep” na `server`, trzy przebiegi każdego wariantu tła, z rozrzutem.
6. P5: testy G1–G3 (porcja do `row_limit` także przy błędnych statystykach, każdy produkt w dokładnie jednej
   porcji, także przy równoległych wstawieniach i usunięciach w trakcie cięcia, przepełnienie po cięciu unieważnia
   granice i następna mapa jest pełna); czas zimnego obliczenia granic na `server`.
7. P6: test pagera (przerwanie poprzedniego żądania, brak błędu po przerwaniu, przerwanie przy odmontowaniu) dla
   wszystkich list używających `useCursorPager`.
8. P8: WAL i czas zapisu przed i po dla każdego nowego indeksu albo struktury; W6 i W11 z nowymi indeksami, jeśli
   dotyczą zamówień.
9. Jeśli wybrano K2 albo K3: test zgodności kopii z danymi źródłowymi po zapisie produktu, zmianie kategorii
   dodatkowych, zmianie zdjęć, usunięciu produktu i po generatorze; test równoległych zapisów tego samego produktu.
10. `make check` i testy `apps/api` i `apps/admin` przechodzą; dokumentacja opisuje stan po zmianie
    (`docs/features/seo/spec.md`, jeśli wybrano M2).
11. Raport w `docs/reports/` z wynikami przed i po, poleceniami do powtórzenia i listą niespełnionych albo
    niepotwierdzonych wymagań z przyczyną.
