# Audyt funkcjonalny: konta klientów, panel, raporty, integracje, i18n, B2B, zgodność prawna

Rewizja: `1aeb5076` (origin/main, 2026-11-18). Metoda: odczyt kodu (domena → endpoint OpenAPI → ekran), bez uruchamiania
stosu. Ścieżki względne od korzenia repo. Statusy: KOMPLETNA / CZĘŚCIOWA / ZAPLANOWANA / BRAK. Znaczenie braku:
BLOKER (uruchomienie sklepu) / ISTOTNE (rozwój) / OPCJONALNE / NIEWARTE (teraz).

Najważniejszy fakt kontekstowy: całe API to **27 operacji publicznych/klienta** (`apps/api/openapi.json`) i **31 operacji
panelu** (`apps/api/openapi-admin.json`). Nie ma w nich żadnego endpointu dla: profilu klienta poza odczytem `/me`, adresów,
pracowników, ustawień sklepu, eksportu, importu, webhooków wychodzących, raportów poza pulpitem. Moduł `Customer`
w API to 5 plików: `MeController`, `MeResponse`, `AuthenticatedCustomer`, `CustomerUser(Provider)` (`apps/api/src/Customer/`).
Zakres wizji „Poza zakresem (na razie)”: wiele walut i języków, prawdziwi dostawcy płatności, kurierzy, faktury,
marketplace (`docs/vision.md:383-385`).

---

## 1. Konta klientów

