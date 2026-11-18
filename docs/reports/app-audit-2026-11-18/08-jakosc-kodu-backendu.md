# 08. Jakość kodu backendu i zbędna złożoność (`apps/api/src`)

Wszystkie ścieżki są względne wobec katalogu eksportu. Skrót `src/…` oznacza `apps/api/src/…`.

## Zakres i metoda

- Przedmiot: `apps/api/src` (1286 plików PHP, 61 045 linii), `apps/api/config`, `phpstan.dist.neon`, `deptrac.yaml`,
  `.php-cs-fixer.dist.php`, `Dockerfile`, `docker/php/conf.d`, oraz `docs/vision.md` i `apps/api/CLAUDE.md` jako źródło
  świadomych decyzji.
- Przeczytane w całości albo w większości: ok. 130 plików (wszystkie pliki trzech prześledzonych przepływów, po 2–4
  pliki każdego rodzaju z każdego modułu, 10 największych plików, cała konfiguracja usług, Messengera, Doctrine,
  security). Pozostałe pliki objęte analizą skryptową (`grep`, `wc`, `awk`, `diff`): liczba implementacji każdego
  interfejsu w `src` i dubli w `tests`, klasy bez odwołań, metody publiczne bez wywołań, rozkład rozmiarów, liczniki
  wzorców (asercje, `catch`, wyrażenia regularne, DTO).
- Trzy przepływy prześledzone od kontrolera do bazy: zmiana ceny wariantu, dodanie pozycji do koszyka gościa, lista
  zamówień w panelu. Dodatkowo: edycja produktu i pulpit panelu.
- Audyt statyczny: nie uruchamiałem PHPStana, Deptraca, PHP CS Fixera ani testów.

Liczby bazowe:

| Warstwa | Pliki | Linie | Udział linii |
|---|---|---|---|
| Domain | 274 | 12 311 | 20% |
| Application | 578 | 20 169 | 33% |
| Infrastructure | 211 | 15 158 | 25% |
| UI | 195 | 12 100 | 20% |
| PaymentSimulator + Kernel | 28 | 1 307 | 2% |

1090 klas (807 `final readonly`, 282 `final`), 114 interfejsów, 53 enumy. 91 handlerów Messengera (46 komend, 44
zapytania, 18 subskrybentów zdarzeń), 60 kontrolerów. Komentarze to ok. 10,5 tys. linii (17%), atrybuty OpenAPI w UI
ok. 1,2 tys. linii. Rozmiary plików: 785 plików ma do 40 linii, tylko 8 ma ponad 300, żaden ponad 502.

## Ocena ogólna obszaru: 7/10

Kod jest zdyscyplinowany w stopniu rzadko spotykanym: PHPStan na poziomie `max` bez baseline i bez ignorowanych
błędów, Deptrac bez `skip_violations`, domena bez jednego importu z vendorów, zero `TODO`/`FIXME`, praktycznie zero
martwego kodu, krótkie metody, komentarze tłumaczące „dlaczego”, jednolity model błędów (RFC 9457). Najtrudniejsze
miejsca (złożenie zamówienia, blokady, outbox, wersjonowanie) są napisane czytelnie i z uzasadnieniem.

Trzy punkty odejmuję za koszt zmian. Reguły architektury zostały zastosowane mechanicznie także tam, gdzie nie ma czego
chronić: te same dane przechodzą przez 3–4 identyczne klasy, techniczne klocki (zegar, identyfikator UUID, kursor,
stronicowanie, rozpakowanie wyjątku szyny) są kopiowane w każdym module, synchroniczne zapytania idą przez nietypowaną
szynę Messengera, a część reguł walidacji jest zapisana dwa albo trzy razy. Skutek: dodanie jednego pola do odpowiedzi
to 8–11 plików PHP, a pulpit panelu (jeden endpoint) to 46 plików. Nie znalazłem błędu o wadze Critical ani High; to
jest kod drogi w utrzymaniu, nie kod wadliwy.

## Proporcjonalność: trzy przepływy

| Funkcja | Klasy na ścieżce synchronicznej | Z czego „klej” (komenda, DTO, port, adapter, rekord) | Ocena |
|---|---|---|---|
| Zmiana ceny wariantu (`PUT /admin/variants/{id}/price`) | ok. 46 (Pricing 41, Catalog 5) + 4 asynchroniczne (outbox, reindeksacja, cache) | ok. 15 | Rdzeń uzasadniony (VAT, historia Omnibus, wersja, blokada względem promocji). Klej nadmiarowy. |
| Dodanie pozycji do koszyka gościa (`POST /guest-carts/{id}/items`) | ok. 55 w Cart + ok. 25 w Catalog, Pricing i Inventory (4 pytania przez szynę) | ok. 30 | Agregat `Cart` i jego reguły w porządku. 9 klas DTO portów i 4 adaptery szyny tylko przepisują pola. |
| Lista zamówień w panelu (`GET /admin/orders`) | 16 (kontroler, DTO zapytania, zapytanie, handler, filtr, kursor, port, czytnik DBAL, 3 DTO wiersza, builder JSON, schemat YAML, 3 wyjątki) | 6 | SQL i kursor uzasadnione. Trzy reprezentacje wiersza i reguły filtrów w dwóch warstwach nie. |

Miara praktyczna: dodanie pola „powód zmiany” do historii cen wymaga zmiany `ChangePriceRequest`,
`ChangePriceController`, `SetVariantPrice` (konstruktor i dwie fabryki), `SetVariantPriceHandler`, `PriceChangeOrigin`,
`PriceHistoryEntry`, `DoctrineVariantPriceRepository`, `PriceHistoryRecord`, `DbalPriceHistoryReader`,
`PriceHistoryItem`, `PriceHistoryItemResponse`, czyli 11 plików PHP, do tego migracja, dwa pliki OpenAPI i dwóch
klientów TS.

