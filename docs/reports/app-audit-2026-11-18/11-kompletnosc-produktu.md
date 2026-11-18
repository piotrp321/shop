# 11. Kompletność produktu: gotowość do sprzedaży produkcyjnej (B2C, Polska i UE)

## Zakres i metoda

Audyt statyczny migawki `main` (`ef204620`), wyłącznie w katalogu eksportu. Stan faktyczny ustalałem z kodu, a
dokumenty (`docs/vision.md`, specyfikacje) czytałem dopiero do porównania i do sprawdzenia, czy brak jest świadomą decyzją.

Inwentaryzacja:

- **API**: oba pliki OpenAPI w całości (`apps/api/openapi.json`: 30 operacji na 27 ścieżkach; `openapi-admin.json`:
  37 operacji na 28 ścieżkach) i wszystkie atrybuty `#[Route]` w `apps/api/src` (zgodne z OpenAPI, plus `/health`
  i symulator płatności `/payment-simulator/...`).
- **Sklep**: wszystkie trasy `apps/store/app` (strony: `/`, `/c/[...path]`, `/p/[slug]`, `/szukaj`, `/cart`,
  `/checkout`, `/checkout/confirmation`, `/konto/zamowienia`, `/konto/zamowienia/[orderId]`; BFF: `/api/cart*`,
  `/api/checkout*`, `/api/account/orders*`, `/api/site`, `/api/revalidate`, `/auth/*`; SEO: `robots`, mapy strony).
- **Panel**: `apps/admin/src/router/index.ts` (pulpit, produkty, kategorie, ceny, promocje, kody rabatowe, magazyn,
  zamówienia, klienci, system).
- **Komendy konsolowe**: 15 (`#[AsCommand]`): seedy demo (6), `catalog:reindex`, `catalog:search-index:setup`,
  `app:backfill`, `storefront:cache:purge-all`, `ordering:read-models:rebuild`, `inventory:stock-records:sync`,
  `cart:purge-expired`, `pricing:reconcile-tax`.
- **E-maile**: 5 rodzajów (`NotificationKind`: potwierdzenie, płatność przyjęta, anulowanie, wysyłka, zwrot przyjęty),
  wszystkie tekstowe, wyłącznie do klienta.
- **Zadania cykliczne**: CronJob `cart-purge-expired` (co godzinę), `backup` i `backup-verify` (chart); wygasanie
  nieopłaconych zamówień przez opóźnioną wiadomość Messengera. Symfony Scheduler nie jest używany.
- **Tożsamość**: realmy Keycloaka `infra/keycloak/realms/shop.json` i `backoffice.json`.

Przeczytane w całości albo w istotnych fragmentach: ok. 40 plików (m.in. `Order.php`, `OrderStatus.php`,
`ShippingAddress.php`, `Contact.php`, `OrderTotals.php`, `Refund.php`, `PlaceGuestOrderRequest.php`,
`ChangeOrderStatusRequest.php`, 5 klas treści e-maili, oba mailery, `PaymentProvider.php`, `DemoCardProvider.php`,
`Payment.php`, `HandleProviderWebhookHandler.php`, `PolishVatRates.php`, `LowestPriorPrice.php`, `Product.php`,
`CheckoutPage.tsx`, `PaymentConfirmation.tsx`, `SiteFooter.tsx`, `ProductDetailsView.tsx`, `PromotionNotice.tsx`,
`AccountMenu.tsx`, `layout.tsx`, `checkout/cookies.ts`, `environments/prod/values.yaml`, `docs/vision.md`, sekcje zakresu
i decyzji specyfikacji checkout, payment, order-management, deploy, promotions). Braki potwierdzałem wyszukiwaniem słów
kluczowych w 1286 plikach PHP, 150 plikach sklepu i 253 plikach panelu (bez testów); wyniki są w tabeli w sekcji „Braki”.

Nie jestem prawnikiem: oceny „blokujące prawnie” wskazują obszary, które przed startem musi potwierdzić prawnik
i księgowy, nie są opinią prawną.

## Ocena ogólna obszaru

**Gotowość do sprzedaży produkcyjnej: 3/10. Zgodność z własnym „Zakresem MVP”: 9/10.**

Sklep robi rzetelnie to, co wizja nazywa MVP (10 punktów „Zakresu MVP” jest zrealizowanych pełnymi przepływami:
katalog z wariantami, ceny netto/VAT/brutto z historią, koszyk gościa i klienta, checkout z idempotencją, płatność
z webhookami, statusy zamówień, rezerwacje, promocje i kody z ceną Omnibus). Ale wizja wprost wyłącza z zakresu
prawdziwych dostawców płatności, kurierów i faktury (`docs/vision.md:364-366`), a specyfikacje dokładają kolejne
wyłączenia: dostawę (`docs/features/checkout/spec.md:30,42`), regulamin (`:48`), anonimizację RODO (`:32`), zwroty
u operatora płatności (`docs/features/order-management/spec.md:31`).

Skutek: dziś nie da się przyjąć ani jednej złotówki (dostawcy płatności to dwa symulatory), nie da się naliczyć ani
wybrać dostawy, nie ma żadnego dokumentu prawnego ani danych sprzedawcy, nie ma faktury, a dane osobowe z zamówień nie
mają procedury usunięcia. To nie są usterki wykonania, tylko brakujące moduły: każdy z nich to epik rozmiaru M albo L.
Fundament pod nie jest dobry (port płatności, kopie cen i stawek VAT w pozycjach zamówienia, historia statusów
z aktorem), więc dobudowa nie wymaga przebudowy.

## Znaleziska

Większość znalezisk to braki świadomie wyłączone z zakresu. Klasyfikuję je jako `RYZYKO` z warunkiem „uruchomienie
prawdziwej sprzedaży” (prawdopodobieństwo wystąpienia: pewne, jeśli sprzedaż ruszy bez nich); waga opisuje skutek
dla startu sprzedaży, nie jakość istniejącego kodu.

### F1. Brak prawdziwego operatora płatności i zwrotu środków przez operatora

