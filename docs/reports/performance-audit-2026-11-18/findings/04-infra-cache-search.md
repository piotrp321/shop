# Audyt 04: infrastruktura, cache i wyszukiwarka (analiza statyczna)

Zakres: Elasticsearch 9.4, Redis 8, Varnish 9, RabbitMQ 4 / Messenger, Keycloak 26, Garage + imgproxy, chart Helm
(`infra/k8s/charts/shop`, profile `infra/k8s/environments/*`) i compose, metodyka `load/` i `infra/k8s/scripts/load.sh`.
Ścieżki są względne do katalogu eksportu. Nie uruchamiano żadnego stosu: wszystkie liczby wydajnościowe to szacunki albo
hipotezy do pomiaru.

Oznaczenia:
- **[K]** – potwierdzone analizą kodu/konfiguracji,
- **[H]** – hipoteza do pomiaru,
- **[S]** – szacunek (rząd wielkości, do potwierdzenia pomiarem).

Waga zapisana jako `docelowa / demo`: skutek przy skali docelowej (10 mln produktów, ~30 mln wariantów, 1 mln klientów,
50 mln zamówień, 60 tys. jednocześnie aktywnych użytkowników) oraz na obecnym demo (jeden VPS, 8 vCore, 24 GB RAM,
k3s, każda usługa w jednej replice, `infra/k8s/environments/prod/values.yaml:1-2`).

Część ryzyk opisuje już specyfikacja DEMO-DATA (`docs/features/demo-data/spec.md:531-548`, H1–H5). Ten raport je
potwierdza, uszczegóławia i dodaje nowe.

---

## 0. Najważniejsze znaleziska (skrót)

| # | Waga (docelowa / demo) | Miejsce | Znalezisko |
|---|---|---|---|
| F1 | Critical / High | `apps/api/src/Catalog/Infrastructure/Search/ProductSearchQuery.php:31-47,117-184`, `apps/api/config/services.yaml:11-12,26` | Każde chybienie cache listingu liczy `track_total_hits: true` i 4+ agregacje nested (z `reverse_nested` w każdym kubełku) na jednym shardzie bez replik. Limit klienta to 2 s. Strona główna agreguje cały katalog (~100 mln dokumentów Lucene). [K] budowa zapytania, [H] czas |
| F2 | Critical / Medium | `apps/store/app/p/[slug]/page.tsx:10`, `apps/store/next.config.ts` (brak `cacheHandler`), `infra/k8s/charts/shop/templates/_helpers.tpl:244` | Cache ISR i data cache Next.js są w lokalnym systemie plików poda: bez limitu, tracone przy każdym wdrożeniu, nie dzielone między repliki. `POST /api/revalidate` trafia do jednego poda. To główna blokada skalowania sklepu w poziomie. [K] |
| F3 | High / High | `infra/k8s/charts/shop/templates/redis.yaml:1-18`, `infra/docker-compose.yml:28-38` | Redis działa bez konfiguracji: bez `maxmemory`, z domyślnym RDB, bez AOF, z limitem poda 512 Mi. Są w nim sesje sklepu (tokeny, do 30 dni), blokady odświeżania i limity. Przekroczenie limitu kończy się OOMKill i utratą wszystkich sesji. [K] konfiguracja, [S] pamięć |
| F4 | High / Medium | `apps/api/config/packages/messenger.yaml:25-113`, `infra/k8s/charts/shop/templates/api.yaml:136` | Jeden proces workera obsługuje szeregowo 6 transportów. Reindeks i invalidacja idą po jednym produkcie, każdy z odczytami z bazy, pojedynczym `index` do ES, PURGE i POST do sklepu. Masowa zmiana cen to godziny zaległości. [K] architektura, [S] przepustowość |
| F5 | High / Medium | `apps/api/src/Catalog/Application/Command/ReindexCategoryBatchHandler.php:57-79` | Reindeks poddrzewa kategorii pisze dokumenty pojedynczo, nie przez bulk. Na końcu wywołuje `invalidateCatalog()`, który czyści cały Varnish i cały ISR, co daje lawinę zimnych zapytań z agregacjami. [K] |
| F6 | High / High | `infra/k8s/charts/shop/templates/varnish.yaml:4`, `infra/varnish/default.vcl` | Varnish nie ma ustawionego rozmiaru storage, więc działa domyślne `malloc` obrazu (najpewniej 100 MB). Mieści kilka–kilkanaście tysięcy obiektów, a TTL listingu to 60 s bez grace. Hit ratio dla długiego ogona będzie niskie. [K] brak ustawienia, [H] wartość domyślna i hit ratio |
| F7 | High / Medium | `infra/k8s/charts/shop/templates/ingress.yaml:12`, `infra/k8s/README.md:197` | `api.<domena>` wystawia publiczne odczyty katalogu z pominięciem Varnisha. Cloudflare cache'uje tylko host `img`. Każdy klient z internetu może więc wywołać agregacje ES bez cache. [K] |
| F8 | High / Medium | `infra/k8s/environments/prod/values.yaml:85-108`, `charts/shop/values.yaml:160-169` | Zasoby prod nie odpowiadają 10 mln produktów: ES ma stertę 2 GB i limit 4 Gi przy indeksie ~20 GB, Postgres limit 2 Gi przy bazie ~62 GB bez tuningu, wolumeny nominalnie 10 Gi. Wszystko leży na jednym dysku `local-path`. [K] konfiguracja, [S] rozmiary ze spec |
| F9 | High / Low | `apps/api/docker/frankenphp/Caddyfile:10`, brak `max_connections` w `templates/postgres.yaml` | FrankenPHP działa w trybie klasycznym (bez worker mode, start kernela przy każdym żądaniu), z domyślną liczbą wątków 2×CPU węzła. Do tego pula Keycloaka (domyślnie do 100) i worker, a Postgres ma domyślne `max_connections=100`. Ryzyko „too many clients” przy skalowaniu. [K] konfiguracja, [H] skutki |
| F10 | High / Medium | `apps/api/src/Catalog/Application/Search/ProductSearchIndexRebuilder.php:19-20,126-154` | Pełna przebudowa nie jest online: zmiany zapisane w trakcie (godziny przy 10 mln) trafiają do starego indeksu i giną po przełączeniu aliasu. ES nie ma kopii ani repliki, więc po utracie wolumenu wyszukiwarka i listingi nie działają przez godziny. [K] |
| F11 | Medium / Medium | `load/lib/data.js:47-61`, `load/lib/config.js:43-49`, `infra/k8s/scripts/load.sh:104` | Test obciążeniowy losuje tylko z pierwszych ≤4800 produktów (100 stron × 48), więc mierzy gorący cache. k6 działa na tej samej maszynie co klaster. Nie ma zalogowanych użytkowników, obrazów, panelu ani zapisów katalogu. Wynik zawyża przepustowość dla długiego ogona. [K] |
| F12 | Medium / Low | `apps/api/src/Catalog/Application/Query/ListProductsHandler.php:79` | Każde żądanie listingu czyta z Postgresa wszystkie kategorie i buduje drzewo, bez cache aplikacji. [K] |

---

## 1. Elasticsearch

### 1.1 Mapowanie (`apps/api/src/Catalog/Infrastructure/Search/ProductIndexDefinition.php:162-229`)

- [K] `dynamic: strict`, dwa analizatory (`polish_text` ze stemmerem `polish_stem` i `asciifolding`, `folded_text`) oraz
  normalizator do sortowania (`:162-177`). Pola tekstowe `name`, `short_description`, `category_names` mają podpole
  `folded`, więc każde jest indeksowane dwa razy (`:192-207`). Dla polskiego to rozsądny koszt.