| Funkcja | Status | Co działa / czego brakuje | Dowód | Znaczenie braku |
|---|---|---|---|---|
| Rejestracja | CZĘŚCIOWA | Samoobsługowa w Keycloaku (realm `shop`: `registrationAllowed: true`, e-mail jako login, `verifyEmail: true`). Strona rejestracji to domyślny ekran Keycloaka bez motywu sklepu (`loginTheme` brak). **W produkcji wyłączona**: `customerAccounts: false` ustawia `registrationAllowed` w realmie i chowa link „Zaloguj się” w sklepie. Brak checkboxów regulaminu/zgód na formularzu rejestracji (Keycloak bez „Terms and Conditions” required action). | `infra/keycloak/realms/shop.json`; `infra/k8s/environments/prod/values.yaml:20-23`; `infra/k8s/charts/shop/templates/_keycloak.tpl:26-29`; `apps/store/src/account/AccountMenu.tsx:40-43` | ISTOTNE (konta działają lokalnie; włączenie to przełącznik, ale brak brandingu i akceptacji regulaminu przy rejestracji) |
| Logowanie (OIDC, Keycloak) | KOMPLETNA | BFF sklepu: `/auth/login` (PKCE, limit 30/10 min/IP w Redisie), `/auth/callback`, `/auth/logout`, `/auth/session`, sesja w Redisie, odświeżanie tokenu, „zapamiętaj mnie”, ochrona brute force w realmie. Brak logowania społecznościowego (`identityProviders: []`), choć wizja je przewiduje. | `apps/store/app/auth/*/route.ts`; `apps/store/src/auth/{oidc,refresh,session-store,login-rate-limit}.ts`; `docs/vision.md:85` | OPCJONALNE (social login) |
| Reset hasła, weryfikacja e-mail | KOMPLETNA (Keycloak) | `resetPasswordAllowed: true`, SMTP realmu (lokalnie Mailpit, w prod Brevo). Szablony e-maili domyślne Keycloaka, bez marki sklepu (`emailTheme` brak). | `infra/keycloak/realms/shop.json`; `infra/k8s/charts/shop/values.yaml:106-113` | OPCJONALNE (branding) |
| Profil klienta (imię, e-mail, telefon) w sklepie | BRAK | API daje tylko odczyt `GET /api/v1/me` (`id`, `email`, `name` z tokenu). W sklepie nie ma strony profilu: trasy `/konto` to tylko `zamowienia` i `zamowienia/[orderId]`; menu konta to ikony „Moje zamówienia” i „Wyloguj”. Jedyna droga zmiany danych/hasła: domyślna konsola konta Keycloaka (`/realms/shop/account`, ścieżka `/realms/shop/` jest wystawiona w ingressie), do której sklep nie linkuje. | `apps/api/src/Customer/UI/Http/MeController.php`; `apps/store/app/konto/`; `apps/store/src/account/AccountMenu.tsx:58-74`; `infra/k8s/charts/shop/templates/ingress.yaml:13` | ISTOTNE (klient nie zmieni danych z poziomu sklepu) |
| Książka adresowa | BRAK | Nie istnieje ani w API, ani w sklepie. Wizja zapowiada „Customer (profil, adresy)” — niezrealizowane. Zalogowany klient przy każdym zamówieniu wpisuje adres od nowa; checkout wypełnia tylko imię i nazwisko z sesji (`prefilled`). Adresy „z ostatnich zamówień” widzi tylko obsługa w panelu (model odczytu `ordering_customer`). | `apps/store/src/checkout/CheckoutPage.tsx:416-418`; `docs/vision.md:92`; `apps/api/openapi.json` (brak `/me/addresses`) | ISTOTNE (główna korzyść z konta dla klienta) |
| Historia zamówień | KOMPLETNA | `GET /api/v1/me/orders` (kursor), lista w `/konto/zamowienia` z „Pokaż więcej”, stany loading/error/retry. | `apps/store/src/account/OrdersPage.tsx`; `apps/store/app/api/account/orders/route.ts` | – |
| Szczegóły zamówienia klienta | KOMPLETNA (tylko odczyt) | Pozycje, rabat, sumy, adres, daty statusów (paid/shipped/delivered/cancelled/returned), ponowienie płatności (`OrderPaymentNote`). Brak akcji klienta: anulowanie, odstąpienie/zwrot, reklamacja, faktura do pobrania, numer przesyłki. | `apps/store/src/account/OrderDetailPage.tsx`; `apps/store/src/account/OrderPaymentNote.tsx`; schemat `CustomerOrder` w `openapi.json` | BLOKER (prawo odstąpienia musi mieć ścieżkę; patrz obszar 7) |
| Status zamówienia gościa | CZĘŚCIOWA | Gość widzi zamówienie tylko przez ciasteczko potwierdzenia ważne 60 min; potem brak strony „sprawdź status zamówienia” (numer + e-mail). E-maile w prod idą do Mailpita w klastrze (`orderMail: mailpit`), więc gość nie dostaje nawet potwierdzenia. | `apps/store/src/checkout/cookies.ts:9`; `infra/k8s/environments/prod/values.yaml:23` | BLOKER (w trybie prod brak jakiegokolwiek potwierdzenia dla gościa; po przełączeniu na Brevo – ISTOTNE) |
| Usunięcie konta / anonimizacja (RODO art. 17) | BRAK | Brak endpointu, komendy i ekranu. Spec panelu wprost wyłącza: „RODO: usunięcie konta klienta i anonimizacja … osobne zgłoszenie razem z #119”. Wizja zakłada anonimizację – nie ma kodu. Keycloak account console nie ma włączonej akcji „delete account” (domyślnie wyłączona). | `docs/features/admin-features/spec.md:70-71, 506-507`; `docs/vision.md:88` | BLOKER (prawny obowiązek obsłużenia żądania; minimum: procedura ręczna + narzędzie anonimizacji) |
| Eksport danych klienta (RODO art. 15/20) | BRAK | Brak. | jw. | ISTOTNE (można obsłużyć ręcznie przy małej skali) |
| Zgody marketingowe / newsletter | BRAK | Zero wystąpień `consent`/`newsletter` w API, sklepie i panelu. | grep po `apps/` | ISTOTNE (marketing), nie bloker |
| Komunikacja z klientem (e-mail transakcyjny) | CZĘŚCIOWA | 5 rodzajów wiadomości przez outbox: potwierdzenie, płatność przyjęta, anulowanie, wysłane, zwrot przyjęty. Tylko tekst (`->text()`), treść na sztywno w klasach PHP, bez edycji w panelu, bez danych sprzedawcy, bez pouczenia o odstąpieniu. Brak: „dostarczono”, „zwrot pieniędzy wykonany”, przypomnienie o płatności. W prod nie wychodzą (Mailpit). | `apps/api/src/Ordering/Application/{OrderConfirmationText,OrderShippedText,...}.php`; `NotificationKind.php:13-17`; `apps/api/src/Ordering/Infrastructure/Mail/` | BLOKER (potwierdzenie na trwałym nośniku z pełną treścią to wymóg prawa konsumenckiego) |
| Wiadomości/czat/zgłoszenia klienta | BRAK | Nie ma formularza kontaktowego ani wątków. | – | OPCJONALNE (e-mail zewnętrzny wystarczy) |
| Grupy klientów / segmenty | BRAK | Brak pojęcia grupy; promocje i kody nie mają warunku na klienta. | `apps/api/openapi-admin.json` (schematy Promotion/DiscountCode) | ISTOTNE dla B2B, OPCJONALNE dla B2C |
| Widok klienta w panelu | CZĘŚCIOWA (tylko odczyt) | Lista (`admin`, `support`): szukanie, sortowanie (ostatnie zamówienie, nazwa, liczba, wartość), licznik do 10 000, kursor. Karta: kontakt z ostatniego zamówienia, podsumowanie (liczba, opłacone, wartość, pierwsze/ostatnie), adresy z ostatnich zamówień, zamówienia klienta. **Tylko konta z ≥1 zamówieniem; goście i konta bez zamówień niewidoczne** (API nie pyta Keycloaka). Obsługa nie może: edytować danych, zablokować konta, wysłać resetu hasła, dodać notatki, zanonimizować, złożyć zamówienia w imieniu klienta. | `apps/admin/src/views/CustomerDetailView.vue:139,173,226`; `docs/vision.md:99-103`; `docs/features/admin-features/spec.md:431` (P11) | ISTOTNE (obsługa klienta musi wchodzić do konsoli Keycloaka) |

## 2. Panel administracyjny i codzienna praca

