# CLAUDE.md – apps/store

Next.js (App Router), React, TypeScript, Tailwind CSS 4, shadcn/ui. Sklep dla klientów.
Zasady wspólne dla całego repo są w głównym `CLAUDE.md`, opis domeny i kierunek wyglądu w `docs/vision.md`.

## Wersja Next.js

Ta wersja Next.js ma zmiany łamiące: API, konwencje i struktura plików mogą się różnić od tego, co znasz.
Przed pisaniem kodu przeczytaj odpowiedni przewodnik w `node_modules/next/dist/docs/` (w kontenerze `store`,
`make -C apps/store shell`) i zwracaj uwagę na ostrzeżenia o wycofywanych funkcjach.
`next dev` nie generuje `AGENTS.md`, bo `next.config.ts` ma `agentRules: false`. Nie usuwaj tej opcji.
`next dev` nie zapisuje cache Turbopacka na dysku (`experimental.turbopackFileSystemCacheForDev: false`). `.next` leży
w zamontowanym katalogu źródeł, więc przeżywa kontener, a serwer uruchomiony na cache zapisanym dla innych źródeł
(nowy stos po zmianie gałęzi, jak między rundami `/integration`) wysyła w pętli komunikat HMR `restart`. Nie usuwaj
tej opcji.

## Nagłówki bezpieczeństwa

- `next.config.ts` (`headers()`, źródło `/:path*`, czyli każda trasa, także handlery API sklepu i `/_next/`) dodaje
  do każdej odpowiedzi: `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`,
  `X-Frame-Options: DENY`, `Permissions-Policy: camera=(), microphone=(), geolocation=()` i `Content-Security-Policy:
  frame-ancestors 'none'; object-src 'none'; base-uri 'self'`. CSP nie ma `script-src` (ani `default-src`): CSP z nonce
  wymusza renderowanie dynamiczne, co wyłączyłoby ISR stron produktów. Nie dodawaj dyrektywy skryptów ani nonce.
  Nagłówki nie zmieniają `Cache-Control` ani tagów cache. HSTS ustawia ingress (middleware Traefika przy `ingress.tls`),
  nie sklep. Test: `tests/security-headers.test.ts`.

## Komendy

Uruchamiaj z katalogu głównego przez `make -C apps/store <cel>`:
- `check` – ESLint i `tsc --noEmit` (po `next typegen`, który generuje typy tras do `.next/types`)
- `test` – wszystkie testy (Vitest), także integracyjne na usługach stosu
- `test-unit` – tylko testy jednostkowe, bez usług
- `shell` – powłoka w kontenerze `store`
- `check-dynamic-routes` – na buildzie produkcyjnym (`STORE_MODE=production make up`) czyta w kontenerze `store`
  `.next/prerender-manifest.json` (`scripts/check-dynamic-routes.mjs`) i kończy się kodem skryptu 1, gdy `/robots.txt`,
  `/sitemap.xml` albo trasa `/sitemaps/…` jest prerenderowana, a kodem 2, gdy `STORE_MODE` kontenera nie jest `production`
  (manifest po wcześniejszym buildzie zostaje na bind moucie także w trybie `dev`) albo manifestu brak lub jest
  nieczytelny; `make` pokazuje kod skryptu w wierszu `Error N` (sam kończy się kodem 2 przy każdym błędzie przepisu). CI
  uruchamia go w jobie `e2e` po starcie stosu.
- `check-isr-memory-cache` – na buildzie produkcyjnym z danymi (`make seed`) odwiedza strony produktów i unikalne
  nieistniejące slugi, aż wpisy przepełnią limit cache ISR (`scripts/check-isr-memory-cache.mjs`, ok. 1–2 min); kod 1,
  gdy w `.next/server` albo `.next/cache` zmienił się choć jeden plik albo najstarszy wpis nie został wypchnięty, kod 2,
  gdy nie da się tego sprawdzić (tryb inny niż `production`, brak buildu, API albo nieoczekiwany status strony). CI
  uruchamia go po E2E (`CI_AFTER_E2E`).

Klienta API generuje `make api-client` z katalogu głównego.

## Obraz produkcyjny

- `apps/store/Dockerfile` ma etapy `node` (wspólna baza), `build`, `prod` i `dev`; `dev` jest ostatni, bo compose buduje
  sklep bez `target`. `docker build --target prod apps/store` działa bez stosu i bez sekretów.
- `build` uruchamia `next build` z `STORE_STANDALONE=1` (mapy źródeł: patrz „Sentry”, w obrazie nie zostaje żaden `.map`). Tylko ta zmienna włącza w `next.config.ts` `output:
  "standalone"`; lokalny `STORE_MODE=production` (`next build` i `next start` w compose) buduje jak dotąd, bez standalone.
  Nie ustawiaj `STORE_STANDALONE` nigdzie poza Dockerfile.
- `prod` zawiera tylko serwer standalone (prześledzone zależności, `.next/static`, `public`), działa jako użytkownik
  `node` i startuje `node server.js` (`HOSTNAME=0.0.0.0`, `PORT=3000`). Konfigurację (`API_CATALOG_URL`,
  `API_INTERNAL_URL`, `STORE_URL`, `REDIS_URL`, `KEYCLOAK_*`, sekrety) czyta ze zmiennych środowiska przy starcie, nic
  nie jest wbudowane w obraz.
- Build nie woła API: strony prerenderowane w buildzie (`/cart`, 404) powstają bez menu (komunikat o drzewie kategorii
  w logu buildu to stan oczekiwany), a menu dostają przy pierwszej rewalidacji tagu `catalog`
  (`storefront:cache:purge-all`).

## Struktura

- Trasy są w `app/`, a komponenty, BFF i ładowanie danych obszaru w `src/<obszar>/` (`account`, `auth`, `cart`,
  `catalog`, `checkout`, `home`, `observability`, `seo`).
- `src/api/` – `client.ts` (jedyne miejsce rozmowy z API i jedyne miejsce wywołań `/auth/session`, `/api/cart/*`,
  `/api/checkout`, `/api/checkout/payment`, `/api/account/orders*` i `/api/site` z przeglądarki) i wygenerowany
  `schema.d.ts` (nie edytuj ręcznie).
- `src/home/` – strona główna w stanie domyślnym listingu (patrz „Strona główna `/`”): `HomeIntro` (`Hero`
  i `DepartmentTiles`), `home-content.ts` (tekst hero i przypisanie zdjęć do działów po slugu kategorii głównej),
  `departments.ts`, `home-images.ts`, `marketing-images.ts` (lista zdjęć marketingowych: jedyne miejsce ich rozmiarów),
  `images.generated.ts` i `images/*.webp` (wynik skryptu, commitowany; nie edytuj ręcznie).
- `src/site.ts` – `STORE_NAME`, jedyne miejsce nazwy sklepu: znak słowny w `SiteHeader`, `title` i `description`
  w `app/layout.tsx`, tytuły i opisy w `page-metadata.ts` i `product-metadata.ts` oraz JSON-LD strony głównej. Nie wpisuj
  nazwy w komponentach ani w metadanych.
