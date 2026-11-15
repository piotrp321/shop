## Krytyka szkicu `docs/features/deploy/spec.md` (DEPLOY, #175)

### Critical

**C1. Fale Argo się zakleszczają przy pierwszej synchronizacji** (techniczne; sekcje „Wdrożenie nowej wersji” pkt 5, „Chart `shop`”, „Dane startowe”)
- `keycloak-config` jest w fali 0, a Keycloak jest Deploymentem, więc trafia do fali 1. Argo nie przejdzie do fali 1, dopóki Job z fali 0 nie jest zdrowy.
- `initialize` (fala 0) uruchamia seedy:
  - `catalog:seed-demo` czeka do 60 s, aż worker zaindeksuje produkty („Is the worker running?”, `apps/api/src/Catalog/UI/Console/SeedDemoCatalogCommand.php` ok. l. 59–70). Worker jest w fali 1.
  - `storefront:cache:purge-all` wymaga Varnisha i sklepu (fala 1), inaczej kończy się kodem 2 (`apps/api/CLAUDE.md`).
- Smoke test instaluje chart Helmem bez fal, więc tego nie wykryje (szkic sam to przyznaje w „Ryzykach”).
- Propozycja:
  - Keycloak w fali −1 (razem z usługami stanowymi).
  - `initialize` w fali 2, z initContainerem czekającym na aktualny schemat, Garage z bucketami i indeks.
  - W `check` test renderu: każdy Job ma zależności we wcześniejszych falach.

**C2. Wymagany `check` potrzebuje zaszyfrowanych sekretów `prod`, a certyfikatu Sealed Secrets w czasie epiku nie ma** (architektoniczne; „Sekrety”, D5, „Serwer, bootstrap”, kryteria 1 i 8)
- Certyfikat zapisuje do repo dopiero `bootstrap`, a ten działa na serwerze, czyli po epiku.
- Bez certyfikatu `seal-secrets.sh` kończy się błędem, a test „każdy klucz z `init-env.sh` ma zaszyfrowaną wartość” w wymaganym jobie `checks` nie przejdzie.
- Gdyby epik użył certyfikatu tymczasowego, skrypt „istniejących nie zmienia”, więc po bootstrapie wszystkie sekrety byłyby nie do odszyfrowania.
- Rekomendowany wariant: użytkownik przed epikiem generuje parę kluczy kontrolera (poza AO, klucz prywatny zostaje u niego), certyfikat trafia do repo, a `bootstrap` importuje klucz „z kopii”. Szkic musi opisać ten krok i kto go wykonuje.
- Alternatywa (gorsza): test sekretów pomija `prod`, gdy nie ma certyfikatu.

### Important

**I1. Cloudflare Access na `auth.<domena>/realms/backoffice/` psuje logowanie panelu** (techniczne; „Adresy”)
- `oidc-client-ts` pobiera discovery, wymienia kod na token i odnawia go (`automaticSilentRenew`) zapytaniami `fetch` z innego originu (`admin.<domena>`), bez ciasteczek (`apps/admin/src/auth/config.ts`).
- Access odpowie przekierowaniem albo 403 i panel dostanie błąd CORS. To ten sam problem, który szkic opisuje w „Ryzykach” dla API.
- Rekomendacja: Access tylko na `admin.<domena>`; realm `backoffice` chronią TOTP, brute force i ingress. Ewentualnie jawnie konfiguracja CORS Access plus `fetchRequestCredentials: "include"`, z testem.

**I2. Firewall „80/443 tylko z Cloudflare” prawdopodobnie nie zadziała z k3s** (techniczne; D6, „Bootstrap”)
- Traefik w k3s wystawia porty przez ServiceLB i reguły DNAT w iptables. Ruch idzie łańcuchem FORWARD, więc reguły INPUT (ufw) go nie filtrują.
- Kryterium 10 testuje bootstrap w kontenerze bez k3s, więc tego nie sprawdzi.
- Propozycja:
  - Wybrać mechanizm: firewall sieciowy OVH, `ipAllowList` w Traefiku z zakresami Cloudflare albo reguły nftables przed DNAT.
  - Dodać krok runbooka: żądanie na IP serwera spoza Cloudflare jest odrzucane.

**I3. Pasek demonstracji nie pojawi się na stronach prerenderowanych w buildzie** (techniczne; D7, „Sklep: informacja o demonstracji”)
- Layout jest wspólny (`apps/store/app/layout.tsx`).
- Strony `/cart` i `/checkout` (statyczne `metadata`, bez danych żądania), `_not-found` i prawdopodobnie `/checkout/confirmation` są renderowane w czasie buildu, więc wartość zmiennej z buildu (brak flagi) zostanie w HTML.
- Smoke sprawdza tylko stronę główną, która i tak jest dynamiczna (`searchParams`).
- Propozycja: określić mechanizm, np. komponent paska czyta zmienną w dynamicznej granicy (`connection()` w `Suspense`) albo layout jest dynamiczny. Smoke ma sprawdzać także `/cart`.

