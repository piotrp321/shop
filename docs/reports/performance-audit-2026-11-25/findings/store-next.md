# Audyt wydajności: sklep (Next.js, BFF, Varnish, cache HTTP)

Rewizja: main 2b4258c7. Analiza statyczna kodu i konfiguracji, bez pomiarów. Next `^16.4.6`, React `^19.3.1`
(`apps/store/package.json`), bez `cacheComponents`/PPR, bez `use cache`, bez `cacheHandler`.

## 1. Mapa tras: tryb renderowania i żądania do API

| Trasa | Tryb | Co wymusza | Żądania do API na render (ścieżka) |
|---|---|---|---|
| `/` | dynamic (SSR przy każdym żądaniu) | `searchParams` w `generateMetadata` i stronie (`app/page.tsx:61,70`) | layout: `GET /categories` (Varnish) ‖ strona: `GET /products` → **potem** `GET /product-offers` (seryjnie, `load-catalog.ts:79-104`). Wariant `?category=` czyta drzewo i listę równolegle (`app/page.tsx:41`) |
| `/c/[...path]` | dynamic | `searchParams` | `GET /categories` → `GET /products` (potrzebuje id kategorii z drzewa) → `GET /product-offers`: **3 kroki seryjne** (`app/c/[...path]/page.tsx:33-44`); nieznana ścieżka: + `GET /categories/redirect` |
| `/szukaj` | dynamic | `searchParams` (także slot `@search`) | (`GET /categories` gdy `kategoria`) → `GET /products?q=` → `GET /product-offers` |
| `/p/[slug]` | ISR, `revalidate=3600`, `generateStaticParams=[]` | — | `GET /products/{slug}` → `GET /product-offers?productId=` (seryjnie, `load-product.ts:65-77`) + drzewo w layoucie; odczyty z tagami → trafiają też do data cache Next |
| `/cart` | statyczna, `revalidate=3600` | — | 0 na serwerze; w przeglądarce `GET /api/cart` (BFF → API) |
| `/checkout` | dynamic | `cookies()` (`app/checkout/page.tsx:18`) | lista dostawców płatności (API bezpośrednio) + drzewo |
| `/checkout/confirmation` | dynamic | `cookies()` | status płatności + dostawcy |
| `/konto/zamowienia*` | statyczne ramy, dane w przeglądarce przez BFF | — | BFF: Redis + (Keycloak) + `GET /api/v1/me/orders` |
| `/sitemap.xml`, `/sitemaps/*`, `/robots.txt` | `force-dynamic`, `no-store` | jawnie | odczyty przez Varnish |
| `/api/*`, `/auth/*` (BFF) | route handlery, `private, no-store` | — | patrz sekcja 7 |

Deduplikacja w obrębie jednego żądania jest poprawna: `loadCatalogForRequest`, `loadCategoryTreeForRequest`,
`loadProductForRequest` to `React.cache`, więc `generateMetadata` i strona nie dublują wywołań [KOD]
(`load-catalog.ts:106-113`, `category-tree.ts:36`). Między równoległymi żądaniami Next nie deduplikuje renderów stron
dynamicznych (patrz F3).

## 2. Ścieżka błędu: dlaczego 503 z `GET /api/v1/products` daje HTTP 500 całej strony

[KOD] Prześledzone krok po kroku:

1. API: `ListProductsController.php:125-131` zamienia `SearchUnavailable` (przejściowy błąd ES) na 503;
   `PublicReadErrorCacheSubscriber` ustawia na 503 `Cache-Control: private, no-store` i zdejmuje `xkey`.
2. Varnish (`infra/varnish/default.vcl` = `infra/k8s/charts/shop/files/default.vcl`, identyczne): odpowiedź przechodzi
   przez `vcl_builtin_backend_response` → `private|no-store` → `vcl_beresp_hitmiss`: obiekt **hit-for-miss na 120 s**.
   Varnish oddaje 503 sklepowi. Brak `beresp.grace`/`keep` w VCL i brak `stale-while-revalidate`/`stale-if-error`
   w odpowiedziach API, więc grace to domyślne 10 s (`default_grace`), a po wygaśnięciu TTL+grace nie ma już starej
   kopii, którą można by podać przy błędzie. Brak `vcl_backend_error` i brak `return (abandon)` dla 5xx przy fetchu w tle.
