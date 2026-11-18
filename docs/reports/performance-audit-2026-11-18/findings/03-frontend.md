# Audyt wydajności i skalowalności frontendu (sklep Next.js i panel Vue)

Analiza statyczna eksportu rewizji (bez uruchamiania stosu). Ścieżki względem katalogu głównego repozytorium.
Skala docelowa: 10 mln produktów, 60 tys. jednocześnie aktywnych użytkowników (`docs/vision.md`, „Wymagania
niefunkcjonalne”). Każde znalezisko ma status („potwierdzone analizą kodu” albo „hipoteza do pomiaru”) i dwie wagi:
**przy skali docelowej** / **przy obecnym demo** (worf.pl, jedna replika, mały katalog).

## Streszczenie

Sklep jest zbudowany starannie pod kątem poprawności (deduplikacja `React.cache`, blokada odświeżania tokenów, HTML
niezależny od sesji, obrazy z `srcset`, Sentry ładowany leniwie). Największe ryzyka skali nie leżą w kodzie komponentów,
tylko w modelu cache i wdrożenia:

1. Wszystkie listingi (`/`, `/c/…`, `/szukaj`) są SSR przy każdym żądaniu, bez cache HTML (Next, Varnish ani CDN), a
   sklep ma na sztywno jedną replikę.
2. Cache ISR stron produktów leży w systemie plików kontenera: jest lokalny dla repliki, znika przy każdym wdrożeniu,
   rośnie bez limitu (także o strony 404 dowolnych slugów), a `/api/revalidate` trafia tylko do jednej repliki.
3. Każda zmiana drzewa kategorii unieważnia tag `catalog`, czyli cały Varnish i wszystkie strony ISR naraz, z `expire: 0`
   (blokujący render przy następnym wejściu).
4. Wywołania API ze sklepu nie mają timeoutów.
5. W produkcji (`demoNotice: true`) pasek demo wstawiany po odczycie `/api/site` w przeglądarce przesuwa całą stronę (CLS),
   a Lighthouse w CI tego nie widzi.

Panel jest dobrze przygotowany na duże dane (paginacja kursorem po stronie API, ograniczone liczniki, debounce,
`shallowRef`, polling z pauzą w ukrytej karcie); jego ryzyka są niskie.

## Tabela: trasa → tryb renderowania → wywołania API na render → cache

„Varnish” = `API_CATALOG_URL` (`http://varnish`), „API” = `API_INTERNAL_URL` (bezpośrednio). Liczby dotyczą renderu na
serwerze; osobno żądania przeglądarki po hydratacji (BFF).

| Trasa | Tryb renderowania (co go wymusza) | Wywołania API na render (serwer) | Cache HTML / danych |
|---|---|---|---|
| `/` (stan domyślny i z filtrami) | dynamiczny SSR przy każdym żądaniu: `searchParams` w `generateMetadata` i stronie (`app/page.tsx:60-72`) | 3: drzewo (layout, `app/layout.tsx:23`) ∥ lista → oferty (sekwencyjnie, `src/catalog/load-catalog.ts:66,91`); `/?category=` 2 (drzewo ∥ lista, `app/page.tsx:41`) | brak cache HTML; lista i oferty `no-store` w Next, w Varnishu `max-age=60` (lista) / `s-maxage=3600` (oferty) |
| `/c/[...path]` | dynamiczny SSR (`params` + `searchParams`, `app/c/[...path]/page.tsx:45-58`) | 3 sekwencyjnie: drzewo → lista → oferty (`:33,41`); nieznana ścieżka: drzewo → redirect (2) | jak wyżej; redirect w Varnishu po pełnym URL |
| `/szukaj` | dynamiczny SSR (`searchParams`, także w slocie `app/@search/szukaj/page.tsx:7-10`) | 3 (lista → oferty, drzewo z layoutu; z `kategoria` drzewo → lista → oferty, `app/szukaj/page.tsx:31-38`); fraza < 2 znaki: 1 (drzewo) | Varnish po pełnym URL (fraza i filtry → bardzo wysoka kardynalność kluczy) |
| `/p/[slug]` | ISR: `revalidate = 3600`, `generateStaticParams` → `[]` (`app/p/[slug]/page.tsx:10-14`), tagi `catalog`, `product-<id>`, `product-slug-<slug>` | trafienie ISR: 0; render: 3 = drzewo ∥ szczegóły → oferta (sekwencyjnie, `src/catalog/load-product.ts:60,72`) | cache ISR Next w systemie plików repliki + 50 MB LRU w pamięci (domyślne); Varnish `s-maxage=3600` |
| `/cart` | statyczny (prerender w buildzie, bez menu do rewalidacji `catalog`) | 0 | plik z builda; dane koszyka tylko w przeglądarce |
| `/checkout` | dynamiczny (`cookies()`, `app/checkout/page.tsx:18`) | 2: drzewo (Varnish) + `GET /payment-providers` (API bezpośrednio, `src/checkout/payment-api.ts:15`) | brak |
| `/checkout/confirmation` | dynamiczny (`cookies()`, `app/checkout/confirmation/page.tsx:55`) | 3: drzewo + status płatności ∥ dostawcy (`:37`); klient: + Redis i ewentualnie Keycloak | brak |
| `/konto/zamowienia`, `/konto/zamowienia/[id]` | statyczny / dynamiczny tylko przez `params`; dane w przeglądarce | 0–1 (drzewo) | — |
| `/sitemap.xml`, `/sitemaps/*`, `/robots.txt` | `force-dynamic` | 1 (lista chunków w Varnishu; chunk produktów zawsze `pass` do API, `infra/varnish/default.vcl:50-56`) | chunk produktów bez cache |
| BFF `/api/site` | `force-dynamic`, `no-store` (`app/api/site/route.ts:13,33`) | 0 | brak (choć treść jest stała dla wdrożenia) |
| BFF `/auth/session` | route handler | gość bez ciasteczka: 0; zalogowany: Redis GET (+ odświeżenie) + `GET /api/v1/me` (`app/auth/session/route.ts:23-29`) | `private, no-store` |
| BFF `/api/cart/summary` | route handler | gość bez `store_cart`: 0; gość z koszykiem: 1 API; zalogowany: Redis + (scalanie) + 1 API (`src/cart/bff.ts:162-190`) | `private, no-store` |

