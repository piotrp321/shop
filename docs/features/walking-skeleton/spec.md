# Walking skeleton

Pierwszy krok zakresu MVP. Szkielet przeprowadza najcieńszy działający kawałek przez każdą warstwę systemu:
wszystkie usługi startują jedną komendą, klient i pracownik logują się przez Keycloak, lista produktów ze
zdjęciami przechodzi drogę Postgres → outbox → RabbitMQ → Elasticsearch → API → sklep (obrazy z Garage przez
imgproxy), a fronty rozmawiają z API wyłącznie przez klientów TS wygenerowanych z OpenAPI.

## Cel i kryteria sukcesu

Na czystym klonie repozytorium, z zainstalowanym tylko Dockerem:

1. `make up` stawia cały stos i kończy się dopiero, gdy wszystkie usługi są zdrowe.
2. `make seed` ładuje dane demo (300–500 produktów sport/outdoor w drzewie kategorii, 20–30 zdjęć) i kończy się,
   gdy indeks w Elasticsearch jest zgodny z Postgresem.
3. Sklep pokazuje drzewo kategorii i siatkę produktów ze zdjęciami (WebP z imgproxy). Kliknięcie kategorii
   zawęża listę razem z podkategoriami.
4. Klient loguje się w sklepie (realm `shop`), może się zarejestrować z weryfikacją e-mail (Mailpit), widzi
   „Zalogowany jako …” i może się wylogować.
5. Pracownik loguje się w panelu hasłem i TOTP (realm `backoffice`) i widzi dashboard na prawdziwych danych z API.
   Sekcję „Stan systemu” widzi tylko rola `admin`.
6. `make check`, `make test` i `make e2e` przechodzą dla wszystkich aplikacji. Lekkie CI w GitHub Actions
   przechodzi na PR.

Kolejne kroki MVP mają tylko dokładać contexty i ekrany, bez przebudowy infrastruktury.

## Poza zakresem

Varnish, koszyk, zamówienia, płatności, stany magazynowe, ceny i stawki VAT (Pricing), zarządzanie katalogiem
w panelu i upload zdjęć, wyszukiwanie pełnotekstowe, strona produktu, SEO (metadane, JSON-LD, sitemap, adresy
`/c/<slug>`), docelowy projekt wizualny sklepu, correlation ID, rate limiting w API, pełne CI (cały stos,
testy integracyjne, E2E), deployment.

## Środowisko uruchomieniowe

### Usługi

Stos jest zdefiniowany w `infra/docker-compose.yml`, dołączanym (`include`) przez `compose.yaml` w korzeniu repo,
więc `docker compose` działa z korzenia i czyta `.env` z korzenia. Wersje główne poniżej, dokładne wersje obrazów
są przypięte w pliku compose.

| Usługa | Obraz | Rola | Health check |
|---|---|---|---|
| `postgres` | PostgreSQL 18 | bazy `shop`, `shop_test` (API, właścicielem jest rola `shop`) i `keycloak` | `pg_isready` |
| `redis` | Redis 8 | sesje sklepu; sonda w dashboardzie | `redis-cli ping` |
| `elasticsearch` | Elasticsearch 9, jeden węzeł, security wyłączone lokalnie, `ES_JAVA_OPTS=-Xms512m -Xmx512m` | indeks `products` | `_cluster/health` (yellow lub green) |
| `rabbitmq` | RabbitMQ 4 z wtyczką management | transport `events` Messengera; vhost `/` dla dev, vhost `test` dla testów | `rabbitmq-diagnostics ping` jako użytkownik `rabbitmq` (sonda jako root tworzy na świeżym wolumenie `.erlang.cookie`, którego serwer nie może odczytać) |
| `rabbitmq-init` | jednorazowy | idempotentnie, przez Management API: vhost `test` i uprawnienia użytkownika aplikacji | kończy się kodem 0 |
| `keycloak` | Keycloak 26, `start-dev --import-realm`, baza `keycloak` w Postgresie, ograniczony heap | realmy `shop` i `backoffice` | `/health/ready` na porcie zarządzania |
| `garage` | Garage 2 | bucket `product-images` (i `product-images-test`) z oryginałami zdjęć | `garage status` (obraz nie ma powłoki ani curl, a admin API `/health` zwraca 503 do czasu zastosowania układu przez `garage-init`) |
| `garage-init` | Garage 2 (jednorazowy) | idempotentnie: układ węzła, import klucza z `.env.local`, buckety, uprawnienia | kończy się kodem 0 |
| `imgproxy` | imgproxy 3, źródło S3 → Garage | przetwarzanie obrazów na żądanie | `imgproxy health` |
| `mailpit` | Mailpit | SMTP dla realmu `shop` (weryfikacja e-mail, reset hasła) | `/readyz` |
| `api` | własny obraz FrankenPHP, PHP 8.5, target `dev` (z SPX) | HTTP API | `GET /health` |
| `worker` | obraz z tego samego Dockerfile i targetu co `api`, własny katalog cache (`APP_CACHE_DIR=/tmp/cache`) | `messenger:consume outbox events` | proces konsumenta działa |
| `store` | Node 24, `next dev` | sklep | `GET /` |
| `admin` | Node 24, `vite dev` | panel | `GET /` |
| `e2e` | obraz Playwright, profil `e2e` | testy E2E, uruchamiany tylko przez `make e2e` | — |

Aplikacje zależą od usług przez `depends_on: condition: service_healthy`. `make up` używa `docker compose up -d --wait` dla wszystkich usług poza `rabbitmq-init` i `worker`. Potem uruchamia `rabbitmq-init` przez `docker compose run --rm` (`up --wait` traktuje zakończoną usługę jednorazową, na którą nic nie czeka, jako błąd), krok inicjalizacji (niżej) i dopiero na końcu `worker`, bo bez migracji nie ma tabel transportu `outbox`. `worker` jest tworzony od nowa przy każdym `make up` (`--force-recreate`), więc działa na kodzie i konfiguracji skompilowanych przez `init`. Ma własny katalog cache poza bind mountem: gdy `api` przebudowuje kontener DI we wspólnym `var/cache` (po zmianie kodu), usuwa pliki, które długo działający worker wczytuje leniwie, a worker kończy się błędem na pierwszej wiadomości, której nie przetworzy przed `redeliver_timeout` (300 s).

Obraz API zawiera rozszerzenia `pdo_pgsql`, `amqp`, `redis`, `intl`, `opcache`, `pcntl` (worker kończy bieżącą
wiadomość i zatrzymuje się na SIGTERM), a target `dev` dodatkowo SPX.
Entrypoint `api` w trybie dev instaluje tylko zależności, jeśli zmienił się lock.

### Inicjalizacja bazy i indeksu

Migracje i indeks Elasticsearch nie powstają przy starcie kontenera (`api` ani `worker`). Robi to osobny,
idempotentny krok, uruchamiany jawnie:

| Gdzie | Krok |
|---|---|
| lokalnie | `make up` po starcie usług, a przed startem `worker`, wywołuje `make -C apps/api init`: `doctrine:migrations:migrate` i `catalog:search-index:setup` |
| testy na usługach stosu | `make -C apps/api init-test`: te same komendy w środowisku `test` (baza `shop_test`, alias `test_products`), przy czym indeks testowy powstaje od nowa (`catalog:search-index:setup --recreate`); cel `test` api wywołuje go przed testami |
| CI | usługi potrzebne testom startują przed `make -C apps/api test`, który sam wywołuje `init-test` |
| wdrożenie (Kubernetes) | osobny Job z tymi samymi komendami co `init`, przed wdrożeniem nowej wersji aplikacji |

`node_modules` i `vendor` są w wolumenach Dockera.

### Adresy: przeglądarka i kontenery

- Przeglądarka używa `http://localhost:<port>`. Kontenery rozmawiają ze sobą po nazwach usług.
- Keycloak: `KC_HOSTNAME=http://localhost:${KEYCLOAK_PORT}` i `KC_HOSTNAME_BACKCHANNEL_DYNAMIC=true`. Wystawca
  tokenów (`iss`) jest zawsze adresem publicznym, a API i serwer sklepu pobierają discovery, JWKS i wymieniają
  kod na token przez `http://keycloak:8080`.
- API sprawdza `iss` z publicznym adresem Keycloak i buduje adresy obrazów z publicznym adresem imgproxy.
  Adresy Keycloak dostaje z env: `KEYCLOAK_PUBLIC_URL` (wystawca) i `KEYCLOAK_INTERNAL_URL` (discovery i JWKS).
- Domyślne porty na hoście: sklep 3000, panel 5173, API 8000, Keycloak 8080, imgproxy 8081, Mailpit 8025,
  RabbitMQ management 15672. Każdy port jest zmienną w `.env` i jest wystawiony tylko na `127.0.0.1`.

### Profile i sekrety

- `.env` (w `.gitignore`) to lokalny profil Compose: `COMPOSE_PROJECT_NAME` i porty. Dzięki temu w osobnych
  worktree może działać kilka stosów naraz. Wszystkie zmienne mają wartości domyślne w compose i są opisane
  w `.env.example`.
- `.env.local` (w `.gitignore`) zawiera sekrety lokalne. Tworzy go `infra/scripts/init-env.sh` przy pierwszym
  `make up`, jeśli plik nie istnieje: klucz i sól imgproxy, klucz dostępu i sekret Garage, sekret klienta `store`,
  sekret sesji sklepu, hasło administratora Keycloak, hasła do Postgresa (superużytkownik, role `keycloak` i `shop`)
  i RabbitMQ. Gdy plik istnieje, skrypt sprawdza, że ma wszystkie klucze; brakujący klucz kończy `make up` błędem
  z jego nazwą i instrukcją odtworzenia stosu (usunąć `.env.local` i wolumeny projektu Compose). Skrypt niczego
  nie dopisuje, bo nowy sekret zwykle wymaga też nowego wolumenu (np. rola `shop` w Postgresie).
- Usługi, które potrzebują sekretów, wczytują go przez `env_file: {path: .env.local, required: true}`.
  Plik compose nie podstawia sekretów przez `${...}`. Brak pliku kończy się błędem Compose, a nie pustą wartością.
- Lokalnie jeden `.env.local` trafia do wszystkich usług, które potrzebują sekretów. Podział sekretów per usługa
  (np. klucz i sól imgproxy tylko dla `api` i `imgproxy`) obowiązuje przy wdrożeniu (Kubernetes), nie lokalnie.
- W repo nie ma żadnego sekretu. Hasła i sekrety TOTP kont demo są jawnymi danymi demo opisanymi w README.

### Wymagania sprzętowe

