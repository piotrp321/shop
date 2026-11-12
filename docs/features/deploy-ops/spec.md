# DEPLOY-OPS: kopie zapasowe i monitoring środowiska

Druga część środowiska wdrożeniowego (DEPLOY, `docs/features/deploy/spec.md`): codzienne, szyfrowane kopie danych
sklepu poza serwerem, ich automatyczna weryfikacja, procedura odtworzenia sprawdzona na pustym klastrze oraz logi,
metryki i alerty w Grafana Cloud. Musi być skończone przed przełączeniem na publiczny sklep albo przed pierwszymi
prawdziwymi danymi.

Issues: #178
Zakres: `infra/k8s`, `docs/adr`, `CLAUDE.md`, `docs/vision.md`, `docs/features/deploy/spec.md`, `docs/features/hardening/spec.md`

## Cel i zakres

- Codzienna kopia baz PostgreSQL `shop` i `keycloak` (`pg_dump`) oraz obiektów Garage (bucket `product-images`, przez
  API S3 i rclone), szyfrowana i deduplikowana przez restic, w repozytorium restic w Cloudflare R2. Retencja 7 dni.
- Automatyczna weryfikacja po każdej kopii (`backup-verify`): wiek ostatniego snapshotu, `restic check`, odtworzenie
  obu dumpów do tymczasowej bazy.
- Odtworzenie wskazanego snapshotu jednym celem `make -C infra/k8s restore` (zgoda użytkownika), z migracjami,
  konfiguracją realmów, przebudową indeksu wyszukiwarki i czyszczeniem cache sklepu; lista snapshotów celem tylko do
  odczytu.
- Testowe odtworzenie na pustym klastrze w smoke teście (k3d) i ćwiczenie odtworzenia na serwerze w runbooku.
- Grafana Alloy (chart `k8s-monitoring`) w klastrze: logi podów, zdarzenia i metryki klastra do Grafana Cloud.
- Alerty w Grafana Cloud: nieudana kopia, brak kopii, nieudana albo brakująca weryfikacja, zajęty dysk węzła, brak
  sygnału z klastra, niegotowy albo brakujący workload sklepu.
- Logi w Grafana Cloud bez haseł, tokenów, nagłówków `Authorization`, ciasteczek sesyjnych i danych klientów
  (maskowanie w Alloy; źródło, którego nie da się zamaskować, jest wyłączone).

## Decyzje użytkownika

- Storage S3 to Cloudflare R2 (osobny dostawca niż VPS, 10 GB bez opłat, bez opłat za transfer wychodzący).
- Alerty: cztery alerty kopii oraz zajęty dysk węzła powyżej 85%, brak sygnału z klastra przez 15 minut i
  `WorkloadNotReady` (Deploymenty i StatefulSety w `shop`, w tym Redis, niedostępne repliki albo brak workloadu przez
  10 minut). Alerty z treści logów są poza zakresem. Świadomy `restore` i planowane prace: ręczne wyciszenie w Grafana
  Cloud według runbooka, bez automatycznego mechanizmu.
- Logi wszystkich podów trafiają do Grafana Cloud (stos w regionie UE, 14 dni retencji), ale bez haseł, tokenów,
  nagłówków `Authorization`, ciasteczek sesyjnych i danych klientów: maskowanie albo wyłączenie źródła (O7).
- Nieudana kopia `pre-restore` blokuje odtworzenie, chyba że jawnie podano `PRE_RESTORE=skip`.
- Odtworzenie wylogowuje klientów (czyści Redis) i usuwa wiadomości z kolejek RabbitMQ.
- Kopie niezmienne (S3 Object Lock) są poza zakresem demo; ryzyko jest udokumentowane, wracamy do niego przed
  uruchomieniem publicznym.

## Poza zakresem

- Sentry, trace'y i Sentry Performance (#180); własny Prometheus i Loki; dashboardy poza tymi, które daje
  Grafana Cloud dla Kubernetes.
- Alerty z treści logów (np. ostrzeżenia kanału `cache` przy awarii Redisa) i automatyczne wyciszanie alertów.
- Kopia poza OVH inna niż storage S3; kopia Elasticsearcha (indeks jest pochodny: odtworzenie go przebudowuje),
  RabbitMQ (wiadomości w locie; trwałe zdarzenia są w outboxie w Postgresie) i Redisa (sesje sklepu).
- Kopie i monitoring w lokalnym compose: to funkcje środowiska wdrożeniowego, compose się nie zmienia.
- Kopia klucza Sealed Secrets: zostaje u użytkownika (DEPLOY, D5).
- Automatyczne odtwarzanie bez człowieka i odtwarzanie do punktu w czasie (PITR, WAL).
- Zakup i konfiguracja kont (Cloudflare R2, Grafana Cloud), ich wartości sekretów i samo uruchomienie na serwerze: kroki
  runbooka „użytkownik” po merge'u epiku (serwer: #297).

## Decyzje

- **O1. Kopia jako CronJob chartu `shop` z oficjalnymi obrazami narzędzi.** CronJob `backup` (codziennie o 03:00
  `Europe/Warsaw`, `concurrencyPolicy: Forbid`, `startingDeadlineSeconds: 3600`, `backoffLimit: 1`,
  `activeDeadlineSeconds: 7200`, `successfulJobsHistoryLimit: 1`, `failedJobsHistoryLimit: 1`) ma pod z sekwencją
  kontenerów na PVC `backup-work`:
  1. initContainer z tokenem API (wolumen tokenu tylko dla niego, jak oczekiwanie Jobów `initialize` i `purge-caches`;
     ClusterRole z samym `get` na `namespaces`, `resourceNames: [kube-system]`) zapisuje UID przestrzeni `kube-system`
     (identyfikator klastra) do `emptyDir`; kontenery z sekretem `shop-backup` nie montują tokenu;
  2. `pg_dump` obu baz (obraz `postgres` z chartu, ta sama wersja co serwer; format custom, `--no-owner
     --no-privileges`), użytkownik `postgres`, do `dumps/`;
  3. `rclone sync` bucketu `product-images` z Garage (klucz S3 sklepu) do `objects/`;
  4. `manifest.json`: rewizja (tag obrazu `api`), czas, wersje migracji Doctrine z bazy `shop` (tabela
     `doctrine_migration_versions`), liczba obiektów i bajtów;
  5. `restic unlock` (tylko nieaktualne blokady), `restic backup --retry-lock 30m` katalogów `dumps/`, `objects/`
     i manifestu z tagiem `BACKUP_TAG` (domyślnie `nightly`) i `--host` równym identyfikatorowi klastra, potem `restic
     forget --keep-within 7d --prune --retry-lock 30m` (restic grupuje po hoście, więc klaster przycina tylko własne
     snapshoty: nowy serwer po awarii nie usunie kopii starego); repozytorium inicjuje się samo przy pierwszej kopii
     (`restic init`, gdy go nie ma; każdy inny błąd dostępu to błąd).

  Błąd dowolnego kroku przed `restic backup` kończy Job bez snapshotu. Obrazy `restic/restic` i `rclone/rclone` są
  w `images.*` wartości chartu z tagiem i digestem, jak inne obrazy usług (bez odpowiednika w compose; test obrazów zna
  je jak `alpine` i `kubectl`). Bez własnego obrazu kopii i bez zmian w `deploy.yml`. PVC `backup-work` (`local-path`,
  `storage.backupWork: 25Gi`, więcej niż `storage.garageData`) trzyma lustro obiektów i cache restic: `rclone sync`
  przenosi tylko zmienione obiekty, więc nocny koszt rośnie ze zmianami, nie z rozmiarem bucketu.
