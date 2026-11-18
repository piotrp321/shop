# 06 – Frontend sklepu (`apps/store`, Next.js App Router)

Audyt statyczny migawki `main` (`ef204620`). Ścieżki względem katalogu eksportu.

## Zakres i metoda

Przeczytane w całości (kod produkcyjny, bez testów), ok. 105 plików z ok. 10,6 tys. linii:

- konfiguracja: `apps/store/CLAUDE.md` (848 linii), `next.config.ts`, `package.json`, `instrumentation.ts`,
  `vitest.config.ts`, `eslint.config.mjs`, `Dockerfile`, `tsconfig.json`;
- `app/`: layout, 9 stron, sloty `@search`, `error.tsx`, `not-found.tsx`, 14 route handlerów (`auth/*`, `api/cart/*`,
  `api/checkout/*`, `api/account/*`, `api/site`, `api/revalidate`), `robots.ts`, trasy mapy strony;
- `src/api/client.ts`, cały `src/auth`, `src/cart`, `src/checkout`, `src/account`, `src/seo`, `src/home` (bez
  `home-images.ts`, `marketing-images.ts`), `src/catalog` (wszystkie komponenty, loadery i moduły zapytań),
  `src/components/SiteSwitches.tsx`, `src/observability/lazy.ts`.

Przeczytane wyrywkowo: testy (114 plików, ok. 17 tys. linii, 1243 przypadki; przeczytane nagłówki i listy przypadków
`app/api/checkout/route.test.ts`, `src/checkout/CheckoutPage.test.tsx`, `src/cart/CartPage.test.tsx`,
`app/page.test.tsx`, `app/page.requests.test.tsx`, `app/p/[slug]/page.test.tsx`, `app/error.test.tsx`,
`src/catalog/category-tree.test.ts`, dublery `src/cart/test-support.ts`), sekcje „Decyzje” i „Ryzyka i ograniczenia”
specyfikacji `cart`, `checkout`, `product-page`, `search`, `seo`, `lighthouse`, `hardening`, `docs/vision.md`
(wymagania niefunkcjonalne).

Sprawdzone poza `apps/store`, żeby wykluczyć fałszywe alarmy: `infra/varnish/default.vcl`, szablon
`infra/k8s/charts/shop/templates/store.yaml` i helper deploymentu (repliki), `environments/prod/values.yaml`
(przełączniki), `infra/k8s/smoke/checks.py`, handlery unieważniania cache w API
(`InvalidateStorefrontOn*`), kontroler `ProductDetailsController`, `apps/api/openapi.json` (opis listy produktów).

Nie czytałem: `src/observability/{filter,server,browser,options,config}.ts`, `src/styles/tokens.css`,
`scripts/*.mjs`, `src/components/ui/*`, wygenerowanego `schema.d.ts` (poza kilkoma typami), testów E2E (tylko grep).

## Ocena ogólna obszaru: 7,5 / 10

Sklep jest napisany wyjątkowo zdyscyplinowanie. Granica przeglądarka → BFF → API jest konsekwentna, tokeny i
identyfikatory nie wychodzą do przeglądarki, HTML stron cache'owanych nie zależy od sesji, front nie liczy żadnych
kwot, a obsługa błędów kasy (klucz idempotencji, typy problemów 409, fokus) jest dokładniejsza niż w większości
sklepów produkcyjnych. Testy jednostkowe są liczne i sprawdzają zachowanie na granicy sieci, a nie implementację.

Punkty odjąłem za cztery rzeczy:

1. odporność: żadne wywołanie API z serwera sklepu nie ma limitu czasu, nie ma `loading.tsx` ani strumieniowania,
   więc powolne API oznacza wiszące strony zamiast szybkiego błędu;
2. skala docelowa (10 mln produktów): cache ISR na dysku bez limitu, paginacja prowadząca poza okno 10 000 wyników,
   całe drzewo kategorii w każdej stronie;
3. kilka realnych wad wykonania, których nie łapią testy (strony statyczne obrazu produkcyjnego bez menu, utrata
   fokusu w koszyku, przesunięcie układu przez pasek demo);
