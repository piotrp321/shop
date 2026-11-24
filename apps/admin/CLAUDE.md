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
- `src/router/` – trasy i guard: każda trasa poza `/auth/callback` wymaga zalogowania. Strażniki tras według ról i odmowa
  dostępu: `docs/features/admin-design/spec.md` („Powłoka”) i `docs/features/admin-features/spec.md` (sekcja 5).
- `src/layout/` – powłoka panelu (spec „Powłoka”). `menu.ts` to jedyna definicja menu i wybór bieżącej pozycji.
  `AppMenu` i `AppMenuItem` renderują ją: tylko bieżąca pozycja ma `aria-current="page"` (zwykły `<a>`, nie `aria-current`
  z RouterLink). Region grupy ma `id` z `useId()` instancji menu (sidebar i szuflada są razem w DOM).
- `src/views/` – widoki przypisane do tras.
- `src/dashboard/` – komponenty i composables pulpitu i ekranu „Stan systemu”. Opis: `docs/features/admin-design/spec.md`
  („Pulpit i Stan systemu”), `docs/features/admin-features/spec.md` (sekcja 1) i
  `docs/features/dashboard-data-freshness/spec.md`.
- `src/composables/` – współdzielona logika reaktywna. `useNameSearch({ initial, apply })`: jedyne miejsce reguł
  wyszukiwania po nazwie listy produktów, listy cen, listy stanów i wyboru wariantów promocji. Reguły są testowane raz,
  w `tests/useNameSearch.test.ts`. `useFormSafety` i `formRequests`: wspólna ochrona szkicu formularza przed jego własnymi żądaniami (patrz „Wygląd”).
  Pozostałe composables (`useCursorPager`, `useListUrlState`, `useUnsavedChangesGuard`,
  `usePagerFocus`) opisują komentarze w ich plikach i `docs/features/admin-design/spec.md`.
- `src/categories/` – ekran „Kategorie” (`/categories`, `CategoriesView`, menu Sklep › Katalog › Kategorie; docs/features/admin-features/spec.md,
  „Kategorie”). Panel nie liczy reguł drzewa (głębokość, cykl, pustość, unikalność sluga to API); wyłącza tylko to, co oczywiście niemożliwe: własne
  poddrzewo w „Przenieś do…” i upuszczenie bez zmiany.
- `src/prices/` – lista cen (`/prices`, widok `PricesView`). `pricePage.ts`: `perPage` idzie do API zawsze, bo domyślne API
  to 25, a lista zaczyna od 10. `format.ts` (kwoty z groszy i stawki z
  punktów bazowych przez `Intl`, bez liczenia czegokolwiek), `parseGross.ts` (tekst kwoty w złotych na grosze na cyfrach
  tekstu, bez `float`; zakres kwoty sprawdza API). `format.ts` ma też `formatDateTime` (czas polski niezależnie od strefy przeglądarki)
  i `sourceLabel` (etykiety źródeł zmiany: Pracownik, Dane demo, Uzgodnienie podatkowe). Opis ekranu:
  `docs/features/admin-screens/spec.md`, „Ceny i Stany magazynowe”.
- `src/inventory/` – lista stanów magazynowych (`/inventory`, widok `InventoryView`). `stockPage.ts`: żądanie `GET /stock` z `perPage`
  zawsze w żądaniu, domyślne API to 25, panel zaczyna od 10. Opis: `docs/features/inventory/spec.md`
  i `docs/features/admin-screens/spec.md`, „Ceny i Stany magazynowe”.
- `src/products/` – produkty (menu „Produkty”, trasy `/products`, `/products/new`, `/products/:id`). Panel niczego nie liczy: slug, `slugLocked`, status i wersja pochodzą z odpowiedzi API, a po
  każdym sukcesie formularz jest budowany z odpowiedzi. Odpowiedź żądania, które skończyło się po opuszczeniu formularza, jest ignorowana.
  `thumbnail.ts` buduje `src` i `srcset` z podpisanych adresów API. Opis: `docs/features/admin-design/spec.md` („Produkty”)
  i `docs/features/admin-features/spec.md` (sekcja 2); zapis, błędy 409/422 i role: `src/products/useProductForm.ts`.
