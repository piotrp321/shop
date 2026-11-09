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

Klienta API generuje `make api-client` z katalogu głównego.

## Obraz produkcyjny

- `apps/store/Dockerfile` ma etapy `node` (wspólna baza), `build`, `prod` i `dev`; `dev` jest ostatni, bo compose buduje
  sklep bez `target`. `docker build --target prod apps/store` działa bez stosu i bez sekretów.
- `build` uruchamia `next build` z `STORE_STANDALONE=1`. Tylko ta zmienna włącza w `next.config.ts` `output:
  "standalone"`; lokalny `STORE_MODE=production` (`next build` i `next start` w compose) buduje jak dotąd, bez standalone.
  Nie ustawiaj `STORE_STANDALONE` nigdzie poza Dockerfile.
- `prod` zawiera tylko serwer standalone (prześledzone zależności, `.next/static`, `public`), działa jako użytkownik
  `node` (`.next` jest jego, bo cache ISR zapisuje się w czasie działania) i startuje `node server.js`
  (`HOSTNAME=0.0.0.0`, `PORT=3000`). Konfigurację (`API_CATALOG_URL`, `API_INTERNAL_URL`, `STORE_URL`, `REDIS_URL`,
  `KEYCLOAK_*`, sekrety) czyta ze zmiennych środowiska przy starcie, nic nie jest wbudowane w obraz.
- Build nie woła API: strony prerenderowane w buildzie (`/cart`, 404) powstają bez menu (komunikat o drzewie kategorii
  w logu buildu to stan oczekiwany), a menu dostają przy pierwszej rewalidacji tagu `catalog`
  (`storefront:cache:purge-all`).

## Struktura

- `app/` – trasy App Router, w tym route handlery logowania w `app/auth/`, koszyka w `app/api/cart/`, kasy
  w `app/api/checkout/` (płatność zamówienia w `app/api/checkout/payment/`), historii zamówień w `app/api/account/orders/`,
  przełączników wdrożenia w `app/api/site/` i rewalidacji ISR w `app/api/revalidate/`; strony konta w
  `app/konto/zamowienia/`.
- `src/api/` – `client.ts` (jedyne miejsce rozmowy z API i jedyne miejsce wywołań `/auth/session`, `/api/cart/*`,
  `/api/checkout`, `/api/checkout/payment`, `/api/account/orders*` i `/api/site` z przeglądarki) i wygenerowany
  `schema.d.ts` (nie edytuj ręcznie).
- `src/account/` – komponent konta w nagłówku (`AccountMenu`, Client Component), historia zamówień (`OrdersPage`,
  `OrderDetailPage`, `OrderPaymentNote`, `order-format.ts`) i jej BFF (`orders-bff.ts`).
- `src/components/SiteSwitches.tsx` – `SiteSwitchesProvider`, `useSiteSwitches` i pasek `DemoNotice` (Client Components).
- `src/cart/` – BFF koszyka (wybór koszyka, ciasteczka `store_cart` i `store_cart_notice`, scalanie koszyka gościa
  w `merge.ts`, wywołania API, mapowanie błędów) oraz komponenty koszyka: `AddToCartButton`, `CartBadge` i `CartPage`.
- `src/checkout/` – BFF kasy (złożenie zamówienia i rozpoczęcie płatności, ciasteczko `store_order_confirmation`),
  BFF płatności zamówienia (`payment-bff.ts`), wywołania API płatności (`payment-api.ts`), strona kasy `CheckoutPage`
  i strona potwierdzenia `PaymentConfirmation`.
- `src/auth/` – BFF: OIDC, sesje w Redisie, odświeżanie tokenów, walidacja `returnTo`.
- `src/catalog/` – komponenty i ładowanie danych katalogu, w tym `formatPrice` (formatowanie kwoty z API, `pl-PL`),
  `load-catalog.ts` (lista produktów i oferty), `category-tree.ts` (drzewo kategorii) z `category-path.ts` (ścieżka
  slugów → kategorie), `CatalogListing`, `Breadcrumbs` z `breadcrumb.ts`, `SiteHeader`, `MegaMenu` i `SearchBox`,
  `listing-query.ts` (parametry URL ↔ zapytanie API, adresy listingu), `listing-context.tsx`, `ListingFilters`, `ActiveFilters`, `SortSelect`
  i `EmptyResults` (panel filtrów i sortowanie),
  `load-product.ts` (odczyty strony produktu), `ProductDetailsView`, `ProductGallery` (galeria i powiększenie), `ProductPrice`, `VariantChoice` z `variant-choice.ts` (wybór wariantu),
  `product-metadata.ts` i `product-json-ld.ts`.
- `app/page.tsx` (`/`), `app/c/[...path]/` (kategoria) i `app/szukaj/` (wyszukiwanie) – listing (SSR przy każdym
  żądaniu); `app/p/[slug]/` – strona produktu (ISR).
- `src/home/` – strona główna w stanie domyślnym listingu (patrz „Strona główna `/`”): `HomeIntro` (`Hero`
  i `DepartmentTiles`), `home-content.ts` (tekst hero i przypisanie zdjęć do działów po slugu kategorii głównej),
  `departments.ts`, `home-images.ts`, `marketing-images.ts` (lista zdjęć marketingowych: jedyne miejsce ich rozmiarów),
  `images.generated.ts` i `images/*.webp` (wynik skryptu, commitowany; nie edytuj ręcznie).
- `scripts/marketing-images.mjs` – generuje z listy pliki WebP i `images.generated.ts` (patrz „Obrazy”).
- `src/seo/` – mapa strony i `robots.txt`: `xml.ts` (budowanie `sitemapindex` i `urlset` z ucieczką `& < > " '`),
  `sitemap.ts` (odpowiedzi tras mapy), `robots-rules.ts` (reguły `robots.txt`), `store-url.ts` (`STORE_URL` czytany przy
  żądaniu) i `home-json-ld.ts` (JSON-LD strony głównej).
- `src/site.ts` – `STORE_NAME`, jedyne miejsce nazwy sklepu: znak słowny w `SiteHeader`, `title` i `description`
  w `app/layout.tsx`, tytuły i opisy w `page-metadata.ts` i `product-metadata.ts` oraz JSON-LD strony głównej. Nie wpisuj
  nazwy w komponentach ani w metadanych.
- JSON-LD strony głównej: `/` renderuje dwa `<script type="application/ld+json">` (`Organization` i `WebSite` z `name`
  = `STORE_NAME` i `url` = `<STORE_URL>/`, przez `serializeJsonLd`), zawsze gdy pokazuje listing (z wynikami albo pustą
  listą, także z parametrami filtrów i strony); nie na „Nieprawidłowym filtrze”, przy przekierowaniu `?category=`
  ani na 404. Bez `logo`, `sameAs`, `SearchAction` i `ItemList`. `/c/` ma tylko `BreadcrumbList`, `/p/` `Product`
  i `BreadcrumbList`, `/szukaj` nie ma JSON-LD.
- `app/sitemap.xml/`, `app/sitemaps/listing.xml/`, `app/sitemaps/products/[file]/` (route handlery mapy strony)
  i `app/robots.ts` – patrz „Mapa strony i `robots.txt`”.
- `app/not-found.tsx` – polska strona 404 (nieznany adres i `notFound()`), w layoucie sklepu.
- `src/catalog/SiteFooter.tsx` – stopka (Server Component w `app/layout.tsx`), obok `SiteHeader`.
- `src/components/ui/` – komponenty shadcn/ui.
- `src/styles/tokens.css` – jedyne miejsce tokenów wyglądu; `src/styles/fonts.ts` (`next/font/local`) i
  `src/styles/fonts/` – pliki WOFF2 fontu Outfit (`latin`, `latin-ext`); `THIRD_PARTY_NOTICES.md` – licencja OFL
  fontu i lista plików (przy przeniesieniu plików z vercel/shop także pełny tekst MIT).

## Dane i API

- Dane wyłącznie z API przez wygenerowanego klienta (`src/api/client.ts`). ESLint blokuje globalny `fetch`
  poza tym plikiem.
- Żadnej logiki biznesowej: ceny, sumy, dostępność i filtrowanie liczy API, sklep je wyświetla.
- Oferty listy (warianty z etykietami, cena „od”, poziom produktu i wariantów, `purchasable`) `loadCatalog` pobiera jednym
  wywołaniem `GET /api/v1/product-offers` po pobraniu strony produktów. Błąd ofert nie psuje listy: kafelki są bez ceny
  („Cena niedostępna”), bez etykiety dostępności, bez wyboru wariantu i bez przycisku, a błąd trafia do logu serwera.
  `ProductTile` pokazuje etykietę według `level` z API („Dostępny”, „Ostatnie sztuki”, „Niedostępny”), bez liczenia na
  froncie (API nie zwraca liczby sztuk); liczba wariantów, ich kolejność i etykiety pochodzą tylko z oferty.
