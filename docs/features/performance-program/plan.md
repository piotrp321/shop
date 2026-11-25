# Program naprawczy wydajności – plan

Program odpowiada na audyt [`docs/reports/performance-audit-2026-11-25.md`](../../reports/performance-audit-2026-11-25.md)
(rewizja `2b4258c7`). Właściciel zaakceptował diagnozę audytu i kierunek programu (2026-11-25). Epiki realizują osobne
sesje; kolejność weryfikujemy po wynikach każdej optymalizacji. Epik dostaje specyfikację dopiero wtedy, gdy rusza.
Plan jest dokumentem roboczym: po zakończeniu programu usuń go, a obowiązujące wymagania zostają w `docs/vision.md`
i specyfikacjach funkcji.

## Status

| Pozycja | Stan |
|---|---|
| Kierunek programu | zaakceptowany przez właściciela 2026-11-25 |
| Nowe epiki funkcjonalne | wstrzymane do zakończenia programu |
| Profil `server` na worf.pl | generuje go osobna sesja; jej wynik jest linią bazową (PERF-0) |
| PERF-7 | specyfikacja i plan w `docs/features/admin-queries/` czekają na akceptację i decyzje D1–D3; start po PERF-0 |
| pozostałe PERF-0 … PERF-9 | nie rozpoczęte |

## Zasady programu (decyzje właściciela)

1. Wydajność i skala (10 mln produktów, 1 mln klientów, 5 mln zamówień) są wymaganiem projektu od początku.
2. Najpierw pomiar i konkretne wymagania, potem optymalizacja. Każdy epik pokazuje wynik przed i po.
3. Pomiary na docelowym wolumenie (`server`) od początku programu, nie dopiero na końcu.
4. Funkcji nie ograniczamy po to, żeby poprawić wynik benchmarku.
5. Rzeczywista optymalizacja architektury i zapytań ma pierwszeństwo; natywne mechanizmy technologii są pożądane,
   kolejne warstwy cache na nieoptymalnym zapytaniu nie.
6. Proste rozwiązania, bez nowych usług, bez rozbudowy narzędzi i procesu ponad potrzebę pomiaru.
7. Obecny serwer zostaje (8 vCPU, 22,9 GiB, 193 GB). Nie zakładamy z góry ani nadprzydziału pamięci, ani większego
   sprzętu: decyzje zapadają po pomiarach na `server`.
8. Odpowiedź zdegradowana, odrzucona albo błędna nie jest sukcesem benchmarku.
9. Pomiary obejmują poprawność danych, współbieżność, długotrwałe obciążenie i równoległe zapisy.
10. Wymagań nie obniżamy bez uzasadnienia pomiarem i zgody właściciela.
11. Sukces programu to wydajny sklep na profilu `server`, a nie wygenerowanie danych.
12. Workflow ASH bez zmian w trakcie programu (sekcja „Wnioski dla ASH”).

## Rodzaje cache i zasady ich użycia

| Rodzaj | Przykłady w projekcie | Zasada |
|---|---|---|
| cache systemu operacyjnego (pliki) | indeks Elasticsearcha, tabele i indeksy Postgresa w pamięci | zawsze pożądany; usługi mają mieć pamięć na gorące dane. Pomiar „zimny” nie oznacza pustego cache plików: mierzymy stan ustalony po rozgrzaniu danych |
| natywne mechanizmy silnika | request cache i query cache Elasticsearcha, `shared_buffers`, opcache, ISR i `use cache` Next.js | pożądane, gdy silnik sam unieważnia wynik przy zmianie danych; konfigurujemy je świadomie i mierzymy trafienia |
| materializowany model odczytu | `ordering_sales_hour`, `ordering_customer`; ewentualnie liczniki facetów | dopuszczalny, gdy jest utrzymywany przy zapisie, ma zdefiniowaną świeżość i test zgodności z wynikiem liczonym na żywo; to decyzja architektoniczna z pomiarem, nie domyślne lekarstwo |
| cache HTTP | Varnish przed API, Cloudflare dla obrazów | dla odpowiedzi niezależnych od użytkownika; chroni przed powtórzeniami, nie zastępuje szybkiej ścieżki zimnej |
| cache aplikacyjny z TTL | pule Symfony Cache | tylko dla danych rzadko zmiennych i tanich w unieważnieniu; nie jako osłona wolnego zapytania |