4. koszt złożoności: część rozwiązań (wyszukiwarka w nagłówku, slot `@search`, kolejka Web Locks) jest dużo cięższa
   niż problem, który rozwiązuje, a `apps/store/CLAUDE.md` ma 87 KB.

Nie znalazłem wady klasy Critical ani High.

## Znaleziska

### Z1. Wywołania API z serwera sklepu nie mają limitu czasu

- **Waga:** Medium. **Klasa:** RYZYKO.
- **Dowód:** `apps/store/src/api/client.ts:14`

  ```ts
  export function createApiClient(options: { baseUrl: string; accessToken?: string; fetch?: ...; tags?: string[] }): ApiClient {
    return createClient<paths>({
      baseUrl: options.baseUrl,
      fetch: options.fetch,
      headers: options.accessToken ? { Authorization: `Bearer ${options.accessToken}` } : undefined,
      requestInitExt: options.tags === undefined ? undefined : { next: { tags: options.tags } },
    });
  }
  ```

  Wyszukanie `AbortSignal`, `signal:` i `timeout` w `apps/store/src` i `apps/store/app` (bez testów) zwraca jedno
  trafienie: `src/auth/oidc.ts:37` (`HTTP_TIMEOUT_SECONDS = 2`, tylko Keycloak). Odczyty katalogu
  (`src/catalog/load-catalog.ts:66`), koszyk (`src/cart/api.ts:22`), kasa (`src/checkout/api.ts:23`), `/api/v1/me`
  (`src/auth/me.ts:10`) idą bez sygnału przerwania.
- **Warunki i prawdopodobieństwo:** API odpowiada wolno albo wcale, ale przyjmuje połączenia (wyczerpana pula
  PHP-FPM, blokada w bazie, długi GC). Przy jednym węźle i jednej replice API to scenariusz realny, nie egzotyczny.
- **Konsekwencja:** domyślny limit `fetch` w Node to 300 s na nagłówki. Odczyty katalogu ogranicza dopiero Varnish
  (domyślne `first_byte_timeout` 60 s, VCL go nie zmienia), a wywołania BFF idą do `http://api` bezpośrednio, więc
  wiszą do 5 minut. Klient zamiast strony błędu z przyciskiem „Spróbuj ponownie” (`app/error.tsx`) ogląda pustą
  kartę; każda odsłona dokłada jeszcze `GET /api/cart/summary` i `GET /auth/session`, które też wiszą. Sonda
  gotowości sklepu to statyczne `/favicon.ico` (`infra/k8s/charts/shop/templates/store.yaml`), więc pod zostaje
  „gotowy” i zbiera ruch. Kasa: klient po kilkudziesięciu sekundach odświeża stronę i traci klucz idempotencji (Z8).
- **Rekomendacja:** jeden limit w `createApiClient` (`AbortSignal.timeout`, np. 3–5 s dla odczytów, 10–15 s dla
  złożenia zamówienia), mapowany na istniejące ścieżki błędu (wyjątek → `error.tsx`, BFF → 503). To kilka linii
  w jedynym miejscu rozmowy z API.
- **Pewność:** wysoka co do braku limitu (grep i lektura wszystkich wywołań); średnia co do czasu po stronie Varnisha
  (wartość domyślna, nie zmierzona). Specyfikacje `hardening`, `cart`, `checkout`, `product-page` nie wymieniają tego
  jako świadomego kompromisu.

### Z2. Strony statyczne obrazu produkcyjnego powstają bez menu i bez tagu `catalog`, więc rewalidacja ich nie naprawi

- **Waga:** Medium. **Klasa:** BŁĄD (wniosek ze statycznej analizy, niepotwierdzony uruchomieniem).
- **Dowód:** `apps/store/src/catalog/category-tree.ts:13`

  ```ts
  export async function loadCategoryTree(): Promise<CategoryNode[]> {
    const baseUrl = process.env.API_CATALOG_URL;
    if (!baseUrl) {
      throw new Error("API_CATALOG_URL is not set.");
    }
    const { data, response } = await createApiClient({ baseUrl, tags: ["catalog"] }).GET("/api/v1/categories");
  ```

  `apps/store/Dockerfile:12` („it prerenders without API_CATALOG_URL and its pages fall back”) i `:38`
  (`STORE_STANDALONE=1 npm run build`, bez tej zmiennej). `apps/store/CLAUDE.md:54-56` twierdzi, że te strony „menu
  dostają przy pierwszej rewalidacji tagu `catalog`”.
