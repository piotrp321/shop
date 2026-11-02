# CLAUDE.md – apps/api

Symfony 8, PHP 8.5, Doctrine ORM, PostgreSQL. DDD + hexagonal.
Zasady wspólne dla całego repo są w głównym `CLAUDE.md`, opis domeny w `docs/vision.md`.

## Komendy

Uruchamiaj z katalogu głównego przez `make -C apps/api <cel>`:
- `check` – PHPStan, Deptrac, PHP CS Fixer
- `test` – wszystkie testy, `test-unit` – tylko jednostkowe (bez Postgresa, Elasticsearch i RabbitMQ)
- `init` – migracje bazy deweloperskiej i `catalog:search-index:setup` (alias `products` i indeks, jeśli ich nie ma);
  idempotentny, wywołuje go `make up` po starcie usług
- `init-test` – migracje bazy `shop_test` i indeks testowy `test_products` tworzony od nowa
  (`catalog:search-index:setup --recreate`); wywołuje go `test` przed testami
- `migrate`, `migration` – uruchomienie i wygenerowanie migracji
- dane demo ładuje `make seed` z głównego `Makefile`: najpierw `catalog:seed-demo` (katalog), potem
  `pricing:seed-demo`, który czyści ceny i ich historię i ustawia cenę (źródło `seed`) każdemu produktowi demo według
  wzorców slugów z `fixtures/prices.yaml` (pierwszy pasujący wzorzec w kolejności pliku; produkt bez wzorca kończy
  seed błędem bez zmian w danych). Obie komendy w `prod` wymagają `--force-reset`
- `pricing:reconcile-tax [--apply]` (przez `make -C apps/api shell` albo `docker compose exec api bin/console`) –
  narzędzie uruchamiane świadomie, np. po zmianie stawki w `PolishVatRates`; nie wywołuje go `make init`, `make up`
  ani `make seed`. Przechodzi wszystkie ceny partiami po `product_id` razem z produktami Catalog
  (`ListProductsForPricing`) i wypisuje w jednej linii każdą cenę ze stawką inną niż bieżąca stawka jej klasy albo
  z klasą inną niż klasa produktu w Catalog (cena produktu, którego nie ma w Catalog, zachowuje swoją klasę).
  Z `--apply` przelicza je (`reprice`: to samo brutto, źródło `tax_reconciliation`), każdą w osobnej transakcji;
  zapis odrzucony przez równoległą zmianę ceny ponawia raz na cenie odczytanej od nowa (świeży EntityManager), drugi
  konflikt zostaje w raporcie. Kod wyjścia: 0 – wszystko spójne albo naprawione, 1 – zostały rozbieżności (bez
  `--apply` znalezione; z `--apply` nienaprawione: dwa konflikty albo klasa bez stawki), 2 – przerwane błędem
  (np. niedostępna baza), naprawione wcześniej ceny zostają zmienione
- `openapi` – eksport specyfikacji do `openapi.json` (sklep: endpointy publiczne i klienta)
  oraz `openapi-admin.json` (panel: `/api/v1/admin`). Zrzut idzie przez pliki tymczasowe, więc nieudany eksport
  kończy się błędem i nie zmienia ani nie obcina plików. CI sprawdza, że commitowane pliki są aktualne

## Architektura

- Kod jest podzielony na bounded contexty: `src/<Context>/{Domain,Application,Infrastructure,UI}`.
- Domain nie zależy od Symfony, Doctrine ani żadnego frameworka. Tylko czysty PHP.
- Domena nie jest mapowana przez Doctrine. Persystencja przez rekordy w `<Context>/Infrastructure/Persistence/Doctrine`
  mapowane atrybutami, z relacjami między rekordami. Repozytoria mapują agregat ↔ rekord, a agregat odtwarzają
  przez `reconstitute()`, które nie zapisuje zdarzeń.
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
- Agregaty edytowane równolegle (zamówienie, stan magazynowy, cena) mają optimistic locking (pole wersji).
- Zdarzenia domenowe zapisywane w transactional outbox w tej samej transakcji co agregat, potem wysyłane do RabbitMQ.
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
- Błędy w formacie RFC 9457 (problem details).
- `detail` w błędzie ma tylko wyjątek projektu: implementuje znacznik z `App\Shared\Domain\Exception`
  (`NotFound` → 404, `Conflict` → 409, `InvalidInput` → 422, goły `ProblemException` → 422). Domena nie zna statusów
  HTTP, mapowanie jest w `Shared\Infrastructure\Http`. Komunikat bez danych wewnętrznych i wejścia użytkownika
  (dotyczy też komunikatów naruszeń własnych constraintów). Wyjątki frameworka dostają stały ogólny opis.
  Subscriber rozpakowuje tylko `HandlerFailedException` z jednym wyjątkiem, `previous` poza tym ignoruje.
- Operacje nieidempotentne (złożenie zamówienia, rozpoczęcie płatności) wymagają nagłówka `Idempotency-Key`.
- Rate limiting (Symfony RateLimiter na Redis) na logowaniu, checkout i webhookach.
- Webhooki zewnętrzne: weryfikacja podpisu i deduplikacja po identyfikatorze zdarzenia.

## Infrastruktura

- Varnish stoi tylko przed publicznymi endpointami `GET` katalogu i wyszukiwarki. Każda taka odpowiedź
  ma jawny `Cache-Control` i tagi obiektów (FOSHttpCacheBundle, xkey). Endpointy z autoryzacją,
  koszyk i checkout zawsze `private, no-store`.
- Nowy publiczny endpoint katalogu = tagi w odpowiedzi + unieważnianie w konsumencie zdarzeń. Bez tego
  zmiana danych nie będzie widoczna w sklepie.

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