Każde **twarde** wejście na dowolną stronę dokłada w przeglądarce 3 żądania do Node (`/api/site`,
`/auth/session`, `/api/cart/summary`: `src/components/SiteSwitches.tsx:22-31`, `src/account/AccountMenu.tsx:26-34`,
`src/cart/CartBadge.tsx:25-42`). Nawigacje miękkie ich nie powtarzają (komponenty żyją w layoucie).

## Sklep (`apps/store`)

### 1. Strategia renderowania i cache ISR

**F1. Listingi są SSR przy każdym żądaniu, bez żadnego cache HTML; sklep ma jedną replikę** – potwierdzone analizą kodu.
Waga: **Critical** / Medium.
- `/`, `/c/…`, `/szukaj` czytają `searchParams`, więc są dynamiczne (`app/page.tsx:60-72`,
  `app/c/[...path]/page.tsx:45-58`); dane listy mają `cache: "no-store"` (`src/catalog/load-catalog.ts:41,79`). Jedynym
  cache jest Varnish przed API, nie przed sklepem (`infra/varnish/default.vcl:10-13`). Brak PPR/`cacheComponents`,
  `use cache` i `cacheHandler` (`next.config.ts:17-36`).
- Strona główna w stanie domyślnym (najczęściej odwiedzany adres) jest renderowana od zera dla każdego żądania, choć jej
  treść nie zależy od użytkownika.
- `replicas: 1` jest wpisane na sztywno w helperze wszystkich Deploymentów (`infra/k8s/charts/shop/templates/_helpers.tpl:244`),
  bez HPA i bez wartości w `values.yaml`; prod: 500m CPU request (`infra/k8s/environments/prod/values.yaml:103`).
- Skutek przy skali: 60 tys. aktywnych użytkowników to rząd 1–3 tys. odsłon/s; każdy render listingu to pełny render React
  (≈24 kafelki, mega menu, stopka) i 3 żądania HTTP do Varnisha. Nawet przy dobrym hit-rate Varnisha koszt CPU Node na
  render (hipoteza: 20–80 ms) wymaga dziesiątek rdzeni, a chart nie pozwala wyjść poza jedną replikę.
- Kierunek: cache HTML stanu domyślnego (`/`, `/c/<ścieżka>` bez parametrów i strona 1) przez ISR z tagami albo PPR
  (statyczna powłoka + dynamiczna siatka), `s-maxage` + reguła cache CDN dla HTML listingów bez parametrów, konfigurowalne
  `replicas`/HPA w chartcie.

**F2. Cache ISR stron produktów jest lokalny, ulotny i nieograniczony** – potwierdzone analizą kodu (rozmiary: hipoteza).
Waga: **Critical** / Medium.
- Brak `cacheHandler` (`next.config.ts`), więc Next używa domyślnego cache w systemie plików `.next` kontenera
  (`apps/store/Dockerfile:48-54`, `apps/store/CLAUDE.md`, „Obraz produkcyjny”) i 50 MB LRU w pamięci procesu. Deployment
  sklepu nie montuje wolumenu (`infra/k8s/charts/shop/templates/store.yaml:47-51`).
