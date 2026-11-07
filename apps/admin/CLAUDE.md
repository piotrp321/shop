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
- `src/auth/` – logowanie OIDC (`oidc-client-ts`): `config.ts` (ustawienia z env `VITE_*`), `userManager.ts`
  (tokeny w pamięci).
- `src/stores/` – store'y Pinia (`auth` z pracownikiem z `GET /api/v1/admin/me`).
- `src/router/` – trasy i guard: każda trasa poza `/auth/callback` wymaga zalogowania.
- `src/layout/` – menu boczne (pozycja „Zamówienia” tylko dla ról `admin`, `support` i `warehouse` z `/me`, do czasu
  wczytania `/me` ukryta; ukrywa tylko link, dostęp egzekwuje API), topbar i `AppLayout` (uruchamia `useIdleLogout`, 15 min).
- `src/views/` – widoki przypisane do tras.
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
