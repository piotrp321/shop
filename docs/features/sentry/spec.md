# SENTRY: Sentry i trace'y aplikacji

Błędy i wydajność api, workera, sklepu i panelu w Sentry SaaS (darmowy plan), uzupełnienie logów i metryk z Grafana
Cloud (DEPLOY-OPS). SDK są nieaktywne bez DSN, więc lokalny stos, CI i smoke test działają jak dziś.

Issues: #180
Zakres: `apps/api/composer.json`, `apps/api/composer.lock`, `apps/api/symfony.lock`, `apps/api/config`, `apps/api/docker/php/conf.d/prod.ini`, `apps/api/src/Shared/Infrastructure/Observability`, `apps/api/src/Shared/Infrastructure/Http`, `apps/api/tests/Unit/Shared`, `apps/api/tests/Functional/Shared`, `apps/api/phpunit.dist.xml`, `apps/api/CLAUDE.md`, `apps/store/package.json`, `apps/store/package-lock.json`, `apps/store/next.config.ts`, `apps/store/Dockerfile`, `apps/store/app/api/site`, `apps/store/app/error.tsx`, `apps/store/src/api/client.ts`, `apps/store/src/components/SiteSwitches.tsx`, `apps/store/src/observability`, `apps/store/CLAUDE.md`, `apps/admin/package.json`, `apps/admin/package-lock.json`, `apps/admin/vite.config.ts`, `apps/admin/Dockerfile`, `apps/admin/docker`, `apps/admin/src/main.ts`, `apps/admin/src/config`, `apps/admin/src/env.d.ts`, `apps/admin/src/observability`, `apps/admin/src/stores`, `apps/admin/src/auth`, `apps/admin/src/composables`, `apps/admin/tests`, `apps/admin/CLAUDE.md`, `infra/k8s/charts/shop`, `infra/k8s/environments/ci/values.yaml`, `infra/k8s/environments/prod/values.yaml`, `infra/k8s/tests`, `infra/k8s/smoke/checks.py`, `infra/k8s/CLAUDE.md`, `infra/k8s/README.md`, `.github/workflows/deploy.yml`, `docs/vision.md`, `docs/adr/backups-observability.md`

## Cel i zakres

- Błędy serwera (wyjątki kończące się odpowiedzią 5xx, nieobsłużone wyjątki komend konsoli, wiadomości, które
  ostatecznie nie przeszły w workerze, jawne logi aplikacji od `error`) z api, workera i serwera sklepu trafiają do
  Sentry jako zdarzenia z wersją (rewizja obrazu), środowiskiem i correlation ID; każdy błąd daje jedno zdarzenie.
- Błędy w przeglądarce panelu i sklepu (nieobsłużone wyjątki i odrzucone obietnice, błąd przeglądarki złapany przez
  `app/error.tsx` sklepu) trafiają do Sentry.
- Sentry Performance: trace'y żądań HTTP api (ze spanami Doctrine, klienta HTTP i Redisa, jakie daje SDK),
  renderowania i tras sklepu po stronie serwera oraz nawigacji panelu, połączone w jeden trace między procesami
  (panel → api, serwer sklepu → api) nagłówkami `sentry-trace` i `baggage`.
- Zdarzenia, transakcje i breadcrumbs bez danych osobowych klientów, tokenów, ciasteczek i treści formularzy (model
  zagrożeń niżej).
- DSN podawany w czasie uruchomienia kontenera, osobno dla api (także worker i Joby), sklepu i panelu; jeden obraz
  działa z Sentry i bez niego.
- Mapy źródeł (source maps) sklepu i panelu wysyłane do Sentry przy budowaniu obrazów produkcyjnych na `main`,
  niepublikowane w obrazach (P3).

## Decyzje użytkownika

Użytkownik przyjął rekomendacje P1–P8 (2026-11-13) z zastrzeżeniami: dokładnie jedno zdarzenie na błąd (D5),
filtrowanie obejmuje też spany SQL/Doctrine, breadcrumbs i komunikaty wyjątków (D4), izolacja kontekstu Sentry między
wiadomościami Messengera (D6), limity Sentry w runbooku (P8), rozwiązanie proste i bez rozszerzania zakresu.

- **P1. Region i projekty.** Organizacja Sentry w regionie UE (`*.ingest.de.sentry.io`), trzy projekty: `api` (api,
  worker, Joby), `store`, `admin`.
