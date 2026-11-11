# CLAUDE.md – infra/k8s

Zasady i cele chartu Helm, serwera i wdrożenia. Zasady ogólne (chart odzwierciedla compose, realmy, zgoda użytkownika
na operacje na serwerze) są w głównym `CLAUDE.md`. Runbook wdrożenia: `README.md` w tym katalogu.

## Cele Makefile

- `infra/k8s` ma własny `Makefile` (potrzebuje Dockera, a `check` także gita): `make -C infra/k8s check`; `make -C infra/k8s smoke`
  (test dymny: chart z profilem `ci` na pustym klastrze k3d z obrazami produkcyjnymi zbudowanymi lokalnie, kilka minut;
  sprawdza m.in. nagłówki bezpieczeństwa panelu i jego załadowanie w przeglądarce bez naruszeń CSP;
  `SMOKE_BREAK=migration|image` psuje go celowo; po `create-admin` robi kopię zapasową: osobna instancja S3 w przestrzeni
  `smoke-backup`, Job z CronJoba `backup`, snapshot `nightly` z dumpami obu baz, obiektem i manifestem; wypisuje czas
  tej fazy i zajęty dysk); `make -C infra/k8s load LOAD_OUT=<katalog poza repo>` (pomiar
  obciążenia: klaster k3d z obrazami produkcyjnymi zbudowanymi lokalnie i profilem `load`, dane demo, stany
  podniesione o 100 000 bezpośrednim SQL, rozgrzanie, przebieg rosnący do nasycenia z k6 (odczyt i koszyk) i przebieg
  stały na 70% nasycenia z checkoutem o ograniczonej równoległości, wyniki do `LOAD_OUT`; kilkadziesiąt minut, VM
  Dockera co najmniej 10 vCPU i 20 GiB; kod 0 – pomiar wykonany, bez progów, 2 – błąd środowiska: brak `LOAD_OUT`,
  za mało zasobów, pody niegotowe, dane demo albo stany niegotowe, nieudane rozgrzanie; 1 – inny błąd (nieudany przebieg k6, build); klaster jest usuwany przy każdym wyjściu); `make -C infra/k8s rerun JOB=<nazwa>` (ponawia Job wersji pod tą
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
  demo od nowa; wypisuje, co zrobi, i działa dopiero po słowie `tak`), `seal-secrets [WHAT="shop brevo tls backup"]` (szyfruje
  sekrety certyfikatem z repo do `environments/prod/secrets/`: brakujące klucze `init-env.sh` z losowymi wartościami,
  Brevo SMTP, certyfikat Origin CA oraz hasło restic i klucz S3 kopii zapasowych (`shop-backup`) czytane z terminala bez
  echa, każdy z nich tylko raz, błąd nic nie zapisuje) i `cloudflare-ips [CHECK=1]`
  (zakresy Cloudflare w `ipAllowList` produkcji). Agent wykonuje operacje na serwerze wyłącznie tymi celami. Kroki
  runbooka `infra/k8s/README.md` oznaczone „zgoda użytkownika” wykonuje dopiero po zgodzie w rozmowie, a oznaczone
  „użytkownik” (zakupy, konta zewnętrzne, wartości sekretów, Cloudflare Access, ruleset i deploy key, backup VPS-a)
  nie są jego decyzją ani czynnością.

## Co sprawdza `check`

- `make -C infra/k8s check` (woła go też główny `make check`): `helm lint`, render chartu dla `prod`, `ci` i `load` (nakładka z zasobami podów jak `prod` i nagłówkiem adresu klienta;
  test porównuje zasoby z `prod`), schemat wartości,
  `kubeconform`, `shellcheck`, `actionlint` (workflowy z `.github/workflows` razem ze skryptami ich kroków) i testy
  renderu (fale, ścieżki ingressu `auth`, middleware (`ipAllowList`, `noindex`, HSTS tylko przy `ingress.tls`), klucze sekretu, nazwy Jobów,
  CronJob, obrazy: własne z rejestru po digeście, pozostałe `tag@digest` z tagiem jak w compose, bez `latest`;
  przełączniki `demoNotice`, `customerAccounts`, `orderMail` i poczta Keycloaka w środowisku sklepu, api, workera i Jobów
  oraz w ustawieniach realmów; wartość wybierająca Brevo bez `brevo.secretName` kończy render błędem; `clientIpHeader`
  jako `CLIENT_IP_HEADER` tylko w api i sklepie, w `prod` `CF-Connecting-IP` przy `ipAllowList` na każdym ingressie,
  w `ci` brak; `clientIpHeader` bez `ipAllowList` i bez `clientIpHeaderWithoutAllowList: true` oraz to potwierdzenie
  razem z `image.requireDigests` kończą render błędem) oraz testy
  skryptów `keycloak-config` i `create-admin` na atrapach `kcadm` i `docker` oraz skryptu kopii `backup.sh` na atrapach
  `pg_dump`, `psql`, `rclone` i `restic` (błąd każdego narzędzia kończy go błędem i nie robi snapshotu; tag, host i
  manifest snapshotu; `forget --keep-within`). Kopia w renderze: CronJob `backup` (harmonogram, `Forbid`, limity czasu
  i historii), PVC `backup-work`, token API tylko w kontenerze identyfikatora klastra (bez sekretu), sekret `shop-backup`
  tylko w kontenerze restic (z hasłem repozytorium zawsze razem z adresem), obrazy z digestem; schemat: `backup.enabled`
  bez `backup.repository` albo `backup.secretName` kończy render błędem z nazwą wartości. Sprawdza też: render `prod` z plikiem
  digestów jak z `deploy-images` (bez `--set`; plik bez digestu albo z `latest` nie renderuje się), manifesty Argo CD
  (`kubeconform`; testy: jedno źródło `shop` na gałęzi `deploy-images`, kolejność fal `sealed-secrets`, `secrets`, `shop`),
  sekrety zaszyfrowane (po dodaniu certyfikatu `environments/prod/sealed-secrets.pem` każdy klucz z `init-env.sh` ma
  zaszyfrowaną wartość w `environments/prod/secrets/`; jawny Secret w tym katalogu to błąd), `seal-secrets.sh`
  i skrypty serwera na atrapach `docker` i `ssh`, `server-bootstrap.sh` dwa razy w kontenerze Ubuntu 24.04 bez k3s
  (atrapy `k3s`, `apt-get`, `ufw`, `sysctl`), brak workflowu usuwającego wersje pakietów GHCR oraz to, że `.gitignore`
  nie ignoruje certyfikatu `environments/prod/sealed-secrets.pem` (`git check-ignore` na hoście).

## Gałęzie `deploy` i `deploy-images`

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
    Są dwa, bo bypass dotyczy wszystkich reguł rulesetu. Zakładanie jak rulesetu `epic` (główny `CLAUDE.md`), osobno każdy plik
    (`--input tools/rulesets/deploy.json`, `--input tools/rulesets/deploy-push.json`). Rulesety, deploy key i sekret
    `DEPLOY_KEY` zakłada się po merge'u epiku według runbooka wdrożenia.
  - Obrazy, do których odwołują się snapshoty `deploy-images`, nie mogą być usuwane z GHCR (cofnięcie wersji to powrót do
    starszego snapshotu): żadna retencja pakietów ani workflow nie usuwa ich wersji (test w `make -C infra/k8s check`).
  - Test struktury rulesetów: `python3 -m unittest discover -s tools/rulesets -p 'test_*.py'`.