**I4. Nazwa Joba z tagiem obrazu przeczy innym zapisom szkicu** (techniczne; „Chart: Joby”, D9, D10, „Scenariusze błędów”, kryterium 7)
- „`keycloak-config` przy każdej synchronizacji”: Job o tej samej nazwie już istnieje (Completed), więc nie uruchomi się ponownie.
- „Nieudany `purge-caches` … kolejna synchronizacja ponawia”: Job w stanie Failed o tej samej nazwie nie zostanie odtworzony.
- D10 („zmiana bez budowania obrazów”) jest sprzeczne z budowaniem obrazów z każdego commita na `main`. Gdyby obrazów nie budowano, zmiana wartości dałaby Job o tej samej nazwie z innym spec, a Job jest niemutowalny i synchronizacja padnie.
- Smoke „druga instalacja nie zmienia liczby produktów” z tym samym tagiem w ogóle nie uruchomi ponownie `initialize`, więc kryterium 7 niczego nie sprawdza.
- Propozycja:
  - Sufiks nazwy z sumy kontrolnej tagu i konfiguracji danego Joba.
  - Druga instalacja w smoke z wymuszonym nowym sufiksem.
  - Procedura ponowienia w runbooku.
  - Poprawić zapis D10.

**I5. Zmiany realmów po pierwszej instalacji nie trafią na serwer** (architektoniczne; D9)
- `keycloak-config` zakłada realm tylko, gdy go nie ma. Nowy klient, rola, redirect URI czy zmiana domeny w `infra/keycloak/realms/*.json` w kolejnych epikach zostaną tylko lokalnie.
- Zasada w `CLAUDE.md` (zmiana w compose wymaga zmiany w chartcie) nie obejmuje realmów.
- Rekomendowany wariant: `partialImport` z `ifResourceExists=SKIP` (dodaje brakujące klienty i role, nic nie nadpisuje) plus lista zawsze ustawianych atrybutów, w tym `redirectUris` i `webOrigins` z domeny. Zasadę dopisać do `CLAUDE.md`.

**I6. Publiczny sklep wysyła prawdziwe e-maile pod dowolne adresy** (produktowe; „Poczta”, „Cel i zakres”)
- Checkout gościa jest publiczny i nie ma limitów (rate limiting odłożony: CHECKOUT T2, PAYMENT D9). Potwierdzenia zamówień pójdą przez Brevo na dowolne wpisane adresy, co grozi spamem i utratą reputacji domeny. Do tego dowolny gość może rezerwować stany.
- Issue #175 mówi „e-maile nie wychodzą na zewnątrz”.
- Keycloak praktycznie nie wysyła poczty: rejestracja jest wyłączona, w realmie `shop` nie ma kont, a `backoffice` ma `resetPasswordAllowed: false`.
- Rekomendowany wariant: na start `MAILER_DSN` API kierowany do Mailpita w klastrze (przełącznik wartości chartu), Brevo skonfigurowane dla Keycloaka. Wysyłka zamówień przez Brevo dopiero razem z limitami nadużyć. Ryzyko blokowania stanów warto nazwać w „Ryzykach”.

**I7. Agent i sekrety** (produktowe; „Keycloak i konta”, „Sekrety”, „Serwer, bootstrap”)
- `create-admin` wypisuje hasło jednorazowe. Jeśli uruchamia go Claude Code (szkic na to pozwala „po zgodzie”), hasło trafia do kontekstu i logów agenta. To przeczy zapisowi „hasło dostaje tylko terminal użytkownika”.
- Tak samo wartości zewnętrzne dla `seal-secrets.sh` (Brevo, S3, Grafana, Origin CA) przekazywane agentowi przez zmienne środowiska.
- Rekomendowany wariant: `create-admin` i szyfrowanie wartości zewnętrznych oznaczone w runbooku jako „użytkownik” (uruchamiane w terminalu użytkownika). Alternatywa: `create-admin` bez hasła, przez `execute-actions-email` przez SMTP.

**I8. Kryterium 6 (i test rulesetu z D4) są nieweryfikowalne w epiku** (techniczne)
- `deploy.yml` na `main` uruchomi się dopiero po zmergowaniu końcowego PR.
- Ruleset wymaga uprawnień admina repo (`CLAUDE.md`, sekcja rulesetów), a deploy key i sekret repo zakłada użytkownik. Tymczasem szkic deklaruje, że zadania nie wymagają niczego poza repo.
- Propozycja:
  - W epiku: `workflow_dispatch` albo dry-run na gałęzi epiku bez pushu do `deploy` oraz statyczna walidacja workflow.
  - Ruleset, deploy key i pierwsza aktualizacja `deploy` jako krok użytkownika po merge'u, z zapisem wyniku.
  - Przeformułować kryterium 6.

**I9. Odtworzenie z kopii jest niepełne** („Kopie zapasowe”, kryterium 9)
- Po odtworzeniu bazy indeks Elasticsearch jest pusty albo nieaktualny. Komenda `catalog:reindex` istnieje, ale runbook jej nie przewiduje. Brakuje też `purge-caches`.
- Na „pustym klastrze” pierwsza synchronizacja uruchomi `initialize` przed odtworzeniem, więc w Garage obiekty demo zmieszają się z odtworzonymi.
- Propozycja:
  - Kolejność w runbooku: odtworzenie przy wyłączonym `initialize` (wartość chartu), potem `catalog:reindex` i `purge-caches`.
  - Testowe odtworzenie sprawdza też listę produktów w sklepie.

