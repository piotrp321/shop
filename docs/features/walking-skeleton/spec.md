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
3. Sklep pokazuje drzewo kategorii i siatkę produktów ze zdjęciami (AVIF/WebP z imgproxy). Kliknięcie kategorii
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
| `postgres` | PostgreSQL 18 | bazy `shop`, `shop_test` (API) i `keycloak` | `pg_isready` |
| `redis` | Redis 8 | sesje sklepu; sonda w dashboardzie | `redis-cli ping` |
| `elasticsearch` | Elasticsearch 9, jeden węzeł, security wyłączone lokalnie, `ES_JAVA_OPTS=-Xms512m -Xmx512m` | indeks `products` | `_cluster/health` (yellow lub green) |
| `rabbitmq` | RabbitMQ 4 z wtyczką management | transport `events` Messengera; vhost `/` dla dev, vhost `test` dla testów | `rabbitmq-diagnostics ping` |
| `rabbitmq-init` | jednorazowy | idempotentnie, przez Management API: vhost `test` i uprawnienia użytkownika aplikacji | kończy się kodem 0 |
| `keycloak` | Keycloak 26, `start-dev --import-realm`, baza `keycloak` w Postgresie, ograniczony heap | realmy `shop` i `backoffice` | `/health/ready` na porcie zarządzania |
| `garage` | Garage 2 | bucket `product-images` (i `product-images-test`) z oryginałami zdjęć | `garage status` (obraz nie ma powłoki ani curl, a admin API `/health` zwraca 503 do czasu zastosowania układu przez `garage-init`) |
| `garage-init` | Garage 2 (jednorazowy) | idempotentnie: układ węzła, import klucza z `.env.local`, buckety, uprawnienia | kończy się kodem 0 |
| `imgproxy` | imgproxy 3, źródło S3 → Garage | przetwarzanie obrazów na żądanie | `imgproxy health` |
| `mailpit` | Mailpit | SMTP dla realmu `shop` (weryfikacja e-mail, reset hasła) | `/readyz` |
| `api` | własny obraz FrankenPHP, PHP 8.5, target `dev` (z SPX) | HTTP API | `GET /health` |
| `worker` | ten sam obraz co `api` | `messenger:consume outbox events` | proces konsumenta działa |
| `store` | Node 24, `next dev` | sklep | `GET /` |
| `admin` | Node 24, `vite dev` | panel | `GET /` |
| `e2e` | obraz Playwright, profil `e2e` | testy E2E, uruchamiany tylko przez `make e2e` | — |

Aplikacje zależą od usług przez `depends_on: condition: service_healthy`. `make up` używa `docker compose up -d --wait` dla wszystkich usług poza `rabbitmq-init`, który uruchamia potem przez `docker compose run --rm` (`up --wait` traktuje zakończoną usługę jednorazową, na którą nic nie czeka, jako błąd).

Obraz API zawiera rozszerzenia `pdo_pgsql`, `amqp`, `redis`, `intl`, `opcache`, a target `dev` dodatkowo SPX.
Entrypoint `api` w trybie dev instaluje zależności, jeśli zmienił się lock, uruchamia migracje i tworzy indeks
Elasticsearch, jeśli go nie ma (`catalog:search-index:setup`, idempotentne).

`node_modules` i `vendor` są w wolumenach Dockera.

### Adresy: przeglądarka i kontenery

- Przeglądarka używa `http://localhost:<port>`. Kontenery rozmawiają ze sobą po nazwach usług.
- Keycloak: `KC_HOSTNAME=http://localhost:${KEYCLOAK_PORT}` i `KC_HOSTNAME_BACKCHANNEL_DYNAMIC=true`. Wystawca
  tokenów (`iss`) jest zawsze adresem publicznym, a API i serwer sklepu pobierają discovery, JWKS i wymieniają
  kod na token przez `http://keycloak:8080`.
- API sprawdza `iss` z publicznym adresem Keycloak i buduje adresy obrazów z publicznym adresem imgproxy.
- Domyślne porty na hoście: sklep 3000, panel 5173, API 8000, Keycloak 8080, imgproxy 8081, Mailpit 8025,
  RabbitMQ management 15672. Każdy port jest zmienną w `.env` i jest wystawiony tylko na `127.0.0.1`.