| Funkcja | Status | Co działa / czego brakuje | Dowód | Znaczenie braku |
|---|---|---|---|---|
| Logowanie personelu | KOMPLETNA | Realm `backoffice`, PKCE, obowiązkowe TOTP (flow `browser-otp`), sesja bezczynności 15 min + wylogowanie po bezczynności w panelu. W prod panel za Cloudflare Access. | `infra/keycloak/realms/backoffice.json:19-64`; `apps/admin/src/composables/useIdleLogout.ts`; `docs/vision.md:345` | – |
| Role i uprawnienia | KOMPLETNA (stałe) | 4 role na sztywno, sprawdzane voterami w API i ukrywane w menu. Macierz: katalog/kategorie/promocje/kody – `admin`, `marketing`; ceny – tylko `admin`; stany – `admin`, `warehouse`; zamówienia (odczyt) – `admin`, `support`, `warehouse`; wysyłka/dostarczenie – `admin`, `warehouse`; anulowanie/zwrot/zwrot pieniędzy/klienci – `admin`, `support`; sprzedaż na pulpicie i „Stan systemu” – tylko `admin`. Konto bez roli: 403 wszędzie poza `/admin/me`. Brak ról definiowanych przez sklep i uprawnień granularnych. | `apps/api/src/*/Infrastructure/Security/*Voter.php` (np. `OrderVoter.php:32-62`, `PriceVoter.php:31`); `apps/admin/src/*/permissions.ts`; `docs/features/admin-features/spec.md:73-82` | OPCJONALNE (4 role wystarczą małemu sklepowi) |
| Zarządzanie pracownikami | BRAK (w panelu) | Pierwszy admin: `make -C infra/k8s create-admin` (kubectl, wysyła e-mail z ustawieniem hasła i TOTP). Każdy kolejny pracownik, zmiana roli, dezaktywacja: wyłącznie konsola administracyjna Keycloaka (realm `master`, niewystawiona w ingressie – tylko przez port-forward). Spec: „zarządzanie kontami pracowników” poza zakresem. | `infra/k8s/Makefile:81-87`; `infra/k8s/charts/shop/templates/ingress.yaml:13` (tylko `/realms/shop/`, `/realms/backoffice/`); `docs/features/admin-features/spec.md:504` | BLOKER dla nietechnicznego właściciela (nie doda pracownika bez admina IT) |
| Ustawienia sklepu (dane firmy, stawki VAT, dostawa, płatności, e-maile) | BRAK | Nie ma żadnego ekranu ani endpointu ustawień. Nazwa sklepu w kodzie (`apps/store/src/site.ts`: `STORE_NAME = "Worf"`), stawki VAT w kodzie i oznaczone jako „EXAMPLES … not verified by an accountant”, termin płatności 30 min na sztywno, próg niskiego stanu 5 szt. na sztywno, metody płatności to dwa symulatory w enumie, metod dostawy brak w ogóle, treści e-maili w PHP, przełączniki trybu w wartościach Helma. | `apps/api/src/Pricing/Domain/Tax/PolishVatRates.php:8-12`; `apps/api/src/Ordering/Domain/Order.php:31`; `apps/api/src/Inventory/Domain/Stock/AvailabilityLevel.php:13`; `apps/api/src/Payment/Domain/ProviderCode.php:12-13`; `docs/features/admin-features/spec.md:504` | BLOKER (każda zmiana biznesowa = wdrożenie kodu) |
| Audit log działań | CZĘŚCIOWA | Rozproszony: historia statusów zamówienia z pracownikiem i notatką, kto wykonał zwrot pieniędzy, historia cen wariantu, historia ruchów stanu. Brak: wspólnego dziennika, historii zmian produktu/kategorii/promocji/kodów, logowań personelu w panelu. | `BackofficeOrderStatusChange` (`staffUsername`, `note`), `/admin/variants/{id}/price-history`, `/admin/variants/{id}/stock-history` w `openapi-admin.json`; `docs/features/admin-features/spec.md:503` („dziennik zmian” poza zakresem) | ISTOTNE |
| Wyszukiwarka globalna | BRAK | Pole w topbarze szuka tylko produktów (przekierowanie na `/products?q=`). Ctrl+K – poza zakresem. Zamówienia szukane osobno (fragment ≥3 znaków w numerze/e-mailu/nazwisku). | `apps/admin/src/layout/AppTopbar.vue` (`router.push({ name: "products" ...})`); `docs/features/admin-features/spec.md:428, 504` | OPCJONALNE |
| Operacje masowe | BRAK | Brak zaznaczania wierszy i akcji masowych (publikacja, zmiana cen, wysyłka wielu zamówień). | `docs/features/admin-features/spec.md:503`; brak `checkbox`/`bulk` w `apps/admin/src` | ISTOTNE (przy >kilkudziesięciu zamówieniach dziennie) |
| Filtry list / zapisane widoki | CZĘŚCIOWA | Filtry: zamówienia – status, zwrot (do wykonania/przeterminowany), zakres dat, szukanie, sortowanie, klient; produkty – kategoria, status, bez zdjęć; stany – poziom (brak/niski), kategoria; stan filtrów w URL (można dodać zakładkę w przeglądarce). Zapisanych widoków brak. | `openapi-admin.json` parametry `/admin/orders`, `/admin/products`, `/admin/stock`; `apps/admin/src/composables/useListUrlState.ts` | OPCJONALNE |
| Skróty klawiaturowe | BRAK | Tylko Enter w dialogach. | `apps/admin/src/prices/PriceDialogs.vue:255` | NIEWARTE teraz |
| Powiadomienia o nowych zamówieniach | CZĘŚCIOWA | Pulpit odpytuje API co 30 s (kolejki „Opłacone do wysłania”, „Czekają na płatność”, „Zwroty do wykonania”, stany niskie/brak, produkty bez zdjęć; tabela ostatnich zamówień). Brak e-maila/push/dźwięku do sprzedawcy; e-maile zamówień idą zawsze tylko do klienta. | `apps/admin/src/dashboard/useDashboard.ts:27-75`; `apps/admin/src/dashboard/todo.ts` | ISTOTNE (e-mail „nowe zamówienie” do sklepu to standard) |
| Obsługa zamówienia | CZĘŚCIOWA | Szczegóły (klient, adres, płatność, pozycje z promocją, historia, zwrot), przejścia `ship`/`deliver`/`cancel` (tylko opłacone)/`return` z notatką i wersją (optimistic lock), ręczne oznaczenie zwrotu pieniędzy jako wykonanego (przelew poza systemem). Brak: edycji zamówienia i adresu, częściowej realizacji/zwrotu, numeru przesyłki, druku dokumentów/listy kompletacyjnej, notatek wewnętrznych, ręcznego zamówienia (telefonicznego). | `BackofficeOrderTransition` w `openapi-admin.json`; `apps/api/src/Ordering/Domain/Refund.php:8-10`; `apps/admin/src/views/OrderDetailView.vue:358-684` | BLOKER: numer przesyłki/wysyłka (z obszaru dostaw); reszta ISTOTNE |
| Wielosklepowość | BRAK | Jeden sklep, brak pojęcia kanału/sklepu w modelu. | brak `channel`/`store_id` w API | NIEWARTE teraz |
| Stan systemu | KOMPLETNA (dla admina IT) | Sondy usług, outbox, kolejki RabbitMQ, zgodność indeksu wyszukiwania. | `apps/admin/src/views/SystemView.vue`; `apps/api/src/Backoffice/` | – (dla właściciela sklepu mało przydatne, patrz „Nadmiarowe”) |
| Panel na telefonie | CZĘŚCIOWA | Lista i szczegóły zamówień mają układ mobilny; pozostałe ekrany tylko reflow 320 px. | `docs/features/admin-screens/spec.md:481-482` | OPCJONALNE |