- `src/components/ui/` – komponenty shadcn/ui.
- `src/styles/tokens.css` – jedyne miejsce tokenów wyglądu; `src/styles/fonts.ts` (`next/font/local`) i
  `src/styles/fonts/` – pliki WOFF2 fontu Outfit (`latin`, `latin-ext`); `THIRD_PARTY_NOTICES.md` – licencja OFL
  fontu i lista plików (przy przeniesieniu plików z vercel/shop także pełny tekst MIT).

## Dane i API

- Dane wyłącznie z API przez wygenerowanego klienta (`src/api/client.ts`). ESLint blokuje globalny `fetch`
  poza tym plikiem.
- Żadnej logiki biznesowej: ceny, sumy, dostępność i filtrowanie liczy API, sklep je wyświetla.
- `ProductTile` pokazuje etykietę według `level` z API („Dostępny”, „Ostatnie sztuki”, „Niedostępny”), bez liczenia na
  froncie (API nie zwraca liczby sztuk); liczba wariantów, ich kolejność i etykiety pochodzą tylko z oferty.
- Sklep woła API tylko z serwera, pod jednym z dwóch adresów w sieci compose:
  - `API_CATALOG_URL` (`http://varnish`): odczyty katalogu `loadCatalog` (lista, oferty) i `loadCategoryTree` (drzewo kategorii), `loadCategoryRedirect` (przekierowanie starego adresu kategorii) oraz `loadProduct` (szczegóły i oferta
    strony produktu) idą przez Varnish,
    który trzyma je według `Cache-Control` API; brak zmiennej to błąd, bez obejścia na adres bezpośredni;
  - `API_INTERNAL_URL` (`http://api`): koszyk, kasa, scalanie koszyka i logowanie (`/api/v1/me`) idą do API
    bezpośrednio (klient z `getAuthDeps()`), nigdy przez Varnish.
- Klient API (`createApiClient`) przyjmuje `tags`: trafiają do fetch jako `next: { tags }` (przez `requestInitExt`
  openapi-fetch, bo biblioteka wywołuje fetch gotowym `Request`). Tylko tak odczyty dostają tagi cache Next.js.
- Domyślnie Server Components. Client Component tylko tam, gdzie potrzebna jest interakcja.
- `generateMetadata` i strona pobierają listę przez `loadCatalogForRequest`, a drzewo kategorii przez
  `loadCategoryTreeForRequest` (oba `React.cache`), więc jedno wejście to jedno żądanie na endpoint
  (`loadCatalogForRequest` bierze zapytanie API; klucz cache to jego JSON, a `toApiQuery` buduje klucze zawsze w tej samej
  kolejności). Metadane katalogu (`src/catalog/page-metadata.ts`) opierają się na `STORE_URL`.
- HTML katalogu nie zależy od sesji. To, co zależy od zalogowania, ładuje Client Component przez `/auth/session`.

## Listing `/`, `/c/[...path]` i `/szukaj`

- Wszystkie trzy są Server Components renderowanymi przy każdym żądaniu (SSR, bez ISR) i wspólnym widokiem `CatalogListing`. Jedynym
  cache list jest Varnish: odczyty listy i ofert (`loadCatalog`) mają `cache: "no-store"` (bez `next.revalidate`
  i tagów), więc nie trafiają do data cache Next.
- Kolejność kategorii w drzewie (a więc w menu, stopce i kafelkach działów) daje API: pozycja rodzeństwa ustawiona
  w panelu, potem nazwa `pl_PL`, potem id. Sklep jej nie zmienia ani nie sortuje.
- Ścieżki kategorii, przekierowania starych adresów, widok listingu i SEO `/c/`: `docs/features/search/spec.md`,
  `docs/features/catalog-unknown-category/spec.md`, `docs/features/admin-features/spec.md`
  i `docs/features/catalog-page-metadata/spec.md`.
- Wszystkie adresy strony (paginacja, linki kategorii, chipy) buduje `listingHref(basePath, state, patch)` z adresu
  listingu bez parametrów (`basePath`) i jego parametrów (`state`).

## Strona główna `/`

- Hero, kafelki działów i stan domyślny listingu: `docs/features/visual-design/spec.md`.
- Priorytet ładowania ma tylko zdjęcie hero, kafelki produktów w tym stanie nie mają `priority`. Siatka produktów ma
  `data-testid="product-grid"`: testy E2E (`gridTiles` w `e2e/support/listing.ts`) znajdują kafelki po nim, nie po
  poziomie nagłówka.

## Wyszukiwanie `/szukaj` i parametry URL

- Parametry URL, wyszukiwanie, panel filtrów, aktywne filtry i ich SEO: `docs/features/search/spec.md`
  i `docs/features/catalog-page-metadata/spec.md`.
- Jedyne, co front liczy, to złote na grosze: napis z samych cyfr dostaje `00` (`100` →
  `10000`), każda inna wartość idzie bez zmian, więc API odpowiada 400. Liczby, zakresy cen, liczby przy wartościach
  i dostępność pochodzą z `facets` API; podpowiedź zakresu ceny to kwoty z API sformatowane `formatPrice`.

## Nagłówek i mega menu

- Wyszukiwarka, mega menu i układ nagłówka: `docs/features/visual-design/spec.md`, `docs/features/search/spec.md`
  i komentarze w `app/@search/`, `app/layout.tsx` i `src/catalog/SearchBox.tsx` (slot `@search`).
- Układ (CSS grid, jedno `SiteHeader` bez zdublowanego formularza): kolejność elementów w dokumencie jest kolejnością
  wizualną na każdej szerokości (WCAG 2.4.3), więc tabulator idzie tak samo w każdej przeglądarce, bez żadnej
  właściwości CSS zmieniającej kolejność fokusu, bez `order` i bez obszarów siatki przestawiających elementy względem
  dokumentu (nie dodawaj ich).
- Zmieniając układ sprawdź wymiary na 1024 px po zalogowaniu (zapas paska działów to kilkanaście pikseli przy siedmiu
  działach demo). Brak poziomego przewijania przy 375 px sprawdza `e2e/tests/site-header.spec.ts`; kolejność
  w dokumencie względem położenia, sekwencję tabulatora, jeden wiersz i wysokość poniżej 100 px na 1024–1920 px (gość
  i `jan`) oraz kompaktową wyszukiwarkę `e2e/tests/visual-foundation.spec.ts` (pomocnik `e2e/support/focus-order.ts`).
- Na stronie produktu (ISR) odczyt drzewa ma tag `catalog` (`loadCategoryTree`), więc strona rewaliduje się razem
  z katalogiem; odczyt nie ustawia trybu cache.