- [K] `variants` to `nested` (`:209`), a w nim znowu `nested` `pairs` (`:219`). Każdy produkt daje
  1 + V + V×A dokumentów Lucene (V – warianty, A – osie wariantu). [S] Przy 10 mln produktów, 30 mln wariantów i średnio
  2 osiach to około 10 + 30 + 60 = **~100 mln dokumentów Lucene na jednym shardzie**. Mieści się w limicie Lucene
  (~2,1 mld), ale każda agregacja po `pairs` przechodzi przez dziesiątki milionów dokumentów.
- [K] Opcje wariantu są zapisane dwa razy: jako `variants.options` (keyword, filtry) i jako `variants.pairs` (nested,
  facety), `:216-219`. Duplikacja jest celowa, ale to największy składnik rozmiaru indeksu i liczby dokumentów.
- [K] Pola indeksowane, których zapytania sklepu nie używają: `slug`, `main_category_id`, `category_ids`, `version`
  (zapytania filtrują tylko `status` i `category_ancestor_ids`, `ProductSearchQuery.php:62-64`; reindeks poddrzewa sortuje
  po `id`). Te pola można trzymać w `_source` z `index: false` / `doc_values: false`. Zysk niewielki (Low / Low).
  `main_image` jest poprawnie `enabled: false` (`:205`).
- [K] `doc_values` mają wartości domyślne (włączone dla keyword/long). Pola do sortowania (`name.sort`, `from_gross`, `id`)
  i facetów mają je słusznie.

### 1.2 Shardy, repliki, refresh, sterta

- [K] `app.search.shards: 1`, `app.search.replicas: 0` (`apps/api/config/services.yaml:11-12`). Jeden shard to jeden
  wątek zapytania na shard (ES 8.12+ ma współbieżność po segmentach dla części faz, [H] jej wpływ na agregacje nested
  trzeba zmierzyć). Brak replik oznacza brak HA i brak skalowania odczytów. Komentarz w kodzie świadomie zakłada jeden
  shard, bo przy większej liczbie sortowane termy wymagają `shard_size` (`ProductSearchQuery.php:139-141`).
  Waga: High / Low.
- [K] `refresh_interval` jest domyślny (1 s) dla indeksu żywego. Przy przebudowie wynosi `-1` i jest przywracany w
  `verify()` (`ElasticsearchSearchIndexMaintenance.php:194-196,286`). Poprawnie.
- [K] Sterta: compose 512 MB (`infra/docker-compose.yml:47`), chart domyślnie 1 g (`values.yaml:172`), prod 2 g przy limicie
  poda 4 Gi (`environments/prod/values.yaml:85-86,96`). [S] Przy indeksie ~20 GB (szacunek ze spec,
  `docs/features/demo-data/spec.md:148-151`) na page cache zostaje ~1,5–2 GB, czyli ~10% indeksu. Agregacje po
  `doc_values` będą czytać z dysku, który dzielą Postgres, Garage i Keycloak. Waga: High / High (przy załadowanych
  10 mln na demo).
- [K] Wolumen ES ma nominalnie `10Gi` (`values.yaml:163`), a prod tego nie nadpisuje. `local-path` nie egzekwuje rozmiaru,
  więc faktycznym limitem jest dysk węzła. Rozmiary w chartcie są mylące, a alert `NodeDiskFull` jest jedynym
  zabezpieczeniem (`infra/k8s/README.md:316`).
- [K] `xpack.security.enabled: false`, `discovery.type: single-node` (`templates/elasticsearch.yaml:8-11`). Brak
  snapshotów ES (kopie obejmują bazy i obrazy, `values.yaml:131`). RTO wyszukiwarki to pełna przebudowa (godziny).

### 1.3 Kształt zapytania (`ProductSearchQuery.php`)

- [K] `query`: filtr `status` i `category_ancestor_ids`, fraza w `must` jako dwa `multi_match` (cross_fields + fuzzy
  most_fields z `max_expansions: 20`, `prefix_length: 1`), `:60-99`. Warunki wariantu są w `post_filter` (`:41-44`).
- [K] `track_total_hits: true` (`:36`). Dokładne liczenie wyłącza block-max WAND dla sortowania po trafności, więc każde
  zapytanie z frazą ocenia wszystkie dopasowania. Przy krótkich frazach („buty”) to setki tysięcy dokumentów.
  Waga: High / Medium. [H]
- [K] Agregacje liczone przy każdym żądaniu (`:117-184`):
  1. `categories` – `terms` po `category_ancestor_ids` z `include` (tanie, niska kardynalność);
  2. `available` – nested + filter + `reverse_nested`;
  3. `price` – nested + filter + min/max;
  4. `free` – nested → filter → nested `pairs` → `terms` osi (10) → `reverse_nested` + `terms` wartości (30) →
     `reverse_nested`;
  5. po jednej agregacji na każdą wybraną oś (do 5 osi, `ListProductsController.php` OA: „at most 5 different axes”), każda
     z `top` i `chosen`, obie z `reverse_nested`.
  `reverse_nested` w każdym kubełku wymaga przejścia z dokumentu wariantu do rodzica dla każdego dopasowanego dokumentu
  pary. [S] Na stronie głównej (bez kategorii) zakres to cały katalog: ~60 mln dokumentów par przy 10 mln produktów.
  [H] Spodziewany czas na jednym shardzie z 2 GB sterty i zimnym page cache: **od kilkuset ms do kilku s**, czyli blisko
  albo powyżej limitu klienta 2 s (`services.yaml:26`). Przekroczenie daje 503 (`ListProductsController.php` → `SearchUnavailable`).
  Waga: Critical / High.
- [K] Paginacja to `from/size` z obcięciem do `max_result_window` 10 000 (`ElasticsearchProductSearch.php:28-31`). Strona za
  oknem zwraca tylko liczbę (`size: 0`), ale nadal liczy wszystkie agregacje. Najgłębsza strona (from≈9 952) zbiera
  top 10 000 na shardzie, co przy jednym shardzie jest akceptowalne. `search_after` używa tylko reindeks poddrzewa
  (`ElasticsearchSubtreeProductIds.php:25-34`) i to jest poprawne. Spec zgłasza stronicowanie bez górnej granicy w sklepie
  (H2), co potwierdzam: Medium / Low.
- [K] Sortowania (`:104-112`) używają `name.sort` (keyword z normalizatorem), `from_gross` i `id`: tanie, z doc_values.
- [K] Global ordinals dla `pairs.axis`, `pairs.value` i `category_ancestor_ids` są budowane leniwie po każdym refreshu,
  w którym były zapisy. Kardynalność jest niska (osie i wartości), więc [H] koszt jest mały. Low / Low.

### 1.4 Co dociągane jest z Postgresa po wyniku ES

- [K] Lista produktów jest w całości z `_source` (`ElasticsearchProductSearch.php:42-45`). Przed zapytaniem handler czyta
  kategorię (`ListProductsHandler.php:41`) i **wszystkie kategorie** do budowy drzewa facetów (`:79`) przy każdym
  żądaniu, bez cache. Medium / Low (koszt rośnie z liczbą kategorii).
- [K] Ceny, warianty i dostępność przychodzą osobnym żądaniem sklepu `GET /api/v1/product-offers?productId=…` (do 48 id),
  przez Varnisha (`apps/store/src/catalog/load-catalog.ts:33-55`, `apps/api/src/Cart/UI/Http/ProductOffersController.php:85-108`),
  czyli zapytania Pricing i Inventory w Postgresie. Strona listingu to więc 1 zapytanie ES + 1–2 serie zapytań Postgresa
  przy chybieniu Varnisha.

### 1.5 Reindeksacja

