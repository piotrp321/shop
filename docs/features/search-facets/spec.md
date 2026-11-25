# SEARCH-FACETS: fasety listingu w osobnym zapytaniu z cache

Listing produktów (`GET /api/v1/products`) liczy dziś stronę wyników i fasety jednym zapytaniem Elasticsearcha.
Fasety (agregacje) to prawie cały koszt tego zapytania, a są takie same dla każdej strony i każdego sortowania tego
samego filtra. Ta zmiana liczy fasety osobnym zapytaniem i trzyma je w cache API, więc kolejne strony i równoległe
wejścia tego samego filtra płacą tylko za stronę wyników. Jest warunkiem danych w skali `server` na serwerze demo
(decyzja właściciela 2026-11-25) i poprawką błędu #954.

**Stan: wstrzymana, niezaakceptowana.** Właściciel wstrzymał 2026-11-25 prace nad SEARCH-FACETS i przejściem na profil
`server` do czasu audytu wydajności wyszukiwarki; jego wynik może zmienić to rozwiązanie (natywny request cache
Elasticsearcha, spłaszczenie facetów). Ustalenia cząstkowe: `docs/reports/search-performance-audit-partial-2026-11-25.md`.
Implementacji nie zaczynamy bez akceptacji właściciela.

Działanie listingu i definicje facetów opisuje `docs/features/search/spec.md`; ta specyfikacja opisuje tylko zmianę.
Twierdzenia o obecnym kodzie dotyczą `origin/main` z 2026-11-25 (`2b4258c7`).

Issues: #954, #781 (pozycja SEARCH-FACETS, R4), #484
Zakres: `apps/api/src/Catalog`, `apps/api/config`, `apps/api/tests`, `apps/api/openapi.json`, `apps/store/src/api/schema.d.ts`, `apps/store/src/catalog`, `apps/store/app`, `load/lib/data.js`, `infra/k8s/scripts/open.sh`, `infra/k8s/scripts/load.sh`, `infra/k8s/tests`, `docs/features/search/spec.md`, `docs/features/demo-scale/spec.md`, `docs/features/search-facets`, `docs/reports`

## Problem (pomiary)

- Audyt wydajności (`docs/reports/performance-audit-2026-11-18.md`, 3.4), lokalnie przy 1 mln produktów: zapytanie
  całego katalogu 577 ms, bez agregacji 3 ms. Agregacje to 96–99% czasu dla katalogu i działu, 81% dla częstej frazy.
- worf.pl, dane `medium` (`docs/reports/search-shards-worf-2026-11-25.md`): przy 4 shardach cały katalog przez API
  0,69 s, zimna strona główna 1,08 s; 8 jednoczesnych zimnych wejść na różne strony tego samego listingu daje
  24 z 24 odpowiedzi 500 w sklepie i 8 z 24 odpowiedzi 503 w API. Przyczyna: każde wejście liczy od nowa te same
  agregacje, a Elasticsearch (szczyt 3,31 rdzenia) nie mieści ich w limicie klienta 2 s.
- Przy 10 mln produktów koszt agregacji szerokiego zbioru rośnie z katalogiem: liniowo ok. 5–7 s dla całego katalogu
  (szacunek), czyli 503 na każdej zimnej stronie głównej. `make open` po `generate PROFILE=server` wymaga 200 od strony
  głównej, kategorii i wyszukiwarki.
- Fasety nie zależą od `page`, `perPage` ani `sort` (`ProductSearchQuery::aggregations()`); zależą od kategorii,
  frazy, wybranych opcji, ceny i `available`.

## Decyzje

F2 i F5 zaakceptował właściciel 2026-11-25; F1, F3 i F4 są uzupełnione o jego uwagi z tego dnia i czekają na akceptację.