3. Sklep: `loadCatalog` (`src/catalog/load-catalog.ts:93-101`) — 400 → `invalid-filter`, 404 → `unknown-category`,
   **każdy inny status → `throw new Error("GET /api/v1/products failed with status 503.")`**. Brak ponowienia, brak
   timeoutu (`AbortSignal`), brak wariantu „lista bez facetów”. (Dla porównania `loadOffers` degraduje łagodnie:
   błąd ofert → produkty bez cen, `load-catalog.ts:33-57`.)
4. Wyjątek leci z `readHome`/`read` zarówno w `generateMetadata`, jak i w stronie. Nie ma `loading.tsx` ani `<Suspense>`
   wokół listingu, a layout też czeka (`await loadMenuCategories()`), więc żaden bajt nie został wysłany przed błędem.
   Next renderuje granicę `app/error.tsx` („Nie udało się wczytać katalogu”) i odpowiada **500**. App Router nie
   pozwala stronie ustawić 503/`Retry-After` (tylko `notFound`/`redirect`/`forbidden`/`unauthorized`), więc z poziomu
   strony nie da się zwrócić 503 bez obejścia. Błąd zgłasza `onRequestError` do Sentry (jedno zdarzenie na żądanie).
5. Czy 500 jest cache’owane? **Nie** [KOD + dokumentacja]: strona dynamiczna dostaje od Next `Cache-Control: private,
   no-cache, no-store, max-age=0, must-revalidate`; Varnish nie stoi przed sklepem (tylko przed API), Cloudflare nie
   cache’uje HTML (`docs/features/demo-scale/spec.md:145`: jedyna reguła cache to host `img.`). Ale też **nic** z HTML
   listingów nie jest cache’owane przy sukcesie. Dla `/p/[slug]` błąd pierwszego renderu → 500 bez zapisu do ISR;
   błąd rewalidacji w tle zostawia starą stronę (zachowanie ISR).

Skutek uboczny kroku 2 (ważny pod obciążeniem): **po jednym 503 dla danego URL Varnish przez 120 s nie łączy
(coalescing) równoległych żądań tego URL** — hit-for-miss omija waiting list, więc każde wejście idzie do API/ES
równolegle, dokładnie wtedy, gdy ES jest przeciążony. Waiting list dla obiektu, którego fetch skończył się
nie-cache’owalnym 503, zostaje „wypuszczona” i jej żądania też idą do backendu. To mechanizm wzmacniający
(amplifikacja), nie przyczyna pierwotna. Przyczyną pierwotną 503 jest ES/API (obszar innego audytora).
Dokumentacja: https://varnish-cache.org/docs/trunk/users-guide/vcl-built-in-code.html (hit-for-miss, 120 s),
https://varnish-cache.org/docs/trunk/users-guide/vcl-grace.html (grace, stale przy błędzie fetchu w tle),
https://nextjs.org/docs/app/api-reference/file-conventions/error (granica błędu).

## 3. Varnish: co robi, a czego nie

[KOD] `default.vcl`:
- Stoi **wyłącznie przed API** (store → `API_CATALOG_URL=http://varnish`), nie przed sklepem. Przeglądarka nigdy go
  nie widzi. Cache’uje tylko `GET /api/v1/(products(/slug)?|categories(/redirect)?|product-offers)` i
  `sitemap/product-chunks`; reszta `pass`.
- TTL wyłącznie z `Cache-Control` API (`-t 0`): lista produktów `public, max-age=60` (bez `s-maxage`) → **60 s**;
  drzewo kategorii 60 s; szczegóły produktu i oferty `s-maxage=3600`; redirect kategorii 60 s.
- Hash: domyślny (URL + Host). Brak normalizacji query (`std.querysort`) — ale klucze od strony sklepu są już
  znormalizowane: `toApiQuery` buduje zapytanie API z rozparsowanego stanu w stałej kolejności, odrzuca `utm_*` i inne
  obce parametry (`listing-query.ts:126-140`). Jedyna niestabilność: kolejność powtarzanych `o=` (opcji) zależy od
  kolejności w URL użytkownika → `option=a&option=b` i `option=b&option=a` to dwa obiekty. [KOD] Niska.
