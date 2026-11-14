# DEPLOY: środowisko wdrożeniowe na k3s

Pierwsze wdrożenie sklepu i panelu poza komputerem dewelopera: jeden serwer, jeden klaster k3s, jeden pipeline,
GitOps przez Argo CD. Na start działa jako demonstracja (sklep publiczny z informacją o symulowanych płatnościach,
`noindex`, panel za Cloudflare Access, rejestracja wyłączona, potwierdzenia zamówień nie wychodzą na zewnątrz). Te
ograniczenia są wartościami konfiguracji, więc przejście na publiczny sklep nie wymaga przebudowy obrazów ani
architektury. Nocne kopie zapasowe, ich weryfikacja i odtwarzanie (`make -C infra/k8s restore`) są częścią chartu (sekcja
„Kopie zapasowe”); logi i metryki klastra trafiają do Grafana Cloud przez Grafana Alloy (aplikacja Argo CD `monitoring`,
`docs/features/deploy-ops/spec.md`, O5 i O7); alerty (nieudana albo brakująca kopia i weryfikacja, zajęty dysk węzła, brak
sygnału z klastra, niegotowy workload) to `PrometheusRule` w chartcie, synchronizowany do Grafana Cloud
(`docs/features/deploy-ops/spec.md`, O6). Prawdziwe dane i publiczny start wymagają jeszcze ćwiczenia odtworzenia z
runbooka na serwerze (zgoda użytkownika).

Issues: #175
Zakres: `infra/k8s`, `infra/elasticsearch`, `.github/workflows/deploy.yml`, `.github/workflows/ci.yml`, `.github/scripts`, `.gitignore`, `apps/api/Dockerfile`, `apps/api/docker`, `apps/api/.dockerignore`, `apps/api/bin`, `apps/store/Dockerfile`, `apps/store/.dockerignore`, `apps/store/next.config.ts`, `apps/store/app/layout.tsx`, `apps/store/app/api/site`, `apps/store/src/api/client.ts`, `apps/store/src/components`, `apps/store/src/account`, `apps/admin/Dockerfile`, `apps/admin/.dockerignore`, `apps/admin/docker`, `apps/admin/index.html`, `apps/admin/public`, `apps/admin/src/auth`, `apps/admin/src/api/client.ts`, `apps/admin/src/config`, `Makefile`, `CLAUDE.md`, `apps/api/CLAUDE.md`, `apps/store/CLAUDE.md`, `apps/admin/CLAUDE.md`, `docs/vision.md`, `docs/adr`, `tools/rulesets`

## Cel i zakres

- Produkcyjne obrazy `api` (także dla workera, Jobów i CronJobów), `store`, `admin` i `elasticsearch`, budowane
  w GitHub Actions z commita `main` przy wdrożeniu na życzenie użytkownika i wypychane do GHCR.
- Helm chart całego stosu w `infra/k8s/`, wdrażany przez Argo CD z gałęzi `deploy-images` (snapshoty gałęzi `deploy`
  z plikiem digestów obrazów).
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

- Alerty z treści logów i automatyczne wyciszanie alertów. Tu nocna kopia (restic), jej weryfikacja, odtwarzanie, logi,
  metryki i alerty w Grafana Cloud (Alloy, `PrometheusRule`) i włączenie backupu VPS-a od OVH (runbook).