- **Ścieżka:** w budowaniu obrazu wyjątek pada przed `fetch`, `loadMenuCategories` go łapie
  (`category-tree.ts:38-46`) i layout renderuje nagłówek bez menu. Tag cache trafia do strony tylko przez wywołanie
  `fetch` z `next.tags`, a ono się nie odbyło, więc prerenderowany HTML nie ma tagu `catalog`.
  `revalidateTag("catalog", { expire: 0 })` z `app/api/revalidate/route.ts:57` nie ma czego unieważnić. Strona
  statyczna bez `revalidate` nie odświeża się sama.
- **Konsekwencja:** w klastrze (obraz `prod`) `/cart`, `/konto/zamowienia` i strona 404 nieznanego adresu nie mają
  menu działów ani linków stopki przez cały czas życia obrazu. Klient w koszyku nie ma jak wrócić do działu inaczej
  niż przez logo albo wyszukiwarkę. Lokalnie i w CI błędu nie widać: tam `next build` biegnie w kontenerze z
  ustawionym `API_CATALOG_URL` (`infra/docker-compose.yml:399`), więc `fetch` jest wywołany i tag istnieje.
- **Rekomendacja:** najpierw potwierdzić w teście dymnym klastra (dziś `infra/k8s/smoke/checks.py:320` sprawdza na
  `/cart` tylko brak paska demo): asercja, że `/cart` po `purge-caches` zawiera `nav` „Działy”. Naprawa: budować obraz
  z zastępczym `API_CATALOG_URL` (nieosiągalny adres), żeby otagowany `fetch` się odbył i zawiódł po sieci, albo dać
  tym stronom rewalidację czasową.
- **Pewność:** średnia. Pewne są: kolejność `throw` przed `fetch`, brak zmiennej w Dockerfile, brak testu dymnego
  menu. Niepewne jest zachowanie Next.js 16.4 (nie miałem `node_modules` ani buildu); jeśli ta wersja taguje strony
  inaczej, znalezisko upada.

### Z3. Paginacja listingu prowadzi poza okno 10 000 wyników API

- **Waga:** Medium (przy katalogu demo niewidoczne, przy każdym katalogu powyżej 10 tys. produktów pewne).
  **Klasa:** RYZYKO.
- **Dowód:** `apps/store/src/catalog/Pagination.tsx:8` i `:22`

  ```tsx
  const pageCount = Math.ceil(total / perPage);
  ...
  {page < pageCount && (
    <PaginationItem>
      <PaginationNext href={hrefFor(page + 1)} />
  ```

  `docs/features/search/spec.md:203`: „Okno wyników to 10 000 (`index.max_result_window`): strona zaczynająca się za
  oknem jest pusta z prawdziwym `total`”.
- **Konsekwencja:** przy 10 mln produktów `/` pokazuje „Strona 1 z 416667”. Klient albo robot, który dojdzie do
  strony 418, dostaje „Nie znaleźliśmy produktów.” z licznikiem „10 000 000 produktów” w nagłówku i dalej aktywnym
  linkiem „Następna”. Dla robotów to pułapka kilkuset tysięcy pustych stron (mają `noindex`, ale `follow` i link
  dalej), każda z odczytem przez Varnish i Elasticsearch.
- **Rekomendacja:** API powinno zwracać liczbę osiągalnych stron (albo `hasNext`), a sklep pokazywać ją zamiast
  liczyć z `total`. To jedyna pochodna, którą front wylicza z danych listy, i akurat tu się myli.
- **Pewność:** wysoka (kod paginacji i zapis specyfikacji są jednoznaczne); sekcja ryzyk specyfikacji `search` nie
  wymienia skutku w UI.

### Z4. Cache ISR stron produktu rośnie na dysku bez limitu