- **O2. Repozytorium i dostęp z wartości i jednego sekretu.** Adres repozytorium restic (`backup.repository`, w `prod`
  bucket Cloudflare R2: `s3:https://<id konta>.r2.cloudflarestorage.com/<bucket>`) i region (`backup.s3Region`, dla R2
  `auto`) są wartościami `environments/prod/values.yaml`. Sekret `shop-backup` (SealedSecret w
  `environments/prod/secrets/`, przestrzeń `shop`): `RESTIC_PASSWORD`, `BACKUP_S3_ACCESS_KEY_ID`,
  `BACKUP_S3_SECRET_ACCESS_KEY`. Tworzy go raz `make -C infra/k8s seal-secrets WHAT=backup` (wartości z terminala bez
  echa, jak `brevo`); `seal-secrets` bez `WHAT` obejmuje `shop brevo tls backup grafana`. Test kluczy SealedSecretów
  (`EXPECTED_KEYS`) zna `shop-backup` i `grafana-cloud`. Profil `ci` ma sekret renderowany przez chart z
  `backup.secretValues` przy `secrets.create: true`. `backup.enabled: true` bez `backup.repository` albo
  `backup.secretName` (w `prod`) kończy render błędem z nazwą wartości (schemat). Hasło restic użytkownik trzyma też poza
  serwerem (menedżer haseł): bez niego kopie są nie do odczytania, także po utracie klucza Sealed Secrets.
- **O3. Weryfikacja jako osobny CronJob, czytający z S3.** `backup-verify` (codziennie o 05:00, te same limity historii
  i czasu co `backup`, identyfikator klastra jak w O1):
  1. najnowszy snapshot z tagiem `nightly` tego klastra młodszy niż 26 h;
  2. `restic check --retry-lock 30m` z odczytem podzbioru danych `--read-data-subset=n/7`, gdzie n to `date +%u`
     (1–7), więc w tygodniu odczytuje każde dane raz; `backup.verifyReadData: all` (profil `ci`) odczytuje wszystko
     (`--read-data`);
  3. `restic dump` obu dumpów strumieniowo do `pg_restore` w tymczasowym Postgresie w tym samym podzie (kontener
     z obrazem `postgres` na `emptyDir`, bez dostępu do produkcyjnej bazy i bez kopii obiektów na dysku); sprawdza brak
     błędów `pg_restore`, niepustą tabelę migracji w `shop`, realmy `shop` i `backoffice` w `keycloak`;
  4. liczba obiektów w snapshocie (`restic ls`) równa liczbie z `manifest.json`.

  Każdy niespełniony warunek, także błąd narzędzia, kończy Job błędem z opisem w logu (kod wyjścia skryptu sprawdzają
  testy na atrapach `restic` i `pg_restore` w `check`).