- Osobne środowiska demo i produkcji, wiele węzłów, autoskalowanie, Rancher, własny Prometheus i Loki.
- Tryb publicznego demo (nocny reset danych, publiczne konta demo, limity uploadów) – #179.
- Sentry (SDK w api, store i admin) oraz trace'y i Sentry Performance – #180.
- Włączenie publicznej rejestracji i wysyłki zamówień przez Brevo: wymaga polityki prywatności i zabezpieczeń przed
  nadużyciami (#181); środowisko ma przełączniki i działającą weryfikację e-mail.
- Testy obciążeniowe.
- Zakup serwera, domeny, założenie kont zewnętrznych (Cloudflare, Brevo), ruleset
  i deploy key na GitHubie oraz samo uruchomienie na serwerze – według runbooka (agent prowadzi kroki oznaczone
  „agent”). Repo, jego testy i CI nie wymagają serwera ani zmian w ustawieniach repo.
- Sprawdzenie nazwy sklepu w bazach znaków towarowych (UPRP, EUIPO).
- Zmiany środowiska lokalnego poza wspólnym skryptem seedów i przełącznikami sklepu (ich wartości domyślne dają zachowanie lokalne).

## Decyzje

- **D1. k3s na jednym węźle, Traefik z k3s jako ingress, `local-path` jako storage.**
- **D2. Własny chart zamiast gotowych chartów usług.** Jeden chart `infra/k8s/charts/shop` z szablonami dla każdej
  usługi compose, na tych samych obrazach i wersjach co `infra/docker-compose.yml`. Elasticsearch działa na obrazie
  budowanym z tego samego Dockerfile co w compose (`infra/elasticsearch/Dockerfile`). Gotowe charty tylko dla
  platformy: Argo CD, Sealed Secrets, `k8s-monitoring` (Grafana Alloy) i `prometheus-operator-crds` (CRD, m.in.
  `PrometheusRule`), każdy przypięty wersją jako osobna aplikacja Argo CD.
- **D3. Nazwy usług w klastrze takie jak w compose**; wewnętrzne adresy i VCL (`api:80`) bez zmian.
- **D4. Gałęzie `deploy` i `deploy-images`, przesuwane jednym atomowym pushem, tylko na życzenie użytkownika.** Wdrożenie
  nie startuje po merge'u ani pushu do `main`: uruchamia je użytkownik albo agent na jego wyraźne polecenie
  (`gh workflow run deploy.yml --ref main`, zdarzenie `workflow_dispatch` na `main`). `deploy` przesuwa się wyłącznie
  fast-forwardem na zatwierdzony commit z `main` (historia `main` jest liniowa, więc `deploy` jest zawsze jej przodkiem).
  `deploy-images` to kolejne snapshoty: każdy commit ma drzewo commita z `deploy` plus wygenerowany plik
  `infra/k8s/environments/prod/values-images.yaml` (`image.tag: <sha>` i `image.digests.{api,store,admin,elasticsearch}`)
  i dwóch rodziców: poprzedni `deploy-images` (gdy istnieje) i commit z `deploy`. Commit tworzy CI przez `git commit-tree`
  (tymczasowy indeks, working tree nietknięty); bez merge'a i bez force-pusha, a komunikat ma SHA źródłowego commita
  i cztery digesty. Ponowny run tego samego commitu z identycznym drzewem nie tworzy nowego snapshotu. Argo CD śledzi
  tylko `deploy-images`: aplikacja `shop` ma jedno źródło (repo, `targetRevision: deploy-images`, ścieżka
  `infra/k8s/charts/shop`, `helm.valueFiles` `../../environments/prod/values.yaml` i
  `../../environments/prod/values-images.yaml`, bez `ignoreMissingValueFiles`), bez wielu źródeł i bez parametru
  `$ARGOCD_APP_REVISION` (rewizja `deploy-images` nie jest tagiem obrazu; tag daje plik). Pozostałe aplikacje (`root`,
  sekrety) też czytają `deploy-images`, więc całe wdrożenie ma jedną rewizję. Obrazy są wdrażane po digeście
  (`image.digests`, `image.requireDigests: true`), bez `latest`, bez fallbacku do tagów, bez Image Updatera. Workflow po
  zbudowaniu obrazów z tagiem SHA raz generuje plik (`infra/k8s/scripts/images-values.sh`), smoke test instaluje chart
  dokładnie z tym plikiem, a po zielonym smoke teście i sprawdzeniu renderu `prod` z tym plikiem
  (`infra/k8s/scripts/verify-images.sh`) przesuwa obie gałęzie jednym `git push --atomic` (`.github/scripts/deploy-ref.sh`,
  deploy key z sekretu repo `DEPLOY_KEY`, bez `--force`): błąd walidacji albo pusha nie publikuje niczego, a przy
  odrzuconym non-fast-forward żadna gałąź się nie przesuwa. Bypass rulesetu dotyczy wszystkich jego reguł, więc obie
  gałęzie chronią dwa rulesety na `refs/heads/deploy` i `refs/heads/deploy-images`: `deploy` (`tools/rulesets/deploy.json`:
  `deletion` i `non_fast_forward`, bez żadnego aktora bypass) zabrania usunięcia i force pusha wszystkim, a `deploy-push`
  (`tools/rulesets/deploy-push.json`: reguły `creation` i `update`) dopuszcza utworzenie gałęzi i zwykły push tylko dla
  deploy keya (jedyny aktor bypass, typ `DeployKey`, tryb `always`); bez wymogu PR. Aktor `DeployKey` obejmuje każdy
  deploy key repo z prawem zapisu, więc repo ma mieć tylko jeden taki klucz (warunek dla runbooka wdrożenia). Założenie
  rulesetów i deploy keya oraz próby (push innym poświadczeniem i usunięcie odrzucone na obu gałęziach, push deploy
  keyem przyjęty) to krok runbooka po merge'u, z zapisem wyników. Obrazy z digestami, do których odwołuje się
  `deploy-images`, nie mogą być usuwane z GHCR (rollback to powrót do starszego snapshotu): żadna retencja pakietów ani
  workflow nie usuwa ich wersji (test w `check`).
- **D5. Sealed Secrets.** Publiczny certyfikat kontrolera trafia do repo przy bootstrapie, więc kolejne sekrety
  zaszyfruje każdy (także agent) bez dostępu do klastra. Kopia klucza prywatnego kontrolera jest u użytkownika.
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
  i `webOrigins` z domeny, rejestrację, `verifyEmail`, SMTP. Nie usuwa użytkowników, haseł ani zasobów. Pliki
  realmów w repo (publicznym) mają konta demo z hasłami i sekretami TOTP, więc w klastrze nie trafiają do realmów.
  Lokalny `--import-realm` z kontami demo działa jak w compose.
- **D10. Ograniczenia startowe są wartościami chartu** (`environments/prod/values.yaml`): `demoNotice`, `noindex`,
  `customerAccounts` (rejestracja w realmie `shop` i link logowania w sklepie), `orderMail` (`brevo` albo `mailpit`).
  Zmiana to commit z wartością, który przechodzi zwykły pipeline (build z cache, smoke, a na życzenie wdrożenie); kod i obrazy się
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
| `api.<domena>` | api | publiczny; API panelu (token realmu `backoffice` i role) i strona płatności symulatora; sklep i webhooki symulatora wołają API wewnątrz klastra |
| `auth.<domena>` | keycloak | tylko `/realms/shop/`, `/realms/backoffice/` i `/resources/`; realm `master` i konsola administracyjna niedostępne z zewnątrz; bez Access (panel wymienia kod na token zapytaniem z innego originu), realm `backoffice` chronią hasło, TOTP i blokada brute force |
| `img.<domena>` | imgproxy | publiczny; reguła cache Cloudflare dla całego hosta |

Argo CD, RabbitMQ, Mailpit, Garage, Elasticsearch i Postgres nie mają publicznego adresu; dostęp przez
`kubectl port-forward` po SSH.

## Obrazy produkcyjne

Cztery obrazy `ghcr.io/piotrp321/shop/<app>:<sha commita>` (`api`, `store`, `admin`, `elasticsearch`), publiczne
(runbook ustawia widoczność pakietów; k3s pobiera je bez `imagePullSecret`).

- **api:** etapy: `runtime` (FrankenPHP, rozszerzenia, konfiguracja PHP i Caddy), `base` z niego (Composer, git,
  unzip, entrypoint z `composer install`, jak w compose), `build` z `base` (`composer install --no-dev
  --classmap-authoritative`, rozgrzany cache `prod`), `prod` z `runtime` (kod i `vendor` z `build`, produkcyjne opcache,
  bez entrypointu instalującego zależności, bez Composera, git i SPX), `dev` z `base` jako ostatni (używa go compose).
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
  `image.digests.<app>` jest podana, inaczej `<rejestr>/<app>:<sha>`; w `prod` tag i digesty daje wyłącznie plik
  `values-images.yaml` snapshotu (D4). `image.requireDigests: true` (profil `prod`)
  wymaga wszystkich czterech digestów (`sha256:` i 64 znaki hex); podany digest zawsze musi mieć ten format,
  a `image.tag` nie może być `latest` (schemat wartości, błąd renderu z nazwą wartości). Profil `ci` digestów nie
  wymaga (smoke test importuje obrazy lokalne po tagu).
- Obrazy usług bez własnego kodu (`images.*` w wartościach chartu) mają postać `nazwa:tag@sha256:<digest indeksu
  wieloarchitekturowego>`, z tagiem takim jak w `infra/docker-compose.yml`; compose zostaje przy samych tagach.
- Kontenery przy starcie niczego nie pobierają (wtyczek ani innych artefaktów); klaster pobiera tylko obrazy
  o przypiętych digestach.
- Compose buduje `api` i `worker` z `target: dev`, a `store` i `admin` bez `target` (ostatni etap, `dev`), więc
  lokalny `make up` nie zależy od etapów `prod`.
- Usługi bez własnego kodu używają obrazów i wersji z `infra/docker-compose.yml`. `garage-init` w klastrze: kontener
  Alpine uruchamia `infra/garage/init.sh` z binarką Garage zamontowaną z jej obrazu wolumenem typu `image` (bez kopii
  w `emptyDir`). Wolumen `image` (ImageVolume) jest alfa w Kubernetes 1.31–1.32, betą domyślnie wyłączoną w 1.33–1.34,
  betą domyślnie włączoną w 1.35 i stabilny od 1.36; wymaga też runtime'u z obsługą wolumenów obrazów (containerd 2.1
  lub nowszy). Wymaganie: k3s/Kubernetes ≥ 1.36. Pilnuje go `kubeVersion: ">=1.36.0-0"` w `Chart.yaml` (Helm, a przez
  niego Argo CD, odmawia renderu dla starszego klastra). Smoke test i bootstrap serwera używają tego samego k3s
  `v1.36.6-k3s1` (`infra/k8s/scripts/tools.sh`); bootstrap odmawia wersji starszej niż v1.36. Konfiguracja z repo
  (VCL, `garage.toml`, init Postgresa, `init.sh`, realmy) trafia do ConfigMap przez symlinki w
  `infra/k8s/charts/shop/files/`, które Helm i Argo CD czytają wewnątrz repo; test w `check` pilnuje, że symlinki
  nie wychodzą poza repo, a ConfigMap mają treść oryginałów. Skrypty Jobów (`initialize.sh`, `keycloak-config.sh`) są
  zwykłymi plikami w `files/scripts/`.

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
  | −2 | Secret, ConfigMap, middleware Traefika, ServiceAccount `shop-wait` z Role i RoleBinding | – |
  | −1 | StatefulSety, Keycloak, Mailpit | Keycloak: Postgres |
  | 0 | Joby `garage-init`, `migrate` (`doctrine:migrations:migrate --no-interaction`, `catalog:search-index:setup`), `keycloak-config` | Garage; Postgres i Elasticsearch; gotowy Keycloak |
  | 1 | Deploymenty api, worker, store, admin, imgproxy, varnish; Ingress; CronJob | api i worker: `doctrine:migrations:up-to-date` (api i worker także RabbitMQ); store: Redis; imgproxy: Garage z bucketami |
  | 2 | Job `initialize` (dane startowe) | zakończony rollout Deploymentów api, varnish, store, worker |
  | 3 | Job `purge-caches` (`storefront:cache:purge-all`) | zakończony rollout Deploymentów api, varnish, store |

  Nieudana migracja: nowe `api` i `worker` nigdy nie są gotowe, stare działają (`maxUnavailable: 0`).