Zasada wspólna: wymagania zimnej ścieżki (W1, W3, W8, W9) mierzymy z wyłączonym cache HTTP i aplikacyjnym dla
mierzonych adresów i z opróżnionym request cache Elasticsearcha. Natywny cache silnika i model odczytu mogą być
częścią rozwiązania, jeśli wynik jest poprawny także tuż po zmianie danych, a pierwsze zapytanie po unieważnieniu
mieści się w wymaganiu. Warstwa cache dodana po to, żeby ukryć zapytanie niespełniające wymagania, nie jest
rozwiązaniem.

## Wymagania W1–W20

Wartości są celem programu. Zmiana wartości wymaga pomiaru, uzasadnienia i zgody właściciela.

### Metodologia

- **Środowisko obowiązujące:** worf.pl, profil `server`. Pomiary lokalne (ta sama rewizja i dane) służą do porównań
  przed i po oraz do eksperymentów; ich czasy nie są wynikiem serwera.
- **Co liczymy:** wyłącznie pełne odpowiedzi 2xx zgodne z kontraktem (listing z kompletem facetów i liczbą wyników).
  Odpowiedź zdegradowana, odrzucona, przerwana albo 5xx to niepowodzenie. Żądanie, którego generator nie zdążył
  wysłać w zadanym tempie, też.
- **Czas:** po stronie serwera, od przyjęcia żądania do ostatniego bajtu, bez sieci klienta (generator w sieci
  klastra albo czas z logu serwera brzegowego). Dla stron sklepu mierzymy HTML strony, osobno czas samego API.
- **Czasy odpowiedzi (W1–W7) mierzymy w modelu otwartym:** żądania przychodzą w stałym tempie, niezależnie od tego,
  czy poprzednie się skończyły. Tempo pomiaru czasów to obciążenie nominalne: połowa przepustowości wymaganej dla
  danej ścieżki (np. W1 przy 15 zimnych listingach na sekundę, W6 przy 10 zamówieniach na sekundę).
- **Przepustowość (W9–W11)** to najwyższe stałe tempo modelu otwartego utrzymane przez 30 minut, przy którym czasy
  mieszczą się w W1–W7 i nie ma niepowodzeń. Liczba żądań w toku wynika z tempa i czasu (tempo × średni czas) i nie
  jest osobnym parametrem.
- **Współbieżność (W8)** to osobny test skoku: N żądań wysłanych jednocześnie na różne zimne adresy, przy tle równym
  obciążeniu nominalnemu. Kryterium ma własny próg czasu, bo przy skoku żądania z natury czekają na CPU.
- **Zimne:** adres niewidziany przez żaden cache HTTP ani aplikacyjny, request cache Elasticsearcha opróżniony;
  dane rozgrzane w cache plików (stan ustalony).
- **Próba:** percentyle z co najmniej 1000 pomiarów na ścieżkę (p99) albo z całego przebiegu; trzy przebiegi, wynikiem
  jest środkowy; rozrzut między przebiegami podajemy w raporcie. Różnicę przed i po uznajemy za efekt tylko wtedy,
  gdy przekracza ten rozrzut.
- **Mieszanka adresów:** generowana z danych (strona główna, działy, liście, frazy z nazw produktów, filtry opcji,
  ceny i dostępności, sortowania, strony głębokie), zapisana razem z wynikiem, ta sama przed i po.

### Czasy odpowiedzi (profil `server`, obciążenie nominalne)

