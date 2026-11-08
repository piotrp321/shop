# CLAUDE.md – apps/admin

Vue 3 (Composition API), Vite, TypeScript, Pinia, Vue Router, PrimeVue 4 (preset Aura), PrimeIcons.
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
- `src/router/` – trasy i guard: każda trasa poza `/auth/callback` wymaga zalogowania.
- `src/layout/` – menu boczne (pozycja „Zamówienia” tylko dla ról `admin`, `support` i `warehouse` z `/me`, do czasu
  wczytania `/me` ukryta; ukrywa tylko link, dostęp egzekwuje API), topbar i `AppLayout` (uruchamia `useIdleLogout`, 15 min).
- `src/views/` – widoki przypisane do tras (m.in. `ProductsView`, `ProductFormView`).
- `src/dashboard/` – komponenty i ładowanie danych dashboardu (`useDashboard`).
- `src/composables/` – współdzielona logika reaktywna (`useIdleLogout`, `useCategoryTree` – drzewo kategorii dla filtrów
  list cen i stanów).
- `src/prices/` – lista cen (`/prices`): `usePriceList` (wyszukiwanie od 2 znaków z opóźnieniem, filtr kategorii,
  stronicowanie po kursorach ze stosem kursorów w panelu; strona to produkty z API, a `rows` ma wiersz na wariant pod
  nazwą swojego produktu, z etykietą wariantu w kolumnie „Wariant”, pustą dla wariantu domyślnego; zmiana ceny
  wariantu wiersza: zapisana cena z API zastępuje wiersz, a spóźniona odpowiedź listy z niższą wersją ceny jej nie
  nadpisuje, 409 odświeża bieżącą stronę), `format.ts` (kwoty z groszy i stawki z punktów
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
  (widok `ProductsView`): wyszukiwanie od 2 znaków z opóźnieniem, filtr kategorii (`useCategoryTree`) i statusu
  (Wszystkie, Szkice, Opublikowane), stronicowanie po kursorach ze stosem kursorów w panelu, jak w `usePriceList`.
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
  `ProductFormView` pyta o potwierdzenie przy opuszczeniu formularza z niezapisanymi zmianami (globalny guard
  routera, bo po utworzeniu produktu ta sama instancja przechodzi między trasami) i ostrzega przy zamknięciu karty, ale nie przy wylogowaniu (`signingOut` w store `auth`; blokada wylogowania po bezczynności
  zostawiłaby sesję bez limitu). Odpowiedź żądania, które skończyło się po opuszczeniu formularza, jest ignorowana.
  Błąd 422 dla całej listy zdjęć (`images`) pokazuje sekcja „Zdjęcia”, dla jednego zdjęcia (`images[i]`) jego wiersz.
  Zapis, publikacja i wycofanie zwracają wynik (`ActionResult`), po którym `ProductFormView` przewija i ustawia fokus
  na pierwszym błędzie w kolejności dokumentu (pole z błędem albo komunikat, także w sekcjach „Zdjęcia” i „Warianty”),
  a po sukcesie na komunikacie sukcesu; formularz jest długi, a wynik pojawia się daleko od przycisku.
  `useImageUpload` (sekcja „Zdjęcia”, `ProductImages.vue`): pliki wysyłane po kolei jako `multipart` z osobnym
  statusem (oczekuje, wysyłanie, gotowe, błąd z `detail` 422 albo ogólnym komunikatem, ponowienie), klucz już
  obecny w formularzu nie jest dodawany drugi raz, z wybranych plików wysyłane są tylko te, które mieszczą się
  w limicie 10 zdjęć (pozostałe dostają błąd bez żądania). Nowe zdjęcie ma alt równy nazwie produktu.
  `thumbnail.ts` buduje `src` i `srcset` z podpisanych adresów API. `ProductVariants.vue` to edytor wariantów
  (sekcja „Warianty”): osie (0–2: dodanie, zmiana nazwy, przestawienie strzałkami razem z wartościami w wierszach,
  usunięcie) i tabela wierszy (pole wartości na oś, dodanie i usunięcie wiersza, 1–100 wierszy). Operacje na
  kształcie robi `useProductForm` (`addAxis`, `setAxis`, `removeAxis`, `moveAxis`, `addVariant`, `removeVariant`,
  `setValue`): dodanie osi dopisuje pustą wartość w każdym wierszu, usunięcie osi usuwa wartość na jej pozycji,
  usunięcie ostatniej osi zostawia pierwszy wiersz (z jego `variantId`, bez wartości) i usuwa pozostałe, a pierwsza
  oś dodana produktowi bez osi zachowuje wiersz domyślny z jego `variantId`. Nowy wiersz ma `variantId: null`,
  zachowane wiersze wysyłają swoje `variantId`. Produkt bez osi ma jeden wiersz „Wariant domyślny” bez pól i bez
  dodawania wierszy. Usunięcie wiersza istniejącego wariantu (i ostatniej osi, gdy usuwa istniejące warianty) pyta
  w `Dialog` PrimeVue („Cena i stan wariantu przestaną być widoczne w panelu.”), nowy wiersz znika bez pytania. Po
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
  „Zwroty do wykonania” (`refund=pending`: widok dodaje kolumny kwoty i terminu zwrotu oraz `Tag` „Po terminie” według
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
- `src/primevue.ts` – konfiguracja PrimeVue i motywu Aura.

## Konfiguracja i obraz produkcyjny

- Panel czyta trzy adresy: `VITE_API_URL`, `VITE_KEYCLOAK_URL`, `VITE_ADMIN_URL`. `readUrl` (`src/config`) bierze
  wartość najpierw z `window.__ADMIN_CONFIG__`, a gdy nie ma tam klucza (albo jest pusty), z `import.meta.env`.
  Brak klucza w obu źródłach to błąd z nazwą klucza. Końcowe `/` jest obcinane. Nie czytaj `import.meta.env` poza
  `src/config`.
- `index.html` ładuje `/config.js` przed modułem aplikacji. Lokalnie `public/config.js` jest pusty (komentarz), więc
  `vite dev` nie daje 404, a adresy idą ze zmiennych `VITE_*` kontenera `admin` (compose).
- `apps/admin/Dockerfile` ma etapy `node`, `build` (`npm run build`: `vue-tsc` i `vite build`), `prod` i `dev`; `dev`
  jest ostatni, bo compose buduje panel bez `target`. `docker build --target prod apps/admin` działa bez stosu.
- `prod` to `nginxinc/nginx-unprivileged` (przypięta wersja, port 8080, użytkownik `nginx`) z `dist`. Przy starcie
  kontenera `docker/40-admin-config.sh` (hook `/docker-entrypoint.d`) zapisuje `/tmp/admin-config/config.js` ze
  zmiennych środowiska `VITE_API_URL`, `VITE_KEYCLOAK_URL`, `VITE_ADMIN_URL` jako `window.__ADMIN_CONFIG__ = {...}`
  (wartości jako napisy JSON). Brak zmiennej, pusta wartość albo biały znak w wartości kończy start błędem z nazwą
  zmiennej. Przy katalogu głównym tylko do odczytu `/tmp` musi być zapisywalny (`emptyDir`).
- `docker/nginx.conf`: `/config.js` z zapisanego pliku, fallback SPA na `index.html` (np. `/auth/callback`),
  `Cache-Control: no-cache` dla `index.html` i `/config.js`, długi cache tylko dla haszowanych `/assets/`.

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

- Komponenty PrimeVue zamiast własnych odpowiedników (`Button`, `DataTable`, `Tag`, `Message`, `Skeleton`
  i pozostałe). Własny komponent powstaje tylko wtedy, gdy PrimeVue nie ma odpowiednika.
- Ikony z PrimeIcons. Kolory i odstępy z motywu Aura, bez kolorów „na sztywno”.
- Teksty interfejsu po polsku.

## Testy

- Vitest, `@vue/test-utils` i jsdom. Wszystkie testy to testy jednostkowe bez usług stosu.
- Testy leżą w `tests/` (reguły lintera w `tests/lint/`) lub obok kodu jako `*.test.ts`.
- Logika w composables i store'ach (np. `useIdleLogout`, obsługa 401, guard routera) ma testy.
- Test reguły ESLint sprawdza identyfikator reguły i liczbę błędów, nie tylko to, że coś zostało zgłoszone.