- `initialize` i `purge-caches` czekają na rollout (`kubectl rollout status`) w initContainerach z obrazem `kubectl`,
  a nie na odpowiedź Service, którą mógłby jeszcze dać stary pod. Te Joby działają na ServiceAccount `shop-wait`
  (Role: tylko `get`, `list`, `watch` na `deployments`), a token API Kubernetes (wolumen `projected`) montują tylko te
  initContainery. Pozostałe pody i kontenery nie montują tokenu (`automountServiceAccountToken: false`). Deploymenty
  mają `progressDeadlineSeconds` nie krótszy niż czas oczekiwania Jobów (1500 s).
- Middleware Traefika: `ipAllowList` (D6) na wszystkich ingressach; `X-Robots-Tag: noindex` na wszystkich hostach,
  gdy `noindex: true`; `hsts` (`Strict-Transport-Security: max-age=31536000`, bez `includeSubDomains` i `preload`)
  na wszystkich hostach, tylko gdy `ingress.tls` (`prod`; profil `ci` jest bez TLS i bez HSTS). Pozostałe nagłówki
  bezpieczeństwa wysyłają aplikacje (`docs/features/hardening/spec.md`, D10).
- `clientIpHeader` (domyślnie puste) trafia jako `CLIENT_IP_HEADER` do `api` i `store`: nagłówek z adresem klienta,
  po którym liczą się limity API (`docs/features/hardening/spec.md`, D2 i D3). `prod` ma `CF-Connecting-IP`, który
  ustawia Cloudflare (żądanie z nagłówkiem podanym przez klienta Cloudflare odrzuca, błąd 1000), a `ipAllowList` nie wpuszcza nikogo poza Cloudflare na żaden host (także `api.`
  i `auth.`). `clientIpHeader` przy `ipAllowList.enabled: false` kończy render błędem, chyba że profil potwierdza to
  wartością `clientIpHeaderWithoutAllowList: true` (tylko pomiar na lokalnym klastrze); to potwierdzenie razem
  z `image.requireDigests: true` (obrazy wydania) też kończy render błędem.
- CronJob `cart:purge-expired` (co godzinę, `concurrencyPolicy: Forbid`, strefa `Europe/Warsaw`).
- `rabbitmq-init` (vhost testów) nie istnieje.
- Profile wartości:
  - `values.yaml` – wspólne; wymagane wartości walidowane schematem (`values.schema.json`), więc brak np. sekretu SMTP
    przy `orderMail: brevo` kończy render (i synchronizację Argo) błędem z nazwą wartości;
  - `environments/prod/values.yaml` – domena, przełączniki D10, `clientIpHeader: CF-Connecting-IP`, zasoby na serwer
    docelowy (8 vCore, 24 GB RAM): suma requestów CPU do 7 vCPU (limity CPU mogą je przekraczać), suma limitów pamięci
    do 20 GB;
  - `environments/ci/values.yaml` – odchudzony profil smoke testu: hosty `*.localhost`, zwykłe sekrety, bez
    `ipAllowList` i bez `clientIpHeader`, minimalne requesty, sterta ES 512 MB, mniejsza sterta Keycloaka, poczta do Mailpita; mieści się na `ubuntu-latest` (16 GB RAM, 4 vCPU, 14 GB SSD).

## Wdrożenie nowej wersji

1. Merge PR do `main` niczego nie wdraża. Wdrożenie uruchamia na życzenie użytkownika
   `gh workflow run deploy.yml --ref main` (zdarzenie `workflow_dispatch` na `main`: `.github/workflows/deploy.yml`
   wdraża wtedy bieżący commit `main`). Grupa `concurrency` bez anulowania trwającego runu `main`: działa jeden, czeka
   najwyżej jeden, pośrednie są pomijane, więc wdraża się najnowszy commit.
2. Workflow buduje i wypycha obrazy `api`, `store`, `admin`, `elasticsearch` z tagiem SHA; wdrażane są po digeście
   (D4). Digesty czterech obrazów są w podsumowaniu runu i w outputach joba `images`, który raz generuje z nich plik
   `values-images.yaml` (output wieloliniowy z sumą kontrolną; kolejne joby zapisują te same bajty i sprawdzają sumę).
   Obraz, który już ma ten tag (ponowny run tego samego commitu), zostaje bez przebudowy, więc jeden tag nie dostaje
   drugiego digestu.
3. Smoke test na k3d (niżej) instaluje chart z tym plikiem: klaster pobiera obrazy z GHCR po digeście i test sprawdza,
   że pody api, store, admin i elasticsearch działają na tych digestach.
