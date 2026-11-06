# CLAUDE.md – apps/api

Symfony 8, PHP 8.5, Doctrine ORM, PostgreSQL. DDD + hexagonal.
Zasady wspólne dla całego repo są w głównym `CLAUDE.md`, opis domeny w `docs/vision.md`.

## Komendy

Uruchamiaj z katalogu głównego przez `make -C apps/api <cel>`:
- `check` – PHPStan, Deptrac, PHP CS Fixer
- `test` – wszystkie testy, `test-unit` – tylko jednostkowe (bez Postgresa, Elasticsearch i RabbitMQ); dodatkowe
  argumenty PHPUnit w `PHPUNIT_ARGS`, np. `make -C apps/api test-unit PHPUNIT_ARGS='--filter CartTest'`
- `init` – migracje bazy deweloperskiej i `catalog:search-index:setup` (alias `products` i indeks, jeśli ich nie ma);
  idempotentny, wywołuje go `make up` po starcie usług
- `init-test` – migracje bazy `shop_test` i indeks testowy `test_products` tworzony od nowa
  (`catalog:search-index:setup --recreate`); wywołuje go `test` przed testami
- `migrate`, `migration` – uruchomienie i wygenerowanie migracji
- dane demo ładuje `make seed` z głównego `Makefile`: najpierw `catalog:seed-demo` (katalog), potem
  `pricing:seed-demo`, który czyści ceny i ich historię i ustawia cenę (źródło `seed`) każdemu wariantowi demo według
  wzorców kluczy wariantów z `fixtures/prices.yaml` (klucz z Catalog `ListVariantIdentities`:
  `<slug produktu>/<slug etykiety>`, wariant domyślny `<slug produktu>/standard`; `*` dopasowuje też `/`; pierwszy
  pasujący wzorzec w kolejności pliku; wariant bez wzorca kończy seed błędem bez zmian w danych); potem
  `ordering:seed-demo`, który usuwa wszystkie zamówienia i ich linie jednym `TRUNCATE` (sekwencja numerów zostaje,
  luki są dopuszczalne); na końcu `inventory:seed-demo`, który czyści stany, rezerwacje i historię stanów
  i ustawia stan fizyczny każdemu wariantowi demo według wzorców kluczy wariantów z `fixtures/stock.yaml` (ten sam
  klucz z `ListVariantIdentities`, ta sama składnia i zasada pierwszego pasującego wzorca; stan > 0 to przyjęcie ze
  źródłem `seed` i kluczem `seed:<variantId>`, stan 0 to rekord stanu bez wpisu historii; wariant bez wzorca kończy
  seed błędem bez zmian w danych), więc po seedzie nie ma zamówień ani ich rezerwacji. Wszystkie cztery komendy
  w `prod` wymagają `--force-reset`. Potem `make seed` woła `catalog:reindex`, który przebudowuje indeks wyszukiwania
  z cenami i dostępnością, a na końcu `storefront:cache:purge-all` (zdarzenia cen i stanów ze źródła `seed` niczego
  nie reindeksują ani nie unieważniają). Błąd kroku przerywa `make seed`
- `storefront:cache:purge-all` – unieważnia cały katalog w obu cache sklepu: purge tagu `catalog` w Varnishu, potem
  rewalidacja tagu `catalog` w sklepie. Każdą warstwę próbuje do 5 razy co 5 s (parametr
  `app.storefront_cache.retry_delay`; testy: 0), po nieudanym purge nie rewaliduje. Kod wyjścia: 0 – gotowe, 2 – warstwa
  nie odpowiedziała 200 po ostatniej próbie (np. niedostępny Varnish albo sklep), z komunikatem bez sekretów