- Wszystkie linki są w HTML także przy zamkniętym menu (panele mają atrybut `hidden`), JavaScript tylko je otwiera.
- Błąd odczytu drzewa w layoucie: `console.error` na serwerze, nagłówek bez menu, strona renderuje się dalej (koszyk
  działa). Strona produktu w ISR może zostać zapisana bez menu do następnej rewalidacji.
- Strony prerenderowane statycznie w buildzie (`/cart`, strona 404) też dziedziczą z layoutu odczyt drzewa
  i tag `catalog`: mają menu z ostatniego renderu (z builda albo z rewalidacji tagu `catalog`, np. po `make seed`).
  Build bez dostępnego drzewa daje te strony bez menu aż do następnej rewalidacji tagu. Strony dynamiczne (`/`,
  `/c/...`, `/checkout/confirmation`) czytają drzewo przy każdym żądaniu.

## Stopka

- `SiteFooter` (Server Component w `app/layout.tsx`, `<footer>`) dostaje to samo drzewo co nagłówek (`loadMenuCategories`,
  jeden odczyt na żądanie, bez własnego żądania; testuje to `app/page.requests.test.tsx`). Grafitowe tło, mały znak
  „Worf” (link do `/`), linki kategorii głównych w dwóch kolumnach (`nav` „Linki w stopce”, nazwa nie zawiera „Działy”,
  bo e2e szuka nawigacji „Działy” po części nazwy) i pod cienką linią jeden wiersz „© <bieżący rok> Worf · Zdjęcia:
  Unsplash” (nazwa ze `STORE_NAME`). Bez linków do stron, których nie ma.

## Strona produktu `/p/[slug]`

- ISR: `revalidate = 3600` (siatka bezpieczeństwa), `generateStaticParams` zwraca `[]` (strony powstają przy pierwszym
  wejściu, nie przy buildzie). HTML nie zależy od sesji. Cache ISR istnieje tylko w buildzie produkcyjnym
  (`STORE_MODE=production`); `next dev` renderuje przy każdym żądaniu.
- Cache ISR (strony, także 404, i cache `fetch`) jest tylko w pamięci procesu: `experimental.isrFlushToDisk: false`
  i `cacheMaxMemorySize` (stała 128 MB w `next.config.ts`, nie zmienna środowiska). Nie włączaj zapisu na dysk i nie
  zmieniaj limitu bez nowego pomiaru pamięci wobec limitu pamięci kontenera `store` w chartcie; działanie i pomiar:
  `docs/features/product-page/spec.md`, „Cache ISR”. Pilnują tego `tests/isr-cache.test.ts` i `check-isr-memory-cache`
  (opcja jest eksperymentalna: jej zmianę przy podbiciu Next wykrywa test w CI).
- Dane (`loadProduct` w `src/catalog/load-product.ts`, wspólne dla `generateMetadata` i strony przez `React.cache`
  jako `loadProductForRequest`): `GET /api/v1/products/{slug}`, potem `GET /api/v1/product-offers?productId=<id>`, oba
  przez `API_CATALOG_URL`. Slug spoza formatu sluga produktu (`isProductSlug` w `load-product.ts`, ten sam wzorzec co
  w `/api/revalidate`) to 404 bez wywołania API. Tagi cache Next to te same nazwy, które API wystawia w `xkey`:
  szczegóły `catalog` i `product-slug-<slug>`, oferta `catalog` i `product-<id>` (UUID małymi literami). Strona
  dziedziczy sumę tagów odczytów, więc `revalidateTag` tych nazw unieważnia jej HTML, także strony 404 sluga, który
  właśnie został opublikowany.
- 404 szczegółów → `notFound()` (strona 404 z `noindex`; `generateMetadata` zwraca wtedy `{}`). Każdy inny błąd
  szczegółów albo oferty (status, sieć, nieoczekiwana treść, brak oferty produktu) to wyjątek, czyli `app/error.tsx`,
  którego ISR nie cache'uje; strona bez ceny nigdy nie powstaje.
- Widok, wybór wariantu (reguły w czystych funkcjach `variant-choice.ts`, z testami), metadane i JSON-LD:
  `docs/features/product-page/spec.md`, `docs/features/variants/spec.md` i `docs/features/promotions/spec.md`.
- Wartości pochodzą z oferty, front niczego nie liczy. Cena to zawsze cena skuteczna z API (bez przekreślonej ceny
  katalogowej). `PromotionNotice` pokazuje oznaczenie „Promocja” zawsze razem z linią „Najniższa cena z 30 dni przed
  obniżką: X zł” (Omnibus, `lowestPriorGross` przez `formatPrice`); `lowestPriorGross = null` albo brak promocji to
  żadne z dwóch.
- Galeria (`ProductGallery`, Client Component renderowany też na serwerze, z `usePhotoStrip` w `use-photo-strip.ts`; pełny
  opis i testy: `docs/features/product-page/spec.md`, „Galeria i powiększenie”). HTML z serwera ma cały pasek zdjęć
  (CSS `scroll-snap`, `id="zdjecie-n"`, `alt` z API), miniatury jako linki `#zdjecie-n` (`alt=""`, najmniejsza szerokość
  rozmiaru `product`) i licznik „1 / m”: działa bez JavaScriptu i jest taki sam przed hydratacją i po niej. Kolejność
  w dokumencie jest kolejnością wizualną (bez właściwości CSS zmieniających kolejność fokusu i bez `order`; nie dodawaj
  ich): lista miniatur (`Thumbnails`) jest w HTML dwa razy, kolumna po lewej od zdjęcia (`hidden lg:flex`) przed paskiem
  i rząd pod nim (`lg:hidden`) po pasku; ukryta ma `display: none` (poza Tab i drzewem dostępności), jej obrazy mają `loading="lazy"` i te same adresy co
  obrazy widocznej listy, obie mają ten sam `aria-current`. Testy Vitest w jsdom (bez CSS) widzą więc dwie listy; kolejność
  w dokumencie względem położenia i sekwencję Tab sprawdza `e2e/tests/product-gallery.spec.ts` (`e2e/support/focus-order.ts`).
  Rozmiary obrazów pochodzą tylko z API
  (`buildImageProps`, `buildZoomImageProps`, `smallestVariant` w `image-props.ts`); `STAGE_SIZES_SEVERAL` i `STAGE_SIZES_ONE` opisują tylko
  szerokość sceny galerii w układzie strony (z kolumną miniatur i bez niej). Test E2E dotyku używa `Input.dispatchTouchEvent`: `Input.synthesizeScrollGesture`
  z `gestureSourceType: "touch"` niczego nie przewija w tym Chromium.
- Wariant ze szczegółów jest możliwy do kupienia tylko, gdy oferta ma go (po `variantId`) z `purchasable = true`; cena,
  poziom i `purchasable` pochodzą z oferty, front tylko dopasowuje wybrane wartości do wariantu. Wariant możliwy do
  kupienia, jeśli jest jedyny (także spośród wielu), jest wybrany od pierwszego renderu (stan początkowy zależy tylko od
  danych, więc HTML ISR jest taki sam dla każdego). Wariantu nie ma w URL.