- **O4. Odtworzenie przez cel Makefile i Job z szablonu chartu.** Chart ma wstrzymany CronJob `restore` (`suspend:
  true`, nigdy nie uruchamia się sam, `activeDeadlineSeconds: 10800`) z pełną specyfikacją poda odtworzenia, więc
  odtworzenie używa obrazów i sekretów wdrożonej rewizji. Pod ma tryby: `list` (snapshoty), `check` (manifest snapshotu
  i zgodność migracji) i `restore`. Cel `make -C infra/k8s restore SNAPSHOT=<id> KUBE_DIR=<katalog>` łączy się z
  klastrem jak `rerun` i `create-admin` (kubeconfig z `KUBE_DIR`, `KUBE_NETWORK`, `KUBE_NAMESPACE`), więc smoke test
  uruchamia ten sam skrypt co serwer. Joby z CronJobów tworzy `kubectl create job --from=cronjob/<nazwa> --dry-run=client
  -o yaml`, parametry dodaje `kubectl set env --local` (`RESTORE_MODE`, `RESTORE_SNAPSHOT`, `BACKUP_TAG=pre-restore`),
  bez dodatkowych obiektów. Skrypt czeka na każdy Job najwyżej tyle, ile jego `activeDeadlineSeconds`. Kroki:
  1. sprawdzenia bez zmian: `SNAPSHOT` jest wymagany (identyfikator z listy), bez `latest`; odmowa, gdy w przestrzeni
     `shop` jest aktywny dowolny Job (`backup`, `migrate`, `initialize`, `keycloak-config`, `purge-caches`, czyszczenie
     koszyków, inny `restore`) albo, przy Argo CD, aplikacja `shop` ma trwającą operację synchronizacji; Job w trybie
     `check` odmawia, gdy manifest ma migrację spoza listy klas migracji w obrazie `api` (snapshot nowszy niż wdrożona
     wersja);
  2. wypisuje snapshot (czas, host, tag, rewizja, liczby z manifestu) i skutki (w tym to, czy kopia `pre-restore` będzie
     pominięta), działa dopiero po słowie `tak`;
  3. robi kopię bieżącego stanu z tagiem `pre-restore`; jej błąd zatrzymuje `restore` bez zmian, chyba że użytkownik
     podał `PRE_RESTORE=skip` (wypisane w kroku 2);
  4. gdy w klastrze jest Argo CD (CRD `Application` i aplikacja `shop`), wyłącza automatyczną synchronizację `root`,
     potem `shop` (kolejność jak w runbooku cofnięcia wersji); zapamiętuje liczby replik, skaluje do zera `api`, `worker`,
     `store`, `keycloak`, wstrzymuje CronJoby `cart-purge-expired` i `backup`;
  5. Job w trybie `restore` (tylko operacje na danych): `restic restore` snapshotu na PVC `backup-work` (lustro obiektów
     staje się stanem snapshotu); `DROP DATABASE` i `CREATE DATABASE` `shop` i `keycloak` (zakończenie połączeń,
     właściciele jak w `10-create-databases.sh`), `pg_restore`; `rclone sync` z powrotem do bucketu `product-images`
     (z usuwaniem obiektów spoza snapshotu); czyści Redis (sesje sklepu) i kolejki RabbitMQ (wiadomości sprzed
     odtworzenia);
  6. ponawia istniejące Joby bieżącej rewizji tym samym mechanizmem co `rerun`: `migrate` (snapshot starszy niż kod),
     przywraca `keycloak` i ponawia `keycloak-config` (odtworzona baza cofa sekret klienta `store`, adresy, rejestrację,
     `verifyEmail` i SMTP do stanu snapshotu), potem `catalog:reindex` (Job z CronJoba `restore` w trybie `reindex`,
     obraz `api`); przywraca `api`, `worker`, `store` i CronJoby, czeka na rollout, ponawia `purge-caches`, a przy Argo CD
     włącza automatyczną synchronizację `root` (ten przywraca `shop`);
  7. błąd w krokach 4–6: wypisuje, na którym kroku stanął i jaki snapshot `pre-restore` powstał; nie włącza aplikacji
     z częściowo odtworzonymi danymi; runbook opisuje dokończenie.

  Ten sam cel służy odtworzeniu na żywym klastrze i na nowym serwerze po `bootstrap` z kopią klucza Sealed Secrets (te
  same sekrety i hasła baz; dane demo z `initialize` zastępuje odtworzenie, więc `initialize.enabled: false` nie jest
  potrzebne). `make -C infra/k8s backups KUBE_DIR=…` wypisuje snapshoty (identyfikator, czas, host, tag, rewizja) Jobem
  w trybie `list`, tylko odczyt. Agent uruchamia `restore` wyłącznie po zgodzie użytkownika w rozmowie; `backups` sam.
- **O5. Obserwowalność: chart `grafana/k8s-monitoring` 4.x jako aplikacja Argo CD.** Gotowe charty tylko dla platformy
  (DEPLOY, D2), jak Argo CD i Sealed Secrets: wersja chartu przypięta, obrazy z wartości domyślnych chartu (bez
  digestów, jak w aplikacji `sealed-secrets`). Nowe aplikacje w `argocd/apps/`:
  - `prometheus-crds` (chart `prometheus-operator-crds`, tylko CRD, m.in. `PrometheusRule`; `ServerSideApply=true`, bo
    CRD przekraczają limit adnotacji zwykłego apply), fala −3;
  - `monitoring` (chart `k8s-monitoring` przypięty wersją 4.x, `valuesObject` w manifeście, przestrzeń `monitoring` z
    `CreateNamespace=true`, `SkipDryRunOnMissingResource=true`), fala 1, po `shop`: Alloy bez kont albo z błędnym
    tokenem nie blokuje wdrożenia sklepu.

  Fale: `prometheus-crds` −3, `sealed-secrets` −2, `secrets` −1, `shop` 0, `monitoring` 1. Chart 4.x instaluje Alloy
  Operator (Deployment i CRD `collectors.grafana.com`), a kolektor jest zasobem `kind: Alloy`; operator tworzy z niego
  Deployment Alloy (`presets: [deployment]`), więc nie ma go w renderze. Funkcje: `clusterMetrics` (kube-state-metrics,
  node-exporter, kubelet, cAdvisor), `clusterEvents`, logi podów wszystkich przestrzeni (`podLogsViaKubernetesApi`). Cele
  `prometheus` (z `rules.enabled` i `rules.address` wskazującym bazowy adres rulera, nie adres zapisu) i `loki` w
  Grafana Cloud mają adresy i identyfikatory instancji w `valuesObject` manifestu `monitoring` (nie w wartościach
  `prod` chartu `shop`). Token jest w sekrecie `grafana-cloud` (klucz `GRAFANA_CLOUD_TOKEN`, jeden token access policy
  dla obu celów; SealedSecret w przestrzeni `shop` w `environments/prod/secrets/`, `seal-secrets WHAT=grafana`), który
  Alloy czyta z tej przestrzeni (`secret.create: false`, `secret.namespace: shop`, klucz hasła wskazany w celu). RBAC
  i polityki dopuszczania monitoringu są w manifeście (`extraObjects`), nie w chartach:
  - Alloy Operator działa tylko w przestrzeni `monitoring` (`ownNamespaceOnly: true`, `rbac.create: false`): bez ClusterRoli,
    z jedną Role w `monitoring` i bez uprawnień do obiektów RBAC; nie czyta ani nie zmienia sekretów żadnej innej przestrzeni.
    Role: zasób `Alloy` bez tworzenia i usuwania (zakłada go Argo CD; operator czyta go, zmienia, ustawia finalizer i status),
    Deployment, konto usługi i Service, które robi z niego chart Alloy (tworzenie dowolnych, zmiana i usuwanie tylko obiektów
    `monitoring-alloy` przez `resourceNames`, więc nie podmieni obrazu kube-state-metrics, node-exportera ani swojego), sekrety
    z wydaniami Helm, dzierżawa lidera i zdarzenia; bez DaemonSetów, StatefulSetów, ConfigMap i podów;
  - polityki dopuszczania (`ValidatingAdmissionPolicy` z powiązaniem na przestrzeń `monitoring` przez etykietę
    `kubernetes.io/metadata.name`, którą nadaje serwer API; `validationActions: [Deny]`, `failurePolicy: Fail`; fala −1
    w aplikacji, więc powstają przed operatorem i podami) obowiązują każdego, także Argo CD i administratora:
    `monitoring-no-host-access` odrzuca przy tworzeniu i zmianie pod (z kontenerami init i efemerycznymi) oraz szablon
    poda w Deployment, ReplicaSet, StatefulSet, DaemonSet, Job i CronJob z `hostNetwork`, `hostPID`, `hostIPC`,
    wolumenem `hostPath`, `hostPort`, kontenerem `privileged` albo dodaną capability spoza listy profilu baseline Pod
    Security Standards. Wyjątek ma tylko node-exporter: DaemonSet `monitoring-node-exporter`, jeśli nie zmienia go konto
    usługi z `monitoring`, i jego pody, jeśli tworzy je kontroler DaemonSetów (w k3s własne konto
    `system:serviceaccount:kube-system:daemon-set-controller`, którym nikt w `monitoring` nie działa) z `ownerReference`
    na ten DaemonSet; etykiety nie są wyróżnikiem. Zmiana poda bez zmiany `spec` (metadane) przechodzi.
    `monitoring-no-service-account-tokens` odrzuca sekret typu `kubernetes.io/service-account-token` (operator zapisuje
    sekrety w `monitoring` i tak dostałby token dowolnego konta tej przestrzeni);
  - kolektor (zasób `Alloy` z `rbac.create: false`, konto usługi `monitoring-alloy`) ma własną ClusterRole tylko do odczytu
    (`get/list/watch`: węzły i `nodes/metrics`, usługi, endpointy, pody i `pods/log`, przestrzenie, zdarzenia,
    `PrometheusRule`), bez `secrets` i ConfigMap, oraz osobną Role w `shop` z `get/list/watch` tylko na
    `resourceNames: [grafana-cloud]`; Alloy nie czyta `shop-backup` ani `shop-secrets`;
  - żadna rola monitoringu nie ma `bind`, `escalate`, `impersonate`, zmian obiektów RBAC ani podzasobów `pods/exec`,
    `pods/attach`, `pods/portforward`, `pods/ephemeralcontainers`, `serviceaccounts/token` i `nodes/proxy`.

  Metryki ograniczone listą dozwolonych, żeby zmieścić się w darmowym planie (10 tys. serii aktywnych).
  `check`: lista nie jest pusta i ma metryki alertów; RBAC renderu `monitoring` jak wyżej (dostęp do `secrets` daje tylko
  Role na `grafana-cloud` w `shop`, związana tylko z kontem Alloy, i Role operatora w `monitoring`, związana tylko z jego
  kontem; podmiot powiązania to także grupa kont usług i nazwa użytkownika konta; dokładny kształt Role operatora);
  polityki dopuszczania jak wyżej (przestrzeń, `Deny`, `Fail`, zasoby i operacje, fala przed resztą aplikacji, wyjątek tylko
  dla node-exportera, w renderze dostęp do hosta ma tylko jego DaemonSet); budżet węzła liczy razem `shop` i `monitoring` (`spec.resources` zasobów `Alloy`,
  Deployment operatora, kube-state-metrics, DaemonSet node-exportera); `kubeconform` zna schemat `Alloy` (CRDs-catalog).
