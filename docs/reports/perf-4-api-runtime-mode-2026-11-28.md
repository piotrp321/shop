# PERF-4: tryb uruchomienia API, analiza do decyzji (2026-11-28)

Rewizja: `7fd4d9d2` (main). Cel: dać właścicielowi podstawę do decyzji o PERF-4 „Tryb uruchomienia API”
(`docs/features/performance-program/plan.md`) bez przesądzania o trybie worker FrankenPHP. Raport nie jest
specyfikacją i niczego nie zmienia w aplikacji; prototyp działał poza repozytorium, a jego pliki i surowe wyniki są
w `perf-4-api-runtime-mode-2026-11-28/`.

Raport rozdziela **potwierdzone** (zmierzone albo odczytane z kodu i zależności) od **hipotez** (niesprawdzone).

## 1. Wynik w skrócie

- **Potwierdzone:** oba warianty usuwają koszt P7 (jądro i połączenie z Postgresem przy każdym żądaniu). Przy 16
  klientach i 4 rdzeniach dla API przepustowość względem trybu klasycznego: strona produktu ×7–11 (same trwałe
  połączenia PDO) i ×14–22 (worker), koszyk do odczytu ×4–6 i ×7–13, zapis do koszyka ×1,1–1,4 i ×2,9–3,0, listing
  ×1,9–2,2 i ×3,3–5. CPU Postgresa na żądanie spada w obu z 3,5–6,8 ms do 0,3–1,7 ms.
- **Potwierdzone:** kod API jest prawie gotowy na worker: Symfony Runtime 8.1 obsługuje go bez nowej zależności,
  serwisy nie trzymają stanu żądania poza `CorrelationId` (resetowanym), Sentry i Doctrine resetują się same. Główne
  ryzyko to połączenie z Postgresem, które przeżywa żądanie: po zerwaniu połączeń pierwsze żądanie każdego wątku kończy
  się 500 (worker 16 z 64, trwałe PDO 8 z 64, klasyczny 0).
- **Rekomendacja (§6):** wariant A bez worker mode (trwałe połączenia PDO z ochroną przed martwym połączeniem) jako
  pierwszy krok PERF-4; worker tylko wtedy, gdy po A pomiar na pełnym profilu `server` pokaże, że API ogranicza
  zapis koszyka i zamówienia (W5, W6, W10).
- **Brakuje linii bazowej dla kryterium PERF-4:** raport z 2026-11-26 nie mierzył przepustowości API strony produktu
  (rampa szła przez Varnish i nie nasyciła API) ani koszyka (zapisów nie uruchamiano). Kryterium „×3 względem linii
  bazowej” wymaga najpierw pomiaru trybu klasycznego tym samym narzędziem co pomiar po zmianie (lokalnie na pełnym
  profilu `server`, zasada 3 planu).

## 2. Kompatybilność z trybem worker (potwierdzone w kodzie i w `vendor` obrazu `prod`)

Wersje: FrankenPHP 1.13.6 na PHP 8.5.13 ZTS (`apps/api/Dockerfile`), `symfony/runtime` 8.1.12,
`doctrine/doctrine-bundle` 3.x, `sentry/sentry-symfony` 5.16.0. `symfony/runtime` ma wbudowany
`FrankenPhpWorkerRunner`: włącza się, gdy FrankenPHP poda `FRANKENPHP_WORKER`, restartuje skrypt po
`FRANKENPHP_LOOP_MAX` żądaniach (domyślnie 500) i woła `gc_collect_cycles()` po każdym żądaniu.
`public/index.php` jest już w formacie Runtime. Zmiana to blok `worker` w `Caddyfile`.