| ID | Ścieżka | p50 | p95 | p99 |
|---|---|---|---|---|
| W1 | listing, wyszukiwanie, filtrowanie (z facetami): zimne | 200 ms | 500 ms | 1000 ms |
| W2 | listing: ciepłe | 30 ms | 100 ms | 200 ms |
| W3 | strona produktu: zimna | 80 ms | 250 ms | 500 ms |
| W4 | strona produktu: ciepła | 15 ms | 50 ms | 100 ms |
| W5 | koszyk: odczyt i zmiana | 50 ms | 150 ms | 300 ms |
| W6 | złożenie zamówienia | 150 ms | 500 ms | 1000 ms |
| W7 | panel: listy, wyszukiwanie, filtry | 100 ms | 500 ms | 2000 ms (maksimum) |

### Przepustowość, współbieżność, przeciążenie

| ID | Wymaganie |
|---|---|
| W8 | skok 100 jednoczesnych zimnych wejść na listing (różne adresy z mieszanki): 100 pełnych odpowiedzi, najwolniejsza do 2 s; w tym czasie W5 i W6 w normie |
| W9 | zimne listingi z mieszanki: co najmniej 30 na sekundę przez 30 minut |
| W10 | ruch mieszany sklepu (listing 60%, strona produktu 30%, koszyk i zamówienia 10%): co najmniej 150 stron na sekundę przez 30 minut |
| W11 | zamówienia: co najmniej 20 na sekundę przez 30 minut, równolegle ze 50 zmianami cen i stanów na sekundę i pracą w panelu |
| W12 | tempo 2 razy wyższe od zmierzonej przepustowości przez 5 minut: pełne odpowiedzi co najmniej 80% przepustowości, koszyk i zamówienia w normie W5–W6, powrót do normy w 60 s po ustaniu, 0 restartów |

Z W8 wynika budżet: 100 zimnych listingów w 2 s na 8 vCPU to najwyżej ok. 160 ms CPU serwera na jeden listing
łącznie we wszystkich usługach. To miara, którą PERF-2 porównuje modele.

### Poprawność, trwałość, zasoby

| ID | Wymaganie |
|---|---|
| W13 | po każdym pomiarze z zapisami: stan magazynowy nieujemny i zgodny z ruchami, brak podwójnych zamówień i płatności, sumy zamówień zgodne z liniami, indeks wyszukiwarki zgodny z bazą dla próby produktów, 0 wiadomości w `failed` |
| W14 | bieg 2 h przy 70% zmierzonej przepustowości z zapisami: brak trendu wzrostu p95, pamięci, zaległości i rozmiaru tabel kolejek; 0 restartów i OOM |
| W15 | przy obciążeniu nominalnym: CPU węzła do 70%, iowait do 5%, żaden kontener powyżej 85% limitu pamięci |

### Operacje asynchroniczne i masowe (`server`)

| ID | Wymaganie |
|---|---|
| W16 | zmiana ceny albo stanu widoczna w sklepie: p95 do 10 s, p99 do 60 s przy 50 zmianach na sekundę |
| W17 | 100 tys. zmian cen: sklep zgodny w 15 minut; e-mail zamówienia złożonego w trakcie w 60 s |
| W18 | zmiana nazwy albo przeniesienie działu (20% katalogu): zakończone w 30 minut, W1 spełnione w trakcie |
| W19 | pełny reindeks do 60 minut |
| W20 | generator `server` do 6 h w limitach pamięci zadania |

**W19a (poprawność, nie wydajność):** zmiana zapisana w trakcie pełnej przebudowy indeksu jest obecna w nowym
indeksie po zamianie aliasu. Dziś tak nie jest (audyt, P13). Do czasu spełnienia W19a pełny reindeks wykonujemy
wyłącznie przy zamkniętym sklepie albo bez równoległych zapisów katalogu; reindeks pod normalnym ruchem jest
niedopuszczony.

