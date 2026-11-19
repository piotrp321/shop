# Audyt funkcjonalny: koszyk, checkout, zamówienia, płatności, dostawa, zwroty, dokumenty

Rewizja: `1aeb5076` (origin/main, 2026-11-18). Metoda: odczyt kodu (domena → endpoint → ekran), OpenAPI
(`apps/api/openapi.json`, `apps/api/openapi-admin.json`), specyfikacje funkcji, konfiguracja prod. Niczego nie
uruchamiano. Ścieżki względem katalogu repo; `api/` = `apps/api/src/`, `store/` = `apps/store/`, `admin/` = `apps/admin/src/`.

Kontekst nadrzędny: `docs/vision.md:385` „Poza zakresem (na razie)": *prawdziwi dostawcy płatności, integracje
z kurierami, faktury*. Produkt jest świadomie demonstracją domeny („udawani dostawcy", `docs/vision.md:335`), a prod
(`infra/k8s/environments/prod/values.yaml:20-23`) ma `demoNotice: true`, `customerAccounts: false`,
`orderMail: mailpit` (e-maile zamówień nie wychodzą z klastra).

Pełna lista endpointów obszaru (z OpenAPI): sklep – koszyk gościa (`/guest-carts/...`) i klienta (`/me/cart/...`,
`/me/cart/merge`), `POST /orders` (gość), `POST/GET /me/orders`, `GET /me/orders/{id}`, start i status płatności
(`/orders/{id}/payments`, `/me/orders/{id}/payments`, `.../payment`), `GET /payment-providers`,
`POST /payments/webhooks/{provider}`. Panel – `GET /admin/orders`, `GET /admin/orders/{id}`,
`POST /admin/orders/{id}/status-changes`, `POST /admin/orders/{id}/refund-completion`, klienci, pulpit. **Nie ma
żadnego endpointu**: dostawy, przesyłki, faktury, RMA/zwrotu inicjowanego przez klienta, edycji zamówienia, ręcznego
zamówienia, notatki wewnętrznej, eksportu, wydruku, refundacji u dostawcy, anulowania przez klienta.

---

## 1. Koszyk

| Funkcja | Status | Co działa / czego brakuje | Dowód | Znaczenie braku |
|---|---|---|---|---|
| Koszyk gościa | KOMPLETNA | Koszyk w PostgreSQL, id w ciasteczku `store_cart`, BFF Next → API. Dodanie, zmiana ilości, usunięcie. | `api/Cart/UI/Http/GuestCartController.php`, `store/app/api/cart/*`, `store/src/cart/CartPage.tsx` | – |
| Koszyk klienta zalogowanego | KOMPLETNA technicznie, **wyłączona w prod** | `/me/cart/*`; w prod `customerAccounts: false`, więc realnie tylko gość. | `api/Cart/UI/Http/CustomerCartController.php`, `values.yaml:22` | Konta klientów ISTOTNE (powracający klienci), nie BLOKER |
| Scalanie po logowaniu | KOMPLETNA | `MergeGuestCart`: sumy ilości przycinane do 99, pomijanie pozycji ponad 50 linii (`truncated`), komunikat w sklepie; podrobione ciasteczko odrzucane. | `api/Cart/Domain/MergeOutcome.php:7-10`, `store/src/cart/merge.ts:8-11`, `e2e/tests/cart-merge.spec.ts` | – |
| Trwałość | KOMPLETNA | Gość 30 dni, klient 180 dni od ostatniej zmiany; komenda `PurgeExpiredCarts`. | `api/Cart/Domain/Cart.php` (`GUEST_LIFETIME_DAYS = 30`, `CUSTOMER_LIFETIME_DAYS = 180`), `api/Cart/UI/Console/PurgeExpiredCartsCommand.php` | – |
| Walidacja cen i stanów | KOMPLETNA | Ceny z jednej wyceny Pricing przy każdym odczycie (żywe, nie zapamiętane); stan linii: `unavailable_product`, `no_price`, `out_of_stock`, `insufficient_stock`, `purchasable`; linia niemożliwa do kupienia blokuje złożenie. Limity 50 linii / 99 szt. | `api/Cart/Application/View/CartLineState.php`, `docs/features/checkout/spec.md:40` (P1), `api/Cart/Domain/CartLineLimit.php` | – |
| Rezerwacja stanu przy dodaniu | BRAK (świadomie) | Stan rezerwowany dopiero przy złożeniu zamówienia. Standard rynkowy. | `docs/features/cart/spec.md:14-16` | NIEWARTE budowy |
| Kod rabatowy w koszyku | CZĘŚCIOWA | Kod wpisuje się **tylko w kasie** (`CheckoutPage`), nie na stronie koszyka; nie jest zapisywany w koszyku (przeładowanie kasy = kod znika). API przyjmuje kod jako parametr odczytu koszyka (podgląd). | `store/src/checkout/CheckoutPage.tsx:63-68,104-110` (komentarz „D3: a reload of the page starts without one"), `store/src/cart/CartPage.tsx` (brak pola kodu), `docs/features/cart/spec.md:13-14` | OPCJONALNE (UX) |
| Mini-koszyk | BRAK | Tylko licznik sztuk w nagłówku (`CartBadge`) i komunikat „Dodano do koszyka" przy przycisku; brak wysuwanego podglądu. | `store/src/cart/CartBadge.tsx`, `store/src/cart/AddToCartButton.tsx:50-52` | OPCJONALNE |
| Porzucone koszyki (odzyskiwanie, e-mail przypominający) | BRAK | Brak e-maila gościa w koszyku, brak raportu, brak przypomnień. Koszyk po prostu wygasa. | brak kodu (grep `abandon` w `api/Cart`: 0) | ISTOTNE dla rozwoju (realny przychód), nie BLOKER |
| Przywrócenie koszyka po nieudanej płatności | BRAK | Złożenie zamówienia **czyści koszyk** w tej samej transakcji; po wygaśnięciu nieopłaconego zamówienia (35 min) klient musi kompletować koszyk od nowa. | `api/Ordering/Application/Command/PlaceOrderHandler.php:44-60` („the cleared cart"), `api/Cart/Application/Command/ClearCartAfterOrderHandler.php` | ISTOTNE (utrata konwersji) |
| Zapisane / ulubione / „kup ponownie" | BRAK | – | – | OPCJONALNE |

## 2. Checkout

| Funkcja | Status | Co działa / czego brakuje | Dowód | Znaczenie braku |
|---|---|---|---|---|
| Kroki kasy | KOMPLETNA (jednostronicowa) | Jedna strona `/checkout`: podsumowanie koszyka, kontakt + adres, kod rabatowy, wybór płatności, „Zamawiam" → przekierowanie do strony dostawcy → `/checkout/confirmation`. | `store/app/checkout/page.tsx`, `store/src/checkout/CheckoutPage.tsx:83-93`, `store/app/checkout/confirmation/page.tsx` | – |
| Dane gościa | KOMPLETNA | E-mail, imię i nazwisko, telefon (tylko PL, 9 cyfr), walidacja po polsku, autocomplete. | `store/src/checkout/CheckoutPage.tsx:44-51`, `api/Ordering/UI/Http/PlaceGuestOrderRequest.php:24-90`, `api/Ordering/Domain/Contact.php:15` | – |
| Adres dostawy | CZĘŚCIOWA | Jeden adres: ulica z numerem (jedno pole), kod NN-NNN, miasto; **kraj na sztywno PL**. Brak pól: firma, osobne imię/nazwisko odbiorcy, numer lokalu osobno, kraj UE. | `api/Ordering/Domain/ShippingAddress.php:7-16,77-80` | ISTOTNE (sprzedaż UE), dla PL wystarczy |
| Osobny adres rozliczeniowy / do faktury | BRAK | Brak pojęcia adresu rozliczeniowego. | brak w `Order.php:42-66`; `docs/features/checkout/spec.md:31-32` („faktury i dane firmy" poza zakresem) | **BLOKER** (B2B, faktura na żądanie konsumenta) |
| Firma + NIP | BRAK | grep `nip`/`invoice`/`faktur` w api/store/admin: 0 trafień w kodzie domenowym. | – | **BLOKER** dla sklepu w PL (klienci firmowi, faktura VAT) |
| Adresy zapisane na koncie | BRAK | Zalogowany dostaje tylko wstępnie wypełnione imię i nazwisko; brak książki adresów. Panel pokazuje adresy z ostatnich zamówień klienta (tylko odczyt). | `store/src/checkout/CheckoutPage.tsx:416-418`, `docs/features/checkout/spec.md:31` (#117) | OPCJONALNE |
| Metoda dostawy i jej koszt | **BRAK** | Nie istnieje pojęcie dostawy. Suma zamówienia = suma linii (`OrderTotals::of($lines)`), bez pozycji wysyłki; brak darmowej dostawy od progu, brak paczkomatów / punktów odbioru, brak odbioru osobistego. | `api/Ordering/Domain/OrderTotals.php:20-38`, `api/Ordering/Domain/Order.php:42-66` (brak pola), `docs/features/checkout/spec.md:31` („metody i koszty dostawy (decyzja P3, #120)") | **BLOKER** – sklep albo dokłada do każdej wysyłki, albo ukrywa koszt w cenie; brak paczkomatów w PL to utrata dużej części klientów |
| Wybór metody płatności | KOMPLETNA (tylko demo) | Lista z `GET /payment-providers`, bez domyślnej (klient musi wybrać), błąd w `aria-live`. Dwie metody: „Szybki przelew (demo)", „Karta płatnicza (demo)". | `store/src/checkout/CheckoutPage.tsx:99-102,225-228`, `api/Payment/Domain/ProviderCode.php:10-30` | patrz obszar 4 |
| Zgody: regulamin, RODO, newsletter | **BRAK** | Brak checkboxa akceptacji regulaminu, klauzuli informacyjnej RODO, linków do regulaminu/polityki; brak stron regulaminu w sklepie (trasy `store/app/*`: brak). Brak newslettera. | lista tras `store/app/` (brak `regulamin`, `polityka`); grep `regulamin|consent|newsletter`: 0 | **BLOKER** prawny (art. 8 ustawy o prawach konsumenta, RODO, przycisk „zamówienie z obowiązkiem zapłaty") |
| Etykieta przycisku z obowiązkiem zapłaty | DO SPRAWDZENIA | Przycisk istnieje; tekst do weryfikacji w przeglądarce (wymóg „Zamawiam i płacę"/równoważny). | `store/src/checkout/CheckoutPage.tsx` | BLOKER prawny, jeśli brak |
| Uwagi do zamówienia | BRAK | Brak pola uwag klienta w żądaniu i domenie. | `PlaceGuestOrderRequest.php:24` (lista wymaganych pól), `Order.php:42-66` | OPCJONALNE |
| Podsumowanie | KOMPLETNA | Pozycje, netto/VAT/brutto, rabat z kodu (kwoty z API, nie liczone we froncie). Brak kosztu dostawy (bo brak dostawy). | `CheckoutPage.tsx`, `store/src/account/OrderDetailPage.tsx:143-151` | – |
| Ochrona przed zmianą ceny w trakcie | KOMPLETNA | Żądanie niesie `expectedTotalGross`; inna suma → 409 `OrderTotalChanged`, sklep odświeża podgląd i nowy klucz. | `PlaceGuestOrderRequest.php:24`, `PlaceOrderHandler.php:80-89`, `CheckoutPage.tsx:113-117`, `e2e/tests/price-change.spec.ts` | – |
| Idempotencja złożenia | KOMPLETNA (mocna) | `Idempotency-Key` generowany w przeglądarce, odcisk żądania, sprawdzenie przed i pod blokadą koszyka, indeks unikalny; ponowienie z tym samym kluczem zwraca to samo zamówienie, inne dane → 409. | `CheckoutPage.tsx:80,117`, `PlaceOrderHandler.php:54-60`, `api/Ordering/Domain/RequestFingerprint.php`, `api/Ordering/Domain/IdempotencyKeyReused.php` | – |
| Limit nadużyć kasy gościa | KOMPLETNA (częściowo w toku) | Rate limit `order_placement` 10/15 min na IP; epik ABUSE-LIMITS (L5, L6: limit nieopłaconych zamówień na e-mail/adres) zaplanowany, plan bez statusu. | `apps/api/config/packages/rate_limiter.yaml`, `docs/features/abuse-limits/plan.md:8-12` | – |

## 3. Zamówienia

| Funkcja | Status | Co działa / czego brakuje | Dowód | Znaczenie braku |
|---|---|---|---|---|
| Statusy i przejścia | KOMPLETNA (prosty model) | `new → paid` (webhook) / `new → cancelled` (system po 35 min); pracownik: `paid → shipped | cancelled`, `shipped → delivered | returned`, `delivered → returned`. Jedna tabela przejść. **Brak** statusów pośrednich: „w realizacji/kompletowane", „gotowe do odbioru", „wstrzymane", „częściowo wysłane". Status łączy płatność, realizację i wysyłkę w jednym polu. | `api/Ordering/Domain/OrderStatus.php:12-34` | ISTOTNE (przy magazynie i wielu paczkach model jednego statusu nie wystarcza) |
| Kto zmienia w panelu | KOMPLETNA | Role: admin + magazyn wysyłają i oznaczają dostarczenie; admin + obsługa anulują i przyjmują zwrot; notatka wymagana przy anulowaniu i zwrocie. Optimistic locking (`version`, 409 przy równoległej zmianie). Przyciski z `allowedTransitions` z API, dialog potwierdzenia. | `openapi-admin.json:2490` (opis status-changes), `api/Ordering/Application/Command/ChangeOrderStatusHandler.php:20-28`, `admin/views/OrderDetailView.vue:141-144,370`, `admin/orders/format.ts` (etykiety) | – |
| Anulowanie zamówienia `new` (nieopłaconego) przez obsługę | BRAK | `new` nie ma przejść pracownika; towar zarezerwowany do 35 min bez możliwości zwolnienia. | `OrderStatus.php:32`, `docs/features/order-management/spec.md:35` (P2) | ISTOTNE przy przelewie tradycyjnym/COD (gdyby powstały) |
| Historia działań | KOMPLETNA | Każda zmiana statusu dopisuje wpis (kto: klient/system/login pracownika, kiedy, notatka); nigdy nie nadpisywana; oś czasu w panelu. Zakończenie zwrotu **nie** dodaje wpisu do historii (jest w osobnym polu refundu). | `api/Ordering/Domain/Order.php:463-466`, `admin/orders/OrderTimeline.vue`, `openapi-admin.json:2620` | – |
| Notatki wewnętrzne | CZĘŚCIOWA | Notatka tylko jako dodatek do zmiany statusu (`StaffNote`) albo zakończenia zwrotu. Nie da się dodać samej notatki („klient dzwonił") bez zmiany statusu. | `api/Ordering/Domain/StaffNote.php`, brak endpointu notatki w `openapi-admin.json` | ISTOTNE (codzienna praca obsługi) |
| Edycja zamówienia po złożeniu | BRAK | Brak zmiany pozycji, adresu, danych kontaktowych (pola `readonly` w agregacie). Błąd w adresie = obsługa poza systemem. | `Order.php:42-66` (`readonly`), `docs/features/order-management/spec.md:33` | ISTOTNE (codzienny przypadek: „pomyliłem numer mieszkania") |
| Zamówienia tworzone ręcznie przez obsługę (telefoniczne) | BRAK | Brak endpointu i ekranu. | `openapi-admin.json` (brak POST orders) | OPCJONALNE / ISTOTNE dla B2B |
| Częściowa realizacja / dzielenie na paczki | BRAK | Wysyłka całego zamówienia naraz (`ship()` zatwierdza całą rezerwację). | `Order.php:230-238`, `ChangeOrderStatusHandler.php:55` | OPCJONALNE na start |
| Numeracja | KOMPLETNA | `R-100001…`, sekwencja w bazie, ciągła. | `api/Ordering/Domain/OrderNumber.php:13`, `api/Ordering/Infrastructure/Persistence/Doctrine/DbalOrderNumbers.php` | – |
| Lista, wyszukiwanie, filtrowanie | KOMPLETNA (dobra) | Kursorowa paginacja, wybór liczby na stronie, sortowanie po dacie/kwocie, filtr jednego statusu, zakres dni (Europe/Warsaw), wyszukiwanie: dokładny numer, pełny e-mail albo fragment numeru/e-maila/nazwiska; filtr „zwroty do wykonania"/„przeterminowane"; licznik do 10 000; filtr per klient. Kolumny: numer, data, klient, liczba pozycji, kwota, status. | `openapi-admin.json:2316` (opis), `admin/views/OrdersView.vue:47-62,82-95`, `e2e/tests/panel-orders-url.spec.ts` | – |
| Kolejki na pulpicie | KOMPLETNA | Liczniki statusów i zwrotów (do 1000+), linkują do przefiltrowanej listy. | `api/Ordering/Application/Query/GetOrderQueuesHandler.php:14-21` | – |
| Akcje masowe (zaznacz 30 → wyślij) | BRAK | Brak checkboxów/akcji masowych na liście. | `admin/views/OrdersView.vue` (grep `checkbox|bulk`: 0) | **ISTOTNE** przy 200 zamówieniach/dzień |
| Wydruki (list przewozowy, packing slip, potwierdzenie) | BRAK | – | grep `print|drukuj`: 0 | ISTOTNE (magazyn kompletuje z kartki/ekranu) |
| Eksport (CSV/XLSX) | BRAK | – | grep `export|csv` w `admin/`: 0 | ISTOTNE (księgowość, raporty) |
| Szczegóły zamówienia w panelu | KOMPLETNA | Kontakt (mailto), adres, daty statusów, identyfikator płatności (kopiuj), pozycje z wariantem, sumy, rabat, zwrot, historia, link do karty klienta. Brak: nazwy dostawcy płatności i statusu płatności, przewoźnika, numeru przesyłki. | `admin/views/OrderDetailView.vue:280-560`, `openapi-admin.json:2236,4404` | patrz 4/5 |
| Historia zamówień klienta w sklepie | KOMPLETNA (zalogowany), BRAK (gość) | `/konto/zamowienia` i szczegóły; gość po zamknięciu sesji (ciasteczko potwierdzenia 1 h) nie ma dostępu do zamówienia, e-mail nie ma linku. W prod konta wyłączone, więc w praktyce nikt nie ma historii. | `store/app/konto/zamowienia/*`, `store/src/checkout/cookies.ts:9` (`60 * 60`), `docs/features/order-management/spec.md:36` (#118) | ISTOTNE (pytania „gdzie moja paczka" trafią do obsługi) |

## 4. Płatności

| Funkcja | Status | Co działa / czego brakuje | Dowód | Znaczenie braku |
|---|---|---|---|---|
| Prawdziwi dostawcy (PayU, Przelewy24, Tpay, Stripe, BLIK) | **BRAK** | Wyłącznie dwa symulatory działające **w tym samym procesie API** (`/payment-simulator/...`), wołane po HTTP jak zewnętrzne. Klient na stronie symulatora sam wybiera „zapłać/odrzuć". | `api/Payment/Domain/ProviderCode.php:10-13`, `api/Payment/Infrastructure/Provider/DemoTransferProvider.php:18`, `api/PaymentSimulator/Http/PaymentPageHtml.php:19-20`, `apps/api/config/services.yaml:219`, `docs/vision.md:385` | **BLOKER** – nie da się przyjąć pieniędzy |
| Port/adapter | KOMPLETNA jako szkielet | `PaymentProvider`: `createSession`, `cancelSession`, `parseWebhook` (podpis przed parsowaniem); rejestr po tagu. Dodanie PayU/P24 = nowy adapter + przypadek enuma `ProviderCode` + sekrety w compose/chartcie. **Brakuje w porcie**: zwrotu pieniędzy (`refund`), odpytania o stan transakcji (uzgodnienie przed anulowaniem; P24 wymaga `verify` po powiadomieniu), zdarzeń innych niż opłacone/odrzucone (zwrot, chargeback, wygaśnięcie), danych kupującego. Wywołania dostawcy biegną w transakcji bazy z blokadą. Szacunek: pierwszy prawdziwy operator to epik (dni–tygodnie), nie „wpięcie adaptera". | `api/Payment/Application/Port/PaymentProvider.php:18-55`, `api/Payment/Application/Command/StartPaymentHandler.php:32-49`, `docs/reports/app-audit-2026-11-18/02-zamowienia-platnosci.md` (znalezisko 7), `docs/features/abuse-limits/spec.md:48` (PAYMENT-OPERATOR) | BLOKER (razem z wierszem wyżej) |
| Webhooki | KOMPLETNA (mocna) | Podpis HMAC (`hash_equals`) na surowej treści, pusty sekret odrzuca wszystko, deduplikacja po id zdarzenia (`ON CONFLICT DO NOTHING`), blokada wiersza płatności, rate limit, weryfikacja kwoty dwa razy. | `api/Payment/UI/Http/WebhookController.php`, `api/Payment/Application/Command/HandleProviderWebhookHandler.php:20-32`, `api/PaymentSimulator/WebhookSignature.php` | – |
| Statusy płatności | KOMPLETNA (minimalne) | `pending`, `succeeded`, `failed`, `cancelled`. Brak `refunded`, `partially_refunded`, `chargeback`. | `api/Payment/Domain/PaymentStatus.php` | ISTOTNE przy prawdziwym operatorze |
| Widok płatności w panelu | CZĘŚCIOWA | Tylko identyfikator płatności na zamówieniu; brak dostawcy, statusu, prób, kwoty transakcji, listy płatności. | `admin/views/OrderDetailView.vue:511-523` | ISTOTNE (reklamacje płatności, uzgodnienia) |
| Płatności „do ręcznego zwrotu" (po terminie, po anulowaniu, podwójne) | CZĘŚCIOWA – tylko w bazie i logu | Webhook oznacza `refund_required_reason`, loguje błąd (Sentry). **Brak** ekranu, licznika, alertu; klient dostaje e-mail „anulowane – brak płatności", choć zapłacił. | `HandleProviderWebhookHandler.php:79-88`, `api/Payment/Domain/Payment.php:126`, `docs/features/payment/spec.md:635`, `docs/features/order-management/spec.md:31-32` (#221) | **BLOKER** przy prawdziwych pieniądzach (pieniądze klienta „znikają" dla obsługi) |
| Płatność przy odbiorze (COD) | BRAK | Model wymaga opłacenia w 30 min, inaczej anulacja; COD wymagałby wysyłki zamówienia `new`. | `Order.php:31` (`PAYMENT_TIME`), `OrderStatus.php:29-32` | ISTOTNE w PL (część klientów), sprzeczne z obecnym modelem |
| Przelew tradycyjny (dane do przelewu, ręczne zaksięgowanie) | BRAK | Brak ręcznego „oznacz jako opłacone" w panelu, termin 30 min wyklucza przelew zwykły. „Szybki przelew (demo)" to symulacja pay-by-link. | `OrderStatus.php:32` (`new` bez przejść pracownika) | ISTOTNE (B2B, starsi klienci) |
| Ponowienie nieudanej płatności | KOMPLETNA (w oknie 30 min, w sesji kasy) | Na stronie potwierdzenia: „Wróć do płatności" (ta sama sesja), „Zapłać innym sposobem", po odrzuceniu nowa próba; poprzednia sesja anulowana u dostawcy przed nową. **Brak** ponowienia z historii konta i z linku w e-mailu; gość traci dostęp po 1 h (ciasteczko). | `store/src/checkout/PaymentConfirmation.tsx:283-311`, `StartPaymentHandler.php:38-44`, `docs/features/payment/spec.md:44` (P5), `:30-31`, `e2e/tests/payment-resume.spec.ts` | ISTOTNE (odzysk nieudanych płatności to realny przychód) |
| Wygasanie nieopłaconych | KOMPLETNA | Opóźniona wiadomość zapisana w transakcji złożenia, anulacja po 30 + 5 min, zwolnienie rezerwacji i użycia kodu, e-mail o anulowaniu. Znane ryzyka (utrata wiadomości, płatność `pending` na zawsze) – epik ASYNC-RELIABILITY W5/W6, niezaczęty. | `api/Ordering/Application/Command/ExpireUnpaidOrderHandler.php:20-31`, `api/Ordering/Infrastructure/Messaging/MessengerUnpaidOrderExpiry.php`, `docs/features/async-reliability/spec.md:263,287`, `plan.md:8-14` | – (30 min jest krótkie dla BLIK/przelewu, ale poprawne dla pay-by-link) |

## 5. Dostawa

| Funkcja | Status | Co działa / czego brakuje | Dowód | Znaczenie braku |
|---|---|---|---|---|
| Metody wysyłki | **BRAK** | Brak encji, endpointu, ekranu. | `docs/features/checkout/spec.md:31`, `docs/vision.md:385` | **BLOKER** |
| Cenniki, darmowa dostawa od progu | **BRAK** | Suma bez kosztu dostawy. | `OrderTotals.php:20-38` | **BLOKER** |
| Paczkomaty / punkty odbioru (InPost, Orlen Paczka, DPD Pickup) | **BRAK** | – | – | **BLOKER** w PL (dominująca forma dostawy) |
| Integracje kurierskie (InPost ShipX, DPD, DHL, GLS, Furgonetka/Apaczka/BaseLinker) | **BRAK** | – | `docs/features/order-management/spec.md:30` | ISTOTNE (na start da się ręcznie w panelu kuriera, ale z przepisywaniem adresów) |
| Generowanie etykiet | BRAK | – | – | ISTOTNE |
| Numer przesyłki, przewoźnik, link śledzenia | **BRAK** | „Wysłane" to wyłącznie zmiana statusu z datą i opcjonalną notatką (można tam wpisać numer – obejście). | `Order.php:230-238` (`ship(StaffActor, ?note)`), `docs/features/order-management/spec.md:30` | **BLOKER** dla jakości obsługi (każdy klient pyta „gdzie paczka") |
| Powiadomienie o wysyłce | CZĘŚCIOWA | E-mail „Zamówienie R-… wysłane" z adresem; bez przewoźnika, numeru, linku. | `api/Ordering/Application/OrderShippedText.php`, `api/Ordering/Application/EventHandler/SendOrderShipped.php` | patrz wyżej |
| Stan magazynowy przy wysyłce | KOMPLETNA | Wysyłka zatwierdza rezerwację (towar schodzi ze stanu), anulowanie ją zwalnia. | `ChangeOrderStatusHandler.php:20-28,55,62` | – |

## 6. Zwroty, reklamacje, anulowanie, refundacje

| Funkcja | Status | Co działa / czego brakuje | Dowód | Znaczenie braku |
|---|---|---|---|---|
| Anulowanie przez klienta | BRAK | Brak endpointu i przycisku; nieopłacone wygasa samo. | `docs/features/order-management/spec.md:35` | ISTOTNE (odstąpienie przed wysyłką trafi do obsługi mailem) |
| Anulowanie przez obsługę | KOMPLETNA dla `paid` | Tylko z `paid` (przed wysyłką), notatka wymagana; rezerwacja zwolniona; powstaje „zwrot do wykonania" = pełna kwota brutto, termin 14 dni; e-mail „Anulowaliśmy… Zwrócimy płatność". Z `new` – niemożliwe. | `Order.php:262-272`, `OrderStatus.php:29`, `api/Ordering/Application/OrderCancelledByStaffText.php:25-27` | – |
| Zwrot towaru | CZĘŚCIOWA | Pracownik „Przyjmij zwrot" ze `shipped`/`delivered`, notatka wymagana → status `returned`, refund pełnej kwoty brutto (14 dni), e-mail „wróciło do nas. Zwrócimy płatność". **Tylko całe zamówienie**, bez per pozycja/ilość. **Towar nie wraca na stan** – obsługa musi osobno zrobić korektę stanu w module magazynu. | `Order.php:274-290` („The goods do not go back on the stock by themselves"), `docs/features/order-management/spec.md:46` (P1: „Bez zwrotów częściowych") | ISTOTNE: zwroty częściowe to codzienność (zwrot 1 z 3 rozmiarów butów w branży sport) |
| RMA inicjowane przez klienta (formularz odstąpienia, 14 dni) | BRAK | Brak formularza, brak statusu „zgłoszony zwrot", brak etykiety zwrotnej. | brak w `openapi.json` | **BLOKER** prawny w formie minimalnej: konsument musi dostać informację i wzór formularza odstąpienia (może być PDF/strona + e-mail, bez modułu RMA) |
| Refundacja | **CZĘŚCIOWA – tylko ewidencja** | „Oznacz zwrot jako wykonany" zapisuje kto/kiedy/notatkę; **pieniądze przelewa się ręcznie poza systemem**; brak wywołania dostawcy, brak zwrotu częściowego, brak korekty kwoty (np. potrącenie kosztów), brak e-maila o wykonanym zwrocie. Lista „Zwroty do wykonania" z terminem i przeterminowanymi. | `api/Ordering/Domain/Refund.php:7-11`, `Order.php:292-308`, `openapi-admin.json:2620`, `admin/views/OrdersView.vue:40-62`, `admin/orders/permissions.ts:11-14` | **BLOKER** przy prawdziwych płatnościach (ręczny przelew do płatności kartą/BLIK jest niewykonalny – trzeba wejść do panelu operatora) |
| Reklamacje (rękojmia/gwarancja) | BRAK | Brak pojęcia. | – | ISTOTNE (obowiązek prawny obsługi, na start możliwy mailem poza systemem) |
| Korekty (faktura korygująca) | BRAK | – | – | BLOKER razem z fakturami |
| Wymiana (inny rozmiar) | BRAK | – | – | OPCJONALNE (istotne w branży odzieżowo-sportowej) |

## 7. Dokumenty i komunikacja transakcyjna

| Funkcja | Status | Co działa / czego brakuje | Dowód | Znaczenie braku |
|---|---|---|---|---|
| E-mail: potwierdzenie zamówienia | KOMPLETNA w kodzie, **nie wychodzi w prod** | Czysty tekst: pozycje, kod, suma, adres, telefon, termin płatności. Brak: danych sprzedawcy, linku do zamówienia/płatności, regulaminu, pouczenia o odstąpieniu, formularza odstąpienia (wymóg trwałego nośnika). | `api/Ordering/Application/OrderConfirmationText.php` (`body()`), `api/Ordering/Infrastructure/Mail/SymfonyOrderConfirmationMailer.php`, `values.yaml:23` (`orderMail: mailpit`) | **BLOKER** (prawnie wymagana treść + wysyłka) |
| E-mail: opłacenie | KOMPLETNA w kodzie | „Płatność przyjęta – zamówienie R-…". | `api/Ordering/Application/PaymentReceivedText.php`, `EventHandler/SendPaymentReceived.php` | – |
| E-mail: wysyłka | CZĘŚCIOWA | Bez numeru przesyłki. | `OrderShippedText.php` | patrz 5 |
| E-mail: anulowanie | KOMPLETNA | Dwie treści: brak płatności / anulowanie przez sklep. | `EventHandler/SendOrderCancelled.php:19-23,47` | – |
| E-mail: zwrot przyjęty | KOMPLETNA | „Zamówienie wróciło do nas. Zwrócimy płatność." | `ReturnReceivedText.php:17-25` | – |
| E-mail: dostarczono, zwrot pieniędzy wykonany, nieudana płatność, przypomnienie o płatności | BRAK | `deliver()` celowo bez zdarzenia; `completeRefund` bez zdarzenia. | `Order.php:240-252` („No event: the customer gets no email"), `Order.php:292-308` | OPCJONALNE (dostarczono), ISTOTNE (zwrot wykonany, przypomnienie o płatności) |
| Niezawodność wysyłki e-maili | KOMPLETNA (mocna) | Zdarzenia przez transactional outbox → RabbitMQ; jeden e-mail na zdarzenie (claim z dzierżawą `UPSERT`), ponowienia Messengera. | `apps/api/config/packages/messenger.yaml:29-30,108`, `api/Ordering/Infrastructure/Persistence/Doctrine/DbalOrderNotifications.php` | – |
| Szablony HTML, branding | BRAK | Tylko `text/plain`, brak szablonów Twig/HTML. | `SymfonyOrderConfirmationMailer.php` (`->text(...)`), brak `apps/api/templates` | OPCJONALNE |
| Faktury / paragony / numeracja dokumentów | **BRAK** | – | `docs/vision.md:385` | **BLOKER** (obowiązek podatkowy; na start można integracją z Fakturownią/wFirmą/inFaktem albo kasą fiskalną online, ale musi istnieć eksport danych zamówień) |
| KSeF | BRAK | – | – | **BLOKER** dla faktur B2B w PL (obowiązkowy KSeF od 2026) – praktycznie wyłącznie przez zewnętrzny system fakturowy |
| Eksport do księgowości (JPK, CSV, integracja) | BRAK | Brak eksportu zamówień; pulpit ma statystyki sprzedaży, ale nie raport dla księgowej. | `openapi-admin.json` (brak), `api/Ordering/Application/Query/GetSalesSummaryHandler.php` (tylko pulpit) | **BLOKER** pośredni: bez eksportu księgowość = ręczne przepisywanie |

---

## Przebieg pełnego cyklu sprzedaży krok po kroku

### (a) Klient-gość kupuje

1. Strona produktu → „Dodaj do koszyka": koszyk gościa w bazie, ciasteczko, licznik w nagłówku, komunikat „Dodano do
   koszyka". OK.
2. `/cart`: pozycje z żywymi cenami i stanami; linia niedostępna blokuje dalej. Brak pola kodu rabatowego (jest
   dopiero w kasie). Brak informacji o koszcie dostawy i progu darmowej dostawy – **pierwsze miejsce, gdzie klient
   prawdziwego sklepu się zatrzymuje** („ile zapłacę za wysyłkę?").
3. `/checkout`: e-mail, imię i nazwisko, telefon, ulica, kod, miasto. **Urywa się**: brak wyboru dostawy (kurier /
   paczkomat), brak danych firmy i NIP, brak adresu do faktury, brak checkboxa regulaminu i linku do polityki
   prywatności, brak uwag. Kod rabatowy działa, suma przeliczana przez API.
4. Wybór „Szybki przelew (demo)" lub „Karta płatnicza (demo)" → „Zamawiam": zamówienie `new` (R-…), rezerwacja stanu,
   **koszyk wyczyszczony**, e-mail potwierdzenia (w prod do Mailpita w klastrze, klient go nie dostaje).
5. Przekierowanie do strony symulatora w tym samym API: klient klika „zapłać" albo „odrzuć". **Urywa się**: nie ma
   prawdziwego operatora – pieniądze nie istnieją.
6. Webhook z podpisem → `paid`, e-mail „Płatność przyjęta". Strona `/checkout/confirmation` odpytuje status;
   przy odrzuceniu – ponowienie innym sposobem w ciągu 30 min.
7. Po zamknięciu karty: gość nie ma już dostępu do zamówienia (ciasteczko 1 h, e-mail bez linku). Brak płatności
   w 35 min → anulacja, e-mail; koszyk już nie wróci.

### (b) Obsługa realizuje zamówienie w panelu

1. Pulpit: licznik „opłacone" → lista przefiltrowana po `paid`. OK.
2. Szczegóły: kontakt, adres, pozycje z wariantem, sumy. **Urywa się**: nie ma informacji, jak wysłać (metoda
   dostawy, paczkomat), brak wydruku listy kompletacyjnej, brak etykiety. Pracownik idzie do panelu InPost/DPD
   i przepisuje adres ręcznie (brak eksportu/integracji).
3. „Oznacz jako wysłane" (rola admin/magazyn) z opcjonalną notatką: stan magazynu schodzi, e-mail „wysłane" bez
   numeru przesyłki. Numer przesyłki można wpisać tylko w notatkę historii – klient go nie zobaczy.
   Każde zamówienie osobno, z dialogiem (brak akcji masowych).
4. **Faktura: poza systemem** (ręcznie w programie fakturowym, bez NIP od klienta, bo kasa go nie zbiera).
5. „Oznacz jako dostarczone" – ręcznie, bez e-maila, bez informacji od przewoźnika.
6. Rozliczenie / księgowość: brak eksportu – **poza systemem**.

### (c) Klient chce zwrócić towar

1. Klient nie ma w sklepie ani formularza odstąpienia, ani informacji o zwrotach (brak stron prawnych), ani konta
   (w prod wyłączone). **Urywa się od razu**: kontakt mailem/telefonicznie poza systemem (adres kontaktowy sklepu –
   do sprawdzenia, czy w ogóle jest na stronie).
2. Obsługa po otrzymaniu paczki: „Przyjmij zwrot" z notatką – **tylko całe zamówienie**. Przy zwrocie 1 z 3 pozycji
   system nie ma narzędzia (obsługa musi zostawić status i rozliczyć poza systemem albo zarejestrować pełny zwrot
   i ręcznie przelać mniej – sprzeczne z danymi).
3. Towar nie wraca na stan automatycznie – osobna korekta w module magazynu.
4. Refund pełnej kwoty brutto pojawia się na liście „Zwroty do wykonania" z terminem 14 dni. Pieniądze przelewa się
   **poza systemem** (przy karcie/BLIK – w panelu operatora, którego nie ma), potem „Oznacz zwrot jako wykonany".
   Klient nie dostaje e-maila o wykonaniu zwrotu.
5. Korekta faktury – **poza systemem**. Reklamacja (wada towaru) – w ogóle brak pojęcia.

---

## Mocne strony (z dowodem)

- **Idempotencja i spójność złożenia**: jedna transakcja obejmuje blokadę koszyka, kod, rezerwację, zamówienie, outbox
  i wiadomość wygaśnięcia; klucz + odcisk żądania, sprawdzenie przed i pod blokadą
  (`PlaceOrderHandler.php:44-60`, `RequestFingerprint.php`). Ochrona przed zmianą ceny (`expectedTotalGross` → 409).
- **Brak sprzedaży ponad stan** przy równoległych zakupach: rezerwacja z blokadą wiersza w Inventory,
  wysyłka zatwierdza, anulowanie zwalnia (`ChangeOrderStatusHandler.php:20-28`; `docs/vision.md:410-412`).
- **Webhooki płatności na poziomie produkcyjnym**: HMAC na surowej treści przed parsowaniem, deduplikacja zdarzeń,
  blokada wiersza, podwójna weryfikacja kwoty, „pieniądze ważniejsze" (sukces wygrywa z odrzuceniem)
  (`HandleProviderWebhookHandler.php:20-32`, `DoctrinePaymentRepository.php`).
- **Jedna oczekująca płatność na zamówienie** (indeks częściowy) i bezpieczne ponowienie z anulowaniem poprzedniej
  sesji u dostawcy (`StartPaymentHandler.php:32-49`).
- **Transactional outbox + jednokrotne e-maile** (`messenger.yaml:29-30,108`, `DbalOrderNotifications.php`).
- **Optimistic locking w panelu** (`version`, 409 przy równoległej zmianie dwóch pracowników) i niezmienniki statusów
  w bazie (`CHECK` w `migrations/Version20261107140000AddOrderLifecycle.php:24-27`).
- **Migawka zamówienia**: nazwa, wariant, ceny netto/VAT/brutto, stawka, promocja, udział w rabacie kopiowane do linii.
- **Port płatności z dwoma różnymi API symulatorów** – wymusza prawdziwą abstrakcję (różne podpisy, formaty kwot).
- **Lista zamówień w panelu** z wyszukiwaniem, zakresem dat, sortowaniem, paginacją kursorową i stanem w URL;
  osobna kolejka „Zwroty do wykonania / przeterminowane" z terminem 14 dni; role (admin/obsługa/magazyn) wymuszane
  w API, nie tylko w UI (`openapi-admin.json:2316,2490`, `admin/orders/permissions.ts`).
- **Polskie komunikaty walidacji** w API (`PlaceGuestOrderRequest.php:43-90`), dostępność kasy (`aria-live`, focus).
- **Pokrycie E2E** głównych ścieżek: `checkout-guest`, `checkout-customer`, `payment-resume`, `order-management`,
  `cart-merge`, `price-change`, `discount-codes` (`e2e/tests/`).

## Niewygodne w codziennej pracy (200 zamówień dziennie)

- Brak akcji masowych: 200 × (otwórz → „Oznacz jako wysłane" → potwierdź) dziennie, plus to samo dla „dostarczone".
- Brak wydruku listy kompletacyjnej i etykiet; ręczne przepisywanie 200 adresów do panelu kuriera.
- Brak numeru przesyłki w danych zamówienia → telefony „gdzie paczka"; numer da się wpisać tylko w notatkę historii.
- Status „dostarczone" ustawiany ręcznie, bez informacji od przewoźnika – w praktyce nikt tego nie zrobi, kolejka
  `shipped` będzie rosła.
- Brak samodzielnej notatki wewnętrznej (tylko przy zmianie statusu).
- Brak edycji adresu/telefonu – każda pomyłka klienta to obejście poza systemem.
- Zwrot wyłącznie całego zamówienia; towar nie wraca na stan automatycznie (dwa miejsca do kliknięcia).
- Refund ręczny poza systemem + osobne „oznacz jako wykonany"; płatności „do ręcznego zwrotu" (po terminie, podwójne)
  niewidoczne w panelu.
- Filtr statusu pojedynczy (nie „opłacone + wysłane"); brak filtra po metodzie płatności/dostawy (bo ich nie ma).
- Brak eksportu – raport dla księgowej ręcznie.
- Panel nie pokazuje dostawcy ani statusu płatności (tylko UUID płatności).
- Nie da się anulować zamówienia `new` (np. klient dzwoni „pomyliłem się") – trzeba czekać 35 min.

## Nadmiarowe (jak na obecny zakres funkcji)

- Symulator dwóch dostawców z osobnymi API, podpisami, stroną płatności i kolejką webhooków (~1300 linii,
  `api/PaymentSimulator/`) – ma wartość demonstracyjną i testową, ale nie sprzedaje; w realnym sklepie zastępuje go
  sandbox operatora.
- Modele odczytu pulpitu (`ordering_sales_hour`, `ordering_customer` z przebudową) zapisywane w transakcji webhooka
  i złożenia – rozbudowane względem braku podstaw (dostawa, faktura) (`api/Ordering/Infrastructure/Persistence/Doctrine/DbalSalesHours.php`).
- Bardzo dopracowana idempotencja/współbieżność przy jednocześnie brakujących funkcjach podstawowych – proporcje
  inwestycji odwrócone względem potrzeb właściciela sklepu (to nie wada kodu, tylko kolejności).

## Do sprawdzenia w przeglądarce

1. Strona koszyka: czy widać jakąkolwiek informację o dostawie / koszcie; brak pola kodu (potwierdzić).
2. Kasa: dokładny tekst przycisku składania zamówienia (wymóg „zamówienie z obowiązkiem zapłaty"); czy jest
   jakikolwiek link do regulaminu/polityki prywatności w kasie i stopce; czy podany jest adres/kontakt sprzedawcy.
3. Kasa: zachowanie kodu rabatowego po przeładowaniu (powinien zniknąć) i komunikat przy zmianie ceny w trakcie.
4. Strona symulatora płatności: jak wygląda dla klienta (czy jasno oznaczona jako demo), powrót do sklepu,
   „Wróć do płatności" i „Zapłać innym sposobem" po odrzuceniu.
5. Po opłaceniu: zamknąć kartę i spróbować wrócić do zamówienia jako gość (oczekiwane: niemożliwe po 1 h).
6. Po 35 min bez płatności: czy koszyk jest pusty (oczekiwane: tak) i co widzi klient.
7. Mailpit: treść wszystkich e-maili (potwierdzenie, opłacenie, wysyłka, anulowanie, zwrot) – brak danych sprzedawcy,
   linków, pouczenia o odstąpieniu.
8. Panel, zamówienie `paid`: przyciski dostępne dla ról admin/obsługa/magazyn; dialog „Oznacz jako wysłane" – czy jest
   miejsce na numer przesyłki (oczekiwane: tylko notatka); czy notatka jest widoczna dla klienta.
9. Panel: „Przyjmij zwrot" → lista „Zwroty do wykonania" → „Oznacz zwrot jako wykonany"; sprawdzić, że stan magazynu
   nie wrócił.
10. Panel: zamówienie `new` – brak przycisków (potwierdzić); czy widać termin płatności.
11. Panel: lista zamówień na 375 px i z klawiatury; wyszukiwanie fragmentem nazwiska; brak zaznaczania wielu wierszy.
12. Panel: czy widać gdziekolwiek nazwę dostawcy płatności i status płatności.