| Element | Stan w `apps/api` | Ryzyko w trybie worker |
|---|---|---|
| pola statyczne | jedno: `DemoData/Verify/BlankText::$visible` (stała wyliczana raz, tylko konsola) | brak |
| serwisy ze stanem | `CorrelationId` (`ResetInterface`); `CorrelationIdWorkerSubscriber` i `RoutedAmqpTransport` tylko w workerach Messengera. Poza nimi `src` nie przypisuje `$this->…` poza konstruktorem, większość serwisów to `final readonly class` | niskie dziś; nowy serwis bez `ResetInterface` przeniesie dane jednego klienta do żądania następnego |
| `EntityManager` po wyjątku | zapisy idą przez `command.bus` z `doctrine_transaction`; nieudany `flush` zamyka EM, a `Registry::reset()` (kernel.reset) czyści otwarty EM i odtwarza zamknięty | niskie: ten sam mechanizm działa dziś w `worker` i `worker-commands` dla tych samych handlerów |
| mapa tożsamości | czyszczona przy resecie; repozytoria zamówień, płatności i koszyka i tak czytają wiersz na nowo pod `FOR UPDATE` | niskie |
| blokady Postgresa | ścieżki HTTP mają tylko blokady transakcyjne (`pg_advisory_xact_lock`, `pg_try_advisory_xact_lock`, `FOR UPDATE`); jedyna blokada sesyjna (`PostgresSearchIndexRebuildLock`) jest w komendach konsoli | brak dla HTTP |
| połączenie z Postgresem | jedno połączenie DBAL na wątek, trzymane między żądaniami; DoctrineBundle zamyka je po `idle_connection_ttl` (600 s) | **wysokie**: martwe połączenie daje 500 (§3.3); otwarta transakcja przeżyje żądanie (§4) |
| Elasticsearch | `ElasticsearchClientFactory` jest już przygotowany na długie procesy (węzeł nigdy nie zostaje „martwy”, 0 ponowień); HttpClient trzyma keep-alive | korzyść |
| Redis (limity, blokady) | połączenie leniwe, w trybie klasycznym nowe przy każdym żądaniu (§3.4) | korzyść: w workerze połączenie zostaje |
| Monolog, Sentry | `fingers_crossed` resetowany przez MonologBundle; Sentry 5.16 ma `RuntimeContextListener`, który izoluje kontekst każdego głównego żądania (wprost dla FrankenPHP) | niskie |
| bezpieczeństwo | token storage resetuje Symfony; klucze OIDC w puli plikowej `cache.oidc` | niskie |
| pamięć | po obciążeniu: klasyczny 111 MiB, trwałe PDO 115 MiB, worker 181 MiB (16 wątków workera) przy limicie poda 2 GiB | niskie; trend sprawdza W14 |

## 3. Korzyść: pomiar lokalny

### 3.1 Warunki

- Obraz `prod` z rewizji `7fd4d9d2`, trzy kontenery obok małego stosu demo (`make up`, `make seed`, 191 produktów), po
  `--cpus=4` i `num_threads 16`: **klasyczny** (dzisiejszy), **trwałe PDO** (`PDO::ATTR_PERSISTENT` w `doctrine.dbal.options`),
  **worker** (`worker { file /app/public/index.php; num 16 }`, `FRANKENPHP_LOOP_MAX` domyślne 500). Limity koszyka
  podniesione tylko w prototypie (`bench.yaml`), bo k6 ma jeden adres.
- k6 bezpośrednio do API (bez Varnisha): `GET /api/v1/products/{slug}` (20 produktów), `GET /api/v1/guest-carts/{id}`,
  `PUT …/items/{variantId}` (koszyk na klienta), `GET /api/v1/products?category=…` (dział, 3 strony). N=1 przez 20 s,
  N=16 przez 30 s; CPU z `cpu.stat` cgroup kontenerów API, Postgresa i Elasticsearcha, podzielone przez liczbę żądań.
- Dwa przebiegi. Na tym samym hoście działały stosy innych sesji, więc rozrzut między przebiegami sięga 40%; tabela
  podaje oba wyniki. Mały katalog: czas Elasticsearcha i Postgresa nie odpowiada 10 mln produktów, a koszt trybu
  uruchomienia od danych nie zależy.

### 3.2 Wyniki (przebieg 1 / przebieg 2)

| Ścieżka | Wariant | N=1 p50 ms | N=16 żądań/s | N=16 p95 ms | CPU API ms/żądanie (N=16) | CPU Postgresa ms/żądanie (N=16) |
|---|---|---|---|---|---|---|
| strona produktu | klasyczny | 6,9 / 9,4 | 201 / 285 | 112 / 99 | 20,0 / 14,0 | 4,6 / 4,7 |
| | trwałe PDO | 2,1 / 2,2 | 2179 / 1989 | 12 / 14 | 1,6 / 1,8 | 0,3 / 0,3 |
| | worker | 0,9 / 1,3 | 4350 / 4072 | 6 / 8 | 0,7 / 0,8 | 0,3 / 0,3 |
| koszyk, odczyt | klasyczny | 8,5 / 11,3 | 196 / 306 | 109 / 95 | 20,3 / 12,9 | 5,3 / 5,2 |
| | trwałe PDO | 3,5 / 3,6 | 1091 / 1235 | 41 / 30 | 3,2 / 2,9 | 0,8 / 0,7 |
| | worker | 2,6 / 2,9 | 2618 / 2227 | 13 / 16 | 1,2 / 1,4 | 0,6 / 0,8 |
| koszyk, zapis | klasyczny | 11,7 / 16,1 | 388 / 326 | 103 / 119 | 9,2 / 9,9 | 5,1 / 5,7 |
| | trwałe PDO | 6,0 / 9,8 | 538 / 347 | 88 / 131 | 5,7 / 6,9 | 1,1 / 1,3 |
| | worker | 3,2 / 4,7 | 1114 / 980 | 55 / 59 | 2,1 / 2,2 | 1,0 / 1,1 |
| listing działu | klasyczny | 11,2 / 10,4 | 287 / 299 | 117 / 94 | 11,1 / 13,3 | 4,3 / 3,9 |
| | trwałe PDO | 5,7 / 6,1 | 541 / 663 | 66 / 62 | 5,9 / 5,9 | 0,4 / 0,4 |
| | worker | 4,0 / 5,2 | 1435 / 976 | 23 / 39 | 2,4 / 2,6 | 0,3 / 0,3 |

