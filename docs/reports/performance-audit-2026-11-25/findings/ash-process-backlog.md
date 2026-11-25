# Dlaczego proces ASH przepuścił problemy wydajnościowe i backlog wydajności

Analiza tylko do odczytu na `main` `2b4258c7` (2026-11-25). Bez zmian w repo, GitHubie i na serwerze. Treść zgłoszeń
pobrana wyłącznie bramką `tools/agent-security/github_trust.py` (`issues --state all --limit 1000`, `issue <nr>`);
363 zgłoszenia, wszystkie od zaufanego autora. Oznaczenia: **[O]** obserwacja z dowodem, **[H]** hipoteza.

---

## A1. Definicje procesu: gdzie jest wydajność i skala

| Miejsce | Co mówi | Dowód |
|---|---|---|
| `docs/vision.md` „Skala docelowa” | Tylko wolumeny (10 mln produktów, 60 tys. użytkowników), „cele, do których architektura ma być przygotowana”, „takiego obciążenia nie uruchamiamy lokalnie ani w środowisku demo”. Brak czasu odpowiedzi, przepustowości, p95. | `docs/vision.md:243-247` |
| `docs/vision.md` | „Wymagania wydajności i dużych wolumenów … pozostają celem; prace nad nimi są w backlogu.” | `docs/vision.md:384-385` |
| Główny `CLAUDE.md` | „Uwzględniaj [skalę] przy istotnych decyzjach … nie wybieraj rozwiązań, które z definicji się nie skalują … Nie wprowadzaj przedwcześnie złożonych optymalizacji … Nie twierdź, że system spełnia wymagania wydajnościowe, dopóki nie potwierdzą tego testy obciążeniowe.” Zasada ogranicza **twierdzenia**, nie jest bramką. | `CLAUDE.md:83-88` |
| „Lista jakości” | Błędy API, komunikaty, Unicode, normalizacja, klawiatura, stany, przypadki brzegowe (paginacja i limity w sensie poprawności). **Brak punktu o wydajności, wolumenie, liczbie zapytań ani czasie.** | `CLAUDE.md:185-210` |
| `apps/api/CLAUDE.md` | „Przy problemie z wydajnością najpierw zmierz w SPX, potem optymalizuj” (reakcja, nie prewencja); reguły `BoundedCount`, brak `count(*)`, testy planów bez `Seq Scan`. | `apps/api/CLAUDE.md:446-461` |
| `apps/store/CLAUDE.md` | Jedyne liczbowe budżety w procesie: rozmiary plików WebP strony głównej (hero ≤ 250 KB itd.). | `apps/store/CLAUDE.md:474-476` |
| `infra/k8s/CLAUDE.md` | `load` z danymi innymi niż `seed` tylko za zgodą (czyli pomiar na skali to operacja ręczna). | `infra/k8s/CLAUDE.md:67-72` |
| `e2e/CLAUDE.md` | Lighthouse: blokuje tylko SEO. | `e2e/CLAUDE.md:38`, `e2e/lighthouse/lighthouserc.cjs:24-28` (performance, LCP, CLS, TBT = `warn`) |
| `.claude/agents/architecture-reviewer.md` | DDD, heksagonalna, zbędna złożoność, biblioteka zamiast kodu. Jedyne „skala” to „uzasadnij … skalą problemu” (wielkość uwagi, nie wolumen danych). Dodatkowo każe oceniać mechanizmy na zapas jako zbędną złożoność. | `architecture-reviewer.md:12-25` |
| `.claude/agents/code-reviewer.md` | Wymagania, logika, autoryzacja, współbieżność, migracje. `IMPORTANT` obejmuje „odpowiedź 500 na żądanie, które wysyła front”, ale tylko to, co widać na danych seeda. Brak kryterium złożoności względem wolumenu. | `code-reviewer.md:13`, `:22` |
| `.claude/agents/qa-adversarial.md` | Transakcje, współbieżność, bezpieczeństwo, dane; nic o czasie i wolumenie. | `qa-adversarial.md:3`, `:50-61` |
| `.claude/agents/developer.md` | TDD i lista jakości; nic o wydajności. | `developer.md:20` |
| `/brainstorm` | Ryzyka i konsumenci danych („modele odczytu, indeksy”), ale bez pytania o koszt względem wolumenu i bez budżetu w kryteriach akceptacji. | `.claude/skills/brainstorm/SKILL.md:19`, `:64-66`, `:78-79` |
| `/planner` | Ryzyko `CRITICAL` = auth, pieniądze, migracje, transakcje, współbieżność, bezpieczeństwo. Wydajność nie podnosi ryzyka zadania. | `.claude/skills/planner/SKILL.md:54-55` |
| `/retro` | Analizuje incydenty z przebiegu epiku (`input.json`, rekord metryk), nie błędy znalezione po wdrożeniu. | `.claude/skills/retro/SKILL.md:226-237` |
| ADR `agentic-software-house.md` | Budżety są tylko budżetami rund przeglądu i czasu CI. | `docs/adr/agentic-software-house.md:41`, `:120-140` |