**Pełna** (`ProductSearchIndexRebuilder.php`, `ElasticsearchSearchIndexMaintenance.php`):
- [K] Nowy indeks fizyczny za aliasem, wypełniany przy `refresh_interval: -1` paczkami `bulk` po 500 (`services.yaml:22`),
  z keyset pagination po `id` (`:129-146`). Ponawia błędy przejściowe z backoffem, maksymalnie 5 prób (`Maintenance.php:105-139`).
  Potem weryfikacja liczby dokumentów i atomowe przełączenie aliasu z `must_exist` (`:216-257`). To zero-downtime dla
  odczytów. Poprawne.
- [K] Przebudowa nie jest online (`Rebuilder.php:19-20`): zapisy przyrostowe w trakcie trafiają do starego indeksu i po
  przełączeniu nowy indeks ma dane z chwili odczytu każdej paczki. Brak drugiego przebiegu „dogonienia” (np. po
  `updated_at` od startu). Waga: High / Medium.
- [S] Czas: 10 mln / 500 = 20 tys. paczek. Każda to odczyt SQL, fakty ofert (`GetProductOffers` w paczkach po 48,
  ~208 tys. wywołań według spec H5) i bulk ~1,5–2,5 MB. Przy 1–3 tys. dok./s daje to **~1–3 h**. Spec mówi „godzinami”.
  Proces jest jednowątkowy, bez równoległych paczek. [H]
- [K] Limit `jobs.activeDeadlineSeconds: 1800` (`values.yaml:122`) dotyczy jobów wersji. Przebudowa przy 10 mln musi
  działać poza nimi (ręcznie albo przez `backfillActiveDeadlineSeconds: 21600`, `values.yaml:125`).

**Przyrostowa** (`ProductIndexing.php:43-56`, `ElasticsearchProductIndexer.php:27-39`):
- [K] Każde zdarzenie (`VariantPriceChanged`, `StockLevelChanged`, `PromotionPricesChanged`) wywołuje pełny odczyt
  produktu z bazy, fakty oferty i **pojedynczy** `index` z `version_type: external_gte` (idempotentnie)
  (`Pricing/Infrastructure/Search/ReindexProductOnPriceChange.php:45-63`, `Inventory/.../ReindexProductOnStockLevelChange.php:45-63`,
  `Pricing/.../ReindexProductOnPromotionChange.php:35-47`). Brak łączenia: zmiana ceny 20 wariantów jednego produktu to
  20 reindeksów tego samego produktu. Promocje deduplikują po produkcie (`PromotionPriceEvents.php:31-41`) i mają limit
  1000 wariantów (`Promotion.php:29`).
- [K] `StockLevelChanged` powstaje tylko przy zmianie poziomu dostępności (`StockItem.php:233-236`, próg 5,
  `AvailabilityLevel.php:13`), nie przy każdej rezerwacji. To dobrze ogranicza ruch.
- [S] Masowa zmiana cen (np. 1 mln wariantów po imporcie albo zmianie VAT; obecnie nie ma endpointu bulk, więc to
  N edycji albo przyszły import) daje 1 mln wierszy outboxa i 1 mln zdarzeń. Każde zdarzenie ma 2 handlery: reindeks
  (GetVariant + odczyty + `index`) oraz invalidację (GetVariant + PURGE + POST do sklepu z timeoutem 30 s,
  `HttpStorefrontCache.php:24-25`). Przy **20–60 zdarzeniach/s** jednego workera to **~5–14 h** zaległości. [H]
- [K] Reindeks poddrzewa kategorii (`ReindexCategoryBatchHandler.php:57-79`): paczki po 500 id z ES (search_after), ale
  każdy produkt pisany pojedynczo przez `ProductIndexing`. Kategoria z 2 mln produktów to 4000 paczek i 2 mln
  pojedynczych zapisów. [S] Przy ~5–15 ms na produkt to 3–8 h na jednym workerze, a każda paczka (kilka s) blokuje
  pozostałe transporty. Na końcu `invalidateCatalog()` (`:78`) czyści cały cache sklepu. Waga: High / Medium.

---

## 2. Redis

### 2.1 Co jest w Redisie

| Klucz | Kto | TTL | Skutek awarii |
|---|---|---|---|
| `session:<id>` – JSON z `accessToken`, `refreshToken`, `idToken`, czasami | sklep, `apps/store/src/auth/session-store.ts:37,59-82` | do wygaśnięcia refresh tokenu (`:62`); przy „zapamiętaj mnie” do 30 dni (`infra/keycloak/realms/shop.json:15-17`) | [K] zalogowani dostają 503 (`redis.ts:3-6`, `disableOfflineQueue`); utrata danych = wylogowanie wszystkich |
| `login:<id>` – transakcja PKCE | sklep, `session-store.ts:85-98` | `LOGIN_TRANSACTION_TTL_SECONDS` | logowanie niemożliwe |
| `login-rate:<adres>` – licznik okna 10 min | sklep, `login-rate-limit.ts:66-81` | 600 s | [K] `hit` odrzuca, gdy Redis nie odpowiada, więc logowanie nie startuje (fail closed) |
| `lock:refresh:<id>` | sklep, `refresh.ts` (LOCK_TTL 5 s, polling co 100 ms) | 5 s | odświeżenie tokenu daje `RefreshUnavailableError` → 503 |
| pula `cache.rate_limiter` (limity składania zamówień, płatności, webhooków) | API, `apps/api/config/packages/rate_limiter.yaml:11-33` | okna 1–15 min | [K] fail open (`:5-7`): bez limitów, z ostrzeżeniem w logu |

- [K] Cache aplikacji API **nie** jest w Redisie: `cache.oidc`, `cache.sitemap` i pule Doctrine używają `cache.app`
  (domyślnie system plików poda) (`framework.yaml:13-18`, `doctrine.yaml:66-80`). Idempotencja i blokady przebudowy są
  w Postgresie (`PostgresSearchIndexRebuildLock`, repozytoria zamówień i płatności). Redis jest więc przede wszystkim
  magazynem sesji sklepu.

### 2.2 Konfiguracja

- [K] Brak jakiegokolwiek pliku konfiguracji i argumentów (`templates/redis.yaml:1-18`, `docker-compose.yml:28-38`).
  Obowiązują domyślne Redisa 8: `maxmemory 0` (bez limitu), `maxmemory-policy noeviction` (bez znaczenia przy braku
  limitu), RDB `save 3600 1 300 100 60 10000`, `appendonly no`. Limit poda 512 Mi (`prod/values.yaml:95`).
- [H] Skutek: Redis rośnie do limitu cgroup i dostaje OOMKill. Po restarcie wczytuje ostatni RDB (do kilku minut starszy),
  więc część sesji ginie. `BGSAVE` robi `fork()`, a copy-on-write przy aktywnym zapisie może chwilowo podwoić pamięć.
  Przy danych powyżej ~250 MB sam zapis RDB może przekroczyć limit. Waga: High / High.
- [S] Pamięć na sesję: trzy tokeny Keycloaka (access ~1,2–2 KB, id ~1–1,5 KB, refresh ~0,7–1 KB) i pola JSON to ~3,5–5 KB,
  z narzutem klucza i alokatora **~4–6 KB**. 60 tys. aktywnych sesji to **~250–360 MB**. Ale sesje żyją do 30 dni („zapamiętaj
  mnie”), więc liczba kluczy to nie aktywni, tylko wszyscy zalogowani w ostatnich 30 dniach. Przy 1 mln klientów i 20%
  aktywnych w miesiącu: 200 tys. × 5 KB ≈ **1 GB**. Obecny limit 512 Mi nie wystarczy.
- Zalecenie: `maxmemory` ~75% limitu, `maxmemory-policy volatile-ttl` albo `volatile-lru` (wszystkie klucze mają TTL),
  AOF `everysec` albo świadoma rezygnacja z trwałości (sesje odtwarzalne logowaniem), limit poda dobrany do liczby
  sesji. Przy skali: Redis zarządzany albo z repliką (Sentinel), bo dziś to SPOF logowania.