## Znaleziska

### Z1. Reguły zapisu produktu są zaimplementowane dwa razy, a limity trzeci raz jako literały w OpenAPI

- **Waga:** Medium. **Klasa:** RYZYKO.
- **Dowód:** `src/Catalog/UI/Http/Validation/ValidProductWriteValidator.php:172-210` powtarza regułę osi wariantów,
  którą ma `src/Catalog/Domain/Product/VariantAxes.php:29-56`:

  ```php
  // walidator UI
  } elseif (str_contains($trimmed, ':')) {
      self::add($context, $path, 'Nazwa osi nie może zawierać dwukropka.');
  } elseif (isset($seen[mb_strtolower($trimmed)])) {
  // value object domeny
  if (str_contains($name, ':')) { throw InvalidVariantAxes::nameWithColon(); }
  $key = mb_strtolower($name);
  if (isset($seen[$key])) { throw InvalidVariantAxes::nameRepeated(); }
  ```

  To samo dotyczy nazwy, opisu, tekstu alternatywnego, wartości wariantów, kategorii i zdjęć (cały plik, 345 linii,
  103 rozgałęzienia: najbardziej złożony plik w `src`). Value objecty Catalog rzucają gołe
  `\InvalidArgumentException` (`src/Catalog/Domain/Product/ProductName.php:15-24`), które
  `src/Shared/Infrastructure/Http/ExceptionStatus.php:61` mapuje na 500. Trzecia kopia:
  `src/Catalog/UI/Http/UpdateProductRequest.php:21-42` i `CreateProductRequest.php:19-40` mają limity jako literały
  (`maxLength: 200`, `maxItems: 10`, `maxItems: 2`, `maxItems: 100`), choć stałe istnieją
  (`ProductName::MAX_LENGTH`, `VariantAxes::MAX_AXES`).
- **Konsekwencja:** zmiana reguły w jednym miejscu daje rozjazd. Jeśli walidator przepuści coś, czego domena nie
  przyjmie, sprzedawca zapisujący produkt dostaje 500 bez komunikatu zamiast 422 przy polu. Jeśli zmieni się stała,
  `openapi-admin.json` i klient TS dalej deklarują stary limit. Ten sam wzorzec, w mniejszej skali, jest w liście
  zamówień: reguły łączenia filtrów są w `src/Ordering/UI/Http/BackofficeOrderListQuery.php:153-171` i drugi raz w
  `src/Ordering/Application/Query/ListBackofficeOrdersHandler.php:43,70-90`.
- **Rekomendacja:** jedno źródło reguł. Wzorzec już jest w repo: Pricing waliduje w UI tylko kształt, a reguły domeny
  wracają jako typowany wyjątek przypięty do pola (`src/Pricing/UI/Http/Promotion/PromotionBodyRules.php:12-14`,
  `PromotionWriteFailures`). Catalog może zrobić to samo (wyjątki domeny z nazwą pola), a walidator skurczy się do
  sprawdzenia kształtu (ok. 200 linii mniej). W atrybutach OpenAPI użyć stałych, jak robi to Pricing
  (`PromotionRequest.php:22`).
- **Pewność:** wysoka co do duplikacji i mapowania na 500 (przeczytałem walidator, value objecty, handler
  `EditProductHandler.php:91-93` i `ExceptionStatus`). Porównałem reguły ręcznie i dziś rozjazdu nie znalazłem: część
  logiki jest wspólna przez `ProductText` i stałe. Ryzyko dotyczy przyszłych zmian; prawdopodobieństwo oceniam jako
  niskie do średniego, bo testy funkcjonalne zapewne pokrywają większość reguł (testów nie uruchamiałem).

### Z2. Synchroniczne zapytania idą przez nietypowaną szynę: 49 bloków `catch (HandlerFailedException)` i ok. 130 adnotacji przywracających typ

- **Waga:** Medium. **Klasa:** PREFERENCJA (świadoma decyzja, mierzalny koszt, brak wykazanej szkody).
- **Dowód:** `config/packages/messenger.yaml` definiuje `query.bus` z jednym middleware, `CorrelationIdMiddleware`,
  który dla wiadomości nieodebranej z transportu tylko dokleja stempel
  (`src/Shared/Infrastructure/Observability/CorrelationIdMiddleware.php:32-38`). Szyna nie daje więc zapytaniom nic
  poza pośrednictwem, a kosztuje:
  - wynik jest `mixed`: 79 adnotacji `/** @var … */` i 54 `\assert(… instanceof …)`, w większości po `handle()`, np.
    `src/Ordering/UI/Http/BackofficeOrdersController.php:83` i `src/Cart/Infrastructure/Catalog/QueryBusProductCatalog.php:47,69,89`;
  - szyna opakowuje każdy wyjątek, więc jest 24 `catch (HandlerFailedException)` w UI i 25 w Infrastructure oraz pięć
    kopii rozpakowania: `ExceptionStatus::unwrapHandlerFailure`, `src/Cart/Infrastructure/QueryBusFailure.php:16`,
    `src/Ordering/Infrastructure/BusFailure.php:17`, `src/Backoffice/Infrastructure/AsksQueryBus.php:23` i wersje
    wklejone w adaptery (`src/Pricing/Infrastructure/Catalog/QueryBusCatalogVariants.php:54-63`, podobnie Inventory
    i Payment);
  - 22 adaptery `QueryBus*`/`CommandBus*` (ok. 1380 linii) istnieją po to, żeby zapytać inny moduł.
