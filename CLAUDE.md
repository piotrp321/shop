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
- `infra/k8s` – chart Helm całego stosu (`charts/shop`, te same usługi i wersje obrazów co compose), profile wartości
  (`environments/prod`, `environments/ci`), app-of-apps Argo CD (`argocd`), zaszyfrowane sekrety
  (`environments/prod/secrets`), skrypty serwera i sprawdzeń oraz runbook (`README.md`). Narzędzia (helm, kubeconform, shellcheck, k3d, kubectl)
  działają w kontenerach z przypiętymi wersjami, bez instalacji na hoście.
- `e2e` – testy end-to-end (Playwright) na pełnym stosie, uruchamiane przez `make e2e`, i pomiar Lighthouse
  (`e2e/lighthouse`), uruchamiany przez `make lighthouse`.
- `docs` – wizja, ADR-y (`docs/adr/`), specyfikacje i plany funkcji (`docs/features/`).

Każda aplikacja ma własny `CLAUDE.md` z zasadami tylko dla niej. Przeczytaj go przed pracą w tej aplikacji.
Przy generowaniu szkieletu aplikacji (`symfony new`, `create-next-app`, `npm create vite`) zachowaj istniejący
`CLAUDE.md` w jej katalogu.

## Środowisko

- Wszystko działa w Dockerze. Nigdy nie uruchamiaj `php`, `composer`, `npm` ani `node` bezpośrednio na hoście.
- Komendy uruchamiaj przez Makefile, który opakowuje `docker compose exec`:
  - Główny `Makefile` zarządza stosem i uruchamia wszystkie aplikacje:
    `make up` / `make down` / `make logs`, `make seed`, `make check`, `make test`, `make e2e`,
    `make e2e-agent AGENT_DIR=<katalog> [E2E_ARGS=...]` (skrypty agentów tester i UX, wyniki poza repo).
    `make lighthouse` (pomiar Lighthouse trzech stron sklepu: `/`, `/c/turystyka/biwak/namioty`
    i `/p/namiot-turystyczny-norvane-biwak-2`, po 3 przebiegi w profilu mobilnym, w kontenerze `e2e`; wymaga stosu
    ze sklepem w trybie produkcyjnym, `STORE_MODE=production make up` i `make seed`, a w innym trybie albo przy
    zatrzymanym sklepie kończy się komunikatem, bez pomiaru; raporty, `manifest.json` i `medians.md` z medianami
    SEO, Performance, LCP, CLS i TBT są w `e2e/lighthouse-results/`; blokuje tylko wynik SEO < 0,9 mediany adresu,
    pozostałe progi ostrzegają; `make e2e-check` uruchamia też testy skryptu median).
    `make test` uruchamia też `make varnish-test`: testy VCL (`infra/varnish/tests/*.vtc`, `varnishtest` z obrazu
    usługi `varnish`, bez stosu).
  - Każda aplikacja ma własny `Makefile` z tymi samymi podstawowymi celami (`check`, `test`)
    oraz swoimi (np. `make migrate` w api). Pracując w jednej aplikacji, używaj jej Makefile:
    `make -C apps/api check`.
  - `check` = statyczna analiza i lint (api: PHPStan, Deptrac, PHP CS Fixer; store/admin: ESLint, tsc). Główny
    `make check` woła też `make -C infra/k8s check`: `helm lint`, render chartu dla `prod` i `ci`, schemat wartości,
    `kubeconform`, `shellcheck`, `actionlint` (workflowy z `.github/workflows` razem ze skryptami ich kroków) i testy
    renderu (fale, ścieżki ingressu `auth`, middleware, klucze sekretu, nazwy Jobów,
    CronJob, obrazy: własne z rejestru po digeście, pozostałe `tag@digest` z tagiem jak w compose, bez `latest`;
    przełączniki `demoNotice`, `customerAccounts`, `orderMail` i poczta Keycloaka w środowisku sklepu, api, workera i Jobów
    oraz w ustawieniach realmów; wartość wybierająca Brevo bez `brevo.secretName` kończy render błędem) oraz testy
    skryptów `keycloak-config` i `create-admin` na atrapach `kcadm` i `docker`. Sprawdza też: render `prod` z plikiem
    digestów jak z `deploy-images` (bez `--set`; plik bez digestu albo z `latest` nie renderuje się), manifesty Argo CD
    (`kubeconform`; testy: jedno źródło `shop` na gałęzi `deploy-images`, kolejność fal `sealed-secrets`, `secrets`, `shop`),
    sekrety zaszyfrowane (po dodaniu certyfikatu `environments/prod/sealed-secrets.pem` każdy klucz z `init-env.sh` ma
    zaszyfrowaną wartość w `environments/prod/secrets/`; jawny Secret w tym katalogu to błąd), `seal-secrets.sh`
    i skrypty serwera na atrapach `docker` i `ssh`, `server-bootstrap.sh` dwa razy w kontenerze Ubuntu 24.04 bez k3s
    (atrapy `k3s`, `apt-get`, `ufw`, `sysctl`), brak workflowu usuwającego wersje pakietów GHCR oraz to, że `.gitignore`
    nie ignoruje certyfikatu `environments/prod/sealed-secrets.pem` (`git check-ignore` na hoście).
  - `infra/k8s` ma własny `Makefile` (potrzebuje Dockera, a `check` także gita): `make -C infra/k8s check`; `make -C infra/k8s smoke`
    (test dymny: chart z profilem `ci` na pustym klastrze k3d z obrazami produkcyjnymi zbudowanymi lokalnie, kilka minut;
    `SMOKE_BREAK=migration|image` psuje go celowo); `make -C infra/k8s rerun JOB=<nazwa>` (ponawia Job wersji pod tą
    samą nazwą); `make -C infra/k8s selftest` (wstrzykuje błędy i sprawdza, że `check` je wyłapuje);
    `make -C infra/k8s create-admin LOGIN=<login> EMAIL=<e-mail>` (pierwszy administrator panelu: wypisuje, co zrobi,
    i działa dopiero po słowie `tak`; zakłada użytkownika w realmie `backoffice` z rolą `admin` bez hasła i wysyła mu
    e-mail z akcjami `UPDATE_PASSWORD` i `CONFIGURE_TOTP`, link ważny 12 h; istniejący login albo e-mail: błąd, kod 3,
    bez zmian; niczego tajnego nie wypisuje). `rerun` i `create-admin` łączą się z klastrem przez kubeconfig z `KUBE_DIR`
    (wymagany), w kontenerze w sieci Dockera `KUBE_NETWORK` (domyślnie `host`), w przestrzeni `KUBE_NAMESPACE`
    (domyślnie `shop`). Agent uruchamia `create-admin` tylko po zgodzie użytkownika w rozmowie.
    Serwer obsługują cele (po SSH, `HOST=<user>@<adres>`, opcje ssh w `SSH_OPTS`): `make -C infra/k8s bootstrap HOST=…
    [SEALED_KEY_FILE=<kopia klucza>]` (czysty Ubuntu 24.04: k3s w przypiętej wersji, ufw, aktualizacje bezpieczeństwa, Argo CD,
    Sealed Secrets, aplikacja `root`; idempotentny; bez roota albo na innym systemie kończy się błędem przed zmianami;
    zapisuje certyfikat kontrolera do `environments/prod/sealed-secrets.pem`, gdy repo go nie ma, a rozbieżny
    certyfikat to błąd), `kubeconfig HOST=… KUBE_DIR=…` (kubeconfig klastra poza repo, dla `rerun` i `create-admin`),
    `status HOST=…` (tylko odczyt: aplikacje Argo, pody, Joby), `reinitialize HOST=…` (kasuje dane sklepu i wczytuje dane
    demo od nowa; wypisuje, co zrobi, i działa dopiero po słowie `tak`), `seal-secrets [WHAT="shop brevo tls"]` (szyfruje
    sekrety certyfikatem z repo do `environments/prod/secrets/`: brakujące klucze `init-env.sh` z losowymi wartościami,
    Brevo SMTP i certyfikat Origin CA czytane z terminala bez echa, błąd nic nie zapisuje) i `cloudflare-ips [CHECK=1]`
    (zakresy Cloudflare w `ipAllowList` produkcji). Agent wykonuje operacje na serwerze wyłącznie tymi celami. Kroki
    runbooka `infra/k8s/README.md` oznaczone „zgoda użytkownika” wykonuje dopiero po zgodzie w rozmowie, a oznaczone
    „użytkownik” (zakupy, konta zewnętrzne, wartości sekretów, Cloudflare Access, ruleset i deploy key, backup VPS-a)
    nie są jego decyzją ani czynnością.
