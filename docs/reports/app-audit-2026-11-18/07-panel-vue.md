# 07 – Panel administracyjny (`apps/admin`) i endpointy `/api/v1/admin` od strony operacyjnej

## Zakres i metoda

Audyt statyczny migawki `ef204620`, wyłącznie odczyt. Ścieżki względem katalogu eksportu.

Co przeczytałem:

- `apps/admin/src` (bez `components/ui` i bez wygenerowanego `api/schema.d.ts`): ok. 130 plików, ok. 18,5 tys. linii.
  W całości ok. 30 plików: `api/client.ts`, `auth/*` (4), `stores/auth.ts`, `router/index.ts`, `config/index.ts`,
  `main.ts`, `App.vue`, `views/AuthCallbackView.vue`, `views/OrderDetailView.vue`, composables `useCursorPager`,
  `useListUrlState`, `useUnsavedChangesGuard`, `useIdleLogout`, `useCategoryTree`, `products/useProductForm.ts`,
  `products/useImageUpload.ts`, `products/useProductPriceStock.ts`, `products/taxClasses.ts`,
  `promotions/usePromotionForm.ts`, `promotions/time.ts`, `discount-codes/useDiscountCodeForm.ts`,
  `discount-codes/format.ts`, `orders/useOrderDetail.ts`, `orders/useOrderList.ts`, `orders/format.ts`,
  `prices/parseGross.ts`, `prices/priceChange.ts`, `prices/pricePage.ts`, `prices/format.ts`,
  `inventory/parseCount.ts`, `inventory/stockChange.ts`, `categories/useCategoryScreen.ts`, `categories/problem.ts`,
  `dashboard/useDashboard.ts`, wszystkie `permissions.ts`, `layout/menu.ts` (część), `layout/account.ts`.
  Częściowo (blok `<script>` albo fragment): `AppLayout.vue`, `ProductFormView.vue`, `PriceDialogs.vue`,
  `StockDialogs.vue`, `PromotionVariantPicker.vue`, `DataTable.vue`. Reszta przez `grep` (rzutowania, role, importy
  między modułami, potwierdzenia, użycie pól API).
- Konfiguracja: `package.json`, `vite.config.ts`, `vitest.config.ts`, `eslint.config.js`, `Dockerfile`,
  `docker/nginx.conf`, `docker/security-headers.conf`, `docker/40-admin-config.sh`, `apps/admin/CLAUDE.md` (pierwsze
  140 z 786 linii i wyszukiwanie w reszcie).
- Testy panelu: statystyki całości (100 plików, 1836 przypadków, 5423 `expect`), w całości albo w dużej części
  `useOrderDetail.test.ts`, `ProductFormView.test.ts` (początek), spisy przypadków `client.test.ts`, `auth.test.ts`,
  `userManager.test.ts`, `useUnsavedChangesGuard.test.ts`, `useImageUpload.test.ts`; lista `e2e/tests`.
- API: lista wszystkich 37 tras `/api/v1/admin`, kontrolery `ChangeOrderStatusController`, `DashboardController`,
  kontrolery kategorii, `ReviseCategoryRequest`, `ChangePriceRequest`, `CorrectStockController` (opis 409),
  `RevisePromotionHandler`, `Promotion.php` (reguły okresu), `GetDashboardHandler`, `ProbingSystemHealth`,
  `Payment.php` (`requireRefund`), `HandleProviderWebhookHandler` (fragment), `RefundReason`.
- Dokumenty: `docs/vision.md` (pracownicy, wymagania niefunkcjonalne, zakres MVP, poza zakresem), sekcje „Poza
  zakresem” specyfikacji `catalog-admin`, `order-management`, `admin-design`, `admin-screens`, `admin-features`,
  `dashboard-data-freshness`; `infra/keycloak/realms/backoffice.json` (sesje, klient `admin`).

Każde znalezisko poniżej ma ścieżkę wykonania w kodzie, który przeczytałem; tam, gdzie wniosek zależy od zachowania
biblioteki, której źródeł nie ma w eksporcie (`node_modules` jest w wolumenie Dockera), piszę to wprost.

## Ocena ogólna obszaru

**8 / 10.**

Kod panelu jest wyraźnie powyżej tego, co zwykle widać w panelach administracyjnych: zero `any` i zero
`eslint-disable` w `src`, cała rozmowa z API przez wygenerowany klient, kwoty parsowane na liczbach całkowitych bez
floatów, strefa czasowa jawna i poprawnie obsłużona przy zmianie czasu, wersja (optimistic locking) w każdej operacji
zapisu i po stronie API, i w panelu, klucz idempotencji przy przyjęciu magazynowym, ochrona przed spóźnionymi
odpowiedziami w każdym composable, stronicowanie kursorowe po stronie serwera na każdej liście, staranna dostępność
(fokus po akcji, `aria-*`, reflow) i 30 tys. linii testów, które sprawdzają zachowanie, a nie implementację. Nie
znalazłem błędu, który psułby dane.

Punkty odjęte są za trzy rzeczy. Po pierwsze brzegi sesji: 401 kończy się twardym przekierowaniem bez próby cichego
odnowienia, a wylogowanie po bezczynności kasuje niezapisany formularz bez ostrzeżenia. Po drugie luki operacyjne,
w większości świadomie odłożone, ale z realnym skutkiem: płatności do ręcznego zwrotu, których panel nie pokazuje,
niepełna i niespójna informacja „kto zmienił”, brak operacji masowych przy deklarowanym katalogu 10 mln produktów. Po
trzecie duplikacja między modułami (parsery `problem+json` w siedmiu kopiach, trzy formularze z tym samym szkieletem,
dwa identyczne composables historii).

## Znaleziska

### 1. Wygaśnięcie sesji w trakcie edycji kasuje niezapisane dane: 401 to twarde przekierowanie, bezczynność wylogowuje bez ostrzeżenia