- Grace 10 s (domyślne), keep 0, brak stale-if-error, brak `vcl_backend_error`, brak sondy backendu (`.probe`), brak
  `.first_byte_timeout` (domyślnie 60 s) i `.max_connections` (bez limitu równoległych połączeń do FrankenPHP).
- Storage: VCL/chart nie ustawia rozmiaru; obraz `varnish:9.1.0` ma domyślnie `VARNISH_SIZE=100M` (malloc)
  [HIPOTEZA: sprawdzić `varnishstat -1 -f SMA.s0.g_bytes -f SMA.s0.g_space`]; limit pamięci poda 512 Mi (prod).
- Coalescing: działa dla cache’owalnych missów (lista, oferty, drzewo) — 8 równoczesnych wejść na **ten sam** listing
  da 1 zapytanie do API na endpoint. Dla **różnych** URL (strony, sortowania, filtry, frazy) każdy jest osobnym
  zapytaniem do API/ES. Po 503 — patrz sekcja 2 (hit-for-miss 120 s).
- PURGE: twardy `xkey.purge` (nie soft), więc po purge nie ma nawet starej kopii w grace.
- `replicas: 1` (na sztywno w `_helpers.tpl:245`), jedna instancja Varnisha dla wszystkiego.

## 4. Przestrzeń kluczy cache

- Listing: per kategoria × `page` (24 na stronę, `ListProductsQuery.php:56`) × 4 sortowania × dowolne kombinacje `o=`
  × wolne liczby `cena-od`/`cena-do` × `dostepne`; `/szukaj` dodatkowo wolny tekst `q` (do 100 znaków). Przy 10 mln
  produktów: strona główna bez filtrów ma ~417 stron w oknie `max_result_window` 10 000, każda kategoria do tyle samo.
  [SZACUNEK] Liczba możliwych kluczy jest praktycznie nieograniczona; trafienia w Varnishu będą tylko dla „głowy”
  (strona 1–3 popularnych kategorii, domyślne sortowanie). Ogon zawsze idzie zimną ścieżką do ES.
- Frazy `q` są cache’owane w tym samym storage co katalog (60 s) — długi ogon fraz wypycha (LRU) obiekty listingów
  i oferty z 100 MB [HIPOTEZA: `MAIN.n_lru_nuked` > 0 pod ruchem wyszukiwania].
- HTML listingów: **brak cache na żadnej warstwie** (dynamic + Cloudflare bez reguły dla HTML). Każde wejście = render
  SSR w jednym procesie Node.
- Ciasteczka/zalogowanie **nie** wyłączają niczego: HTML listingów i produktu nie zależy od sesji (stan sesji ładują
  komponenty klienckie przez `/auth/session`, `/api/cart/summary`), serwer sklepu nie przekazuje ciasteczek przeglądarki
  do Varnisha [KOD: `catalogClient()` bez nagłówków]. To jest zrobione dobrze: kupujący z koszykiem idzie tą samą
  ścieżką co gość. (Ale ta ścieżka i tak jest zimna dla HTML, bo HTML listingów nie jest nigdzie cache’owany.)
- Vary: Varnish nie ma tu problemu (sklep nie wysyła `Accept-Encoding`-zależnych wariantów w specjalny sposób);
  ewentualny cache HTML na brzegu musi rozróżniać żądania RSC (`_rsc=` w query, nagłówek `RSC`) — patrz rekomendacje.

## 5. ISR stron produktu i unieważnianie

- Cache ISR w pamięci procesu, 128 MB (`next.config.ts:544,554`, `isrFlushToDisk: false`) ≈ 500 stron produktu
  (komentarz w configu). Ten sam limit dzieli data cache `fetch` (odczyty produktu mają tagi, więc w renderze ISR
  trafiają do cache Next). [SZACUNEK] Przy 10 mln produktów i ruchu z ogona (wyszukiwarki, crawlery, linki z listingów)
  hit ratio ISR ≈ udział top-500 w ruchu; dla reszty każda wizyta to pełny SSR + zapis do LRU + wypchnięcie innego
  wpisu. ISR działa zgodnie z przeznaczeniem tylko dla „głowy”.
- Tagi: 65 536 kubełków + `catalog` (`isr-tags.ts`) — ograniczony rozmiar listy tagów w pamięci; dobre rozwiązanie,
  nie ruszać. `revalidateTag(tag, { expire: 0 })` → następne wejście czeka na render (brak stale-while-revalidate po
  zmianie ceny — świadoma decyzja P5).