README podaje minimalną i zalecaną ilość pamięci dla Docker Desktop, zmierzoną przez `docker stats`
po `make up`, `make seed` i `make e2e`.

### Makefile

Wszystkie cele uruchamiają komendy przez zmienną `RUN ?= docker compose exec`. CI nadpisuje ją na
`docker compose run --rm --no-deps -T`, więc analiza statyczna i testy, które nie potrzebują usług, działają bez
całego stosu.

| Makefile | Cele |
|---|---|
| główny | `up`, `down`, `logs`, `ps`, `seed`, `check`, `test`, `e2e`, `api-client`, `keycloak-reset` |
| `apps/api` | `check`, `test`, `test-unit`, `init`, `init-test`, `migrate`, `migration`, `openapi`, `shell` |
| `apps/store` | `check`, `test`, `test-unit`, `shell` |
| `apps/admin` | `check`, `test`, `shell` |

- Główne `check` i `test` uruchamiają cele wszystkich trzech aplikacji.
- `test` uruchamia wszystkie testy aplikacji, także te, które potrzebują usług stosu. `test-unit` nie wymaga żadnej
  usługi.
- `e2e` wymaga wcześniejszego `make up` i `make seed`.
- `keycloak-reset` usuwa bazę `keycloak` i restartuje Keycloak, który importuje realmy od nowa.

## Keycloak

Realmy są w `infra/keycloak/realms/shop.json` i `infra/keycloak/realms/backoffice.json`, importowane przy starcie,
gdy realmu jeszcze nie ma. Adresy (`${STORE_URL}`, `${ADMIN_URL}`) i sekret klienta (`${STORE_CLIENT_SECRET}`)
są placeholderami podstawianymi przez Keycloak z env.

| | realm `shop` | realm `backoffice` |
|---|---|---|
| Klient OIDC | `store`: confidential, Authorization Code + PKCE S256, tylko standard flow | `admin`: public, Authorization Code + PKCE S256, tylko standard flow |
| Przekierowania | `${STORE_URL}/auth/callback`; po wylogowaniu `${STORE_URL}/` | `${ADMIN_URL}/*`; web origin `${ADMIN_URL}` |
| Audience w access tokenie | `shop-api` (mapper audience) | `backoffice-api` (mapper audience) |
| Rejestracja | włączona, login e-mailem, weryfikacja e-mail wymagana | wyłączona |
| Reset hasła | włączony | wyłączony |
| SMTP | `mailpit:1025`, nadawca `sklep@example.com` | brak |
| 2FA | brak | własny browser flow z formularzem OTP jako krok wymaganym; polityka TOTP: SHA1, 6 cyfr, 30 s; nowe konto dostaje wymaganą akcję `CONFIGURE_TOTP` |
| Sesja SSO | bezczynność 30 min; z „zapamiętaj mnie” bezczynność 30 dni | bezczynność 15 min, maksymalnie 8 h |
| Brute force detection | włączone | włączone |
| Role | brak | role realmu `admin`, `support`, `warehouse`, `marketing` |

Konta demo:

| Realm | Login | Rola | Uwagi |
|---|---|---|---|
| `shop` | `jan.kowalski@example.com` | — | e-mail zweryfikowany |
| `shop` | `anna.nowak@example.com` | — | e-mail zweryfikowany |
| `backoffice` | `admin` | `admin` | hasło + wgrany sekret TOTP |
| `backoffice` | `support` | `support` | hasło + wgrany sekret TOTP |
| `backoffice` | `warehouse` | `warehouse` | hasło + wgrany sekret TOTP |
| `backoffice` | `marketing` | `marketing` | hasło + wgrany sekret TOTP |

Sekrety TOTP są stałe i jawne. README podaje dla każdego konta hasło, sekret i URI `otpauth://` do zeskanowania
w aplikacji uwierzytelniającej.

## API (`apps/api`)

### Moduły

| Moduł | Domain | Application | Infrastructure | UI |
|---|---|---|---|---|
| `Catalog` | `Category`, `Product`, value objecty, zdarzenie `ProductCreated`, interfejsy repozytoriów | komendy `CreateCategory`, `CreateProduct`; zapytania `ListProducts`, `GetCategoryTree`, `GetCatalogStatistics`; handler zdarzenia `IndexProduct`; przebudowa indeksu `ProductSearchIndexRebuilder`; porty `ProductSearch`, `ProductIndexer`, `ProductSearchSource`, `SearchIndexMaintenance`, `SearchIndexRebuildLock`, `ProductImageStorage`, `ImageUrlGenerator`, `DemoCatalogSource`, `CatalogReset`; ładowanie danych demo `DemoCatalogLoader` (przez `command.bus`); lista rozmiarów `ImageSize` | Doctrine (rekordy z atrybutami), adapter Elasticsearch, Flysystem → Garage, generator podpisanych adresów imgproxy, źródło danych demo z `catalog.yaml` (parsowanie, rozwijanie szablonów, UUID v5) i `CatalogReset` | `GET /api/v1/products`, `GET /api/v1/categories`, komendy konsoli |
| `Customer` | — | interfejs `AuthenticatedCustomer` (klient z tokena) | `CustomerUser` (implementuje `AuthenticatedCustomer`) i `CustomerUserProvider` (z tokena realmu `shop`) | `GET /api/v1/me` |
| `Backoffice` | — | zapytanie `GetDashboard`; porty `CatalogStatistics`, `SystemHealth`, `MessagingStatistics`; interfejs `AuthenticatedStaffMember` (pracownik z tokena) | `StaffUser` (implementuje `AuthenticatedStaffMember`) i `StaffUserProvider` (z tokena realmu `backoffice`), `DashboardVoter`, adaptery portów | `GET /api/v1/admin/me`, `GET /api/v1/admin/dashboard` |
| `Shared` | interfejs `DomainEvent` (`occurredAt()`), port `DomainEventPublisher`, interfejsy-znaczniki wyjątków `ProblemException`, `NotFound`, `Conflict`, `InvalidInput` | — | konfiguracja szyn Messengera, przekaźnik outboxa, problem details, nagłówki cache, `GET /health`, sondy usług | — |

`Backoffice` to moduł funkcji panelu, a nie context domenowy: pracownik nie ma własnego modelu, tożsamość i role
pochodzą z tokena.

Reguły Deptrac:

- Klasy wbudowane PHP (rdzeń i rozszerzenia, np. `\DateTimeImmutable`, `\InvalidArgumentException`) są dozwolone
  w każdej warstwie, także w `Domain` (warstwa `Native`).
- `Domain` modułu zależy tylko od `Shared\Domain` (poza klasami wbudowanymi PHP). Nie używa żadnego frameworka
  ani biblioteki.
- `Application` zależy od własnego `Domain` i `Shared\Domain`. Może używać atrybutów i interfejsów Symfony
  Messenger (warstwa `Vendor`).
- `Infrastructure` i `UI` zależą od własnego `Application` i `Domain` oraz `Shared\Domain` i `Shared\Infrastructure`.
- Jedyna dozwolona zależność między modułami: `X\Infrastructure` → `Y\Application` (np. adapter portu
  `CatalogStatistics` w `Backoffice` woła zapytanie `GetCatalogStatistics` z `Catalog`). Nikt nie importuje
  cudzego `Domain`.

### Model Catalog

**`Category`** (agregat)
- `CategoryId` (UUID), `CategoryName` (niepusta, do 100 znaków), `parentId` (`?CategoryId`), `CategoryPath`.
- Drzewo jako lista sąsiedztwa (`parent_id`) będąca źródłem prawdy oraz zmaterializowana ścieżka do zapytań
  o poddrzewo. Ścieżka to identyfikatory od korzenia do kategorii włącznie, w bazie jako tekst
  `/<id-korzenia>/…/<id>/` z indeksem `text_pattern_ops`. Poddrzewo: `path LIKE '/…/<id>/%'`.
- Tworzenie: `Category::createRoot(id, name)` albo `Category::createChild(id, name, parent)`. Ścieżkę liczy
  domena z ścieżki rodzica.
- Odtworzenie z zapisanego stanu: `Category::reconstitute(id, name, parentId, path)`. Odrzuca stan niespójny:
  ścieżka nie kończy się własnym id, rodzic nie jest przedostatnim elementem ścieżki albo korzeń ma rodzica.

**`Product`** (agregat)
- `ProductId` (UUID), `ProductName` (niepusta, do 200 znaków), `Slug` (`^[a-z0-9]+(-[a-z0-9]+)*$`, do 200 znaków,
  unikalny), `ShortDescription` (do 500 znaków, może być pusty), `ProductCategories`, `TaxCategory`,
  `ProductImages`.
- `ProductCategories`: dokładnie jedna kategoria główna i najwyżej 10 dodatkowych, wszystkie jako
  `CategoryId`. Kategoria nie powtarza się na liście, a główna nie jest jednocześnie dodatkową. `all()` zwraca
  główną, a po niej dodatkowe. Kolejność dodatkowych nie ma znaczenia biznesowego.
- `Product` i `Category` to niezależne agregaty. Odwołują się do siebie wyłącznie przez identyfikatory.
- `TaxCategory`: `standard`, `food`, `food_supplement`, `printed_publication`. Produkt ma kategorię podatkową,
  a nie stawkę.
- `ProductImages`: lista `ProductImage` (`ImageKey` – klucz oryginału w magazynie, `alt` niepusty, pozycja).
  `ImageKey` ma postać `originals/<sha256>.<rozszerzenie>`, gdzie rozszerzenie pochodzi z enumu
  `OriginalImageType` (`jpg`, `png`, `webp`, `avif`, `heic`).
  Najwyżej 10 zdjęć, pozycja zdjęcia równa jego indeksowi na liście (ciągła od 0), bez powtórzonego `ImageKey`.
  Pierwsze zdjęcie jest główne.
- `Product::create(...)` zapisuje w agregacie zdarzenie `ProductCreated(productId, occurredAt)`.
- `Product::reconstitute(...)` przyjmuje te same argumenty co `create` i odtwarza produkt z zapisanego stanu bez
  zapisywania zdarzeń.
- Naruszenie niezmiennika value objectu rzuca `\InvalidArgumentException`. To strażnik spójności modelu: dane
  od klienta API waliduje wcześniej warstwa aplikacji.
- Identyfikatory (`CategoryId`, `ProductId`) domena sprawdza formatem UUID w czystym PHP i zapisuje małymi
  literami.

Persystencja:

- `catalog_category`: `id`, `parent_id` (FK), `name`, `path` (tekst z indeksem `text_pattern_ops`).
- `catalog_product`: `id`, `slug` (ograniczenie unikalności), `name`, `short_description`, `primary_category_id`
  (NOT NULL, FK do `catalog_category`, indeks), `tax_category`, `images` (JSONB), `version` (BIGINT NOT NULL).