**I10. W `Zakres:` brakuje `.github/workflows/deploy.yml`** (techniczne; nagłówek)
- Szkic tworzy ten workflow, a linia `Zakres:` służy do sprawdzania rozłączności epików (`docs/features/agentic-software-house/plan.md:116`). `.github/` często zmieniają inne prace (#159, #160, #169).
- Propozycja: dopisać `.github/workflows/deploy.yml` (oraz `.github/scripts`, jeśli workflow ich użyje).

### Minor

- **M1** (techniczne, „Obrazy produkcyjne”): `garage-init` w compose ma obraz budowany inline (`infra/docker-compose.yml`, `dockerfile_inline`), a w k8s nie da się go zbudować. Trzeba wskazać sposób, np. initContainer kopiujący binarkę `/garage` do `emptyDir` i alpine uruchamiający `init.sh`.
- **M2** (techniczne): etap `prod` „między `base` a `dev`” i „bez Composera” wymaga podziału `base`, bo dziś `base` ma Composera, git, unzip i entrypoint z `composer install` (`apps/api/Dockerfile`). Warto doprecyzować układ etapów.
- **M3** (techniczne): `backup-verify` porównuje liczbę obiektów z bucketem po 2 h, więc upload zdjęcia między 02:30 a 04:30 da fałszywy alarm. Lepiej porównywać z listą obiektów zapisaną w snapshocie albo warunkiem „nie mniej”.
- **M4** (techniczne): `/config.js` w panelu. Brakuje:
  - pierwszeństwa względem `import.meta.env`;
  - zachowania `vite dev`, w którym `<script src="/config.js">` dostanie 404;
  - komunikatu przy brakującym kluczu.
- **M5** (produktowe): #175 obejmuje Sentry, tryb demo z nocnym resetem i kontami demo oraz ADR „…i Sentry SaaS”, a szkic przenosi je poza zakres. Końcowy PR ma `Closes #175`, więc warto zaktualizować issue albo założyć zgłoszenia kontynuacyjne. `docs/vision.md` (ok. l. 215) wspomina też Sentry Performance „w środowisku demo”, czego lista zmian dokumentacji nie obejmuje.
- **M6** (techniczne): nie wiadomo, czy nowe pakiety GHCR `shop/<app>` będą publiczne, ani czy k3s dostanie `imagePullSecret`.
- **M7** (produktowe): wyzwalacz `initialize` „brak produktów”. Gdyby katalog kiedyś opustoszał, kolejne wdrożenie uruchomi seedy z `--force-reset` i skasuje zamówienia. Rekomendacja: trwały znacznik inicjalizacji zamiast liczby produktów.
- **M8** (produktowe): przy wyłączonej rejestracji i bez kont w realmie `shop` przycisk logowania w sklepie prowadzi donikąd. Do decyzji: ukryć go przełącznikiem czy zostawić.
- **M9** (techniczne): scenariusz „Brak sekretu SMTP: keycloak-config i API nie startują” w praktyce oznacza błąd renderu chartu, czyli nieudaną całą synchronizację. Trzeba poprawić opis.
- **M10** (techniczne): `make -C infra/k8s smoke` nie wiąże nazwy klastra k3d ani portów z `COMPOSE_PROJECT_NAME` i `.env` worktree. Równoległe zadania AO mogą kolidować (`CLAUDE.md`, „Środowisko”).

## Werdykt: DO POPRAWY

Najważniejsze pliki dowodowe:
- `<home>/Development/claude/shop/.claude/worktrees/parallel-environment-setup-3d960b/docs/features/deploy/spec.md`
- `<home>/Development/claude/shop/.claude/worktrees/parallel-environment-setup-3d960b/apps/api/src/Catalog/UI/Console/SeedDemoCatalogCommand.php`
- `<home>/Development/claude/shop/.claude/worktrees/parallel-environment-setup-3d960b/apps/admin/src/auth/config.ts`
- `<home>/Development/claude/shop/.claude/worktrees/parallel-environment-setup-3d960b/apps/store/app/layout.tsx`
- `<home>/Development/claude/shop/.claude/worktrees/parallel-environment-setup-3d960b/apps/store/app/cart/page.tsx`
- `<home>/Development/claude/shop/.claude/worktrees/parallel-environment-setup-3d960b/infra/docker-compose.yml`
- `<home>/Development/claude/shop/.claude/worktrees/parallel-environment-setup-3d960b/infra/keycloak/realms/backoffice.json`
- `<home>/Development/claude/shop/.claude/worktrees/parallel-environment-setup-3d960b/apps/api/Dockerfile`
- `<home>/Development/claude/shop/.claude/worktrees/parallel-environment-setup-3d960b/docs/features/agentic-software-house/plan.md`