- **Unieważnianie trafia do jednego poda**: worker woła `POST http://store:3000/api/revalidate` przez Service
  (`HttpStorefrontCache.php:72-80`, `api.yaml:83`). Przy `replicas > 1` rewalidacja trafi do jednego losowego poda,
  a pozostałe pokażą starą cenę/stan do 1 h. Dziś `replicas: 1`, więc to bloker skalowania poziomego, nie błąd.
  [KOD] Wysoka (przy skalowaniu).
- **Każda zmiana drzewa kategorii czyści cały cache**: `InvalidateStorefrontOnCategoryTreeChange` i
  `ReindexCategoryBatchHandler` → `invalidateTags([catalog])` → twardy purge wszystkich obiektów Varnisha z tagiem
  `catalog` (czyli wszystkich: listy, oferty, szczegóły, drzewo) + rewalidacja wszystkich stron ISR. Zmiana nazwy albo
  kolejności kategorii w panelu = cały sklep zimny naraz, bez grace (twardy purge) → stampede na ES/API.
  [KOD] Wysoka.
- Zmiany masowe (ceny, stany): każde zdarzenie to osobny PURGE + osobny `POST /api/revalidate` z jednym tagiem
  (`invalidateProduct`), choć endpoint przyjmuje do 100 tagów. [SZACUNEK] 1 mln zmian = 1 mln żądań HTTP do jedynego
  procesu Node (konkurują z renderami klientów) i 1 mln PURGE. Koszt liniowy w liczbie zmian. Średnia.

## 6. Skalowanie procesu sklepu

- `replicas: 1` dla wszystkich Deploymentów, w tym `store` i `varnish` [KOD `_helpers.tpl:245`]. Prod: `store`
  requests 500m CPU, **bez limitu CPU**, pamięć 1536 Mi. Node renderuje w jednym wątku: przepustowość SSR ≈ 1 rdzeń
  niezależnie od 8 vCPU węzła. [HIPOTEZA: pod obciążeniem listingami CPU poda store ≈ 1000m i rosnące p95
  event-loop lag, reszta węzła wolna.]
- Stan w procesie: cache ISR + data cache (pamięć), lista tagów rewalidowanych. Sesje BFF w Redisie (dobrze, gotowe na
  wiele replik), limiter logowania w Redisie.
- Brak timeoutów na wywołaniach API z serwera sklepu (openapi-fetch → undici, domyślnie długie timeouty) + Varnish
  `first_byte_timeout` 60 s: przy wolnym ES rendery wiszą do 60 s, kumulują się w jednym procesie (pamięć, gniazda),
  a klient i tak dostaje 500. [KOD] Średnia.
- Zrzucenie się wszystkiego na jeden proces: każdy page view to dodatkowo 3 żądania przeglądarki do tego samego
  procesu (`/api/site`, `/api/cart/summary`, `/auth/session`) — patrz sekcja 7.

## 7. Koszyk, sesja, BFF: round-tripy na wizytę

[KOD] Na każdą stronę przeglądarka woła (Client Components w layoucie):
- `GET /api/site` (`SiteSwitchesProvider`) — tanie, tylko zmienne środowiska; bez `Cache-Control`, więc nie cache’owane.
- `GET /api/cart/summary` (`CartBadge`) — gość bez `store_cart`: 0 wywołań API; gość z koszykiem: 1 × API
  `summary`; zalogowany: Redis (`freshAccessToken`) + ewentualny refresh w Keycloaku + API `/me/cart/summary`.
- `GET /auth/session` (`AccountMenu`) — bez ciasteczka sesji 0; zalogowany: Redis (token) + Redis (`sessions.get`)
  + **`GET /api/v1/me` na każdą odsłonę** (`app/auth/session/route.ts:28-38`). Na demo `customerAccounts=false`, więc
  w praktyce zalogowanych klientów nie ma; przy publicznym sklepie to 2 wywołania API i ~3 odczyty Redis na odsłonę
  zalogowanego klienta tylko na nagłówek.