---

## 3. Varnish

### 3.1 Co jest cache'owane

- [K] Tylko GET/HEAD bez `Cookie` i `Authorization` (wbudowane VCL) na ścieżkach
  `^/api/v1/(products(/[^/?]+)?|categories(/redirect)?|product-offers)` i `/api/v1/sitemap/product-chunks`
  (`infra/varnish/default.vcl:54-56`). Reszta to `pass`.
- [K] Domyślny TTL 0 (`-t 0`, `templates/varnish.yaml:4`), więc decydują nagłówki API:

| Odpowiedź | Cache-Control | Tagi xkey | Miejsce |
|---|---|---|---|
| lista / wyszukiwarka | `public, max-age=60` (s-maxage brak → TTL 60 s) | `catalog` + `product-<id>` każdego produktu strony | `ListProductsController.php:135-140` |
| szczegóły produktu | `max-age=60, s-maxage=3600` | `catalog`, `product-<id>` | `ProductDetailsController.php:86-89` |
| 404 produktu | `max-age=60` | slug | `ProductDetailsController.php:78-80` |
| oferty produktów | `max-age=60, s-maxage=3600` | `catalog` + każdy produkt | `ProductOffersController.php:101-106` |
| drzewo kategorii | `max-age=60` | `catalog` | `CategoryTreeController.php:58-60` |

- [K] Grace nie jest ustawiony (`beresp.grace`, `stale-while-revalidate`), więc obowiązuje domyślne 10 s. Po 60 s gorący
  klucz listingu idzie do backendu (koalescencja żądań w Varnishu ogranicza to do jednego żądania na klucz).
- [K] `Vary: Origin` jest dopisywany każdej publicznej odpowiedzi (`PublicCacheVarySubscriber.php:21-50`). Serwer sklepu
  nie wysyła `Origin`, więc w praktyce to jeden wariant. Low.

### 3.2 Klucz cache i fragmentacja

- [K] Klucz to pełny URL bez normalizacji query stringu (brak `std.querysort`, `default.vcl:32-59`). Kolejność parametrów
  ustala kod sklepu, więc rozjazd jest mały, ale każda kombinacja kategorii × filtrów × sortu × strony × frazy to osobny
  obiekt. `product-offers?productId=…×24/48` tworzy osobny obiekt dla każdego zestawu id i duplikuje dane ofert
  (każda strona listingu ma własny obiekt ofert). [H] Fragmentacja: Medium / Low.
- [K] Ryzyko zatrucia jest niskie: brak `Set-Cookie` w cache (wbudowane), `X-Correlation-ID` jest zdejmowany przy zapisie
  (`default.vcl:61-69`), tagi są zdejmowane przy dostarczeniu (`:73`).

### 3.3 Invalidacja

- [K] Tylko `xkey.purge` (twardy purge) po tagach, bez banów (`default.vcl:33-48`). Koszt jest proporcjonalny do liczby
  obiektów z tagiem, bez rosnącej listy banów i bez ban lurkera. Dobry wybór dla masowych zmian.
- [K] Zmiana ceny lub poziomu stanu jednego produktu usuwa jego szczegóły, oferty i **każdą stronę listingu, na której był**
  (`ListProductsController.php:137-140`). Przy częstych zmianach popularnych produktów strony główne kategorii będą
  często zimne. [H] Medium / Low.
- [K] Tag `catalog` (wszystko) czyści się przy: każdym wdrożeniu (job `purge-caches`, `templates/jobs.yaml:180-195,243`),
  końcu reindeksu poddrzewa (`ReindexCategoryBatchHandler.php:78`) i zmianach drzewa kategorii. Każde takie zdarzenie
  wysyła cały ruch listingu do ES z pełnymi agregacjami. Przy 10 mln produktów to lawina (F1 × F5). High / Medium.
- [K] Kolejność: najpierw Varnish, potem `POST /api/revalidate` sklepu (`HttpStorefrontCache.php:34-47`). Revalidate trafia
  przez Service do jednego poda sklepu.

### 3.4 Pamięć i hit ratio

- [K] Rozmiar storage nie jest ustawiony (brak `VARNISH_SIZE` i `-s`, `templates/varnish.yaml:1-21`), więc działa
  domyślna wartość obrazu `varnish:9.1.0`. [H] To `malloc,100M`, do potwierdzenia `varnishadm param.show`/`varnishstat`
  (`SMA.s0.g_space`). Limit poda 512 Mi jest wtedy w większości niewykorzystany.
- [S] Obiekty: lista 24–48 produktów ~15–50 KB, oferty ~10–40 KB, szczegóły ~5–15 KB. 100 MB mieści
  **~3–10 tys. obiektów**, a 10 mln produktów to 10 mln kluczy szczegółów i kilka mln kluczy listingu.
- [H] Hit ratio: wysokie (>90%) dla strony głównej i pierwszych stron głównych kategorii, niskie (<20–30%) dla filtrów,
  fraz, dalszych stron i szczegółów produktów z długiego ogona (TTL 1 h, LRU 100 MB). Szczegóły produktów i tak chroni ISR
  sklepu (punkt 3.5).

### 3.5 Varnish a ISR Next.js (podwójny cache)

- [K] Varnish stoi tylko między serwerem sklepu a API (`API_CATALOG_URL: http://varnish`, `templates/store.yaml:13-14`).
  Przeglądarka i Cloudflare go nie widzą.
- [K] Listing i wyszukiwarka renderują się w sklepie przy każdym żądaniu, z `cache: "no-store"`
  (`apps/store/src/catalog/load-catalog.ts:15-20,41`). Dla nich Varnish to **jedyny** cache i ma sens.
- [K] Strona produktu: ISR `revalidate = 3600` (`app/p/[slug]/page.tsx:10`) oraz data cache Next.js z tagami
  (`load-product.ts:22-31`) oraz Varnish `s-maxage=3600`. To potrójna warstwa: Varnish pomaga tylko przy chybieniu ISR
  (inny pod, po revalidate, po wdrożeniu). Uzasadniona, ale korzyść jest marginalna.
- [K] ISR i data cache są w systemie plików poda (brak `cacheHandler` w `next.config.ts`): bez limitu rozmiaru
  (spec: „cache ISR sklepu na dysku poda bez limitu”, `docs/features/demo-data/spec.md:546`), tracone przy każdym
  restarcie i wdrożeniu, nie dzielone między repliki. [S] 10 mln stron × 60–150 KB (HTML + RSC) daje **0,6–1,5 TB** przy
  pełnym przejściu crawlera (sitemap jest wystawiony). W demo `noindex: true` (`prod/values.yaml:18`) ogranicza ruch botów.
  Waga: Critical / Medium.
- Wniosek: przy skali właściwym miejscem cache HTML jest CDN (Cloudflare z regułami cache dla `/p/*` i `/c/*`, krótki TTL,
  purge po tagu albo URL), a ISR wymaga wspólnego `cacheHandler` (Redis) albo wyłączenia na rzecz CDN. Varnish może zostać
  jako mikrocache API dla listingu, z większym storage i grace.

---

## 4. RabbitMQ i Messenger od strony infrastruktury

- [K] Transporty (`messenger.yaml:25-105`): `outbox`, `scheduled`, `payment_simulator`, `promotions`, `jobs`, `failed` są
  w Postgresie (Doctrine, `use_notify: false`, polling). Tylko `events` jest w RabbitMQ (`:40-51`), z `confirm_timeout: 5`
  i obowiązkowym routowaniem. RabbitMQ ma więc **jedną kolejkę** (`events`) i jeden vhost aplikacji (plus `test`).