- **Konsekwencja:** dla programisty: IDE nie pokaże, kto obsługuje zapytanie ani co zwraca; zmiana typu wyniku handlera
  nie jest wykrywana przez PHPStana w miejscu wywołania (adnotacja `@var` jest deklaracją, nie sprawdzeniem), tylko
  przez testy. Dla operatora brak skutków.
- **Rekomendacja:** zostawić `command.bus` (transakcja i walidacja w middleware mają wartość) i `event.bus`. Zapytania
  wołać bezpośrednio: handler zapytania jest zwykłą usługą z typowanym `__invoke`, więc adapter albo kontroler może go
  wstrzyknąć. Znika opakowanie wyjątku, 49 bloków `catch`, pomocnicze klasy rozpakowujące i większość adnotacji.
  Deptrac dalej pilnuje, że moduł sięga tylko do `*_Application` innego modułu.
- **Pewność:** wysoka co do liczb i działania middleware. Decyzja o szynie zapytań jest opisana w `deptrac.yaml`
  (komentarze przy `*_Infrastructure`) i `apps/api/CLAUDE.md`; nie znalazłem uzasadnienia, co szyna daje odczytom.

### Z3. Te same dane przechodzą przez 3–4 klasy o identycznych polach

- **Waga:** Medium. **Klasa:** PREFERENCJA.
- **Dowód:** `src/Ordering/Application/Query/OrderQueues.php:12-18` i
  `src/Backoffice/Application/Port/OrderQueueCounts.php:12-18` mają te same cztery pola tego samego typu:

  ```php
  public ?BoundedCount $paidToShip,
  public ?BoundedCount $awaitingPayment,
  public ?BoundedCount $pendingRefunds,
  public ?BoundedCount $overdueRefunds,
  ```

  Sprzedaż okresu to trzy klasy z tymi samymi czterema `?int`: `Ordering\…\PeriodSales`,
  `Backoffice\…\Port\PeriodFigures`, `Backoffice\UI\Http\DashboardSalesPeriodResponse`, przepisywane pole po polu w
  `src/Backoffice/Infrastructure/QueryBusSalesSummary.php:35-38`. Każda lista panelu ma trzy klasy wiersza, np.
  `Port\ListedOrder` → `Query\BackofficeOrderListItem` (9 z 10 pól bez zmian,
  `ListBackofficeOrdersHandler.php:105-116`) → tablica w `BackofficeOrderJson.php:95-117`; tak samo
  `ListedDiscountCode` → `DiscountCodeView` → `DiscountCodeResponse`.
  Skrypt znalazł 297 klas będących wyłącznie konstruktorem z promowanymi właściwościami i 58 klas „konstruktor +
  `of()`”: razem 355 z 1286 plików (28%). Moduł Backoffice to 46 plików i 1577 linii na jeden endpoint pulpitu i
  `/me`, z czego 11 to porty z DTO, 7 to adaptery, a 13 to klasy odpowiedzi.
- **Konsekwencja:** każda zmiana kształtu danych to edycja kilku klas i kilku mapowań; błąd przepisania (zamienione
  pola tego samego typu) wyłapią dopiero testy. Dla klienta i operatora brak skutków.
- **Rekomendacja:** dopuścić w zasadach projektu skrót dla odczytów: klasa odpowiedzi UI budowana wprost z wiersza
  odczytu, gdy pola są 1:1; DTO pośrednie tylko tam, gdzie handler coś dolicza. Adaptery Backoffice mogą zwracać widoki
  Ordering i Inventory (Deptrac już pozwala `Backoffice_Infrastructure` na `Ordering_Application`). Szacunek: 40–60
  plików mniej.
- **Pewność:** wysoka (pliki porównane, liczby ze skryptu; klasyfikacja „czysty nośnik danych” jest heurystyczna, z
  błędem rzędu kilku procent).

### Z4. Techniczne klocki skopiowane w każdym module

- **Waga:** Medium. **Klasa:** PREFERENCJA (częściowo świadoma decyzja z `docs/vision.md:384`: „osobne value objecty w
  każdym contexcie”).
- **Dowód:**
  - Zegar: pięć interfejsów `Clock` (`src/{Cart,Catalog,Ordering,Payment,Pricing}/Application/Port/Clock.php`), pięć
    `SystemClock`, pięć dubli w testach. `symfony/clock` jest już w `composer.lock:4009`. Inventory zegara nie ma i
    woła `new \DateTimeImmutable()` w domenie (`src/Inventory/Domain/Stock/StockItem.php:57,218`).
  - Identyfikatory: 22 klasy `*Id` (780 linii), w tym `VariantId` w pięciu modułach i `ProductId` w pięciu; wersje
    Pricing, Inventory i Cart są identyczne poza przestrzenią nazw i komentarzem. `UuidFormat` w dwóch egzemplarzach
    (`src/Ordering/Domain/UuidFormat.php`, `src/Payment/Domain/UuidFormat.php`). Wyrażenie regularne UUID (w dwóch wariantach wielkości liter)
    jest wpisane w 38 plikach `src`.
  - Kursory: 9 klas kursorów i 8 wyjątków `Invalid*Cursor`. `PromotionCursor` i `DiscountCodeCursor` różnią się tylko
    nazwą pola (`diff` po podmianie nazw daje wyłącznie komentarze i nazwy zmiennych; np. linie 18–22 i 59–66 obu
    plików są identyczne). Kodowanie base64url jest wpisane 11 razy.
  - Stronicowanie: blok „czytaj `perPage + 1`, utnij, policz kursor, policz `total` tylko na pierwszej stronie” jest
    wklejony w 10 handlerach (np. `ListPromotionsHandler.php:37-45` i `ListDiscountCodesHandler.php:46-54`), a
    tłumaczenie złego kursora na naruszenie pola `after` w 10 kontrolerach (np.
    `BackofficeOrdersController.php:85-93`, `ListPromotionsController.php:62-70`).
  - Nagłówek `private, no-store`: cztery niemal identyczne subskrybenty po ścieżce (Cart, Ordering, Payment,
    Category) i 22 ręczne ustawienia w kontrolerach pozostałych modułów.
  - Pieniądze: `Money` w Cart, Ordering i Pricing oraz `Amount` w Payment; tu różnice są rzeczywiste (inne
    niezmienniki), ale już `multiply` (Cart) i `times` (Ordering) to ta sama operacja pod dwiema nazwami.