- `serializeJsonLd` zamienia każdy `<` na ucieczkę Unicode, więc tekst produktu nie zamknie elementu `script`.

## Mapa strony i `robots.txt`

- Trasy, treść i odpowiedzi mapy strony: `docs/features/seo/spec.md`.
- To własne route handlery (konwencja `sitemap.ts` z `generateSitemaps` nie tworzy `sitemapindex` ani 404 dla złego
  klucza). Segment `[file]` to `<klucz>.xml`; sklep nie dekoduje klucza, API go sprawdza.
- Sklep nigdy nie wysyła pustej ani częściowej mapy zamiast błędu.
- Bez data cache Next.js: trasy mają `export const dynamic = "force-dynamic"`, a każdy odczyt API `cache: "no-store"`. Cache
  to Varnish i pula API (jak przy listingu). Odczyty idą przez `API_CATALOG_URL`; `STORE_URL` i `API_CATALOG_URL` są czytane
  przy żądaniu, a brak którejkolwiek to błąd konfiguracji (wyjątek, 500).
- `app/robots.ts` (`MetadataRoute.Robots`, `dynamic = "force-dynamic"`, bo trasa metadanych jest domyślnie statyczna
  i utrwaliłaby `STORE_URL` z builda): `Disallow` dla `/cart`, `/checkout`, `/konto`, `/auth/`, `/api/`, reszta dozwolona,
  `Sitemap: <STORE_URL>/sitemap.xml`. Strony z `noindex, follow` zostają dozwolone, żeby robot widział ich dyrektywę.
  Reguły są takie same w każdym środowisku.
- Nowa trasa mapy albo `robots.txt` musi być dynamiczna; build produkcyjny pilnuje tego `check-dynamic-routes`
  (patrz „Komendy”).

## Rewalidacja (`POST /api/revalidate`)

- Woła ją tylko worker API (`StorefrontCache`) po purge Varnisha: po zmianie ceny albo poziomu dostępności z tagiem
  `product-<id>`, po edycji, publikacji albo wycofaniu produktu z tagami `product-<id>` i `product-slug-<slug>`,
  w `storefront:cache:purge-all` (koniec `make seed`) z tagiem `catalog`. Przeglądarka jej nie woła.
- `Authorization: Bearer <STORE_REVALIDATE_SECRET>` (z `.env.local`), porównany w stałym czasie (skróty SHA-256
  i `timingSafeEqual`, więc nie zdradza też długości); treść JSON `{ tags }`, 1–100 tagów, każdy `catalog`,
  `product-<uuid małymi literami>` albo `product-slug-<slug>` (slug w formacie sluga produktu: małe litery i cyfry
  w grupach łączonych pojedynczym `-`, do 200 znaków; słownik tagów API).
- Każdy tag: `revalidateTag(tag, { expire: 0 })`: następne wejście czeka na świeży render zamiast dostać starą stronę
  (P5). Odpowiedź 200 `{ revalidated: <liczba tagów> }`.
- Wszystkie odpowiedzi `private, no-store`. Sekret nie trafia do logów.

## Koszyk

- Przeglądarka rozmawia z koszykiem wyłącznie przez BFF (`/api/cart`, `/api/cart/summary`, `/api/cart/items`,
  `/api/cart/items/[variantId]`); API woła serwer sklepu. Route handlery są owinięte w `withCartDeps`
  (`src/cart/responses.ts`; zależności z `getCartDeps()` w `src/cart/deps.ts`, brak zależności daje 503 z `no-store`).
  Cała logika jest w `runCartCommand` (`src/cart/bff.ts`), testy podmieniają API dublerem z `src/cart/test-support.ts`.
- Koszyk wybiera sesja: zalogowany klient ma koszyk `/api/v1/me/cart` z tokenem z `freshAccessToken`, gość koszyk
  z ciasteczka `store_cart`. Ciasteczko zawiera id koszyka gościa podpisane (HMAC, `STORE_SESSION_SECRET`), nie
  szyfrowane; `HttpOnly`, `SameSite=Lax`, 30 dni odnawiane przy udanym zapisie. Niepoprawny podpis lub wartość spoza
  UUID jest usuwana jak brak ciasteczka (także przy sesji, wtedy bez scalania).
- Scalanie koszyka gościa, komunikat o obcięciu scalenia, podgląd kodu rabatowego i widoki koszyka:
  `docs/features/cart/spec.md`, `docs/features/visual-design/spec.md`, `docs/features/variants/spec.md`
  i `docs/features/discount-codes/spec.md`.
- Żądania zmieniające (`POST`, `PATCH`, `DELETE`) wymagają `Origin` równego `STORE_URL`, a `POST` i `PATCH` także
  `Content-Type: application/json`; inaczej 403 bez wywołania API.
- `variantId` spoza UUID w `/api/cart/items/[variantId]` (PATCH i DELETE) dostaje 404 „Tej pozycji nie ma w koszyku.”
  bez wywołania API i bez ruszania ciasteczek (API też odpowiedziałoby 404, który wyglądałby jak zniknięty koszyk).
- 404 w koszyku gościa może znaczyć „koszyk zniknął”, „brak pozycji” albo „brak wariantu”. BFF nie zgaduje po tekście
  `detail`.
- Zapisy koszyka z przeglądarki (`addCartItem`, `changeCartItemQuantity`, `removeCartItem` w `src/api/client.ts`) idą
  jeden po drugim we wszystkich kartach: kolejka na poziomie modułu (`queueWrite`) ustala kolejność zapisów karty
  (następny startuje po zakończeniu poprzedniego, także po błędzie), a każdy zapis z kolejki trzyma blokadę Web Locks
  API `store-cart-write` (`navigator.locks.request`, `exclusive`), gdy przeglądarka ją ma. Dzięki temu równoczesne
  pierwsze dodania gościa bez `store_cart` z kilku kart nie zakładają kilku koszyków. Odrzucony `request` daje `failed`
  bez wysłania żądania; funkcje zapisu nigdy nie odrzucają obietnicy. Bez `navigator.locks` (kontekst bez HTTPS poza
  `localhost`) działa sama kolejka karty i ta gwarancja obejmuje tylko jedną kartę. Odczyty nie czekają ani w kolejce,
  ani na blokadę. Testy ustawiają `navigator` przez `vi.stubGlobal` (Node ma własne `navigator.locks`).
- Po każdej zmianie koszyka komponent ogłasza `itemCount` z odpowiedzi zdarzeniem (`src/cart/events.ts`), a licznik się
  aktualizuje. Front niczego nie liczy: kwoty, sumy i stany linii pochodzą z ostatniej odpowiedzi API, a odrzucona
  zmiana zostawia ostatni widok.
- Odpowiedzi: 200 z danymi API, 404/409/422 z `{ detail }` API, 401 (sesja się skończyła: usunięta z Redisa i
  ciasteczka), 403, 503 (chwilowy błąd API, Redisa lub Keycloaka; sesja zostaje). Wszystkie z `private, no-store`.

