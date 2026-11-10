# QUALITY-FIXES: poprawki zgłoszeń agentów po epikach MVP

Epik poprawek po epikach CART, SEARCH, PAYMENT, CATALOG-ADMIN, ORDER-MANAGEMENT i DISCOUNT-CODES. Każda sekcja to jedno
issue zgłoszone przez agenta i przyjęte w triage. Działanie funkcji opisują ich specyfikacje; ta opisuje tylko zmiany.

Issues: #342, #113, #130, #197, #213, #247, #248, #256, #257, #339, #340, #341
Zakres: `apps/api/src/Cart/UI/Http`, `apps/api/tests/Functional/Cart`, `apps/api/src/Catalog/Application/Query`, `apps/api/src/Catalog/Domain/Product`, `apps/api/src/Catalog/UI/Http/Validation`, `apps/api/src/Catalog/Infrastructure/Persistence`, `apps/api/tests/Functional/Catalog`, `apps/api/tests/Unit/Catalog`, `apps/api/tests/Integration/Catalog`, `apps/api/src/Payment`, `apps/api/tests/Unit/Payment`, `apps/api/tests/Functional/Payment`, `apps/api/src/Ordering/Domain`, `apps/api/src/Ordering/UI/Http`, `apps/api/tests/Functional/Ordering`, `apps/api/tests/Unit/Ordering`, `apps/api/src/Inventory/UI/Http`, `apps/api/tests/Functional/Inventory`, `apps/api/src/Pricing/UI/Http`, `apps/api/tests/Functional/Pricing`, `apps/api/src/Shared/UI/Http`, `apps/api/tests/Unit/Shared`, `apps/api/CLAUDE.md`, `apps/store/src/checkout`, `apps/admin/src/views`, `apps/admin/tests`, `e2e/tests/checkout-customer.spec.ts`, `e2e/tests/discount-codes.spec.ts`, `e2e/support`, `docs/features/catalog-admin/spec.md`, `docs/features/variants/spec.md`, `docs/features/walking-skeleton/spec.md`, `docs/features/order-management/spec.md`, `docs/features/checkout/spec.md`, `docs/features/payment/spec.md`

## Cel i zakres

