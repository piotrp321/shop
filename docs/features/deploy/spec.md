# DEPLOY: środowisko wdrożeniowe na k3s

Pierwsze wdrożenie sklepu i panelu poza komputerem dewelopera: jeden serwer, jeden klaster k3s, jeden pipeline,
GitOps przez Argo CD. Na start działa jako demonstracja (sklep publiczny z informacją o symulowanych płatnościach,
`noindex`, panel za Cloudflare Access, rejestracja wyłączona, potwierdzenia zamówień nie wychodzą na zewnątrz). Te
ograniczenia są wartościami konfiguracji, więc przejście na publiczny sklep nie wymaga przebudowy obrazów ani
architektury. Realizowany równolegle z MVP decyzją użytkownika; zastępuje sekcję „Po MVP: publiczne środowisko
demo” z `docs/vision.md`. Część 1 z 2: kopie zapasowe i monitoring są w DEPLOY-OPS (#178), które musi być
skończone przed przełączeniem na publiczny sklep albo przed pierwszymi prawdziwymi danymi.

Issues: #175
Zakres: `infra/k8s`, `apps/api/Dockerfile`, `apps/api/docker`, `apps/api/.dockerignore`, `apps/api/bin`, `apps/store/Dockerfile`, `apps/store/.dockerignore`, `apps/store/next.config.ts`, `apps/store/app/layout.tsx`, `apps/store/app/api/site`, `apps/store/src/components`, `apps/store/src/account`, `apps/admin/Dockerfile`, `apps/admin/.dockerignore`, `apps/admin/index.html`, `apps/admin/public`, `apps/admin/src/auth`, `apps/admin/src/api/client.ts`, `apps/admin/src/config`, `Makefile`, `CLAUDE.md`, `apps/api/CLAUDE.md`, `apps/store/CLAUDE.md`, `apps/admin/CLAUDE.md`, `docs/vision.md`, `docs/adr`, `tools/rulesets`

## Stan wyjściowy

- Obrazy aplikacji są tylko deweloperskie. `apps/api/Dockerfile` ma etap `base` (FrankenPHP, rozszerzenia, Composer,
  git, unzip, entrypoint z `composer install`) i `dev` (SPX); kod jest montowany z hosta. `apps/store/Dockerfile`
  i `apps/admin/Dockerfile` zawierają same zależności (`npm ci`); serwer uruchamia komenda z compose.
- Compose buduje `store` i `admin` bez `target` (ostatni etap), `api` i `worker` z `target: dev`, a `garage-init`
  z obrazu budowanego inline (`dockerfile_inline`: binarka `/garage` w Alpine).
- CI (`.github/workflows/ci.yml`) trzyma w GHCR obrazy deweloperskie `ghcr.io/piotrp321/shop-<app>:<klucz>` i uruchamia
  cały stos compose z E2E na `ubuntu-latest`. Repo jest publiczne.
- Gałąź `main` chroni ruleset: zmiany tylko przez PR (tylko squash w praktyce, historia liniowa), wymagane checki
  `checks` i `e2e`, bez bypassu.
- Sekrety lokalne generuje `infra/scripts/init-env.sh` do `.env.local` (lista kluczy w skrypcie).
- API ma w `prod` logi JSON na stderr, komendy `catalog:search-index:setup`, `catalog:reindex`,
  `storefront:cache:purge-all` (wymaga Varnisha i sklepu), `cart:purge-expired` i seedy `<moduł>:seed-demo`, które
  w `prod` wymagają `--force-reset`. `catalog:seed-demo` czeka do 60 s, aż worker zaindeksuje produkty. `make seed`
  uruchamia seedy w kolejności z głównego `Makefile` (zamówienia przed stanami).
- Pliki realmów (`infra/keycloak/realms/*.json`, publiczne repo) zawierają konta demo z hasłami i sekretami TOTP;
  placeholdery `${STORE_URL}`, `${ADMIN_URL}`, `${STORE_CLIENT_SECRET}` Keycloak podstawia przy imporcie przy starcie
  (`--import-realm`, tylko brakujące realmy). Panel loguje się przez `oidc-client-ts` (discovery, wymiana kodu
  i odnawianie tokenu to zapytania `fetch` z originu panelu do Keycloaka).
- Layout sklepu (`apps/store/app/layout.tsx`) jest wspólny; część stron (`/cart`, `/checkout`, strona 404) jest
  renderowana w czasie buildu, strony katalogu przez ISR. Link „Zaloguj się” jest w `src/account/AccountMenu.tsx`.

## Cel i zakres

- Produkcyjne obrazy `api` (także dla workera, Jobów i CronJobów), `store`, `admin` i `elasticsearch`, budowane
  w GitHub Actions z każdego commita na `main` i wypychane do GHCR.
- Helm chart całego stosu w `infra/k8s/`, wdrażany przez Argo CD z gałęzi `deploy`.
- Sekrety w repo tylko zaszyfrowane (Sealed Secrets).
- Migracje i przygotowanie indeksu jako Job przed startem nowej wersji, nigdy przy starcie kontenera aplikacji.
- Jednorazowa, idempotentna inicjalizacja katalogu demo; kolejne wdrożenia nie nadpisują danych.
- Keycloak bez kont demo, z realmami aktualizowanymi addytywnie; pierwszy administrator zakładany po zgodzie
  użytkownika.
- Poczta przez Brevo SMTP dla Symfony Mailera i Keycloaka (SPF, DKIM, DMARC w Cloudflare); wysyłka potwierdzeń
  zamówień przez Brevo za przełącznikiem, na start wyłączonym.
- Ograniczenia startowe sterowane konfiguracją: pasek demonstracji, `X-Robots-Tag: noindex`, rejestracja
  i logowanie klientów, poczta zamówień; Cloudflare Access przed panelem.
- Brzeg sieci: Cloudflare; origin przyjmuje HTTP(S) tylko od Cloudflare.
- Weryfikacja bez serwera: statyczny check chartu w wymaganym CI i smoke test na k3d na standardowym runnerze.
- Bootstrap czystego VPS-a i wdrożenie wykonywalne przez Claude Code po SSH, z jawnymi punktami zgody i krokami
  użytkownika.

## Poza zakresem

- Kopie zapasowe (restic, weryfikacja, odtwarzanie), Grafana Alloy i alerty – DEPLOY-OPS (#178). W tym epiku tylko
  włączenie backupu VPS-a od OVH (runbook).
- Osobne środowiska demo i produkcji, wiele węzłów, autoskalowanie, Rancher, własny Prometheus i Loki.
- Tryb publicznego demo (nocny reset danych, publiczne konta demo, limity uploadów) – #179.
- Sentry (SDK w api, store i admin) oraz trace'y i Sentry Performance – #180.
- Włączenie publicznej rejestracji i wysyłki zamówień przez Brevo: wymaga polityki prywatności i zabezpieczeń przed
  nadużyciami (#181); ten epik daje przełączniki i działającą weryfikację e-mail.
- Testy obciążeniowe.
- Zakup serwera, domeny, założenie kont zewnętrznych (Cloudflare, Brevo), ruleset
  i deploy key na GitHubie oraz samo uruchomienie na serwerze – po merge'u epiku, poza AO, według runbooka (agent
  prowadzi kroki oznaczone „agent”). Zadania epiku nie wymagają serwera ani zmian w ustawieniach repo.
- Sprawdzenie nazwy sklepu w bazach znaków towarowych (UPRP, EUIPO).
- Zmiany środowiska lokalnego poza wspólnym skryptem seedów i przełącznikami sklepu (domyślnie jak dziś).

## Decyzje

- **D1. k3s na jednym węźle, Traefik z k3s jako ingress, `local-path` jako storage.**
- **D2. Własny chart zamiast gotowych chartów usług.** Jeden chart `infra/k8s/charts/shop` z szablonami dla każdej
  usługi compose, na tych samych obrazach i wersjach co `infra/docker-compose.yml`. Elasticsearch działa na obrazie
  budowanym z tego samego Dockerfile co w compose (`infra/elasticsearch/Dockerfile`). Gotowe charty tylko dla
  platformy: Argo CD i Sealed Secrets.
- **D3. Nazwy usług w klastrze takie jak w compose**; wewnętrzne adresy i VCL (`api:80`) bez zmian.
- **D4. Wdrażana gałąź `deploy`, przesuwana fast-forwardem.** Argo CD śledzi `deploy` i ustawia tag obrazów na
  `$ARGOCD_APP_REVISION` (parametr Helma aplikacji Argo), czyli SHA commita. Obrazy są wdrażane po digeście
  (`image.digests`); sposób, w jaki digesty trafiają do aplikacji Argo CD przy zachowaniu fast-forwardu `deploy`,
  ustalają DEPLOY-5 i DEPLOY-6. Workflow wdrożenia po zbudowaniu obrazów
  z tagiem SHA i zielonym smoke teście przesuwa `deploy` fast-forwardem na ten commit z `main` (historia `main` jest
  liniowa, więc `deploy` jest zawsze jej przodkiem). Bez commitów z tagami i bez force pusha. Wypycha deploy key
  z prawem zapisu (sekret repo `DEPLOY_KEY`, skrypt `.github/scripts/deploy-ref.sh` pushuje bez `--force`). Bypass
  rulesetu dotyczy wszystkich jego reguł, więc gałąź chronią dwa rulesety na `refs/heads/deploy`: `deploy`
  (`tools/rulesets/deploy.json`: `deletion` i `non_fast_forward`, bez żadnego aktora bypass) zabrania usunięcia
  i force pusha wszystkim, a `deploy-push` (`tools/rulesets/deploy-push.json`: reguły `creation` i `update`) dopuszcza
  utworzenie gałęzi i zwykły push tylko dla deploy keya (jedyny aktor bypass, typ `DeployKey`, tryb `always`). Aktor
  `DeployKey` obejmuje każdy deploy key repo z prawem zapisu, więc repo ma mieć tylko jeden taki klucz (warunek
  dla runbooka wdrożenia). Założenie
  rulesetów i deploy keya oraz próby (push innym poświadczeniem i usunięcie odrzucone, push deploy keyem przyjęty) to
  krok runbooka po merge'u, z zapisem wyników.
- **D5. Sealed Secrets.** Publiczny certyfikat kontrolera trafia do repo przy bootstrapie, więc kolejne sekrety
  zaszyfruje każdy (także agent AO) bez dostępu do klastra. Kopia klucza prywatnego kontrolera jest u użytkownika.
- **D6. TLS i dostęp do originu.** Cloudflare w trybie proxy, „Full (strict)”, certyfikat Cloudflare Origin CA jako
  zaszyfrowany sekret TLS Traefika. Ruch HTTP(S) spoza Cloudflare odrzuca middleware Traefika `ipAllowList` z zakresami
  Cloudflare (lista w wartościach chartu, aktualizowana skryptem), na wszystkich ingressach; serwis Traefika ma
  `externalTrafficPolicy: Local`, więc Traefik widzi prawdziwy adres źródłowy. Firewall hosta (ufw) chroni tylko
  porty hosta (SSH i API k3s), bo ruch do Traefika k3s kieruje DNAT-em z pominięciem łańcucha INPUT.
- **D7. Konfiguracja frontów w czasie uruchomienia.** Obrazy są budowane raz. Panel czyta adresy z `/config.js`
  generowanego przy starcie kontenera nginx; sklep podaje przełączniki przeglądarce z dynamicznego endpointu
  `/api/site` (bez cache), więc HTML stron statycznych i ISR jest niezależny od przełączników.
- **D8. Kolejność startu wymuszają initContainery, fale Argo ją porządkują.** Każdy pod i Job czeka w initContainerze
  na to, czego potrzebuje; fale ustawiają zależności wcześniej niż zależnych. Ta sama kolejność działa przy
  pierwszej synchronizacji pustego klastra, przy aktualizacji i w smoke teście instalowanym Helmem bez fal.
- **D9. Keycloak: realmy z chartu, aktualizowane addytywnie.** Chart renderuje pliki realmów z repo bez sekcji
  `users` i z podstawionymi adresami (Helm `fromJson`). Job `keycloak-config` (obraz Keycloaka, `kcadm`) zakłada realm,
  gdy go nie ma, a gdy jest, robi `partialImport` z `ifResourceExists=SKIP` (dodaje brakujące klienty, role i grupy,
  niczego nie nadpisuje). Potem zawsze ustawia wartości sterowane konfiguracją: sekret klienta `store`, `redirectUris`
  i `webOrigins` z domeny, rejestrację, `verifyEmail`, SMTP. Nie usuwa użytkowników, haseł ani zasobów. Lokalny
  `--import-realm` z kontami demo zostaje bez zmian.
- **D10. Ograniczenia startowe są wartościami chartu** (`environments/prod/values.yaml`): `demoNotice`, `noindex`,
  `customerAccounts` (rejestracja w realmie `shop` i link logowania w sklepie), `orderMail` (`brevo` albo `mailpit`).
  Zmiana to commit z wartością, który przechodzi zwykły pipeline (build z cache, smoke, wdrożenie); kod i obrazy się
  nie zmieniają. Cloudflare Access jest konfiguracją Cloudflare (runbook).
- **D11. Joby wersji nazwane sumą kontrolną** rewizji i konfiguracji Joba (`<job>-<hash>`): nowa rewizja albo zmiana
  wartości dotyczących Joba daje nowy Job, a ten sam stan nie uruchamia go ponownie. Bez `ttlSecondsAfterFinished`.
  Ponowienie nieudanego Joba bez nowej rewizji: cel `make -C infra/k8s rerun JOB=<nazwa>` (runbook).

## Adresy

Domena bazowa jest parametrem wartości chartu (`global.domain`); użytkownik poda ją przed uruchomieniem.

| Host | Usługa | Dostęp |
|---|---|---|
| `<domena>` | store | publiczny; `X-Robots-Tag: noindex`, gdy `noindex` |
| `admin.<domena>` | admin | Cloudflare Access (lista uprawnionych e-maili) |
| `api.<domena>` | api | publiczny; API panelu (token realmu `backoffice` i role, jak dziś) i strona płatności symulatora; sklep i webhooki symulatora wołają API wewnątrz klastra |
| `auth.<domena>` | keycloak | tylko `/realms/shop/`, `/realms/backoffice/` i `/resources/`; realm `master` i konsola administracyjna niedostępne z zewnątrz; bez Access (panel wymienia kod na token zapytaniem z innego originu), realm `backoffice` chronią hasło, TOTP i blokada brute force |
| `img.<domena>` | imgproxy | publiczny; reguła cache Cloudflare dla całego hosta |

Argo CD, RabbitMQ, Mailpit, Garage, Elasticsearch i Postgres nie mają publicznego adresu; dostęp przez
`kubectl port-forward` po SSH.

## Obrazy produkcyjne

Cztery obrazy `ghcr.io/piotrp321/shop/<app>:<sha commita>` (`api`, `store`, `admin`, `elasticsearch`), publiczne
(runbook ustawia widoczność pakietów; k3s pobiera je bez `imagePullSecret`).

- **api:** etapy: `runtime` (FrankenPHP, rozszerzenia, konfiguracja PHP i Caddy), `base` z niego (Composer, git,
  unzip, entrypoint z `composer install`, jak dziś), `build` z `base` (`composer install --no-dev
  --classmap-authoritative`, rozgrzany cache `prod`), `prod` z `runtime` (kod i `vendor` z `build`, produkcyjne opcache,
  bez entrypointu instalującego zależności, bez Composera, git i SPX), `dev` z `base` jako ostatni (compose bez zmian).
  Dane demo (`apps/api/fixtures`) są w obrazie. Ten sam obraz uruchamia worker (te same transporty co compose), Joby
  i CronJoby.
- **store:** etap `prod`: `next build` z `output: "standalone"` włączanym zmienną tylko w buildzie obrazu (lokalny
  `STORE_MODE=production` bez zmian), komenda `node server.js`. Build nie woła API.
- **admin:** etap `prod`: `vite build` i `nginx` (obraz unprivileged) z generowaniem `/config.js` przy starcie
  (`window.__ADMIN_CONFIG__`), fallbackiem SPA na `index.html` i `Cache-Control: no-cache` dla `index.html`
  i `/config.js`. Panel czyta najpierw `window.__ADMIN_CONFIG__`, a bez niego `import.meta.env`; lokalnie
  `public/config.js` jest pusty (bez 404 w `vite dev`). Brak wymaganego klucza w obu źródłach: błąd z nazwą klucza.
- **elasticsearch:** `infra/elasticsearch/Dockerfile`, ten sam, który buduje compose: oficjalny obraz Elasticsearch
  przypięty digestem i wtyczka `analysis-stempel` instalowana w czasie buildu (jej wersja jest wersją Elasticsearch).
  Wersje w compose i w klastrze są zgodne z definicji.
- Wdrożenie po digeście, nigdy `latest`: obraz własny to `<rejestr>/<app>:<sha>@<digest>`, gdy wartość
  `image.digests.<app>` jest podana, inaczej `<rejestr>/<app>:<sha>`. `image.requireDigests: true` (profil `prod`)
  wymaga wszystkich czterech digestów (`sha256:` i 64 znaki hex); podany digest zawsze musi mieć ten format,
  a `image.tag` nie może być `latest` (schemat wartości, błąd renderu z nazwą wartości). Profil `ci` digestów nie
  wymaga (smoke test importuje obrazy lokalne po tagu).
- Obrazy usług bez własnego kodu (`images.*` w wartościach chartu) mają postać `nazwa:tag@sha256:<digest indeksu
  wieloarchitekturowego>`, z tagiem takim jak w `infra/docker-compose.yml`; compose zostaje przy samych tagach.
- Kontenery przy starcie niczego nie pobierają (wtyczek ani innych artefaktów); klaster pobiera tylko obrazy
  o przypiętych digestach.
- Etap używany przez compose pozostaje ostatni; lokalny `make up` działa jak dziś.
- Usługi bez własnego kodu używają obrazów i wersji z `infra/docker-compose.yml`. `garage-init` w klastrze: kontener
  Alpine uruchamia `infra/garage/init.sh` z binarką Garage zamontowaną z jej obrazu wolumenem typu `image` (bez kopii
  w `emptyDir`). Wolumen `image` (ImageVolume) jest alfa w Kubernetes 1.31–1.32, betą domyślnie wyłączoną w 1.33–1.34,
  betą domyślnie włączoną w 1.35 i stabilny od 1.36; wymaga też runtime'u z obsługą wolumenów obrazów (containerd 2.1
  lub nowszy). Wymaganie: k3s/Kubernetes ≥ 1.36. Pilnuje go `kubeVersion: ">=1.36.0-0"` w `Chart.yaml` (Helm, a przez
  niego Argo CD, odmawia renderu dla starszego klastra), a smoke test działa na k3s `v1.36.6-k3s1`; bootstrap
  (DEPLOY-6) przypina k3s ≥ v1.36. Konfiguracja z repo
  (VCL, `garage.toml`, init Postgresa, `init.sh`, realmy) trafia do ConfigMap przez symlinki
  w `infra/k8s/charts/shop/files/`. Pierwsze zadanie sprawdza, że Helm i Argo CD czytają symlinki wewnątrz repo; jeśli
  nie, chart trzyma kopie, a `check` sprawdza ich zgodność z oryginałami.

## Chart `shop`

- Usługi stanowe (Postgres, Elasticsearch, RabbitMQ, Redis, Garage) jako StatefulSety z PVC `local-path`.
  Elasticsearch `single-node`, bez security (bez publicznego adresu).
- Bezstanowe (api, worker, store, admin, imgproxy, varnish, keycloak, mailpit) jako Deploymenty z jedną repliką,
  `maxUnavailable: 0`, sondami jak health checki compose.
- Keycloak w trybie `start`, za proxy (`KC_PROXY_HEADERS=xforwarded`, `KC_HTTP_ENABLED=true`),
  `KC_HOSTNAME=https://auth.<domena>`, `KC_HOSTNAME_BACKCHANNEL_DYNAMIC=true`, bez `--import-realm` (D9).
- Fale i Joby (D8, D11):

  | Fala | Zasoby | Czeka w initContainerze na |
  |---|---|---|
  | −2 | Secret, ConfigMap, middleware Traefika | – |
  | −1 | StatefulSety, Keycloak, Mailpit | Keycloak: Postgres |
  | 0 | Joby `garage-init`, `migrate` (`doctrine:migrations:migrate --no-interaction`, `catalog:search-index:setup`), `keycloak-config` | Garage; Postgres i Elasticsearch; gotowy Keycloak |
  | 1 | Deploymenty api, worker, store, admin, imgproxy, varnish | api i worker: `doctrine:migrations:up-to-date`; imgproxy: Garage z bucketami |
  | 2 | Job `initialize` (dane startowe) | zakończony rollout Deploymentów api, varnish, store, worker |
  | 3 | Job `purge-caches` (`storefront:cache:purge-all`) | zakończony rollout Deploymentów api, varnish, store |

  Nieudana migracja: nowe `api` i `worker` nigdy nie są gotowe, stare działają (`maxUnavailable: 0`).
- `initialize` i `purge-caches` czekają na rollout (`kubectl rollout status`) w initContainerach z obrazem `kubectl`,
  a nie na odpowiedź Service, którą mógłby jeszcze dać stary pod. Te Joby działają na ServiceAccount `shop-wait`
  (Role: tylko `get`, `list`, `watch` na `deployments`), a token API Kubernetes (wolumen `projected`) montują tylko te
  initContainery. Pozostałe pody i kontenery nie montują tokenu (`automountServiceAccountToken: false`). Deploymenty
  mają `progressDeadlineSeconds` nie krótszy niż czas oczekiwania Jobów (1500 s).
- Middleware Traefika: `ipAllowList` (D6) na wszystkich ingressach; `X-Robots-Tag: noindex` na wszystkich hostach,
  gdy `noindex: true`.
- CronJob `cart:purge-expired` (co godzinę, `concurrencyPolicy: Forbid`, strefa `Europe/Warsaw`).
- `rabbitmq-init` (vhost testów) nie istnieje.
- Profile wartości:
  - `values.yaml` – wspólne; wymagane wartości walidowane schematem (`values.schema.json`), więc brak np. sekretu SMTP
    przy `orderMail: brevo` kończy render (i synchronizację Argo) błędem z nazwą wartości;
  - `environments/prod/values.yaml` – domena, przełączniki D10, zasoby na serwer docelowy (8 vCore, 24 GB RAM): suma
    requestów CPU do 7 vCPU (limity CPU mogą je przekraczać), suma limitów pamięci do 20 GB;
  - `environments/ci/values.yaml` – odchudzony profil smoke testu: hosty `*.localhost`, zwykłe sekrety, bez
    `ipAllowList`, minimalne requesty, sterta ES 512 MB, mniejsza sterta Keycloaka, poczta do Mailpita; mieści się na `ubuntu-latest` (16 GB RAM, 4 vCPU, 14 GB SSD).

## Wdrożenie nowej wersji

1. Merge PR do `main` uruchamia `.github/workflows/deploy.yml`. Grupa `concurrency` bez anulowania trwającego runu:
   działa jeden, czeka najwyżej jeden, pośrednie są pomijane, więc wdraża się najnowszy commit.
2. Workflow buduje i wypycha obrazy `api`, `store`, `admin`, `elasticsearch` z tagiem SHA; wdrażane są po digeście
   (D4). Digesty czterech obrazów
   są w podsumowaniu runu i w outputach joba `images`. Obraz, który już ma ten tag (ponowny run tego samego commitu),
   zostaje bez przebudowy, więc jeden tag nie dostaje drugiego digestu.
3. Smoke test na k3d (niżej) na tych obrazach.
4. Po zielonym smoke teście workflow przesuwa `deploy` fast-forwardem na ten commit (D4). Bez sekretu z deploy keyem
   (przed krokiem runbooka) workflow kończy się po smoke teście z ostrzeżeniem, bez błędu.
5. Argo CD synchronizuje `shop` z `deploy` według fal.
6. Wycofanie wersji: revert na `main` (nowe wdrożenie). Schemat bazy jest zgodny wstecz tylko z poprzednią wersją;
   cofnięcie o więcej wersji wymaga odtworzenia kopii (DEPLOY-OPS) albo pełnego ponownego wczytania danych
   (`reinitialize`, zgoda użytkownika).

Zasady dla kolejnych epików (dokumentacja w `apps/api/CLAUDE.md` i `CLAUDE.md`): migracje zgodne wstecz z poprzednią
wersją kodu; wiadomości Messengera zgodne w obie strony między kolejnymi wersjami; zmiana usług, zmiennych,
transportów workera albo sekretów w compose i zmiana realmów (nowy klient, rola, adres) wymagają tej samej zmiany
w chartcie w tym samym PR.

## Dane startowe

- Wspólny skrypt `apps/api/bin/seed-demo` uruchamia komendy `<moduł>:seed-demo` w kolejności z `make seed`
  (z modułami PAYMENT) i `storefront:cache:purge-all`; w `prod` przekazuje `--force-reset`. `make seed` woła ten sam
  skrypt.
- Job `initialize` (fala 2, skrypt `files/scripts/initialize.sh`) liczy produkty, ceny, stany i zamówienia w bazie
  `shop` (zapytania tylko do odczytu) i rozróżnia trzy stany:
  - wszystkie cztery liczby są zerowe – wczytuje dane demo (`bin/seed-demo`, w `prod` z `--force-reset`);
  - są produkty, ceny i stany – sukces bez zmian, niezależnie od zamówień;
  - każdy inny stan jest mieszany – błąd z liczbami i bez zmian, np. pusty katalog z zamówieniami albo cenami (reset
    seeda by je skasował) albo produkty bez cen lub stanów (przerwana inicjalizacja, wymaga decyzji człowieka).
- Wyłączany wartością `initialize.enabled: false` (na potrzeby odtwarzania kopii w DEPLOY-OPS).
- Inicjalizacja przerwana w połowie: kolejne uruchomienie kończy się błędem stanu mieszanego; runbook opisuje ponowne
  wymuszone wczytanie (`make -C infra/k8s reinitialize`), które kasuje dane i wymaga zgody użytkownika.

## Keycloak i konta

- Realmy bez użytkowników demo, aktualizowane addytywnie (D9).
- Pierwszy administrator: `make -C infra/k8s create-admin LOGIN=… EMAIL=…` (po zgodzie użytkownika; może go uruchomić
  agent). Zakłada użytkownika w realmie `backoffice` z rolą `admin` bez hasła i wysyła mu przez SMTP Brevo e-mail
  z akcjami „ustaw hasło” i „skonfiguruj TOTP” (`execute-actions-email`, link ważny 12 h). Hasło i TOTP ustawia
  administrator w przeglądarce, więc nie trafiają do repo, skryptów, logów ani kontekstu agenta. Istniejący login:
  błąd bez zmian.
- Konta klientów: `customerAccounts: false` w `prod` wyłącza rejestrację w realmie `shop` i ukrywa link „Zaloguj się”
  w sklepie (przełącznik z `/api/site`). `verifyEmail` włączone; e-mail weryfikacyjny i reset hasła idą przez Brevo.

## Poczta

- SMTP realmów `shop` i `backoffice` (Keycloak) zawsze przez Brevo; `MAILER_DSN` API przez Brevo, gdy
  `orderMail: brevo`, a do Mailpita w klastrze, gdy `orderMail: mailpit` (start). Jeden zaszyfrowany sekret z danymi
  Brevo SMTP; nadawca z wartości chartu (domena użytkownika). Bez nowych zależności (transport SMTP Symfony).
- Runbook: rekordy SPF, DKIM (z panelu Brevo) i DMARC (`p=quarantine`, raporty na adres użytkownika) w Cloudflare,
  weryfikacja domeny nadawcy w Brevo, test wysyłki (`create-admin` jest pierwszym prawdziwym e-mailem).
- Smoke test: poczta API i Keycloaka do Mailpita.

## Sklep: przełączniki

- Endpoint `/api/site` (route handler, dynamiczny, `Cache-Control: no-store`) zwraca `{ demoNotice, customerAccounts }`
  ze zmiennych serwera `STORE_DEMO_NOTICE` i `STORE_CUSTOMER_ACCOUNTS`. Domyślnie (lokalnie, w testach):
  `demoNotice: false`, `customerAccounts: true`, czyli jak dziś.
- Komponent klienta w layoutcie pokazuje nad nagłówkiem pasek: „Sklep demonstracyjny: płatności i zamówienia są
  symulowane, nic nie zostanie wysłane ani pobrane.”, gdy `demoNotice`. `AccountMenu` nie pokazuje „Zaloguj się”, gdy
  `!customerAccounts`. Błąd odczytu `/api/site`: bez paska i z linkiem (stan domyślny).
- Testy komponentów (z flagą, bez niej, błąd odczytu); smoke test sprawdza pasek i brak linku na `/` i `/cart`
  w obrazie zbudowanym bez flag.

## Kopie zapasowe

W tym epiku tylko automatyczny backup VPS-a od OVH (runbook: włączenie). Kopie baz i obiektów poza serwerem,
weryfikacja, odtwarzanie i alerty są w DEPLOY-OPS (#178).

## Sekrety

- Klucze z `infra/scripts/init-env.sh` oraz zewnętrzne: Brevo SMTP, certyfikat i klucz Cloudflare Origin CA.
- `infra/k8s/scripts/seal-secrets.sh` (w kontenerze z `kubeseal`) szyfruje certyfikatem z repo
  (`environments/prod/sealed-secrets.pem`). Klucze z listy `init-env.sh` (czytanej z pliku) dopisuje tylko brakujące,
  z losową wartością; istniejących nie zmienia. Wartości zewnętrzne czyta interaktywnie z terminala (bez echa), nie
  z argumentów ani zmiennych, i nie wypisuje ich; krok runbooka „użytkownik”. Brak certyfikatu, pusta wartość albo
  nieudany `kubeseal` kończą skrypt błędem bez zapisu.
- `check` chartu: każdy klucz z `init-env.sh` jest w szablonie sekretu chartu (zawsze); gdy w repo jest certyfikat,
  także ma zaszyfrowaną wartość w `environments/prod/secrets/`.

## Serwer, bootstrap i wdrożenie przez Claude Code

- Cele `infra/k8s/Makefile` działają z komputera użytkownika (narzędzia w kontenerach) i łączą się z serwerem po SSH
  (`HOST=user@adres`):
  - `bootstrap`: na czystym Ubuntu 24.04 LTS przypięta wersja k3s (≥ v1.36, wymaganie chartu), `vm.max_map_count=262144`, ufw (SSH i API k3s;
    D6), automatyczne poprawki bezpieczeństwa, Sealed Secrets (z kluczem z kopii, jeśli jest; inaczej zapis
    certyfikatu do repo), Argo CD z aplikacją `root` (app-of-apps w `infra/k8s/argocd/`). Idempotentny; brak uprawnień
    root albo inny system kończą go błędem przed zmianami.
  - `status`: stan aplikacji Argo, podów i Jobów.
  - `rerun JOB=…`: ponowienie Joba wersji (D11).
  - `create-admin`, `reinitialize`: wypisują, co zrobią, i wymagają potwierdzenia słowem `tak`; agent
    uruchamia je tylko po zgodzie użytkownika w rozmowie.
- Runbook `infra/k8s/README.md` jest sekwencją tych celów i kroków w Cloudflare, Brevo i GitHubie, oznaczonych:
  „agent”, „zgoda użytkownika” (firewall, operacje kasujące dane, konta), „użytkownik” (zakupy, konta zewnętrzne,
  wartości sekretów, Cloudflare Access, ruleset i deploy key, backup VPS-a w OVH). Zawiera sprawdzenia po uruchomieniu: żądanie na IP
  serwera spoza Cloudflare odrzucone, logowanie do panelu przez Access, e-mail z `create-admin` dotarł, próby
  rulesetu `deploy`.

## Weryfikacja bez serwera

- `make -C infra/k8s check`: `helm lint`, render dla `prod` i `ci` (`prod` z testowym SHA), `kubeconform`,
  `shellcheck`, test sekretów, (przy kopiach) zgodność kopii z oryginałami, `actionlint` workflowu, testy renderu:
  przełączniki D10, CronJoby, ścieżki ingressu `auth`, middleware, fale (każdy Job i Deployment ma zależności we
  wcześniejszych falach), nazwy Jobów D11, obrazy (własne z rejestru, z digestem w `prod`; pozostałe z tagiem jak
  w compose i digestem; Elasticsearch z `infra/elasticsearch/Dockerfile`, bez `elasticsearch-plugin` w kontenerach).
  Render (z wersją Kubernetes klastra smoke testu, `--kube-version`), który musi się nie udać: `prod` bez digestów,
  `image.tag=latest`, digest w złym formacie, obraz usługi bez digestu, klaster Kubernetes 1.35 (`kubeVersion`). Główny `make check` go wywołuje, więc działa w wymaganym jobie `checks`
  i w bramkach AO.
- `make -C infra/k8s smoke`: buduje cztery obrazy produkcyjne lokalnie i importuje je do k3d (bez wypychania; nazwa
  klastra i porty z `COMPOSE_PROJECT_NAME` i `.env` worktree), instaluje chart z profilem `ci` na pustym klastrze,
  czeka na Joby i Deploymenty, a potem sprawdza: wtyczkę `analysis-stempel` w Elasticsearchu (`_cat/plugins`)
  i działanie filtra `polish_stem` (`_analyze`: „koszulkami” → „koszulka”), `/health` API, stronę główną sklepu z produktem, pasek i brak linku logowania
  na `/` i `/cart`, nagłówek `X-Robots-Tag`, `/config.js` panelu, discovery obu realmów, brak użytkowników demo
  i rejestracji w realmie `shop`, zdjęcie przez imgproxy, potwierdzenie zamówienia w Mailpicie, idempotencję (upgrade
  z nową rewizją: `initialize` i `keycloak-config` nie zmieniają danych ani realmów). Wypisuje szczytowe zużycie pamięci i dysku. Na koniec usuwa
  klaster (także po błędzie).
- CI (`deploy.yml`): `smoke` na `ubuntu-latest` dla PR zmieniających `infra/**`, `apps/*/Dockerfile`,
  `apps/api/config/**`, `apps/api/bin/**`, `apps/api/composer.lock`, `apps/store/package-lock.json`,
  `apps/admin/package-lock.json`, `apps/store/next.config.ts` oraz sam workflow i `deploy-ref.sh` (obrazy z cache GHA)
  i na `workflow_dispatch` (bez przesuwania `deploy`), oraz zawsze na `main` przed wdrożeniem. `workflow_dispatch`
  GitHub oferuje dopiero, gdy `deploy.yml` jest na gałęzi domyślnej (`main`); przed merge'em epiku próba na gałęzi
  epiku odbywa się przez zdarzenie `pull_request` (PR zmienia `deploy.yml`, który jest w filtrze ścieżek).
  `smoke` nie jest wymaganym checkiem `main`.

## Scenariusze błędów

| Sytuacja | Zachowanie |
|---|---|
| Pierwsza synchronizacja na pustym klastrze | fale i initContainery; `initialize` wczytuje katalog; bez ręcznych kroków |
| Kolejne wdrożenie | `initialize` widzi dane i nic nie zmienia; `keycloak-config` dodaje tylko brakujące zasoby realmów |
| Pusty katalog, ale są zamówienia | `initialize` kończy się błędem stanu, dane nietknięte, synchronizacja czerwona |
| Nieudany build albo smoke test na `main` | `deploy` bez zmian, działa poprzednia wersja, workflow czerwony |
| Nieudana migracja | Job `migrate` pada, nowe `api` i `worker` nie są gotowe, stare działają, aplikacja w Argo `Degraded` |
| Nieudany `purge-caches` | nowa wersja działa, synchronizacja czerwona; `rerun` albo kolejna rewizja |
| Brak wymaganej wartości (np. sekret SMTP przy `orderMail: brevo`) | render chartu kończy się błędem z nazwą wartości; synchronizacja nie startuje, działa poprzednia wersja |
| Żądanie HTTP(S) na IP serwera spoza Cloudflare | 403 z `ipAllowList` |
| Push do `deploy` spoza workflowu, force push, usunięcie | odrzucone przez ruleset |
| Restart serwera | k3s i usługi wstają same; dane na PVC zostają |
| Utrata klucza Sealed Secrets | nowy certyfikat w repo, nowe sekrety skryptem (runbook); wymaga ponownego wczytania danych albo odtworzenia z kopii (DEPLOY-OPS) |

## Zmiany dokumentacji (zadania z implementacją)

- `docs/vision.md`: sekcja „Po MVP: publiczne środowisko demo” zastąpiona opisem środowiska wdrożeniowego (jeden
  serwer i pipeline, gałąź `deploy`, przełączniki startowe, Brevo); kopie i monitoring (DEPLOY-OPS), tryb
  publicznego demo i Sentry (także Sentry Performance z sekcji „Obserwowalność”) jako kolejne kroki; pozycja
  „k3s + Argo CD…” znika z listy „Do opisania”.
- `docs/adr/k3s-argo-cd.md`: k3s na jednym węźle, Argo CD z gałęzią `deploy` (fast-forward, tag z rewizji), własny
  chart, kolejność przez initContainery i fale, realmy addytywnie, Sealed Secrets, Cloudflare (Origin CA, Access,
  `ipAllowList`).
- `CLAUDE.md`: `infra/k8s` w „Strukturze”; cele `make -C infra/k8s …`; zasady zgodności chartu z compose i realmami
  (nowy sekret szyfrowany `seal-secrets.sh`); gałąź i ruleset `deploy`; agent wykonuje operacje na serwerze tylko
  celami Makefile, a kroki „zgoda użytkownika” i „użytkownik” z runbooka nie są jego decyzją.
- `apps/api/CLAUDE.md`: etapy obrazu, `bin/seed-demo`, migracje i wiadomości zgodne wstecz.
- `apps/store/CLAUDE.md`: obraz produkcyjny, `/api/site` i przełączniki; `apps/admin/CLAUDE.md`: obraz produkcyjny,
  `/config.js`.

## Kryteria akceptacji

1. `make -C infra/k8s check` przechodzi i jest częścią `make check`; wprowadzony błąd (zły szablon, klucz
   z `init-env.sh` poza szablonem sekretu, niepoprawny manifest, ingress `auth` z realmem `master`, Job w fali
   wcześniejszej niż jego zależność) kończy go błędem.
2. `make -C infra/k8s smoke` przechodzi lokalnie i na `ubuntu-latest` z zapisanym szczytowym zużyciem pamięci i dysku;
   zepsuta migracja albo zepsuty obraz kończą go błędem, a klaster zostaje usunięty.
3. Obrazy produkcyjne budują się bez działającego stosu; obraz `api` nie ma SPX, Composera, gita ani zależności `dev`.
4. Lokalny `make up`, `make seed`, `make check`, `make test` i `make e2e` (także `STORE_MODE=production`) działają jak
   przed epikiem.
5. Przełączniki sklepu: testy komponentów i `/api/site` (z flagami, bez nich, błąd odczytu) i smoke test na obrazie
   zbudowanym bez flag.
6. `deploy.yml` przechodzi `actionlint`; uruchomiony na gałęzi epiku (zdarzenie `pull_request`, a po merge'u do `main`
   także `workflow_dispatch`) buduje obrazy i przechodzi smoke test, a bez sekretu deploy keya kończy się bez
   przesuwania `deploy`.
7. `initialize` i `keycloak-config` są idempotentne (smoke: upgrade z nową rewizją bez zmian danych i realmów; realm
   bez użytkowników demo); `initialize` przy mieszanym stanie kończy się błędem bez zmian (test w smoke).
8. `seal-secrets.sh` na ścieżce błędu nie zapisuje plików, a ponowne uruchomienie nie zmienia istniejących sekretów.
9. `bootstrap` przechodzi `shellcheck`, uruchomiony dwa razy w kontenerze z Ubuntu 24.04 (bez k3s) nie psuje
   konfiguracji; cele z potwierdzeniem bez `tak` niczego nie zmieniają.
10. Runbook prowadzi od pustego serwera do działającego środowiska; przejście go na serwerze (z próbami rulesetu,
    Access, poczty i `ipAllowList`) robi się po epiku, poza AO, z dostępami od użytkownika.

## Ryzyka

- **Rozjazd compose, realmów i chartu.** Pilnują go zasady w `CLAUDE.md`, test sekretów w wymaganym `check` i smoke
  test; nie wyłapią nowej zmiennej z wartością domyślną ani zmiany realmu, którą `partialImport` pomija (zmiana
  istniejącego klienta – runbook: ręczna zmiana przez `kcadm` albo osobny krok w Jobie).
- **Argo bez Argo w smoke teście.** Fale sprawdza test renderu w `check`, a synchronizację Argo dopiero serwer; ryzyko
  ogranicza D8.
- **Epiki równoległe.** PAYMENT zmienia `infra/docker-compose.yml`, `init-env.sh`, `Makefile` i transporty workera;
  DEPLOY startuje w AO po zmergowaniu PAYMENT do `main`.
- **Zasoby runnera i maszyny AO.** Cały stos w k3d na `ubuntu-latest` jest blisko limitów (16 GB, 14 GB dysku);
  profil `ci` i pomiar w kryterium 2 to sprawdzają.
- **Publiczne API panelu i checkout.** `api.<domena>/api/v1/admin` chroni token Keycloaka i role (jak dziś). Publiczny
  checkout gościa bez limitów pozwala blokować stany fikcyjnymi zamówieniami; rate limiting jest w osobnym
  zgłoszeniu.
- **Klucz Sealed Secrets** jest jedyną rzeczą, której nie da się odtworzyć z repo; trzyma go użytkownik.
- **Brak kopii poza serwerem do czasu DEPLOY-OPS.** Dane da się odtworzyć z seedów, a backup VPS-a od OVH działa od
  pierwszego dnia; prawdziwe dane i publiczny start dopiero po DEPLOY-OPS.
