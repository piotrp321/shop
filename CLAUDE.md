# CLAUDE.md

Monorepo e-commerce. Przed planowaniem jakiejkolwiek funkcji przeczytaj `docs/vision.md`.

## Język

- Dokumentacja, specyfikacje, plany i rozmowa: po polsku.
- Kod po angielsku: nazwy klas, metod, zmiennych, komentarze, komunikaty commitów.

## Struktura

- `apps/api` – Symfony 8, PHP 8.5, Doctrine, PostgreSQL. DDD + hexagonal.
- `apps/store` – Next.js (App Router), React, TypeScript, Tailwind CSS 4, shadcn/ui. Sklep, strony katalogu
  renderowane na serwerze pod SEO.
- `apps/admin` – Vue 3 (Composition API), Vite, TypeScript, Pinia, Vue Router, PrimeVue. Panel administracyjny.
- `infra` – Docker Compose i konfiguracja usług
  (Postgres, Redis, Elasticsearch, RabbitMQ, Keycloak, Garage, imgproxy, Varnish, Mailpit).
- `e2e` – testy end-to-end (Playwright) na pełnym stosie, uruchamiane przez `make e2e`.
- `docs` – wizja, ADR-y (`docs/adr/`), specyfikacje i plany funkcji (`docs/features/`).

Każda aplikacja ma własny `CLAUDE.md` z zasadami tylko dla niej. Przeczytaj go przed pracą w tej aplikacji.
Przy generowaniu szkieletu aplikacji (`symfony new`, `create-next-app`, `npm create vite`) zachowaj istniejący
`CLAUDE.md` w jej katalogu.

## Środowisko

- Wszystko działa w Dockerze. Nigdy nie uruchamiaj `php`, `composer`, `npm` ani `node` bezpośrednio na hoście.
- Komendy uruchamiaj przez Makefile, który opakowuje `docker compose exec`:
  - Główny `Makefile` zarządza stosem i uruchamia wszystkie aplikacje:
    `make up` / `make down` / `make logs`, `make seed`, `make check`, `make test`, `make e2e`.
  - Każda aplikacja ma własny `Makefile` z tymi samymi podstawowymi celami (`check`, `test`)
    oraz swoimi (np. `make migrate` w api). Pracując w jednej aplikacji, używaj jej Makefile:
    `make -C apps/api check`.
  - `check` = statyczna analiza i lint (api: PHPStan, Deptrac, PHP CS Fixer; store/admin: ESLint, tsc).
- `node_modules` i `vendor` są w wolumenach Dockera, nie na hoście.
- Każdy worktree ma lokalny `.env` (poza repo) z własnym `COMPOSE_PROJECT_NAME` i wolnymi portami, żeby stosy
  z różnych worktree nie kolidowały ze sobą ani z innymi projektami.

## Kontrakt API

- Źródłem prawdy jest OpenAPI wyeksportowane do dwóch plików: `apps/api/openapi.json` (sklep: endpointy
  publiczne i klienta) i `apps/api/openapi-admin.json` (panel: `/api/v1/admin`). Klient TS sklepu jest generowany
  tylko z pierwszego, klient panelu tylko z drugiego.
- Po każdej zmianie endpointu lub DTO w API: wyeksportuj specyfikacje (`make -C apps/api openapi`)
  i wygeneruj klientów TS (`make api-client`). Commituj te zmiany razem ze zmianą w API.
- Fronty używają wyłącznie wygenerowanego klienta. Nie pisz ręcznie typów odpowiedzi API.

## Obrazy

- Oryginały zdjęć są w Garage (prywatny bucket), serwowane wyłącznie przez imgproxy.
- Podpisane adresy imgproxy buduje API. Klucz podpisu nigdy nie trafia do frontów.
- Rozmiary obrazów są zdefiniowane w jednym miejscu. Nie dodawaj nowego rozmiaru w komponencie, dopisz go do listy.

## Sposób pracy

- Nie ma kodu bez planu. Przy wszystkim większym niż drobna poprawka: napisz lub zaktualizuj specyfikację
  w `docs/features/<funkcja>/`, zaproponuj plan, poczekaj na akceptację.
- Testy przed kodem obowiązują dla logiki domeny i aplikacji oraz poprawek błędów. Konfigurację i infrastrukturę
  weryfikujemy komendami z planu, bez sztucznego odtwarzania fazy RED.
- Pracuj małymi krokami. Jedna logiczna zmiana na commit, format Conventional Commits.
- Zadania ustawiające reguły (lint, Deptrac, CORS i podobne) sprawdzają też próby obejścia, nie tylko
  przypadek podstawowy.
- Zadanie jest skończone dopiero, gdy przechodzą `make check` i testy aplikacji, której dotyczy zmiana.
  Pokaż wynik, nie deklaruj.
- Zmiana dotykająca kilku aplikacji (np. nowy endpoint i ekran w panelu) jest skończona, gdy przechodzą
  `check` i testy wszystkich tych aplikacji.
- Skrypty, komendy i cele Makefile testujemy także na ścieżce błędu (brak pliku, błąd narzędzia, niedziałająca
  usługa, brak zależności), nie tylko na poprawnej. Pokazujemy wynik obu ścieżek.
- Nie dodawaj nowych zależności bez pytania.
- Po `composer require` z Fleksem przejrzyj zmiany wprowadzone przez przepisy i usuń zbędne: sekrety w `.env`,
  `AGENTS.md`, pliki konfiguracji, których zadanie nie potrzebuje.
- Jeśli zasada z tego pliku koliduje z tym, czego wymaga zadanie, zatrzymaj się i zapytaj, zamiast ją obchodzić.

## Claude Code

- Wspólna konfiguracja jest w `.claude/settings.json`: włączony plugin superpowers. Lokalne ustawienia trzymaj
  w `.claude/settings.local.json` (poza repo).

## Dokumentacja

- Dokumentacja opisuje wyłącznie stan obecny. Nie prowadzimy dzienników zmian, historii decyzji
  ani sekcji „wcześniej było…”. Historia jest w gicie.
- Zmiana architektury, zasady lub działania funkcji = aktualizacja dokumentacji w tym samym commicie co kod.
- Gdy coś usuwamy z projektu (usługę, funkcję, decyzję), usuwamy to też ze wszystkich dokumentów:
  `docs/vision.md`, `CLAUDE.md` (główny i aplikacji), `docs/adr/`, `docs/features/`.
- `docs/adr/` – jeden plik na obowiązującą decyzję: kontekst, decyzja, uzasadnienie, konsekwencje.
  Zmiana decyzji to edycja pliku, porzucenie decyzji to usunięcie pliku. Bez statusów „zastąpiona” ani „wycofana”.
- `docs/features/<funkcja>/spec.md` – opisuje, jak funkcja działa teraz. Utrzymywana razem z kodem.
- Plan implementacji jest dokumentem roboczym. Po ukończeniu funkcji usuń go.

## Czego nie robić

- Nie umieszczaj logiki biznesowej we frontendzie. Fronty wyświetlają to, co policzy API (ceny, sumy, dostępność).
- Nie commituj sekretów. Lokalna konfiguracja trafia do `.env.local`, który jest w `.gitignore`.
- Nie wyłączaj reguł lintera, PHPStana ani Deptraca, żeby przeszedł `check`. Popraw kod albo zapytaj.