## 3. Raportowanie, analityka, eksport

| Funkcja | Status | Co działa / czego brakuje | Dowód | Znaczenie braku |
|---|---|---|---|---|
| Pulpit sprzedaży | CZĘŚCIOWA | Tylko `admin`: przychód brutto, liczba zamówień, AOV i zmiana % dla „Dziś” (vs wczoraj do tej samej godziny), 7 dni i 30 dni (vs poprzedni okres); tylko zakończone godziny. Przychód = zamówienia opłacone wg `paid_at`, **bez odejmowania anulowań i zwrotów**. Dane z prekomputowanej tabeli godzinowej `ordering_sales_hour` (16 shardów). Brak wykresu w czasie, dowolnego zakresu dat, netto/VAT. | `apps/api/src/Ordering/Application/Query/{GetSalesSummaryHandler,SalesPeriods}.php`; `docs/features/admin-features/spec.md:151-170, 423` | ISTOTNE (sprzedaż brutto bez zwrotów może wprowadzać w błąd) |
| Raporty per produkt / kategoria / okres | BRAK | „Najczęściej kupowane”, wykresy – poza zakresem. | `docs/features/admin-features/spec.md:506` | ISTOTNE |
| Raport VAT / do księgowości | BRAK | Brak zestawienia netto/VAT per stawka i okres. | – | BLOKER (dla księgowości trzeba to wyciągać SQL-em) |
| Raporty magazynowe | CZĘŚCIOWA | Lista stanów z filtrem „brak”/„niski” (próg 5 na sztywno), historia ruchów per wariant, liczniki na pulpicie. Brak wartości magazynu, rotacji, raportu do inwentaryzacji. | `/admin/stock?level=`, `/admin/variants/{id}/stock-history`; `AvailabilityLevel.php:13` | OPCJONALNE |
| Eksport CSV/XLSX (zamówienia, produkty, klienci) | BRAK | Ani endpointu, ani przycisku. „Eksport CSV” jest w ADMIN-TOOLS (niezaplanowany plan). | `docs/features/admin-features/spec.md:503-504`; brak `csv` w `apps/admin/src` | BLOKER praktyczny (księgowość/kurier potrzebują eksportu zamówień, gdy nie ma integracji) |
| GA4 / analityka / piksele | BRAK | Zero wystąpień `gtag`/GTM; brak też banera cookies (spójne: brak analityki = brak potrzeby zgody, ale brak informacji o cookies sesyjnych). | grep po `apps/store` | ISTOTNE (marketing bez pomiaru konwersji) |
| Liczniki list | KOMPLETNA (z limitem) | Liczba wyników do 10 000, na pulpicie do 1000, liczba produktów z szacunku `reltuples`. | `docs/features/admin-features/spec.md:418-419` | – |