4. Po zielonym smoke teście workflow sprawdza render `prod` z tym plikiem (każdy obraz własny to dokładnie
   `ghcr.io/piotrp321/shop/<app>:<sha>@<digest>` z pliku) i jednym atomowym pushem przesuwa `deploy` fast-forwardem na ten
   commit oraz dodaje snapshot do `deploy-images` (D4). Bez sekretu z deploy keyem (przed krokiem runbooka) workflow
   kończy się po smoke teście z ostrzeżeniem, bez błędu.
5. Argo CD synchronizuje `root`, a przez niego `prometheus-crds`, `sealed-secrets`, `secrets`, `shop` i `monitoring` (fale
   −3, −2, −1, 0 i 1) z `deploy-images` (charty `sealed-secrets`, `prometheus-crds` i `monitoring` z ich repozytoriów Helm, w
   przypiętych wersjach); chart `shop` rozkłada zasoby w falach. `monitoring` jest ostatni: brak konta Grafana Cloud albo
   zły token zostawia `Degraded` tylko tę aplikację, a sklep działa i się wdraża.
6. Wycofanie wersji: revert na `main` i nowe uruchomienie wdrożenia. Awaryjnie można zsynchronizować `shop` z poprzednim snapshotem
   `deploy-images` (jego obrazy zostają w GHCR), po wyłączeniu automatycznej synchronizacji `root` (inaczej przywraca ona
   `syncPolicy` aplikacji `shop`), a potem `shop`; po revercie automatyczną synchronizację włącza się z powrotem, najpierw
   `root`. Schemat bazy jest zgodny wstecz tylko z poprzednią wersją; cofnięcie o więcej wersji to starszy snapshot
   `deploy-images` i odtworzenie kopii z czasu tej wersji (`make -C infra/k8s restore`, zgoda użytkownika; kopii nowszej
   niż wdrożony kod odtworzenie odmawia) albo pełne ponowne wczytanie danych (`reinitialize`, zgoda użytkownika).

Zasady dla zmian w repo (dokumentacja w `apps/api/CLAUDE.md` i `CLAUDE.md`): migracje zgodne wstecz z poprzednią
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
- Wyłączany wartością `initialize.enabled: false`. Odtworzenie kopii go nie potrzebuje: na nowym klastrze `initialize`
  wczytuje dane demo, a `make -C infra/k8s restore` zastępuje je danymi snapshotu.
- Inicjalizacja przerwana w połowie: kolejne uruchomienie kończy się błędem stanu mieszanego; runbook opisuje ponowne
  wymuszone wczytanie (`make -C infra/k8s reinitialize`), które kasuje dane i wymaga zgody użytkownika.

## Keycloak i konta

- Realmy bez użytkowników demo, aktualizowane addytywnie (D9).
- Pierwszy administrator: `make -C infra/k8s create-admin LOGIN=… EMAIL=…` (po zgodzie użytkownika; może go uruchomić
  agent). Zakłada użytkownika w realmie `backoffice` z rolą `admin` bez hasła i wysyła mu przez SMTP Brevo e-mail
  z akcjami „ustaw hasło” i „skonfiguruj TOTP” (`execute-actions-email`, link ważny 12 h). Hasło i TOTP ustawia
  administrator w przeglądarce, więc nie trafiają do repo, skryptów, logów ani kontekstu agenta. Istniejący login albo
  e-mail: błąd (kod 3) bez zmian; błąd po założeniu użytkownika usuwa go, więc polecenie można powtórzyć.
- Konta klientów: `customerAccounts: false` w `prod` wyłącza rejestrację w realmie `shop` i ukrywa link „Zaloguj się”
  w sklepie (przełącznik z `/api/site`). `verifyEmail` włączone; e-mail weryfikacyjny i reset hasła idą przez Brevo.
- Ochrona przed zgadywaniem haseł: oba realmy mają `bruteForceProtected: true` z wartościami domyślnymi Keycloaka
  (blokada konta po 30 nieudanych próbach, czas blokady rosnący do 15 min); wdrożenie ich nie zmienia
  (`docs/features/hardening/spec.md`, D17).

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
  `demoNotice: false`, `customerAccounts: true`, czyli zachowanie lokalne.
- Komponent klienta w layoutcie pokazuje nad nagłówkiem pasek: „Sklep demonstracyjny: płatności i zamówienia są
  symulowane, nic nie zostanie wysłane ani pobrane.”, gdy `demoNotice`. `AccountMenu` nie pokazuje „Zaloguj się”, gdy
  `!customerAccounts`. Błąd odczytu `/api/site`: bez paska i z linkiem (stan domyślny).
- Testy komponentów (z flagą, bez niej, błąd odczytu); smoke test sprawdza pasek i brak linku na `/` i `/cart`
  w przeglądarce, w obrazie zbudowanym bez flag.

## Kopie zapasowe

Specyfikacja: `docs/features/deploy-ops/spec.md` (O1, O2, O3, O4); decyzja: `docs/adr/backups-observability.md`.

- CronJob `backup` (wartości `backup.*`, `enabled: true` w `prod` i `ci`) robi codziennie o 03:00 (`Europe/Warsaw`) jedną
  kopię: `pg_dump` baz `shop` i `keycloak` (format custom, `--no-owner --no-privileges`, użytkownik `postgres`), lustro
  bucketu `product-images` z Garage (`rclone sync`, klucz S3 sklepu) i `manifest.json` (rewizja, czas, identyfikator
  klastra, wersje migracji Doctrine z bazy `shop`, liczba i rozmiar obiektów). Całość trafia jako jeden snapshot (tag
  `nightly`, host równy identyfikatorowi klastra) do zaszyfrowanego repozytorium restic w S3 (Cloudflare R2 w `prod`);
  repozytorium zakłada się samo przy pierwszej kopii, a `restic forget --keep-within 7d --prune` po kopii `nightly` przycina tylko
  snapshoty tego klastra (nowy serwer po awarii nie usuwa kopii starego).
- Pod ma kontenery po kolei na PVC `backup-work` (`storage.backupWork`, 25 Gi: lustro obiektów i cache restic): `cluster-id`
  (UID przestrzeni `kube-system`; jedyny z tokenem API, ClusterRole z samym `get` na `namespaces/kube-system`, bez
  sekretu), `dump`, `objects` i `backup` (restic; jedyny z sekretem `shop-backup`, bez tokenu). Błąd dowolnego kroku
  przed `restic backup` kończy Job i nie zostawia snapshotu. `concurrencyPolicy: Forbid`, `startingDeadlineSeconds: 3600`,
  `backoffLimit: 1`, `activeDeadlineSeconds: 7200`.
