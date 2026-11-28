# PERF-3: odporność na przeciążenie i izolacja ścieżek

Epik PERF-3 programu wydajności (`docs/features/performance-program/plan.md`). Przeciążenie i awaria wyszukiwarki mają
przestać być awarią całego sklepu: nadmiar listingów czeka w krótkiej kolejce albo jest szybko odrzucany, koszyk,
zamówienia i `/health` mają zawsze wolne wątki, a błąd wyszukiwarki nie daje HTTP 500 strony.

Podstawa: audyt `docs/reports/performance-audit-2026-11-25.md` (P2, P3, część P8, Varnish w „Średnie i niskie”), linia
bazowa `docs/reports/server-profile-baseline-worf-2026-11-26.md` (§5–7: 22–33% zimnych listingów to 503 w API i 500
w sklepie, w skoku W8 strony produktów 6–7 s). Twierdzenia o kodzie dotyczą `main` `7fd4d9d2` (model R z PERF-2B).

Zamyka: W12; zatrzymanie Elasticsearcha nie daje 500 i nie zatrzymuje zamówień. Odpowiedź z kopii, zdegradowana albo
odrzucona jest w W1–W12 niepowodzeniem (zasada 8 programu); mechanizmy tego epiku chronią użytkownika i resztę sklepu,
nie poprawiają wyniku benchmarku.

**Stan: decyzje D1–D8 czekają na akceptację właściciela. Implementacja dopiero po jego decyzji o kolejnych epikach
programu.**

## Granice

| Poza PERF-3 | Epik |
|---|---|
| limity czasu bazy (`statement_timeout` i pozostałe), 503 z `Retry-After` dla przerwanego zapytania, budżet pamięci, `num_threads` 16 i `VARNISH_SIZE` jawnie | PERF-1 |
| model wyszukiwania, klient Elasticsearcha (limit 2 s bez ponowień zostaje) | PERF-2 |
| tryb worker FrankenPHP, liczba wątków ponad 16 | PERF-4 |
| repliki sklepu i API, równoległe odczyty zimnej ścieżki `/c/...` | PERF-5 |
| grupowanie purge i rewalidacji, reindeks kategorii | PERF-6 |

Pule wątków Elasticsearcha zostają domyślne: limit stoi przed nimi, a pomiar (K5) potwierdza pustą kolejkę `search`.

## Stan wyjściowy (kod)

| Obszar | Dziś |
|---|---|
| FrankenPHP | tryb klasyczny, 16 wątków wspólnych dla wszystkich tras, kolejka na wątek bez limitu (`Caddyfile`) |
| Varnish | jeden backend `api` bez limitów połączeń i czasów; purge twardy (`default.vcl`); grace domyślne 10 s; po 503 z API (`private, no-store`) builtin VCL tworzy hit-for-miss na 120 s, więc przez 2 minuty nie łączy żądań o ten adres |
| API | `ListProductsController` zamienia `SearchUnavailable` na 503 bez `Retry-After`; `ServerErrorReporter` wysyła każde 5xx synchronicznie do Sentry (limit 2 s) |
| Wejście bezpośrednie | `api.<domena>/api/v1/products` idzie z Traefika prosto do API, z pominięciem Varnisha |
| Sklep | odczyty katalogu bez limitu czasu (domyślne `fetch` Node); `loadCatalog` rzuca dla każdego statusu poza 400 i 404, więc `app/error.tsx` daje 500; rewalidacja `revalidateTag(..., { expire: 0 })` także dla `catalog` |
| Zmiana drzewa kategorii | purge `catalog` w Varnishu i ISR przy zdarzeniu i drugi raz po reindeksie poddrzewa (zmiana nazwy, przeniesienie); oba potrzebne: nazwy od razu, przynależność po reindeksie |
| Sondy | `/health` statyczny w PHP, tylko `readinessProbe` (5 s, 20 prób); bez `livenessProbe` |
| Elasticsearch | jedyna publiczna ścieżka żądania z wyszukiwarką to `GET /api/v1/products`; koszyk, zamówienie, strona produktu, oferty i drzewo czytają Postgresa |