- **Konsekwencja:** poprawka w jednym egzemplarzu (np. w dekodowaniu kursora) nie trafia do pozostałych; nowy moduł
  zaczyna od kopiowania ok. 10 plików. Brak skutków dla użytkownika dziś.
- **Rekomendacja:** rozdzielić pojęcia domenowe od technicznych. Zasada „to samo słowo znaczy co innego” uzasadnia
  osobne `Money`/`Amount`, ale nie dotyczy zegara, formatu UUID, kodeka kursora ani stronicowania. Przenieść do
  `Shared`: jeden zegar (PSR-20), jeden format UUID, kodek kursora z jednym wyjątkiem mapowanym w `ExceptionStatus` na
  400 przy `after`, pomocnik strony, jedną politykę cache po ścieżkach. `deptrac.yaml` już pozwala każdej warstwie na
  `Shared_Domain`. Szacunek: 25–35 plików i ok. 1500 linii mniej, ryzyko niskie (testy kursorów i list istnieją).
- **Pewność:** wysoka (pliki porównane `diff`-em, liczniki z `grep`).

### Z5. Osobny model persystencji: ORM użyty jako mapper wierszy z obejściami; zapowiedziana ocena decyzji nie jest udokumentowana

- **Waga:** Medium. **Klasa:** PREFERENCJA.
- **Dowód:** `docs/vision.md:379-383` nazywa osobne rekordy „założeniem początkowym”, które miało być ocenione po
  walking skeleton; w `docs/` nie ma śladu tej oceny (ADR-ów jest trzy, żaden o persystencji). Koszt dziś: 26 klas
  rekordów, 12 repozytoriów z ręcznym mapowaniem w obie strony, 30 klas `Dbal*`, razem 6646 linii (11% `src`).
  Repozytoria walczą z ORM-em:

  ```php
  // src/Pricing/Infrastructure/Persistence/Doctrine/DoctrineVariantPriceRepository.php:135-137
  // The versioned UPDATE compares with this value, so a record cached in the identity map with a newer
  // version cannot make a stale write pass.
  $record->version = $readVersion;
  ```

  Ten sam plik czyta listy z pominięciem ORM-u (linie 44–83) i ma dwa równoległe mapowania (`fromRow` i `toDomain`).
  `apps/api/CLAUDE.md:121-146` opisuje trzy style: rekordy ORM (Catalog, Pricing, Ordering, Payment), ORM z
  `refresh()` pod blokadą (Cart) i czysty DBAL, w którym rekordy służą tylko za definicję schematu (Inventory).
- **Konsekwencja:** programista dodający pole do agregatu zmienia agregat, rekord, mapowanie w jedną i w drugą stronę,
  a czasem trzecie mapowanie z wiersza SQL. Musi też znać pułapki identity map opisane w komentarzach. Bez skutków dla
  użytkownika.
- **Rekomendacja:** podjąć zapowiedzianą decyzję i zapisać ją jako ADR. Realne opcje: (a) zostawić, ale ujednolicić do
  jednego stylu zapisu; (b) tam, gdzie i tak rządzi SQL z blokadami (Inventory, Cart, odczyty), przejść w całości na
  DBAL i zostawić rekordy tylko jako schemat; (c) mapować agregaty przez Doctrine w XML, co vision sama wymienia. Nie
  rekomenduję przepisywania działającego kodu bez tej decyzji.
- **Pewność:** wysoka co do faktów; ocena opłacalności jest opinią. Nie sprawdzałem, czy testy integracyjne pokrywają
  każde obejście.

### Z6. Ciała żądań jako `mixed` z ręcznym sprawdzaniem typów

- **Waga:** Medium. **Klasa:** PREFERENCJA.
- **Dowód:** 112 właściwości `public mixed $…` w 28 z 34 klas żądań i zapytań w UI, 123 ręcznie wpisane komunikaty
  `message: '…'`, 40 metod `#[Assert\Callback]`. Przykład:

  ```php
  // src/Pricing/UI/Http/ChangePriceRequest.php:24-30
  #[Assert\Sequentially([
      new Assert\NotNull(message: 'Cena brutto jest wymagana.'),
      new Assert\Type('integer', message: 'Cena brutto musi być liczbą całkowitą w groszach.'),
      new Assert\Range(…),
  ])]
  public mixed $gross,
  ```

  Kontroler potem przywraca typ asercją (`src/Pricing/UI/Http/ChangePriceController.php:109`). Do tego własny resolver
  ciała (`src/Shared/UI/Http/RequiredKeysBodyResolver.php`, 120 linii, plus `RequiredKeysBody` i `PendingBody`),
  używany w 12 kontrolerach obok `#[MapRequestPayload]` w 13 innych. Uzasadnienie w komentarzach: polskie komunikaty i
  brak rzutowania. `symfony/translation` nie jest zainstalowany (`composer.json`), więc wbudowane komunikaty walidatora
  i serializera są po angielsku.