- Repozytorium i region to wartości (`backup.repository`, `backup.s3Region`); `backup.enabled` bez repozytorium albo
  `backup.secretName` kończy render błędem. Sekret `shop-backup` (`RESTIC_PASSWORD`, `BACKUP_S3_ACCESS_KEY_ID`,
  `BACKUP_S3_SECRET_ACCESS_KEY`) tworzy w `prod` jednorazowo `make -C infra/k8s seal-secrets WHAT=backup`; w profilu `ci`
  chart renderuje go z `backup.secretValues`. Hasło restic użytkownik trzyma też poza serwerem: bez niego kopie są nie do
  odczytania, także po utracie klucza Sealed Secrets.
- CronJob `backup-verify` (`backup.verifySchedule`, codziennie o 05:00 `Europe/Warsaw`; te same limity czasu i historii co
  `backup`; konto usługi i rola kopii) czyta repozytorium w S3 i nie ma dostępu do produkcyjnej bazy. Sprawdza: wiek
  najnowszego snapshotu `nightly` tego klastra (poniżej 26 h), `restic check` z odczytem podzbioru danych
  (`--read-data-subset=n/7`, n to dzień tygodnia; `backup.verifyReadData: all`, profil `ci`, odczytuje wszystko),
  odtworzenie obu dumpów (`restic dump` strumieniowo do `pg_restore` w tymczasowym Postgresie poda na `emptyDir`; niepusta
  tabela migracji `shop`, realmy `shop` i `backoffice` w `keycloak`) i zgodność liczby obiektów snapshotu (`restic ls`)
  z `manifest.json`. Każdy niespełniony warunek, także błąd narzędzia, kończy Job błędem z opisem w logu. Pod: `cluster-id`
  (jak w kopii), `postgres` (natywny sidecar, tylko pętla zwrotna, bez sekretów) i `verify` (jedyny z sekretem
  `shop-backup`, bez tokenu; `restic` z obrazu `restic/restic` zamontowanego jako wolumen obrazu, razem z jego certyfikatami CA w `SSL_CERT_FILE`:
  obraz `postgres` ich nie ma, a repozytorium w `prod` jest po HTTPS). Dane tymczasowego Postgresa leżą na `emptyDir` z limitem
  równym `storage.postgres`: pod, którego odtworzone bazy go przerosną, jest wyrzucany, a nie zapełnia dysku węzła. Brak weryfikacji albo jej
  błąd to alerty `BackupVerifyMissing` i `BackupVerifyFailed` (`docs/features/deploy-ops/spec.md`, O6).
- Odtworzenie: `make -C infra/k8s restore SNAPSHOT=<id> [PRE_RESTORE=skip]` (zgoda użytkownika) tworzy Joby z wstrzymanego
  CronJoba `restore` (`suspend: true`, `activeDeadlineSeconds: 10800`, `backoffLimit: 0`; obrazy i sekrety wdrożonej
  rewizji, bez tokenu API, sekret `shop-backup` tylko w kontenerze restic) i z Jobów wersji. Odmawia bez zmian: bez
  `SNAPSHOT` (identyfikator, nigdy `latest`), przy aktywnym Jobie w przestrzeni `shop`, trwającej synchronizacji `shop` w
  Argo CD i snapshocie z migracją spoza klas migracji obrazu `api` (Job w trybie `check`, przed pytaniem). Po słowie `tak`:
  kopia `pre-restore` (Job z CronJoba `backup` z `BACKUP_TAG=pre-restore`; jej błąd kończy odtworzenie bez zmian, chyba że
  `PRE_RESTORE=skip`, który ją pomija), przy Argo CD wyłączenie automatycznej synchronizacji `root`, potem `shop`,
  wstrzymanie CronJobów `cart-purge-expired` i `backup`, `api`, `worker`, `store` i `keycloak` do zera, Job w trybie
  `restore` (`restic restore` na `backup-work`, `DROP`/`CREATE` i `pg_restore` obu baz z właścicielami jak w
  `10-create-databases.sh`, `rclone sync` z usuwaniem do `product-images`, `FLUSHALL` Redisa, opróżnienie kolejek
  RabbitMQ), ponowienie `migrate`, `keycloak` i `keycloak-config`, Job w trybie `reindex` (`catalog:reindex`), powrót
  replik i CronJobów, `purge-caches` i włączenie synchronizacji `root`. Skrypt czeka na każdy Job najwyżej jego
  `activeDeadlineSeconds`; błąd po pierwszej zmianie zostawia aplikacje wyłączone i wypisuje krok oraz snapshot
  `pre-restore` (runbook opisuje dokończenie). `make -C infra/k8s backups` wypisuje snapshoty (identyfikator, czas, host,
  tagi, rewizja) Jobem w trybie `list`, tylko do odczytu. Ten sam cel odtwarza dane na nowym serwerze po `bootstrap` z
  kopią klucza Sealed Secrets (snapshoty starego serwera mają inny host).
- Obrazy `restic/restic` i `rclone/rclone` są w `images.*` z tagiem i digestem (bez odpowiednika w compose, który się nie
  zmienia). Smoke test uruchamia kopię i jej weryfikację na osobnej instancji S3 (przestrzeń `smoke-backup`), sprawdza snapshot,
  a potem odtwarza go na pustym klastrze (przestrzeń `shop` usunięta z wolumenami, chart zainstalowany od nowa) i sprawdza
  odmowę snapshotu nowszego niż kod.
- Automatyczny backup VPS-a od OVH (runbook: włączenie) zostaje dodatkową kopią całego serwera. Alerty kopii (`BackupFailed`, `BackupMissing`) są w Grafana Cloud (`docs/features/deploy-ops/spec.md`, O6).

## Sekrety

- Klucze z `infra/scripts/init-env.sh` oraz zewnętrzne: Brevo SMTP, certyfikat i klucz Cloudflare Origin CA. Zaszyfrowane
  sekrety są w `infra/k8s/environments/prod/secrets/` (SealedSecrety dla przestrzeni `shop`): `shop-secrets.yaml` (klucze
  `init-env.sh`), `shop-brevo-smtp.yaml` (`BREVO_SMTP_LOGIN`, `BREVO_SMTP_PASSWORD`), `shop-origin-tls.yaml` (typ
  `kubernetes.io/tls`; `ingress.tlsSecretName` w wartościach `prod` wskazuje go na wszystkich ingressach). Kontroler i
  sekrety stawiają aplikacje Argo `sealed-secrets` i `secrets` w falach przed `shop`. Token Grafana Cloud to
  `grafana-cloud.yaml` (klucz `GRAFANA_CLOUD_TOKEN`), który czyta Alloy (aplikacja `monitoring`) z przestrzeni `shop` przez Role
  tylko na ten sekret.