### Profile i sekrety

- `.env` (w `.gitignore`) to lokalny profil Compose: `COMPOSE_PROJECT_NAME` i porty. Dzięki temu w osobnych
  worktree może działać kilka stosów naraz. Wszystkie zmienne mają wartości domyślne w compose i są opisane
  w `.env.example`.
- `.env.local` (w `.gitignore`) zawiera sekrety lokalne. Tworzy go `infra/scripts/init-env.sh` przy pierwszym
  `make up`, jeśli plik nie istnieje: klucz i sól imgproxy, klucz dostępu i sekret Garage, sekret klienta `store`,
  sekret sesji sklepu, hasło administratora Keycloak, hasła do Postgresa i RabbitMQ.
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
`docker compose run --rm --no-deps`, więc analiza statyczna i testy jednostkowe działają bez całego stosu.

| Makefile | Cele |
|---|---|
| główny | `up`, `down`, `logs`, `ps`, `seed`, `check`, `test`, `e2e`, `api-client`, `keycloak-reset` |
| `apps/api` | `check`, `test`, `test-unit`, `migrate`, `migration`, `openapi`, `shell` |
| `apps/store` | `check`, `test`, `test-unit`, `shell` |
| `apps/admin` | `check`, `test`, `shell` |

- Główne `check` i `test` uruchamiają cele wszystkich trzech aplikacji.
- `test` wymaga postawionego stosu. `test-unit` nie wymaga żadnej usługi.
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
| `Catalog` | `Category`, `Product`, value objecty, zdarzenie `ProductCreated`, interfejsy repozytoriów | komendy `CreateCategory`, `CreateProduct`; zapytania `ListProducts`, `GetCategoryTree`, `GetCatalogStatistics`; handler zdarzenia `IndexProduct`; porty `ProductSearch`, `ProductIndexer`, `ProductImageStorage`, `ImageUrlGenerator`; lista rozmiarów `ImageSize` | Doctrine (mapowanie XML), adapter Elasticsearch, Flysystem → Garage, generator podpisanych adresów imgproxy, loader danych demo | `GET /api/v1/products`, `GET /api/v1/categories`, komendy konsoli |
| `Customer` | — | — | `CustomerUser` i `CustomerUserProvider` (z tokena realmu `shop`) | `GET /api/v1/me` |
| `Backoffice` | — | zapytanie `GetDashboard`; porty `CatalogStatistics`, `SystemHealth`, `MessagingStatistics` | `StaffUser` i `StaffUserProvider` (z tokena realmu `backoffice`), `DashboardVoter`, adaptery portów | `GET /api/v1/admin/me`, `GET /api/v1/admin/dashboard` |
| `Shared` | interfejs-znacznik `DomainEvent`, interfejsy-znaczniki wyjątków `ProblemException`, `NotFound`, `Conflict`, `InvalidInput` | — | konfiguracja szyn Messengera, przekaźnik outboxa, problem details, nagłówki cache, `GET /health`, sondy usług | — |

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

**`Product`** (agregat)
- `ProductId` (UUID), `ProductName` (niepusta, do 200 znaków), `Slug` (`^[a-z0-9]+(-[a-z0-9]+)*$`, do 200 znaków,
  unikalny), `ShortDescription` (do 500 znaków), `categoryId` (`CategoryId`, jedna kategoria główna),
  `TaxCategory`, lista `ProductImage`.
- `TaxCategory`: `standard`, `food`, `food_supplement`, `printed_publication`. Produkt ma kategorię podatkową,
  a nie stawkę.
- `ProductImage`: `ImageKey` (klucz oryginału w magazynie), `alt` (niepusty), pozycja. Najwyżej 10 zdjęć,
  pozycje ciągłe od 0. Pierwsze zdjęcie jest główne. W bazie lista zdjęć to kolumna JSONB.
- `Product::create(...)` zapisuje w agregacie zdarzenie `ProductCreated(productId, occurredAt)`.
- Handler `CreateProduct` sprawdza, że kategoria istnieje, a slug jest wolny. Unikalność sluga gwarantuje też
  ograniczenie w bazie.