- **O6. Alerty jako kod.** Reguły to `PrometheusRule` w chartcie `shop` (`templates/alerts.yaml`), renderowane, gdy
  `alerts.enabled` (`prod: true`, `ci: false`, bo smoke test nie ma CRD); brak CRD w klastrze z `alerts.enabled` daje
  widoczny błąd synchronizacji, nie ciche zniknięcie reguł. Alloy synchronizuje je do rulera Grafana Cloud. Metryki
  z kube-state-metrics: `kube_cronjob_status_last_successful_time`, `kube_job_status_condition` (warunek `Failed`)
  i `kube_job_owner` (Job należy do CronJoba o dokładnej nazwie, więc `backup` nie łapie `backup-verify`). Reguły:
  - `BackupFailed`: Job CronJoba `backup` z warunkiem `Failed` (wyczerpane ponowienia), nowszy niż ostatnia udana kopia;
    nieudany pod, po którym ponowienie się udało, nie odpala; po kolejnej udanej kopii alert gaśnie;
  - `BackupMissing`: ostatnia udana kopia starsza niż 26 h albo brak metryki przez 30 min (`absent`; ruler działa
    w Grafana Cloud, więc alert przychodzi także, gdy serwer albo Alloy nie żyje);
  - `BackupVerifyFailed`, `BackupVerifyMissing`: to samo dla `backup-verify`;
  - `NodeDiskFull`: system plików węzła (node-exporter, `node_filesystem_avail_bytes` i `node_filesystem_size_bytes`,
    bez systemów tymczasowych) zajęty w ponad 85% przez 10 min;
  - `ClusterSilent`: brak metryk z klastra przez 15 min (`absent` metryki, którą kube-state-metrics wysyła zawsze, np.
    `kube_node_info`);
  - `WorkloadNotReady`: przez 10 min Deployment w przestrzeni `shop` ma mniej dostępnych replik niż oczekiwanych
    (`kube_deployment_status_replicas_available` < `kube_deployment_spec_replicas`) albo StatefulSet mniej gotowych niż
    oczekiwanych (`kube_statefulset_status_replicas_ready` < `kube_statefulset_replicas`), w tym Redis; albo
    oczekiwanego workloadu nie ma: szablon reguły generuje `absent` metryki każdego Deploymentu i StatefulSetu, który
    renderuje chart (lista z tego samego renderu, więc nowy workload dostaje regułę sam), przy obecnych metrykach
    klastra (`kube_node_info`), żeby cisza całego klastra była tylko `ClusterSilent`. Workload świadomie zeskalowany do
    zera (`restore`) nie odpala części o replikach.

  Powiadomienia: punkt kontaktowy e-mail w Grafana Cloud (krok runbooka „użytkownik”). Świadomy `restore` i planowane
  prace wyciszamy ręcznie w Grafana Cloud (silence na czas prac, procedura w runbooku), bez automatycznego mechanizmu.
  Reguły mają testy `promtool test rules` (obraz `prom/prometheus` przypięty digestem) w `check`: każdy alert odpala
  w swoim scenariuszu i milczy, gdy wszystko jest zdrowe; alerty kopii milczą też, gdy ponowienie po nieudanym podzie się
  udało i gdy po wczorajszym błędzie dziś jest sukces; `NodeDiskFull` milczy przy 85% i krótkim skoku;
  `WorkloadNotReady` odpala przy niedostępnej replice i przy zniknięciu workloadu, milczy przy niedostępności krótszej niż
  10 min, przy workloadzie zeskalowanym do zera i przy ciszy całego klastra.