Te same identyfikatory mierzymy też na `medium` (szybkie porównania w epikach); obowiązuje wynik na `server`.

## Epiki

Rodzaj: **przebudowa** (zmiana modelu danych, kontraktu albo sposobu przetwarzania), **optymalizacja** (zapytanie,
konfiguracja albo kod bez zmiany modelu), **pomiar**.

### PERF-0. Linia bazowa na `server` (pomiar)

Mały epik: istniejące narzędzia, bez nowego frameworka. Zestaw pomiarów rośnie razem z epikami: epik, który
potrzebuje scenariusza dla swojego wymagania, dopisuje go i mierzy nim stan przed zmianą.

- Narzędzia: skrypty k6 w `load/` (`saturate.js`, `steady.js`) i `make -C infra/k8s load`; skrypty pomiaru przez
  HTTP z `docs/reports/search-shards-worf-2026-11-25/scripts/` (`measure.py`, `measure-shallow.py`, `facets.py`);
  narzędzie współbieżności i skrypty Elasticsearcha z `docs/reports/performance-audit-2026-11-25/` (`scripts/lt.py`,
  `results/es/esq.py`, `conc.py`, `compare.py`); zapytania `EXPLAIN` z `results/pg` i `results/admin`.
- Wynik generowania `server` na worf.pl z osobnej sesji jest częścią linii bazowej: czasy kroków generatora
  i reindeksu, rozmiary tabel i indeksu, szczyt dysku, pamięć i CPU usług, stan po zakończeniu. Tej sesji nie
  dotykamy; czytamy jej wynik i metryki.
- Pomiar stanu wyjściowego na `server` na worf.pl, bez zmian na serwerze: W1, W2, W3, W4 i W8 żądaniami GET (jak
  w #928), zasoby z Grafana Cloud (W15), wynik generatora (W20) i reindeksu (W19). Wymagania z zapisami (W5, W6,
  W10–W14, W16–W18) i panel (W7) mierzymy lokalnie na `server` albo `medium`; ich pomiar na worf.pl wymaga osobnej
  zgody właściciela.
- Jeden raport w `docs/reports/` z tabelą W1–W20: wartość zmierzona, sposób pomiaru, albo „niezmierzone” z powodem.
- Zatwierdzone wartości W1–W20 i metodologia trafiają do `docs/vision.md`.

Kryteria akceptacji: raport linii bazowej `server` z worf.pl z tabelą W1–W20; każdy pomiar da się powtórzyć jednym
poleceniem z raportu; trzy przebiegi i rozrzut dla W1 i W8; lista wymagań jeszcze bez scenariusza, z epikiem, który
go dopisze.

### PERF-2. Model wyszukiwania i facetów (przebudowa) – priorytet programu

Zakres funkcjonalny bez zmian: fasety na każdym listingu (także na stronie głównej), filtry wariantu wszędzie,
dokładne liczby produktów, warunek „ten sam wariant”, sortowania i stronicowanie jak dziś.

**Etap A: eksperyment na 10 mln produktów (rusza od razu, równolegle z PERF-0).** Nie zakładamy, że obecny model
albo alternatywa z audytu są optymalne. Wybór modelu wynika z pomiaru.

- Dane: indeks 10 mln produktów o rozkładach profilu `server` (lokalnie, w izolowanym Elasticsearchu; do porównań
  także 1 mln). Wynik wybranego modelu potwierdzamy potem na indeksie `server` na worf.pl.
- Zestaw zapytań z kodu sklepu: cały katalog, dział, liść, fraza, jeden i kilka filtrów opcji, cena, dostępność,
  kombinacje na całym katalogu i w kategorii, sortowania, strona głęboka; ten sam zestaw dla każdego modelu.