- Sklep woła API tylko z serwera, pod jednym z dwóch adresów w sieci compose:
  - `API_CATALOG_URL` (`http://varnish`): odczyty katalogu `loadCatalog` (lista, oferty) i `loadCategoryTree` (drzewo kategorii) oraz `loadProduct` (szczegóły i oferta
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
- `/c/[...path]`: kategoria wskazana ścieżką slugów w drzewie (`path` i `slug` z `GET /api/v1/categories`,
  `resolveCategoryPath`); brak w drzewie albo `unknown-category` z listy → `notFound()`. Kolejność odczytów: drzewo
  (jeden odczyt na żądanie, `React.cache`, wspólny dla layoutu, `generateMetadata` i strony), potem lista z id
  kategorii z drzewa, potem oferty. Błąd drzewa na tej stronie to wyjątek, czyli `app/error.tsx`.
- `/`: lista wszystkich produktów, bez breadcrumbów; w stanie domyślnym z hero i kafelkami działów nad listą (patrz „Strona główna `/`”). `/?category=<id>` czyta drzewo i listę razem; kategoria z drzewa →
  `permanentRedirect` (308) na `/c/<ścieżka>` z pozostałymi parametrami (bez `category`); nieznana → 404, niepoprawna
  (`400` API) → „Nieprawidłowy filtr”.
- `CatalogListing`: breadcrumby „Wszystkie produkty” › kategorie ścieżki (linki `/c/<ścieżka>`, ostatni element bez
  linku, tylko na `/c/` i `/szukaj`), `h1` z nazwą, obok liczba produktów (`formatProductCount`: „1 produkt”,
  „2–4 produkty”, 22–24 itd., reszta „produktów”, także 12–14), panel filtrów (po lewej od `lg`), nad siatką aktywne
  filtry i lista „Sortuj”, siatka i paginacja. Wygląd według makiety „Granit”: kafelki bez ramki (zdjęcie na całą szerokość
  kafelka, tekst pod nim; zdjęcie produktu niedostępnego jest wyszarzone), 2 kolumny na telefonie, od `sm` 3, od `lg` 4;
  ścieżka z separatorem „/”; od `lg` kolumna filtrów 260 px po lewej bez ramki, grupy rozdzielone liniami (panel nie jest
  wysuwany: makieta pokazuje go zawsze). Wszystkie adresy strony (paginacja, linki kategorii, chipy) buduje
  `listingHref(basePath, state, patch)` z adresu listingu bez parametrów (`basePath`) i jego parametrów (`state`).
- SEO: tytuł `/c/` to `<nazwa>[ – strona n] | Worf`, kanoniczny `<STORE_URL>/c/<ścieżka>` (`?page=n` przy n > 1);
  pusta strona poza zakresem i niepoprawny `page` mają `noindex`. `/c/` ma JSON-LD `BreadcrumbList` (bezwzględne
  adresy, ten sam `serializeJsonLd` co strona produktu).

## Strona główna `/`

- Stan domyślny listingu: bez filtrów, bez sortowania innego niż domyślne (`sort` brak albo `nazwa`, czyli `defaultSort`
  `/`; formularz filtrów bez JavaScriptu zawsze wysyła `sort=nazwa`) i z `page` brak albo `1` (parametry spoza listingu,
  np. `utm_*`, nie liczą się; `isDefaultState` w `app/page.tsx`; `isRefined`, od którego zależą `noindex` i canonical,
  liczy każdy `sort` i się nie zmienia, więc `/?sort=nazwa` ma hero, ale zostaje `noindex`). Tylko w nim `/` ma nad listą `HomeIntro` (`CatalogListing` dostaje
  `intro`): hero (zdjęcie na całą szerokość, `h1`, krótki tekst, link do działu Turystyka) i kafelki każdej kategorii
  głównej z drzewa (zdjęcie, nazwa jako `h2` będąca linkiem do `/c/<ścieżka>`). W innym stanie (`?page=2`, inny sort, filtr)
  `/` jest samym listingiem z `h1` „Wszystkie produkty” i nazwami produktów w `h2`.
- Nagłówki w stanie domyślnym: `h1` to nagłówek hero, `h2` nazwy działów i „Wszystkie produkty” nad listą, `h3` nazwy
  produktów (`ProductGrid home`, `ProductTile headingLevel`). Priorytet ładowania ma tylko zdjęcie hero, kafelki
  produktów w tym stanie nie mają `priority`. Siatka produktów ma `data-testid="product-grid"`: testy E2E (`gridTiles`
  w `e2e/support/listing.ts`) znajdują kafelki po nim, nie po poziomie nagłówka.
- Drzewo to `loadMenuCategories` (to samo `React.cache` co nagłówek i stopka, bez dodatkowego żądania). Błąd drzewa:
  hero bez linku i bez kafelków, lista jak zwykle. Link hero do działu, którego nie ma w drzewie, jest pomijany;
  kategoria główna bez zdjęcia w `home-content.ts` ma kafelek bez zdjęcia.
- Tytuł, opis, kanoniczny, `noindex` i JSON-LD `/` bez zmian.

## Wyszukiwanie `/szukaj` i parametry URL

- Parametry URL (`readListingState` czyta pierwszą wartość, puste wartości pomija, bo formularz bez JavaScriptu wysyła
  każde pole; `o` czyta wszystkie): `q` (fraza, po przycięciu i do 100 znaków; tylko `/szukaj`), `sort` (`trafnosc`,
  `nazwa`, `cena-rosnaco`, `cena-malejaco`), `cena-od` i `cena-do` (pełne złote), `dostepne=1`, `o=<oś>:<wartość>`
  (powtarzany; filtr osi), `kategoria=<ścieżka slugów>` (tylko `/szukaj`), `page`. Na `/` i `/c/` `q` i `kategoria` są
  pomijane (nie trafiają do API ani do adresów), kategoria jest w ścieżce.
- `toApiQuery` mapuje stan na zapytanie `GET /api/v1/products`: `sort` na nazwę API, `dostepne=1` na `available=true`,
  `o` na powtarzane `option`. Jedyne, co front liczy, to złote na grosze: napis z samych cyfr dostaje `00` (`100` →
  `10000`), każda inna wartość idzie bez zmian, więc API odpowiada 400. Liczby, zakresy cen, liczby przy wartościach
  i dostępność pochodzą z `facets` API; podpowiedź zakresu ceny to kwoty z API sformatowane `formatPrice`.
  Nieznany `sort`, `dostepne` inne niż `1` i 400 API to „Nieprawidłowy filtr” (z `noindex`); dla nieznanego `sort`
  i `dostepne` sklep nie woła API. `InvalidFilter` ma obok „Zobacz cały katalog” link powrotu z `invalidFilterExit`:
  „Pokaż wyniki bez błędnej ceny” (bez ceny, która nie jest samymi cyframi, gdy reszta przechodzi `toApiQuery`) albo
  „Pokaż wyniki bez filtrów” (listing bez filtrów i sortowania, na `/szukaj` z frazą; na `/` go nie ma).
- `/szukaj`: `q` bez frazy albo krótsze niż 2 znaki (po przycięciu) → „Wpisz co najmniej 2 znaki.” bez wywołania listy
  produktów i drzewa kategorii. `kategoria` czyta drzewo (to samo `React.cache`), zamienia ścieżkę na `category` API;
  nieznana ścieżka (też kategoria, której API już nie zna) → „Nieprawidłowy filtr”. `h1` `Wyniki dla „<fraza>”`,
  breadcrumby „Wszystkie produkty” › „Wyniki wyszukiwania” (z `kategoria` link do wyszukiwania i ścieżka kategorii).
- Panel filtrów (`ListingFilters`, Client Component, dane z `facets` listy): „Kategorie” (linki z liczbą: na `/` i `/c/`
  do `/c/<ścieżka>`, na `/szukaj` z `kategoria`; zachowują filtry), grupa pól wyboru dla każdej osi (`o`, z liczbą,
  wybrana wartość z 0 zostaje na liście), „Cena (zł)” (pola „Cena od” i „Cena do”) i „Tylko dostępne” (z `availableCount`).
  Pola wyboru, „Tylko dostępne” i lista „Sortuj” (`SortSelect`, natywny `select`, domyślne sortowanie zostawia `sort`
  poza adresem) nawigują od razu (`router.push` w tranzycji z `useOptimistic`, więc zaznaczenie widać przed nową
  stroną). Panel i sortowanie dzielą jeden stan optymistyczny (`ListingProvider` w `CatalogListing`, `useListing`),
  więc zmiany wykonane jedna po drugiej budują adres z poprzedniej zmiany i nie nadpisują się; cena po Enter (obsługa klawisza, bo formularz bez przycisku z kilkoma polami tekstowymi nie wysyła się
  Enterem) albo po opuszczeniu pola (przejście z jednego pola ceny do drugiego nie nawiguje); wpisana wartość jest
  przycinana i idzie bez przeliczania, pusta usuwa filtr. Każda zmiana filtra lub sortowania usuwa `page` (`listingHref`).
- Bez JavaScriptu panel jest formularzem `GET` (`id="listing-filters"`, ukryte pola `q` i `kategoria`; `select` „Sortuj”
  należy do niego przez atrybut `form`) z przyciskiem „Pokaż wyniki” w `noscript`; `noscript` pokazuje też panel
  na wąskim ekranie. Na wąskim ekranie panel (w ramce) rozwija przycisk „Filtry” (`aria-expanded`); od `lg` przycisk jest
  ukryty, a panel zawsze widoczny.
- Aktywne filtry (`ActiveFilters`, zwykłe linki, więc działają bez JavaScriptu): link usuwający jeden filtr (osie, cena
  od, cena do, „Tylko dostępne”, kategoria wyszukiwania) i „Wyczyść filtry” (zostają fraza i sortowanie). Brak wyników:
  „Nie znaleźliśmy produktów.”, przy filtrach przycisk „Wyczyść filtry”, przy frazie link „Zobacz wszystkie produkty”.
- SEO: parametry `sort`, `cena-od`, `cena-do`, `dostepne` i `o` (niepuste) dają `noindex, follow` i kanoniczny listingu
  bez nich i bez `page`; `/szukaj` zawsze `noindex, follow` bez kanonicznego (`buildSearchMetadata`); szczegóły
  w `docs/features/catalog-page-metadata/spec.md`.

## Nagłówek i mega menu

- `SiteHeader` ma pole wyszukiwania (`SearchBox`, Client Component): formularz `GET /szukaj` (`next/form`) z polem `q`
  (etykieta „Szukaj produktów”). Pole przychodzi do layoutu jako slot `@search` (layout nie dostaje `searchParams`,
  strona slotu tak): `app/@search/szukaj/page.tsx` daje pole z frazą w HTML z serwera (`/szukaj` i tak jest renderowane
  przy każdym żądaniu, więc działa bez JavaScriptu), `app/@search/default.tsx` puste pole na każdej innej stronie (HTML
  taki sam dla wszystkich, strona produktu jest ISR). Strona 404 nieznanego adresu nie dostaje slotu, więc layout
  wstawia wtedy puste pole sam. Przy nawigacji klienckiej z `/szukaj` na inną stronę Next zostawia aktywny slot
  `@search/szukaj` (z frazą w `defaultValue`), a w drugą stronę montuje nowe pole z frazą. Pole w przeglądarce
  prowadzi `useSearchParams` (mały komponent w `Suspense` bez wyniku: formularz i pole są poza nim, więc element pola
  się nie wymienia przy hydratacji i wpisany wcześniej tekst zostaje): przy pierwszym uruchomieniu pisze tylko do
  pustego pola, potem nadpisuje je przy zmianie frazy (nowe wyszukiwanie, wyjście z `/szukaj`, które czyści pole
  pozostawionego slotu), nie przy zmianie innych parametrów.
- Układ (CSS grid, jedno `SiteHeader` bez zdublowanego formularza): kolejność elementów w dokumencie jest kolejnością
  wizualną na każdej szerokości (WCAG 2.4.3), więc tabulator idzie tak samo w każdej przeglądarce, bez żadnej
  właściwości CSS zmieniającej kolejność fokusu, bez `order` i bez obszarów siatki przestawiających elementy względem
  dokumentu (nie dodawaj ich). Kolejność:
  znak „Worf”, działy (poniżej `lg` przycisk „Menu”), koszyk, konto, wyszukiwarka, a na końcu komunikat o koszyku, gdy
  jest. Od `lg` (1024 px) jeden wiersz (nazwane obszary `brand nav cart account search` stoją w kolejności
  dokumentu), pasek działów (`flex-wrap`: dla działów demo jeden rząd, przy większej liczbie albo dłuższej nazwie zawija się zamiast
  nachodzić na koszyk i konto; `text-ui`, cele 44 px, poziomy padding przycisku 8 px do `xl`, od `xl`
  12 px); panel działu rozciąga się na całą szerokość pod nagłówkiem (pozycjonowany względem `header`, w czterech
  kolumnach: kategoria poziomu 2 jako nagłówek i linki poziomu 3). Poniżej `lg` dwa rzędy: znak, przycisk „Menu”
  (`aria-expanded`), koszyk i konto, pod nimi pole na pełną szerokość; lista działów otwiera się jako panel pod
  nagłówkiem. `CartBadge` renderuje link koszyka, potem swoje `children` (komórkę konta i wyszukiwarkę, które
  `SiteHeader` wstawia po koszyku) i na końcu komunikat, wszystko jako dzieci siatki.
- Koszyk i konto są ikonami z nazwą w `sr-only` na każdej szerokości (ikony konta zalogowanego mają też `title`;
  „Zalogowany jako …” jest tylko dla czytników), bo z tekstem wiersz klienta nie mieści się w jednym wierszu; słowa
  „Koszyk” i „Zaloguj się” (gość) pokazuje dopiero `min-[90rem]` (1440 px), gdzie się mieszczą. Kompaktowy wariant
  wyszukiwarki (1024–1279 px, gdzie pole się nie mieści): `SearchBox` renderuje przycisk „Wyszukiwarka”
  (`aria-expanded`, `aria-controls`) i formularz; przycisk jest `display: none` poza tym zakresem, a formularz jest
  w nim panelem pod nagłówkiem (`data-state`, fokus w polu po otwarciu; Escape, wysłanie, kliknięcie poza panelem
  opuszczenie go fokusem i otwarcie panelu działu (zdarzenie `DEPARTMENT_PANEL_OPENED` z `header-panels.ts`, także przy
  najechaniu myszą) zamykają go, Escape i wysłanie oddają fokus przyciskowi). Bez JavaScriptu `noscript` ze stylem pokazuje
  formularz jako drugi rząd nagłówka, a przycisk chowa. Od 1280 px pole stoi w wierszu (kolumna 14 rem) bez przycisku,
  poniżej 1024 px jest drugim rzędem. Zmieniając układ sprawdź wymiary na 1024 px po zalogowaniu (zapas paska działów
  to kilkanaście pikseli przy siedmiu działach demo). Brak poziomego przewijania przy 375 px sprawdza
  `e2e/tests/site-header.spec.ts`; kolejność w dokumencie względem położenia, sekwencję tabulatora, jeden wiersz
  i wysokość poniżej 100 px na 1024–1920 px (gość i `jan`) oraz kompaktową wyszukiwarkę
  `e2e/tests/visual-foundation.spec.ts` (pomocnik `e2e/support/focus-order.ts`).
- Layout czyta drzewo przez `loadMenuCategories` (to samo `React.cache` co listing) i renderuje `SiteHeader` z menu
  działów (`MegaMenu`, `nav` „Działy”, Client Component). Na stronie produktu (ISR) odczyt drzewa ma tag `catalog`
  (`loadCategoryTree`), więc strona rewaliduje się razem z katalogiem; odczyt nie ustawia trybu cache.
- Od `lg`: przyciski działów (kategorie główne); panel działu (poziomy 2–3 i „Wszystko z działu”) otwiera się
  kliknięciem, Enter, Spacją i najechaniem myszą, zamyka Escape, kliknięciem poza menu, przejściem fokusu poza menu,
  kliknięciem linku i przejściem na inny dział; przyciski mają `aria-expanded`. Escape zamyka menu przy dowolnym
  fokusie (pomija zdarzenie z `defaultPrevented`), a fokus wraca na przycisk tylko wtedy, gdy był w menu albo nigdzie
  (`body`); fokus w polu poza menu zostaje tam, gdzie był. Poniżej `lg`: przycisk „Menu” z listą trzech poziomów.
  Głębsze poziomy są na stronie kategorii.
- Wszystkie linki są w HTML także przy zamkniętym menu (panele mają atrybut `hidden`), JavaScript tylko je otwiera.
- Błąd odczytu drzewa w layoucie: `console.error` na serwerze, nagłówek bez menu, strona renderuje się dalej (koszyk
  działa). Strona produktu w ISR może zostać zapisana bez menu do następnej rewalidacji.
- Strony prerenderowane statycznie w buildzie (`/cart`, `/checkout`, strona 404) też dziedziczą z layoutu odczyt drzewa
  i tag `catalog`: mają menu z ostatniego renderu (z builda albo z rewalidacji tagu `catalog`, np. po `make seed`).
  Build bez dostępnego drzewa daje te strony bez menu aż do następnej rewalidacji tagu. Strony dynamiczne (`/`,
  `/c/...`, `/checkout/confirmation`) czytają drzewo przy każdym żądaniu.

## Stopka i strona 404

- `SiteFooter` (Server Component w `app/layout.tsx`, `<footer>`) dostaje to samo drzewo co nagłówek (`loadMenuCategories`,
  jeden odczyt na żądanie, bez własnego żądania; testuje to `app/page.requests.test.tsx`). Grafitowe tło, mały znak
  „Worf” (link do `/`), linki kategorii głównych w dwóch kolumnach (`nav` „Linki w stopce”, nazwa nie zawiera „Działy”,
  bo e2e szuka nawigacji „Działy” po części nazwy) i pod cienką linią jeden wiersz „© <bieżący rok> Worf · Zdjęcia:
  Unsplash” (nazwa ze `STORE_NAME`). Od `lg` znak po lewej i linki po prawej, na telefonie znak nad linkami o wysokości
  44 px. Błąd drzewa albo puste drzewo: stopka bez `nav`. Bez linków do stron, których nie ma.
- `app/not-found.tsx`: „Nie znaleźliśmy tej strony.” (`h1`) i przycisk „Wróć do sklepu” (`/`), w layoucie sklepu
  (nagłówek i stopka), kod 404, `noindex` dodaje Next.js. Dotyczy nieznanego adresu i `notFound()` (nieznany produkt,
  kategoria).

## Strona produktu `/p/[slug]`

- ISR: `revalidate = 3600` (siatka bezpieczeństwa), `generateStaticParams` zwraca `[]` (strony powstają przy pierwszym
  wejściu, nie przy buildzie). HTML nie zależy od sesji. Cache ISR istnieje tylko w buildzie produkcyjnym
  (`STORE_MODE=production`); `next dev` renderuje przy każdym żądaniu.
- Dane (`loadProduct` w `src/catalog/load-product.ts`, wspólne dla `generateMetadata` i strony przez `React.cache`
  jako `loadProductForRequest`): `GET /api/v1/products/{slug}`, potem `GET /api/v1/product-offers?productId=<id>`, oba
  przez `API_CATALOG_URL`. Tagi cache Next to te same nazwy, które API wystawia w `xkey`: szczegóły `catalog`
  i `product-slug-<slug>` (tylko dla sluga w formacie sluga produktu, `isProductSlug` w `load-product.ts`, ten sam
  wzorzec co w `/api/revalidate`; inny napis tylko `catalog`), oferta `catalog` i `product-<id>` (UUID małymi
  literami). Strona dziedziczy sumę tagów odczytów, więc `revalidateTag` tych nazw unieważnia jej HTML, także strony
  404 sluga, który właśnie został opublikowany.
- 404 szczegółów → `notFound()` (strona 404 z `noindex`; `generateMetadata` zwraca wtedy `{}`). Każdy inny błąd
  szczegółów albo oferty (status, sieć, nieoczekiwana treść, brak oferty produktu) to wyjątek, czyli `app/error.tsx`,
  którego ISR nie cache'uje; strona bez ceny nigdy nie powstaje.
- `ProductDetailsView` (Server Component): ścieżka „Wszystkie produkty” › kategorie (linki `/c/<ścieżka>`, `Breadcrumbs`), od `lg`
  galeria (`ProductGallery`) po lewej i dane po prawej (kolumny 7:5), na telefonie galeria nad danymi; dane: `h1`, krótki
  opis,
  cena „od X zł” (gdy `hasPriceRange`) albo „X zł” i etykieta `level` produktu; produkt bez wariantu możliwego do kupienia
  (`fromGross = null`): „Niedostępny” bez ceny, wyboru i przycisku. Wartości pochodzą z oferty, front niczego nie liczy.
  Cena to zawsze cena skuteczna z API (bez przekreślonej ceny katalogowej). `PromotionNotice` pokazuje oznaczenie
  „Promocja” zawsze razem z linią „Najniższa cena z 30 dni przed obniżką: X zł” (Omnibus, `lowestPriorGross` przez
  `formatPrice`); `lowestPriorGross = null` albo brak promocji to żadne z dwóch. Przed wyborem wariantu bierze je
  z `fromPromotion` oferty, po wyborze z `promotion` wybranego wariantu (to samo na kafelku w `TileOffer`).
- Galeria (`ProductGallery`, Client Component renderowany też na serwerze, z `usePhotoStrip` w `use-photo-strip.ts`; pełny
  opis i testy: `docs/features/product-page/spec.md`, „Galeria i powiększenie”). HTML z serwera ma cały pasek zdjęć
  (CSS `scroll-snap`, `id="zdjecie-n"`, `alt` z API), miniatury jako linki `#zdjecie-n` (`alt=""`, najmniejsza szerokość
  rozmiaru `product`) i licznik „1 / m”: działa bez JavaScriptu i jest taki sam przed hydratacją i po niej. Kolejność
  w dokumencie jest kolejnością wizualną (bez właściwości CSS zmieniających kolejność fokusu i bez `order`; nie dodawaj
  ich): lista miniatur (`Thumbnails`) jest w HTML dwa razy, kolumna po lewej od zdjęcia (`hidden lg:flex`) przed paskiem
  i rząd pod nim (`lg:hidden`) po pasku; ukryta ma `display: none` (poza Tab i drzewem dostępności), jej obrazy mają `loading="lazy"` i te same adresy co
  obrazy widocznej listy, obie mają ten sam `aria-current`. Testy Vitest w jsdom (bez CSS) widzą więc dwie listy; kolejność
  w dokumencie względem położenia i sekwencję Tab sprawdza `e2e/tests/product-gallery.spec.ts` (`e2e/support/focus-order.ts`). Po hydratacji
  (`useSyncExternalStore` z migawką serwera `false`: tego, co dodaje JavaScript, nie ma w pierwszym renderze) dochodzą
  `aria-current` miniatury, licznik podążający za paskiem (też za przesunięciem palcem, po 100 ms bez przewijania; `usePhotoStrip` czyta pozycję
  paska także raz, gdy element się pojawia, więc pasek przewinięty przed hydratacją i okno otwarte na zdjęciu 2 są zgodne
  z licznikiem),
  przyciski „Poprzednie zdjęcie” i „Następne zdjęcie” (ułożone bezwzględnie, bez przesunięcia układu), płynne przewijanie
  z miniatur bez zmiany adresu (`preventDefault`) i ← →. Zdjęcie paska jest w przycisku „Powiększ zdjęcie n z m: <alt>”,
  który otwiera shadcn `dialog` na całe okno z paskiem `zoomImages` (`sizes="100vw"`, `object-fit: contain`, widoczne
  `eager`, reszta `lazy`); fokus wraca na przycisk otwierający przez `onCloseAutoFocus`. Jedno zdjęcie: bez miniatur,
  licznika i strzałek, z powiększeniem; bez zdjęć: `PhotoPlaceholder`. Rozmiary obrazów pochodzą tylko z API
  (`buildImageProps`, `buildZoomImageProps`, `smallestVariant` w `image-props.ts`); `STAGE_SIZES_SEVERAL` i `STAGE_SIZES_ONE` opisują tylko
  szerokość sceny galerii w układzie strony (z kolumną miniatur i bez niej). Test E2E dotyku używa `Input.dispatchTouchEvent`: `Input.synthesizeScrollGesture`
  z `gestureSourceType: "touch"` niczego nie przewija w tym Chromium.
- Wybór wariantu (`VariantChoice`, Client Component osadzony w `ProductDetailsView`; reguły w czystych funkcjach
  `variant-choice.ts`, z testami): jedna grupa przycisków (`fieldset`, `aria-pressed`) na oś z `variantAxes`, wartości
  w kolejności pierwszego wystąpienia w `variants` szczegółów. Wariant ze szczegółów jest możliwy do kupienia tylko, gdy
  oferta ma go (po `variantId`) z `purchasable = true`; cena, poziom i `purchasable` pochodzą z oferty, front tylko
  dopasowuje wybrane wartości do wariantu. Dostępność wartości jest hierarchiczna, w kolejności osi: wartość pierwszej
  osi jest wyłączona (z dopiskiem „niedostępny”) tylko wtedy, gdy żaden wariant możliwy do kupienia jej nie ma; wartość
  kolejnej osi liczy się względem wyborów osi wcześniejszych (wybory osi późniejszych się nie liczą); oś późniejsza jest
  wyłączona (bez dopiska), dopóki wcześniejsze nie mają wyboru. Zmiana wartości osi czyści wybory osi późniejszych, które
  przestały pasować, więc wybór nigdy się nie blokuje. Kliknięcie wybranej wartości niczego nie zmienia (bez odznaczania).
  Wariant możliwy do kupienia, jeśli jest jedyny (także spośród wielu), jest wybrany od pierwszego renderu (stan
  początkowy zależy tylko od danych, więc HTML ISR jest taki sam dla każdego). Produkt z jednym wariantem pokazuje
  wartości osi jako tekst („Kolor: czarne”), bez wyboru. Do pełnego wyboru strona pokazuje cenę, oznaczenie promocji
  i poziom produktu (`children` z serwera), po nim cenę, oznaczenie promocji i poziom wybranego wariantu. `AddToCartButton` jest wyłączony do pełnego wyboru
  i dodaje 1 sztukę wybranego wariantu (ilość zmienia się w koszyku). Wariantu nie ma w URL.
- Metadane (`buildProductMetadata`): tytuł `<nazwa> | Worf`, opis = krótki opis (bez opisu: brak), kanoniczny
  `<STORE_URL>/p/<slug>`, Open Graph (tytuł, opis, adres, obraz = pierwsze zdjęcie w największej szerokości; bez zdjęć
  bez obrazu).
- JSON-LD (`product-json-ld.ts`, dwa `<script type="application/ld+json">`): `Product` z `Offer` tylko dla wariantów
  z ceną (`gross` ≠ `null`; `name` = etykieta, `price` w złotych z kropką, czyli cena skuteczna z API, `availability`
  z `level`; wariant w promocji ma `priceValidUntil`: data `YYYY-MM-DD` chwili `endsAt − 1 s` w `Europe/Warsaw`, więc
  koniec o 00:00 czasu warszawskiego daje poprzedni dzień; samo formatowanie daty) i `BreadcrumbList`
  z adresami bezwzględnymi. `serializeJsonLd` zamienia każdy `<` na ucieczkę Unicode, więc tekst produktu nie zamknie
  elementu `script`.
- Kafelek listy (`ProductTile`): nazwa i zdjęcie to linki do `/p/<slug>`; link zdjęcia jest `aria-hidden` i poza
  kolejnością tabulatora, nazwa jest linkiem dla klawiatury i czytników.

## Mapa strony i `robots.txt`

- Trasy: `/sitemap.xml` (`sitemapindex`: najpierw `<STORE_URL>/sitemaps/listing.xml`, potem po jednej pozycji na porcję
  z `GET /api/v1/sitemap/product-chunks`, w kolejności API: `<STORE_URL>/sitemaps/products/<klucz>.xml`),
  `/sitemaps/listing.xml` (`urlset`: `<STORE_URL>/` i `/c/<path>` każdej kategorii z `GET /api/v1/categories`, w głąb)
  i `/sitemaps/products/<klucz>.xml` (`/p/<slug>` z `lastmod` = `updatedAt` z `GET /api/v1/sitemap/products?chunk=<klucz>`).
  Bez `lastmod` w indeksie i w mapie listingu. To własne route handlery (konwencja `sitemap.ts` z `generateSitemaps` nie
  tworzy `sitemapindex` ani 404 dla złego klucza). Segment `[file]` to `<klucz>.xml`; sklep nie dekoduje klucza, API go
  sprawdza.
- Odpowiedzi: 200 z `Content-Type: application/xml; charset=utf-8` i `Cache-Control: public, max-age=300`; pusta lista
  produktów to 200 z pustym `urlset`; adres bez `.xml` i klucz, który API odrzuca (400), to 404; każdy inny błąd API
  (także 404), błąd sieci albo nieoczekiwana treść (m.in. pusta lista porcji, `updatedAt` spoza RFC 3339 z przesunięciem) to 503 z `Cache-Control: no-store` i wpisem w logu serwera. Odpowiedzi 404 i 503 mają puste ciało (tylko nagłówki). Klucz porcji w adresie indeksu
  przechodzi przez `encodeURIComponent` (base64url bez zmian), a Next dekoduje segment `[file]` z powrotem. Sklep nigdy
  nie wysyła pustej ani częściowej mapy zamiast błędu.
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
- Kolejność sprawdzeń: brak sekretu w środowisku 503, brak lub zły sekret 401, `Content-Type` inny niż
  `application/json`, zły JSON albo zła lista tagów 400 (bez rewalidacji). Wszystkie odpowiedzi `private, no-store`.
  Sekret nie trafia do logów.

## Koszyk

- Przeglądarka rozmawia z koszykiem wyłącznie przez BFF (`/api/cart`, `/api/cart/summary`, `/api/cart/items`,
  `/api/cart/items/[variantId]`); API woła serwer sklepu. Route handlery są owinięte w `withCartDeps`
  (`src/cart/responses.ts`; zależności z `getCartDeps()` w `src/cart/deps.ts`, brak zależności daje 503 z `no-store`).
  Cała logika jest w `runCartCommand` (`src/cart/bff.ts`), testy podmieniają API dublerem z `src/cart/test-support.ts`.
- Koszyk wybiera sesja: zalogowany klient ma koszyk `/api/v1/me/cart` z tokenem z `freshAccessToken`, gość koszyk
  z ciasteczka `store_cart`. Ciasteczko zawiera id koszyka gościa podpisane (HMAC, `STORE_SESSION_SECRET`), nie
  szyfrowane; `HttpOnly`, `SameSite=Lax`, 30 dni odnawiane przy udanym zapisie. Niepoprawny podpis lub wartość spoza
  UUID jest usuwana jak brak ciasteczka (także przy sesji, wtedy bez scalania).
- Scalanie koszyka gościa (`mergeGuestCart` i `applyGuestCartMerge` w `src/cart/merge.ts`, jedno miejsce dla BFF
  i `/auth/callback`): `/auth/callback` po utworzeniu sesji (token z właśnie uzyskanych tokenów) i BFF przed każdą
  operacją przy sesji wołają `POST /api/v1/me/cart/merge`, gdy jest `store_cart`. Każda odpowiedź 200 (`merged`,
  `already_merged`, `nothing_to_merge`) usuwa `store_cart`. Błąd scalenia zostawia `store_cart` (następne żądanie
  koszyka ponawia scalenie) i trafia do `logFailure`; w BFF operacja idzie dalej na koszyku klienta, w callbacku
  logowanie kończy się normalnie. 401 scalenia w BFF kończy sesję jak każdy 401 API (odpowiedź 401, `store_cart`
  zostaje), token, którego nie da się odświeżyć, daje 401 bez scalania, a chwilowy błąd odświeżenia 503 bez scalania.
- `truncated` scalenia ustawia `store_cart_notice`: znacznik `merge_truncated` podpisany jak `store_cart`, `HttpOnly`,
  `SameSite=Lax`, `Path=/`, `Max-Age` 5 minut. `GET /api/cart/summary` z odpowiedzią 200 zwraca `notice:
  "merge_truncated"` raz (z poprawnie podpisanego ciasteczka albo ze scalenia w tym samym żądaniu) i usuwa ciasteczko;
  inaczej `notice: null`. `CartBadge` pokazuje wtedy „Część produktów z koszyka gościa nie zmieściła się
  w koszyku” (`role="status"`) jako osobny wiersz nagłówka pod pozostałymi, na pełną szerokość i w przepływie
  dokumentu (nie zasłania treści; trzeci wiersz siatki na telefonie, drugi od `lg`; w dokumencie ostatni element nagłówka, po wyszukiwarce); układ sprawdza
  `e2e/tests/visual-foundation.spec.ts`.
- `GET /api/cart?discountCode=` (podgląd kodu w kasie) przekazuje parametr do API koszyka gościa i klienta; inne parametry
  są pomijane. Koszyk bez `store_cart` i bez sesji (pusty) odpowiada bez API. 400 API (kod nie jest tekstem albo ma
  ponad 64 znaki) wraca jako 400 z `{ detail }` (tekst naruszenia z API), tylko dla odczytu z kodem; każdy inny 400
  to 503. `loadCart(discountCode?)` w `src/api/client.ts` zwraca wtedy `invalid` z `detail`.
- Żądania zmieniające (`POST`, `PATCH`, `DELETE`) wymagają `Origin` równego `STORE_URL`, a `POST` i `PATCH` także
  `Content-Type: application/json`; inaczej 403 bez wywołania API.
- `variantId` spoza UUID w `/api/cart/items/[variantId]` (PATCH i DELETE) dostaje 404 „Tej pozycji nie ma w koszyku.”
  bez wywołania API i bez ruszania ciasteczek (API też odpowiedziałoby 404, który wyglądałby jak zniknięty koszyk).
- 404 w koszyku gościa może znaczyć „koszyk zniknął”, „brak pozycji” albo „brak wariantu”. BFF nie zgaduje po tekście
  `detail`. Odczyt i usunięcie pozycji: 404 oznacza koszyk, który zniknął, więc ciasteczko jest usuwane, a odpowiedzią
  jest pusty koszyk (bez sondy). Dodanie i zmiana pytają o `summary` koszyka: 404 oznacza koszyk, który zniknął
  (ciasteczko usuwane, dodanie zakłada nowy koszyk i powtarza się raz, zmiana zwraca 404 pozycji), w przeciwnym razie
  404 jest przekazywany.
- Zapisy koszyka z przeglądarki (`addCartItem`, `changeCartItemQuantity`, `removeCartItem` w `src/api/client.ts`) idą
  jeden po drugim we wszystkich kartach: kolejka na poziomie modułu (`queueWrite`) ustala kolejność zapisów karty
  (następny startuje po zakończeniu poprzedniego, także po błędzie), a każdy zapis z kolejki trzyma blokadę Web Locks
  API `store-cart-write` (`navigator.locks.request`, `exclusive`), gdy przeglądarka ją ma. Dzięki temu równoczesne
  pierwsze dodania gościa bez `store_cart` z kilku kart nie zakładają kilku koszyków. Odrzucony `request` daje `failed`
  bez wysłania żądania; funkcje zapisu nigdy nie odrzucają obietnicy. Bez `navigator.locks` (kontekst bez HTTPS poza
  `localhost`) działa sama kolejka karty i ta gwarancja obejmuje tylko jedną kartę. Odczyty nie czekają ani w kolejce,
  ani na blokadę. Testy ustawiają `navigator` przez `vi.stubGlobal` (Node ma własne `navigator.locks`).
- UI: `ProductTile` (Server Component: nazwa, zdjęcie, cena „od X zł” przy `hasPriceRange` z oznaczeniem promocji i linią Omnibus z `fromPromotion`, poziom produktu; produkt
  bez wariantu możliwego do kupienia, `fromGross = null`: „Niedostępny” bez ceny, listy i przycisku) z częścią kliencką
  `TileOffer`: produkt z jednym wariantem ma od razu przycisk `AddToCartButton` dodający ten wariant; produkt z ≥ 2
  wariantami ma jedną aktywną akcję „Wybierz wariant” (bez `<select>` i bez nieaktywnego „Do koszyka”). Po niej akcja
  znika, a kafelek pokazuje warianty z oferty w jej kolejności jako grupę radio (`fieldset` z nazwami osi albo „Wybierz
  wariant” w `legend`; jeden przystanek Tab, strzałki zmieniają wybór, wybór można zmienić) bez wyboru domyślnego;
  fokus przechodzi na pierwszy wariant możliwy do wybrania, a wariant `purchasable = false` ma dopisek „niedostępny”
  i jest wyłączony. Jedna oś: etykiety jako przyciski (`data-form="buttons"`; na telefonie jeden pod drugim, od `lg` w rzędzie z zawijaniem); dwie osie lub więcej: lista pełnych
  etykiet jedna pod drugą (`data-form="list"`, mieści się w 375 px bez przewijania poziomego); oferta ma tylko złączoną
  etykietę, więc sklep nie rozbija jej na osie. Ukryty wizualnie obszar `role="status"` (`aria-live="polite"`, istnieje
  od pierwszego renderu) ogłasza rozwinięcie i każdy wybór (etykieta, cena, dostępność). Po wyborze kafelek pokazuje cenę, oznaczenie
  promocji (z `promotion` wariantu) i poziom wybranego wariantu oraz aktywny „Do koszyka”, który dodaje 1 sztukę wybranego wariantu
  (bez wyboru przycisku „Do koszyka” nie ma; zmiana wyboru w trakcie żądania zostawia przycisk zablokowany, a komunikat
  „Dodano do koszyka” dotyczy tylko wariantu, dla którego powstał). To samo na kafelkach strony głównej. `CartBadge` w nagłówku (licznik z `/api/cart/summary`) i strona `/cart` z `noindex`
  (`CartPage`, Client Component ładujący `/api/cart`), linia z nazwą produktu i etykietą wariantu, pod podsumowaniem
  przycisk „Przejdź do kasy” (link do `/checkout`; przy `hasExcludedLines` nieaktywny z tekstem „Usuń albo popraw
  oznaczone pozycje, żeby złożyć zamówienie.”). Po każdej zmianie koszyka komponent ogłasza `itemCount` z
  odpowiedzi zdarzeniem (`src/cart/events.ts`), a licznik się aktualizuje. Front niczego nie liczy: kwoty, sumy i stany
  linii pochodzą z ostatniej odpowiedzi API, a odrzucona zmiana zostawia ostatni widok.
- Odpowiedzi: 200 z danymi API, 404/409/422 z `{ detail }` API, 401 (sesja się skończyła: usunięta z Redisa i
  ciasteczka), 403, 503 (chwilowy błąd API, Redisa lub Keycloaka; sesja zostaje). Wszystkie z `private, no-store`.

## Kasa

- `POST /api/checkout` (`runCheckout` w `src/checkout/bff.ts`, owinięty w `withCartDeps`, testy na dublerze API
  z `src/cart/test-support.ts`) składa zamówienie całego koszyka i rozpoczyna jego płatność. Te same zabezpieczenia
  co zapisy koszyka (`Origin` równy `STORE_URL` i `Content-Type: application/json`, inaczej 403 bez API). Nagłówek
  `Idempotency-Key` wybiera przeglądarka; jego brak albo treść, która nie jest obiektem JSON, daje 422
  „Nieprawidłowe dane.” bez API. BFF przekazuje klucz do API bez zmian, także do rozpoczęcia płatności. Przeglądarka
  woła BFF przez `placeOrder(input, idempotencyKey)` w `src/api/client.ts` (wynik: `ok` z numerem i `redirectUrl`,
  `invalid` z `violations`, `conflict`, `not_found`, `unauthorized`, `failed`; funkcja nigdy nie odrzuca obietnicy).
- Dostawca płatności (`provider` w treści) jest sprawdzany jako pierwszy, przed scaleniem koszyka i złożeniem, z listą
  `GET /api/v1/payment-providers` (`listPaymentProviders` w `src/checkout/payment-api.ts`): brak albo kod spoza listy
  to 422 z naruszeniem pola `provider` („Wybierz sposób płatności.”), lista niedostępna to 503.
- Koszyk wybiera sesja, jak w BFF koszyka: klient `POST /api/v1/me/orders` z tokenem z `freshAccessToken` po scaleniu
  `store_cart` (`mergeGuestCart`), gość `POST /api/v1/orders` z `cartId` z `store_cart`. Bez `store_cart` (albo
  z niepoprawnym podpisem, wtedy ciasteczko jest usuwane) odpowiedź to 409 „Koszyk jest pusty.” bez API.
- Do API trafiają tylko pola kontraktu (`pickFields` w `src/checkout/api.ts`: gość `email`, `fullName`, `phone`,
  `street`, `postalCode`, `city`, `expectedTotalGross`, `discountCode`, klient bez `email`), z wartościami, jakie przysłała przeglądarka:
  sprawdza je API. `cartId` pochodzi wyłącznie z ciasteczka; ceny, linie i inne pola przeglądarki są pomijane.
- Sukces (201 albo 200 przy powtórzeniu z tym samym kluczem): ciasteczko `store_order_confirmation` z `{orderId,
  number, email, totalGross, currency, accessToken}` z `OrderView` (gość ma `accessToken`, klient `null`; JSON
  w base64url, podpisany jak `store_cart`, `HttpOnly`, `SameSite=Lax`, `Path=/`, `Max-Age` 1 godzina; stare ciasteczko
  bez `orderId` jest niepoprawne). Potem BFF rozpoczyna płatność tym samym `Idempotency-Key` (gość `Order-Access-Token`,
  klient `/me/...` z tokenem sesji) i odpowiada `{ number, redirectUrl }`. Każdy błąd rozpoczęcia płatności (także 409
  `PaymentIdempotencyKeyReused`, gdy klient zmienił dostawcę przy ponowieniu z tym samym kluczem złożenia) daje
  `redirectUrl: null` i wpis w logu bez tokenu: zamówienie stoi, a klient płaci ze strony potwierdzenia. `store_cart`
  zostaje (koszyk jest pusty). `POST /auth/logout` usuwa to ciasteczko razem z sesją (zawiera e-mail zamówienia
  i token dostępu).
- Błędy: 404, 409 i 422 API z `{ detail, violations }`, 409 także z `type` bez zmian; własne 409 bez `store_cart` ma
  `type` `CartEmpty` (`src/checkout/problems.ts`); 404 gościa (koszyk zniknął) usuwa `store_cart`; 401 sesji
  kończy sesję jak w koszyku; 400, 415, 401 gościa, 5xx i niedostępne API dają 503. Wszystkie odpowiedzi
  z `private, no-store`.
- `POST` i `GET /api/checkout/payment` (`runPaymentStart` i `runPaymentStatus` w `src/checkout/payment-bff.ts`, ten
  sam `withCartDeps`): płatność zamówienia z `store_order_confirmation` (brak ciasteczka, zły podpis albo ciasteczko
  bez `orderId`: 404 „Nie znaleźliśmy zamówienia.” bez API). Endpoint wybiera ciasteczko, nie sesja: `accessToken`
  w ciasteczku → endpointy gościa z `Order-Access-Token` (także gdy gość zalogował się po złożeniu zamówienia),
  `accessToken: null` → `/me/...` z tokenem sesji (bez sesji 401 bez API; sesja, której tokenu nie da się
  odświeżyć, kończy się z 401). `POST` ma te same zabezpieczenia i `Idempotency-Key` co kasa, treść
  `{ provider }` (inne pola są pomijane, brak `provider` przekazuje API, które nazywa pole), odpowiedź `{ redirectUrl }`;
  404, 409 (z `type`) i 422 API przechodzą z `detail` i `violations`, 401 klienta (sesja) kończy sesję, reszta (także 400,
  415 i 401 endpointu gościa) to 503. `GET` odpowiada `PaymentStatusView` API: 404 bez ciasteczka i przy 404 API, 401 dla
  zamówienia klienta bez sesji (401 API kończy sesję), 503 przy każdym innym błędzie albo treści, która nie jest
  statusem. Strona potwierdzenia czyta status tą samą funkcją (`readPaymentStatus`). Przeglądarka woła je przez
  `startPayment(provider, idempotencyKey)` i `getPaymentStatus()`; wynik 409 to `conflict` z `reason` z `type`
  (`paymentConflictReason` w `src/checkout/problems.ts`, mapa sprawdzana kompilatorem względem
  `PaymentConflictProblem`).
- `/checkout` (`noindex, nofollow`, `CheckoutPage` jako Client Component z danymi serwera: `app/checkout/page.tsx`
  czyta listę dostawców API i numer zamówienia z `store_order_confirmation`, więc strona jest dynamiczna):
  podsumowanie z `/api/cart` (nazwa, etykieta wariantu, ilość, wartość linii, suma brutto z API), formularz (gość:
  pole e-mail i link „Zaloguj się” z `returnTo=/checkout`; klient: e-mail z `/auth/session` jako tekst, imię
  i nazwisko wstępnie z sesji, chyba że konto ma tylko e-mail), grupa radiowa „Sposób płatności” z dostawcami
  z API (wymagana, bez domyślnego wyboru; brak wyboru: „Wybierz sposób płatności.” przy grupie, bez żądania
  i z fokusem na pierwszym dostawcy), przycisk „Zamawiam z obowiązkiem zapłaty” zablokowany na czas żądania. Lista
  niedostępna albo pusta: „Płatności są chwilowo niedostępne. Spróbuj za chwilę.” zamiast przycisku. Pusty koszyk albo
  `hasExcludedLines`: komunikat i link do koszyka zamiast formularza; przy pustym koszyku i poprawnym ciasteczku
  potwierdzenia także link „Opłać zamówienie R-…” na `/checkout/confirmation` (klient cofnął się ze strony dostawcy;
  sklep nie sprawdza przy tym statusu). `expectedTotalGross` to `totals.gross` ostatnio wczytanego podsumowania.
- Kod rabatowy w kasie (`DiscountCode` w `CheckoutPage`): pole „Kod rabatowy” (`maxLength` 64) z przyciskiem „Zastosuj”
  w osobnym formularzu pod podsumowaniem (Enter stosuje kod, nie składa zamówienia). Stan kodu jest tylko w komponencie
  (nie w ciasteczku ani w storage), więc nie przeżywa przeładowania strony. „Zastosuj” czyta podsumowanie przez
  `loadCart(kod)` (`loadPreview`): pusty kod to komunikat „Wpisz kod rabatowy.” bez żądania; zastosowany (`discount.status`
  `applied`) pokazuje linię „Kod <KOD>: −X zł” przed „Do zapłaty (brutto)” (sumy z API), przycisk „Usuń kod” zamiast pola
  i ogłoszenie w regionie `role="status"`; odrzucony (`rejected`) pokazuje `detail` z API przy polu (`role="alert"`,
  `aria-describedby`) i podsumowanie bez rabatu; 400 (kod za długi) pokazuje tekst z BFF przy polu i czyta podsumowanie
  bez kodu. „Usuń kod” czyta podsumowanie bez kodu. Fokus: na „Usuń kod” po zastosowaniu, na polu po odrzuceniu
  i po usunięciu. Kontrolki kodu i przycisk zamówienia są zablokowane na czas podglądu. Złożenie wysyła `discountCode`
  zastosowanego kodu (bez pola, gdy kodu nie ma) i `expectedTotalGross` = `totals.gross` podsumowania z rabatem. Nowy
  `Idempotency-Key` po zastosowaniu i po usunięciu kodu (odrzucony kod nie zmienia żądania, więc klucza). 409 z typem
  kodu (`discount-code-invalid`, `-used-up`, `-below-minimum`; powód `discount_code_rejected` w `problems.ts`): `detail`
  przy polu, kod zdjęty, podsumowanie wczytane bez kodu, nowy klucz; zamówienie składa dopiero następne kliknięcie.
  409 `OrderTotalChanged` (i pozostałe wczytujące podsumowanie) czyta je ponownie z tym samym kodem; kod, który API
  odrzuci przy tym odczycie, pokazuje `detail` przy polu. Pusty koszyk albo linie wykluczone zastępują formularz razem z polem kodu.
- `Idempotency-Key` (`crypto.randomUUID`, więc kasa wymaga bezpiecznego kontekstu: HTTPS albo `localhost`) to klucz
  jednej logicznej próby. Powstaje przy wejściu na stronę i wraca przy ponowieniu niepewnego wyniku (błąd sieci, 403,
  5xx) oraz po 409 `CartEmpty`, `CartNotOrderable`, `InsufficientStockForOrder` i nieznanym; nowy klucz po zmianie
  pola formularza, po 409 `IdempotencyKeyReused` i po 409 `OrderTotalChanged` (inna kwota w żądaniu, nic nie zostało
  złożone). Zmiana dostawcy nie zmienia klucza (dostawca nie należy do odcisku zamówienia). Znany skutek: gdy wynik
  poprzedniej próby był niepewny (sieć, 5xx, 503, 403), a zamówienie jednak powstało, zmiana pola i ponowne
  kliknięcie dają 409 `CartEmpty` (pusty koszyk), a nie potwierdzenie; drugiego zamówienia nie ma, bo koszyk jest
  pusty. Formularz ma `noValidate`: dane sprawdza API, a komunikaty 422 (`violations`) są przy polach, także
  komunikat o dostawcy przy grupie.
- Odpowiedzi kasy w UI: sukces ogłasza licznik 0 (`src/cart/events.ts`) i przechodzi na stronę dostawcy
  (`navigateTo(redirectUrl)` z `src/checkout/navigate.ts`, `window.location.assign`), a bez `redirectUrl` na
  `/checkout/confirmation`; przycisk zostaje zablokowany. Przyczynę 409 wyznacza wyłącznie `type` (`placeOrder` zwraca
  `reason` z `conflictReason`, mapa typów w `src/checkout/problems.ts` jest sprawdzana kompilatorem względem schematu),
  nigdy tekst `detail`:
  `OrderTotalChanged` pokazuje `detail` bez linku do koszyka i wczytuje podsumowanie ponownie (nowa kwota, formularz
  zostaje); `CartEmpty`, `CartNotOrderable`, `InsufficientStockForOrder` i nieznany 409 (inny `type` albo brak; bez
  `detail` tekst „Nie udało się złożyć zamówienia. Spróbuj ponownie.”) pokazują `detail` z linkiem „Wróć do koszyka”
  i też wczytują podsumowanie; `IdempotencyKeyReused` pokazuje `detail` bez linku i bez wczytywania. Pusty koszyk albo
  `hasExcludedLines` po przeładowaniu zastępuje formularz komunikatem z własnym linkiem, wtedy komunikat błędu nie ma
  drugiego. Żadne odrzucenie nie ponawia zamówienia samo. 404: „Koszyk nie jest już dostępny.” z linkiem do sklepu;
  401: „Twoja sesja wygasła. Zaloguj się ponownie.” z `returnTo=/checkout`; 403, 503, 5xx i błąd sieci: „Nie udało
  się złożyć zamówienia. Spróbuj ponownie.”. Po każdym odrzuceniu fokus idzie na komunikat (`role="alert"`,
  `tabIndex={-1}`, bo przycisk jest zablokowany na czas żądania), a po 422 z błędami pól na pierwsze błędne pole.
- `/checkout/confirmation` (`noindex, nofollow`, Server Component): czyta `store_order_confirmation` (`cookies()`
  i `readOrderConfirmation`, sekret z `readAuthConfig`). Bez ciasteczka, z niepoprawnym podpisem albo treścią:
  „Nie znaleźliśmy informacji o zamówieniu.” i link „Wróć do sklepu”. Inaczej czyta status płatności (`readPaymentStatus`:
  gość tokenem z ciasteczka, klient sesją) i listę dostawców, a do Client Componentu `PaymentConfirmation`
  przekazuje tylko numer, e-mail, kwotę, status i dostawców (nigdy `accessToken` ani `orderId`). Stany wyznacza API:
  `paid` („Płatność przyjęta. Potwierdzenie wysłaliśmy na adres …”), `cancelled`, `new` ze `canStartPayment = false`
  (po terminie, okno przed anulowaniem: „Minął termin płatności…”, bez przycisków płatności), `new` z oczekującą
  płatnością (link „Wróć do płatności” na `resumeUrl`, „Czekamy na potwierdzenie płatności…”) i `new` bez oczekującej
  płatności (wybór dostawcy i „Zapłać”, przy `failed` „Płatność nie powiodła się.”); odczyt nieudany (numer i kwota
  z ciasteczka, „Sprawdź ponownie”), 401 (link do logowania z `returnTo=/checkout/confirmation`) i 404. Strona nie
  używa zegara przeglądarki do stanów; godzinę terminu formatuje `formatDeadlineTime` jawnie w `Europe/Warsaw`.
  Oczekiwanie odświeża status co 2 s przez 30 s (nieudane odświeżenie zostawia widok, 401 i 404 go zastępują); potem
  „Nie mamy jeszcze potwierdzenia płatności.”, „Sprawdź ponownie” (jedno odczytanie) i „Zapłać innym sposobem”.
  „Zapłać”: klucz nowy przy każdej próbie i po zmianie dostawcy, ten sam przy ponowieniu niepewnego wyniku (błąd
  sieci, 5xx, 503, nieznany 409) z tym samym dostawcą. Wyniki: `redirectUrl` → strona dostawcy, 409 `PaymentInProgress`
  → `detail`, odczyt statusu i ponowne 30 s odświeżania, 409 `OrderAlreadyPaid` i `PaymentDeadlinePassed` → odczyt statusu
  (komunikat tylko, gdy zamówienie nie jest opłacone ani anulowane), 409 `PaymentIdempotencyKeyReused` → `detail` i nowy
  klucz, 404 → „Nie znaleźliśmy zamówienia.”, 422 → komunikat przy grupie, 401 → link do logowania, reszta →
  „Nie udało się rozpocząć płatności. Spróbuj ponownie.”. Po odrzuceniu fokus idzie na komunikat (`role="alert"`).

## Logowanie

- Tokeny OIDC nigdy nie trafiają do przeglądarki. Są tylko w sesji w Redisie, a ciasteczko zawiera wyłącznie
  id sesji podpisane kluczem `STORE_SESSION_SECRET`.
- Route handlery `/auth/*` są owinięte w `withAuthDeps` (`src/auth/responses.ts`), które daje im zależności
  (Redis, OIDC, klient API) z `getAuthDeps()` w `src/auth/deps.ts`, a przy ich braku odpowiada 503 z `no-store`.
  `getCartDeps()` bierze z nich sesje, `freshAccessToken`, klienta API i konfigurację. Testy podmieniają zależności
  dublerami z `src/auth/test-support.ts` (domyślny klient API odrzuca każde wywołanie).
- Nie loguj obiektów błędów openid-client (ich `cause` zawiera odpowiedzi z tokenami). Użyj `logFailure`.
- `GET /auth/session` zwraca `{ name, email }` zalogowanego klienta (z `GET /api/v1/me`; `name` to e-mail, gdy konto
  nie ma imienia) albo `null`. Korzystają z niego konto w nagłówku i kasa (e-mail klienta, imię i nazwisko).
- `returnTo` przechodzi zawsze przez `sanitizeReturnTo`.
- Odpowiedzi `/auth/*` mają `Cache-Control: private, no-store`.

## Przełączniki wdrożenia (`GET /api/site`)

- Route handler (dynamiczny, `Cache-Control: no-store`) zwraca `{ demoNotice, customerAccounts }` ze zmiennych serwera
  `STORE_DEMO_NOTICE` (domyślnie `false`) i `STORE_CUSTOMER_ACCOUNTS` (domyślnie `true`). Wartością jest `true` albo
  `false` (wielkość liter i spacje wokół bez znaczenia); każda inna, także pusta, daje wartość domyślną. Bez zmiennych
  sklep działa jak lokalnie i w testach. Wartości ustawia chart (`environments/prod/values.yaml`).
- Przełączniki czyta tylko przeglądarka: `SiteSwitchesProvider` w `app/layout.tsx` woła `loadSiteSwitches()`
  (`src/api/client.ts`) raz na stronę i rozdaje wynik przez `useSiteSwitches()`. Layout i strony nie czytają tych
  zmiennych na serwerze, więc HTML stron statycznych i ISR jest taki sam w każdym wdrożeniu. Nie czytaj ich w kodzie
  serwera stron.
- `DemoNotice` pokazuje nad nagłówkiem pasek „Sklep demonstracyjny: płatności i zamówienia są symulowane, nic nie
  zostanie wysłane ani pobrane.”, gdy `demoNotice`. `AccountMenu` nie pokazuje „Zaloguj się”, gdy `!customerAccounts`,
  i czeka z linkiem na odczyt przełączników, żeby nie mignął. Zalogowany klient nadal widzi „Wyloguj się”.
- Błąd odczytu (sieć, status nie-2xx, zły JSON, przełącznik niebędący booleanem) daje stan domyślny: bez paska,
  z linkiem.

## Konto: historia zamówień

- `AccountMenu` pokazuje zalogowanemu klientowi link „Moje zamówienia” (`/konto/zamowienia`).
- `/konto/zamowienia` (`OrdersPage`) i `/konto/zamowienia/[orderId]` (`OrderDetailPage`) to Server Components tylko z
  metadanymi (`noindex`, tytuł) i Client Componentem, który ładuje dane w przeglądarce przez `loadCustomerOrders`
  i `loadCustomerOrder` z `src/api/client.ts`: HTML strony nie zależy od sesji i nie zawiera danych zamówień.
- BFF: `GET /api/account/orders?after=` i `GET /api/account/orders/[orderId]` (`withAuthDeps`, logika w
  `src/account/orders-bff.ts`): sesja z ciasteczka, `freshAccessToken`, wywołanie `GET /api/v1/me/orders` albo
  `/api/v1/me/orders/{orderId}` przez `API_INTERNAL_URL` (nigdy Varnish), odpowiedź `private, no-store`. Dalej idzie tylko
  kursor `after` (rozmiar strony to domyślne 10 API; klienta wskazuje token, nie parametr). Wyniki: 200 z treścią API;
  401 bez ciasteczka albo z podrobionym (to czyści), gdy `freshAccessToken` zwróci `null` albo API odpowie 401 (sesja
  kończy się: usunięcie z Redisa i czyszczenie ciasteczka); 400 dla kursora, który API odrzuci; 404 z tekstem „Nie
  znaleźliśmy zamówienia.” dla id spoza UUID (bez wywołania API) i każdego zamówienia, którego API nie daje klientowi;
  503 `{}` dla reszty (status API, treść, której nie rozpoznaje, sieć, niedostępne odświeżenie tokenu), z zachowaniem
  sesji. Kolejność: sesja przed id.
- Przeglądarka: 401 z BFF (także przy „Pokaż więcej” po wygaśnięciu sesji) to `navigateTo` na
  `/auth/login?returnTo=<bieżąca ścieżka>` (ścieżka strony w `encodeURIComponent`; `sanitizeReturnTo` sprawdza ją
  w `/auth/login`) z odnośnikiem „Zaloguj się” jako zapasowym, a załadowane pozycje zostają. Brak sesji przy wejściu
  przechodzi tą samą drogą.
- Lista: po 10 (rozmiar z API), numer (link do szczegółów), data i godzina w `Europe/Warsaw` (`formatOrderMoment`),
  etykieta statusu z `ORDER_STATUS_LABELS` (Czeka na płatność, Opłacone, Wysłane, Dostarczone, Anulowane, Zwrócone),
  kwota brutto z API (`formatPrice`), „N szt.”, „Pokaż więcej” tylko przy `nextCursor`. Nieudana kolejna strona
  zostawia wczytane pozycje, pokazuje „Nie udało się wczytać zamówień. Spróbuj ponownie.” z przyciskiem, który ponawia
  tę samą stronę; kliknięcie w trakcie ładowania nie wysyła drugiego żądania. Pusta lista: „Nie masz jeszcze
  zamówień.”; nieudana pierwsza strona: ten sam komunikat błędu z przyciskiem „Spróbuj ponownie”.
- Szczegóły: pozycje (nazwa, etykieta wariantu, ilość × cena, wartość linii przed rabatem `grossBeforeDiscount`), przy
  zamówieniu z kodem linia „Kod <KOD>: −X zł” (`discount`), netto, VAT i razem brutto (po rabacie) z API, adres
  dostawy i daty kolejnych statusów (złożone, opłacone, wysłane, dostarczone, anulowane, zwrócone; tylko osiągnięte). Zamówienie `new`:
  „Czeka na płatność do 14:35” (`OrderPaymentNote`), po terminie wg `paymentDeadlinePassed` z API „Termin płatności
  minął”; płatności z historii nie ma. 404: „Nie znaleźliśmy zamówienia.” z linkiem „Wróć do zamówień”; inny błąd:
  komunikat błędu z przyciskiem. Front niczego nie liczy i nie porównuje z zegarem przeglądarki.
- `PaymentConfirmation` dla `cancelled` mówi neutralnie „Zamówienie zostało anulowane.” (przyczynę podaje e-mail).

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
- Paletę (`--palette-*`) i istniejące tokeny zmienia tylko VISUAL-DESIGN-5; kolejne zadania wyglądu mogą dopisać nowy
  token semantyczny wskazujący istniejącą paletę.
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
  Hero: kadr telefonu (`media="(max-width: 767px)"`, 4:5, 480, 640, 828) i szerokiego ekranu (16:9, 1280, 1600);
  kafelki 3:4 w 320, 480, 768. Budżety: hero 1600 ≤ 250 KB, hero 828 ≤ 120 KB, kafelek 768 ≤ 80 KB.
- `scripts/marketing-images.mjs` czyta listę (Node usuwa typy z `.ts`), a z niej generuje pliki i moduł. Uruchamia się
  w kontenerze `store`: `make -C apps/store shell`, potem `node scripts/marketing-images.mjs`. Źródła są pod
  `/fixtures/images` z montowania tylko do odczytu `./apps/api/fixtures/images:/fixtures/images:ro` usługi `store`
  (`infra/docker-compose.yml`, tylko środowisko deweloperskie: obraz produkcyjny ma commitowane pliki WebP; po dodaniu
  montowania odtwórz kontener `docker compose up -d store`). Używa `sharp`, który instaluje Next.js (bez zmiany
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
- Strona główna (`src/home/`) i jej stan domyślny w `app/page.test.tsx`; liczba żądań w `app/page.requests.test.tsx`.
- Logika w komponentach (np. budowa `srcset`, mega menu, panel filtrów i sortowanie), `listing-query.ts` (mapowanie
  parametrów URL na zapytanie API i adresy), strony listingów (`app/page.test.tsx`, `app/c/`, `app/szukaj/`, liczba
  żądań w `app/page.requests.test.tsx`), cały `src/auth/`, cały `src/cart/` (BFF, przyciski,
  strona koszyka) i cały `src/checkout/` (BFF, strona kasy) oraz strona potwierdzenia mają testy.