- `node_modules` i `vendor` są w wolumenach Dockera, nie na hoście.
- Tryb sklepu wybiera zmienna `STORE_MODE` dla `make up`: `dev` (domyślnie) uruchamia `next dev`, `production` robi
  `next build` i uruchamia `next start`. Inna wartość zatrzymuje kontener sklepu, a nieudany build też, więc `make up`
  kończy się błędem. Tryb podaje się przy każdym `make up` (`STORE_MODE=production make up`); bez zmiennej
  sklep wraca do `dev`. Powtórzone `STORE_MODE=production make up` nie przebuduje działającego sklepu (compose
  odtwarza kontener tylko przy zmianie konfiguracji): po zmianie kodu użyj `docker compose restart store`, który
  uruchamia `next build` od nowa w tym samym trybie. Kontener `e2e` czyta `STORE_MODE` ze środowiska `make e2e`,
  nie z działającego sklepu, więc ten sam tryb podaj też tam (`STORE_MODE=production make e2e`); bez tego testy
  tylko produkcyjne są pomijane, a asercje ISR nie przechodzą. Lokalnie E2E działa domyślnie na `next dev`;
  E2E w CI działa na buildzie produkcyjnym (`STORE_MODE=production`), bo tylko on ma cache ISR.
- Każdy worktree ma lokalny `.env` (poza repo) z własnym `COMPOSE_PROJECT_NAME` i wolnymi portami, żeby stosy
  z różnych worktree nie kolidowały ze sobą ani z innymi projektami.

