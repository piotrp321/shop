# Audyt wydajności wyszukiwarki: ustalenia cząstkowe (2026-11-25)

Audyt został zatrzymany na polecenie właściciela w trakcie pierwszego pomiaru, a prace nad SEARCH-FACETS
i przejściem demo na profil `server` są wstrzymane. Dokument zapisuje stan z tej chwili: pomiary cząstkowe i hipotezy,
które nie zostały sprawdzone. To nie jest zakończony audyt ani rekomendacja. Rewizja: `origin/main` `2b4258c7`; na
worf.pl wdrożona `a5056dbc`. W kodzie i na worf.pl nic nie zostało w ramach audytu zmienione.

## Zlecenie (właściciel, 2026-11-25)

Przed akceptacją SEARCH-FACETS niezależny audyt wyszukiwarki: mapping, dokumenty, zapytania, Profile API, zbędne
agregacje, natywny request cache, `size: 0`, `eager_global_ordinals`, konfiguracja (shardy, CPU, pamięć, throttling,
JVM, GC, kolejki), dobre praktyki z dokumentacji Elastic, ocena, czy cache Symfony to najlepsze rozwiązanie. Pomiary
lokalnie na `medium`. Tylko analiza i rekomendacja; bez implementacji i bez zmian na worf.pl.

## Środowisko pomiaru (lokalne)

- Kopia indeksu z audytu wydajności (wolumen `es-audit-data`, skopiowany z `shop-perfaudit_elasticsearch-data`;
  oryginał nietknięty). Kontener `es-audit` (zatrzymany, nie usunięty): obraz `shop-perfaudit-elasticsearch`
  (ES 9.4.0, wtyczka `analysis-stempel`), `--cpus=8 --memory=4g`, sterta `-Xms2g -Xmx2g`, port `127.0.0.1:19200`.
  Wznowienie: `docker start es-audit`.
- Indeks `products_20261118164738354_b3e480`: 1 shard, 0 replik, 8 891 973 dokumentów Lucene (ok. 1 mln produktów,
  ok. 3 mln zagnieżdżonych wariantów, ok. 4,9 mln zagnieżdżonych par oś–wartość), 1013 MB, 20 segmentów.
- Pula `search`: 13 wątków, kolejka 13 000, `allocated_processors` 8; GC: G1.
- Skrypt i wyniki: `docs/reports/search-performance-audit-partial-2026-11-25/` (`bench.py`, `listing-all.json`:
  zapytanie całego katalogu z raportu audytu z 2026-11-18, `breakdown-all.txt`: wyjście skryptu; `request_cache=false`,
  mediana z 9 po 2 rozgrzewkach).
- Sprzęt to Mac M4; worf.pl był w tych samych zapytaniach ok. 2,5–3,5 raza wolniejszy.

## Mapping (stan faktyczny)

`dynamic: strict`. Pola dokumentu produktu: `category_ancestor_ids`, `category_ids`, `main_category_id`, `slug`,
`status`, `id` (keyword), `name`, `short_description`, `category_names` (text, analizator `polish_text`, podpola
`folded`, `name.sort` keyword), `from_gross`, `version` (long), `main_image` (wyłączony). `variants` to `nested`
z `gross`, `options` (keyword), `purchasable` i drugim poziomem `nested`: `variants.pairs` (`axis`, `value`).
Bez `eager_global_ordinals`, bez ustawień `refresh_interval` i request cache (domyślne).

## Pomiar cząstkowy: rozbicie zapytania całego katalogu (lokalnie, 1 shard)

| Wariant | Mediana `took` |
|---|---|
| Pełne zapytanie (24 wyniki i wszystkie agregacje) | 490 ms |
| Same wyniki, dokładny `total` | 13 ms |
| Same wyniki, `track_total_hits: 10000` | 4 ms |
| Wszystkie agregacje, `size: 0` | 480 ms |
| Tylko `categories` | 57 ms |
| Tylko `available` | 24 ms |
| Tylko `price` | 36 ms |
| Tylko `free` (opcje: osie i wartości) | 289 ms |
| `free`, sortowanie po `_count`, `reverse_nested` jako metryka | 339 ms |
| `free` bez `reverse_nested` (liczy pary, nie produkty) | 215 ms |
| `free`, same osie z `reverse_nested`, bez wartości | 60 ms |
| `free`, `collect_mode: breadth_first` | 350 ms |
| `free`, `execution_hint: map` | niezmierzone (przerwane) |

Obserwacje (z tego jednego przebiegu):
- Agregacje to ok. 98% czasu zapytania; strona wyników kosztuje 13 ms.
- Agregacja opcji (`free`: `nested` → `nested` → `terms` osi → `terms` wartości, obie z `reverse_nested`) to ok. 60%
  kosztu agregacji. Większość tego kosztu to poziom wartości (289 ms wobec 60 ms dla samych osi).
- `reverse_nested` odpowiada za ok. jedną czwartą kosztu `free` (289 → 215 ms).
- Zmiana sortowania i `breadth_first` nie pomagają (339 i 350 ms).
- Dokładny `total` kosztuje 9 ms; to nie jest przyczyna.

## Hipotezy do sprawdzenia (niesprawdzone)