- Kandydaci (lista otwarta, eksperyment może dodać własnych):
  - obecny model jako punkt odniesienia;
  - fasety z pól dokumentu produktu, `nested` tylko dla warunków na wariant (audyt: 4 razy mniej CPU na 1 mln);
  - model bez `nested`: wariant jako osobny dokument z polami produktu i zliczanie produktów zwijaniem albo
    agregacją po identyfikatorze produktu (z kontrolą dokładności);
  - warunki pojedyncze obsłużone polami produktu, `nested` tylko dla kombinacji warunków;
  - fasety osobnym zapytaniem niezależnym od strony i sortowania, z natywnym request cache;
  - natywne ustawienia silnika: liczba shardów i równoległość wyszukiwania, sortowanie indeksu, `filters` zamiast
    `terms` z `include`, typy pól;
  - materializowane liczniki facetów dla zapytań bez filtrów, tylko jeśli żaden model liczony na żywo nie spełni
    budżetu.
- Miary dla każdego kandydata: CPU na zapytanie i czas przy 1, 8 i 100 równoległych, przepustowość przy 8 rdzeniach,
  rozmiar indeksu i gorących danych, tempo indeksowania, koszt aktualizacji jednego produktu, zgodność wyników
  z obecnym modelem (liczby trafień, fasety, pierwsza strona) dla całego zestawu zapytań.
- Wynik: raport w `docs/reports/` z tabelą kandydatów, rekomendacją i tym, czego rekomendowany model nie spełnia.
  Wybór modelu to decyzja właściciela.

Kryteria etapu A: każdy kandydat zmierzony tym samym zestawem na 10 mln; zgodność wyników sprawdzona automatycznie;
dla rekomendowanego modelu policzony budżet W1, W8 i W9 na obecnym serwerze (pomiar lokalny przeliczony zmierzonym
stosunkiem szybkości rdzeni, oznaczony jako szacunek do potwierdzenia na worf.pl).

**Etap B: implementacja wybranego modelu.** Mapping, zapytania, indeksowanie, kontrakt API i sklep jako konsument,
przebudowa indeksu spełniająca W19a.

Kryteria etapu B: W1, W8 i W9 spełnione na `server` na worf.pl z wyłączonym cache HTTP; test równoważności wyników
z obecnym modelem na danych z wieloma wariantami; W19a spełnione testem (zapis w trakcie przebudowy widoczny w nowym
indeksie); W13 po pomiarze; porównanie z linią bazową.

### Backlog pozostałych epików

Bez szczegółowych specyfikacji; kolejność weryfikujemy po każdym epiku na podstawie pomiarów.