- **Waga:** Medium. **Klasa:** RYZYKO.
- **Dowód:**
  - `apps/admin/src/api/client.ts:34-52` – jedyna reakcja na 401 to `signinRedirect` (pełna nawigacja strony); nie ma
    próby `signinSilent()` ani ponowienia żądania:
    ```ts
    } else if (response.status === 401 && !redirecting) {
      ...
      redirecting = true;
      markSigninAttempt();
      try {
        await userManager.signinRedirect({ state: { returnTo: `${location.pathname}${location.search}` } });
      } catch {
        redirecting = false;
    ```
  - `apps/admin/src/api/client.ts:27-31` – do żądania trafia token z `getUser()` bez sprawdzenia `user.expired`
    (strażnik routera w `router/index.ts:163-165` to sprawdza, klient API nie).
  - `apps/admin/src/auth/userManager.ts:8` – tokeny tylko w pamięci karty (`InMemoryWebStorage`), więc zalogowanie
    w drugiej karcie nie przywraca sesji w karcie z formularzem.
  - `apps/admin/src/composables/useUnsavedChangesGuard.ts:50-55` – ostrzeżenie `beforeunload` jest celowo wyłączone
    przy wylogowaniu: `if (toValue(dirty) && !auth.signingOut)`.
  - `apps/admin/src/layout/AppLayout.vue:16,76-80` – `IDLE_TIMEOUT_MS = 15 * 60 * 1000`, po czym `auth.logout()`.
  - `infra/keycloak/realms/backoffice.json:14-15` – `ssoSessionIdleTimeout: 900`, `ssoSessionMaxLifespan: 28800`.
  - `grep` w `apps/admin/src`: brak `signinSilent`, `signinPopup`, `events.` (nikt nie słucha `silentRenewError`).
- **Konsekwencja.** Trzy scenariusze, dwa pierwsze codzienne:
  1. Pracownik marketingu układa promocję (warianty dodaje się pojedynczo, patrz znalezisko 4) i odbiera telefon na
     15 minut. Panel wylogowuje go bez ostrzeżenia i bez pytania przeglądarki, formularz przepada.
  2. Po 8 godzinach od logowania kończy się sesja SSO. Pierwsze żądanie (np. „Zapisz” produktu ze 100 wariantami)
     dostaje 401 i panel przechodzi do Keycloaka. Przeglądarka pyta o opuszczenie strony (formularz jest „dirty”).
     „Opuść” oznacza utratę danych. „Zostań” nie pomaga: `redirecting` zostaje `true` (zeruje go tylko `catch`), więc
     kolejne 401 nie proponują już logowania, a ta karta nie ma jak odzyskać tokenu. Zostaje ręczne przepisanie danych.
  3. Jednorazowy błąd odnowienia tokenu (chwilowy brak sieci, uśpiony laptop wybudzony tuż przed kliknięciem) daje
     ten sam skutek, choć sesja SSO nadal żyje i ciche odnowienie by wystarczyło.
- **Ocena decyzji.** Krótka sesja i wylogowanie po bezczynności to wymaganie z `docs/vision.md` („Sesja: krótka,
  wygasa po bezczynności”), a „Ostrzeżenie przed wylogowaniem po bezczynności” jest jawnie poza zakresem
  (`docs/features/admin-design/spec.md:753`). Wymaganie jest sensowne; brak łagodzenia skutków już nie, bo formularze
  panelu są długie i nie mają wersji roboczej.
- **Rekomendacja.** (a) Przy 401 jedna próba `signinSilent()` i ponowienie żądania; przekierowanie dopiero po jej
  porażce. (b) Minutę przed wylogowaniem po bezczynności okno „Zostajesz zalogowany?”. (c) Gdy sesji nie da się
  odnowić, okno „Sesja wygasła” z logowaniem w wyskakującym oknie (`signinPopup`) albo zapis szkicu formularza do
  `sessionStorage` i odtworzenie po powrocie z Keycloaka (dane formularzy nie zawierają sekretów). (d) Zerować
  `redirecting`, gdy nawigacja nie doszła do skutku.
- **Pewność:** wysoka co do ścieżek w kodzie panelu i konfiguracji realmu; średnia co do szczegółów scenariusza 3
  i zachowania obietnicy `signinRedirect` po anulowaniu nawigacji (źródeł `oidc-client-ts` nie ma w eksporcie).
  Sprawdziłem: brak innej warstwy odnawiającej token (interceptor, listener zdarzeń), testy `client.test.ts` (opisują
  dokładnie to zachowanie), specyfikację.

### 2. Płatności oznaczone „do ręcznego zwrotu” nie są widoczne w panelu ani w API panelu

- **Waga:** Medium (High przed podłączeniem prawdziwego dostawcy płatności). **Klasa:** RYZYKO (luka świadomie
  odłożona).
- **Dowód:**
  - `apps/api/src/Payment/Domain/RefundReason.php` – trzy powody: `order_cancelled`, `paid_after_deadline`,
    `duplicate`.
  - `apps/api/src/Payment/Application/Command/HandleProviderWebhookHandler.php:79-81`:
    ```php
    $refund = $this->orders->markPaid(...)->refundReason();
    if (null !== $refund) {
        $payment->requireRefund($refund);
    ```
  - `apps/api/src/Payment/Domain/Payment.php:126-131` – „Marks a succeeded payment for a refund by hand”.
  - `apps/api/openapi-admin.json` – zero wystąpień `refundRequired`; żaden kontroler w `src/*/UI` nie czyta tego pola
    (`grep` po `src`: tylko encja, repozytorium i handler webhooka). Pulpit liczy wyłącznie zwroty zamówień
    (`DashboardQueuesResponse.php:41-42`: `pendingRefunds`, `overdueRefunds` z Ordering).
  - `docs/features/order-management/spec.md:31-33` – „zwroty płatności oznaczane przez Payment … w panelu i wspólny
    przegląd wszystkich zwrotów: #221” w sekcji „Poza zakresem”.
- **Konsekwencja.** Klient płaci po terminie (zamówienie już anulowane automatycznie) albo płaci dwa razy. System
  poprawnie zapisuje „do zwrotu”, ale żaden pracownik się o tym nie dowie: nie ma kolejki, licznika, filtra ani
  wpisu na karcie zamówienia. Pieniądze klienta zostają w sklepie do czasu reklamacji. Kolejka „Zwroty do wykonania”
  sugeruje operatorowi, że widzi wszystkie zwroty, a widzi tylko te z anulowania przez pracownika i ze zwrotu towaru.
