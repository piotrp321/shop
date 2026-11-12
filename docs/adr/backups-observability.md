# Kopie zapasowe poza serwerem (restic do Cloudflare R2) i obserwowalność (Grafana Cloud)

## Kontekst

Sklep działa na jednym serwerze (`docs/adr/k3s-argo-cd.md`): dane (bazy PostgreSQL `shop` i `keycloak`, obiekty Garage ze
zdjęciami produktów) leżą na wolumenach tego serwera. Backup VPS-a od OVH jest tylko dodatkiem: leży u tego samego
dostawcy, a jego przywrócenie cofa cały serwer. Potrzebna jest kopia danych sklepu poza serwerem, szyfrowana, tania i
odtwarzalna na pustym klastrze. Logi i metryki klastra muszą być poza nim: serwer, który padł, nie opowie o sobie. Własny
Prometheus i Loki to usługi do utrzymania na tym samym serwerze, więc nie pomagają przy jego awarii. Specyfikacja:
`docs/features/deploy-ops/spec.md` (O1, O2, O3, O4, O5, O6, O7).

## Decyzja

- **Kopia to CronJob chartu `shop`** (`backup`, codziennie o 03:00 `Europe/Warsaw`), na oficjalnych obrazach narzędzi
  (`restic/restic`, `rclone/rclone`, `postgres` z chartu w tej samej wersji co serwer), z tagiem i digestem jak inne obrazy
  usług. Bez własnego obrazu kopii i bez zmian w pipeline.
- **`pg_dump` obu baz (format custom) i `rclone sync` bucketu `product-images` przez API S3 Garage**, a potem jeden snapshot
  restic z dumpami, lustrem obiektów i `manifest.json` (rewizja, migracje Doctrine, liczba obiektów). Dump logiczny zamiast
  kopii fizycznej: przy obecnej skali jest prosty i przenośny między wersjami; odtwarzanie do punktu w czasie (WAL) wymaga
  osobnej decyzji przy docelowej skali.
- **Repozytorium restic w Cloudflare R2**: osobny dostawca niż VPS, 10 GB bez opłat, bez opłat za transfer wychodzący.
  Restic szyfruje i deduplikuje, więc nocny koszt rośnie ze zmianami, nie z rozmiarem danych. Retencja 7 dni
  (`forget --keep-within 7d --prune` po każdej kopii `nightly`; kopia `pre-restore` przed odtworzeniem niczego nie przycina).
- **Host snapshotów to identyfikator klastra** (UID przestrzeni `kube-system`), a `forget` działa tylko dla tego hosta:
  nowy serwer po awarii robi własne kopie i nie usuwa kopii starego. UID czyta pierwszy kontener poda (jedyny z tokenem API,
  ClusterRole z samym `get` na `namespaces/kube-system`); kontenery z sekretem repozytorium nie mają tokenu.
- **Błąd dowolnego kroku przed `restic backup` kończy Job bez snapshotu**; repozytorium zakłada się samo przy pierwszej
  kopii, a każdy inny błąd dostępu do niego jest błędem.
- **Weryfikacja to osobny CronJob `backup-verify`** (codziennie o 05:00), który czyta z S3, nie z produkcyjnej bazy: kopia,
  której nikt nie sprawdził, jest nadzieją. Sprawdza wiek najnowszego snapshotu `nightly` tego klastra (poniżej 26 h),
  `restic check` z odczytem podzbioru danych (`--read-data-subset=n/7`, n to dzień tygodnia, więc w tygodniu odczytuje
  każde dane raz; `all` w profilu `ci`), odtworzenie obu dumpów do tymczasowego Postgresa w tym samym podzie
  (`restic dump` strumieniowo do `pg_restore`: dumpy i obiekty nie trafiają na dysk, a odtworzone bazy leżą na `emptyDir` z limitem równym wolumenowi produkcyjnego Postgresa; tabela migracji `shop` niepusta, realmy `shop`
  i `backoffice` w `keycloak`) i zgodność liczby obiektów (`restic ls`) z manifestem. Osobny CronJob zamiast kroku
  kopii: czyta repozytorium tak, jak odczyta je odtworzenie, a jego wynik i brak można alertować niezależnie od kopii. Tymczasowy
  Postgres to natywny sidecar poda (`initContainers` z `restartPolicy: Always`, dane na `emptyDir`, tylko pętla zwrotna),
  więc Job kończy się razem ze skryptem; `restic` pochodzi z obrazu `restic/restic` zamontowanego jako wolumen obrazu
  w kontenerze z obrazem `postgres`, który ma `pg_restore`. Pod używa konta usługi i roli kopii (identyfikator klastra);
  sekret `shop-backup` czyta tylko kontener skryptu, bez tokenu, a sekretów produkcyjnej bazy nie ma żaden.
