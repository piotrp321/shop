# Walking skeleton – plan implementacji

> **Dla agentów:** wymagany skill `superpowers:subagent-driven-development`. Stan prac jest wyłącznie w sekcji
> „Status”. Czytaj zadanie, które realizujesz (skrypt `task-brief` ze skilla), a nie cały plan.

**Cel:** po `make up` i `make seed` działa cały stos: katalog ze zdjęciami przechodzi drogę
Postgres → outbox → RabbitMQ → Elasticsearch → API → sklep, a klient i pracownik logują się przez Keycloak.

**Architektura:** Docker Compose z health checkami; API w Symfony (moduły `Catalog`, `Customer`, `Backoffice`,
`Shared`, granice pilnowane przez Deptrac); sklep Next.js jako BFF z sesją w Redisie; panel Vue jako SPA
z klientem publicznym; kontrakt w dwóch plikach OpenAPI i dwóch wygenerowanych klientach TS.

**Stos:** PHP 8.5, Symfony 8, FrankenPHP, Doctrine, PostgreSQL 18, Redis 8, Elasticsearch 9, RabbitMQ 4,
Keycloak 26, Garage 2, imgproxy 3, Mailpit, Node 24, Next.js 16, Tailwind CSS 4, Vue 3, PrimeVue 4, Playwright.

**Specyfikacja:** `docs/features/walking-skeleton/spec.md`. Plan wynika ze specyfikacji, więc wykonawca czyta oba
dokumenty. Wartości (porty, nazwy, limity, kształty odpowiedzi) są w specyfikacji i nie są tu powtarzane.

## Status

Aktualnie: domena katalogu. Następne zadanie: **Task 9**. Blokery: brak (Task 9–11 mają punkt zatrzymania
na decyzje DDD, opisany niżej).