## Epiki, gałęzie `epic/*` i hooki AO

- Backlog jest w GitHub Issues: etykieta `epic` to funkcja z „Zakresu MVP” (`docs/vision.md`), `bug` to błąd,
  `ux` to problem użyteczności albo wyglądu, `tech-debt` to porządki bez zmiany zachowania, `needs-info` oznacza
  zgłoszenie z brakami, a `agent` zgłoszenie od agenta. Formularze `bug` i `ux` są w `.github/ISSUE_TEMPLATE/`.
  Kolejność ustala użytkownik na tablicy GitHub Projects „Backlog sklepu”. Definicje zadań są tylko w manifeście
  `plan.md`, ich stan tylko w AO. `/ao-run` dopisuje do końcowego PR epiku `Closes #N` dla issues z linii `Issues:`
  specyfikacji, a użytkownik sprawdza je przed merge'em. Proces:
  `docs/features/agentic-software-house/plan.md`.
- Epik realizowany przez AO ma gałąź `epic/<epik>` (zakładaną z `main`). PR zadań (`task/<id>`) mają bazę `epic/<epik>`
  i AO squash-merguje je tylko tam, nigdy do `main`. Wyjątek: zadanie REPAIR w AO (naprawa czerwonego CI gałęzi
  epiku albo konfliktu końcowego PR z `main`, przez prawdziwy `git merge origin/main`) i PR `sync/<epik>-main`
  z `/ao-run` trafiają do `epic/<epik>` merge commitem.
  PR `epic/<epik>` → `main` zatwierdza i merguje użytkownik.
  Skill `/delivery` dostaje gałąź docelową PR w linii `Gałąź docelowa PR:` (domyślnie `main`) i tworzy PR z `--base`.