- **P2. Przeglądarka sklepu.** Sklep w przeglądarce zgłasza tylko błędy, bez trace'ów i bez Session Replay; SDK
  przeglądarki ładuje się dopiero po odczycie DSN z `/api/site` (osobny chunk), więc bez DSN strona nie pobiera kodu
  Sentry, a budżety Lighthouse się nie zmieniają. Błędy przeglądarki sprzed inicjalizacji SDK są pomijane (przyjęte).
- **P3. Mapy źródeł.** Wysyłane z budowania obrazów `prod` w jobie `images` workflowu `deploy.yml` (tylko
  `workflow_dispatch` na `main`) tokenem `SENTRY_AUTH_TOKEN` (sekret GitHub Actions, montowany jako sekret budowania), dopasowane przez debug
  ID; po wysłaniu usuwane z obrazu. Bez sekretu krok wysyłki się pomija i obraz powstaje bez map. Wymaga zależności
  deweloperskiej `@sentry/vite-plugin` w panelu (sklep ma wtyczkę w `@sentry/nextjs`).
- **P4. Próbkowanie trace'ów.** `tracesSampleRate` z wartości chartu, w `prod` 0,1 dla api, sklepu i panelu; żądania
  `/health` i sond nie są śledzone.
- **P5. Użytkownik w zdarzeniu.** Klient sklepu: brak identyfikacji. Pracownik panelu: tylko `id` (sub z `/me`), bez
  loginu i e-maila.
- **P6. Logi jako zdarzenia.** Jawne logi aplikacji od `error` w górę (np. „requires a manual refund”) są zdarzeniami
  Sentry, ale z kontekstu logu przechodzi tylko `correlation_id` (identyfikatory zamówienia i płatności znajduje się
  przez correlation ID w logach Grafana Cloud). Logi niższych poziomów nie trafiają do Sentry, także jako
  breadcrumbs. Logi `console.error` sklepu nie są zdarzeniami.
- **P7. DSN w repozytorium.** DSN to wartość jawna (jest w przeglądarce), więc trafia do
  `infra/k8s/environments/prod/values.yaml` jako zwykła wartość, nie do Sealed Secrets. Wpisuje ją osobny mały PR po
  epiku (konto Sentry powstaje poza repo); do tego czasu `prod` działa bez Sentry.
- **P8. Ruch z internetu a próbkowanie.** Api kontynuuje trace z nagłówków `sentry-trace` i `baggage` (wymaga tego
  panel), więc każdy może wymusić śledzenie swoich żądań do api; ryzyko przyjęte, ogranicza je ochrona przed skokami
  w projekcie Sentry. Serwer sklepu nie kontynuuje trace'ów z przychodzących żądań (przeglądarka sklepu nie śledzi).

## Poza zakresem

- Zamiana zdarzeń Sentry w issues `bug` z etykietą `agent` (krok 11 planu Agentic Software House).
- Session Replay, profilowanie (Sentry Profiling), Sentry Logs, Crons, metryki i integracja z Pinią.
- Trace'y w przeglądarce sklepu (P2) i Web Vitals w Sentry (mierzy je Lighthouse, `docs/features/lighthouse`).
- Zdarzenia Keycloaka, Varnisha, imgproxy, Garage i usług stosu bez naszego kodu.
- Połączenie correlation ID z trace ID (correlation ID zostaje osobnym identyfikatorem, w zdarzeniu jako tag) i trace
  przez RabbitMQ (wiadomości łączy correlation ID).
- Przyjmowanie przez api correlation ID z nagłówka żądania (dziś api zawsze nadaje własny).
- Asynchroniczna wysyłka zdarzeń błędów w api (własny transport buforujący).
- Lokalny Sentry w compose (np. self-hosted) i Sentry w panelu w trybie `vite dev`.
- Reguły alertów Sentry i powiadomienia poza domyślnymi z konta (użytkownik ustawia je w Sentry).
- NetworkPolicy dla ruchu wychodzącego (klaster jej nie ma; przy jej dodaniu ruch do Sentry trzeba będzie dopuścić).

## Wymagane przed startem

- **ADMIN-DESIGN na `main`** – rozłączność zakresu (`apps/admin`, compose, `templates/admin.yaml`,
  `smoke/checks.py`); źródło: `/ao-run` sprawdza linię `Zakres:` epików w toku.