- **Odtworzenie to cel `make -C infra/k8s restore SNAPSHOT=<id>` na Jobach z wstrzymanego CronJoba `restore` chartu**
  (`suspend: true`, `activeDeadlineSeconds: 10800`): pod ma pełną specyfikację odtworzenia w obrazach i z sekretami wdrożonej
  rewizji, a skrypt celu (ten sam w smoke teście i na serwerze, przez kubeconfig z `KUBE_DIR` jak `rerun`) tworzy z niego Joby w
  trybach `check`, `restore`, `reindex` i `list` (`make backups`) przez `kubectl create job --from` i `kubectl set env --local`,
  bez dodatkowych obiektów. Szablon w chartcie zamiast manifestu w skrypcie: obrazy, sekrety i adresy usług zmieniają się razem
  z chartem, a skrypt nie zna żadnego z nich. Kontrola migracji (klasy migracji obrazu `api` wobec wersji z manifestu snapshotu)
  odmawia snapshotu nowszego niż wdrożony kod przed jakąkolwiek zmianą; starszy snapshot doprowadzają do bieżącej wersji
  ponowione Joby `migrate` i `keycloak-config`. Przed odtworzeniem powstaje snapshot `pre-restore` stanu bieżącego (jego
  błąd zatrzymuje odtworzenie, chyba że `PRE_RESTORE=skip`), a na czas odtworzenia wyłączona jest automatyczna
  synchronizacja Argo CD (`root`, potem `shop`), wstrzymane CronJoby `cart-purge-expired` i `backup` (potem odtworzenie czeka na Job, który zdążył wystartować po
  pierwszym sprawdzeniu, bo działałby na tym samym wolumenie `backup-work`), a `api`, `worker`, `store` i `keycloak`
  zeskalowane do zera. Odtworzenie danych: `restic restore` na wolumen `backup-work` (`--delete`: lustro
  obiektów staje się stanem snapshotu), `DROP DATABASE … WITH (FORCE)` i `CREATE DATABASE` z właścicielami jak w
  `10-create-databases.sh`, `pg_restore --role=<właściciel>`, `rclone sync` do bucketu (obiekty spoza snapshotu znikają),
  `FLUSHALL` Redisa (sesje sklepu) i opróżnienie kolejek RabbitMQ (wiadomości sprzed odtworzenia), potem `catalog:reindex`
  (indeks wyszukiwarki jest pochodny i nie ma kopii) i `purge-caches`. Błąd po pierwszej zmianie zostawia aplikacje
  wyłączone: lepszy wyłączony sklep niż sklep na częściowo odtworzonych danych.
- **Dostęp: jeden sekret `shop-backup`** (`RESTIC_PASSWORD`, klucz i sekret S3), SealedSecret tworzony raz przez
  `make -C infra/k8s seal-secrets WHAT=backup`; adres repozytorium i region to wartości `environments/prod`.

- **Obserwowalność: Grafana Cloud (darmowy plan, stos w UE, logi 14 dni) z Grafana Alloy zamiast własnego Prometheusa i
  Lokiego.** Alloy (chart `k8s-monitoring` 4.x przypięty wersją, aplikacja Argo CD `monitoring` w ostatniej fali, po `shop`)
  wysyła logi podów wszystkich przestrzeni (przez API Kubernetesa), zdarzenia klastra i metryki z listy dozwolonych (lista
  domyślna chartu dla pulpitów Kubernetes plus metryki alertów), żeby zmieścić się w 10 tys. serii planu. CRD Prometheusa
  (`prometheus-operator-crds`) to osobna aplikacja w pierwszej fali, tylko CRD bez operatora, `ServerSideApply`. Chart
  instaluje Alloy Operator, a kolektor jest zasobem `Alloy` (jedna replika: klaster ma jeden węzeł). Obraz Alloy jest
  przypięty tagiem, który wskazuje chart, więc działa ten, na którym biegnie test maskowania. Brak konta albo zły token
  zostawia `Degraded` tylko `monitoring`; sklep się wdraża.