- Ruleset `epic` (`tools/rulesets/epic.json`, wzorzec `refs/heads/epic/*`): zakaz force-push i usuwania gałęzi, zmiany
  tylko przez PR (0 zatwierdzeń, squash albo merge commit), wymagane checki `checks` i `e2e` (bez „up to date”), bez
  bypassu. Merge commit służy wyłącznie zadaniom REPAIR i synchronizacji z `main`: squash nie przesuwa
  merge-base gałęzi epiku, więc rozwiązany konflikt wracał przy każdym kolejnym merge'u `main`. PR zadań planu
  i poprawek QA idą przez squash.
  Tworzenia gałęzi nie blokuje: `do_not_enforce_on_create` pozwala AO wypchnąć nową gałąź od commita z `main`.
  Zakładanie lub aktualizacja (wymaga uprawnień admina repo):
  `gh api repos/piotrp321/shop/rulesets -X POST --input tools/rulesets/epic.json`
  (aktualizacja istniejącego: `-X PUT repos/piotrp321/shop/rulesets/<id>`).
- Gałęzie `deploy` i `deploy-images` to wdrażana wersja. `deploy` przesuwa się wyłącznie fast-forwardem na zatwierdzony
  commit z `main`. `deploy-images` (śledzi ją Argo CD) to kolejne snapshoty: drzewo commita z `deploy` plus wygenerowany
  `infra/k8s/environments/prod/values-images.yaml` (`image.tag` i digesty czterech obrazów; nie ma go na `main`), z
  rodzicami: poprzedni snapshot i commit z `deploy`. Aplikacja `shop` ma jedno źródło: chart z `deploy-images` z plikami
  wartości `prod` i `values-images.yaml`. Obie gałęzie przesuwa wyłącznie workflow `.github/workflows/deploy.yml` jednym
  `git push --atomic` (`.github/scripts/deploy-ref.sh`: bez `--force`, deploy keyem z sekretu repo `DEPLOY_KEY`), nikt
  ich nie edytuje ręcznie. Workflow:
  - push do `main`: buduje i wypycha cztery obrazy produkcyjne `ghcr.io/piotrp321/shop/<app>:<sha>` (api, store, admin,
    elasticsearch; ich digesty są w podsumowaniu runu), raz generuje `values-images.yaml`
    (`infra/k8s/scripts/images-values.sh`), uruchamia smoke test na tych obrazach (`make -C infra/k8s smoke` na
    `ubuntu-latest`; z `SMOKE_VALUES=<ten plik>` pierwsza instalacja pobiera obrazy z GHCR po digeście) i dopiero po zielonym
    oraz po sprawdzeniu renderu `prod` z tym plikiem (`infra/k8s/scripts/verify-images.sh`) przesuwa `deploy` i
    `deploy-images`. Błąd walidacji albo pusha: nic nie jest opublikowane. Bez sekretu `DEPLOY_KEY` kończy się po smoke
    teście z ostrzeżeniem, bez błędu. Runy dla `main` nie są anulowane: działa jeden, czeka najwyżej jeden (najnowszy).
  - pull request zmieniający pliki obrazów lub chartu (ścieżki w `deploy.yml`) i `workflow_dispatch`: tylko smoke test
    na obrazach z cache GHA, bez wypychania obrazów i bez ruszania `deploy`; to nie jest wymagany check.
    `workflow_dispatch` działa dopiero, gdy `deploy.yml` jest na gałęzi domyślnej (`main`); przed merge'em epiku próba
    na jego gałęzi odbywa się przez zdarzenie `pull_request` (PR zmienia `deploy.yml`, który jest w filtrze ścieżek).
  - Rulesety `deploy` (`tools/rulesets/deploy.json`: zakaz usunięcia i force pusha wszystkim, bez aktora bypass)
    i `deploy-push` (`tools/rulesets/deploy-push.json`: reguły `creation` i `update`, jedyny aktor bypass to deploy key,
    typ `DeployKey`) obejmują `refs/heads/deploy` i `refs/heads/deploy-images`, bez wymogu PR. Aktor `DeployKey` to
    każdy deploy key repo z prawem zapisu, więc repo ma mieć tylko jeden taki klucz (warunek dla runbooka wdrożenia).
    Są dwa, bo bypass dotyczy wszystkich reguł rulesetu. Zakładanie jak wyżej, osobno każdy plik
    (`--input tools/rulesets/deploy.json`, `--input tools/rulesets/deploy-push.json`). Rulesety, deploy key i sekret
    `DEPLOY_KEY` zakłada się po merge'u epiku według runbooka wdrożenia.
  - Obrazy, do których odwołują się snapshoty `deploy-images`, nie mogą być usuwane z GHCR (cofnięcie wersji to powrót do
    starszego snapshotu): żadna retencja pakietów ani workflow nie usuwa ich wersji (test w `make -C infra/k8s check`).
  - Test struktury rulesetów: `python3 -m unittest discover -s tools/rulesets -p 'test_*.py'`.
