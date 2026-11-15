# CLAUDE.md – apps/admin

Vue 3 (Composition API), Vite, TypeScript, Pinia, Vue Router, Tailwind CSS 4, shadcn-vue (Reka UI), ikony Lucide.
Panel administracyjny dla pracowników. Zasady wspólne dla całego repo są w głównym `CLAUDE.md`,
opis domeny w `docs/vision.md`.

## Komendy

Uruchamiaj z katalogu głównego przez `make -C apps/admin <cel>`:
- `check` – ESLint i `vue-tsc --noEmit`
- `test` – wszystkie testy (Vitest)
- `shell` – powłoka w kontenerze `admin`

Klienta API generuje `make api-client` z katalogu głównego.

## Struktura

- `src/api/` – `client.ts` (jedyne miejsce rozmowy z API) i wygenerowany `schema.d.ts` (nie edytuj ręcznie).
- `src/config/` – `readUrl(klucz)`: jedyny czytnik konfiguracji (adresy API, Keycloaka i panelu), patrz „Konfiguracja”;
  `readSentryConfig()`: opcjonalne klucze Sentry, patrz „Sentry”.
- `src/observability/` – Sentry (patrz „Sentry”): `index.ts` (`initSentry`, `setSentryUser`), `options.ts` (opcje SDK i adresy
  propagacji trace'ów) i `filter.ts` (jedyny filtr zdarzeń).
- `src/auth/` – logowanie OIDC (`oidc-client-ts`): `config.ts` (adresy przez `readUrl`), `userManager.ts`
  (tokeny w pamięci).
- `src/stores/` – store'y Pinia (`auth` z pracownikiem z `GET /api/v1/admin/me`; ustawia i czyści użytkownika Sentry, patrz „Sentry”).
- `src/router/` – trasy i guard: każda trasa poza `/auth/callback` wymaga zalogowania. `/orders` i `/orders/:orderId` mają
  strażnika (`beforeEnter` na ścieżce `orders`, `canViewOrders` z `src/orders/permissions.ts`), a `/system` strażnika
  `canViewSystem` (`src/dashboard/permissions.ts`: tylko `admin`): rola bez dostępu trafia na
  pulpit z `history.state.denied`, który pokazuje „Nie masz dostępu do tej strony.” (`useAccessDenied`: odczyt i czyszczenie
  znacznika w tym samym wpisie historii, więc przeładowanie komunikatu nie powtarza); gdy `/me` się nie wczyta, trasa jest
  przepuszczana (dostęp sprawdza API).
- `src/layout/` – powłoka panelu (spec „Powłoka”). `AppLayout`: link „Przejdź do treści” (pierwszy w kolejności Tab,
  przenosi fokus na `<main tabindex="-1">`), sidebar 260 px od 1024 px (`hidden lg:flex`), poniżej tej szerokości szuflada
  (`Sheet` z lewej: pułapka fokusu, `Escape` i wybór pozycji zamykają, fokus wraca na „Menu” przez `close-auto-focus`,
  powiększenie okna ≥ 1024 px ją zamyka), `AppTopbar`, `<main>` z komunikatem `staff-error` (`role="alert"`; gdy `/me` zwróci pustą listę ról, czyli konto bez roli
  personelu, któremu API odpowiada tylko na `/me`, `<main>` na każdej trasie zamiast widoku pokazuje H1 „Brak dostępu do
  panelu” i `Alert` `no-staff-role` (`role="alert"`) „Twoje konto nie ma roli w panelu. Skontaktuj się z administratorem.”,
  bez menu w sidebarze, bez szuflady i przycisku „Menu”, a topbar z „Wyloguj” i stopka zostają; wolny fokus trafia na ten
  H1; do wczytania `/me` i przy jego błędzie panel wygląda jak dotąd), stopka
  („© rok Worf · Panel administracyjny” i „Sklep”), kontener toastów (`Toaster`, vue-sonner, region `aria-live="polite"` o nazwie
  dokładnie „Powiadomienia”, bez skrótu klawiszowego vue-sonner, który doklejałby się do nazwy; 5 s; `position="top-right"` z odsunięciem
  pod topbar, `TOAST_TOP` w `AppLayout`, więc toast nie zasłania paska zapisu ani topbaru; poniżej 640 px na szerokość ekranu
  z marginesem 16 px: klasy `max-sm:…!` `TOAST_NARROW`, bo vue-sonner robi to dopiero do 600 px) i `useIdleLogout` (15 min). `usePageFocus`: po zmianie ścieżki trasy (inny ekran albo rekord; sama zmiana `query` lub `hash`, nawigacja
  anulowana i pierwsze wczytanie niczego nie ruszają) wolny fokus (na `body`, np. po zniknięciu klikniętego linku, albo na `<main>`)
  trafia na H1 nowej strony; do czasu pojawienia się H1 (widok ładuje dane) na `<main>`. Fokus, który ma istniejący element
  (przeniesiony przez widok, np. formularz po zapisie przed `router.replace` na trasę utworzonego rekordu tą samą instancją,
  pole, w którym jest pracownik, topbar, menu), zostaje. H1 bez własnego `tabindex` (pulpit) dostaje od powłoki `tabindex="-1"`. Pozycja wybrana w szufladzie,
  która zmieniła stronę, zabiera fokus bez względu na to, gdzie jest (szuflada nie oddaje go wtedy „Menu”). `menu.ts` to jedyna definicja menu (sekcje Główne, Sklep, Marketing, System; grupy Katalog,
  Produkty, Zamówienia; widoczność według ról z `/me`, do czasu jego wczytania ukryte są tylko pozycje wymagające roli;
  ukrywa tylko link, dostęp egzekwuje API i strażnik trasy) i wybór bieżącej pozycji (`currentLeaf`: formularze i
  szczegóły należą do pozycji listy, `/orders?refund=pending` i `/orders?refund=overdue` do „Zwrotów do wykonania”, a dla roli bez tej pozycji do
  „Listy zamówień”, „Pulpit” tylko na `/`). `AppMenu` i `AppMenuItem` renderują ją: grupa to przycisk z `aria-expanded`
  i `aria-controls`, grupa bieżącej pozycji otwiera się przy wejściu i przy każdej zmianie trasy (zamyka ją tylko
  przycisk), tylko bieżąca pozycja ma `aria-current="page"` (zwykły `<a>`, nie `aria-current` z RouterLink). Region grupy ma `id`
z `useId()` instancji menu (sidebar i szuflada są razem w DOM). Wybór pozycji to zwykła nawigacja routera: listy
zapisują filtry do adresu, więc pozycja o celu równym bieżącej lokalizacji nic nie zmienia (duplikat) i tylko zamyka
szufladę; klik z modyfikatorem albo środkowym przyciskiem zostaje przeglądarce i nie zamyka szuflady. Pozycja
  „Stan systemu” (`/system`, sekcja System) jest tylko dla `admin`. `AppTopbar`: przycisk „Menu” (`lg:hidden`), wyszukiwarka produktów
  (`role="search"`; fraza po przycięciu 2–100 punktów kodowych idzie do `/products?q=`, znaki sterujące i separatory
  wierszy liczą się jak spacje, pusta nic nie robi, za krótka i za długa pokazują „Wpisz od 2 do 100 znaków.” w stałym
  regionie `role="status"` połączonym z polem przez `aria-describedby`, fokus zostaje w polu), link „Sklep” z
  `VITE_STORE_URL` w nowej karcie (`rel="noopener"`), konto (inicjały, login, polskie role z `account.ts`, menu z
  „Wyloguj”; bez wczytanego pracownika „Konto” i „Wyloguj” też są; nazwa przycisku pochodzi z jego treści („Konto: login
  role”, bez `aria-label`, bo ten musiałby powtarzać wszystko, co przycisk pokazuje: WCAG 2.5.3), a poniżej 640 px
  login i role są tylko wizualnie ukryte (`sr-only`); menu jest niemodalne (`:modal="false"`), bo modalne ukrywa resztę
  strony przez `aria-hidden` mimo fokusowalnych elementów: axe `aria-hidden-focus`). `useAccessDenied` czyta i czyści
  `history.state.denied` (komunikat na pulpicie po strażniku trasy).
- `src/views/` – widoki przypisane do tras (m.in. `ProductsView`, `ProductFormView`, `PromotionsView`, `PromotionFormView`, `DiscountCodesView`, `DiscountCodeFormView`).
- `src/dashboard/` – komponenty i composables pulpitu i ekranu „Stan systemu”; widoki tras to `DashboardView` (`/`, H1
  „Pulpit”, bez okruszków) i `SystemView` (`/system`, tylko `admin`, okruszki „Pulpit › Stan systemu”) w `src/views/`.
  `useDashboard` (`GET /dashboard`) ładuje dane przy wejściu i co 30 s
  oprócz ukrytej karty, po powrocie do karty odświeża od razu, gdy minęło co najmniej 30 s; nieudane odświeżenie zostawia
  dane i podaje ich czas, a tylko najnowsze żądanie zmienia stan (opis: `docs/features/dashboard-data-freshness/spec.md`).
  Drugi argument `alongside` jest wołany przy każdym odświeżeniu (pulpit odświeża nim zamówienia). `FreshnessBar`:
  „Dane z <czas polski przez `formatDateTime`>” (`muted-foreground`, `aria-live="polite"`), „Odśwież” i komunikat błędu
  (`role="alert"`); używają go oba ekrany. Pulpit: `KpiTile` (`title`, `icon`, `tone` z par `kpi-*`, `value`, opcjonalnie
  `footer` i `to`: z `to` tytuł jest linkiem rozciągniętym na kafel), `CatalogTiles` („Produkty” z linkiem do `/products`
  i stopką „Bez zdjęć”, „Kategorie” i „Bez zdjęć” bez linku, bo API nie ma dla nich ekranów), `TodoCard` („Do obsługi”:
  linki bez liczb, wiersze z `todo.ts` według ról z `/me`: każda rola ma co najmniej jeden, rola z kilku widzi każdy
  raz; do czasu wczytania `/me` karty nie ma) i `RecentOrders` (`useRecentOrders`: `GET /orders?perPage=5` tylko dla
  ról z `canViewOrders`, ładowane z odświeżeniem pulpitu i gdy `/me` pokaże dostęp; błąd, w tym 403 bez „Spróbuj
  ponownie”, zostaje w karcie z fokusem na jej nagłówku po ponowieniu, a nieudane odświeżenie zostawia pokazane
  zamówienia i podaje czas ich pobrania jak błąd pulpitu: `withStaleNote`; kolory statusów według tokenów spec:
  Wysłane `success`, Dostarczone i Opłacone `info`, Czeka na płatność `warning`, Anulowane `danger`, Zwrócone `neutral`). Pulpit nie pokazuje stanu systemu. „Stan systemu” (`SystemStatus`): usługi z polską etykietą (`labels.ts`:
  `serviceLabel`, nieznana nazwa zostaje jak z API) i stanem „Działa” / „Nie działa” z opóźnieniem, „Zdarzenia czekające
  na wysłanie”, tabela kolejek w regionie `role="region"` z nazwą i `tabindex="0"` (przewijana w poziomie) i porównanie
  indeksu wyszukiwania z katalogiem; `system: null` (rola bez dostępu, gdy `/me` się nie wczytał) to komunikat „Brak
  danych o stanie systemu”. Pliki bez bloku `<style>`.
- `src/composables/` – współdzielona logika reaktywna (`useIdleLogout`, `useCategoryTree` – drzewo kategorii dla filtrów
  list; `loaded` to koniec próby wczytania, także nieudanej, na który czeka lista z kategorią w adresie).
  `useNameSearch({ initial, apply })`: jedyne miejsce reguł wyszukiwania po nazwie listy produktów, listy cen, listy stanów i wyboru wariantów promocji (od 2 do 100 znaków w punktach kodowych po
  przycięciu, 300 ms przerwy w pisaniu, pusta fraza i same spacje to `null`, podpowiedź dla frazy za krótkiej i za długiej);
  zwraca `search`, `tooShort`, `hint`, `reset` (czyści pole i anuluje opóźnienie) i `setFromAddress` (wartość z adresu ustawia
  pole bez `apply`); opóźnienie jest anulowane też przy odmontowaniu. Reguły są testowane raz, w `tests/useNameSearch.test.ts`.
  `useUnsavedChangesGuard(dirty)`: strażnik niezapisanych zmian formularza (globalny `router.beforeEach`, nie
  `onBeforeRouteLeave`, bo po utworzeniu obiektu ta sama instancja przechodzi z trasy „nowy” na trasę utworzonego):
  przejście na inną trasę przy `dirty` czeka na odpowiedź, którą dają `open` (okno; zamknięcie, np. `Escape`, to
  zostanie), `stay()` i `leave()` (zamknięcie i potwierdzenie w jednym kliknięciu rozstrzyga potwierdzenie; nowsza
  nawigacja unieważnia poprzednie pytanie), a zamknięcie karty ostrzega `beforeunload` poza wylogowaniem (`signingOut`
  w store `auth`: blokada wylogowania po bezczynności zostawiłaby sesję bez limitu); po odmontowaniu nic nie pilnuje.
  `usePagerFocus({ hasPrevious, hasNext, previous, next })`: fokus pagera z dwoma przyciskami (`ListPager`, wybór wariantów
  promocji): zostaje na naciśniętym przycisku, a gdy kolejna strona go wyłącza, przechodzi na drugi, chyba że pracownik sam
  przeniósł fokus poza pager (fokus przechodzi tylko z `body` albo z wnętrza pagera i najwyżej raz na naciśnięcie, więc późniejsza odpowiedź, np. wyszukiwania, nie zabiera go z pola); `press(which)` w obsłudze kliknięcia, `onFocusOut` na elemencie z oboma przyciskami.
  `useCursorPager(fetchPage, query, { ready })`: wspólna implementacja stronicowania po kursorach (spec „Standard listy”; używa jej
  lista produktów, lista cen, lista stanów, lista kodów rabatowych, lista promocji, lista zamówień i wybór wariantów promocji).
  `fetchPage({ query, after })` zwraca `{ items, next, total? }` (`total` to `BoundedCountResponse` z pierwszej strony, na kolejnych
  null) albo `{ error, forbidden? }` (żądanie i komunikaty należą do
  composable listy), `query` to reaktywne zapytanie z `perPage`; każda nowa wartość zapytania (porównanie po JSON, więc
  równa nie wysyła żądania) zaczyna od pierwszej strony i porzuca stos kursorów, a `ready` wstrzymuje żądania. Stan:
  `items`, `loading`, `loaded`, `error`, `forbidden`, `hasPrevious`, `hasNext`, `pageNumber`, `range` (numery pierwszego
  i ostatniego wiersza strony dla „Pokazano X–Y”, z rozmiaru strony, na której wiersze zostały pobrane), `total` (licznik
  pierwszej strony, trzymany na kolejnych do zmiany zapytania; nowe zapytanie kasuje go od razu, także gdy jego odpowiedź jest
  błędem; powrót na pierwszą stronę bierze go z nowej odpowiedzi), `goNext`,
  `goPrevious`, `retry` (powtarza nieudane żądanie) i `refresh` (bieżąca strona po zapisie). Trzy przypadki błędu:
  nieudana zmiana zapytania (także pierwsze wczytanie) usuwa wiersze i kursory (`loaded` false, zostaje `error`), bo
  należą do poprzedniego zapytania (#251 F10); nieudana sąsiednia strona albo odświeżenie zostawia wyświetloną stronę.
  Tylko najnowsze żądanie zmienia stan. `useListUrlState(schema)`: wyszukiwanie, filtry, kolejność (`sort`) i rozmiar strony w adresie.
  Parametr schematu: `parse(tekst)` (null: wartość pominięta, adres poprawiony), `fallback` (wartość bez parametru, nie jest
  zapisywana do adresu), opcjonalnie `format` i `confirm(wartość)` (true, false albo undefined, gdy źródło jeszcze nie
  odpowiedziało: tak sprawdzana jest kategoria w drzewie; `ready` jest false do rozstrzygnięcia, a odrzucona wartość jest
  pomijana). Zwraca `query` (do `useCursorPager`), `address` (tekst każdego parametru z ostatniego odczytu adresu przy
  wejściu i przy zmianie z zewnątrz: widok pokazuje w polu za krótkie `q`), `ready`, `set(patch)` i `clear()` (wszystkie
  parametry do wartości domyślnych, rozmiar strony też). Zapis to `router.replace` (bez wpisu historii, parametry spoza
  schematu zostają); własny zapis rozpoznaje po sygnaturze adresu, więc nie jest zmianą z zewnątrz i nie wysyła drugiego
  żądania, a zmiana `route.query` z zewnątrz (menu, wyszukiwarka topbaru, Wstecz i Dalej) ustawia zapytanie jednym
  przypisaniem, czyli jednym żądaniem od pierwszej strony. Zmiany adresu po opuszczeniu trasy widoku są pomijane
  (nazwa trasy). Pusty i powtórzony parametr nie jest wartością.
- `src/prices/` – lista cen (`/prices`, widok `PricesView`). `pricePage.ts`: czyste funkcje `fetchPricePage({ query, after })`
  (`GET /prices`; `perPage` idzie do API zawsze, bo domyślne API to 25, a lista zaczyna od 10; błędy: 403 „Nie masz dostępu do
  cen.” bez ponowienia, 404 kategoria, 400, sieć) i `priceRows(items)` (wiersz na wariant, `first` oznacza pierwszy wariant
  produktu), typ `PriceRow`. `usePriceList({ nodes, loaded })` składa z nich listę jak `useProductList`: `useListUrlState` (`q`,
  `category`, `sort`, `perPage`: 10, 25, 50, domyślnie 10, a 10 nie trafia do adresu; `sort` jak na liście produktów,
  `PRODUCT_SORT_OPTIONS` z `src/composables/listSort.ts`, do API idzie tylko, gdy to nie `name`), `useCursorPager` stronicujący produkty (więc
  „Pokazano produkty X–Y” liczy produkty, a zmiana rozmiaru wraca na pierwszą stronę) i `useNameSearch`; `rows` rozbija strony
  na warianty. Zmiana ceny wariantu (`changePrice`): zapisana cena z API jest nakładką w `computed` nad `items` pagera
  (`savedPrices`, nie zapisuje do `items` i nie jest czyszczona na starcie żądania), obowiązuje, dopóki cena z listy nie ma
  wersji nowszej albo jest `null`, więc ani trwające odświeżenie, ani spóźniona odpowiedź listy nie przywraca starej ceny;
  409 odświeża bieżącą stronę (`refresh`), a 409 po `type` `price-locked-by-promotion`, czyli wariant w aktywnej promocji,
  zwraca `locked` z `detail` z API: widok pokazuje go przy polu ceny, bez komunikatu o zmianie w międzyczasie i bez
  odświeżania. `PricesView`: karta z `ListToolbar` (wyszukiwanie „Szukaj po nazwie produktu”, `CategoryCombobox`
  „Kategoria”, `Select` „Sortuj”, rozmiar strony), `DataTable` z wierszami grup (`groupKey`: wiersz `product-row` z `<th scope="rowgroup">` na
  całą szerokość: `name` pogrubiona i `tax-class`, czyli polska nazwa klasy z `fetchTaxClasses` (`src/products/taxClasses.ts`,
  wspólne z formularzem produktu; kod spoza listy albo błąd żądania daje sam kod, bez komunikatu), bez akcji), pod nim
  `price-row` z kolumnami Wariant („Wariant domyślny” dla pustej etykiety), Brutto (pogrubione), Netto i VAT (ukryte poniżej
  1024 px jako linie komórki Wariant), Stawka (z `Badge` nieaktualnej stawki) i Akcje (`RowActions` z przyciskami ikon „Zmień
  cenę” tylko dla roli `admin` i „Historia” dla wszystkich; nazwa „Zmień cenę: <produkt> / <wariant>”). Okna to `Dialog`:
  „Zmień cenę” (`change-dialog`, szerokość `min(28rem, 100vw - 2rem)`, fokus w `gross-input` także po otwarciu Enterem,
  „Zapisz cenę” w wariancie `interface`, błędy 422 i `locked` przy polu z fokusem w polu, 403, 404, sieć i `conflict` w
  `Alert` z fokusem; po zapisie okno się zamyka, toast „Cena zapisana: <produkt> / <wariant>” i fokus wraca na „Zmień
  cenę” wiersza, a odpowiedź na zapis z innego otwarcia okna jest pomijana) i „Historia ceny” (`history-dialog`, szerokość
  `min(56rem, 100vw - 2rem)`, `DataTable` bez błędu własnego: Data zmiany, Brutto i Źródło zostają poniżej 1024 px, reszta jest
  liniami komórki daty; błąd i „Pokaż więcej” poza tabelą, po zniknięciu przycisku fokus trafia na „Zamknij”). Okno zamyka się
  z fokusem na przycisku, który je otworzył (`RowActions` podaje go w `select`), a gdy zniknął, na H1. Fokus po zmianie strony,
  błędzie i 403 daje standard (`useCursorPager`, `ListPager`), bez obsługi w widoku. `format.ts` (kwoty z groszy i stawki z
  punktów bazowych przez `Intl`, bez liczenia czegokolwiek), `parseGross.ts` (tekst kwoty w złotych na grosze na cyfrach
  tekstu, bez `float`; zakres kwoty sprawdza API). `usePriceHistory` (okno „Historia ceny”, dostępne dla każdego pracownika):
  pierwsza strona historii wariantu wiersza i „Pokaż więcej” po kursorze z API, odpowiedź po zamknięciu okna albo otwarciu
  innego wariantu jest ignorowana. `format.ts` ma też `formatDateTime` (czas polski niezależnie od strefy przeglądarki)
  i `sourceLabel` (etykiety źródeł zmiany: Pracownik, Dane demo, Uzgodnienie podatkowe).
- `src/inventory/` – lista stanów magazynowych (`/inventory`, widok `InventoryView`): `fetchStockPage` i `stockRows`
  (`stockPage.ts`: żądanie `GET /stock` z `perPage` zawsze w żądaniu, domyślne API to 25, panel zaczyna od 10, i rozbicie
  produktów strony na wiersze wariantów), `useStockList(kategorie)` na `useListUrlState` (`q`, `category`, `level`, `sort` jak na liście cen, `perPage`: 10, 25
  albo 50 produktów, 10 nie trafia do adresu; `level` to `out_of_stock` albo `low_stock`, inna wartość jest pomijana), `useCursorPager` i `useNameSearch` (jak `usePriceList`; kategoria z adresu, której
  nie ma w drzewie, jest pomijana). Strona to produkty z API, więc „Pokazano produkty X–Y” liczy produkty, a `rows` ma wiersz na
  wariant w kolejności produktów i ich wariantów. Stan z odpowiedzi na przyjęcie i korektę jest nakładką `savedStocks` nad
  `items` pagera (nie zapisuje do nich i nie jest czyszczona): wiersz pokazuje go, dopóki wersja stanu z listy nie jest
  nowsza albo stan z listy jest `null`, więc ani trwające żądanie, ani spóźniona odpowiedź listy nie przywracają starego
  stanu. Zapisany stan zdejmuje `matchesLevel` wariantu (znacznik poziomu dotyczył stanu odczytanego przez API, a panel nie
  liczy poziomu sam). Filtr poziomu wyklucza wyszukiwanie, kategorię i sortowanie (API odmawia ich łącznie z `level`): `selectLevel` czyści `q`,
  `category` i `sort` w adresie i pole wyszukiwania, a `query` listy ma przy poziomie tylko `level` i `perPage` (adres z `level`
  i `q` albo `category` daje jedno żądanie z samym poziomem). Wiersz ma `matchesLevel` z API (wariant na poziomie filtra).
  403 listy: „Nie masz dostępu do stanów magazynowych.” bez ponowienia, 404: kategoria nie istnieje.
  `format.ts` (`levelLabel`: etykieta i wariant `Badge` poziomu z API: Dostępny `success`, Ostatnie sztuki `warning`,
  Niedostępny `danger`; `movementTypeLabel`, `movementReasonLabel`, `movementSourceLabel` i `formatDelta` dla historii stanów).
  `parseCount.ts` (pole liczby okien przyjęcia i korekty: tekst przycięty, także o spacje nierozdzielające, na liczbę
  całkowitą z ASCII cyfr, pusty tekst to komunikat pola, inny „Podaj liczbę całkowitą.”, ponad bezpieczny zakres „Liczba
  jest za duża.”; zakres wartości sprawdza API). `InventoryView`: karta z `ListToolbar` (wyszukiwanie „Szukaj po nazwie
  produktu”, `CategoryCombobox` „Kategoria”, `Select` „Poziom” (`level-filter`: Wszystkie, Bez stanu, Niski stan; wybór poziomu wyłącza
  i czyści wyszukiwanie (`ListToolbar` `searchDisabled`) i kategorię, a opis „Filtr poziomu nie łączy się z wyszukiwaniem ani
  kategorią.” (`level-hint`, `role="status"`) jest jedynym regionem live z tym tekstem, a pole wyszukiwania (`ListToolbar` `searchDescribedBy`) i kategoria wskazują go przez `aria-describedby`; „Sortuj” pokazuje
  „Wg produktu” z opcjami wyłączonymi z opisem „Filtr poziomu ma własną kolejność.”), `Select` „Sortuj”, rozmiar strony; wariant
  na poziomie filtra ma w komórce Poziom `data-matches-level="true"`, obrys i ukryty dla czytnika tekst „pasuje do filtra”),
  `DataTable` z wierszami grup (`product-row` z `<th
  scope="rowgroup">` i pogrubioną `name`, bez akcji), pod nim `stock-row` z kolumnami Wariant („Wariant domyślny” dla pustej
  etykiety), Fizycznie, Zarezerwowane (ukryte poniżej 1024 px jako linia komórki Wariant), Dostępne (pogrubione), Poziom
  (`Badge`) i Akcje (`RowActions`: przyciski ikon „Przyjęcie” i „Korekta” tylko dla ról `admin` i `warehouse` (role z `/me`;
  API sprawdza niezależnie), „Historia” dla każdego pracownika; nazwa „Przyjęcie: <produkt> / <wariant>”). Wariant bez rekordu
  (`stock: null`) pokazuje „—” w liczbach i w komórce poziomu, bez `Badge`; panel nie przypisuje mu poziomu.
  Okna to `Dialog`: „Przyjęcie” (`receive-dialog`) i „Korekta” (`correct-dialog`), szerokość `min(28rem, 100vw - 2rem)`,
  nazwa produktu z wariantem w opisie, pola `Input` z `inputmode="numeric"` („Przyjęta ilość (szt.)”, „Stan policzony
  (szt.)”), powód korekty jako `Select` „Powód” (Inwentaryzacja, Uszkodzenie, Zaginięcie, Inne), notatka (przy powodzie
  „Inne” wymagana), „Przyjmij” i „Zapisz korektę” w wariancie `interface`. Fokus trafia w pierwsze pole po otwarciu (także
  Enterem), a po zapisie na przycisk, który okno otworzył (`RowActions` podaje go w `select`; gdy zniknął, na H1);
  po błędzie fokus trafia w pole, które API wskazuje (422: `violations[].propertyPath`), a bez pola na komunikat (`Alert`).
  Po zapisie okno się zamyka i pojawia się toast „Przyjęto N szt.: <produkt> / <wariant>” albo „Stan skorygowany: <produkt> /
  <wariant>” (sam produkt dla wariantu domyślnego). `receiveStock` i `correctStock` w `useStockList`: klucz
  `Idempotency-Key` (UUID z `crypto.randomUUID()`) powstaje przy otwarciu okna przyjęcia i jest wysyłany ponownie
  przy ponowieniu zapisu; nowe okno to nowy klucz. Korekta wysyła `version` wiersza (null bez rekordu). 422 bez `violations`
  (np. ponad 1 000 000), 403, 404 i sieć pokazują komunikat w oknie. 409 przyjęcia (klucz użyty z inną treścią) pokazuje
  `detail` z API i odświeża bieżącą stronę (`refresh`), bo pierwsze przyjęcie z tym kluczem mogło się zapisać mimo
  zgubionej odpowiedzi. Oba 409 korekty mają `type: about:blank`, więc panel nie czyta `detail`: na 409 odświeża bieżącą
  stronę i jeśli wersja wiersza różni się od wysłanej, pokazuje „Stan został w międzyczasie zmieniony – odśwież”, w przeciwnym
  razie `detail` z API (poniżej zarezerwowanego); gdy wiersza nie da się odczytać (odświeżenie nieudane albo wiersza nie ma już na
  stronie), powód nie jest znany, więc też „odśwież”, nigdy `detail`. Odpowiedź na zapis z innego otwarcia okna jest pomijana.
  `useStockHistory` (okno „Historia stanu”, `history-dialog`, dostępne dla każdego pracownika, szerokość
  `min(56rem, 100vw - 2rem)`): pierwsza strona historii stanów wariantu wiersza i „Pokaż więcej” po kursorze z API, odpowiedź
  po zamknięciu okna albo otwarciu innego wariantu jest ignorowana, błąd kolejnej strony zostawia wczytane wpisy i pozwala
  ponowić tę stronę (po zniknięciu przycisku fokus trafia na „Zamknij”). `DataTable` okna ma 11 kolumn w przewijanym regionie
  „Historia stanu” (`role="region"`, `tabindex="0"`): Data, Rodzaj, Zmiana fizycznego i Stan fizyczny po zmianie zostają
  poniżej 1024 px, reszta (zarezerwowane, powód, notatka, referencja, źródło, pracownik) jest liniami komórki daty.
- `src/products/` – produkty (menu „Produkty”, trasy `/products`, `/products/new`, `/products/:id`). `useProductList`
  (widok `ProductsView`, `useProductList({ nodes, loaded })` z drzewem z `useCategoryTree`): na `useListUrlState`
  (`q`, `category`, `status`, `images`, `sort`, `perPage`; `images=none` to filtr „Zdjęcia”: `Select` Wszystkie, Bez zdjęć, inna wartość jest
  pomijana) i `useCursorPager`; własne zostają żądanie `GET /products` (`perPage`
  i `sort` idą tylko, gdy to nie 25 i nie `name`, domyślne API), komunikaty błędów (403: „Nie masz dostępu do produktów.” bez ponowienia, 404:
  kategoria nie istnieje) i połączenie wyszukiwania z adresem: reguły ma `useNameSearch` (od 2 do 100 znaków w punktach
  kodowych po 300 ms przerwy w pisaniu, przycięte; `apply` zapisuje `q` do adresu); do adresu i zapytania trafia dopiero
  zastosowana fraza, a za krótka albo za długa (też z adresu) zostaje w polu z podpowiedzią (`searchHint`) i nie filtruje. Kategoria z adresu, której nie ma w drzewie (także przy błędzie drzewa),
  jest pomijana, a przy kategorii w adresie lista czeka na drzewo przed pierwszym żądaniem. `clearFilters` czyści
  wyszukiwanie, filtry, kolejność i rozmiar strony (adres `/products`). `ProductsView`: karta z `ListToolbar` (wyszukiwanie,
  `CategoryCombobox` „Kategoria”, Select „Status”, `Select` „Sortuj”, rozmiar strony, „Dodaj produkt” dla `admin` i `marketing`), `DataTable`
  (kolumny Produkt, Kategoria, Warianty, Status, Ostatnia zmiana, Akcje; nagłówek „Produkt” albo „Ostatnia zmiana” ma `aria-sort` według `sort`; Kategoria ukryta poniżej 1024 px, Ostatnia zmiana
  poniżej 900 px; miniatura 42 px albo placeholder, slug pod nazwą, data i pod nią godzina z sekundami; w Akcjach
  „Edytuj” tylko dla `admin` i `marketing` (`canManageProducts`; nazwa produktu jest linkiem do formularza dla każdej
  roli, a dla pozostałych formularz jest tylko do odczytu) i dla opublikowanego „Podgląd w sklepie”
  `<VITE_STORE_URL>/p/<slug>` w nowej karcie dla każdej roli), `ListPager` i `ListEmpty`. Pusty stan ma własne
  `data-testid` (`empty-add-product`, `empty-clear-filters`), bo pasek ma te same przyciski. Strona po pierwszej, na
  którą API odpowie bez wierszy (lista zmieniła się od poprzedniej strony), zostawia pager z „Poprzednia strona”, a
  pusty stan mówi tylko „Na tej stronie nie ma już wyników.”. Po „Wyczyść filtry” fokus trafia na pole wyszukiwania.
  `useProductForm` (widok `ProductFormView`): ładuje produkt (`GET /products/{id}`) albo zaczyna pusty formularz
  z wariantem domyślnym (bez osi, jeden wiersz `{variantId: null, values: []}`) i klasy podatkowe
  (`GET /tax-classes` przez `fetchTaxClasses` z `taxClasses.ts`, wspólnego z listą cen). Panel niczego nie liczy: slug, `slugLocked`, status i wersja pochodzą z odpowiedzi API, a po
  każdym sukcesie formularz jest budowany z odpowiedzi. `dirty` porównuje formularz ze stanem z API. „Zapisz”:
  nowy produkt `POST` i `onCreated(id)` (widok przechodzi na `/products/:id` tą samą instancją, bez ponownego
  pobrania), istniejący `PUT` z `version`. „Opublikuj” i „Wycofaj z publikacji” wysyłają `version` i są wyłączone
  przy niezapisanych zmianach; wszystkie akcje są wyłączone w trakcie żądania i uploadu zdjęcia. Błędy: 422
  z `violations[].propertyPath` trafia do pól (`errorsAt(ścieżka)` zwraca też zagnieżdżone ścieżki, np. `images[1]`),
  ścieżka spoza formularza i 422 bez `violations` dają komunikat formularza; 409 po `type`: `product-modified`
  (komunikat i „Odśwież”, które odrzuca zmiany), `slug-taken` i `slug-locked` przy polu slug, `product-not-publishable`
  (inny tekst przy publikacji i przy zapisie), `variant-locked` i `variant-in-use` z `detail` w sekcji „Warianty”
  (`variantsErrors`); 403, 404, 503 i sieć to komunikat bez utraty danych formularza. Formularz widzi tylko do
  odczytu (bez akcji) pracownik bez roli `admin` ani `marketing` (`permissions.ts`, role z `/me`); `/products/new`
  bez tej roli przekierowuje na listę (`beforeEnter` w routerze, który w razie potrzeby sam pobiera `/me`).
  `discard()` przywraca formularz z ostatniej odpowiedzi API bez żądania (dla „Odrzuć” w pasku zapisu; nie działa w trakcie
  żądania). `ProductFormView` stoi na standardzie formularza (niżej, „Wygląd”): `PageHeader` (H1 „Nowy produkt”, nazwa
  produktu, „Produkt” do wczytania i „Nie udało się wczytać produktu” po błędzie wczytania istniejącego, #251 F9;
  okruszki „Katalog › Produkty › produkt”), dwie kolumny od 1280 px (boczna 320 px: „Status” tylko dla zapisanego
  produktu z etykietą, „Opublikuj” albo „Wycofaj z publikacji” i powodem „Najpierw zapisz zmiany.” przy niezapisanych
  zmianach, oraz „Organizacja” z dwoma `CategoryCombobox`; główna: „Dane produktu” z klasą podatkową jako Select
  „Klasa podatkowa”, `ProductImages`, `ProductVariants`; w źródle boczna jest przed główną, więc poniżej 1280 px stoi nad
  nią), `SaveBar` (widoczny, gdy `dirty`, i od początku dla nowego produktu) i `UnsavedChangesDialog` z
  `useUnsavedChangesGuard`. Odpowiedź żądania, które skończyło się po opuszczeniu formularza, jest ignorowana.
  Błąd 422 dla całej listy zdjęć (`images`) pokazuje sekcja „Zdjęcia”, dla jednego zdjęcia (`images[i]`) jego wiersz.
  Zapis, publikacja i wycofanie zwracają wynik (`ActionResult`), po którym `ProductFormView` robi `run`: po błędzie
  przewija i ustawia fokus na pierwszym błędzie w kolejności dokumentu (element z `data-form-error`: komunikat pola,
  formularza, sekcji, albo pole z `aria-invalid`, także w sekcjach „Zdjęcia” i „Warianty”; formularz jest długi, a wynik
  pojawia się daleko od przycisku), a po sukcesie pokazuje toast z `message` composable („Produkt zapisany.”, „Produkt
  opublikowany.”, „Produkt wycofany z publikacji.”) i przenosi fokus tam, gdzie był przycisk: po zapisie na H1 (pasek
  zapisu zniknął razem z nim), po publikacji i wycofaniu na nagłówek karty „Status”. Wyjątek (#250 F16): gdy fokus
  jest wtedy w polu formularza (pracownik przeszedł do niego w trakcie żądania), zostaje tam.
  `useImageUpload` (sekcja „Zdjęcia”, `ProductImages.vue`): pliki wysyłane po kolei jako `multipart` z osobnym
  statusem (oczekuje, wysyłanie, gotowe, błąd z `detail` 422 albo ogólnym komunikatem, ponowienie), klucz już
  obecny w formularzu nie jest dodawany drugi raz, z wybranych plików wysyłane są tylko te, które mieszczą się
  w limicie 10 zdjęć (pozostałe dostają błąd bez żądania). Nowe zdjęcie ma alt równy nazwie produktu.
  `thumbnail.ts` buduje `src` i `srcset` z podpisanych adresów API. `ProductImages.vue`: po usunięciu zdjęcia fokus
  przechodzi na przycisk usuwania zdjęcia, które zajęło jego miejsce, a bez następnego na „Dodaj zdjęcia”; po ukryciu
  wyniku wysyłania na następny wynik albo „Dodaj zdjęcia” (nigdy `body`, #250 F8); błąd wysyłania pliku ma
  `role="alert"` (#250 F14). Obie sekcje to `FormSection` na tokenach. `ProductVariants.vue` to edytor wariantów
  (karta „Warianty”): osie (0–2: dodanie, zmiana nazwy, przestawienie strzałkami razem z wartościami w wierszach,
  usunięcie) i tabela wierszy (pole wartości na oś, dodanie i usunięcie wiersza, 1–100 wierszy). Operacje na
  kształcie robi `useProductForm` (`addAxis`, `setAxis`, `removeAxis`, `moveAxis`, `addVariant`, `removeVariant`,
  `setValue`): dodanie osi dopisuje pustą wartość w każdym wierszu, usunięcie osi usuwa wartość na jej pozycji,
  usunięcie ostatniej osi zostawia pierwszy wiersz (z jego `variantId`, bez wartości) i usuwa pozostałe, a pierwsza
  oś dodana produktowi bez osi zachowuje wiersz domyślny z jego `variantId`. Nowy wiersz ma `variantId: null`,
  zachowane wiersze wysyłają swoje `variantId`. Produkt bez osi ma jeden wiersz „Wariant domyślny” bez pól i bez
  dodawania wierszy. Usunięcie wiersza istniejącego wariantu (i ostatniej osi, gdy usuwa istniejące warianty) pyta
  w `ConfirmDialog` z nazwą wariantu (wartości osi: „Usunąć wariant czarny / 42?”, czerwony „Usuń wariant czarny / 42”,
  opis „Cena i stan wariantu przestaną być widoczne w panelu.”; usunięcie ostatniej osi: „Usunąć oś Kolor?”), nowy wiersz
  znika bez pytania; po usunięciu wiersza albo osi (z oknem i bez) fokus przechodzi na nagłówek „Warianty” (z oknem przez
  `fallback-focus`), bo zestaw przycisków się zmienił (#251 F12; następny element dostaje fokus tylko po usunięciu zdjęcia). Po
  pierwszej publikacji (`axesLocked` z `firstPublishedAt` API) dodanie, usunięcie i przestawienie osi są wyłączone
  i pod tabelą jest wyjaśnienie, a nazwy osi, wartości i wiersze zostają edytowalne. Panel nie liczy klucza
  porównania ani unikalności kombinacji, robi to API. `variantsErrors` ma tylko komunikaty całej sekcji (409
  `variant-locked` i `variant-in-use` z `detail`, 422 dla `variants` i `variantAxes`); 422 `variantAxes[i]` jest
  przy osi, `variants[i].values[j]` przy polu, `variants[i].values` i `variants[i].variantId` przy wierszu
  (`errorsExactly` zwraca ścieżkę bez zagnieżdżonych). Zmiana kształtu (dodanie, przestawienie, usunięcie) kasuje te
  komunikaty, bo ich ścieżki liczą pozycje. Bez roli do zmiany produktów edytor nie ma kontrolek edycji, a pola są
  wyłączone.
- `src/orders/` – zamówienia (widoki `OrdersView` pod `/orders` i `OrderDetailView` pod `/orders/:orderId`, dwie trasy
  jednej ścieżki `orders`, więc pozycja menu zostaje aktywna na szczegółach). `useOrderList` (standard
  listy): na `useListUrlState` (`q`, `status`, `refund` (`pending`: zwroty do wykonania, `overdue`: tylko po terminie; inna wartość jest pomijana), `placedFrom`, `placedTo` (dni `YYYY-MM-DD`), `sort`: `-placedAt` (domyślny), `placedAt`, `-total`, `total`, niewysyłany, gdy domyślny, `perPage`: 10, 25 albo 50, domyślnie 25 i niewysyłane;
  odczyt przy wejściu i przy zmianie z zewnątrz, np. pozycja menu „Zwroty do wykonania” na otwartej liście albo link „Inne
  zamówienia klienta” z `?q=<e-mail>`, zapis przez `replace` bez nowego wpisu historii, nieznana wartość jest pomijana,
  a adres poprawiany; do adresu trafia tylko zastosowane wyszukiwanie) i `useCursorPager` (stos kursorów, spóźnione
  odpowiedzi, ponowienie, skutek błędu: nieudana zmiana filtra usuwa wiersze i kursory, nieudana sąsiednia strona zostawia
  wyświetloną stronę; fokus po zmianie strony, błędzie i 403 daje standard, #260 F7). Własne zostają żądanie `GET /orders`
  z komunikatami (403: „Nie masz dostępu do zamówień.” bez ponowienia, 400, inne, sieć) i reguła wyszukiwania numeru, e-maila
  albo fragmentu: tylko po Enter (`submitSearch`; zdarzenie `submit` paska `ListToolbar`), tekst przycięty
  (`trim()` zna też spację nierozdzielającą i U+2028) ma 3–254 punkty kodowe (tekst o 1–2 znakach nie zmienia niczego,
  a `searchHint` mówi, że trzeba 3), ten sam tekst ładuje listę ponownie. Zakres dni: `placedFromField` i `placedToField`
  to to, co wybrano, `setPlacedFrom` i `setPlacedTo` stosują zakres od razu przez `url.set`; pusty dzień go czyści, a wartość,
  która nie jest dniem 2000–2999 (Chrome przepuszcza `0002`, `0020`, `0202` przy wpisywaniu roku), nie zmienia zastosowanego
  dnia ani nie wywołuje żądania; koniec przed początkiem nie jest stosowany (`rangeError`, liczony z dni, które zostałyby wysłane, także gdy jedno pole ma niepełny rok); `dayBounds` daje `min` i `max` pól: lata 2000–2999 i drugi koniec zakresu; przy zwrotach do wykonania
  dni nie są wysyłane (`rangeDisabled`, `rangeDisabledReason`).
  `status`, `refundPending` i `filtered` wynikają z zapytania (kolejność nie jest filtrem), „Wyczyść filtry” (`clearFilters`)
  wraca na `/orders`. Kolejność (`sort`, `sortOptions`, `setSort`): `sort` to kolejność w użyciu, `null` przy zwrotach do
  wykonania (mają własną, wg terminu zwrotu, i nie dostają `sort` w żądaniu), a kwota przy zastosowanym wyszukiwaniu albo zakresie dat
  wraca do „Najnowsze” (API odrzuca to połączenie); `sortOptions` wyłącza te opcje z powodem (`disabledReason`), a zatwierdzenie
  wyszukiwania albo wybór dnia przy sortowaniu po kwocie ustawia „Najnowsze”.
  `useOrderDetail(orderId)`: szczegóły z `GET /api/v1/admin/orders/{id}`;
  przyciski to wyłącznie `allowedTransitions` (`actions`) i `canCompleteRefund` z API, panel nie liczy statusów ani ról.
  `changeStatus` i `completeRefund` wysyłają `version` pokazanego widoku, a zamówienie z odpowiedzi zastępuje widok.
  Notatka jest wymagana przy `cancel` i `return` (okno sprawdza przed wysłaniem, API sprawdza jeszcze raz), pusta
  notatka w pozostałych to `null`. 409 (wersja, przejście, zwrot) przeładowuje szczegóły i zamyka okno
  z komunikatem „Zamówienie zostało w międzyczasie zmienione – sprawdź jego stan.” (`conflict`, `tabindex="-1"`, fokus na nim);
  422 wskazuje pole notatki (`violations[].propertyPath`), a 403 i pozostałe błędy to komunikat w oknie z fokusem na nim
  (#260 F8). Jednocześnie leci najwyżej jedna zmiana, a odpowiedź na zmianę po przejściu widoku do innego zamówienia jest
  pomijana (`ignored`, także przeładowanie po 409). 404 szczegółów: H1 „Nie znaleziono zamówienia”, „Nie znaleźliśmy zamówienia.” z linkiem
  do listy (`Alert` w tokenach `status-danger`); inny błąd odczytu: komunikat z „Spróbuj ponownie” (403 bez ponowienia;
  po udanym ponowieniu fokus na H1, po nieudanym zostaje na przycisku). Błąd przeładowania po 409 zostawia pokazane
  zamówienie. `format.ts`: etykiety i warianty `Badge` statusów (`statusLabel`: `label` i `variant` jak na pulpicie,
  nieznany status to wartość z API w neutralnym `Badge`; `STATUS_OPTIONS`), aktorów historii (`actorLabel`: nazwa
  pracownika, „Pracownik” bez loginu, Klient, System), przejść (`transitionLabel`), powodu anulowania i `noteRequired`; kwoty
  i czas przez `formatMoney` i `formatDateTime` z `src/prices/format.ts`.
  `OrdersView`: `PageHeader` (H1 „Zamówienia” albo „Zwroty do wykonania” przy `refund=pending`, okruszki „Pulpit ›
  Zamówienia” albo „Pulpit › Zamówienia › Zwroty do wykonania” z linkiem do listy), karta z `ListToolbar` (wyszukiwanie
  „Szukaj po numerze zamówienia lub e-mailu” z podpowiedzią „Numer, e-mail albo fragment (od 3 znaków) numeru, e-maila lub nazwiska. Enter wyszukuje.” (tekst o 1–2 znakach nie wyszukuje i zmienia podpowiedź na „Wpisz co najmniej 3 znaki, aby wyszukać.”), grupa „Data zamówienia” z polami „Od” i „Do” (`input type="date"`, `placedFrom` i `placedTo` w adresie; zastosowane od razu po wybraniu dnia; koniec przed początkiem nie jest stosowany, komunikat w `role="alert"` i `aria-invalid` na „Do”; przy zwrotach do wykonania pola są wyłączone z opisem, bo API odmawia tego połączenia; kwota przy zakresie dat jest wyłączona w „Sortuj”), `Select` „Status”, `Switch` „Zwroty do wykonania” z `id` i `data-testid` `refund-pending` i `label for`; gdy jest włączony, obok niego `Switch` „Tylko po terminie” (`refund-overdue-only`, `refund=overdue`: wyłączenie wraca do `refund=pending`, wyłączenie zwrotów do wykonania zdejmuje oba), `Select` „Sortuj” (nad tabelą i nad kartami; przy zwrotach do
  wykonania z napisem „Termin zwrotu”), rozmiar strony), `DataTable` (nagłówki „Data” i „Kwota” mają `aria-sort` według
  kolejności, a przy zwrotach do wykonania „Termin zwrotu” `ascending`), `ListPager` i `ListEmpty` („Nie ma
  jeszcze zamówień.”, „Brak wyników dla wybranych filtrów.”). Widok renderuje jeden układ według
  `useMediaQuery("(min-width: 640px)")` (`@vueuse/core`, jak `AppLayout`): od 640 px tabela (Numer: link bez łamania, #261, z datą
  i liczbą pozycji jako liniami komórki poniżej 1024 px; Data, Klient: imię i nazwisko, pod nim e-mail obcięty z pełną wartością
  w `title`; Pozycje; Kwota; Status jako `Badge`; przy zwrotach Kwota zwrotu i Termin zwrotu z „Po terminie”), poniżej
  640 px lista kart (`DataTable` z `cards`: `<ul>` z nazwą „Zamówienia”, `<li>` z numerem, statusem, klientem, kwotą, liczbą pozycji („Pozycje: N”, `items`), datą i w widoku
  „Zwroty do wykonania” kwotą i terminem zwrotu, jak kolumny tabeli); `order-row` i identyfikatory `number`, `status`, `total`… są na wierszu albo na karcie, nigdy w obu.
  `OrderDetailView`: `PageHeader` (H1 „Zamówienie <numer>” z `Badge` statusu w slocie `status`, okruszki „Pulpit › Zamówienia › <numer>”
  zamiast „Wróć do listy”), od 1280 px dwie kolumny (boczna 320 px w DOM przed główną, poniżej 1280 px nad nią, Tab zaczyna od niej:
  wyjątek od kolejności zgodnej z układem jak w formularzach). Boczna: „Akcje” (przyciski `allowedTransitions` i „Oznacz zwrot jako
  wykonany”; karta ukryta bez akcji), „Klient” (e-mail jako `mailto:` z zawijaniem, link „Inne zamówienia klienta” do
  `/orders?q=<e-mail>`), „Adres dostawy”, „Płatność” (daty statusów, termin płatności tylko dla `new`, powód anulowania,
  `paymentId` krojem o stałej szerokości z przyciskiem „Kopiuj identyfikator płatności”: `navigator.clipboard.writeText`,
  `copy-status` w `role="status"` ogłasza „Skopiowano”, a przy odmowie albo braku schowka „Nie udało się skopiować –
  zaznacz identyfikator ręcznie” i zaznacza tekst identyfikatora). Główna: „Pozycje” (tabela w regionie z nazwą:
  Produkt z wariantem i kopią promocji, Ilość, Cena brutto, a przy `discount` Wartość przed rabatem i Rabat, na końcu
  Wartość brutto albo po rabacie; poniżej 640 px widoczne Produkt, Ilość i ostatnia Wartość, a pozostałe są liniami komórki
  produktu `line-meta-*`; pod tabelą linia kodu i sumy od API), „Historia” (`OrderTimeline`) i „Zwrot pieniędzy” (kwota, termin
  z „Po terminie”, wykonanie: data, pracownik, notatka, płatność do zwrotu). `OrderTimeline`: `<ol>` wpisów `history` w kolejności z API
  (od najstarszego, nic nie jest sortowane ani dokładane), wpis `history-row` ma czas w `<time datetime>` (`history-at`),
  zmianę „Opłacone → Wysłane” jako dwa `Badge` (`history-change`; złożenie, `from: null`, to sam status docelowy),
  aktora (`history-actor`) i notatkę (`history-note`). Okno zmiany statusu i zwrotu stoi na `Dialog` (`change-dialog`,
  szerokość `min(28rem, 100vw - 2rem)`): tytuł ze skutkiem i numerem („Anulować zamówienie R-100002?”, „Oznaczyć zamówienie
  R-100002 jako wysłane?”, „…jako dostarczone?”, „Przyjąć zwrot zamówienia R-100002?”, „Oznaczyć zwrot pieniędzy za R-100002 jako
  wykonany?”), pole „Notatka” z fokusem po otwarciu (wymagana przy anulowaniu i zwrocie), przycisk skutku (`destructive` przy
  anulowaniu i zwrocie, inaczej `interface`) i „Wróć”; Enter w polu zatwierdza. Po udanej akcji toast „Status zamówienia
  zmieniony: <polska etykieta statusu z odpowiedzi API>.” (nieznany status: wartość z API, nigdy pusty) albo „Zwrot oznaczony jako
  wykonany.”, a fokus trafia na nagłówek karty „Akcje” (zestaw przycisków się zmienił), gdy karta zniknęła na „Historia”;
  „Wróć” i `Escape` oddają fokus przyciskowi, który otworzył okno. Zdarzenie `close-auto-focus` okna jest przejęte, żeby fokus nie
  spadł na `body`.
- `src/promotions/` – promocje (menu „Promocje”, trasy `/promotions`, `/promotions/new`, `/promotions/:id`: jedna ścieżka
  `promotions`, więc pozycja menu zostaje aktywna na formularzu). `usePromotionList` (widok `PromotionsView`, standard listy: `PageHeader` z okruszkami „Pulpit › Marketing › Promocje”,
  `ListToolbar` bez wyszukiwania, bo API nie szuka promocji, `DataTable`, `ListPager`) na `useListUrlState` (`status` jako
  `Select` „Status”: Wszystkie, Zaplanowane, Aktywne, Zakończone, i `perPage`: 10, 25 albo 50, domyślnie 25 i niewysyłane;
  odczyt przy wejściu i przy zmianie z zewnątrz, np. link „Aktywne promocje” z pulpitu, zapis przez `replace`, nieznana
  wartość jest pomijana, a adres poprawiany) i `useCursorPager` (stos kursorów, spóźnione odpowiedzi, ponowienie, skutek
  błędu; 403 bez ponowienia; „Wyczyść filtry” kładzie fokus na filtrze statusu). Kolumny: Nazwa (link), Obniżka, Początek
  i Koniec (poniżej 1024 px jako linie komórki Nazwa), Status (`Badge` z tokenami `status-*`), Warianty i Akcje („Edytuj:
  <nazwa>” dla ról z prawem zmiany). Status wiersza i jego etykieta (`format.ts`) pochodzą z API. Przycisk „Dodaj promocję” i cała edycja:
  tylko `admin` i `marketing` (`permissions.ts`, role z `/me`; API sprawdza niezależnie); pozostali pracownicy widzą
  listę i szczegóły tylko do odczytu, a `/promotions/new` bez tej roli przekierowuje na listę (`beforeEnter`, jak
  `/products/new`). `usePromotionForm` (widok `PromotionFormView`, standard formularza: `PageHeader` z okruszkami „Pulpit › Marketing ›
  Promocje › <nazwa>”, od 1280 px dwie kolumny z kartą „Status” w DOM przed kartami „Dane promocji” i „Warianty”, `FormField`,
  `SaveBar` tylko przy zmianach albo przy nowej promocji, `useUnsavedChangesGuard` z `UnsavedChangesDialog`, toast
  i fokus jak w formularzu kodu): ładuje promocję (`GET /promotions/{id}`) albo zaczyna
  pusty formularz. Panel niczego nie liczy: status, wersja i ceny (katalogowa i promocyjna wariantu, `null` dla
  zakończonej) pochodzą z odpowiedzi API, a po każdym sukcesie formularz jest budowany z odpowiedzi. Status wyznacza
  pola: zaplanowana zmienia wszystko, aktywna tylko nazwę i koniec (`scheduleLocked`: procent, początek i warianty
  zablokowane, notatka w karcie „Dane promocji”, a do API idą zapisane wartości), zakończona i pracownik bez roli: tylko
  odczyt (bez paska zapisu, bez zmiany wariantów i bez akcji; notatka `read-only-note` w karcie „Status”). „Zapisz”:
  `POST` (potem `onCreated(id)`, widok przechodzi na `/promotions/:id` tą samą instancją, `replace`) albo `PUT`
  z `version`; po sukcesie toast „Promocja zapisana.” i fokus na H1 (chyba że pracownik przeniósł go do pola), po błędzie
  fokus na pierwszym `data-form-error` albo polu `aria-invalid` z przewinięciem do niego (#304 F3). „Odrzuć” (`discard`)
  przywraca formularz z ostatniej odpowiedzi API bez żądania. „Zakończ teraz” (aktywna) wysyła `PUT` z zapisanymi
  wartościami i `endsAt` równym czasowi przeglądarki, „Usuń” (zaplanowana) wysyła `DELETE ?version=`; oba stoją w karcie
  „Status”, pytają `ConfirmDialog` („Zakończyć promocję <nazwa> teraz?” z „Zakończ promocję teraz”, „Usunąć promocję
  <nazwa>?” z „Usuń promocję”, czerwone; „Wróć” ma fokus, `Escape` zamyka i fokus wraca na przycisk, #304 F6)
  i są wyłączone przy niezapisanych zmianach (z opisem „Najpierw zapisz zmiany.”). Po usunięciu toast „Promocja
  usunięta.”, przejście na listę (fokus na jej H1 daje powłoka). Ponowny odczyt po „Zakończ teraz” (#304 F7): API
  ustawia koniec chwilę w przyszłości, więc odpowiedź `PUT` zwykle ma `status: active`; `watchEnd` czeka
  `max(endsAt − teraz, 0) + 1 s`, odczytuje promocję ponownie i, gdy dalej jest `active` (zegar przeglądarki różni się od
  serwera), ponawia co 1 s najwyżej 5 razy. Do końca czekania `ending` wyłącza „Zapisz” i „Zakończ teraz” z opisem
  „Promocja kończy się.” (region `role="status"` w karcie „Status”, istniejący przed tekstem). Odczyt ze `status: ended`
  (albo taka odpowiedź `PUT`) daje toast „Promocja zakończona.” (przez `onEnded`, a przy `ended` w odpowiedzi `PUT` przez
  wynik wywołania), tryb tylko do odczytu i fokus na nagłówku „Status”; wyczerpane ponowienia zostawiają promocję aktywną
  z `endNotice` („Koniec promocji ustawiony na <czas>.” z ostatniej odpowiedzi), a formularz, `view` i wersja pochodzą
  z tej ostatniej odpowiedzi (także ponad niezapisane zmiany); błąd odczytu to `endError` z „Odśwież”, a gdy odświeżenie też zawiedzie
  (`loadError` zastępuje formularz), fokus trafia na H1. Odczyt `active` przed ostatnim nie nadpisuje tego, co pracownik wpisał
  w międzyczasie. Opuszczenie formularza przerywa czekanie
  (bez żądań po odmontowaniu). Czasy są wpisywane
  i pokazywane w `Europe/Warsaw` (pole `datetime-local`, nie `DatePicker`, bo ten liczy w strefie przeglądarki):
  `time.ts` zamienia je z i na moment RFC 3339 przez `Intl`, bez bibliotek (godzina z przejścia na czas letni, która
  nie istnieje, jest błędem pola, a godzina powtórzona jesienią daje pierwsze wystąpienie); czas, którego nie
  edytowano, idzie do API tak, jak go zwróciło (sekundy się nie gubią). „Od teraz” (`Switch`) wysyła `startsAt: null`. Procent
  to tekst z liczbą całkowitą (zakres sprawdza API). Błędy: 422 z `violations[].propertyPath` trafia do pól
  (`errorsAt`), 422 bez `violations`, 403, 404, 503 i sieć to komunikat (`Alert`) bez utraty formularza; 409 po `type`:
  `promotion-overlap` oznacza warianty z `conflictingVariantIds` („Koliduje z inną promocją”) i zostawia formularz,
  pozostałe (wersja `about:blank` z własnym komunikatem, `promotion-not-editable` i inne z `detail`) pokazują komunikat
  i przeładowują promocję z API. 404 promocji: H1 „Nie udało się wczytać promocji”, „Ta promocja nie istnieje.”
  w `Alert` z tokenem `status-danger` (kontrast, #446) i link do listy. `PromotionVariantPicker.vue` (shadcn-vue): wybór wariantów przez `useCursorPager(fetchPricePage, …)` z zapytaniem w pamięci (25 produktów na
  stronę, bez rozmiaru i stanu w adresie), `priceRows` i `useNameSearch` (wyszukiwanie od 2 znaków, podpowiedź w regionie
  `status`, stronicowanie „Poprzednia”/„Następna” z fokusem `usePagerFocus` jak w `ListPager`, bez wyłączania przycisków na czas żądania;
  „Spróbuj ponownie” zostawia fokus na sobie, a gdy komunikat znika, daje go tabeli albo polu wyszukiwania), „Dodaj” i „Dodaj cały produkt” (warianty bez ceny nie są oferowane;
  „Dodaj cały produkt” jest wyłączony, gdy nie ma czego dodać). Przyciski mają nazwy z nazwą wariantu („Dodaj: <produkt> /
  <wariant>”, „Dodaj cały produkt: <produkt>”, „Usuń: <produkt> / <wariant>”, #304 F5); fokus (#304 F4): otwarcie wyboru
  na pole wyszukiwania, po dodaniu wraca na pole wyszukiwania (przycisk, który go miał, jest już wyłączony), po
  usunięciu wybranego wariantu na przycisk usuwania następnego wiersza, a bez następnego na pole wyszukiwania otwartego
  wyboru albo na przycisk „Dodaj warianty”, po zamknięciu wyboru na ten przycisk. Lista wybranych (tabela w przewijanym
  regionie) ma „Usuń”; wariant usunięty z katalogu ma znacznik „Wariant usunięty”. Odpowiedź żądania, które skończyło
  się po opuszczeniu formularza, jest ignorowana. Pliki bez bloku `<style>`.
- `src/discount-codes/` – kody rabatowe (menu „Kody rabatowe”, trasy `/discount-codes`, `/discount-codes/new`,
  `/discount-codes/:id`: jedna ścieżka `discount-codes`, więc pozycja menu zostaje aktywna na formularzu).
  `useDiscountCodeList` (widok `DiscountCodesView`, standard listy: `PageHeader` z okruszkami „Pulpit › Marketing › Kody
  rabatowe”, `ListToolbar`, `DataTable`, `ListPager`) na `useListUrlState` (`q`, `status`, `perPage`: 10, 25 albo 50,
  domyślnie 25, niewysyłane) i `useCursorPager`; stan listy jest w adresie (czytany przy wejściu i przy zmianie z zewnątrz,
  np. link „Kody rabatowe” z pulpitu z `?status=active`, Wstecz; zapisywany przez `replace`, bez nowego wpisu historii;
  zła wartość jest pomijana, a adres poprawiany; „Wyczyść filtry” wraca na `/discount-codes`). Filtr statusu to `Select`
  „Status” (Wszystkie, Zaplanowane, Aktywne, Wygasłe, Wyłączone), wyszukiwanie po początku kodu to reguły tego composable
  (nie `useNameSearch`): od 1 do 32 znaków (punkty kodowe) po przycięciu białych znaków, separatorów i znaków formatu
  `/^[\s\p{Z}\p{Cf}]+|[\s\p{Z}\p{Cf}]+$/gu` jak robi to API, więc sam U+200B, U+00AD albo U+2060 to puste wyszukiwanie bez
  żądania z `q` (#336); po 300 ms przerwy w pisaniu; tekst dłuższy niż 32 znaki nie jest wysyłany, a podpowiedź (`role="status"`)
  to mówi; wielkość liter normalizuje API. Fokus po zmianie strony, po błędzie i po 403 robią `ListPager` i `DataTable`
  (widok nie ma własnej obsługi fokusu; #337). Kolumny: kod (link), rabat (procent albo kwota), minimum i okres (czas polski,
  „bez końca”; poniżej 1024 px są liniami komórki kodu), użycia (`activeUses` / limit albo „(bez limitu)”), status
  (`Badge` z tokenami `status-*`) i akcje; status, `activeUses` i kwoty pochodzą z API, panel niczego nie liczy
  (`format.ts`). Akcja wiersza „Edytuj: <kod>”, przycisk „Dodaj kod” i cała edycja: tylko `admin` i `marketing`
  (`permissions.ts`, role z `/me`; API sprawdza niezależnie); pozostali pracownicy widzą listę i formularz tylko do
  odczytu, a `/discount-codes/new` bez tej roli przekierowuje na listę (`beforeEnter`, jak `/products/new`).
  `useDiscountCodeForm` (widok `DiscountCodeFormView`): ładuje kod (`GET /discount-codes/{id}`) albo zaczyna pusty
  formularz (standard formularza: H1 „Nowy kod rabatowy” albo kod, kolumna boczna z kartą „Status” w DOM przed główną
  z kartami „Kod i rabat” i „Okres i limit”; rodzaj rabatu to `RadioGroup` Procent/Kwota, przełącznik „Aktywny” jest
  polem formularza: jego zmiana pokazuje `SaveBar` i trafia do API dopiero z „Zapisz”; `discard()` wraca do kodu z API bez
  żądania). Po każdym sukcesie formularz jest budowany z odpowiedzi API. Pola według reguł edycji: tekst kodu
  zmienia się tylko przy tworzeniu; kod `planned` zmienia wszystko poza tekstem; po starcie (`active`, `expired` i kod
  `disabled`, którego początek już minął według zegara przeglądarki; zegar tylko wybiera, które pola są dostępne,
  pomyłkę poprawia 409 z komunikatem i odświeżeniem) zmieniają się tylko koniec, `enabled` i limit (`termsLocked`:
  rodzaj, wartość, minimum i początek zablokowane, do API idą zapisane wartości), a limit tylko wtedy, gdy kod już go
  ma (`limitLocked`: nie da się go dodać; zdjęcie jest dozwolone). Kwoty (kwota rabatu, minimum) są wpisywane
  w złotych i zamieniane na grosze przez `parseGross`, procent i limit to liczby całkowite (zakres sprawdza API),
  czasy są w `Europe/Warsaw` przez `src/promotions/time.ts` (pole `datetime-local`; czas, którego nie edytowano, idzie
  do API tak, jak go zwróciło; puste pole końca to brak końca, puste pole limitu to brak limitu, puste minimum to
  brak minimum). „Zapisz”: `POST` (potem `onCreated(id)`, widok przechodzi na `/discount-codes/:id` tą samą instancją)
  albo `PUT` z `version` (bez tekstu kodu). Błędy: 422 z `violations[].propertyPath` trafia do pól (`errorsAt`),
  wartość, której nie da się odczytać, jest błędem pola bez żądania; 422 bez `violations`, 403, 404, 503 i sieć to
  komunikat bez utraty formularza; 409 po `type`: `discount-code-taken` przy polu kodu (formularz zostaje), pozostałe
  (wersja `about:blank`, `discount-code-not-editable` i inne) pokazują `detail` z API i przeładowują kod. Po
  nieudanym zapisie fokus trafia na pierwsze pole z błędem, a bez takiego na komunikat formularza; po udanym (także
  po utworzeniu kodu) pokazuje się toast „Kod rabatowy zapisany.” i fokus trafia na H1 (chyba że pracownik przeniósł go
  do pola). Niezapisane zmiany (także samego „Aktywny”) pilnuje `useUnsavedChangesGuard`. Kod, który nie istnieje albo
  się nie wczytał: H1 „Nie udało się wczytać kodu rabatowego”, `Alert` w tokenach `status-danger` (kontrast, #446)
  i link do listy. Tryb tylko do odczytu nie ma paska zapisu. Odpowiedź żądania,
  które skończyło się po opuszczeniu formularza, jest ignorowana. Lista zamówień per kod jest poza panelem.
- `src/components/ui/` – komponenty shadcn-vue na Reka UI (Button, Input, Label, Textarea, Select, Combobox, Switch,
  Checkbox, RadioGroup, Dialog, AlertDialog, Sheet, DropdownMenu, Tooltip, Badge, Card, Table, Skeleton, Alert, Sonner, Breadcrumb):
  nasz kod, tylko na tokenach, z polskimi nazwami dostępnymi („Zamknij”). Nowy komponent shadcn-vue dodaje się CLI
  w kontenerze i poprawia (klasy palety na tokeny, polskie teksty); `components.json` w `apps/admin`.
- `src/components/` – własne komponenty, gdy `ui/` nie ma odpowiednika. `ConfirmDialog` (na `AlertDialog`): tytuł z nazwą
  obiektu, opis skutku, przycisk skutku (`destructive`: czerwony, inaczej niebieski) i „Wróć” z domyślnym fokusem;
  zamknięcie (`Escape`, „Wróć”, akcja) oddaje fokus elementowi, który go miał przy otwarciu, a gdy ten zniknął,
  wskazanemu nagłówkowi (`fallback-focus`); zdarzenie `confirm`. `focusAfterAction(wyzwalacz, nagłówek)` z `src/lib/focus.ts`
  to ta sama reguła dla akcji, które zmieniają zestaw przycisków (po odpowiedzi API ekran woła ją sam, gdy dialog
  zamknął się wcześniej). `PageHeader`: H1 (`tabindex="-1"`, udostępnia `focus()` i element `heading`, np. na `fallback-focus` okna; slot `status` stoi tuż za H1, np. `Badge` statusu zamówienia) i okruszki (`<nav aria-label="Okruszki">`
  zaczynają się od „Pulpit”, ostatnia pozycja bez linku z `aria-current="page"`; zwykłe `<a>`, bo RouterLink oznacza
  `aria-current` każdy link do bieżącej trasy); używają go wszystkie ekrany poza pulpitem. `BrandMark`: znak Worf. `CategoryCombobox`: wybór kategorii z drzewa
  `useCategoryTree` (prop `nodes`), jedna (`modelValue`: id albo null) albo wiele (`multiple`: lista id); etykieta
  w prop `label` (z `hideLabel` tylko dla czytników), opcje w kolejności drzewa z pełną ścieżką jako nazwą dostępną
  („Turystyka › Biwak › Namioty”) i wcięciem według poziomu, filtrowanie po nazwie i ścieżce bez względu na wielkość
  liter, „Usuń: <nazwa>” przy wybranych w trybie wielokrotnym (po usunięciu fokus przechodzi na „Usuń” następnej
  etykiety, a bez niej poprzedniej albo na pole); `modelValue` w trybie pojedynczym to id albo `null`; z prop `deselectable` (tylko tryb pojedynczy; filtr listy produktów)
  ponowny wybór zaznaczonej kategorii zdejmuje ją (`null`), bo `ComboboxItem` Reki w trybie pojedynczym zawsze ustawia
  wybraną wartość i nie przełącza jej. Pole wejściowe jest tworzone na nowo, gdy zmieni się liczba opcji
  (prymityw czyta nazwę wybranej kategorii raz, a wartość z adresu listy bywa wcześniejsza niż drzewo). Z prop
  `invalid` i `describedBy` pole dostaje `aria-invalid` i `aria-describedby` (pole formularza z błędem). Używa go lista
  produktów, lista cen, lista stanów i formularz produktu.
  Standard formularza (spec „Standard formularza i okien”; podłączony do formularza produktu, kodu rabatowego i promocji): `FormSection` (biała karta z H2 `tabindex="-1"`, `heading` i `focusHeading()` dla fokusu po akcji),
  `FormField` (etykieta nad polem, podpowiedź w slocie `hint` i błędy pod nim z `data-form-error`, slot daje `describedBy`
  i `invalid` do wstawienia na kontrolkę; kontrolka z własną etykietą, jak `CategoryCombobox`, pomija `label`), `SaveBar`
  (przyklejony do dołu obszaru treści: „Niezapisane zmiany” albo prop `newLabel` przy `creating`, „Odrzuć” albo „Anuluj”
  i „Zapisz”; wyłączone w trakcie żądania; prop `saveDescribedBy` to `id` elementu, który opisuje wyłączony „Zapisz”; ustawia `scroll-padding-bottom` strony na swoją wysokość, więc pole z fokusem
  nie jest pod paskiem; ma ujemne marginesy równe paddingowi `<main>`) i `UnsavedChangesDialog` (`ConfirmDialog` „Masz
  niezapisane zmiany” z „Zostań” i „Opuść bez zapisu”; prop `fallbackFocus`: gdzie trafia fokus, gdy nawigację wywołało coś
  bez elementu, np. Wstecz przeglądarki, formularz podaje swój H1).
- `src/components/list/` – standard listy (spec „Standard listy”); używa go lista produktów, lista cen, lista stanów, lista kodów rabatowych, lista promocji i lista zamówień. `DataTable` (na `Table` shadcn-vue; opcjonalnie wiersze grup: `groupKey` łączy kolejne wiersze o tym samym kluczu w grupę, każda w osobnym `<tbody>`, a przed jej wierszami stoi wiersz z `<th scope="rowgroup">` na całą szerokość i slotem `group` (`row` to pierwszy wiersz grupy), z `data-testid` z `groupTestid`; `errorTestid` zmienia `data-testid` komunikatu błędu, domyślnie `list-error`; `cards` zamiast tabeli pokazuje wiersze jako listę kart: `<ul>` z nazwą z `label`, `<li>` z `rowTestid` i zawartością slotu `card`, a szkielet, błąd i pusty stan zostają te same: widok wybiera jeden układ): kolumny `ListColumn` (`key`, `header`, `align`, `main`, `sort` (`ascending` albo `descending`: `aria-sort` na `<th>` kolumny, po której lista jest posortowana), `hideBelow`
  `lg` = 1024 px albo `md` = 900 px i `text`), komórki to sloty `cell-<key>` (`row`), komórka główna ma opcjonalny slot
  `lead` (miniatura) i pod tekstem linie „Nagłówek: wartość” kolumn ukrytych (każda z klasą ukrywającą powyżej progu
  swojej kolumny, więc żadna informacja nie ginie), slot `empty` dla pustej listy. Pokazuje też stan listy: szkielet
  wierszy przy pierwszym wczytaniu (także gdy lista czeka jeszcze na drzewo kategorii z adresu; `loading` z
  `useCursorPager` jest wtedy prawdziwe) i po nieudanym zapytaniu, gdy filtr zmienia się znowu (nowe zapytanie czyści
  stary błąd i zakres „Pokazano X–Y”; ponowienie zostawia błąd do odpowiedzi), `aria-busy` na tabeli przy kolejnych, komunikat błędu (`role="alert"`,
  `tabindex="-1"`, „Spróbuj ponownie” poza 403: po ponowieniu fokus zostaje na przycisku, a gdy znika, trafia na
  komunikat, tabelę (w trybie `cards` listę kart, `tabindex="-1"`) albo pusty stan, nigdy na `body`); błąd listy, która nie wczytała się nigdy, stoi sam, bez tabeli, a
  błąd sąsiedniej strony nad nią. Tabela przewija się w swoim regionie (`role="region"` z nazwą z prop `label`,
  `tabindex="0"`) z cieniem przy krawędzi, za którą jest dalsza treść; strona nie przewija się w poziomie. `ListToolbar`:
  wyszukiwanie (opcjonalne; etykieta dostępna, `searchHint` w `role="status"` połączonym z polem, opcjonalnie `searchDescribedBy` (id innego tekstu, który też opisuje pole), zdarzenie `submit` to Enter w polu dla listy, która szuka po Enterze), sloty `filters` i
  `actions`, „Wyczyść filtry” przy `filtered`, kolejność (`Select` „Sortuj”, gdy lista ma `sortOptions`: `SortOption`
  z `src/composables/listSort.ts` to `value` (klucz API), `label` i `disabledReason`; opcja z powodem jest wyłączona, a powody
  są też w tekście `role="status"` pod polem, połączonym z nim przez `aria-describedby`; `sort` równe `null` pokazuje
  `sortPlaceholder`; zdarzenie `update:sort`), rozmiar strony (Select 10, 25, 50, etykieta „Wierszy na stronie”),
  `focusSearch()`. `ListPager`: „Pokazano X–Y” w `role="status"` (prop `noun` dla „Pokazano produkty X–Y”; prop `total` z `useCursorPager`
  dopisuje „z 1 234” albo „z ponad 10 000” przy `capped`; bez `total` jest tylko zakres; wszystkie liczby stopki, także
  zakres („Pokazano 10 001–10 025 z ponad 10 000”), mają tysiące oddzielone twardą spacją,
  `Intl.NumberFormat('pl-PL', { useGrouping: 'always' })`, bo sama reguła polska zostawia cztery cyfry razem; widoki
  list przekazują `total` z composable listy), przyciski
  „Poprzednia strona” i „Następna strona” z numerem strony; fokus zostaje na naciśniętym przycisku, a gdy kolejna strona go
  wyłącza (ostatnia, pierwsza), przechodzi na drugi, chyba że pracownik sam przeniósł fokus poza pager. `ListEmpty`: „Brak
  wyników dla wybranych filtrów.” z „Wyczyść filtry” (`empty-clear-filters`) przy filtrach, bez filtrów tekst listy
  (prop `emptyText`) i slot `action` (przycisk główny listy, widoczny tylko dla roli, która może tworzyć); prop
  `laterPage` (strona po pierwszej bez wierszy) pokazuje samo „Na tej stronie nie ma już wyników.”. `RowActions`: kwadratowe przyciski 34 px z
  ikoną i nazwą dostępną „Czasownik: nazwa obiektu” (zewnętrzny adres dopisuje „ (otwiera się w nowej karcie)”,
  `target="_blank"` `rel="noopener"`) oraz podpowiedzią z tą samą nazwą (`TooltipProvider` jest w komponencie); zwykły link,
  nie `RouterLink` (router nie oznacza go `aria-current`), klik z modyfikatorem zostaje przeglądarce; akcja bez `to` i `href` to przycisk (`select` z kluczem akcji i przyciskiem, `testid`
  nadpisuje `action-<klucz>`); akcję, której rola nie wykona, widok w ogóle nie podaje.
- `src/lib/utils.ts` – `cn` (łączenie klas) dla komponentów; `src/lib/focus.ts` – `focusAfterAction`.
- `src/styles/tokens.css` – jedyne miejsce wyglądu: `@theme` Tailwinda z paletą (`--palette-*`, bez klas) i warstwą
  semantyczną (tokeny specyfikacji ADMIN-DESIGN i nazwy zmiennych shadcn-vue), promienie, cienie, skala tekstu,
  `@font-face` Nunito i pierścień fokusu. Importowany w `main.ts`; w warstwie `base` ustawia też krój i kolor tekstu `body` (reguła poza warstwami wygrywałaby z Tailwindem).
- `src/assets/fonts/` – Nunito (WOFF2, podzbiory `latin` i `latin-ext`, wagi 400–800); źródło, wersja, licencja
  i SHA-256 plików w `THIRD_PARTY_NOTICES.md` (test `tests/fonts.test.ts`).

## Konfiguracja i obraz produkcyjny

- Panel czyta cztery adresy: `VITE_API_URL`, `VITE_KEYCLOAK_URL`, `VITE_ADMIN_URL` i `VITE_STORE_URL` (adres sklepu, cel linku „Sklep”
  w topbarze). `readUrl` (`src/config`) bierze
  wartość najpierw z `window.__ADMIN_CONFIG__`, a gdy nie ma tam klucza (albo jest pusty), z `import.meta.env`.
  Brak klucza w obu źródłach to błąd z nazwą klucza. Końcowe `/` jest obcinane. Nie czytaj `import.meta.env` poza
  `src/config`.
- `index.html` ładuje `/config.js` przed modułem aplikacji. Lokalnie `public/config.js` jest pusty (komentarz), więc
  `vite dev` nie daje 404, a adresy idą ze zmiennych `VITE_*` kontenera `admin` (compose).
- `apps/admin/Dockerfile` ma etapy `node`, `build` (`npm run build`: `vue-tsc` i `vite build`), `prod` i `dev`; `dev`
  jest ostatni, bo compose buduje panel bez `target`. `docker build --target prod apps/admin` działa bez stosu.
- `prod` to `nginxinc/nginx-unprivileged` (przypięta wersja, port 8080, użytkownik `nginx`) z `dist`. Przy starcie
  kontenera `docker/40-admin-config.sh` (hook `/docker-entrypoint.d`) zapisuje `/tmp/admin-config/config.js` ze
  zmiennych środowiska `VITE_API_URL`, `VITE_KEYCLOAK_URL`, `VITE_ADMIN_URL` i `VITE_STORE_URL` jako `window.__ADMIN_CONFIG__ = {...}`
  (wartości jako napisy JSON). Opcjonalne `SENTRY_DSN`, `SENTRY_ENVIRONMENT`, `SENTRY_RELEASE` i `SENTRY_TRACES_SAMPLE_RATE`
  dostają w pliku klucz o tej samej nazwie tylko wtedy, gdy są ustawione i niepuste (bez nich plik jest taki jak z czterema
  adresami; biały znak w wartości opcjonalnej kończy start błędem jak w adresie). Brak zmiennej adresu, pusta wartość albo
  biały znak w wartości kończy start błędem z nazwą zmiennej (test `infra/k8s/tests/test_admin_config.py` uruchamia skrypt w `sh` z katalogiem wyjścia z `ADMIN_CONFIG_DIR`
  w katalogu tymczasowym; biegnie w `make -C infra/k8s check`). Przy katalogu głównym tylko do odczytu `/tmp` musi być
  zapisywalny (`emptyDir`).
- `docker/nginx.conf`: `/config.js` z zapisanego pliku, fallback SPA na `index.html` (np. `/auth/callback`),
  `Cache-Control: no-cache` dla `index.html` i `/config.js`, długi cache tylko dla haszowanych `/assets/`.
- Nagłówki bezpieczeństwa: `docker/security-headers.conf` (kopiowany do `/etc/nginx/security-headers.conf`) jest
  dołączany (`include`) w każdej lokalizacji `nginx.conf`, bo `add_header` w `location` zastępuje nagłówki serwera.
  Wszystkie z `always` (także 404): `X-Content-Type-Options: nosniff`, `Referrer-Policy:
  strict-origin-when-cross-origin`, `X-Frame-Options: DENY`, `Permissions-Policy: camera=(), microphone=(),
  geolocation=()` i `Content-Security-Policy: script-src 'self'; frame-ancestors 'none'; object-src 'none';
  base-uri 'self'`. `style-src` nie jest ograniczony (komponenty Reka UI ustawiają style inline przy pozycjonowaniu okien i list). `index.html` nie może mieć
  skryptów inline ani zewnętrznych: skrypt spoza własnego origin (poza `/config.js` i bundlem) zablokuje CSP.
  Nowa lokalizacja w `nginx.conf` musi dołączać ten plik. Obraz deweloperski (Vite) nagłówków nie ma; nagłówki
  i załadowanie panelu bez naruszeń CSP sprawdza test dymny chartu (`make -C infra/k8s smoke`, obraz produkcyjny).

## Sentry

Spec: `docs/features/sentry/spec.md`.

- Konfiguracja: opcjonalne klucze `SENTRY_DSN`, `SENTRY_ENVIRONMENT`, `SENTRY_RELEASE` i `SENTRY_TRACES_SAMPLE_RATE` w `/config.js`
  (patrz „Konfiguracja i obraz produkcyjny”), czytane przez `readSentryConfig` (`src/config`) tylko z `window.__ADMIN_CONFIG__`:
  w `vite dev` Sentry nie działa (pusty `config.js`, `import.meta.env` nie jest czytany). `tracesSampleRate` to liczba od 0 do 1
  zapisana cyframi, inna wartość albo brak to 0. Wartości wdrożenia ustawia chart.
- Aktywacja tylko z DSN: `initSentry` (`main.ts`, przed zainstalowaniem routera i `mount`) bez DSN (brak, pusty, same białe znaki)
  nie woła `Sentry.init` i nic nie wysyła; zły format DSN to zachowanie SDK (nic nie jest wysyłane, panel działa). SDK
  (`@sentry/vue`) jest w bundlu, więc CSP (`script-src 'self'`) i nagłówki bezpieczeństwa są bez zmian.
- Opcje (`buildSentryOptions`): `attachProps: false` (propsy komponentów nie trafiają do błędów), bez integracji Pinii
  (`createSentryPiniaPlugin` w stanie store'ów ma pracownika i dane zamówień), bez Replay i Feedback, `sendDefaultPii` nie
  istnieje w tej wersji SDK: odpowiada mu `dataCollection` (bez użytkownika, ciasteczek, treści, query stringów, zmiennych
  ramek i linii źródła, z nagłówków tylko `User-Agent`, `Content-Type`, `Accept`). `traceLifecycle: "static"`: transakcja na
  stronę albo nawigację, wysyłana przez `beforeSendTransaction` (domyślne strumieniowanie spanów omija filtr).
  `browserTracingIntegration({ router })` daje transakcje nawigacji.
- Propagacja trace'ów: `sentry-trace` i `baggage` dostają tylko żądania do API (`tracePropagationTargets` to wyrażenie
  zakotwiczone na adresie z `VITE_API_URL`: cel tekstowy SDK pasuje w dowolnym miejscu adresu), nigdy Keycloak ani inny host.
  CORS api dopuszcza te nagłówki.
- Użytkownik: `setSentryUser(id)` po wczytaniu `/me` w store `auth` (`StaffMeResponse.id`, `sub`; bez loginu i e-maila),
  `setSentryUser(null)` na początku każdego wylogowania (`logout`, także po bezczynności, bo `useIdleLogout` woła `logout`),
  również gdy wylogowanie się nie uda (sesja się kończy; ponowne wczytanie `/me` ustawia id znowu). Bez
  zainicjowanego SDK to nic nie robi. Nie ustawiaj innych pól użytkownika.
- Filtr zdarzeń (`src/observability/filter.ts`, `filterEvent` i `filterBreadcrumb`) to jedna funkcja wołana z `beforeSend`,
  `beforeSendTransaction` i `beforeBreadcrumb`. Z żądania zostają metoda, adres bez query i fragmentu i nagłówki `User-Agent`,
  `Content-Type`, `Accept`; bez ciasteczek, treści, query stringu i adresu klienta; z użytkownika tylko `id`; bez `extra`.
  Query i fragment znikają z nazw transakcji, nazw spanów (HTTP, zasobów, nawigacji) i ich danych, adresów w danych
  kontekstów i breadcrumbs (`fetch`, `xhr`, `navigation`) i z adresów ramek (`q` wyszukiwania zamówień to e-mail, `code`
  i `state` z `/auth/callback`). Znikają też atrybuty `query.<klucz>`, które integracja routera SDK zapisuje dla każdego
  parametru zapytania trasy w spanie pageload i spanach nawigacji (a stamtąd w `contexts.trace.data` transakcji); parametry
  ścieżki (`params.<klucz>`, `url.path.parameter.<klucz>`) zostają. Breadcrumbs `console` są odrzucane, ramki bez `vars`. Teksty (komunikaty, wartości wyjątków,
  breadcrumbs, opisy spanów, tagi, wartości kontekstów) maskują te same wzorce co Alloy, filtr sklepu i filtr api
  (`SentryEventFilter`): e-mail (także `%40`), JWT, `Bearer`, hasło w adresie URL, klucz=wartość z sekretami, `Failing row
  contains (…)`, `Key (…)=(…)`, `DETAIL`/`STATEMENT`, `with params [...]`, adresy IP. Wzorce działają w czasie liniowym.
- Mapy źródeł (`sentrySourceMaps` w `vite.config.ts`, test `tests/vite-config.test.ts`; spec: P3, D10): wtyczka
  `@sentry/vite-plugin` (zależność deweloperska) jest dodawana do `plugins` tylko przy `SENTRY_UPLOAD=true` w budowaniu `prod`. Wtedy build
  zapisuje mapy „hidden” (bez komentarza `sourceMappingURL` w bundlu), wtyczka wysyła je dopasowane przez debug ID (bez
  tworzenia release'u) i usuwa (`deleteSourcemapsAfterUpload`, `filesToDeleteAfterUpload`), a błąd wysyłki przerywa build
  (`errorHandler` rzuca). Telemetria wtyczki wyłączona (`telemetry: false`), telemetria CLI Sentry pod nią przez `ENV SENTRY_CLI_NO_TELEMETRY=1` w etapie `build`. Flaga i `SENTRY_ORG`, `SENTRY_PROJECT` to `ARG` etapu
  `build` (domyślnie `false`, puste), token to sekret budowania `sentry_auth_token` (`RUN --mount=type=secret`, nigdy `ARG`
  ani `ENV`); flaga włączona bez tokenu, organizacji albo projektu kończy build błędem, a nie obrazem bez map. Bez flagi
  wtyczka nie jest dodawana do `plugins` i nic nie idzie do Sentry. Dockerfile na końcu usuwa każdy `.map` z `dist`. Flagę ustawia tylko
  job `images` workflowu `deploy.yml`; dokumentacja: `infra/k8s/CLAUDE.md`.
- Nowy format danych klienta w logach albo wyjątkach wymaga wzorca w filtrze Sentry (`MASKS` w `src/observability/filter.ts`,
  w filtrze sklepu `apps/store/src/observability/filter.ts` i `SentryEventFilter::MASKS` w api) i w Alloy
  (`infra/k8s/argocd/apps/monitoring.yaml`, korpus `infra/k8s/tests/log-corpus`) w tym samym PR. Test filtra ma kopię wierszy
  korpusu `store.log`, `api.log` i `postgres.log` (kontener panelu widzi tylko `apps/admin`): zmiana korpusu zmienia też kopię.

## Dane i API

- Dane wyłącznie z API przez wygenerowanego klienta (`src/api/client.ts`). ESLint blokuje globalny `fetch`
  (także `globalThis.fetch`, `window.fetch`, `self.fetch`) poza tym plikiem.
- Żadnej logiki biznesowej: ceny, sumy, dostępność i liczniki liczy API, panel je wyświetla.
- Role i uprawnienia pochodzą z API (`/me`, pola odpowiedzi), nigdy z dekodowania tokena. Front tylko ukrywa
  elementy interfejsu. Autoryzację egzekwuje API.

## Logowanie

- Tokeny OIDC trzymaj wyłącznie w pamięci (`InMemoryWebStorage`). Nigdy w `localStorage`, `sessionStorage`
  ani w ciasteczkach.
- Poza pamięcią (w `sessionStorage` karty) jest tylko stan trwającego żądania logowania (state i weryfikator PKCE).
  Musi przetrwać przekierowanie do Keycloak i z powrotem, nie zawiera tokenów, a biblioteka usuwa go po callbacku.
- 401 z API daje najwyżej jedno `signinRedirect()` na cykl logowania (jednorazowy znacznik z czasem w `sessionStorage`,
  nigdy token, usuwany po pierwszej odpowiedzi 2xx). Kolejny 401 pokazuje błąd z przyciskiem „Zaloguj ponownie”,
  bez pętli przekierowań. Błędy logowania i wylogowania zgłasza `src/auth/loginProblem.ts`, a pokazuje je `App.vue`.
- Po przeładowaniu strony pamięć jest pusta, a guard routera loguje przez aktywną sesję SSO w Keycloak.

## Wygląd

- Ekrany i komponenty: najpierw komponent shadcn-vue z `src/components/ui/`, potem własny w `src/components/`,
  na tokenach i klasach Tailwinda; ikony z Lucide (`lucide-vue-next`, dekoracyjne z `aria-hidden`). Wszystko w `src/`
  podlega regułom niżej, bez listy wyjątków.
- Formularz według standardu (wzorem jest `ProductFormView`, tak samo `DiscountCodeFormView` i `PromotionFormView`): `PageHeader`, dwie
  kolumny od 1280 px, karty `FormSection`, pola `FormField` (etykieta nad polem, podpowiedź i błąd połączone przez
  `aria-describedby`, `aria-invalid` przy błędzie), `SaveBar` i `useUnsavedChangesGuard` z `UnsavedChangesDialog` zamiast
  `window.confirm`. Po zapisie toast (`vue-sonner`, region `aria-live`) i fokus na H1 (chyba że pracownik przeniósł go do
  pola), po akcji, która zastępuje swój przycisk, na nagłówek karty; po błędzie na pierwszy `data-form-error` albo pole
  `aria-invalid`. Akcje nieodwracalne pytają `ConfirmDialog`.
- Lista według standardu: `PageHeader`, karta z `ListToolbar`, `DataTable` i `ListPager`; stan listy (wyszukiwanie, filtry,
  rozmiar strony 10/25/50, domyślnie 25) jest w adresie przez `useListUrlState`, stronicowanie przez `useCursorPager`, a
  pozycja w stronicowaniu tylko w pamięci. Wzorem jest `ProductsView`. Ukrywanie kolumn to klasy CSS na progach 1024 i 900 px
  (`hideBelow`), bez modelu tabeli. Kolejność wybiera `Select` „Sortuj” w `ListToolbar` (lista produktów, cen, stanów
  i zamówień; klucze API, `sortParam` i `ariaSortOf` z `src/composables/listSort.ts`), jest w adresie jako `sort`
  (domyślna nie jest zapisywana ani wysyłana), a jej zmiana wraca na pierwszą stronę. Sortuje API, bez
  `@tanstack/vue-table`; zaznaczania nie ma.
- Wygląd, standard listy, formularza i okien: `docs/features/admin-design/spec.md`; biblioteka i licencje:
  `docs/adr/admin-ui-library.md`.
- Tokeny wyglądu są wyłącznie w `src/styles/tokens.css` (`--color-*: initial`: domyślnej palety Tailwinda nie ma).
  Nowy kolor to nowy token w tym pliku i para w `tests/tokens.test.ts` (kontrast WCAG 2.x: tekst ≥ 4,5:1, obramowania
  pól, fokus i ikony KPI ≥ 3:1).
- Reguły wyglądu (`eslint.config.js`, każdy plik `src/**/*.{ts,vue}`, w tym `src/main.ts`): kolor „na sztywno” poza `tokens.css` jest błędem.
  `no-restricted-syntax` łapie wartości arbitralne z kolorem (`bg-[#fff]`, `text-[rgb(…)]`, `bg-[oklch(…)]`,
  `color-mix`, kolory nazwane, także we właściwościach arbitralnych `[color:red]` i jako człon wartości złożonej:
  `shadow-[0_0_0_2px_red]`, `bg-[linear-gradient(red,blue)]`; nazwa tokenu jak `var(--kpi-blue)` nie jest kolorem)
  w łańcuchach `<script>` i `.ts`,
  `vue/no-restricted-class` te same wzorce w klasach szablonu, `vue/no-restricted-syntax` w wyrażeniach szablonu poza
  `:class` (np. `cn(…)`, warunek) i w `:style`: tylko obiekt (albo tablica obiektów) z kluczami niewyliczanymi,
  bez spreadu; szablonowy napis, konkatenacja, zmienna, wywołanie i warunek są błędem, a kolory w nim są dozwolone tylko
  jako `var(--token)` (właściwość koloru z wartością wyliczaną też jest błędem), `vue/no-restricted-static-attribute` statyczny `style`, `vue/no-restricted-block` bloki
  `<style>`, a `better-tailwindcss/no-unknown-classes` (punkt wejścia `tokens.css`) klasy spoza tokenów, np.
  `bg-red-500`. `no-restricted-imports` blokuje import pakietów dawnej biblioteki panelu (lista w `docs/adr/admin-ui-library.md`). Kolor złożony w `<script>` poza
  łańcuchem klas (np. wartość zbudowana z kawałków) nie jest analizowany.
- Krój Nunito z plików panelu, stos zapasowy `system-ui, sans-serif`. Cyfry Nunito są tabelaryczne bez cechy `tnum`
  (podzbiory Google Fonts jej nie mają, ale każda cyfra 0–9 ma tę samą szerokość 600 jednostek przy wagach 400–800,
  więc „111” i „888” są równej szerokości), dlatego kolumny liczbowe używają Nunito z `tabular-nums`, bez stosu
  systemowego.
- Teksty interfejsu po polsku.

## Testy

- Vitest, `@vue/test-utils` i jsdom. Wszystkie testy to testy jednostkowe bez usług stosu.
- Testy leżą w `tests/` (reguły lintera w `tests/lint/`) lub obok kodu jako `*.test.ts`.
- Logika w composables i store'ach (np. `useIdleLogout`, obsługa 401, guard routera) ma testy.
- Test reguły ESLint sprawdza identyfikator reguły i liczbę błędów, nie tylko to, że coś zostało zgłoszone.
- `tests/lint/color-rules.test.ts` sprawdza regułę koloru (identyfikator i liczba błędów dla każdej ścieżki i prób
  obejścia, brak błędu dla klas z tokenów), blokadę importu pakietów dawnej biblioteki (także w `src/main.ts`)
  i to, że reguły obejmują każdy plik `src/` (kolor w `src/main.ts`, `<style>` w dowolnym `.vue`; poziom `error` reguł
  wyglądu dla każdego pliku z dysku według `calculateConfigForFile`, więc `ignores` w bloku reguł oblewa test); typy konfiguracji dla testu są
  w `eslint.config.d.ts`. Import dynamiczny `import()` blokuje dopiero brak pakietów (`vue-tsc`), nie ta reguła.
- Komponenty z Reka UI (okna, menu, combobox) testuje się w jsdom z atrapami `ResizeObserver`, `scrollIntoView`
  i przechwytywania wskaźnika w `tests/setup/jsdom-mocks.ts` (konfiguracja Vitest `setupFiles`); zawartość okien i list
  rozwijanych trafia do `document.body`, więc testy montują z `attachTo: document.body` i czyszczą je po teście.
  `tests/CategoryCombobox.test.ts` sprawdza rolę, nazwy dostępne i filtrowanie;
  `tests/ProductFormView.test.ts` formularz produktu (pola, błędy, pasek zapisu, okno niezapisanych zmian, toasty i fokus
  po zapisie, publikacji i błędzie, błąd wczytania), `tests/ProductImages.test.ts` i `tests/ProductVariants.test.ts`
  fokus po usunięciu, alert błędu wysyłania i okno usunięcia wariantu, `tests/useUnsavedChangesGuard.test.ts`,
  `tests/SaveBar.test.ts` i `tests/FormField.test.ts` elementy standardu formularza; `tests/ProductsView.test.ts` listę produktów
  (rzędy, akcje, linie kolumn ukrytych, pasek narzędzi, adres, pager i fokus, puste stany, trzy przypadki błędu);
  testy `useCursorPager` i `useListUrlState` używają routera w pamięci; `tests/ui-accessible-names.test.ts`
  nazwę „Zamknij” okien i brak angielskich nazw dostępnych w `src/components/ui/`.
- Sentry (`tests/sentryFilter.test.ts`, `tests/sentry.test.ts`, `tests/sentryTracing.test.ts`, `tests/config.test.ts`, `tests/vite-config.test.ts` (konfiguracja wysyłki map); nawigacje routera z `code`, `state` i `q` w `sentryTracing.test.ts` na fałszywych zegarach, bo spany nawigacji kończą się po bezczynności) bez konta
  i bez ruchu do Sentry: prawdziwe SDK z fałszywym DSN i transportem testowym zbierającym koperty (opcja `transport` w trzecim
  argumencie `initSentry`). SDK opakowuje `fetch`, `history`, `console` i `window.onerror` raz na stronę, więc `fetch` testu jest
  podmieniony raz przed pierwszym `init` (`vi.stubGlobal`), a testy śladów są w osobnym pliku z jednym klientem (próbkowanie 1).
  Wywołania sieci w testach idą przez wygenerowanego klienta (ESLint blokuje globalny `fetch`). Czyszczenie po teście: tylko
  zakres izolacji (`getIsolationScope().setUser(null)`), bo użytkownik „wyczyszczony” na bieżącym zakresie nadpisuje id w kolejnych zdarzeniach.
- `tests/tokens.test.ts` czyta `tokens.css` i sprawdza kontrast każdej pary tokenów; `tests/fonts.test.ts` sprawdza
  pliki Nunito, ich deklaracje i sumy SHA-256 w `THIRD_PARTY_NOTICES.md`.