- **O7. Logi bez sekretów i danych klientów.** Alloy przetwarza każdą linię przed wysłaniem: te same etapy
  (`extraLogProcessingStages`) dla logów podów i zdarzeń klastra (`clusterEvents`), reguły odkrywania
  (`extraDiscoveryRules`) w `valuesObject` aplikacji `monitoring`, jedno źródło reguł. Zasady:
  - maskowanie we wszystkich źródłach (zamiana na `[REDACTED]`): wartości nagłówków `Authorization`, `Cookie`,
    `Set-Cookie`; tokeny `Bearer` i JWT (`eyJ…`); wartości kluczy JSON i parametrów zapytań o nazwach z listy
    (`password`, `passwd`, `secret`, `token`, `access_token`, `refresh_token`, `id_token`, `code`, `session`,
    `client_secret`, `api_key`, `authorization`, `cookie`); adresy e-mail; adresy IPv4 i IPv6 (adres klienta to dana
    osobowa; adresy podów też giną, to akceptowalny koszt); parametry zapytań SQL w komunikatach Doctrine (`with params
    […]`), bo zawierają dane zamówień i adresy;
  - linie Postgresa `DETAIL:` i `STATEMENT:` są odrzucane (wartości kluczy i treść zapytań z danymi klientów);
  - wyłączone źródła (nie da się zagwarantować maskowania): Mailpit (adresaci i tematy wiadomości); lista wyłączonych
    jest w regułach odkrywania i w teście;
  - nowe źródło bez osobnej reguły dostaje maskowanie ogólne; wyłączenie kolejnego źródła to dopisanie go do listy.

  Test w `check` uruchamia Alloy (obraz w wersji, którą instaluje przypięty chart, przypięty digestem) z etapami
  wyciągniętymi z manifestu `monitoring` na korpusie próbek logów każdego źródła w ich formacie (JSON Monologa `api`
  z wyjątkiem Doctrine, zdarzenie logowania Keycloaka, błąd Postgresa z `DETAIL`, log `store`, dostęp nginx panelu,
  imgproxy, zdarzenie klastra) z podłożonymi sekretami, e-mailami, adresami i IP, i sprawdza na wyjściu (`loki.echo`), że
  żadna podłożona wartość nie przeszła, a treść potrzebna do diagnozy (poziom, komunikat, kanał, kod odpowiedzi)
  została.

## Przepływ danych

```
CronJob backup (03:00)        Postgres (shop, keycloak) --pg_dump-->  PVC backup-work  --restic-->  R2 (repozytorium)
                              Garage product-images --rclone sync-->
CronJob backup-verify (05:00) R2 --restic check / dump--> tymczasowy Postgres w podzie
Alloy (monitoring)            logi podów i zdarzenia (maskowane), metryki, PrometheusRule --> Grafana Cloud --> e-mail
make restore SNAPSHOT=…       R2 --restic restore--> PVC backup-work --> Postgres, Garage; Joby migrate,
                              keycloak-config, reindeks, purge-caches
```

## Konfiguracja

- `values.yaml` (domyślnie): `backup.enabled: false`, `backup.schedule: "0 3 * * *"`, `backup.verifySchedule: "0 5 * *
  *"`, `backup.timeZone: Europe/Warsaw`, `backup.retention: 7d`, `backup.repository: ""`, `backup.s3Region: ""`,
  `backup.secretName: ""`, `backup.secretValues: {}`, `backup.verifyReadData: subset`, `storage.backupWork: 25Gi`, zasoby
  `backup`, `alerts.enabled: false`.
- `environments/prod/values.yaml`: `backup.enabled: true`, adres repozytorium i region (wypełnia użytkownik według
  runbooka przed pierwszym wdrożeniem z kopiami; do tego czasu wartość przykładowa jak `global.domain`),
  `backup.secretName: shop-backup`, `alerts.enabled: true`.
- `environments/ci/values.yaml`: `backup.enabled: true`, repozytorium w osobnej instancji S3 smoke testu (poza
  przestrzenią `shop`), sekret z `backup.secretValues` (`secrets.create`), `backup.verifyReadData: all`, mniejsze
  zasoby, `alerts.enabled: false`.

## Smoke test i check

- `make -C infra/k8s check` dodatkowo: render `backup`, `backup-verify`, `restore` (obrazy z digestem, sekret
  `shop-backup`, token API tylko w initContainerze identyfikatora klastra, kontenery z sekretem `shop-backup` bez
  tokenu, limity czasu Jobów), schemat (`backup.enabled` bez repozytorium albo sekretu: błąd), render `alerts` dla
  `prod`, `seal-secrets` dla `backup` i `grafana` (ścieżka błędu bez zapisu, drugi przebieg niczego nie pyta), manifesty
  `prometheus-crds` i `monitoring` (`kubeconform`, fale jak w O5, `ServerSideApply` przy CRD,
  `SkipDryRunOnMissingResource` przy `monitoring`, ta sama rewizja), render chartu `k8s-monitoring` z wartościami
  z manifestu (`helm template` przypiętej wersji, `kubeconform` ze schematem `Alloy`, RBAC sekretów jak w O5),
  `promtool check rules` i `promtool test rules`, test maskowania logów (O7), budżet zasobów `shop` + `monitoring`
  (O5); skrypty kopii i weryfikacji na atrapach `restic`, `rclone`, `pg_dump`, `pg_restore` (błąd narzędzia kończy Job
  błędem i nie tworzy snapshotu; uszkodzone repozytorium i nieodtwarzalny dump kończą `backup-verify` błędem);
  skrypty `restore` i `backups` na atrapie `kubectl` (odmowa bez `tak`, bez `SNAPSHOT`, przy aktywnym Jobie i trwającej
  synchronizacji, przy snapshocie nowszym niż kod przed kopią `pre-restore` i przed skalowaniem; nieudana kopia
  `pre-restore` bez `PRE_RESTORE=skip` niczego nie zmienia; kolejność kroków z Argo CD i bez; błąd w kroku 5 nie
  włącza aplikacji). `selftest` wstrzykuje: repozytorium bez hasła, CronJob kopii bez `concurrencyPolicy: Forbid`,
  `BackupMissing` bez `absent`, `restore` bez potwierdzenia, usunięcie reguły maskowania e-maili, Role Alloy na wszystkie
  sekrety, przywrócony RBAC chartu operatora (ClusterRole), Role operatora ze zmianą obiektów RBAC, operator obserwujący cały
  klaster, zasób `Alloy` z własnym RBAC, usunięcie polityki dostępu do hosta albo powiązania polityki tokenów, polityka tylko
  ostrzegająca (`Warn`) albo przepuszczająca przy błędzie (`Ignore`), polityki w fali workloadów, wyjątek node-exportera po
  etykietach, Role operatora z DaemonSetami albo ze zmianą Deploymentu kube-state-metrics, ClusterRole Alloy z `nodes/proxy`,
  sekret `grafana-cloud` dla grupy kont usług.