- **Konsekwencja:** każda nowa klasa żądania powtarza ten sam szablon; PHPStan nie zna typów pól, więc w `src` jest
  209 `\assert()` (45 w UI, 154 w Infrastructure: przy żądaniach, wynikach szyny i wierszach SQL). Asercje działają w produkcji tylko dlatego,
  że obraz nie ma `php.ini` i obowiązuje domyślne `zend.assertions = 1` (`docker/php/conf.d/app.ini:11`,
  `prod.ini` tego nie ustawia); standardowe utwardzenie obrazu (`-1`) po cichu je wyłączy.
- **Rekomendacja:** rozważyć typowane właściwości z `#[MapRequestPayload]` i tłumaczenia walidatora (`pl`), a własne
  komunikaty zostawić tam, gdzie niosą treść biznesową (zakres ceny w złotych). Przed zmianą sprawdzić testem, czy
  serializer w tej wersji rzeczywiście odrzuca `"100"` i `100.0` dla pola `int`: komentarz w
  `ChangePriceRequest.php:13-15` twierdzi, że typowane pole pozwoliłoby na rzutowanie. Ustawić `zend.assertions`
  jawnie w `prod.ini`, w którąkolwiek stronę.
- **Pewność:** wysoka co do liczb; średnia co do tego, ile da się uprościć bez zmiany kontraktu komunikatów (testy E2E
  mogą sprawdzać ich treść, a zachowania serializera nie mogłem uruchomić).

### Z7. Trzy sposoby budowania odpowiedzi, dwa sposoby opisu kontraktu, dwie nazwy pola kursora

- **Waga:** Low. **Klasa:** RYZYKO (dla spójności kontraktu), w części PREFERENCJA.
- **Dowód:**
  - klasy `*Response::of()` z atrybutami OpenAPI (58 klas: Catalog, Pricing, Inventory, Backoffice, część Ordering);
  - statyczne buildery tablic `BackofficeOrderJson`, `CustomerOrderJson`, `OrderLineJson` ze schematem pisanym ręcznie
    w `config/packages/nelmio_api_doc.yaml` (53 schematy, 1450 linii), w tym samym module co klasy `*Response`
    (`BackofficeCustomerResponse`);
  - widoki warstwy aplikacji oddawane wprost do `JsonResponse` (Cart:
    `src/Cart/UI/Http/GuestCartController.php:139`), też ze schematem w YAML.
  Pole następnej strony nazywa się `next` w Catalog, Inventory i Pricing
  (`src/Pricing/UI/Http/Promotion/PromotionListResponse.php:22`), a `nextCursor` w Ordering
  (`src/Ordering/UI/Http/BackofficeOrderJson.php:115`, `BackofficeCustomerListResponse.php:22`).
- **Konsekwencja:** panel musi pamiętać, która lista używa której nazwy; programista nie wie, który wzorzec jest
  obowiązujący, więc powstają kolejne warianty. Schemat w YAML i builder tablicy mogą się rozjechać (dziś pilnują tego
  testy funkcjonalne porównujące odpowiedź ze schematem, np. `tests/Functional/Ordering/BackofficeOrderEndpointTestTrait.php:186`).
- **Rekomendacja:** wybrać jeden wzorzec dla nowych endpointów i zapisać go w `apps/api/CLAUDE.md`; istniejące
  migrować przy okazji zmian. Nazwę pola kursora ujednolicić przy najbliższej zmianie kontraktu list zamówień.
- **Pewność:** wysoka.

### Z8. Trzy komendy konsolowe łapią `\Throwable` i zostawiają tylko jedną linię tekstu

- **Waga:** Low. **Klasa:** RYZYKO.
- **Dowód:**

  ```php
  // src/Cart/UI/Console/PurgeExpiredCartsCommand.php:53-57
  } catch (\Throwable $e) {
      $io->error(\sprintf('The purge stopped: %s The carts removed so far (%d) stay removed; …', …));
      return self::ERROR;
  }
  ```

  Tak samo `src/Ordering/UI/Console/RebuildReadModelsCommand.php:34-38` i
  `src/Pricing/UI/Console/ReconcileTaxCommand.php:51-55`. Żadna nie loguje wyjątku. `config/services.yaml` (definicja
  `Sentry\SentryBundle\EventListener\ConsoleListener`) zgłasza do Sentry tylko wyjątki nieobsłużone, więc te tam nie
  trafią. `cart:purge-expired` działa w produkcji jako CronJob (`infra/k8s/tests/test_jobs.py:104-109`).
  Dla porównania `src/Shared/UI/Console/BackfillCommand.php:64-65` loguje wyjątek na poziomie `error`.
- **Konsekwencja:** gdy nocne czyszczenie koszyków padnie (np. błąd SQL po migracji), operator dostanie alert o
  nieudanym Jobie i jedną linię z komunikatem wyjątku w logu poda, bez śladu stosu i bez zdarzenia w Sentry. Diagnoza
  wymaga ręcznego odtworzenia. Prawdopodobieństwo awarii niskie, koszt diagnozy podniesiony.
- **Rekomendacja:** w bloku `catch` dodać `$this->logger->error(…, ['exception' => $e])`, jak w `BackfillCommand`, albo
  po wypisaniu komunikatu rzucić wyjątek dalej.
- **Pewność:** wysoka co do kodu; średnia co do skutku w Sentry (konfigurację przeczytałem, nie uruchamiałem).
  Specyfikacja Sentry (`docs/features/sentry/spec.md:11`) mówi o „nieobsłużonych wyjątkach komend”, więc formalnie
  jest zgodnie; uważam, że to niezamierzona luka, bo czwarta komenda robi inaczej.

### Z9. Tymczasowa zgodność wersji `ProductCreated` bez terminu ani znacznika