**Wniosek [O]:** żaden agent ani skill nie ma obowiązku, kryterium ani narzędzia, żeby sprawdzić wydajność na danych
w skali. Jedyne progi wydajności w repo: `load/saturate.js:36-37` (p95 i błędy jako warunek przerwania rampy, czyli
narzędzie pomiaru, nie bramka), `load/smoke.js:16-22` (tylko poprawność: `checks`, `http_req_failed`), Lighthouse
`warn` (`lighthouserc.cjs:25-28`), `load/steady.js:27-32` (puste progi, tylko raportowanie). `make load-smoke` „measures
nothing and has no limits of speed” (`make/load.mk:7-10`).

## A2. Specyfikacje: gdzie powstały problemy

| Problem | Gdzie powstał | Wymaganie liczbowe? | Skala 10 mln rozważana? | Ryzyko znane i odroczone? | Dane testowe |
|---|---|---|---|---|---|
| Fasety `nested` + `reverse_nested` liczone przy każdym chybieniu cache, dokładne `total` | SEARCH, spec 2026-11-05 (`7eab9994`), epik #195 przez AO, 2026-11-07. Dziś `docs/features/search/spec.md:203`, `:226-245`; kod `ProductSearchQuery.php:36` (`track_total_hits => true`) | Nie. Jedyne „pomiar `took` … pod `SEARCH_PERF=1`, poza zwykłym `make test`” (`search/spec.md:431`) | Tylko jako zastrzeżenie: „Fasety na 10 mln produktów … nie są potwierdzone testami obciążeniowymi” (już w pierwszej wersji: `git show 7eab9994:docs/features/search/spec.md:390`; dziś `search/spec.md:470`). Brak analizy kosztu względem liczby trafień | Tak, odroczone bez właściciela, terminu i warunku | „setki produktów” (`search/spec.md:430-431`); `SearchFacetsPerformanceTest` domyślnie 5000 produktów, pomijany bez `SEARCH_PERF=1`, kończy się bez asercji (`docs/reports/app-audit-2026-11-18/09-testy.md:350-352`) |
| Mapping ES (`variants` nested, `pairs` nested w nested, ok. 2,4× dokumentów Lucene) | WALKING-SKELETON (2026-10-27) i SEARCH. `walking-skeleton/spec.md:409-413`, `search/spec.md:96`, `:471-474` | Nie | Przebudowa: „świadome ograniczenia szkieletu, a nie docelowe rozwiązanie dla katalogu rzędu 10 mln” (`walking-skeleton/spec.md:489`). Koszt zapytań przy 10 mln: nie | Tak, jako „Ograniczenia” | seed (191 produktów, `docs/reports/demo-scale-medium-worf-2026-11-20.md:21`) |
| Limit klienta ES 2 s → 503 | `apps/api/config/services.yaml:43` (`app.search.request_timeout: 2.0`) | Limit istnieje, ale brak budżetu zapytania względem niego | Nie | H1 w DEMO-DATA (2026-11-13): „limit zapytania 2 s” (`demo-data/spec.md:606-608`) | — |
| Głęboka paginacja bez górnej granicy | SEARCH / listing; H2 (`demo-data/spec.md:609-610`) | Nie | H2 jako hipoteza | Tak | — |
| ISR w pamięci procesu jednej repliki, brak cache HTML listingów | PRODUCT-PAGE A6 (`product-page/spec.md:54`: „współdzielony cache to osobne zadanie przy skalowaniu”); listingi `cache: "no-store"` (`search/spec.md:276`) | Nie | Częściowo (brak prerenderu 10 mln stron, `product-page/spec.md:217-218`) | Tak, „osobne zadanie” bez numeru | seed |
| Jedna replika wszystkich usług bezstanowych | DEPLOY (`deploy/spec.md:183`), `_helpers.tpl:245`, `:305` (`replicas: 1` na sztywno) | Nie | Poza zakresem (`deploy/spec.md:42`: „autoskalowanie”) | Tak | — |
| FrankenPHP w trybie klasycznym, połączenie z bazą na żądanie | Nigdy nie decydowano: `Caddyfile:33`, `:40` (`php_server` bez `worker`), `doctrine.yaml:1-9` (bez opcji trwałego połączenia). `git grep` „worker mode” w `docs/` – 0 trafień | Nie | Nie | Nie zapisano jako ryzyka przed audytem 18.11 | — |
| Postgres na ustawieniach domyślnych | Nigdy nie decydowano: `templates/postgres.yaml` (tylko `pg_isready`, brak `-c`), brak `statement_timeout`/`lock_timeout` w `apps/api/config` i chartcie | Nie | Pierwsze wzmianki dopiero w DEMO-DATA (`demo-data/spec.md:618`) i DEMO-SCALE (`demo-scale/spec.md:528`) | Tak, „brak `statement_timeout`” w ryzykach | — |
| Wyszukiwanie w panelu (trigram, `OR EXISTS`) | PRICING / CATALOG-ADMIN / ADMIN-FEATURES | ADMIN-FEATURES ma regułę jakościową: „żadne zapytanie … nie liczy `count(*)` … nic nie ładuje całej tabeli” (`admin-features/spec.md:25-27`) | „Wyszukiwanie trigramowe … przy 10 mln … Wydajność nie jest potwierdzona” (`pricing/spec.md:630-631`); H4 (`demo-data/spec.md:613-614`); „ta funkcja go nie zmienia” (`admin-features/spec.md:528-529`) | Tak, wielokrotnie | Testy planów z `SET LOCAL enable_seqscan = off` (`SearchBackofficeProductsTest.php:388`) na kilku wierszach: dowodzą, że indeks **może** być użyty, nie że planista go **wybierze** przy 1 mln (audyt: 7 ms – 4,3 s, `performance-audit-2026-11-18.md:66-67`) |
| Worker ~17 zdarzeń/s | WALKING-SKELETON (jeden `worker`, `walking-skeleton/spec.md:56`); SEARCH: „reindeksacja po każdej zmianie ceny … obciążenie workera. Bez kolejkowania” (`search/spec.md:463-464`) | Nie | Nie | Tak (`search/spec.md:463`, `async-reliability/spec.md:625`: „Wydajność transportów Doctrine przy docelowej skali dalej niepotwierdzona”) | seed |
| Inne „nie potwierdzone” | CATALOG-ADMIN (`catalog-admin/spec.md:457`), INVENTORY (`inventory/spec.md:478`), PRICING (`pricing/spec.md:629`) | Nie | Zastrzeżenia | Tak | — |