## 4. Integracje i rozszerzalność

| Funkcja | Status | Co działa / czego brakuje | Dowód | Znaczenie braku |
|---|---|---|---|---|
| Kontrakt OpenAPI | KOMPLETNA (wewnętrzna) | Dwa dokumenty OpenAPI 3.0.3 w repo, generowane z kodu, klienci TS generowani; błędy w formacie problem details; kursorowa paginacja. **Brak publicznie dostępnej dokumentacji** („No Swagger UI or JSON routes are exposed”). | `apps/api/config/packages/nelmio_api_doc.yaml:1`; `apps/api/openapi*.json` | ISTOTNE dla headless |
| Wersjonowanie API | CZĘŚCIOWA | Prefiks `/api/v1`, `info.version 1.0.0`; brak polityki deprecjacji/changelogu (kontrakt zmienia się razem z frontami w jednym PR). | `openapi.json` `info` | OPCJONALNE |
| Klucze API / tokeny dla integracji | BRAK | Jedyny klient realmu `backoffice` to publiczny `admin` (PKCE, `serviceAccountsEnabled: false`); realm `shop` – poufny `store` (BFF). Integracja maszyna-maszyna wymaga ręcznego założenia klienta w konsoli Keycloaka i mapowania roli + audience `backoffice-api`. Nie ma ekranu „klucze API”. | `infra/keycloak/realms/backoffice.json:103-107`; `shop.json:36-42`; `apps/api/config/packages/security.yaml` (audience `backoffice-api`/`shop-api`) | ISTOTNE (bloker dla jakiejkolwiek integracji ERP/BaseLinker) |
| Webhooki wychodzące | BRAK | Tylko przychodzące webhooki płatności (`POST /api/v1/payments/webhooks/{provider}`). | `openapi.json` | ISTOTNE |
| Zdarzenia domenowe na zewnątrz | CZĘŚCIOWA (wewnętrzne) | Transactional outbox → RabbitMQ (`events`), ale serializer `symfony_serializer` z klasami PHP, bez opisanego schematu (AsyncAPI) i bez dostępu z zewnątrz klastra. To magistrala wewnętrzna, nie produkt dla integratora. | `apps/api/config/packages/messenger.yaml:29-51,107-113`; `apps/api/src/Shared/Infrastructure/Messaging/` | ISTOTNE (dobra baza pod webhooki) |
| System wtyczek | BRAK | Brak mechanizmu rozszerzeń (monolit Symfony; rozszerzenie = fork kodu). | – | NIEWARTE teraz (dla własnego sklepu); ISTOTNE gdyby to miała być platforma |
| Import danych (CSV produktów/cen/stanów) | BRAK | Tylko seedy/generator demo (`make seed`, `make seed-scale`, CLI) – narzędzia deweloperskie, nie dla sklepu. | `docs/vision.md:293-319`; `docs/features/admin-features/spec.md:505` | BLOKER przy realnym asortymencie (ręczne wprowadzanie setek produktów) |
| ERP / księgowość / Allegro / BaseLinker / porównywarki / feed Google Merchant | BRAK | Zero wystąpień. Sitemapy są, feed produktowy nie. | grep `allegro|baselinker|ceneo|merchant` w `apps/` | ISTOTNE (BaseLinker lub feed zwykle pierwszym krokiem PL) |
| E-mail marketing (Mailchimp, FreshMail…) | BRAK | Brak zgód i synchronizacji. | – | OPCJONALNE |
| Płatności | CZĘŚCIOWA (architektura gotowa) | Port dostawcy z dwoma symulatorami (`demo-transfer`, `demo-card`), podpisane webhooki, deduplikacja, ponowienie płatności. Brak P24/PayU/Stripe/BLIK i zwrotu przez operatora. (Poza moim zakresem – tylko odnotowane.) | `apps/api/src/Payment/Domain/ProviderCode.php:12-13` | BLOKER |

## 5. Wielojęzyczność, wielowalutowość, sprzedaż międzynarodowa, wielokanałowość

| Funkcja | Status | Co działa / czego brakuje | Dowód | Znaczenie braku |
|---|---|---|---|---|
| Wiele języków | BRAK | `lang="pl"`, teksty w komponentach, realm `supportedLocales: ['pl']`, produkty bez tłumaczeń. Wizja: poza zakresem. | `apps/store/app/layout.tsx`; `infra/keycloak/realms/shop.json`; `docs/vision.md:385` | OPCJONALNE na start PL, ISTOTNE przy ekspansji |
| Wiele walut | BRAK | `Currency` ma tylko `PLN`. | `apps/api/src/Ordering/Domain/Currency.php:12` | OPCJONALNE |
| Kraje dostawy | BRAK | Adres dostawy zawsze `PL` (stała `POLAND`), pole kraju nie istnieje w checkoucie. | `apps/api/src/Ordering/Domain/ShippingAddress.php:15,79` | OPCJONALNE na start |
| VAT OSS / stawki per kraj | BRAK | Jedna tabela stawek PL (przykładowa). | `PolishVatRates.php` | NIEWARTE teraz (tylko PL) |
| Kanały sprzedaży / marketplace / POS | BRAK | Brak pojęcia kanału. | – | NIEWARTE teraz |