- **Waga:** Critical. **Klasa:** RYZYKO (świadoma decyzja zakresu: `docs/vision.md:366`, `docs/features/payment/spec.md:27`).
- **Dowód:** jedyni dostawcy to symulatory działające w tym samym API:
  `apps/api/src/Payment/Domain/ProviderCode.php:12-13`
  ```php
  case DemoTransfer = 'demo-transfer';
  case DemoCard = 'demo-card';
  ```
  `apps/api/src/Payment/Infrastructure/Provider/` zawiera tylko `DemoCardProvider.php` i `DemoTransferProvider.php`
  (adres `'/payment-simulator/demo-card/v1/checkout-sessions'`, `DemoCardProvider.php:32`). Port ma trzy operacje
  i żadnej zwrotu: `apps/api/src/Payment/Application/Port/PaymentProvider.php:35,42,55` (`createSession`,
  `cancelSession`, `parseWebhook`). Zwrot jest ręczny, poza systemem: `apps/api/src/Ordering/Domain/Refund.php:8-10`
  („the transfer itself is manual, outside the system”). Wyszukiwanie `przelewy24|payu|stripe|blik|tpay|paypal|adyen`
  w `apps/` trafia wyłącznie w fałszywe dopasowania (`startPayment`, `mapRequestPayload`, „opublikowany”), brak integracji.
- **Konsekwencja:** sklep nie może pobrać pieniędzy. Po dodaniu operatora każdy zwrot (anulowanie, zwrot towaru,
  nadpłata) trzeba by wykonywać ręcznie w panelu operatora i osobno odhaczać w panelu sklepu, bez powiązania kwot.
  Brak też płatności przy odbiorze i zwykłego przelewu (termin płatności to sztywne 30 minut,
  `apps/api/src/Ordering/Domain/Order.php:31`, więc przelew tradycyjny nie zmieści się w modelu).
- **Rekomendacja:** jeden agregator na start (Przelewy24, PayU albo Tpay: BLIK, szybkie przelewy i karty jedną
  integracją). Rozszerzyć port o `refund(providerPaymentId, amount)` i odczyt statusu transakcji (część operatorów
  wymaga jawnej weryfikacji transakcji po powiadomieniu), `Refund` w Ordering połączyć z wynikiem zwrotu u operatora.
  Symulatory wyłączyć konfiguracją w środowisku sprzedażowym. Rozmiar: M (jeden operator), L ze zwrotami częściowymi.
- **Pewność:** wysoka. Sprawdziłem enum kodów, katalog adapterów, port, specyfikację i wizję.

### F2. Brak dostawy: metod, kosztu, przewoźników, numeru przesyłki

- **Waga:** Critical. **Klasa:** RYZYKO (świadoma decyzja P3, `docs/features/checkout/spec.md:42`).
- **Dowód:** suma zamówienia to wyłącznie suma pozycji, `apps/api/src/Ordering/Domain/OrderTotals.php:31-36`:
  ```php
  $net = $vat = $gross = Money::zero($currency);
  foreach ($lines as $line) {
      $net = $net->add($line->lineNet());
      $vat = $vat->add($line->lineVat());
      $gross = $gross->add($line->lineGross());
  }
  ```
  Formularz kasy ma sześć pól kontaktowo-adresowych i wybór płatności, bez dostawy
  (`apps/store/src/checkout/CheckoutPage.tsx:44-51`), a podsumowanie kończy się na „Do zapłaty (brutto)” (`:730`).
  Wysyłka w panelu to sama zmiana statusu z opcjonalną notatką widoczną tylko w panelu
  (`apps/api/src/Ordering/UI/Http/ChangeOrderStatusRequest.php:22-24`), a e-mail „wysłane” zawiera tylko adres
  (`apps/api/src/Ordering/Application/OrderShippedText.php:23-32`). Słowa `inpost|paczkomat|parcel|locker|dpd|dhl|
  carrier|kurier` występują w kodzie tylko w opisach przejścia „return” (paczka wróciła). Adres to wyłącznie Polska:
  `apps/api/src/Ordering/Domain/ShippingAddress.php:77-80` (`return self::POLAND;`), kod `NN-NNN`, telefon `+48`.
- **Konsekwencja:** sprzedawca dopłaca do każdej paczki albo wlicza dostawę w cenę towaru; klient nie wybiera
  paczkomatu (dominująca forma dostawy w Polsce), nie zna kosztu ani terminu dostawy przed zamówieniem (wymóg
  informacyjny wobec konsumenta), nie dostaje numeru przesyłki. Obsługa nadaje paczki ręcznie w panelu przewoźnika,
  przepisując adres. Sprzedaż do innych krajów UE jest niemożliwa przez walidację.
- **Rekomendacja:** context albo moduł Shipping: metody dostawy z cennikiem (stała cena, próg darmowej dostawy),
  koszt jako osobna pozycja sumy zamówienia (z VAT), wybór punktu odbioru (widżet mapy przewoźnika), pole przewoźnika
  i numeru przesyłki przy przejściu „ship” oraz link śledzenia w e-mailu. Integrację etykiet (np. przez brokera)
  można odłożyć: na start wystarczy ręczne wpisanie numeru. Rozmiar: M (metody, koszt, numer przesyłki), L z punktami
  odbioru i etykietami.
- **Pewność:** wysoka. Sprawdziłem agregat, DTO żądania, widok kasy, e-mail i specyfikację.

### F3. Brak dokumentów i informacji wymaganych w sprzedaży konsumenckiej

- **Waga:** Critical. **Klasa:** RYZYKO (świadoma decyzja P9: „Bez akceptacji regulaminu (sklep nie ma regulaminu)”,
  `docs/features/checkout/spec.md:48`; włączenie rejestracji i poczty „wymaga polityki prywatności”,
  `docs/features/deploy/spec.md:43-44`).
- **Dowód:** sklep nie ma żadnej strony treści: lista tras `apps/store/app` to katalog, koszyk, kasa, konto i SEO.
  Stopka zawiera tylko działy i jedną linię, `apps/store/src/catalog/SiteFooter.tsx:37-39`:
  ```tsx
  <p className="border-t border-inverse-border pt-3.5 text-xs leading-snug text-inverse-muted">
    © {new Date().getFullYear()} {STORE_NAME} <span aria-hidden="true">·</span> Zdjęcia: Unsplash
  </p>
  ```
  Zero wystąpień (pliki nietestowe `apps/`): `regulamin`, `prywatno|privacy`, `reklamac|complaint`,
  `rękojmi|gwarancj|niezgodno`, `sprzedawc|KRS|REGON`, `czas dostawy|termin dostawy`; `odst[ąa]pi|withdrawal` tylko
  w opisie pola API panelu (wycofanie produktu). Potwierdzenie zamówienia to czysty tekst z pozycjami, sumą i adresem
  (`apps/api/src/Ordering/Application/OrderConfirmationText.php:28-46`), bez danych sprzedawcy, regulaminu, pouczenia
  o odstąpieniu i formularza odstąpienia; mailer wysyła tylko `->text($body)`, bez załączników
  (`apps/api/src/Ordering/Infrastructure/Mail/SymfonyOrderConfirmationMailer.php:33-37`). Rejestracja w Keycloaku nie
  wymaga akceptacji regulaminu (`infra/keycloak/realms/shop.json`: `requiredActions: []`).