- Skutki:
  - **Zimny cache po każdym wdrożeniu i restarcie poda**: rolling update (`maxSurge: 1`, `_helpers.tpl:248-252`) przenosi
    ruch na pod z pustym cache; każda strona produktu to render z 3 odczytami Varnisha.
  - **Brak współdzielenia między replikami**: po zwiększeniu liczby replik każda ma własną kopię (N× renderów i dysku).
  - **`/api/revalidate` trafia do jednej repliki**: worker woła `storeUrl` (serwis k8s, `apps/api/src/Shared/Infrastructure/Http/Cache/HttpStorefrontCache.php:74-81`),
    więc `revalidateTag` (`app/api/revalidate/route.ts:57`) unieważnia tylko cache poda, który dostał żądanie. Pozostałe
    pokazują starą cenę do `revalidate = 3600`. Dziś (1 replika) to nie działa źle, ale blokuje skalowanie poziome.
  - **Brak eksmisji na dysku**: cache plikowy Next nie ma limitu. 10 mln stron × (HTML + payload RSC + segmenty
    prefetchu; każda strona zawiera też całe drzewo kategorii, F9) → hipoteza: 100–400 KB na stronę, czyli 1–4 TB. Dysk
    efemeryczny węzła skończy się wcześniej (eksmisja poda przez `DiskPressure`).
  - **Strony 404 też są cache'owane** (`generateStaticParams` → `[]` przy domyślnym `dynamicParams`, a `notFound()` w ISR
    jest zapisywane; `apps/store/CLAUDE.md` opisuje unieważnianie „strony 404 sluga”). Robot albo atakujący z losowymi
    `/p/<slug>` zapełnia dysk i za każdym razem woła API przez Varnish.
- Kierunek: współdzielony `cacheHandler` (np. Redis z limitem pamięci i polityką LRU albo magazyn obiektowy), krótki TTL
  dla 404, cache HTML produktu w CDN z `s-maxage` i purge po tagu (zamiast trzymania 10 mln stron w Next).

**F3. Brak rozgrzewania i ochrony przed zimnym startem** – potwierdzone analizą kodu. Waga: High / Low.
Sonda gotowości sklepu to `/favicon.ico` (`store.yaml:38-45`), więc pod jest „gotowy” bez żadnej wyrenderowanej strony i
bez sprawdzenia API/Redisa; skrypt `load/warmup.js` istnieje tylko dla testów obciążeniowych.

### 2. Pobieranie danych

**F4. Wywołania API ze sklepu nie mają timeoutów** – potwierdzone analizą kodu. Waga: **High** / Medium.
`createApiClient` (`src/api/client.ts:14-20`) nie ustawia `signal`/`AbortSignal.timeout`; żadne wywołanie katalogu, koszyka
ani kasy go nie przekazuje (jedyny timeout to OIDC, `src/auth/oidc.ts:123`, i połączenie z Redisem,
`src/auth/redis.ts:13`). Domyślne limity undici w Node to 300 s na nagłówki i treść. Wolne API (Elasticsearch, Postgres)
przy SSR każdej odsłony oznacza narastanie otwartych renderów i pamięci procesu zamiast szybkiego `error.tsx`/503.
Kierunek: jeden timeout w `createApiClient` (np. 3–5 s dla katalogu, dłuższy dla kasy) i rozróżnienie w logach.

**F5. Wodospady w listingu kategorii i stronie produktu** – potwierdzone analizą kodu. Waga: Medium / Low.
- `/c/…`: drzewo → lista → oferty, trzy kolejne round-tripy (`app/c/[...path]/page.tsx:33,41`,
  `src/catalog/load-catalog.ts:66,91`). Drzewo jest potrzebne do zamiany ścieżki na id; oferty potrzebują id produktów.
- `/p/[slug]`: szczegóły → oferta (`src/catalog/load-product.ts:60,72`).
- Przy trafieniu w Varnish to 3× ~1–3 ms w klastrze, więc wpływ jest mały; przy chybieniu (filtry, wyszukiwanie) czas
  odpowiedzi API sumuje się. Kierunek: przyjmowanie ścieżki kategorii przez API listy albo oferta osadzona w odpowiedzi
  listy (zmiana kontraktu API) – tylko jeśli pomiar TTFB pokaże, że to istotne.

**F6. Deduplikacja jest zrobiona dobrze** – potwierdzone analizą kodu. `loadCatalogForRequest` (klucz = JSON zapytania,
`src/catalog/load-catalog.ts:95-102`), `loadCategoryTreeForRequest` (`src/catalog/category-tree.ts:32`),
`loadProductForRequest` (`src/catalog/load-product.ts:86`) i `loadCategoryRedirectForRequest` dzielą odczyty między
`generateMetadata`, layoutem i stroną; brak middleware/`proxy.ts`, więc żadnego kosztu na każde żądanie.