- Mutacje koszyka: przeglądarka → BFF → API (1 round-trip API na zapis, + ewentualnie tworzenie koszyka/merge), zapisy
  szeregowane w kolejce + Web Locks. Brak `router.refresh()`/`revalidatePath` po mutacji (grep) — licznik aktualizuje
  się zdarzeniem; listing nie jest przerenderowywany. Dobrze.
- Nawigacja filtrów/sortowania: `router.push` → żądanie RSC → pełny render strony dynamicznej (lista + oferty) bez
  layoutu; koszt jak pełne wejście minus drzewo.

## 8. Payload i komponenty

- `MegaMenu` jest Client Componentem, dostaje **całe drzewo kategorii** jako props (`SiteHeader.tsx`, `MegaMenu.tsx:64`):
  drzewo trafia do HTML (dwa warianty listy: szeroki i wąski, `MegaMenu.tsx:143,212`), do stopki (`SiteFooter`) i do
  payloadu RSC w całości (wszystkie poziomy, choć menu pokazuje 3). Przy ~100 kategoriach końcowych danych demo to
  kilkadziesiąt KB [HIPOTEZA: zmierzyć], ale rośnie liniowo z drzewem i jest na **każdej** stronie, także 404.
  Niska teraz, Średnia przy realnym drzewie (tysiące kategorii).
- Kafelki: `ProductGrid` serwerowy, `TileOffer`/`VariantChoice`/`AddToCartButton` klienckie z ofertą jako props →
  oferty 24 produktów (warianty, ceny) w payloadzie RSC + HTML. Rozsądne.
- Obrazy: bez optymalizacji Next (`loader` rzuca), warianty z imgproxy z podpisem API, Cloudflare cache’uje `img.` —
  dobrze. Fonty lokalne (`next/font/local`) — dobrze.
- Brak streamingu: brak `loading.tsx`/`Suspense` wokół listingu; TTFB = czas najwolniejszego odczytu z łańcucha
  (drzewo → lista → oferty). To też sprawia, że błąd = 500 (sekcja 2).

## 9. Znaleziska (z wagą)

### F1. Brak degradacji przy błędzie wyszukiwania: 503 API → 500 całej strony [KOD] — Wysoka
- Przyczyna: `loadCatalog` rzuca dla każdego statusu poza 400/404; brak ponowienia, brak stale w Varnishu (grace 10 s,
  twardy purge, brak stale-if-error), brak streamingu.
- Rodzaj: architektura/konfiguracja cache + kod sklepu. Koszt rośnie ze współbieżnością (przy przeciążeniu ES coraz
  więcej URL-i trafia w błąd), niezależny od danych.
- Rekomendacja (proste, natywne, bez nowych usług):
  1. Varnish: dla katalogu `set beresp.grace = 6h` (albo `stale-while-revalidate`/`stale-if-error` w `Cache-Control`
     API, które Varnish rozumie jako grace) i w `vcl_backend_response`
     `if (beresp.status >= 500 && bereq.is_bgfetch) { return (abandon); }` — przy błędzie fetchu w tle zostaje stara
     kopia. Przy wygasłej kopii klient dostaje dane sprzed ≤6 h zamiast 500; świeżość po zmianie ceny nadal daje purge
     po xkey. Koszt S.
  2. Varnish: 5xx katalogu nie jako hit-for-miss 120 s, tylko krótki cache błędu (np. `beresp.ttl = 2s`,
     `beresp.grace = 0s`, bez wołania builtina), żeby waiting list dostała ten sam błąd zamiast stampede. Koszt S.
  3. Sklep: jedno ponowienie `GET /products` przy 502/503/504 i błędzie sieci, z krótkim opóźnieniem (przez Varnish
     z coalescingiem jest tanie), oraz timeout odczytu (`AbortSignal.timeout`, np. 5 s) spójny z
     `first_byte_timeout` Varnisha. Koszt S.
  4. Opcjonalnie (Średnia, M): widok listingu z komunikatem zamiast `app/error.tsx` — ale App Router i tak da 200/500,
     nie 503; dla robotów lepsze 500 niż pusta lista 200. Nie zalecam „listy bez facetów” po stronie sklepu: wymaga
     rozdzielenia facetów od wyników w API (obszar API/ES).
- Efekt: [SZACUNEK] przy chwilowym przeciążeniu ES głowa ruchu (najpopularniejsze listingi) przestaje dawać 500; ogon
  nadal zależy od wydajności ES.