- **Konsekwencja:** sprzedaż konsumentowi bez informacji przedumownych (tożsamość i adres sprzedawcy, koszty i termin
  dostawy, prawo odstąpienia w 14 dni, procedura reklamacji, odpowiedzialność za zgodność towaru z umową) i bez
  potwierdzenia umowy na trwałym nośniku naraża sprzedawcę m.in. na wydłużenie terminu odstąpienia do 12 miesięcy
  i na postępowanie UOKiK; brak klauzuli informacyjnej przy zbieraniu danych w kasie narusza art. 13 RODO.
  Jedyny spełniony wymóg tej grupy to etykieta przycisku „Zamawiam z obowiązkiem zapłaty”
  (`CheckoutPage.tsx:538`).
- **Rekomendacja:** (1) strony treści: regulamin, polityka prywatności i cookies, dostawa i płatność, zwroty
  i reklamacje, kontakt z danymi sprzedawcy; na start jako statyczne strony w sklepie, bez CMS; (2) linki w stopce
  i w kasie, klauzula informacyjna przy formularzu, zapis wersji regulaminu obowiązującej przy zamówieniu;
  (3) potwierdzenie zamówienia z danymi sprzedawcy, kosztami, pouczeniem i wzorem formularza odstąpienia (treść albo
  załącznik PDF); (4) akceptacja regulaminu przy rejestracji. Rozmiar: M (kod: S–M, treść prawna: poza kodem).
- **Pewność:** wysoka co do stanu kodu; ocena prawna wymaga prawnika.

### F4. RODO: brak usunięcia, anonimizacji, eksportu i retencji danych klientów