- **Alloy czyta z klastra jeden sekret: `grafana-cloud` w przestrzeni `shop`** (SealedSecret z `seal-secrets WHAT=grafana`,
  klucz `GRAFANA_CLOUD_TOKEN`, `secret.create: false`). Domyślne reguły Alloy (`get/list/watch` na wszystkie sekrety i
  ConfigMapy klastra) zastępuje ClusterRole kolektora tylko do odczytu, bez sekretów i ConfigMap (węzły i metryki kubeleta
  i cAdvisora, usługi, endpointy, pody i ich logi, przestrzenie, zdarzenia, `PrometheusRule`), a osobna Role w `shop` daje
  dostęp tylko do tego sekretu (`resourceNames`); kube-state-metrics nie zbiera metryk sekretów.
- **Alloy Operator działa tylko w przestrzeni `monitoring`** (`ownNamespaceOnly`, `WATCH_NAMESPACE`), bez ClusterRoli: jego
  uprawnienia to jedna Role w `monitoring` (zasób `Alloy` bez tworzenia i usuwania, bo zakłada go Argo CD; Deployment, konto
  usługi i Service, które robi z niego chart Alloy, przy czym zmienia i usuwa tylko obiekty `monitoring-alloy`; sekrety
  z wydaniami Helm kolektora, dzierżawa lidera, zdarzenia; bez DaemonSetów, StatefulSetów, ConfigMap i podów), bez żadnych
  uprawnień do obiektów RBAC.
  Dlatego zasób `Alloy` nie tworzy RBAC (`rbac.create: false`), a ClusterRole kolektora i jej powiązanie są w `extraObjects`
  manifestu. Domyślny RBAC chartu operatora (ClusterRole z pełnym dostępem do sekretów klastra oraz `bind` i `escalate`, żeby
  tworzyć role kolektorów) jest wyłączony. Żadna rola renderu nie ma `bind`, `escalate`, `impersonate` ani zmian obiektów
  RBAC, a sekrety w `monitoring` ma tylko operator: sprawdza to `check` na renderze, a `make -C infra/k8s monitoring-smoke`
  uruchamia monitoring z tym RBAC w k3d i pyta klaster (`kubectl auth can-i`), co mogą konta usług operatora, Alloy,
  kube-state-metrics i node-exportera.
- **Przestrzeń `monitoring` ma trzy polityki dopuszczania** (`ValidatingAdmissionPolicy` w `extraObjects`, powiązane
  z przestrzenią przez `kubernetes.io/metadata.name`, `Deny`, `failurePolicy: Fail`, fala −1 przed resztą aplikacji). Operator
  (obcy obraz) tworzy tam Deploymenty, Service i sekrety, a sam RBAC nie zabrania podowi dostępu do hosta (plik kubeconfig k3s na
  węźle to administrator klastra) ani klasy priorytetu systemu (`system-cluster-critical` i `system-node-critical` są dozwolone
  w każdej przestrzeni, a pod z nią wywłaszcza pody sklepu), Service otwierającego port węzła (`NodePort`, `LoadBalancer` przez
  ServiceLB k3s, z pominięciem ufw) albo przejmującego ruch podów klastra do obcych adresów (`externalIPs`, CVE-2020-8554) ani
  sekretu typu `kubernetes.io/service-account-token` (token dowolnego konta przestrzeni). Polityki odrzucają, komukolwiek:
  `monitoring-no-host-access` pod i szablon poda (Deployment, ReplicaSet, StatefulSet, DaemonSet, Job, CronJob) z dostępem do
  hosta (`hostNetwork`, `hostPID`, `hostIPC`, `hostPath`, `hostPort`, `privileged`, capability spoza profilu baseline) albo
  z `priorityClassName` zaczynającym się od `system-`, `monitoring-cluster-ip-services` Service innego typu niż `ClusterIP`
  (także `ExternalName`) albo z `externalIPs`, a `monitoring-no-service-account-tokens` sekret z tokenem konta. Wszystkie
  Service chartów są `ClusterIP`. Node-exporter potrzebuje hosta, więc ma jedyny wyjątek, rozpoznawany po tym, kto tworzy pody: kontroler
  DaemonSetów (w k3s ma własne konto w `kube-system`) dla DaemonSetu `monitoring-node-exporter`, którego żadne konto
  `monitoring` nie może zmienić; etykiet ani `ownerReference` ustawionych przez kogoś innego polityka nie uznaje. Wbudowany
  Pod Security Admission nie wystarcza: poziom przestrzeni nie ma wyjątku dla jednego DaemonSetu, a wyjątki po użytkowniku są
  w konfiguracji serwera API, poza Helm i Argo CD. `check` pilnuje polityk w renderze, `monitoring-smoke` wysyła w k3d żądania,
  które muszą odrzucić, i odtwarza pody `monitoring` pod nimi.