## 6. B2B i automatyzacje

| Funkcja | Status | Co działa / czego brakuje | Dowód | Znaczenie braku |
|---|---|---|---|---|
| Zakup na firmę (NIP, nazwa firmy, faktura VAT) | BRAK | Checkout: e-mail, imię i nazwisko, telefon, ulica, kod, miasto. Brak NIP i faktury. | `apps/store/src/checkout/CheckoutPage.tsx:44-51`; schematy `PlaceGuestOrderRequest`/`PlaceCustomerOrderRequest` | BLOKER (w PL klient firmowy oczekuje faktury z NIP; od 2026 KSeF) |
| Konta firmowe, wielu użytkowników firmy | BRAK | – | – | NIEWARTE teraz (B2C) |
| Ceny indywidualne / cenniki grupowe | BRAK | Cena per wariant, jedna dla wszystkich. | `PUT /admin/variants/{id}/price` | OPCJONALNE |
| Przelew z terminem / płatność odroczona / za pobraniem | BRAK | Termin płatności 30 min, potem automatyczne anulowanie. | `apps/api/src/Ordering/Domain/Order.php:31` | ISTOTNE (pobranie i tradycyjny przelew są popularne w PL) |
| Zapytania ofertowe (RFQ) | BRAK | – | – | NIEWARTE teraz |
| Reguły automatyzacji (workflow) | BRAK | Jedyne automaty: wygaszenie nieopłaconego zamówienia (opóźniona komenda), granice czasowe promocji, czyszczenie koszyków (CLI). | `messenger.yaml:107-113`; `apps/api/src/Cart/UI/Console/PurgeExpiredCartsCommand.php` | OPCJONALNE |
| Zadania cykliczne | CZĘŚCIOWA (techniczne) | Brak Symfony Scheduler; cykliczność to opóźnione komunikaty i CronJoby w chartcie (kopie). Niewidoczne i niekonfigurowalne dla sklepu. | `apps/api/src` (brak `AsCronTask`) | NIEWARTE |

## 7. Zgodność prawna widoczna w produkcie (PL/UE, B2C)

| Funkcja | Status | Co działa / czego brakuje | Dowód | Znaczenie braku |
|---|---|---|---|---|
| Regulamin, polityka prywatności, zwroty/reklamacje, dostawa – strony | BRAK | Brak tras treści (app router: `/`, `/c`, `/p`, `/szukaj`, `/cart`, `/checkout`, `/checkout/confirmation`, `/konto/zamowienia`). Brak CMS. | `apps/store/app/**/page.tsx` | BLOKER |
| Dane sprzedawcy w stopce (firma, adres, NIP, kontakt) | BRAK | Stopka: wordmark, linki działów, „© rok Worf · Zdjęcia: Unsplash”. | `apps/store/src/catalog/SiteFooter.tsx:36-40` | BLOKER |
| Checkboxy akceptacji regulaminu / klauzula RODO w checkoucie | BRAK | Formularz bez żadnej zgody; jest poprawny przycisk „Zamawiam z obowiązkiem zapłaty”. | `apps/store/src/checkout/CheckoutPage.tsx:514-541` | BLOKER |
| Informacja o prawie odstąpienia (14 dni), formularz odstąpienia | BRAK | Ani w sklepie, ani w e-mailu potwierdzenia; klient nie ma ścieżki zwrotu (zwrot inicjuje tylko pracownik). | `OrderConfirmationText.php:22-47`; `OrderDetailPage.tsx` | BLOKER |
| Potwierdzenie umowy na trwałym nośniku | CZĘŚCIOWA | E-mail z pozycjami, sumą brutto, adresem i terminem płatności; bez danych sprzedawcy, regulaminu, pouczeń, kosztów dostawy; w prod nie wychodzi. | `OrderConfirmationText.php`; `values.yaml:23` | BLOKER |
| Cookie banner | BRAK (obecnie mało potrzebny) | Sklep używa tylko ciasteczek niezbędnych (sesja, koszyk, potwierdzenie); brak analityki. Wymagana co najmniej informacja w polityce prywatności. Po dodaniu GA4 – banner konieczny. | `apps/store/src/auth/cookies.ts`, `apps/store/src/checkout/cookies.ts` | ISTOTNE (gdy pojawi się analityka) |
| Omnibus (najniższa cena 30 dni) | KOMPLETNA | (poza zakresem obszaru – odnotowane jako mocna strona prawna) | `docs/reports/app-audit-2026-11-18/11-kompletnosc-produktu.md:417` (zweryfikować w obszarze cen) | – |
| Akceptacja regulaminu przy rejestracji konta | BRAK | Domyślny formularz Keycloaka. | `shop.json` (brak `TERMS_AND_CONDITIONS`) | ISTOTNE |

---

## Mapa ekranów panelu i sklepu

**Panel (`apps/admin/src/router/index.ts`, menu `apps/admin/src/layout/menu.ts`)**

