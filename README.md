# Shop („Worf”)

Sklep internetowy z branży sport i outdoor w monorepo: API, sklep dla klientów i panel administracyjny.
Projekt jest budowany jak system produkcyjny, a większość kodu piszą agenci Claude Code według specyfikacji,
z niezależnym review i CI. Cele i architektura: [docs/vision.md](docs/vision.md).

## Stack

| Część | Technologie |
|---|---|
| `apps/api` | PHP 8.5, Symfony 8, Doctrine, PostgreSQL; DDD i architektura heksagonalna |
| `apps/store` | Next.js (App Router), React, TypeScript, Tailwind CSS 4, shadcn/ui |
| `apps/admin` | Vue 3, Vite, TypeScript, Pinia, Vue Router, Tailwind CSS 4, shadcn-vue (Reka UI), Lucide, PrimeVue |
| `infra` | Docker Compose: PostgreSQL, Redis, Elasticsearch, RabbitMQ, Keycloak, Garage, imgproxy, Varnish, Mailpit |

## Wymagania

- Docker z Docker Compose, `make` i `openssl`. PHP i Node działają w kontenerach, na hoście ich nie instaluj.
- Pamięć dla Docker Desktop (Settings → Resources → Memory): **minimum 5 GB, zalecane 7 GB**. Wartości wynikają
  z pomiaru `docker stats` na pełnym stosie (12 kontenerów aplikacji): po `make up` suma wynosi około 2,3 GB
  (Elasticsearch około 0,9 GB, Keycloak około 0,6 GB, panel około 0,3 GB), a szczyt podczas `make e2e`, razem
  z kontenerem Playwright, około 3,6 GB (3659 MiB). Minimum to szczyt zaokrąglony w górę do pełnego GB (4 GB) plus
  1 GB zapasu, zalecane to minimum plus 2 GB. Kilka stosów naraz wymaga odpowiednio więcej.
- Pierwsze `make up` buduje obrazy i pobiera zależności, więc trwa dłużej niż kolejne.

## Uruchomienie

```bash
make up     # przy pierwszym uruchomieniu tworzy .env.local z sekretami; czeka, aż usługi będą zdrowe
make seed   # czyści katalog i ładuje dane demo (kategorie, produkty ze zdjęciami); czeka na indeks
make down   # zatrzymuje stos (dane zostają w wolumenach)
```

Adresy przy domyślnych portach:

| Adres | Co | Zmienna portu |
|---|---|---|
| http://localhost:3000 | sklep | `STORE_PORT` |
| http://localhost:5173 | panel administracyjny | `ADMIN_PORT` |
| http://localhost:8000/health | API (`/health`) | `API_PORT` |
| http://localhost:8080 | Keycloak | `KEYCLOAK_PORT` |
| http://localhost:8081 | imgproxy (adresy zdjęć podpisuje API) | `IMGPROXY_PORT` |
| http://localhost:8025 | Mailpit (e-maile z Keycloak i potwierdzenia zamówień z API) | `MAILPIT_PORT` |
| http://localhost:15672 | RabbitMQ, panel zarządzania | `RABBITMQ_MANAGEMENT_PORT` |

Porty są wystawione tylko na `127.0.0.1`. Konsola administracyjna Keycloak: użytkownik `admin`, hasło
`KC_BOOTSTRAP_ADMIN_PASSWORD` z wygenerowanego `.env.local`. Panel RabbitMQ: użytkownik `shop`,
hasło `RABBITMQ_DEFAULT_PASS` z `.env.local`.

### Porty i kilka stosów naraz

Nazwę projektu Compose i porty zmienia się w `.env` w korzeniu repo (gitignorowany, wzór w `.env.example`). Każda
zmienna jest opcjonalna. Dzięki temu stosy z różnych worktree nie kolidują ze sobą, np.:

```bash
# .env
COMPOSE_PROJECT_NAME=shop-feature-x
STORE_PORT=3100
ADMIN_PORT=5273
API_PORT=8100
KEYCLOAK_PORT=8180
IMGPROXY_PORT=8181
MAILPIT_PORT=8125
RABBITMQ_MANAGEMENT_PORT=15772
```

Zmiana portów wystarcza: API, Keycloak i testy E2E biorą adresy publiczne z tych samych zmiennych. Każdy stos ma
własny `.env.local` i własne wolumeny.

### Odtworzenie stosu

Jeśli `make up` zgłosi brak klucza w `.env.local` (nowy sekret, np. hasło roli `shop` w Postgresie), stos
trzeba odtworzyć: usunąć `.env.local` i wolumeny projektu Compose (`make down` i `docker compose down -v` dla
właściwego `COMPOSE_PROJECT_NAME`). Skrypt inicjalizacji Postgresa tworzy role i bazy tylko na pustym wolumenie.

`make keycloak-reset` usuwa bazę Keycloak i importuje realmy od nowa z `infra/keycloak/realms/` (znikają konta
założone w sklepie i zmiany z konsoli Keycloak).

### Zmiana kodu handlerów

`worker` (konsument kolejek) ładuje kod i konfigurację handlerów przy starcie. Po zmianie kodu handlerów albo
ich konfiguracji uruchom go od nowa: `docker compose restart worker` (`make up` też tworzy go od nowa).

## Konta demo