- `pricing:reconcile-tax [--apply]` (przez `make -C apps/api shell` albo `docker compose exec api bin/console`) –
  narzędzie uruchamiane świadomie, np. po zmianie stawki w `PolishVatRates`; nie wywołuje go `make init`, `make up`
  ani `make seed`. Przechodzi wszystkie ceny wariantów partiami po `variant_id` razem z wariantami Catalog
  (`ListVariantIdentities`) i wypisuje w jednej linii każdą cenę ze stawką inną niż bieżąca stawka jej klasy albo
  z klasą inną niż klasa produktu wariantu w Catalog (cena wariantu, którego nie ma w Catalog, zachowuje swoją
  klasę).
  Z `--apply` przelicza je (`reprice`: to samo brutto, źródło `tax_reconciliation`), każdą w osobnej transakcji;
  zapis odrzucony przez równoległą zmianę ceny ponawia raz na cenie odczytanej od nowa (świeży EntityManager), drugi
  konflikt zostaje w raporcie. Kod wyjścia: 0 – wszystko spójne albo naprawione, 1 – zostały rozbieżności (bez
  `--apply` znalezione; z `--apply` nienaprawione: dwa konflikty albo klasa bez stawki), 2 – przerwane błędem
  (np. niedostępna baza), naprawione wcześniej ceny zostają zmienione
- `cart:purge-expired [--batch-size=1000]` (przez `make -C apps/api shell` albo `docker compose exec api bin/console`) –
  narzędzie uruchamiane świadomie (ręcznie albo z crona); nie wywołuje go `make init`, `make up` ani `make seed`,
  a w MVP nie ma harmonogramu. Usuwa koszyki z `expires_at` w przeszłości (gości w każdym statusie i klientów) razem
  z liniami (kaskada FK), partiami po `--batch-size` (domyślnie 1000) id po indeksie `expires_at`. Każda partia to
  osobna komenda `PurgeExpiredCarts` na `command.bus`, czyli osobna transakcja, powtarzana do partii pustej;
  koszyk zablokowany przez równoległą zmianę jest pomijany (`FOR UPDATE SKIP LOCKED`), a odnowiony nie jest usuwany.
  Wypisuje każdą partię i sumę. Kod wyjścia: 0 – gotowe (także gdy nic nie wygasło), 1 – rozmiar partii nie jest
  dodatnią liczbą całkowitą (bez zmian), 2 – przerwane błędem (np. niedostępna baza), usunięte wcześniej partie
  zostają usunięte
- `openapi` – eksport specyfikacji do `openapi.json` (sklep: endpointy publiczne i klienta)
  oraz `openapi-admin.json` (panel: `/api/v1/admin`). Zrzut idzie przez pliki tymczasowe, więc nieudany eksport
  kończy się błędem i nie zmienia ani nie obcina plików. CI sprawdza, że commitowane pliki są aktualne

## Architektura

- Kod jest podzielony na bounded contexty: `src/<Context>/{Domain,Application,Infrastructure,UI}`.
- Domain nie zależy od Symfony, Doctrine ani żadnego frameworka. Tylko czysty PHP.
- Domena nie jest mapowana przez Doctrine. Persystencja przez rekordy w `<Context>/Infrastructure/Persistence/Doctrine`
  mapowane atrybutami, z relacjami między rekordami. Repozytoria mapują agregat ↔ rekord, a agregat odtwarzają
  przez `reconstitute()`, które nie zapisuje zdarzeń.
- Wyjątek: Inventory. Stan, rezerwacje i historię zapisuje i czyta przez DBAL na połączeniu Doctrine, w transakcji
  `command.bus` (jawne `SELECT … FOR UPDATE`, `INSERT … ON CONFLICT DO NOTHING`, `UPDATE` z wersją). Rekordy Doctrine
  Inventory są tylko definicją schematu dla `doctrine:schema` i migracji, repozytoria ich nie używają. Powody:
  rollback do savepointu przy zagnieżdżonym dispatchu nie czyści `UnitOfWork`, więc `flush` wywołującego zapisałby
  wycofane zmiany; błąd bazy zamyka wspólny `EntityManager`; `find()` z `PESSIMISTIC_WRITE` na encji już obecnej
  w identity map nie odświeża jej stanu (ryzyko sprzedaży ponad stan); `ON CONFLICT` i tak wymaga SQL.