- **Alerty to `PrometheusRule` w chartcie `shop`** (`templates/00-alerts.yaml`, tylko przy `alerts.enabled`: `prod`), które Alloy
  synchronizuje do rulera Grafana Cloud, więc alert przychodzi także wtedy, gdy serwer albo Alloy nie żyje (reguły z `absent`).
  Siedem reguł: `BackupFailed`, `BackupMissing`, `BackupVerifyFailed`, `BackupVerifyMissing`, `NodeDiskFull`, `ClusterSilent`,
  `WorkloadNotReady`. Alerty kopii patrzą na Job z `kube_job_owner` o dokładnej nazwie CronJoba (`backup` nie łapie
  `backup-verify`) i na `kube_cronjob_status_last_successful_time` (26 godzin); `BackupFailed` odpala dla Joba `Failed` nowszego niż
  ostatnia udana kopia, więc nieudany pod z udanym ponowieniem go nie wywołuje, a sukces gasi alert. `WorkloadNotReady` ma `absent`
  dla każdego Deploymentu i StatefulSetu renderu (lista z tego samego renderu, więc nowy workload dostaje regułę sam), tylko gdy
  klaster raportuje, żeby cisza całego klastra była wyłącznie `ClusterSilent`; workload zeskalowany do zera (`restore`) nie odpala.
  Reguły są testowane `promtool test rules` w `check` na regułach wyciągniętych z renderu, nie z ich kopii. Alternatywą były
  reguły napisane w Grafana Cloud ręcznie: poza repo, bez testów i bez przeglądu. Brak CRD przy `alerts.enabled` daje błąd
  synchronizacji, a nie ciche zniknięcie reguł. Powiadomienia to punkt kontaktowy e-mail w Grafana Cloud, a świadomy `restore` i
  prace wyciszane są ręcznie (runbook); alerty z treści logów są poza zakresem.
- **Logi są maskowane w Alloy przed wysłaniem, jedną listą etapów** (`extraLogProcessingStages`, blok w manifeście
  `monitoring` użyty dla logów podów i zdarzeń klastra): hasła, tokeny, JWT, `Bearer`, nagłówki `Authorization` i ciasteczka,
  klucze z listy (JSON, logfmt, zapytania URL), adresy e-mail i IP, parametry zapytań Doctrine; linie `DETAIL` i
  `STATEMENT` Postgresa są odrzucane. Źródła, których nie da się zagwarantować zamaskować, nie są zbierane (Mailpit:
  `extraDiscoveryRules`). Wzorce nie znają każdego formatu, więc po wdrożeniu przegląd próbki logów (runbook) jest częścią
  decyzji: znalezisko to nowa reguła albo wyłączenie źródła. Test w `check` uruchamia prawdziwy Alloy (przypięty tagiem i
  digestem) na korpusie próbek logów każdego źródła z podłożonymi sekretami i danymi klientów.

## Konsekwencje

- Hasło restic jest jedyną rzeczą, bez której kopie są nie do odczytania; użytkownik trzyma je też w menedżerze haseł,
  poza serwerem i poza kluczem Sealed Secrets.
- Klucz R2 w klastrze pozwala usunąć kopie. Ograniczenie: token tylko do jednego bucketu. Kopie niezmienne (S3 Object
  Lock) są poza zakresem demo; decyzja wraca przed uruchomieniem publicznym.
- Dump i lustro obiektów powstają w różnych chwilach (sekundy–minuty), więc nie są ściśle spójne; odtworzenie tego nie
  wymaga.
- Dump logiczny rośnie z bazą; przy docelowej skali (`docs/vision.md`) potrzebne będą kopie fizyczne z WAL.
- Weryfikacja odczytuje w tygodniu całe repozytorium, ale tylko raz w tygodniu każdy jego fragment: uszkodzenie danych
  wychodzi najwyżej po 7 dniach (retencja kopii to 7 dni, więc uszkodzony snapshot może wygasnąć, zanim go odczyta). Dump
  odtwarza się w pustej bazie, więc nie wyłapie błędów widocznych dopiero przy pracy aplikacji.
- Smoke test sprawdza kopię, jej weryfikację i odtworzenie na pustym klastrze (bez Argo CD) na osobnej instancji S3 (przestrzeń
  `smoke-backup`), bez kont zewnętrznych; wyłączanie synchronizacji Argo CD sprawdzają testy skryptu na atrapie `kubectl`, a na
  serwerze ćwiczenie odtworzenia z runbooka.
