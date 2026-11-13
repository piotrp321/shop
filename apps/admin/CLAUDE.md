# CLAUDE.md – apps/admin

Vue 3 (Composition API), Vite, TypeScript, Pinia, Vue Router, Tailwind CSS 4, shadcn-vue (Reka UI), ikony Lucide;
PrimeVue 4 (preset Aura) i PrimeIcons tylko na ekranach jeszcze niezmigrowanych (`LEGACY_FILES`, do ADMIN-SCREENS).
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
- `src/config/` – `readUrl(klucz)`: jedyny czytnik konfiguracji (adresy API, Keycloaka i panelu), patrz „Konfiguracja”.
- `src/auth/` – logowanie OIDC (`oidc-client-ts`): `config.ts` (adresy przez `readUrl`), `userManager.ts`
  (tokeny w pamięci).
- `src/stores/` – store'y Pinia (`auth` z pracownikiem z `GET /api/v1/admin/me`).
- `src/router/` – trasy i guard: każda trasa poza `/auth/callback` wymaga zalogowania. `/orders` i `/orders/:orderId` mają
  strażnika (`beforeEnter` na ścieżce `orders`, `canViewOrders` z `src/orders/permissions.ts`), a `/system` strażnika
  `canViewSystem` (`src/dashboard/permissions.ts`: tylko `admin`): rola bez dostępu trafia na
  pulpit z `history.state.denied`, który pokazuje „Nie masz dostępu do tej strony.” (`useAccessDenied`: odczyt i czyszczenie
  znacznika w tym samym wpisie historii, więc przeładowanie komunikatu nie powtarza); gdy `/me` się nie wczyta, trasa jest
  przepuszczana (dostęp sprawdza API).
- `src/layout/` – powłoka panelu (spec „Powłoka”). `AppLayout`: link „Przejdź do treści” (pierwszy w kolejności Tab,
  przenosi fokus na `<main tabindex="-1">`), sidebar 260 px od 1024 px (`hidden lg:flex`), poniżej tej szerokości szuflada
  (`Sheet` z lewej: pułapka fokusu, `Escape` i wybór pozycji zamykają, fokus wraca na „Menu” przez `close-auto-focus`,
  powiększenie okna ≥ 1024 px ją zamyka), `AppTopbar`, `<main>` z komunikatem `staff-error` (`role="alert"`), stopka
  („© rok Worf · Panel administracyjny” i „Sklep”), kontener toastów (`Toaster`, vue-sonner, region `aria-live="polite"`,
  5 s) i `useIdleLogout` (15 min). `menu.ts` to jedyna definicja menu (sekcje Główne, Sklep, Marketing, System; grupy Katalog,
  Produkty, Zamówienia; widoczność według ról z `/me`, do czasu jego wczytania ukryte są tylko pozycje wymagające roli;
  ukrywa tylko link, dostęp egzekwuje API i strażnik trasy) i wybór bieżącej pozycji (`currentLeaf`: formularze i
  szczegóły należą do pozycji listy, `/orders?refund=pending` do „Zwrotów do wykonania”, a dla roli bez tej pozycji do
  „Listy zamówień”, „Pulpit” tylko na `/`). `AppMenu` i `AppMenuItem` renderują ją: grupa to przycisk z `aria-expanded`
  i `aria-controls`, grupa bieżącej pozycji otwiera się przy wejściu i przy każdej zmianie trasy (zamyka ją tylko
  przycisk), tylko bieżąca pozycja ma `aria-current="page"` (zwykły `<a>`, nie `aria-current` z RouterLink). Region grupy ma `id`
z `useId()` instancji menu (sidebar i szuflada są razem w DOM). Klik w pozycję o celu równym bieżącej lokalizacji
nawiguje ponownie (`force`, `replace`), bo stare listy nie zapisują swoich filtrów do adresu i router odrzuciłby push
jako duplikat; klik z modyfikatorem albo środkowym przyciskiem zostaje przeglądarce i nie zamyka szuflady. Pozycja
  „Stan systemu” (`/system`, sekcja System) jest tylko dla `admin`. `AppTopbar`: przycisk „Menu” (`lg:hidden`), wyszukiwarka produktów
  (`role="search"`; fraza po przycięciu 2–100 punktów kodowych idzie do `/products?q=`, znaki sterujące i separatory
  wierszy liczą się jak spacje, pusta nic nie robi, za krótka i za długa pokazują „Wpisz od 2 do 100 znaków.” w stałym
  regionie `role="status"` połączonym z polem przez `aria-describedby`, fokus zostaje w polu), link „Sklep” z
  `VITE_STORE_URL` w nowej karcie (`rel="noopener"`), konto (inicjały, login, polskie role z `account.ts`, menu z
  „Wyloguj”; bez wczytanego pracownika „Konto” i „Wyloguj” też są). `useAccessDenied` czyta i czyści
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
  danych o stanie systemu”. Pliki bez PrimeVue i bez bloku `<style>`.