- **Waga:** Medium. **Klasa:** RYZYKO (częściowo świadome).
- **Dowód:** `apps/store/app/p/[slug]/page.tsx:10`

  ```tsx
  export const revalidate = 3600;

  export async function generateStaticParams() {
    return [];
  }
  ```

  `next.config.ts` nie ma `cacheHandler` ani `cacheMaxMemorySize`. Szablon wdrożenia nie daje sklepowi limitu
  `ephemeral-storage` (`infra/k8s/charts/shop/values.yaml:194`: tylko `memory: 1Gi`).
  `docs/features/product-page/spec.md:446`: „Każdy nieznany slug zostawia wpis ISR 404 na dysku instancji […] nic
  ich nie usuwa (nieograniczony przyrost przy losowych adresach)”.
- **Ocena kompromisu:** specyfikacja opisuje przyrost wpisów 404 i jedną instancję (A6), ale nie opisuje, że to samo
  dotyczy prawidłowych produktów: każdy odwiedzony produkt (także przez robota idącego po mapie strony) zostawia na
  dysku HTML i ładunek RSC na zawsze. Przy 10 mln produktów to rząd setek GB, więc domyślny cache plikowy nie nadaje
  się na skalę docelową nawet przy jednej replice. Dziś, na publicznym demo, tani atak (kilkaset tysięcy żądań
  `/p/<losowy-slug>`) zapełnia dysk jedynego węzła, na którym stoją też baza i pozostałe usługi.
- **Rekomendacja:** proporcjonalnie teraz: limit `ephemeral-storage` dla poda sklepu (eksmisja jednego poda zamiast
  presji na dysk węzła) i niecache'owanie 404 nieznanego sluga. Przed skalowaniem: `cacheHandler` z limitem
  i eksmisją, co i tak jest wymagane przez A6.
- **Pewność:** wysoka co do braku limitu w konfiguracji; średnia co do rozmiaru wpisu (nie mierzyłem).

### Z5. Koszyk gubi fokus klawiatury po każdej zmianie ilości

- **Waga:** Medium (dostępność na ścieżce zakupu). **Klasa:** BŁĄD.
- **Dowód:** `apps/store/src/cart/CartPage.tsx:259-264`

  ```tsx
  <QuantityField
    key={`${line.quantity}:${resetKey}`}
    label={...}
    quantity={line.quantity}
    disabled={busy}
    onCommit={onChangeQuantity}
  />
  ```

  oraz `:271` (`<Button ... disabled={busy} onClick={onRemove}>`).
- **Ścieżka:** Enter w polu ilości → `commit()` → `setBusy(true)` wyłącza pole, w którym jest fokus → odpowiedź
  zmienia `line.quantity` → zmienia się `key` → React odmontowuje pole i montuje nowe. Fokus ląduje na `body`.
  Wariant z Tab: fokus przechodzi na „Usuń”, które w tej samej chwili dostaje `disabled`.
- **Konsekwencja:** osoba korzystająca z klawiatury albo czytnika ekranu po każdej zmianie ilości zaczyna nawigację od
  początku strony (nagłówek, menu, wyszukiwarka). Nowa suma nie jest ogłaszana (region `role="status"` służy tylko
  komunikatowi o usuniętej pozycji). Narusza WCAG 2.4.3 i 4.1.3. Kasa robi to dobrze (stan `attention`,
  `CheckoutPage.tsx:112`), więc to niespójność, nie decyzja.
- **Rekomendacja:** nie wyłączać kontrolki z fokusem (`aria-disabled` i blokada w handlerze), nie przemontowywać pola
  przy powodzeniu (klucz tylko z `resetKey`, wartość synchronizowana z `quantity`), sumę ogłaszać w `role="status"`.
- **Pewność:** wysoka dla ścieżki z Enterem (wynika wprost z klucza); średnia dla ścieżki z Tab (zachowanie
  wyłączonego elementu z fokusem różni się między przeglądarkami). `CartPage.test.tsx` nie ma ani jednej asercji
  fokusu (grep `focus|activeElement` bez trafień), E2E `cart-guest.spec.ts:51` naciska Enter, ale fokusu nie sprawdza.

### Z6. Pasek demo przesuwa całą stronę po hydratacji (CLS) w profilu produkcyjnym