| Trasa | Ekran | Akcje | Role |
|---|---|---|---|
| `/` | Pulpit | kafle katalogu, „Do obsługi” (kolejki z linkami do przefiltrowanych list), sprzedaż (admin), ostatnie zamówienia, odśwież co 30 s | wszyscy z rolą (sekcje wg roli) |
| `/products`, `/products/new`, `/products/:id` | Produkty, formularz | lista z filtrami, tworzenie/edycja, warianty, zdjęcia, publikacja/wycofanie | odczyt wszyscy, zmiany admin+marketing |
| `/categories` | Drzewo kategorii | dodaj, edytuj, przenieś, usuń pustą (do 4 poziomów) | odczyt wszyscy, zmiany admin+marketing |
| `/prices` | Ceny wariantów | zmiana ceny, historia cen | zmiana tylko admin |
| `/inventory` | Stany | przyjęcie, korekta, historia ruchów, filtr niski/brak | admin+warehouse |
| `/promotions`, `/new`, `/:id` | Promocje | CRUD, harmonogram | admin+marketing |
| `/discount-codes`, `/new`, `/:id` | Kody rabatowe | tworzenie, edycja (bez usuwania w API) | admin+marketing |
| `/orders`, `/orders/:orderId` | Zamówienia | filtry, szczegóły, przejścia statusu, oznaczenie zwrotu pieniędzy; skrót „Zwroty do wykonania” | admin+support+warehouse (akcje wg roli) |
| `/customers`, `/customers/:customerId` | Klienci | lista, karta (tylko odczyt), zamówienia klienta | admin+support |
| `/system` | Stan systemu | sondy, kolejki, outbox, indeks | admin |
| `/auth/callback` | logowanie | – | – |

Brak ekranów: pracownicy, ustawienia, raporty, eksport/import, dziennik zmian, szablony e-maili, metody dostawy/płatności,
strony treści, zgody/RODO, integracje/klucze API.

**Sklep (`apps/store/app`)**: `/` (strona główna), `/c/[...path]` (kategoria), `/p/[slug]` (produkt), `/szukaj`, `/cart`,
`/checkout`, `/checkout/confirmation`, `/konto/zamowienia`, `/konto/zamowienia/[orderId]`, 404, sitemapy. Logowanie,
rejestracja i reset hasła na stronach Keycloaka. Brak: `/konto` (profil), adresów, stron prawnych, kontaktu, statusu
zamówienia gościa, listy życzeń.

## Gotowość headless

Co integrator dostaje dziś:
- REST `/api/v1` z kontraktem OpenAPI 3.0.3 w repo (osobno publiczny/klient i panel), generowanym z kodu i testowanym;
  spójne błędy problem details z polami naruszeń; kursorowa paginacja; `Idempotency-Key` na zamówieniu, płatności
  i przyjęciu; optimistic locking (`version`) w panelu; rate limiting; cache Varnish z tagami na odczytach publicznych.
- Storefront API wystarcza do alternatywnego frontu: katalog z fasetami, produkt, kategorie, koszyk gościa i klienta
  z merge, checkout gościa/klienta, płatności, historia zamówień.
- Admin API obejmuje katalog, zdjęcia, ceny, stany, promocje, kody, zamówienia (odczyt + statusy), klientów (odczyt).
- Auth: standardowy OIDC (Keycloak), dwa realmy, JWT RS256 z audience.

Czego brakuje względem Medusa/Saleor/Sylius:
- Brak publicznej dokumentacji (Swagger UI/Redoc) i SDK poza wewnętrznymi klientami TS.
- Brak tokenów integracyjnych (client credentials / API keys) z poziomu panelu; integracja = ręczna konfiguracja Keycloaka.
- Brak webhooków wychodzących i subskrypcji zdarzeń (Saleor: webhooki + aplikacje; Medusa: subscribers + moduły;
  Sylius: zdarzenia + pluginy). Outbox → RabbitMQ istnieje, ale jest wewnętrzny i nieudokumentowany.
- Brak systemu rozszerzeń (moduły/pluginy/aplikacje), brak pól niestandardowych/metadanych na encjach.
- Brak API: klientów (zapis), adresów, ustawień, kanałów, dostaw, podatków, regionów, walut, operacji masowych,
  importu/eksportu, tłumaczeń.
- Brak GraphQL (świadoma decyzja REST – nie wada sama w sobie).
- Alternatywny klient frontowy/mobilny potrzebuje własnego klienta OIDC w realmie `shop` (jedyny `store` jest poufny, BFF).
Ocena: solidne „API własnego sklepu” z dobrym kontraktem, ale nie platforma headless – brak warstwy rozszerzalności
i integracji, która jest istotą Medusa/Saleor.

## Mocne strony

- Rzetelny łańcuch domena → API → panel dla tego, co istnieje: każda akcja w panelu sprawdzana voterem w API, nie tylko
  ukrywana (`*Voter.php`, `security.yaml` z `ROLE_STAFF`).
- Separacja klientów i personelu (dwa realmy, dwa firewalle, audience), obowiązkowe TOTP dla personelu, idle logout.
- Sensowny model ról odpowiadający realnym działom (admin/obsługa/magazyn/marketing), z rozdzieleniem wysyłki (magazyn)
  od anulowań i zwrotów (obsługa).