- Wyjątek: Cart. Zapisuje przez rekordy Doctrine, ale każda komenda zaczyna od blokady wiersza koszyka przez ORM
  (`find()` z `PESSIMISTIC_WRITE`). Rekord już obecny w identity map jest odświeżany pod blokadą
  (`refresh()` z `PESSIMISTIC_WRITE`), a po nim jego linie: kaskada `refresh` Doctrine czytałaby (i blokowała) linie
  przed wierszem koszyka, w odwrotnej kolejności niż inne transakcje. Koszyk klienta zakłada
  `INSERT … ON CONFLICT DO NOTHING` przed blokadą (`lockOrCreateForCustomer`), bo naruszenie unikalności przy dwóch
  równoległych pierwszych dodaniach zamknęłoby `EntityManager`; gdy koszyk zniknął między `INSERT` a blokadą
  (usunął go `cart:purge-expired`), `lockOrCreateForCustomer` ponawia `INSERT` i blokadę raz. Wyjątek od blokady przez
  ORM: `PurgeExpiredCarts` usuwa partię jednym `DELETE` przez DBAL z `FOR UPDATE SKIP LOCKED` (bez ORM i repozytorium).
  Dwie blokady naraz bierze tylko scalenie
  (`MergeGuestCart`), zawsze w kolejności: koszyk gościa, potem koszyk klienta, i zapisuje oba w jednej transakcji,
  więc nie ma zakleszczeń, a koszyk gościa jest `merged` tylko po udanym przeniesieniu linii. Scalenie bez
  przeniesienia (`nothing_to_merge`, `already_merged`) czyta koszyk klienta bez blokady, więc trzyma tylko blokadę
  wiersza wskazanego jako koszyk gościa (może to być koszyk innego klienta). Odczyty (`GetCart`, `GetCartSummary`) idą przez DBAL,
  bez blokady i bez transakcji. Linie są zapisywane w miejscu po wariancie, więc usunięcie i ponowne dodanie wariantu
  w jednej zmianie nie trafia na unikalny indeks `(cart_id, variant_id)`.
- Ordering składa zamówienie (`PlaceOrder`) w jednej transakcji `command.bus`: komendy Cart (`LockCartForOrder`,
  `ClearCartAfterOrder`) i Inventory (`ReserveStock` z kluczem `orderId`) idą przez porty Ordering jako zagnieżdżony
  dispatch na tym samym połączeniu (savepoint), a ceny (Pricing `GetCurrentPrices`) i nazwy (Catalog
  `GetVariantSummaries`) przez `query.bus`. Zasady:
  - handler nigdy nie łapie wyjątku zagnieżdżonej komendy, żeby pisać dalej: każdy błąd wycofuje całą transakcję
    (koszyk, rezerwację, zamówienie, outbox). Adapter tłumaczy tylko niedobór Inventory (`InsufficientStock`) na
    `InsufficientStockForOrder` i rzuca go dalej, inne wyjątki przekazuje bez zmian;
  - zamówienie zapisuje się na końcu (po rezerwacji), więc `flush` komend zagnieżdżonych nie zapisuje niczego
    z Ordering; Ordering zapisuje przez rekordy Doctrine według ogólnej zasady;
  - kolejność blokad: wiersz koszyka (`LockCartForOrder`), potem wiersze stanów (`lockMany` Inventory, rosnąco po
    `variant_id`). Inne komendy Cart blokują tylko koszyki, Inventory tylko stany i rezerwacje, więc nie ma cyklu;
  - idempotencja: zamówienie z tym samym `Idempotency-Key` i tym samym odciskiem żądania (koszyk i treść) jest
    zwracane jako powtórzenie przed blokadą koszyka i jeszcze raz pod nią; inny odcisk to `IdempotencyKeyReused` (409),
    także z unikalnego indeksu `idempotency_key` przy równoległym złożeniu z innego koszyka;
  - numer zamówienia (`R-` i wartość od 100001) bierze `nextval('ordering_order_number_seq')` przez DBAL. Sekwencja
    nie jest częścią transakcji: wycofane złożenie zostawia lukę. Schemat Doctrine dostaje sekwencję od listenera
    `OrderNumberSequenceSchema` (mapowanie nie wyraża sekwencji poza id), więc diff migracji jej nie usuwa.