- [K] Symfony AMQP domyślnie deklaruje exchange fanout i kolejkę **classic**, durable, wiadomości persistent (brak
  `x-queue-type`). W RabbitMQ 4 kolejki classic nie mają już mirroringu, więc HA wymagałoby kolejek quorum
  (`arguments: {x-queue-type: quorum}`) i klastra 3 węzłów. Dziś jeden węzeł (`templates/rabbitmq.yaml:30-32`).
  [H] Receiver Symfony pobiera wiadomości przez `basic.get` (pull, round-trip na wiadomość), więc prefetch nie gra roli.
  Do potwierdzenia w `vendor` (poza eksportem).
- [K] Jeden worker: `messenger:consume outbox events scheduled payment_simulator promotions jobs`
  (`templates/api.yaml:136`, `docker-compose.yml:350`), z `replicas: 1` na sztywno (`_helpers.tpl:244`). Symfony
  obsługuje transporty priorytetowo: po każdej obsłużonej wiadomości wraca do pierwszego transportu. Przy stałym napływie
  outboxa `jobs` (reindeks poddrzewa) czeka, a jedna paczka `jobs` (500 produktów, kilka s) blokuje relay i zdarzenia.
- [K] Brak `--time-limit`, `--memory-limit`, `--limit`: długo żyjący proces PHP z `memory_limit 256M`
  (`docker/php/conf.d/app.ini:2`) restartuje się dopiero po błędzie. Sonda gotowości sprawdza tylko `cmdline`
  (`templates/api.yaml:140-148`), więc zawieszony worker jest „gotowy”. Brak sondy liveness. Medium / Medium.
- [S] Przepustowość: relay outboxa to Doctrine `SELECT … FOR UPDATE SKIP LOCKED` po jednym wierszu + publish z confirm +
  ack (kilka ms, [S] ~100–300 wiadomości/s). Konsument `events` (reindeks + invalidacja) ~20–60/s. Granicą jest konsument.
- [K] Skalowanie workera w poziomie jest możliwe co do zasady: Doctrine transport używa `SKIP LOCKED`, RabbitMQ obsługuje
  wielu konsumentów, a reindeks jest idempotentny dzięki wersjom zewnętrznym ES. Łańcuch `jobs` jest szeregowany blokadą
  wiersza runu. Blokuje tylko sztywne `replicas: 1` i wspólny proces dla wszystkich transportów. Zalecenie: osobne
  deploymenty per transport (relay, events ×N, jobs).
- [H] Przy zaległościach kolejka `events` rośnie na dysku RabbitMQ (wolumen 2 Gi, `values.yaml:164`). Przy ~1–2 KB na
  wiadomość 1 mln zdarzeń to ~1–2 GB, czyli blisko limitu wolumenu (który `local-path` i tak nie egzekwuje). RabbitMQ
  zatrzymuje publishery przy `disk_free_limit` albo alarmie pamięci (limit 1 Gi). Wtedy relay zwalnia, a outbox w
  Postgresie rośnie bezpiecznie (trwały bufor). Dobre zachowanie, ale bez alertu na długość kolejki.
- Uwaga (punkt 9): RabbitMQ przekazuje zdarzenia z outboxa w Postgresie do konsumenta w tej samej aplikacji. Przy obecnej
  skali to warstwa bez mierzalnej korzyści. Konsumpcja outboxa bezpośrednio (Doctrine transport z wieloma konsumentami)
  dałaby to samo. Przy wielu niezależnych konsumentach i fan-oucie RabbitMQ zaczyna mieć sens.

---

## 5. Keycloak

- [K] API weryfikuje tokeny **lokalnie**: RS256, issuer, audience, exp/nbf/iat, klucze z discovery/JWKS przez adres
  wewnętrzny, cache `cache.oidc` (`security.yaml:12-36,64-80`, `framework.yaml:15-16`; TTL z odpowiedzi JWKS, do 1 h).
  Brak introspekcji per żądanie. `gmp` przyspiesza weryfikację RSA (`apps/api/Dockerfile:10-12`). Użytkownik powstaje z
  claimów, bez odczytu bazy (`CustomerUserProvider.php`). Koszt weryfikacji to [S] <1 ms. Dobrze.
- [K] Cache JWKS jest per pod w systemie plików. Awaria Keycloaka po wygaśnięciu cache daje 503 dla zalogowanych
  (`KeySourceOutageTokenHandler`, `ListProductsController` OA 503). Odczyty publiczne działają bez tokenu.
- [K] TTL: access token ma domyślne 300 s (realm go nie nadpisuje), `ssoSessionIdleTimeout` 1800 s, „zapamiętaj mnie”
  30 dni (`shop.json:15-17`). Sklep odświeża token, gdy zostaje mniej niż 30 s (`refresh.ts:REFRESH_THRESHOLD_MS`).
  [S] Każdy aktywny zalogowany klient to ~1 odświeżenie na 270 s. 60 tys. zalogowanych daje **~220 odświeżeń/s**. W
  Keycloaku 26 sesje użytkowników są domyślnie trwałe (w bazie), więc każde odświeżenie to zapis do Postgresa
  Keycloaka. [H] Waga: High / Low.
- [H] Logowanie: Keycloak 26 domyślnie hashuje hasła algorytmem Argon2 (dziesiątki ms CPU i kilka MB pamięci na hash).
  Przy 500m CPU w requestach i limicie 2 Gi szczyt logowań to [S] ~20–50/s na replikę.
- [K] Chart: `start` (tryb produkcyjny, nie `start-dev`), bez `--optimized` i bez wcześniejszego `kc.sh build`
  (`templates/keycloak.yaml:13`). Każdy start robi augmentację (wolniejszy start, więcej pamięci). Compose używa
  `start-dev` (`docker-compose.yml:132`), co jest właściwe tylko lokalnie. Low / Low.
- [K] Brak ustawień puli JDBC (`KC_DB_POOL_*`), cache (`KC_CACHE`, stack) i metryk. Jedna replika, sterta 512 m–1 g przy
  limicie 2 Gi (`prod/values.yaml:88-89,99`). Keycloak 26 ma domyślnie stack `jdbc-ping`, więc dołożenie replik jest
  stosunkowo proste (wspólna baza, rozproszony Infinispan). [H]
- [S] Baza Keycloaka przy 1 mln użytkowników: ~1,7 GB (spec `demo-data/spec.md:148-151`) w tej samej instancji Postgresa
  co sklep (`KC_DB_URL …/keycloak`, `templates/keycloak.yaml:17-18`). Ruch sesji konkuruje o I/O i połączenia z bazą sklepu.

---

## 6. Obrazy: Garage i imgproxy

- [K] Adresy imgproxy podpisuje API. Rozmiary są zdefiniowane w jednym miejscu: 4 typy, 13 szerokości, tylko WebP
  (`apps/api/src/Catalog/Application/Image/ImageSize.php:13-60`, `ImgproxyUrlGenerator.php:29-43`). Zbiór wariantów jest
  ograniczony, co dobrze wpływa na hit ratio CDN.
- [K] Przed imgproxy stoi tylko Cloudflare z regułą cache dla całego hosta `img.<domena>` (`infra/k8s/README.md:196-197`).
  Nie ma lokalnego cache wyników (Varnish, nginx, dysk). Każde chybienie w Cloudflare to pobranie oryginału z Garage i
  przeliczenie. Na Cloudflare Free obiekty z długiego ogona są wypierane z PoP-ów, więc [H] dla rzadkich produktów
  chybienia będą częste.