- `catalog_product_category`: dodatkowe kategorie produktu. `product_id` (FK do `catalog_product`, `ON DELETE
  CASCADE`) i `category_id` (FK do `catalog_category`), klucz główny (`product_id`, `category_id`) i indeks na
  `category_id` do wyszukiwania produktów po kategorii. Kategoria główna nie trafia do tej tabeli (niezmiennik
  `ProductCategories`). Kolejność kategorii dodatkowych nie jest zapisywana: odczyt zwraca je posortowane po
  `category_id`.
- API łączy się z bazą jako rola `shop`, właściciel baz `shop` i `shop_test`, nigdy jako superużytkownik
  `postgres`. Rolę i bazy tworzy skrypt inicjalizacji Postgresa przy pierwszym starcie pustego wolumenu. Połączenie
  składa się ze zmiennych: `SHOP_DB_HOST`, `SHOP_DB_NAME`, `SHOP_DB_USER` (jawne, w `environment` usługi `api`)
  i `SHOP_DB_PASSWORD` (`.env.local`). Testy używają tej samej roli i bazy `shop_test`.
- Domena nie jest mapowana przez Doctrine. Model persystencji to rekordy `CategoryRecord` i `ProductRecord`
  w `Catalog/Infrastructure/Persistence/Doctrine/Record`, mapowane atrybutami Doctrine, z identyfikatorami jako
  tekst UUID. Rekordy mają relacje między sobą: `CategoryRecord::parent` (ManyToOne do `CategoryRecord`),
  `ProductRecord::primaryCategory` (ManyToOne) i `ProductRecord::additionalCategories` (ManyToMany przez
  `catalog_product_category`). Klucze obce, tabelę łączącą i indeksy tworzy z tego Doctrine. Indeksy kluczy obcych
  tworzone automatycznie przez Doctrine zostają, także pokryty przez klucz główny indeks na
  `catalog_product_category.product_id`. Jedynym elementem schematu spoza mapowania jest klasa operatorów
  `text_pattern_ops` indeksu `path`, dopisana w migracji.
- Agregaty w domenie odwołują się do siebie wyłącznie przez identyfikatory. `DoctrineCategoryRepository`
  i `DoctrineProductRepository` mapują agregat na rekord przy zapisie, a przy odczycie odtwarzają agregat przez
  `reconstitute()`.
- Unikalność sluga gwarantuje baza. `ProductRepository::save()` zapisuje od razu (flush w transakcji szyny
  komend) i zamienia naruszenie ograniczenia unikalności sluga na `SlugAlreadyTaken` (409). Nie ma osobnego
  sprawdzania sluga przed zapisem. Zamknięty po tym błędzie EntityManager nie przechodzi do kolejnego żądania
  ani kolejnej wiadomości.
- `save()` w obu repozytoriach zapisuje agregat jako nowy albo nadpisuje istniejący o tym samym id (upsert).
  Tworzenia pilnują handlery: `CreateCategory` i `CreateProduct` odrzucają istniejące id wyjątkami
  `CategoryAlreadyExists` i `ProductAlreadyExists` (`Conflict`, 409). Gdy dwa równoczesne utworzenia z tym samym id
  oba przejdą sprawdzenie, repozytorium zamienia naruszenie klucza głównego na ten sam wyjątek.
- Handler `CreateProduct` sprawdza, że istnieją wszystkie kategorie produktu (`CategoryNotFound`, 404).
- Wersja produktu (`catalog_product.version`) należy do persystencji, nie do domeny: `Product` jej nie zna.
  Nowy produkt ma wersję 1. Każdy zapis, który zmienia wiersz produktu albo jego kategorie dodatkowe, zwiększa ją
  o 1 w tej samej transakcji. Wersję pilnuje `#[ORM\Version]` na `ProductRecord`. Doctrine nie zwiększa wersji
  przy zmianie wyłącznie kolekcji ManyToMany, więc robi to repozytorium w tej samej transakcji zapytaniem
  `UPDATE catalog_product SET version = version + 1 WHERE id = :id AND version = :expected`. Porównywana jest wersja
  odczytana w tej samej jednostce pracy (`get()`, a potem `save()` w jednym handlerze albo jednej transakcji).
  Zapis, w którym ten odczyt jest nieaktualny (równoległy zapis tego samego produktu), rzuca
  `ProductModifiedConcurrently` (`Conflict`, 409) i nie zmienia ani `catalog_product`, ani
  `catalog_product_category`. Stan odczytany we wcześniejszym żądaniu nie jest wykrywany, bo `Product` nie niesie
  wersji. Ochrona między żądaniami wymagałaby wersji w komendzie i nie jest teraz w zakresie. Dwa różne stany produktu nigdy nie
  mają tej samej wersji.
- Wersja chroni tylko dane z wiersza produktu i jego przypisań do kategorii. Pola dokumentu wyszukiwarki pochodzące
  z innych agregatów (ścieżki kategorii, w przyszłości ceny, dostępność, atrybuty) nie są nią objęte. Wymaga to
  osobnego wersjonowania całej projekcji wyszukiwania. Do tego czasu zmiana drzewa kategorii wymaga pełnej
  przebudowy indeksu (w szkielecie drzewo się nie zmienia).
- Wersje mogą się cofnąć tylko przy wyczyszczeniu albo odtworzeniu bazy. Seed zawsze resetuje też indeks, a po
  odtworzeniu bazy z kopii obowiązuje pełna przebudowa indeksu.

Identyfikatory nadaje wywołujący komendę (komendy przyjmują `id`). Domena nigdy nie wylicza identyfikatora
z innych pól. Seed używa UUID v5 ze sluga (opis niżej), przyszłe kontrolery będą nadawać UUID v7.

W szkielecie katalog nie ma edycji ani usuwania, istnieje tylko tworzenie.

### Zdarzenia: outbox → RabbitMQ → handler

Szyny Messengera: `command.bus` (middleware walidacji i `doctrine_transaction`, szyna domyślna), `query.bus`,
`event.bus` (zdarzenie bez odbiorcy nie jest błędem: `allow_no_handlers`).

Transporty:

| Transport | DSN | Rola |
|---|---|---|
| `outbox` | Doctrine, to samo połączenie co agregaty, `queue_name=outbox` | transactional outbox |
| `events` | AMQP (RabbitMQ), exchange i kolejka `events`, serializer JSON, `confirm_timeout` 5 s | dystrybucja zdarzeń do odbiorców |
| `failed` | Doctrine, `queue_name=failed` | wiadomości po wyczerpaniu ponowień |

`outbox` i `failed` dzielą tabelę `messenger_messages`, którą tworzy migracja (`auto_setup=false`). Worker odpytuje
je co sekundę (`use_notify: false`, migracja nie tworzy wyzwalacza NOTIFY). Połączenie
z RabbitMQ składa się ze zmiennych `RABBITMQ_HOST`, `RABBITMQ_USER`, `RABBITMQ_VHOST` (jawne, w `environment`
usług `api` i `worker`) oraz `RABBITMQ_DEFAULT_PASS` (`.env.local`).

Przepływ:

1. Handler komendy zapisuje agregat i publikuje jego zdarzenia na `event.bus`. Każdy `DomainEvent` jest
   kierowany na transport `outbox`, więc wiersz w outboxie powstaje w tej samej transakcji co agregat.
   Wycofanie transakcji usuwa oba.
2. `worker` konsumuje `outbox`. Jedyny handler z `from_transport: outbox` to przekaźnik, który wysyła to samo
   zdarzenie dalej z `TransportNamesStamp(['events'])`. Transport `events` czeka na potwierdzenie publikacji
   przez brokera (publisher confirms), więc wiersz outboxa dostaje ack dopiero, gdy RabbitMQ ma wiadomość.
   Nieudana publikacja to wyjątek przekaźnika i ponowienie wiersza outboxa.
3. `worker` konsumuje `events`. Handlery odbiorców mają `from_transport: events`, w szkielecie jest to
   `IndexProduct` dla `ProductCreated`.
4. Każdy handler zdarzenia ma jawny `from_transport`. Przekaźnik nigdy nie działa przy konsumpcji z `events`,
   a odbiorcy nigdy przy konsumpcji z `outbox`, więc nie ma pętli ani podwójnej obsługi.
5. Ponowienia (z domyślnym jitterem ±10 %), potem transport `failed`, gdzie zdarzenie czeka na
   `messenger:failed:retry`:
   - `events`: 3 próby z opóźnieniem 1 s i mnożnikiem 3,
   - `outbox`: 30 prób z opóźnieniem 1 s, mnożnikiem 2 i najwyżej 60 s, razem około 25 minut. Błąd przekaźnika to
     zawsze niedostępny albo odrzucający broker, więc restart RabbitMQ nie przenosi zdarzeń do `failed`.
     Ponowienie to nowy wiersz z późniejszym `available_at`.

Gwarancja dostarczenia to at-least-once. Pobrany wiersz outboxa ma `delivered_at` i zostaje w tabeli do ack.
Gdy przekaźnik padnie po publikacji, a przed ackiem, wiersz wraca po `redeliver_timeout` (300 s; przekazanie trwa
milisekundy plus najwyżej 5 s oczekiwania na potwierdzenie) i zdarzenie trafia
do RabbitMQ drugi raz. Tak samo RabbitMQ doręcza ponownie wiadomość bez ack odbiorcy. Kilka workerów nie bierze
tego samego wiersza (`SELECT … FOR UPDATE SKIP LOCKED`), ale kolejność zdarzeń nie jest gwarantowana.

Zdarzenia niosą tylko identyfikatory i czas. `IndexProduct` czyta aktualny stan produktu z wersją przez
`ProductSearchSource` i zapisuje dokument z tą wersją. Starszy albo powtórzony zapis Elasticsearch odrzuca, a handler
traktuje to jako obsłużoną wiadomość, bez ponowienia. Handler jest więc idempotentny i niezależny od kolejności
zdarzeń.
Błąd przejściowy (`SearchUnavailable`) ponawia Messenger według strategii `events`. Błąd trwały (`SearchIndexError`)
i produkt, którego nie da się odczytać (katalog nie ma usuwania, więc to niespójność), kończą się
`UnrecoverableMessageHandlingException`: wiadomość od razu trafia do `failed`.

### Indeks Elasticsearch

Elasticsearch jest wyłącznie modelem odczytu, odtwarzanym z Postgresa.