- **Ocena decyzji.** Przy udawanych dostawcach płatności skutek jest zerowy, więc odłożenie jest obronne. To jednak
  jedyna z odłożonych funkcji panelu, której brak oznacza zatrzymane pieniądze klienta, więc powinna być warunkiem
  wejścia prawdziwego dostawcy, a nie pozycją „część 2”.
- **Rekomendacja.** Minimalnie: licznik na pulpicie i filtr listy zamówień „płatność do zwrotu” z powodem, plus
  oznaczenie wykonania (ten sam wzorzec co `refund-completion`).
- **Pewność:** wysoka. Sprawdziłem cały `src` API pod kątem odczytu pola, kontrakt panelu i specyfikacje.

### 3. „Kto to zmienił?” – rozliczalność zmian jest niepełna i niespójna między modułami

- **Waga:** Medium. **Klasa:** RYZYKO.
- **Dowód:**
  - Kategorie nie zapisują pracownika w ogóle. Komendy nie niosą aktora:
    `apps/api/src/Catalog/UI/Http/Category/MoveCategoryController.php:60`
    `new MoveCategory($id, $move->version, $move->parentId, $move->position)`,
    `DeleteCategoryController.php:60` `new DeleteCategory($id, (int) $version)`, tak samo `CreateCategory`
    i `ReviseCategory`. W `src/Catalog/Domain/Category` nie ma pola aktora (jedyne trafienie `grep -i staff` to
    komentarz o kolejności).
  - Produkt zapisuje tylko ostatniego edytującego (`EditProductHandler.php:102`
    `$this->products->save($product, $command->expectedVersion, $command->actorId, $now)`), ale API go nie zwraca:
    `ProductEditViewResponse` w `apps/admin/src/api/schema.d.ts` kończy się na `updatedAt`.
  - Promocja: API zwraca `updatedByName` (`schema.d.ts:2498`), panel go nie pokazuje (`grep updatedByName` w
    `apps/admin/src` poza schematem: 0 trafień).
  - Kod rabatowy: aktor trafia do komendy (`ReviseDiscountCodeController.php:83-84`), odpowiedź go nie zawiera.
  - Pełną historię z pracownikiem mają tylko ceny, stany i statusy zamówień (`PriceDialogs.vue:186`,
    `StockDialogs.vue:344`, `orders/format.ts:32-43`).
  - `docs/vision.md:97-98`: „Zmiany wykonane w panelu zapisują identyfikator pracownika (z tokena), żeby było
    wiadomo, kto zmienił cenę, stan czy status zamówienia.” Dziennik zmian jest poza zakresem
    (`admin-features/spec.md:502-503`).
- **Konsekwencja.** Konto `marketing` przenosi albo usuwa kategorię (zmiana adresów całego działu, przekierowania,
  reindeksacja wszystkich jej produktów) i nie zostaje żaden ślad, kto to zrobił. Ktoś tworzy promocję −90% albo kod
  100%: w panelu nie widać autora, choć dane są w bazie. Przy czterech rolach i kilku osobach z tą samą rolą
  wyjaśnienie incydentu wymaga dostępu do bazy, a dla kategorii jest niemożliwe.
- **Rekomendacja.** Tanie kroki, bez dziennika zmian: (a) aktor w komendach kategorii i kolumna „ostatnia zmiana:
  kto, kiedy”; (b) `updatedByName` w odpowiedziach produktu i kodu rabatowego; (c) wiersz „Ostatnia zmiana: <data>,
  <login>” w formularzach produktu, promocji i kodu (promocja ma już dane w odpowiedzi).
- **Pewność:** wysoka. Sprawdziłem komendy, kontrolery, schemat odpowiedzi i użycie pól w panelu; nie znalazłem
  ogólnego mechanizmu audytu (middleware szyny, listener, log z identyfikatorem pracownika).

### 4. Panel nie ma operacji masowych, a wybór wariantów promocji to klikanie produkt po produkcie

- **Waga:** Medium. **Klasa:** RYZYKO (ograniczenie operacyjne przy docelowej skali).
- **Dowód:**
  - `apps/admin/src/promotions/PromotionVariantPicker.vue:30-32,83-96` – strona 25 produktów, bez filtra kategorii
    (`category: null`), dwie akcje: jeden wariant albo wszystkie warianty jednego produktu:
    ```ts
    const PICKER_PAGE_SIZE = 25;
    const query = ref<PriceQuery>({ q: null, category: null, perPage: PICKER_PAGE_SIZE });
    ...
    function addVariant(row: PriceRow): void { emit("add", [toVariant(row)]); focusSearch(); }
    function addProduct(productId: string): void { emit("add", addableOf(productId).map(toVariant)); focusSearch(); }
    ```
  - `apps/api/src/Pricing/Domain/Promotion/Promotion.php:29` – `MAX_VARIANTS = 1000`.
  - Cena: jedna trasa zapisu, `PUT /api/v1/admin/variants/{variantId}/price` (jeden wariant na żądanie, jedno okno na
    wariant). Stan: `…/stock/receipts` i `…/stock/corrections`, też po jednym wariancie. W liście 37 tras panelu nie
    ma importu, eksportu ani żadnej operacji na wielu rekordach.
  - `docs/vision.md` („Skala docelowa”): katalog do 10 mln produktów. `catalog-admin/spec.md:30-34`: „import hurtowy,
    eksport, operacje masowe” poza zakresem.
- **Konsekwencja.** Wyprzedaż „namioty −20%” na 400 produktach to 400 wyszukań i kliknięć, po każdym fokus wraca do
  pola wyszukiwania; dział powyżej 1000 wariantów wymaga kilku promocji. Nowy cennik dostawcy dla 3000 wariantów to
  3000 okien „Zmień cenę”. Dostawa 200 pozycji to 200 okien „Przyjęcie”. Architektura API jest gotowa na 10 mln
  produktów, ale narzędzia sprzedawcy są gotowe na katalog rzędu setek pozycji, czyli na demo.
