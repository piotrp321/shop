# Kopie zapasowe poza serwerem (restic do Cloudflare R2) i obserwowalność (Grafana Cloud)

## Kontekst

Sklep działa na jednym serwerze (`docs/adr/k3s-argo-cd.md`): dane (bazy PostgreSQL `shop` i `keycloak`, obiekty Garage ze
zdjęciami produktów) leżą na wolumenach tego serwera. Backup VPS-a od OVH jest tylko dodatkiem: leży u tego samego
dostawcy, a jego przywrócenie cofa cały serwer. Potrzebna jest kopia danych sklepu poza serwerem, szyfrowana, tania i
odtwarzalna na pustym klastrze. Logi i metryki klastra muszą być poza nim: serwer, który padł, nie opowie o sobie. Własny
Prometheus i Loki to usługi do utrzymania na tym samym serwerze, więc nie pomagają przy jego awarii. Specyfikacja:
`docs/features/deploy-ops/spec.md` (O1, O2, O3, O4, O5, O7).

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
  (`forget --keep-within 7d --prune`).
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
  uprawnienia to jedna Role w `monitoring` (zasoby `Alloy`, Deployment, konto usługi, Service i ConfigMap, które robi z nich
  chart Alloy, sekrety z wydaniami Helm kolektora, dzierżawa lidera, zdarzenia), bez żadnych uprawnień do obiektów RBAC.
  Dlatego zasób `Alloy` nie tworzy RBAC (`rbac.create: false`), a ClusterRole kolektora i jej powiązanie są w `extraObjects`
  manifestu. Domyślny RBAC chartu operatora (ClusterRole z pełnym dostępem do sekretów klastra oraz `bind` i `escalate`, żeby
  tworzyć role kolektorów) jest wyłączony. Żadna rola renderu nie ma `bind`, `escalate`, `impersonate` ani zmian obiektów
  RBAC, a sekrety w `monitoring` ma tylko operator: sprawdza to `check` na renderze.
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
- Grafana Cloud dostaje logi i metryki klastra: dane klientów w nieprzewidzianym formacie (np. imię i adres w treści wyjątku
  poza parametrami Doctrine) mogą przejść maskowanie; ograniczają to korpus testowy, przegląd próbki po wdrożeniu i zasada
  wyłączania źródła do czasu poprawki. Przekroczenie darmowego planu Grafana Cloud odrzuca nadmiar; lista dozwolonych metryk
  i pomiar po wdrożeniu.
- Interfejs chartu `k8s-monitoring` zmienia się między głównymi wersjami (operator od 3.x, `destinations` w 4.x), a obraz
  Alloy zależy od wersji chartu: aktualizacja to osobna zmiana (wersje i digesty archiwów chartów są w
  `infra/k8s/scripts/monitoring-render.sh`, obraz Alloy w `infra/k8s/scripts/tools.sh`).
- Operator tworzy w `monitoring` Deploymenty z kontem usługi Alloy, więc pośrednio ma prawa kolektora (odczyt podów, ich logów
  i zdarzeń całego klastra, sekret `grafana-cloud`); sekretów `shop-backup` i `shop-secrets` nie czyta ani on, ani kolektor.
- RBAC kolektora i operatora jest w manifeście, nie w chartcie: funkcja chartu, która potrzebuje innego zasobu (nowa wersja
  albo włączona opcja), kończy się błędem `forbidden` w logu Alloy albo operatora, a nie
  cichym poszerzeniem uprawnień; brakującą regułę dopisuje się w `extraObjects`.