| Epik | Rodzaj | Zakres w skrócie | Zależy od | Kryteria akceptacji |
|---|---|---|---|---|
| PERF-1. Konfiguracja uruchomieniowa i zasoby | optymalizacja | Postgres (pamięć, WAL, limity czasu zapytań i blokad, połączenia), limity pamięci usług wobec zmierzonego zużycia i gorących danych, Redis, Varnish, wątki PHP, wolumeny; decyzje o pamięci i dysku wyłącznie z pomiaru linii bazowej `server` | PERF-0 | W15 spełnione na `server`; zapytanie ponad limit czasu jest przerywane i zwalnia wątek; W3, W5, W6 przed i po; ADR z liczbami |
| PERF-3. Odporność na przeciążenie i izolacja ścieżek | optymalizacja | limit współbieżności wyszukiwarki i kolejki wątków, żeby wolna wyszukiwarka nie blokowała koszyka i zamówień; Varnish: obsługa błędów i miękki purge; limit czasu w sklepie; błąd wyszukiwarki bez 500 całej strony jako zabezpieczenie awaryjne | PERF-0; kod wyszukiwarki po PERF-2 B | W12 spełnione; w W1–W11 odpowiedź odrzucona albo zdegradowana nadal jest niepowodzeniem; zatrzymanie Elasticsearcha nie daje 500 i nie zatrzymuje zamówień |
| PERF-4. Tryb uruchomienia API | optymalizacja o podwyższonym ryzyku | trwałe połączenia albo tryb worker FrankenPHP, wybór pomiarem; walidacja stanu między żądaniami | PERF-1 | przepustowość strony produktu i koszyka w API co najmniej 3 razy większa niż na linii bazowej; W13 i W14 spełnione |
| PERF-5. Renderowanie i skalowanie sklepu | przebudowa częściowa | HTML listingów niezależny od sesji renderowany raz (natywny mechanizm Next.js), równoległe odczyty zimnej ścieżki, sklep gotowy na więcej niż jedną replikę | PERF-2 B, PERF-3 | W2 i W10 spełnione; W1 spełnione także z wyłączonym cache HTML; zmiana ceny widoczna na wszystkich replikach w czasie W16 |
| PERF-6. Zdarzenia i operacje masowe | przebudowa | przetwarzanie wsadowe natywnym mechanizmem Messengera, osobna kolejka e-maili, wsadowy reindeks kategorii, pełny reindeks w kilku procesach, alert zaległości | PERF-2 B | W16–W19 spełnione; W13 po każdym scenariuszu (kolejność zdarzeń produktu, brak utraconych zmian) |
| PERF-7. Zapytania panelu i mapy strony | optymalizacja | plan dobierany do liczby trafień dla filtra kategorii i wyszukiwania, frazy 1–2-znakowe bez ograniczania funkcji, anulowanie żądań w panelu, granice mapy strony | PERF-0 | W7 spełnione dla najgorszych przypadków z audytu; równoległe zapytania panelu nie zmieniają W1 i W5 |
| PERF-8. Ścieżka zapisu i równoległe zapisy | optymalizacja | indeksy tabel zapisu, czas blokad w zamówieniu, rozrost tabel kolejek i historii | PERF-1 | W6, W11, W13, W14 spełnione; wyprzedaż jednego wariantu z kodem z limitem: brak nadsprzedaży i zakleszczeń |
| PERF-9. Akceptacja końcowa | pomiar | pełny zestaw W1–W20 i bieg W14 na worf.pl na jednej rewizji; aktualizacja `docs/vision.md` i specyfikacji | wszystkie | wszystkie wymagania spełnione w jednym przebiegu; niespełnione wymienione z przyczyną i decyzją właściciela |

## Kolejność i praca równoległa

| Krok | Sesje | Uwagi |
|---|---|---|
| 1 | PERF-2 etap A i PERF-0 równolegle | etap A pracuje lokalnie na izolowanym Elasticsearchu; PERF-0 czyta wynik sesji generującej `server` i mierzy żądaniami GET |
| 2 | decyzja właściciela o modelu (raport etapu A) i zatwierdzenie W1–W20 (raport PERF-0) | po niej rusza implementacja |
| 3 | PERF-2 etap B; równolegle PERF-1 i PERF-7 | różne obszary: wyszukiwarka i sklep, chart i konfiguracja, zapytania panelu |
| 4 | pomiar `server` na worf.pl po wdrożeniu kroku 3 | weryfikacja priorytetów dalszych epików |
| 5 | PERF-3, PERF-4, PERF-8, potem PERF-5 i PERF-6 | kolejność według pomiaru z kroku 4 |
| 6 | PERF-9 | |

Równoległość ogranicza wspólny kod aplikacji: wyszukiwarkę API zmienia naraz jeden epik (PERF-2, potem PERF-3
i PERF-6), pliki listingu w sklepie też (PERF-2, PERF-3, PERF-5); chart zmienia PERF-1, a potem każdy epik
zmieniający usługę. Wdrożenia, `reindex` i `generate` na worf.pl wyłącznie za osobną zgodą właściciela.

## Przebudowa a optymalizacja