- CI uruchamia się też na `push` do `epic/**`, więc `checks` i `e2e` raportują na HEAD gałęzi epika po każdym merge'u.
  Run dla pusha jest grupowany po SHA i nie jest anulowany przez kolejne merge'e; anulowanie dotyczy tylko kolejnych
  pushy do tego samego PR.
- `.ao/setup` i `.ao/teardown` to wykonywalne skrypty uruchamiane przez AO z worktree zadania (zmienne
  `AO_COMPOSE_PROJECT` i `AO_PORT_BASE`, `AO_SETUP_READY_TIMEOUT` opcjonalnie). `setup` zapisuje `.env` (nazwa projektu
  i 7 kolejnych portów od `AO_PORT_BASE`), sprawdza, że porty są wolne, uruchamia `make up`, czyści pulę `cache.oidc` API (cache w `apps/api/var/cache`
  leży na bind mouncie worktree i przeżywa `teardown`, a nowy Keycloak ma nowe klucze), uruchamia `make seed`, po
  czym czeka na `/health` API. Nieudany `make up` ponawia raz. Każdy błąd po starcie stosu zapisuje `docker compose ps -a`
  i logi usług do `AO_SETUP_DIAG_DIR` (domyślnie `~/.local/state/ao-setup`, poza worktree), bo AO zaraz po nieudanym
  `setup` sprząta stos. `teardown` jest idempotentny: `docker compose -p "$AO_COMPOSE_PROJECT" down -v --remove-orphans`.
  Oba odmawiają pracy bez zmiennych albo bez Dockera. Testy: `python3 -m unittest discover -s .ao -p 'test_*.py'`.

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
- Wyjątek: zdjęcia marketingowe strony głównej sklepu (hero i kafelki działów) to statyczne pliki WebP w repo
  (`apps/store/src/home/images/`), nie zdjęcia produktów, więc nie idą przez Garage ani imgproxy. Ich jedyna lista
  rozmiarów (kadry, szerokości, budżety) to `apps/store/src/home/marketing-images.ts`; pliki generuje
  `apps/store/scripts/marketing-images.mjs`. Nie dodawaj rozmiaru zdjęcia marketingowego w komponencie.

## Docelowa skala

Docelowa skala jest opisana w `docs/vision.md` (sekcja „Wymagania niefunkcjonalne”).

- Uwzględniaj ją przy istotnych decyzjach architektonicznych. Preferuj rozwiązania, które pozwalają później
  skalować horyzontalnie.
