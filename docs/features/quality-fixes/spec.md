# QUALITY-FIXES: poprawki zgłoszeń agentów po epikach MVP

Epik poprawek po epikach CART, SEARCH, PAYMENT, CATALOG-ADMIN, ORDER-MANAGEMENT i DISCOUNT-CODES. Każda sekcja to jedno
issue zgłoszone przez agenta i przyjęte w triage. Działanie funkcji opisują ich specyfikacje; ta opisuje tylko zmiany.

Issues: #342, #113, #130, #197, #213, #247, #248, #256, #257, #339, #340, #341
Zakres: `apps/api/src/Cart/UI/Http`, `apps/api/tests/Functional/Cart`, `apps/api/src/Catalog/Application/Query`, `apps/api/src/Catalog/Domain/Product`, `apps/api/src/Catalog/UI/Http/Validation`, `apps/api/src/Catalog/Infrastructure/Persistence`, `apps/api/tests/Functional/Catalog`, `apps/api/tests/Unit/Catalog`, `apps/api/tests/Integration/Catalog`, `apps/api/src/Payment`, `apps/api/tests/Unit/Payment`, `apps/api/tests/Functional/Payment`, `apps/api/src/Ordering/Domain`, `apps/api/src/Ordering/UI/Http`, `apps/api/tests/Functional/Ordering`, `apps/api/tests/Unit/Ordering`, `apps/api/src/Inventory/UI/Http`, `apps/api/tests/Functional/Inventory`, `apps/api/src/Pricing/UI/Http`, `apps/api/tests/Functional/Pricing`, `apps/api/src/Shared/UI/Http`, `apps/api/tests/Unit/Shared`, `apps/api/tests/Functional/Shared`, `apps/api/migrations`, `apps/api/deptrac.yaml`, `apps/api/openapi.json`, `apps/api/openapi-admin.json`, `apps/admin/src/api/schema.d.ts`, `apps/api/CLAUDE.md`, `apps/store/CLAUDE.md`, `apps/store/src/checkout`, `apps/admin/src/views`, `apps/admin/tests`, `e2e/tests/checkout-customer.spec.ts`, `e2e/tests/discount-codes.spec.ts`, `e2e/support`, `docs/features/catalog-admin/spec.md`, `docs/features/variants/spec.md`, `docs/features/walking-skeleton/spec.md`, `docs/features/order-management/spec.md`, `docs/features/checkout/spec.md`, `docs/features/payment/spec.md`

## Cel i zakres