- [K] imgproxy nie ma ustawionych `IMGPROXY_WORKERS`/`IMGPROXY_CONCURRENCY`, `IMGPROXY_MAX_CLIENTS` ani `IMGPROXY_TTL`
  (`templates/imgproxy.yaml:18-32`). Działają domyślne: [H] liczba workerów = 2 × CPU widzianych przez kontener (16 na
  węźle 8 vCore, bez limitu CPU) i `Cache-Control: max-age=31536000`. Źródła są ograniczone do 64 MB / 50 MP
  (`:28-32`). [S] Dekodowanie 50 MP to ~200 MB RAM na żądanie, więc kilka równoległych dużych źródeł przekracza limit
  poda 1 Gi (OOMKill). Zalecenie: `IMGPROXY_WORKERS` ≈ CPU limitu, `IMGPROXY_DOWNLOAD_BUFFER_SIZE`, limit źródeł bliższy
  rzeczywistym zdjęciom. Medium / Medium.
- [K] Garage działa na jednym węźle z `replication_factor = 1`, LMDB, metadane i dane na `local-path`
  (`infra/garage/garage.toml:5-7`, `templates/garage.yaml:39-43`). Kopia obrazów jest w nocnym backupie (gdy włączony;
  dziś `backup.enabled: false`, `prod/values.yaml:74-75`). Dla demo (pula ~4,3 tys. plików współdzielona przez produkty)
  to wystarcza. [S] Dla prawdziwego sklepu z 10 mln produktów i ~3–5 zdjęciami po 1–3 MB to **30–150 TB** oryginałów.
  Garage na jednym dysku jest wtedy wykluczony; właściwy jest zarządzany S3/R2. High / Low.

---

## 7. Zasoby i skalowanie w chartcie

### 7.1 Tabela zasobów (profil `prod`, `infra/k8s/environments/prod/values.yaml:93-108`)

Wszystkie usługi mają `replicas: 1` na sztywno (`_helpers.tpl:244,300`). Brak limitów CPU we wszystkich usługach (QoS
Burstable), brak HPA, PDB, anti-affinity i priorityClass. Sondy to wyłącznie readiness, bez liveness i startup.

| Usługa | Żądanie CPU | Żądanie RAM | Limit RAM | Repliki | Wolumen | Stan / uwagi |
|---|---|---|---|---|---|---|
| postgres | 500m | 1 Gi | 2 Gi | 1 (STS) | 10 Gi nominalnie | brak tuningu (`shared_buffers` 128 MB, `max_connections` 100); baza ~62 GB przy 10 mln ([S] spec) |
| elasticsearch | 1 | 3 Gi | 4 Gi | 1 (STS) | 10 Gi nominalnie | sterta 2 g; indeks ~20 GB ([S]); 1 shard, 0 replik |
| redis | 100m | 128 Mi | 512 Mi | 1 (STS) | 1 Gi | brak `maxmemory`, RDB domyślny |
| rabbitmq | 250m | 512 Mi | 1 Gi | 1 (STS) | 2 Gi | jedna kolejka classic |
| garage | 100m | 256 Mi | 1 Gi | 1 (STS) | 1 Gi meta + 20 Gi data | RF=1 |
| keycloak | 500m | 1,5 Gi | 2 Gi | 1 | – | sterta 512 m–1 g, `start` bez `--optimized` |
| api (FrankenPHP) | 1 | 512 Mi | 2 Gi | 1 | – | tryb klasyczny, wątki 2×CPU węzła; [H] 16 × `memory_limit 256M` może przekroczyć 2 Gi |
| worker | 500m | 512 Mi | 1 Gi | 1 | – | 6 transportów w jednym procesie |
| store (Next.js) | 500m | 512 Mi | 1,5 Gi | 1 | – (ISR na warstwie zapisywalnej kontenera) | jeden wątek JS; ISR lokalny |
| varnish | 100m | 256 Mi | 512 Mi | 1 | – | storage domyślny obrazu ([H] 100 MB) |
| imgproxy | 250m | 256 Mi | 1 Gi | 1 | – | workerzy domyślni (2×CPU węzła) |
| admin | 50m | 64 Mi | 128 Mi | 1 | – | statyczny panel |
| mailpit | 50m | 64 Mi | 256 Mi | 1 | – | w prod (`orderMail: mailpit`) |
| backup / job | 100m | 256 Mi | 1 Gi | – | 25 Gi `backupWork` | backup wyłączony |
| **Suma** (bez backup/job) | **~4,9 vCPU** | **~8,6 Gi** | **~16,9 Gi** | | | węzeł 8 vCore / 24 GB; budżet `make check`: ≤7 vCPU żądań, ≤20 GB limitów |

[K] Wartości z plików. [S] Rozmiary danych ze spec DEMO-DATA.

### 7.2 Wątki FrankenPHP a połączenia Postgresa

- [K] Caddyfile: `php_server` bez `worker` i bez `num_threads` (`apps/api/docker/frankenphp/Caddyfile:1-11`). FrankenPHP
  przyjmuje domyślnie 2 × liczba CPU widziana przez proces. Bez limitu CPU to CPU węzła, czyli **16 wątków**
  (`load.sh:180-185` czyta `total_threads` z logu i to potwierdza w metodyce).
- [K] Tryb klasyczny: każde żądanie uruchamia kernel Symfony od nowa (opcache bez preload, `prod.ini:1-4`). [S] Narzut
  ~5–20 ms na żądanie, a połączenia PDO nie są utrzymywane między żądaniami (nowe TCP + uwierzytelnienie do Postgresa,
  ES i Redisa przy każdym żądaniu). Worker mode FrankenPHP to najtańsza duża poprawa wydajności API. [H] High / Medium.
- [H] Połączenia: api do 16 + worker 1 + joby + Keycloak (pula Agroal, domyślnie do 100) + backup przy `max_connections=100`
  (domyślne, brak konfiguracji w `templates/postgres.yaml`). Przy 3 replikach API to 48 połączeń z samego API. Potrzebny
  PgBouncer (transaction pooling) albo jawne limity puli Keycloaka. High / Low.

### 7.3 Co skaluje się horyzontalnie, a co blokuje

| Usługa | Bezstanowa? | Skaluje się dziś | Co blokuje | Waga (docelowa / demo) |
|---|---|---|---|---|
| api | tak (JWT lokalnie, cache plikowy per pod jest tylko optymalizacją) | prawie: wystarczy zmienić `replicas` | `replicas: 1` na sztywno w helperze; połączenia Postgresa (7.2); brak HPA/PDB | High / Low |
| store | **nie** w praktyce | nie | ISR i data cache lokalne; `POST /api/revalidate` dociera do jednego poda, więc pozostałe serwują nieaktualne strony do 1 h (`page.tsx:10`); brak `cacheHandler` | Critical / Low |
| worker | tak (SKIP LOCKED, idempotentne handlery) | prawie | jeden proces na 6 transportów; łańcuch `jobs` celowo szeregowy | High / Medium |
| varnish | tak (cache per instancja) | tak, z kosztem: PURGE trzeba rozesłać do wszystkich instancji, a `HttpStorefrontCache` wysyła jeden PURGE na adres Service | PURGE do jednej instancji | Medium / Low |
| imgproxy | tak | tak | – (Garage RF=1 pod spodem) | Low / Low |
| keycloak | częściowo (jdbc-ping) | wymaga konfiguracji cache i bazy | jedna replika; współdzielony Postgres | Medium / Low |
| elasticsearch | nie | nie | 1 shard, 0 replik; zmiana liczby shardów = przebudowa | High / Low |
| postgres | nie | nie | jedna instancja, bez replik i PITR | Critical / Medium |
| redis | nie | nie | brak repliki/Sentinel | High / Low |
| rabbitmq | nie | nie | classic queue, jeden węzeł | Medium / Low |
| garage | nie | nie | RF=1, jeden węzeł | High / Low |

### 7.4 Pozostałe

- [K] Jeden węzeł, jeden dysk `local-path` dla Postgresa, ES, Garage, RabbitMQ i Redisa (`values.yaml:160-169`).
  Przebudowa ES (bulk), `pg_dump` backupu, checkpointy Postgresa i zapisy sesji Keycloaka konkurują o te same IOPS.
  [H] Najbardziej prawdopodobne wąskie gardło demo przy 10 mln produktów to dysk, nie CPU. High / High.