- `src/orders/` – zamówienia (widoki `OrdersView` pod `/orders` i `OrderDetailView` pod `/orders/:orderId`, dwie trasy
  jednej ścieżki `orders`, więc pozycja menu zostaje aktywna na szczegółach). `useOrderDetail(orderId)`: szczegóły z `GET /api/v1/admin/orders/{id}`;
  przyciski to wyłącznie `allowedTransitions` (`actions`), `canCompleteRefund` i `canChangeTrackingNumber` z API, panel nie
  liczy statusów ani ról.
  Opis: `docs/features/admin-screens/spec.md` („Zamówienia”) i `docs/features/order-management/spec.md`.
- `src/customers/` – klienci (menu Sklep › Klienci, trasy `/customers` i `/customers/:customerId`: dwie trasy jednej ścieżki
  `customers`, więc pozycja menu zostaje aktywna na karcie). Klient to konto sklepu z co najmniej jednym
  zamówieniem (docs/features/admin-features/spec.md, sekcja 5).
- `src/promotions/` – promocje (menu „Promocje”, trasy `/promotions`, `/promotions/new`, `/promotions/:id`: jedna ścieżka
  `promotions`, więc pozycja menu zostaje aktywna na formularzu). Czasy są wpisywane
  i pokazywane w `Europe/Warsaw` (pole `datetime-local`, nie `DatePicker`, bo ten liczy w strefie przeglądarki):
  `time.ts` zamienia je z i na moment RFC 3339 przez `Intl`, bez bibliotek (godzina z przejścia na czas letni, która
  nie istnieje, jest błędem pola, a godzina powtórzona jesienią daje pierwsze wystąpienie); czas, którego nie
  edytowano, idzie do API tak, jak go zwróciło (sekundy się nie gubią). Opis: `docs/features/admin-screens/spec.md`
  („Promocje i kody rabatowe”) i `docs/features/promotions/spec.md`.
- `src/discount-codes/` – kody rabatowe (menu „Kody rabatowe”, trasy `/discount-codes`, `/discount-codes/new`,
  `/discount-codes/:id`: jedna ścieżka `discount-codes`, więc pozycja menu zostaje aktywna na formularzu). Opis:
  `docs/features/admin-screens/spec.md` („Promocje i kody rabatowe”) i `docs/features/discount-codes/spec.md`.
- `src/components/ui/` – komponenty shadcn-vue na Reka UI:
  nasz kod, tylko na tokenach, z polskimi nazwami dostępnymi („Zamknij”). Nowy komponent shadcn-vue dodaje się CLI
  w kontenerze i poprawia (klasy palety na tokeny, polskie teksty); `components.json` w `apps/admin`.
- `src/components/` – własne komponenty, gdy `ui/` nie ma odpowiednika. `ConfirmDialog` (na `AlertDialog`): tytuł z nazwą
  obiektu, opis skutku, przycisk skutku (`destructive`: czerwony, inaczej niebieski) i „Wróć” z domyślnym fokusem;
  zamknięcie (`Escape`, „Wróć”, akcja) oddaje fokus elementowi, który go miał przy otwarciu, a gdy ten zniknął,
  wskazanemu nagłówkowi (`fallback-focus`); zdarzenie `confirm`. Akcja, której odmowę API ma powiedzieć okno, wyłącza `close-on-confirm`:
  potwierdzenie zostawia okno otwarte (zamyka je widok przez `open`), `busy` blokuje przycisk na czas żądania przez `aria-disabled` (nie `disabled`, który oddałby fokus
  `body`), a `error` jest w oknie (`role="alert"`, `tabindex="-1"`) i dostaje fokus, gdy się pojawi. `focusAfterAction(wyzwalacz, nagłówek)` z `src/lib/focus.ts`
  to ta sama reguła dla akcji, które zmieniają zestaw przycisków (po odpowiedzi API ekran woła ją sam, gdy dialog
  zamknął się wcześniej). `PageHeader`: H1 (`tabindex="-1"`, udostępnia `focus()` i element `heading`, np. na `fallback-focus` okna; slot `status` stoi tuż za H1, np. `Badge` statusu zamówienia) i okruszki (`<nav aria-label="Okruszki">`
  zaczynają się od „Pulpit”, ostatnia pozycja bez linku z `aria-current="page"`; zwykłe `<a>`, bo RouterLink oznacza
  `aria-current` każdy link do bieżącej trasy); używają go wszystkie ekrany poza pulpitem. `SaveBar` ma ujemne marginesy równe paddingowi `<main>`.
  Pozostałe komponenty (`CategoryCombobox`, `FormSection`, `FormField`, `SaveBar`, `UnsavedChangesDialog`) opisują ich pliki
  i `docs/features/admin-design/spec.md` („Produkty”, „Standard formularza i okien”).