## Kasa

- Kasa, płatność zamówienia, kod rabatowy w kasie i strona potwierdzenia (odpowiedzi, komunikaty, klucz idempotencji):
  `docs/features/checkout/spec.md`, `docs/features/payment/spec.md` i `docs/features/discount-codes/spec.md`.
- `POST /api/checkout` (`runCheckout` w `src/checkout/bff.ts`, owinięty w `withCartDeps`, testy na dublerze API
  z `src/cart/test-support.ts`) składa zamówienie całego koszyka i rozpoczyna jego płatność. Te same zabezpieczenia
  co zapisy koszyka (`Origin` równy `STORE_URL` i `Content-Type: application/json`, inaczej 403 bez API). Nagłówek
  `Idempotency-Key` wybiera przeglądarka; jego brak albo treść, która nie jest obiektem JSON, daje 422
  „Nieprawidłowe dane.” bez API. BFF przekazuje klucz do API bez zmian, także do rozpoczęcia płatności. Przeglądarka
  woła BFF przez `placeOrder(input, idempotencyKey)` w `src/api/client.ts` (wynik: `ok` z numerem i `redirectUrl`,
  `invalid` z `violations`, `conflict`, `not_found`, `unauthorized`, `rate_limited`, `failed`; funkcja nigdy nie
  odrzuca obietnicy).
- Adres klienta: BFF kasy, płatności i koszyka (`runCheckout`, `runPaymentStart`, `runPaymentStatus`, `runCartCommand`) owijają klienta API
  w `forwardingClientIp` (`src/checkout/client-ip.ts`), który do każdego wywołania API kopiuje nagłówek nazwany
  w `CLIENT_IP_HEADER` z żądania przeglądarki (w `prod` `CF-Connecting-IP` od Cloudflare). Bez zmiennej (compose,
  testy) albo bez nagłówka w żądaniu nic nie jest dodawane, a API liczy limity po adresie serwera sklepu. Wartość idzie
  bez zmian: API bierze ją tylko jako jeden poprawny adres IP (`docs/features/hardening/spec.md`, D2).
- Do API trafiają tylko pola kontraktu (`pickFields` w `src/checkout/api.ts`: gość `email`, `fullName`, `phone`,
  `street`, `postalCode`, `city`, `expectedTotalGross`, `discountCode`, klient bez `email`), z wartościami, jakie przysłała przeglądarka:
  sprawdza je API. `cartId` pochodzi wyłącznie z ciasteczka; ceny, linie i inne pola przeglądarki są pomijane.
- Sukces (201 albo 200 przy powtórzeniu z tym samym kluczem): ciasteczko `store_order_confirmation` z `{orderId,
  number, email, totalGross, currency, accessToken}` z `OrderView` (gość ma `accessToken`, klient `null`; JSON
  w base64url, podpisany jak `store_cart`, `HttpOnly`, `SameSite=Lax`, `Path=/`, `Max-Age` 1 godzina; stare ciasteczko
  bez `orderId` jest niepoprawne). Potem BFF rozpoczyna płatność tym samym `Idempotency-Key` (gość `Order-Access-Token`,
  klient `/me/...` z tokenem sesji) i odpowiada `{ number, redirectUrl }`. Każdy błąd rozpoczęcia płatności (także 409
  `PaymentIdempotencyKeyReused`, gdy klient zmienił dostawcę przy ponowieniu z tym samym kluczem złożenia) daje
  `redirectUrl: null` i wpis w logu bez tokenu: zamówienie stoi, a klient płaci ze strony potwierdzenia. `POST /auth/logout` usuwa to ciasteczko razem z sesją (zawiera e-mail zamówienia
  i token dostępu).
- Wszystkie odpowiedzi z `private, no-store`.
- `Idempotency-Key` (`crypto.randomUUID`, więc kasa wymaga bezpiecznego kontekstu: HTTPS albo `localhost`) to klucz
  jednej logicznej próby. Formularz ma `noValidate`: dane sprawdza API, a komunikaty 422 (`violations`) są przy polach,
  także komunikat o dostawcy przy grupie.
- Przyczynę 409 wyznacza wyłącznie `type` (`placeOrder` zwraca `reason` z `conflictReason`, mapa typów
  w `src/checkout/problems.ts` jest sprawdzana kompilatorem względem schematu), nigdy tekst `detail`. Żadne odrzucenie
  nie ponawia zamówienia samo. Po odrzuceniu fokus idzie na komunikat (`role="alert"`, `tabIndex={-1}`), a po 422
  z błędami pól na pierwsze błędne pole, tylko gdy został na elemencie, z którego wyszło wysłanie, albo zginął
  (`focusStayed` w `CheckoutPage.tsx`); przeniesiony przez klienta w trakcie żądania zostaje tam.
- `/checkout/confirmation` (`noindex, nofollow`, Server Component): czyta `store_order_confirmation` (`cookies()`
  i `readOrderConfirmation`, sekret z `readAuthConfig`). Bez ciasteczka, z niepoprawnym podpisem albo treścią:
  „Nie znaleźliśmy informacji o zamówieniu.” i link „Wróć do sklepu”. Inaczej czyta status płatności (`readPaymentStatus`:
  gość tokenem z ciasteczka, klient sesją) i listę dostawców, a do Client Componentu `PaymentConfirmation`
  przekazuje tylko numer, e-mail, kwotę, status i dostawców (nigdy `accessToken` ani `orderId`). Strona nie
  używa zegara przeglądarki do stanów; godzinę terminu formatuje `formatDeadlineTime` jawnie w `Europe/Warsaw`.

## Logowanie

- Tokeny OIDC nigdy nie trafiają do przeglądarki. Są tylko w sesji w Redisie, a ciasteczko zawiera wyłącznie
  id sesji podpisane kluczem `STORE_SESSION_SECRET`.
- Route handlery `/auth/*` są owinięte w `withAuthDeps` (`src/auth/responses.ts`), które daje im zależności
  (Redis, OIDC, klient API) z `getAuthDeps()` w `src/auth/deps.ts`, a przy ich braku odpowiada 503 z `no-store`.
  `getCartDeps()` bierze z nich sesje, `freshAccessToken`, klienta API i konfigurację. Testy podmieniają zależności
  dublerami z `src/auth/test-support.ts` (domyślny klient API odrzuca każde wywołanie).
