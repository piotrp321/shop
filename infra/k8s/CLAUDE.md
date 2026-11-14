# CLAUDE.md – infra/k8s

Zasady i cele chartu Helm, serwera i wdrożenia. Zasady ogólne (chart odzwierciedla compose, realmy, zgoda użytkownika
na operacje na serwerze) są w głównym `CLAUDE.md`. Runbook wdrożenia: `README.md` w tym katalogu.

## Cele Makefile

- `infra/k8s` ma własny `Makefile` (potrzebuje Dockera, a `check` także gita): `make -C infra/k8s check`; `make -C infra/k8s smoke`
  (test dymny: chart z profilem `ci` na pustym klastrze k3d z obrazami produkcyjnymi zbudowanymi lokalnie, kilka minut;
  sprawdza m.in. nagłówki bezpieczeństwa panelu i jego załadowanie w przeglądarce bez naruszeń CSP;
  obrazy każdej rewizji sprawdza w węźle przez `crictl` po `k3d image import`, który potrafi skończyć się kodem 0 bez
  importu, i importuje brakujące ponownie, najwyżej trzy razy; `SMOKE_BREAK=migration|image` psuje go celowo; po `create-admin` robi kopię zapasową: osobna instancja S3 w przestrzeni
  `smoke-backup`, Job z CronJoba `backup`, snapshot `nightly` z dumpami obu baz, obiektem i manifestem, potem Job z CronJoba
  `backup-verify` (profil `ci`: `verifyReadData: all`); wypisuje czas tej fazy, czas weryfikacji i zajęty dysk; potem
  odtworzenie na pustym klastrze: `backups`, usunięcie przestrzeni `shop` z wolumenami, instalacja od nowa, `restore` snapshotu
  `nightly` (bez Argo CD): administrator z `create-admin`, klient i jego logowanie, liczby produktów i zamówień jak przy kopii,
  realmy bez zmian, obiekt w buckecie, zdjęcie przez imgproxy, produkt w wyszukiwarce po słowie, które ma tylko odtworzona
  baza, strona główna, snapshot `pre-restore`; `restore` snapshotu z migracją nieznaną kodowi kończy się odmową bez pytania,
  bez kopii `pre-restore` i bez zmian; wypisuje czas fazy i szczytowy dysk; dalsze fazy działają na odtworzonym klastrze; Alloy i Grafana Cloud w nim nie działają, bo CI nie ma kont: sprawdza je `check`, `monitoring-smoke` i runbook); `make -C infra/k8s monitoring-smoke` (monitoring na pustym klastrze k3d, poza `check` i CI, kilka minut: CRD `prometheus-operator-crds` i chart `k8s-monitoring` z archiwów `monitoring-render.sh` z wartościami manifestu `monitoring`, jedyna nakładka to adresy celów wskazujące odbiornik, Alloy z `ALLOY_IMAGE` w kontenerze na sieci Dockera klastra, nie w klastrze, bo Alloy zbierałby jego log w pętli; RBAC i polityki dopuszczania bez nakładki; czeka na operatora i Deployment `monitoring-alloy`; polityki dopuszczania: żądania `--dry-run=server` jako konto operatora (Deploymenty z `hostPath` `/`, `hostNetwork`, `hostPID`, `hostIPC`, `hostPort`, `privileged`, dodaną capability, uprzywilejowanym kontenerem init, klasą priorytetu `system-cluster-critical` albo `system-node-critical`, etykietami node-exportera i szablonem z `ownerReference` na jego DaemonSet, Service `NodePort`, `LoadBalancer`, `ExternalName` i `ClusterIP` z `externalIPs`, zmiana Service `monitoring-alloy` na `NodePort` albo z `externalIPs`, sekret z tokenem konta) i jako administrator (pody z etykietami albo podrobionym `ownerReference` node-exportera, DaemonSet, StatefulSet, ReplicaSet, Job, CronJob, kontener efemeryczny i inny obraz w podzie node-exportera, pod i CronJob z klasą priorytetu systemu, Service `NodePort`, `LoadBalancer` i z `externalIPs`, zmiana Service kube-state-metrics na `LoadBalancer`, sekret z tokenem) musi odrzucić polityka (jej nazwa w błędzie, nie RBAC), a zwykły Deployment, Service `ClusterIP` i headless operatora, zwykły kontener efemeryczny, sekret `Opaque` oraz w `shop` pod z `hostPath`, pod z klasą `system-cluster-critical` i Service `NodePort` przechodzą; potem usuwa wszystkie pody `monitoring` i czeka, aż powstaną pod politykami (DaemonSet node-exportera, operator, kube-state-metrics, Alloy); dalej zamaskowana linia poda-znacznika w `shop` z hasłem i e-mailem, zdarzenie klastra tego poda i metryki kube-state-metrics, node-exportera, kubeleta i cAdvisora w odbiorniku (metryki wysłane po odtworzeniu podów), logi operatora, Alloy i kube-state-metrics sprzed i po odtworzeniu bez `forbidden`, potem `kubectl auth can-i` dla kont usług operatora, Alloy, kube-state-metrics i node-exportera: żadnych sekretów `shop` (w tym `shop-backup`, `shop-secrets`) ani `kube-system`, `bind`, `escalate`, `impersonate`, tworzenia ról i powiązań ani podów w `shop`, podzasobów (`--subresource`) `exec`, `attach`, `portforward`, `ephemeralcontainers` i `serviceaccounts/token` w `shop` i `kube-system` ani `nodes/proxy`; Alloy czyta `grafana-cloud`, operator działa w `monitoring`, tworzy Deploymenty, ale nie DaemonSety, StatefulSety, pody ani zasoby `Alloy`, a zmienia i usuwa tylko obiekty `monitoring-alloy`; wypisuje `can-i --list` operatora i Alloy, czas faz; każda niezgodność kończy go błędem; `MONITORING_SMOKE_BREAK=operator-cluster-role` instaluje RBAC chartu operatora, a `MONITORING_SMOKE_BREAK=no-admission-policy` `extraObjects` bez polityk dopuszczania i test musi się nie udać, `MONITORING_SMOKE_KEEP=1` zostawia klaster po błędzie); `make -C infra/k8s load LOAD_OUT=<katalog poza repo>` (pomiar
  obciążenia: klaster k3d z obrazami produkcyjnymi zbudowanymi lokalnie i profilem `load`, dane demo, stany
  podniesione o 100 000 bezpośrednim SQL, rozgrzanie, przebieg rosnący do nasycenia z k6 (odczyt i koszyk) i przebieg
  stały na 70% nasycenia z checkoutem o ograniczonej równoległości, wyniki do `LOAD_OUT`; kilkadziesiąt minut, VM
  Dockera co najmniej 10 vCPU i 20 GiB; kod 0 – pomiar wykonany, bez progów, 2 – błąd środowiska: brak `LOAD_OUT`,
  za mało zasobów, obrazy nieobecne w węźle po trzech importach, pody niegotowe, dane demo albo stany niegotowe, nieudane rozgrzanie; 1 – inny błąd (nieudany przebieg k6, build); klaster jest usuwany przy każdym wyjściu); `make -C infra/k8s rerun JOB=<nazwa>` (ponawia Job wersji pod tą
  samą nazwą); `make -C infra/k8s selftest` (wstrzykuje błędy i sprawdza, że `check` je wyłapuje: każdy przypadek uruchamia tylko krok
  `check.sh`, który ma ten błąd wyłapać, a w kroku `tests` tylko test, który ma się nie udać (`CHECK_TESTS_FILTER`);
  nieuszkodzona kopia przechodzi pełny `check`; `SELFTEST_FULL=1` uruchamia przy każdym przypadku pełny `check`;
  `SELFTEST_ONLY='<regex>'` wybiera przypadki po nazwie; przypadki działają równolegle, po jednym na procesor demona Dockera,
  każdy we własnym drzewie (`SELFTEST_JOBS=<n>` ustawia liczbę, `1` to kolejno; wyjście przypadku jest drukowane w całości, po jego
  zakończeniu); CI go nie uruchamia: wszystkie przypadki idą na żądanie (`make -C infra/k8s selftest` lokalnie albo
  `gh workflow run k8s-selftest.yml --ref <gałąź> [-f only=<regex>]`, pole `only` to `SELFTEST_ONLY`), a agent uruchamia lokalnie tylko
  przypadki, które dodał albo zmienił, i przypadki testów, które zmienił);
  `make -C infra/k8s create-admin LOGIN=<login> EMAIL=<e-mail>` (pierwszy administrator panelu: wypisuje, co zrobi,
  i działa dopiero po słowie `tak`; zakłada użytkownika w realmie `backoffice` z rolą `admin` bez hasła i wysyła mu
  e-mail z akcjami `UPDATE_PASSWORD` i `CONFIGURE_TOTP`, link ważny 12 h; istniejący login albo e-mail: błąd, kod 3,
  bez zmian; niczego tajnego nie wypisuje); `make -C infra/k8s restore SNAPSHOT=<id> [PRE_RESTORE=skip]` (odtworzenie
  snapshotu kopii, `docs/features/deploy-ops/spec.md`, O4: bez `SNAPSHOT` (identyfikator z `backups`, nigdy `latest`), przy
  aktywnym Jobie w przestrzeni, trwającej synchronizacji `shop` w Argo CD albo snapshocie z migracją spoza klas migracji
  obrazu `api` odmawia bez zmian; wypisuje snapshot i skutki, działa dopiero po słowie `tak`; najpierw kopia `pre-restore`
  (Job z CronJoba `backup`; jej błąd kończy bez zmian, `PRE_RESTORE=skip` ją pomija), potem wyłącza synchronizację `root`
  i `shop` (merge patchem: przejdzie też bez `automated`), wstrzymuje `cart-purge-expired` i `backup`, czeka na Job, który
  wystartował po pierwszym sprawdzeniu (`RESTORE_ACTIVE_JOBS_WAIT_SECONDS`, domyślnie 7200 s; potem błąd bez zmiany
  aplikacji), skaluje do zera `api`, `worker`, `store` i `keycloak`, Job z
  CronJoba `restore` odtwarza dane, ponawia `migrate`, `keycloak-config` (jak `rerun`), reindeks i `purge-caches`, przywraca
  repliki, CronJoby (`suspend: false` jak w chartcie, także po przerwanej próbie) i synchronizację `root`; błąd po pierwszej zmianie zostawia aplikacje wyłączone i wypisuje krok oraz
  snapshot `pre-restore`; kod 0, 1 odmowa albo błąd, 2 złe argumenty) i `make -C infra/k8s backups` (snapshoty repozytorium:
  identyfikator, czas, host, tagi, rewizja; tylko odczyt). `rerun`, `create-admin`, `restore` i `backups` łączą się z
  klastrem przez kubeconfig z `KUBE_DIR` (wymagany), w kontenerze w sieci Dockera `KUBE_NETWORK` (domyślnie `host`), w
  przestrzeni `KUBE_NAMESPACE` (domyślnie `shop`). Agent uruchamia `create-admin` i `restore` tylko po zgodzie użytkownika
  w rozmowie; `backups` sam.
  Serwer obsługują cele (po SSH, `HOST=<user>@<adres>`, opcje ssh w `SSH_OPTS`): `make -C infra/k8s bootstrap HOST=…
  [SEALED_KEY_FILE=<kopia klucza>]` (czysty Ubuntu 26.04: k3s w przypiętej wersji, ufw, aktualizacje bezpieczeństwa, Argo CD,
  Sealed Secrets, aplikacja `root`; idempotentny; bez roota albo na innym systemie kończy się błędem przed zmianami;
  zapisuje certyfikat kontrolera do `environments/prod/sealed-secrets.pem`, gdy repo go nie ma, a rozbieżny
  certyfikat to błąd), `kubeconfig HOST=… KUBE_DIR=…` (kubeconfig klastra poza repo, dla `rerun`, `create-admin`, `restore`
  i `backups`),
  `status HOST=…` (tylko odczyt: aplikacje Argo, pody, Joby i logi nieudanych Jobów), `reinitialize HOST=…` (kasuje dane sklepu i wczytuje dane
  demo od nowa; wypisuje, co zrobi, i działa dopiero po słowie `tak`), `seal-secrets [WHAT="shop brevo tls backup grafana"]` (szyfruje
  sekrety certyfikatem z repo do `environments/prod/secrets/`: brakujące klucze `init-env.sh` z losowymi wartościami,
  Brevo SMTP, certyfikat Origin CA, hasło restic i klucz S3 kopii zapasowych (`shop-backup`) oraz token Grafana Cloud
  (`grafana-cloud`, klucz `GRAFANA_CLOUD_TOKEN`) czytane z terminala bez echa (hasło restic dwa razy, różne wartości to
  błąd), każdy z nich tylko raz, błąd nic nie zapisuje) i `cloudflare-ips [CHECK=1]`
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
  skryptów `keycloak-config` i `create-admin` na atrapach `kcadm` i `docker` oraz skryptów kopii `backup.sh` i weryfikacji `backup-verify.sh` na atrapach
  `pg_dump`, `psql`, `rclone`, `restic`, `pg_restore` i `date` (błąd każdego narzędzia kończy je błędem i `backup.sh` nie
  robi snapshotu; tag, host i manifest snapshotu; `forget --keep-within` tylko po kopii `nightly` (kopia `pre-restore` niczego nie przycina); weryfikacja: snapshot `nightly` tego klastra
  starszy niż 26 h albo brak snapshotu, błąd `restic check`, `n` w `--read-data-subset=n/7` z `date +%u` i `--read-data`
  przy `verifyReadData: all`, dumpy strumieniem z `restic dump` do `pg_restore`, błąd `pg_restore` albo `restic dump`,
  pusta tabela migracji, brak realmu `shop` albo `backoffice`, liczba obiektów inna niż w manifeście: błąd z opisem,
  zdrowy przypadek przechodzi) oraz skryptu poda odtworzenia `restore-job.sh` na atrapach `restic`, `psql`, `pg_restore`, `rclone`,
  `redis-cli`, `rabbitmqadmin` i `bin/console` (tryby `list`, `check`, `restore`, `reindex`; snapshot z migracją spoza klas
  migracji obrazu `api` odrzucony przed odtworzeniem; kolejność `DROP`/`CREATE`/`pg_restore` z rolą właściciela; błąd każdego
  narzędzia kończy etap błędem), skryptów `restore.sh`, `backups.sh` i `rerun.sh` na atrapie `docker` grającej klaster dla
  `kubectl` (bez `tak`, bez `SNAPSHOT`, przy aktywnym Jobie, trwającej synchronizacji `shop` i snapshocie nowszym niż kod
  nic się nie zmienia; nieudana kopia `pre-restore` bez `PRE_RESTORE=skip` niczego nie zmienia; identyfikator snapshotu `pre-restore` jest czytany z pełnego logu Joba, także przy długim; kolejność kroków z Argo CD
  (`root`, potem `shop`, na końcu `root`) i bez niego (zapamiętane repliki; CronJoby wstrzymane po przerwanej próbie wracają do `suspend: false`); błąd Joba odtworzenia zostawia aplikacje
  wyłączone i wypisuje krok oraz snapshot `pre-restore`; aplikacje bez `automated` w Argo CD; Job aktywny, który pojawił się po
  pierwszym sprawdzeniu, jest czekany i po limicie kończy odtworzenie przed tym, co rusza dane; czekanie najwyżej `activeDeadlineSeconds` Joba). Kopia w renderze: CronJob `backup` (harmonogram, `Forbid`, limity czasu
  i historii), PVC `backup-work`, token API tylko w kontenerze identyfikatora klastra (bez sekretu), sekret `shop-backup`
  tylko w kontenerze restic (z hasłem repozytorium zawsze razem z adresem), obrazy z digestem; CronJob
  `backup-verify` (05:00, limity jak `backup`, tymczasowy Postgres jako natywny sidecar na `emptyDir`, bez sekretów i
  połączeń produkcyjnej bazy, sekret `shop-backup` tylko w kontenerze skryptu, token tylko w identyfikatorze klastra,
  `restic` jako wolumen obrazu z jego certyfikatami CA w `SSL_CERT_FILE`, `emptyDir` z limitem `storage.postgres`); CronJob `restore` (`suspend: true`, `activeDeadlineSeconds: 10800`,
  `backoffLimit: 0`, etapy w kontenerach obrazów narzędzi i `api` rewizji, tryb `list` domyślnie, bez tokenu API, sekret
  `shop-backup` tylko w kontenerze restic, cache restic poza wolumenem `backup-work`); schemat: `backup.enabled`
  bez `backup.repository` albo `backup.secretName` oraz `backup.verifyReadData` inne niż `subset` i `all` kończą render błędem
  z nazwą wartości. Sprawdza też: render `prod` z plikiem
  digestów jak z `deploy-images` (bez `--set`; plik bez digestu albo z `latest` nie renderuje się), manifesty Argo CD
  (`kubeconform`; testy: jedno źródło `shop` na gałęzi `deploy-images`, kolejność fal `prometheus-crds` −3, `sealed-secrets` −2,
  `secrets` −1, `shop` 0, `monitoring` 1, `ServerSideApply` przy CRD, `SkipDryRunOnMissingResource` i `CreateNamespace` przy
  `monitoring`, charty innych przypięte wersją), render chartu `k8s-monitoring` z wartościami z manifestu `monitoring`
  (`scripts/monitoring-render.sh`: archiwum chartu pobrane raz do cache narzędzi, sprawdzone sha256 zapisanym w skrypcie;
  `kubeconform` ze schematem `Alloy`; RBAC renderu: żadna rola nie ma `bind`, `escalate`, `impersonate`, `*`, zmian obiektów
  RBAC ani podzasobów `pods/exec`, `pods/attach`, `pods/portforward`, `pods/ephemeralcontainers`, `serviceaccounts/token`
  i `nodes/proxy` (także przez `*`, `pods/*`, `*/proxy`), ClusterRole tylko czyta i nigdy sekretów (także przez `*`), Role tylko
  w `shop` i `monitoring`, sekrety poza `monitoring` daje tylko Role na `grafana-cloud` w `shop` związana wyłącznie z kontem
  Alloy, a sekrety w `monitoring` tylko Role operatora związana wyłącznie z jego kontem; operator bez ClusterRoleBinding i bez
  powiązań poza `monitoring`, z `WATCH_NAMESPACE=monitoring`; podmioty powiązań porównywane po rodzaju, nazwie i przestrzeni,
  a grupy `system:serviceaccounts`, `system:serviceaccounts:monitoring`, `system:authenticated` i nazwa użytkownika konta
  obejmują konta monitoringu; powiązania tylko do ról renderu, bez `aggregationRule`; dokładny kształt Role operatora (bez
  DaemonSetów, StatefulSetów, ConfigMap, podów i tworzenia zasobów `Alloy`, zmiana i usuwanie Deploymentu, konta i Service
  tylko `monitoring-alloy`); zasób `Alloy` z `rbac.create: false`, a ClusterRole kolektora z `extraObjects` czyta dokładnie
  listę zasobów z testu; polityki dopuszczania: trzy `ValidatingAdmissionPolicy` z powiązaniami na `monitoring` przez
  `kubernetes.io/metadata.name`, `Deny`, `failurePolicy: Fail`, bez `matchConditions` i selektorów, w fali przed resztą
  aplikacji, polityka hosta na pody (z kontenerami efemerycznymi) i szablony podów sześciu rodzajów workloadów przy tworzeniu
  i zmianie, z polami dostępu do hosta i listą capability profilu baseline, wyjątek tylko dla node-exportera po koncie
  kontrolera DaemonSetów i `ownerReferences`, bez etykiet, oraz odrzucenie `priorityClassName` z prefiksem `system-` bez
  wyjątku; w renderze dostęp do hosta ma tylko DaemonSet node-exportera, a klasy priorytetu systemu żaden workload; polityka
  Service przy tworzeniu i zmianie dopuszcza tylko typ `ClusterIP` bez `externalIPs`, a każdy Service renderu jest `ClusterIP`;
  polityka tokenów na sekrety; lista dozwolonych metryk niepusta i z metrykami alertów, widoczna w
  konfiguracji Alloy; budżet węzła: `shop` i `monitoring` razem do 7 vCPU requestów i 20 GiB limitów, z jawnymi zasobami Alloy,
  jego reloadera, operatora, kube-state-metrics i node-exportera; obraz Alloy w `tools.sh` to tag z chartu i z manifestu),
  test maskowania logów (`scripts/masking.sh`: prawdziwy Alloy, przypięty tagiem i digestem, uruchamia etapy
  `extraLogProcessingStages` wyciągnięte z manifestu na korpusie `tests/log-corpus`, jeden plik na źródło w jego formacie, z
  podłożonymi hasłami, tokenami, JWT, nagłówkami, ciasteczkami, e-mailami, adresami IP, parametrami Doctrine i `DETAIL`
  Postgresa; korpus idzie przez potok logów podów (linie CRI) i zdarzeń; test sprawdza, że żadna podłożona wartość nie wyszła,
  linie `DETAIL` i `STATEMENT` zniknęły, a poziom, komunikat, kanał i kod odpowiedzi zostały, że oba miejsca konfiguracji mają
  ten sam blok etapów i że reguły `extraDiscoveryRules` odrzucają dokładnie źródła z listy wyłączonych, czyli Mailpit; ta sama
  konfiguracja z renderu przechodzi `alloy validate`),
  reguły alertów (`templates/00-alerts.yaml`: nazwa sortuje się tak, żeby Helm, który renderuje szablony w odwrotnej kolejności
  nazw, wyrenderował ją po wszystkich workloadach; `PrometheusRule` z siedmioma alertami tylko przy `alerts.enabled`, czyli w
  `prod`, nie w `ci` i `load`, a bez `backup.enabled` bez czterech alertów kopii; `WorkloadNotReady` ma `absent` dla każdego Deploymentu
  i StatefulSetu renderu, także w `ci` z `alerts.enabled`, bo `shop.deployment` i `shop.statefulset` zapisują workload w rejestrze
  czytanym przez regułę; alerty kopii patrzą na dokładną nazwę CronJoba, `BackupMissing` i `BackupVerifyMissing` mają `absent`,
  `NodeDiskFull` pomija systemy tymczasowe; brak CRD przy `alerts.enabled` ma dawać błąd synchronizacji, więc szablon nie pyta
  klastra o wersje API) i krok `alerts`: `promtool check rules` i `promtool test rules` (obraz `prom/prometheus` przypięty
  tagiem i digestem w `tools.sh`) na regułach wyciągniętych z renderu `prod` przez `tests/alerts/prepare.py`, ze scenariuszami
  `tests/alerts/alerts.test.yml` (każdy alert odpala w swoim scenariuszu i milczy, gdy zdrowo; alerty kopii milczą przy udanym
  ponowieniu i po powrocie do zdrowia, `backup` nie łapie Jobów `backup-verify`; `NodeDiskFull` milczy przy 85% i krótkim skoku;
  `WorkloadNotReady` odpala przy niedostępnej replice i brakującym workloadzie, milczy przy niedostępności krótszej niż 10 minut,
  zeskalowaniu do zera i ciszy klastra; linia `# @healthy-workloads` scenariusza to serie zdrowych workloadów renderu, więc nowy
  workload nie wymaga zmiany scenariuszy); `selftest` ma przypadki: `BackupMissing` bez `absent`, `NodeDiskFull` odpalający przy
  dokładnie 85% i regułę ze składnią, której PromQL nie przyjmuje,
  sekrety zaszyfrowane (po dodaniu certyfikatu `environments/prod/sealed-secrets.pem` każdy klucz z `init-env.sh` ma
  zaszyfrowaną wartość w `environments/prod/secrets/`; jawny Secret w tym katalogu to błąd), `seal-secrets.sh`
  i skrypty serwera na atrapach `docker` i `ssh`, `server-bootstrap.sh` dwa razy w kontenerze Ubuntu 26.04 bez k3s
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
  - `workflow_dispatch` na `main` (wdrożenie tylko na życzenie użytkownika: `gh workflow run deploy.yml --ref main`, agent
    uruchamia je wyłącznie na jego wyraźne polecenie w rozmowie; push do `main` niczego nie wdraża): buduje i wypycha cztery obrazy produkcyjne
    `ghcr.io/piotrp321/shop/<app>:<sha>` (api, store, admin, elasticsearch; ich digesty są w podsumowaniu runu), raz generuje `values-images.yaml`
    (`infra/k8s/scripts/images-values.sh`), uruchamia smoke test na tych obrazach (`make -C infra/k8s smoke` na
    `ubuntu-latest`; z `SMOKE_VALUES=<ten plik>` pierwsza instalacja pobiera obrazy z GHCR po digeście) i dopiero po zielonym
    oraz po sprawdzeniu renderu `prod` z tym plikiem (`infra/k8s/scripts/verify-images.sh`) przesuwa `deploy` i
    `deploy-images`. Błąd walidacji albo pusha: nic nie jest opublikowane. Bez sekretu `DEPLOY_KEY` kończy się po smoke
    teście z ostrzeżeniem, bez błędu. Runy dla `main` nie są anulowane: działa jeden, czeka najwyżej jeden (najnowszy). Jeden przełącznik `env.DEPLOY`
    (dispatch na `main`) decyduje o wypychaniu obrazów, pliku digestów i jobie `deploy`.
  - pull request zmieniający pliki obrazów lub chartu (ścieżki w `deploy.yml`) i `workflow_dispatch` na innej gałęzi: tylko smoke test
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