Identyfikatory nadaje wywołujący komendę (komendy przyjmują `id`). Domena nigdy nie wylicza identyfikatora
z innych pól. Seed używa UUID v5 ze sluga (opis niżej), przyszłe kontrolery będą nadawać UUID v7.

W szkielecie katalog nie ma edycji ani usuwania, istnieje tylko tworzenie.

### Zdarzenia: outbox → RabbitMQ → handler

Szyny Messengera: `command.bus` (middleware walidacji i `doctrine_transaction`), `query.bus`, `event.bus`.

Transporty:

| Transport | DSN | Rola |
|---|---|---|
| `outbox` | Doctrine, to samo połączenie co agregaty, `queue_name=outbox` | transactional outbox |
| `events` | AMQP (RabbitMQ), serializer JSON | dystrybucja zdarzeń do odbiorców |
| `failed` | Doctrine, `queue_name=failed` | wiadomości po wyczerpaniu ponowień |

Przepływ:

1. Handler komendy zapisuje agregat i publikuje jego zdarzenia na `event.bus`. Każdy `DomainEvent` jest
   kierowany na transport `outbox`, więc wiersz w outboxie powstaje w tej samej transakcji co agregat.
   Wycofanie transakcji usuwa oba.
2. `worker` konsumuje `outbox`. Jedyny handler z `from_transport: outbox` to przekaźnik, który wysyła to samo
   zdarzenie dalej z `TransportNamesStamp(['events'])`.
3. `worker` konsumuje `events`. Handlery odbiorców mają `from_transport: events`, w szkielecie jest to
   `IndexProduct` dla `ProductCreated`.
4. Każdy handler zdarzenia ma jawny `from_transport`. Przekaźnik nigdy nie działa przy konsumpcji z `events`,
   a odbiorcy nigdy przy konsumpcji z `outbox`, więc nie ma pętli ani podwójnej obsługi.
5. Ponowienia: 3 próby z opóźnieniem 1 s i mnożnikiem 3, potem transport `failed`.

Zdarzenia niosą tylko identyfikatory i czas. `IndexProduct` czyta aktualny stan produktu i przodków jego kategorii
z Postgresa i robi upsert dokumentu po `id`, więc jest idempotentny i niezależny od kolejności zdarzeń.

### Indeks Elasticsearch

- Alias `products` wskazuje na fizyczny indeks `products_<znacznik czasu>`. Elasticsearch jest wyłącznie
  modelem odczytu.
- Dokument: `id`, `name` (text oraz pole `name.sort` typu keyword z normalizerem lowercase + asciifolding),
  `slug`, `short_description` (nieindeksowane), `category_id`, `category_ancestor_ids` (keyword, zawiera
  własną kategorię i wszystkich przodków), `main_image` (`key`, `alt`; nieindeksowane).
- `catalog:reindex` odbudowuje indeks z Postgresa: nowy indeks fizyczny, zasilenie, przepięcie aliasu, usunięcie
  starego.
- `catalog:search-index:setup` tworzy indeks i alias, jeśli nie istnieją.

### Obrazy

- Oryginały są w prywatnym buckecie `product-images` w Garage, zapisywane przez port `ProductImageStorage`
  (Flysystem z adapterem S3).
- Klucz oryginału zależy od treści: `originals/<sha256>.jpg`. Adres obrazu dla danej treści i rozmiaru nigdy się
  nie zmienia, a to samo zdjęcie jest zapisane raz.
- Lista rozmiarów jest zamknięta i zdefiniowana w jednym miejscu: enum `ImageSize` w `Catalog\Application`.
  Każdy rozmiar określa proporcje, sposób skalowania, szerokości i formaty. W szkielecie istnieje jeden rozmiar:

  | Rozmiar | Proporcje | Skalowanie | Szerokości | Formaty |
  |---|---|---|---|---|
  | `listing` | 1:1 | `fill`, gravity `sm` | 256, 384, 512, 768 | AVIF, WebP |

- Format jest częścią podpisanego adresu (rozszerzenie w ścieżce imgproxy). Każda para format × szerokość ma
  osobny, jednoznaczny adres. imgproxy nie negocjuje formatu po nagłówku `Accept`, bo CDN przed nim
  (darmowy Cloudflare) nie obsługuje Vary for Images.