- Nie loguj obiektów błędów openid-client (ich `cause` zawiera odpowiedzi z tokenami). Użyj `logFailure`.
- `returnTo` przechodzi zawsze przez `sanitizeReturnTo`.
- Limit `GET /auth/login` (`src/auth/login-rate-limit.ts`; licznik, klucz i okno: `docs/features/hardening/spec.md`,
  D4 i D5). Powyżej `STORE_LOGIN_RATE_LIMIT` (domyślnie 30 na klucz; dodatnia liczba całkowita,
  inna wartość to błąd konfiguracji, czyli 503; compose ustawia 100000, bo w stosie deweloperskim i E2E wszystkie
  żądania mają jeden adres; chart jej nie ustawia) trasa odpowiada 429 z `Retry-After` (sekundy do końca okna) i tekstem
  „Zbyt wiele prób logowania. Spróbuj ponownie za kilka minut.”, `private, no-store`, bez ciasteczka i bez zapisu
  w Redisie poza licznikiem. Licznik jest w `AuthDeps` (`loginRateLimiter`,
  w testach `memoryLoginRateLimiter`); jego błąd (Redis niedostępny) daje to samo 503 co reszta trasy.
- Odpowiedzi `/auth/*` mają `Cache-Control: private, no-store`.

## Przełączniki wdrożenia (`GET /api/site`)

- Przełączniki (`demoNotice`, `customerAccounts`, `sentry`) i ich wartości domyślne: `app/api/site/route.ts`
  i `docs/features/deploy/spec.md`. Wartości ustawia chart (`environments/prod/values.yaml`). `sentry` to
  `{ dsn, environment, release }` Sentry dla przeglądarki z `SENTRY_DSN`, `SENTRY_ENVIRONMENT` i `SENTRY_RELEASE`
  (puste: `null`) albo `null` bez DSN; nigdy próbkowanie (patrz „Sentry”).
- Przełączniki czyta tylko przeglądarka: `SiteSwitchesProvider` w `app/layout.tsx` woła `loadSiteSwitches()`
  (`src/api/client.ts`) raz na stronę i rozdaje wynik przez `useSiteSwitches()`. Layout i strony nie czytają tych
  zmiennych na serwerze, więc HTML stron statycznych i ISR jest taki sam w każdym wdrożeniu. Nie czytaj ich w kodzie
  serwera stron.

## Sentry

Spec: `docs/features/sentry/spec.md`.

- Serwer: `register` (tylko runtime `nodejs`) uruchamia `initServerSentry` (`src/observability/server.ts`), a błędy
  żądań (renderowanie, route handlery, server actions) zgłasza wyłącznie `onRequestError` (`captureRequestError` SDK):
  jeden błąd to jedno zdarzenie. Obsłużone błędy BFF (odpowiedź 502 i `console.error`) nie są zdarzeniami, a logi
  konsoli nie trafiają do Sentry także jako breadcrumbs. SDK nie owija komponentów ani tras. `withSentryConfig` jest
  używany tylko do map źródeł (patrz „Mapy źródeł”) i tylko w budowaniu obrazu z wysyłką.
- Trace'y serwera: próbkowanie `SENTRY_TRACES_SAMPLE_RATE`, bez transakcji `GET /favicon.ico` (sonda gotowości
  Kubernetesa i healthcheck compose co 5 s, `ignoreTransactions`; spec P4), jedna transakcja na żądanie (`traceLifecycle: "static"`:
  domyślne w tej wersji SDK strumieniowanie pojedynczych spanów omija `beforeSendTransaction`, czyli filtr); `sentry-trace` i `baggage` dostają tylko wywołania do
  originów `API_INTERNAL_URL` i `API_CATALOG_URL` (`apiPropagationTargets`: wyrażenia zakotwiczone, bo cel tekstowy SDK
  pasuje w dowolnym miejscu adresu), nigdy Keycloak ani Redis. Serwer nie kontynuuje trace'ów z przychodzących żądań:
  nagłówki `sentry-trace`, `baggage`, `traceparent` i `tracestate` znikają z żądania przy `http.server.request.start`
  (Node.js, przed SDK i Next.js), więc każde żądanie zaczyna nowy trace, a nagłówek z internetu nie wymusza śledzenia.
- Przeglądarka: tylko błędy, bez trace'ów (bez `browserTracingIntegration` i `tracesSampleRate`, więc żądania
  przeglądarki nie niosą `sentry-trace`), bez Replay, sesji i Feedback. Tylko `browser.ts` i `server.ts` importują SDK, a oba są importowane wyłącznie przez `import()`
  (`tests/sentry-lazy.test.ts`); inne moduły biorą z `@sentry/nextjs` tylko typy. CSP i nagłówki z `next.config.ts` są
  bez zmian: SDK jest w chunku z `/_next/`, a CSP nie ma `connect-src`.
- Filtr zdarzeń (`src/observability/filter.ts`, `filterEvent` i `filterBreadcrumb`) to jedna funkcja dla serwera
  i przeglądarki, wołana z `beforeSend`, `beforeSendTransaction` i `beforeBreadcrumb`. Teksty (komunikaty, wartości wyjątków, breadcrumbs, opisy spanów, tagi, wartości kontekstów) maskują te same
  wzorce co Alloy i filtr api (`SentryEventFilter`): e-mail (także `%40`), JWT, `Bearer`, hasło w adresie URL,
  klucz=wartość z sekretami, `Failing row contains (…)`, `Key (…)=(…)`, `DETAIL`/`STATEMENT`, `with params [...]`,
  adresy IP. Wzorce działają w czasie liniowym (adres e-mail zaczyna się tylko po znaku,
  którego w nim nie ma).