| # | Decyzja | Rekomendacja przyjęta w specyfikacji | Odrzucone warianty |
|---|---|---|---|
| F1 | Gdzie cache | Pula cache Symfony w API, `CacheInterface::get()` (jak `cache.sitemap` w `CachingSitemapProductSource`), adapter plikowy w podzie `api`. W jednym podzie fasety brakującego klucza liczy jedno żądanie, a pozostałe tego klucza czekają na wynik (sprawdzone eksperymentem, „Ochrona przed równoczesnym liczeniem”). Między replikami `api` nie ma koordynacji: każdy pod liczy klucz raz na okres ważności (dziś jedna replika). Zajętość ograniczona usuwaniem przeterminowanych wpisów (`prune`) co najwyżej raz na minutę | Redis (dziś trzyma liczniki limiterów i sesje sklepu, bez polityki pamięci, audyt C1); cache w Varnishu (klucz to pełny URL ze stroną i sortem, więc każda strona liczy fasety od nowa); osobna usługa albo wstępne liczenie facetów w tle |
| F2 | Ważność wpisu | 5 minut, bez unieważniania zdarzeniami. Liczby przy fasetach mogą być do 5 minut starsze niż wyniki strony | Unieważnianie po każdej zmianie produktu, ceny albo stanu (każda zmiana kasowałaby fasety szerokich zbiorów, więc przy ruchu w panelu cache prawie nie działa, a kod rośnie); 60 s jak Varnish (5 razy więcej liczenia przy 10 mln) |
| F3 | Limit czasu zapytania facetów | 10 s (nowy parametr `app.search.facets_timeout`); strona wyników zostaje przy 2 s. Najdłuższa ścieżka żądania mieści się w limitach wszystkich warstw („Limity czasu na ścieżce żądania”) | Ten sam limit 2 s (przy 10 mln fasety szerokiego zbioru nigdy by się nie policzyły); 30 s (klient zapisu indeksu; za długo dla odwiedzającego) |
| F4 | Fasety niedostępne (limit czasu albo błąd Elasticsearcha tylko w zapytaniu facetów) | API odpowiada 200 ze stroną wyników i `facets: null`; sklep pokazuje listing bez filtrów i komunikat „Filtry są chwilowo niedostępne”. 503 zostaje tylko dla błędu zapytania strony. Błąd zapisuje w cache znacznik „niedostępne” ważny 15 s, nie 5 minut: żądania czekające na ten klucz dostają `null` od razu zamiast liczyć po kolei od nowa, a po 15 s następne żądanie próbuje ponownie | 503 dla całego listingu, jak dziś (jedna wolna agregacja zabiera odwiedzającemu wyniki); pusta lista facetów (wyglądałaby jak „brak filtrów”) |
| F5 | Czyszczenie po zastąpieniu danych | `make open` (po `generate`) i `load` czyszczą nową pulę w każdym podzie `api` razem z `cache.sitemap` | Klucz z nazwą indeksu (dodatkowy odczyt aliasu przy każdym żądaniu) |

## Rozwiązanie

### API

- `ProductSearch` dostaje dwa wywołania zamiast jednego: strona wyników (`query`, `post_filter`, `sort`, `from`, `size`,
  `track_total_hits: true`, bez `aggs`) i fasety (`query`, `size: 0`, `aggs` jak dziś, bez `post_filter`, `sort`
  i `from`). Body agregacji i mapowanie ich wyniku na `SearchFacets` się nie zmieniają.
- Zapytanie facetów idzie klientem z limitem `app.search.facets_timeout` (10 s); strona wyników zostaje przy
  `app.search.request_timeout` (2 s).
- Nowa pula `cache.search_facets` (`framework.yaml`, obok `cache.sitemap`; w `when@test` adapter `array`). Wartość to
  `SearchFacets` (liczby kategorii po identyfikatorze, opcje, cena, `availableCount`); nazwy, slugi i ścieżki kategorii
  handler dokłada z drzewa jak dziś, więc zmiana drzewa kategorii nie wymaga czyszczenia cache.