Jawne dane demo, nie sekrety: występują tylko w lokalnym stosie, a ich źródłem są realmy w `infra/keycloak/realms/`.
Kod TOTP panelu: SHA1, 6 cyfr, 30 s. Sekret w base32 dodaj ręcznie w aplikacji uwierzytelniającej albo użyj URI
`otpauth://totp/Worf:<login>?secret=<sekret>&issuer=Worf&algorithm=SHA1&digits=6&period=30` (np. do wygenerowania
kodu QR).

| Realm | Login | Hasło | TOTP (base32) |
|---|---|---|---|
| `shop` | `jan.kowalski@example.com`, `anna.nowak@example.com` | `Klient-Demo-1` | — |
| `backoffice` | `admin` | `Pracownik-Demo-1` | `MRSW23ZNORXXI4BNMFSG22LOFUYDAMBR` |
| `backoffice` | `support` | `Pracownik-Demo-1` | `MRSW23ZNORXXI4BNON2XA4DPOJ2C2MBR` |
| `backoffice` | `warehouse` | `Pracownik-Demo-1` | `MRSW23ZNORXXI4BNO5QXEZLIN52XGZJR` |
| `backoffice` | `marketing` | `Pracownik-Demo-1` | `MRSW23ZNORXXI4BNNVQXE23FORUW4ZZR` |

## Komendy Makefile

| Komenda | Działanie |
|---|---|
| `make up` / `make down` | start (z oczekiwaniem na zdrowe usługi) i zatrzymanie stosu |
| `make logs` / `make ps` | logi (ogon 100 linii, na żywo) i stan kontenerów |
| `make seed` | reset katalogu i dane demo |
| `make check` | analiza statyczna i lint wszystkich aplikacji |
| `make test` | testy wszystkich aplikacji |
| `make e2e` | testy Playwright na działającym stosie (po `make up` i `make seed`) |
| `make e2e-check` | kontrola typów pakietu `e2e` (bez usług stosu) |
| `make api-client` | generowanie klientów TypeScript z OpenAPI (po `make -C apps/api openapi`) |
| `make keycloak-reset` | import realmów Keycloak od nowa |
| `make -C apps/<api\|store\|admin> <cel>` | cele jednej aplikacji, m.in. `check`, `test`, `shell` oraz w api `migrate`, `openapi` |

## Testy i kontrola jakości

```bash
make check   # PHPStan, Deptrac, PHP CS Fixer; ESLint i TypeScript w sklepie i panelu
make test    # PHPUnit i Vitest wszystkich aplikacji
make -C apps/api test   # jedna aplikacja: make -C apps/<api|store|admin> <cel>
make e2e     # testy Playwright na działającym stosie (najpierw make up i make seed)
```

Komendy działają w kontenerach postawionych przez `make up`. Wszystkie testy API, także te na usługach stosu,
wymagają działającego stosu. CI (GitHub Actions) uruchamia `make check`, testy
i testy E2E na pełnym stosie przy każdym pull requeście.

## Co działa teraz

- Wszystkie usługi startują jedną komendą, każda z health checkiem. Keycloak ma realmy `shop` (klienci)
  i `backoffice` (pracownicy, hasło i TOTP) z kontami demo.
- API: katalog (domena, Postgres), transactional outbox → RabbitMQ → worker → indeks Elasticsearch, zdjęcia
  z Garage przez imgproxy. Endpointy: `GET /api/v1/products`, `/api/v1/categories`, `/api/v1/me`,
  `/api/v1/admin/me`, `/api/v1/admin/dashboard` (sekcja `system` tylko dla roli `admin`) i `GET /health`.
  Tokeny Keycloak (JWT) są weryfikowane osobno dla klientów i pracowników. Błędy w formacie problem details
  (RFC 9457), CORS tylko dla panelu.
- Dane demo: `make seed` czyści katalog i ładuje drzewo kategorii oraz produkty ze zdjęciami.
- Sklep: strona katalogu z drzewem kategorii i siatką produktów z API, renderowana na serwerze, oraz logowanie
  klientów przez Keycloak (realm `shop`) z sesją po stronie serwera sklepu w Redisie.
- Panel: logowanie przez Keycloak (realm `backoffice`, hasło i TOTP), layout i dashboard, wylogowanie po 15
  minutach bezczynności.
- Kontrakt: OpenAPI sklepu i panelu eksportowane do plików, z nich generowani klienci TypeScript obu frontów
  (`make api-client`); reguła ESLint blokuje `fetch` poza klientem API.
- Testy E2E (Playwright, `make e2e`): katalog, logowanie klienta, rejestracja z weryfikacją e-maila, logowanie
  pracownika z TOTP, zmiana ceny w panelu widoczna w sklepie, przyjęcie towaru w panelu widoczne w sklepie (test
  przywraca stan 0 korektą, więc kolejne `make e2e` nie wymaga `make seed`).
- CI: analiza statyczna, testy trzech aplikacji, kontrola aktualności kontraktu i testy E2E na pełnym stosie.

## Dokumentacja

- [docs/vision.md](docs/vision.md): wizja, architektura i reguły domeny.
- [docs/features/](docs/features/): specyfikacje i plany funkcji.
- `CLAUDE.md` w korzeniu i w każdej aplikacji: zasady pracy, także dla agentów.