- Mapy źródeł (spec: P3, D10; `sentryBuildOptions` i `withSourceMaps` w `next.config.ts`, test
  `tests/sentry-source-maps.test.ts`): przy `SENTRY_UPLOAD=true` w budowaniu `prod` (Turbopack) build zapisuje mapy
  przeglądarki i debug ID (`productionBrowserSourceMaps`, `turbopack.debugIds`), a hook `compiler.runAfterProductionCompile`
  z `@sentry/nextjs` wysyła mapy serwera i przeglądarki, dopasowane przez debug ID (bez tworzenia release'u), usuwa je z
  `.next/static` (`deleteSourcemapsAfterUpload`, domyślne usuwanie Turbopacka; bez `filesToDeleteAfterUpload`, bo hook działa
  przed śladami tras i kopiowaniem `standalone`, a usunięte wcześniej mapy serwera mogłyby je zepsuć) i zdejmuje komentarze
  `sourceMappingURL`; błąd wysyłki przerywa build (`errorHandler` rzuca), telemetria wtyczki jest wyłączona (`telemetry: false`),
  a telemetrię CLI Sentry, przez które wtyczka wysyła, wyłącza `ENV SENTRY_CLI_NO_TELEMETRY=1` w etapie `build` Dockerfile. `withSentryConfig` (`@sentry/nextjs/config`, ładowany tylko przy wysyłce, bo ciągnie SDK
  Node.js do każdego procesu czytającego config) działa na kopii configu, a z jego wyniku bierze się tylko te trzy ustawienia:
  poza nimi dodaje m.in. meta tagi śladu w HTML (`experimental.clientTraceMetadata`), zewnętrzne pakiety, loadery i zmienne
  wbudowane w bundle, więc to nie wchodzi do sklepu (test porównuje wynik z wejściowym configiem). Budowanie bez Turbopacka z flagą
  kończy się błędem. Flaga bez `SENTRY_AUTH_TOKEN`, `SENTRY_ORG` albo `SENTRY_PROJECT` też. Bez flagi `next.config.ts` jest jak
  dotąd i nic nie idzie do Sentry. Dockerfile: flaga, organizacja i projekt to `ARG` etapu `build` (przed budowaniem, więc w
  kluczu cache), token to sekret budowania `sentry_auth_token` (`RUN --mount=type=secret`, nigdy `ARG` ani `ENV`); po budowaniu
  `find .next -name '*.map' -delete` usuwa każdy `.map` (serwera, `standalone` i puste pliki wejściowe tras, które Next zapisuje zawsze), więc etap
  `prod` nigdy ich nie ma; test dymny (`infra/k8s/smoke/checks.py`) sprawdza, że sklep ich nie serwuje. Flagę ustawia tylko job
  `images` workflowu `deploy.yml` (`docs/features/sentry/spec.md`, D10).
- Nowy format danych klienta w logach albo wyjątkach wymaga wzorca w filtrze Sentry (`MASKS` w
  `src/observability/filter.ts`, w filtrze panelu `apps/admin/src/observability/filter.ts` i
  `SentryEventFilter::MASKS` w api) i w Alloy (`infra/k8s/argocd/apps/monitoring.yaml`,
  korpus `infra/k8s/tests/log-corpus`) w tym samym PR. Test filtra ma kopię wierszy korpusu `store.log`, `api.log`
  i `postgres.log` (kontener sklepu widzi tylko `apps/store`): zmiana korpusu zmienia też kopię.

## Konto: historia zamówień

- Widoki i odpowiedzi historii zamówień: `docs/features/order-management/spec.md`.
- `/konto/zamowienia` (`OrdersPage`) i `/konto/zamowienia/[orderId]` (`OrderDetailPage`) to Server Components tylko z
  metadanymi (`noindex`, tytuł) i Client Componentem, który ładuje dane w przeglądarce przez `loadCustomerOrders`
  i `loadCustomerOrder` z `src/api/client.ts`: HTML strony nie zależy od sesji i nie zawiera danych zamówień.
- BFF: `GET /api/account/orders?after=` i `GET /api/account/orders/[orderId]` (`withAuthDeps`, logika w
  `src/account/orders-bff.ts`): sesja z ciasteczka, `freshAccessToken`, wywołanie `GET /api/v1/me/orders` albo
  `/api/v1/me/orders/{orderId}` przez `API_INTERNAL_URL` (nigdy Varnish), odpowiedź `private, no-store`.
- Front niczego nie liczy i nie porównuje z zegarem przeglądarki.

## Wygląd

- Wybrany kierunek: „Granit” (opis i wymagania: `docs/features/visual-design/spec.md`, makieta wskazówką wdrożenia:
  `docs/features/visual-design/mockups/`). Jasne tło, grafit, jeden pomarańczowy akcent (przycisk główny, plakietka
  koszyka, podkreślenie działu; ciemniejszy odcień dla linków i pierścienia fokusu), ostre kształty (promień 4 px) bez
  cieni, kontrast WCAG 2.2 AA i widoczny fokus.
- Komponenty na shadcn/ui. Nowy komponent dodawaj CLI shadcn w kontenerze (`make -C apps/store shell`,
  potem `npx shadcn@latest add <komponent>`) i od razu dopasuj go do tokenów. CLI wpisuje w nowych plikach import
  `cn` z pakietu `cn` (i dopisuje go do `package.json`): popraw na `@/lib/utils` i cofnij zmianę w `package.json` i
  `package-lock.json`. Klasy `data-open:` i `dark:` z szablonów nie działają (Radix ustawia `data-state`, ciemnego
  motywu nie ma).
- Kolory, typografia, promienie i cienie wyłącznie z tokenów w `src/styles/tokens.css`. Żadnych kolorów
  „na sztywno”: bez wartości arbitralnych (`bg-[#…]`, `text-[rgb(…)]`), bez kolorów w `style` i bez klas spoza
  tokenów (`bg-black`, `text-red-500`). Domyślna paleta Tailwinda jest wyłączona, a ESLint to pilnuje.
- Paleta (`--palette-*`) i istniejące tokeny są ustalone w `docs/features/visual-design/spec.md` („Wybrany kierunek”):
  ich zmiana to zmiana specyfikacji. Zadanie może dopisać nowy token semantyczny wskazujący istniejącą paletę.
- Komponenty używają warstwy semantycznej (zmienne motywu shadcn/ui: `background`, `foreground`, `primary`,
  `muted`, `border` i pozostałe, oraz tokeny kierunku: `link`, `success` („Dostępny”), `warning` („Ostatnie sztuki”),
  `inverse*` (stopka), `hero-overlay`), nigdy palety bezpośrednio. Błędy pól i komunikaty używają `destructive` (to ten
  sam odcień co `warning`: paleta nie ma osobnego koloru błędu).
- Skala typografii to `text-*` z tokenów (`text-ui` = 15 px dla przycisków i pól), odstępy z siatki Tailwinda 4 px.
  Margines boczny strony to `px-gutter` (16 px, od `lg` 48 px), szerokość treści `max-w-content` (1440 px).
- Font: Outfit (SIL OFL), plik zmienny w dwóch podzbiorach (`latin`, `latin-ext` z polskimi znakami) z repozytorium przez
  `next/font/local` (`src/styles/fonts.ts`, zmienna `--font-outfit` w `--font-sans`). Bez żądań do hostów fontów,
  także w buildzie; `e2e/tests/visual-foundation.spec.ts` sprawdza, że przeglądarka pyta tylko o hosty stosu.
  Vitest podmienia `next/font/local` atrapą (`tests/stubs/next-font-local.ts`), bo to transformacja kompilatora.
- Koszyk, kasa, potwierdzenie i konto (`/konto/zamowienia`) mają wspólną ramę `ScreenMain` i `ScreenTitle`
  (`src/components/ScreenLayout.tsx`: margines `px-gutter`, szerokość `max-w-content`, wąska kolumna robocza `max-w-3xl`
  przy lewej krawędzi tytułu). Komunikaty `role="alert"` to `Alert`, pozycje list zamówień i podsumowania to `Card`,
  status zamówienia to `Badge`, etykiety pól to `Label`. Wybór płatności zostaje natywnym `<input type="radio">`
  (testy czytają `radio.checked` i `radio.labels`), stylowanym tokenami. Strona błędu (`app/error.tsx`) ma ramę 404.
- Focus: globalna reguła `:focus-visible` w `tokens.css` (3 px, `--ring`); komponenty jej nie wyłączają
  (bez `outline-none`). Na ciemnym tle (`data-surface="inverse"`, np. stopka) pierścień ma kolor `--ring-inverse`.

## Obrazy

- Zdjęcia wyłącznie z wariantów zwróconych przez API (`image.variants`), przez komponent `ProductPhoto` (powiększenie w galerii
  strony produktu: `<img>` z `buildZoomImageProps` z wariantów rozmiaru `zoom`).
  Sklep nie podpisuje adresów i nie dodaje rozmiarów. Nowy rozmiar dopisuje się do listy w API.
- `<Image>` z `next/image` bez loadera wariantów nie zadziała: globalny loader celowo rzuca błąd.
- Wyjątek: zdjęcia marketingowe strony głównej (hero i kafelki działów, nie produktów) to statyczne pliki WebP sklepu
  w `src/home/images/`, importowane statycznie (`src/home/images.generated.ts`, adresy `/_next/static/media/...`
  z hashem) i renderowane zwykłym `<picture>`/`<img srcset>`. Każdy `<img>` ma `srcset`, `sizes`, `width`, `height`; hero
  `fetchpriority="high"`, kafelki `loading="lazy"` i `decoding="async"`; kafelek ma `alt=""` (nazwa działu jest tekstem
  linku), hero `alt` z listy.
- Lista `src/home/marketing-images.ts` (czyste dane, bez importów plików) to jedyne miejsce ich rozmiarów: plik źródłowy
  z `apps/api/fixtures/images/`, kadr (proporcje i punkt), szerokości, budżet każdego pliku w KB i opcjonalna jakość
  WebP (domyślnie `WEBP_QUALITY` 75; hero ma niższą, bo jest bogaty w szczegóły i inaczej nie mieści się w budżecie).
  Budżety: hero 1600 ≤ 250 KB, hero 828 ≤ 120 KB, kafelek 768 ≤ 80 KB.
- `scripts/marketing-images.mjs` czyta listę (Node usuwa typy z `.ts`), a z niej generuje pliki i moduł. Uruchamia się
  w kontenerze `store`: `make -C apps/store shell`, potem `node scripts/marketing-images.mjs`. Źródła są pod
  `/fixtures/images` z montowania tylko do odczytu `./apps/api/fixtures/images:/fixtures/images:ro` usługi `store`
  (`infra/docker-compose.yml`, tylko środowisko deweloperskie: obraz produkcyjny ma commitowane pliki WebP). Używa
  `sharp`, który instaluje Next.js (bez zmiany
  `package.json`). Najpierw sprawdza i koduje wszystko w pamięci, dopiero potem zapisuje (kasuje też stare pliki `.webp`
  spoza listy). Kody wyjścia: 0 gotowe; 1 błędna lista albo wynik (szerokość ponad kadr, czyli powiększanie źródła;
  plik ponad budżet; brak budżetu szerokości; plik źródłowy, którego `sharp` nie czyta albo nie przekonwertuje);
  2 środowisko (brak `sharp`, katalogu źródeł lub pliku źródłowego). Komunikat z nazwą pliku i przyczyną idzie na stderr.
  Błąd walidacji albo kodowania niczego nie zapisuje, bo zdarza się przed zapisem; błąd I/O podczas zapisu (np. pełny
  dysk) może zostawić częściowy wynik, który cofa `git checkout`. Zmienne dla testów: `MARKETING_IMAGES_LIST`, `_SOURCE`, `_OUT`, `_SHARP`. Dodanie
  zdjęcia: wpis w liście, uruchomienie skryptu, commit listy, plików i `images.generated.ts`.
- Vitest: import `.webp` daje napis zamiast `StaticImageData`, więc `vitest.config.ts` ma wspólny mock plików obrazów
  (wtyczka `imageFiles`: adres `/_next/static/media/<plik>` i szerokość z nazwy) dla testów modułu i strony głównej.
  `src/home/images.generated.test.ts` pilnuje, że moduł wygenerowany odpowiada liście (nazwy plików, szerokości,
  wysokości) i że pliki mieszczą się w budżetach; testy skryptu w `tests/marketing-images.test.ts` (potrzebują
  montowania `/fixtures/images`).
- Przeglądarka z emulacją urządzenia mobilnego (`isMobile` w Playwright) pobiera przy `<picture>` także plik szerokiego
  ekranu (skaner ładowania ocenia `media` przy szerszym oknie); test E2E na 375 px używa więc wąskiego okna bez `isMobile`.

## Komponenty i układy z vercel/shop

- Z github.com/vercel/shop (licencja MIT, „Copyright (c) 2025 Vercel, Inc.”) wolno przenosić komponenty i układy.
- Każdy przeniesiony plik ma w nagłówku komentarz `// Adapted from vercel/shop (MIT): <ścieżka w repo źródłowym>`.
  Przy pierwszym przeniesionym pliku dopisz do `THIRD_PARTY_NOTICES.md` w tym katalogu wpis z pełnym tekstem licencji
  MIT i plikiem na liście przeniesionych.
- Przy przenoszeniu: dane tylko z naszego API przez wygenerowanego klienta, bez kodu, typów i zapytań Shopify;
  obrazy przez `ProductPhoto` zamiast `next/image` z CDN Shopify; kolory i odstępy przepisane na nasze tokeny.

## Testy

- Kasa i płatności: cały `src/checkout/` (BFF kasy i płatności, strona kasy, `PaymentConfirmation`) oraz strony
  `/checkout` i `/checkout/confirmation` mają testy. Testy czasu (odświeżanie co 2 s przez 30 s) używają fałszywych
  zegarów Vitest (`vi.useFakeTimers`); wywołanie `window.location.assign` jest za `navigateTo`, które testy podmieniają.
- Vitest i Testing Library. `*.test.ts(x)` to testy jednostkowe bez usług, `*.integration.test.ts` działają
  na usługach stosu (Redis).
- Sentry (`src/observability/*.test.ts`, `tests/instrumentation.test.ts`, `tests/sentry-lazy.test.ts`, `tests/sentry-source-maps.test.ts` (konfiguracja map źródeł), `app/error.test.tsx`)
  bez konta i bez ruchu do Sentry: test serwera uruchamia `register` z fałszywym DSN wskazującym lokalny serwer, który
  zbiera koperty, i lokalne atrapy API i Keycloaka; test przeglądarki działa na buildzie przeglądarkowym
  `@sentry/nextjs` (Vitest bierze inaczej build serwerowy; `vitest.config.ts` przetwarza go przez Vite) z transportem
  testowym.
- Strona główna (`src/home/`) i jej stan domyślny w `app/page.test.tsx`; liczba żądań w `app/page.requests.test.tsx`.
- Logika w komponentach (np. budowa `srcset`, mega menu, panel filtrów i sortowanie), `listing-query.ts` (mapowanie
  parametrów URL na zapytanie API i adresy), strony listingów (`app/page.test.tsx`, `app/c/`, `app/szukaj/`, liczba
  żądań w `app/page.requests.test.tsx`), cały `src/auth/`, cały `src/cart/` (BFF, przyciski,
  strona koszyka) i cały `src/checkout/` (BFF, strona kasy) oraz strona potwierdzenia mają testy.