- Pomiar: patrz P1, P2.

### F2. Hit-for-miss po 503 wyłącza coalescing na 120 s (amplifikacja stampede) [KOD] — Wysoka
- Przyczyna: builtin VCL dla `private, no-store` (ustawiane przez `PublicReadErrorCacheSubscriber` na 503).
- Rekomendacja: jak F1.2. Koszt S. Pomiar: P2.

### F3. HTML listingów nie jest cache’owany nigdzie; każdy render w jednym wątku Node, `replicas: 1` [KOD] — Wysoka
- `/`, `/c/...`, `/szukaj` są dynamiczne (świadoma decyzja, `docs/vision.md:154`), Cloudflare nie cache’uje HTML,
  Varnish nie stoi przed sklepem. Najgorętsza strona sklepu (`/` w stanie domyślnym) jest renderowana przy każdym
  żądaniu. Next nie deduplikuje równoległych renderów tej samej strony dynamicznej [HIPOTEZA wg działania
  ResponseCache Next — deduplikuje tylko trasy cache’owalne (ISR); do potwierdzenia P3].
- Rodzaj: architektura. Koszt CPU liniowy w ruchu, stały w danych (o ile API odpowiada szybko).
- Rekomendacja, kolejność:
  1. `replicas` ≥ 2 dla `store` (S) — ale dopiero po rozwiązaniu F5 (rewalidacja ISR do każdego poda). Alternatywa na
     jednym podzie bez zmiany architektury: brak (Node = 1 wątek renderu).
  2. Krótki cache HTML listingów na brzegu (Cloudflare Cache Rule dla hosta sklepu, ścieżki `/`, `/c/*`, Edge TTL
     „ignore origin” 30–60 s, z kluczem obejmującym pełne query; żądania RSC mają `_rsc=` w query, więc nie mieszają się
     z HTML — do weryfikacji). HTML nie zależy od sesji, więc to jest bezpieczne z założenia projektu. Cloudflare jest
     już w stosie (bez nowej usługi). Koszt S–M; pomaga głowie, nie ogonowi. Uwaga: Cloudflare nie zna tagów Next/xkey —
     świeżość ceny na listingu ograniczona TTL (dziś lista w Varnishu i tak ma 60 s TTL, a oferty są purge’owane po
     zmianie ceny). To decyzja właściciela (zmiana obietnicy świeżości).
  3. Nie przenosić listingów do ISR: `searchParams` i długi ogon kluczy sprawiają, że ISR w pamięci 128 MB nic nie da.

### F4. Zimna ścieżka listingu: 3 seryjne odczyty na `/c/...` (drzewo → lista → oferty) [KOD] — Średnia
- Drzewo zwykle jest HIT w Varnishu (TTL 60 s), więc realnie 2 seryjne round-tripy do API przy missie:
  `/products` (ES) → `/product-offers` (PostgreSQL). Oferty nie mogą iść równolegle, bo potrzebują id z listy.
- Rekomendacja: oferty w odpowiedzi listy (API) albo endpoint listy zwracający oferty — usuwa jeden round-trip
  i jedno wywołanie PHP na render. Ale rozdzielenie ma sens cache’owy (oferty `s-maxage` 3600 i purge per produkt,
  lista 60 s). Zmierzyć udział `/product-offers` w czasie renderu (P4) zanim się to ruszy. Koszt M.

### F5. Rewalidacja ISR trafia tylko do jednego poda; cache ISR per proces [KOD] — Wysoka przy skalowaniu
- Rekomendacja (prosta): przy `replicas > 1` worker wysyła `/api/revalidate` do każdego poda (headless Service +
  rozwiązanie DNS na liście adresów) albo wspólny `cacheHandler` w Redisie (Redis już jest). Najprostsze: fan-out po
  headless Service. Koszt S–M. Bez tego `replicas > 1` daje stare ceny do 1 h.

### F6. Zmiana drzewa kategorii = twardy purge całego katalogu + rewalidacja wszystkich stron ISR [KOD] — Wysoka
- Każda zmiana drzewa w panelu (`InvalidateStorefrontOnCategoryTreeChange`) i koniec przebiegu reindeksu poddrzewa
  (`ReindexCategoryBatchHandler.php:76-79`, pusta partia) — czyli **dwa razy na jedną zmianę** — zeruje cały Varnish (wszystkie obiekty mają tag `catalog`) i cały ISR jednocześnie → zimny start całego sklepu przy
  ruchu.