- `infra/k8s/scripts/seal-secrets.sh` (`make -C infra/k8s seal-secrets`, w kontenerze z `kubeseal` przypiętym wersją i
  digestem) szyfruje certyfikatem z repo (`environments/prod/sealed-secrets.pem`). Klucze z listy `init-env.sh` (czytanej z
  pliku) dopisuje tylko brakujące, z losową wartością; istniejących nie zmienia. Wartości zewnętrzne (login i klucz Brevo,
  certyfikat i klucz Origin CA, hasło restic i klucz S3 kopii, token Grafana Cloud) czyta interaktywnie z terminala (bez echa), nie z argumentów ani zmiennych, i nie
  wypisuje ich; tworzy je raz (gdy plik istnieje, niczego nie pyta); krok runbooka „użytkownik”. Brak certyfikatu, pusta
  wartość, zły format albo nieudany `kubeseal` kończą skrypt błędem bez zapisu (pliki powstają dopiero, gdy wszystko się
  udało), a plik w innym formacie niż zapisuje skrypt nie jest ruszany.
- `check` chartu: każdy klucz z `init-env.sh` jest w szablonie sekretu chartu (zawsze); gdy w repo jest certyfikat,
  także ma zaszyfrowaną wartość w `environments/prod/secrets/`. Zawsze: w tym katalogu są tylko SealedSecrety (jawny
  Secret to błąd), w przestrzeni `shop`, bez jawnych danych.

## Serwer, bootstrap i wdrożenie przez Claude Code

- Cele `infra/k8s/Makefile` działają z komputera użytkownika (narzędzia w kontenerach) i łączą się z serwerem po SSH
  (`HOST=user@adres`):
  - `bootstrap` (`SEALED_KEY_FILE=<kopia klucza>` opcjonalnie): na czystym Ubuntu 26.04 LTS przypięta wersja k3s (≥ v1.36,
    wymaganie chartu; instalator z tagu wydania), `vm.max_map_count=262144`, ufw (SSH i API k3s oraz sieci podów i usług;
    D6), automatyczne poprawki bezpieczeństwa, Traefik z `externalTrafficPolicy: Local` (`HelmChartConfig`), Argo CD
    (przypięty chart instalowany przez kontroler Helma k3s), Sealed Secrets (z kluczem z kopii, jeśli jest, stosowanym
    przed kontrolerem; inaczej zapis certyfikatu do repo), aplikacja `root` (app-of-apps w `infra/k8s/argocd/`).
    Idempotentny; brak uprawnień root albo inny system kończą go błędem przed zmianami; zmiana konfiguracji k3s
    (`tls-san`) przy tej samej wersji restartuje k3s. Kopia klucza to Secret albo `List` Secretów (wynik polecenia
    z runbooka). Certyfikat kontrolera trafia do `environments/prod/sealed-secrets.pem`, gdy repo go nie ma; inny niż w repo
    to błąd zgłaszany przed zastosowaniem `root` (sekrety z repo nie dałyby się odszyfrować), nic nie jest nadpisywane.
    Kontroler nie rotuje klucza (`keyrenewperiod: "0"`), więc certyfikat w repo i kopia klucza zostają aktualne.
    `argocd-cm` ma standardową customizację zdrowia `Application`, więc `root` czeka na aplikacje-dzieci w falach.
  - `kubeconfig`: kubeconfig klastra do katalogu poza repo (`KUBE_DIR`), dla `rerun` i `create-admin`.
  - `status`: stan aplikacji Argo, podów i Jobów (tylko odczyt).
  - `rerun JOB=…`: ponowienie Joba wersji (D11).
  - `create-admin`, `reinitialize` (kasuje dane sklepu i wczytuje dane demo od nowa przez `bin/seed-demo` w podzie api):
    wypisują, co zrobią, i wymagają potwierdzenia słowem `tak`; agent uruchamia je tylko po zgodzie użytkownika w rozmowie.
  - `seal-secrets` (Sekrety) i `cloudflare-ips` (zakresy Cloudflare w `ipAllowList` z opublikowanych list; `CHECK=1`
    tylko raportuje).
- Runbook `infra/k8s/README.md` jest sekwencją tych celów i kroków w Cloudflare, Brevo i GitHubie, oznaczonych:
  „agent”, „zgoda użytkownika” (firewall, operacje kasujące dane, konta), „użytkownik” (zakupy, konta zewnętrzne,
  wartości sekretów, Cloudflare Access, ruleset i deploy key, backup VPS-a w OVH). Zawiera sprawdzenia po uruchomieniu: żądanie na IP
  serwera spoza Cloudflare odrzucone, logowanie do panelu przez Access, e-mail z `create-admin` dotarł, próby
  rulesetów `deploy` i `deploy-push` na obu gałęziach (`deploy`, `deploy-images`).

## Weryfikacja bez serwera

- `make -C infra/k8s check`: `helm lint`, render dla `prod` i `ci` (`prod` z testowym SHA), `kubeconform`,
  `shellcheck` (także `deploy-ref.sh`), `actionlint` workflowów, testy renderu i skryptów: zgodność ConfigMap
  z oryginałami przez symlinki, sekrety (każdy klucz `init-env.sh` w szablonie, SealedSecrety), przełączniki D10,
  utwardzenie (tokeny API tylko w initContainerach `kubectl`, `progressDeadlineSeconds`), zasoby `prod` na serwer,
  workflow `deploy.yml`, generator pliku digestów, skrypty `keycloak-config`, `initialize`, `create-admin`,
  `seal-secrets`, `cloudflare-ips` i skrypty serwera na atrapach, CronJoby, ścieżki ingressu `auth`, middleware (w tym HSTS tylko przy TLS), fale (każdy Job i Deployment ma zależności we
  wcześniejszych falach), nazwy Jobów D11, obrazy (własne z rejestru, z digestem w `prod`; pozostałe z tagiem jak
  w compose i digestem; Elasticsearch z `infra/elasticsearch/Dockerfile`, bez `elasticsearch-plugin` w kontenerach).
  Render (z wersją Kubernetes klastra smoke testu, `--kube-version`), który musi się nie udać: `prod` bez digestów,
  `image.tag=latest`, digest w złym formacie, obraz usługi bez digestu, klaster Kubernetes 1.35 (`kubeVersion`), plik
  `values-images.yaml` bez digestu `elasticsearch`, z digestem będącym tagiem albo z `latest`. Render `prod` z samym
  wygenerowanym plikiem (bez `--set`) ma dokładnie obrazy z pliku. Manifesty Argo CD (`argocd/`) przechodzą
  `kubeconform` (schematy CRD z katalogu CRDs-catalog), a testy sprawdzają: `root` obejmuje `shop`, `secrets`,
  `sealed-secrets`, `prometheus-crds` i `monitoring`, `shop` ma jedno źródło na `deploy-images`, aplikacje z gita czytają tę
  samą gałąź, charty innych (`sealed-secrets`, `prometheus-crds`, `monitoring`) są przypięte wersją, fale (−3, −2, −1, 0, 1).
  Chart `k8s-monitoring` renderuje się z wartościami z manifestu `monitoring` (`kubeconform` ze schematem `Alloy`; dostęp do
  sekretów, lista metryk, budżet węzła z `shop`, maskowanie logów w prawdziwym Alloy: `docs/features/deploy-ops/spec.md`,
  „Smoke test i check”). `server-bootstrap.sh`
  przechodzi `shellcheck` i dwa przebiegi w kontenerze Ubuntu 26.04 bez k3s (atrapy `k3s`, `apt-get`, `ufw`, `sysctl` i
  instalatora; prawdziwe są sprawdzenia, zapisy plików i idempotencja), z odmową bez roota i na innym systemie przed
  zmianami. Ostatni krok sprawdza gitem na hoście, że `.gitignore` nie ignoruje certyfikatu
  `environments/prod/sealed-secrets.pem`. `check` chartu działa w części `checks part k8s` wymaganego
  checku `checks`, a lokalnie i w bramkach AO woła go główny `make check`. Testy skryptu `.github/scripts/deploy-ref.sh` (lokalne repo bare, także odrzucony
  non-fast-forward) i struktury rulesetów (`tools/rulesets`) uruchamia job narzędzi repo w `ci.yml`.