- Walidacja API daje najwyżej jedno naruszenie na pole, po polsku, także dla brakującego klucza (#113, #257).
- Teksty produktu i notatki pracownika: znaki sterujące i same białe znaki Unicode dają 422 przy polu albo pusty tekst,
  nie 500 ani zapisane śmieci (#247, #256).
- Unikalność kombinacji wartości wariantu liczy ten sam klucz w domenie i w bazie (#248).
- Filtr kategorii sklepu przyjmuje UUID niezależnie od wielkości liter (#197).
- Adapter płatności nie przyjmuje webhooka przy pustym sekrecie (#213).
- Strona potwierdzenia płatności nie dostaje stanu, którego nie było w bazie: status płatności czyta płatność przed
  zamówieniem (#339).
- Kasa i panel nie mówią nieprawdy o stanie ekranu (#340, #341).

## Decyzje użytkownika

| Issue | Decyzja |
|---|---|
| #130 | Bez zadania: kryterium issue jest nieaktualne (CATALOG-ADMIN K5 celowo zmienia warianty przez `save()`); końcowy PR epiku zamyka issue. |
| #248 | Klucz porównania liczy domena: `DROP EXPRESSION` na `values_key`, kolumna dopuszcza NULL, repozytorium zapisuje klucz z `comparisonKey()`; `NOT NULL` w osobnej, późniejszej zmianie. |
| #257 | Brak dowolnego klucza (także takiego, który dopuszcza `null`) daje 422 „To pole jest wymagane.” przy kluczu; kontrakt OpenAPI bez zmian. Wszystkie siedem endpointów (Ordering, Inventory, Pricing), jeden wspólny mechanizm w `apps/api/src/Shared/UI/Http`. |
| #256 | Ostrzejszy `ControlCharacters` obejmuje wszystkie jednoliniowe pola Ordering (notatka, dane kontaktowe, adres zamówienia). |
| #247 | Krótki opis może zawierać LF; z samych białych znaków jest pusty i przyjęty. Pozostałe teksty produktu są jednoliniowe. |
| #341 | Poprawka obejmuje też `PromotionFormView.vue` i `ProductFormView.vue` (F9 z #251); #251 zostaje otwarte z pozostałymi punktami. |

Poza zakresem: nowe funkcje; pozostałe zgłoszenia `bug`, `tech-debt` i `ux` z backlogu (także #114, #251 poza F9,
#272, #336); walidacja UUID poza żądaniami koszyka (#113).

## #113: UUID z kilkoma błędami w żądaniach koszyka

**Problem.** `Assert\Uuid` (tryb ścisły) nie przerywa po pierwszym błędzie: nil (`00000000-…`), max (`ffffffff-…`) i każdy
UUID z błędną wersją i wariantem (np. `12345678-1234-0234-0234-123456789012`) dają dwa naruszenia, a napis o znak za
długi z takimi bitami trzy. `Assert\Sequentially` tego nie zatrzymuje, bo to jedno ograniczenie. Pusty napis `Assert\Uuid`
pomija, więc trafia do domeny i kończy się 404. Dotyczy `variantId` w `AddCartItemRequest` i `guestCartId`
w `MergeGuestCartRequest` (`apps/api/src/Cart/UI/Http/`). Zasada z #82: jedno pole daje najwyżej jedno naruszenie.

**Zachowanie.** Każda wartość `variantId` i `guestCartId` daje najwyżej jedno naruszenie pola. Niepoprawny UUID, także
pusty napis: 422 „Identyfikator wariantu musi być UUID.” albo „Identyfikator koszyka musi być UUID.”. Ograniczenia
pola idą po kolei (`Assert\Sequentially`): `NotNull` („… jest wymagany.”), `Type('string')`, `NotBlank` (z komunikatem
o UUID, nie „wymagany”) i `Assert\Uuid` w `Assert\AtLeastOneOf` bez komunikatów wewnętrznych, który zgłasza jeden
komunikat pola.

**Kryteria.** W `apps/api/tests/Functional/Cart/CartRequestViolationCases.php` przypadki: pusty napis, same spacje, nil,
max, zła wersja i wariant, za długi z takimi bitami, dla obu pól; dokładnie jedno naruszenie, `propertyPath`, komunikat.

## #197: UUID kategorii wielkimi literami w sklepie

**Problem.** `ListProductsHandler` (`apps/api/src/Catalog/Application/Query/ListProductsHandler.php`) woła
`CategoryId::fromString()` (które robi `strtolower`) tylko po to, żeby sprawdzić istnienie kategorii, a dalej przekazuje
surowy `$query->categoryId` do `facetCategories()`, `find()` i zapytania indeksu. `GET /api/v1/products?category=<UUID
wielkimi literami>` daje `total: 0`. Lista panelu (`SearchBackofficeProductsHandler`) już normalizuje.

**Zachowanie.** `ListProductsHandler` raz, na początku, tworzy `CategoryId::fromString($query->categoryId)` i dalej używa
tylko jego postaci kanonicznej (`toString()`, małe litery): do sprawdzenia istnienia kategorii, `facetCategories()`
i kryteriów indeksu. Kategoria wielkimi literami daje ten sam wynik (produkty, `total`, fasety) co małymi. Nieznana
kategoria wielkimi literami to 404, niepoprawny UUID zachowuje się jak dotąd.

**Kryteria.** Testy funkcjonalne listy sklepu (`ProductsEndpointTest`): kategoria wielkimi literami zwraca tę samą
odpowiedź co małymi (`total`, fasety), nieznana kategoria wielkimi literami to 404. Test listy panelu
(`BackofficeProductsEndpointTest`, `testTheListFiltersByCategoryGivenInUpperCase`) to test regresji: przechodzi też bez
zmiany.

## #213: pusty sekret webhooka

**Problem.** `DemoCardProvider` i `DemoTransferProvider` (`apps/api/src/Payment/Infrastructure/Provider/`) liczą HMAC kluczem
ze zmiennej środowiska. Pusta zmienna daje pusty klucz, a webhook podpisany pustym kluczem przechodzi. Chart wymaga
niepustych sekretów (`required` w `secret.yaml`), a `init-env.sh` generuje 32 znaki, więc to ryzyko złej konfiguracji.

**Zachowanie.** Adapter z pustym sekretem odrzuca każdy webhook wyjątkiem `InvalidWebhook` z osobną przyczyną
(`InvalidWebhook::emptySecret()`, „Webhook secret: empty webhook secret.”), zanim sprawdzi nagłówek i podpis.
`WebhookController` zapisuje ją jak inne przyczyny (`warning`) i odpowiada jak na zły podpis (400); płatność bez zmian.
Start kontenera bez zmian (adapter nie rzuca w konstruktorze). `docs/features/payment/spec.md` ma tę regułę.

**Kryteria.** Testy adapterów (`apps/api/tests/Unit/Payment/Infrastructure/Demo*WebhookTest.php`): pusty sekret i podpis
policzony pustym kluczem, podpis z wektora, brak nagłówka i treść niebędąca JSON → `InvalidWebhook` z przyczyną „empty
webhook secret”, bez słowa „signature”; niepusty sekret działa jak dotąd.

## #247: znaki sterujące i białe znaki Unicode w tekstach produktu

**Problem.** `ValidProductWriteValidator` (`apps/api/src/Catalog/UI/Http/Validation/`) sprawdza pustkę przez `trim()` (tylko
ASCII) i nie odrzuca znaków sterujących; value objecty domeny też używają `trim()`. NUL w `images[].alt`
i `variantAxes[]` daje 500 (jsonb), w `name`, `shortDescription` i wartościach wariantów zapis z uciętą wartością.
Nazwa, oś albo wartość z samych NBSP przechodzi (nazwa z NBSP daje naruszenie przy `slug`, nie przy `name`). Walidator
kopiuje limity domeny jako literały.

**Zachowanie.**
- Jedna klasa reguł tekstu, `ProductText`, w `apps/api/src/Catalog/Domain/Product` (własna kopia, wzorem
  `Ordering\Domain\ControlCharacters`; bez wspólnych value objectów między modułami): przycinanie białych znaków Unicode
  (`\s` i `\p{Z}` z `/u`), wykrywanie znaków niedozwolonych: C0 i DEL (U+0000–U+001F, U+007F) oraz U+0085, U+2028,
  U+2029, z wyjątkiem LF (U+000A) w krótkim opisie. Niepoprawny UTF-8 (błąd wyrażenia) to znak niedozwolony. Używają jej
  value objecty (`ProductName`, `ShortDescription`, `ProductImage`, `VariantAxes`, `VariantValues`) i walidator.
- Znak niedozwolony w `name`, `shortDescription`, `images[].alt`, `variantAxes[]` albo wartości wariantu → 422 przy polu
  z komunikatem „Nazwa produktu zawiera niedozwolone znaki.”, „Krótki opis zawiera niedozwolone znaki.”, „Tekst
  alternatywny zawiera niedozwolone znaki.”, „Nazwa osi zawiera niedozwolone znaki.” albo „Wartość wariantu zawiera
  niedozwolone znaki.” (styl Ordering). Niepoprawny UTF-8 w treści JSON (bajt poza UTF-8, samotny surogat `\udc80`)
  nie dociera do walidatora: dekoder odpowiada 400 i nic się nie zapisuje; reguła dla takiego tekstu działa w walidatorze
  i value objectach.
- Tekst z samych białych znaków Unicode w `name`, `images[].alt`, osi albo wartości wariantu → 422 przy tym polu z tym
  samym komunikatem co pusty („Podaj nazwę produktu.”, „Podaj tekst alternatywny zdjęcia.”, „Nazwa osi musi mieć od 1 do
  30 znaków.”, „Wartość musi mieć od 1 do 40 znaków.”), nie przy `slug`. Krótki opis z samych białych znaków po
  przycięciu jest pusty i zostaje przyjęty (pole opcjonalne). Zapisywane są teksty przycięte, a długość liczy się po
  przycięciu.
- Limity długości walidator bierze ze stałych domeny (publicznych), bez literałów.
- `docs/features/catalog-admin/spec.md` opisuje te reguły.

**Kryteria.** Testy funkcjonalne `POST` i `PUT` produktu (`ProductTextRulesEndpointsTest`): NUL, BEL, U+2028 i NUL na
końcu w każdym polu → 422 przy polu, LF odrzucony poza krótkim opisem; niepoprawny UTF-8 w treści JSON → 400, nic nie
zapisane; same białe znaki Unicode (NBSP, U+3000) w polach wymaganych → 422 jak pusty tekst, w krótkim opisie → zapis
pustego opisu; LF w krótkim opisie zapisany; zapis tekstów przyciętych i limity liczone po przycięciu. Testy
jednostkowe klasy reguł (`ProductTextTest`), value objectów (`ProductTextRulesInValueObjectsTest`) i walidatora
(`ProductWriteValidationTest`).

## #248: duplikat wariantu według bazy, nie domeny

**Problem.** `VariantValues::comparisonKey()` używa `mb_strtolower`, a unikalny indeks na `catalog_product_variant`
(`values_key TEXT GENERATED ALWAYS AS (lower(option_values::text))`) używa `lower()` PostgreSQL. Pary `["İ"]`/`["i"]`
i `["ΑΣ"]`/`["ασ"]` przechodzą domenę, a zapis kończy się `UniqueConstraintViolationException` i 500.

**Zachowanie.**
- Migracja: `ALTER COLUMN values_key DROP EXPRESSION` (wartości zostają, tabela nie jest przepisywana) i `DROP NOT NULL`.
  Unikalny indeks zostaje. `down()` przywraca kolumnę generowaną.
- Migracja `Version20261110090000StopGeneratingVariantValuesKey`. `ProductVariantRecord` mapuje `values_key` jako
  zapisywalną (`?string`); `DoctrineProductRepository` zapisuje przy każdym wariancie
  `json_encode(comparisonKey(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)`.
- Zgodność wersji (`apps/api/CLAUDE.md`): poprzednia wersja kodu nie zapisuje kolumny, więc w oknie wdrożenia jej
  warianty mają NULL, którego indeks nie pilnuje; unikalność i tak sprawdza domena pod wersją agregatu. `NOT NULL`
  i uzupełnienie wartości to osobna, późniejsza zmiana (poza tym epikiem).
- Istniejące wiersze mają klucz w formacie PostgreSQL do najbliższego zapisu produktu, który przepisuje jego warianty.
- Kombinacje różne według domeny są różne według bazy, więc 500 znika; duplikat według domeny daje jak dziś 422
  `DuplicateVariantValues`.
- `docs/features/variants/spec.md` i `docs/features/walking-skeleton/spec.md` opisują klucz liczony przez domenę;
  wiersz bez klucza (`NULL`, także z bezpośredniego SQL) indeks pomija.

**Kryteria.** Test integracyjny repozytorium (`DoctrineProductRepositoryTest`: pary `["İ"]`/`["i"]` i `["ΑΣ"]`/`["ασ"]`
zapisane, `values_key` to klucz porównania domeny, wiersze bez klucza pomijane przez indeks), funkcjonalny zapisu
(`WriteProductEndpointsTest`: te pary przy tworzeniu i edycji bez 500; para różniąca się tylko wielkością liter ASCII →
422 jak dotąd) i migracji (`VariantValuesKeyMigrationTest`: `up()` zachowuje klucze i indeks bez przepisania tabeli,
`down()` przywraca kolumnę generowaną); wstawienie SQL bez `values_key` (fixture
`apps/api/tests/Integration/Inventory/CatalogFixture.php`) działa dalej.

## #256: notatka pracownika z białymi znakami Unicode

**Problem.** `StaffNote::optional()` (`apps/api/src/Ordering/Domain/StaffNote.php`) przycina `trim()`, a
`ControlCharacters::PATTERN` (`/[\x00-\x1F\x7F]/`) nie ma `/u` ani separatorów linii. Notatka z samym NBSP albo z U+2028
daje 200 i zapis.

**Zachowanie.**
- `ControlCharacters` odrzuca też U+0085, U+2028 i U+2029 (wzorzec z `/u`); niepoprawny UTF-8 (błąd wyrażenia) to znak
  niedozwolony. Dotyczy wszystkich jednoliniowych pól Ordering, które go używają: notatki, danych kontaktowych (imię
  i nazwisko, e-mail), adresu zamówienia (ulica, miejscowość, kod pocztowy) w `PlaceGuestOrderRequest`
  i `PlaceCustomerOrderRequest`. `Assert\Length` pól imienia i nazwiska, ulicy, miejscowości, e-maila i notatki ma
  `charsetMessage` z komunikatem pola, więc niepoprawny UTF-8 daje to samo naruszenie co znak niedozwolony. `docs/features/order-management/spec.md`,
  `docs/features/checkout/spec.md` i `apps/api/CLAUDE.md` opisują regułę; opisy pól w OpenAPI
  (`openapi.json`, `openapi-admin.json`) i kliencie panelu są wyeksportowane od nowa (wzorzec z `/u` nie trafia już do
  specyfikacji jako `pattern`).
- `StaffNote::trim()` przycina białe znaki Unicode (`\s` i `\p{Z}`, także NBSP), a `StaffNote::optional()` używa go przed
  liczeniem długości; notatka z samych białych znaków jest brakiem notatki (dla anulowania i zwrotu 422 „Notatka jest
  wymagana.”, dla pozostałych przejść bez notatki, jak pusta). `ChangeOrderStatusRequest` i `CompleteRefundRequest`
  liczą długość po tym samym przycięciu (`normalizer: StaffNote::trim`); sama notatka z U+2028 itp. to „Notatka zawiera
  niedozwolone znaki.”, nie „wymagana”.

**Kryteria.** Testy 422 dla notatki z samym NBSP (przy anulowaniu i zwrocie) i z U+2028, U+2029, U+0085, w domenie
(`OrderLifecycleTest`, `OrderValuesTest`) i w obu żądaniach (`ForbiddenCharactersInRequestsTest`,
`ChangeOrderStatusEndpointTest`, `CompleteRefundEndpointTest`); notatka z samego NBSP przy wysyłce i wykonaniu zwrotu to
brak notatki (200); przycięcie NBSP z końców i limit 500 znaków po przycięciu; te znaki w danych kontaktowych i adresie
zamówienia gościa i klienta (`OrderRequestViolationCases`).

## #257: brakujący klucz treści w żądaniach panelu

**Problem.** Kontrolery z `REQUIRE_ALL_PROPERTIES` (Ordering: `ChangeOrderStatusController`, `CompleteRefundController`;
Inventory: `CorrectStockController`, `ReceiveStockController`; Pricing: `ChangePriceController`,
`PlanPromotionController`, `RevisePromotionController`) dla `{}` albo brakującego klucza dają 422 z angielskim
`violations[].title` „This value should be of type mixed.” z serializera. OpenAPI tych żądań oznacza wszystkie klucze
jako wymagane, a w korekcie stanu `version: null` ma znaczenie („wariant bez stanu”), więc brak klucza nie może znaczyć
`null`. DTO Inventory i Pricing miały `Assert\NotNull` i `Assert\Type` bez polskich komunikatów i bez `Sequentially`.

**Zachowanie.**
- Brak dowolnego klucza ciała w siedmiu endpointach (także klucza, który dopuszcza `null`) daje 422 z jednym
  naruszeniem „To pole jest wymagane.” przy tym kluczu (`propertyPath` = nazwa klucza); `{}` daje takie naruszenie dla
  każdego klucza. Klucz z wartością `null` zachowuje się jak dziś. Kontrakt OpenAPI bez zmian.
- Jeden mechanizm w `apps/api/src/Shared/UI/Http`: atrybut argumentu kontrolera `#[RequiredKeysBody]`
  (`RequiredKeysBody`, `RequiredKeysBodyResolver`, `PendingBody`), którego używają wszystkie siedem kontrolerów zamiast
  `REQUIRE_ALL_PROPERTIES`. Parametry konstruktora klasy żądania to klucze; przy braku klucza klasa nie powstaje, więc
  brak nie dociera do niej jako `null`, a raportowane są tylko brakujące klucze (wartości ocenia dopiero komplet).
  Treść jest czytana późno (nasłuch `kernel.controller_arguments`, priorytet -10100, po `#[IsGranted]`), więc 403
  atrybutu nadal poprzedza 400, 415 i 422. Odpowiedzi 400 (zły JSON) i 415 (inny format, także brak `Content-Type`) jak
  dotąd; JSON niebędący obiektem i pusta treść mają zero kluczy. `DiscountCodeBodyReader` w Pricing zostaje bez zmian
  (sprawdza 404 przed treścią). Deptrac pozwala `Ordering_UI`, `Inventory_UI` i `Pricing_UI` na `Shared_UI`.
- DTO Inventory (`CorrectStockRequest`, `ReceiveStockRequest`) i Pricing (`ChangePriceRequest`,
  `Promotion\PromotionRequest`, `Promotion\RevisePromotionRequest`) dostają polskie komunikaty dla braku wartości
  i złego typu oraz `Assert\Sequentially` (wzorem `ChangeOrderStatusRequest`): najwyżej jedno polskie naruszenie na pole.
  W Pricing: „Cena brutto jest wymagana.”, „Cena brutto musi być liczbą całkowitą w groszach.”, „Wersja ceny musi być
  liczbą całkowitą albo null.”, „Nazwa promocji jest wymagana.” / „… musi być tekstem.”, „Rabat jest wymagany.” /
  „Rabat musi być liczbą całkowitą.”, „Wersja promocji jest wymagana.” / „… musi być liczbą całkowitą.”, a `endsAt`
  i `variantIds` mają komunikaty `PromotionBodyRules::MOMENT` i `PromotionBodyRules::VARIANT_LIST`.
- `apps/api/CLAUDE.md` opisuje mechanizm jako sposób czytania ciała żądań panelu z wymaganymi kluczami.

**Kryteria.** Testy funkcjonalne siedmiu endpointów (wspólny `RequiredKeyViolationsTrait`): każdy klucz pominięty osobno
i `{}` → 422 z „To pole jest wymagane.” przy kluczu; w Inventory i Pricing (`CorrectStockEndpointTest`,
`ReceiveStockEndpointTest`, `ChangePriceEndpointTest`, `PromotionsEndpointTest`) `null` i zły typ każdego pola → jedno
polskie naruszenie przy polu, bez zmiany stanu; test jednostkowy mechanizmu (`RequiredKeysBodyResolverTest`: brak
klucza, `null`, zły JSON, inny format, kolejność względem atrybutów kontrolera).

## #339: niestabilny `checkout-customer.spec.ts`

**Problem.** Raz w CI (push do `epic/discount-codes`) po drugiej, udanej płatności strona potwierdzenia nie pokazała
„Płatność przyjęta…” w 30 s (`e2e/tests/checkout-customer.spec.ts:53`). Ponowienie przeszło.

**Przyczyna.** Wyścig odczytów w `GET /api/v1/orders/{id}/payment` i `/me/orders/{id}/payment`
(`GetPaymentStatusHandler`, `apps/api/src/Payment/Application/Query/`), nie czas obsługi webhooka ani test. Handler działa
na `query.bus` bez transakcji i czytał zamówienie, a potem najnowszą płatność, jako dwa osobne odczyty. Webhook
(`HandleProviderWebhookHandler`) oznacza płatność jako udaną i opłaca zamówienie w jednej transakcji, więc gdy
zatwierdził się między odczytami, odpowiedź łączyła zamówienie `new` z płatnością `succeeded`. Strona potwierdzenia
(`phaseOf()` w `apps/store/src/checkout/PaymentConfirmation.tsx`) uznaje taki stan za ustalony (formularz płatności) i
przestaje odpytywać, więc „Płatność przyjęta…” nigdy się nie pojawia. Okno jest realne: symulator kolejkuje webhook
i od razu przekierowuje na stronę potwierdzenia, która w tym momencie czyta stan na serwerze.

**Zachowanie.** `GetPaymentStatusHandler` czyta najpierw najnowszą płatność, potem zamówienie. Zamówienie odczytane po
płatności uwzględnia każdy webhook, który tę płatność zmienił, więc odpowiedź nigdy nie łączy płatności `succeeded`
ze stanem zamówienia sprzed tego webhooka. Odwrotny przeplot (zamówienie `paid`, płatność jeszcze `pending`) strona
pokazuje poprawnie jako opłacone. Płatność `succeeded` nie zawsze oznacza zamówienie `paid` (wyniki webhooka ze zwrotem
zostawiają zamówienie bez zmian). Sklep, BFF i test E2E bez zmian; `docs/features/payment/spec.md` („Status płatności”)
opisuje kolejność odczytów.

**Kryteria.** Test jednostkowy z wstrzykniętym przeplotem
(`apps/api/tests/Unit/Payment/Application/GetPaymentStatusHandlerTest.php`,
`testAWebhookCommittedBetweenTheTwoReadsNeverShowsASucceededPaymentOfAnUnpaidOrder`; atrapy `InMemoryPayableOrders`
i `InMemoryPayments` mają haki `afterRead` i `afterLatestRead`): webhook zatwierdzony po pierwszym odczycie nie daje
`new` + `succeeded`, wynik to `paid`, `canStartPayment: false`, płatność `pending`. Błąd występował około raz na 65
przebiegów, więc `--repeat-each 20` testu E2E na buildzie produkcyjnym (`STORE_MODE=production`, po `make seed`:
wariant ma 45 sztuk, a rezerwacja opłaconego zamówienia zostaje do wysyłki) niczego nie dowodzi; dowodem jest test
jednostkowy.

## #340: region statusu kodu, gdy kod schodzi z zamówienia

**Problem.** W `apps/store/src/checkout/CheckoutPage.tsx` gałąź `discount_code_rejected` w `showFailure` zdejmuje kod i
pokazuje błąd przy polu, ale nie zmienia `codeStatus`. Ukryty region `role="status"` (`checkout-code-status`) dalej
mówi „Zastosowano kod X.”. Kod może zejść też w innych gałęziach konfliktu, które wczytują podgląd od nowa
(`total_changed`).

**Zachowanie.** Gdy kod schodzi z zamówienia inaczej niż przez „Usuń kod” (podgląd wczytany bez kodu), region statusu
jest pusty; błąd ogłasza komunikat przy polu (`role="alert"`). Czyści go `reloadPreview` w `CheckoutPage.tsx`: udany
odczyt podsumowania bez kodu, gdy kod był zastosowany, zeruje `codeStatus`; obejmuje to 409 `discount_code_rejected`
i `total_changed` oraz inne 409 wczytujące podsumowanie. Gdy podglądu nie da się wczytać i kod zostaje, region mówi dalej
„Zastosowano kod X.” (to prawda). „Usunięto kod rabatowy.” po ręcznym usunięciu zostaje także po późniejszym 409.
`apps/store/CLAUDE.md` (kasa, kod rabatowy) opisuje region.

**Kryteria.** Testy komponentu kasy (`apps/store/src/checkout/CheckoutPage.test.tsx`): po 409 `discount_code_rejected`
(kod nieprawidłowy, wyczerpany, poniżej minimum) region jest pusty, a alert przy polu zostaje; po 409
`total_changed`, gdy odczyt odrzuca kod, region jest pusty; przy nieudanym odczycie zostaje „Zastosowano kod”;
„Usunięto kod rabatowy.” zostaje po późniejszym 409. E2E w `e2e/tests/discount-codes.spec.ts` (pomocnik
`disableDiscountCode` w `e2e/support/discount-codes.ts`): po 409 `discount_code_rejected` (kod poniżej minimum, potem
kod wyłączony w panelu) `checkout-code-status` nie zawiera „Zastosowano”.

## #341: nagłówek formularza istniejącego obiektu przy błędzie wczytania

**Problem.** `DiscountCodeFormView.vue` (`apps/admin/src/views/`) wybiera nagłówek według wczytanego widoku
(`view ? … : "Nowy kod rabatowy"`). Na adresie kodu, który nie istnieje (404) albo się nie wczytał (500), nagłówek mówi
„Nowy kod rabatowy”. Ten sam wzorzec mają `PromotionFormView.vue` („Nowa promocja”) i `ProductFormView.vue` („Nowy
produkt”, F9 z #251).

**Zachowanie.** Nagłówek „Nowy …” tylko na trasie tworzenia (brak parametru `id` trasy; `isExisting` w każdym z trzech
widoków). Na trasie z identyfikatorem, przed wczytaniem i przy błędzie: „Kod rabatowy”, „Promocja”, „Produkt”; po
wczytaniu jak dotąd (kod albo nazwa).

**Kryteria.** Testy komponentów (`apps/admin/tests/DiscountCodeFormView.test.ts`, `PromotionFormView.test.ts`,
`ProductFormView.test.ts`): 404 i 500 na trasie z id oraz czas przed wczytaniem → nagłówek bez „Nowy”/„Nowa”; trasa
tworzenia bez zmian.

## Wymagane przed startem

Brak. Epik zmienia tylko kod, testy, dokumentację i jedną migrację w istniejących usługach; nie potrzebuje sekretów, kont
ani uprawnień spoza repo.

## Ryzyka

- #248: w oknie wdrożenia warianty zapisane przez poprzednią wersję mają `values_key` NULL i baza nie pilnuje ich
  unikalności; pilnuje domena.
- #339: poprawka jest w API (kolejność odczytów), strona potwierdzenia nadal uznaje zamówienie `new` z płatnością
  `succeeded` za stan ustalony i przestaje odpytywać; po poprawce wyścig takiego stanu nie daje.
