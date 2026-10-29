# Walking skeleton – plan implementacji

> **Dla agentów:** wymagany skill `superpowers:subagent-driven-development`. Zacznij od sekcji „Status”, a z zadań
> czytaj tylko to, które realizujesz (skrypt `task-brief` ze skilla), a nie cały plan.

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

W toku: stos PR-ów od 13B do 24 (każdy PR do gałęzi poprzedniego wiersza). Następne zadanie: **Task 16 i 17**. Blokery: brak.

Po wydaniu stabilnego Symfony 8.2: zastąpić `RelayDomainEventToBroker` wbudowaną opcją `outbox` transportu
Messengera (routing `DomainEvent` → `events`, `events.outbox: outbox`), testy z Task 14 zostają bez zmian.

| PR | Zadania | Zakres | Status |
|---|---|---|---|
| [#1](https://github.com/piotrp321/shop/pull/1) | 1–8 | dokumentacja API, stos usług, obraz API z SPX, narzędzia jakości API, szkielety sklepu i panelu, konfiguracja Claude Code, wspólne zachowanie HTTP w API | ✅ |
| [#2](https://github.com/piotrp321/shop/pull/2), [#3](https://github.com/piotrp321/shop/pull/3) | 25 | lekkie CI | ✅ |
| [#5](https://github.com/piotrp321/shop/pull/5) | 9 | domena Catalog | ✅ |
| [#6](https://github.com/piotrp321/shop/pull/6) | 11 | persystencja Catalog (Doctrine, PostgreSQL) | ✅ |
| [#8](https://github.com/piotrp321/shop/pull/8) | 12 | obrazy w API (Garage, imgproxy) | ✅ |
| [#9](https://github.com/piotrp321/shop/pull/9) | 13A | wersja produktu i odczyt produktów paczkami do indeksu | ✅ |
| [#11](https://github.com/piotrp321/shop/pull/11) | 15 (część 1) | drzewo kategorii, OpenAPI sklepu i panelu, kontrola aktualności kontraktu | ✅ |
| [#12](https://github.com/piotrp321/shop/pull/12) | 13B | indeks Elasticsearch, adaptery, przebudowa synchroniczna z przepięciem aliasu | ✅ |
| [#13](https://github.com/piotrp321/shop/pull/13) | 15 (część 2) | lista produktów (po 13B) | ✅ |
| [#10](https://github.com/piotrp321/shop/pull/10) | 14 | transactional outbox, szyny Messengera, RabbitMQ, worker | ✅ |
| [#14](https://github.com/piotrp321/shop/pull/14) | 10, 27 | komendy tworzenia kategorii i produktów, indeksowanie produktu po zdarzeniu | ✅ |
| | 16, 17 | dane demo i seed | |
| | 18 | strona katalogu w sklepie | |
| | 19, 20 | realmy Keycloak i uwierzytelnianie w API | |
| | 21 | dashboard w API | |
| | 22 | logowanie w sklepie | |
| | 23 | panel: logowanie, layout, dashboard | |
| | 24, 26 | testy E2E i odbiór | |

- Wiersz to jeden PR z jedną logiczną zmianą, a kolejność wierszy to kolejność prac (wynika z zależności
  „Konsumuje” w zadaniach, nie z numeracji). Task 12 jest przed Task 15, bo endpoint zwraca podpisane warianty
  zdjęć, a seed zapisuje zdjęcia w Garage. Task 14 jest przed Task 10: handlery komend są usługami
  kontenera i potrzebują szyny `command.bus` oraz implementacji `DomainEventPublisher` z Task 14 i repozytoriów
  z Task 11, inaczej kontener się nie kompiluje. Task 27 jest w jednym PR z Task 10, bo test przepływu zdarzenia
  zaczyna się od komendy `CreateProduct`.
- Jedno uruchomienie `subagent-driven-development` realizuje jeden wiersz i kończy się otwarciem PR. Kolejny
  wiersz zaczyna się po decyzji użytkownika o merge.
- Orkiestrator oznacza wiersz jako ✅ w PR, który go realizuje, a numer PR dopisuje po jego otwarciu, w tym
  samym PR. Ledger SDD jest tylko pamięcią roboczą jednego worktree, a lista zadań w aplikacji tylko widokiem.

Wznowienie pracy w nowej sesji:

1. Przeczytaj „Status”, „Ograniczenia globalne” i „Punkty zatrzymania”.
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
- `Domain` to czysty PHP, bez mapowania Doctrine. Persystencja przez rekordy z atrybutami w `Infrastructure`.
- Wersje główne jak w „Stosie”. Dokładne wersje przypięte w Dockerfile, compose i lockfile'ach.
- Sekrety wyłącznie w `.env.local`. Plik compose nie podstawia sekretów przez `${…}`.
- Po zmianie endpointu lub DTO: `make -C apps/api openapi` i `make api-client` w tym samym commicie.
- Rozmiary obrazów tylko w `ImageSize`. Kolory w sklepie tylko z tokenów (zmienne motywu shadcn/ui
  w `src/styles/tokens.css`).
- Pracując w `apps/store`, przestrzegaj `apps/store/CLAUDE.md`, w tym zasad przenoszenia kodu z vercel/shop.
- Zadanie jest skończone, gdy przechodzą `check` i testy dotkniętych aplikacji. W raporcie pokaż wynik komend.
- Zadania ustawiające reguły (lint, Deptrac, CORS i podobne) sprawdzają też próby obejścia (sondy negatywne),
  nie tylko przypadek podstawowy.
- Po `composer require` z Fleksem przejrzyj zmiany przepisów i usuń zbędne (sekrety w `.env`, `AGENTS.md`,
  nieużywaną konfigurację).

## Punkty zatrzymania

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
6. Odczyt paczkami: `LIMIT` liczy produkty, a nie wiersze po złączeniu z kategoriami dodatkowymi (zadanie 13A).
7. Niejednoznaczny wynik przepięcia aliasu: przebudowa czyta rzeczywisty cel aliasu i nigdy nie usuwa indeksu,
   na który alias może wskazywać (zadanie 13B).

---

### Task 9: Domena Catalog

**Pliki:**
- Utwórz: `src/Shared/Domain/Event/DomainEvent.php`, `src/Shared/Domain/Event/DomainEventPublisher.php`,
  `src/Catalog/Domain/Category/{Category,CategoryId,CategoryName,CategoryPath,CategoryRepository,CategoryNotFound}.php`,
  `src/Catalog/Domain/Product/{Product,ProductId,ProductName,Slug,ShortDescription,ProductCategories,TaxCategory,ProductImage,ProductImages,ImageKey,ProductRepository,ProductCreated,SlugAlreadyTaken}.php`,
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
  - `Product::create(ProductId, ProductName, Slug, ShortDescription, ProductCategories, TaxCategory, ProductImages): self`,
    `releaseEvents(): list<DomainEvent>`, gettery pól, `categories(): ProductCategories`, `images(): ProductImages`
  - `ProductCategories::create(CategoryId $main, list<CategoryId> $additional = []): self`, `main(): CategoryId`,
    `additional(): list<CategoryId>`, `all(): list<CategoryId>` (główna pierwsza)
  - `ProductImages::fromList(list<ProductImage>)`, `main(): ?ProductImage`, `isEmpty(): bool`, `toList()`
  - `ProductImage(ImageKey $key, string $alt, int $position)`; `ImageKey::fromString(string)` wymaga
    `^originals/[a-f0-9]{64}\.(jpg|png|webp|avif|heic)$`, a rozszerzenia pochodzą z enumu `OriginalImageType`
  - `TaxCategory` (enum string): `standard`, `food`, `food_supplement`, `printed_publication`
  - `ProductRepository { save(Product $p): void; get(ProductId $id): Product; all(): iterable<Product>; count(): int; countWithoutImages(): int }`;
    `save` deklaruje w PHPDoc `@throws SlugAlreadyTaken`
  - `ProductCreated(string $productId, DateTimeImmutable $occurredAt)` implementuje `DomainEvent`

- **Krok 1: Testy**
  - `CategoryTest`: korzeń ma ścieżkę `/<id>/` i `ancestorIds() == [id]`; dziecko wnuka ma ścieżkę
    `/<r>/<c>/<g>/` i przodków w kolejności od korzenia; pusta nazwa i nazwa o długości 101 rzucają
    `InvalidArgumentException`.
  - `ProductTest`: `create` zapisuje dokładnie jedno `ProductCreated` z tym samym `productId`; drugie
    `releaseEvents()` zwraca `[]`; nazwa pusta i o długości 201 są odrzucane; `ShortDescription` o długości 501
    jest odrzucany, a pusty przechodzi.
  - `ProductCategoriesTest`: sama główna daje `all() == [main]`; główna z dwiema dodatkowymi daje `all()`
    w kolejności główna, dodatkowe; 10 dodatkowych przechodzi, 11 jest odrzucanych; powtórzona dodatkowa i główna
    podana też jako dodatkowa są odrzucane.
  - `SlugTest`: `buty-puls-2` jest poprawny; `Buty`, `-a`, `a-`, `a--b`, `a b` i slug o długości 201 są odrzucane.
  - `ProductImagesTest`: 11 zdjęć jest odrzucanych; pozycje `[0, 2]` i `[1, 0]` są odrzucane; dwa zdjęcia z tym
    samym `ImageKey` są odrzucane; pusty `alt` jest odrzucany;
    `main()` zwraca pozycję 0; pusta lista daje `isEmpty() === true` i `main() === null`.
  - `ImageKeyTest`: poprawny klucz przechodzi; `originals/abc.jpg` i `../x.jpg` są odrzucane.
  - `CategoryIdTest`: UUID wielkimi literami jest zapisywany małymi; `abc` jest odrzucany.
  - `TaxCategoryTest`: `TaxCategory::from('food')` działa, `from('luxury')` rzuca `ValueError`.
- **Krok 2: Uruchom `make -C apps/api test-unit`, oczekiwany FAIL.**
- **Krok 3: Zaimplementuj model.**
- **Krok 4: Uruchom `make -C apps/api check test-unit`, oczekiwany PASS.**
- **Krok 5: Commit** `feat(api): add catalog domain model`

### Task 10: Komendy Catalog

**Pliki:**
- Utwórz: `src/Catalog/Application/Command/{CreateCategory,CreateCategoryHandler,CreateProduct,CreateProductHandler}.php`,
  `src/Catalog/Domain/Category/CategoryAlreadyExists.php`, `src/Catalog/Domain/Product/ProductAlreadyExists.php`,
  `tests/Support/InMemory/{InMemoryCategoryRepository,InMemoryProductRepository,RecordingDomainEventPublisher}.php`,
  `tests/Unit/Catalog/Application/{CreateCategoryHandlerTest,CreateProductHandlerTest}.php`
- Modyfikuj: `src/Catalog/Infrastructure/Persistence/Doctrine/{DoctrineCategoryRepository,DoctrineProductRepository}.php`
  (naruszenie klucza głównego → `CategoryAlreadyExists` / `ProductAlreadyExists`),
  `tests/Integration/Catalog/Persistence/{DoctrineCategoryRepositoryTest,DoctrineProductRepositoryTest}.php`

**Interfejsy:**
- Konsumuje: zadanie 9, szyny Messengera i `DomainEventPublisher` (zadanie 14), repozytoria (zadanie 11).
  Realizowane w jednym PR z zadaniem 27.
- Produkuje: `CreateCategory(string $id, string $name, ?string $parentId)`;
  `CreateProduct(string $id, string $name, string $slug, string $shortDescription, string $mainCategoryId, list<string> $additionalCategoryIds, string $taxCategory, list<array{key: string, alt: string}> $images)`.
  Handlery jako `#[AsMessageHandler(bus: 'command.bus')]`. Oba handlery odrzucają istniejące id
  (`CategoryAlreadyExists`, `ProductAlreadyExists` w domenie, implementują `Conflict`), bo `save()` repozytoriów
  nadpisuje agregat o tym samym id. Wyścig dwóch równoczesnych utworzeń, które oba przejdą sprawdzenie, kończy się
  naruszeniem klucza głównego; repozytoria zamieniają je na te same wyjątki (409). `CreateProductHandler` sprawdza istnienie wszystkich kategorii produktu i publikuje zdarzenia agregatu przez `DomainEventPublisher` po `save`.
  `InMemoryProductRepository::save` rzuca `SlugAlreadyTaken` przy zajętym slugu, jak ograniczenie w bazie.

- **Krok 1: Testy**
  - `CreateCategoryHandlerTest`: korzeń zapisany; dziecko ma ścieżkę rodzica plus własne id; nieznany
    `parentId` rzuca `CategoryNotFound`; istniejące id rzuca `CategoryAlreadyExists`, a zapisana kategoria się
    nie zmienia.
  - `CreateProductHandlerTest`: produkt z kategorią główną i dodatkową zapisany, opublikowane dokładnie jedno
    `ProductCreated`; nieznana kategoria główna i nieznana dodatkowa rzucają `CategoryNotFound`; zajęty slug
    rzuca `SlugAlreadyTaken` i nic nie jest publikowane; istniejące id rzuca `ProductAlreadyExists`, zapisany
    produkt się nie zmienia i nic nie jest publikowane.
  - `DoctrineCategoryRepositoryTest`, `DoctrineProductRepositoryTest`: naruszenie klucza głównego przy zapisie
    (wiersz o tym samym id wstawiony drugim połączeniem po odczycie w repozytorium) rzuca `CategoryAlreadyExists`
    / `ProductAlreadyExists`.
- **Krok 2: FAIL. Krok 3: implementacja. Krok 4: PASS (`make -C apps/api check test`).**
- **Krok 5: Commit** `feat(api): add commands for creating categories and products`

### Task 11: Persystencja Catalog

**Pliki:**
- Utwórz: `config/packages/doctrine.yaml` (mapowanie atrybutami z `src/Catalog/Infrastructure/Persistence/Doctrine/Record`),
  `src/Catalog/Infrastructure/Persistence/Doctrine/Record/{CategoryRecord,ProductRecord}.php` (`images` w JSONB),
  `src/Catalog/Infrastructure/Persistence/Doctrine/{DoctrineCategoryRepository,DoctrineProductRepository}.php`,
  `migrations/Version…CreateCatalogCategory.php`, `migrations/Version…CreateCatalogProduct.php`,
  `tests/object-manager.php` (loader PHPStana), `tests/Factory/{CategoryFactory,ProductFactory}.php`,
  `tests/Integration/Catalog/Persistence/{DoctrineCategoryRepositoryTest,DoctrineProductRepositoryTest}.php`
- Modyfikuj: `src/Catalog/Domain/Category/Category.php`, `src/Catalog/Domain/Product/Product.php` (`reconstitute()`),
  `tests/Unit/Catalog/Domain/{CategoryTest,ProductTest}.php`, `infra/docker-compose.yml` (`env_file` dla `api`),
  `config/packages/test/dama_doctrine_test_bundle.yaml`, `phpunit.dist.xml` (rozszerzenie dama),
  `phpstan.dist.neon` (`objectManagerLoader` rozszerzenia doctrine), `apps/api/Makefile` (`init`: migracje;
  `init-test`: migracje w środowisku `test`; `test` wywołuje najpierw `init-test`), `Makefile` (`up` po starcie
  usług wywołuje `make -C apps/api init`), `apps/api/CLAUDE.md` (cele `init` i `init-test`),
  `.github/workflows/ci.yml` (usługa `postgres` przed testami)

**Interfejsy:**
- Konsumuje: interfejsy repozytoriów z zadania 9.
- Produkuje: tabele `catalog_category`, `catalog_product` i `catalog_product_category` jak w sekcji
  „Persystencja” specyfikacji, z relacjami między rekordami i bez mapowania domeny; `Category::reconstitute()`
  i `Product::reconstitute()`; `DoctrineProductRepository::save()` zapisuje produkt i jego przypisania dodatkowe
  w jednej transakcji, robi flush i zamienia naruszenie ograniczenia unikalności sluga na `SlugAlreadyTaken`;
  fabryki Foundry `CategoryFactory` (z opcją rodzica) i `ProductFactory` budują obiekty domenowe.

- **Krok 1: Testy**
  - `CategoryTest`: `reconstitute` odtwarza id, nazwę, rodzica i ścieżkę; odrzuca ścieżkę niekończącą się własnym
    id, rodzica innego niż przedostatni element ścieżki i korzeń z rodzicem.
  - `ProductTest`: `reconstitute` odtwarza pola i nie zapisuje zdarzeń (`releaseEvents() === []`).
  - `DoctrineCategoryRepositoryTest`: zapis i odczyt zachowuje ścieżkę i rodzica; `get` nieznanego id rzuca
    `CategoryNotFound`; `count()`.
  - `DoctrineProductRepositoryTest`: zapis i odczyt zachowuje kolejność zdjęć, kategorię główną, zbiór kategorii
    dodatkowych (posortowany po id) i `TaxCategory`; produkt bez kategorii dodatkowych nie ma wierszy
    w `catalog_product_category`; kategoria główna nie ma tam wiersza; `count()`; `countWithoutImages()`; zapis
    drugiego produktu z tym samym slugiem rzuca `SlugAlreadyTaken`, a w tabelach nie ma jego wierszy; po
    `SlugAlreadyTaken` kolejna operacja na repozytorium (nowe żądanie albo kolejna wiadomość) działa, czyli
    zamknięty EntityManager nie przechodzi dalej; produkt z nieistniejącą kategorią główną albo dodatkową narusza
    klucz obcy; usunięcie produktu usuwa jego wiersze z `catalog_product_category`, a usunięcie przypisanej
    kategorii jest odrzucane.
  - Deptrac: sonda z atrybutem Doctrine na klasie w `Catalog/Domain` kończy `make -C apps/api check` błędem.
  - Schemat: `catalog_product.primary_category_id` jest `NOT NULL`; indeks `path` ma `text_pattern_ops`; istnieją indeksy na
    `catalog_product.primary_category_id` i `catalog_product_category.category_id` (odczyt z `pg_indexes`).
- **Krok 2: FAIL. Krok 3: `reconstitute()`, rekordy, repozytoria, migracja (`make -C apps/api migration`,
  `text_pattern_ops` dopisany ręcznie).**
- **Krok 4: PASS**: `make -C apps/api check test`; `bin/console doctrine:schema:validate` w kontenerze
  przechodzi.
- **Krok 5: Sprawdź inicjalizację**: na świeżym projekcie Compose (inny `COMPOSE_PROJECT_NAME`, puste wolumeny)
  `make up` tworzy schemat, a `make test` przechodzi bez ręcznych kroków; drugie `make up` kończy się kodem 0
  bez nowych migracji; `docker compose restart api` nie uruchamia migracji (logi `api`); `make -C apps/api init`
  przy zatrzymanym Postgresie kończy się kodem różnym od 0.
- **Krok 6: Commit** `feat(api): persist catalog with Doctrine records`

### Task 12: Obrazy – Garage i imgproxy

**Pliki:**
- Utwórz: `src/Catalog/Domain/Product/OriginalImageType.php`,
  `src/Catalog/Application/Image/{ImageSize,ImageFormat}.php`,
  `src/Catalog/Application/Port/{ImageUrlGenerator,ProductImageStorage}.php`,
  `src/Catalog/Infrastructure/Image/{ImgproxyUrlGenerator,FlysystemProductImageStorage,InvalidImage}.php`,
  `config/packages/flysystem.yaml`, `tests/Unit/Catalog/Image/{ImageSizeTest,ImgproxyUrlGeneratorTest}.php`,
  `tests/Integration/Catalog/Image/{FlysystemProductImageStorageTest,ImgproxySigningTest}.php`,
  `tests/Fixtures/images/{sample.jpg,sample.png,sample.webp,sample.avif,sample.heic,sample.svg,oversized.webp}`,
  `tests/Fixtures/images/README.md` (komenda odtworzenia fixtures)
- Modyfikuj: `src/Catalog/Domain/Product/ImageKey.php`, `tests/Unit/Catalog/Domain/ImageKeyTest.php`,
  `infra/docker-compose.yml` (env `api`: `IMGPROXY_PUBLIC_URL`, `IMAGES_BUCKET`, endpoint Garage; env `imgproxy`:
  `IMGPROXY_ALLOWED_SOURCES` z obu bucketów, `IMGPROXY_MAX_SRC_FILE_SIZE`, `IMGPROXY_MAX_SRC_RESOLUTION`),
  `.github/workflows/ci.yml` (usługi `garage`, `garage-init` i `imgproxy` przed testami)

**Interfejsy:**
- Produkuje:
  - `OriginalImageType` (enum string w domenie, wartość to rozszerzenie): `jpg`, `png`, `webp`, `avif`, `heic`;
    wzorzec `ImageKey` budowany z jego przypadków: `^originals/[a-f0-9]{64}\.(jpg|png|webp|avif|heic)$`
  - `ImageSize::Listing` z `aspectRatio(): string` (`'1:1'`), `widths(): list<int>` (`[256, 384, 512, 768]`),
    `formats(): list<ImageFormat>` (`[Webp]`), `resize(): string` (`'fill'`), `gravity(): string` (`'sm'`)
  - `ImageFormat` (enum string): `webp` (wyjście; AVIF można dopisać później)
  - `ImageUrlGenerator { url(string $imageKey, ImageSize $size, int $width, ImageFormat $format): string }`;
    szerokość spoza `widths()` rzuca `InvalidArgumentException`
  - `ProductImageStorage { store(string $contents): string /* ImageKey */; purge(): void }`. `store` sprawdza
    po kolei: rozmiar (powyżej limitu → `InvalidImage`, zanim cokolwiek innego), typ przez
    `finfo(FILEINFO_MIME_TYPE)->buffer()` z zamkniętą mapą `image/jpeg|png|webp|avif|heic` → `OriginalImageType`
    (inny typ → `InvalidImage`), rozdzielczość z `getimagesizefromstring` (brak wymiarów albo
    szerokość × wysokość powyżej limitu → `InvalidImage`). Odrzucona treść nie trafia do bucketu.
  - Limity są parametrami konstruktora adaptera ustawionymi w konfiguracji kontenera: 64 MB (`67108864` B)
    i 50 MP (`50000000` px). imgproxy ma te same limity (`IMGPROXY_MAX_SRC_FILE_SIZE=67108864`,
    `IMGPROXY_MAX_SRC_RESOLUTION=50`).
- Ścieżka imgproxy: `/{signature}/rs:fill:{w}:{h}/g:sm/{base64url("s3://{bucket}/{key}")}.{format}`, gdzie
  podpis to base64url bez paddingu z HMAC-SHA256(klucz, sól + ścieżka); klucz i sól są w `.env.local` jako hex.
- Wektor z dokumentacji imgproxy („Signing a URL”): klucz `736563726574`, sól `68656C6C6F`, ścieżka
  `/rs:fill:300:400:0/g:sm/aHR0cDovL2V4YW1w/bGUuY29tL2ltYWdl/cy9jdXJpb3NpdHku/anBn.png`, podpis
  `oKfUtW34Dvo2BGQehJFR4Nr0_rIjOtdtzJ3QFsUcXH8`. Szerokość 300 jest spoza `ImageSize`, więc wektor sprawdza samą
  funkcję podpisującą ścieżkę.
- Fixtures (każdy poniżej 3 KB) generuje jednorazowy kontener `alpine:3.22` z `vips-tools` i `vips-heif`:
  obraz 64×48 sRGB zapisany jako JPEG, PNG, WebP, AVIF (`heifsave --compression av1`) i HEIC
  (`heifsave --compression hevc`), wszystko z `strip`; `oversized.webp` to bezstratny czarny obraz 8000×7000
  (56 MP); `sample.svg` to minimalny SVG z prologiem XML. Testy używają tylko plików z repo, bez macOS i `sips`.

- **Krok 1: `ImageKey` (osobny commit, TDD)**
  - `ImageKeyTest`: klucz z każdym z rozszerzeń `jpg`, `png`, `webp`, `avif`, `heic` przechodzi; `.jpeg`, `.svg`,
    `.gif` i dotychczasowe przypadki (krótki hash, traversal, nowa linia, wielkie litery) są odrzucane.
  - FAIL (`make -C apps/api test-unit`), implementacja `OriginalImageType` i `ImageKey`, PASS
    (`make -C apps/api check test-unit`).
  - Commit `feat(api): accept PNG, WebP, AVIF and HEIC original image keys`
- **Krok 2: Testy**
  - `ImageSizeTest`: wartości `Listing` jak wyżej.
  - `ImgproxyUrlGeneratorTest`: wektor z dokumentacji daje udokumentowany podpis; dla `Listing`, 384 i WebP adres
    zawiera `rs:fill:384:384/g:sm/` i kończy się `.webp`; inna szerokość daje inny adres; szerokość 300 rzuca
    wyjątek; adres zaczyna się od `IMGPROXY_PUBLIC_URL`.
  - `FlysystemProductImageStorageTest` (bucket `product-images-test`): każdy z pięciu fixtures daje
    `originals/<sha256>.<rozszerzenie>` i plik istnieje; dwa zapisy tej samej treści dają ten sam klucz; treść PNG
    z pliku o nazwie `.jpg` dostaje klucz `.png`; SVG z prologiem i bez, HTML i pusta treść rzucają `InvalidImage`;
    przy wstrzykniętym małym limicie rozmiaru treść o długości limitu przechodzi, a o 1 B dłuższa jest odrzucana;
    przy wstrzykniętym limicie rozdzielczości poniżej 64×48 `sample.png` jest odrzucany; `oversized.webp` przy
    limicie z kontenera jest odrzucany; po każdym odrzuceniu bucket nie ma nowego pliku; `purge()` usuwa zapisane
    oryginały; usługa z kontenera ma limity 64 MB i 50 MP.
  - `ImgproxySigningTest` (baza podmieniona na `http://imgproxy:8080`): dla każdego z pięciu typów źródła
    podpisany adres WebP zwraca 200 i `Content-Type: image/webp`; adres ze zmienionym znakiem podpisu zwraca 403;
    `oversized.webp` wgrany bezpośrednio do bucketu (z pominięciem `store`) zwraca 422.
- **Krok 3: FAIL. Krok 4: implementacja. Krok 5: PASS (`make -C apps/api check test`).** Limit
  `IMGPROXY_MAX_SRC_FILE_SIZE` nie ma testu na żywo (wymagałby pliku 64 MB); sprawdź go w `docker compose config`.
  Odpowiedź 422 z imgproxy w nieoczekiwanym miejscu najpierw diagnozuj z pola `error` w logach `imgproxy`.
- **Krok 6: Commit** `feat(api): store product originals in Garage and sign imgproxy URLs`

### Task 13A: Wersja produktu i odczyt paczkami

Osobny PR przed 13B. Bez Elasticsearch i bez nowych zależności. Zasady wersji i odczytu są w sekcjach
„Persystencja” i „Indeks Elasticsearch → Odczyt produktów do indeksu” specyfikacji.

**Pliki:**
- Utwórz: `src/Catalog/Domain/Product/ProductModifiedConcurrently.php` (`Conflict`),
  `src/Catalog/Application/Port/ProductSearchSource.php`, `src/Catalog/Application/Search/ProductSearchSnapshot.php`,
  `src/Catalog/Infrastructure/Persistence/Doctrine/DbalProductSearchSource.php`,
  `migrations/Version…AddCatalogProductVersion.php`,
  `tests/Integration/Catalog/Persistence/{ProductVersionTest,DbalProductSearchSourceTest}.php`
- Modyfikuj: `src/Catalog/Infrastructure/Persistence/Doctrine/Record/ProductRecord.php` (`#[ORM\Version]`),
  `src/Catalog/Infrastructure/Persistence/Doctrine/DoctrineProductRepository.php`,
  `src/Catalog/Domain/Product/ProductRepository.php` (`@throws ProductModifiedConcurrently`), `config/services.yaml`

**Interfejsy:**
- Produkuje:
  - `ProductSearchSnapshot(string $id, int $version, string $name, string $slug, string $shortDescription, string $mainCategoryId, list<string> $additionalCategoryIds, list<list<string>> $categoryPaths, ?array{key: string, alt: string} $mainImage)`;
    `categoryPaths` to ścieżki (id od korzenia do kategorii włącznie) kategorii głównej i dodatkowych
  - `ProductSearchSource { page(?string $afterId, int $limit): list<ProductSearchSnapshot> }`
  - `ProductModifiedConcurrently` rzucany przez `DoctrineProductRepository::save()`

- **Krok 1: Testy**
  - `ProductVersionTest`:
    - nowy produkt ma wersję 1;
    - zmiana nazwy daje 2;
    - zmiana wyłącznie kategorii dodatkowych zwiększa wersję (to sprawdza, czy wystarczy `#[ORM\Version]`);
    - zapis bez zmian nie tworzy nowego stanu pod starą wersją.
    - Równoległe zapisy, na dwóch osobnych połączeniach poza transakcją dama i z własnym sprzątaniem: oba
      odczytują wersję 1, pierwszy commituje zmianę kategorii, drugi zapisuje inną zmianę. Drugi dostaje
      `ProductModifiedConcurrently`, a wiersz i `catalog_product_category` zawierają wyłącznie stan pierwszego
      z wersją 2. Po konflikcie kolejna operacja na repozytorium działa.
  - `DbalProductSearchSourceTest`:
    - 7 produktów i `limit=3` dają paczki 3, 3, 1, potem pustą;
    - 6 produktów i `limit=3` dają 3, 3 i pustą;
    - pusty katalog daje pustą listę;
    - każdy id występuje dokładnie raz, rosnąco;
    - produkty z 10 kategoriami dodatkowymi przy `limit=2` dają dokładnie 2 produkty z kompletem kategorii
      (LIMIT na produktach, nie na wierszach złączenia);
    - wersja i pola zgodne z zapisem; produkt bez zdjęć ma `mainImage === null`;
    - liczba zapytań SQL na paczkę jest stała (middleware DBAL albo logger zapytań);
    - 5000 produktów wstawionych przez SQL z `limit=500`: przyrost szczytu pamięci po przejściu wszystkich paczek
      mieści się w ustalonym limicie (wartość w teście z komentarzem) i nie rośnie z liczbą paczek.
- **Krok 2: FAIL. Krok 3: migracja (`make -C apps/api migration`), rekord, repozytorium, port i implementacja DBAL
  (jedno zapytanie z CTE: najpierw `LIMIT` na `catalog_product`, potem złączenia i agregacja).
  Krok 4: PASS (`make -C apps/api check test`).**
- **Krok 5: Commity** `feat(api): version catalog products` i
  `feat(api): read products for the search index in id-ordered pages`

### Task 13B: Indeks Elasticsearch

Po merge'u 13A. Zachowanie opisuje sekcja „Indeks Elasticsearch” specyfikacji, razem z ograniczeniami
przebudowy synchronicznej. Zależności z sekcji „Zależności” specyfikacji; `php-http/discovery` z
`allow-plugins: false`, a `Psr18Client` wstrzyknięty jawnie.

**Pliki:**
- Utwórz:
  `src/Catalog/Application/Search/{ProductDocument,ProductDocumentBuilder,SearchResult,IndexOutcome,BulkResult,ActivationResult,RebuildReport,SearchUnavailable,SearchIndexError,ProductSearchIndexRebuilder}.php`,
  `src/Catalog/Application/Port/{ProductIndexer,ProductSearch,SearchIndexMaintenance,SearchIndexRebuildLock}.php`,
  `src/Catalog/Infrastructure/Search/{ElasticsearchClientFactory,ElasticsearchErrorClassifier,ProductIndexDefinition,ElasticsearchProductIndexer,ElasticsearchProductSearch,ElasticsearchSearchIndexMaintenance}.php`,
  `src/Catalog/Infrastructure/Persistence/Doctrine/PostgresSearchIndexRebuildLock.php`,
  `src/Catalog/UI/Console/{SetupSearchIndexCommand,ReindexCommand}.php`,
  `tests/Unit/Catalog/Search/{ProductDocumentBuilderTest,ProductSearchIndexRebuilderTest}.php`,
  `tests/Integration/Catalog/Search/{ElasticsearchClientTest,SearchIndexSetupTest,ProductIndexerTest,ProductSearchTest,SearchIndexMaintenanceTest,SearchIndexRebuildTest}.php`,
  `tests/Support/Search/ResponseDroppingHttpClient.php` (dekorator PSR-18: wysyła żądanie, potem zgłasza błąd sieci)
- Modyfikuj: `composer.json`, `composer.lock`, `config/services.yaml` (parametry: alias, shardy, repliki, paczka,
  limity czasu), `infra/docker-compose.yml` (env `api`: `ELASTICSEARCH_URL`), `apps/api/Makefile` (`init`:
  `catalog:search-index:setup`; `init-test`: `catalog:search-index:setup --recreate`), `apps/api/CLAUDE.md`
  (cele `init` i `init-test`), `.github/workflows/ci.yml` (usługa `elasticsearch` przed testami)

**Interfejsy:**
- Konsumuje: `ProductSearchSource` i `ProductSearchSnapshot` (zadanie 13A).
- Produkuje:
  - `ProductDocument(string $id, int $version, string $name, string $slug, string $shortDescription, string $mainCategoryId, list<string> $categoryIds, list<string> $categoryAncestorIds, ?array{key: string, alt: string} $mainImage)`
  - `ProductDocumentBuilder::build(ProductSearchSnapshot $s): ProductDocument`
  - `ProductIndexer { index(ProductDocument $d): IndexOutcome; countDocuments(): int }`; `IndexOutcome`:
    `Indexed`, `Stale`
  - `ProductSearch { search(?string $categoryId, int $page, int $perPage): SearchResult }`;
    `SearchResult(list<ProductDocument> $items, int $total)`
  - `SearchIndexMaintenance`:
    - `setup(): list<string>` zwraca osierocone indeksy;
    - `recreate(): void`;
    - `activeIndex(): string`;
    - `createIndex(): string`;
    - `write(string $index, list<ProductDocument> $documents): BulkResult` z wynikiem każdej pozycji;
    - `verify(string $index, int $expectedDocuments): void` rzuca `SearchIndexError`;
    - `activate(string $index, string $expectedCurrent): ActivationResult` (`Activated`, `NotActivated`, `Unknown`);
    - `deleteIndex(string $index): void` odmawia dla celu aliasu.
  - `SearchIndexRebuildLock { tryAcquire(): bool; release(): void }`
  - `ProductSearchIndexRebuilder { rebuild(int $batchSize): RebuildReport; reset(): void }`
  - wyjątki `SearchUnavailable` (przejściowy) i `SearchIndexError` (trwały)

- **Krok 1: Testy**
  - `ProductDocumentBuilderTest`:
    - `categoryAncestorIds` to suma ścieżek bez powtórzeń (dwie kategorie we wspólnym poddrzewie);
    - `categoryIds` zaczyna się od głównej;
    - wersja przepisana ze snapshotu;
    - `mainImage` jest `null` bez zdjęć.
  - `ProductSearchIndexRebuilderTest` (fake'i portów):
    - zajęta blokada: brak zmian;
    - błąd paczki, wynik `Stale` albo nieudana weryfikacja: usunięty nowy indeks, brak przepięcia, raport
      nieudany;
    - `NotActivated`: usunięty nowy indeks;
    - `Unknown`: nic nie usunięte, raport nieznany;
    - `Activated` z błędem usunięcia starego indeksu: sukces z ostrzeżeniem;
    - paczki czytane kursorem `afterId` do pustej listy;
    - blokada zwalniana w każdej ścieżce;
    - `reset()` przepina na pusty indeks.
  - `ElasticsearchClientTest`:
    - nieosiągalny host daje `SearchUnavailable` w czasie poniżej 2 s;
    - klasyfikacja odpowiedzi 429, 503 i 400 (przejściowy, przejściowy, trwały).
  - `SearchIndexSetupTest`:
    - `setup()` dwa razy bez błędu;
    - zwykły indeks o nazwie aliasu daje błąd bez zmian;
    - alias na dwóch indeksach daje błąd;
    - inny `definition_hash` daje błąd z instrukcją;
    - osierocony indeks jest zgłoszony, a nie usunięty;
    - `recreate()` czyści i tworzy od nowa.
  - `ProductIndexerTest`:
    - `index` z wersją 2, potem 1: zostaje 2, wynik `Stale`;
    - dwa razy ta sama wersja: `countDocuments() === 1`, drugi `Stale`, bez wyjątku;
    - zapis bez aliasu jest odrzucony i nie tworzy indeksu (`require_alias`);
    - bulk z jedną pozycją z błędem trwałym (np. slug ponad limit termu keyword) i pozostałymi poprawnymi daje
      wyniki per pozycja, a poprawne dokumenty są zapisane.
  - `ProductSearchTest`, drzewo R > C > L i osobny korzeń X; produkty w L, w C i w X, a produkt z L ma też
    kategorię dodatkową X:
    - `search(R)` daje 2, `search(L)` daje 1, `search(X)` daje 2, `search(null)` daje 3;
    - nazwy `Żel`, `buty`, `Ajax` sortują się jako `Ajax`, `buty`, `Żel`;
    - `perPage=2, page=2` daje 1 element i `total=3`; `page=99` daje `[]` i `total=3`;
    - przypadki okna wyników: `from` na końcu okna oraz `from + size` przekraczające okno (na indeksie testowym
      z obniżonym `max_result_window`).
  - `SearchIndexMaintenanceTest`:
    - `activate` przy aliasie wskazującym inny indeks niż oczekiwany daje `NotActivated` bez zmian;
    - `activate` z `ResponseDroppingHttpClient` daje `Activated` po odczycie stanu, a nowy indeks istnieje;
    - `deleteIndex` celu aliasu odmawia;
    - `verify` przy złej liczbie dokumentów albo innym hashu rzuca `SearchIndexError`.
  - `SearchIndexRebuildTest`, prawdziwe ES i Postgres:
    - 7 produktów i `--batch-size=3`: alias na nowym indeksie, stary usunięty, 7 dokumentów z wersjami;
    - druga przebudowa przy blokadzie wziętej na osobnym połączeniu kończy się kodem 1 bez zmian;
    - `catalog:reindex` zwraca kod 0, 1 i 2 w odpowiednich przypadkach.
- **Krok 2: FAIL. Krok 3: zależności, implementacja, komendy, Makefile, compose i CI.
  Krok 4: PASS (`make -C apps/api check test`).**
- **Krok 5: Sprawdź na stosie:**
  - świeży projekt Compose: `make up` tworzy alias i indeks;
  - drugie `make up` nic nie zmienia;
  - `make -C apps/api init` przy zatrzymanym ES kończy się kodem różnym od 0 w czasie kilku sekund;
  - ręcznie utworzony zwykły indeks `products` przed `init` daje czytelny błąd.
- **Krok 6: Commity** (po jednej zmianie logicznej):
  - `build(api): add the Elasticsearch client`;
  - `feat(api): define the product search index`;
  - `feat(api): index and search product documents`;
  - `feat(api): rebuild the product search index with an alias switch`.

### Task 14: Outbox, Messenger i RabbitMQ

**Pliki:**
- Utwórz: `config/packages/messenger.yaml`, `config/packages/test/messenger.yaml` (transporty `in-memory://`),
  `config/packages/test_messaging/{messenger,dama_doctrine_test_bundle}.yaml` (środowisko kernela z prawdziwymi
  transportami: `outbox` Doctrine w `shop_test`, `events` AMQP na vhoście `test`, `failed` Doctrine),
  `src/Shared/Infrastructure/Messaging/{MessengerDomainEventPublisher,RelayDomainEventToBroker}.php`,
  `migrations/Version…CreateMessengerTables.php`, `tests/Unit/Shared/Messaging/MessengerRoutingTest.php`,
  `tests/Integration/Shared/Messaging/{MessagingKernelTestCase,OutboxAtomicityTest,OutboxRelayTest}.php`
- Modyfikuj: `Dockerfile` (rozszerzenie `pcntl`), `infra/docker-compose.yml` (zmienne RabbitMQ w `api`; usługa `worker`:
  `bin/console messenger:consume outbox events`, health check procesu, `restart: unless-stopped`), `Makefile`
  (`up` startuje `worker` dopiero po `init`), `config/bundles.php` (bundle testowe także w `test_messaging`),
  `config/packages/{framework,doctrine,monolog}.yaml` i `config/services.yaml` (`when@test_messaging` jak
  `when@test`), `.github/workflows/ci.yml` (usługa `rabbitmq` i jednorazowy `rabbitmq-init` przed testami),
  `tests/Support/{TestMessengerController,TestMessageHandler}.php` (jawna szyna `command.bus`)

**Interfejsy:**
- Konsumuje: `DomainEventPublisher` (zadanie 9).
- Produkuje: szyny i transporty ze specyfikacji; routing `DomainEvent` → `outbox`; `DomainEventPublisher`
  → `MessengerDomainEventPublisher`; `RelayDomainEventToBroker` z
  `#[AsMessageHandler(bus: 'event.bus', fromTransport: 'outbox')]`, który ponownie wysyła zdarzenie
  z `TransportNamesStamp(['events'])`; ponowienia `events` 3 × (1 s, mnożnik 3), `outbox` 30 × (1 s, mnożnik 2,
  najwyżej 60 s), transport `failed`; `worker`.

- **Krok 1: Testy**
  - `MessengerRoutingTest` (kernel testowy, transporty in-memory): publikacja `ProductCreated` daje 1 wiadomość
    na `outbox` i 0 na `events`; po konsumpcji `outbox` jest 1 na `events`, a `outbox` jest pusty; po konsumpcji
    `events` nic nie trafia ponownie na `outbox` ani `events`, a `failed` jest pusty.
  - `OutboxAtomicityTest` (kernel `test_messaging`): w `wrapInTransaction` zapis produktu, publikacja zdarzenia
    i wyjątek dają 0 produktów, 0 wierszy `outbox` (liczone w tym samym połączeniu) i 0 wiadomości w RabbitMQ;
    bez wyjątku jest 1 produkt, 1 wiersz i nadal 0 wiadomości w RabbitMQ.
  - `OutboxRelayTest` (kernel `test_messaging`, kolejka `events` czyszczona przed testem): konsumpcja `outbox`
    publikuje zdarzenie w RabbitMQ i usuwa wiersz; przy nieosiągalnym brokerze każda próba zostawia jeden wiersz
    z kolejnym licznikiem ponowień i opóźnieniem 1, 2, 4 … 32 s, potem 60 s, 31. próba przenosi zdarzenie do
    `failed`, a `messenger:failed:retry` po powrocie brokera publikuje je w RabbitMQ; publikacja odrzucona przez
    brokera (polityka kolejki `reject-publish`, nack) zostawia wiersz z ponowieniem; przekaźnik, który padł po
    publikacji, a przed ackiem, publikuje zdarzenie drugi raz po upływie `redeliver_timeout`.
- **Krok 2: FAIL. Krok 3: konfiguracja, publisher, przekaźnik, migracja. Krok 4: PASS (`make -C apps/api check test`).**
- **Krok 5: Sprawdź**: `make up` kończy się kodem 0, `worker` jest `healthy`, `doctrine:migrations:status` nie
  pokazuje brakujących migracji. Przy działającym `worker` uruchom testy integracyjne: logi `worker` nie pokazują
  żadnej obsłużonej wiadomości, a `rabbitmqctl list_queues -p /` nie zmienia się w trakcie testów. Ścieżki błędu:
  nieudany `init` (`make up RUN=false`) kończy `make up` kodem różnym od 0 bez startu `worker`; zatrzymany RabbitMQ
  restartuje `worker`, który po powrocie brokera znów jest `healthy`; `docker compose stop worker` kończy się
  w kilka sekund kodem 0 (SIGTERM, bez SIGKILL).
- **Krok 6: Commit** `feat(api): publish domain events through a transactional outbox`

### Task 15: Endpointy katalogu i OpenAPI sklepu

Zadanie jest realizowane w dwóch PR-ach. Część 1 obejmuje drzewo kategorii (`GET /api/v1/categories`), konfigurację
OpenAPI z obszarami `default` i `admin`, cel `openapi` i krok „Contract freshness” w CI. Część 2 to endpoint
listy produktów (`ProductSearch` z zadania 13B), który dopisuje do wyeksportowanej specyfikacji `/api/v1/products`.

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
- Konsumuje: `ProductSearch` i `SearchIndexError` (zadanie 13B), `ImageUrlGenerator` i `ImageSize` (zadanie 12), `CategoryRepository`.
- Produkuje: `ListProducts(?string $categoryId, int $page, int $perPage)` → `ProductPage(list<ProductListItem> $items, int $page, int $perPage, int $total)`;
  `ImageView(string $alt, string $size, string $aspectRatio, array<string, list<array{width: int, url: string}>> $variants)`;
  `GetCategoryTree` → `list<CategoryNode(string $id, string $name, list<CategoryNode> $children)>`; schematy
  OpenAPI `ProductPage`, `ProductListItem`, `ProductImage`, `CategoryNode`, `Problem`. `SearchUnavailable`
  mapowany na 503.

- **Krok 1: Testy**
  - `ListProductsHandlerTest`: produkt bez zdjęcia ma `image === null`; ze zdjęciem: `size === 'listing'`,
    `aspectRatio === '1:1'`, jedyny klucz `variants` to `webp`, z szerokościami `[256, 384, 512, 768]`
    rosnąco.
  - `GetCategoryTreeHandlerTest`: drzewo zagnieżdżone; rodzeństwo posortowane po nazwie z polską kolacją
    (`Collator('pl_PL')`: `Łyżwy` po `Lody`).
  - `ProductsEndpointTest` (Foundry, dokumenty w indeksie testowym): kształt odpowiedzi jak w specyfikacji; filtr
    po kategorii najwyższego poziomu obejmuje produkty z podkategorii; `page=999` daje 200, `items === []` i prawdziwe `total`;
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
    - category: bieganie/buty/szosowe     # slug path of the main category
      additionalCategories: [ … ]          # optional slug paths, other branches
      productType: Buty do biegania
      taxCategory: standard
      brands: [ … ]                        # fictional house brands
      models: [ { name: …, description: … } ]
      colors: [ … ]
      images: [ { file: …, alt: … } ]      # pool for this template
  ```
  Nazwa produktu to `{productType} {brand} {model} {color}`, a slug to transliteracja nazwy (bez polskich znaków).

- **Krok 1: Przygotuj szkic `catalog.yaml`**: 7 kategorii najwyższego poziomu ze specyfikacji, drzewo na 3–4 poziomy
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
- Konsumuje: `CreateCategory` i `CreateProduct` (zadanie 10, z kategoriami dodatkowymi), `ProductImageStorage` (zadanie 12),
  `ProductSearchIndexRebuilder::reset()` i `ProductIndexer` (zadanie 13B).
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
    produkt nie ma zdjęć, a pozostałe dostają zdjęcie z puli `pool[index % count]`; rodzic występuje przed dzieckiem;
    kategorie dodatkowe szablonu trafiają do produktów jako id kategorii, a nieznana ścieżka kategorii dodatkowej
    rzuca wyjątek z tą ścieżką.
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
- Utwórz: `apps/store/src/api/{client.ts,schema.d.ts}`, `apps/store/src/catalog/{load-catalog.ts,image-props.ts,ProductPhoto.tsx,ProductTile.tsx,ProductGrid.tsx,CategoryTree.tsx,Pagination.tsx}`,
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
  `buildImageProps(image: components['schemas']['ProductImage'], sizes: string): { src: string; srcSet: string; sizes: string; alt: string; width: number; height: number }`
  (zwykły `<img srcset>`, bez `<picture>`);
  `loadCatalog(params: { category?: string; page?: string }): Promise<{ kind: 'ok'; categories; products } | { kind: 'invalid-filter' }>`
  (inne błędy rzuca dalej, więc obsługuje je `error.tsx`).

- **Krok 1: Wygeneruj klienta**: `make api-client` tworzy `src/api/schema.d.ts`.
- **Krok 2: Testy**
  - `image-props.test.ts`: `srcSet === 'u256 256w, u384 384w, u512 512w, u768 768w'` z wariantów WebP;
    `src` to wariant 768; `sizes` i `alt` przekazane bez zmian; `width`/`height` z `aspectRatio`.
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
  - siatka ze zdjęciami, w zakładce sieci żądania `.webp` do imgproxy,
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

### Task 27: Indeksowanie produktu po zdarzeniu

**Pliki:**
- Utwórz: `src/Catalog/Application/EventHandler/IndexProduct.php`,
  `tests/Integration/Shared/Messaging/EventFlowTest.php`
- Modyfikuj: `tests/Unit/Shared/Messaging/MessengerRoutingTest.php` (szpieg `ProductIndexer`, repozytoria
  in-memory)

**Interfejsy:**
- Konsumuje: szyny, transporty i przekaźnik (zadanie 14), komendy (zadanie 10, w tym samym PR),
  `ProductSearchSource` (zadanie 13A), `ProductIndexer` i `ProductDocumentBuilder` (zadanie 13B). `IndexProduct`
  czyta produkt z wersją przez `ProductSearchSource`, do którego to zadanie dodaje odczyt po id tym samym zapytaniem
  co `page`. `IndexOutcome::Stale` kończy obsługę bez ponowienia, `SearchUnavailable` jest ponawiany, a
  `SearchIndexError` trafia od razu do `failed` (`UnrecoverableMessageHandlingException`).
- Produkuje: `IndexProduct` z `#[AsMessageHandler(bus: 'event.bus', fromTransport: 'events')]`.

- **Krok 1: Testy**
  - `MessengerRoutingTest`: po konsumpcji `outbox` szpieg `ProductIndexer` nie został wywołany; po konsumpcji
    `events` szpieg dostał dokładnie jedno wywołanie z tym `productId`, a `outbox` i `events` są puste.
  - `EventFlowTest` (kernel `test_messaging`, jawnie prawdziwe transporty: `outbox` Doctrine i `events` AMQP na
    vhoście `test`, ES na żywo): `CreateProduct` na `command.bus`, potem
    `messenger:consume outbox events --limit=2 --time-limit=10`, a dokument produktu jest w indeksie testowym.
    Przed testem kolejka `events` na vhoście `test` jest czyszczona.
- **Krok 2: FAIL. Krok 3: handler. Krok 4: PASS (`make -C apps/api check test`).**
- **Krok 5: Commit** `feat(api): index products on ProductCreated`

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