## Zachowanie zależności (sprawdzone eksperymentem)

Eksperymenty w kontenerach obrazów z repo, 2026-11-28; zadania zamieniają je w testy.

1. Varnish 9.1.0: backend z `.max_connections`, `.wait_limit` i `.wait_timeout` trzyma nadmiar w kolejce Varnisha
   (bez wątku PHP), a po zapełnieniu kolejki odpowiada od razu 503 (`MAIN.backend_wait`, `MAIN.backend_busy`). Parametry
   `backend_wait_*` mają w 9.1 flagę „experimental”.
2. `xkey.softpurge` z `req.grace = 0s`: następne żądanie po purge czeka na świeżą odpowiedź (tak jak po twardym
   purge). Odpowiedź 5xx z `return (abandon)` nie tworzy obiektu (brak hit-for-miss), a `restart` z dłuższym `req.grace`
   oddaje zachowaną kopię.
3. `return (abandon)` szereguje żądania czekające na ten sam adres (każde idzie do backendu po kolei); przy szybkim
   błędzie (wyszukiwarka zatrzymana, kolejka pełna) koszt jest pomijalny, przy wolnym błędzie ogranicza go limit czasu
   sklepu.
4. FrankenPHP 1.13.6, `max_wait_time`: po przekroczeniu 503 `text/plain` („maximum request handling time exceeded”),
   którego `handle_errors` Caddy nie przechwytuje; dotyczy każdej trasy, także `/health`.
5. Next.js 16.4.6: strona ustawia tylko 404, 403 i 401 (oraz przekierowanie); wyjątek renderu to 500. Strona nie może
   odpowiedzieć 503.

## Rozwiązanie

### R1. Limit współbieżności w Varnishu (D1)

- Dwa backendy do tego samego `api:80`: `api_search` dla `GET /api/v1/products` i `api` dla pozostałych odczytów
  katalogu z VCL. Każdy ma `.max_connections`, `.wait_limit`, `.wait_timeout`, `.connect_timeout` i
  `.first_byte_timeout`.
- Reguła wartości: `max_connections(api_search)` to współbieżność, przy której przepustowość zimnych listingów przestaje
  rosnąć, a p99 mieści się w W1 (kolano krzywej z pomiaru); suma obu `max_connections` zostawia zapas wątków
  (kandydat 4 z 16) dla koszyka, zamówień, panelu i `/health`. `wait_limit(api_search)` mieści skok W8 (100) z tłem.
  `wait_timeout` + `first_byte_timeout` < limit czasu sklepu (R5).
- Nadmiar: 503 z Varnisha z `Retry-After: 1`, bez wątku PHP, a potem kopia (R2), jeśli jest.
- Wartości są w VCL (atrybuty backendu nie czytają zmiennych środowiska), takie same w compose i w chartcie.

### R2. Kopia zapasowa i odpowiedzi błędów w Varnishu (D2)

- Obiekty katalogu mają `beresp.grace` = 1 h. Przy zdrowej odpowiedzi `req.grace = 0s`: Varnish nigdy nie oddaje
  starej treści, gdy API działa, także po TTL (dziś do 10 s).
- 5xx z API albo błąd backendu (kolejka pełna, limit czasu) na trasie katalogu: `abandon` (bez hit-for-miss),
  `restart` z `req.grace` = 1 h; jest kopia, to ją oddaje z `X-Cache: STALE`, nie ma, to 503 z `Retry-After`.
- 400, 401 i 404 bez zmian.

### R3. Miękki purge (D3)

- Varnish: `xkey.softpurge` zamiast `xkey.purge` dla każdego tagu. Świeżość bez zmian (R2: `req.grace = 0s`), kopia
  zostaje na awarię.
- Sklep: rewalidacja tagu `catalog` w trybie stale-while-revalidate (`revalidateTag(tag, "max")`); pierwsze wejście na
  stronę ISR po zmianie drzewa dostaje poprzednią wersję, render w tle pobiera świeże dane. Tagi `product-<id>`
  i `product-slug-<slug>` zostają natychmiastowe (`expire: 0`, P5 strony produktu).