- Walidacja API daje najwyżej jedno naruszenie na pole, po polsku, także dla brakującego klucza (#113, #257).
- Teksty produktu i notatki pracownika: znaki sterujące i same białe znaki Unicode dają 422 przy polu albo pusty tekst,
  nie 500 ani zapisane śmieci (#247, #256).
- Unikalność kombinacji wartości wariantu liczy ten sam klucz w domenie i w bazie (#248).
- Filtr kategorii sklepu przyjmuje UUID niezależnie od wielkości liter (#197).
- Adapter płatności nie przyjmuje webhooka przy pustym sekrecie (#213).
- Test E2E płatności klienta jest stabilny (#339).
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
długi z takimi bitami trzy. `Assert\Sequentially` tego nie zatrzymuje, bo to jedno ograniczenie. Dotyczy `variantId`
w `AddCartItemRequest` i `guestCartId` w `MergeGuestCartRequest` (`apps/api/src/Cart/UI/Http/`). Zasada z #82: jedno
pole daje najwyżej jedno naruszenie.

**Zachowanie.** Każda wartość `variantId` i `guestCartId` daje najwyżej jedno naruszenie pola. Niepoprawny UUID: 422
„Identyfikator wariantu musi być UUID.” albo „Identyfikator koszyka musi być UUID.”.

**Kryteria.** W `apps/api/tests/Functional/Cart/CartRequestViolationCases.php` przypadki: nil, max, zła wersja i wariant,
za długi z takimi bitami, dla obu pól; dokładnie jedno naruszenie, `propertyPath`, komunikat.

## #197: UUID kategorii wielkimi literami w sklepie

**Problem.** `ListProductsHandler` (`apps/api/src/Catalog/Application/Query/ListProductsHandler.php:38`) woła
`CategoryId::fromString()` (które robi `strtolower`) tylko po to, żeby sprawdzić istnienie kategorii, a dalej przekazuje
surowy `$query->categoryId` do `facetCategories()`, `find()` i zapytania indeksu. `GET /api/v1/products?category=<UUID
wielkimi literami>` daje `total: 0`. Lista panelu (`SearchBackofficeProductsHandler.php:55`) już normalizuje.

**Zachowanie.** `ListProductsHandler` raz, na początku, bierze `CategoryId::fromString($query->categoryId)->toString()`
i dalej używa tylko tej wartości. Kategoria wielkimi literami daje ten sam wynik (produkty, `total`, fasety) co małymi.
Niepoprawny UUID zachowuje się jak dziś.

**Kryteria.** Test funkcjonalny listy sklepu: kategoria wielkimi literami zwraca to samo co małymi (RED przed zmianą).
Test panelu z tym samym przypadkiem to test regresji: przechodzi przed zmianą, co raport zadania mówi wprost.

## #213: pusty sekret webhooka

**Problem.** `DemoCardProvider` i `DemoTransferProvider` (`apps/api/src/Payment/Infrastructure/Provider/`) liczą HMAC kluczem
ze zmiennej środowiska. Pusta zmienna daje pusty klucz, a webhook podpisany pustym kluczem przechodzi. Chart wymaga
niepustych sekretów (`required` w `secret.yaml`), a `init-env.sh` generuje 32 znaki, więc to ryzyko złej konfiguracji.

**Zachowanie.** Adapter z pustym sekretem odrzuca każdy webhook wyjątkiem `InvalidWebhook` z osobną przyczyną („pusty
sekret webhooka”), zanim sprawdzi podpis. `WebhookController` zapisuje ją jak inne przyczyny (`warning`) i odpowiada jak
na zły podpis (400); płatność bez zmian. Start kontenera bez zmian. `docs/features/payment/spec.md` dostaje tę regułę.

**Kryteria.** Testy adapterów (`apps/api/tests/Unit/Payment/Infrastructure/Demo*WebhookTest.php`): pusty sekret i podpis
policzony pustym kluczem → `InvalidWebhook` z tą przyczyną; niepusty sekret działa jak dziś.

## #247: znaki sterujące i białe znaki Unicode w tekstach produktu

**Problem.** `ValidProductWriteValidator` (`apps/api/src/Catalog/UI/Http/Validation/`) sprawdza pustkę przez `trim()` (tylko
ASCII) i nie odrzuca znaków sterujących; value objecty domeny też używają `trim()`. NUL w `images[].alt`
i `variantAxes[]` daje 500 (jsonb), w `name`, `shortDescription` i wartościach wariantów zapis z uciętą wartością.
Nazwa, oś albo wartość z samych NBSP przechodzi (nazwa z NBSP daje naruszenie przy `slug`, nie przy `name`). Walidator
kopiuje limity domeny jako literały.

**Zachowanie.**
- Jedna klasa reguł tekstu w `apps/api/src/Catalog/Domain/Product` (własna kopia, wzorem
  `Ordering\Domain\ControlCharacters`; bez wspólnych value objectów między modułami): przycinanie białych znaków Unicode
  (`\s` i `\p{Z}` z `/u`), wykrywanie znaków niedozwolonych: C0 i DEL (U+0000–U+001F, U+007F) oraz U+0085, U+2028,
  U+2029, z wyjątkiem LF (U+000A) w krótkim opisie. Niepoprawny UTF-8 (błąd wyrażenia) to znak niedozwolony. Używają jej
  value objecty (`ProductName`, `ShortDescription`, `ProductImage`, `VariantAxes`, `VariantValues`) i walidator.
- Znak niedozwolony w `name`, `shortDescription`, `images[].alt`, `variantAxes[]` albo wartości wariantu → 422 przy polu
  z komunikatem „… zawiera niedozwolone znaki.” (styl Ordering).
- Tekst z samych białych znaków Unicode w `name`, `images[].alt`, osi albo wartości wariantu → 422 „wymagane” przy tym
  polu, jak pusty. Krótki opis z samych białych znaków po przycięciu jest pusty i zostaje przyjęty (pole opcjonalne).
- Limity długości walidator bierze ze stałych domeny (publicznych), bez literałów.

**Kryteria.** Testy funkcjonalne `POST` i `PUT` produktu: NUL, BEL, U+2028 i niepoprawny UTF-8 w każdym polu → 422 przy
polu; same NBSP w polach wymaganych → 422 „wymagane”, w krótkim opisie → zapis pustego opisu; LF w krótkim opisie
zapisany; testy jednostkowe klasy reguł i value objectów.

## #248: duplikat wariantu według bazy, nie domeny

**Problem.** `VariantValues::comparisonKey()` używa `mb_strtolower`, a unikalny indeks na `catalog_product_variant`
(`values_key TEXT GENERATED ALWAYS AS (lower(option_values::text))`) używa `lower()` PostgreSQL. Pary `["İ"]`/`["i"]`
i `["ΑΣ"]`/`["ασ"]` przechodzą domenę, a zapis kończy się `UniqueConstraintViolationException` i 500.

**Zachowanie.**
- Migracja: `ALTER COLUMN values_key DROP EXPRESSION` (wartości zostają, tabela nie jest przepisywana) i `DROP NOT NULL`.
  Unikalny indeks zostaje. `down()` przywraca kolumnę generowaną.
- `ProductVariantRecord` mapuje `values_key` jako zapisywalną; repozytorium zapisuje przy każdym wariancie
  `json_encode(comparisonKey(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)`.
- Zgodność wersji (`apps/api/CLAUDE.md`): poprzednia wersja kodu nie zapisuje kolumny, więc w oknie wdrożenia jej
  warianty mają NULL, którego indeks nie pilnuje; unikalność i tak sprawdza domena pod wersją agregatu. `NOT NULL`
  i uzupełnienie wartości to osobna, późniejsza zmiana (poza tym epikiem).
- Istniejące wiersze mają klucz w formacie PostgreSQL do najbliższego zapisu produktu, który przepisuje jego warianty.
- Kombinacje różne według domeny są różne według bazy, więc 500 znika; duplikat według domeny daje jak dziś 422
  `DuplicateVariantValues`.
- `docs/features/variants/spec.md` i `docs/features/walking-skeleton/spec.md` przestają mówić o kolumnie generowanej
  i o odrzuceniu duplikatu przy bezpośrednim SQL.

**Kryteria.** Test integracyjny repozytorium i funkcjonalny zapisu z parami `["İ"]`/`["i"]` i `["ΑΣ"]`/`["ασ"]`: brak 500
(RED: dziś 500); para różniąca się tylko wielkością liter ASCII → 422 jak dziś; wstawienie SQL bez `values_key`
(fixture `apps/api/tests/Integration/Inventory/CatalogFixture.php`) działa dalej.

## #256: notatka pracownika z białymi znakami Unicode

**Problem.** `StaffNote::optional()` (`apps/api/src/Ordering/Domain/StaffNote.php`) przycina `trim()`, a
`ControlCharacters::PATTERN` (`/[\x00-\x1F\x7F]/`) nie ma `/u` ani separatorów linii. Notatka z samym NBSP albo z U+2028
daje 200 i zapis.

**Zachowanie.**
- `ControlCharacters` odrzuca też U+0085, U+2028 i U+2029 (wzorzec z `/u`); niepoprawny UTF-8 (błąd wyrażenia) to znak
  niedozwolony. Dotyczy wszystkich jednoliniowych pól Ordering, które go używają (notatka, dane kontaktowe i adres
  zamówienia); `docs/features/order-management/spec.md` i specyfikacja kasy opisują regułę.
- `StaffNote` przycina białe znaki Unicode; notatka z samych białych znaków jest brakiem notatki (dla anulowania i zwrotu
  422 „wymagana”, jak pusta). `ChangeOrderStatusRequest` i `CompleteRefundRequest` stosują tę samą regułę.

**Kryteria.** Testy 422 dla notatki z samym NBSP i z U+2028, U+2029, U+0085, w domenie i w obu żądaniach.

## #257: brakujący klucz treści w żądaniach panelu

**Problem.** Kontrolery z `REQUIRE_ALL_PROPERTIES` (Ordering: `ChangeOrderStatusController`, `CompleteRefundController`;
Inventory: `CorrectStockController`, `ReceiveStockController`; Pricing: `ChangePriceController`,
`PlanPromotionController`, `RevisePromotionController`) dla `{}` albo brakującego klucza dają 422 z angielskim
`violations[].title` „This value should be of type mixed.” z serializera. OpenAPI tych żądań oznacza wszystkie klucze
jako wymagane, a w korekcie stanu `version: null` ma znaczenie („wariant bez stanu”), więc brak klucza nie może znaczyć
`null`. DTO Inventory mają `Assert\NotNull` i `Assert\Type` bez polskich komunikatów i bez `Sequentially`.

**Zachowanie.**
- Brak dowolnego klucza ciała w siedmiu endpointach (także klucza, który dopuszcza `null`) daje 422 z jednym
  naruszeniem „To pole jest wymagane.” przy tym kluczu (`propertyPath` = nazwa klucza); `{}` daje takie naruszenie dla
  każdego klucza. Klucz z wartością `null` zachowuje się jak dziś. Kontrakt OpenAPI bez zmian.
- Jeden mechanizm w `apps/api/src/Shared/UI/Http`, którego używają wszystkie siedem kontrolerów zamiast
  `REQUIRE_ALL_PROPERTIES`; brak klucza nie dociera do konstruktora DTO jako `null`. Odpowiedzi 400 (zły JSON) i 415
  (inny format) jak dziś. `DiscountCodeBodyReader` w Pricing zostaje bez zmian (sprawdza 404 przed treścią).
- DTO Inventory (`CorrectStockRequest`, `ReceiveStockRequest`) dostają polskie komunikaty dla braku wartości i złego typu
  oraz `Assert\Sequentially` (wzorem `ChangeOrderStatusRequest`): najwyżej jedno polskie naruszenie na pole.
- `apps/api/CLAUDE.md` opisuje mechanizm jako sposób czytania ciała żądań panelu z wymaganymi kluczami.

**Kryteria.** Testy funkcjonalne siedmiu endpointów: każdy klucz pominięty osobno i `{}` → 422 z „To pole jest
wymagane.” przy kluczu; w Inventory `null` i zły typ każdego pola → jedno polskie naruszenie; test jednostkowy
mechanizmu (brak klucza, `null`, zły JSON, inny format).

## #339: niestabilny `checkout-customer.spec.ts`

**Problem.** Raz w CI (push do `epic/discount-codes`) po drugiej, udanej płatności strona potwierdzenia nie pokazała
„Płatność przyjęta…” w 30 s (`e2e/tests/checkout-customer.spec.ts:53`). Ponowienie przeszło.

**Zachowanie.** Zadanie ustala przyczynę (czas obsługi webhooka przez workera, odświeżanie stanu płatności na stronie
potwierdzenia po odrzuceniu i ponownym wyborze, kolejność zdarzeń) i naprawia ją w kodzie albo w teście. Wydłużenie
timeoutu bez ustalonej przyczyny nie jest poprawką. Błąd w produkcie poprawia się w sklepie albo API, z testem.

**Kryteria.** Przyczyna opisana w PR zadania; test przechodzi `--repeat-each 20` na buildzie produkcyjnym
(`STORE_MODE=production`), po `make seed` przed przebiegiem (wariant testu ma 45 sztuk, a rezerwacja opłaconego
zamówienia zostaje do wysyłki), wynik w raporcie.

## #340: region statusu kodu, gdy kod schodzi z zamówienia

**Problem.** W `apps/store/src/checkout/CheckoutPage.tsx` gałąź `discount_code_rejected` w `showFailure` zdejmuje kod i
pokazuje błąd przy polu, ale nie zmienia `codeStatus`. Ukryty region `role="status"` (`checkout-code-status`) dalej
mówi „Zastosowano kod X.”. Kod może zejść też w innych gałęziach konfliktu, które wczytują podgląd od nowa
(`total_changed`).

**Zachowanie.** Gdy kod schodzi z zamówienia inaczej niż przez „Usuń kod” (podgląd wczytany bez kodu), region statusu
jest pusty; błąd ogłasza komunikat przy polu (`role="alert"`). Gdy podglądu nie da się wczytać i kod zostaje, region
mówi dalej „Zastosowano kod X.” (to prawda).

**Kryteria.** Test komponentu kasy i E2E w `e2e/tests/discount-codes.spec.ts`: po 409 `discount_code_rejected` (kod
poniżej minimum, kod wyłączony) `checkout-code-status` nie zawiera „Zastosowano”.

## #341: nagłówek formularza istniejącego obiektu przy błędzie wczytania

**Problem.** `DiscountCodeFormView.vue` (`apps/admin/src/views/`) wybiera nagłówek według wczytanego widoku
(`view ? … : "Nowy kod rabatowy"`). Na adresie kodu, który nie istnieje (404) albo się nie wczytał (500), nagłówek mówi
„Nowy kod rabatowy”. Ten sam wzorzec mają `PromotionFormView.vue` („Nowa promocja”) i `ProductFormView.vue` („Nowy
produkt”, F9 z #251).

**Zachowanie.** Nagłówek „Nowy …” tylko na trasie tworzenia. Na trasie z identyfikatorem, przed wczytaniem i przy błędzie:
„Kod rabatowy”, „Promocja”, „Produkt”; po wczytaniu jak dziś (kod albo nazwa).

**Kryteria.** Testy komponentów (`apps/admin/tests`): 404 i 500 na trasie z id → nagłówek bez „Nowy”/„Nowa”; trasa
tworzenia bez zmian.

## Wymagane przed startem

Brak. Epik zmienia tylko kod, testy, dokumentację i jedną migrację w istniejących usługach; nie potrzebuje sekretów, kont
ani uprawnień spoza repo.

## Ryzyka

- Zakres pokrywa się z VISUAL-DESIGN (`apps/api/src/Catalog`, `apps/store/src/checkout`, `e2e/tests`) i HARDENING
  (`apps/api/src/Shared`, Ordering i Payment UI, `apps/store/src/checkout`): epik rusza po nich, nie tej samej nocy.
- #248: w oknie wdrożenia warianty zapisane przez poprzednią wersję mają `values_key` NULL i baza nie pilnuje ich
  unikalności; pilnuje domena.
- #339: przyczyna może leżeć w API (Payment, worker) albo w sklepie; zakres zadania obejmuje obie strony.