- Klucz: `facets-v1-` i SHA-256 kanonicznej postaci JSON filtra, zbudowanej z `ProductSearchCriteria` po walidacji:
  - kategoria: identyfikator małymi literami albo `null`;
  - fraza: po `trim`, ze zwiniętymi ciągami białych znaków do jednej spacji, w postaci NFC, małymi literami
    (`mb_strtolower`); pusta fraza to `null`. Zapytanie facetów używa tej samej znormalizowanej frazy. Analizator pól
    wyszukiwania sprowadza tekst do małych liter, więc wyniki się nie zmieniają; potwierdza to test (kryterium 2);
  - opcje: pary oś–wartość bez powtórzeń, posortowane po osi, potem po wartości;
  - `priceMin`, `priceMax`: liczby całkowite groszy albo `null`; `available`: `true` albo `false`;
  - kategorie facetu (`facetCategoryIds`): posortowane.
  Nie wchodzą `page`, `perPage` ani `sort`. Prefiks `v1` zmienia się przy zmianie postaci wartości albo zapytania
  facetów, więc wdrożenie nie czyta starych wpisów.
- Ważność wpisu 300 s (F2). Fasety niedostępne (F4): w cache trafia znacznik ważny 15 s, odpowiedź ma `facets: null`,
  a log ostrzeżenia na kanale wyszukiwarki podaje skrót klucza, czas i rodzaj błędu (bez frazy w treści).
- Zajętość: po zapisie nowego wpisu, nie częściej niż raz na 60 s na pod (znacznik czasu w katalogu puli), pula usuwa
  przeterminowane pliki (`PruneableInterface::prune()`). Na dysku są więc najwyżej wpisy z ostatnich ok. 6 minut: liczba
  różnych filtrów z tego czasu razy rozmiar wpisu (kilka KB). Każdy nowy wpis kosztuje zapytanie facetów, więc tempo
  przyrostu ogranicza przepustowość Elasticsearcha; przy 50 nowych filtrach na sekundę (więcej, niż Elasticsearch
  worf.pl liczy przy szerokich zbiorach) to ok. 18 tys. plików i do ok. 180 MB.
- Kolejność w handlerze: najpierw strona wyników, potem fasety (z cache albo policzone). Błąd strony wyników to 503
  jak dziś, bez liczenia facetów.
- OpenAPI: `facets` w `ProductPage` jest `nullable` z opisem F4 i F2 (liczby do 5 minut starsze); klient TS sklepu
  wygenerowany na nowo (`make -C apps/api openapi`, `make api-client`).
- Nagłówki listingu bez zmian (`public, max-age=60`, `xkey`). Odpowiedź z `facets: null` ma `Cache-Control: public,
  max-age=10`, żeby Varnish nie trzymał listingu bez filtrów przez minutę.

### Ochrona przed równoczesnym liczeniem (sprawdzone 2026-11-25)

`CacheInterface::get()` puli plikowej woła `LockRegistry::compute` (symfony/cache 8.1.11): blokada `flock` na jednym
z 24 plików źródłowych komponentu, wybranym przez `crc32(klucz) % 24`. Blokady `flock` należą do otwartego pliku, a każdy
wątek FrankenPHP otwiera plik osobno, więc działają między wątkami jednego procesu i między procesami na jednym
systemie plików. Nie działają między podami.

Eksperyment na obrazie API (FrankenPHP 1.13.5, PHP 8.5.12 ZTS, `php-server`, liczenie wartości trwające 2 s):

| Przypadek | Wynik |
|---|---|
| 8 jednoczesnych żądań tego samego klucza | 1 liczenie; wszystkie odpowiedzi po 2,0–2,1 s |
| 8 jednoczesnych żądań różnych kluczy | 8 liczeń równolegle, każde 2,0 s |
| 2 różne klucze na tym samym pliku blokady | drugi czeka na pierwszy, potem liczy: 3,8 s zamiast 2,0 s |
| 8 jednoczesnych żądań tego samego klucza, liczenie kończy się wyjątkiem | 8 liczeń po kolei: ostatnia odpowiedź po 16 s |