- Adresy podpisuje HMAC-em jeden serwis w API (implementacja portu `ImageUrlGenerator`). Podział sekretów
  per usługa (klucz i sól imgproxy tylko dla `api` i `imgproxy`) obowiązuje przy wdrożeniu, lokalnie opisuje go
  sekcja „Profile i sekrety”.
- imgproxy czyta wyłącznie z `s3://product-images/` (`IMGPROXY_ALLOWED_SOURCES`) i zwraca długi `Cache-Control`,
  bo adresy są niezmienne.

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
- Filtr `category` obejmuje kategorię i wszystkie jej podkategorie (`category_ancestor_ids`). Nieistniejąca
  kategoria daje 200 i pustą listę (API nie odpytuje Postgresa przy odczycie z modelu odczytu).
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
          "avif": [{ "width": 256, "url": "http://localhost:8081/…" }, { "width": 384, "url": "…" }],
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
    "queues": [{ "name": "events", "messages": 0, "consumers": 1 }],
    "searchIndex": { "indexedProducts": 412, "catalogProducts": 412, "inSync": true }
  }
}
```

- `system` jest `null`, gdy `DashboardVoter` odmówi atrybutu `VIEW_SYSTEM_STATUS` (wymaga roli `admin`).
- `services` zawsze w tej kolejności: `postgres`, `redis`, `elasticsearch`, `rabbitmq`, `keycloak`, `garage`,
  `imgproxy`, `mailpit`. Sondy działają równolegle, każda z limitem 1 s. Niedostępna usługa ma `status: "down"`
  i `latencyMs: null`; endpoint nadal zwraca 200.
- `queues` pochodzi z Management API RabbitMQ, `outbox.pending` z tabeli transportu `outbox`.
- Częściowa awaria nie psuje dashboardu: niedostępne Management API RabbitMQ daje `queues: null`, niedostępny
  Elasticsearch daje `searchIndex.indexedProducts: null` i `inSync: false`. Niedostępny Postgres (źródło sekcji
  `catalog`) daje 503 jako problem details.

### Uwierzytelnianie i uprawnienia

Wbudowany w Symfony authenticator `access_token` z handlerem `oidc`. Dwa bezstanowe firewalle, sprawdzane
w tej kolejności:

| Firewall | Ścieżki | Discovery / JWKS | Wymagany `iss` | Wymagane `aud` | Użytkownik |
|---|---|---|---|---|---|
| `admin` | `^/api/v1/admin` | `http://keycloak:8080/realms/backoffice` | `${KEYCLOAK_PUBLIC_URL}/realms/backoffice` | `backoffice-api` | `StaffUser` |
| `shop` | `^/api/v1` | `http://keycloak:8080/realms/shop` | `${KEYCLOAK_PUBLIC_URL}/realms/shop` | `shop-api` | `CustomerUser` |

- Tylko RS256. Discovery i JWKS są cache'owane.
- `StaffUser`: `sub`, `preferred_username`, role z `realm_access.roles` przycięte do `admin`, `support`,
  `warehouse`, `marketing`; dostaje `ROLE_STAFF`.
- `CustomerUser`: `sub`, `email`, `name`; dostaje `ROLE_CUSTOMER`.
- `StaffUser` i `CustomerUser` to osobne klasy, bez wspólnej klasy bazowej.
- Endpointy publiczne mają `PUBLIC_ACCESS`. Przesłanie nieprawidłowego tokena do endpointu publicznego też daje 401.
- Brak tokena, zły wystawca (w tym token z drugiego realmu), zła audience, wygasły lub uszkodzony token:
  401 jako problem details z nagłówkiem `WWW-Authenticate: Bearer`.
- Środowisko `test`: handler `oidc` dostaje stały `keyset` z env zamiast discovery. Bootstrap testów generuje
  w pamięci parę kluczy RSA, a `TestTokenFactory` podpisuje nią tokeny obu realmów. W repo nie ma kluczy.

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
  - `HandlerFailedException` Messengera (szyna synchroniczna opakowuje wyjątek handlera) z dokładnie jednym
    opakowanym wyjątkiem jest zastępowana tym wyjątkiem i obowiązują powyższe reguły. To jedyny wyjątek od zasady,
    że łańcuch `previous` nie jest przeglądany. Przy wielu opakowanych wyjątkach: 500 bez `detail`,
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
| `catalog:search-index:setup` | tworzy indeks i alias `products`, jeśli nie istnieją |
| `catalog:reindex` | odbudowuje indeks z Postgresa z przepięciem aliasu |
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
  (informacja o licencji MIT, dane tylko z naszego API, obrazy przez `ProductPicture`).

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