**F7. Drobne duplikaty po stronie przeglądarki** – potwierdzone analizą kodu. Waga: Low / Low.
Na `/checkout` sesja jest czytana dwa razy (`AccountMenu` i `CheckoutPage`, `src/checkout/CheckoutPage.tsx:409`), na
`/cart` koszyk dwa razy (`CartBadge` → summary i `CartPage` → pełny koszyk). Dla zalogowanego każde to Redis + API.

### 3. Invalidacja i burze rewalidacji

**F8. Zmiana drzewa kategorii czyści cały katalog naraz, z blokującym renderem** – potwierdzone analizą kodu.
Waga: **Critical** / Low.
`InvalidateStorefrontOnCategoryTreeChange` woła `invalidateTags([CATALOG])`
(`apps/api/src/Catalog/Infrastructure/Storefront/InvalidateStorefrontOnCategoryTreeChange.php:31`): twardy purge `xkey`
(`infra/varnish/default.vcl:45-47`) usuwa wszystkie obiekty katalogu z Varnisha, a `revalidateTag("catalog", { expire: 0 })`
(`app/api/revalidate/route.ts:57`) wygasza wszystkie strony ISR, bo każda strona produktu ma tag `catalog` (drzewo
z layoutu i odczyty produktu, `src/catalog/load-product.ts:59,72`). Zmiana nazwy jednej kategorii w panelu oznacza przy
skali, że każde kolejne wejście na dowolny produkt czeka na pełny render, a każdy render chybia Varnisha i idzie do API
(Postgres, Elasticsearch). To efekt „thundering herd” bez `grace` w Varnishu i bez stale-while-revalidate w Next.
Kierunek: miękki purge/grace w Varnishu, `revalidateTag` bez `expire: 0` dla tagu `catalog` (stale-while-revalidate),
osobny tag dla menu, docelowo menu poza HTML ISR produktu (F9).

**F9. Każda strona zawiera i serializuje całe drzewo kategorii** – potwierdzone analizą kodu (rozmiar: hipoteza).
Waga: High / Low.
`MegaMenu` jest Client Componentem, a dostaje pełne drzewo (`src/catalog/SiteHeader.tsx:39`,
`src/catalog/MegaMenu.tsx:1,10`): drzewo trafia do HTML (dwie wersje menu: pasek i lista na wąski ekran) i drugi raz do
payloadu RSC, na każdej stronie i w każdym wpisie ISR. Stopka dostaje to samo drzewo (`app/layout.tsx:34`). Przy katalogu
10 mln produktów z tysiącami kategorii to może być kilkaset KB HTML na stronę, mnożone przez 10 mln wpisów ISR (F2) i
dokładające się do LCP/TBT na telefonie. Kierunek: przekazywać do klienta tylko 3 poziomy, które menu pokazuje
(`LAST_LEVEL = 3`), albo renderować drzewo jako Server Component i hydratować tylko przełącznik paneli.

**F10. Masowa zmiana cen lub promocji to jedno żądanie PURGE i jedno POST na produkt** – potwierdzone analizą kodu
(skutek: hipoteza). Waga: High / Low.
Każde zdarzenie produktu (`InvalidateStorefrontOnPriceChange.php:53`, `InvalidateStorefrontOnPromotionChange.php:36`) to
`invalidateProduct` → osobny PURGE i osobne `POST /api/revalidate` z jednym tagiem (`HttpStorefrontCache.php:29-47`),
mimo że endpoint przyjmuje do 100 tagów. Promocja na 100 tys. produktów to 200 tys. żądań HTTP z workera, a do tego purge
tagu `product-<id>` usuwa każdy obiekt listy i ofert z tym produktem (`ListProductsController.php:137-140`), czyli
praktycznie cały cache list. Strony ISR rewalidują się leniwie, więc burza renderów jest ograniczona ruchem, ale z
`expire: 0` każde pierwsze wejście czeka na render. Kierunek: łączenie tagów w paczki (≤100) po stronie workera,
stale-while-revalidate zamiast `expire: 0` przy masowych zmianach.

**F11. Varnish jest mały i bez `grace`** – hipoteza do pomiaru. Waga: High / Low.
Chart uruchamia Varnish tylko z `-t 0` (`infra/k8s/charts/shop/templates/varnish.yaml:4`), bez `-s`, więc obowiązuje
domyślny magazyn obrazu (zwykle `malloc` 100 MB) przy limicie 512 Mi (`environments/prod/values.yaml:106`); jedna replika.
VCL nie ustawia `beresp.grace`/`keep` (`infra/varnish/default.vcl:61-69`), a lista produktów ma tylko `max-age=60`
(`ListProductsController.php:136`). Przy 10 mln produktów i kombinacjach filtrów hit-rate będzie niski, a co 60 s każdy
popularny listing czeka na API (coalescing ogranicza to do jednego żądania na obiekt, ale bez `grace` wszyscy czekają).