- `make -C infra/k8s monitoring-smoke` (poza `check` i CI): chart `k8s-monitoring` z wartościami manifestu na pustym klastrze
  k3d, z jedyną nakładką na adresy celów (odbiornik Alloy poza klastrem); operator i Alloy gotowe, odbiornik dostaje
  zamaskowaną linię poda w `shop`, zdarzenie klastra i metryki kube-state-metrics, node-exportera, kubeleta i cAdvisora, logi
  bez `forbidden`; `kubectl auth can-i` dla kont usług operatora, Alloy, kube-state-metrics i node-exportera potwierdza RBAC
  z O5 (brak sekretów `shop` i `kube-system`, `bind`, `escalate`, `impersonate`, `exec`, `attach`, `portforward`, kontenerów
  efemerycznych i tokenów kont w `shop` i `kube-system`, `nodes/proxy`; Alloy czyta `grafana-cloud`; operator bez DaemonSetów,
  StatefulSetów, podów i tworzenia zasobów `Alloy`, zmienia tylko obiekty `monitoring-alloy`). Polityki dopuszczania:
  żądania `--dry-run=server` jako konto operatora (Deployment z `hostPath` `/`, `hostNetwork`, `hostPID`, `hostIPC`,
  `hostPort`, `privileged`, dodaną capability, uprzywilejowanym kontenerem init, etykietami node-exportera, szablonem
  z `ownerReference` na jego DaemonSet; sekret z tokenem konta) i jako administrator (pod z etykietami albo podrobionym
  `ownerReference` node-exportera, inne rodzaje workloadów, kontener efemeryczny i inny obraz w podzie node-exportera,
  sekret z tokenem) odrzuca polityka (jej nazwa w błędzie, nie RBAC), a zwykły Deployment, zwykły kontener efemeryczny,
  sekret `Opaque` i pod z `hostPath` w `shop` przechodzą; potem wszystkie pody `monitoring` powstają od nowa pod politykami
  i są gotowe (w tym node-exporter), a metryki przychodzą po tym. `MONITORING_SMOKE_BREAK=operator-cluster-role` (RBAC chartu
  operatora) i `MONITORING_SMOKE_BREAK=no-admission-policy` (bez polityk) muszą się nie udać.
- `make -C infra/k8s smoke` dodatkowo, po sprawdzeniach i `create-admin` w fazie r1 (baza ma produkty, zamówienia z
  `checks` i klienta z hasłem), przed fazą r2:
  1. osobna instancja S3 (Garage z obrazu chartu) w przestrzeni `smoke-backup`, z bucketem na repozytorium;
  2. Job z CronJoba `backup`: snapshot `nightly` z dumpami, obiektami i manifestem;
  3. Job z CronJoba `backup-verify` (`verifyReadData: all`): sukces;
  4. odtworzenie na pustym klastrze: usunięcie przestrzeni `shop` z PVC, instalacja od nowa, oczekiwanie na zakończenie
     Jobów (dane demo z `initialize`), `make restore` z identyfikatorem snapshotu z kroku 2 (ścieżka bez Argo CD);
     sprawdza: administrator z `create-admin` jest w realmie `backoffice`, logowanie klienta sklepu działa (sekret
     klienta `store` po `keycloak-config`), liczby produktów i zamówień jak przed kopią, zdjęcie produktu przez imgproxy,
     produkt w wyszukiwarce (reindeks), strona główna sklepu, snapshot `pre-restore` w repozytorium;
  5. `restore` ze snapshotem z migracją nieznaną kodowi (snapshot z manifestem z dodatkową wersją) kończy się odmową bez
     zmian danych i bez wyłączania aplikacji.

  Dalsze fazy (r2–r4) działają na odtworzonym klastrze. Smoke test wypisuje czas faz kopii i odtworzenia oraz szczytowe
  zużycie dysku.
- Alloy i Grafana Cloud nie działają w smoke teście (brak kont w CI); sprawdza je `check` (render, maskowanie, reguły)
  i runbook (serwer).

## Runbook (`infra/k8s/README.md`)

Nowe kroki, z oznaczeniami jak w DEPLOY:

- **użytkownik**: Cloudflare R2 na koncie Cloudflare (bucket, token API R2 z dostępem tylko do tego bucketu), hasło
  restic w menedżerze haseł; konto Grafana Cloud (stos w regionie UE, retencja logów 14 dni), token access policy
  (`metrics:write`, `logs:write`, `rules:read`, `rules:write`), punkt kontaktowy e-mail dla alertów.
- **agent**: adres i region repozytorium w `environments/prod/values.yaml`, adresy i identyfikatory instancji Grafana
  Cloud w `argocd/apps/monitoring.yaml` (PR); `seal-secrets WHAT="backup grafana"` uruchamia **użytkownik** (wartości
  z terminala).
- **agent**: po wdrożeniu: ręczna kopia (Job z CronJoba `backup`), `backup-verify`, `make backups`; w Grafana Cloud
  widać logi i metryki klastra, reguły alertów są w rulerze. Do pierwszej udanej kopii `BackupMissing` odpala z
  `absent` (oczekiwane).
- **agent**: po wdrożeniu przegląd próbki logów w Grafana Cloud pod kątem sekretów i danych klientów; znalezisko to
  nowa reguła maskowania albo wyłączenie źródła (O7).
- **użytkownik**: przed świadomym `restore` albo planowanymi pracami wycisza alerty w Grafana Cloud (silence z
  dopasowaniem do reguł `WorkloadNotReady`, a przy `restore` także alertów kopii, na czas prac z zapasem); po pracach
  sprawdza, że wyciszenie wygasło albo je usuwa.