- **Zależności** `sentry/sentry-symfony` (api), `@sentry/nextjs` (sklep), `@sentry/vue` (panel) – zgoda użytkownika
  w issue #180; `@sentry/vite-plugin` (panel, deweloperska) – P3. Zgodność wersji sprawdzona w rejestrach
  (`sentry-symfony` 5.16 dopuszcza Symfony 8, `@sentry/nextjs` 11 dopuszcza Next 16, `@sentry/vue` 11 dopuszcza
  Vue 3 i Pinię 4); źródło: istnieje (Packagist, npm).

Zadania nie potrzebują konta Sentry ani sekretów: testy używają transportu testowego i fałszywego DSN, a krok wysyłki
map bez sekretu się pomija. Konto Sentry (P1), DSN-y w `prod` (P7), sekret `SENTRY_AUTH_TOKEN` i zmienne `SENTRY_ORG`,
`SENTRY_PROJECT_STORE`, `SENTRY_PROJECT_ADMIN` w GitHub to kroki użytkownika po merge'u epiku (runbook).

## Decyzje

- **D1. Aktywacja tylko z DSN.** Każde SDK inicjalizuje się wyłącznie przy niepustym DSN; pusty albo brak to brak
  wysyłki, brak trace'ów i zachowanie jak dziś. Format DSN sprawdza schemat chartu (`values.schema.json`, wzorzec
  `https://<klucz>@<host>/<id projektu>`); poza chartem aplikacje przyjmują zachowanie SDK przy złym DSN (sklep
  i panel działają bez wysyłki, api zgłasza błąd konfiguracji).
- **D2. Konfiguracja przez zmienne środowiska**, wspólne nazwy: `SENTRY_DSN`, `SENTRY_ENVIRONMENT`, `SENTRY_RELEASE`,
  `SENTRY_TRACES_SAMPLE_RATE` (domyślnie 0). Api: `%env(default::…)%` (wzorzec `CLIENT_IP_HEADER`). Sklep: odczyt
  w `instrumentation.ts` przy starcie serwera, a dla przeglądarki pole `sentry` w odpowiedzi `/api/site`
  (`{dsn, environment, release}` albo `null`), żeby HTML ISR nie zależał od środowiska. Panel: opcjonalne klucze
  `SENTRY_DSN`, `SENTRY_ENVIRONMENT`, `SENTRY_RELEASE`, `SENTRY_TRACES_SAMPLE_RATE` w `/config.js` pisane przez
  `docker/40-admin-config.sh` tylko wtedy, gdy są ustawione (pozostałe klucze nadal wymagane), czytane jedną funkcją
  w `src/config` obok `readUrl`.
- **D3. Wersja i środowisko.** `SENTRY_RELEASE` = `image.tag` chartu (rewizja commita), `SENTRY_ENVIRONMENT` =
  wartość chartu `sentry.environment` (w `prod`: `demo`). Chart: blok `sentry` w `values.yaml` (`environment`,
  `tracesSampleRate`, `dsn.api`, `dsn.store`, `dsn.admin`, domyślnie puste), zmienne emitowane tylko przy niepustym
  DSN danej aplikacji (wzorzec `shop.clientIpHeaderEnv`); DSN api trafia do `shop.apiEnv` (api, worker, Joby, init
  kontenery i CronJob odtworzenia).