- E-mail z potwierdzeniem zamówienia wysyła handler zdarzenia `SendOrderConfirmation` (`event.bus`, transport `events`,
  po `OrderPlaced` z outboxa). Wysyłka idzie przez Symfony Mailer (`symfony/mailer`) synchronicznie, bez Messengera:
  `framework.mailer.message_bus: false` (inaczej `MailerInterface::send()` wysłałby `SendEmailMessage` na domyślną
  szynę `command.bus`, a błąd SMTP nie wróciłby do handlera i nie uruchomił ponowień transportu `events`). Konfiguracja
  w zmiennych środowiska: `MAILER_DSN` (`smtp://mailpit:1025`) i `MAILER_FROM` (`Rytm <zamowienia@rytm.localhost>`),
  ustawianych w `infra/docker-compose.yml` dla `api` i `worker` (testy i CI biorą je z serwisu `api`; `phpunit.dist.xml`
  ma te same wartości domyślne). Treść składa `OrderConfirmationText` (Ordering Application), a port
  `OrderConfirmationMailer` ma adapter `SymfonyOrderConfirmationMailer` w Ordering Infrastructure. Handler najpierw
  wysyła, potem zapisuje `confirmationSentAt` z wersją, bez transakcji wokół SMTP: awaria między jednym a drugim albo
  równoległe dostarczenie tego samego zdarzenia może dać drugi e-mail (konflikt wersji przy zapisie znacznika kończy
  się wyjątkiem i ponowieniem, które widzi znacznik i nic nie wysyła).
- Warstwa Application: handlery komend i zapytań na szynach Symfony Messenger, porty jako interfejsy.
- UI: kontrolery HTTP i komendy konsoli. Tylko tłumaczą wejście na komendę/zapytanie i wynik na odpowiedź.
- Context nigdy nie importuje Domain innego contextu. Używaj zdarzeń domenowych albo portu w warstwie aplikacji.
- Brak wspólnych value objectów między contextami. Każdy context ma własne (np. `Price` w Pricing i `Amount` w Payment).
- Deptrac pilnuje tych granic. Jeśli Deptrac zgłasza błąd, popraw projekt, nie edytuj `deptrac.yaml`.

## Przepisy Fleksa

- Przepis `symfony/framework-bundle` tworzy `AGENTS.md`, a `symfony.lock` go wymienia. Plik jest w `.gitignore`
  (nie da się go zacommitować przez pomyłkę), a zasady dla agenta są w tym `CLAUDE.md`. Po `composer require`,
  `composer update` i `composer recipes:update` usuń odtworzony `AGENTS.md` i przejrzyj resztę zmian przepisów.

## Reguły modelu

- Pieniądze zawsze jako liczba całkowita w groszach plus waluta, nigdy float. Zaokrąglanie VAT jawnie, w jednym miejscu.
- Agregaty edytowane równolegle (zamówienie, stan magazynowy, cena) mają optimistic locking (pole wersji): edycja
  z widoku sprawdza wersję, na której się opiera. Wyjątek: atomowe operacje na stanie magazynowym (przyjęcie,
  rezerwacja, zwolnienie, zatwierdzenie) nie sprawdzają wersji z widoku, tylko blokują wiersze (`SELECT … FOR UPDATE`
  w `StockItemRepository::lockMany`, w kolejności rosnącego `variant_id`; przyjęcie i korekta wariantu bez stanu
  zakładają wiersz przez `lockOrCreate`, `INSERT … ON CONFLICT DO NOTHING` przed blokadą) i czytają stan od nowa po
  uzyskaniu blokady; zapis i tak podnosi wersję. Korekta z panelu blokuje wiersz i dodatkowo sprawdza wersję z widoku.