- `make -C infra/k8s smoke`: buduje cztery obrazy produkcyjne lokalnie i importuje je do k3d (bez wypychania; nazwa
  klastra i porty z `COMPOSE_PROJECT_NAME` i `.env` worktree), instaluje chart z profilem `ci` na pustym klastrze,
  czeka na Joby i Deploymenty, a potem sprawdza: wtyczkę `analysis-stempel` w Elasticsearchu (`_cat/plugins`)
  i działanie filtra `polish_stem` (`_analyze`: „koszulkami” → „koszulka”), `/health` API, stronę główną sklepu z produktem,
  `/api/site` (przełączniki profilu `ci`, `no-store`), nagłówek `X-Robots-Tag`, `/config.js` panelu, discovery obu
  realmów, brak dostępu do realmu `master` i konsoli przez `auth` (także próby obejścia ścieżkami z `..` i zakodowanymi
  znakami), brak użytkowników demo i rejestracji w realmie `shop`, ustawienia realmów z konfiguracji (SMTP,
  `verifyEmail`, adresy klientów), logowanie klienta sklepu (sekret klienta `store`), zdjęcie przez imgproxy i potwierdzenie zamówienia w Mailpicie. Pasek demonstracji
  i brak linku logowania na `/` i `/cart` sprawdza w prawdziwej przeglądarce (Chromium), bo przełączniki czyta
  komponent klienta. Dalej: `create-admin` (bez słowa `tak` niczego nie zmienia; z nim zakłada administratora i wysyła e-mail do
  Mailpita; istniejący login kończy się kodem 3 bez zmian; wynik nie zawiera sekretów), upgrade
  z nową rewizją (`initialize` i `keycloak-config` nie zmieniają danych ani realmów), upgrade z klientem dodanym do pliku
  realmu `shop` (`keycloak-config` dodaje go, użytkownicy zostają), `rerun` zakończonego Joba, upgrade ze zepsutą
  migracją (poprzednie `api` i `worker` działają) i upgrade na bazie z zamówieniami bez produktów (`initialize` kończy
  się błędem stanu mieszanego bez zmian). Wypisuje szczytowe zużycie pamięci i dysku. Na koniec usuwa klaster (także po
  błędzie). Z `SMOKE_VALUES=<values-images.yaml>` (workflow na `main`) pierwsza instalacja używa tego
  pliku zamiast obrazów lokalnych: k3d pobiera `ghcr.io/piotrp321/shop/<app>:<sha>@<digest>` z GHCR (uwierzytelnienie
  tokenem runu z prawem `packages: read` w prywatnym pliku `registries.yaml`, którego token nigdy nie jest wypisany), a
  test sprawdza imageID podów; dalsze fazy (nowe rewizje, zepsuta migracja) idą na obrazach lokalnych jak dotąd. Ścieżkę
  GHCR sprawdza dopiero run na `main`. Kubelet klastra smoke testu ma `imagePullCredentialsVerificationPolicy:
  NeverVerify` (plik w `kubelet.conf.d` węzła, sprawdzany po utworzeniu klastra): domyślnie kubelet pozwala użyć
  pobranego przez siebie obrazu tylko pod nazwą repozytorium, z którego go pobrał, więc ten sam obraz zaimportowany jako
  `shop-smoke-<klaster>/<app>:r2` kończyłby się `ErrImageNeverPull`.
- CI (`deploy.yml`): `smoke` na `ubuntu-latest` dla PR zmieniających `infra/**`, `apps/*/Dockerfile`,
  `apps/api/config/**`, `apps/api/bin/**`, `apps/api/composer.lock`, `apps/store/package-lock.json`,
  `apps/admin/package-lock.json`, `apps/store/next.config.ts` oraz sam workflow i `deploy-ref.sh` (obrazy z cache GHA)
  i na `workflow_dispatch` na innej gałęzi niż `main` (próba: tylko smoke, nic nie jest wypychane i `deploy` się nie
  przesuwa), oraz zawsze przed wdrożeniem (`workflow_dispatch` na `main`: obrazy, smoke, `deploy`). Push do `main` nie
  uruchamia `deploy.yml`. `workflow_dispatch` GitHub oferuje dopiero, gdy `deploy.yml` jest na gałęzi domyślnej (`main`);
  wcześniej próbę daje zdarzenie `pull_request` (PR zmienia `deploy.yml`, który jest w filtrze ścieżek).
  `smoke` nie jest wymaganym checkiem `main`.

## Scenariusze błędów

| Sytuacja | Zachowanie |
|---|---|
| Pierwsza synchronizacja na pustym klastrze | fale i initContainery; `initialize` wczytuje katalog; bez ręcznych kroków |
| Kolejne wdrożenie | `initialize` widzi dane i nic nie zmienia; `keycloak-config` dodaje tylko brakujące zasoby realmów |
| Pusty katalog, ale są zamówienia | `initialize` kończy się błędem stanu, dane nietknięte, synchronizacja czerwona |
| Nieudany build albo smoke test wdrożenia (`workflow_dispatch` na `main`) | `deploy` i `deploy-images` bez zmian, działa poprzednia wersja, workflow czerwony |
| Render `prod` z plikiem digestów nie zgadza się z plikiem albo odrzucony push (non-fast-forward, ruleset) | nic nie jest opublikowane (atomowy push), workflow czerwony |
| Rewizja `deploy-images` bez pliku `values-images.yaml` albo z brakującym digestem | render chartu kończy się błędem z nazwą wartości, synchronizacja nie startuje, działa poprzednia wersja |
| Nieudana migracja | Job `migrate` pada, nowe `api` i `worker` nie są gotowe, stare działają, aplikacja w Argo `Degraded` |
| Nieudany `purge-caches` | nowa wersja działa, synchronizacja czerwona; `rerun` albo kolejna rewizja |
| Brak wymaganej wartości (np. sekret SMTP przy `orderMail: brevo`) | render chartu kończy się błędem z nazwą wartości; synchronizacja nie startuje, działa poprzednia wersja |
| Żądanie HTTP(S) na IP serwera spoza Cloudflare | 403 z `ipAllowList` |
| Push do `deploy` albo `deploy-images` spoza workflowu, force push, usunięcie | odrzucone przez ruleset |
| Certyfikat kontrolera Sealed Secrets inny niż w repo (bootstrap bez kopii klucza) | `bootstrap` kończy się błędem, niczego nie nadpisuje; kopia klucza albo nowy certyfikat i ponowne zaszyfrowanie (runbook) |
| Restart serwera | k3s i usługi wstają same; dane na PVC zostają |
| Utrata klucza Sealed Secrets | nowy certyfikat w repo, nowe sekrety skryptem (runbook); wymaga ponownego wczytania danych albo odtworzenia z kopii (`make restore`; realm `master` z kopii ma stare hasło administratora Keycloaka, więc odtworzenie staje na `keycloak-config`: runbook) |