### 4. BFF i sesja

**F12. Trzy nie-cache'owalne żądania BFF na każde twarde wejście** – potwierdzone analizą kodu. Waga: Medium / Low.
- `/api/site` ma `no-store` (`app/api/site/route.ts:33`), choć jego treść jest stała dla wdrożenia; mógłby mieć
  `public, max-age=300` i być cache'owany w CDN.
- `/auth/session` i `/api/cart/summary` dla gościa bez ciasteczek nie dotykają Redisa ani API (dobrze), ale dla
  zalogowanego każde to `GET session:<id>` w Redisie i jedno wywołanie API (`/api/v1/me` z weryfikacją JWT, koszyk).
  Przy 60 tys. aktywnych i odsłonach twardych to kilka tysięcy żądań/s do Node, Redisa i API, nie do ominięcia przez cache.
- Kierunek: cache `/api/site`; połączenie `session` i `cart/summary` w jedno żądanie („kontekst nagłówka”) z jednym
  odczytem sesji; nazwa i e-mail w sesji Redis zamiast `GET /api/v1/me` przy każdym wejściu.

**F13. Odświeżanie tokenów jest zabezpieczone przed wyścigiem** – potwierdzone analizą kodu (dobrze zrobione).
`getFreshAccessToken` bierze blokadę `SET lock:refresh:<id> NX PX 5000`, sprawdza sesję ponownie po wzięciu blokady,
a czekający odpytują Redis co 100 ms (`src/auth/refresh.ts:95-162`). Redis „fail fast” (`disableOfflineQueue`,
`connectTimeout: 2000`, `src/auth/redis.ts:7-19`), jedno połączenie na proces (`src/auth/deps.ts:36-51`), limiter logowania
w Redisie. Koszt: odczyt sesji na każde żądanie BFF zalogowanego; czekający przy odświeżaniu robią do 2×50 komend Redisa.

**F14. HTML katalogu nie zależy od sesji** – potwierdzone analizą kodu (dobrze zrobione). Sesja, koszyk i przełączniki
ładuje przeglądarka, więc HTML ISR jest wspólny dla zalogowanych i gości (`src/catalog/SiteHeader.tsx:40-51`). CSRF to
tanie porównanie `Origin` i `Content-Type` bez wywołania API.

### 5. JavaScript po stronie klienta

**F15. Granice `"use client"` są rozsądne** – potwierdzone analizą kodu. Strony i listing są Server Components;
klienckie są tylko interaktywne części (`TileOffer`, `ListingFilters`, `SortSelect`, `MegaMenu`, `SearchBox`, galeria,
koszyk, kasa). `ListingProvider` przyjmuje serwerowe `children` (`src/catalog/listing-context.tsx:21-31`). Wyjątek: F9.
Zależności lekkie (`package.json`: radix-ui, lucide-react, openapi-fetch; bez bibliotek wykresów ani stanu).
Sentry przeglądarki ładowany przez `import()` dopiero po `/api/site` z DSN, bez tracingu i Replay
(`src/observability/lazy.ts:15-23`). Fonty z `next/font/local`, dwa podzbiory z `unicode-range`, `display: swap`
(`src/styles/fonts.ts`). Waga ryzyk: Low / Low.

**F16. Prefetch linków produktów w siatce** – hipoteza do pomiaru. Waga: Medium / Low.
Każdy kafelek ma dwa `<Link>` do `/p/<slug>` z domyślnym prefetchem (`src/catalog/ProductTile.tsx:41,46`). W produkcji
Next prefetchuje widoczne linki; dla trasy ISR to pełny payload RSC. Wejście na listing generuje więc kilkanaście–24
dodatkowych żądań do Node, a przy zimnym cache ISR (F2) każde to pełny render z 3 odczytami Varnisha. Kierunek: zmierzyć;
ewentualnie `prefetch={false}` albo prefetch przy hover.

**F17. Każda zmiana filtra to natychmiastowa nawigacja i render serwera** – potwierdzone analizą kodu. Waga: Low / Low.
Checkbox i dostępność wywołują `go()` → `router.push` od razu (`src/catalog/ListingFilters.tsx:124,159`,
`src/catalog/listing-context.tsx:25-29`); szybkie zaznaczenie trzech opcji to trzy rendery SSR (przerwana nawigacja nie
przerywa renderu na serwerze). Ceny są stosowane dopiero po Enter/blur (dobrze).

### 6. Obrazy