- Koszyk nie ma optimistic locking ani wersji z widoku: zmiany szereguje blokada wiersza koszyka, dodanie zwiększa
  ilość, a zmiana ilości z nieaktualnej karty nadpisuje nowszą (ostatni zapis wygrywa).
- Zdarzenia domenowe zapisywane w transactional outbox w tej samej transakcji co agregat, potem wysyłane do RabbitMQ.
  Kto je publikuje: handler komendy (`releaseEvents()` agregatu i `DomainEventPublisher` w `command.bus`; Catalog
  `ProductCreated`, Ordering `OrderPlaced`), gdy jedyną drogą zapisu jest ta komenda. Gdy agregat ma wiele ścieżek
  zapisu, także poza `command.bus` (Pricing: panel, seed, `pricing:reconcile-tax`; Inventory: panel, seed, port
  rezerwacji w zagnieżdżonym dispatchu), publikuje je `save` repozytorium w transakcji zapisu (`VariantPriceChanged`
  w `DoctrineVariantPriceRepository`, `StockLevelChanged` w `DoctrineStockItemRepository`), więc żadna ścieżka go nie
  pominie, a odrzucony albo wycofany zapis nie zostawia zdarzenia.
- Odbiorcy zdarzeń muszą być idempotentni: to samo zdarzenie może przyjść więcej niż raz.
- Zamówienie przechowuje kopię cen, rabatów i adresu z chwili złożenia, nie referencje.
- Elasticsearch to tylko model odczytu. Nigdy nie podejmuj na jego podstawie decyzji biznesowych.

## API

- REST, JSON, wersjonowane pod `/api/v1`.
- Wejście przez DTO z Symfony Validator. Domena dodatkowo pilnuje swoich niezmienników. Złe parametry zapytania
  (`#[MapQueryString]`) to 400, zła treść żądania (`#[MapRequestPayload]`, tylko JSON, typy bez rzutowania) to 422;
  oba z listą `violations`.
- Dokumentacja OpenAPI z kodu (NelmioApiDocBundle). Każdy endpoint ma opisane żądanie, odpowiedzi i błędy.
- Autoryzacja: JWT z Keycloak, walidowany w authenticatorze Symfony. Dwa firewalle:
  `/api/v1/admin/...` przyjmuje tylko tokeny z realmu `backoffice` (pracownicy, `Staff`),
  reszta API tylko z realmu `shop` (klienci, `Customer`). Sprawdzany jest wystawca tokena.
- Nie twórz wspólnej klasy `User` dla klientów i pracowników. To osobne byty.
- Uprawnienia w voterach. Pracownik: na podstawie ról z tokena (`admin`, `support`, `warehouse`, `marketing`).
  Klient: tylko własne dane (właściciel zasobu = `sub` z tokena).
- Zmiany z panelu zapisują identyfikator pracownika (`sub` z tokena realmu `backoffice`).
- Błędy w formacie RFC 9457 (problem details). `type` to domyślnie `about:blank`. Wyjątek projektu, którego przyczynę
  klient ma rozróżniać maszynowo, implementuje `App\Shared\Domain\Exception\TypedProblem` (`problemType()`) i trzyma
  wartość w stałej `TYPE`; subscriber wpisuje ją do odpowiedzi, a Shared nie zna contextów. Format wartości:
  `tag:piotrp321.github.io,2026:shop/problems/<przyczyna>` (identyfikator, nie adres). Dziś mają je pięć 409 Ordering
  (schemat OpenAPI `OrderConflictProblem` wylicza ich wartości w `enum`; nowy typ dopisz też tam).