- [K] Brak limitów CPU: ES (agregacje) albo imgproxy mogą zająć wszystkie rdzenie i zagłodzić API i Postgresa. To
  świadoma decyzja dla jednego węzła, ale przy pomiarze trzeba patrzeć na `throttling` i `steal`. Medium / Medium.
- [K] Sondy: tylko readiness. `/health` API zwraca stałe `ok` (`HealthController.php:12-19`), co jest poprawne dla
  readiness (nie zależy od ES). Zawieszony proces (worker, Varnish child, Node) nie jest restartowany. Medium / Medium.
- [K] Sieć: Cloudflare przed wszystkimi hostami, origin przyjmuje ruch tylko z zakresów Cloudflare (`prod/values.yaml:43-68`,
  `docs/adr/k3s-argo-cd.md:33-35`). Edge cache tylko dla `img`. HTML sklepu i JSON `api.` idą zawsze do originu (F7).
  Traefik z k3s ma jedną replikę.

---

## 8. Metodyka testów obciążeniowych

Mocne strony:
- [K] Model **otwarty**: `ramping-arrival-rate` z krokami i progami `abortOnFail` (p95 > 2 s albo >1% błędów 5xx/sieci)
  (`load/saturate.js:25-60`), potem przebieg stały na 70% przepustowości (`load/README.md:24-25`). Nie ma zjawiska
  „coordinated omission”, a `dropped_iterations` jest raportowane (`saturate.js:45,103`).
- [K] Zasoby podów jak w prod (`environments/load/values.yaml:22-38`, test zgodności), obrazy produkcyjne, nowe IP i
  ciasteczka per iteracja (`load/README.md:38-39`), osobne liczenie 409/429.

Słabości:
- [K] **Gorący zbiór danych:** `discover()` czyta co najwyżej `DISCOVER_MAX_PAGES=100` stron po 48 produktów
  (`load/lib/data.js:47-61`), sortowanych po nazwie. Iteracje losują jednostajnie z ≤4800 slugów i fraz z ich nazw. Przy
  10 mln produktów test mierzy więc ~0,05% katalogu, który po rozgrzaniu w całości siedzi w ISR, Varnishu i page cache ES.
  Wynik zawyża przepustowość dla długiego ogona. Brak rozkładu Zipfa i ruchu crawlera. High / Medium.
- [K] **Mały katalog:** pomiar działa na danych `bin/seed-demo` z joba `initialize`
  (`charts/shop/files/scripts/initialize.sh:4-29`). Spec sama zastrzega, że wynik opisuje demo, a nie skalę docelową
  (`docs/vision.md:232-234`) i wymaga pomiaru `full` (`demo-data/spec.md:110-112`).
- [K] **k6 na tej samej maszynie:** k6 działa w kontenerze w sieci Dockera klastra k3d na tym samym hoście/VM
  (`load.sh:96-104`), bez limitu CPU. Przy setkach VU zabiera CPU testowanemu systemowi, a pomiar nie przechodzi przez
  sieć ani TLS. `node-stats.log` i `top.log` (`load.sh:10-11`) pozwalają to ocenić post factum, ale nie usuwają zakłócenia.
  Medium.
- [K] **Miks ruchu:** 10% home, 20% kategoria, 35% produkt, 25% wyszukiwarka, 10% koszyk (`load/lib/config.js:43-49`),
  checkout osobno z ograniczoną równoległością (ćwierć wątków FrankenPHP, `load.sh:185`). Brak uzasadnienia proporcji
  danymi (typowo listing + produkt > 80%, wyszukiwarka 10–20%). Low.
- [K] **Czego pomiar nie obejmuje:** zalogowanych klientów (sesje w Redisie, odświeżanie tokenów w Keycloaku, logowanie
  Argon2), panelu administracyjnego, obrazów (imgproxy, Garage, Cloudflare), plików statycznych, zapisów katalogu pod
  obciążeniem (zmiany cen → outbox → reindeks → PURGE → revalidate), purge `catalog` w trakcie ruchu, wdrożenia w trakcie
  ruchu, kolejki workera, dużych danych (10 mln produktów, 50 mln zamówień), awarii usług. High / Medium.
- [K] Kryterium nasycenia p95 > 2 s jest bardzo luźne dla sklepu (typowy cel to p95 < 500 ms dla HTML). Wynik „max
  sustained” oznacza raczej punkt załamania niż użyteczną przepustowość. Medium.

---

## 9. Zbędne lub przedwczesne elementy i braki

Przedwczesne albo bez mierzalnej korzyści przy obecnej skali:
- [K] **RabbitMQ** jako przekaźnik jednej kolejki `events` między outboxem w Postgresie a konsumentem w tej samej aplikacji
  (`messenger.yaml:40-51,107-113`). Dodaje usługę, wolumen, sondę z wyjątkiem AppArmor (`templates/rabbitmq.yaml:14-19`)
  i drugi hop opóźnienia. Do rozważenia, nie pilne. Low / Low.
- [K] **Varnish dla stron produktu** przy ISR i data cache Next.js (3.5). Dla listingu jest potrzebny, dla produktu daje
  mało. Low.
- [K] **Garage** dla demo z ~4 tys. plików, gdy kopie i tak idą do Cloudflare R2 (`prod/values.yaml:70-78`). R2/S3
  bezpośrednio uprościłby stos i backup obrazów. Low / Low.
- [K] **Mailpit w prod** (`orderMail: mailpit`) – świadoma decyzja demo, bez wpływu na wydajność.

Brakuje do skali docelowej:
1. Wspólnego cache ISR (Redis `cacheHandler`) albo cache HTML na CDN z purge po tagach (F2).
2. Edge cache katalogu (Cloudflare Cache Rules dla `/`, `/c/*`, `/p/*`, `api.…/api/v1/products*`) z krótkim TTL i
   stale-while-revalidate (F7).
3. Facetów tańszych przy szerokich zapytaniach: cache agregacji per kategoria (agregacje bez warunków wariantu zmieniają się
   rzadko), `track_total_hits` z limitem (np. 10 000), osobne zapytanie facetów cache'owane dłużej niż wyniki,
   denormalizacja `pairs` do pól keyword na produkcie zamiast nested-in-nested (F1).
4. Kilku shardów i replik ES na ≥3 węzłach, snapshotów ES, przebudowy online (drugi przebieg po `updated_at`) (F10).
5. Batchowania reindeksu przyrostowego (koalescencja zdarzeń per produkt w oknie, `bulk`), osobnych workerów per transport,
   metryki i alertu na długość kolejek (F4, F5).
6. PgBouncera, tuningu Postgresa, replik do odczytu i PITR (poza zakresem tego audytu, ale blokuje resztę).
7. Konfiguracji Redisa (`maxmemory`, polityka, trwałość) i repliki (F3).
8. Worker mode FrankenPHP (F9).
9. Liveness/startup probes, PDB, anti-affinity, HPA dla api, store i worker po usunięciu blokad z 7.3.
10. Metryk: Varnish (`varnishstat` exporter), ES (slow log, metryki zapytań), RabbitMQ (długość kolejki), Keycloak
    (`KC_METRICS_ENABLED`), Redis (`used_memory`, `evicted_keys`).

---

## 10. Rekomendowana infrastruktura i ścieżka skalowania

> To szkic oparty na analizie statycznej i szacunkach. Każdy rozmiar trzeba potwierdzić pomiarem z sekcji
> „Do zmierzenia”, zwłaszcza zapytań ES z facetami na 10 mln dokumentów i przepustowości workera.

### 10.1 Start prawdziwego sklepu (mały ruch, możliwość wzrostu)