- **Waga:** Low. **Klasa:** BŁĄD.
- **Dowód:** `apps/store/src/components/SiteSwitches.tsx:39`

  ```tsx
  export function DemoNotice() {
    const { demoNotice } = useSiteSwitches();
    if (!demoNotice) {
      return null;
    }
    return (
      <div role="note" className="bg-foreground px-4 py-2 text-center text-sm text-background">
  ```

  `app/layout.tsx:31` renderuje go nad nagłówkiem; `infra/k8s/environments/prod/values.yaml:21`: `demoNotice: true`.
- **Konsekwencja:** na każdej stronie wdrożenia, po odczycie `/api/site`, nad nagłówkiem pojawia się pasek (ok. 36 px
  na szerokim ekranie, 2–3 wiersze na telefonie) i spycha w dół całą treść razem ze zdjęciem hero (LCP). Pomiar
  Lighthouse tego nie widzi, bo compose nie ustawia przełącznika.
- **Rekomendacja:** zarezerwować miejsce (pasek renderowany zawsze, treść po odczycie) albo pozycjonować go poza
  przepływem. Przy okazji `/api/site` może mieć krótkie `max-age` zamiast `no-store` (`app/api/site/route.ts:33`):
  to stała konfiguracja wdrożenia, a dziś każda odsłona pyta o nią serwer Node.
- **Pewność:** wysoka (brak rezerwacji miejsca w kodzie, wartość profilu sprawdzona).

### Z7. Brak `loading.tsx` i strumieniowania: nawigacja po katalogu bez informacji zwrotnej

- **Waga:** Low. **Klasa:** RYZYKO.
- **Dowód:** w `apps/store/app` nie ma żadnego `loading.tsx` ani `global-error.tsx` (wynik `find`). Jedyne `Suspense`
  to `SiteHeader.tsx:46` i `SearchBox.tsx:172`, oba bez treści zastępczej. Strona kategorii czeka kolejno na trzy
  odczyty: drzewo, lista, oferty (`app/c/[...path]/page.tsx:33-41`, `load-catalog.ts:66-91`).
- **Konsekwencja:** kliknięcie działu w menu albo linku paginacji nie zmienia nic na ekranie do końca renderu na
  serwerze. Przy zimnym Varnishu albo wolnym API klient klika drugi raz. Filtry mają stan optymistyczny
  (`listing-context.tsx:24`), linki nie. Błąd rzucony w samym layoucie pokaże domyślną angielską stronę Next.js, a
  `app/error.tsx:19` mówi „Nie udało się wczytać katalogu” także dla błędu `/checkout` czy `/konto`.
- **Rekomendacja:** `loading.tsx` ze szkieletem siatki dla `/c` i `/szukaj`, `global-error.tsx` po polsku, neutralny
  tekst strony błędu.
- **Pewność:** wysoka co do braku plików; odczucie opóźnienia nie było mierzone.

### Z8. Klucz idempotencji i formularz kasy żyją tylko w pamięci komponentu

- **Waga:** Low. **Klasa:** RYZYKO (świadome w części).
- **Dowód:** `apps/store/src/checkout/CheckoutPage.tsx:117` (`useState(newIdempotencyKey)`) i `:143-147`

  ```tsx
  function change(name: FieldName, value: string) {
    setForm((current) => ({ ...current, [name]: value }));
    setFieldErrors((current) => ({ ...current, [name]: undefined }));
    setIdempotencyKey(newIdempotencyKey());
  }
  ```

- **Konsekwencja:** `apps/store/CLAUDE.md:563-565` opisuje znany skutek: niepewny wynik, potem zmiana pola, daje
  „Koszyk jest pusty” zamiast potwierdzenia. Nie opisuje częstszego wariantu: klient po zawieszonym żądaniu odświeża
  stronę. Zamówienie powstało i trzyma rezerwację do 35 minut, ciasteczko potwierdzenia nie zostało ustawione
  (odpowiedź przepadła), gość widzi pusty koszyk bez linku „Opłać zamówienie” i ma tylko e-mail. Z1 zwiększa szansę
  tego scenariusza.