- **Potwierdzone:** w trybie klasycznym CPU API strony produktu rośnie z 4,3–5,5 ms przy N=1 do 14–20 ms przy N=16,
  jak w audycie (P7: 4,3 → 20,7 ms); w obu nowych wariantach nie rośnie. Postgres zużywa w trybie klasycznym 3,5–6,8 ms
  CPU na każde żądanie na samo zestawienie połączenia (SCRAM, nowy proces), w nowych wariantach 0,3–1,7 ms.
- **Potwierdzone:** zapis koszyka zyskuje na samych trwałych połączeniach najmniej (×1,1–1,4), bo zostaje koszt jądra
  Symfony i transakcji; worker daje ×2,9–3,0.
- **Hipoteza:** wzrost CPU API w trybie klasycznym przy współbieżności to koszt SCRAM po stronie klienta i rywalizacja
  16 wątków o 4 rdzenie; nie profilowano.
- **Hipoteza:** na pełnym profilu `server` i na worf.pl (8 wolniejszych vCPU, API i Postgres na jednym węźle
  z Elasticsearchem) proporcje będą podobne, bo koszt jest stały na żądanie; liczby bezwzględne poda dopiero pomiar
  na `server` (lokalnie) i PERF-9 (worf.pl).

### 3.3 Zerwane połączenia z Postgresem (`resilience.sh`)

32 rozgrzewające żądania strony produktu, zakończenie wszystkich połączeń roli `shop` (`pg_terminate_backend`, tak
jak przy restarcie Postgresa albo `idle_in_transaction_session_timeout`), potem 2 × 64 żądania.

| Wariant | 64 żądania zaraz po zerwaniu | kolejne 64 |
|---|---|---|
| klasyczny | 64 × 200 | 64 × 200 |
| trwałe PDO | 56 × 200, 8 × 500 | 64 × 200 |
| worker | 48 × 200, 16 × 500 (jedno na wątek) | 64 × 200 |

- **Potwierdzone:** każdy wątek z martwym połączeniem kończy jedno żądanie błędem 500 (`ConnectionLost`), następne już
  łączy się na nowo. Trwałe PDO nie wykrywa martwego połączenia przed użyciem.
- **Hipoteza:** jeden przebieg k6 wystartował zaraz po takim zerwaniu i 64% jego żądań się nie udało (wiersz
  odrzucony z `results-predis.tsv`); najpewniej setup nie założył części koszyków, czego nie sprawdzono.
- **Potwierdzone (`t.php`):** trwałe PDO cofa transakcję pozostawioną przez żądanie, także po błędzie krytycznym PHP
  (połączenie wraca w stanie `idle`, nie `idle in transaction`).

### 3.4 Połączenia z Redisem przy dużej liczbie zapisów

- **Potwierdzone:** w trybie klasycznym i z samymi trwałymi PDO API otwiera nowe połączenie z Redisem dla blokady
  limitu przy każdym zapisie koszyka. Przy ok. 540 zapisach/s zabrakło portów efemerycznych (`Cannot assign requested
  address`, 4701 ostrzeżeń w 30 s) i blokada limitu przepuszczała żądania bez limitu (fail open z ABUSE-LIMITS, D1).
  Worker: 0 takich błędów. `persistent=1` w DSN Redisa w prototypie tego nie usunął (325 błędów); przyczyny nie badano.
- **Hipoteza:** na worf.pl ten sam limit (jeden adres poda API do jednego adresu Redisa) wystąpi przy podobnej liczbie
  zapisów na sekundę; dziś ruch zapisu jest dużo mniejszy.

## 4. Koszt dostosowania i ryzyko dla zamówień i płatności