- Nie wybieraj rozwiązań, które z definicji się nie skalują (np. ładowanie całego katalogu do pamięci).
- Decyzje i kompromisy, które mogą istotnie ograniczyć skalowalność, zgłaszaj użytkownikowi.
- Nie wprowadzaj przedwcześnie złożonych optymalizacji, dodatkowych usług ani abstrakcji bez rzeczywistej potrzeby.
- Nie twierdź, że system spełnia wymagania wydajnościowe, dopóki nie potwierdzą tego testy obciążeniowe.

## Sposób pracy

- Nie ma kodu bez planu. Przy wszystkim większym niż drobna poprawka: napisz lub zaktualizuj specyfikację
  w `docs/features/<funkcja>/`, zaproponuj plan, poczekaj na akceptację.
- Testy przed kodem (TDD) obowiązują dla logiki domeny i aplikacji oraz poprawek błędów: test uruchomiony przed
  implementacją nie przechodzi, po implementacji przechodzi. Raport zawiera komendy i wyniki obu uruchomień.
  Nie odtwarzaj fazy RED po implementacji. Gdy TDD nie zastosowano, napisz to wprost w raporcie.
  Konfigurację i infrastrukturę weryfikujemy komendami z planu, bez sztucznego odtwarzania fazy RED.
- Pracuj małymi krokami. Jedna logiczna zmiana na commit, format Conventional Commits.
- Jeden PR to jedna logiczna, weryfikowalna zmiana. Powiązane, niewielkie zadania planu mogą trafić do jednego PR.
  PR przechodzi CI i review. Nie merguj bez zgody użytkownika. Wyjątek: akceptacja epiku w `/prepare-epic` (specyfikacja
  i rozmiar końcowego PR) obejmuje zgodę na squash-merge PR zadań do jego gałęzi `epic/<epik>` po zielonym CI
  (zadanie REPAIR: merge commit).
  Drugi wyjątek: PR ze specyfikacją i planem epiku z `/prepare-epic` merguje skill po akceptacji użytkownika
  i zielonym CI, gdy PR zmienia wyłącznie te dwa pliki. Trzeci wyjątek: PR `sync/<epik>-main` z `/ao-run` merguje
  skill merge commitem po zgodzie użytkownika na tę procedurę i zielonym CI. Pozostałe PR do `main`, także końcowy
  PR epiku, merguje wyłącznie użytkownik.
- Każdy PR/MR mergujemy wyłącznie w trybie squash, a po merge'u usuwamy jego branch
  (np. `gh pr merge <nr> --squash --delete-branch`). Jedyny wyjątek to PR do `epic/*` z zadania REPAIR i PR
  `sync/<epik>-main`: merge commit (`--merge`).
- CI (`.github/workflows/ci.yml`) uruchamia `make check`, testy wszystkich aplikacji i E2E przy każdym PR i każdym
  pushu na `main` i `epic/**`. Job `e2e` po `make e2e` i testach `init-env.sh` uruchamia `make lighthouse`
  (sklep w trybie produkcyjnym), wysyła `e2e/lighthouse-results` jako artefakt `lighthouse-results` także po błędzie
  i dopisuje `medians.md` do podsumowania kroku (bez nowego joba i checka: niespełniony wynik SEO albo błąd pomiaru
  robi czerwony job `e2e`). Wyjątki dotyczą tylko PR (`.github/scripts/scope.sh`): PR zmieniający wyłącznie
  dokumentację niczego nie uruchamia, a PR zmieniający wyłącznie narzędzia repo (`.claude/**`, `.ao/**`, `tools/**`,
  ewentualnie z dokumentacją) uruchamia tylko testy skryptów. Testy na usługach stosu trafiają do CI w tym samym PR
  co kod, razem z uruchomieniem usług, których potrzebują.
