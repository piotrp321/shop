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

Porty i nazwę projektu Compose można zmienić w `.env` (wzór w `.env.example`), np. żeby uruchomić kilka stosów
naraz z różnych worktree.

## Testy i kontrola jakości

```bash
make check   # PHPStan, Deptrac, PHP CS Fixer; ESLint i TypeScript w sklepie i panelu
make test    # PHPUnit i Vitest wszystkich aplikacji
make -C apps/api test   # jedna aplikacja: make -C apps/<api|store|admin> <cel>
```

Komendy działają w kontenerach postawionych przez `make up`. CI (GitHub Actions) uruchamia `make check` i testy
przy każdym pull requeście.

## Co działa teraz

- Wszystkie usługi startują jedną komendą, każda z health checkiem. Keycloak nie ma jeszcze realmów.
- API: `GET /health`, błędy w formacie problem details (RFC 9457), CORS tylko dla panelu, logi w JSON.
- Sklep i panel: szkielety aplikacji. Sklep ma tokeny wyglądu i reguły ESLint blokujące kolory spoza tokenów,
  oba fronty mają regułę blokującą `fetch` poza klientem API.
- CI: analiza statyczna i testy trzech aplikacji.

Katalogu produktów, logowania, danych demo ani klientów API jeszcze nie ma.

## Najbliższe etapy

1. Domena katalogu i komendy tworzenia kategorii i produktów.
2. Persystencja w PostgreSQL.
3. Elasticsearch jako model odczytu, zasilany przez transactional outbox i RabbitMQ.
4. Endpointy katalogu z OpenAPI, dane demo i strona katalogu w sklepie.

Kolejność i stan prac: sekcja „Status” w [planie](docs/features/walking-skeleton/plan.md).

## Dokumentacja

- [docs/vision.md](docs/vision.md): wizja, architektura i reguły domeny.
- [docs/features/](docs/features/): specyfikacje i plany funkcji.
- `CLAUDE.md` w korzeniu i w każdej aplikacji: zasady pracy, także dla agentów.