| Zmiana | Rodzaj |
|---|---|
| model dokumentu i zapytań wyszukiwarki, przebudowa indeksu zachowująca zapisy (PERF-2) | przebudowa |
| przetwarzanie zdarzeń wsadowo, podział kolejek, reindeks w kilku procesach (PERF-6) | przebudowa |
| HTML listingów poza ścieżką każdego żądania, sklep na wielu replikach (PERF-5) | przebudowa częściowa |
| tryb uruchomienia API (PERF-4) | optymalizacja o podwyższonym ryzyku |
| konfiguracja i zasoby (PERF-1), limity współbieżności i obsługa błędów (PERF-3), zapytania panelu (PERF-7), ścieżka zapisu (PERF-8) | optymalizacja |

## Ryzyka do rozstrzygnięcia pomiarem

- Dokładne fasety całego katalogu 10 mln liczone przy każdym żądaniu: audyt szacuje ok. 5 s CPU serwera w najtańszym
  zbadanym modelu wobec budżetu ok. 160 ms z W8. Etap A ma pokazać, który model i które natywne mechanizmy zamykają
  tę różnicę bez ograniczania funkcji.
- Zapytania z filtrem wariantu na całym katalogu 10 mln: w obu modelach z audytu szacunkowo 9–13 s na serwerze.
- Pamięć: gorące dane `server` audyt szacuje na ok. 16 GiB przy 22,9 GiB węzła i sumie limitów 20 GB. Czy mieszczą
  się bez zmian zasad, pokaże linia bazowa.
- 60 tys. jednocześnie aktywnych użytkowników (`docs/vision.md`) przekracza możliwości jednego węzła 8 vCPU; program
  mierzy przepustowość węzła i usuwa przeszkody w skalowaniu poziomym.

Gdy pomiar potwierdzi, że wymaganie jest nieosiągalne na obecnym serwerze bez ograniczenia funkcji, epik kończy się
raportem z wariantami i decyzją właściciela.

## Pokrycie problemów audytu

| Problem audytu | Epik |
|---|---|
| P1 koszt listingu liniowy z katalogiem; P13 zapisy tracone przy przebudowie indeksu | PERF-2 |
| P2 załamanie przy przeciążeniu, P3 błąd wyszukiwarki jako 500, P8 czyszczenie całego cache | PERF-3 |
| P4 Postgres na ustawieniach domyślnych, P5 limity pamięci, P14 dysk, limity połączeń i pamięci usług | PERF-1 |
| P6 HTML listingów i jedna replika sklepu | PERF-5 |
| P7 kernel i połączenia przy każdym żądaniu | PERF-4 |
| P9 zdarzenia po jednym, P10 operacje masowe, P13 czas pełnego reindeksu | PERF-6 |
| P11 zapytania panelu, mapa strony | PERF-7 |
| P12 brak pomiaru zimnej ścieżki i metryk | PERF-0 i każdy epik w swoim zakresie |
| indeksy zapisu, blokady w zamówieniu, rozrost tabel kolejek | PERF-8 |

## Wnioski dla ASH (do rozwiązania po programie)

Workflow ASH nie zmienia się w trakcie programu. Ustalenia, dlaczego proces dopuścił te błędy mimo jednoznacznych
wymagań projektu, są zachowane w audycie (sekcja 6) i w
`docs/reports/performance-audit-2026-11-25/findings/ash-process-backlog.md`: brak wymagań liczbowych w specyfikacjach
gorących ścieżek, brak etapu pracującego na danych w skali, ryzyka odraczane bez właściciela i warunku, ustawienia
uruchomieniowe bez decyzji, retrospektywy pomijające wydajność produktu. Po zakończeniu programu osobna decyzja
właściciela o zmianach procesu, z wykorzystaniem pomiarów i scenariuszy, które program zostawi w repo.

## Poza programem

Nowe funkcje sklepu, zmiany workflow ASH, operacje na worf.pl bez osobnej zgody właściciela, praca sesji generującej
`server`.