**Indeksy i alias**
- Aplikacja czyta i zapisuje przez alias (`products` w dev, `test_products` w testach, z parametru). Alias wskazuje
  dokładnie jeden fizyczny indeks `<alias>_<UTC rrrrmmddggmmssmmm>_<6 znaków hex>`, więc kolejne przebudowy nie
  kolidują nazwami.
- `ProductIndexDefinition` w kodzie definiuje ustawienia i mapping. Shardy i repliki pochodzą z parametrów
  (domyślnie 1 i 0). W `_meta.definition_hash` indeksu zapisany jest hash definicji (mapping i analiza, bez
  shardów i replik), z którym porównujemy indeks przy `setup` i przed przepięciem.
- Mapping ma `dynamic: strict`. Pola: `id` (keyword), `version` (long), `name` (text oraz `name.sort` typu
  keyword z normalizerem lowercase + asciifolding), `slug` (keyword), `short_description` (text, `index: false`),
  `main_category_id`, `category_ids` (keyword, wszystkie kategorie produktu, główna pierwsza),
  `category_ancestor_ids` (keyword, suma kategorii produktu i wszystkich ich przodków, bez powtórzeń),
  `main_image` (obiekt z `enabled: false`: `key`, `alt`).

**Zapis**
- Każdy zapis ma `version` dokumentu równe wersji produktu i `version_type=external`. Elasticsearch odrzuca zapis
  z wersją nie większą niż zapisana (409). To wynik „przestarzały”, a nie błąd: dotyczy zarówno starszego stanu,
  jak i powtórzenia tej samej wersji.
- Pojedynczy zapis (`ProductIndexer`) idzie przez alias z `require_alias=true`, więc nigdy nie utworzy indeksu
  o nazwie aliasu. Zapis paczki przy przebudowie idzie bulkiem bezpośrednio do wskazanego indeksu fizycznego.
- Wynik bulka jest sprawdzany pozycja po pozycji: zapisany, przestarzały, błąd przejściowy albo błąd trwały.
- Zapisy nie wymuszają odświeżenia indeksu.

**Wyszukiwanie**
- Filtr `term` na `category_ancestor_ids` w kontekście `filter`, sortowanie po `name.sort`, potem po `id`,
  `track_total_hits: true`.
- Okno wyników ES ma 10 000 pozycji. Gdy `from` sięga końca okna, zapytanie ma `size: 0` i zwraca pustą listę
  z prawdziwym `total`. Gdy `from + size` przekracza okno, `size` jest przycinany do końca okna.

**Błędy**
- `SearchUnavailable`: błąd przejściowy, czyli brak połączenia, przekroczony czas, 429, 502, 503, 504, odrzucenie
  z braku zasobów (`es_rejected_execution_exception`). Endpoint mapuje go na 503, a worker ponawia.
- `SearchIndexError`: błąd trwały, czyli pozostałe 4xx, odrzucony mapping, brak aliasu albo stan indeksów
  niezgodny z oczekiwanym. To błąd programisty albo konfiguracji: 500 w API, bez ponowień w workerze.
- Klient nie ponawia żądań. Limit połączenia 1 s. Limit odpowiedzi z parametrów: 2 s dla wyszukiwania,
  liczenia, pojedynczych zapisów i operacji na aliasach; 30 s dla bulka, tworzenia indeksu i odświeżania.

**`catalog:search-index:setup`**
- Gdy nie ma aliasu ani indeksu o jego nazwie: tworzy indeks fizyczny i alias. Wywołany drugi raz nie robi nic.
- Kończy się błędem (kod 1, komunikat z instrukcją) i niczego nie zmienia, gdy: istnieje zwykły indeks o nazwie
  aliasu, alias wskazuje inną liczbę indeksów niż jeden albo `definition_hash` indeksu różni się od definicji
  (instrukcja: `catalog:reindex`).
- Wypisuje ostrzeżenie z listą indeksów `<alias>_*`, na które nie wskazuje alias. Nie usuwa ich.
- `--recreate` usuwa alias i wszystkie indeksy `<alias>_*`, potem tworzy je od nowa. Przy `APP_ENV=prod` odmawia
  (kod 1). Używa go `init-test`, bo indeks testowy jest jednorazowy.

**`catalog:reindex` (przebudowa synchroniczna)**

`ProductSearchIndexRebuilder` w warstwie aplikacji, kolejno:

1. Bierze blokadę przebudowy (`SearchIndexRebuildLock`: sesyjna blokada doradcza Postgresa, zwalniana też
   przy awarii procesu). Gdy blokada jest zajęta, kończy się kodem 1, nic nie zmieniając. Tej samej blokady
   używa `setup`.
2. Odczytuje aktualny cel aliasu. Musi to być dokładnie jeden indeks.
3. Tworzy nowy indeks fizyczny z aktualną definicją.
4. Czyta produkty paczkami (`ProductSearchSource::page`, domyślnie po 500, z parametru i opcji
   `--batch-size`) i zapisuje każdą paczkę bulkiem. Pierwszy błąd przejściowy, trwały albo wynik „przestarzały”
   (w nowym indeksie nie powinien wystąpić) przerywa przebudowę.
5. Weryfikuje: odświeżenie, liczba dokumentów równa liczbie zapisanych, `definition_hash` zgodny, indeks
   istnieje i ma zdrowe shardy. Liczba dokumentów to dodatkowy test, a nie dowód pełnej spójności.
6. Przepina alias jednym żądaniem `_aliases`: `remove` starego celu z `must_exist: true` i `add` nowego.
   Elasticsearch nie ma porównania i zamiany aliasu, więc poprawność zapewniają razem: blokada przebudowy,
   sprawdzenie dokładnego celu przed żądaniem, atomowość akcji w jednym żądaniu (`remove` nie przejdzie, gdy
   alias nie wskazuje już starego celu) i sprawdzenie po żądaniu, że alias wskazuje dokładnie nowy indeks.
7. Przy niejednoznacznym wyniku (przekroczony czas, zerwane połączenie) czyta rzeczywisty cel aliasu:
   - dokładnie nowy indeks: przepięcie się udało;
   - dokładnie stary: przepięcie się nie udało;
   - inny stan albo brak odpowiedzi: stan nieznany.
8. Po udanym przepięciu usuwa stary indeks. Nieudane usunięcie to ostrzeżenie, a nie porażka przebudowy:
   stary indeks zostaje na liście osieroconych w `setup`.
9. Po nieudanej przebudowie, czyli błąd w krokach 3–5 albo przepięcie, które się nie udało, usuwa nowy indeks
   i kończy się kodem 1. Alias zostaje przy starym indeksie.
10. Przy stanie nieznanym niczego nie usuwa i kończy się kodem 2 z nazwami obu indeksów.

Usunięcie indeksu zawsze najpierw sprawdza, że alias na niego nie wskazuje, i odmawia w przeciwnym razie.

`ProductSearchIndexRebuilder::reset()` (używany przez seed) tworzy pusty indeks, przepina na niego alias
i usuwa stary, tak jak przebudowa, tylko bez zasilania. Alias nie znika ani na chwilę.

**Ograniczenia tej przebudowy**

To świadome ograniczenia szkieletu, a nie docelowe rozwiązanie dla katalogu rzędu 10 mln produktów:

- To nie jest przebudowa online. Zapisy przyrostowe w trakcie przebudowy (`IndexProduct`) trafiają przez alias
  do starego indeksu. Produkt zmieniony po tym, jak przebudowa minęła jego pozycję, nie trafi do nowego indeksu.
  Poprawny wynik wymaga braku równoległych zapisów katalogu (w szkielecie zapisuje tylko seed, który używa
  `reset()`).
- Brak checkpointów i wznowienia: przerwana przebudowa zaczyna się od początku. Indeks osierocony po awarii
  procesu zostaje i jest wypisywany przez `setup`.
- Brak równoległego zasilania przez wielu consumerów, okresu retencji starego indeksu i rollbacku.

Port odczytu paczkami (`page(afterId, limit)`), zapis paczki do wskazanego indeksu z wynikiem każdej pozycji
i osobne operacje utworzenia, weryfikacji i przepięcia indeksu są podstawą pod przyszłą przebudowę online
z wieloma consumerami, która zastąpi pętlę w `ProductSearchIndexRebuilder`.

**Odczyt produktów do indeksu**
- `ProductSearchSource` (port w `Application`, implementacja na DBAL, bez hydratacji agregatów) zwraca
  `page(?afterId, limit)`: najwyżej `limit` produktów o `id > afterId`, posortowanych po `id`. Pusta lista oznacza
  koniec. `afterId` musi być UUID (wielkość liter bez znaczenia, wartość jest normalizowana do małych liter) albo
  `null`, w przeciwnym razie metoda rzuca `\InvalidArgumentException`. `limit` musi wynosić co najmniej 1.
- `find(id)` czyta jeden produkt tym samym zapytaniem co `page`, z warunkiem `WHERE id = :id` zamiast kursora, i zwraca
  `null`, gdy produktu nie ma. `id` ma te same wymagania co `afterId`. Używa go `IndexProduct`.
- Jedno zapytanie SQL na paczkę, czyli jeden spójny snapshot. Najpierw CTE wybiera identyfikatory paczki
  z `LIMIT` na `catalog_product`, dopiero potem zapytanie dołącza kategorie dodatkowe i ścieżki kategorii
  i agreguje je dla każdego produktu. `LIMIT` nigdy nie działa na wierszach po złączeniu.
  Pierwsza paczka nie ma warunku kursora, kolejne mają `WHERE id > :after`, dzięki czemu kursor pozostaje warunkiem
  indeksu klucza głównego także w planie generycznym (test przypina to przez `EXPLAIN`).
- Wynik dla produktu: id, wersja, pola dokumentu, id kategorii głównej, posortowane id kategorii dodatkowych
  i ścieżki wszystkich kategorii produktu. Przodków liczymy ze ścieżek, bez wczytywania kategorii nadrzędnych.
- Pamięć zależy od rozmiaru paczki, a nie od wielkości katalogu.

### Obrazy

- Oryginały są w prywatnym buckecie `product-images` w Garage, zapisywane przez port `ProductImageStorage`
  (Flysystem z adapterem S3).
- Formaty wejściowe: JPEG, PNG, WebP, AVIF i HEIC (imgproxy dekoduje wszystkie jako źródło). SVG nie jest
  przyjmowany, bo może zawierać skrypty. Typ rozpoznaje `finfo` (rozszerzenie `fileinfo`, libmagic) po zawartości,
  nigdy po rozszerzeniu ani nazwie pliku.
- Klucz oryginału zależy od treści: `originals/<sha256>.<rozszerzenie wykrytego typu>` (`jpg`, `png`, `webp`,
  `avif`, `heic`). Adres obrazu dla danej treści i rozmiaru nigdy się nie zmienia, a to samo zdjęcie jest zapisane
  raz.