- **Ocena decyzji.** Dla demonstracji świadome odłożenie jest w porządku. Jako sklep „zbudowany jak system
  produkcyjny” to największa luka funkcjonalna panelu: bez niej nie da się utrzymać cen i stanów katalogu większego
  niż kilka tysięcy wariantów.
- **Rekomendacja.** Kolejność według kosztu i zysku: (1) w wyborze wariantów promocji filtr kategorii (API listy cen
  już przyjmuje `category`) i „Dodaj wszystkie z tej strony”; (2) import CSV cen i przyjęć (asynchroniczny, z
  raportem błędów, z tymi samymi regułami wersji i idempotencji); (3) eksport CSV list.
- **Pewność:** wysoka (kod panelu, lista tras, specyfikacje).

### 5. Zmiany widoczne od razu w sklepie nie mają zabezpieczenia przed pomyłką: cena o rząd wielkości, wycofanie publikacji jednym kliknięciem

- **Waga:** Medium. **Klasa:** RYZYKO.
- **Dowód:**
  - `apps/admin/src/prices/PriceDialogs.vue:93-110,255` – jedno pole, Enter zapisuje, brak kroku potwierdzenia
    i porównania z obecną ceną:
    ```ts
    const gross = parseGross(grossText.value);
    ...
    const result = await props.changePrice(item.variantId, gross.grosze, item.price?.version ?? null);
    ```
    ```html
    @keydown.enter.prevent="saveChange()"
    ```
  - `apps/api/src/Pricing/UI/Http/ChangePriceRequest.php` – jedyna reguła kwoty to zakres 0,01 zł – 999 999,99 zł.
  - `apps/admin/src/views/ProductFormView.vue:260-267` – „Wycofaj z publikacji” woła `run(unpublish, 'status')`
    bez okna potwierdzenia. `ConfirmDialog` jest używany tylko w `ProductVariants.vue`, `PromotionFormView.vue`
    i `CategoriesView.vue`.
- **Konsekwencja.** Pracownik wpisuje „549” zamiast „54,90” albo „5,49” zamiast „54,90”. Cena jest zapisana od razu
  i od razu obowiązuje w sklepie (wpis w historii cen liczy się też do ceny Omnibus). Cena 10 razy za niska to
  zamówienia do anulowania i spór z klientami; 10 razy za wysoka to produkt, który przestaje się sprzedawać, dopóki
  ktoś nie zauważy. Prawdopodobieństwo pojedynczej pomyłki jest małe, ale przy ręcznej zmianie cen wariant po
  wariancie (znalezisko 4) rośnie z liczbą zmian. Wycofanie publikacji jest odwracalne, ale produkt znika ze sklepu
  i z wyszukiwarki do czasu ponownej publikacji.
- **Rekomendacja.** W oknie ceny pokazać zmianę („54,90 zł → 5,49 zł, −90%”) i wymagać drugiego potwierdzenia, gdy
  zmiana przekracza próg (np. ponad 50% w dół albo ponad 100% w górę). Próg najlepiej jako ostrzeżenie zwracane
  przez API, żeby reguła nie trafiła do frontu. Dla wycofania publikacji `ConfirmDialog`, jak przy zakończeniu
  promocji.
- **Pewność:** wysoka co do braku zabezpieczenia; ocena prawdopodobieństwa jest szacunkiem.

### 6. Zdjęcie wysłane dla jednego produktu trafia do formularza nowego produktu po przejściu „Dodaj produkt” w trakcie uploadu

- **Waga:** Low. **Klasa:** BŁĄD.
- **Dowód:**
  - `apps/admin/src/products/useProductForm.ts:169-175` – uploader jest tworzony raz na instancję i pisze do
    bieżącego `form.value.images`:
    ```ts
    const images = computed({ get: () => form.value.images, set: (value) => { form.value.images = value; } });
    const upload = useImageUpload(images, () => form.value.name);
    ```
  - `useProductForm.ts:272-285` – `open(id)` przy zmianie trasy zeruje formularz (`startNew()`), ale nie dotyka
    kolejki ani trwających wysyłek.
  - `apps/admin/src/products/useImageUpload.ts:103` – po odpowiedzi API:
    `images.value = [...images.value, { key, alt: defaultAlt(), variants: preview.variants }];`
  - `apps/admin/src/router/index.ts:45-57` – trasy `product-new` i `product` używają tego samego komponentu
    `ProductFormView`, więc instancja zostaje (kod sam na tym polega: `watch(() => options.productId(), open)`
    i komentarz w `useUnsavedChangesGuard.ts:20-22`). `apps/admin/src/layout/menu.ts:76` – pozycja menu „Dodaj
    produkt” jest dostępna z każdej strony.
  - Strażnik niezapisanych zmian nie zatrzyma przejścia: dopóki upload trwa, `images` się nie zmieniło, więc
    `dirty` jest `false`.
- **Konsekwencja.** Pracownik na stronie produktu A wybiera plik i, zanim upload się skończy, klika „Dodaj produkt”.
  Formularz nowego produktu jest pusty, po chwili pojawia się w nim zdjęcie produktu A (z pustym tekstem
  alternatywnym, bo nazwa nowego produktu jest pusta), a produkt A go nie dostaje. Pomyłka jest widoczna na ekranie
  i wymaga nietypowej kolejności kliknięć, stąd niska waga.
- **Rekomendacja.** W `open()` porzucić kolejkę i unieważnić trwające wysyłki (licznik „pokolenia” w
  `useImageUpload`, tak jak `latest` w pozostałych composables), albo liczyć trwający upload jako niezapisaną zmianę.
- **Pewność:** średnio-wysoka. Ścieżka wynika wprost z kodu; ponowne użycie instancji komponentu między dwiema
  trasami z tym samym komponentem jest zachowaniem Vue Routera, którego nie mogłem uruchomić. Testy
  (`useImageUpload.test.ts`, `useProductForm.test.ts`) nie mają przypadku „zmiana produktu w trakcie uploadu”.

### 7. Obsługa błędów API omija wygenerowane typy; parser `problem+json` istnieje w siedmiu kopiach