- `detail` w błędzie ma tylko wyjątek projektu: implementuje znacznik z `App\Shared\Domain\Exception`
  (`NotFound` → 404, `Conflict` → 409, `InvalidInput` → 422, goły `ProblemException` → 422). Domena nie zna statusów
  HTTP, mapowanie jest w `Shared\Infrastructure\Http`. Komunikat bez danych wewnętrznych i wejścia użytkownika
  (dotyczy też komunikatów naruszeń własnych constraintów). Wyjątki frameworka dostają stały ogólny opis.
  Subscriber rozpakowuje tylko `HandlerFailedException` z jednym wyjątkiem, `previous` poza tym ignoruje.
- Operacje nieidempotentne (złożenie zamówienia, rozpoczęcie płatności, przyjęcie magazynowe) wymagają nagłówka
  `Idempotency-Key`. Brak albo zły format klucza to 400 z naruszeniem nagłówka. Przyjęcie z tym samym kluczem i tą samą
  treścią (wariant, ilość, notatka) nic nie zmienia i zwraca 200 z bieżącym stanem zamiast 201; ten sam klucz z inną
  treścią albo dla innego wariantu to 409. Klucze przyjęć są w historii stanów na zawsze (unikalny indeks).
  Złożenie zamówienia (`POST /api/v1/orders` gościa, `POST /api/v1/me/orders` klienta) z tym samym kluczem, z tego
  samego koszyka (id koszyka gościa z treści albo `sub` z tokena) i z tą samą treścią niczego nie zmienia i zwraca 200
  z tym samym zamówieniem zamiast 201, także gdy koszyk gościa w międzyczasie wygasł albo został scalony; ten sam klucz
  z innego koszyka, od innego klienta albo z inną treścią to 409 `IdempotencyKeyReused` bez żadnych danych zamówienia,
  które go użyło. Klucze zamówień są w `ordering_order` na zawsze (unikalny indeks).
- Rate limiting (Symfony RateLimiter na Redis) na logowaniu, checkout i webhookach.
- Webhooki zewnętrzne: weryfikacja podpisu i deduplikacja po identyfikatorze zdarzenia.

## Infrastruktura

- Varnish (`infra/varnish/default.vcl`) stoi tylko przed publicznymi odczytami `GET` katalogu: `/api/v1/products`,
  `/api/v1/products/*`, `/api/v1/categories`, `/api/v1/product-offers`. Czyta przez niego wyłącznie serwer sklepu
  (`API_CATALOG_URL`); inne ścieżki, żądania z `Authorization` albo `Cookie` i metody inne niż `GET`/`HEAD` Varnish
  przepuszcza do API bez cache. TTL bierze tylko z `Cache-Control` odpowiedzi; bez nagłówka, z `private` albo
  `no-store` nic nie trzyma. Endpointy z autoryzacją, koszyk i checkout zawsze `private, no-store`.
- Tagi cache (xkey) bez FOSHttpCacheBundle. Słownik: `catalog` (każdy odczyt katalogu) i `product-<id>` (UUID małymi
  literami) dla każdego produktu, którego dane zawiera odpowiedź. Nazwy buduje wyłącznie `CacheTags`
  (`Shared\Infrastructure\Http\Cache`: stała `CATALOG`, `product(id)`, inny napis niż UUID to wyjątek), a nagłówek
  `xkey: <tag> <tag>` (spacje) ustawia w kontrolerze `CacheTagger::tag()`. Varnish usuwa `xkey` przed wysłaniem
  odpowiedzi. Dziś: lista produktów (`catalog` i `product-<id>` każdego produktu strony) i drzewo kategorii
  (`catalog`) z `Cache-Control: public, max-age=60`; oferty (`catalog` i `product-<id>` każdego żądanego id) z `public,
  max-age=60, s-maxage=3600`; szczegóły produktu `GET /api/v1/products/{slug}` (`ProductDetailsController`): 200 z `public,
  max-age=60, s-maxage=3600` i tagami `catalog` `product-<id>`, 404 „Produkt nie istnieje.” (też dla sluga
  niezgodnego z formatem `Slug`) z `public, max-age=60` i tagiem `catalog`. Zdjęcia szczegółów są w rozmiarze
  `product` (`ImageSize`), a `GetProductBySlug` czyta wprost z tabel Catalog przez port `ProductDetailsSource`.