- `ProductImageStorage::store` przed zapisem odrzuca (`InvalidImage`) plik większy niż 64 MB, typ spoza listy
  i obraz o rozdzielczości powyżej 50 MP (wymiary z nagłówka przez `getimagesizefromstring`, bez dekodowania).
  Odrzucony plik nie trafia do bucketu.
- imgproxy jest drugą linią obrony z tymi samymi limitami: `IMGPROXY_MAX_SRC_FILE_SIZE` (64 MB)
  i `IMGPROXY_MAX_SRC_RESOLUTION` (50 MP); za duży oryginał daje 422.
- Lista rozmiarów jest zamknięta i zdefiniowana w jednym miejscu: enum `ImageSize` w `Catalog\Application`.
  Każdy rozmiar określa proporcje, sposób skalowania, szerokości i formaty wyjściowe. W szkielecie istnieje jeden rozmiar:

  | Rozmiar | Proporcje | Skalowanie | Szerokości | Formaty |
  |---|---|---|---|---|
  | `listing` | 1:1 | `fill`, gravity `sm` | 256, 384, 512, 768 | WebP |

- Wyjście jest wyłącznie w WebP. Kodowanie AVIF na żądanie mocno obciąża CPU na małym serwerze demo; AVIF można
  dodać później jako kolejny format w `ImageSize`, bez zmiany kształtu odpowiedzi API (warianty są pogrupowane
  po formacie).
- Format jest częścią podpisanego adresu (rozszerzenie w ścieżce imgproxy). Każda para format × szerokość ma
  osobny, jednoznaczny adres. imgproxy nie negocjuje formatu po nagłówku `Accept`, bo CDN przed nim
  (darmowy Cloudflare) nie obsługuje Vary for Images.
- Adresy podpisuje HMAC-em jeden serwis w API (implementacja portu `ImageUrlGenerator`). Podział sekretów
  per usługa (klucz i sól imgproxy tylko dla `api` i `imgproxy`) obowiązuje przy wdrożeniu, lokalnie opisuje go
  sekcja „Profile i sekrety”.
- imgproxy czyta wyłącznie z bucketów zdjęć (`IMGPROXY_ALLOWED_SOURCES`) i zwraca długi `Cache-Control`,
  bo adresy są niezmienne. Lokalnie lista to `s3://product-images/` i `s3://product-images-test/` (bucket testów
  integracyjnych), w demo i produkcji tylko `s3://product-images/`.

### Endpointy

Wszystkie pod `/api/v1`, JSON, opisane w OpenAPI (żądanie, odpowiedzi, błędy).

| Metoda i ścieżka | Dostęp | Źródło danych | Cache |
|---|---|---|---|
| `GET /api/v1/products` | publiczny | Elasticsearch | `public, max-age=60`, `Vary: Origin` |
| `GET /api/v1/categories` | publiczny | Postgres | `public, max-age=60`, `Vary: Origin` |
| `GET /api/v1/me` | klient (`ROLE_CUSTOMER`) | token | `private, no-store` |
| `GET /api/v1/admin/me` | pracownik (dowolna rola) | token | `private, no-store` |
| `GET /api/v1/admin/dashboard` | pracownik; sekcja `system` tylko `admin` | Postgres, ES, RabbitMQ, sondy | `private, no-store` |
| `GET /health` | publiczny, poza OpenAPI | proces API | `no-store` |

**`GET /api/v1/products`**
- Parametry: `category` (UUID, opcjonalny), `page` (≥ 1, domyślnie 1), `perPage` (1–48, domyślnie 24).
- Filtr `category` obejmuje produkty przypisane do kategorii (jako głównej lub dodatkowej) i do wszystkich jej
  podkategorii (`category_ancestor_ids`). Nieistniejąca kategoria
  daje 200 i pustą listę (API nie odpytuje Postgresa przy odczycie z modelu odczytu).
- Sortowanie po nazwie (`name.sort`), potem po `id`.
- Strona poza zakresem (np. `page=999`) daje 200, pustą listę `items` i prawdziwe `total`.
- Błędne parametry: 400 jako problem details z listą naruszeń.
- Niedostępny Elasticsearch: 503 jako problem details, bez szczegółów technicznych w treści.

```json
{
  "items": [
    {
      "id": "0b9f…",
      "name": "Buty do biegania Puls 2 granatowe",
      "slug": "buty-do-biegania-puls-2-granatowe",
      "shortDescription": "…",
      "image": {
        "alt": "Biegaczka na leśnej ścieżce",
        "size": "listing",
        "aspectRatio": "1:1",
        "variants": {
          "webp": [{ "width": 256, "url": "http://localhost:8081/…" }, { "width": 384, "url": "…" }]
        }
      }
    }
  ],
  "page": 1,
  "perPage": 24,
  "total": 412
}
```

`image` jest `null`, gdy produkt nie ma zdjęć.

**`GET /api/v1/categories`**: całe drzewo, rodzeństwo posortowane po nazwie.

```json
[{ "id": "…", "name": "Bieganie", "children": [{ "id": "…", "name": "Buty", "children": [] }] }]
```

**`GET /api/v1/me`**: `{ "id": "<sub>", "email": "…", "name": "…" }`.

**`GET /api/v1/admin/me`**: `{ "id": "<sub>", "username": "…", "roles": ["admin"] }`.

**`GET /api/v1/admin/dashboard`**

```json
{
  "catalog": { "productCount": 412, "categoryCount": 36, "productsWithoutImages": 8 },
  "system": {
    "services": [{ "name": "postgres", "status": "up", "latencyMs": 3 }],
    "outbox": { "pending": 0 },
    "queues": [{ "name": "events", "messages": 0, "consumers": 0 }],
    "searchIndex": { "indexedProducts": 412, "catalogProducts": 412, "inSync": true }
  }
}
```

- `system` jest `null`, gdy `DashboardVoter` odmówi atrybutu `VIEW_SYSTEM_STATUS` (wymaga roli `admin`).
- `services` zawsze w tej kolejności: `postgres`, `redis`, `elasticsearch`, `rabbitmq`, `keycloak`, `garage`,
  `imgproxy`, `mailpit`. Każda sonda ma limit 1 s, liczony od wysłania żądania. Żądania sond HTTP, połączenie z Redisem
  i żądanie o kolejki do Management API są wysyłane razem, a odpowiedzi zbierane razem, więc sekcja `system` kosztuje
  około jednego limitu (najwyżej 1 s), a nie sumę limitów. Sekcja `system` jest zbierana przed odczytem katalogu
  (liczenie dokumentów w Elasticsearchu ma własny limit 2 s), więc wolne liczenie nie psuje wyników sond. Niedostępna
  usługa ma `status: "down"` i `latencyMs: null`; endpoint nadal zwraca 200.
- `queues` pochodzi z Management API RabbitMQ (vhost transportu `events`), `outbox.pending` z tabeli transportu `outbox`.
  `consumers` to liczba subskrybentów AMQP (`basic.consume`) raportowana przez RabbitMQ. Worker Messengera odbiera
  wiadomości przez `basic.get` (odpytywanie), więc kolejka `events` ma `consumers: 0` także wtedy, gdy worker działa.
  Stanu workera nie wolno z tego wnioskować.
- Częściowa awaria nie psuje dashboardu: niedostępne Management API RabbitMQ daje `queues: null`, a Elasticsearch,
  którego dokumentów nie da się policzyć (niedostępny, `SearchUnavailable`, albo błąd trwały, np. brak aliasu,
  `SearchIndexError`), daje `searchIndex.indexedProducts: null` i `inSync: false`. Niedostępny Postgres (źródło sekcji
  `catalog`) daje 503 jako problem details.

### Uwierzytelnianie i uprawnienia

Wbudowany w Symfony authenticator `access_token` z handlerem `oidc`. Dwa bezstanowe firewalle, sprawdzane
w tej kolejności:

| Firewall | Ścieżki | Discovery / JWKS | Wymagany `iss` | Wymagane `aud` | Użytkownik |
|---|---|---|---|---|---|
| `admin` | `^/api/v1/admin(/\|$)` | `${KEYCLOAK_INTERNAL_URL}/realms/backoffice/` (`http://keycloak:8080`) | `${KEYCLOAK_PUBLIC_URL}/realms/backoffice` | `backoffice-api` | `StaffUser` |
| `shop` | `^/api/v1(/\|$)` | `${KEYCLOAK_INTERNAL_URL}/realms/shop/` | `${KEYCLOAK_PUBLIC_URL}/realms/shop` | `shop-api` | `CustomerUser` |

- Tylko RS256. Wymagane `iat`, `exp`, `aud` i `iss`, sprawdzane też `nbf`, bez tolerancji czasu. Adres discovery
  kończy się ukośnikiem, bo ścieżki discovery są rozwiązywane względem niego.
- Discovery i JWKS są w puli cache `cache.oidc` przez czas z nagłówków JWKS. Keycloak ich nie podaje, więc obowiązuje
  domyślna 1 h. Klucz po rotacji (nowy `kid`) jest odrzucany do wygaśnięcia cache, najdłużej 1 h.
- Klient HTTP API ma limit 2 s na połączenie i odpowiedź oraz 5 s na całe żądanie, więc zawieszony Keycloak nie
  blokuje żądań.
- `StaffUser`: `sub`, `preferred_username`, role z `realm_access.roles` przycięte do `admin`, `support`,
  `warehouse`, `marketing` (w tej kolejności); dostaje `ROLE_STAFF`.
- `CustomerUser`: `sub`, `email`, `name` (pusty, gdy go nie ma); dostaje `ROLE_CUSTOMER`. Token bez `email` daje 401.
- `StaffUser` i `CustomerUser` to osobne klasy, bez wspólnej klasy bazowej. Implementują interfejsy z warstwy
  Application swoich modułów (`AuthenticatedStaffMember`, `AuthenticatedCustomer`), a kontrolery dostają je przez
  `#[CurrentUser]`. Dzięki temu UI nie zależy od Infrastructure.
- `access_control` domyślnie odmawia: `/api/v1/admin` wymaga `ROLE_STAFF`, reszta `/api/v1` `ROLE_CUSTOMER`, poza
  jawnie publicznymi `GET` (`products`, `categories`) z `PUBLIC_ACCESS`. Nowy endpoint publiczny trzeba tam dopisać.