- **zgoda użytkownika**: ćwiczenie odtworzenia na serwerze (`make restore` z identyfikatorem świeżego snapshotu), wynik
  w komentarzu do zgłoszenia; scenariusz utraty serwera: nowy VPS, `bootstrap` z kopią klucza Sealed Secrets,
  `make backups`, `restore` snapshotu starego serwera (inny host w liście).
- Sekcja „Eksploatacja”: cofnięcie o więcej niż jedną wersję przez `restore`; reakcja na alerty kopii (log Joba przez
  `status`, ponowienie Jobem z CronJoba); snapshoty starego serwera po odtworzeniu na nowym nie wygasają same
  (`restic forget --host <stary>` po decyzji użytkownika); zamiana zdania „backup VPS-a od OVH to jedyna kopia” na stan
  po DEPLOY-OPS.

## Scenariusze błędów

| Sytuacja | Zachowanie |
|---|---|
| R2 niedostępny albo zły klucz | Job `backup` kończy się błędem; alert `BackupFailed`; poprzednie snapshoty nietknięte |
| Postgres albo Garage niedostępny w czasie kopii | błąd przed `restic backup`, brak niepełnego snapshotu; `BackupFailed` |
| Pod kopii padł, ponowienie się udało | snapshot jest, alert milczy |
| Zawieszony `pg_dump` albo `rclone` | Job przerwany po `activeDeadlineSeconds`; `BackupFailed` |
| CronJob się nie uruchomił (węzeł wyłączony, zablokowany) | `BackupMissing` po 26 h od ostatniej kopii |
| Serwer albo Alloy nie żyje | metryk brak, `BackupMissing` z `absent` po 30 min, `ClusterSilent` po 15 min |
| Przerwana kopia zostawiła blokadę restic | następna kopia zdejmuje nieaktualne blokady (`restic unlock`) |
| Kopia i weryfikacja (albo `pre-restore`) jednocześnie | druga czeka na blokadę (`--retry-lock 30m`) zamiast fałszywego błędu |
| Uszkodzone dane w repozytorium | `backup-verify` kończy się błędem (w ciągu tygodnia przy odczycie podzbioru); `BackupVerifyFailed` |
| Dump nie odtwarza się | `backup-verify` kończy się błędem z wyjściem `pg_restore` |
| Nowy serwer po awarii robi nocne kopie danych demo | snapshoty mają inny host, retencja nowego klastra nie usuwa kopii starego |
| `restore` bez `tak` albo bez `SNAPSHOT` | nic się nie zmienia, kod różny od 0 |
| `restore` przy aktywnym Jobie w `shop` albo trwającej synchronizacji `shop` | odmowa bez zmian |
| Snapshot nowszy niż wdrożony kod | odmowa przed potwierdzeniem, kopią `pre-restore` i skalowaniem; aplikacje działają |
| Nieudana kopia `pre-restore` | `restore` kończy się bez zmian, chyba że `PRE_RESTORE=skip` |
| Błąd w trakcie odtwarzania | aplikacje zostają wyłączone, komunikat z krokiem i snapshotem `pre-restore`; runbook |
| Utrata hasła restic | kopie nie do odczytania; dlatego hasło jest też u użytkownika |
| Dysk węzła zajęty w ponad 85% przez 10 min | `NodeDiskFull` |
| Redis, API albo inny workload `shop` niegotowy albo usunięty przez 10 min | `WorkloadNotReady` |
| Świadomy `restore` albo prace | alerty wyciszone ręcznie w Grafana Cloud (runbook) |
| Linia logu z hasłem, tokenem, e-mailem albo IP | wartość zamaskowana w Alloy przed wysłaniem; linie Mailpita i `DETAIL` Postgresa nie wychodzą |
| Brak kont albo błędny token Grafana Cloud | aplikacja `monitoring` `Degraded` (fala 1, po `shop`); sklep działa i się wdraża |
| Brak CRD `PrometheusRule` przy `alerts.enabled` | synchronizacja `shop` czerwona z błędem (nie ciche zniknięcie alertów) |
| Przekroczony limit darmowego planu Grafana Cloud | Grafana Cloud odrzuca nadmiar; lista dozwolonych metryk i pomiar po wdrożeniu (runbook) |

## Kryteria akceptacji

1. `make -C infra/k8s check` przechodzi z nowymi testami; każdy błąd z `selftest` kończy go błędem.
2. `make -C infra/k8s smoke` przechodzi lokalnie i na `ubuntu-latest`: kopia, weryfikacja, odtworzenie na pustym
   klastrze z wynikami jak przed kopią (także logowanie klienta), odmowa snapshotu nowszego niż kod bez wyłączania
   aplikacji; z zapisanym czasem i zużyciem dysku.
3. `promtool test rules`: każdy z siedmiu alertów odpala w swoim scenariuszu i milczy, gdy wszystko jest zdrowe; alerty
   kopii milczą przy udanym ponowieniu i po powrocie do zdrowia; `WorkloadNotReady` odpala przy niedostępnej replice
   i brakującym workloadzie, a milczy przy krótkiej niedostępności, zeskalowaniu do zera i ciszy klastra; reguła ma
   `absent` dla każdego Deploymentu i StatefulSetu z renderu `prod`.
4. Test filtrowania logów (O7): żadna podłożona wartość (hasło, token, JWT, nagłówek `Authorization`, ciasteczko,
   e-mail, IP, parametry Doctrine, `DETAIL` Postgresa) nie wychodzi z Alloy ani z logów podów, ani ze zdarzeń klastra,
   linie Mailpita nie wychodzą wcale, a poziom, komunikat i kod odpowiedzi zostają.
5. Skrypty kopii, weryfikacji, `restore` i `backups` przechodzą testy na atrapach, także na ścieżkach błędu (błąd
   narzędzia, uszkodzone repozytorium, bez `tak`, bez `SNAPSHOT`, aktywny Job, snapshot nowszy niż kod, nieudana kopia
   `pre-restore`, błąd Joba odtworzenia), z Argo CD i bez.
6. Render z `backup.enabled` bez repozytorium albo sekretu kończy się błędem z nazwą wartości; `seal-secrets` dla
   `backup` i `grafana` nie zapisuje niczego po błędzie; Alloy ma dostęp tylko do sekretu `grafana-cloud`.