1. Natywny request cache Elasticsearcha dla osobnego zapytania facetów z `size: 0` może zastąpić cache Symfony:
   unieważnia się sam przy zmianie danych (bez okna 5 minut). Do zmierzenia: czas trafienia, czy równoczesne identyczne
   żądania liczą wynik raz, zachowanie przy odświeżaniu indeksu, limit rozmiaru (domyślnie 1% sterty).
2. Spłaszczenie facetów dla przypadku bez filtrów wariantu (pole keyword z parami oś–wartość na dokumencie produktu,
   zwykłe `terms` bez `nested` i `reverse_nested`) może dać wielokrotne przyspieszenie najczęstszych listingów
   (strona główna, kategoria). Wymaga zmiany mappingu i reindeksu; do zmierzenia przez `_reindex` ze skryptem na kopii.
3. `eager_global_ordinals` dla pól agregowanych: wpływ na pierwsze zapytanie po odświeżeniu.
4. Błędy przy 8 równoległych wejściach na worf.pl wyglądają na brak CPU, nie na kolejkę: jedno zapytanie całego
   katalogu to ok. 2 rdzenio-sekundy na worf.pl (0,69 s × 4 shardy liczone równolegle), więc 8 naraz na 8 vCPU
   przekracza limit 2 s. Do potwierdzenia pomiarem przepustowości i statystykami puli wątków.

## Nie zrobione

Profile API; zapytania działu, frazy i z wybranymi opcjami; request cache; przepustowość równoległa; statystyki JVM,
GC i puli wątków pod obciążeniem; porównanie 1 i 4 shardów lokalnie; przegląd dokumentacji Elastic (subagent
zatrzymany bez wyniku); raport i rekomendacja.

## SEARCH-FACETS (specyfikacja wstrzymana)

- `docs/features/search-facets/spec.md` i `plan.md`: wersje robocze, niezaakceptowane. F2 i F5 zaakceptowane; F1, F3,
  F4 uzupełnione po uwagach właściciela; całość czeka na wynik audytu i może się zmienić (hipotezy 1 i 2).
- Eksperyment z blokadą cache Symfony (`stampede-experiment.php` w katalogu wyników; FrankenPHP 1.13.5, PHP 8.5.12
  ZTS, symfony/cache 8.1.11, pula plikowa, liczenie wartości trwające 2 s):
  8 jednoczesnych żądań tego samego klucza → 1 liczenie; 8 różnych kluczy → 8 liczeń równolegle; 2 klucze na tym
  samym pliku blokady (1 z 24) → drugi czeka (3,8 s zamiast 2,0 s); liczenie kończące się wyjątkiem → 8 liczeń po
  kolei (16 s). Blokada nie działa między podami.
- Konsumenci pola `facets`: sklep (`CatalogListing`, `ListingFilters`, strony `/`, `/c/...`, `/szukaj`, fixtury)
  i `load/lib/data.js`; `e2e/tests/listing-filters.spec.ts` i `infra/k8s/smoke/checks.py` bez zmian.
- Limity czasu ścieżki: Elasticsearch 2 s (strona), PHP `max_execution_time` 30 s (czas zegarowy w FrankenPHP),
  Caddy treść 60 s, Varnish pierwszy bajt 60 s (domyślny), sklep bez limitu w kodzie.

## Pamięć usług (zebrane, bez PR)

- Węzeł worf.pl: 22,91 GiB RAM (tyle samo `allocatable`, bez rezerwy dla systemu i kubeleta), bez swapu. Dostępne
  teraz 14,9 GiB, minimum z 7 dni 14,0 GiB. Zajęte 8,0 GiB, z czego pody 5,9 GiB (shop 4,8, argocd 0,5, monitoring
  0,4, kube-system 0,2); reszta ok. 2,1 GiB to system, k3s i jądro. Cache plików 13,8 GiB.
- Elasticsearch: sterta 2 GiB (`heap size [2gb]` w logu), pamięć procesu 2885 MiB, working set do 3589 MiB z 4096 MiB
  (pomiar B). W logach z 7 dni: brak ostrzeżeń GC, brak wyłączników (circuit breaker), brak OOM. Metryk JVM w Grafanie
  nie ma.
- PostgreSQL: ustawienia domyślne (`shared_buffers` 128 MB), limit 2 GiB, pamięć procesu do 70 MiB, working set do
  357 MiB, cache plików do limitu. Uzasadnienie parametrów (współbieżność, generator: 4 procesy, Keycloak) niezrobione.
- Suma limitów pamięci w `prod`: sklep 16,9 GiB, z monitoringiem ok. 18,3 GiB; test chartu dopuszcza 20 GiB.

## Stan worf.pl i repozytorium

- Wdrożona rewizja `a5056dbc`, dane `medium`, indeks z 4 shardami (reindex 2026-11-25), sklep otwarty.
- `main`: #936 (poprawka #922), #939 (ISR-TAG-LIMIT), #945 (profil `server`), #955 (raport shardów). Zgłoszenia:
  #954 (błędy listingu), #938 (odroczone uwagi).
- Warunki `server`: spełnione L9, `tagsManifest` (D4), #922, profil, pierwsze wdrożenie po `medium`, miejsce na dysku;
  otwarte: wydajność listingu (ten audyt, R4), pamięć usług, zamówienia z #908, zgoda na `generate`.
- Pomiary listingu na serwerze (1 i 4 shardy): `docs/reports/search-shards-worf-2026-11-25.md`.