- Trasa publicznego odczytu ma domyślną wartość `PublicReadErrorCacheSubscriber::ROUTE_DEFAULT`
  (`defaults: [... => true]` w `#[Route]`): jej 400, 401 (także z firewalla) i 503 dostają `private, no-store` bez tagów.
- Unieważnianie: `StorefrontCache` (`Shared\Infrastructure\Http\Cache`, implementacja `HttpStorefrontCache` na
  kliencie HTTP Symfony) robi twardy `PURGE` do `VARNISH_URL` z `xkey-purge` i `X-Purge-Token`
  (`VARNISH_PURGE_SECRET`), a dopiero po nim `POST <STORE_INTERNAL_URL>/api/revalidate` z `{tags}` i
  `Authorization: Bearer <STORE_REVALIDATE_SECRET>`; własne limity czasu (Varnish 5 s, sklep 30 s, zamiast domyślnych
  2 s i 5 s klienta). Każdy błąd (status inny niż 200, sieć, timeout) to `StorefrontCacheFailure`, a błąd purge
  zatrzymuje rewalidację. Wołają go konsumenci zdarzeń na transporcie `events`: `InvalidateStorefrontOnPriceChange`
  (`Pricing_Infrastructure`, `VariantPriceChanged`) i `InvalidateStorefrontOnStockLevelChange`
  (`Inventory_Infrastructure`, `StockLevelChanged`): produkt z Catalog `GetVariant` (`query.bus`), `invalidateProduct`.
  Wariant nieznany Catalogowi i źródło `seed` to wiadomość obsłużona bez unieważnienia; błąd `StorefrontCache` idzie
  dalej (ponowienia transportu `events`, potem `failed`). Bez zdarzenia (np. koniec okresu stawki VAT) odpowiedź
  żyje do `s-maxage`.
- Nowy publiczny endpoint katalogu = ścieżka na liście w VCL, jawny `Cache-Control`, tagi w odpowiedzi, znacznik
  trasy i unieważnianie po zmianie danych (zdarzenie i konsument wołający `StorefrontCache`). Bez tego zmiana danych
  nie będzie widoczna w sklepie przed końcem `max-age`/`s-maxage`.

- Indeks wyszukiwania (Elasticsearch, alias `products`) należy do Catalog. Dokument (`ProductIndexDefinition`,
  `dynamic: strict`): dane produktu, `name`, `category_names` (nazwy kategorii produktu i ich przodków)
  i `short_description` z analizatorem `polish_text` (`lowercase`, `polish_stem`, `asciifolding`) i podpolem `folded`
  (bez stemmera), `variants` (`nested`: `gross` brutto w groszach, brak bez ceny; `purchasable`; `axes`; `options` jako
  `<oś>` U+001F `<wartość>` jak w Catalog) i `from_gross` (cena „od”, brak bez wariantu możliwego do kupienia).
  Ceny i dostępność daje port `ProductOfferFacts` (`Catalog_Application`) z adapterem `QueryBusProductOfferFacts`
  w `Cart_Infrastructure` (Cart `GetProductOffers` na `query.bus`, paczki po 48): reguła „możliwy do kupienia” i cena
  „od” są tylko w Cart. Produkt bez faktów nie ma cen ani nic do kupienia. Catalog nie importuje Cart, Pricing ani
  Inventory.