## Kryteria akceptacji

1. `make -C infra/k8s check` przechodzi i jest częścią `make check`; wprowadzony błąd (zły szablon, klucz
   z `init-env.sh` poza szablonem sekretu, niepoprawny manifest, ingress `auth` z realmem `master`, Job w fali
   wcześniejszej niż jego zależność) kończy go błędem.
2. `make -C infra/k8s smoke` przechodzi lokalnie i na `ubuntu-latest` z zapisanym szczytowym zużyciem pamięci i dysku;
   zepsuta migracja albo zepsuty obraz kończą go błędem, a klaster zostaje usunięty.
3. Obrazy produkcyjne budują się bez działającego stosu; obraz `api` nie ma SPX, Composera, gita ani zależności `dev`.
4. Lokalny `make up`, `make seed`, `make check`, `make test` i `make e2e` (także `STORE_MODE=production`) działają
   niezależnie od środowiska wdrożeniowego (compose nie używa etapów `prod` obrazów).
5. Przełączniki sklepu: testy komponentów i `/api/site` (z flagami, bez nich, błąd odczytu) i smoke test na obrazie
   zbudowanym bez flag.
6. `deploy.yml` przechodzi `actionlint`; uruchomiony w PR zmieniającym pliki z filtra ścieżek (zdarzenie `pull_request`; na
   innej gałęzi niż `main` także `workflow_dispatch`) buduje obrazy i przechodzi smoke test, a bez sekretu deploy keya
   kończy się bez przesuwania `deploy` i `deploy-images`. Przy wdrożeniu (`workflow_dispatch` na `main`) smoke test instaluje chart z plikiem digestów (obrazy z GHCR), a
   publikacja to jeden atomowy push po sprawdzeniu renderu (test na lokalnym repo bare, także odrzucony
   non-fast-forward, który nie przesuwa żadnej gałęzi).
7. `initialize` i `keycloak-config` są idempotentne (smoke: upgrade z nową rewizją bez zmian danych i realmów; realm
   bez użytkowników demo); `initialize` przy mieszanym stanie kończy się błędem bez zmian (test w smoke).
8. `seal-secrets.sh` na ścieżce błędu nie zapisuje plików, a ponowne uruchomienie nie zmienia istniejących sekretów;
   po dodaniu certyfikatu do repo `check` wymaga zaszyfrowanej wartości dla każdego klucza `init-env.sh`.
9. `bootstrap` przechodzi `shellcheck`, uruchomiony dwa razy w kontenerze z Ubuntu 26.04 (bez k3s) nie psuje
   konfiguracji; cele z potwierdzeniem bez `tak` niczego nie zmieniają.
10. Runbook prowadzi od pustego serwera do działającego środowiska (z próbami rulesetów, Access, poczty
    i `ipAllowList`); wykonuje się go na serwerze, poza AO, z dostępami od użytkownika.

## Ryzyka

- **Rozjazd compose, realmów i chartu.** Pilnują go zasady w `CLAUDE.md`, test sekretów w wymaganym `check` i smoke
  test; nie wyłapią nowej zmiennej z wartością domyślną ani zmiany realmu, którą `partialImport` pomija (zmiana
  istniejącego klienta – runbook: ręczna zmiana przez `kcadm` albo osobny krok w Jobie).
- **Argo bez Argo w smoke teście.** Fale sprawdza test renderu w `check`, a synchronizację Argo dopiero serwer; ryzyko
  ogranicza D8.
- **Zasoby runnera.** Cały stos w k3d na `ubuntu-latest` jest blisko limitów (16 GB, 14 GB dysku);
  profil `ci` i pomiar w kryterium 2 to sprawdzają.
- **Publiczne API panelu i checkout.** `api.<domena>/api/v1/admin` chroni token Keycloaka i role. Publiczny
  checkout gościa bez limitów pozwala blokować stany fikcyjnymi zamówieniami; rate limiting jest w osobnym
  zgłoszeniu.
- **Klucz Sealed Secrets** jest jedyną rzeczą, której nie da się odtworzyć z repo; trzyma go użytkownik.
- **Odtworzenie ćwiczone przed serwerem tylko w smoke teście.** Nocna kopia do R2 jest codziennie weryfikowana
  (alerty na jej wynik i brak), a smoke test odtwarza ją na pustym klastrze, ale bez Argo CD: wyłączanie synchronizacji
  sprawdzają testy na atrapie `kubectl`, a serwer dopiero ćwiczenie odtworzenia z runbooka (zgoda użytkownika). Backup VPS-a
  od OVH działa od pierwszego dnia. Prawdziwe dane i publiczny start dopiero po ćwiczeniu odtworzenia z runbooka na serwerze (zgoda użytkownika). Klucz R2 w klastrze pozwala usunąć kopie (token tylko do jednego
  bucketu; kopie niezmienne poza zakresem demo).
- **AppArmor containerd na Ubuntu 26.04.** k3s v1.36 oficjalnie wspiera Ubuntu 26.04 (matryca SUSE), ale przy nakładaniu
  profili (profile stacking) profil `cri-containerd.apparmor.d` blokuje sygnały między
  procesami uruchomionymi przez exec (containerd/containerd#12886, otwarte). Dotyczy exec probe usług
  i `kubectl exec` w `reinitialize` i `create-admin`: oczekiwany jest szum `DENIED` w logach jądra, a zgłoszone awarie
  dotyczą procesu zabijającego własne dziecko w sesji exec. Runbook (sekcja 6) sprawdza to na serwerze. Gdyby coś
  przestało działać, obejściem jest `securityContext.appArmorProfile: Unconfined` dla dotkniętego podu.