- **D4. Filtrowanie (wspólne zasady trzech SDK).** `sendDefaultPii`/`send_default_pii` wyłączone, w api
  `max_request_body_size: none`. Jedna funkcja filtra na aplikację (z testami), wołana z `before_send`,
  `before_send_transaction` i `beforeBreadcrumb`, usuwa:
  - nagłówki żądania poza `User-Agent`, `Content-Type`, `Accept`, ciasteczka, treść żądania, adres IP użytkownika;
  - query string z adresu żądania, nazw transakcji i spanów, danych spanów (`url.full`, `http.query`) i adresów
    breadcrumbs (`fetch`, `xhr`, `navigation`);
  - wartości parametrów zapytań SQL: spany Doctrine niosą tylko SQL z placeholderami (bez parametrów SDK), a literały
    w opisie spanu `db` maskuje ten sam zestaw wzorców co niżej;
  - argumenty ramek stosu (`vars`); w api także `zend.exception_ignore_args=On` w `prod.ini`;
  - breadcrumbs `console` (przeglądarka i Node);
  - w sklepie argumenty poleceń Redisa w nazwie i danych spanu (`db.query.text`, `db.statement`, `cache.key`):
    zostaje nazwa polecenia;
  - w api i sklepie identyfikator koszyka gościa ze ścieżki `/guest-carts/<id>` (klucz dostępu do koszyka, nie format
    Alloy) w adresach, nazwach transakcji, opisach i danych spanów, breadcrumbs i tekstach;
  - w api pola `extra` i `context` poza `correlation_id`.

  W komunikatach i wartościach wyjątków, komunikatach zdarzeń, breadcrumbs i opisach spanów filtr maskuje te same formaty co Alloy
  (`infra/k8s/argocd/apps/monitoring.yaml`): adresy e-mail, JWT, `Bearer`, hasło w adresie URL, pary klucz=wartość
  z sekretami, `Failing row contains (…)` i `Key (…)=(…)` PostgreSQL, `with params [...]` DBAL. Testy filtra api
  używają wierszy z `infra/k8s/tests/log-corpus`.
- **D5. Jeden kanał na źródło w api.**
  - HTTP: listener bundla (`register_error_listener`) wyłączony; własny listener `kernel.exception` po
    `ProblemDetailsSubscriber` zgłasza wyjątek tylko wtedy, gdy ustalona odpowiedź ma status ≥ 500. Status pochodzi
    z mapowania `ProblemDetailsSubscriber` (jedna funkcja „status dla wyjątku” w `Shared\Infrastructure\Http`), bez
    drugiej listy wyjątków w konfiguracji Sentry.
  - Konsola: listener konsoli bundla (nieobsłużony wyjątek komendy).
  - Messenger: listener Messengera bundla z `capture_soft_fails: false` (tylko ostatnia próba przed `failed`).
  - Jawne logi aplikacji (P6): handler Monologa Sentry od `error`, z wykluczonymi kanałami `request`, `console`,
    `messenger`, `php`, `deprecation` i `cache` (te kanały powielają wyjątki zgłoszone wyżej albo nie są błędami
    aplikacji) i bez rekordów z `context.exception`; bez handlera breadcrumbs.
  - Transport z limitem czasu połączenia i odpowiedzi po 2 s. Zdarzenie błędu `sentry-php` wysyła synchronicznie, więc
    przy niedostępnym Sentry odpowiedź 5xx, odpowiedź z logiem `error` i obsługa wiadomości w workerze opóźniają się
    najwyżej o ten limit; transakcje wysyłane są po odpowiedzi (`kernel.terminate`).
- **D6. Correlation ID.** Api dodaje tag `correlation_id` z bieżącego `CorrelationId` do każdego zdarzenia i transakcji
  (procesor zdarzeń w `Shared\Infrastructure\Observability`), także w workerze (identyfikator bieżącej wiadomości;
  tag i zakres nie przechodzą między wiadomościami w jednym `messenger:consume`).
- **D7. Propagacja trace'ów.** Panel: `tracePropagationTargets` tylko adres API; CORS api dopuszcza nagłówki
  `sentry-trace` i `baggage` (`nelmio_cors.yaml`). Serwer sklepu: propagacja tylko do adresów API
  (`API_INTERNAL_URL`, `API_CATALOG_URL`), nigdy do Keycloaka ani Redisa; trace'u z przychodzących żądań nie kontynuuje
  (P8). Api kontynuuje trace z tych nagłówków (domyślne zachowanie `sentry-symfony`).
- **D8. Sklep.** `instrumentation.ts` (`register` dla runtime `nodejs`, `onRequestError`), filtr z D4 i propagacja z D7.
  Inicjalizacja w przeglądarce w `SiteSwitches` po odczycie `/api/site` przez dynamiczny import modułu
  `src/observability` (P2). `app/error.tsx` zgłasza przez ten sam leniwy moduł tylko błędy bez `digest` (błąd
  z `digest` pochodzi z serwera i zgłosił go już `onRequestError`), gdy SDK jest zainicjowane; wygląd strony błędu się
  nie zmienia. Obsłużone błędy BFF (odpowiedź 502 i `console.error`) nie są zdarzeniami. `next.config.ts` nie dodaje
  `script-src` ani `connect-src` (CSP bez zmian).