**F18. Obrazy produktów są dobrze zrobione** – potwierdzone analizą kodu. Zwykłe `<img>` z `srcset` z wariantów imgproxy
(WebP) zwróconych przez API, `sizes` dopasowane do siatki, `width`/`height` z proporcji (bez CLS), `loading="lazy"` poza
pierwszym rzędem, `fetchPriority="high"` dla pierwszego rzędu listingu i pierwszego zdjęcia galerii
(`src/catalog/ProductGrid.tsx:9-11,35`, `src/catalog/ProductPhoto.tsx:38-47`, `src/catalog/image-props.ts:6-27`,
`src/catalog/ProductGallery.tsx:124`). `next/image` wyłączony loaderem rzucającym błąd (bez optymalizacji w Node).
Zdjęcia marketingowe to statyczne WebP z budżetami KB, hero z `fetchpriority="high"`. Imgproxy za regułą cache Cloudflare.
Uwaga: tylko WebP, bez AVIF (Low).

### 7. Core Web Vitals

**F19. Pasek demo wstawiany po hydratacji przesuwa stronę (CLS) w produkcji** – potwierdzone analizą kodu.
Waga: Medium / **High**.
`DemoNotice` renderuje pasek nad nagłówkiem dopiero po odczycie `/api/site` w przeglądarce
(`src/components/SiteSwitches.tsx:19-33,39-47`, `app/layout.tsx:31`), a produkcja ma `demoNotice: true`
(`infra/k8s/environments/prod/values.yaml:21`). Cała strona przesuwa się o wysokość paska po każdym twardym wejściu.
Lighthouse w CI mierzy stos compose bez `STORE_DEMO_NOTICE` (domyślnie `false`), więc tego nie widzi. Kierunek:
zarezerwować miejsce (stała wysokość) albo pozycjonować pasek bez wpływu na układ.

**F20. Mniejsze przesunięcia w nagłówku** – hipoteza do pomiaru. Waga: Low / Low.
Licznik koszyka od 90rem jest w przepływie (`min-[90rem]:static`, `src/cart/CartBadge.tsx:56`), a ikony konta
zalogowanego pojawiają się po `/auth/session` (`src/account/AccountMenu.tsx:36-72`); oba zmieniają szerokość elementów
rzędu nagłówka. Wiersz komunikatu scalenia koszyka (`CartBadge.tsx:63-67`) jest rzadki.

**F21. Lighthouse nie pilnuje wydajności** – potwierdzone analizą kodu. Waga: Medium / Medium.
Blokuje tylko SEO; LCP, CLS, TBT i wynik wydajności są ostrzeżeniami (`e2e/lighthouse/lighthouserc.cjs:24-28`), mierzone
są trzy adresy demo na rozgrzanym lokalnym stosie (`:7`), bez budżetu rozmiaru JS/HTML i bez wariantu z paskiem demo.

### 8. Skalowanie serwera Next

**F22. Bezstanowość tylko częściowa** – potwierdzone analizą kodu. Waga: High / Low.
Sesje, transakcje logowania i limiter są w Redisie (dobrze), ale cache ISR jest stanem lokalnym poda (F2). `output:
standalone` tylko w obrazie (`next.config.ts:20`), użytkownik `node`, limit 1536 Mi. Kompresja: domyślne `compress` Next
(gzip w procesie Node, CPU) mimo Cloudflare przed originem – hipoteza: zbędny koszt CPU. `/_next/static` ma
`immutable` z Next i jest cache'owane przez Cloudflare po rozszerzeniu; HTML nie ma reguły cache CDN (`docs/features/deploy/spec.md`,
tabela adresów: reguła cache tylko dla `img.`), więc nawet strony ISR z `s-maxage` są obsługiwane przez Node.

**F23. Mapa strony produktów bez cache** – potwierdzone analizą kodu. Waga: Medium / Low.
`/sitemaps/products/[file]` jest `force-dynamic` (`app/sitemaps/products/[file]/route.ts:4`), a VCL przepuszcza chunki
produktów do API (`infra/varnish/default.vcl:50-56`). Przy 10 mln produktów każde pobranie chunku przez robota to zapytanie
do bazy; `max-age=300` z API (`SitemapProductsController.php:96`) działa tylko w przeglądarce/CDN, którego nie ma.

## Panel (`apps/admin`)

**F24. Jeden bundle bez podziału tras** – potwierdzone analizą kodu. Waga: Low / Low.
Wszystkie widoki są importowane statycznie (`src/router/index.ts:13-28`), `vite.config.ts:50-63` nie ma `manualChunks`;
`@sentry/vue` z `browserTracingIntegration` jest w bundlu zawsze, także bez DSN. Zależności są lekkie (Reka UI, lucide,
vueuse; bez wykresów, edytorów i bibliotek tabel), panel ma kilkudziesięciu użytkowników, `/assets/` z `immutable` i gzip
(`apps/admin/docker/nginx.conf:10-11,24-28`). Lazy routes dałyby niewielki zysk; nie jest to priorytet.