- Odtworzenie cofa wszystko do chwili snapshotu (zamówienia, konta, hasła klientów i administratorów) i wylogowuje klientów;
  wiadomości w kolejkach giną (trwałe zdarzenia są w outboxie odtworzonej bazy). Cofnięcie kodu o więcej niż jedną wersję to
  starszy snapshot `deploy-images` i odtworzenie kopii z czasu tej wersji.
- Realm `master` Keycloaka jest w kopii bazy `keycloak`, więc po odtworzeniu jego administrator ma hasło z chwili snapshotu.
  Przy tych samych sekretach (ten sam serwer albo nowy z kopią klucza Sealed Secrets) to jest `KC_BOOTSTRAP_ADMIN_PASSWORD`
  sekretu; po utracie klucza Sealed Secrets (nowe sekrety) `keycloak-config` po odtworzeniu nie zaloguje się do `master` i
  odtworzenie stanie na kroku 6 (runbook).
- Alerty zależą od działania Alloy i kube-state-metrics: bez nich nie ma metryk, a `absent` zamienia ciszę w alert (`BackupMissing`
  po 30 minutach, `ClusterSilent` po 15). Przed pierwszą kopią `BackupMissing` odpala, bo metryki jeszcze nie ma. `NodeDiskFull`
  patrzy na jeden węzeł; wyciszenie na czas prac jest ręczne i może zostać zapomniane.
- Grafana Cloud dostaje logi i metryki klastra: dane klientów w nieprzewidzianym formacie (np. imię i adres w treści wyjątku
  poza parametrami Doctrine) mogą przejść maskowanie; ograniczają to korpus testowy, przegląd próbki po wdrożeniu i zasada
  wyłączania źródła do czasu poprawki. Przekroczenie darmowego planu Grafana Cloud odrzuca nadmiar; lista dozwolonych metryk
  i pomiar po wdrożeniu.
- Interfejs chartu `k8s-monitoring` zmienia się między głównymi wersjami (operator od 3.x, `destinations` w 4.x), a obraz
  Alloy zależy od wersji chartu: aktualizacja to osobna zmiana (wersje i digesty archiwów chartów są w
  `infra/k8s/scripts/monitoring-render.sh`, obraz Alloy w `infra/k8s/scripts/tools.sh`).
- Ryzyko rezydualne operatora: przejęty operator może uruchomić w `monitoring` dowolny obraz z kontem usługi tej przestrzeni
  (tworzenia nie da się ograniczyć do nazw), więc pośrednio ma prawa kolektora (odczyt podów, ich logów i zdarzeń całego
  klastra, token `grafana-cloud`, którym wyśle dane dokądkolwiek) i kube-state-metrics (lista obiektów klastra bez sekretów
  i ConfigMap). Może zatrzymać zbieranie (zmienić albo usunąć Alloy) i, bez limitów zasobów przestrzeni, zająć węzeł.
  Istniejących Deploymentów kube-state-metrics i swojego ani DaemonSetu `monitoring-node-exporter` nie zmieni (`resourceNames`,
  bez uprawnień do DaemonSetów). Nie dostanie się do hosta ani do tokenów kont przez sekrety, nie otworzy portu węzła, nie
  przejmie ruchu przez `externalIPs` i nie wywłaszczy podów sklepu klasą priorytetu systemu (polityki dopuszczania) i nie
  czyta sekretów `shop-backup` ani `shop-secrets` (RBAC); kolektor też nie.
- Klaster nie ma żadnej NetworkPolicy, więc każdy pod sięga po sieci do każdej usługi każdej przestrzeni. Redis działa bez
  hasła i trzyma sesje sklepu z tokenami OIDC klientów, a Elasticsearch działa bez security (`xpack.security.enabled: false`).
  Każdy pod w `monitoring`, także przejęty operator bez tworzenia czegokolwiek, czyta i zmienia sesje klientów przez
  `redis.shop:6379` (podszycie się pod klienta) i indeks wyszukiwarki przez Elasticsearch, tak samo jak każdy pod w `shop` i każdy obcy obraz tam
  (Keycloak, Varnish, imgproxy, Elasticsearch). Polityki dopuszczania i RBAC tego nie ograniczają; zamknięcie wymaga
  NetworkPolicy z domyślną odmową, hasła Redisa i security Elasticsearcha.
- RBAC kolektora i operatora jest w manifeście, nie w chartcie: funkcja chartu, która potrzebuje innego zasobu (nowa wersja
  albo włączona opcja), kończy się błędem `forbidden` w logu Alloy albo operatora (wyłapuje go `monitoring-smoke`), a nie
  cichym poszerzeniem uprawnień; brakującą regułę dopisuje się w `extraObjects`.