**Wniosek [O]:** w żadnej z przejrzanych specyfikacji nie ma liczbowego wymagania czasu odpowiedzi ani przepustowości
dla ścieżki gorącej. Skala 10 mln pojawia się w nich prawie wyłącznie jako zdanie „nie jest potwierdzone testami
obciążeniowymi” w „Ryzykach”, czyli dokładnie to, czego wymaga `CLAUDE.md:87-88`. Pierwszy dokument, który nazywa
konkretne wąskie gardła z liniami kodu, to DEMO-DATA z 2026-11-13 (H1–H5, `demo-data/spec.md:603-618`), osiem dni po
projekcie facetów. Wyjątkiem pozytywnym jest ADMIN-FEATURES (reguła o `count(*)` i testy planów), ale tam też bez
progu czasu i z testami na małych danych.

## A3. Oś czasu (z datami)

| Data | Zdarzenie | Dowód |
|---|---|---|
| 2026-10-27 | Szkielet: mapping `nested`, jeden worker, przebudowa „nie docelowa dla 10 mln” | `7c7df478`; `walking-skeleton/spec.md:489` |
| 2026-11-05 | Spec SEARCH: fasety `nested`/`reverse_nested` w każdym zapytaniu; ryzyko „fasety na 10 mln niepotwierdzone” | `7eab9994`; tamże `:390` |
| 2026-11-05 | Spec PRODUCT-PAGE: ISR w pamięci procesu, wspólny cache „przy skalowaniu” | `bf69eb05`; `product-page/spec.md:54` |
| 2026-11-07 | SEARCH zmergowany (AO, #195) z „testem” pomiaru bez asercji | `97179671` |
| 2026-11-08 | #290: decyzja o blokujących progach LCP/CLS – otwarte do dziś | issue #290 |
| 2026-11-10 | HARDENING-5/6: k6, `load-smoke` w CI bez progów, pomiar na **191 produktach**: p95 `home` 1117 ms, `category` 986 ms wobec `product` 119 ms; „Skala docelowa” poza zakresem | `afad437a`, `5f2d5531`; `load-test-2026-11-10.md:247-250`, `:291-296`; `hardening/spec.md:29` |
| 2026-11-10 | #418: analiza plateau CPU sklepu i wolnych list | issue #418 (zamknięte 18.11 jako zastąpione przez #781) |
| 2026-11-13 | DEMO-DATA: H1–H5 z liniami kodu, „hipotezy do sprawdzenia pomiarem” | `eb4c7d28`; `demo-data/spec.md:603-618` |
| 2026-11-17 | Audyt wydajności epików ADMIN-FEATURES i METRICS – dotyczy **czasu pracy procesu AO**, nie aplikacji (#698–#706, PR #677–#681) | `60a65ec9` |
| 2026-11-18 | Audyt wydajności aplikacji na 1 mln produktów: R1–R10, propozycje epików API-RUNTIME, STORE-CACHE, SEARCH-FACETS, ADMIN-QUERIES, ASYNC-THROUGHPUT z kryteriami akceptacji z pomiarem. Audyt aplikacji: R19 „testy obciążeniowe i Lighthouse niczego nie egzekwują … Regresja wydajności … nie zatrzyma PR”. Powstaje #781 (zbiorcze) | `b0959435`; `performance-audit-2026-11-18.md:33-108`, `:773-797`, `:879-893`; `app-audit-2026-11-18.md:267` |
| 2026-11-19 | DEMO-LOAD (#756): `medium` na k3d, nasycenie 177 it/s; p95 `home`/`category`/`search` 1,4–1,6 s w rampie; „zgodne z H1, ale pomiar jej nie rozstrzyga” | `bd5ccf81`; `demo-load-medium-2026-11-19.md:111-144` |
| 2026-11-19 | L9: cache ISR tylko w pamięci, 128 MB (#800) | `7c88c060` |
| 2026-11-20 | ASYNC-RELIABILITY (#811) realizuje R6a (poprawność, nie przepustowość) | `143c6994` |
| 2026-11-20 | DEMO-SCALE etap 1, `medium` na worf.pl: listing API 2,0–2,3 s, „należy do PERFORMANCE (#781)” | `f33ac36a`, `5561de99`; `demo-scale-medium-worf-2026-11-20.md:93-96` |
| 2026-11-24 | Komentarz w #781: worf.pl cały katalog 1,98–2,48 s; eksperyment z shardami; #918 (shardy konfigurowalne, `prod` = 4) | `476d84c4`; komentarze #781 |
| 2026-11-25 | #939: lista tagów ISR ograniczona kubełkami; #954: 503/500 na głębokich stronach i przy 8 równoległych zimnych wejściach; raport shardów: 4 shardy skracają 1,86 → 0,69 s, ale nie zwiększają przepustowości | `f93e16fe`, `2b4258c7`; `search-shards-worf-2026-11-25.md:70-82` |
| 2026-11-25 | SEARCH-FACETS i przejście na `server` wstrzymane przez właściciela do audytu wyszukiwarki; szkic tylko na gałęzi `docs/search-facets-spec` | `811e5f99` (nie na `main`) |

Stan rekomendacji audytu z 18.11 na `main` `2b4258c7`:

| R | Treść | Stan | Dowód |
|---|---|---|---|
| R1 | trwałe połączenie / worker mode | **nie zrobione** | `Caddyfile:33,40`; `doctrine.yaml:1-9` |
| R2 | konfiguracja Postgresa, `statement_timeout` | **nie zrobione** | `templates/postgres.yaml`; brak `statement_timeout` w `apps/api/config`, chartcie, compose |
| R3 | cache HTML listingów, `replicas` w chartcie | **nie zrobione** (L9 tylko ograniczył pamięć ISR) | `_helpers.tpl:245,305`; `search/spec.md:276` |
| R4 | fasety osobno z cache | **nie zrobione**, wstrzymane | `ProductSearchQuery.php:36`; `811e5f99` |
| R5 | trzy zapytania panelu | **nie zrobione** | `admin-features/spec.md:528-529` (H4 „nie zmienia”) |
| R6a | wygaśnięcia niezależne od zaległości | zrobione (#811) | `143c6994` |
| R6b | przepustowość workera | nie zrobione | `async-reliability/spec.md:141` („chart dalej ma jedną replikę”) |
| R7 | limity i waga sklepu | nie zrobione (niesprawdzone szczegółowo) | — |
| R8 | Redis `maxmemory`, rozmiar Varnisha | nie zrobione (`git grep maxmemory`, `-s malloc` – brak) | — |
| R9 | gorący wariant, `lock_timeout` | nie zrobione | brak `lock_timeout` |
| R10 | generator i pomiar | częściowo (#756) | `bd5ccf81` |
| poza R | shardy (#918), tagi ISR (#939) | zrobione, ad hoc, pod warunek `generate server` | `476d84c4`, `f93e16fe` |

Dlaczego odroczono [O z repo]: #781 „podział na osobne epiki nastąpi przy ich planowaniu” i „Po ASYNC-RELIABILITY …
API-RUNTIME oraz R8 i R9 przed pierwszą prawdziwą sprzedażą”; tylko lista tagów ISR (a od 24.11 cache facetów) ma
twardy warunek („przed `generate` z profilem `server`”). `docs/vision.md:385`: „prace nad nimi są w backlogu”.
[poza repo, z pamięci sesji] kolejność tablicy po audytach stawiała epiki poprawności i sprzedaży (#767 → #829 →
#769 → #770) przed #781, a o poprawkach wydajności właściciel miał zdecydować po zebraniu wszystkich audytów.

## A4. CI: rozmiar danych i co jest mierzone

- Dane: `make seed` (`.github/workflows/ci.yml:621-623`), 191 produktów (`demo-scale-medium-worf-2026-11-20.md:21`).
  `make seed-scale` istnieje (`make/demo-data.mk:25`), ale nie biegnie w CI, a `medium` na stosie `dev` nie kończy się
  (256 MB, `performance-audit-2026-11-18.md:218-221`).
- Czas odpowiedzi: żaden test nie egzekwuje. `load-smoke` (`make/load.mk:7-12`, `load/smoke.js:16-22`) sprawdza tylko
  poprawność; Lighthouse ostrzega (`lighthouserc.cjs:25-28`); wyjątki to testy środowiska i czasu przycinania
  (`AccessTokenSignatureSpeedTest`, zamknięte #466 – próg 100 ms był niestabilny).
- Liczba zapytań SQL na żądanie: brak testu (`git grep` `getQueries|query_count|DebugDataHolder` w `apps/api/tests` – 0).
  Audyt nie znalazł N+1 (2–5 zapytań na żądanie, `performance-audit-2026-11-18.md:103-104`, `:117`).
- Plan zapytania: ok. 20 testów planów w `apps/api/tests/Integration/**/*Plan*`, większość z
  `enable_seqscan = off` na kilku wierszach (`SearchBackofficeProductsTest.php:386-391`); wyjątek na wolumenie:
  `OrderSearchPlanTest` (100 tys. zamówień, `ANALYZE`, bez sterowania planistą, `:40-44`), który raz zawiódł przez
  wybór innego indeksu (#886, run 44802037841).
- Rozmiar odpowiedzi: brak testu (budżety KB tylko dla statycznych WebP).
- Zapytania ES: brak testu czasu i `took`; `SearchFacetsPerformanceTest` nie może się nie udać.

## A5. Przyczyny źródłowe w procesie

1. **Brak wymagania, z którym można porównać [O].** Wizja definiuje wolumen, nie zachowanie (`vision.md:243-247`);
   żadna spec gorącej ścieżki nie ma budżetu czasu ani współbieżności (A2). Reviewer bez kryterium nie ma czego
   „złamać”: `code-reviewer.md:22` liczy 500 jako `IMPORTANT`, ale na seedzie 500 nie występuje.
2. **Zasada z `CLAUDE.md` pilnuje słów, nie projektu [O].** `CLAUDE.md:87-88` („nie twierdź…”) jest spełniana
   zdaniem w „Ryzykach” (`search/spec.md:470`, `catalog-admin/spec.md:457`, `pricing/spec.md:630-631`,
   `inventory/spec.md:478`, `async-reliability/spec.md:625`). Zasada `CLAUDE.md:83-86` („uwzględniaj skalę”) nie ma
   nikogo, kto ją sprawdza: żaden agent jej nie cytuje (A1).
3. **Żaden etap nie pracuje na danych w skali [O].** CI i stosy epików mają 191 produktów; pomiar `medium` to
   ręczna operacja za zgodą (`infra/k8s/CLAUDE.md:67-72`), pierwszy raz 2026-11-18 – 13 dni po projekcie facetów.
   Testy, które wyglądają na zabezpieczenie skali, go nie dają: test pomiaru bez asercji
   (`09-testy.md:350-352`) i testy planów z wyłączonym seq scanem (`SearchBackofficeProductsTest.php:388`), które
   przeszły, a zapytania panelu i tak trwały do 4,3 s przy 1 mln (`performance-audit-2026-11-18.md:66-67`).
4. **Ryzyka odraczane bez właściciela, terminu i warunku uruchomienia [O].** Każda spec zapisuje ryzyko skali,
   żadna nie łączy go z warunkiem (np. „przed `medium` na serwerze”). Jedyne ryzyka, które zostały naprawione
   (tagi ISR #939, shardy #918), miały twardy warunek w #781 („przed `generate server`”). Reguła odraczania
   w ADR (`agentic-software-house.md:120-140`) dotyczy znalezisk przeglądu i gwarancji spec, a wydajność nie jest
   gwarancją spec – więc odroczenie jest zgodne z procesem.
5. **Ustawienia domyślne nigdy nie były decyzją [O] → [H] przyczyna.** Tryb FrankenPHP, połączenie z bazą,
   parametry Postgresa, jedna replika: brak ADR i zapisów w spec (A2). Hipoteza: zadania dotykały kodu, nie
   konfiguracji uruchomieniowej, więc reviewer per diff nigdy jej nie widział, a `CLAUDE.md:86-87` i
   `architecture-reviewer.md:19-22` zniechęcają do „optymalizacji na zapas”.
6. **Pętla zwrotna nie obejmuje produktu [O].** Retro analizuje incydenty epiku (`retro/SKILL.md:226-237`); raporty
   procesu (`ash-workflow-audit-2026-11-24.md`, `ash-v1-series-retrospective-2026-11-21.md`,
   `retro-demo-scale-2026-11-20.md`) nie zawierają ani jednego trafienia „wydajn|#781|10 mln”. Defekt odkryty po
   wdrożeniu (#954) nie wraca jako incydent procesu. Rekomendacja audytu aplikacji R19 (progi regresji) i #290 nie
   mają właściciela od 8–18 dni.
7. **Koszt ścieżki gorącej rośnie z wolumenem z projektu [O].** Dokładne `total` i agregacje faset przy każdym
   chybieniu (`search/spec.md:203`, `:226-245`; `ProductSearchQuery.php:36`) – koszt O(liczba trafień). Specyfikacja
   uzasadnia poprawność faset (porównanie z modelem w PHP), nie ich koszt; nikt w procesie nie zadał pytania „jak to
   rośnie z N”. [H] Projekt przez AO (#195) bez przeglądu architektury pod kątem wolumenu.

Czego nie twierdzę: nie wiem, czy któryś reviewer SEARCH zgłaszał koszt faset (transkryptów AO nie czytałem).
Liczby z audytów to pojedyncze pomiary na współdzielonych maszynach; nie wyprowadzam z nich progów.

## A6. Propozycje mechanizmów (bez zmiany workflow, tylko propozycje)

Zasada doboru progów: próg bezwzględny tylko z ograniczeń technicznych lub decyzji właściciela (np. „bez 5xx”,
„poniżej limitu klienta ES z zapasem”), nie z małej próby; progi regresji dopiero po kilku pomiarach ze znanym
rozrzutem.

| # | Mechanizm | Złapałby | Nie złapałby | Koszt |
|---|---|---|---|---|
| M1 | **Budżet ścieżki gorącej w spec** (`/brainstorm`, sekcja „Wydajność” tylko dla ścieżek: listing, wyszukiwarka, strona produktu, koszyk/checkout, listy panelu): wolumen (`medium`/`server`), oczekiwana zależność od N, zimny/ciepły czas względem limitów zależności, współbieżność, co przy przekroczeniu (503 czy degradacja). Właściciel akceptuje razem ze spec. | Wymusiłby decyzję o fasetach i dokładnym `total` vs limit 2 s (#954), głęboką paginację (H2) | Sam nic nie mierzy; ustawień domyślnych (R1, R2) | 10–20 min na spec dotyczącą ścieżki gorącej; 0 dla pozostałych |
| M2 | **Pytanie o złożoność względem wolumenu** w `/planner` (pole przy zadaniu, które zmienia zapytanie, indeks, mapping, cache, kolejkę) i u `architecture-reviewer` tylko dla takich diffów: „koszt jako funkcja N, wartość przy 10 mln, czy zależy od liczby trafień, czy jest górna granica”. | Fasety O(trafień), dokładne `total`, `OR EXISTS` (H4), nieograniczona lista tagów ISR, reindeks po każdej zmianie ceny | Konfiguracji, której diff nie dotyka (FrankenPHP, Postgres, repliki) | 5–15 min na przegląd zadania, którego dotyczy; agenci ładowani z głównego checkoutu, więc wymaga jego aktualizacji |
| M3 | **Testy planów na wolumenie bez sterowania planistą** dla zapytań z listy gorących (wzorzec `OrderSearchPlanTest`: tabela tymczasowa, 100 tys. wierszy, `ANALYZE`, asercja braku `Seq Scan`/nieograniczonego sortu, przypadki fraz rzadkich i częstych) zamiast `enable_seqscan = off`. | Wolne wyszukiwanie i filtr kategorii panelu (R5, 1–12 s) | ES, sklep, konfiguracja | jednorazowo ~1–2 h na zapytanie; CI ok. 10–30 s na zestaw; ryzyko niestabilności (#886) – asercja na klasę planu, nie konkretny indeks |
| M4 | **Test liczby zapytań** (licznik w middleware DBAL w teście funkcjonalnym) dla 4–5 endpointów gorących. | Przyszłe N+1 | Żadnego z faktycznych problemów (audyt: brak N+1) – tani strażnik, nie przyczyna obecnych problemów | ~1–2 h raz, sekundy w CI |
| M5 | **„Perf smoke” na `medium` na żądanie i przed wdrożeniem**, nie w każdym PR: utrzymywany wolumen `medium` (k3d `make -C infra/k8s load LOAD_DATA=medium` już istnieje) + skrypt: kilka zimnych żądań na ścieżkę (cały katalog, dział, fraza, produkt, wyszukiwarka panelu), 8 równoległych zimnych wejść, `took` ES, `EXPLAIN (ANALYZE)` kilku zapytań; bramka bezwzględna: 0 × 5xx i czas poniżej limitu zależności; regresja względem poprzedniego raportu po zebraniu rozrzutu. Uruchamia sesja epiku, którego spec ma M1, przed merge'em; właściciel przed wdrożeniem. | #954 (503/500, 8 równoległych), listing ~2 s, fasety, panel 4,3 s, 1 replika sklepu pod ruchem | Skali 10 mln (tylko ekstrapolacja), workera bez scenariusza zdarzeń, zachowania przez Cloudflare | generowanie `medium` ok. 29 min (`demo-load-medium-2026-11-19.md:37`), potem przebieg 5–10 min; dysk ~21 GB (`demo-data/spec.md:488`) |
| M6 | **Jednorazowa decyzja o konfiguracji uruchomieniowej** (ADR lub spec API-RUNTIME): tryb FrankenPHP, połączenie z bazą, parametry i limity Postgresa, repliki, Redis `maxmemory`, rozmiar Varnisha – z wartościami jako pozycjami chartu. | R1, R2, R8, `replicas: 1` | Nic poza konfiguracją | jeden epik (S–M wg audytu) |
| M7 | **Znaleziska audytów z właścicielem i warunkiem uruchomienia**: każde R jako zgłoszenie (lub pozycja #781) z polem „przed czym” (np. „przed `generate server`”, „przed prawdziwą sprzedażą”, „przed publicznym ruchem”); przegląd przy porządkowaniu tablicy. Ryzyko skali w „Ryzykach” spec ma ten sam warunek. | Dryf od 18.11 (8 z 10 R nadal otwartych), #290 otwarte 17 dni | Niczego nie mierzy | ~15 min na audyt; bez kosztu CI |
| M8 | **Lighthouse i k6 z progami** (#290: LCP/CLS z `warn` na `error` po zebraniu rozrzutu; `steady.js` z progami regresji względem ostatniego raportu) | Regresję wagi strony i LCP sklepu | Kosztu ES i bazy przy wolumenie (CI na 191 produktach) | Lighthouse już biegnie; 0 min CI dodatkowo |

Najtańszy zestaw o największym pokryciu faktycznych problemów: M1 + M2 (myślenie przed kodem) + M5 (jedno
sprawdzenie na danych przed merge'em epików ścieżek gorących) + M7 (domknięcie pętli). M3 i M4 to tanie strażniki
regresji SQL. M6 to praca do zrobienia, nie mechanizm.

---

## B. Backlog wydajności (GitHub przez bramkę pochodzenia)

Uwaga: #677, #678, #680, #681 nie są issues (to PR-y poprawek po audycie wydajności **procesu AO**); #698–#706
dotyczą procesu AO/ASH, nie aplikacji. Wymienione dla porządku.

| Nr | Tytuł (skrót) | Stan | Czego dotyczy | Aktualne wg kodu na `main` |
|---|---|---|---|---|
| **#781** | PERFORMANCE: wydajność i skala, epiki z audytu | OPEN | Zbiorcze: API-RUNTIME, ADMIN-QUERIES, STORE-CACHE, SEARCH-FACETS, ASYNC-THROUGHPUT, R9, tagi ISR | Tak: R1–R5, R6b–R9 nie zrobione (A3); tagi ISR zrobione (#939) – pozycja do odhaczenia |
| **#954** | Listing: 503 z API i 500 w sklepie (głębokie strony, równoległe wejścia, medium) | OPEN, bug | Fasety + `total` przy 1 mln vs limit 2 s | Tak: `services.yaml:43` (2,0 s), `ProductSearchQuery.php:36`; 4 shardy nie pomagają przy współbieżności (raport 25.11) |
| #928 | worf.pl: porównanie 1 i 4 shardów | OPEN | Pomiar po #918 | Raport zmergowany (#955, `2b4258c7`); do zamknięcia po decyzji „czy 4 zostają” |
| #929 | make reindex: odroczone uwagi z #918 | OPEN, tech-debt | Testy i komunikaty `reindex.sh` | Prawdopodobnie tak (niesprawdzone) |
| #922 | generate/open: Job queue-check/order-expiry przerywa przebieg | CLOSED | Operacje na serwerze | Naprawione (#936) |
| #938 | generate/open/reindex/restore: odroczone uwagi z #936 | OPEN, tech-debt | Dwie kopie listy CronJobów | Niesprawdzone |
| #484 | DEMO-SCALE: pomiar na serwerze, kopie, konfiguracja usług | OPEN, epic | Pomiar w klastrze, konfiguracja Postgres/ES w `prod` | Tak: Postgres bez konfiguracji (`templates/postgres.yaml`) |
| #486 | DEMO-LOAD | CLOSED | Generator na k3d, `medium` | Zrobione (#756) |
| #482 | DEMO-DATA | CLOSED | Generator | Zrobione |
| #786 | DEMO-SCALE etap 1 | CLOSED | `medium` na worf.pl | Zrobione (#788) |
| #794 | SMALL-FIXES: weryfikacja na worf.pl | OPEN | Kryterium 4 (ostatnia strona listingu) | Nie spełnione na serwerze (#954) |
| #908 | CART-ORDER-CONSISTENCY: weryfikacja na worf.pl | OPEN | Sprawdzenie po wdrożeniu (nie wydajność) | Zależy od wdrożenia |
| #829 | ABUSE-LIMITS i ASYNC-RELIABILITY: weryfikacja na worf.pl | OPEN | Po wdrożeniu | Zależy od wdrożenia |
| #951 | Kolejność: PRODUCT-DATA etap 1 przed `generate server` | OPEN | Decyzja kolejności | Aktualne |
| #290 | Lighthouse: blokujące progi LCP i CLS | OPEN | Bramka regresji frontu | Tak: `lighthouserc.cjs:25-28` – `warn` |
| #396 | LIGHTHOUSE: drobne poprawki | OPEN | `.dockerignore`, komunikat `000` | Tak: `e2e/.dockerignore` bez `lighthouse-results`; `run.sh:22` z martwym fallbackiem |
| #418 | Analiza plateau CPU sklepu | CLOSED | Zastąpione przez #781 | — |
| #267 | HARDENING | CLOSED | k6 i pomiar 191 produktów | — |
| #289 | LIGHTHOUSE | CLOSED | Pomiar CWV w CI | — |
| #791 | make load: odporność load.sh | OPEN | Narzędzie pomiaru | Niesprawdzone |
| #792 | k6 discover() i smoke: walidacja opcji i progi | OPEN | 404 w `setup()` psuje próg smoke | Niesprawdzone |
| #793 | CronJob demo-data i klient Keycloaka generatora | OPEN | Generator | Niesprawdzone |
| #816 | CI: część k8s 13–15 min | OPEN | Czas CI | Niesprawdzone |
| #817 | DEMO-SCALE: odroczone uwagi z #788 | OPEN | Skrypty generate/open | Niesprawdzone |
| #818 | Retro DEMO-SCALE: decyzja o F1–F5 | OPEN, ash | Proces | — |
| #646 | CI: zmiana SKILL.md nie uruchamia testów skryptów | OPEN, ash | Zakres CI | Tak (zasada w `scope.sh`) |
| #886 | CI: niezbadane czerwone próby (m.in. plan zapytania) | OPEN, ash | Niestabilny test planu na wolumenie | Aktualne |
| #684 | Wiele magazynów i 100 magazynów w danych demo | OPEN | Pozycja #781 | — |
| #745 | DemoData: krok zdjęć przepisuje całą tabelę produktów jednym procesem | OPEN | Czas generatora przy 10 mln | Niesprawdzone |
| #746–#750 | DemoData: porządki, pula zdjęć v2 | OPEN | Generator | — |
| #166 | Jedno źródło nazw tagów cache w sklepie | OPEN | Tagi | Częściowo nieaktualne po #939 (`isr-tags.ts` jedynym źródłem tagów Next) – do sprawdzenia |
| #167 | Nieaktualne komentarze CacheTags/StorefrontCache | OPEN | Dokumentacja kodu | Niesprawdzone |
| #578, #949 | CI: wąskie gardło e2e shard 1; wzrost czasu testów | OPEN | Czas CI, nie aplikacja | — |
| #698–#706 | Uwagi z audytu wydajności procesu AO | mieszane | Proces AO/ASH | Poza aplikacją |
| #762 | Testy API przy zimnym cache > 256 MB | CLOSED | Pamięć testów | — |

Brak osobnych zgłoszeń dla: R1 (FrankenPHP/połączenie), R2 (Postgres), R3 (cache listingów), R4 (fasety), R5
(zapytania panelu), R6b (worker), R8 (Redis/Varnish) – są tylko jako linie zakresu #781. Szkic SEARCH-FACETS
istnieje tylko na gałęzi `docs/search-facets-spec` (`811e5f99`).