7. Lokalny `make up`, `make check`, `make test` i `make e2e` działają bez zmian (compose nie ma kopii ani Alloy).
8. Dokumentacja opisuje stan po epiku (sekcja „Zadania dokumentacji”).

## Zadania dokumentacji (razem z implementacją)

- `infra/k8s/README.md`: kroki z sekcji „Runbook”.
- `CLAUDE.md`: cele `restore` i `backups` (agent uruchamia `restore` tylko po zgodzie), nowe aplikacje Argo i fale,
  sprawdzenia `check` i smoke, `seal-secrets` z `backup` i `grafana`.
- `docs/vision.md`: DEPLOY-OPS jako stan środowiska (kopie w R2, Grafana Cloud z logami i metrykami, bez punktu
  w „Kolejne kroki”); trace'y przez Alloy przeniesione do #180 (zdanie o trace'ach w „Wymaganiach niefunkcjonalnych”
  i opis DEPLOY-OPS); gotowe charty platformy poszerzone o `k8s-monitoring` i `prometheus-operator-crds`.
- `docs/features/deploy/spec.md`: odwołania do DEPLOY-OPS zamienione na stan obecny (sekcje „Kopie zapasowe”, „Dane
  startowe” bez zapowiedzi `initialize.enabled: false` dla odtwarzania, „Wdrożenie nowej wersji”, „Ryzyka”, fale
  aplikacji Argo, D2 z nowymi chartami platformy).
- `docs/features/hardening/spec.md`: D7 opisuje rzeczywisty sygnał awarii Redisa: alert `WorkloadNotReady`
  (StatefulSet Redisa niegotowy przez 10 min) i ostrzeżenia kanału `cache` w logach Grafana Cloud bez alertu; alerty
  z treści logów są poza zakresem.
- `docs/adr/k3s-argo-cd.md`: konsekwencje o kopiach; nowy `docs/adr/backups-observability.md` (restic do Cloudflare R2
  poza serwerem, Grafana Cloud z Alloy zamiast własnego Prometheusa i Lokiego, maskowanie logów w Alloy, alerty jako
  `PrometheusRule`).

## Wymagane przed startem

- Epik HARDENING (#267) zmergowany do `main`: zmienia te same katalogi (`infra/k8s`, `docs/features/deploy/spec.md`),
  a `docs/features/hardening/spec.md` musi opisywać stan po nim (źródło: lista nocy `/ao-run` z linii `Zakres:` nie
  uruchomi obu epików naraz; kolejność na tablicy: HARDENING przed DEPLOY-OPS).

Poza tym nic: zadania weryfikuje się bez serwera i bez kont zewnętrznych. Obrazy restic, rclone, Prometheusa (promtool)
i Alloy oraz charty `k8s-monitoring` i `prometheus-operator-crds` są publiczne (istnieje: Docker Hub, GHCR, repozytoria
Helm), a smoke test ma własną instancję S3. Konta R2 i Grafana Cloud oraz wartości sekretów to kroki runbooka
„użytkownik” po merge'u epiku, razem z pierwszym wdrożeniem (#297).

## Ryzyka

- **Kopie do usunięcia z przejętego serwera.** Klucz R2 w klastrze pozwala usunąć kopie. Ograniczenie: token tylko do
  jednego bucketu. Kopie niezmienne (Object Lock) są poza zakresem demo; decyzja przed uruchomieniem publicznym.
- **Maskowanie logów oparte na wzorcach.** Dane klienta w nieprzewidzianym formacie (np. imię i adres w treści
  wyjątku poza parametrami Doctrine) mogą przejść. Ograniczają to korpus testowy, przegląd próbki logów po wdrożeniu
  (runbook) i zasada wyłączania źródła do czasu poprawki.
- **Zakres smoke testu.** Odtworzenie w smoke teście idzie bez Argo CD; wyłączanie synchronizacji sprawdzają testy na
  atrapach, a na serwerze ćwiczenie z runbooka. Synchronizacji CRD, Alloy Operatora i `k8s-monitoring` przez Argo CD nie
  sprawdza nic przed serwerem.
- **Czas smoke testu.** Kopia, weryfikacja i ponowna instalacja wydłużają go o kilka minut na runnerze blisko limitów;
  kryterium 2 to mierzy.
- **Darmowy plan Grafana Cloud** (10 tys. serii, 50 GB logów, 14 dni retencji): domyślne metryki `k8s-monitoring` mogą
  go przekroczyć; lista dozwolonych i pomiar po wdrożeniu.
- **Budżet węzła.** Limity `shop` w `prod` to ok. 17 GiB z 20; Alloy, operator, kube-state-metrics i node-exporter
  dochodzą do tego; `check` liczy budżet łącznie.
- **Skala.** `pg_dump` i weryfikacja dumpu rosną z bazą; przy docelowej skali (`docs/vision.md`) potrzebne będą kopie
  fizyczne z WAL (PITR). Przy demo i pierwszych danych wystarcza dump.
- **Spójność między bazami i obiektami.** Dumpy `shop` i `keycloak` oraz kopia obiektów powstają w różnych chwilach
  (sekundy–minuty); zdjęcie dodane w tym czasie może nie mieć wiersza albo odwrotnie. Nocna pora i krótki czas kopii
  ograniczają to; odtworzenie nie wymaga ścisłej spójności.
- **Operator to obcy obraz z prawem tworzenia Deploymentów w `monitoring`.** Polityki dopuszczania odcinają go od hosta
  i od tokenów kont w sekretach, a RBAC od sekretów `shop-secrets` i `shop-backup`, ale przejęty operator nadal może
  uruchomić w `monitoring` dowolny obraz z kontem usługi tej przestrzeni: z kontem Alloy czyta logi podów i zdarzenia całego
  klastra oraz token `grafana-cloud`, z kontem kube-state-metrics listę obiektów klastra (bez sekretów i ConfigMap); może też
  zatrzymać zbieranie (zmienić albo usunąć Alloy) i bez limitów przestrzeni zająć zasoby węzła. Istniejących Deploymentów
  kube-state-metrics, node-exportera i swojego nie zmieni.
- **Zależności w ruchu.** Interfejs chartu `k8s-monitoring` zmienia się między głównymi wersjami (operator od 3.x,
  `destinations` w 4.x); wersja przypięta, aktualizacja to osobna zmiana.