- `src/components/list/` – standard listy (spec „Standard listy”). Tabela przewija się w swoim regionie (`role="region"` z nazwą z prop `label`,
  `tabindex="0"`) z cieniem przy krawędzi, za którą jest dalsza treść; strona nie przewija się w poziomie. `RowActions`: kwadratowe przyciski 34 px z
  ikoną i nazwą dostępną „Czasownik: nazwa obiektu” (zewnętrzny adres dopisuje „ (otwiera się w nowej karcie)”,
  `target="_blank"` `rel="noopener"`) oraz podpowiedzią z tą samą nazwą; zwykły link,
  nie `RouterLink` (router nie oznacza go `aria-current`), klik z modyfikatorem zostaje przeglądarce; akcję, której rola nie wykona, widok w ogóle nie podaje.
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
  w `vite dev` Sentry nie działa (pusty `config.js`, `import.meta.env` nie jest czytany). Wartości wdrożenia ustawia chart.
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
  `beforeSendTransaction` i `beforeBreadcrumb`. Co filtr usuwa i maskuje, opisuje komentarz na początku tego pliku.
  Wzorce działają w czasie liniowym.
- Mapy źródeł (`sentrySourceMaps` w `vite.config.ts`, test `tests/vite-config.test.ts`; spec: P3, D10): wtyczka
  `@sentry/vite-plugin` (zależność deweloperska) jest dodawana do `plugins` tylko przy `SENTRY_UPLOAD=true` w budowaniu `prod`. Wtedy build
  zapisuje mapy „hidden” (bez komentarza `sourceMappingURL` w bundlu), wtyczka wysyła je dopasowane przez debug ID (bez
  tworzenia release'u) i usuwa (`deleteSourcemapsAfterUpload`, `filesToDeleteAfterUpload`), a błąd wysyłki przerywa build
  (`errorHandler` rzuca). Telemetria wtyczki wyłączona (`telemetry: false`), telemetria CLI Sentry pod nią przez `ENV SENTRY_CLI_NO_TELEMETRY=1` w etapie `build`. Flaga i `SENTRY_ORG`, `SENTRY_PROJECT` to `ARG` etapu
  `build` (domyślnie `false`, puste), token to sekret budowania `sentry_auth_token` (`RUN --mount=type=secret`, nigdy `ARG`
  ani `ENV`); flaga włączona bez tokenu, organizacji albo projektu kończy build błędem, a nie obrazem bez map. Bez flagi
  wtyczka nie jest dodawana do `plugins` i nic nie idzie do Sentry. Dockerfile na końcu usuwa każdy `.map` z `dist`. Flagę ustawia tylko
  job `images` workflowu `deploy.yml` (`docs/features/sentry/spec.md`, D10).
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
  `aria-invalid`. Akcje nieodwracalne pytają `ConfirmDialog`. W trakcie każdego żądania, którego odpowiedź
  zastępuje pola formularza albo jego stan bazowy (zapis, „Odśwież”, ponowny odczyt po zapisie), pola formularza są
  zablokowane, nie tylko przyciski paska zapisu (wzór zapisu i odczytu: `safety.locked` z `useFormSafety` w
  `usePromotionForm`); sprawdza to test Vitest z niezałatwioną obietnicą mocka każdego takiego żądania. „Zapisz” paska zapisu jest wtedy nieaktywny przez `aria-disabled`, nie `disabled` (jak przycisk `ConfirmDialog`): przycisk, który pracownik nacisnął, zachowuje fokus do odpowiedzi.
- Szkic formularza zmienia tylko pracownik: odczyt, 409 ani spóźniona odpowiedź go nie zastępują (wyjątki: potwierdzony
  sukces zapisu, zgodność przy „Sprawdź stan”, udany odczyt po „Odrzuć moje zmiany i pokaż stan z serwera”). Brak
  odpowiedzi przez 10 s, błąd sieci i każde 5xx to „nieznany wynik” zapisu, nigdy komunikat, że zapis się nie powiódł;
  limit nie przerywa żądania i panel niczego nie ponawia sam. Tę logikę ma jeden wspólny kod, bez kopii w formularzach:
  `src/composables/useFormSafety.ts` (stan żądań formularza: zapis, „Sprawdź stan”, odczyty z limitem), `formRequests.ts`
  (limit, teksty, klasyfikacja odpowiedzi, porównanie żądań) i komponent `FormSafetyNotices` (komunikaty i przyciski);
  wzorem jest `usePromotionForm`. Nowy formularz używa ich, nie pisze własnych limitów ani odczytów po 409.
- Lista według standardu: `PageHeader`, karta z `ListToolbar`, `DataTable` i `ListPager`; stan listy (wyszukiwanie, filtry,
  rozmiar strony 10/25/50, domyślnie 25, w Cenach i Stanach 10) jest w adresie przez `useListUrlState`,
  stronicowanie przez `useCursorPager`, a
  pozycja w stronicowaniu tylko w pamięci. Wzorem jest `ProductsView`. Ukrywanie kolumn to klasy CSS na progach 1024 i 900 px
  (`hideBelow`), bez modelu tabeli. Kolejność wybiera `Select` „Sortuj” w `ListToolbar` (lista produktów, cen, stanów,
  zamówień i klientów; klucze API, `sortParam` i `ariaSortOf` z `src/composables/listSort.ts`), jest w adresie jako `sort`
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
- Testy `useCursorPager` i `useListUrlState` używają routera w pamięci; `tests/ui-accessible-names.test.ts` sprawdza
  nazwę „Zamknij” okien i brak angielskich nazw dostępnych w `src/components/ui/`.
- Sentry (`tests/sentryFilter.test.ts`, `tests/sentry.test.ts`, `tests/sentryTracing.test.ts`, `tests/config.test.ts`, `tests/vite-config.test.ts` (konfiguracja wysyłki map); nawigacje routera z `code`, `state` i `q` w `sentryTracing.test.ts` na fałszywych zegarach, bo spany nawigacji kończą się po bezczynności) bez konta
  i bez ruchu do Sentry: prawdziwe SDK z fałszywym DSN i transportem testowym zbierającym koperty (opcja `transport` w trzecim
  argumencie `initSentry`). SDK opakowuje `fetch`, `history`, `console` i `window.onerror` raz na stronę, więc `fetch` testu jest
  podmieniony raz przed pierwszym `init` (`vi.stubGlobal`), a testy śladów są w osobnym pliku z jednym klientem (próbkowanie 1).
  Wywołania sieci w testach idą przez wygenerowanego klienta (ESLint blokuje globalny `fetch`). Czyszczenie po teście: tylko
  zakres izolacji (`getIsolationScope().setUser(null)`), bo użytkownik „wyczyszczony” na bieżącym zakresie nadpisuje id w kolejnych zdarzeniach.
- `tests/tokens.test.ts` czyta `tokens.css` i sprawdza kontrast każdej pary tokenów; `tests/fonts.test.ts` sprawdza
  pliki Nunito, ich deklaracje i sumy SHA-256 w `THIRD_PARTY_NOTICES.md`.