- **Waga:** Low. **Klasa:** RYZYKO.
- **Dowód:** `src/Catalog/Application/Event/IntegrationEvents.php:15-17,30-32`:

  ```php
  * Version compatibility: the aggregate's ProductCreated is published as it is too, before its contract. […]
  * Remove it, and its type in IndexProduct, in the next version.
  …
  if ($event instanceof ProductCreatedInCatalog) {
      $published[] = $event;
  }
  ```

  i `src/Catalog/Application/EventHandler/IndexProduct.php:23-25,38` („a product created now is indexed by both
  events”).
- **Konsekwencja:** każdy nowy produkt trafia do outboxu dwoma zdarzeniami i jest indeksowany dwa razy. Przy imporcie
  dużego katalogu (cel: 10 mln produktów) to podwojona praca workera. Usunięcie zależy od tego, że ktoś pamięta o
  komentarzu.
- **Rekomendacja:** usunąć, jeśli wersja z tą zmianą jest już wdrożona; inaczej założyć zgłoszenie z warunkiem
  usunięcia. Przy okazji: domenowe i integracyjne `ProductChanged`/`ProductCreated` mają identyczne pola
  (`src/Catalog/Domain/Product/ProductChanged.php:16`, `src/Catalog/Application/Event/ProductChanged.php:18`); para
  ma sens dopiero wtedy, gdy kontrakt zacznie się różnić od zdarzenia agregatu.
- **Pewność:** wysoka co do kodu; nie wiem, ile wersji temu to dodano (eksport bez historii gita).

### Z10. Ten sam problem rozwiązany różnie w różnych modułach

- **Waga:** Low. **Klasa:** PREFERENCJA.
- **Dowód (wybór):**
  - Gdzie publikuje się zdarzenia: w handlerze (`src/Catalog/Application/Command/EditProductHandler.php:103`,
    `src/Ordering/Application/Command/PlaceOrderHandler.php:138`) albo w repozytorium
    (`src/Pricing/Infrastructure/Persistence/Doctrine/DoctrineVariantPriceRepository.php:106`,
    `src/Inventory/Infrastructure/Persistence/Doctrine/DoctrineStockItemRepository.php:167`).
  - Gdzie leżą widoki: `Application/View` (Cart, Ordering, Payment), `Application/Query` (Catalog, Pricing,
    Inventory, Backoffice), `Application/Command` (Inventory: `StockView`, `ReservationView`), `Application/Port`.
  - Domena płaska (55 plików w `src/Ordering/Domain`) albo w podkatalogach agregatów (Catalog, Pricing, Inventory).
  - Nazwa uprawnienia votera: prywatna stała w każdym kontrolerze (`'MANAGE_PRODUCTS'` w pięciu kontrolerach
    Catalog i w `DashboardController`) albo wspólna klasa (`BackofficeOrderAttributes`, `PromotionAttributes`).
  - Wynik szyny: `HandleTrait` (52 miejsca) albo ręcznie `->last(HandledStamp::class)` (12 miejsc).
  - Kontrolery jednoakcyjne (52) i wieloakcyjne (8, w tym oba koszyki po 6 akcji).
  - Język `detail` dla klienta API: z 76 wyjątków z komunikatem wpisanym literałem ok. 50 ma tekst po polsku, a ok.
    25 po angielsku, np. „Kategoria nie istnieje.” obok „Category not found.”.
  - Nazwy: wyjątki nazwane jak zdarzenia (`OrderTotalChanged`, `GuestCartMerged`, `DiscountCodeRejected`) leżą obok
    zdarzeń `OrderCancelled`, `OrderPaid` w tym samym katalogu; Inventory ma po dwa wyjątki o tej samej nazwie w
    `Application/Command` i `Domain/Reservation` (`InvalidReservation`, `ReservationStateConflict`), z których jeden
    opakowuje drugi.
- **Konsekwencja:** wyższy próg wejścia: nie da się przenieść nawyku z jednego modułu do drugiego. Angielskie `detail`
  może trafić na ekran panelu tam, gdzie front pokazuje `detail` bez własnego tekstu
  (`apps/admin/src/inventory/stockChange.ts:78`); nie potwierdziłem konkretnego przypadku.
- **Rekomendacja:** krótka sekcja „konwencje” w `apps/api/CLAUDE.md` (jedno miejsce na widoki, jedno na publikację
  zdarzeń, jeden język `detail`, przyrostek dla wyjątków mylonych ze zdarzeniami) i stosowanie jej w nowym kodzie.
- **Pewność:** wysoka co do faktów.

### Z11. Drobne resztki

- **Waga:** Low. **Klasa:** PREFERENCJA.
- **Dowód:** metody używane tylko w testach: `src/Cart/Domain/Money.php:51` (`multiply`) i
  `src/Ordering/Application/Port/NotificationClaim.php:35` (`isClaimed`). W `deptrac.yaml` są warstwy bez kodu
  (`Shared_Application`, `Customer_Domain`, `Backoffice_Domain`). `config/services.yaml` ma 29 jawnych aliasów portów
  o jednej implementacji, których autowiring nie potrzebuje, podczas gdy ok. 70 innych portów aliasu nie ma;
  wyjątkiem uzasadnionym jest `SitemapProductSource` (dwie implementacje). `EditProductHandler` i `CreateProductHandler`
  tworzą `ProductInput` przez `new` w konstruktorze (`src/Catalog/Application/Command/EditProductHandler.php:56`),
  choć ta sama klasa jest zarejestrowana jako usługa.
- **Konsekwencja:** kosmetyczna.
- **Rekomendacja:** usunąć przy okazji.
- **Pewność:** wysoka dla metod (sprawdzone w `src`, `tests`, `config`); średnia dla aliasów (nie uruchamiałem
  kontenera, żeby potwierdzić, że autowiring rozstrzyga każdy z nich).

## Mocne strony