- Zadania ustawiające reguły (lint, Deptrac, CORS i podobne) sprawdzają też próby obejścia, nie tylko
  przypadek podstawowy.
- Chart `infra/k8s/charts/shop` odzwierciedla compose. Zmiana usług, obrazów i ich wersji, zmiennych środowiska,
  transportów workera, sekretów w compose (`infra/scripts/init-env.sh`) i zmiana realmów Keycloaka
  (`infra/keycloak/realms`: nowy klient, rola, adres) wymaga tej samej zmiany w chartcie w tym samym PR. Pilnuje tego
  `make -C infra/k8s check` (każdy klucz z `init-env.sh` jest w szablonie sekretu, obrazy usług bez naszego kodu mają
  tag jak w compose i digest, Elasticsearch to obraz z `infra/elasticsearch/Dockerfile` jak w compose) i test dymny;
  nowej zmiennej z wartością domyślną ani zmiany istniejącego klienta realmu żaden z nich nie wyłapie. Nowy sekret
  (klucz w `infra/scripts/init-env.sh`) trafia do szablonu sekretu chartu i, gdy w repo jest certyfikat Sealed Secrets,
  zaszyfrowany `make -C infra/k8s seal-secrets` do `environments/prod/secrets/` w tym samym PR (pilnuje tego `check`). Zmiana wersji
  obrazu w compose wymaga nowego digestu w chartcie (`docker buildx imagetools inspect <obraz:tag>`).
- Realmy w klastrze aktualizuje wyłącznie Job `keycloak-config` (pliki realmów z repo bez `users`, z adresami domeny;
  nazwa Joba zależy od tych plików, `customerAccounts` i poczty Keycloaka). Brakujący realm zakłada; w istniejącym
  `partialImport` z `ifResourceExists=SKIP` dodaje tylko brakujące klienty, role i grupy i niczego nie nadpisuje. Potem
  przy każdym uruchomieniu ustawia wartości z konfiguracji: sekret klienta `store`, `redirectUris`, `webOrigins`
  i adresy po wylogowaniu klientów z domeny, rejestrację w realmie `shop` (`customerAccounts`), `verifyEmail` w `shop`
  i SMTP obu realmów (`keycloak.mail`: Mailpit albo Brevo). Nigdy nie usuwa użytkowników, haseł ani zasobów. Inna
  zmiana istniejącego klienta, roli albo ustawienia realmu w pliku do klastra nie trafi: wymaga ręcznej zmiany przez
  `kcadm` albo nowego kroku w Jobie.
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

## Claude Code i Superpowers

- Wspólna konfiguracja jest w `.claude/settings.json`: włączony plugin superpowers. Lokalne ustawienia trzymaj
  w `.claude/settings.local.json` (poza repo).
- Zadania z planu realizujemy przez `superpowers:subagent-driven-development`: orkiestrator prowadzi plan,
  subagenci implementują i robią review. Nie dokładamy własnej orkiestracji ani hooków. Implementerzy pracują
  po kolei, jak w skillu; równolegle tylko przy zadaniach naprawdę niezależnych, z ustalonym kontraktem, bez
  wspólnych plików i każdy we własnym worktree.
- Epik do realizacji przez Agentic Orchestrator (AO): `/prepare-epic [nr issue]` przygotowuje `spec.md` i `plan.md`
  (analityk według `/brainstorm`, `spec-critic`, `architecture-reviewer`, `/planner`). Użytkownik akceptuje
  specyfikację i rozmiar końcowego PR, `plan.md` przechodzi walidator manifestu (manifest JSON), a skill merguje
  do `main` PR z tymi plikami. `/brainstorm` i `/planner` zostają do pracy poza epikami. `ao submit-plan` zgłasza
  zadania pierwszej fali, a każde z nich realizuje `/delivery` jako osobny PR. Plan z `/planner` nie ma kroków
  implementacji.
  Po zmergowaniu zadań do `epic/<epik>` AO uruchamia `/integration` (globalny Architecture Review, adversarial QA
  i bramki `make check`, `make test`, `make e2e` na całym diffie `origin/main...HEAD`); przy zmianach w `apps/store`
  lub `apps/admin` dochodzi agent UX (`ux-reviewer`). Skill niczego nie commituje, a jego wynik waliduje
  `.claude/skills/integration/integration_result.py`.