- **D9. Panel.** `@sentry/vue` w `main.ts` przed `mount`, z integracją routera (`browserTracingIntegration`),
  `attachProps: false`, bez integracji Pinii, z filtrem z D4 (także `q` wyszukiwania zamówień i `code`/`state`
  z `/auth/callback`). `setUser({id})` po wczytaniu `/me` (P5), `setUser(null)` przy każdym wylogowaniu, także po
  bezczynności (`useIdleLogout`). SDK jest w bundlu (CSP `script-src 'self'` bez zmian, bez skryptu z CDN). Nagłówki
  bezpieczeństwa i treść `security-headers.conf` bez zmian.
- **D10. Mapy źródeł (P3).** Budowanie `prod` sklepu i panelu generuje mapy, wysyła je przy dostępnym sekrecie i usuwa
  pliki `.map` opcjami wtyczek (`deleteSourcemapsAfterUpload`, w panelu też `filesToDeleteAfterUpload`) i `find ... -delete`
  na końcu budowania, a bez sekretu nie zostawia ich w etapie `prod`. Sekret trafia wyłącznie do kroków budowania w jobie `images`; job `smoke` (także na
  PR) buduje bez niego. Flaga wysyłki to argument budowania (`ARG`), który wchodzi do klucza cache, więc warstwa
  zbudowana bez wysyłki nie jest używana przy wysyłce. Telemetria wtyczek budowania wyłączona (`telemetry: false`), a CLI Sentry, przez które wysyłają mapy, ma własną: wyłącza ją
  `SENTRY_CLI_NO_TELEMETRY=1` w etapie `build` obu Dockerfile.
  Organizacja i projekty Sentry dla wysyłki ze zmiennych repozytorium GitHub (`SENTRY_ORG`, `SENTRY_PROJECT_STORE`,
  `SENTRY_PROJECT_ADMIN`); token nie trafia do warstw obrazu ani logu. Obraz `dev` i budowanie w `ci.yml` bez zmian.
- **D11. Compose.** Plik compose się nie zmienia: api, worker i sklep mogą dostać `SENTRY_*` z `.env.local` (już czytają go
  przez `env_file`), panel w compose działa bez Sentry. `init-env.sh` się nie zmienia (DSN nie jest losowym sekretem).

## Model zagrożeń

**Aktywa i strony.** Dane klientów (e-mail, imię i nazwisko, telefon, adres, numer zamówienia z tokenem dostępu gościa),
tokeny OIDC i sesje (ciasteczka `store_session`, `store_cart`, `store_order_confirmation`, nagłówki `Authorization`,
`Order-Access-Token`), kody rabatowe i frazy wyszukiwania, tożsamość pracowników, limit darmowego planu Sentry.
Strony: Sentry SaaS (zewnętrzny procesor danych), każdy, kto zna DSN (jest jawny w przeglądarce), każdy, kto wysyła
żądania do api i sklepu, każdy z dostępem do projektu w Sentry.

**Drogi w zakresie** (każda ze sprawdzeniem dostarczanym przez zadanie):

1. Nagłówki i ciasteczka w zdarzeniu → D4. Test filtra w każdej aplikacji: zdarzenie z `Authorization`, `Cookie`,
   `Order-Access-Token` i ciasteczkami wychodzi bez nich. Test funkcjonalny api: błąd 500 z tymi nagłówkami trafia do
   transportu testowego bez nich.
2. Query string z danymi (`q` = e-mail w panelu, `discountCode`, `returnTo`, `code`, `state`) w adresie, nazwie
   transakcji, spanie (nazwa i dane) i breadcrumbs → D4. Testy filtrów z tymi parametrami.
3. Treść żądania (formularz kasy, dane adresowe) → D4. Test api: błąd 500 przy `POST` z treścią JSON; zdarzenie bez
   `request.data`.
4. Dane w komunikacie wyjątku, logu, breadcrumbie albo spanie SQL (e-mail, wiersz PostgreSQL, parametry DBAL, JWT, `Bearer`) →
   D4. Testy filtra api na wierszach korpusu `log-corpus` i test: log `error` z e-mailem w kontekście wychodzi bez
   kontekstu poza `correlation_id`; logi poniżej `error` nie trafiają do Sentry; span Doctrine zapytania z e-mailem
   w parametrze ma SQL z placeholderem i bez wartości.