- **Narzędzia jakości bez obejść.** `phpstan.dist.neon`: `level: max`, ścieżki `src` i `tests`, bez `ignoreErrors`,
  bez baseline. W `src` nie ma ani jednego `@phpstan-ignore`. `deptrac.yaml` nie ma `skip_violations`. PHP CS Fixer z
  `@Symfony:risky`, `declare_strict_types` i `strict_param`.
- **Czysta domena.** Zero importów z Symfony, Doctrine i innych bibliotek w `src/*/Domain`; warstwa aplikacji importuje
  z vendorów tylko atrybuty i wyjątki Messengera, `Uuid`, logger PSR.
- **Brak martwego kodu.** Skrypt szukający klas bez odwołań znalazł tylko adaptery wiązane przez autowiring. Zero
  `TODO`/`FIXME`/`@deprecated`, zero zakomentowanego kodu. Wszystkie 28 ścieżek panelu i wszystkie ścieżki sklepu z
  OpenAPI mają wywołanie we frontach.
- **Porty mają dublery testowe.** Ze 102 interfejsów o jednej implementacji produkcyjnej 93 ma odpowiednik w `tests`
  (106 nazwanych klas in-memory i 51 anonimowych, tylko 21 mocków). To realny powód istnienia portów: handlery są
  testowane bez bazy. Porty z dwiema implementacjami (`PaymentProvider`, `SitemapProductSource` z dekoratorem cache)
  są w pełni uzasadnione.
- **Jeden model błędów.** `src/Shared/Infrastructure/Http/ExceptionStatus.php` i `ProblemDetailsSubscriber.php`:
  interfejsy znacznikowe (`NotFound`, `Conflict`, `InvalidInput`, `Unavailable`) mapowane w jednym miejscu, komunikaty
  frameworka nigdy nie wyciekają, 5xx bez `detail`.
- **Obsługa wyjątków jest wąska.** Tylko 8 `catch (\Throwable)`, wszystkie w komendach albo z ponownym rzuceniem. Puste
  bloki `catch` są dwa, oba celowe i wąskie (`src/Cart/Application/Command/RemoveCartItemHandler.php:41`,
  `src/Catalog/Application/Command/CreateProductHandler.php:75`). 47 bloków `catch (\InvalidArgumentException)` obejmuje pojedyncze konstrukcje value objectów
  (sprawdzone skryptem: żaden blok `try` nie jest dłuższy niż 2 instrukcje).
- **Czytelność trudnych miejsc.** `src/Ordering/Application/Command/PlaceOrderHandler.php:44-62` opisuje kolejność
  kroków i blokad; `src/Pricing/Application/Command/SetVariantPriceHandler.php:40-48` tłumaczy, dlaczego zegar jest
  czytany po blokadzie.
- **Dobre użycie frameworka tam, gdzie pasuje.** Votery i `#[IsGranted]`, `access_token` z OIDC, `#[ORM\Version]`,
  middleware `doctrine_transaction`, `#[AsCommand]` z atrybutami opcji, `#[MapQueryString]`, dekoratory usług,
  `#[AsTaggedItem]` dla kroków backfillu.

### Dziesięć największych i najbardziej złożonych plików

| Plik | Linie | Ocena |
|---|---|---|
| `src/Ordering/Domain/Order.php` | 502 | Agregat z maszyną stanów; rozmiar uzasadniony, metody krótkie. |
| `src/Cart/Domain/Cart.php` | 424 | Jak wyżej; reguły limitów i scalania w jednym miejscu. |
| `src/Catalog/Domain/Category/CategoryTree.php` | 348 | Niezmienniki drzewa (cykl, głębokość, slug wśród rodzeństwa); uzasadniony. |
| `src/Catalog/UI/Http/Validation/ValidProductWriteValidator.php` | 345 | Najwyższa złożoność w `src` (103 rozgałęzienia) i duplikat reguł domeny: patrz Z1. |
| `src/Ordering/Infrastructure/Persistence/Doctrine/DoctrineOrderRepository.php` | 341 | Ręczne mapowanie 20 pól linii i zamówienia; koszt decyzji z Z5. |
| `src/Pricing/Domain/Promotion/Promotion.php` | 316 | Agregat; w porządku. |
| `src/Catalog/Infrastructure/Search/ElasticsearchSearchIndexMaintenance.php` | 311 | Przebudowa indeksu przez alias; złożoność wynika z zadania. |
| `src/Catalog/Domain/Product/Product.php` | 302 | Agregat; w porządku. |
| `src/Shared/Infrastructure/Observability/SentryEventFilter.php` | 292 | Maskowanie danych w zdarzeniach; długi, ale liniowy. |
| `src/Catalog/Infrastructure/Search/ElasticsearchProductSearch.php` + `ProductSearchQuery.php` | 189 + 263 | Budowa zapytania i facetów; najdłuższa metoda logiki to `aggregations` (ok. 79 linii), do podziału przy następnej zmianie. |

Najdłuższe „metody” według skryptu to w większości konstruktory kontrolerów, bo między nimi a akcją leży 50–70 linii
atrybutów OpenAPI. Rzeczywista logika nie przekracza ok. 85 linii (`DoctrineProductRepository::write`).

## Zbędna złożoność

### Co uprościć