- Liczba purge przy zmianie drzewa bez zmian (oba potrzebne).

### R4. API (D5)

- `SearchUnavailable` → 503 z `Retry-After: 5` i problemem bez szczegółów technicznych. OpenAPI i klienci TS bez
  zmian (nagłówek nie zmienia kształtu odpowiedzi).
- Ten 503 nie trafia do Sentry przy każdym żądaniu: log ostrzeżenia ze stałą treścią i klasą przyczyny. Awaria
  Elasticsearcha jest widoczna w `WorkloadNotReady` i na ekranie „Stan systemu”. Pozostałe 5xx bez zmian.
- `max_wait_time` FrankenPHP jako ostatnia granica: kandydat 2 s (powyżej p99 W6), wartość z pomiaru. Odpowiedź po jego
  przekroczeniu to 503 `text/plain` (eksperyment 4); jawny wyjątek od formatu problemu, bo żaden klient nie pokazuje treści
  503 z API (sklep i panel mapują status).
- `/health` zostaje w PHP; chroni go zapas wątków z R1.

### R5. Sklep (D4, D7)

- Odczyty przez `API_CATALOG_URL` (lista, oferty, drzewo, przekierowanie, produkt, mapa strony) mają limit czasu
  (`AbortSignal.timeout`); kandydat 5 s, większy od `wait_timeout` + `first_byte_timeout` Varnisha. Bez ponowień.
  Zapisy i odczyty BFF przez `API_INTERNAL_URL` bez limitu po stronie sklepu: przerwanie zapisu, który może się
  zatwierdzić, dałoby fałszywy błąd; ogranicza je API (PERF-1 i `max_wait_time`).
- Lista produktów nieosiągalna (5xx, limit czasu, sieć) na `/`, `/c/...` i `/szukaj`: `loadCatalog` zwraca
  `unavailable` zamiast wyjątku. Strona renderuje nagłówek, nazwę i ścieżkę kategorii (gdy drzewo jest), komunikat
  w `aria-live` „Nie udało się teraz wczytać produktów. Spróbuj ponownie za chwilę.” i odnośnik ponowienia z focusem
  osiągalnym klawiaturą; bez listy, filtrów i stronicowania. Status 200 (eksperyment 5), bez `noindex`; metadane
  z nazwą kategorii. Log ostrzeżenia, bez zdarzenia Sentry. Ekran 375 px.
- Błąd drzewa kategorii i strony produktu bez zmian (nie zależą od wyszukiwarki; chroni je R2).

### R6. Wejście bezpośrednie na API (D1)

- Osobny ingress hosta `api` dla ścieżki `/api/v1/products` (`Exact`) z middleware `inFlightReq` Traefika,
  `sourceCriterion.requestHost` (limit globalny, nie per klient); kandydat 2. Nadmiar: 429 z Traefika. Ingress ma
  middleware polityki hosta (allow list, HSTS, noindex, buffering) jak pozostałe.
- Łączna współbieżność wyszukiwania ≤ `max_connections(api_search)` + limit `inFlightReq`.

## Decyzje do akceptacji właściciela