| | Wariant A: trwałe PDO | Wariant B: worker |
|---|---|---|
| zmiana | opcja w `doctrine.yaml` (compose i chart, także CI i E2E) | blok `worker` w `Caddyfile`, `num_threads`, `FRANKENPHP_LOOP_MAX`; w compose `watch` albo osobny tryb dev, bo kod ładuje się raz |
| ochrona połączenia | wykrycie martwego połączenia przed pierwszym zapytaniem żądania i ponowne połączenie (test z `pg_terminate_backend`) | to samo |
| otwarta transakcja | PHP cofa ją na końcu żądania (§3.3) | nikt jej nie cofa: transakcja z `FOR UPDATE` na zamówieniu albo stanie zostaje na wątku do `idle_in_transaction_session_timeout` (60 s w PERF-1), a następne żądanie tego wątku dostaje 500; potrzebny strażnik przy `kernel.terminate`: rollback i błąd w logu (wniosek z modelu, nie zmierzony) |
| stan między żądaniami | nie dotyczy (jądro co żądanie) | test funkcjonalny z `disableReboot()`: dwa żądania różnych klientów na jednym jądrze (koszyk, zamówienie, `me`) i strażnik w przeglądzie: serwis z polem mutowalnym implementuje `ResetInterface` |
| walidacja | W13 po pomiarze zapisów | W13 i bieg W14 (2 h, pamięć i trend p95) |
| szacunek | 1 zadanie | 3–4 zadania i bieg W14 |

Ryzyko dla zamówień i płatności w obu wariantach dotyczy błędów, nie spójności: zamówienie i start płatności idą
w jednej transakcji z blokadami transakcyjnymi, więc żądanie przerwane przez martwe połączenie niczego nie zapisuje.
Klient widzi jednak błąd składania zamówienia; webhook dostawcy płatności jest ponawiany. W wariancie B dochodzą:
pozostawiona transakcja (blokady zamówienia do 60 s) i przeniesienie danych klienta przez serwis bez resetu.

## 5. Wpływ na PERF-1 (`docs/features/runtime-config/spec.md`)

- **Budżet połączeń:** w obu wariantach API trzyma stale tyle połączeń, ile ma wątków (zmierzone: 16 na kontener),
  a nie tylko w trakcie żądań. Budżet PERF-1 (API 16) mieści to bez zmiany, ale każda dodatkowa replika API to stałe
  +16. Martwe połączenia po restarcie Postgresa trzeba obsłużyć jak w §4.
- **`num_threads`:** w trybie worker FrankenPHP liczy wątki workera osobno: `num_threads 16` z `num 16` dało 32 wątki
  (16 workera i 16 zwykłych). Jawna wartość z PERF-1 (Z5) musi wtedy opisywać oba parametry; dla wariantu A bez zmian.
- **`statement_timeout`:** `PGOPTIONS` w środowisku poda ustawia limit przy zestawianiu połączenia, więc działa w obu
  wariantach. Ustawienie sesji w Doctrine przy połączeniu w wariancie A wykona się przy każdym żądaniu (DBAL łączy się
  na nowo z tym samym uchwytem), w wariancie B raz na połączenie. Przemawia to za `PGOPTIONS`.
- **`idle_in_transaction_session_timeout`:** w trybie klasycznym i A ma znaczenie tylko dla zabitego procesu,
  w wariancie B także dla pozostawionej transakcji (§4).

## 6. Rekomendacja

1. **Wariant A (bez worker mode) jako PERF-4:** trwałe połączenia PDO z ochroną przed martwym połączeniem. Lokalnie
   spełnia kryterium ×3 dla strony produktu i odczytu koszyka, usuwa koszt Postgresa z P7, nie zmienia modelu
   żądania i nie wymaga strażników stanu. Nie spełnia ×3 dla zapisu koszyka (×1,1–1,4).
2. **Przed implementacją:** zmierzyć tryb klasyczny lokalnie na pełnym profilu `server` jako linię bazową kryterium
   (strona produktu i koszyk bezpośrednio do API, z pominięciem Varnisha, skryptami z katalogu raportu); bez tego
   kryterium planu nie da się sprawdzić.
3. **Wariant B (worker) tylko na podstawie pomiaru po A:** gdy na `server` API ogranicza W5, W6 albo W10 (zapis
   koszyka i zamówienia ×2,9–3,0 w tym prototypie). Wtedy koszt z §4 i bieg W14.
4. **Niezależnie od wariantu:** zgłoszenie dla limitów przy dużym ruchu zapisu (§3.4): blokada limitu przepuszcza
   żądania, gdy zabraknie portów do Redisa. Do decyzji właściciela, czy w PERF-3, czy osobno.