5. Argumenty ramek stosu i propsy komponentów → D4, D9. Test api: wyjątek rzucony z funkcji z argumentem
   z e-mailem daje ramki bez `vars`; test panelu: konfiguracja SDK z `attachProps: false` i bez integracji Pinii.
6. Tożsamość w zdarzeniu → P5. Test panelu: po `/me` użytkownik zdarzenia ma tylko `id`, po wylogowaniu (także po
   bezczynności) brak użytkownika; test sklepu i api: brak `user` i brak IP.
7. Mapy źródeł publicznie w obrazie → D10. Test `deploy.yml` (`infra/k8s/tests/test_deploy_workflow.py`) i sprawdzenie
   obrazu w smoke teście: brak plików `.map` w etapie `prod` sklepu i panelu.
8. Token wysyłki map w obrazie, logu albo w budowaniu kodu z PR → D10. Test `deploy.yml`: `SENTRY_AUTH_TOKEN` tylko
   jako sekret budowania (`secrets:`) w krokach joba `images`, nigdy w `build-args`, `env` innego kroku ani w jobie
   `smoke`; flaga wysyłki jest argumentem budowania; Dockerfile czyta token z montowania `--mount=type=secret`.
9. Wymuszenie śledzenia z internetu na serwerze sklepu → D7, P8. Test sklepu: żądanie z `sentry-trace` z decyzją
   próbkowania 1 nie zmienia decyzji serwera (nowy trace).

**Poza zakresem (założenia):**

- Wymuszenie śledzenia żądań api nagłówkiem `sentry-trace` (P8) i nadużycie jawnego DSN (zalewanie projektu
  zdarzeniami): ogranicza je ochrona przed skokami i limity w ustawieniach projektu Sentry (użytkownik, runbook);
  filtrowanie przez tunel i własny `traces_sampler` są poza zakresem.
- Przejęcie konta Sentry i bezpieczeństwo danych u dostawcy (umowa powierzenia, region UE z P1).
- Dane osobowe w formacie spoza listy wzorców (np. imię w komunikacie wyjątku) mogą przejść filtr; ogranicza to
  przegląd próbki zdarzeń po wdrożeniu (runbook) i „Data Scrubber” Sentry po stronie serwera (domyślnie włączony,
  użytkownik go nie wyłącza).

## Konfiguracja

| Zmienna | api, worker, Joby | sklep (serwer) | panel (`/config.js`) | Chart |
|---|---|---|---|---|
| `SENTRY_DSN` | tak | tak | tak | `sentry.dsn.api` / `.store` / `.admin` |
| `SENTRY_ENVIRONMENT` | tak | tak | tak | `sentry.environment` |
| `SENTRY_RELEASE` | tak | tak | tak | `image.tag` |
| `SENTRY_TRACES_SAMPLE_RATE` | tak | tak | tak | `sentry.tracesSampleRate` (0–1, schemat) |

Zmienne inne niż DSN chart emituje tylko dla aplikacji z niepustym DSN. Profil `ci` ma puste DSN (smoke test bez
kont zewnętrznych); emisję sprawdzają testy renderu chartu na wartościach podanych w teście.

## Scenariusze błędów

- Sentry niedostępny albo odrzuca zdarzenia (limit): SDK gubi zdarzenia; w api ścieżki błędu opóźniają się najwyżej
  o limit transportu 2 s (D5), ścieżki bez błędu i przeglądarka bez opóźnienia widocznego dla klienta.
- Brak DSN: zachowanie jak dziś, żadnego ruchu do zewnętrznych hostów (test E2E `visual-foundation` z asercją
  „tylko `localhost`” zostaje bez zmian).
- Błąd odczytu `/api/site` w przeglądarce sklepu: SDK się nie inicjalizuje, strona działa (jak dziś przełączniki).
- Błąd wysyłki map w jobie `images` (zły token, Sentry niedostępny): budowanie obrazu kończy się błędem i deploy nie
  rusza (obraz bez map przy ustawionym sekrecie byłby cichym brakiem); brak sekretu: wysyłka pominięta z wpisem
  w podsumowaniu workflowu.

## Zmiany dokumentacji (razem z implementacją)