- **Waga:** Low. **Klasa:** RYZYKO (rozjazd kontraktu niewidoczny dla kompilatora).
- **Dowód:**
  - Schemat ma typy błędów z wyliczeniem `type`: `Problem`, `ProductConflictProblem`, `CategoryConflictProblem`,
    `PricingConflictProblem`, `BackofficeOrderConflictProblem` (`apps/admin/src/api/schema.d.ts:643-659,942-981`).
  - Panel ich nie używa: ciało błędu jest wszędzie `unknown` i ręcznie rzutowane, a identyfikatory są literałami:
    `apps/admin/src/products/useProductForm.ts:127-141,357-380`
    ```ts
    const type = problemField(body, "type")?.replace(PROBLEM_PREFIX, "") ?? null;
    switch (type) {
      case "product-modified": ...
      case "slug-taken": ...
      case "variant-locked":
    ```
  - Te same funkcje (`problemField`, `violationsOf`, `problemDetail`, `invalidChange`, `blankToNull`) są powtórzone
    w `discount-codes/useDiscountCodeForm.ts:65-79`, `promotions/usePromotionForm.ts:78-97`,
    `prices/priceChange.ts:16-42`, `inventory/stockChange.ts:24-67`, `orders/useOrderDetail.ts:48-88`,
    `categories/problem.ts:26-63`. Prefiks `tag:piotrp321.github.io,2026:shop/problems/` jest zapisany w pięciu
    plikach.
- **Konsekwencja.** Zmiana nazwy typu problemu w API (np. `product-modified`) przejdzie `vue-tsc` i testy panelu
  (te wpisują te same literały ręcznie, np. `tests/ProductFormView.test.ts:21`), a pracownik zamiast „Produkt został
  w międzyczasie zmieniony – odśwież” zobaczy „Nie udało się wykonać operacji (HTTP 409)”. Zasada „fronty nie piszą
  ręcznie typów odpowiedzi API” jest spełniona dla odpowiedzi 2xx, ale nie dla błędów.
- **Rekomendacja.** Jeden moduł `src/api/problem.ts`: odczyt `violations`, `detail` i `type` typowany schematem
  (`type` jako unia z `*ConflictProblem["type"]`, więc `switch` z nieznaną wartością nie skompiluje się), używany
  przez wszystkie moduły. Usuwa ok. 150 linii powtórzeń.
- **Pewność:** wysoka.

### 8. Duplikacja i sprzężenia między modułami

- **Waga:** Low. **Klasa:** PREFERENCJA (koszt utrzymania, bez wykazanej szkody dziś).
- **Dowód:**
  - `prices/usePriceHistory.ts` i `inventory/useStockHistory.ts` (po 100 linii) różnią się 8 liniami: typem, adresem
    i czterema napisami (`diff` obu plików).
  - `useProductForm.ts` (598 linii), `usePromotionForm.ts` (624), `useDiscountCodeForm.ts` (446) powtarzają ten sam
    szkielet: `latest`/`disposed`, `loadX`, `open`, `refresh`, `discard`, `addFieldError`, `clearFeedback`,
    `validationFailure`, `failure` z tym samym `switch` po statusie, `perform`.
  - Macierz ról jest zapisana w 12 miejscach frontu, w tym dwa razy inline: `views/PricesView.vue:37`
    i `products/ProductPriceStock.vue:36` (`roles.includes("admin")`), `views/InventoryView.vue:36`
    i `ProductPriceStock.vue:37` (`admin || warehouse`), plus 7 plików `permissions.ts` i `dashboard/todo.ts`.
    Zamówienia robią to lepiej: przyciski pochodzą z `allowedTransitions` i `canCompleteRefund` z API
    (`orders/useOrderDetail.ts:112-119`).
  - Wspólne formatowanie żyje w module funkcji: `@/prices/format` (`formatDateTime`, `formatMoney`) importuje 19
    plików, w tym `orders`, `dashboard`, `inventory`, `discount-codes`. Są cykle modułów: `prices → products`
    i `products → prices`, `inventory → products` i `products → inventory` (typ `CategoryLookup` z
    `products/useProductList`).
- **Konsekwencja.** Poprawka w jednym formularzu (np. obsługa 503) wymaga trzech identycznych zmian; nowa rola albo
  zmiana uprawnień wymaga przejścia po 12 miejscach i zgrania z voterami API. Rozjazd nie tworzy dziury
  bezpieczeństwa (API sprawdza), tylko ukryty przycisk albo 403.
- **Rekomendacja.** `useVariantHistory(path, texts)` zamiast dwóch kopii; wspólny `useRecordForm` dla części
  ładowanie/konflikt/walidacja; `lib/format.ts` na formatowanie; uprawnienia z API (`/me` zwraca listę uprawnień
  obok ról) albo jeden plik `auth/permissions.ts`.
- **Pewność:** wysoka.

### 9. „Zakończ teraz” promocji zależy od zegara przeglądarki

- **Waga:** Low. **Klasa:** RYZYKO (warunek: zegar komputera pracownika spieszy się względem serwera).
- **Dowód:**
  - `apps/admin/src/promotions/usePromotionForm.ts:520-529` – panel wysyła `endsAt: new Date().toISOString()`.
  - `apps/api/src/Pricing/Domain/Promotion/Promotion.php:139` –
    `$end = PromotionPeriod::wholeSecond($endsAt) <= $now ? self::startOfNow($now) : $endsAt;`, czyli koniec „teraz”
    tylko wtedy, gdy przysłana chwila nie jest w przyszłości serwera.
  - `usePromotionForm.ts:473-508` – `watchEnd`: odczekanie, do 5 ponownych odczytów co sekundę, trzy stany (`ending`,
    `endNotice`, `endError`), żeby obsłużyć skutki tej konstrukcji.
- **Konsekwencja.** Gdy zegar przeglądarki spieszy się o 5 minut, „Zakończ promocję teraz” ustawia koniec za 5
  minut. Panel po ok. 6 sekundach pokazuje „Koniec promocji ustawiony na …”, więc pracownik jest poinformowany, ale
  błędna promocja sprzedaje dalej przez ten czas, a jedynym wyjściem jest ponowna edycja końca. Na komputerach
  z synchronizacją czasu różnice to ułamki sekund, stąd niska waga.