| Warstwa | Rekomendacja | Dlaczego |
|---|---|---|
| CDN | Cloudflare (jak dziś) + reguły cache HTML katalogu i JSON publicznego API, krótki TTL + stale-while-revalidate, purge po URL/tagu | najtańszy sposób na odciążenie originu i ES |
| Baza | **zarządzany Postgres** (HA, PITR, kopie), osobna baza Keycloaka albo osobna instancja | trwałość danych i I/O; największy i najbardziej krytyczny stan |
| Obrazy | **zarządzany S3/R2** zamiast Garage; imgproxy za CDN | trwałość i rozmiar oryginałów; Garage RF=1 to SPOF |
| Aplikacje | 2 węzły k3s albo zarządzany k8s: api ×2 (worker mode), store ×2 ze wspólnym `cacheHandler` w Redisie, worker ×2 (relay+events, jobs osobno), Varnish ×1 z większym storage | pierwsze HA i rolling update bez przerwy |
| Wyszukiwarka | 1 dedykowany węzeł ES (16–32 GB RAM, sterta ≤50%, NVMe) ze snapshotami do S3; docelowo zarządzany ES/OpenSearch | izolacja I/O i pamięci od Postgresa |
| Redis | mały zarządzany Redis z repliką albo jeden węzeł z `maxmemory` i AOF | sesje i limity logowania |
| Keycloak | 1–2 repliki w trybie `start --optimized`, metryki; rozważyć zarządzane IdP | logowanie jest ścieżką krytyczną |
| Kolejki | zostawić RabbitMQ jednowęzłowy albo usunąć na rzecz konsumpcji outboxa; nie dokładać klastra bez potrzeby | prostota |

### 10.2 Ścieżka do skali docelowej (kolejność wynoszenia)

1. **Postgres** jako pierwszy na osobną maszynę lub usługę zarządzaną, z PgBouncerem: największe dane (~62–190 GB),
   najwięcej I/O, konkurencja o dysk z ES (F8).
2. **CDN dla HTML i API katalogu** oraz wspólny cache ISR: bez tego skalowanie store i ES nic nie da (F2, F7).
3. **Elasticsearch** na osobne węzły (3 węzły, np. 3–6 shardów, 1 replika, sterta 16–31 GB, NVMe), z przeprojektowaniem
   facetów (F1). Shardowanie wymaga `shard_size` w agregacjach termów (`ProductSearchQuery.php:139-141`).
4. **Obrazy na S3/R2 + CDN** (prawdziwe zdjęcia to dziesiątki TB).
5. **Poziome skalowanie api, store, worker** z HPA (po usunięciu `replicas: 1` z helpera, PURGE rozsyłany do wszystkich
   Varnishy albo jedna warstwa Varnisha z rozmiarem kilku–kilkunastu GB).
6. **Keycloak** 2–3 repliki z własną bazą (~220 odświeżeń/s przy 60 tys. zalogowanych [S]).
7. **Redis** z repliką i pamięcią na ~1 GB+ sesji [S]; **RabbitMQ** z kolejkami quorum na 3 węzłach dopiero, gdy pojawi
   się wielu konsumentów.

---

## Do zmierzenia

| # | Pomiar | Jak | Oczekiwany wynik / próg |
|---|---|---|---|
| M1 | Czas zapytania listingu ES z pełnymi facetami: strona główna, duża kategoria, fraza krótka, fraza + 2 osie + cena | `profile: true` i `took` na indeksie 1 mln i 10 mln produktów, zimny i ciepły page cache, sterta 2 GB vs 8 GB | [H] 1 mln: 100–500 ms; 10 mln: 0,5–5 s na stronie głównej. Próg: p95 < 300 ms ciepło, < 2 s zimno (limit klienta) |
| M2 | Udział `track_total_hits: true` i każdej agregacji w czasie | to samo zapytanie bez agregacji / z `track_total_hits: 10000` | [H] agregacje nested ≥60% czasu |
| M3 | Hit ratio Varnisha i liczba obiektów | `varnishstat` (`MAIN.cache_hit/miss`, `SMA.s0.g_bytes`, `MAIN.n_object`, `n_lru_nuked`) przy ruchu z rozkładem Zipfa na 10 mln produktów | [S] lista ogólnie 40–70%, filtry <30%, szczegóły długiego ogona <10%; storage 100 MB zapełniony w minutach |
| M4 | Rozmiar storage Varnisha | `varnishadm param.show`, `SMA.s0.g_space` | [H] 100 MB (domyślne obrazu) |
| M5 | Czas pełnej przebudowy indeksu | `catalog:reindex` na 1 mln i 10 mln, log `documents_per_second`, iostat dysku | [S] 1–3 tys. dok./s → 1–3 h na 10 mln; wąskie gardło: dysk albo `GetProductOffers` |
| M6 | Przepustowość workera dla zdarzeń ceny/stanu | wstrzyknąć 100 tys. `VariantPriceChanged` (nie z seeda), mierzyć opróżnianie outboxa i `events` | [S] relay 100–300/s, konsument 20–60/s; 1 mln zmian = 5–14 h |
| M7 | Reindeks poddrzewa kategorii z 1 mln produktów | przeniesienie kategorii, czas łańcucha `jobs`, opóźnienie `events` w tym czasie | [S] 1,5–4 h; opóźnienie zdarzeń rosnące o czas paczki |
| M8 | Pamięć Redisa na sesję | `MEMORY USAGE session:<id>` dla typowego klienta, `INFO memory` przy 10 tys. sesji | [S] 4–6 KB/sesję; 60 tys. aktywnych ≈ 250–360 MB; 30-dniowe ≈ 1 GB |
| M9 | Zachowanie Redisa przy limicie i BGSAVE | wypełnić do 400 MB w podzie 512 Mi, wymusić `BGSAVE` | [H] OOMKill i utrata sesji od ostatniego RDB |
| M10 | Koszt Keycloaka: logowanie i odświeżanie | k6 na `/token` (password/refresh grant), CPU, zapisy do bazy | [S] logowanie 20–50/s na replikę (Argon2), odświeżanie kilkaset/s; zapis do DB przy każdym |
| M11 | Wydajność API w trybie klasycznym vs worker mode FrankenPHP | ten sam test `saturate.js` na obu | [H] worker mode −30–60% latencji, +2–3× przepustowości |
| M12 | Liczba połączeń Postgresa pod obciążeniem | `pg_stat_activity` przy nasyceniu API i logowaniach | [H] zbliżenie do 100 przy 2–3 replikach API + Keycloak |
| M13 | Rozmiar cache ISR sklepu | przejście crawlerem 100 tys. produktów, `du` katalogu cache w podzie | [S] 60–150 KB/stronę → 6–15 GB na 100 tys. |
| M14 | Spójność stron przy 2 replikach store | zmiana ceny, porównanie HTML z obu podów | [K/H] jeden pod serwuje starą cenę do 1 h |
| M15 | Lawina po purge `catalog` | `storefront:cache:purge-all` pod obciążeniem, p95 i błędy 503 ES | [H] skok p95 > 2 s, 503 dla części listingów przy 10 mln |
| M16 | imgproxy pod obciążeniem chybień CDN | k6 na 13 rozmiarów × losowe obrazy, RSS poda, OOM | [H] OOM przy kilku równoległych źródłach 50 MP; przepustowość ~20–50 obrazów/s na 8 vCore |
| M17 | Zakłócenie wyniku przez k6 na tej samej maszynie | ten sam test z k6 na osobnej maszynie | [H] 10–30% wyższa przepustowość bez k6 na hoście |
| M18 | Test z długim ogonem | `discover` z próbką z całego katalogu (losowe strony/`search_after`) i rozkładem Zipfa | [H] przepustowość niższa o rząd wielkości względem obecnego wyniku |