- Rekomendacja: osobny tag dla drzewa (np. `category-tree`) zamiast `catalog`; listy z kategorią — purge tylko tej
  kategorii albo pozostawienie TTL 60 s; soft purge (xkey `softpurge`) + grace zamiast twardego, żeby kolejne wejścia
  dostały starą kopię i jeden fetch w tle. Koszt S–M. Pomiar: P6.

### F7. Brak timeoutów i limitów połączeń sklep → Varnish → API [KOD] — Średnia
- `first_byte_timeout` 60 s (domyślny), brak `.max_connections`, brak `AbortSignal` w sklepie. Przy wolnym ES rendery
  wiszą, kolejka rośnie w jednym procesie Node i w FrankenPHP.
- Rekomendacja: `first_byte_timeout` ~5–10 s dla backendu API, `.max_connections` równe liczbie workerów FrankenPHP
  (nadmiar czeka w Varnishu, nie w PHP), timeout w kliencie sklepu. Koszt S.

### F8. Masowe unieważnienia: po jednym żądaniu HTTP na produkt do jedynego procesu Node [KOD] — Średnia
- Rekomendacja: grupowanie tagów (endpoint przyjmuje 100) w workerze, albo debounce per kubełek. Koszt M.

### F9. Wyszukiwanie (`q`) cache’owane w tym samym storage Varnisha co katalog [KOD] — Niska/Średnia
- Ogon fraz wypycha listingi i oferty z ~100 MB. Rekomendacja: jawny rozmiar storage (`-s malloc,…` w granicach
  limitu poda) i krótszy TTL albo `pass` dla `q` z rzadkimi frazami; najpierw zmierzyć `n_lru_nuked` (P5). Koszt S.

### F10. Pełne drzewo kategorii w props Client Componentu na każdej stronie [KOD] — Niska (rośnie z drzewem)
- Rekomendacja: przyciąć drzewo do 3 poziomów i do pól potrzebnych menu przed przekazaniem do `MegaMenu`. Koszt S.

### F11. `/auth/session` woła `GET /api/v1/me` przy każdej odsłonie zalogowanego klienta [KOD] — Niska teraz (konta wyłączone na demo), Średnia przy publicznym sklepie
- Rekomendacja: `name`/`email` w sesji Redis (już tam jest `sub`) z odświeżeniem przy refreshu tokenu; `/me` tylko
  przy logowaniu. Koszt S.

### F12. Opcje `o=` w różnej kolejności to różne klucze Varnisha [KOD] — Niska
- Rekomendacja: sortowanie `option` w `toApiQuery` (sklep) albo `std.querysort` w VCL. Koszt S.

## 10. Co jest zrobione dobrze (nie ruszać)

- HTML niezależny od sesji; sesja i koszyk w Client Components przez BFF — zalogowanie ani koszyk nie psują cache.
- Normalizacja zapytania API w `toApiQuery` (stała kolejność kluczy, obce parametry odrzucone) — dobre klucze Varnisha.
- `React.cache` na odczytach (metadane + strona = jedno wywołanie na endpoint).
- Ograniczona lista tagów Next (65 536 kubełków) — pamięć `revalidatedTags` ma górną granicę.
- Cache ISR tylko w pamięci z limitem (brak wzrostu dysku od skanerów).
- Oferty listy degradują łagodnie (produkty bez cen zamiast błędu).
- xkey per produkt w liście: zmiana ceny purge’uje tylko listy zawierające produkt.
- Odczyty koszyka/kasy nie przechodzą przez Varnish; Varnish nie dostaje ciasteczek ani `Authorization`.

## 11. Co uprościć

- Warstwy cache dziś: (a) ISR w pamięci (tylko `/p/*`, ~500 stron), (b) data cache Next dla odczytów produktu w ISR
  (ten sam limit 128 MB, dubluje Varnish), (c) Varnish przed API (lista 60 s, oferty/szczegóły 3600 s), (d) pule cache
  Symfony/Redis w API (poza moim obszarem), (e) Cloudflare tylko obrazy/statyczne. Dla listingów działa w praktyce
  tylko (c). Dla produktu (a) i (b) mają sens tylko dla głowy.