- Przesłanie nieprawidłowego tokena do endpointu publicznego też daje 401. Nagłówek `Authorization` z innym
  schematem niż `Bearer` (albo z wartością spoza składni tokena) jest traktowany jak brak tokena.
  Rozpoznawany jest tylko schemat `Bearer` z dokładną wielkością liter, po nim dowolna liczba białych znaków (spacje,
  tabulator) i token, który kończy nagłówek. Nagłówek w innej formie (`bearer`, `BEARER`, spacja po tokenie, brak
  separatora, sam token) to brak tokena, więc endpoint publiczny odpowiada jak anonimowemu klientowi, a chroniony 401.
- Brak tokena, zły wystawca (w tym token z drugiego realmu), zła audience, wygasły, jeszcze nieważny lub uszkodzony
  token, zły podpis lub algorytm: 401 jako problem details z nagłówkiem `WWW-Authenticate: Bearer` i stałym `detail`,
  bez powodu odrzucenia.
- Keycloak nieosiągalny albo odpowiadający błędem przy pobieraniu discovery lub JWKS: 503 jako problem details
  bez `detail` i bez `WWW-Authenticate`. Tokena nie da się wtedy ocenić, więc to awaria, a nie zły token. Sklep na 503
  z `GET /api/v1/me` nie usuwa sesji. Żądanie bez tokena nie potrzebuje kluczy i nadal daje 401.
- Środowisko `test`: handler `oidc` dostaje stały `keyset` z env zamiast discovery. Bootstrap testów generuje
  w pamięci parę kluczy RSA, a `TestTokenFactory` podpisuje nią tokeny obu realmów. W repo nie ma kluczy.
  Środowisko `test_oidc` pobiera klucze przez discovery jak dev i prod (cache tylko w pamięci); używają go testy
  awarii Keycloak.

### Wspólne zachowanie HTTP

- Błędy w formacie RFC 9457 (`application/problem+json`: `type`, `title`, `status`, `detail`; dla 400 także
  `violations` z `propertyPath` i `title`).
- `detail` zależy od rodzaju wyjątku, a decyduje wyłącznie wyjątek rzucony, nie jego łańcuch `previous`:
  - wyjątek projektu (interfejs `App\Shared\Domain\Exception\ProblemException`): `detail` to jego komunikat,
    napisany dla klienta po angielsku, bez danych wewnętrznych i wejścia użytkownika. Interfejs nie niesie statusu
    HTTP. Rodzaj problemu wyrażają interfejsy-znaczniki `NotFound` (404), `Conflict` (409) i `InvalidInput` (422);
    mapowanie na status jest w `Shared\Infrastructure\Http` (pierwsze dopasowanie wygrywa). `ProblemException`
    bez znanego rodzaju daje 422. Klasa będąca jednocześnie `HttpExceptionInterface` jest traktowana jako wyjątek
    projektu, jej status i nagłówki HTTP są ignorowane,
  - wyjątek frameworka (`HttpExceptionInterface`: 404 routera, 405, `MapQueryString` itp.): status i nagłówki
    (np. `Allow`) są zachowane, a `detail` to stały angielski opis zależny od statusu, nigdy komunikat wyjątku
    (komunikaty routera zawierają żądany adres),
  - `ValidationFailedException` (także w łańcuchu `previous`): 400 z `violations`. Komunikaty naruszeń (także
    własnych constraintów) nie zawierają wejścia użytkownika ani danych wewnętrznych,
  - `HandlerFailedException` Messengera (szyna synchroniczna opakowuje wyjątek handlera): łańcuch takich wyjątków,
    z których każdy opakowuje dokładnie jeden wyjątek (zagnieżdżone szyny), jest rozwijany do wyjątku wewnętrznego
    i obowiązują dla niego powyższe reguły. Rozwijanie ma limit głębokości 10. To jedyny wyjątek od zasady, że łańcuch
    `previous` nie jest przeglądany. Gdy któryś poziom opakowuje więcej niż jeden wyjątek albo limit zostanie
    przekroczony: 500 bez `detail` i bez `violations`, także gdy pierwszy opakowany wyjątek to `ValidationFailedException`,
  - każdy inny wyjątek, także projektowy opakowany w wyjątek frameworka i frameworkowy opakowany w zwykły:
    pierwszy dostaje ogólny opis frameworka, drugi 500 bez `detail`,
  - 5xx nigdy nie ma `detail`.
- Każda odpowiedź z `Cache-Control: public` ma `Vary: Origin` (ustawia jeden subscriber).
- CORS (NelmioCorsBundle) dla `^/api/v1` tylko z `ADMIN_URL`. Sklep woła API wyłącznie z serwera, więc jego
  domena nie dostaje CORS.
- Logi Monolog w JSON na stderr.

### Komendy konsoli

| Komenda | Działanie |
|---|---|
| `catalog:search-index:setup [--recreate]` | tworzy indeks i alias `products`, jeśli nie istnieją, i sprawdza ich stan (sekcja „Indeks Elasticsearch”) |
| `catalog:reindex [--batch-size=N]` | przebudowa synchroniczna z Postgresa z przepięciem aliasu; kod 0 po przepięciu, 1 gdy alias zostaje przy starym indeksie, 2 gdy stan aliasu jest nieznany |
| `catalog:seed-demo` | resetuje dane katalogu (tabele, outbox, indeks, bucket) i ładuje dane demo (opis niżej); przy `APP_ENV=prod` odmawia działania (kod 1, bez zmian w danych), chyba że dostanie flagę `--force-reset` |

## Kontrakt API i klienci TS

- NelmioApiDocBundle z dwoma obszarami:
  - `default`: `/api/v1` bez `/api/v1/admin`, eksportowany do `apps/api/openapi.json`,
  - `admin`: `/api/v1/admin`, eksportowany do `apps/api/openapi-admin.json`.
- Oba schematy bezpieczeństwa są typu bearer: `shop` w pierwszej specyfikacji, `backoffice` w drugiej.
- `make -C apps/api openapi` eksportuje oba pliki.
- `make api-client` generuje `apps/store/src/api/schema.d.ts` z `openapi.json` i `apps/admin/src/api/schema.d.ts`
  z `openapi-admin.json` (`openapi-typescript`). Kontener każdego frontu ma zamontowany tylko do odczytu wyłącznie
  swój plik specyfikacji.
- W runtime oba fronty używają `openapi-fetch`, każdy z jednym modułem `src/api/client.ts`.
- ESLint w obu frontach zabrania globalnego `fetch` poza `src/api/client.ts`.
- Wyeksportowane specyfikacje i wygenerowane `schema.d.ts` są commitowane.

## Sklep (`apps/store`)

Next.js 16 (App Router), React, TypeScript w trybie strict, domyślnie Server Components. Robocza nazwa sklepu:
„Rytm”.

### Wygląd

- Komponenty na shadcn/ui, style w Tailwind CSS 4. W szkielecie z shadcn/ui pochodzą `button`, `card`
  i `pagination` w `src/components/ui/`. Tryb ciemny nie jest obsługiwany.
- Tokeny są w jednym miejscu, w `src/styles/tokens.css`. Na ten plik wskazuje też `components.json` shadcn/ui.
  - Paleta: surowe wartości kolorów w `@theme` pod przestrzenią `--palette-*`, której Tailwind nie zamienia
    na klasy (nie ma `bg-gray-500`). `--color-*: initial` usuwa domyślną paletę Tailwinda. Na klasy `--color-*`
    mapowane są tylko zmienne semantyczne.
  - Warstwa semantyczna: zmienne motywu shadcn/ui (`--background`, `--foreground`, `--card`, `--card-foreground`,
    `--primary`, `--primary-foreground`, `--secondary`, `--secondary-foreground`, `--muted`, `--muted-foreground`,
    `--accent`, `--accent-foreground`, `--destructive`, `--border`, `--input`, `--ring`, `--radius`) zdefiniowane
    przez odwołania do palety i mapowane w `@theme inline` na klasy (`bg-background`, `text-foreground`,
    `bg-primary` i pozostałe).
- Komponenty, także wygenerowane przez shadcn/ui, używają wyłącznie warstwy semantycznej. Klasy z kolorami palety
  w wygenerowanym kodzie (np. `bg-black/50`) są zastępowane tokenami semantycznymi.
- ESLint odrzuca:
  - wartości arbitralne z kolorem w `className` (`[#…]`, `rgb(`, `hsl(`, `oklch(`),
  - kolory w atrybucie `style`,
  - klasy nieznane Tailwindowi skonfigurowanemu z `tokens.css` (reguła `no-unregistered-classes` z
    `eslint-plugin-better-tailwindcss`), więc `bg-black` czy `text-red-500` nie przejdą.
- Motyw w szkielecie jest tymczasowy i neutralny: odcienie szarości, jeden akcent, systemowy krój pisma.
  Docelowy wygląd (kierunek w `docs/vision.md`, sekcja „Wygląd sklepu”) to osobny krok MVP.
- Komponenty i układy przenoszone później z vercel/shop podlegają zasadom z `apps/store/CLAUDE.md`
  (informacja o licencji MIT, dane tylko z naszego API, obrazy przez `ProductPhoto`).

### Strona `/`

- Server Component. Równolegle pobiera z API (`http://api`, wygenerowany klient) drzewo kategorii i stronę
  produktów dla `?category=<uuid>&page=<n>`.
- Lewa kolumna: drzewo kategorii jako linki `?category=<id>`. Aktywna kategoria jest wyróżniona, a jej przodkowie
  rozwinięci.
- Siatka produktów: kafel (`card`) ze zdjęciem i nazwą. Responsywnie 2, 3 albo 4 kolumny.
- Paginacja (`pagination`): linki „Poprzednia” i „Następna” z zachowaniem filtra.
- Pusty wynik pokazuje komunikat z linkiem do całego katalogu. Produkt bez zdjęcia ma neutralny placeholder.
- 400 z API pokazuje „Nieprawidłowy filtr” z linkiem do całego katalogu. Niedostępne API kończy się w `error.tsx`.
- Nagłówek: nazwa sklepu oraz komponent konta (niżej).

### Obrazy

- `ProductPhoto` renderuje zwykły `<img>` ze `srcset` z wariantów WebP z API, `src` z największym wariantem,
  atrybutem `sizes` zgodnym z siatką oraz `width`/`height` z proporcji rozmiaru. Nie ma `<picture>`, bo API
  zwraca jeden format.
- Front niczego nie podpisuje ani nie dobiera rozmiarów spoza odpowiedzi API.
- Globalny loader obrazów Next.js (`images.loader: 'custom'`) rzuca błąd, więc użycie `<Image>` jest niemożliwe, a wbudowana optymalizacja nigdy nie przetwarza obrazów.

### Logowanie (BFF)

Tokeny nigdy nie trafiają do przeglądarki. Serwer sklepu jest klientem confidential `store`
(`openid-client`), sesje są w Redisie.