- Zapis produktu do indeksu robi `ProductIndexing::reindex` (`Catalog_Application`, poza `command.bus`): znacznik
  czasu w mikrosekundach pobrany przed odczytem (DBAL Catalog i `ProductOfferFacts`) jest wersją dokumentu
  z `version_type=external_gte`, więc zapis starszego odczytu po nowszym to wynik „przestarzały”. Wyzwalacze na
  transporcie `events`: `IndexProduct` (`ProductCreated`; brak produktu to `failed`), `ReindexProductOnPriceChange`
  (`Pricing_Infrastructure`, `VariantPriceChanged`) i `ReindexProductOnStockLevelChange` (`Inventory_Infrastructure`,
  `StockLevelChanged`): produkt z Catalog `GetVariant`, wariant nieznany, brak produktu i źródło `seed` to wiadomość
  obsłużona bez zapisu; `SearchUnavailable` i `OfferFactsUnavailable` są ponawiane, `SearchIndexError` idzie do
  `failed`. Przebudowa (`catalog:reindex`) pobiera fakty ofert każdej paczki; ich błąd przerywa przebudowę, a zmiany
  cen i stanów obsłużone w jej trakcie trafiają do starego indeksu (przebudowa nie jest online).

- Pliki (zdjęcia produktów) przez Flysystem na Garage, prywatny bucket. Nigdy na lokalny dysk kontenera.
- Upload: walidacja typu po zawartości (MIME), limit rozmiaru i wymiarów.
- Adresy obrazów: jeden serwis buduje podpisane (HMAC) adresy imgproxy dla zamkniętej listy rozmiarów.
  Odpowiedzi API zwracają gotowe adresy, fronty niczego nie podpisują.
- Logi przez Monolog w JSON. Correlation ID z nagłówka HTTP przekazywany w stemplu Messengera do konsumentów.
- Nigdy nie edytuj migracji, która jest już w repo. Utwórz nową.
- Wyszukiwanie produktów po nazwie w panelu (`SearchBackofficeProducts`) używa rozszerzenia PostgreSQL `pg_trgm`
  (indeks GIN `gin_trgm_ops` na `catalog_product.name`, tworzony migracją) i stronicuje kursorem keyset po `(name, id)`.
  Indeks GIN obsługuje frazy od 3 znaków; fraza 2-znakowa (minimum w API) nie ma trigramów, więc idzie skanem indeksu
  `(name, id)` z filtrem `ILIKE`.
  Rozszerzenie musi być dostępne w bazie, każda baza przechodzi przez migracje.
- Profilowanie: SPX (php-spx) jest tylko w obrazie deweloperskim (target `dev` w Dockerfile), nigdy w obrazie produkcyjnym.
  Przy problemie z wydajnością najpierw zmierz w SPX, potem optymalizuj.

## Testy

- Logika domeny i aplikacji: testy jednostkowe z adapterami in-memory. Mają być szybkie (sekundy),
  bo uruchamiasz je przy każdej zmianie.
- Infrastruktura (Doctrine, Elasticsearch, RabbitMQ, Garage): testy integracyjne na prawdziwych usługach.
- Każdy endpoint: co najmniej jeden test funkcjonalny, w tym przypadek bez autoryzacji i z błędnymi danymi.
- Dane testowe przez fabryki (Foundry), a nie tablice budowane ręcznie w każdym teście.
- Poprawka błędu zaczyna się od testu, który ten błąd odtwarza i nie przechodzi.

## Czego nie robić

- Nie umieszczaj logiki biznesowej w kontrolerach, listenerach Doctrine ani w handlerach wiadomości.
  Handler orkiestruje, decyzje podejmuje domena.
- Nie wołaj innego contextu przez jego repozytorium ani encje.