- **Waga:** High. **Klasa:** RYZYKO (odłożone: `docs/features/checkout/spec.md:32`, „anonimizacja danych zamówień
  (RODO, #119)”).
- **Dowód:** zero wystąpień `gdpr|rodo|anonymi|erasure` w plikach nietestowych `apps/`. Dane osobowe są w
  `ordering_order` (`OrderRecord.php:64-79`: `email`, `fullName`, `phone`, `street`, `postalCode`, `city`) i w modelu
  odczytu klientów; jedyne czyszczenie danych to `cart:purge-expired`. Context Customer to tylko `GET /api/v1/me`
  (5 plików w `apps/api/src/Customer`). Realm `shop` nie ma włączonego usuwania konta, a sklep nie linkuje do konsoli
  konta Keycloaka (`apps/store/src/account/AccountMenu.tsx:58-72`: tylko „Moje zamówienia” i „Wyloguj się”).
  Jednocześnie wizja opisuje to jako stan obecny: `docs/vision.md:88` „Usunięcie | na żądanie klienta (RODO), dane
  zamówień anonimizowane”.
- **Konsekwencja:** żądanie klienta „usuńcie moje dane” albo „wyślijcie kopię danych” obsłuży tylko programista
  ręcznym SQL-em na produkcji, w dwóch bazach (API i Keycloak) i w kopiach. Brak polityki retencji: zamówienia gości
  z adresem i telefonem leżą bezterminowo.
- **Rekomendacja:** komenda albo akcja w panelu (rola `admin`): anonimizacja danych kontaktowych zamówień klienta
  albo gościa po e-mailu (z zachowaniem kwot i pozycji dla księgowości), usunięcie konta w Keycloaku przez Admin API,
  eksport danych do JSON; reguła retencji dla zamówień anulowanych przed opłaceniem. Rozmiar: M.
- **Pewność:** wysoka. Sprawdziłem rekordy, komendy, context Customer, realm i menu konta.

### F5. Brak dokumentów sprzedaży i zakupu na firmę; stawki VAT oznaczone jako przykładowe

- **Waga:** High. **Klasa:** RYZYKO (faktury świadomie poza zakresem: `docs/vision.md:366`).
- **Dowód:** zero wystąpień `invoice|faktur`, `paragon`, `nip|taxId|vatId` w plikach nietestowych `apps/`. Żądanie
  zamówienia nie ma pól firmy ani NIP (`PlaceGuestOrderRequest.php:40-117`). Tabela stawek ma zastrzeżenie w kodzie,
  `apps/api/src/Pricing/Domain/Tax/PolishVatRates.php:10-12`:
  ```php
  * The rates are EXAMPLES for the demo data. They have not been verified by an accountant and must be verified
  * before anything is sold. ...
  ```
- **Konsekwencja:** sprzedawca nie wystawi faktury z systemu (klient firmowy jej zażąda, konsument ma do niej prawo
  na żądanie), nie ma numeracji dokumentów ani korekt przy zwrocie, a ewidencję sprzedaży (kasa fiskalna albo
  zwolnienie przy płatnościach bezgotówkowych z ewidencją) trzeba prowadzić obok. Błędna stawka w tabeli przykładowej
  oznacza zaniżony albo zawyżony VAT w każdej cenie danej klasy.
- **Rekomendacja:** przed startem: weryfikacja tabeli stawek przez księgowego (S). Dokumenty: nie budować własnego
  fakturowania; dodać pola „kupuję na firmę” (nazwa, NIP, adres) i integrację z programem do faktur po zdarzeniu
  `OrderPaid` oraz korektę po `OrderReturned` (M). Dane w zamówieniu już na to pozwalają (mocna strona nr 3).
- **Pewność:** wysoka.

### F6. Płatności wymagające ręcznego zwrotu nie są widoczne w panelu

- **Waga:** High (przy prawdziwym operatorze; dziś bez skutku finansowego, bo płatności są udawane).
  **Klasa:** RYZYKO. Warunek: płatność wykonana po terminie, druga płatność za to samo zamówienie albo płatność po
  anulowaniu. Prawdopodobieństwo przy prawdziwym ruchu: średnie (klient kończy BLIK-a albo przelew po 30 minutach,
  dwie karty przeglądarki). Znane i odłożone: `docs/features/order-management/spec.md:32-33` (#221).
- **Dowód:** `apps/api/src/Payment/Application/Command/HandleProviderWebhookHandler.php:79-87`:
  ```php
  $refund = $this->orders->markPaid($payment->orderId(), $payment->id(), $payment->amount(), $executedAt)->refundReason();
  if (null !== $refund) {
      $payment->requireRefund($refund);
      $this->logger->error('Payment webhook: the payment cannot pay its order and requires a manual refund.', [
  ```
  Znacznik trafia do kolumny `refund_required_reason` (`PaymentRecord.php:70`), a jedynym kodem, który ją czyta, jest
  mapowanie w `DoctrinePaymentRepository.php:151,169`. Żaden endpoint panelu jej nie zwraca: kolejki pulpitu
  (`pendingRefunds`, `overdueRefunds`) liczą wyłącznie zwroty z agregatu `Order`
  (`apps/api/src/Ordering/Application/Query/GetOrderQueuesHandler.php`), a `BackofficeOrder` ma tylko `refund`
  zamówienia.
- **Konsekwencja:** klient zapłacił, zamówienie jest anulowane (albo opłacone inną płatnością), a obsługa nie widzi
  tego nigdzie poza logiem błędu. Pieniądze klienta wiszą do czasu jego reklamacji albo chargebacku.
- **Rekomendacja:** przed podłączeniem operatora: lista „Płatności do zwrotu” w panelu i licznik na pulpicie obok
  `pendingRefunds`, docelowo automatyczny zwrot przez operatora (F1). Rozmiar: S–M.
- **Pewność:** wysoka. Przeszukałem `refundRequired|refund_required|RefundReason` w `api/src`, `admin/src`, sklepie.

### F7. Gość traci dostęp do zamówienia po godzinie, a e-maile zamówień w środowisku wdrożeniowym nie wychodzą

- **Waga:** High. **Klasa:** RYZYKO (obie części to świadome decyzje: P7 checkoutu i D10 wdrożenia).
- **Dowód:** jedyny dostęp gościa do zamówienia to podpisane ciasteczko,
  `apps/store/src/checkout/cookies.ts:5-9`:
  ```ts
  * ... The guest has no other way to read the order (decision P7).
  export const CONFIRMATION_COOKIE = "store_order_confirmation";
  export const CONFIRMATION_COOKIE_MAX_AGE_SECONDS = 60 * 60;
  ```
  API nie ma endpointu odczytu zamówienia gościa (tylko `GET /api/v1/orders/{orderId}/payment`). W wartościach
  produkcyjnych poczta zamówień zostaje w klastrze: `infra/k8s/environments/prod/values.yaml:23` `orderMail: mailpit`.
  E-maile nie zawierają linku do zamówienia ani do płatności (`OrderConfirmationText.php:28-46`).
- **Konsekwencja:** po przełączeniu na sprzedaż bez zmiany `orderMail` klient-gość nie ma żadnego trwałego śladu
  zakupu. Nawet z włączoną pocztą gość nie sprawdzi statusu („czy wysłane?”), nie dokończy płatności na innym
  urządzeniu i każde pytanie trafi do obsługi.
- **Rekomendacja:** przełączyć `orderMail: brevo` razem ze startem (i dodać to do listy kontrolnej startu); dodać
  stronę statusu zamówienia gościa dostępną z linku z tokenem w e-mailu (token `accessToken` już istnieje w agregacie).
  Rozmiar: S (przełącznik), M (strona statusu).
- **Pewność:** wysoka.

### F8. Zwroty, anulowania i reklamacje obsługuje wyłącznie pracownik, tylko dla całego zamówienia

- **Waga:** Medium. **Klasa:** RYZYKO (decyzje P1, P2 w `docs/features/order-management/spec.md`).
- **Dowód:** przejścia personelu, `apps/api/src/Ordering/Domain/OrderStatus.php:28-33`:
  ```php
  self::Paid => [StaffTransition::Ship, StaffTransition::Cancel],
  self::Shipped => [StaffTransition::Deliver, StaffTransition::Return],
  self::Delivered => [StaffTransition::Return],
  self::New, self::Cancelled, self::Returned => [],
  ```
  Zwrot zawsze na pełną kwotę: `Order.php:288` `Refund::due($this->totals->gross(), ...)`. Sklep nie ma żadnej akcji
  klienta na zamówieniu (trasy konta to tylko lista i szczegóły). Zamówienia `new` nie anuluje ani klient, ani
  pracownik (czeka na wygaśnięcie). Towar ze zwrotu nie wraca na stan (`Order.php:276`: „The goods do not go back on
  the stock by themselves”). Brak obsługi reklamacji (zero wystąpień).
- **Konsekwencja:** zwrot jednej z trzech pozycji (typowy przypadek w odzieży i obuwiu) wymaga obejścia: rejestracja
  zwrotu całości z notatką i ręczne rozliczenie różnicy poza systemem, przez co raporty sprzedaży i kwota zwrotu
  w panelu są nieprawdziwe. Klient zgłasza odstąpienie e-mailem albo telefonem, bez numeru sprawy.
- **Rekomendacja:** w pierwszych 3 miesiącach: zwrot częściowy (wybór pozycji i ilości, kwota zwrotu z pozycji),
  formularz zgłoszenia zwrotu w koncie i dla gościa, opcjonalne przyjęcie na stan przy rejestracji zwrotu. Rozmiar: L.
- **Pewność:** wysoka.

### F9. Karta produktu nie udźwignie realnej oferty: brak opisu, parametrów, SKU, EAN i danych producenta

- **Waga:** Medium. **Klasa:** RYZYKO.
- **Dowód:** agregat produktu, `apps/api/src/Catalog/Domain/Product/Product.php:19-29`: `name`, `slug`,
  `shortDescription`, `categories`, `taxClass`, `images`, `variants`, `status`. Opis ma limit 500 znaków
  (`ShortDescription.php:12`), wariant to tylko `id` i wartości najwyżej dwóch osi (`ProductVariant.php:10-11`,
  `VariantAxes.php:14`). Zero wystąpień całych słów `brand|manufacturer|producent|ean|gtin|sku|weight|gpsr|description`
  w `apps/api/src/Catalog/Domain` poza komentarzem o krótkim opisie. Strona produktu pokazuje nazwę, krótki opis,
  cenę i wybór wariantu (`apps/store/src/catalog/ProductDetailsView.tsx:29-46`).
- **Konsekwencja:** (1) magazyn nie ma SKU ani EAN, więc kompletacja i przyjęcia idą po nazwie i etykiecie wariantu;
  integracja z hurtownią, ERP albo feedem Google Merchant nie ma klucza; (2) nie ma gdzie podać danych producenta
  i informacji o bezpieczeństwie, których dla ofert online wymaga rozporządzenie GPSR (do potwierdzenia przez
  prawnika); (3) brak tabeli rozmiarów, składu, parametrów technicznych, czyli głównych cech towaru, na podstawie
  których klient kupuje sprzęt outdoorowy, co podnosi liczbę zwrotów; (4) wizja wymienia „atrybuty” w Catalog
  (`docs/vision.md:197`), a są tylko osie wariantów.
- **Rekomendacja:** SKU i EAN na wariancie, marka/producent z danymi kontaktowymi, długi opis (bezpieczny format),
  lista parametrów (nazwa–wartość) na produkcie. Rozmiar: M.
- **Pewność:** wysoka co do modelu; wymóg GPSR do weryfikacji prawnej.

### F10. Konto klienta kończy się na historii zamówień

- **Waga:** Medium. **Klasa:** RYZYKO (decyzje P5, P10 checkoutu; #117, #118).
- **Dowód:** `apps/api/src/Customer` ma 5 plików i jeden endpoint (`MeController.php:17`, odpowiedź `id`, `email`,
  `name`). Brak tras profilu i adresów w sklepie (`apps/store/app/konto/` ma tylko `zamowienia`). Zalogowany klient
  wpisuje adres przy każdym zamówieniu. Rejestracja, logowanie, weryfikacja e-maila i reset hasła działają przez
  Keycloaka (`shop.json`: `registrationAllowed: true`, `verifyEmail: true`, `resetPasswordAllowed: true`), ale na
  domyślnym motywie Keycloaka (`loginTheme: null`) i są wyłączone w środowisku wdrożeniowym
  (`prod/values.yaml:22` `customerAccounts: false`).
- **Konsekwencja:** konto daje klientowi niewiele ponad zakup jako gość; brak książki adresowej obniża konwersję
  powracających klientów; zmiana imienia, e-maila albo hasła nie ma wejścia ze sklepu; zamówienia złożone jako gość
  nie pojawią się po założeniu konta.
- **Rekomendacja:** książka adresowa i strona „Moje dane” (albo link do konsoli konta Keycloaka), usunięcie konta
  (łącznie z F4), motyw logowania w stylu sklepu. Rozmiar: M.
- **Pewność:** wysoka.

### F11. Operacje sprzedawcy: wszystko pojedynczo i ręcznie

- **Waga:** Medium. **Klasa:** RYZYKO (warunek: asortyment i ruch większe niż demo).
- **Dowód:** cena i stan zmieniają się wyłącznie po jednym wariancie: `PUT /api/v1/admin/variants/{variantId}/price`,
  `POST .../stock/receipts`, `POST .../stock/corrections` (brak operacji zbiorczych w `openapi-admin.json`). Brak
  importu i eksportu (jedyne trafienia `csv` w `apps/api/src` to fragment nazwy klasy `CatalogStatisticsView`). Wszystkie e-maile idą tylko do
  klienta (`->to($order->email)` w obu mailerach, brak innych odbiorców w `api/src`). Panel nie ma wydruku ani
  dokumentu kompletacji. Raport sprzedaży to trzy okresy na pulpicie (`DashboardSalesResponse`: `today`, `last7Days`,
  `last30Days`).
- **Konsekwencja:** wprowadzenie 1000 wariantów z cenami i stanami to kilka tysięcy ręcznych zapisów; dostawa
  z hurtowni nie da się przyjąć plikiem; o nowym zamówieniu sprzedawca dowiaduje się tylko, patrząc na pulpit;
  księgowość nie dostanie zestawienia sprzedaży za miesiąc z podziałem na stawki VAT.
- **Rekomendacja:** import CSV produktów, cen i stanów (z podglądem i walidacją), eksport zamówień i raport VAT za
  okres, e-mail do sprzedawcy o opłaconym zamówieniu, lista kompletacyjna. Rozmiar: M–L (etapami).
- **Pewność:** wysoka.

### F12. Kopie zapasowe wyłączone w wartościach produkcyjnych

- **Waga:** Medium. **Klasa:** RYZYKO (świadome do czasu utworzenia bucketu i sekretu; wizja uzależnia „prawdziwe
  dane i publiczny start” od ćwiczenia odtworzenia, `docs/vision.md:354-355`).
- **Dowód:** `infra/k8s/environments/prod/values.yaml:74-76`:
  ```yaml
  backup:
    enabled: false
    repository: s3:https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com/shop-backups
  ```
- **Konsekwencja:** awaria dysku jedynego serwera przy włączonej sprzedaży oznacza utratę zamówień, płatności
  i danych klientów.
- **Rekomendacja:** pozycja listy kontrolnej startu sprzedaży: bucket, sekret, `enabled: true`, jedno ćwiczenie
  odtworzenia. Rozmiar: S.
- **Pewność:** wysoka co do pliku; stanu faktycznego serwera nie sprawdzałem.

### F13. Dokumentacja opisuje funkcje, których kod nie ma

- **Waga:** Low. **Klasa:** BŁĄD (wobec zasady projektu „dokumentacja opisuje wyłącznie stan obecny”).
- **Dowód:** `docs/vision.md:88` (anonimizacja danych zamówień na żądanie klienta), `:89` i `:203` (Customer:
  „profil, adresy”), `:215` (stawka VAT „z klasy podatkowej i okresu obowiązywania stawki”). W kodzie: brak
  anonimizacji (F4), brak profilu i adresów (F10), a stawka zależy tylko od klasy,
  `apps/api/src/Pricing/Domain/Tax/VatRates.php`: `public function rateFor(TaxClass $taxClass): VatRate;`.
- **Konsekwencja:** czytelnik wizji (nowy programista, agent planujący funkcję, audytor RODO) przyjmie, że usuwanie
  danych i adresy istnieją; zmiana stawki VAT od określonej daty nie jest obsłużona, choć wizja to sugeruje (dziś:
  zmiana tabeli, wdrożenie i `pricing:reconcile-tax --apply`, czyli nowa stawka działa od chwili wdrożenia, nie od daty).
- **Rekomendacja:** poprawić trzy miejsca wizji albo oznaczyć je jako docelowe. Rozmiar: S.
- **Pewność:** wysoka.

### F14. Tylko Polska, PLN i język polski na sztywno

- **Waga:** Low. **Klasa:** PREFERENCJA (zgodne z wizją: „Wiele walut, wiele języków” poza zakresem).
- **Dowód:** `Currency.php:10-13` (tylko `PLN`), `ShippingAddress.php:15,77-80`, `Contact.php:15` (telefon `+48`
  i 9 cyfr), brak biblioteki i18n w obu frontach (zero wystąpień `i18n|next-intl|vue-i18n`), teksty po polsku w kodzie.
- **Konsekwencja:** sprzedaż do innych krajów UE wymaga zmian w domenie (adres, telefon), w VAT (procedura OSS po
  przekroczeniu progu sprzedaży wysyłkowej) i w dostawie. Dla startu w Polsce bez znaczenia.
- **Rekomendacja:** odłożyć świadomie; przy projektowaniu dostawy (F2) nie utrwalać założenia „tylko PL” w nowych
  tabelach (kolumna `country` w zamówieniu już jest).
- **Pewność:** wysoka.

### F15. Brak analityki, zgód marketingowych i feedu produktowego

- **Waga:** Low. **Klasa:** PREFERENCJA.
- **Dowód:** zero wystąpień `gtag|google-analytics|googletagmanager|plausible|matomo|fbq`, `newsletter`,
  `cookieConsent|cookie banner`, `abandon|porzucon`, `wishlist`, `productFeed|feed.xml` w plikach nietestowych.
- **Konsekwencja:** sprzedawca nie zna źródeł ruchu ani konwersji lejka; nie uruchomi kampanii produktowych Google.
  Z drugiej strony: skoro sklep używa wyłącznie ciasteczek niezbędnych (sesja, koszyk, potwierdzenie), baner zgody nie
  jest dziś potrzebny; wystarczy informacja o cookies w polityce prywatności (do potwierdzenia przez prawnika, także
  dla Sentry w przeglądarce).
- **Rekomendacja:** w pierwszych 3 miesiącach analityka bez ciasteczek albo GA4 z banerem zgody i zdarzeniami
  e-commerce; feed Google Merchant po dodaniu EAN i marki (F9). Rozmiar: S–M.
- **Pewność:** wysoka co do braku kodu.

## Mocne strony

1. **To, co jest, jest domknięte.** Przepływ zakupu działa od koszyka do zwrotu z obsługą przypadków brzegowych:
   kwota oczekiwana chroni przed zmianą ceny w trakcie zakupu (`PlaceGuestOrderRequest.php:104-110`), `Idempotency-Key`
   na zamówieniu i płatności, wygasanie nieopłaconych zamówień z oknem na spóźnione webhooki (`Order.php:31-32,
   207-222`), ponowienie płatności na stronie potwierdzenia (`PaymentConfirmation.tsx:42-47`), deduplikacja e-maili
   (`NotificationClaim`). Nie znalazłem funkcji MVP urwanej w połowie przepływu.
2. **Omnibus zrobiony porządnie, nie etykietą.** Najniższa cena z 30 dni liczona z historii cen i wcześniejszych
   promocji (`apps/api/src/Pricing/Domain/Promotion/LowestPriorPrice.php:10-16,37-66`), blokada zmiany ceny
   w aktywnej promocji, a sklep pokazuje oznaczenie „Promocja” wyłącznie razem z linią Omnibus
   (`apps/store/src/catalog/PromotionNotice.tsx:10-19`). Brak przekreślonych cen eliminuje typowe naruszenie.
3. **Zamówienie jest gotowe pod dokumenty księgowe.** Pozycja przechowuje kopię netto, VAT, brutto, stawki w punktach
   bazowych i klasy podatkowej oraz rabat rozłożony na linie (`OrderLineRecord.php:40-65`), a zamówienie sumy netto,
   VAT i brutto (`OrderRecord.php:88-94`). Fakturę da się wystawić z samych danych zamówienia.
4. **Port płatności jest dobrym miejscem na prawdziwego operatora**: jeden interfejs, weryfikacja podpisu przed
   odczytem treści, czas wykonania płatności z webhooka, a nie z zegara sklepu (`PaymentProvider.php:27-55`).
5. **Ślad audytowy operacji**: historia statusów zamówienia z aktorem i notatką (`Order.php:463`), historia cen
   i stanów z endpointami w panelu (`/price-history`, `/stock-history`), wykonawca zwrotu pieniędzy (`Refund.php:16-19`).
6. **Role personelu egzekwowane w API** (cztery role, votery), kolejki pracy na pulpicie (`paidToShip`,
   `pendingRefunds`, `overdueRefunds`, `lowStockVariants`), termin zwrotu pieniędzy 14 dni pilnowany filtrem „po terminie”.
7. **Ograniczenia demo są przełącznikami, nie kodem** (`prod/values.yaml:17-23`: `noindex`, `demoNotice`,
   `customerAccounts`, `orderMail`), a pasek demo uczciwie informuje o symulowanych płatnościach.
8. **Przycisk „Zamawiam z obowiązkiem zapłaty”** (`CheckoutPage.tsx:538`) i ceny brutto w całym sklepie są zgodne
   z wymogami konsumenckimi od początku.

## Zbędna złożoność

W tym obszarze nie stwierdziłem. Uwaga odwrotna: skala inżynierii (10 mln produktów, outbox, trzy warstwy cache, k3s,
Argo CD) wyraźnie wyprzedza kompletność handlową. Sklep jest przygotowany na 60 tys. jednoczesnych użytkowników, ale
nie na pierwszego klienta, który chce zapłacić BLIK-iem i odebrać paczkę w paczkomacie. To konsekwencja celu projektu
(demonstracja architektury i workflow), nie błąd, ale przy decyzji o prawdziwej sprzedaży priorytety trzeba odwrócić.

## Braki

### Tabela kompletności

Waga: **PR** blokujące prawnie, **OP** blokujące operacyjnie, **W** ważne, **M** miłe. Rozmiar: S (dni), M (1–2
tygodnie), L (ponad 2 tygodnie).

| Obszar | Pozycja | Stan | Dowód | Waga | Rozmiar |
|---|---|---|---|---|---|
| Płatności | Prawdziwy operator (P24, PayU, Stripe, BLIK) | brak | `ProviderCode.php:12-13`: tylko `demo-transfer`, `demo-card` | OP | M |
| Płatności | Zwrot środków przez operatora | brak | port bez `refund` (`PaymentProvider.php`); `Refund.php:8-10` ręczny | OP | M |
| Płatności | Płatność przy odbiorze, przelew tradycyjny | brak | brak `cashOnDelivery\|pobrani`; termin 30 min (`Order.php:31`) | M | M |
| Płatności | Ponowienie płatności, wygasanie, webhooki z podpisem | jest | `PaymentConfirmation.tsx`, `HandleProviderWebhookHandler.php` | – | – |
| Płatności | Widok płatności do ręcznego zwrotu | brak | F6 | OP | S–M |
| Dostawa | Metody i cennik, darmowa dostawa od kwoty | brak | `OrderTotals.php:31-36`; checkout P3 | OP/PR | M |
| Dostawa | Przewoźnicy, paczkomaty, punkty odbioru, etykiety | brak | brak integracji w `apps/` | OP | L |
| Dostawa | Numer przesyłki i śledzenie | brak | `ChangeOrderStatusRequest.php`, `OrderShippedText.php` | W | S |
| Dostawa | Kraje inne niż PL | brak | `ShippingAddress.php:77-80` | M | M |
| Podatki | Stawki VAT, netto/brutto, klasa podatkowa | częściowo | działa; stawki „EXAMPLES” (`PolishVatRates.php:10-12`), bez okresu obowiązywania | PR | S |
| Dokumenty | Faktury, paragony, numeracja, korekty | brak | brak `invoice\|faktur\|paragon` | PR/OP | M |
| Dokumenty | NIP i zakup na firmę | brak | brak pól w `PlaceGuestOrderRequest.php` | W | S |
| Prawo | Regulamin, polityka prywatności, dane sprzedawcy | brak | brak tras treści; `SiteFooter.tsx:37-39` | PR | M |
| Prawo | Zgody i klauzula informacyjna w kasie | brak | `CheckoutPage.tsx:512-540` | PR | S |
| Prawo | Przycisk „Zamówienie z obowiązkiem zapłaty” | jest | `CheckoutPage.tsx:538` | – | – |
| Prawo | Potwierdzenie na trwałym nośniku (pełna treść) | częściowo | e-mail z pozycjami i sumą, bez pouczeń; w prod nie wychodzi | PR | S |
| Prawo | Odstąpienie od umowy, zwroty (RMA) po stronie klienta | brak | F8; brak formularza i pouczenia | PR | M |
| Prawo | Reklamacje | brak | brak `reklamac\|complaint` | PR (procedura), W (system) | S–M |
| Prawo | RODO: eksport, usunięcie, retencja, rejestr zgód | brak | F4 | PR | M |
| Prawo | Omnibus: najniższa cena z 30 dni | jest | `LowestPriorPrice.php`, `PromotionNotice.tsx` | – | – |
| Prawo | Omnibus: oznaczanie opinii | nie dotyczy | brak opinii | – | – |
| Prawo | Baner cookies i zgoda na analitykę | nie dotyczy dziś | brak analityki; potrzebna informacja o cookies | W | S |
| Prawo | Dane producenta i bezpieczeństwo produktu (GPSR) | brak | F9 | PR (do potwierdzenia) | M |
| Komunikacja | Potwierdzenie, płatność, wysyłka, anulowanie, zwrot przyjęty | jest | `NotificationKind.php` (5 rodzajów), tekstowe | – | – |
| Komunikacja | Dostarczono, zwrot pieniędzy wykonany | brak | decyzja P5 order-management | M | S |
| Komunikacja | Szablony HTML z marką | brak | brak `templates/`, `->text()` | M | S–M |
| Komunikacja | Dostawca poczty | częściowo | Brevo gotowe za przełącznikiem; `orderMail: mailpit` w prod | OP | S |
| Komunikacja | Reset hasła, weryfikacja e-maila | jest | Keycloak (`shop.json`) | – | – |
| Komunikacja | Powiadomienia dla sprzedawcy | brak | odbiorcą zawsze `$order->email` | W | S |
| Konto | Rejestracja, logowanie, reset hasła | jest (wyłączone w prod) | Keycloak; `customerAccounts: false` | W | S |
| Konto | Historia i szczegóły zamówień | jest | `/konto/zamowienia`, `GET /api/v1/me/orders` | – | – |
| Konto | Książka adresowa, zmiana danych | brak | Customer: tylko `/me` | W | M |
| Konto | Usunięcie konta | brak | F4 | PR | S–M |
| Konto | Dostęp gościa do statusu zamówienia | brak | ciasteczko 60 min (`cookies.ts:9`) | W | M |
| Katalog | Warianty (do 2 osi), kategorie, zdjęcia | jest | `Product.php`, `VariantAxes.php:14` | – | – |
| Katalog | Atrybuty i parametry, długi opis, SKU, EAN, marka | brak | F9 | OP/W | M |
| Katalog | Wyszukiwarka i filtry (opcje, cena, dostępność, sort) | jest | `GET /api/v1/products`: `q`, `option`, `priceMin`, `priceMax`, `available`, `sort` | – | – |
| Katalog | Produkty powiązane, opinie, lista życzeń, powiadomienie o dostępności | brak | zero wystąpień | M | M każde |
| Katalog | Treści: strony statyczne, CMS, blog | brak | brak tras | PR (strony prawne), M (blog) | S–M |
| Katalog | Wiele języków i walut | brak | F14 | M | L |
| Operacje | Zamówienia: lista, szczegóły, wysyłka, dostarczenie, anulowanie, zwrot | jest | `status-changes`, `refund-completion` | – | – |
| Operacje | Częściowa realizacja i zwrot, edycja zamówienia | brak | order-management „Poza zakresem” | W | L |
| Operacje | Import i eksport produktów, cen, stanów | brak | F11 | OP przy realnym asortymencie | M |
| Operacje | Raporty sprzedaży | częściowo | pulpit: 3 okresy; brak eksportu i raportu VAT | W | M |
| Operacje | Role i uprawnienia | jest | 4 role, votery; konta przez Keycloak | – | – |
| Operacje | Log audytowy | częściowo | historia cen, stanów, statusów; brak wspólnego dziennika i historii zmian produktu w panelu | M | M |
| Operacje | Integracje (ERP, księgowość, marketplace) | brak | brak | W (księgowość), M (reszta) | M–L |
| Marketing | Promocje i kody rabatowe | jest | `/admin/promotions`, `/admin/discount-codes` | – | – |
| Marketing | Analityka, piksele | brak | F15 | W | S |
| Marketing | Feed Google Merchant | brak | F15 | W | S–M |
| Marketing | Newsletter, porzucone koszyki, kody polecające | brak | zero wystąpień | M | M każde |
| SEO | Metadane, JSON-LD, mapy strony, przekierowania | jest | `apps/store/src/seo`, `app/sitemap.xml` | – | – |

### Czy to, co jest, jest zrobione do końca?

Tak w granicach przyjętych decyzji: nie znalazłem przepływu MVP urwanego w połowie. Funkcje „w połowie” z punktu
widzenia realnej sprzedaży to:

- **Zwrot pieniędzy**: system zna termin i wykonawcę, ale nie wykonuje przelewu ani nie widzi zwrotów oznaczonych
  przez Payment (F1, F6).
- **Wysyłka**: jest status i e-mail, nie ma przewoźnika, numeru ani kosztu (F2).
- **Zwrot towaru**: rejestrowany tylko w całości i tylko przez pracownika, bez powrotu na stan (F8).
- **Konto klienta**: tożsamość i historia, bez danych i adresów (F10).
- **Poczta**: pięć wiadomości gotowych, w środowisku wdrożeniowym skierowanych do Mailpita (F7).
- **Kopie zapasowe**: mechanizm z weryfikacją i alertami gotowy, w wartościach produkcyjnych wyłączony (F12).
- **VAT**: mechanizm kompletny, tabela stawek niezweryfikowana (F5).

### Lista 1: minimum do legalnej i bezpiecznej sprzedaży pierwszemu klientowi

1. Prawdziwy operator płatności (jeden agregator z BLIK-iem) i wyłączenie symulatorów (F1).
2. Lista płatności do ręcznego zwrotu w panelu (F6), jeśli zwroty przez API operatora nie wejdą od razu.
3. Dostawa w wersji minimalnej: co najmniej jedna metoda z ceną doliczaną do zamówienia, informacja o terminie,
   numer przesyłki wpisywany przy wysyłce (F2).
4. Strony: regulamin, polityka prywatności i cookies, dostawa i płatność, zwroty i reklamacje, kontakt z danymi
   sprzedawcy; linki w stopce i w kasie; klauzula informacyjna przy formularzu (F3).
5. Potwierdzenie zamówienia z danymi sprzedawcy i pouczeniem o odstąpieniu (wzór formularza), wysyłane naprawdę:
   `orderMail: brevo` (F3, F7).
6. Procedura RODO choćby ręczna, ale opisana i przetestowana: anonimizacja zamówień i usunięcie konta (F4).
7. Weryfikacja tabeli stawek VAT przez księgowego i ustalony sposób dokumentowania sprzedaży (faktura z zewnętrznego
   programu na żądanie, ewidencja sprzedaży) (F5).
8. Kopie zapasowe włączone i jedno ćwiczenie odtworzenia (F12); wyłączenie `demoNotice` i `noindex`.
9. Dane producenta i bezpieczeństwa na karcie produktu, jeśli prawnik potwierdzi wymóg GPSR dla asortymentu (F9).
10. Prawdziwe treści: własne zdjęcia i opisy produktów zamiast danych demo.

### Lista 2: potrzebne w pierwszych 3 miesiącach działania

1. Paczkomaty i punkty odbioru z wyborem na mapie, generowanie etykiet (F2).
2. Zwroty przez API operatora płatności, zwroty częściowe, formularz zwrotu dla klienta (F1, F8).
3. Faktury z integracją z programem księgowym, zakup na firmę z NIP, korekty (F5).
4. SKU, EAN, marka, długi opis i parametry produktu (F9); import CSV produktów, cen i stanów; eksport zamówień
   i raport VAT (F11).
5. Strona statusu zamówienia dla gościa z linku w e-mailu; e-maile HTML z marką; powiadomienie sprzedawcy
   o opłaconym zamówieniu (F7, F11).
6. Konto: książka adresowa, edycja danych, usunięcie konta, powiązanie zamówień gościa (F10); włączenie rejestracji.
7. Analityka z obsługą zgody i feed Google Merchant (F15).
8. Anonimizacja i retencja jako funkcja panelu zamiast procedury ręcznej (F4).

### Lista 3: można świadomie odłożyć

- Wiele walut i języków, sprzedaż poza Polskę (F14).
- Opinie, lista życzeń, produkty powiązane, powiadomienia o dostępności.
- Newsletter, porzucone koszyki, kody polecające.
- CMS i blog (strony prawne mogą być statyczne).
- Płatność przy odbiorze i przelew tradycyjny.
- Integracje z ERP i marketplace, wiele magazynów.
- Wspólny dziennik audytowy, częściowa realizacja zamówień, edycja zamówienia po złożeniu.
- Logowanie społecznościowe.

## Czego nie zweryfikowano

- Niczego nie uruchamiałem: działanie przepływów (rejestracja w Keycloaku, wysyłka przez Brevo, ponowienie płatności)
  oceniam z kodu i konfiguracji, nie z przebiegu.
- Stanu faktycznego serwera wdrożeniowego (czy kopie są włączone, jakie wartości mają przełączniki): znam tylko
  `environments/prod/values.yaml` z migawki.
- Ocen prawnych: zakres obowiązków (ustawa o prawach konsumenta, RODO, przepisy o cookies, GPSR, kasa fiskalna,
  poprawność stawek VAT) wymaga potwierdzenia przez prawnika i księgowego. Wagi „blokujące prawnie” to moja ocena
  ryzyka, nie opinia prawna.
- Treści zgłoszeń, na które powołują się specyfikacje (#117–#120, #179, #181, #221): nie zaglądałem do GitHuba,
  więc nie wiem, co dokładnie planują.
- Jakości wykonania istniejących funkcji (współbieżność, bezpieczeństwo, wydajność): poza moim obszarem; oceniałem
  kompletność przepływów, nie ich odporność.
- Szablonów e-maili i stron logowania Keycloaka w języku polskim (domyślny motyw): nie sprawdzałem ich treści.
- Rozmiary S/M/L to szacunek z samej lektury kodu, bez planu implementacji.