- `docs/vision.md`: „Obserwowalność” opisuje Sentry (błędy, trace'y, filtrowanie danych), punkt „Sentry (#180)”
  znika z „Kolejnych kroków”.
- `docs/adr/backups-observability.md`: sekcja o Sentry (SaaS, region UE, DSN jawny, filtrowanie po stronie SDK,
  jeden kanał na źródło, mapy źródeł).
- `apps/api/CLAUDE.md`, `apps/store/CLAUDE.md`, `apps/admin/CLAUDE.md`: konfiguracja Sentry, filtr zdarzeń, zasada
  „nowy format danych klienta w logach albo wyjątkach wymaga wzorca w filtrze Sentry i w Alloy w tym samym PR”.
- `infra/k8s/CLAUDE.md` i `infra/k8s/README.md`: wartości `sentry.*`, kroki użytkownika (konto, projekty, DSN,
  token i zmienne GitHub, ochrona przed skokami, przegląd próbki zdarzeń po wdrożeniu).

## Kryteria akceptacji

1. Bez DSN: `make check`, `make test`, `make e2e` i `make -C infra/k8s smoke` przechodzą bez zmian zachowania; brak
   ruchu do hostów Sentry.
2. Api z DSN i transportem testowym: wyjątek 500 daje dokładnie jedno zdarzenie z `release`, `environment`, tagiem
   `correlation_id` równym nagłówkowi `X-Correlation-ID` odpowiedzi i bez danych z dróg 1–5; 401, 403, 404, 409, 422
   i 429 (także wyjątki domeny `NotFound`, `Conflict`, `InvalidInput`) nie dają zdarzeń; nieobsłużony wyjątek komendy
   daje jedno zdarzenie; wiadomość po ostatniej próbie w workerze daje jedno zdarzenie z correlation ID wiadomości,
   a dwie kolejne wiadomości mają własne correlation ID; log `error` z kanału aplikacji daje jedno zdarzenie, a log
   `warning` żadnego; transakcja żądania ma spany Doctrine; `/health` nie ma transakcji.
3. Sklep z DSN: błąd renderowania strony daje jedno zdarzenie serwera (`onRequestError`) bez query stringu
   i ciasteczek; `/api/site` zwraca `sentry` tylko przy DSN; bez DSN przeglądarka nie pobiera chunka Sentry;
   przychodzący `sentry-trace` nie wymusza śledzenia.
4. Panel z DSN w `/config.js`: nieobsłużony wyjątek daje zdarzenie z `user.id` i bez `q`/`code`/`state`; żądanie do
   api niesie `sentry-trace` i `baggage`, a preflight CORS je dopuszcza; bez kluczy Sentry `config.js` jest jak dziś,
   a `40-admin-config.sh` nadal odrzuca brak wymaganych adresów.
5. Chart: render z DSN emituje zmienne tylko do właściwych workloadów (api: api, worker, Joby; sklep; panel), bez DSN
   żadnej `SENTRY_*`; schemat odrzuca `tracesSampleRate` spoza 0–1 i DSN w złym formacie.
6. `deploy.yml`: token tylko jako sekret budowania w jobie `images`; flaga wysyłki w argumentach budowania; etapy
   `prod` sklepu i panelu bez `.map`; bez sekretu workflow przechodzi; telemetria wtyczek wyłączona.
7. Filtry zdarzeń mają testy każdej drogi z modelu zagrożeń.

## Ryzyka

- **Darmowy plan Sentry** (limity zdarzeń i spanów miesięcznie): próbkowanie 0,1, brak zdarzeń z 4xx i jeden kanał
  na źródło; przekroczenie gubi zdarzenia do końca miesiąca. Pomiar po wdrożeniu.
- **Narzut SDK.** Trace'y kosztują czas żądania api; zdarzenia błędów wysyłane synchronicznie (D5). Worker
  (`messenger:consume`) musi izolować zakres między wiadomościami (D6, kryterium 2).
- **Filtr oparty na wzorcach** (jak maskowanie logów w Alloy): nowy format danych klienta może przejść; zasada
  w `CLAUDE.md` aplikacji, wspólny korpus z Alloy i przegląd próbki zdarzeń.
- **Kolejność epików.** ADMIN-DESIGN (w toku) zmienia `apps/admin`, `infra/docker-compose.yml`,
  `templates/admin.yaml` i `smoke/checks.py`; SENTRY startuje po jego merge'u do `main`.