- Po merge'u epiku sesja `/ao-run` sama uruchamia `/sweep <epik>` (kolejka przeglądów, nigdy równolegle z epikiem;
  pominięcie na baterii, przy oknie limitu ≥ 80% albo przy odmowie `/sweep`), a użytkownik może go uruchomić ręcznie (`/sweep [epik]`):
  agenci `tester` i `ux-reviewer` przechodzą sklep na świeżym stosie z `main`. Wynik to propozycje zgłoszeń do triage
  użytkownika (etykiety `bug`/`ux` i `agent`), nie issues.
- `/retro` (po merge'u epiku w kolejce `/ao-run` po `/sweep`, ręcznie `/retro [epik]`): `retro.py` zbiera incydenty
  z przebiegów AO, agent `retro-analyst` szuka ich przyczyn w instrukcjach procesu. Wynik to PR do `main` z poprawkami
  procesu i sekcją „Status” planu Agentic Software House, który merguje użytkownik (PR powstaje tylko przy
  poprawkach albo zmianie „Statusu”, inaczej wynik to „bez zmian”); issues i komentarze powstają dopiero po „tak”
  użytkownika.
- `/groom` (codziennie o 8:00 z zadania zaplanowanego w aplikacji Claude albo ręcznie): agent `backlog-groomer`
  proponuje duplikaty, nieaktualne zgłoszenia, braki, etykiety i jeden epik poprawek (`-FIXES`), a `groom.py` sprawdza
  listę i wykonuje w GitHubie tylko pozycje zatwierdzone przez użytkownika w rozmowie. Zatwierdzony epik poprawek ta
  sama sesja przygotowuje według `/prepare-epic` bez użytkownika aż do jednej wiadomości z pytaniami i akceptacją;
  samo „tak” akceptuje tylko przy spełnionej bramce `groom.py gate`, a przerwane przygotowanie wznawia
  `/prepare-epic <nr>`.
- Orkiestrator sam rozstrzyga szczegóły implementacji (wpis `Ruling:` w ledgerze). Istotne decyzje DDD (agregaty,
  value objecty, granice modułów, persystencja) oraz zmiany zasad, zależności, specyfikacji i architektury
  przedstawia użytkownikowi przed implementacją.
- Reviewer sprawdza zgodność ze specyfikacją, błędy logiczne, architekturę, przypadki brzegowe, testy (także
  przebieg TDD na podstawie wyników obu uruchomień) i zbędną złożoność. Brak prawdziwej fazy RED w zadaniu z TDD
  to problem Important.
- PR realizowany przez SDD kończy się przeglądem całej zmiany (krok skilla). Inny PR z większą zmianą przechodzi
  taki przegląd przez `superpowers:requesting-code-review`. Dodatkowych reviewerów ani modeli nie uruchamiamy
  bez powodu.
- Raport po PR: zakres, subagenci i ich role, wynik review, wykryte i naprawione problemy, uruchomione testy
  z wynikami, link do PR i CI, czas pracy i zużycie modelu. Zwięźle, bez pełnych logów.
- Stan prac jest wyłącznie w sekcji „Status” planu funkcji (`docs/features/<funkcja>/plan.md`). Przebieg prób
  zadań zgłoszonych do AO jest w AO (`ao status`), nie w repo.

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


## Tymczasowe

Gdy użytkownik wywołuje `/brainstorm`, stosuj wyłącznie nasz Skill `brainstorm`, zamiast `superpowers:brainstorming`. W pozostałych przypadkach obowiązują dotychczasowe zasady.