# Shop („Rytm”)

Sklep internetowy z branży sport i outdoor w monorepo: API, sklep dla klientów i panel administracyjny.
Projekt jest budowany jak system produkcyjny, a większość kodu piszą agenci Claude Code według specyfikacji,
z niezależnym review i CI. Cele i architektura: [docs/vision.md](docs/vision.md).

## Stack

| Część | Technologie |
|---|---|
| `apps/api` | PHP 8.5, Symfony 8, Doctrine, PostgreSQL; DDD i architektura heksagonalna |
| `apps/store` | Next.js (App Router), React, TypeScript, Tailwind CSS 4, shadcn/ui |
| `apps/admin` | Vue 3, Vite, TypeScript, Pinia, Vue Router, PrimeVue |
| `infra` | Docker Compose: PostgreSQL, Redis, Elasticsearch, RabbitMQ, Keycloak, Garage, imgproxy, Mailpit |

## Uruchomienie

Na hoście potrzebne są tylko Docker (z Docker Compose), `make` i `openssl`. PHP i Node działają w kontenerach.

```bash
make up     # przy pierwszym uruchomieniu tworzy .env.local z sekretami; czeka, aż usługi będą zdrowe
make down
```

| Adres | Co |
|---|---|
| http://localhost:8000/health | API |
| http://localhost:3000 | sklep |
| http://localhost:5173 | panel |
| http://localhost:8025 | Mailpit |
| http://localhost:15672 | RabbitMQ (panel zarządzania) |
| http://localhost:8080 | Keycloak |

Jeśli `make up` zgłosi brak klucza w `.env.local` (nowy sekret, np. hasło roli `shop` w Postgresie), stos
trzeba odtworzyć: usunąć `.env.local` i wolumeny projektu Compose (`make down` i `docker compose down -v` dla
właściwego `COMPOSE_PROJECT_NAME`). Skrypt inicjalizacji Postgresa tworzy role i bazy tylko na pustym wolumenie.

`make keycloak-reset` usuwa bazę Keycloak i importuje realmy od nowa z `infra/keycloak/realms/` (znikają konta
założone w sklepie i zmiany z konsoli Keycloak).

Porty i nazwę projektu Compose można zmienić w `.env` (wzór w `.env.example`), np. żeby uruchomić kilka stosów
naraz z różnych worktree.

## Konta demo

Jawne dane demo, nie sekrety. Kod TOTP panelu: SHA1, 6 cyfr, 30 s; sekret w base32 do aplikacji uwierzytelniającej.

| Realm | Login | Hasło | TOTP (base32) |
|---|---|---|---|
| `shop` | `jan.kowalski@example.com`, `anna.nowak@example.com` | `Klient-Demo-1` | — |
| `backoffice` | `admin` | `Pracownik-Demo-1` | `MRSW23ZNORXXI4BNMFSG22LOFUYDAMBR` |
| `backoffice` | `support` | `Pracownik-Demo-1` | `MRSW23ZNORXXI4BNON2XA4DPOJ2C2MBR` |
| `backoffice` | `warehouse` | `Pracownik-Demo-1` | `MRSW23ZNORXXI4BNO5QXEZLIN52XGZJR` |
| `backoffice` | `marketing` | `Pracownik-Demo-1` | `MRSW23ZNORXXI4BNNVQXE23FORUW4ZZR` |

## Testy i kontrola jakości

```bash
make check   # PHPStan, Deptrac, PHP CS Fixer; ESLint i TypeScript w sklepie i panelu
make test    # PHPUnit i Vitest wszystkich aplikacji
make -C apps/api test   # jedna aplikacja: make -C apps/<api|store|admin> <cel>
make e2e     # testy Playwright na działającym stosie (najpierw make up i make seed)
```

Komendy działają w kontenerach postawionych przez `make up`. CI (GitHub Actions) uruchamia `make check`, testy
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
  pracownika z TOTP.
- CI: analiza statyczna, testy trzech aplikacji, kontrola aktualności kontraktu i testy E2E na pełnym stosie.

## Najbliższe etapy

1. Odbiór szkieletu i pełny README.

Kolejność i stan prac: sekcja „Status” w [planie](docs/features/walking-skeleton/plan.md).

## Dokumentacja

- [docs/vision.md](docs/vision.md): wizja, architektura i reguły domeny.
- [docs/features/](docs/features/): specyfikacje i plany funkcji.
- `CLAUDE.md` w korzeniu i w każdej aplikacji: zasady pracy, także dla agentów.