- **Rekomendacja.** Osobna komenda API „zakończ teraz” (bez daty od klienta) albo wysyłanie chwili z pewnością
  przeszłej (API i tak przycina ją do „teraz”). Upraszcza też `watchEnd`.
- **Pewność:** wysoka (obie strony przeczytane).

### 10. Konflikt wersji zachowuje się różnie w formularzach: promocja i kod rabatowy nadpisują wpisane dane

- **Waga:** Low. **Klasa:** PREFERENCJA.
- **Dowód:** `apps/admin/src/products/useProductForm.ts:361-364` – produkt przy `product-modified` zostawia wpisane
  wartości i ustawia `conflict` (pracownik sam klika „Odśwież”). `apps/admin/src/promotions/usePromotionForm.ts:433-435`
  i `225-235` oraz `discount-codes/useDiscountCodeForm.ts:411-413` – po 409 `reloadQuietly()` woła `apply(result.data)`,
  które zastępuje formularz stanem z serwera.
- **Konsekwencja.** Dwie osoby edytują tę samą zaplanowaną promocję. Druga po „Zapisz” dostaje komunikat „…pokazujemy
  jej aktualny stan” i traci swoje zmiany, łącznie z ręcznie wybraną listą wariantów, bez możliwości ich skopiowania.
  Dane w bazie są bezpieczne (wersja działa), tracona jest praca.
- **Rekomendacja.** Jeden wzorzec: zostawić wpisane wartości, pokazać konflikt i dać wybór „Odśwież (odrzuć moje
  zmiany)”.
- **Pewność:** wysoka; zachowanie jest opisane w komentarzach kodu jako zamierzone.

### 11. Zbędne żądania: pulpit uruchamia sondy usług co 30 s, `/me` czytane dwa razy, dane słownikowe bez pamięci

- **Waga:** Low. **Klasa:** RYZYKO (koszt rośnie z liczbą otwartych kart administratorów i przy awarii usługi).
- **Dowód:**
  - `apps/api/src/Backoffice/UI/Http/DashboardController.php` (`__invoke`) – `includeSystem` zależy tylko od roli:
    `new GetDashboard($this->authorization->isGranted(self::VIEW_SYSTEM_STATUS), …)`. Pulpit nie pokazuje stanu
    systemu (osobny ekran `/system`), ale dla administratora każde odświeżenie pulpitu
    (`apps/admin/src/dashboard/useDashboard.ts:27,69-76`, co 30 s) uruchamia sondy 8 usług i statystyki kolejek
    (`GetDashboardHandler.php:44-52`, `ProbingSystemHealth.php`: do 1 s czekania, gdy usługa nie odpowiada). Ekran
    „Stan systemu” w drugą stronę pobiera też kolejki pracy i sprzedaż, których nie pokazuje.
  - `apps/admin/src/router/index.ts:123-128` (i 5 pozostałych strażników `beforeEnter`) woła `await auth.load()`, a
    `apps/admin/src/layout/AppLayout.vue:116` woła `void auth.load()` bezwarunkowo: wejście bezpośrednio na
    `/orders`, `/customers`, `/system` albo trasę „nowy” to dwa żądania `GET /me`.
  - `apps/admin/src/composables/useCategoryTree.ts:28-41` i `products/taxClasses.ts:9-19` – całe drzewo kategorii
    i klasy podatkowe są pobierane przy każdym zamontowaniu listy produktów, cen, stanów i formularza produktu;
    jedyny store Pinia to `auth`.
- **Konsekwencja.** Przy kilku administratorach koszt jest pomijalny. Przy awarii jednej usługi każda otwarta karta
  pulpitu administratora zajmuje worker PHP na ok. sekundę dwa razy na minutę, akurat wtedy, gdy API jest pod presją.
- **Rekomendacja.** Parametr zakresu w zapytaniu pulpitu (`?sections=`) albo osobna trasa stanu systemu; w
  `AppLayout` ładować `/me` tylko, gdy `auth.staff === null`; drzewo kategorii w store z odświeżeniem po zmianie na
  ekranie „Kategorie”.
- **Pewność:** wysoka.

### 12. Brak podziału paczki: wszystkie widoki są importowane statycznie

- **Waga:** Low. **Klasa:** PREFERENCJA.
- **Dowód:** `apps/admin/src/router/index.ts:13-28` – 16 widoków przez `import X from "@/views/…"`; w `src` nie ma
  ani jednego `import()`. `vite.config.ts` nie ma `manualChunks`. W tej samej paczce są `@sentry/vue`,
  `oidc-client-ts` i `reka-ui`.
- **Konsekwencja.** Pierwsze wejście pobiera cały panel, także widoki, do których rola nie ma dostępu. Dla
  narzędzia wewnętrznego z plikami `immutable` w cache (`docker/nginx.conf`) to koszt jednorazowy na wersję. Uboczna
  zaleta: po wdrożeniu nie ma błędów ładowania brakujących fragmentów w otwartych kartach.
- **Rekomendacja.** Nie priorytet. Jeśli paczka urośnie, leniwe ładowanie tras formularzy i szczegółów plus obsługa
  błędu ładowania fragmentu po wdrożeniu.
- **Pewność:** wysoka co do faktu; rozmiaru paczki nie mierzyłem (bez builda).

### 13. Niespójności kontraktu API panelu, które front musi obchodzić

- **Waga:** Low. **Klasa:** PREFERENCJA.
- **Dowód:**
  - Kursor następnej strony nazywa się `nextCursor` w zamówieniach i klientach (`schema.d.ts:667,2126`), a `next`
    w pozostałych listach (`schema.d.ts:1732,1911,2022,…`); stąd osobne mapowanie w każdym composable listy
    (`orders/useOrderList.ts:188` kontra `prices/pricePage.ts:72`).
  - Konflikt wersji ma stabilny `type` w Catalog (`product-modified`, `category-modified`), `about:blank` w Pricing
    i Ordering (`usePromotionForm.ts:371-373`), a korekta stanu nie rozróżnia dwóch przyczyn 409 inaczej niż tekstem
    `detail`. Panel rozpoznaje je, czytając wiersz ponownie i porównując wersję
    (`apps/admin/src/inventory/stockChange.ts:122-130,154-158`, komentarz: „tells them apart only by `detail` (one in
    English), so the panel does not read the text”).