- `ProductPicture` składa `<picture>`: `<source type="image/avif">` i `<source type="image/webp">` ze `srcset`
  z wariantów API, `<img>` z wariantem WebP jako fallback, atrybut `sizes` zgodny z siatką.
- Atrybuty powstają przez `getImageProps()` z `next/image` z loaderem, który wybiera gotowy adres z wariantów
  dla żądanej szerokości. Front niczego nie podpisuje ani nie dobiera rozmiarów spoza odpowiedzi API.
- Globalny loader obrazów Next.js (`images.loader: 'custom'`) rzuca błąd, więc użycie `<Image>` bez loadera
  wariantów jest niemożliwe, a wbudowana optymalizacja nigdy nie przetwarza obrazów.

### Logowanie (BFF)

Tokeny nigdy nie trafiają do przeglądarki. Serwer sklepu jest klientem confidential `store`
(`openid-client`), sesje są w Redisie.

| Route handler | Działanie |
|---|---|
| `GET /auth/login?returnTo=…` | generuje `state`, `nonce` i weryfikator PKCE; zapisuje je w Redisie pod jednorazowym kluczem (TTL 10 min), którego id trafia do krótkiego ciasteczka; przekierowuje do Keycloak (adres publiczny) |
| `GET /auth/callback` | sprawdza `state`; wymienia kod na tokeny przez `http://keycloak:8080`; waliduje `id_token`; tworzy sesję i przekierowuje na `returnTo` |
| `GET /auth/session` | zwraca `{ "name": "…" }` albo `null`; dane z `GET /api/v1/me` wołanego z tokenem z sesji; 401 z API usuwa sesję |
| `POST /auth/logout` | usuwa sesję i ciasteczko; przekierowuje do `end_session` Keycloak z `id_token_hint` i `post_logout_redirect_uri` |