| Route handler | Działanie |
|---|---|
| `GET /auth/login?returnTo=…` | generuje `state`, `nonce` i weryfikator PKCE; zapisuje je w Redisie pod jednorazowym kluczem (TTL 10 min), którego id trafia do krótkiego ciasteczka; przekierowuje do Keycloak (adres publiczny) |
| `GET /auth/callback` | sprawdza `state`; wymienia kod na tokeny przez `http://keycloak:8080`; waliduje `id_token`; tworzy sesję i przekierowuje na `returnTo` |
| `GET /auth/session` | zwraca `{ "name": "…" }` albo `null`; dane z `GET /api/v1/me` wołanego z tokenem z sesji; 401 z API usuwa sesję, a inny błąd API, Redisa albo Keycloak daje 503 bez usuwania sesji |
| `POST /auth/logout` | tylko POST (`GET` dostaje 405 z `Allow: POST`); żądanie z obcym nagłówkiem `Origin` albo z `Sec-Fetch-Site` równym `cross-site` lub `same-site` dostaje 403, a żądanie bez obu nagłówków przechodzi; usuwa sesję i ciasteczko; przekierowuje do `end_session` Keycloak z `id_token_hint` i `post_logout_redirect_uri` |

- `returnTo` jest akceptowany tylko jako ścieżka w obrębie sklepu: zaczyna się od pojedynczego `/`, nie od `//`,
  nie zawiera `\` ani znaków sterujących (C0, DEL, C1), a `new URL(returnTo, STORE_URL).origin` równa się `STORE_URL`.
  Każda inna wartość (także brak) daje przekierowanie na `/`.
- Sesja: klucz `session:<losowe id>` w Redisie z `sub`, tokenami i czasem wygaśnięcia access tokena; TTL równy
  czasowi życia refresh tokena. Ciasteczko `store_session` zawiera tylko id: `HttpOnly`, `SameSite=Lax`,
  `Path=/`, `Secure` poza `localhost`, bez `Max-Age` (o czasie życia sesji decyduje Redis). Każde logowanie
  tworzy nową sesję pod nowym id i usuwa sesję, z którą przyszła przeglądarka.
- Wartości ciasteczek sklepu (`store_session` i ciasteczka transakcji logowania `store_login`, `Path=/auth/callback`,
  10 min) są podpisane HMAC-SHA256 kluczem `STORE_SESSION_SECRET`. Wartość bez poprawnego podpisu jest traktowana
  jak brak ciasteczka, bez odczytu z Redisa.
- Odświeżanie: gdy access token wygasa za mniej niż 30 s, serwer odświeża go pod blokadą
  `SET lock:refresh:<id sesji> <losowy token> NX PX 5000`. Zwycięzca odświeża tokeny, zapisuje sesję i zwalnia
  blokadę (usunięcie tylko przy zgodnym tokenie). Discovery OIDC odbywa się przed założeniem blokady, a każde
  żądanie HTTP do Keycloak ma limit 2 s. Pozostałe żądania co 100 ms czytają sesję, aż zmieni się czas
  wygaśnięcia, i używają nowego tokena. Gdy blokada zniknęła, a sesja się nie zmieniła (zwycięzca nie odświeżył,
  np. Keycloak nieosiągalny), dostają 503 bez usuwania sesji, tak jak sam zwycięzca. Odrzucony refresh token
  (`invalid_grant`) usuwa sesję. Jeśli blokada nadal istnieje, a przez 5 s nic się nie zmieni, sesja jest
  traktowana jak wylogowana. Ciasteczko się nie zmienia.
- Wszystkie odpowiedzi `/auth/*` mają `Cache-Control: private, no-store`, także 503, gdy brakuje konfiguracji.
- Komponent konta w nagłówku to Client Component, który woła `/auth/session` i pokazuje „Zaloguj się” albo
  „Zalogowany jako …” z przyciskiem wylogowania. HTML katalogu nie zależy od sesji.

## Panel (`apps/admin`)

Vue 3 (Composition API), Vite, TypeScript, Pinia, Vue Router, PrimeVue 4 z presetem Aura, PrimeIcons.

### Logowanie

- `oidc-client-ts`, klient public `admin`, PKCE. Tokeny wyłącznie w pamięci (`InMemoryWebStorage`).
- Odnawianie access tokena refresh tokenem (`automaticSilentRenew`).
- Każda trasa wymaga zalogowania. Po przeładowaniu strony guard routera wywołuje `signinRedirect()`; aktywna sesja
  SSO w Keycloak zwraca użytkownika bez ponownego hasła i TOTP.
- Store Pinia `auth` trzyma pracownika z `GET /api/v1/admin/me`. Role pochodzą z API, nie z dekodowania tokena.
- Middleware wygenerowanego klienta dodaje `Authorization: Bearer …`; odpowiedź 401 wywołuje `signinRedirect()`
  najwyżej raz na cykl logowania. Przed przekierowaniem klient zapisuje w `sessionStorage` jednorazowy znacznik
  (znacznik czasu, nigdy token), a pierwsza odpowiedź 2xx go usuwa. 401 przy ustawionym znaczniku (API odrzuca świeży
  token) nie przekierowuje ponownie, tylko pokazuje `Message` z błędem i przyciskiem „Zaloguj ponownie”.
- Błąd `signinRedirect()` lub `signoutRedirect()` (Keycloak niedostępny) pokazuje ten sam `Message` zamiast pustej strony.
- Wylogowanie: `signoutRedirect()` z powrotem na `ADMIN_URL`.
- Bezczynność: `useIdleLogout` nasłuchuje `pointerdown`, `keydown`, `wheel` i `visibilitychange` (z throttlingiem)
  i dzieli czas ostatniej aktywności między kartami przez `BroadcastChannel`. Po 15 minutach bez aktywności we
  wszystkich kartach wywołuje `signoutRedirect()`. Automatyczne odświeżanie tokena i danych nie jest aktywnością.

### Layout i dashboard

- Layout: menu boczne (pozycja „Dashboard”), topbar z loginem pracownika, jego rolami (`Tag`) i przyciskiem
  wylogowania.
- Sekcja „Katalog”: kafle z liczbą produktów, liczbą kategorii i liczbą produktów bez zdjęć (wyróżniona
  ostrzeżeniem, gdy jest większa od 0).
- Sekcja „Stan systemu”, wyświetlana tylko gdy `system` nie jest `null`: usługi ze stanem (`Tag` up/down)
  i opóźnieniem, liczba zdarzeń czekających w outboxie, tabela kolejek (`DataTable`: nazwa, wiadomości,
  konsumenci), porównanie liczby dokumentów w ES z liczbą produktów w Postgresie z oznaczeniem rozbieżności.
- `Skeleton` podczas ładowania, `Message` przy błędzie API, przycisk „Odśwież” i automatyczne odświeżanie co 30 s.
- Dashboard pokazuje wyłącznie dane z API. Nie ma danych zmockowanych ani widgetów sprzedaży.

## Dane demo

- Branża: sport i outdoor. Drzewo kategorii na 3–4 poziomy z kategoriami najwyższego poziomu: bieganie, rower, turystyka,
  sporty zimowe, fitness, sporty wodne, sporty drużynowe.
- `apps/api/fixtures/catalog.yaml` definiuje drzewo kategorii oraz szablony produktów dla kategorii: fikcyjne marki
  własne sklepu, modele, kolory, szablony opisów, kategorię podatkową, pulę zdjęć i opcjonalne kategorie
  dodatkowe (część szablonów ma kategorię dodatkową w innej gałęzi drzewa).
- Część asortymentu ma obniżoną kategorię podatkową: żywność sportowa (`food`), suplementy (`food_supplement`),
  przewodniki i mapy (`printed_publication`).
- `catalog:seed-demo` rozwija szablony deterministycznie (iloczyn kombinacji w ustalonej kolejności, bez losowości)
  do 300–500 produktów. Zdjęcie produktu jest wybierane z puli kategorii według pozycji produktu w kolejności
  rozwijania. Reguła zapisana w pliku pozostawia kilka produktów bez zdjęć.
- Zdjęcia: 20–30 prawdziwych zdjęć „w terenie” (ludzie w górach, na szlaku, na rowerze) w spójnej tonacji,
  z Unsplash lub Pexels, przeskalowanych do około 1600 px na dłuższym boku, w `apps/api/fixtures/images/`.
  `apps/api/fixtures/images/CREDITS.md` podaje dla każdego pliku źródło, autora i licencję.
- Seed nadaje kategoriom i produktom UUID v5 wyliczane ze sluga w stałej przestrzeni nazw, więc identyfikatory są
  takie same po każdym seedzie. To wyłącznie mechanizm seeda: komendy aplikacji przyjmują identyfikator
  od wywołującego.
- Seed tworzy dane przez komendy aplikacji (`CreateCategory`, `CreateProduct`), więc indeks powstaje drogą
  outbox → RabbitMQ → `IndexProduct`. Na koniec czeka (do 60 s), aż liczba dokumentów w ES zrówna się z liczbą
  produktów, i wypisuje podsumowanie. Jeśli indeks nie dogoni Postgresa w tym czasie (np. nie działa `worker`),
  komenda kończy się kodem różnym od 0 i komunikatem z obiema liczbami.
- Konta demo w Keycloak pochodzą z importu realmów, nie z seeda.

## Testy

| Aplikacja | Testy jednostkowe (bez usług) | Testy integracyjne i funkcjonalne (na stosie) |
|---|---|---|
| api | domena: ścieżka kategorii, niezmienniki produktu, `TaxCategory`, `Slug`; handlery z adapterami in-memory; budowa dokumentu wyszukiwarki; przebieg przebudowy indeksu na fake'ach portów; routing Messengera na transportach `in-memory://` | repozytoria Doctrine (z wersją produktu); odczyt paczkami; adapter ES (filtr po przodkach, sortowanie, paginacja, wersje, bulk, alias); zapis do Garage; podpis imgproxy na żywym imgproxy; droga seed → outbox → RabbitMQ → ES; sondy usług; testy funkcjonalne endpointów |
| store | reguły ESLint (kolory, klasy spoza tokenów, `fetch`); walidacja `returnTo`; budowa `srcset` z wariantów; `CategoryTree`; `ProductGrid` | blokada odświeżania na prawdziwym Redisie |
| admin | dashboard; `useIdleLogout`; 401 → `signinRedirect`; guard routera | — |

Wymagane przypadki:

- **Routing Messengera:** konsumpcja `outbox` umieszcza dokładnie jedną wiadomość na `events` i nie wywołuje
  `IndexProduct`; konsumpcja `events` wywołuje `IndexProduct` raz i nie umieszcza niczego na `outbox` ani `events`.
- **Atomowość outboxa:** wyjątek po zapisaniu zdarzenia, a przed commitem, zostawia zero produktów, zero wierszy
  w outboxie i zero wiadomości w RabbitMQ; udany commit zostawia jeden produkt i jeden wiersz.
- **Przekaźnik outboxa:** nieosiągalny broker zostawia zdarzenie w outboxie z kolejnymi ponowieniami, potem
  w `failed`, skąd `messenger:failed:retry` publikuje je po powrocie brokera; publikacja odrzucona przez brokera
  (nack) zostawia zdarzenie w outboxie; przekaźnik, który padł przed ackiem, publikuje zdarzenie drugi raz.
- **imgproxy:** podpisany adres WebP zwraca 200 i `image/webp` dla oryginału w każdym formacie wejściowym (JPEG,
  PNG, WebP, AVIF, HEIC); zmieniony podpis zwraca 403; oryginał powyżej 50 MP wgrany bezpośrednio do bucketu
  zwraca 422.
- **Upload:** typ rozpoznany po treści (PNG z rozszerzeniem `.jpg` dostaje klucz `.png`); SVG, HTML, pusta treść,
  plik ponad limit rozmiaru i obraz ponad limit rozdzielczości dają `InvalidImage` i nie trafiają do bucketu.
- **Wersja produktu:** zmiana pól i zmiana samych kategorii dodatkowych zwiększają wersję; dwa zapisy oparte na
  tym samym odczycie (drugi po commicie pierwszego, na osobnych połączeniach) dają konflikt drugiego bez zmian
  w tabelach.
- **Odczyt paczkami:** kilka paczek, granica paczki na ostatnim produkcie, pusty katalog, każdy produkt dokładnie
  raz, poprawne kategorie dodatkowe przy produktach z wieloma kategoriami (paczka liczy produkty, nie wiersze
  złączenia), stała liczba zapytań na paczkę, szczyt pamięci przy kilku tysiącach produktów w ustalonym limicie.
- **Indeks:** starsza wersja i powtórzenie nie nadpisują dokumentu; częściowy błąd bulka jest wykryty;
  zerwana odpowiedź po wykonanym przepięciu aliasu (prawdziwy ES, dekorator klienta HTTP) kończy się
  sprawdzeniem stanu i nie usuwa nowego indeksu; nieudana weryfikacja nie zmienia aliasu; druga przebudowa przy
  zajętej blokadzie odmawia; zwykły indeks o nazwie aliasu jest wykryty przez `setup` i odrzucony przy zapisie.
- **Endpointy:** każdy ma test funkcjonalny. Endpointy chronione: brak tokena, token z drugiego realmu, zła
  audience, wygasły token, poprawny token. Uwierzytelnianie: zły podpis, `alg: none`, HS256 z kluczem publicznym,
  zmieniona treść; nieosiągalne discovery daje 503, a zły token przy osiągalnym Keycloak 401. Endpointy publiczne: nagłówki `Cache-Control` i `Vary: Origin`, błędne
  parametry (400 z naruszeniami). Dashboard: rola `support` dostaje `system: null`, rola `admin` dostaje `system`.
- **Odświeżanie w sklepie:** 5 równoległych żądań z wygasającym tokenem daje dokładnie jedno wywołanie endpointu
  tokenów, a wszystkie żądania dostają nowy token.
- **Open redirect:** `//evil.com`, `/\evil.com`, `https://evil.com`, `javascript:alert(1)`, wartość pusta i brak
  wartości dają przekierowanie na `/`.
- **Bezczynność w panelu:** fake timers; wylogowanie po 15 min bez aktywności; aktywność w innej karcie
  (przez `BroadcastChannel`) odsuwa wylogowanie.

Dane testowe w API przez fabryki Foundry. Fabryki agregatów Catalog budują obiekty domenowe przez ich metody
fabryczne, a test zapisuje je przez repozytorium. `dama/doctrine-test-bundle` wycofuje transakcję po każdym teście.

Izolacja od środowiska deweloperskiego: testy integracyjne używają bazy `shop_test`, indeksów z prefiksem `test_`,
bucketu `product-images-test` i vhosta RabbitMQ `test`. Deweloperski `worker` (baza `shop`, vhost `/`) nigdy
nie widzi wiadomości testów.

Transporty Messengera w testach:
- Środowisko kernela `test` ma wszystkie transporty `in-memory://`.
- Testy prawdziwej drogi wiadomości (atomowość outboxa, przepływ outbox → RabbitMQ → handler, statystyki kolejek)
  jawnie uruchamiają kernel w środowisku `test_messaging`: `outbox` Doctrine w `shop_test`, `events` AMQP na
  vhoście `test`, `failed` Doctrine.

Dodatkowe przypadki:
- **Seed w produkcji:** przy `APP_ENV=prod` bez `--force-reset` komenda kończy się kodem 1 i nie wywołuje resetu;
  z `--force-reset` działa normalnie.
- **CORS:** preflight z `ADMIN_URL` dostaje `Access-Control-Allow-Origin`; z `STORE_URL` i z obcej domeny nie.

### E2E (`e2e/`, Playwright)

Kontener `e2e` uruchamia `socat`, który przekierowuje `localhost:<port publiczny>` na odpowiednią usługę, więc
przeglądarka testowa używa tych samych adresów co przeglądarka na hoście (zgodne `iss` i przekierowania OIDC).

1. Katalog: strona główna pokazuje produkty z załadowanymi zdjęciami; wybór kategorii pokazuje też produkty
   z podkategorii; paginacja działa.
2. Klient: logowanie kontem demo, „Zalogowany jako …”, wylogowanie.
3. Rejestracja: nowe konto na unikalny adres, e-mail weryfikacyjny w Mailpicie (API Mailpita), otwarcie linku,
   konto zweryfikowane i zalogowane w sklepie.
4. Pracownik: `admin` loguje się hasłem i kodem TOTP wyliczonym ze stałego sekretu i widzi oba działy dashboardu;
   `support` widzi dział „Katalog” bez działu „Stan systemu”.

## CI

`.github/workflows/ci.yml`, uruchamiany przy każdym pull requeście (także ze zmianami wyłącznie w dokumentacji)
i pushu na `main`, więc job `checks` może być wymaganym checkiem gałęzi `main`. Nowe uruchomienie dla tego samego
PR (albo refu) przerywa poprzednie. Działa bez całego stosu: kontenery aplikacji startują z `--no-deps`.

1. Budowa obrazów deweloperskich `api`, `store` i `admin` z cache buildx (`type=gha`).
2. Instalacja zależności do wolumenów, jak lokalnie.
3. `make check` z `RUN="docker compose run --rm --no-deps -T"`.
4. Testy z tym samym `RUN`: `make -C apps/api test` (wszystkie suity api), `make -C apps/store test`
   (z Redisem), `make -C apps/admin test`. Zmiana, która dodaje testy na usługach stosu, w tym samym PR
   uruchamia w CI te usługi przed testami.
5. Aktualność kontraktu: `make -C apps/api openapi` i `make api-client`, potem `git diff --exit-code` na
   `apps/api/openapi.json`, `apps/api/openapi-admin.json` i obu `schema.d.ts`.

Osobny job `e2e` uruchamia pełny stos (`make up`, `make seed`) i `make e2e`. Przy porażce wypisuje logi usług
i zapisuje ślady Playwrighta (`e2e/test-results`) jako artefakt.

## Zależności

Akceptacja tej specyfikacji jest zgodą na poniższą listę. Każda inna zależność wymaga osobnego pytania.

| Gdzie | Produkcyjne | Deweloperskie |
|---|---|---|
| api (Composer) | `symfony/flex`, `symfony/framework-bundle`, `symfony/runtime`, `symfony/console`, `symfony/dotenv`, `symfony/yaml`, `symfony/security-bundle`, `symfony/messenger`, `symfony/amqp-messenger`, `symfony/doctrine-messenger`, `symfony/serializer`, `symfony/property-access`, `symfony/property-info`, `symfony/validator`, `symfony/uid`, `symfony/http-client`, `symfony/monolog-bundle`, `doctrine/doctrine-bundle`, `doctrine/orm`, `doctrine/doctrine-migrations-bundle`, `nelmio/api-doc-bundle`, `nelmio/cors-bundle`, `web-token/jwt-library`, `league/flysystem-bundle`, `league/flysystem-async-aws-s3`, `elasticsearch/elasticsearch` (wraz z zależnościami przechodnimi: `elastic/transport`, `nyholm/psr7`, `psr/http-client`, `psr/http-factory`, `psr/http-message`, `php-http/httplug`, `php-http/promise`, `php-http/discovery` jako plugin Composera wyłączony w `allow-plugins`, `open-telemetry/api`, `open-telemetry/context`, `symfony/polyfill-php82`; klient HTTP to `symfony/http-client` przez `Psr18Client`) | `phpunit/phpunit`, `symfony/browser-kit`, `phpstan/phpstan`, `phpstan/phpstan-symfony`, `phpstan/phpstan-doctrine`, `phpstan/phpstan-phpunit`, `deptrac/deptrac`, `friendsofphp/php-cs-fixer`, `zenstruck/foundry`, `dama/doctrine-test-bundle` |
| api (rozszerzenia PHP) | `pdo_pgsql`, `amqp`, `redis`, `intl`, `opcache`, `pcntl` | `spx` |
| store (npm) | `next`, `react`, `react-dom`, `openid-client`, `redis`, `openapi-fetch`, `radix-ui`, `class-variance-authority`, `clsx`, `tailwind-merge`, `lucide-react` | `typescript`, `@types/node`, `@types/react`, `@types/react-dom`, `tailwindcss`, `@tailwindcss/postcss`, `tw-animate-css`, `eslint`, `eslint-config-next`, `eslint-plugin-better-tailwindcss`, `openapi-typescript`, `vitest`, `@vitejs/plugin-react`, `@testing-library/react`, `jsdom` |
| admin (npm) | `vue`, `vue-router`, `pinia`, `primevue`, `@primeuix/themes`, `primeicons`, `oidc-client-ts`, `openapi-fetch` | `vite`, `@vitejs/plugin-vue`, `typescript`, `vue-tsc`, `eslint`, `typescript-eslint`, `eslint-plugin-vue`, `openapi-typescript`, `vitest`, `@vue/test-utils`, `jsdom` |
| e2e (npm) | — | `@playwright/test`, `otpauth`, `typescript` |
| obrazy | — | `socat` w obrazie `e2e` |

CLI `shadcn` jest uruchamiane w kontenerze `store` (`npx shadcn@latest`) tylko do dodawania komponentów i nie jest
zależnością projektu.
