# Kompleksowy audyt wydajności projektu Shop (2026-11-25)

Niezależny audyt całego systemu na `main` `2b4258c7`: analiza kodu i konfiguracji przez ośmiu niezależnych audytorów
(subagentów), pomiary na lokalnym stosie `medium`, metryki Grafana Cloud serwera worf.pl (tylko odczyt) i końcowa
krytyczna weryfikacja wniosków. Bez zmian w kodzie, workflow ASH, epikach i na worf.pl.

| | |
|---|---|
| Rewizja | `2b4258c7` (`main` z 2026-11-25) |
| Dane | `medium`: 1 000 191 produktów, 3,0 mln wariantów, 1,0 mln zamówień, 98,6 tys. klientów; indeks 8 891 973 dokumentów Lucene, 1,3 GB, 1 shard |
| Stos pomiarowy | compose `shop-perf25` w tym worktree: API i workery z obrazu produkcyjnego (`--target prod`, `APP_ENV=prod`), sklep `STORE_MODE=production`, Elasticsearch ze stertą 2 GiB jak w `prod`; nadpisanie poza repo (`scripts/perf.override.yaml`) |
| Maszyna | Apple M4 Max, Docker Desktop: VM 16 CPU, 31 GiB; współdzielona z innymi sesjami (dwa stosy innych epików działały w tle) |
| Serwer odniesienia | worf.pl: 8 vCPU, 22,9 GiB RAM, k3s jeden węzeł; rdzeń ok. 2,5–3,5 raza wolniejszy od rdzenia tej maszyny (z porównania tych samych zapytań, notatka z 2026-11-25) |
| Materiał | [`performance-audit-2026-11-25/`](performance-audit-2026-11-25/): `findings/` (osiem raportów obszarów), `results/` (surowe wyniki, plany `EXPLAIN`, skrypty benchmarku Elasticsearcha), `scripts/` (narzędzie `lt.py`, nadpisanie compose, zlecenie audytorów) |

Klasy dowodu: **[POMIAR]** zmierzone w tym audycie, **[KOD]** odczytane z kodu albo konfiguracji, **[GRAFANA]**
z metryk i logów worf.pl, **[HIPOTEZA]** niesprawdzone, **[SZACUNEK]** ekstrapolacja. Wszystkie czasy lokalne opisują
tę maszynę; liczby dla serwera przy `server` (10 mln) są szacunkiem, nie pomiarem.

## 0. Odpowiedzi wprost

**Dlaczego 8 równoczesnych wejść na listing daje HTTP 500.** To nie awaria usługi, tylko załamanie z przeciążenia
(congestion collapse), złożone z trzech rzeczy:

1. Każde zimne wejście na listing liczy wszystkie fasety agregacjami `nested` po wariantach i parach oś–wartość.
   Fasety to ok. 96% czasu zapytania, a koszt rośnie liniowo z liczbą pasujących produktów. Cały katalog 1 mln:
   ok. 0,5 s CPU jednego rdzenia tej maszyny na żądanie [POMIAR]. Na worf.pl to samo zapytanie trwa ok. 1,6 s przy
   1 shardzie i ok. 0,5 s przy 4 shardach (pomiar #928 po odjęciu 0,22 s sieci), czyli jest blisko limitu już bez
   równoległości; 4 shardy skracają czas, ale zużywają 1,7–1,8 raza więcej CPU. [POMIAR]
2. Klient Elasticsearcha w API ma limit 2 s. Czas odpowiedzi to w przybliżeniu większa z dwóch wartości: czas
   pojedynczego zapytania albo (liczba równoległych zapytań × CPU zapytania) / dostępne rdzenie. Gdy ta druga
   przekroczy 2 s, wszystkie równoległe żądania padają naraz: API odpowiada 503, sklep zamienia to na 500 całej
   strony. [KOD, GRAFANA, POMIAR lokalny; dla serwera mechanizm jest wnioskiem z pomiaru #928, nie osobnym pomiarem]
3. Żadna warstwa nie ogranicza współbieżności ani nie degraduje odpowiedzi. Przy przeciążeniu system wykonuje pełną
   pracę i nie oddaje nic: przy 32 równoległych listingach i Elasticsearchu ograniczonym do 3 rdzeni zmierzyłem
   100% odpowiedzi 503 (0 udanych), a strona produktu i `/health` w tym czasie czekały do 1,9 s na wątek PHP. [POMIAR]

Niewyjaśnione: w pomiarze #928 przy 8 równoległych wejściach sklep zwrócił 500 w 18–24 z 24 prób, a samo API 503
w 8 z 24. Możliwe przyczyny to inne strony w obu seriach i dodatkowe odczyty sklepu na jedno wejście; nie
sprawdzałem tego. [HIPOTEZA]

**Czy architektura nadaje się do 10 mln produktów.** Fundament tak, gorąca ścieżka listingu nie. Odczyty sklepu
z Postgresa kosztują 0,05–3 ms i rosną logarytmicznie, listy panelu mają stronicowanie kursorem, zapis zdarzeń jest
transakcyjny, Elasticsearch jest tylko modelem odczytu. Ale listing jest zaprojektowany tak, że koszt jednego żądania
rośnie liniowo z katalogiem. Przy 10 mln produktów zimne wejście na stronę główną to ok. 14–19 s na serwerze przy
1 shardzie i ok. 4–6 s przy 4 shardach [SZACUNEK], czyli zawsze 503. Samo dołożenie cache albo sprzętu tego nie
naprawi: potrzebna jest zmiana modelu facetów i ochrona przed przeciążeniem. Dla `medium` to trzy epiki (fasety,
ochrona, konfiguracja). Dla `server` to nie wystarcza: nawet tańszy model facetów liczy cały katalog 10 mln
w ok. 3,6–5 s na serwerze przy 1 shardzie [SZACUNEK], więc fasety bez filtrów (strona główna, działy) muszą być
liczone rzadko i współdzielone, strona główna powinna stracić fasety opcji, a obecny serwer nie mieści gorących
danych `server` w pamięci (sekcja 4).

**Najważniejsza rekomendacja.** Nie akceptować SEARCH-FACETS w kształcie „cache Symfony dla facetów”. Cache na
obecnym zapytaniu przesuwa koszt na zimne wejścia i wygaśnięcia. Najpierw tańsze fasety (pola na dokumencie produktu,
zmierzone 4-krotnie mniej CPU przy identycznych wynikach), rozdzielenie facetów od wyników i ochrona przed
przeciążeniem; cache dopiero na tym.

**Czego ten audyt nie potwierdził pomiarem:** ścieżki zapisu (koszyk, zamówienie) i tempa workerów nie mierzyłem
ponownie; liczby pochodzą z audytu z 2026-11-18 i z analizy kodu. Profilu `server` nie mierzył nikt.

## 1. Ocena stanu

| Obszar | Ocena | Jedno zdanie |
|---|---|---|
| Listing, wyszukiwanie, filtrowanie | **zła** | fasety `nested` przy każdym żądaniu, koszt liniowy z katalogiem, brak ochrony przed przeciążeniem [POMIAR] |
| Strona produktu, koszyk, oferty (Postgres) | bardzo dobra | 0,05–3 ms na zapytanie, bez N+1 i `OFFSET` [POMIAR] |
| Uruchomienie API (FrankenPHP) | słaba | tryb klasyczny, kernel i połączenie z bazą od zera przy każdym żądaniu, kolejka wątków bez limitu [KOD, POMIAR] |
| Konfiguracja Postgresa | słaba | same ustawienia domyślne, limit pamięci 2 GiB przy bazie ok. 70 GB dla `server` [KOD, SZACUNEK] |
| Sklep (Next.js) | dobry kod, słaba skalowalność | HTML listingów renderowany przy każdym wejściu, jeden proces, `replicas: 1` na sztywno [KOD, POMIAR] |
| Cache HTTP (Varnish) | poprawne łączenie żądań, brak odporności | 100 MB, grace 10 s, po 503 hit-for-miss na 120 s wyłącza łączenie żądań [KOD, POMIAR] |
| Panel | dobra z dwoma wyjątkami | filtr małej kategorii i `ILIKE` czytają cały katalog: 2–9 s na `medium` [POMIAR] |
| Procesy asynchroniczne | słaba przepustowość | jedno zdarzenie naraz, 12–40 zdarzeń/s na proces, e-maile za reindeksami [KOD, SZACUNEK] |
| Infrastruktura i zasoby | niedopasowana do `server` | limity pamięci odcinają cache plików, brak metryk wydajności [KOD, GRAFANA] |
| Obserwowalność wydajności | brak | w Grafanie nie ma metryk JVM, puli wątków ES, Postgresa, FrankenPHP, Varnisha ani kolejek [GRAFANA] |

Rekomendacje R1–R9 z audytu z 2026-11-18 (tryb worker albo trwałe połączenie, cache HTML listingów, fasety poza
gorącą ścieżką, konfiguracja Postgresa, zapytania panelu, przepustowość workera, Redis, Varnish) nie są wdrożone;
zrobione jest R6a (osobny worker komend) i część R10. [KOD, `findings/ash-process-backlog.md`]

## 2. Wyniki pomiarów

Narzędzie: `scripts/lt.py` (pętla zamknięta, N równoległych klientów, czas CPU kontenerów z cgroup). Wyniki surowe:
`results/*.jsonl`. Pomiary z cichej maszyny, chyba że zaznaczono.

### 2.1 API bez cache, pojedynczy klient i rosnąca współbieżność (1 shard)

| Żądanie | N=1: p50 | N=8: p50 / żądań/s | CPU Elasticsearcha na żądanie | CPU API + Postgres na żądanie |
|---|---|---|---|---|
| cały katalog (980 tys. trafień) | 519 ms | 515 ms / 15,5 | 489–514 ms | 10 + 4–7 ms |
| dział „Bieganie” (131 tys.) | 107 ms | 126 ms / 60,9 | 99–104 ms | 11 + 5 ms |
| dział + jedna opcja | 85 ms | 107 ms / 68,7 | 77–80 ms | 10 + 5 ms |
| fraza „buty biegowe” | 42 ms | 51 ms / 141,6 | 29–42 ms | 10 + 5 ms |
| kategoria-liść | 15 ms | 23 ms / 319,6 | 2–4 ms | 10 + 5 ms |
| strona produktu | 8 ms | N=16: 32 ms / 485,6 | 0 | 4,3 + 3,9 ms (N=16: 20,7 + 4,4 ms) |
| drzewo kategorii | 9 ms | N=16: 34 ms / 375 | 0 | 5,7 + 3,4 ms |

Cały katalog przy 16 klientach: 20,8 żądań/s, p95 963 ms, Elasticsearch zajmuje wtedy ok. 13 z 16 rdzeni. Listing to
ścieżka ograniczona CPU Elasticsearcha: przepustowość = liczba rdzeni / czas CPU zapytania. [POMIAR]

### 2.2 Odtworzenie błędu: Elasticsearch ograniczony do 3 rdzeni

Przybliżenie łącznej mocy CPU serwera (8 vCPU wolniejszych ok. 3 razy to ok. 3 rdzenie tej maszyny). Nie odtwarza
czasu pojedynczego zapytania na serwerze (tu 0,5 s, tam 1,6 s przy 1 shardzie), więc pokazuje mechanizm, a nie
liczby serwera: na worf.pl błędy zaczynają się przy mniejszej równoległości. Wiersze N=1–8:
`results/store-and-limit.jsonl`; wiersz N=32 i pomiary towarzyszące: `results/overload-es3cpu.txt`.

| N równoległych listingów całego katalogu | żądań/s | p50 | p95 | Statusy |
|---|---|---|---|---|
| 1 | 1,9 | 535 ms | 553 ms | 200 |
| 2 | 3,7 | 537 ms | 552 ms | 200 |
| 4 | 5,6 | 710 ms | 745 ms | 200 |
| 8 | 5,7 | 1406 ms | 1476 ms | 200 |
| 32 | 15,7 (0 udanych) | 2027 ms | 2082 ms | 320 × 503 |

Przepustowość zatrzymuje się na 5,7 żądań/s (3 rdzenie / 0,53 s). Powyżej ok. 11 równoległych czas przekracza 2 s
i udane odpowiedzi spadają do zera. W trakcie przebiegu z 32 klientami: `/health` p95 1888 ms, strona produktu p95
1910 ms (czekanie na wolny wątek PHP; mediany 15 i 23 ms); pula `search`: kolejka 0, odrzucenia 0; po zakończeniu
brak wiszących zadań wyszukiwania. [POMIAR]

Wcześniejsza seria (`results/api-ramp-all-1shard.jsonl`) była zakłócona pracą innych audytorów na tej samej bazie
i maszynie (CPU Elasticsearcha 1,9 s i Postgresa 0,36 s na żądanie zamiast 0,5 s i 7 ms), więc nie służy do liczb.
Pokazuje tylko ten sam przebieg awarii: przy 8 klientach 100% odpowiedzi 503 i 115 rdzenio-sekund Elasticsearcha
bez udanej odpowiedzi. Łańcuch błędu potwierdzają logi worf.pl z pomiaru 2026-11-25 14:14–14:25 UTC:
`Elasticsearch is unreachable (search): Idle timeout reached` → `ServiceUnavailableHttpException`
w `ListProductsController.php:129` → w sklepie `GET /api/v1/products failed with status 503`. [GRAFANA]

### 2.3 Elasticsearch: co kosztuje i co daje zmiana modelu (kopia indeksu `medium`, `findings/elasticsearch.md`)

| Składnik zapytania całego katalogu | Czas |
|---|---|
| 24 wyniki z sortowaniem i dokładnym `total` | 2–31 ms |
| faseta opcji (`nested` → `nested` → `terms` osi i wartości, dwa `reverse_nested`) | 456 ms |
| faseta kategorii (`terms` z `include`) | 145 ms (jako `filters`: 10 ms) |
| zakres cen, dostępność | 78 ms, 45 ms |

Model alternatywny zmierzony na kopii: pola na dokumencie produktu (`facet_axes`, `facet_options`, `purchasable`,
`max_gross`) i `nested` tylko wtedy, gdy zapytanie ma warunek na wariant. Wyniki identyczne w 10 z 10 scenariuszy
(liczone produkty, nie warianty; warunek „ten sam wariant” zachowany: 8 614 produktów wobec 20 214 przy dowolnych
wariantach).

| Zapytanie | Obecny model | Model z polami produktu (1 shard / 4 shardy) |
|---|---|---|
| cały katalog | 591 ms | 143 / 38 ms |
| dział | 129 ms | 33 / 10 ms |
| liść | 21 ms | 5 / 3 ms |
| fraza | 33 ms | 9 / 6 ms |
| jedna wybrana oś (wariant rozszerzony, poza testem równoważności) | 87 ms | 53 / 17 ms |
| z ceną albo dwiema osiami w dziale | ok. 110 ms | bez zmian |
| cały katalog z filtrem opcji i „tylko dostępne” | 357 ms | 369 ms (bez zmian) |
| CPU na zapytanie całego katalogu | 687 ms | 160 ms |
| przepustowość przy 2 rdzeniach, cały katalog | 2,5 zapytań/s | 11–13 zapytań/s |
| przepustowość przy 2 rdzeniach, mieszanka 10 scenariuszy | 5–6,6 | 17–20 |

Pozostałe wyniki: koszt rośnie liniowo (1 → 3 mln produktów: ×2,96–3,06); 4 shardy skracają pojedyncze zapytanie
2–4 razy, ale kosztują 1,7–1,8 raza więcej CPU, więc przy nasyceniu przepustowość jest ta sama (zgodne z pomiarem na
worf.pl z #928); kolejka, odrzucenia, GC i globalne ordinals nie są przyczyną; request cache dla `size: 0` daje
208 → 3–7 ms, ale zapis jednego dokumentu go unieważnia. [POMIAR]

Granice tej zmiany: filtr „tylko dostępne”, zakres cen i wybrane opcje to warunki na wariant, więc zostają przy
`nested` i nie tanieją (sam warunek dostępności: 241 ms na całym katalogu). Przy 10 mln takie zapytanie na całym
katalogu to ok. 9–13 s na serwerze [SZACUNEK], w dziale kilka razy mniej. Przy więcej niż jednym shardzie `terms`
przestaje być dokładne, gdy shard ma więcej różnych osi albo wartości niż `shard_size` (opisane w komentarzu
`ProductSearchQuery.php` przy agregacji osi). Rozmiar indeksu obu modeli jest podobny (ok. 1 GB na 1 mln).

### 2.4 Sklep, pełna ścieżka

| Scenariusz | Wynik |
|---|---|
| zimny `/?page=K` (różne strony), 1 / 4 / 8 klientów | p50 584 / 610 / 675 ms; 1,7 / 6,5 / 11,7 stron/s; CPU: Elasticsearch 552–619 ms, sklep 19–40 ms, API 16–19 ms |
| ciepły `/` (Varnish trafia, HTML renderowany), 16 klientów | 106,9 stron/s, p50 139 ms, 11,2 ms CPU sklepu na stronę |
| ciepły dział `/c/bieganie`, 16 klientów | 105,6 stron/s, p50 149 ms |
| ciepła strona produktu (ISR), 16 klientów | 1196,7 stron/s, p50 12 ms, 0,9 ms CPU |

Sufit ciepłych listingów to ok. 100 stron/s na cały sklep: jeden proces Node przy ok. 11 ms CPU na stronę, bo HTML
listingu nie jest nigdzie cache'owany. Strona produktu z ISR jest 11 razy szybsza. [POMIAR] Varnish łączy
równoległe żądania tego samego cache'owalnego adresu (zachowanie wbudowane [KOD]; mój test tego nie potwierdził, bo
trafił w adres już obecny w cache), więc głównym problemem jest długi ogon różnych URL-i, a nie tłum na jednym.

### 2.5 Postgres i panel (`EXPLAIN (ANALYZE, BUFFERS)`, `findings/postgres.md`, `findings/admin.md`)

| Zapytanie | Wynik na `medium` | Prognoza dla 10 mln |
|---|---|---|
| ścieżki sklepu (strona produktu, oferty 48 produktów, ceny, stany, zamówienia klienta) | 0,05–3 ms | bez istotnej zmiany |
| panel: lista produktów, cen i stanów z filtrem małej kategorii | 2,2–4,1 s, ok. 1 mln buforów; pod obciążeniem do 9,4 s | 20–90 s (liniowo) [SZACUNEK] |
| panel: `ILIKE '%termos%'` z sortowaniem po nazwie | 1,1–2,4 s | 10–25 s [SZACUNEK] |
| panel: fraza 2-znakowa | 0,3–1,5 s | kilka–kilkanaście s |
| granice porcji mapy strony (publiczny endpoint) | 0,55–1,3 s | 6–13 s |
| zamówienia, klienci, dashboard, historie | 0,05–45 ms | stałe |

### 2.6 Pozostałe pomiary

- Generator `medium` w compose: kroki danych 560 s (`results/generator-steps.txt`); `ordering:read-models:rebuild`
  wyczerpał 256 MB (tryb dev: DoctrineBundle przy `kernel.debug` zbiera każde zapytanie; nie dotyczy obrazu prod).
  [POMIAR, KOD]
- Pełny reindeks 1 mln produktów: 262 s (`results/generator-rest-steps.log`, 19:02:47–19:07:09; 3,8 tys.
  produktów/s lokalnie, w trybie dev); na worf.pl 849 produktów/s przy 0,26 rdzenia
  Elasticsearcha i 0,45 Postgresa. Sam Elasticsearch przyjmuje co najmniej 55 tys. produktów/s, więc ogranicza
  szeregowy proces PHP. [POMIAR, GRAFANA]
- worf.pl, 7 dni: brak limitów CPU i throttlingu, 0 restartów, szczyt CPU węzła 64% (okno 2 min), iowait do 18%,
  Elasticsearch do 3589 MiB z limitu 4096 MiB, Postgres do 357 MiB, dostępna pamięć węzła nie spadła poniżej 14 GiB.
  [GRAFANA]

## 3. Problemy

Waga według skutku przy 10 mln produktów i równoległym ruchu. Szczegóły, pliki i linie w raportach obszarów.

### Krytyczne

| ID | Problem | Przyczyna | Rodzaj | Dowód |
|---|---|---|---|---|
| P1 | Koszt listingu liniowy z katalogiem: fasety `nested` → `nested` z `reverse_nested` liczone przy każdym żądaniu, także bez warunku na wariant, na stronie 2+ i przy każdym sortowaniu | projekt mappingu i zapytania (`ProductSearchQuery.php:117-186`); wyniki i fasety w jednym żądaniu, cache per URL | architektura | [POMIAR] 2.1, 2.3 |
| P2 | Załamanie przy przeciążeniu: limit 2 s zamienia powolność w błąd, a nic nie ogranicza współbieżności; wolna wyszukiwarka zajmuje wszystkie wątki PHP i blokuje koszyk, checkout i `/health` | brak `max_wait_time` FrankenPHP, limitów połączeń w Varnishu i Traefiku, timeoutu w sklepie; jedna replika API | architektura, konfiguracja | [POMIAR] 2.2, [KOD] |
| P3 | Błąd wyszukiwarki to HTTP 500 całej strony, bez degradacji i bez kopii zapasowej | `load-catalog.ts:82-90` rzuca dla każdego statusu poza 400 i 404; brak `Suspense`; Varnish z grace 10 s i hit-for-miss 120 s po 503 (wyłącza łączenie żądań w szczycie) | kod, konfiguracja | [KOD], [GRAFANA] |
| P4 | Postgres na ustawieniach domyślnych (128 MB `shared_buffers`, bez limitów czasu, bez `pg_stat_statements`), limit 2 GiB; dla `server` baza ok. 70 GB, gorące indeksy sklepu ok. 9,5 GB | chart i compose nie przekazują żadnego parametru | konfiguracja | [KOD], [SZACUNEK] |

### Wysokie

| ID | Problem | Przyczyna | Rodzaj | Dowód |
|---|---|---|---|---|
| P5 | Limity pamięci odcinają cache plików: Elasticsearch ma ok. 1,5 GiB na indeks (gorący zbiór ok. 4 GB przy 10 mln), a 14 GiB węzła leży wolne | limit 4 Gi przy stercie 2 GiB, cgroup v2 wlicza cache plików | konfiguracja | [KOD], [GRAFANA], [SZACUNEK] |
| P6 | HTML listingów renderowany przy każdym wejściu w jednym procesie Node: ok. 100 stron/s na cały sklep; `replicas: 1` na sztywno; cache ISR i rewalidacja nie działają przy więcej niż jednej replice | trasy dynamiczne, brak cache HTML, `_helpers.tpl:245` | architektura | [POMIAR] 2.4, [KOD] |
| P7 | API: kernel Symfony, połączenie z Postgresem (SCRAM) i połączenie z Elasticsearchem od zera przy każdym żądaniu; 10 ms CPU API na listing niezależnie od pracy, przy 16 klientach 21 ms na stronę produktu | tryb klasyczny FrankenPHP, brak trwałego połączenia | konfiguracja | [POMIAR] 2.1; audyt z 18.11: 3–7 razy większa przepustowość po zmianie |
| P8 | Zmiana drzewa kategorii czyści cały Varnish i cały ISR, dwa razy; przy P1 cały sklep jest naraz zimny | twardy purge tagu `catalog` przy zdarzeniu i po reindeksie (`ReindexCategoryBatchHandler.php:76-79`) | kod | [KOD] |
| P9 | Przetwarzanie zdarzeń po jednym: 6–9 zapytań SQL, jeden zapis do Elasticsearcha, jeden PURGE i jedna rewalidacja na zdarzenie; jeden proces; e-maile w tej samej kolejce za reindeksami; outbox blokuje `events` | brak przetwarzania wsadowego i podziału kolejek | architektura | [KOD], [SZACUNEK] 12–40 zdarzeń/s; pomiar z 18.11: 17/s |
| P10 | Brak operacji masowych: promocja ma limit 1000 wariantów, reindeks kategorii idzie produkt po produkcie (dział 20% z 10 mln to 2 mln pojedynczych zapisów, 1–2 h) | brak ścieżki zbiorczej | kod | [KOD], [SZACUNEK] |
| P11 | Panel: filtr kategorii i `ILIKE` z jednym stałym planem czytają cały katalog; brak `statement_timeout`; panel nie anuluje poprzednich żądań; wolne zapytania zajmują wątki wspólne ze sklepem | zapytanie nie dobiera planu do liczby trafień (`DbalBackofficeProductSource.php:35-43`) | kod | [POMIAR] 2.5 |
| P12 | Brak metryk i alertów wydajności; `make -C infra/k8s load` mierzy tylko ciepłą ścieżkę na 60 produktach, z rozgrzewką, na 1 shardzie | lista dozwolonych metryk, scenariusze k6 | proces, konfiguracja | [GRAFANA], [KOD] |
| P13 | Pełny reindeks szeregowy: 10 mln to ok. 2,8–3,3 h; zapisy w trakcie przebudowy przepadają w nowym indeksie | jeden proces PHP, brak podwójnego zapisu | kod | [POMIAR], [KOD], [SZACUNEK] |
| P14 | Dysk: `server` przy reindeksie zajmie ok. 125–175 GB ze 193 GB (próg Elasticsearcha 85%); wolumen Postgresa w chartcie 10 Gi | rozmiar danych | sprzęt | [SZACUNEK] |

### Średnie i niskie

- Limit połączeń: `max_connections` 100 wspólne dla API (połączenie na żądanie), workerów, zadań i Keycloaka (pula
  domyślnie do 100). Ryzyko `too many clients`. [KOD, HIPOTEZA]
- Pamięć: 16 wątków PHP × 256 MB przy limicie poda 2 GiB; Redis bez `maxmemory` przy limicie 512 MiB (sesje
  logowania); imgproxy 16 równoległych przetworzeń przy 1 GiB. [KOD]
- Granice mapy strony liczone funkcją okna po całym kluczu: 0,55–1,3 s, endpoint publiczny. [POMIAR]
- `ordering_order` ma 12 indeksów (752 MB przy tabeli 439 MB); aktualizacje stanów i klientów nie są HOT; sześć
  zdublowanych indeksów. [POMIAR]
- Checkout: kod rabatowy z limitem i popularny wariant zablokowane do końca transakcji. [KOD, HIPOTEZA]
- Zimna ścieżka `/c/...`: trzy odczyty po kolei (drzewo → lista → oferty). [KOD]
- Każde 503 wysyła synchronicznie zdarzenie do Sentry w trakcie żądania. [HIPOTEZA]
- Varnish: 100 MB wspólne dla listingów i fraz; kolejność parametrów `o=` tworzy różne klucze. [KOD]
- `variants.pairs` daje 2,2 raza więcej dokumentów Lucene bez zysku. [POMIAR]

### Co jest zrobione dobrze i czego nie ruszać

Stronicowanie kursorem i `BoundedCounter`, odczyty zbiorcze bez N+1, tabele podsumowań sprzedaży i klientów, outbox
ze `SKIP LOCKED`, idempotencja, stała kolejność blokad, zamiana aliasu i `refresh_interval -1` przy przebudowie,
klient Elasticsearcha bez ponowień, okno 10 000 wyników, HTML niezależny od sesji (koszyk nie wyłącza cache), tagi
xkey per produkt, reindeks po zamówieniu tylko przy zmianie poziomu stanu. Nie warto: podnosić limitu 2 s, dodawać
`eager_global_ordinals`, `index.sort`, PgBouncera, partycjonowania ani przepisywać szyny zapytań.

## 4. Ocena architektury i skalowalności

**Czy nadaje się do 10 mln produktów.** Dziś nie. Po zmianach P1–P5 warstwa danych i zamówień tak, a listing tylko
pod warunkami z sekcji 5 (fasety bez filtrów liczone rzadko, strona główna bez facetów opcji, filtry wariantu
w kategorii) i na sprzęcie, który mieści gorące dane w pamięci. Nikt tego nie zmierzył.

**Błędy, które popełniliśmy.**

1. Listing zaprojektowano bez pytania „jak koszt rośnie z liczbą produktów”. Model „agregacje przy każdym żądaniu”
   jest poprawny dla tysięcy produktów; przy milionach wymaga facetów na dokumencie produktu i oddzielenia ich od
   stronicowania. Strona główna jako listing całego katalogu z facetami opcji to najdroższe zapytanie w systemie
   i najczęstsze wejście.
2. Cache potraktowano jako rozwiązanie wydajności. Varnish z TTL 60 s i kluczem per URL ukrywa koszt w testach na
   gorącym zbiorze i nie pomaga na długim ogonie.
3. Limit czasu bez ograniczenia współbieżności. Limit 2 s chroni pojedyncze żądanie, a pogarsza zachowanie całości.
4. Ustawienia uruchomieniowe nigdy nie były decyzją: tryb FrankenPHP, Postgres, limity pamięci, repliki, Varnish.
5. Skala była celem deklarowanym, a nie testowanym: brak wymagań liczbowych, CI na 191 produktach.

**Co uprościć.**

- Strona główna bez facetów opcji całego katalogu (same kategorie i lista); to decyzja produktowa, usuwa najdroższe
  zapytanie.
- Jeden poziom `nested` zamiast dwóch (`variants.pairs` do usunięcia).
- Jedna liczba shardów dobrana do przepustowości (1–2), nie 4 dla czasu pojedynczego zapytania.
- Jeden model cache listingów zamiast nakładania kolejnej warstwy: Varnish dla odpowiedzi API z dłuższym grace
  i kluczem facetów bez strony i sortu; bez osobnego cache Symfony.
- Rewalidacje i purge grupowane po tagach (endpoint przyjmuje 100 tagów).

**Optymalizacja czy przebudowa.** Przebudowy wymaga model facetów (mapping, zapytanie, reindeks) i przetwarzanie
zdarzeń (wsadowe). Reszta to konfiguracja i zmiany punktowe.

**Kod czy sprzęt.** Błędy przy 8 wejściach to kod i konfiguracja: nawet nieskończenie szybki serwer miałby ten sam
problem przy 10 razy większym katalogu. Sprzętem są ograniczone: pamięć dla `server` (cache plików bazy i indeksu)
i dysk.

**Zasoby dla zakładanej skali.** [SZACUNEK]

- `server` na obecnym serwerze (8 vCPU, 22,9 GiB, 193 GB) nie mieści gorących danych w pamięci przy obecnych
  zasadach. Gorący zbiór to ok. 9,5 GB indeksów sklepu w Postgresie i ok. 4 GB indeksu Elasticsearcha plus sterta
  2 GiB, razem ok. 16 GiB. Limity pozostałych usług w `prod` to ok. 11 GiB, system ok. 3 GiB, a chart dopuszcza
  20 GB sumy limitów. Faktyczne zużycie pozostałych usług jest dużo niższe (pody poza Elasticsearchem ok. 2,5 GiB,
  system ok. 2,1 GiB [GRAFANA]), więc fizycznie ok. 18 GiB jest do rozdania, ale tylko przy sumie limitów ponad
  pamięć węzła. To decyzja właściciela: (a) dopuścić taki nadprzydział dla demo, (b) uruchomić `server` z danymi
  czytanymi z dysku i zaakceptować wolniejsze zimne odczyty, (c) serwer z co najmniej 32 GiB. Dysk: 125–175 GB
  z 193 GB w trakcie reindeksu, czyli poniżej 20% wolnego miejsca i blisko progu Elasticsearcha 85%.
- 10 mln produktów z realnym równoległym ruchem: co najmniej 16 vCPU, 64 GiB, NVMe 400 GB, najlepiej 2–3 węzły
  (Elasticsearch, Postgres, aplikacje osobno), i więcej niż jedna replika sklepu i API.
- 60 tys. jednocześnie aktywnych użytkowników to inny rząd wielkości (wiele replik, CDN dla HTML); nie do oceny bez
  pomiaru po naprawie P1–P7.

**Przepustowość obecnego serwera (`medium`, stan dzisiejszy).**

| Ścieżka | Oczekiwanie | Podstawa |
|---|---|---|
| zimny listing całego katalogu | ok. 2–3 na sekundę; przy 8 równoległych błędy | pomiar worf.pl #928: 4 równoległe 1,38 s, 8 → 503 |
| zimne listingi mieszane (liście, frazy, działy) | 5–7 na sekundę | [SZACUNEK] z 2.3 przy 2 rdzeniach; dział na worf.pl trwa 0,34–0,54 s |
| ciepłe listingi (HTML renderowany) | 30–40 stron/s | [SZACUNEK] 107/s lokalnie ÷ 2,7 |
| ciepła strona produktu (ISR) | kilkaset stron/s | [SZACUNEK] |
| zdarzenia (reindeks, e-mail) | 12–40 na sekundę | [KOD], pomiar z 18.11 |

**Czy technologie są używane zgodnie z przeznaczeniem.** Elasticsearch: `nested` do filtrów tak, do facetów bez
warunku na wariant nie. FrankenPHP: tryb klasyczny pomija jego główną zaletę. Next.js: ISR na stronie produktu tak,
listingi jako czysty SSR bez cache nie. Varnish: łączenie żądań tak, grace i obsługa błędów nie. Messenger:
natywny `BatchHandlerInterface` nieużywany. Postgres: zapytania tak, konfiguracja nie.

## 5. Proponowane wymagania wydajnościowe

Czas mierzony na serwerze (od wejścia żądania do pierwszego bajtu, bez sieci klienta), na serwerze odniesienia
8 vCPU / 24 GiB. „Zimne” znaczy bez żadnego trafienia cache. „Obciążenie nominalne” to połowa zmierzonej
przepustowości danej ścieżki. Wartości nie są dopasowane do obecnych wyników: punktem odniesienia jest to, że klient
odczuwa odpowiedź do ok. 1 s jako płynną. To propozycja do kalibracji przez właściciela, nie wynik pomiaru.

Osiągalność według dowodów:

- `medium`: progi listingu są osiągalne z modelem pól produktu (cały katalog 143 ms lokalnie, ok. 0,45 s na
  serwerze przy 1 shardzie [SZACUNEK]) pod warunkiem, że fasety bez filtrów są współdzielone między stronami
  i sortowaniami.
- `server`: progi listingu NIE są osiągalne samym modelem pól produktu (cały katalog ok. 3,6–5 s na serwerze
  [SZACUNEK]). Wymagają jednocześnie: facetów bez filtrów liczonych rzadko (poza żądaniem albo z cache w minutach),
  strony głównej bez facetów opcji i ograniczenia filtrów wariantu do kategorii; zapytania z filtrem wariantu na
  całym katalogu 10 mln nie zmieszczą się w 1 s na 8 vCPU przy żadnym z badanych modeli.
- Zamówienia: próg 20/s nie ma pomiaru w tym audycie (audyt z 18.11: e-mail potwierdzenia 17/s na jednym workerze).

| Ścieżka | `medium` p50 / p95 / p99 | `server` p50 / p95 / p99 |
|---|---|---|
| listing, wyszukiwanie, filtrowanie: zimne | 150 / 400 / 800 ms | 250 / 600 / 1000 ms |
| listing: ciepłe | 30 / 100 / 200 ms | 30 / 100 / 200 ms |
| strona produktu: zimna / ciepła (p95) | 200 / 50 ms | 250 / 50 ms |
| koszyk: odczyt i zmiana | 50 / 150 / 300 ms | 50 / 150 / 300 ms |
| złożenie zamówienia | 150 / 500 / 1000 ms | 150 / 500 / 1000 ms |
| panel: listy i wyszukiwanie | 100 / 500 / 2000 ms | 150 / 800 / 2000 ms; twardy limit zapytania 5 s |

Przepustowość i współbieżność (obecny serwer):

- 50 równoczesnych zimnych wejść na listing (różne URL-e, w tym cały katalog i działy): 0 błędów 5xx, p95 ≤ 1 s
  (`medium`) i ≤ 2 s (`server`). To bezpośrednie kryterium dla dzisiejszego błędu.
- Zimne listingi mieszane: ≥ 20/s (`medium`), ≥ 10/s (`server`). Ciepłe strony: ≥ 200/s. Zamówienia: ≥ 20/s
  z e-mailem w ciągu 60 s.
- Przeciążenie (2 razy ponad przepustowość): udane odpowiedzi ≥ 80% wartości szczytowej, nadmiar odrzucany szybko
  (429 albo 503 z `Retry-After` w czasie poniżej 100 ms) albo obsłużony w trybie zdegradowanym; strona produktu,
  koszyk i `/health` bez wpływu (p95 w normie).

Błędy: 0 odpowiedzi 5xx poniżej przepustowości nominalnej; ≤ 0,1% w teście obciążeniowym.

Zasoby przy obciążeniu nominalnym: CPU węzła ≤ 60%, żaden kontener powyżej 80% limitu pamięci, sterta Elasticsearcha
≤ 75%, odczyty z dysku na ścieżkach sklepu bez wpływu na progi czasu (iowait węzła ≤ 5%), 0 restartów i OOM.

Operacje masowe i indeksowanie:

| Operacja | `medium` | `server` |
|---|---|---|
| zmiana ceny albo stanu widoczna w sklepie | p95 ≤ 10 s, p99 ≤ 60 s przy 50 zmianach/s | to samo |
| 100 tys. zmian cen | ≤ 15 min, e-maile zamówień bez opóźnienia ponad 60 s | to samo |
| zmiana nazwy albo przeniesienie działu | ≤ 10 min, bez zimnego całego sklepu | ≤ 30 min |
| pełny reindeks | ≤ 10 min | ≤ 60 min |
| generator danych | ≤ 30 min | ≤ 6 h |

Uzasadnienie progów reindeksu: Elasticsearch przyjmuje co najmniej 55 tys. produktów/s, więc ograniczeniem jest
liczba procesów czytających; 4 procesy przy obecnym tempie dają ok. 50 min dla 10 mln [SZACUNEK].

## 6. Wnioski dla ASH

Pełna analiza z osią czasu: `findings/ash-process-backlog.md`. Workflow nie został zmieniony.

**Dlaczego proces to przepuścił.**

1. Brak wymagania liczbowego. Wizja podaje wolumen (`vision.md:243-247`), żadna specyfikacja gorącej ścieżki nie ma
   budżetu czasu ani współbieżności. Na danych testowych błąd nie występuje, więc reviewer nie ma czego zgłosić.
2. Zasada z `CLAUDE.md` pilnuje twierdzeń, nie projektu. „Nie twierdź, że system spełnia wymagania” spełnia jedno
   zdanie w sekcji ryzyk (specyfikacja SEARCH od pierwszej wersji: fasety na 10 mln niepotwierdzone). Zasady
   „uwzględniaj skalę” żaden agent nie sprawdza; planner nie zalicza wydajności do ryzyka `CRITICAL`.
3. Żaden etap nie pracuje na danych w skali. CI ma 191 produktów; test wydajności facetów nie może się nie udać;
   testy planów z `enable_seqscan = off` przeszły przy wyszukiwaniu trwającym sekundy.
4. Ryzyka odraczano bez właściciela i warunku. Naprawiono tylko pozycje z twardym warunkiem „przed `generate server`”.
5. Ustawienia domyślne nigdy nie były decyzją (brak ADR dla trybu serwera, Postgresa, replik): to obserwacja. Że
   reviewer oceniający jeden diff nie widzi konfiguracji, której nikt nie zmienia, to [HIPOTEZA].
6. Pętla zwrotna pomija produkt: retro analizuje przebieg epiku, nie wydajność aplikacji. Audyt z 18.11 wskazał te
   same przyczyny; jego rekomendacje trafiły do jednego zbiorczego zgłoszenia #781 bez kolejności i terminu.

Obserwacja z osi czasu: pierwszy sygnał był 2026-11-10 (k6 na 191 produktach: p95 strony głównej 1117 ms wobec 119 ms
strony produktu), diagnoza 2026-11-18, błąd na serwerze 2026-11-25. Proces miał informację, nie miał bramki.

**Mechanizmy, do decyzji właściciela (koszt w nawiasie).**

- M1. Budżet gorącej ścieżki w specyfikacji jako kryterium akceptacji: czas, współbieżność, wzrost kosztu z wolumenem
  (10–20 min na specyfikację).
- M2. Pytanie „jak to rośnie z N” w planie i u `architecture-reviewer`, tylko dla zmian zapytań, indeksów, mappingu
  i cache (5–15 min na epik).
- M5. Zestaw „perf smoke” na `medium`, na żądanie i przed wdrożeniem, nie w każdym PR: kilkanaście zimnych żądań
  i test 50 równoległych; bramka bezwzględna (0 odpowiedzi 5xx, czas poniżej budżetu), bez progów regresji z małej
  próby (generowanie danych raz ok. 30 min, przebieg 5–10 min).
- M7. Każde znalezisko audytu z właścicielem i warunkiem „przed czym” (15 min na audyt).
- Uzupełniająco: M3 testy planów na 100 tys. wierszy bez sterowania planistą dla zapytań panelu (10–30 s CI),
  M6 jednorazowa decyzja o konfiguracji uruchomieniowej (ADR).

M1, M2, M5 i M7 razem złapałyby fasety, błąd 8 wejść i zapytania panelu [HIPOTEZA: ocena wsteczna, niesprawdzona
na żadnym epiku]. Nie złapią ustawień domyślnych, do tego jest M6. Test liczby zapytań (M4) nie złapałby żadnego z obecnych problemów, bo N+1 nie ma.

## 7. Rekomendacje i backlog

Efekt: zmierzony albo szacowany. Koszt: S (do 1 dnia pracy epiku), M (jeden epik), L (duży epik).

| Kolejność | Epik | Zakres | Efekt | Koszt | Zależy od |
|---|---|---|---|---|---|
| 1 | **LISTING-FACETS** (zastępuje SEARCH-FACETS) | pola facetów na dokumencie produktu, `nested` tylko przy warunku na wariant, usunięcie `variants.pairs`, faseta kategorii jako `filters`, fasety osobnym zapytaniem z kluczem bez strony i sortu, współdzielone i liczone rzadko dla zapytań bez filtrów; decyzje produktowe: strona główna bez facetów opcji, filtry wariantu tylko w kategorii; test równoważności wyników na danych z wieloma wariantami; kontrola dokładności `terms` przy liczbie shardów | CPU zapytania całego katalogu 687 → 160 ms, przepustowość mieszana 3 razy większa [POMIAR]; z usunięciem facetów opcji ze strony głównej jej koszt spada do kilkudziesięciu ms [SZACUNEK]; zapytania z filtrem wariantu nie tanieją | M–L | — |
| 2 | **OVERLOAD-PROTECTION** | limit równoległych zapytań wyszukiwarki i `max_wait_time` FrankenPHP, limit połączeń w Varnishu; listing zdegradowany (wyniki bez facetów albo kopia) zamiast 500; Varnish: grace w godzinach, krótki cache błędu zamiast hit-for-miss; timeout w sklepie (bez ponowień); miękki purge zamiast czyszczenia całego cache przy zmianie kategorii | przeciążenie przestaje być awarią: nadmiar odrzucany albo zdegradowany, koszyk i checkout odizolowane | M | część wyszukiwarki i klucz Varnisha po 1 albo w jednym epiku z 1; Varnish, FrankenPHP i sklep niezależnie |
| 3 | **RUNTIME-CONFIG** | Postgres (pamięć, WAL, `pg_stat_statements`, `statement_timeout`, `lock_timeout`, `/dev/shm`), limity pamięci Elasticsearcha i Postgresa pod cache plików, Redis `maxmemory`, rozmiar Varnisha, pula Keycloaka, liczba i pamięć wątków PHP, rozmiary wolumenów; decyzja o pamięci i dysku dla `server` (sekcja 4); ADR | warunek `server`; mniej I/O przy bazie większej niż pamięć | S | — |
| 4 | **PERF-GATE** | metryki (Elasticsearch, Postgres, FrankenPHP, Traefik) w ramach darmowego planu, scenariusz k6 „zimny długi ogon”, kryteria z sekcji 8 jako bramka przed wdrożeniem i przed `generate server` | diagnoza z metryk; regresje widoczne przed serwerem | S–M | 1, 2 |
| 5 | **API-RUNTIME** | trwałe połączenie z bazą albo tryb worker FrankenPHP po walidacji stanu między żądaniami | 3–7 razy większa przepustowość API (pomiar z 18.11) | M | 3 |
| 6 | **STORE-SCALE** | cache HTML anonimowych listingów (HTML nie zależy od sesji), konfigurowalne repliki, rewalidacja do wszystkich replik | sufit ciepłych listingów ponad 100 stron/s | M | 2 |
| 7 | **ASYNC-THROUGHPUT** | przetwarzanie wsadowe (`BatchHandlerInterface`, `_bulk`, wiele tagów w jednym żądaniu), osobna kolejka e-maili, repliki workera, alert zaległości `events`, wsadowy reindeks kategorii, równoległy pełny reindeks z podwójnym zapisem | zdarzenia z 12–40/s do setek/s [SZACUNEK]; reindeks 10 mln ok. 50 min | M–L | — |
| 8 | **ADMIN-QUERIES** | wybór planu według liczby trafień dla filtra kategorii i `ILIKE` (zmierzone 4,6–117 ms zamiast sekund), minimum 3 znaki, anulowanie żądań w panelu, granice mapy strony | panel poniżej 150 ms w najgorszych przypadkach | S–M | 3 |
| 9 | Porządki | indeksy zdublowane i nie-HOT, zbiorcze promocje ponad 1000 wariantów, retencja historii, leniwe trasy panelu | mniejszy koszt zapisu i rozmiar | S każdy | — |

Epik 3 jest niezależny od 1 i 2. Epiki 1 i 2 dotykają tych samych miejsc (zapytanie wyszukiwarki, klucz cache
listingu), więc idą po kolei albo jako jeden epik z dwoma etapami.

**Minimalna ścieżka do stabilnego `server`:** 1 → 2 → 3 (z decyzją o pamięci i dysku) → 4 (pomiar `medium` na
worf.pl: kryteria 1–4 i 8 z sekcji 8) → `generate server` za osobną zgodą → pomiar `server` (kryterium 9).
Epik 7 nie blokuje stabilności: reindeks 10 mln w ok. 3 h mieści się w limicie zadania (6 h). Kryteria 5–7
z sekcji 8 i próg 200 ciepłych stron/s wymagają epików 6, 7 i 8, więc nie należą do minimalnej ścieżki; do tego
czasu panel i operacje masowe pozostają wolne, ale nie wywracają sklepu, jeśli działa ochrona z epiku 2 i limity
czasu z epiku 3.

## 8. Kryteria uznania problemów za rozwiązane

Na worf.pl, najpierw `medium`, potem `server`, mierzone skryptem w repo, wynik w `docs/reports/`:

1. 50 równoczesnych zimnych wejść na listing (różne URL-e, w tym `/`, działy, strony głębokie, sortowania, filtry):
   0 odpowiedzi 5xx, p95 ≤ 1 s (`medium`), ≤ 2 s (`server`).
2. Pojedyncze zimne wejścia: progi z sekcji 5 dla listingu, wyszukiwania i strony produktu.
3. Przeciążenie 2 razy ponad zmierzoną przepustowość przez 2 minuty: udane odpowiedzi ≥ 80% szczytu, w tym czasie
   strona produktu, koszyk i złożenie zamówienia w normie, 0 restartów.
4. Elasticsearch niedostępny albo wolny: listing pokazuje wyniki zdegradowane albo kopię, nie 500.
5. Zmiana nazwy działu pod ruchem: brak skoku błędów, p95 listingu w normie.
6. 100 tys. zmian cen: sklep zgodny w 15 min, e-mail zamówienia złożonego w trakcie w ciągu 60 s.
7. Panel: filtr najmniejszej kategorii, fraza częsta, fraza bez wyników: p95 ≤ 500 ms, najwolniejsze ≤ 2 s.
8. Metryki z sekcji 5 („Zasoby”) widoczne w Grafanie i w normie podczas testów.
9. Po `generate server`: punkty 1–3 i 7 powtórzone; wolne miejsce na dysku ≥ 20% także w trakcie reindeksu.

## 9. Ograniczenia audytu

- Pomiary lokalne na jednej, współdzielonej maszynie; pierwsza seria współbieżności była zakłócona i służy tylko
  jako ilustracja. Liczby dla serwera przy 10 mln to ekstrapolacja liniowa z 1–3 mln i mnożnika 2,5–3,5 (cały
  katalog przy 1 shardzie: 1,64 s na worf.pl wobec 0,52 s tutaj, czyli ok. 3,2).
- Raport przeszedł krytyczną weryfikację w świeżym kontekście; jej uwagi (m.in. nieosiągalność progów `server`
  samym modelem pól produktu, rachunek pamięci, zależność epików 1 i 2, niepotwierdzony test łączenia żądań) są
  naniesione w tekście.
- Lokalny indeks miał 1 shard, worf.pl ma 4; różnicę opisuje pomiar na kopii indeksu (2.3) i raport #928.
- Symulacja serwera przez ograniczenie Elasticsearcha do 3 rdzeni nie odtwarza rywalizacji o CPU z innymi usługami.
- Nie mierzyłem ponownie: koszyka, zamówienia, workerów, trybu worker FrankenPHP, pamięci przy bazie większej niż
  RAM, nasycenia k6 pełnej ścieżki. Hipotezy do sprawdzenia są wypisane w raportach obszarów („Pomiary do wykonania”).
- Model facetów z polami produktu jest zmierzony na kopii indeksu skryptem, nie zaimplementowany w aplikacji;
  równoważność sprawdzono w 10 scenariuszach.
- Stos `shop-perf25` jest zatrzymany z zachowanymi wolumenami (dane `medium` na `2b4258c7`); kontener `es-audit`
  zatrzymany, z indeksami testowymi `t_orig1`, `t_orig4`, `t_flat1`, `t_flat4`.