- **Rekomendacja:** trzymać klucz i odcisk formularza w `sessionStorage` do rozstrzygnięcia próby.
- **Pewność:** wysoka co do mechaniki; częstość zależy od stabilności API.

### Z9. Całe drzewo kategorii trafia do każdej strony trzy razy

- **Waga:** Low dziś, Medium przy dużym katalogu. **Klasa:** RYZYKO.
- **Dowód:** `app/layout.tsx:23,32,34` przekazuje `categories` do `SiteHeader` i `SiteFooter`; `MegaMenu` jest Client
  Componentem (`src/catalog/MegaMenu.tsx:1`), dostaje całe drzewo jako props (`:64`) i renderuje je dwa razy (pasek
  szeroki `:141-189`, lista wąska `:207-216`), choć pokazuje tylko trzy poziomy (`LAST_LEVEL = 3`, `:16`). Opis
  endpointu w `apps/api/openapi.json:1174`: „The whole category tree”.
- **Konsekwencja:** HTML każdej strony zawiera drzewo dwa razy jako znaczniki i raz jako ładunek RSC (z poziomami,
  których menu nie pokazuje), a serwer parsuje je przy każdym żądaniu listingu. Przy 7 działach demo to nieistotne;
  przy kilku tysiącach kategorii to setki KB na odsłonę i dłuższa hydratacja nagłówka.
- **Rekomendacja:** przekazywać do `MegaMenu` drzewo przycięte do trzech poziomów i pól `id`, `name`, `path`; listę
  wąskiego ekranu budować z tych samych danych po otwarciu.
- **Pewność:** wysoka co do przepływu danych; skutek przy dużym drzewie oszacowany, nie zmierzony.

### Z10. Drobne niespójności kontraktu i duplikacja (jedno znalezisko zbiorcze)

- **Waga:** Low. **Klasa:** PREFERENCJA.
- **Dowody:**
  - `src/api/client.ts` łączy klienta serwerowego (`createApiClient`, `:14`) z funkcjami przeglądarki (`:35-384`) bez
    `server-only`; komentarz „Use it from the server only” (`:8`) nie jest egzekwowany.
  - Pięć kopii odczytu `API_CATALOG_URL` z tym samym wyjątkiem (`load-catalog.ts:21`, `load-product.ts:26`,
    `category-tree.ts:14`, `category-redirect.ts:13`, `seo/sitemap.ts:36`) i trzy odczytu `STORE_URL`
    (`app/c/[...path]/page.tsx:15`, `app/p/[slug]/page.tsx:16`, `seo/store-url.ts:6`).
  - Stałe domeny powtórzone we froncie: `MAX_QUANTITY = 99` (`CartPage.tsx:34`, wbrew „jedyne miejsce tych reguł”
    z `docs/features/cart/spec.md:88`), `CURRENCY = "PLN"` (`ListingFilters.tsx:15`), pusty `CartView` z `"PLN"`
    (`cart/bff.ts:27-33`).
  - BFF przekazuje 422 koszyka z `detail` (`cart/bff.ts:147`), a `callCart` go odrzuca jako `failed`
    (`api/client.ts:121-133`); `AddToCartButton.errorMessage` (`AddToCartButton.tsx:64-75`) nie obsługuje statusu
    `invalid` z typu `CartFailure`.
  - `CheckoutPage.tsx`: 737 linii, 13 `useState` w jednym komponencie.
  - `src/components/ui/checkbox.tsx` nie jest importowany nigdzie.
  - `apps/store/CLAUDE.md:283` nazywa `/checkout` stroną prerenderowaną statycznie, a `:530-531` dynamiczną.
- **Konsekwencja:** żadna z tych rzeczy nie daje dziś błędu widocznego dla klienta; utrudniają zmianę.
- **Pewność:** wysoka.

## Mocne strony

- **Front nie liczy pieniędzy.** Jedyne działania na kwotach to `gross / 100` do formatowania
  (`format-price.ts:13`, `product-json-ld.ts:50`) i dopisanie `00` do pełnych złotych filtra (`listing-query.ts:111`).
  Grep po `reduce`, mnożeniu przez ilość i sumowaniu nie znajduje nic więcej. Test to pilnuje:
  `CartPage.test.tsx:126` („totals come from the API even when they differ from the lines”).