| ID | Decyzja | Rekomendacja | Alternatywa |
|---|---|---|---|
| D1 | miejsce limitu | kolejka w Varnishu (R1), `inFlightReq` na wejściu bezpośrednim (R6), `max_wait_time` jako granica | semafor w API na Redisie: czekanie zajmuje wątek PHP, więc bez kolejki, a W8 (100 naraz) się nie mieści |
| D2 | wiek kopii zapasowej | najwyżej 1 h; tylko przy błędzie albo odrzuceniu; ceny w koszyku i zamówieniu liczy API | dłużej (dłuższa awaria bez błędów, starsze ceny) albo bez kopii |
| D3 | miękki purge | Varnish miękko dla wszystkich tagów; ISR `catalog` stale-while-revalidate, produkty natychmiast | zostawić twardy purge (cały sklep zimny po zmianie drzewa) |
| D4 | strona bez listy | 200 z komunikatem, bez `noindex` | 200 z `noindex` (Google może usunąć adres do następnej wizyty robota) |
| D5 | Sentry | 503 wyszukiwarki tylko w logu | każde 503 do Sentry (do 2 s na wątku, zalanie limitu przy awarii) |
| D6 | weryfikacja | lokalnie na pełnym profilu `server` (K1–K6); pomiar akceptacyjny na worf.pl w PERF-9 | – |
| D7 | limit czasu w sklepie | tylko odczyty katalogu | także zapisy (fałszywe błędy przy zatwierdzonym zapisie) |
| D8 | start | po decyzji właściciela o kolejnych epikach i po merge'u PERF-1 (wspólne `Caddyfile`, mapowanie 503, `values.yaml`) | – |

## Weryfikacja (lokalny profil `server`)

Na istniejącym lokalnym stosie z pełnym profilem `server` (generuje go sesja PERF-7), bez ponownego generowania danych
i bez reindeksu (epik nie zmienia indeksu), obraz produkcyjny sklepu (`STORE_MODE=production`). Narzędzia istniejące:
`load/baseline.py` (`open`, `burst`) i `load/steady.js` (listingi, koszyk, zamówienia). Przed i po na tej samej
rewizji danych; trzy przebiegi, wynik środkowy z rozrzutem. Czasy lokalne to porównanie, nie wynik serwera.

| ID | Kryterium |
|---|---|
| K1 | przepustowość zimnych listingów i ruchu mieszanego zmierzona przed zmianą (wejście W12) |
| K2 | W12: tempo 2 × K1 przez 5 min z koszykiem i zamówieniami (`steady.js`): pełne odpowiedzi ≥ 80% K1, W5 i W6 w normie, powrót do normy w 60 s, 0 restartów i 0 utraty gotowości podów; odpowiedzi `STALE`, 429 i 503 liczone jako niepowodzenia |
| K3 | W8 bez regresji wobec stanu przed (kolejka nie odrzuca skoku 100) |
| K4 | zatrzymany Elasticsearch przez 5 min ruchu: 0 odpowiedzi 500 sklepu, listingi z kopią albo stroną R5, zamówienia W6, W13 po przebiegu; po starcie Elasticsearcha powrót w 60 s |
| K5 | w K2 kolejka puli `search` Elasticsearcha bliska zeru, `MAIN.backend_busy` i `backend_wait` odczytane z `varnishstat` |
| K6 | raport w `docs/reports/` z wartościami limitów, pomiarem przed i po i komendami do powtórzenia |

Testy w zadaniach: `varnishtest` dla każdej reguły R1–R3 (także obejścia: inna kolejność parametrów, `HEAD`, adres
spoza listy, purge bez tokenu), test integracyjny API z nieosiągalnym Elasticsearchem (503, `Retry-After`, brak
zdarzenia Sentry), testy sklepu (`unavailable` dla 5xx, limitu czasu i sieci; render strony; tryb rewalidacji per tag),
test renderu chartu dla ingressu z `inFlightReq`.

## Ryzyka

- Flaga „experimental” kolejki backendu w Varnishu 9.1: aktualizacja Varnisha może zmienić zachowanie; pilnują go
  testy `varnishtest`.
- Wartości limitów z pomiaru lokalnego trzeba potwierdzić na serwerze (PERF-9); CPU serwera jest ok. 3 razy wolniejsze.
- Szeregowanie po `abandon` (eksperyment 3) przy wolnym błędzie gorącego adresu: ogranicza je limit czasu sklepu,
  mierzy K2.

## Dokumentacja razem z kodem

- `apps/store/CLAUDE.md` („Rewalidacja”), `docs/features/product-page/spec.md` (P5, Varnish),
  `docs/features/isr-tag-limit/spec.md`: tryb rewalidacji `catalog` i miękki purge.
- Komentarze `infra/varnish/default.vcl` i `Caddyfile`; `docs/features/performance-program/plan.md` („Status”).