- **Konsekwencja.** Dodatkowe żądanie i heurystyka po stronie panelu; każdy nowy klient API (integracja, aplikacja
  magazynowa) musi ją powtórzyć.
- **Rekomendacja.** Jedna nazwa kursora i stabilny `type` dla każdego 409 (wersja, poniżej rezerwacji).
- **Pewność:** wysoka.

## Mocne strony

- **Kwoty bez floatów.** `apps/admin/src/prices/parseGross.ts:17-37`: wyrażenie regularne na tekście, `BigInt`,
  kontrola `MAX_SAFE_INTEGER`, przecinek i kropka, separator tysięcy tylko między trójkami cyfr. `formatMoney`
  dzieli wyłącznie do wyświetlenia. W panelu nie ma ani jednego miejsca, które liczy cenę, VAT, sumę czy rabat
  (netto i VAT: „Netto i VAT wyliczy API”, `PriceDialogs.vue:219`).
- **Czas.** `apps/admin/src/promotions/time.ts`: jawna strefa `Europe/Warsaw` niezależnie od przeglądarki, godzina
  nieistniejąca przy zmianie czasu jest błędem pola, godzina podwójna to pierwsze wystąpienie, a czas nieedytowany
  wraca do API bez utraty sekund (`usePromotionForm.ts:286-301`). API interpretuje dni filtra zamówień w tej samej
  strefie (`BackofficeOrdersController.php:48`).
- **Współbieżność.** Każdy zapis niesie wersję: produkt (`useProductForm.ts:425`), cena (`priceChange.ts:73-76`),
  korekta stanu (`stockChange.ts:136-141`), promocja, kod, kategorie (`ReviseCategoryRequest.php:32-38`, usuwanie
  z `version` w zapytaniu), zamówienie (`useOrderDetail.ts:222,235`). Przyjęcie magazynowe ma `Idempotency-Key`
  tworzony przy otwarciu okna (`StockDialogs.vue:94`) i odświeżenie po 409 (`stockChange.ts:113-115`).
- **Spóźnione odpowiedzi i podwójne wysłanie.** Wzorzec `latest`/`disposed` w każdym composable
  (`useCursorPager.ts:83,106-108`, `useOrderDetail.ts:122-128`), licznik sesji okna (`PriceDialogs.vue:53,108-114`),
  flagi `busy`/`saving` ustawiane synchronicznie przed żądaniem (`useProductForm.ts:383-388`).
- **Uwierzytelnianie.** Authorization Code z PKCE, tokeny tylko w pamięci, stan żądania w `sessionStorage`
  (`auth/userManager.ts`), role z `GET /me`, nie z tokenu (`stores/auth.ts:12`), ochrona przed pętlą przekierowań
  jednorazowym znacznikiem bez tokenu (`auth/signinAttempt.ts`), cel powrotu po logowaniu ograniczony do ścieżek
  panelu (`AuthCallbackView.vue:15-18`), bezczynność liczona wspólnie dla kart przez `BroadcastChannel`
  (`useIdleLogout.ts`). Realm: 2FA, `directAccessGrantsEnabled: false`, PKCE S256.
- **Listy.** Stronicowanie kursorowe, filtrowanie i sortowanie wyłącznie po stronie serwera, liczniki ograniczone
  (`BoundedCountResponse`), stan listy w adresie (`useListUrlState.ts`), trzy przypadki błędu rozróżnione tak, żeby
  nigdy nie pokazać wierszy innego zapytania (`useCursorPager.ts:34-46`).
- **Typowanie i reguły.** Zero `any`, zero `eslint-disable`, 3 × `as never`, 2 × `as unknown as` (upload pliku,
  z komentarzem). Reguła ESLint zakazuje `fetch` poza `src/api/client.ts` i ma własny test
  (`tests/lint/fetch-rule.test.ts`).
- **Autoryzacja operacji na zamówieniach z API.** Przyciski to `allowedTransitions` i `canCompleteRefund`
  (`useOrderDetail.ts:112-119`); kontroler sprawdza rolę dla konkretnego przejścia
  (`ChangeOrderStatusController.php:81-84`).
- **Dostępność.** Zarządzanie fokusem po każdej akcji, okna otwierają się na polu, `aria-invalid`
  i `aria-describedby`, regiony przewijane z nazwą i `tabindex`, link „Przejdź do treści”, menu konta niemodalne
  z uzasadnieniem.
- **Testy.** 100 plików, 1836 przypadków, bez `.skip`/`.only`, bez snapshotów. Dane testowe są typowane schematem
  API (`tests/useOrderDetail.test.ts:17-45`), więc zmiana kontraktu łamie kompilację testów. Widoki są montowane
  z prawdziwym routerem, Pinią i komponentami (tylko 2 pliki używają `stubs`), jedyną atrapą jest granica
  `@/api/client`. `client.test.ts` testuje middleware na prawdziwym `openapi-fetch` z podstawionym `fetch`.
  13 plików E2E obejmuje panel na pełnym stosie z Keycloakiem.
- **Wdrożenie.** Jeden obraz dla wszystkich środowisk (`/config.js` generowany przy starcie, bez cache),
  `index.html` z `no-cache`, zasoby `immutable`, CSP `script-src 'self'`, mapy źródeł usuwane z obrazu.

## Zbędna złożoność

- **Mechanizm `watchEnd` w `usePromotionForm.ts:465-508`** (timer, 5 powtórzeń, trzy stany) istnieje, bo koniec
  promocji wysyła zegar przeglądarki (znalezisko 9). Komenda „zakończ” po stronie API usunęłaby większość tego kodu.
- **Rozpoznawanie przyczyny 409 korekty stanu przez ponowny odczyt** (`stockChange.ts:122-158`,
  `useProductPriceStock.ts:139-147`) zastępuje jedno pole `type` w odpowiedzi API (znalezisko 13).