**F25. Listy na dużych danych są dobrze zaprojektowane** – potwierdzone analizą kodu.
Paginacja kursorem po stronie API, `total` ograniczony (`BoundedCountResponse`) tylko z pierwszą stroną
(`src/composables/useCursorPager.ts:13-18,41-43`), wiersze w `shallowRef` (`:54`), rozmiary 10/25/50, wyszukiwanie z
debounce 300 ms i limitem 2–100 znaków (`src/composables/useNameSearch.ts:7,56-71`), „wygrywa najnowsze żądanie”
(`latest`), stan listy w URL. Brak wirtualizacji jest w porządku przy ≤50 wierszach. Drzewo kategorii na ekranie
„Kategorie” renderuje tylko rozwinięte węzły (Reka `Tree`, `src/categories/CategoryTree.vue:109-119`).

**F26. Brak anulowania żądań i brak cache zapytań** – potwierdzone analizą kodu. Waga: Low / Low.
Starsze odpowiedzi są ignorowane, ale nie przerywane (`AbortController` nie występuje), więc szybkie stronicowanie albo
pisanie wysyła do API zapytania, których wynik jest wyrzucany (przy 50 mln zamówień każde może być kosztowne po stronie
API). Brak warstwy typu TanStack Query: powrót do listy i każde wejście na widok pobiera dane od nowa. Drzewo kategorii
jest pobierane przy każdym montowaniu widoków produktów, cen, stanów i formularza (`src/composables/useCategoryTree.ts:28-42`)
i trzymane w głęboko reaktywnym `ref` (`:24`); przy tysiącach kategorii to zbędna praca (Low/Medium).

**F27. Pulpit odpytuje API rozsądnie** – potwierdzone analizą kodu (dobrze zrobione).
Co 30 s, pauza w ukrytej karcie, odświeżenie po powrocie tylko gdy dane są starsze niż interwał, najnowsze żądanie
wygrywa (`src/dashboard/useDashboard.ts:27,69-99`); „Ostatnie zamówienia” dociąga 5 pozycji w tym samym rytmie
(`src/dashboard/useRecentOrders.ts:39`). Koszt agregacji pulpitu na 50 mln zamówień jest po stronie API (poza zakresem;
hipoteza do pomiaru: czas `GET /api/v1/admin/dashboard`).

**F28. Uwierzytelnianie panelu jest tanie** – potwierdzone analizą kodu. Token z pamięci (`InMemoryWebStorage`)
dokładany w middleware klienta (`src/api/client.ts:27-33`), odnawianie refresh tokenem przed wygaśnięciem
(`automaticSilentRenew`, `src/auth/config.ts:33`), guard bez żądań sieciowych przy ważnym tokenie
(`src/router/index.ts:158-167`), `/me` raz na załadowanie.

## 12. Zbędna albo przedwczesna złożoność

- **Dwie warstwy cache z podwójną invalidacją dla stron produktu** (Varnish przed API i ISR Next z tymi samymi tagami,
  PURGE + `POST /api/revalidate` dla każdego zdarzenia; `HttpStorefrontCache.php:34-47`), a jednocześnie listingi,
  najczęściej odwiedzane, nie mają cache HTML w ogóle. Złożoność invalidacji jest wysoka w miejscu, gdzie korzyść jest
  mniejsza (strona produktu ma już cache Varnisha danych), a brak cache tam, gdzie liczy się ruch (F1). Medium.
- **Odczyt przełączników wdrożenia w przeglądarce** (`/api/site`) dla zachowania identycznego HTML ISR we wszystkich
  wdrożeniach: dodatkowe żądanie na odsłonę i CLS (F19) w zamian za korzyść, która dotyczy tylko budowania jednego obrazu
  na wiele środowisk. Uzasadnione, ale tańsze byłoby cache'owanie `/api/site` i zarezerwowanie miejsca na pasek. Low.
- **Prefetch linków produktów** jest domyślny, nie świadomy (F16).
- Nie widzę złożoności dodanej „dla wydajności” bez korzyści w panelu; `React.cache` z kluczem JSON i blokada odświeżania
  w sklepie są uzasadnione. Kolejka i Web Locks zapisów koszyka służą poprawności, nie wydajności.

## Co jest zrobione dobrze (skrót)

HTML katalogu niezależny od sesji; deduplikacja odczytów na żądanie; brak middleware; blokada odświeżania tokenów w
Redisie i Redis „fail fast”; Server Components domyślnie; obrazy z `srcset`, wymiarami i priorytetem LCP; brak
optymalizacji obrazów w Node; fonty lokalne z `unicode-range`; Sentry przeglądarki leniwy, bez tracingu i Replay; tagi
ISR spójne z `xkey`; 4xx z API nie są cache'owane w ISR jako błąd (wyjątek → `error.tsx`); panel z paginacją kursorem,
ograniczonymi licznikami, debounce, `shallowRef` i pollingiem świadomym widoczności.

## Do zmierzenia