Wnioski dla rozwiązania:
- jedno liczenie na klucz w podzie działa bez dodatkowego kodu;
- różne klucze na jednym pliku blokady (1 do 24) liczą się po kolei, więc pierwsze wejście na filtr może czekać do
  dwóch czasów liczenia;
- nieudane liczenie bez zapisu w cache sprawia, że czekające żądania próbują po kolei od nowa. Stąd znacznik
  „niedostępne” na 15 s (F4): czekające żądania odczytują go zamiast liczyć;
- czekanie na blokadę kończy się po 30 s albo sekundę przed `max_execution_time` (w obrazie API 30 s, liczone czasem
  zegarowym przez FrankenPHP); wtedy żądanie liczy samo.

### Limity czasu na ścieżce żądania

| Warstwa | Limit | Źródło |
|---|---|---|
| Strona wyników w Elasticsearchu | 1 s połączenia + 2 s odpowiedzi | `app.search.connect_timeout`, `request_timeout` |
| Fasety w Elasticsearchu | 1 s połączenia + 10 s odpowiedzi | nowy `app.search.facets_timeout` |
| Czekanie na blokadę klucza | do 29 s | `LockRegistry` (wyżej) |
| Żądanie PHP | 30 s czasu zegarowego | `max_execution_time` (domyślne, obraz bez `php.ini`) |
| Caddy (FrankenPHP) | nagłówki 10 s, treść 60 s, odpowiedź bez limitu | `apps/api/docker/frankenphp/Caddyfile` |
| Varnish do API | pierwszy bajt 60 s | domyślne `first_byte_timeout`, VCL go nie zmienia |
| Sklep do Varnisha | bez limitu w kodzie (undici: 300 s) | `load-catalog.ts` |
| Traefik, Cloudflare | 60 s odczytu wejścia, 100 s do originu | domyślne |

Najdłuższa ścieżka bez błędu: żądanie czeka na liczenie innego klucza z tego samego pliku blokady (do 11 s), potem
liczy swoje fasety (do 11 s), plus strona wyników (do 3 s): ok. 25 s, czyli poniżej 30 s PHP i 60 s Varnisha.
Przy niedostępnych fasetach czekające żądania kończą się po czasie jednego liczenia (znacznik F4).

### Sklep

- `ListingFilters` i miejsca, które czytają `facets` (`CatalogListing`, strona główna, `/c/...`, `/szukaj`), obsługują
  `facets: null`: listing, paginacja i sortowanie działają, filtry zastępuje komunikat w `aria-live="polite"`
  „Filtry są chwilowo niedostępne. Odśwież stronę za chwilę.”. Wybrane filtry z adresu zostają w adresie i nadal
  zawężają wyniki.

### Konsumenci zmienianego kontraktu (`facets` nullable)

- Sklep: `CatalogListing.tsx` i `ListingFilters.tsx` oraz ich testy, strony `/`, `/c/[...path]`, `/szukaj` (testy stron,
  `fixtures.ts`), `listing-context`, `page-metadata`, `site.test.ts` (fixtury z fasetami).
- `load/lib/data.js` (`readFacets`): czyta `listing.facets.options` i `listing.facets.price`; przy `null` pomija
  kategorię jak przy `total` 0.
- Bez zmian: `e2e/tests/listing-filters.spec.ts` (fasety z API są w teście warunkiem wstępnym: przy `null` test kończy
  się czytelnym błędem, nie fałszywym wynikiem), `infra/k8s/smoke/checks.py` (czyta tylko `items` i `total`), panel
  (osobne API), `tools/evals-pilot` (archiwalne dane).

### Infrastruktura

- `infra/k8s/scripts/open.sh` czyści `cache.search_facets` w każdym podzie `api` razem z `cache.sitemap`
  (jedno `cache:pool:clear` z dwiema pulami); `load.sh` tak samo.