| PR | Zadania | Zakres | Status |
|---|---|---|---|
| [#1](https://github.com/piotrp321/shop/pull/1) | 1–8 | dokumentacja API, stos usług, obraz API z SPX, narzędzia jakości API, szkielety sklepu i panelu, konfiguracja Claude Code, wspólne zachowanie HTTP w API | ✅ |
| [#2](https://github.com/piotrp321/shop/pull/2), [#3](https://github.com/piotrp321/shop/pull/3) | 25 | lekkie CI | ✅ |
| | 9, 10 | domena Catalog i komendy tworzenia kategorii i produktów | następne |
| | 11 | persystencja Catalog (Doctrine, PostgreSQL) | |
| | 13 | indeks Elasticsearch i adapter wyszukiwania | |
| | 14 | transactional outbox, RabbitMQ, worker, indeksowanie | |
| | 12 | obrazy w API (Garage, imgproxy) | |
| | 15 | endpointy katalogu i OpenAPI sklepu | |
| | 16, 17 | dane demo i seed | |
| | 18 | strona katalogu w sklepie | |
| | 19, 20 | realmy Keycloak i uwierzytelnianie w API | |
| | 21 | dashboard w API | |
| | 22 | logowanie w sklepie | |
| | 23 | panel: logowanie, layout, dashboard | |
| | 24, 26 | testy E2E i odbiór | |

- Wiersz to jeden PR z jedną logiczną zmianą, a kolejność wierszy to kolejność prac (wynika z zależności
  „Konsumuje” w zadaniach, nie z numeracji). Task 12 jest przed Task 15, bo endpoint zwraca podpisane warianty
  zdjęć, a seed zapisuje zdjęcia w Garage.
- Orkiestrator oznacza wiersz jako ✅ w PR, który go realizuje, a numer PR dopisuje po jego otwarciu, w tym
  samym PR. To jedyne miejsce ze stanem prac: ledger SDD jest pamięcią roboczą jednego worktree, a lista zadań
  w aplikacji tylko widokiem.

Wznowienie pracy w nowej sesji:

1. Przeczytaj „Status” i „Ograniczenia globalne”.
2. Sprawdź pracę w toku: `gh pr list` i `git log --oneline origin/main..HEAD`.
3. W tym samym worktree, w którym zaczęto wiersz, ledger SDD (`.superpowers/sdd/plan/progress.md`) pokazuje
   zadania już zrobione w tym wierszu.

## Ograniczenia globalne

- Wszystko w Dockerze. Nigdy `php`, `composer`, `npm`, `npx` ani `node` na hoście. Komendy przez Makefile
  (`make -C apps/<app> <cel>`), które wołają `$(RUN) <usługa> …` z `RUN ?= docker compose exec`.
- Dokumentacja i komunikaty UI po polsku. Kod, komentarze i commity po angielsku.
- Jedna logiczna zmiana na commit, Conventional Commits, stopka `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Zależności wyłącznie z sekcji „Zależności” specyfikacji. Każda inna wymaga pytania.
- Nie wyłączamy reguł PHPStana, Deptraca ani ESLinta i nie edytujemy `deptrac.yaml`, żeby `check` przeszedł.
- `Domain` to czysty PHP. Mapowanie Doctrine w XML w `Infrastructure`.
- Wersje główne jak w „Stosie”. Dokładne wersje przypięte w Dockerfile, compose i lockfile'ach.
- Sekrety wyłącznie w `.env.local`. Plik compose nie podstawia sekretów przez `${…}`.
- Po zmianie endpointu lub DTO: `make -C apps/api openapi` i `make api-client` w tym samym commicie.
- Rozmiary obrazów tylko w `ImageSize`. Kolory w sklepie tylko z tokenów (zmienne motywu shadcn/ui
  w `src/styles/tokens.css`).
- Pracując w `apps/store`, przestrzegaj `apps/store/CLAUDE.md`, w tym zasad przenoszenia kodu z vercel/shop.
- Zadanie jest skończone, gdy przechodzą `check` i testy dotkniętych aplikacji. W raporcie pokaż wynik komend.
- Testy na usługach stosu trafiają do CI w tym samym PR co kod, razem z uruchomieniem usług, których potrzebują.
- Zadania ustawiające reguły (lint, Deptrac, CORS i podobne) sprawdzają też próby obejścia (sondy negatywne),
  nie tylko przypadek podstawowy.
- Po `composer require` z Fleksem przejrzyj zmiany przepisów i usuń zbędne (sekrety w `.env`, `AGENTS.md`,
  nieużywaną konfigurację).

## Punkty zatrzymania

- **Task 9–11:** zanim implementer zacznie, przedstaw użytkownikowi istotne decyzje DDD i persystencji z zadania
  (agregaty, value objecty, granice modułów, mapowanie i schemat bazy) i poczekaj na akceptację.
- **Task 16:** drzewo kategorii, szablony produktów i lista zdjęć z linkami czekają na akceptację,
  zanim cokolwiek zostanie pobrane.
- **Task 26:** plan zostaje usunięty dopiero po akceptacji całości przez użytkownika.

## Na co patrzeć w review

Przypadki, których specyfikacja nie stawia na pierwszym planie, a które najłatwiej zepsuć. Każdy ma test
w zadaniu, do którego należy kod.

1. Niedostępny Elasticsearch: `GET /api/v1/products` zwraca 503 jako problem details bez szczegółów
   technicznych, a sklep pokazuje stronę błędu zamiast pustej siatki (zadania 15 i 18).
2. Strona poza zakresem (`page=999`): 200, pusta lista i prawdziwe `total`, bez błędu (zadanie 15).
3. `make seed`, gdy `worker` nie działa: kod wyjścia różny od 0 i komunikat z obiema liczbami, bez zawieszenia
   i bez fałszywego sukcesu (zadanie 17).
4. Ciasteczko `store_session` żyje, a sesja w Redisie wygasła: `/auth/session` zwraca `null` i czyści ciasteczko,
   bez 500 (zadanie 22).
5. Dashboard przy częściowej awarii: niedostępne Management API RabbitMQ daje `queues: null`, niedostępny ES
   daje `indexedProducts: null` i `inSync: false`, niedostępny Postgres daje 503 (zadanie 21).

---

### Task 9: Domena Catalog

**Pliki:**
- Utwórz: `src/Shared/Domain/Event/DomainEvent.php`, `src/Shared/Domain/Event/DomainEventPublisher.php`,
  `src/Catalog/Domain/Category/{Category,CategoryId,CategoryName,CategoryPath,CategoryRepository,CategoryNotFound}.php`,
  `src/Catalog/Domain/Product/{Product,ProductId,ProductName,Slug,ShortDescription,TaxCategory,ProductImage,ProductImages,ImageKey,ProductRepository,ProductCreated,SlugAlreadyTaken}.php`,
  testy w `tests/Unit/Catalog/Domain/`

**Interfejsy:**
- Produkuje:
  - `CategoryNotFound` implementuje `Shared\Domain\Exception\NotFound` (404), `SlugAlreadyTaken` implementuje
    `Shared\Domain\Exception\Conflict` (409). Komunikaty bez danych wewnętrznych i bez wejścia użytkownika
    (stałe teksty, bez wartości sluga).
  - `DomainEvent { occurredAt(): DateTimeImmutable }`; `DomainEventPublisher { publish(DomainEvent ...$events): void }`
  - `Category::createRoot(CategoryId $id, CategoryName $name): self`,
    `Category::createChild(CategoryId $id, CategoryName $name, Category $parent): self`, `id()`, `name()`,
    `parentId(): ?CategoryId`, `path(): CategoryPath`
  - `CategoryPath::root(CategoryId $id): self`, `child(CategoryId $id): self`,
    `ancestorIds(): list<CategoryId>` (od korzenia, z własnym id), `toString(): string` (`/a/b/`),
    `fromString(string $path): self`
  - `CategoryRepository { save(Category $c): void; get(CategoryId $id): Category; all(): list<Category>; count(): int }`
  - `Product::create(ProductId, ProductName, Slug, ShortDescription, CategoryId, TaxCategory, ProductImages): self`,
    `releaseEvents(): list<DomainEvent>`, gettery pól, `images(): ProductImages`
  - `ProductImages::fromList(list<ProductImage>)`, `main(): ?ProductImage`, `isEmpty(): bool`, `toList()`
  - `ProductImage(ImageKey $key, string $alt, int $position)`; `ImageKey::fromString(string)` wymaga
    `^originals/[a-f0-9]{64}\.jpg$`
  - `TaxCategory` (enum string): `standard`, `food`, `food_supplement`, `printed_publication`
  - `ProductRepository { save(Product $p): void; get(ProductId $id): Product; all(): iterable<Product>; slugExists(Slug $s): bool; count(): int; countWithoutImages(): int }`
  - `ProductCreated(string $productId, DateTimeImmutable $occurredAt)` implementuje `DomainEvent`

- **Krok 1: Testy**
  - `CategoryTest`: korzeń ma ścieżkę `/<id>/` i `ancestorIds() == [id]`; dziecko wnuka ma ścieżkę
    `/<r>/<c>/<g>/` i przodków w kolejności od korzenia; pusta nazwa i nazwa o długości 101 rzucają
    `InvalidArgumentException`.
  - `ProductTest`: `create` zapisuje dokładnie jedno `ProductCreated` z tym samym `productId`; drugie
    `releaseEvents()` zwraca `[]`; nazwa pusta i o długości 201 są odrzucane; `ShortDescription` o długości 501
    jest odrzucany.
  - `SlugTest`: `buty-puls-2` jest poprawny; `Buty`, `-a`, `a-`, `a--b`, `a b` i slug o długości 201 są odrzucane.
  - `ProductImagesTest`: 11 zdjęć jest odrzucanych; pozycje `[0, 2]` są odrzucane; pusty `alt` jest odrzucany;
    `main()` zwraca pozycję 0; pusta lista daje `isEmpty() === true` i `main() === null`.
  - `ImageKeyTest`: poprawny klucz przechodzi; `originals/abc.jpg` i `../x.jpg` są odrzucane.
  - `TaxCategoryTest`: `TaxCategory::from('food')` działa, `from('luxury')` rzuca `ValueError`.
- **Krok 2: Uruchom `make -C apps/api test-unit`, oczekiwany FAIL.**
- **Krok 3: Zaimplementuj model.**
- **Krok 4: Uruchom `make -C apps/api check test-unit`, oczekiwany PASS.**
- **Krok 5: Commit** `feat(api): add catalog domain model`

### Task 10: Komendy Catalog

**Pliki:**
- Utwórz: `src/Catalog/Application/Command/{CreateCategory,CreateCategoryHandler,CreateProduct,CreateProductHandler}.php`,
  `tests/Support/InMemory/{InMemoryCategoryRepository,InMemoryProductRepository,RecordingDomainEventPublisher}.php`,
  `tests/Unit/Catalog/Application/{CreateCategoryHandlerTest,CreateProductHandlerTest}.php`

**Interfejsy:**
- Konsumuje: zadanie 9.
- Produkuje: `CreateCategory(string $id, string $name, ?string $parentId)`;
  `CreateProduct(string $id, string $name, string $slug, string $shortDescription, string $categoryId, string $taxCategory, list<array{key: string, alt: string}> $images)`.
  Handlery jako `#[AsMessageHandler(bus: 'command.bus')]`. `CreateProductHandler` publikuje zdarzenia agregatu
  przez `DomainEventPublisher` po `save`.

- **Krok 1: Testy**
  - `CreateCategoryHandlerTest`: korzeń zapisany; dziecko ma ścieżkę rodzica plus własne id; nieznany
    `parentId` rzuca `CategoryNotFound`.
  - `CreateProductHandlerTest`: produkt zapisany, opublikowane dokładnie jedno `ProductCreated`; nieznana
    kategoria rzuca `CategoryNotFound`; zajęty slug rzuca `SlugAlreadyTaken` i nic nie jest publikowane.
- **Krok 2: FAIL. Krok 3: implementacja. Krok 4: PASS (`make -C apps/api check test-unit`).**
- **Krok 5: Commit** `feat(api): add commands for creating categories and products`

### Task 11: Persystencja Catalog

**Pliki:**
- Utwórz: `config/packages/doctrine.yaml` (mapowanie XML z `src/*/Infrastructure/Persistence/Doctrine/mapping`),
  `src/Catalog/Infrastructure/Persistence/Doctrine/mapping/{Category,Product}.orm.xml`,
  `src/Catalog/Infrastructure/Persistence/Doctrine/Type/{CategoryIdType,ProductIdType,SlugType,CategoryPathType,ProductImagesType}.php`
  (`ProductImagesType` w JSONB), `src/Catalog/Infrastructure/Persistence/Doctrine/{DoctrineCategoryRepository,DoctrineProductRepository}.php`,
  `migrations/Version…CreateCatalog.php`, `tests/Factory/{CategoryFactory,ProductFactory}.php`,
  `tests/Integration/Catalog/Persistence/{DoctrineCategoryRepositoryTest,DoctrineProductRepositoryTest}.php`
- Modyfikuj: `config/packages/test/dama_doctrine_test_bundle.yaml`, `phpunit.dist.xml` (rozszerzenie dama),
  `phpstan.dist.neon` (`objectManagerLoader` rozszerzenia doctrine),
  `.github/workflows/ci.yml` (usługa `postgres` przed testami)

**Interfejsy:**
- Konsumuje: interfejsy repozytoriów z zadania 9.
- Produkuje: tabele `catalog_category` (`id`, `parent_id` z FK, `name`, `path` text z indeksem
  `text_pattern_ops`) i `catalog_product` (`id`, `slug` unikalny, `name`, `short_description`, `category_id`
  z FK, `tax_category`, `images` JSONB); fabryki Foundry `CategoryFactory` (z opcją rodzica) i `ProductFactory`.

- **Krok 1: Testy**
  - `DoctrineCategoryRepositoryTest`: zapis i odczyt zachowuje ścieżkę i rodzica; `get` nieznanego id rzuca
    `CategoryNotFound`; `count()`.
  - `DoctrineProductRepositoryTest`: zapis i odczyt zachowuje kolejność zdjęć i `TaxCategory`; `slugExists`;
    `count()`; `countWithoutImages()`; zapis drugiego produktu z tym samym slugiem rzuca `SlugAlreadyTaken`.
- **Krok 2: FAIL. Krok 3: mapowanie, typy, repozytoria, migracja (`make -C apps/api migration`).**
- **Krok 4: PASS**: `make -C apps/api check test`; `bin/console doctrine:schema:validate` w kontenerze
  przechodzi.
- **Krok 5: Commit** `feat(api): persist catalog with Doctrine XML mapping`

### Task 12: Obrazy – Garage i imgproxy

**Pliki:**
- Utwórz: `src/Catalog/Application/Image/{ImageSize,ImageFormat}.php`,
  `src/Catalog/Application/Port/{ImageUrlGenerator,ProductImageStorage}.php`,
  `src/Catalog/Infrastructure/Image/{ImgproxyUrlGenerator,FlysystemProductImageStorage,InvalidImage}.php`,
  `config/packages/flysystem.yaml`, `tests/Unit/Catalog/Image/{ImageSizeTest,ImgproxyUrlGeneratorTest}.php`,
  `tests/Integration/Catalog/Image/{FlysystemProductImageStorageTest,ImgproxySigningTest}.php`,
  `tests/Fixtures/images/sample.jpg` (mały JPEG), `tests/Fixtures/images/sample.png`
- Modyfikuj: `infra/docker-compose.yml` (env `api`: `IMGPROXY_PUBLIC_URL`, `IMAGES_BUCKET`, endpoint Garage),
  `.github/workflows/ci.yml` (usługi `garage`, `garage-init` i `imgproxy` przed testami)

**Interfejsy:**
- Produkuje:
  - `ImageSize::Listing` z `aspectRatio(): string` (`'1:1'`), `widths(): list<int>` (`[256, 384, 512, 768]`),
    `formats(): list<ImageFormat>` (`[Avif, Webp]`), `resize(): string` (`'fill'`), `gravity(): string` (`'sm'`)
  - `ImageFormat` (enum string): `avif`, `webp`
  - `ImageUrlGenerator { url(string $imageKey, ImageSize $size, int $width, ImageFormat $format): string }`;
    szerokość spoza `widths()` rzuca `InvalidArgumentException`
  - `ProductImageStorage { store(string $contents): string /* ImageKey */; purge(): void }`; treść inna niż
    JPEG (sprawdzana po zawartości) rzuca `InvalidImage`
- Ścieżka imgproxy: `/{signature}/rs:fill:{w}:{h}/g:sm/{base64url("s3://{bucket}/{key}")}.{format}`, gdzie
  podpis to base64url z HMAC-SHA256(klucz, sól + ścieżka), zgodnie z dokumentacją imgproxy.

- **Krok 1: Testy**
  - `ImageSizeTest`: wartości `Listing` jak wyżej.
  - `ImgproxyUrlGeneratorTest`: wektor testowy podpisu z dokumentacji imgproxy (rozdział „Signing a URL”) daje
    udokumentowany podpis; dla `Listing`, 384 i AVIF adres zawiera `rs:fill:384:384/g:sm/` i kończy się `.avif`;
    ten sam klucz w WebP daje inny adres; szerokość 300 rzuca wyjątek; adres zaczyna się od `IMGPROXY_PUBLIC_URL`.
  - `FlysystemProductImageStorageTest` (bucket `product-images-test`): `store(sample.jpg)` zwraca
    `originals/<sha256>.jpg` i plik istnieje; dwa zapisy tej samej treści dają ten sam klucz; PNG rzuca `InvalidImage`.
  - `ImgproxySigningTest`: dla zapisanego zdjęcia adresy AVIF i WebP (z bazą podmienioną na `http://imgproxy:8080`)
    zwracają 200 z `Content-Type` odpowiednio `image/avif` i `image/webp`; adres ze zmienionym znakiem podpisu
    zwraca 403.
- **Krok 2: FAIL. Krok 3: implementacja. Krok 4: PASS (`make -C apps/api check test`).**
- **Krok 5: Commit** `feat(api): store product originals in Garage and sign imgproxy URLs`

### Task 13: Indeks Elasticsearch

**Pliki:**
- Utwórz: `src/Catalog/Application/Search/{ProductDocument,SearchResult,ProductDocumentBuilder,SearchUnavailable}.php`,
  `src/Catalog/Application/Port/{ProductIndexer,ProductSearch,SearchIndexMaintenance}.php`,
  `src/Catalog/Infrastructure/Search/{ElasticsearchClientFactory,ProductIndexDefinition,ElasticsearchProductIndexer,ElasticsearchProductSearch,ElasticsearchSearchIndexMaintenance}.php`,
  `src/Catalog/UI/Console/{SetupSearchIndexCommand,ReindexCommand}.php`,
  `tests/Integration/Catalog/Search/{ProductSearchTest,SearchIndexMaintenanceTest}.php`,
  `tests/Unit/Catalog/Search/ProductDocumentBuilderTest.php`
- Modyfikuj: `apps/api/docker/entrypoint.sh` (wywołanie `catalog:search-index:setup`),
  `.github/workflows/ci.yml` (usługa `elasticsearch` przed testami)

**Interfejsy:**
- Produkuje:
  - `ProductDocument(string $id, string $name, string $slug, string $shortDescription, string $categoryId, list<string> $categoryAncestorIds, ?array{key: string, alt: string} $mainImage)`
  - `ProductDocumentBuilder::build(Product $product, Category $category): ProductDocument`
  - `ProductIndexer { index(ProductDocument $d): void; countDocuments(): int }`
  - `ProductSearch { search(?string $categoryId, int $page, int $perPage): SearchResult }`;
    `SearchResult(list<ProductDocument> $items, int $total)`
  - `SearchIndexMaintenance { setup(): void; rebuild(iterable<ProductDocument> $documents): int; reset(): void }`
  - każda metoda adaptera rzuca `SearchUnavailable`, gdy ES nie odpowiada (limit połączenia 1 s)
  - nazwa aliasu z parametru (`products` w dev, `test_products` w testach)

- **Krok 1: Testy**
  - `ProductDocumentBuilderTest`: `categoryAncestorIds` to `ancestorIds()` kategorii jako stringi; `mainImage`
    jest `null` bez zdjęć.
  - `ProductSearchTest` (drzewo R > C > L, produkty w L, w C i w innej gałęzi): `search(R)` daje 2, `search(L)`
    daje 1, `search(null)` daje 3; nazwy `Żel`, `buty`, `Ajax` sortują się jako `Ajax`, `buty`, `Żel`;
    `perPage=2, page=2` daje 1 element i `total=3`; `page=99` daje `[]` i `total=3`; dwukrotne `index` tego samego
    dokumentu daje `countDocuments() === 1`; klient z hostem nieosiągalnym rzuca `SearchUnavailable` w czasie
    poniżej 2 s.
  - `SearchIndexMaintenanceTest`: `setup()` dwa razy bez błędu; po `rebuild()` alias wskazuje nowy indeks,
    stary nie istnieje, dokumenty są dostępne.
- **Krok 2: FAIL. Krok 3: implementacja i komendy konsoli. Krok 4: PASS (`make -C apps/api check test`).**
- **Krok 5: Commit** `feat(api): add Elasticsearch read model for products`

### Task 14: Outbox, Messenger i indeksowanie

**Pliki:**
- Utwórz: `config/packages/messenger.yaml`, `config/packages/test/messenger.yaml` (transporty `in-memory://`),
  `config/packages/test_messaging/messenger.yaml` i `.env.test_messaging` (środowisko kernela z prawdziwymi
  transportami: `outbox` Doctrine w `shop_test`, `events` AMQP na vhoście `test`, `failed` Doctrine),
  `src/Shared/Infrastructure/Messaging/{MessengerDomainEventPublisher,RelayDomainEventToBroker}.php`,
  `src/Catalog/Application/EventHandler/IndexProduct.php`, `migrations/Version…CreateMessengerTables.php`,
  `tests/Unit/Shared/Messaging/MessengerRoutingTest.php`,
  `tests/Integration/Shared/Messaging/{OutboxAtomicityTest,EventFlowTest}.php`
- Modyfikuj: `infra/docker-compose.yml` (usługa `worker`: `bin/console messenger:consume outbox events`,
  health check procesu, `restart: unless-stopped`), `config/bundles.php` (bundle testowe także w `test_messaging`),
  `.github/workflows/ci.yml` (usługa `rabbitmq` i jednorazowy `rabbitmq-init` przed testami),
  `tests/Support/TestMessengerController.php` (jawna szyna zamiast domyślnej, gdy powstaną `command.bus`,
  `query.bus` i `event.bus`), `symfony.lock` (wpis `config/packages/messenger.yaml` odpowiada wtedy istniejącemu plikowi)

**Interfejsy:**
- Konsumuje: `DomainEventPublisher` (zadanie 9), `ProductIndexer` i `ProductDocumentBuilder` (zadanie 13).
- Produkuje: szyny i transporty ze specyfikacji; routing `DomainEvent` → `outbox`;
  `RelayDomainEventToBroker` z `#[AsMessageHandler(bus: 'event.bus', fromTransport: 'outbox')]`, który ponownie
  wysyła zdarzenie z `TransportNamesStamp(['events'])`; `IndexProduct` z
  `#[AsMessageHandler(bus: 'event.bus', fromTransport: 'events')]`; ponowienia 3 × (1 s, mnożnik 3), transport
  `failed`.

- **Krok 1: Testy**
  - `MessengerRoutingTest` (kernel testowy, transporty in-memory, `ProductIndexer` podmieniony na szpiega,
    repozytoria in-memory): publikacja `ProductCreated` daje 1 wiadomość na `outbox` i 0 na `events`; po
    konsumpcji `outbox` jest 1 na `events`, a szpieg nie został wywołany; po konsumpcji `events` szpieg
    dostał dokładnie jedno wywołanie z tym `productId`, a `outbox` i `events` są puste.
  - `OutboxAtomicityTest` (kernel `test_messaging`): w `wrapInTransaction` zapis produktu, publikacja zdarzenia
    i wyjątek dają 0 produktów i 0 wierszy `outbox` (liczone w tym samym połączeniu); bez wyjątku jest 1 produkt
    i 1 wiersz.
  - `EventFlowTest` (kernel `test_messaging`, jawnie prawdziwe transporty: `outbox` Doctrine i `events` AMQP na
    vhoście `test`, ES na żywo): `CreateProduct` na `command.bus`, potem
    `messenger:consume outbox events --limit=2 --time-limit=10`, a dokument produktu jest w indeksie testowym.
    Przed testem kolejka `events` na vhoście `test` jest czyszczona.
- **Krok 2: FAIL. Krok 3: konfiguracja, przekaźnik, handler, migracja. Krok 4: PASS (`make -C apps/api check test`).**
- **Krok 5: Sprawdź**: `worker` jest `healthy`, a `docker compose logs worker` nie pokazuje błędów. Przy
  działającym `worker` uruchom testy integracyjne: logi `worker` nie pokazują żadnej obsłużonej wiadomości, a
  `rabbitmqctl list_queues -p /` nie zmienia się w trakcie testów.
- **Krok 6: Commit** `feat(api): publish domain events through transactional outbox and index products`

### Task 15: Endpointy katalogu i OpenAPI sklepu

**Pliki:**
- Utwórz: `src/Catalog/Application/Query/{ListProducts,ListProductsHandler,ProductPage,ProductListItem,ImageView,GetCategoryTree,GetCategoryTreeHandler,CategoryNode}.php`,
  `src/Catalog/UI/Http/{ListProductsController,ListProductsQuery,CategoryTreeController}.php`,
  `config/packages/nelmio_api_doc.yaml` (obszary `default` i `admin`),
  `tests/Unit/Catalog/Application/{ListProductsHandlerTest,GetCategoryTreeHandlerTest}.php`,
  `tests/Functional/Catalog/{ProductsEndpointTest,CategoriesEndpointTest}.php`, `apps/api/openapi.json`,
  `apps/api/openapi-admin.json`
- Modyfikuj: `apps/api/Makefile` (`openapi`: `nelmio:apidoc:dump --area=default` i `--area=admin`),
  `.github/workflows/ci.yml` (krok aktualności kontraktu)

**Interfejsy:**
- Konsumuje: `ProductSearch` (zadanie 13), `ImageUrlGenerator` i `ImageSize` (zadanie 12), `CategoryRepository`.
- Produkuje: `ListProducts(?string $categoryId, int $page, int $perPage)` → `ProductPage(list<ProductListItem> $items, int $page, int $perPage, int $total)`;
  `ImageView(string $alt, string $size, string $aspectRatio, array<string, list<array{width: int, url: string}>> $variants)`;
  `GetCategoryTree` → `list<CategoryNode(string $id, string $name, list<CategoryNode> $children)>`; schematy
  OpenAPI `ProductPage`, `ProductListItem`, `ProductImage`, `CategoryNode`, `Problem`. `SearchUnavailable`
  mapowany na 503.

- **Krok 1: Testy**
  - `ListProductsHandlerTest`: produkt bez zdjęcia ma `image === null`; ze zdjęciem: `size === 'listing'`,
    `aspectRatio === '1:1'`, klucze `variants` to `avif` i `webp`, każdy z szerokościami `[256, 384, 512, 768]`
    rosnąco.
  - `GetCategoryTreeHandlerTest`: drzewo zagnieżdżone; rodzeństwo posortowane po nazwie z polską kolacją
    (`Collator('pl_PL')`: `Łyżwy` po `Lody`).
  - `ProductsEndpointTest` (Foundry, dokumenty w indeksie testowym): kształt odpowiedzi jak w specyfikacji; filtr
    po kategorii głównej obejmuje produkty z podkategorii; `page=999` daje 200, `items === []` i prawdziwe `total`;
    `category=abc` daje 400 z `violations[0].propertyPath === 'category'`; `perPage=0` i `perPage=49` dają 400;
    nagłówki `Cache-Control: max-age=60, public` i `Vary` zawierające `Origin`; `ProductSearch` podmieniony na
    rzucający `SearchUnavailable` daje 503 jako problem details, a treść nie zawiera komunikatu wyjątku.
  - `CategoriesEndpointTest`: zagnieżdżone drzewo; nagłówki cache jak wyżej.
  - Test end-to-end przez prawdziwą szynę synchroniczną: handler rzucający wyjątek projektu (`NotFound`) daje
    odpowiedź ze statusem 404 i `detail` równym komunikatowi (a nie 500, mimo opakowania w `HandlerFailedException`);
    handler rzucający zwykły `RuntimeException` daje 500 bez `detail`.
- **Krok 2: FAIL. Krok 3: zapytania, kontrolery, DTO z ograniczeniami Validatora, atrybuty OpenAPI.**
- **Krok 4: PASS (`make -C apps/api check test`).**
- **Krok 5: Eksport**: `make -C apps/api openapi`; `openapi.json` zawiera `/api/v1/products` i
  `/api/v1/categories` z odpowiedziami 200, 400 i 503, a nie zawiera `/health` ani `/api/v1/admin`.
- **Krok 6: Commit** `feat(api): add product list and category tree endpoints`
- **Krok 7: Aktualność kontraktu w CI**: w `.github/workflows/ci.yml` po kroku testów krok
  „Contract freshness”: `make -C apps/api openapi RUN="$CI_RUN"`, potem
  `git diff --exit-code -- apps/api/openapi.json apps/api/openapi-admin.json`. Ten sam krok sprawdza później klientów
  TS: zadania 18 i 23, które tworzą `make api-client`, dopisują do niego generowanie i swój `schema.d.ts`.
  Sprawdź lokalnie z `CI_RUN="docker compose run --rm --no-deps -T"` (bez Postgresa, Elasticsearch i RabbitMQ):
  na czystym drzewie krok przechodzi; zmiana opisu endpointu w atrybucie OpenAPI bez eksportu daje niezerowy
  kod wyjścia (sonda negatywna). Commit `ci: check that exported OpenAPI files are up to date`.

### Task 16: Dane demo – propozycja, akceptacja, zdjęcia (punkt zatrzymania)

**Pliki:**
- Utwórz: `apps/api/fixtures/catalog.yaml`, `apps/api/fixtures/images/*.jpg`, `apps/api/fixtures/images/CREDITS.md`

**Interfejsy:**
- Produkuje format `catalog.yaml`:
  ```yaml
  namespace: <uuid>          # UUID v5 namespace for seed ids
  imagelessEvery: 50         # every N-th expanded product gets no images
  categories:                # tree, 3–4 levels
    - { name: Bieganie, slug: bieganie, children: [ … ] }
  templates:
    - category: bieganie/buty/szosowe     # slug path
      productType: Buty do biegania
      taxCategory: standard
      brands: [ … ]                        # fictional house brands
      models: [ { name: …, description: … } ]
      colors: [ … ]
      images: [ { file: …, alt: … } ]      # pool for this template
  ```
  Nazwa produktu to `{productType} {brand} {model} {color}`, a slug to transliteracja nazwy (bez polskich znaków).

- **Krok 1: Przygotuj szkic `catalog.yaml`**: 7 kategorii głównych ze specyfikacji, drzewo na 3–4 poziomy
  (około 35 kategorii), szablony dla kategorii-liści. Szablony z `food`, `food_supplement` (np. odżywki)
  i `printed_publication` (przewodniki z ISBN, mapy) są wśród nich. Marki fikcyjne, bez nazw istniejących marek
  sportowych. Policz, że rozwinięcie daje 300–500 produktów.
- **Krok 2: Przygotuj listę 20–30 zdjęć**: tabela z kolumnami plik, pula (szablony), adres strony zdjęcia na
  Unsplash lub Pexels, autor, licencja (Unsplash License albo Pexels License), uzasadnienie (zdjęcie „w terenie”,
  tonacja). Zdjęcia spójne tonacyjnie: ludzie w górach, na szlaku, na rowerze.
- **Krok 3: Punkt zatrzymania**: przedstaw użytkownikowi drzewo, szablony (z liczbą produktów) i listę zdjęć.
  Pobieraj dopiero po akceptacji. Akceptacja listy jest zgodą na pobranie wymienionych plików.
- **Krok 4: Pobierz i przeskaluj**: pobierz do katalogu tymczasowego w scratchpadzie, przeskaluj na hoście
  przez `sips -Z 1600 -s format jpeg -s formatOptions 82` i skopiuj do `fixtures/images/`. Uzupełnij `CREDITS.md`.
- **Krok 5: Sprawdź**: liczba plików mieści się w 20–30; każdy plik jest w `CREDITS.md` i w co najmniej jednej
  puli; dłuższy bok ma 1600 px (`sips -g pixelWidth -g pixelHeight`); każdy plik ma najwyżej 600 KB.
- **Krok 6: Commit** `chore(api): add demo catalog definition and photos`

### Task 17: Seed danych demo

**Pliki:**
- Utwórz: `src/Catalog/Infrastructure/Demo/{DemoCatalogDefinition,DemoCatalogExpander,DemoCatalog,DemoCategory,DemoProduct,DemoCatalogLoader,CatalogReset}.php`,
  `src/Catalog/UI/Console/SeedDemoCatalogCommand.php`, `tests/Fixtures/demo/catalog.yaml` (mały),
  `tests/Unit/Catalog/Demo/{DemoCatalogExpanderTest,RealDemoCatalogTest,SeedWaitTest,SeedProdGuardTest}.php`
- Modyfikuj: `Makefile` (`seed`: `$(RUN) api bin/console catalog:seed-demo`)

**Interfejsy:**
- Konsumuje: `CreateCategory` i `CreateProduct` (zadanie 10), `ProductImageStorage` (zadanie 12),
  `SearchIndexMaintenance` i `ProductIndexer` (zadanie 13).
- Produkuje:
  - `DemoCatalogExpander::expand(DemoCatalogDefinition $d): DemoCatalog` z kategoriami w kolejności „rodzic przed
    dzieckiem” i produktami w kolejności rozwijania (szablony w kolejności z pliku, potem marki, modele, kolory)
  - id kategorii: `Uuid::v5($namespace, 'category:' . $slugPath)`; id produktu: `Uuid::v5($namespace, 'product:' . $slug)`
  - `CatalogReset::reset(): void`: czyści tabele katalogu, wiersze transportu `outbox`, bucket i indeks
  - `catalog:seed-demo [--timeout=60] [--force-reset]`: reset, kategorie, zdjęcia (każdy plik zapisany raz),
    produkty przez `command.bus`, oczekiwanie na indeks, podsumowanie `categories: N, products: M, indexed: M`;
    przy `kernel.environment === 'prod'` bez `--force-reset` kończy się kodem 1 przed jakąkolwiek zmianą danych

- **Krok 1: Testy**
  - `DemoCatalogExpanderTest` (mały fixture): dwa rozwinięcia są identyczne; id produktu równa się
    `Uuid::v5(ns, 'product:' . slug)`; zduplikowany slug rzuca wyjątek z tym slugiem; co `imagelessEvery`-ty
    produkt nie ma zdjęć, a pozostałe dostają zdjęcie z puli `pool[index % count]`; rodzic występuje przed dzieckiem.
  - `RealDemoCatalogTest` (`apps/api/fixtures/catalog.yaml`): liczba produktów w przedziale 300–500; głębokość
    drzewa 3–4; każdy plik z puli istnieje w `fixtures/images`; co najmniej jeden produkt z każdej kategorii
    podatkowej `food`, `food_supplement` i `printed_publication`.
  - `SeedWaitTest`: licznik indeksu, który nie dogania Postgresa, przy `--timeout=0.2` daje kod wyjścia 1
    i komunikat z obiema liczbami.
  - `SeedProdGuardTest` (komenda z `kernel.environment = 'prod'`, `CatalogReset` jako szpieg): bez
    `--force-reset` kod wyjścia 1, komunikat wskazujący flagę, `reset()` nie został wywołany; z `--force-reset`
    `reset()` został wywołany.
- **Krok 2: FAIL. Krok 3: implementacja. Krok 4: PASS (`make -C apps/api check test`).**
- **Krok 5: Sprawdź na stosie**
  - `make seed` kończy się kodem 0 i podsumowaniem z równymi liczbami produktów i dokumentów.
  - Drugi `make seed`: te same liczby, a `id` pierwszego produktu z `GET /api/v1/products?perPage=1` jest takie
    samo jak przed nim.
  - `docker compose stop worker && make seed`: kod różny od 0 po około 60 s, komunikat z obiema liczbami.
    Potem `docker compose start worker` i ponowny `make seed`.
- **Krok 6: Commit** `feat(api): add deterministic demo catalog seed`

### Task 18: Strona katalogu w sklepie

**Pliki:**
- Utwórz: `apps/store/src/api/{client.ts,schema.d.ts}`, `apps/store/src/catalog/{load-catalog.ts,picture.ts,ProductPicture.tsx,ProductTile.tsx,ProductGrid.tsx,CategoryTree.tsx,Pagination.tsx}`,
  `apps/store/src/images/forbidden-loader.ts`, `apps/store/app/error.tsx`, testy obok plików (`*.test.ts(x)`)
- Modyfikuj: `apps/store/app/page.tsx`, `apps/store/next.config.ts` (`images.loader: 'custom'`,
  `loaderFile: './src/images/forbidden-loader.ts'`), `infra/docker-compose.yml` (montowanie
  `apps/api/openapi.json` tylko do odczytu w `/contract/openapi.json`, `API_INTERNAL_URL`), `Makefile`
  (`api-client`: generowanie dla sklepu, komendy przez `$(RUN)` jak w Makefile aplikacji),
  `.github/workflows/ci.yml` (krok „Contract freshness”: `make api-client RUN="$CI_RUN"`
  i `apps/store/src/api/schema.d.ts` w `git diff --exit-code`)

**Interfejsy:**
- Konsumuje: `openapi.json` (zadanie 15).
- Produkuje: `createApiClient(options: { baseUrl: string; accessToken?: string }): Client<paths>`;
  `buildPicture(image: components['schemas']['ProductImage'], sizes: string): { avifSrcSet: string; webpSrcSet: string; img: { src: string; srcSet: string; sizes: string; alt: string; width: number; height: number } }`;
  `loadCatalog(params: { category?: string; page?: string }): Promise<{ kind: 'ok'; categories; products } | { kind: 'invalid-filter' }>`
  (inne błędy rzuca dalej, więc obsługuje je `error.tsx`).

- **Krok 1: Wygeneruj klienta**: `make api-client` tworzy `src/api/schema.d.ts`.
- **Krok 2: Testy**
  - `picture.test.ts`: `avifSrcSet === 'u256 256w, u384 384w, u512 512w, u768 768w'` (analogicznie WebP);
    `img.src` to wariant WebP 768; `sizes` i `alt` przekazane bez zmian.
  - `CategoryTree.test.tsx`: aktywna kategoria ma `aria-current="page"`; jej przodkowie są rozwinięci, inna
    gałąź jest zwinięta; linki mają postać `?category=<id>`.
  - `ProductGrid.test.tsx`: jeden kafel na produkt; `image === null` daje placeholder z `role="img"`
    i `aria-label="Brak zdjęcia"`; pusta lista daje komunikat i link do `/`.
  - `forbidden-loader.test.ts`: wywołanie globalnego loadera rzuca błąd.
  - `load-catalog.test.ts`: 400 z API daje `{ kind: 'invalid-filter' }`; 503 i błąd sieci są rzucane dalej.
- **Krok 3: FAIL. Krok 4: implementacja strony (Server Component, równoległe pobieranie, siatka 2/3/4
  kolumny, `sizes="(min-width: 1024px) 25vw, (min-width: 640px) 33vw, 50vw"`). `ProductTile` na `Card`,
  `Pagination` na komponencie `pagination` z `src/components/ui`.**
- **Krok 5: PASS**: `make -C apps/store check test`; ponowne `make api-client` nie zmienia `git diff`.
  Krok „Contract freshness” z CI uruchomiony lokalnie przechodzi, a ręcznie zmieniony `schema.d.ts` daje
  niezerowy kod wyjścia.
- **Krok 6: Sprawdź w przeglądarce** (wbudowana przeglądarka, `localhost:3000`):
  - siatka ze zdjęciami, w zakładce sieci żądania `.avif` do imgproxy,
  - kliknięcie kategorii zawęża listę razem z podkategoriami, paginacja działa,
  - przy `docker compose stop elasticsearch` sklep pokazuje stronę błędu. Potem `docker compose start elasticsearch`.
- **Krok 7: Commit** `feat(store): show category tree and product grid from API`

### Task 19: Realmy Keycloak

**Pliki:**
- Utwórz: `infra/keycloak/realms/shop.json`, `infra/keycloak/realms/backoffice.json`
- Modyfikuj: `infra/docker-compose.yml` (keycloak: montowanie realmów, `KC_HOSTNAME`,
  `KC_HOSTNAME_BACKCHANNEL_DYNAMIC=true`, `KC_HEALTH_ENABLED=true`, `STORE_URL`, `ADMIN_URL`,
  `STORE_CLIENT_SECRET` z `.env.local`), `Makefile` (`keycloak-reset`)

**Interfejsy:**
- Produkuje: realmy, klienty, mappery audience, role i konta demo z tabel w specyfikacji. Poświadczenie TOTP konta
  pracownika w imporcie: `{"type": "otp", "secretData": "{\"value\":\"<sekret>\"}", "credentialData":
  "{\"subType\":\"totp\",\"digits\":6,\"counter\":0,\"period\":30,\"algorithm\":\"HmacSHA1\"}"}`. Sekrety to stałe
  ciągi ASCII o długości 20 znaków. Ich postać base32 (dla aplikacji uwierzytelniającej i E2E) to base32 bajtów UTF-8.

- **Krok 1: Napisz realmy** z placeholderami `${STORE_URL}`, `${ADMIN_URL}`, `${STORE_CLIENT_SECRET}`.
- **Krok 2: Sprawdź** (`make keycloak-reset`, potem `kcadm.sh` w kontenerze):
  - `curl -s localhost:8080/realms/shop/.well-known/openid-configuration | jq -r .issuer` daje
    `http://localhost:8080/realms/shop`. To samo zapytanie z kontenera `api` przez `http://keycloak:8080` daje ten sam
    `issuer`, a `token_endpoint` zaczyna się od `http://keycloak:8080`.
  - Realm `shop`: 2 użytkowników z `emailVerified: true`; `registrationAllowed`, `verifyEmail` i
    `resetPasswordAllowed` mają `true`; `smtpServer.host` to `mailpit`.
  - Realm `backoffice`: 4 użytkowników, każdy z dokładnie jedną rolą realmu i poświadczeniem typu `otp`;
    `browserFlow` to własny flow z OTP `REQUIRED`; `ssoSessionIdleTimeout = 900`, `ssoSessionMaxLifespan = 28800`.
  - `redirectUris` klientów zawierają podstawione adresy, bez `${`.
- **Krok 3: Commit** `feat(infra): add Keycloak realms for customers and staff`

### Task 20: Uwierzytelnianie w API

**Pliki:**
- Utwórz: `config/packages/security.yaml`, `config/packages/test/security.yaml` (`keyset: '%env(OIDC_TEST_JWKS)%'`),
  `src/Customer/Infrastructure/Security/{CustomerUser,CustomerUserProvider}.php`,
  `src/Customer/UI/Http/{MeController,MeResponse}.php`,
  `src/Backoffice/Infrastructure/Security/{StaffUser,StaffUserProvider}.php`,
  `src/Backoffice/UI/Http/{StaffMeController,StaffMeResponse}.php`,
  `src/Shared/Infrastructure/Security/{ProblemAuthenticationEntryPoint,ProblemAuthenticationFailureHandler}.php`,
  `tests/Support/Security/TestTokenFactory.php`,
  `tests/Functional/Customer/MeEndpointTest.php`, `tests/Functional/Backoffice/StaffMeEndpointTest.php`,
  `tests/Functional/Shared/InvalidTokenOnPublicEndpointTest.php`, `tests/Functional/Shared/SecurityProblemDetailsTest.php`, `tests/Unit/Backoffice/Security/StaffUserProviderTest.php`,
  `tests/Integration/Shared/Security/KeycloakDiscoveryTest.php`
- Modyfikuj: `src/Shared/Infrastructure/Http/ProblemDetailsSubscriber.php` (mapowanie wyjątków bezpieczeństwa),
  `tests/bootstrap.php` (para kluczy RSA w pamięci, `OIDC_TEST_JWKS`), `apps/api/openapi.json`,
  `apps/api/openapi-admin.json`, `.github/workflows/ci.yml` (usługa `keycloak` przed testami)

**Interfejsy:**
- Produkuje: `TestTokenFactory::customer(array $claims = []): string`,
  `TestTokenFactory::staff(list<string> $roles, array $claims = []): string`,
  `TestTokenFactory::forRealm(string $realm, array $claims): string` (domyślnie poprawne `iss`, `aud`, `sub`,
  `exp = now + 300`); `StaffUser` z `id()`, `username()`, `staffRoles(): list<string>`; `CustomerUser` z `id()`,
  `email()`, `name()`. `ProblemDetailsSubscriber` mapuje `AuthenticationException` na 401 z `WWW-Authenticate: Bearer`
  i `AccessDeniedException` na 403, oba jako problem details z ogólnym `detail` (nigdy komunikat wyjątku), także gdy
  wyjątek poleci poza listenerem firewalla.

- **Krok 1: Testy**
  - `MeEndpointTest`: brak tokena daje 401, problem details i `WWW-Authenticate: Bearer`; token pracownika
    (inny `iss`) daje 401; `aud: backoffice-api` daje 401; `exp` w przeszłości daje 401; `Bearer abc` daje 401;
    poprawny token daje 200 z `{id, email, name}` i `Cache-Control: no-store, private`.
  - `StaffMeEndpointTest`: ta sama macierz, w tym token klienta → 401; role
    `[admin, offline_access, uma_authorization]` dają `roles === ['admin']`.
  - `SecurityProblemDetailsTest` (trasy testowe w `tests/Support`): `AuthenticationException` rzucony z kontrolera
    daje 401 z `WWW-Authenticate: Bearer`, `AccessDeniedException` rzucony z kontrolera lub votera daje 403;
    oba jako `application/problem+json`, bez komunikatu wyjątku w treści i bez 500. Sondy obejścia: wyjątek
    bezpieczeństwa opakowany w zwykły wyjątek, rzucony poza firewallem, daje 500 bez `detail` (zasada „decyduje
    rzucony wyjątek”). W firewallu `ExceptionListener` bezpieczeństwa Symfony przegląda `previous` i nadal daje 401/403.
  - `InvalidTokenOnPublicEndpointTest`: `GET /api/v1/products` z `Bearer garbage` daje 401.
  - `StaffUserProviderTest`: role przycięte do czterech znanych, `ROLE_STAFF` zawsze obecne.
  - `KeycloakDiscoveryTest` (prawdziwy Keycloak): discovery z `http://keycloak:8080/realms/backoffice` zwraca
    `issuer` równy skonfigurowanemu publicznemu wystawcy.
- **Krok 2: FAIL. Krok 3: firewalle `admin` i `shop` (w tej kolejności), providery, punkty wejścia, kontrolery.**
- **Krok 4: PASS (`make -C apps/api check test`).**
- **Krok 5: Eksport**: `make -C apps/api openapi`; `openapi.json` ma `/api/v1/me` i schemat `shop`;
  `openapi-admin.json` ma `/api/v1/admin/me` i schemat `backoffice`; `make api-client`; `make check`
  przechodzi we wszystkich aplikacjach.
- **Krok 6: Commit** `feat(api): validate Keycloak tokens for customers and staff`

### Task 21: Dashboard w API

**Pliki:**
- Utwórz: `src/Catalog/Application/Query/{GetCatalogStatistics,GetCatalogStatisticsHandler,CatalogStatisticsView}.php`,
  `src/Backoffice/Application/Query/{GetDashboard,GetDashboardHandler,Dashboard,CatalogSection,SystemSection,ServiceStatus,QueueStatus,SearchIndexStatus}.php`,
  `src/Backoffice/Application/Port/{CatalogStatisticsProvider,SystemHealth,MessagingStatistics}.php`,
  `src/Backoffice/Infrastructure/{QueryBusCatalogStatisticsProvider,ProbingSystemHealth,MessagingStatisticsAdapter}.php`,
  `src/Backoffice/Infrastructure/Security/DashboardVoter.php`, `src/Backoffice/UI/Http/{DashboardController,DashboardResponse}.php`,
  `src/Shared/Infrastructure/Health/{ServiceProbe,ProbeResult}.php`,
  `src/Shared/Infrastructure/Health/Probe/{Postgres,Redis,Elasticsearch,RabbitMq,Keycloak,Garage,Imgproxy,Mailpit}Probe.php`,
  testy w `tests/Unit/Backoffice/`, `tests/Integration/Shared/Health/`, `tests/Integration/Backoffice/`,
  `tests/Functional/Backoffice/DashboardEndpointTest.php`
- Modyfikuj: `apps/api/openapi-admin.json`, `.github/workflows/ci.yml` (wszystkie usługi sondowane przez `ProbesTest`
  przed testami)

**Interfejsy:**
- Konsumuje: `ProductRepository`, `CategoryRepository`, `ProductIndexer::countDocuments()`, `TestTokenFactory`.
- Produkuje:
  - `GetCatalogStatistics` → `CatalogStatisticsView(int $productCount, int $categoryCount, int $productsWithoutImages, ?int $indexedProducts)`
    (`null`, gdy `SearchUnavailable`)
  - `ServiceProbe { name(): string; probe(): ProbeResult }`; `ProbeResult(bool $up, ?int $latencyMs)`; sondy HTTP
    działają równolegle (asynchroniczny HttpClient), limit 1 s na sondę
  - `MessagingStatistics { outboxPending(): int; queues(): ?list<QueueStatus> }` (`null`, gdy Management API
    nie odpowiada)
  - `GetDashboard(bool $includeSystem)` → `Dashboard(CatalogSection $catalog, ?SystemSection $system)`; o
    `includeSystem` decyduje kontroler przez `isGranted('VIEW_SYSTEM_STATUS')`
  - wyjątek połączenia DBAL w trakcie zapytania jest mapowany na 503

- **Krok 1: Testy**
  - `GetDashboardHandlerTest`: `includeSystem: false` daje `system === null` i nie woła `SystemHealth`; usługi
    zawsze w kolejności ze specyfikacji, niezależnie od kolejności sond; `queues() === null` daje `queues === null`;
    `indexedProducts === null` daje `inSync === false`; równe liczby dają `inSync === true`.
  - `DashboardVoterTest`: `admin` dostaje zgodę; `support`, `warehouse` i `marketing` odmowę; `CustomerUser` odmowę.
  - `ProbesTest` (stos): każda sonda daje `up` przy działającej usłudze; sonda z nieosiągalnym hostem daje
    `up === false` i `latencyMs === null` w czasie poniżej 1,5 s.
  - `MessagingStatisticsAdapterTest` (stos, kernel `test_messaging`, vhost `test`): `outboxPending()`
    odzwierciedla wstawioną wiadomość; `queues()` zawiera `events` z liczbą konsumentów; adapter z nieosiągalnym
    Management API daje `null`. Vhost adaptera pochodzi z parametru (`/` w dev, `test` w testach).
  - `DashboardEndpointTest`: `support` dostaje 200 i `system === null` oraz liczby z Foundry; `admin` dostaje
    `system` z ośmioma usługami w ustalonej kolejności; token klienta i brak tokena dają 401;
    `Cache-Control: no-store, private`; `CatalogStatisticsProvider` rzucający `ConnectionException` daje 503
    jako problem details.
- **Krok 2: FAIL. Krok 3: implementacja. Krok 4: PASS (`make -C apps/api check test`).**
- **Krok 5: Eksport**: `make -C apps/api openapi` i `make api-client`.
- **Krok 6: Commit** `feat(api): add staff dashboard with catalog stats and system status`

### Task 22: Logowanie w sklepie (BFF)

**Pliki:**
- Utwórz: `apps/store/src/auth/{config.ts,oidc.ts,return-to.ts,session-store.ts,refresh.ts,cookies.ts}.ts`,
  `apps/store/app/auth/{login,callback,session,logout}/route.ts`, `apps/store/src/account/AccountMenu.tsx`,
  testy obok plików, `apps/store/src/auth/refresh.integration.test.ts`
- Modyfikuj: `apps/store/app/layout.tsx` (nagłówek z `AccountMenu`), `infra/docker-compose.yml` (env `store`:
  `STORE_URL`, `REDIS_URL`, `KEYCLOAK_INTERNAL_URL`, `KEYCLOAK_PUBLIC_URL`; `env_file` z `.env.local`),
  `.github/workflows/ci.yml` (usługa `redis` przed testami; sklep przez `make -C apps/store test` zamiast `test-unit`)

**Interfejsy:**
- Konsumuje: `createApiClient` (zadanie 18), `GET /api/v1/me` (zadanie 20).
- Produkuje:
  - `sanitizeReturnTo(value: string | null | undefined, storeUrl: string): string`
  - `type SessionData = { sub: string; accessToken: string; refreshToken: string; idToken: string; accessTokenExpiresAt: number; refreshTokenExpiresAt: number }`
  - `SessionStore`: `create(data): Promise<string>`, `get(id): Promise<SessionData | null>`, `update(id, data): Promise<void>`, `delete(id): Promise<void>`
  - `getFreshAccessToken(sessionId: string, deps: { store: SessionStore; redis: RedisClient; refresh: (refreshToken: string) => Promise<TokenSet>; now?: () => number; pollIntervalMs?: number; waitTimeoutMs?: number }): Promise<string | null>`
    (blokada `lock:refresh:<id>`, `NX PX 5000`, próg 30 s, sprawdzanie co 100 ms, limit 5 s)

- **Krok 1: Testy**
  - `return-to.test.ts`: `/` i `/?category=x&page=2` są zachowane; `//evil.com`, `/\evil.com`, `https://evil.com`,
    `javascript:alert(1)`, `/a\nb`, `''` i `undefined` dają `/`.
  - `refresh.integration.test.ts` (prawdziwy Redis): 5 równoległych wywołań z tokenem wygasającym za 10 s daje
    dokładnie jedno wywołanie `refresh`, a wszystkie zwracają nowy token; token ważny jeszcze 300 s nie wywołuje
    `refresh`; blokada trzymana przez kogoś, kto nigdy nie zapisze sesji, daje `null` po `waitTimeoutMs`.
  - `session/route.test.ts`: bez ciasteczka zwraca `null` i `Cache-Control: private, no-store`; ciasteczko bez
    sesji w Redisie zwraca `null` i `Set-Cookie` czyszczące `store_session` (`Max-Age=0`); 401 z `/api/v1/me`
    usuwa sesję i zwraca `null`.
  - `login/route.test.ts`: przekierowanie na `${KEYCLOAK_PUBLIC_URL}/realms/shop/protocol/openid-connect/auth` z
    `code_challenge_method=S256`, `state` i `nonce`; niepoprawny `returnTo` zapisany jako `/`; `no-store`.
  - `callback/route.test.ts`: niezgodny `state` albo brak transakcji w Redisie daje przekierowanie na `/` bez
    utworzenia sesji.
  - `AccountMenu.test.tsx`: `null` daje link „Zaloguj się” do `/auth/login?returnTo=<bieżąca ścieżka>`; `{name}`
    daje „Zalogowany jako Jan” i formularz `POST /auth/logout` z przyciskiem `Button` z `src/components/ui`.
- **Krok 2: FAIL. Krok 3: implementacja. Krok 4: PASS (`make -C apps/store check test`).**
- **Krok 5: Sprawdź w przeglądarce**: logowanie kontem `jan.kowalski@example.com` (hasło demo z
  `shop.json`) prowadzi z powrotem do sklepu z „Zalogowany jako …”; `document.cookie` nie zawiera `store_session`;
  wylogowanie przywraca „Zaloguj się”.
- **Krok 6: Commit** `feat(store): add server-side login with Keycloak and Redis sessions`

### Task 23: Panel – logowanie, layout, dashboard

**Pliki:**
- Utwórz: `apps/admin/src/api/{client.ts,schema.d.ts}`, `apps/admin/src/auth/{config.ts,userManager.ts}`,
  `apps/admin/src/stores/auth.ts`, `apps/admin/src/router/index.ts`, `apps/admin/src/composables/useIdleLogout.ts`,
  `apps/admin/src/layout/{AppLayout,AppTopbar,AppMenu}.vue`, `apps/admin/src/views/{DashboardView,AuthCallbackView}.vue`,
  `apps/admin/src/dashboard/{CatalogStats,SystemStatus}.vue`, `apps/admin/src/dashboard/useDashboard.ts`,
  testy w `apps/admin/tests/`
- Modyfikuj: `infra/docker-compose.yml` (montowanie `openapi-admin.json` w `/contract/openapi-admin.json`, env
  `VITE_API_URL`, `VITE_KEYCLOAK_URL`, `VITE_ADMIN_URL`), `Makefile` (`api-client`: generowanie dla panelu),
  `.github/workflows/ci.yml` (`apps/admin/src/api/schema.d.ts` w `git diff --exit-code` kroku „Contract freshness”)

**Interfejsy:**
- Konsumuje: `openapi-admin.json` (zadania 20 i 21).
- Produkuje: `useIdleLogout(options: { timeoutMs: number; onIdle: () => void; channel?: BroadcastChannel; now?: () => number }): { stop(): void }`;
  `useAuthStore()` z `staff: StaffMe | null`, `load(): Promise<void>`, `logout(): Promise<void>`;
  `useDashboard(intervalMs = 30000)` z `data`, `error`, `loading`, `refresh()`.

- **Krok 1: Wygeneruj klienta**: `make api-client` tworzy `apps/admin/src/api/schema.d.ts`.
- **Krok 2: Testy**
  - `useIdleLogout.test.ts` (fake timers): bez aktywności `onIdle` wywołane raz po 15 min; `keydown` w 14. minucie
    przesuwa wywołanie na 29. minutę; wiadomość z innej karty w 14. minucie też przesuwa; `stop()` blokuje wywołanie.
  - `DashboardView.test.ts`: `system === null` ukrywa nagłówek „Stan systemu”; z `system` jest osiem tagów usług;
    `inSync === false` daje komunikat o rozbieżności; `productsWithoutImages > 0` daje ostrzeżenie;
    `queues === null` daje „Brak danych o kolejkach”; błąd API daje `Message` z błędem.
  - `client.test.ts`: odpowiedź 401 wywołuje `signinRedirect` dokładnie raz; nagłówek `Authorization: Bearer <token>`.
  - `router.test.ts`: brak użytkownika wywołuje `signinRedirect`; z użytkownikiem nawigacja przechodzi; trasa
    callback nie jest chroniona.
  - `AppTopbar.test.ts`: login i role z `useAuthStore`; „Wyloguj” wywołuje `signoutRedirect`.
- **Krok 3: FAIL. Krok 4: implementacja (`InMemoryWebStorage`, `automaticSilentRenew`, `useIdleLogout`
  z `timeoutMs: 900000` w `AppLayout`).**
- **Krok 5: PASS**: `make -C apps/admin check test`; ponowne `make api-client` nie zmienia `git diff`.
  Krok „Contract freshness” z CI uruchomiony lokalnie przechodzi, a ręcznie zmieniony `schema.d.ts` panelu daje
  niezerowy kod wyjścia.
- **Krok 6: Sprawdź w przeglądarce**: `localhost:5173` przekierowuje na logowanie realmu `backoffice`.
  Pełne logowanie z TOTP sprawdza zadanie 24.
- **Krok 7: Commit** `feat(admin): add login, layout and dashboard`

### Task 24: Testy E2E

**Pliki:**
- Utwórz: `e2e/Dockerfile` (obraz Playwright + `socat`), `e2e/entrypoint.sh`, `e2e/package.json`, `e2e/tsconfig.json`,
  `e2e/playwright.config.ts`, `e2e/support/{accounts.ts,totp.ts,mailpit.ts}`,
  `e2e/tests/{catalog,customer-login,registration,staff-dashboard}.spec.ts`
- Modyfikuj: `infra/docker-compose.yml` (usługa `e2e` w profilu `e2e`), `Makefile` (`e2e`),
  `.github/workflows/ci.yml` (job E2E: `make up`, `make seed`, `make e2e`)

**Interfejsy:**
- Konsumuje: konta i sekrety TOTP z zadania 19.
- Produkuje: `entrypoint.sh` uruchamia `socat TCP-LISTEN:<port>,fork,reuseaddr TCP:<usługa>:<port wewnętrzny>`
  dla sklepu, panelu, API, Keycloak i imgproxy, a potem `npx playwright test`;
  `totp(rawSecret: string): Promise<string>` (`OTPAuth.Secret.fromUTF8`, SHA1, 6 cyfr, 30 s; czeka na nowe okno,
  gdy do końca bieżącego zostało mniej niż 3 s); `waitForEmail(to: string): Promise<{ html: string }>`
  (API Mailpita `http://mailpit:8025/api/v1/search`).

- **Krok 1: Testy** zgodnie ze scenariuszami 1–4 ze specyfikacji („E2E”). Szczegóły:
  - `catalog`: każde widoczne `<img>` w siatce ma `naturalWidth > 0`.
  - `registration`: adres `e2e+<timestamp>@example.com`; z e-maila bierzemy link z `login-actions/action-token`.
  - `staff-dashboard`: `support` nie widzi nagłówka „Stan systemu”.
- **Krok 2: Sprawdź**: po `make up && make seed` komenda `make e2e` daje 4 testy zaliczone; drugie
  uruchomienie też przechodzi.
- **Krok 3: Commit** `test(e2e): add Playwright smoke tests for catalog and logins`

### Task 26: README, pomiar pamięci i odbiór

**Pliki:**
- Modyfikuj: `README.md`, `docs/features/walking-skeleton/spec.md` (tylko jeśli implementacja odbiegła od opisu)

- **Krok 1: Czysty przebieg w osobnym klonie**: sklonuj repo do scratchpadu, ustaw w `.env` inny
  `COMPOSE_PROJECT_NAME` i inne porty (to sprawdza też kilka stosów naraz). Uruchom `make up`, `make seed`,
  `make check`, `make test` i `make e2e`. Wszystkie przechodzą, a wyniki trafiają do raportu.
- **Krok 2: Pomiar pamięci**: `docker stats --no-stream` po `make up`, po `make seed` i w trakcie `make e2e`.
  Minimum to szczyt sumy zaokrąglony w górę do pełnego GB plus 1 GB zapasu. Zalecana wartość to minimum plus 2 GB.
- **Krok 3: Uzupełnij `README.md`**:
  - start (`make up`, `make seed`, adresy),
  - wymagania (Docker, zmierzona pamięć dla Docker Desktop),
  - porty i kilka stosów przez `.env`,
  - konta demo: hasła oraz sekrety TOTP w base32 z URI `otpauth://`, opisane jako dane demo,
  - komendy Makefile,
  - restart `worker` po zmianie kodu handlerów (`docker compose restart worker`).
- **Krok 4: Zgodność specyfikacji**: przejdź specyfikację punkt po punkcie i popraw opis tam, gdzie
  implementacja świadomie odeszła od niego.
- **Krok 5: Commit** `docs: add README with setup, demo accounts and requirements`
- **Krok 6: Po akceptacji użytkownika** usuń `docs/features/walking-skeleton/plan.md` (commit
  `docs: remove completed walking skeleton plan`).