- **`StockDialogs.vue` (693 linie)** trzyma trzy niezależne okna (przyjęcie, korekta, historia) w jednym komponencie
  z ok. 30 refami; podział na trzy pliki nic nie kosztuje. `OrderDetailView.vue` (829) i `PromotionFormView.vue`
  (681) to głównie szablon, logika jest w composables, więc rozmiar nie jest tu problemem.
- **`apps/admin/CLAUDE.md` ma 786 linii i 98 KB** i opisuje zachowanie każdego komponentu na poziomie pojedynczych
  atrybutów. To drugi, ręcznie utrzymywany opis tego samego, co mówią kod i testy; każda zmiana widoku wymaga zmiany
  w trzech miejscach.
- Nie stwierdziłem nadmiernej abstrakcji: `useCursorPager`, `useListUrlState`, `useNameSearch` i `DataTable` mają po
  7–9 użyć i realnie usuwają powtórzenia. Problemem jest raczej brak abstrakcji w formularzach i obsłudze błędów
  (znaleziska 7 i 8).

## Braki

Funkcje, których operator sklepu nie wykona z panelu. Przy każdej zaznaczam, czy specyfikacja odkłada je świadomie.

| Brak | Stan w dokumentach | Ocena |
|---|---|---|
| Płatności do ręcznego zwrotu (po terminie, podwójne) | odłożone, #221 | patrz znalezisko 2; warunek prawdziwego dostawcy płatności |
| Dziennik zmian, autor zmiany produktu, kodu, kategorii | odłożone (ADMIN-TOOLS) | patrz znalezisko 3; tanie kroki pośrednie |
| Operacje masowe, import i eksport CSV | odłożone | patrz znalezisko 4; warunek katalogu większego niż demo |
| Zarządzanie kontami i rolami pracowników | odłożone; tylko konsola Keycloaka | akceptowalne przy kilku pracownikach; wymaga dostępu administratora do Keycloaka |
| Zwroty częściowe, edycja pozycji, adresu i kontaktu zamówienia | odłożone (`order-management/spec.md`) | w sklepie sportowym zwrot jednej z kilku pozycji jest typowy; dziś tylko zwrot całości, z kwotą równą brutto zamówienia |
| Anulowanie nieopłaconego zamówienia przez pracownika | odłożone (P2) | zamówienie czeka na automatyczny upływ terminu płatności; rezerwacja stanu trzyma towar do tego czasu |
| Notatki wewnętrzne do zamówienia i klienta | odłożone | obsługa klienta nie ma gdzie zapisać ustaleń poza notatką przy zmianie statusu |
| Numer przesyłki, metody i koszty dostawy | poza zakresem wizji | przejście „wysłane” ma tylko opcjonalną notatkę; zamówienie nie ma kosztu dostawy |
| Usuwanie produktu i kodu rabatowego | świadomie: wycofanie publikacji, wyłączenie kodu | rozsądne (historia zamówień), ale lista produktów nie ma archiwum, więc szkice testowe zostają na zawsze |
| Treści (strona główna, banery, strony informacyjne), ustawienia sklepu, klasy podatkowe | brak; klasy podatkowe tylko do odczytu | zmiana treści i stawek wymaga wdrożenia |
| Edycja danych klienta, RODO (usunięcie, anonimizacja) | odłożone, #119 | karta klienta jest tylko do odczytu |
| Długi opis, SKU/EAN, marka, zdjęcia per wariant, generator kombinacji wariantów | odłożone (`catalog-admin/spec.md`) | 100 wariantów dodaje się wiersz po wierszu; brak SKU utrudni każdą integrację magazynową |
| Raporty sprzedaży poza kaflami pulpitu | odłożone | — |

Brakujące scenariusze w testach panelu: 401 w trakcie zapisu formularza z niezapisanymi zmianami (middleware
i formularz nigdy nie są testowane razem, bo testy widoków podmieniają cały `getApiClient`); zakończenie uploadu po
zmianie produktu w tej samej instancji (znalezisko 6); odpowiedzi błędów są w testach pisane ręcznie
(`problem(status, body: Record<string, unknown>)`), więc nie są sprawdzane względem schematu.

## Czego nie zweryfikowano

- Niczego nie uruchamiałem: wyniku testów, lintu, `vue-tsc`, rozmiaru paczki, kontrastów ani zachowania
  w przeglądarce nie potwierdzam.
- Zachowania `oidc-client-ts` (czy ciche odnowienie ponawia po błędzie sieci, czy obietnica `signinRedirect` zostaje
  nierozstrzygnięta po anulowaniu nawigacji) i ponownego użycia instancji komponentu przez Vue Router między trasami
  `product` i `product-new` nie sprawdziłem w źródłach bibliotek; opieram się na kodzie panelu i na tym, na czym on
  sam polega.
- Voterów API nie czytałem jeden po drugim; zgodność macierzy ról panelu z voterami przyjąłem z `docs/vision.md`
  i opisów OpenAPI.
- Wydajności zapytań list panelu (indeksy, plany przy 10 mln produktów i 50 mln zamówień) nie oceniałem; to obszar
  warstwy danych.
- Nie przeczytałem w całości: widoków list (`ProductsView`, `PricesView`, `InventoryView`, `OrdersView`,
  `CustomersView`, `PromotionsView`, `DiscountCodesView`), `CustomerDetailView`, `CategoriesView` z komponentami
  drzewa, `ProductVariants.vue`, `ProductImages.vue`, composables list (`useProductList`, `useStockList`,
  `usePriceList`, `useCustomerList`), `layout/usePageFocus.ts`, `observability/*`, `components/ui/*` ani ok. 85 ze
  100 plików testów. Wnioski o nich pochodzą z wyszukiwania wzorców, nie z lektury.
- Filtra Sentry (dane osobowe w zdarzeniach, e-mail klienta w adresie `/orders?q=`) nie oceniałem; specyfikacja
  odnotowuje e-mail w adresie jako świadomie przyjęty (`admin-design/spec.md:307`).