- **Dane spersonalizowane nie mają drogi do współdzielonego cache.** Strona produktu (ISR) i listingi nie czytają
  ciasteczek; konto, licznik koszyka i przełączniki ładuje przeglądarka (`AccountMenu.tsx:26`, `CartBadge.tsx:29`).
  Odpowiedzi BFF mają `private, no-store` (`auth/responses.ts:6`), odczyty osobiste `cache: "no-store"`
  (`cart/api.ts:22`). Katalog idzie przez Varnish osobnym adresem, koszyk i kasa nigdy (`auth/deps.ts:101`).
- **Sesja i tokeny.** Tokeny OIDC tylko w Redisie, ciasteczko to podpisany identyfikator
  (`auth/cookies.ts:13-27`, porównanie w stałym czasie), PKCE, `state`, `nonce`, jednorazowa transakcja logowania
  (`session-store.ts:95`, `GETDEL`), nowa sesja przy każdym logowaniu (`app/auth/callback/route.ts:27-31`),
  odświeżanie pod blokadą z bezpiecznym zwolnieniem (`refresh.ts:41`), `returnTo` zawsze przez `sanitizeReturnTo`
  (`return-to.ts`). Zapisy wymagają `Origin` równego `STORE_URL` i JSON (`cart/bff.ts:41-48`). Żadnej zmiennej
  `NEXT_PUBLIC_*`, żadnego `localStorage`.
- **Kasa odporna na powtórzenia i wyścigi.** Klucz idempotencji na próbę, przyczyna 409 wyłącznie z `type`
  sprawdzanego kompilatorem względem schematu (`checkout/problems.ts:20-29`, `satisfies Record<OrderConflictType, …>`),
  do API trafiają tylko pola kontraktu (`checkout/api.ts:18`), `cartId` wyłącznie z ciasteczka
  (`checkout/bff.ts:186-193`), token dostępu zamówienia gościa nie wychodzi z ciasteczka `HttpOnly`
  (`app/checkout/confirmation/page.tsx:70-71`).
- **Unieważnianie cache spina się z API.** Tagi Next to te same nazwy co `xkey` Varnisha
  (`load-product.ts:59,72`), rewalidacja blokująca (`route.ts:57`), walidacja tagów słownikiem
  (`route.ts:14-18`). Po stronie API są handlery dla ceny, stanu, promocji i drzewa, więc promocja kończąca się o czasie
  nie zostaje w ISR (sprawdzone: `InvalidateStorefrontOnPromotionChange.php`).
- **Wygenerowany klient jest naprawdę jedynym źródłem typów.** Typy odpowiedzi API pochodzą z `components["schemas"]`;
  ręcznie pisane są tylko kontrakty własnego BFF (`CartSummaryBody`, `SiteSwitches`). ESLint blokuje `fetch` poza
  `client.ts` (`eslint.config.mjs:36-49`).
- **Progresywne ulepszanie i dostępność.** Filtry to formularz `GET` działający bez JavaScriptu
  (`ListingFilters.tsx:86-92,169-174`), aktywne filtry i miniatury galerii to zwykłe linki, kolejność w dokumencie
  równa wizualnej, komunikaty błędów z `role="alert"` i `aria-describedby`, fokus prowadzony po odrzuceniu w kasie.
- **Testy.** BFF testowany na prawdziwym kliencie API z podmienionym `fetch` (`cart/test-support.ts:76-99`), więc
  asercje dotyczą faktycznych żądań HTTP (metoda, ścieżka, nagłówki, treść). Testy stron liczą żądania na wejście
  (`app/page.requests.test.tsx`). 71 `vi.mock` na 114 plików, prawie wszystkie na granicy modułu sieciowego albo
  routera. Brak `skip`, `only`, `todo`.
- **Wydajność bazowa.** Font lokalny w dwóch podzbiorach (48 KB), zero skryptów zewnętrznych, Sentry w osobnym chunku
  ładowanym tylko z DSN (`observability/lazy.ts:16`), obrazy z `srcset` i wymiarami, jedno zdjęcie z priorytetem na
  stronę główną.