- Pulpit zorientowany na pracę („Do obsługi” z liczbą i linkiem do przefiltrowanej listy), filtry w URL, stany
  loading/empty/error, toasty, komunikaty po polsku, dostępność (aria-live, focus).
- Pełna historia statusów zamówienia z pracownikiem i notatką, historia cen i ruchów magazynowych – podstawa audytu.
- Kolejka zwrotów pieniędzy z terminem i statusem przeterminowania (`refund=overdue`).
- Transakcyjne e-maile przez outbox (nie zgubią się przy awarii), konto klienta z historią zamówień i ponowieniem płatności.
- Przełączniki trybu demo (rejestracja, poczta, pasek) w konfiguracji wdrożenia, bez przebudowy obrazów.

## Niewygodne w codziennym użyciu

- Dodanie pracownika, zmiana roli, blokada – tylko konsola Keycloaka przez port-forward (`infra/k8s`), poza panelem.
- Każda zmiana „ustawienia” (stawka VAT, termin płatności, próg niskiego stanu, nazwa sklepu, treść e-maila) = zmiana kodu
  i wdrożenie.
- Zalogowany klient wpisuje adres przy każdym zamówieniu; nie zmieni danych w sklepie.
- Obsługa klienta nie zobaczy gościa ani konta bez zamówień w „Klientach”; nie poprawi adresu w zamówieniu, nie doda
  notatki wewnętrznej, nie wyśle resetu hasła.
- Brak eksportu: każde zestawienie dla księgowości/kuriera wymaga dostępu do bazy.
- Wysyłka zamówień pojedynczo, bez numeru przesyłki i bez druku; anulować można tylko zamówienie opłacone (nieopłacone
  czeka na automatyczne wygaśnięcie po 30 min).
- Zwrot pieniędzy to ręczny przelew poza systemem + ręczne oznaczenie w panelu.
- Przychód na pulpicie nie odejmuje anulowań i zwrotów (sprzedaż brutto), bez netto/VAT – mylące dla właściciela.
- Wyszukiwarka w topbarze szuka tylko produktów, choć pracownik obsługi częściej szuka zamówienia/klienta.
- Brak powiadomienia e-mail o nowym zamówieniu dla sklepu.

## Nadmiarowe (względem funkcji, w moim zakresie)

- Sprzedaż na pulpicie liczona z tabeli godzinowej z 16 shardami „żeby równoległe płatności nie czekały na jeden wiersz”
  (`docs/features/admin-features/spec.md:167-169`) – przy braku podstawowych raportów i eksportu to optymalizacja
  pod skalę, której sklep nie ma, zamiast zakresu dat i wykresu.
- Liczniki ograniczone do 1000/10 000 i liczba produktów z `reltuples` (spec A3) – uzasadnione przy 10 mln produktów,
  dla realnego sklepu z tysiącami pozycji to kompromis dokładności bez korzyści.
- Ekran „Stan systemu” (sondy 8 usług, outbox, kolejki AMQP, synchronizacja indeksu) w panelu sklepu – narzędzie
  operatora infrastruktury, nie właściciela; powstał wcześniej niż ekran ustawień czy pracowników.
- Model odczytu `ordering_customer` budowany pod 1 mln klientów, a jednocześnie brak podstawowej edycji klienta.
- Dopracowana obsługa przypadków brzegowych (zmiana czasu w „Dziś”, porównania równej długości godzinowej) przy braku
  raportów, które właściciel faktycznie potrzebuje.

## Do sprawdzenia w przeglądarce

1. worf.pl: czy brak linku logowania i czy `https://auth.worf.pl/realms/shop/protocol/openid-connect/registrations`
   (lub ekran rejestracji Keycloaka) jest rzeczywiście wyłączony w prod.
2. Czy `https://auth.<domena>/realms/shop/account` (konsola konta Keycloaka) działa dla klienta – jedyna droga zmiany
   danych/hasła; jak wygląda (motyw domyślny, po angielsku czy polsku).
3. Wygląd ekranów logowania/rejestracji/resetu hasła Keycloaka (branding, język, brak zgód).
4. Checkout: brak checkboxów regulaminu, brak NIP, brak wyboru dostawy i kraju, prefill tylko imienia dla zalogowanego.
5. Stopka i 404: brak stron prawnych i danych sprzedawcy; czy jakikolwiek link do regulaminu istnieje.
6. Gość po 60 min: czy `/checkout/confirmation` jeszcze pokazuje zamówienie; brak strony statusu.
7. Panel (lokalnie, konta `admin`/`support`/`warehouse`/`marketing` z seeda): widoczność menu i akcji per rola,
   np. `support` nie widzi „Wyślij”, `warehouse` nie widzi „Klienci” ani „Anuluj”.
8. Karta klienta: tylko odczyt; czy klient bez zamówień (nowo zarejestrowany) nie pojawia się na liście.
9. Pulpit: czy kafle sprzedaży widzi tylko admin; czy przychód nie maleje po anulowaniu opłaconego zamówienia.
10. Mailpit lokalnie: treść e-maili (tylko tekst, brak danych sprzedawcy i pouczenia o odstąpieniu).