- `src/composables/` – współdzielona logika reaktywna (`useIdleLogout`, `useCategoryTree` – drzewo kategorii dla filtrów
  list; `loaded` to koniec próby wczytania, także nieudanej, na który czeka lista z kategorią w adresie).
  `useUnsavedChangesGuard(dirty)`: strażnik niezapisanych zmian formularza (globalny `router.beforeEach`, nie
  `onBeforeRouteLeave`, bo po utworzeniu obiektu ta sama instancja przechodzi z trasy „nowy” na trasę utworzonego):
  przejście na inną trasę przy `dirty` czeka na odpowiedź, którą dają `open` (okno; zamknięcie, np. `Escape`, to
  zostanie), `stay()` i `leave()` (zamknięcie i potwierdzenie w jednym kliknięciu rozstrzyga potwierdzenie; nowsza
  nawigacja unieważnia poprzednie pytanie), a zamknięcie karty ostrzega `beforeunload` poza wylogowaniem (`signingOut`
  w store `auth`: blokada wylogowania po bezczynności zostawiłaby sesję bez limitu); po odmontowaniu nic nie pilnuje.
  `useCursorPager(fetchPage, query, { ready })`: wspólna implementacja stronicowania po kursorach (spec „Standard listy”; używa jej
  lista produktów, pozostałe listy mają jeszcze własne kopie do ADMIN-SCREENS).
  `fetchPage({ query, after })` zwraca `{ items, next }` albo `{ error, forbidden? }` (żądanie i komunikaty należą do
  composable listy), `query` to reaktywne zapytanie z `perPage`; każda nowa wartość zapytania (porównanie po JSON, więc
  równa nie wysyła żądania) zaczyna od pierwszej strony i porzuca stos kursorów, a `ready` wstrzymuje żądania. Stan:
  `items`, `loading`, `loaded`, `error`, `forbidden`, `hasPrevious`, `hasNext`, `pageNumber`, `range` (numery pierwszego
  i ostatniego wiersza strony dla „Pokazano X–Y”, z rozmiaru strony, na której wiersze zostały pobrane), `goNext`,
  `goPrevious`, `retry` (powtarza nieudane żądanie) i `refresh` (bieżąca strona po zapisie). Trzy przypadki błędu:
  nieudana zmiana zapytania (także pierwsze wczytanie) usuwa wiersze i kursory (`loaded` false, zostaje `error`), bo
  należą do poprzedniego zapytania (#251 F10); nieudana sąsiednia strona albo odświeżenie zostawia wyświetloną stronę.
  Tylko najnowsze żądanie zmienia stan. `useListUrlState(schema)`: wyszukiwanie, filtry i rozmiar strony w adresie.
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
- `src/prices/` – lista cen (`/prices`): `usePriceList` (wyszukiwanie od 2 znaków z opóźnieniem, filtr kategorii,
  stronicowanie po kursorach ze stosem kursorów w panelu; strona to produkty z API, a `rows` ma wiersz na wariant pod
  nazwą swojego produktu, z etykietą wariantu w kolumnie „Wariant”, pustą dla wariantu domyślnego; zmiana ceny
  wariantu wiersza: zapisana cena z API zastępuje wiersz, a spóźniona odpowiedź listy z niższą wersją ceny jej nie
  nadpisuje, 409 odświeża bieżącą stronę, a 409 po `type` `price-locked-by-promotion`, czyli wariant w aktywnej promocji,
  zwraca `locked` z `detail` z API: `PricesView` pokazuje go przy polu ceny, bez komunikatu o zmianie w międzyczasie
  i bez odświeżania wiersza), `format.ts` (kwoty z groszy i stawki z punktów
  bazowych przez `Intl`, bez liczenia czegokolwiek), `parseGross.ts` (tekst kwoty w złotych na grosze na cyfrach
  tekstu, bez `float`; zakres kwoty sprawdza API). Dialog „Zmień cenę” w `PricesView` widzi tylko rola `admin`
  (role z `/me`). `usePriceHistory` (dialog „Historia” w `PricesView`, dostępny dla każdego pracownika): pierwsza
  strona historii wariantu wiersza i „Pokaż więcej” po kursorze z API, odpowiedź po zamknięciu dialogu albo otwarciu
  innego wariantu jest ignorowana. `format.ts` ma też `formatDateTime` (czas polski niezależnie od strefy przeglądarki)
  i `sourceLabel` (etykiety źródeł zmiany: Pracownik, Dane demo, Uzgodnienie podatkowe).
- `src/inventory/` – lista stanów magazynowych (`/inventory`, widok `InventoryView`): `useStockList` (wyszukiwanie od
  2 znaków z opóźnieniem, filtr kategorii, stronicowanie po kursorach ze stosem kursorów w panelu, jak w `usePriceList`;
  strona to produkty z API, a `rows` ma wiersz na wariant pod nazwą swojego produktu, z etykietą wariantu w kolumnie
  „Wariant”, pustą dla wariantu domyślnego),
  `format.ts` (`levelLabel`: etykieta i kolor poziomu z API: Dostępny, Ostatnie sztuki, Niedostępny; `movementTypeLabel`,
  `movementReasonLabel`, `movementSourceLabel` i `formatDelta` dla historii stanów). Wariant bez
  rekordu (`stock: null`) pokazuje zera w kolumnach liczbowych i „—” zamiast poziomu; panel nie przypisuje mu poziomu.
  Przyjęcie, korekta i historia dotyczą wariantu wiersza (dialogi pokazują nazwę produktu z etykietą wariantu).
  Przyciski „Przyjęcie” i „Korekta” w wierszu oraz ich dialogi (w `InventoryView`) widzą tylko role `admin` i
  `warehouse` (role z `/me`; API sprawdza niezależnie). `receiveStock` i `correctStock` w `useStockList`: stan
  wariantu z odpowiedzi API zastępuje jego wiersz, a spóźniona odpowiedź listy z niższą wersją stanu go nie nadpisuje. Klucz
  `Idempotency-Key` (UUID z `crypto.randomUUID()`) powstaje przy otwarciu dialogu przyjęcia i jest wysyłany ponownie
  przy ponowieniu zapisu; nowy dialog to nowy klucz. Korekta wysyła `version` wiersza (null bez rekordu), a notatkę
  wymaga przy powodzie „Inne”. 422: `violations[].propertyPath` wskazuje pole, a 422 bez `violations` (np. ponad
  1 000 000) i 403 pokazuje komunikat w dialogu. 409 przyjęcia (klucz użyty z inną treścią) pokazuje `detail` z API i odświeża bieżącą stronę, bo pierwsze przyjęcie z tym kluczem mogło się zapisać mimo zgubionej odpowiedzi. Oba 409 korekty mają
  `type: about:blank`, więc panel nie czyta `detail`: na 409 odświeża bieżącą stronę i jeśli wersja wiersza różni się od
  wysłanej, pokazuje „Stan został w międzyczasie zmieniony – odśwież”, w przeciwnym razie `detail` z API (poniżej
  zarezerwowanego).
  `useStockHistory` (dialog „Historia” w `InventoryView`, dostępny dla każdego pracownika): pierwsza strona historii
  stanów wariantu wiersza i „Pokaż więcej” po kursorze z API, odpowiedź po zamknięciu dialogu albo otwarciu innego
  wariantu jest ignorowana, błąd kolejnej strony zostawia wczytane wpisy i pozwala ponowić tę stronę.
- `src/products/` – produkty (menu „Produkty”, trasy `/products`, `/products/new`, `/products/:id`). `useProductList`
  (widok `ProductsView`, `useProductList({ nodes, loaded })` z drzewem z `useCategoryTree`): na `useListUrlState`
  (`q`, `category`, `status`, `perPage`) i `useCursorPager`; własne zostają żądanie `GET /products` (`perPage` idzie
  tylko, gdy to nie 25, domyślne API), komunikaty błędów (403: „Nie masz dostępu do produktów.” bez ponowienia, 404:
  kategoria nie istnieje) i wyszukiwanie: od 2 do 100 znaków (punkty kodowe) po 300 ms przerwy w pisaniu, przycięte;
  do adresu i zapytania trafia dopiero zastosowana fraza, a za krótka albo za długa (też z adresu) zostaje w polu z
  podpowiedzią (`searchHint`) i nie filtruje. Kategoria z adresu, której nie ma w drzewie (także przy błędzie drzewa),
  jest pomijana, a przy kategorii w adresie lista czeka na drzewo przed pierwszym żądaniem. `clearFilters` czyści
  wyszukiwanie, filtry i rozmiar strony (adres `/products`). `ProductsView`: karta z `ListToolbar` (wyszukiwanie,
  `CategoryCombobox` „Kategoria”, Select „Status”, rozmiar strony, „Dodaj produkt” dla `admin` i `marketing`), `DataTable`
  (kolumny Produkt, Kategoria, Warianty, Status, Ostatnia zmiana, Akcje; Kategoria ukryta poniżej 1024 px, Ostatnia zmiana
  poniżej 900 px; miniatura 42 px albo placeholder, slug pod nazwą, data i pod nią godzina z sekundami; w Akcjach
  „Edytuj” tylko dla `admin` i `marketing` (`canManageProducts`; nazwa produktu jest linkiem do formularza dla każdej
  roli, a dla pozostałych formularz jest tylko do odczytu) i dla opublikowanego „Podgląd w sklepie”
  `<VITE_STORE_URL>/p/<slug>` w nowej karcie dla każdej roli), `ListPager` i `ListEmpty`. Pusty stan ma własne
  `data-testid` (`empty-add-product`, `empty-clear-filters`), bo pasek ma te same przyciski. Strona po pierwszej, na
  którą API odpowie bez wierszy (lista zmieniła się od poprzedniej strony), zostawia pager z „Poprzednia strona”, a
  pusty stan mówi tylko „Na tej stronie nie ma już wyników.”. Po „Wyczyść filtry” fokus trafia na pole wyszukiwania.
  `useProductForm` (widok `ProductFormView`): ładuje produkt (`GET /products/{id}`) albo zaczyna pusty formularz
  z wariantem domyślnym (bez osi, jeden wiersz `{variantId: null, values: []}`) i klasy podatkowe
  (`GET /tax-classes`). Panel niczego nie liczy: slug, `slugLocked`, status i wersja pochodzą z odpowiedzi API, a po
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
  `role="alert"` (#250 F14). Obie sekcje to `FormSection` na tokenach, bez PrimeVue. `ProductVariants.vue` to edytor wariantów
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
  znika bez pytania; po usunięciu fokus przechodzi na nagłówek „Warianty” (`fallback-focus`), bo przycisk zniknął z wierszem (#251 F12). Po
  pierwszej publikacji (`axesLocked` z `firstPublishedAt` API) dodanie, usunięcie i przestawienie osi są wyłączone
  i pod tabelą jest wyjaśnienie, a nazwy osi, wartości i wiersze zostają edytowalne. Panel nie liczy klucza
  porównania ani unikalności kombinacji, robi to API. `variantsErrors` ma tylko komunikaty całej sekcji (409
  `variant-locked` i `variant-in-use` z `detail`, 422 dla `variants` i `variantAxes`); 422 `variantAxes[i]` jest
  przy osi, `variants[i].values[j]` przy polu, `variants[i].values` i `variants[i].variantId` przy wierszu
  (`errorsExactly` zwraca ścieżkę bez zagnieżdżonych). Zmiana kształtu (dodanie, przestawienie, usunięcie) kasuje te
  komunikaty, bo ich ścieżki liczą pozycje. Bez roli do zmiany produktów edytor nie ma kontrolek edycji, a pola są
  wyłączone.
- `src/orders/` – zamówienia (widoki `OrdersView` pod `/orders` i `OrderDetailView` pod `/orders/:orderId`, dwie trasy
  jednej ścieżki `orders`, więc pozycja menu zostaje aktywna na szczegółach). `useOrderList`: filtr statusu, przełącznik
  „Zwroty do wykonania” (oba też z adresu: `status` i `refund=pending` przy wejściu i przy zmianie `route.query` przy
  otwartej liście, np. pozycja menu „Zwroty do wykonania”; ustawia oba filtry według adresu, wraca na pierwszą stronę,
  zostawia wyszukiwanie, pomija nieznaną wartość i niczego nie zapisuje do adresu; `refund=pending`: widok dodaje kolumny kwoty i terminu zwrotu oraz `Tag` „Po terminie” według
  `overdue` z API), wyszukiwanie dokładnego numeru albo e-maila wysyłane dopiero po Enterze (`submitSearch`), stronicowanie
  po kursorach ze stosem kursorów w panelu (zmiana filtra albo wyszukiwania zaczyna od pierwszej strony i porzuca
  wiersze oraz kursory, bo należą do filtrów i sortowania, z których pochodzą: po nieudanym żądaniu nowego filtra tabela
  i pager są ukryte do udanego ponowienia, a nieudane przejście na sąsiednią stronę zostawia wyświetloną stronę). Pusta lista: „Brak zamówień.” bez filtrów
  i wyszukiwania, „Nie znaleziono zamówień.” z nimi. Błąd listy zostawia wyświetloną stronę i pozwala ponowić; 403 to
  „Nie masz dostępu do zamówień.” bez ponowienia. `useOrderDetail(orderId)`: szczegóły z `GET /api/v1/admin/orders/{id}`;
  przyciski to wyłącznie `allowedTransitions` (`actions`) i `canCompleteRefund` z API, panel nie liczy statusów ani ról.
  `changeStatus` i `completeRefund` wysyłają `version` pokazanego widoku, a zamówienie z odpowiedzi zastępuje widok.
  Notatka jest wymagana przy `cancel` i `return` (dialog sprawdza przed wysłaniem, API sprawdza jeszcze raz), pusta
  notatka w pozostałych to `null`. 409 (wersja, przejście, zwrot) przeładowuje szczegóły i zamyka dialog
  z komunikatem „Zamówienie zostało w międzyczasie zmienione – sprawdź jego stan.” na stronie; 422 wskazuje pole
  notatki (`violations[].propertyPath`), a 403 i pozostałe błędy to komunikat w dialogu. Jednocześnie leci najwyżej jedna
  zmiana, a odpowiedź na zmianę po przejściu widoku do innego zamówienia jest pomijana (`ignored`, także przeładowanie po 409). 404 szczegółów: „Nie znaleźliśmy zamówienia.” z linkiem do listy; inny błąd odczytu: komunikat z „Spróbuj
  ponownie” (403 bez ponowienia). Błąd przeładowania po 409 zostawia pokazane zamówienie. `format.ts`: etykiety i kolory
  statusów (`statusLabel`, `STATUS_OPTIONS`), aktorów historii (`actorLabel`: nazwa pracownika, Klient, System),
  przejść (`transitionLabel`), powodu anulowania i `noteRequired`; kwoty i czas przez `formatMoney` i `formatDateTime`
  z `src/prices/format.ts`. Widok szczegółów pokazuje kontakt, adres, pozycje z sumami od API, daty statusów, termin
  płatności tylko dla `new`, historię statusów (data, zmiana, aktor, notatka) oraz sekcję „Zwrot pieniędzy” (kwota,
  termin z „Po terminie”, wykonanie: data, pracownik, notatka, i `paymentId` płatności do zwrotu).
  Rabat z kodu: gdy zamówienie ma `discount`, tabela pozycji ma kolumny „Wartość przed rabatem” (`grossBeforeDiscount`),
  „Rabat” (`discountGross` linii, „—” przy zerze) i „Wartość po rabacie” (`lineGross`), pod nią stoi linia „Kod <KOD>:
  −X zł” (`discount.amountGross`), a suma brutto nazywa się „Brutto po rabacie”; sumy netto, VAT i brutto są po rabacie,
  z API. Zamówienie bez kodu ma jedną kolumnę „Wartość brutto” jak dotąd. Kopia promocji pozycji (`promotion`: nazwa,
  procent, cena katalogowa jednostki) jest pod nazwą produktu, z kodem i bez.
- `src/promotions/` – promocje (menu „Promocje”, trasy `/promotions`, `/promotions/new`, `/promotions/:id`: jedna ścieżka
  `promotions`, więc pozycja menu zostaje aktywna na formularzu). `usePromotionList` (widok `PromotionsView`): filtr
  statusu (Wszystkie, Zaplanowane, Aktywne, Zakończone; także z adresu: `status` przy wejściu i przy zmianie `route.query`
  przy otwartej liście, np. link „Aktywne promocje” z pulpitu; ustawia filtr według adresu, wraca na pierwszą stronę,
  pomija nieznaną wartość, rozpoznaje swój widok po nazwie trasy i niczego nie zapisuje do adresu; filtr w widoku
  pokazuje status listy) i stronicowanie po kursorach ze stosem kursorów w panelu, jak
  w `useOrderList` (zmiana filtra porzuca wiersze i kursory; błąd następnej strony zostawia wyświetloną; 403 bez
  ponowienia). Status wiersza i jego etykieta (`format.ts`) pochodzą z API. Przycisk „Dodaj promocję” i cała edycja:
  tylko `admin` i `marketing` (`permissions.ts`, role z `/me`; API sprawdza niezależnie); pozostali pracownicy widzą
  listę i szczegóły tylko do odczytu, a `/promotions/new` bez tej roli przekierowuje na listę (`beforeEnter`, jak
  `/products/new`). `usePromotionForm` (widok `PromotionFormView`): ładuje promocję (`GET /promotions/{id}`) albo zaczyna
  pusty formularz. Panel niczego nie liczy: status, wersja i ceny (katalogowa i promocyjna wariantu, `null` dla
  zakończonej) pochodzą z odpowiedzi API, a po każdym sukcesie formularz jest budowany z odpowiedzi. Status wyznacza
  pola: zaplanowana zmienia wszystko, aktywna tylko nazwę i koniec (`scheduleLocked`: procent, początek i warianty
  zablokowane, a do API idą zapisane wartości), zakończona i pracownik bez roli: tylko odczyt. „Zapisz”: `POST` (potem
  `onCreated(id)`, widok przechodzi na `/promotions/:id` tą samą instancją) albo `PUT` z `version`. „Zakończ teraz”
  (aktywna) wysyła `PUT` z zapisanymi wartościami i `endsAt` równym czasowi przeglądarki, „Usuń” (zaplanowana) wysyła
  `DELETE ?version=`; oba pytają o potwierdzenie i są wyłączone przy niezapisanych zmianach (pytanie znika, gdy akcja przestaje być dozwolona, np. po edycji formularza). Komunikat po „Zakończ teraz” wynika ze `status` odpowiedzi: `ended` to „Promocja zakończona.”, a `active` (zegar przeglądarki wyprzedza serwer, API tylko skraca promocję) to „Koniec promocji ustawiony na <czas>.”. Czasy są wpisywane
  i pokazywane w `Europe/Warsaw` (pole `datetime-local`, nie `DatePicker`, bo ten liczy w strefie przeglądarki):
  `time.ts` zamienia je z i na moment RFC 3339 przez `Intl`, bez bibliotek (godzina z przejścia na czas letni, która
  nie istnieje, jest błędem pola, a godzina powtórzona jesienią daje pierwsze wystąpienie); czas, którego nie
  edytowano, idzie do API tak, jak go zwróciło (sekundy się nie gubią). „Od teraz” wysyła `startsAt: null`. Procent
  to tekst z liczbą całkowitą (zakres sprawdza API). Błędy: 422 z `violations[].propertyPath` trafia do pól
  (`errorsAt`), 422 bez `violations`, 403, 404, 503 i sieć to komunikat bez utraty formularza; 409 po `type`:
  `promotion-overlap` oznacza warianty z `conflictingVariantIds` („Koliduje z inną promocją”) i zostawia formularz,
  pozostałe (wersja `about:blank` z własnym komunikatem, `promotion-not-editable` i inne z `detail`) pokazują komunikat
  i przeładowują promocję z API. `PromotionVariantPicker.vue`: wybór wariantów przez `usePriceList` (wyszukiwanie od
  2 znaków, stronicowanie), „Dodaj wariant” i „Dodaj cały produkt” (warianty bez ceny nie są oferowane; „Dodaj cały produkt” jest wyłączony, gdy nie ma czego dodać); lista
  wybranych z „Usuń”, wariant usunięty z katalogu ma znacznik „Wariant usunięty”. Odpowiedź żądania, które skończyło
  się po opuszczeniu formularza, jest ignorowana.
- `src/discount-codes/` – kody rabatowe (menu „Kody rabatowe”, trasy `/discount-codes`, `/discount-codes/new`,
  `/discount-codes/:id`: jedna ścieżka `discount-codes`, więc pozycja menu zostaje aktywna na formularzu).
  `useDiscountCodeList` (widok `DiscountCodesView`): filtr statusu (Wszystkie, Zaplanowane, Aktywne, Wygasłe,
  Wyłączone; także z adresu jak w `usePromotionList`, np. link „Kody rabatowe” z pulpitu; wyszukiwanie zostaje), wyszukiwanie po początku kodu (od 1 znaku, po przycięciu, z opóźnieniem; tekst dłuższy niż 32 znaki nie
  jest wysyłany i widok to mówi; wielkość liter normalizuje API) i stronicowanie po kursorach ze stosem kursorów
  w panelu, jak w `usePromotionList` (zmiana filtra albo wyszukiwania porzuca wiersze i kursory; błąd następnej strony
  zostawia wyświetloną; 403 bez ponowienia). Przyciski „Poprzednia” i „Następna” nie są wyłączane na czas ładowania
  (fokus zostaje na naciśniętym; ponowne naciśnięcie prosi o tę samą stronę), a przycisk wyłączony po przejściu
  (ostatnia albo pierwsza strona) oddaje fokus drugiemu; „Strona N” to `aria-live="polite"`. „Spróbuj ponownie” też nie jest wyłączany na czas
  ładowania (nieudane ponowienie zostawia fokus na nim; ponowne naciśnięcie pyta jeszcze raz), a po udanym fokus
  (który spadłby na `body`) trafia na pierwszy kod listy albo, gdy strona nie ma kodów, na włączony przycisk pagera, a gdy nie ma też
  drugiej strony (filtr lub wyszukiwanie bez wyników), na pole wyszukiwania. Kolumny: kod, rabat (procent albo kwota), minimum, okres (czas polski,
  „bez końca”), użycia (`activeUses` / limit albo „(bez limitu)”) i status; status, `activeUses` i kwoty pochodzą
  z API, panel niczego nie liczy (`format.ts`). Przycisk „Dodaj kod” i cała edycja: tylko `admin` i `marketing`
  (`permissions.ts`, role z `/me`; API sprawdza niezależnie); pozostali pracownicy widzą listę i formularz tylko do
  odczytu, a `/discount-codes/new` bez tej roli przekierowuje na listę (`beforeEnter`, jak `/products/new`).
  `useDiscountCodeForm` (widok `DiscountCodeFormView`): ładuje kod (`GET /discount-codes/{id}`) albo zaczyna pusty
  formularz. Po każdym sukcesie formularz jest budowany z odpowiedzi API. Pola według reguł edycji: tekst kodu
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
  po utworzeniu kodu) na komunikat „Kod rabatowy zapisany.”. Odpowiedź żądania,
  które skończyło się po opuszczeniu formularza, jest ignorowana. Lista zamówień per kod jest poza panelem.
- `src/primevue.ts` – konfiguracja PrimeVue i motywu Aura.
- `src/components/ui/` – komponenty shadcn-vue na Reka UI (Button, Input, Label, Textarea, Select, Combobox, Switch,
  Checkbox, Dialog, AlertDialog, Sheet, DropdownMenu, Tooltip, Badge, Card, Table, Skeleton, Alert, Sonner, Breadcrumb):
  nasz kod, tylko na tokenach, z polskimi nazwami dostępnymi („Zamknij”). Nowy komponent shadcn-vue dodaje się CLI
  w kontenerze i poprawia (klasy palety na tokeny, polskie teksty); `components.json` w `apps/admin`.
- `src/components/` – własne komponenty, gdy `ui/` nie ma odpowiednika. `ConfirmDialog` (na `AlertDialog`): tytuł z nazwą
  obiektu, opis skutku, przycisk skutku (`destructive`: czerwony, inaczej niebieski) i „Wróć” z domyślnym fokusem;
  zamknięcie (`Escape`, „Wróć”, akcja) oddaje fokus elementowi, który go miał przy otwarciu, a gdy ten zniknął,
  wskazanemu nagłówkowi (`fallback-focus`); zdarzenie `confirm`. `focusAfterAction(wyzwalacz, nagłówek)` z `src/lib/focus.ts`
  to ta sama reguła dla akcji, które zmieniają zestaw przycisków (po odpowiedzi API ekran woła ją sam, gdy dialog
  zamknął się wcześniej). `PageHeader`: H1 (`tabindex="-1"`, `focus()`) i okruszki (`<nav aria-label="Okruszki">`
  zaczynają się od „Pulpit”, ostatnia pozycja bez linku z `aria-current="page"`; zwykłe `<a>`, bo RouterLink oznacza
  `aria-current` każdy link do bieżącej trasy); używają go ekrany po migracji. `BrandMark`: znak Worf. `CategoryCombobox`: wybór kategorii z drzewa
  `useCategoryTree` (prop `nodes`), jedna (`modelValue`: id albo null) albo wiele (`multiple`: lista id); etykieta
  w prop `label` (z `hideLabel` tylko dla czytników), opcje w kolejności drzewa z pełną ścieżką jako nazwą dostępną
  („Turystyka › Biwak › Namioty”) i wcięciem według poziomu, filtrowanie po nazwie i ścieżce bez względu na wielkość
  liter, „Usuń: <nazwa>” przy wybranych w trybie wielokrotnym (po usunięciu fokus przechodzi na „Usuń” następnej
  etykiety, a bez niej poprzedniej albo na pole); `modelValue` w trybie pojedynczym to id albo `null`; z prop `deselectable` (tylko tryb pojedynczy; filtr listy produktów)
  ponowny wybór zaznaczonej kategorii zdejmuje ją (`null`), bo `ComboboxItem` Reki w trybie pojedynczym zawsze ustawia
  wybraną wartość i nie przełącza jej. Pole wejściowe jest tworzone na nowo, gdy zmieni się liczba opcji
  (prymityw czyta nazwę wybranej kategorii raz, a wartość z adresu listy bywa wcześniejsza niż drzewo). Z prop
  `invalid` i `describedBy` pole dostaje `aria-invalid` i `aria-describedby` (pole formularza z błędem). Używa go lista
  produktów i formularz produktu; ceny i stany (ADMIN-SCREENS) są jeszcze na `TreeSelect`.
  Standard formularza (spec „Standard formularza i okien”; podłączony tylko do formularza produktu, reszta formularzy
  w ADMIN-SCREENS): `FormSection` (biała karta z H2 `tabindex="-1"`, `heading` i `focusHeading()` dla fokusu po akcji),
  `FormField` (etykieta nad polem, podpowiedź w slocie `hint` i błędy pod nim z `data-form-error`, slot daje `describedBy`
  i `invalid` do wstawienia na kontrolkę; kontrolka z własną etykietą, jak `CategoryCombobox`, pomija `label`), `SaveBar`
  (przyklejony do dołu obszaru treści: „Niezapisane zmiany” albo prop `newLabel` przy `creating`, „Odrzuć” albo „Anuluj”
  i „Zapisz”; wyłączone w trakcie żądania; ustawia `scroll-padding-bottom` strony na swoją wysokość, więc pole z fokusem
  nie jest pod paskiem; ma ujemne marginesy równe paddingowi `<main>`) i `UnsavedChangesDialog` (`ConfirmDialog` „Masz
  niezapisane zmiany” z „Zostań” i „Opuść bez zapisu”).
- `src/components/list/` – standard listy (spec „Standard listy”); używa go lista produktów, pozostałe listy przejdą w
  ADMIN-SCREENS. `DataTable` (na `Table` shadcn-vue): kolumny `ListColumn` (`key`, `header`, `align`, `main`, `hideBelow`
  `lg` = 1024 px albo `md` = 900 px i `text`), komórki to sloty `cell-<key>` (`row`), komórka główna ma opcjonalny slot
  `lead` (miniatura) i pod tekstem linie „Nagłówek: wartość” kolumn ukrytych (każda z klasą ukrywającą powyżej progu
  swojej kolumny, więc żadna informacja nie ginie), slot `empty` dla pustej listy. Pokazuje też stan listy: szkielet
  wierszy przy pierwszym wczytaniu (także gdy lista czeka jeszcze na drzewo kategorii z adresu; `loading` z
  `useCursorPager` jest wtedy prawdziwe) i po nieudanym zapytaniu, gdy filtr zmienia się znowu (nowe zapytanie czyści
  stary błąd i zakres „Pokazano X–Y”; ponowienie zostawia błąd do odpowiedzi), `aria-busy` na tabeli przy kolejnych, komunikat błędu (`role="alert"`,
  `tabindex="-1"`, „Spróbuj ponownie” poza 403: po ponowieniu fokus zostaje na przycisku, a gdy znika, trafia na
  komunikat, tabelę albo pusty stan, nigdy na `body`); błąd listy, która nie wczytała się nigdy, stoi sam, bez tabeli, a
  błąd sąsiedniej strony nad nią. Tabela przewija się w swoim regionie (`role="region"` z nazwą z prop `label`,
  `tabindex="0"`) z cieniem przy krawędzi, za którą jest dalsza treść; strona nie przewija się w poziomie. `ListToolbar`:
  wyszukiwanie (opcjonalne; etykieta dostępna, `searchHint` w `role="status"` połączonym z polem), sloty `filters` i
  `actions`, „Wyczyść filtry” przy `filtered`, rozmiar strony (Select 10, 25, 50, etykieta „Wierszy na stronie”),
  `focusSearch()`. `ListPager`: „Pokazano X–Y” w `role="status"` (prop `noun` dla „Pokazano produkty X–Y”), przyciski
  „Poprzednia strona” i „Następna strona” z numerem strony; fokus zostaje na naciśniętym przycisku, a gdy kolejna strona go
  wyłącza (ostatnia, pierwsza), przechodzi na drugi, chyba że pracownik sam przeniósł fokus poza pager. `ListEmpty`: „Brak
  wyników dla wybranych filtrów.” z „Wyczyść filtry” (`empty-clear-filters`) przy filtrach, bez filtrów tekst listy
  (prop `emptyText`) i slot `action` (przycisk główny listy, widoczny tylko dla roli, która może tworzyć); prop
  `laterPage` (strona po pierwszej bez wierszy) pokazuje samo „Na tej stronie nie ma już wyników.”. `RowActions`: kwadratowe przyciski 34 px z
  ikoną i nazwą dostępną „Czasownik: nazwa obiektu” (zewnętrzny adres dopisuje „ (otwiera się w nowej karcie)”,
  `target="_blank"` `rel="noopener"`) oraz podpowiedzią z tą samą nazwą (`TooltipProvider` jest w komponencie); zwykły link,
  nie `RouterLink` (router nie oznacza go `aria-current`), klik z modyfikatorem zostaje przeglądarce; akcję, której rola
  nie wykona, widok w ogóle nie podaje.
- `src/lib/utils.ts` – `cn` (łączenie klas) dla komponentów; `src/lib/focus.ts` – `focusAfterAction`.
- `src/styles/tokens.css` – jedyne miejsce wyglądu: `@theme` Tailwinda z paletą (`--palette-*`, bez klas) i warstwą
  semantyczną (tokeny specyfikacji ADMIN-DESIGN i nazwy zmiennych shadcn-vue), promienie, cienie, skala tekstu,
  `@font-face` Nunito i pierścień fokusu. Importowany w `main.ts` przed `src/styles.css`.
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
  (wartości jako napisy JSON). Brak zmiennej, pusta wartość albo biały znak w wartości kończy start błędem z nazwą
  zmiennej (test `infra/k8s/tests/test_admin_config.py` uruchamia skrypt w `sh` z katalogiem wyjścia z `ADMIN_CONFIG_DIR`
  w katalogu tymczasowym; biegnie w `make -C infra/k8s check`). Przy katalogu głównym tylko do odczytu `/tmp` musi być
  zapisywalny (`emptyDir`).
- `docker/nginx.conf`: `/config.js` z zapisanego pliku, fallback SPA na `index.html` (np. `/auth/callback`),
  `Cache-Control: no-cache` dla `index.html` i `/config.js`, długi cache tylko dla haszowanych `/assets/`.
- Nagłówki bezpieczeństwa: `docker/security-headers.conf` (kopiowany do `/etc/nginx/security-headers.conf`) jest
  dołączany (`include`) w każdej lokalizacji `nginx.conf`, bo `add_header` w `location` zastępuje nagłówki serwera.
  Wszystkie z `always` (także 404): `X-Content-Type-Options: nosniff`, `Referrer-Policy:
  strict-origin-when-cross-origin`, `X-Frame-Options: DENY`, `Permissions-Policy: camera=(), microphone=(),
  geolocation=()` i `Content-Security-Policy: script-src 'self'; frame-ancestors 'none'; object-src 'none';
  base-uri 'self'`. `style-src` nie jest ograniczony (PrimeVue i komponenty Reka UI ustawiają style inline). `index.html` nie może mieć
  skryptów inline ani zewnętrznych: skrypt spoza własnego origin (poza `/config.js` i bundlem) zablokuje CSP.
  Nowa lokalizacja w `nginx.conf` musi dołączać ten plik. Obraz deweloperski (Vite) nagłówków nie ma; nagłówki
  i załadowanie panelu bez naruszeń CSP sprawdza test dymny chartu (`make -C infra/k8s smoke`, obraz produkcyjny).

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

- Nowe i zmigrowane ekrany: najpierw komponent shadcn-vue z `src/components/ui/`, potem własny w `src/components/`,
  na tokenach i klasach Tailwinda; ikony z Lucide (`lucide-vue-next`, dekoracyjne z `aria-hidden`). Ekrany z listy
  `LEGACY_FILES` zostają na komponentach PrimeVue, PrimeIcons i motywie Aura do ADMIN-SCREENS; PrimeVue działa obok
  Tailwinda (style PrimeVue są poza warstwami CSS, więc wygrywają z preflightem).
- Formularz według standardu (wzorem jest `ProductFormView`; promocja i kod rabatowy w ADMIN-SCREENS): `PageHeader`, dwie
  kolumny od 1280 px, karty `FormSection`, pola `FormField` (etykieta nad polem, podpowiedź i błąd połączone przez
  `aria-describedby`, `aria-invalid` przy błędzie), `SaveBar` i `useUnsavedChangesGuard` z `UnsavedChangesDialog` zamiast
  `window.confirm`. Po zapisie toast (`vue-sonner`, region `aria-live`) i fokus na H1 (chyba że pracownik przeniósł go do
  pola), po akcji, która zastępuje swój przycisk, na nagłówek karty; po błędzie na pierwszy `data-form-error` albo pole
  `aria-invalid`. Akcje nieodwracalne pytają `ConfirmDialog`.
- Lista według standardu: `PageHeader`, karta z `ListToolbar`, `DataTable` i `ListPager`; stan listy (wyszukiwanie, filtry,
  rozmiar strony 10/25/50, domyślnie 25) jest w adresie przez `useListUrlState`, stronicowanie przez `useCursorPager`, a
  pozycja w stronicowaniu tylko w pamięci. Wzorem jest `ProductsView`. Ukrywanie kolumn to klasy CSS na progach 1024 i 900 px
  (`hideBelow`), bez modelu tabeli; sortowania i zaznaczania nie ma (ADMIN-FEATURES).
- Wygląd, standard listy, formularza i okien: `docs/features/admin-design/spec.md`; biblioteka i licencje:
  `docs/adr/admin-ui-library.md`.
- Tokeny wyglądu są wyłącznie w `src/styles/tokens.css` (`--color-*: initial`: domyślnej palety Tailwinda nie ma).
  Nowy kolor to nowy token w tym pliku i para w `tests/tokens.test.ts` (kontrast WCAG 2.x: tekst ≥ 4,5:1, obramowania
  pól, fokus i ikony KPI ≥ 3:1).
- Reguła koloru (`eslint.config.js`, pliki `src/**/*.{ts,vue}`): kolor „na sztywno” poza `tokens.css` jest błędem.
  `no-restricted-syntax` łapie wartości arbitralne z kolorem (`bg-[#fff]`, `text-[rgb(…)]`, `bg-[oklch(…)]`,
  `color-mix`, kolory nazwane, także we właściwościach arbitralnych `[color:red]` i jako człon wartości złożonej:
  `shadow-[0_0_0_2px_red]`, `bg-[linear-gradient(red,blue)]`; nazwa tokenu jak `var(--kpi-blue)` nie jest kolorem)
  w łańcuchach `<script>` i `.ts`,
  `vue/no-restricted-class` te same wzorce w klasach szablonu, `vue/no-restricted-syntax` w wyrażeniach szablonu poza
  `:class` (np. `cn(…)`, warunek) i w `:style`: tylko obiekt (albo tablica obiektów) z kluczami niewyliczanymi,
  bez spreadu; szablonowy napis, konkatenacja, zmienna, wywołanie i warunek są błędem, a kolory w nim są dozwolone tylko
  jako `var(--token)` (właściwość koloru z wartością wyliczaną też jest błędem), `vue/no-restricted-static-attribute` statyczny `style`, `vue/no-restricted-block` bloki
  `<style>`, a `better-tailwindcss/no-unknown-classes` (punkt wejścia `tokens.css`) klasy spoza tokenów, np.
  `bg-red-500`. `no-restricted-imports` blokuje import PrimeVue i PrimeIcons. Kolor złożony w `<script>` poza
  łańcuchem klas (np. wartość zbudowana z kawałków) nie jest analizowany.
- `LEGACY_FILES` w `eslint.config.js`: jawne ścieżki plików jeszcze na PrimeVue (bez wzorców i katalogów); tylko
  reguły z punktu wyżej ich nie obejmują. Migracja ekranu usuwa jego pliki z listy, nowy plik na nią nie trafia (test
  reguł sprawdza, że każdy wpis istnieje i importuje PrimeVue). Lista znika z PrimeVue w ADMIN-SCREENS.
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
  obejścia, brak błędu dla klas z tokenów) i listę `LEGACY_FILES`; typy listy dla testu są w `eslint.config.d.ts`.
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
- `tests/tokens.test.ts` czyta `tokens.css` i sprawdza kontrast każdej pary tokenów; `tests/fonts.test.ts` sprawdza
  pliki Nunito, ich deklaracje i sumy SHA-256 w `THIRD_PARTY_NOTICES.md`.