## Zbędna złożoność

- **Wyszukiwarka w nagłówku.** `SearchBox.tsx` (178 linii) z modułową zmienną `submittedTo` do odtwarzania fokusu po
  przemontowaniu, komponentem `CurrentPhrase` piszącym do pola przez `getElementById`, osobnym wariantem panelu dla
  1024–1279 px i CSS w `noscript`; do tego równoległy slot `app/@search` tylko po to, żeby pole miało frazę w HTML
  z serwera. To najdroższy w utrzymaniu fragment nagłówka, a rozwiązuje brak 14 rem miejsca w jednym zakresie
  szerokości.
- **Kolejka zapisów koszyka z Web Locks** (`api/client.ts:148-177`). Chroni przed dwoma pierwszymi dodaniami gościa
  z dwóch kart naraz. Scenariusz jest rzadki, a skutek (drugi koszyk) łagodny; ta sama gwarancja po stronie API
  (idempotentne utworzenie koszyka) byłaby prostsza i działałaby też bez HTTPS.
- **`/api/site`.** Przełączniki wdrożenia czytane w przeglądarce, żeby HTML był identyczny między wdrożeniami. Koszt:
  żądanie na każdą odsłonę, opóźniony link logowania, przesunięcie układu (Z6). Sklep i tak czyta `STORE_URL` przy
  żądaniu, więc ta czystość HTML ma małą wartość.
- **Dokumentacja jako kod.** `apps/store/CLAUDE.md` ma 848 linii i 87 KB i powtarza specyfikacje funkcji zdanie po
  zdaniu, łącznie z wymiarami w pikselach. Znalazłem w nim jedną sprzeczność (Z10) i jedno twierdzenie, które
  według kodu nie zachodzi (Z2). Przy tej objętości nikt nie zauważy rozjazdu.

## Braki

- Limit czasu wywołań API i stan ładowania stron (Z1, Z7).
- Test dymny klastra dla treści stron statycznych (Z2) i jakikolwiek test fokusu w koszyku (Z5).
- Test kontraktu `app/error.tsx` z Next.js: `app/error.test.tsx:41-48` sam podaje prop `retry`, a żaden test E2E nie
  otwiera strony błędu (grep „Nie udało się wczytać katalogu” w `e2e/tests` bez trafień).
- Budżet rozmiaru paczek klienckich: Lighthouse mierzy wynik, nikt nie pilnuje rozmiaru JS.
- Walidacja po stronie przeglądarki w kasie (świadomie: `noValidate`, sprawdza API) oraz `aria-required` na polach
  tekstowych (ma go tylko grupa płatności, `CheckoutPage.tsx:562`).
- Open Graph poza stroną produktu, `metadataBase`.
- Strony prawne i zgody (regulamin, polityka prywatności); stopka celowo ich nie linkuje.

## Czego nie zweryfikowano

- Zachowania Next.js 16.4 w działaniu: zbierania tagów przy prerenderze (podstawa Z2), nazwy propa strony błędu
  (`retry` w `app/error.tsx:12`; jeśli framework podaje inną nazwę, przycisk „Spróbuj ponownie” rzuca wyjątek),
  kodu HTTP strony błędu, tego, czy `notFound()` w ISR dziedziczy tagi tak, jak opisuje dokumentacja projektu.
- Rozmiaru paczek, czasu hydratacji, rzeczywistych LCP i CLS (brak buildu); wartość CLS w Z6 jest oszacowaniem.
- Zachowania fokusu w konkretnych przeglądarkach (Z5, ścieżka z Tab).
- Treści testów E2E i tego, co pokrywają; czytałem tylko wyniki grep.
- Filtra zdarzeń Sentry (`observability/filter.ts`) i maskowania danych: nieczytane.
- Kontrastu i tokenów (`tokens.css`), skryptów obrazów marketingowych.
- Zgodności `schema.d.ts` z aktualnym `openapi.json` (zakładam, że pilnuje jej `make api-client` i CI).
- Tego, czy Cloudflare albo ingress cache'uje HTML stron ISR (nagłówki `s-maxage` Next.js); poza kodem sklepu.