| # | Zmiana | Plików mniej | Linii mniej | Ryzyko |
|---|---|---|---|---|
| 1 | Wspólny zegar, format UUID i identyfikatory techniczne w `Shared` (Z4) | 12–20 | ok. 600 | Niskie |
| 2 | Kodek kursora, jeden wyjątek kursora mapowany centralnie, pomocnik strony (Z4) | 8–10 | ok. 700 | Niskie; listy mają testy funkcjonalne |
| 3 | Zapytania wołane bezpośrednio zamiast przez `query.bus` (Z2) | 4 | ok. 600 | Średnie; dotyka wszystkich modułów, testy podmieniają część usług przez kontener |
| 4 | Spłaszczenie DTO odczytów i adapterów pulpitu (Z3) | 40–60 | ok. 1500 | Niskie do średniego; strażnikiem jest diff `openapi*.json` |
| 5 | Jedna polityka `no-store` po ścieżkach zamiast 4 subskrybentów i 22 ustawień ręcznych | 3 | ok. 150 | Niskie |
| 6 | Jedno źródło reguł zapisu produktu (Z1) | 0–1 | ok. 200 | Średnie; zmienia się droga komunikatów do pól |
| 7 | Typowane żądania i tłumaczenia walidatora (Z6) | 3 | ok. 400 | Średnie; nowa zależność i możliwa zmiana treści komunikatów |
| 8 | Usunięcie zgodności `ProductCreated`, martwych metod, pustych warstw Deptraca (Z9, Z11) | 0–1 | ok. 50 | Niskie po potwierdzeniu wdrożenia |

Razem ok. 70–100 plików (6–8%) i 4–4,5 tys. linii (ok. 7%) bez zmiany zachowania. Kolejność według stosunku zysku do
ryzyka: 1, 2, 5, 8, potem 4, na końcu 3, 6, 7. Punkty 3 i 7 zmieniają zasady projektu, więc wymagają decyzji
właściciela.

Uwaga o granicach modułów: `PlaceOrderHandler` wykonuje komendy Cart, Pricing i Inventory zagnieżdżone w jednej
transakcji bazy (`src/Ordering/Infrastructure/Inventory/CommandBusStockReservations.php:22-28`). Moduły są więc
odseparowane w czasie kompilacji, a nie w czasie działania. Wydzielenie któregoś do osobnej usługi i tak wymagałoby
przeprojektowania, więc warstwa tłumaczeń między modułami chroni dziś mniej, niż kosztuje.

### Czego nie ruszać

- Outbox z przekaźnikiem (`MessengerDomainEventPublisher`, `RelayDomainEventToBroker`) i podział transportów.
- Idempotencja i odcisk żądania w `PlaceOrderHandler`, wersjonowanie optymistyczne, blokada doradcza promocji.
- Inventory na DBAL z `SELECT … FOR UPDATE` (uzasadnienie w `apps/api/CLAUDE.md:126-134`).
- Model błędów (`ExceptionStatus`, `ProblemDetailsSubscriber`, interfejsy znacznikowe, `TypedProblem`). 134 klasy
  wyjątków to dużo, ale każda niesie kontrakt (`type`, status, tekst) dla frontów.
- Porty do usług zewnętrznych: wyszukiwarka, magazyn zdjęć, dostawcy płatności, poczta, cache sklepu.
- Repozytoria jako interfejsy: 13 sztuk, wszystkie z implementacją in-memory w testach.
- Osobne `Money` i `Amount` z różnymi niezmiennikami (Cart: nieujemne, Payment: dodatnie).
- `ClientIp`, `SentryEventFilter`, `ElasticsearchSearchIndexMaintenance`: własny kod wynikający ze specyfikacji, nie z
  braku wiedzy o frameworku.

## Braki

- Brak ADR-ów dla decyzji, które generują większość opisanego kosztu. `docs/vision.md:371-385` wymienia je jako „do
  opisania” („dlaczego DDD + hexagonal i gdzie to przesada”, osobny model persystencji, osobne value objecty,
  outbox); w `docs/adr/` są trzy pliki o innych tematach.
- Brak spisanej konwencji dla rzeczy z Z7 i Z10 (wzorzec odpowiedzi, miejsce widoków, język `detail`).
- `apps/api/CLAUDE.md` ma 1218 linii (136 KB) i opisuje prozą zachowanie pojedynczych klas i zapytań SQL. To drugi
  egzemplarz kodu do utrzymania; przy takiej szczegółowości rozjazd z kodem jest kwestią czasu.
- Brak reguły statycznej, która wykrywałaby kopiowanie (np. PHPCPD albo własna reguła PHPStana na powtórzone
  wyrażenie UUID). Deptrac pilnuje kierunku zależności, nie duplikacji, więc obecne zasady premiują kopiowanie.
- `zend.assertions` nie jest ustawione jawnie w obrazie produkcyjnym (patrz Z6).

## Czego nie zweryfikowano

- Nie uruchamiałem PHPStana, Deptraca, PHP CS Fixera ani testów: stwierdzam brak obejść w konfiguracji, nie to, że
  narzędzia przechodzą.
- Złożoność cyklomatyczna jest przybliżeniem (zliczanie słów kluczowych i operatorów), a długość metod liczona jest
  odstępem między deklaracjami, więc obejmuje atrybuty i docblocki.
- Martwy kod: sprawdziłem klasy po pełnej nazwie i metody publiczne o nazwie unikalnej w `src`. Metody o nazwach
  powtarzających się w wielu klasach (`of`, `from`, `value`) nie zostały sprawdzone.
- Liczba klas „czystych DTO” (355) pochodzi z heurystyki; nie przeglądałem każdej.
- Zachowanie serializera Symfony dla typowanych pól (`"100"`, `100.0` w polu `int`) i skutki Z8 w Sentry znam z
  lektury konfiguracji, nie z uruchomienia.
- Nie wiem, od ilu wdrożeń istnieje zgodność `ProductCreated` (brak historii gita w eksporcie).
- Testy (`apps/api/tests`, 635 plików, ok. 100 tys. linii) przejrzałem tylko pod kątem dublerów portów; ich jakość
  i pokrycie reguł z Z1 nie były przedmiotem tego obszaru.
- Z ok. 1286 plików ok. 1150 znam tylko z analizy skryptowej; pojedyncze odstępstwa od opisanych wzorców mogły mi
  umknąć.