| # | Pomiar | Jak | Oczekiwany wynik |
|---|---|---|---|
| M1 | TTFB `/`, `/c/turystyka/biwak/namioty`, `/szukaj?q=namiot`, `/p/<slug>` na zimnym i ciepłym cache | k6 na k3d z obrazem `prod`; zimny = świeży pod sklepu i `varnishadm ban req.url ~ .`; ciepły = po `load/warmup.js`; osobno ISR trafienie i chybienie | listingi: ciepły Varnish p95 < 300 ms, zimny > 2× wolniej; produkt ISR trafienie p95 < 50 ms, chybienie ≈ listing. Różnica listing vs ISR potwierdzi F1 |
| M2 | Przepustowość jednego poda sklepu (rps przy p95 < 500 ms) dla `/` i `/c/…` | `load/saturate.js` przy 500m CPU | hipoteza: 30–100 rps na pod; z tego liczba replik potrzebnych dla 1–3 tys. odsłon/s (F1) |
| M3 | Rozmiar wpisu ISR i HTML strony produktu | `du -sh .next/server/app/p` po rozgrzaniu N stron / N; rozmiar HTML i payloadu RSC jednej strony; to samo z drzewem kategorii o 1–5 tys. węzłów | 100–400 KB na stronę; udział drzewa kategorii (F9) mierzalny w KB; ekstrapolacja na 10 mln (F2) |
| M4 | Wzrost dysku przez 404 | 10 tys. żądań `/p/losowy-slug-N`, potem `du` cache i liczba wywołań API w logu Varnisha | każdy slug = nowy wpis i chybienie Varnisha (F2) |
| M5 | Burza po zmianie kategorii | po rozgrzaniu 1 tys. stron produktu zmiana nazwy kategorii w panelu, potem k6 na produkty | skok p95 i liczby żądań do API do poziomu zimnego cache (F8) |
| M6 | Rewalidacja przy 2 replikach | `replicas: 2` na k3d, zmiana ceny, 20 żądań produktu | część odpowiedzi ze starą ceną do 1 h (F2) |
| M7 | Lighthouse (mobile) `/`, `/c/…`, `/p/…`, `/cart`, `/checkout` na produkcyjnym obrazie z `STORE_DEMO_NOTICE=true` i z drzewem kategorii w skali | `make lighthouse` z dodanymi adresami i zmienną | CLS > 0,1 z paskiem demo (F19); LCP < 2,5 s na demo; TBT rośnie z rozmiarem drzewa (F9) |
| M8 | Rozmiary chunków JS sklepu | `.next/static/chunks` po `next build` (gzip/brotli): wspólny chunk layoutu, chunk `MegaMenu`/`SearchBox`, `TileOffer`, `ProductGallery`, `CheckoutPage`, chunk Sentry | JS pierwszego wejścia na listing < 150 KB gz; Sentry osobny chunk ładowany po `/api/site` |
| M9 | Liczba żądań do Node na jedno wejście w listing | DevTools/HAR: dokument + `/api/site` + `/auth/session` + `/api/cart/summary` + prefetch `_rsc` produktów | 4 + 8–24 prefetchy (F12, F16) |
| M10 | Koszt BFF zalogowanego | k6: zalogowany użytkownik, twarde wejścia; liczba komend Redisa (`INFO commandstats`) i wywołań `/api/v1/me` na odsłonę | 2 GET Redisa i 2 wywołania API na odsłonę (F12) |
| M11 | Zachowanie przy wolnym API | `tc netem`/opóźnienie 10 s w API, k6 na listing | bez timeoutu żądania wiszą do limitu undici, pamięć poda rośnie (F4) |
| M12 | Hit-rate Varnisha w skali | `varnishstat` (`cache_hit`/`cache_miss`, `n_lru_nuked`) przy k6 z losowymi kategoriami, filtrami i produktami; rozmiar magazynu `-s` | niski hit-rate i wysokie `n_lru_nuked` przy domyślnym magazynie (F11) |
| M13 | Panel: liczba żądań na ekran | HAR wejścia na pulpit, produkty, ceny, stany, zamówienia, klientów, formularz produktu | pulpit 2 (+2/30 s), produkty 2 (drzewo + lista), formularz 3–5 (produkt, drzewo, klasy podatkowe, ceny ∥ stany); `/me` raz na załadowanie |
| M14 | Panel: rozmiar bundla | `dist/assets/*.js` gzip po `vite build` | jeden chunk ≈ 250–400 KB gz z Sentry i tracingiem (F24) |
| M15 | Panel: czas API dla list na pełnych danych | `GET /api/v1/admin/orders` (50 mln), `/products` (10 mln), `/customers` (1 mln), `/dashboard` z filtrami i wyszukiwaniem | p95 < 500 ms; to weryfikuje, że brak anulowania żądań (F26) jest niegroźny |