- `returnTo` jest akceptowany tylko jako ścieżka w obrębie sklepu: zaczyna się od pojedynczego `/`, nie od `//`,
  nie zawiera `\` ani znaków sterujących, a `new URL(returnTo, STORE_URL).origin` równa się `STORE_URL`.
  Każda inna wartość (także brak) daje przekierowanie na `/`.
- Sesja: klucz `session:<losowe id>` w Redisie z `sub`, tokenami i czasem wygaśnięcia access tokena; TTL równy
  czasowi życia refresh tokena. Ciasteczko `store_session` zawiera tylko id: `HttpOnly`, `SameSite=Lax`,
  `Path=/`, `Secure` poza `localhost`.
- Odświeżanie: gdy access token wygasa za mniej niż 30 s, serwer odświeża go pod blokadą
  `SET lock:refresh:<id sesji> <losowy token> NX PX 5000`. Zwycięzca odświeża tokeny, zapisuje sesję i zwalnia
  blokadę (usunięcie tylko przy zgodnym tokenie). Pozostałe żądania co 100 ms czytają sesję, aż zmieni się
  czas wygaśnięcia, i używają nowego tokena. Jeśli przez 5 s nic się nie zmieni, sesja jest traktowana jak
  wylogowana. Ciasteczko się nie zmienia.
- Wszystkie odpowiedzi `/auth/*` mają `Cache-Control: private, no-store`.
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
- Middleware wygenerowanego klienta dodaje `Authorization: Bearer …`; odpowiedź 401 wywołuje `signinRedirect()`.
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

- Branża: sport i outdoor. Drzewo kategorii na 3–4 poziomy z kategoriami głównymi: bieganie, rower, turystyka,
  sporty zimowe, fitness, sporty wodne, sporty drużynowe.
- `apps/api/fixtures/catalog.yaml` definiuje drzewo kategorii oraz szablony produktów dla kategorii: fikcyjne marki
  własne sklepu, modele, kolory, szablony opisów, kategorię podatkową i pulę zdjęć.
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
| api | domena: ścieżka kategorii, niezmienniki produktu, `TaxCategory`, `Slug`; handlery z adapterami in-memory; routing Messengera na transportach `in-memory://` | repozytoria Doctrine; atomowość outboxa; adapter ES (filtr po przodkach, sortowanie, paginacja); zapis do Garage; podpis imgproxy na żywym imgproxy; droga seed → outbox → RabbitMQ → ES; sondy usług; testy funkcjonalne endpointów |
| store | reguły ESLint (kolory, klasy spoza tokenów, `fetch`); walidacja `returnTo`; budowa `<picture>` z wariantów; `CategoryTree`; `ProductGrid` | blokada odświeżania na prawdziwym Redisie |
| admin | dashboard; `useIdleLogout`; 401 → `signinRedirect`; guard routera | — |

Wymagane przypadki:

- **Routing Messengera:** konsumpcja `outbox` umieszcza dokładnie jedną wiadomość na `events` i nie wywołuje
  `IndexProduct`; konsumpcja `events` wywołuje `IndexProduct` raz i nie umieszcza niczego na `outbox` ani `events`.
- **Atomowość outboxa:** wyjątek po zapisaniu zdarzenia, a przed commitem, zostawia zero produktów i zero wierszy
  w outboxie; udany commit zostawia jeden produkt i jeden wiersz.
- **imgproxy:** podpisane adresy AVIF i WebP zwracają 200 z właściwym typem; zmieniony adres zwraca 403.
- **Endpointy:** każdy ma test funkcjonalny. Endpointy chronione: brak tokena, token z drugiego realmu, zła
  audience, wygasły token, poprawny token. Endpointy publiczne: nagłówki `Cache-Control` i `Vary: Origin`, błędne
  parametry (400 z naruszeniami). Dashboard: rola `support` dostaje `system: null`, rola `admin` dostaje `system`.
- **Odświeżanie w sklepie:** 5 równoległych żądań z wygasającym tokenem daje dokładnie jedno wywołanie endpointu
  tokenów, a wszystkie żądania dostają nowy token.
- **Open redirect:** `//evil.com`, `/\evil.com`, `https://evil.com`, `javascript:alert(1)`, wartość pusta i brak
  wartości dają przekierowanie na `/`.
- **Bezczynność w panelu:** fake timers; wylogowanie po 15 min bez aktywności; aktywność w innej karcie
  (przez `BroadcastChannel`) odsuwa wylogowanie.

Dane testowe w API przez fabryki Foundry. `dama/doctrine-test-bundle` wycofuje transakcję po każdym teście.

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

## Konfiguracja Claude Code

- `.claude/settings.json` (wersjonowany) włącza plugin superpowers (`enabledPlugins`), hook `PostToolUse` po
  narzędziach edycji plików (`Edit`, `MultiEdit`, `Write`) i hook `Stop`.
- Hook uruchamia `.claude/hooks/check-changed-file.sh`, który sprawdza tylko aplikację, do której należy plik,
  w jej kontenerze:

  | Plik | Sprawdzenie |
  |---|---|
  | `apps/api/**/*.php` | PHPStan na zmienionym pliku i PHP CS Fixer w trybie sprawdzania (`--dry-run --diff`, hook nie zmienia pliku) |
  | `apps/store/**`, `apps/admin/**` (`.ts`, `.tsx`, `.vue`, `.js`, `.mjs`) | ESLint na zmienionym pliku |

- Znalezione problemy trafiają na stderr z kodem wyjścia 2, więc Claude Code przekazuje je agentowi.
  Brak problemów: kod 0 bez wyjścia.
- Plik spoza aplikacji, inne rozszerzenie, niedziałający kontener albo brak `jq` na hoście nie blokują pracy
  (kod 0).
- Ten sam skrypt zapisuje nazwę zmienionej aplikacji w pliku stanu sesji
  `.claude/state/changed-apps-<session_id>` (poza repo).
- Hook `Stop` (`.claude/hooks/run-unit-tests.sh`, limit 300 s) na koniec tury agenta uruchamia testy jednostkowe
  tylko aplikacji z pliku stanu: `make -C apps/api test-unit`, `make -C apps/store test-unit`,
  `make -C apps/admin test`.
  - Testy przechodzą: plik stanu i licznik blokad są czyszczone, kod 0.
  - Testy nie przechodzą: końcówka wyniku na stderr i kod 2, więc agent nie kończy tury i poprawia błąd.
    Plik stanu zostaje, więc kolejny `Stop` uruchomi testy ponownie.
  - Ochrona przed pętlą: po 3 kolejnych blokadach w tej samej sesji hook kończy się kodem 0 z informacją
    o nieprzechodzących testach. Plik stanu zostaje.
  - Brak pliku stanu albo niedziałający kontener: kod 0 bez uruchamiania testów.
- Hooki nie zastępują `make check` ani pełnych testów przed zakończeniem zadania.

## CI

`.github/workflows/ci.yml`, uruchamiany przy pull requestach i pushu na `main`:

1. Budowa obrazów deweloperskich z cache buildx.
2. `make check` z `RUN="docker compose run --rm --no-deps"`.
3. Testy jednostkowe: `make -C apps/api test-unit`, `make -C apps/store test-unit`, `make -C apps/admin test`.
4. Aktualność kontraktu: `make -C apps/api openapi` i `make api-client`, potem `git diff --exit-code` na
   `apps/api/openapi.json`, `apps/api/openapi-admin.json` i obu `schema.d.ts`.

## Zależności

Akceptacja tej specyfikacji jest zgodą na poniższą listę. Każda inna zależność wymaga osobnego pytania.

| Gdzie | Produkcyjne | Deweloperskie |
|---|---|---|
| api (Composer) | `symfony/flex`, `symfony/framework-bundle`, `symfony/runtime`, `symfony/console`, `symfony/dotenv`, `symfony/yaml`, `symfony/security-bundle`, `symfony/messenger`, `symfony/amqp-messenger`, `symfony/doctrine-messenger`, `symfony/serializer`, `symfony/property-access`, `symfony/property-info`, `symfony/validator`, `symfony/uid`, `symfony/http-client`, `symfony/monolog-bundle`, `doctrine/doctrine-bundle`, `doctrine/orm`, `doctrine/doctrine-migrations-bundle`, `nelmio/api-doc-bundle`, `nelmio/cors-bundle`, `web-token/jwt-library`, `league/flysystem-bundle`, `league/flysystem-async-aws-s3`, `elasticsearch/elasticsearch` | `phpunit/phpunit`, `symfony/browser-kit`, `phpstan/phpstan`, `phpstan/phpstan-symfony`, `phpstan/phpstan-doctrine`, `phpstan/phpstan-phpunit`, `deptrac/deptrac`, `friendsofphp/php-cs-fixer`, `zenstruck/foundry`, `dama/doctrine-test-bundle` |
| api (rozszerzenia PHP) | `pdo_pgsql`, `amqp`, `redis`, `intl`, `opcache` | `spx` |
| store (npm) | `next`, `react`, `react-dom`, `openid-client`, `redis`, `openapi-fetch`, `radix-ui`, `class-variance-authority`, `clsx`, `tailwind-merge`, `lucide-react` | `typescript`, `@types/node`, `@types/react`, `@types/react-dom`, `tailwindcss`, `@tailwindcss/postcss`, `tw-animate-css`, `eslint`, `eslint-config-next`, `eslint-plugin-better-tailwindcss`, `openapi-typescript`, `vitest`, `@vitejs/plugin-react`, `@testing-library/react`, `jsdom` |
| admin (npm) | `vue`, `vue-router`, `pinia`, `primevue`, `@primeuix/themes`, `primeicons`, `oidc-client-ts`, `openapi-fetch` | `vite`, `@vitejs/plugin-vue`, `typescript`, `vue-tsc`, `eslint`, `typescript-eslint`, `eslint-plugin-vue`, `openapi-typescript`, `vitest`, `@vue/test-utils`, `jsdom` |
| e2e (npm) | — | `@playwright/test`, `otpauth`, `typescript` |
| obrazy | — | `socat` w obrazie `e2e` |

CLI `shadcn` jest uruchamiane w kontenerze `store` (`npx shadcn@latest`) tylko do dodawania komponentów i nie jest
zależnością projektu.