## Skutki

- Pierwsze wejście na filtr po wygaśnięciu wpisu płaci za fasety (przy `medium` do ok. 0,7 s, przy 10 mln szacunkowo
  kilka sekund do limitu 10 s); równoległe wejścia tego samego filtra w podzie czekają na ten jeden wynik zamiast liczyć
  go jeszcze raz. Kolejne wejścia przez 5 minut płacą tylko za stronę wyników.
- Równoległe pierwsze wejścia na różne filtry nadal liczą swoje fasety równolegle. Przy przekroczeniu 10 s odwiedzający
  dostaje wyniki bez filtrów zamiast strony błędu.
- Liczby przy fasetach mogą odbiegać od wyników do 5 minut po zmianie produktu, ceny albo stanu (dziś do 60 s).
  Wybór filtra zawsze daje wyniki z bieżącego indeksu.
- Zajętość cache: wpisy z ostatnich ok. 6 minut (wyżej); dysk poda `api` bez limitu `ephemeral-storage`, dysk węzła
  ma 161 GiB wolnego.

## Poza zakresem

- Głęboka paginacja: zapytanie strony z `from` do 9976 bez agregacji (sprawdza je pomiar, kryterium 7).
- `grace` w Varnishu (audyt C2), replika Elasticsearcha, więcej replik `api`.
- Koszt CPU renderu sklepu i cache HTML listingów (STORE-CACHE w #781).
- Ustawienia pamięci Elasticsearcha i Postgresa w `prod` (osobna decyzja przed `server`).

## Kryteria akceptacji

1. Fasety odpowiedzi są takie same jak dziś dla tych samych danych: istniejące testy integracyjne facetów
   (`ProductSearchFacetsTest`, `ProductSearchQueryTest`) przechodzą bez zmiany asercji o liczbach; nowy test porównuje
   fasety z zapytania łączonego (stan przed zmianą) z fasetami z osobnego zapytania dla filtrów: brak, kategoria,
   fraza, wybrana oś, dwie osie, cena, `available`.
2. Klucz cache (test jednostkowy): ten sam dla różnych `page`, `perPage`, `sort`, kolejności i powtórzeń `option`,
   białych znaków i wielkości liter frazy, wielkości liter identyfikatora kategorii; różny dla każdej innej kategorii,
   frazy, opcji, ceny i `available`. Integracyjnie: fasety i wyniki dla frazy `Buty  Biegowe` i `buty biegowe` są
   takie same.
3. Body: strona wyników bez `aggs`; fasety z `size: 0`, bez `post_filter`, `sort` i `from` (test body).
4. Równoczesne żądania tego samego filtra przy pustym cache, na prawdziwym serwerze FrankenPHP stosu compose (test
   funkcjonalny albo skrypt testowy z licznikiem zapytań facetów w podstawionym albo zliczanym kliencie):
   8 jednoczesnych żądań różnych stron i sortowań jednego filtra wykonuje dokładnie 1 zapytanie facetów, a wszystkie
   odpowiedzi mają te same fasety.
5. Równoczesne żądania różnych filtrów: 8 jednoczesnych żądań 8 różnych filtrów wykonuje dokładnie 8 zapytań facetów
   (po jednym na klucz), wszystkie odpowiedzi 200.
6. Niedostępne fasety: przekroczenie limitu albo błąd tylko w zapytaniu facetów daje 200, `facets: null`,
   `Cache-Control: public, max-age=10`. 8 jednoczesnych żądań tego klucza przy błędzie facetów wykonuje 1 zapytanie
   facetów i wszystkie kończą się w czasie jednego liczenia (nie po kolei). Po 15 s następne żądanie liczy fasety
   ponownie. Błąd strony wyników: 503 jak dziś (testy funkcjonalne).
7. Zajętość: po upływie ważności i kolejnym zapisie przeterminowane pliki puli znikają; `prune` biegnie najwyżej raz na
   60 s (test z podstawionym zegarem).
8. Sklep z `facets: null`: listing, paginacja i sortowanie działają, komunikat w `aria-live`, wybrane filtry zostają
   w adresie (test komponentu; E2E listingu bez zmian asercji). `load/lib/data.js` pomija kategorię z `facets: null`.
9. Pomiar po wdrożeniu na worf.pl (dane `medium`, 4 shardy), skrypty z `docs/reports/search-shards-worf-2026-11-25/`:
   - cały katalog przez API przy trafieniu w cache facetów: p95 < 100 ms ponad medianę `/api/v1/categories` (sieć);
   - 8 jednoczesnych zimnych wejść na różne strony jednego listingu w sklepie i w API: 24 z 24 odpowiedzi 200;
   - 8 jednoczesnych zimnych wejść na 8 różnych filtrów (cały katalog, dział, dwie podkategorie, dwie frazy, oś, cena):
     24 z 24 odpowiedzi 200 albo 200 z `facets: null`, żadnej 5xx;
   - ostatnia strona (`/?page=417`) 200;
   - fasety 36 zapytań `facets.py` takie same jak w raporcie z 2026-11-25 (różnica tylko przez zmiany danych od tamtej
     chwili, sprawdzona powtórką);
   Jedno liczenie na klucz potwierdzają kryteria 4–6 (Elasticsearch na serwerze nie ma metryk zapytań). Wynik w nowym
   raporcie w `docs/reports/`.
10. `make open` i `load` czyszczą obie pule w każdym podzie `api` (testy skryptów).
11. `make -C apps/api check`, `make -C apps/store check`, `make -C infra/k8s check` i testy aplikacji przechodzą.

## Przebieg na serwerze

Każdy krok zmieniający serwer wymaga osobnej zgody właściciela w rozmowie.

1. Wdrożenie `main` z tą zmianą (polecenie i zatwierdzenie właściciela).
2. Pomiar z kryterium 7 (tylko żądania GET) i raport.
3. Przed `generate PROFILE=server`: decyzja właściciela na podstawie raportu.

## Dokumentacja

W tym samym PR co kod:

- `docs/features/search/spec.md`: „Fasety” (osobne zapytanie, cache 5 minut, `facets: null`), „Zapytanie”,
  „Infrastruktura” (limit 10 s), „Scenariusze błędów”, „Ryzyka i ograniczenia” (liczby do 5 minut).
- `docs/features/demo-scale/spec.md`: `open` czyści obie pule.
- Komentarze w `framework.yaml`, `services.yaml` i nagłówki skryptów `open.sh` i `load.sh`.

Po wdrożeniu i pomiarze ta specyfikacja i plan są usuwane; trwały opis zostaje w `docs/features/search/spec.md`.

## Ryzyka

- Przy 10 mln produktów fasety całego katalogu mogą przekroczyć 10 s (szacunek 5–7 s przy 4 shardach, bez pomiaru).
  Wtedy strona główna pokazuje wyniki bez filtrów (F4), a każde wejście po wygaśnięciu próbuje policzyć je od nowa.
  Pomiar po `generate PROFILE=server` to pokaże; dalszy krok (wstępne liczenie facetów szerokich zbiorów) dopiero po
  nim.
- Cache i blokada są osobne w każdym podzie `api`: przy kilku replikach każdy pod liczy klucz raz na 5 minut, a pierwsze
  równoczesne wejścia na różne pody liczą go równolegle. Dziś jest jedna replika; więcej replik to osobna decyzja
  (wspólny cache).
- Blokada `LockRegistry` ma 24 pliki na wszystkie klucze i wszystkie pule z `CacheInterface::get()`: różne klucze na
  jednym pliku liczą się po kolei (wyżej). Nie dotyczy to wyników, tylko czasu pierwszego wejścia.
- Fraza w kluczu: długi ogon unikalnych fraz nie trafia w cache; ich fasety kosztują tyle co dziś, ale nie blokują już
  strony wyników.