- Uproszczenie bez utraty: odczyty produktu w ISR mogą mieć `cache: "no-store"` (data cache Next nic nie daje przy
  Varnishu, a zabiera miejsce stronom w 128 MB) — tagi wtedy nie przechodzą na stronę, więc wymaga to
  `cacheTag`/jawnego tagowania strony; [HIPOTEZA] do sprawdzenia zanim się zmieni. Niski priorytet.
- Strategia dla 10 mln produktów: cache nie pomoże ogonowi, więc priorytety to (1) szybka zimna ścieżka w API/ES
  (obszar innych audytorów: zapytanie listy i facety ≤ 100–200 ms p95 przy współbieżności), (2) brak amplifikacji
  błędów w Varnishu (F1, F2, F7), (3) poziome skalowanie renderu (F3 + F5), (4) krótki cache HTML głowy na brzegu
  (F3.2, decyzja właściciela).

## 12. Pomiary do wykonania (stos medium), najważniejsze najpierw

P1. **Zimny listing, ten sam URL, 8/32/100 równolegle** — po `varnishadm "ban req.url ~ ."` (albo restart Varnisha):
`k6`/`hey -n 100 -c {8,32,100} http://<store>/c/<dzial>/<podkategoria>` oraz `/`. Obserwować: statusy sklepu,
`varnishlog -g request -q 'ReqURL ~ "^/api/v1/products"'` (ile `BereqURL` do API — oczekiwane 1 przy coalescingu),
`varnishstat -1 -f MAIN.busy_sleep -f MAIN.busy_wakeup -f MAIN.cache_hitmiss -f MAIN.backend_req`, p50/p95 TTFB,
CPU poda store (`kubectl top`/cAdvisor), event-loop lag.
P2. **Zimny listing, różne URL-e** (strony 1..100 jednej kategorii, 4 sortowania, kilka filtrów) przy 8/32/100 VU:
to samo + czas `GET /products` po stronie API (log FrankenPHP/Sentry trace) i stan ES (`_nodes/stats/thread_pool/search`
rejected/queue). Potwierdza, że 503 pochodzi z ES/API, a 500 z rzutowania w `loadCatalog`. Po pierwszym 503 sprawdzić
`MAIN.cache_hitmiss` i liczbę `BereqURL` dla tego samego URL w ciągu 120 s (F2).
P3. **Deduplikacja renderów w Next**: 50 równoległych wejść na ten sam URL `/` przy ciepłym Varnishu; liczba renderów =
liczba żądań do Varnisha od sklepu (`varnishlog -q 'ReqURL ~ "^/api/v1/categories"'` zlicza po jednym na render).
Dla `/p/<zimny-slug>` to samo: ile renderów ISR (oczekiwane 1, jeśli batcher działa).
P4. **Rozkład czasu renderu listingu**: Sentry trace (próbkowanie 1.0 na stosie medium) albo `varnishlog` z
`Timestamp` — czas `/categories`, `/products`, `/product-offers` i czas samego renderu w Node; rozmiar HTML i payloadu
RSC (`curl -s -o /dev/null -w '%{size_download} %{time_starttransfer}' http://<store>/c/...` oraz z nagłówkiem
`RSC: 1`).
P5. **Varnish storage i LRU**: `varnishstat -1 | grep -E 'SMA|n_lru_nuked|n_object|cache_hit|cache_miss'` po 10 min
mieszanego ruchu (listing + `/szukaj` z losowymi frazami + produkty).
P6. **Purge drzewa**: zmienić kolejność kategorii w panelu pod stałym ruchem k6 na listingi; obserwować spadek
`cache_hit`, skok `backend_req`, p95 i statusy przez 2 min (F6).
P7. **Przepustowość jednego poda store** przy ciepłym Varnishu: rosnące VU na `/` aż do nasycenia; CPU poda ≈ 1 rdzeń?
To jest górna granica sklepu niezależnie od API (F3).
P8. **ISR hit ratio**: ruch k6 po 10 000 losowych slugach + 100 gorących; odsetek odpowiedzi z `x-nextjs-cache: HIT`
i pamięć poda (F5, sekcja 5).
P9. **Koszt nagłówka sesji** (gdy konta włączone): na odsłonę zalogowanego — liczba wywołań `/api/v1/me` w logu API.
