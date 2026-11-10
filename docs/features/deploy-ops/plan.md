# DEPLOY-OPS – plan zadań

Specyfikacja: `docs/features/deploy-ops/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | DEPLOY-OPS-1 | |
| 2 | DEPLOY-OPS-2, DEPLOY-OPS-4 | |
| 3 | DEPLOY-OPS-3, DEPLOY-OPS-5 | |

Stan prób zadań zgłoszonych do AO: `ao status`. Kolumnę „Status” (PR, zmergowane) uzupełnia użytkownik przy
integracji zadań fali.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "DEPLOY-OPS",
  "spec": "docs/features/deploy-ops/spec.md",
  "tasks": [
    {
      "id": "DEPLOY-OPS-1",
      "title": "Nightly encrypted backup of the databases and the product images to an S3 restic repository",
      "scope": "Spec: O1, O2, „Konfiguracja”, „Smoke test i check” (części kopii), „Scenariusze błędów” (kopia). infra/k8s/charts/shop: CronJob backup (harmonogram, Forbid, startingDeadlineSeconds, backoffLimit, activeDeadlineSeconds, limity historii), PVC backup-work, initContainer identyfikatora klastra z wolumenem tokenu i ClusterRole get namespaces/kube-system, skrypt kopii w files/scripts (pg_dump obu baz, rclone sync product-images, manifest.json z migracjami, restic unlock/init/backup z tagiem BACKUP_TAG i --host, forget --keep-within --prune, --retry-lock), obrazy restic/rclone w images.* z tagiem i digestem, wartości backup.* i storage.backupWork, schemat (backup.enabled bez repozytorium albo sekretu: błąd), Secret shop-backup z backup.secretValues przy secrets.create; environments/prod (backup.enabled, przykładowe repozytorium R2, region auto, secretName) i environments/ci (osobne S3, verifyReadData pomijane do DEPLOY-OPS-2). scripts/seal-secrets.sh: pozycja backup (RESTIC_PASSWORD, BACKUP_S3_ACCESS_KEY_ID, BACKUP_S3_SECRET_ACCESS_KEY z terminala bez echa, raz), domyślne WHAT z backup; tests: render, obrazy, EXPECTED_KEYS shop-backup, seal-secrets backup (błąd bez zapisu, drugi przebieg), skrypt kopii na atrapach narzędzi; selftest: repozytorium bez hasła, CronJob bez Forbid. scripts/smoke.sh: instancja S3 w przestrzeni smoke-backup i Job z CronJoba backup po create-admin w r1 (kroki 1–2 smoke ze spec). Dokumentacja: docs/features/deploy/spec.md sekcja „Kopie zapasowe”, docs/adr/k3s-argo-cd.md (konsekwencje o kopiach), nowy docs/adr/backups-observability.md (część o kopiach), docs/vision.md (kopie w opisie środowiska), CLAUDE.md (seal-secrets z backup, nowe sprawdzenia check i smoke). Nie obejmuje backup-verify (DEPLOY-OPS-2), restore (DEPLOY-OPS-3), seal-secrets grafana (DEPLOY-OPS-4) ani alertów (DEPLOY-OPS-5).",
      "acceptance_criteria": [
        "Render prod ma CronJob backup z obrazami po digeście, sekretem shop-backup, PVC backup-work, limitami czasu i historii z O1; token API ma tylko initContainer identyfikatora klastra, kontenery z sekretem shop-backup nie montują tokenu",
        "Render z backup.enabled bez backup.repository albo backup.secretName kończy się błędem z nazwą wartości",
        "Skrypt kopii na atrapach: błąd pg_dump, rclone albo restic kończy Job błędem i nie tworzy snapshotu; snapshot ma tag BACKUP_TAG, --host z identyfikatora klastra i manifest z migracjami; forget działa z --keep-within 7d",
        "seal-secrets WHAT=backup po błędzie niczego nie zapisuje, a drugi przebieg niczego nie pyta; bez WHAT obejmuje backup; EXPECTED_KEYS zna shop-backup",
        "selftest: repozytorium bez hasła i CronJob kopii bez concurrencyPolicy Forbid kończą check błędem",
        "make -C infra/k8s smoke: Job z CronJoba backup tworzy w S3 smoke testu snapshot nightly z dumpami obu baz, obiektami i manifestem; smoke wypisuje czas fazy kopii",
        "Dokumentacja z zakresu opisuje stan po zadaniu; make check przechodzi"
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "DEPLOY-OPS-2",
      "title": "Daily verification of the backups from the S3 repository",
      "scope": "Spec: O3, „Konfiguracja” (verifySchedule, verifyReadData), „Smoke test i check” (backup-verify), „Scenariusze błędów” (weryfikacja). infra/k8s/charts/shop: CronJob backup-verify (05:00, limity jak backup, identyfikator klastra jak w O1), skrypt weryfikacji w files/scripts (wiek najnowszego snapshotu nightly < 26 h, restic check --read-data-subset=n/7 z date +%u albo --read-data przy verifyReadData all, restic dump obu dumpów strumieniowo do pg_restore w tymczasowym Postgresie na emptyDir w tym samym podzie, sprawdzenie tabeli migracji i realmów, liczba obiektów z restic ls zgodna z manifestem), wartości backup.verifySchedule i backup.verifyReadData (ci: all); tests: render i skrypt na atrapach restic i pg_restore (uszkodzone repozytorium, nieodtwarzalny dump, stary snapshot, rozbieżna liczba obiektów: błąd z opisem). scripts/smoke.sh: Job z CronJoba backup-verify po kopii (krok 3 smoke ze spec). Dokumentacja: docs/features/deploy/spec.md i docs/adr/backups-observability.md (weryfikacja), CLAUDE.md (sprawdzenia). Nie obejmuje alertów weryfikacji (DEPLOY-OPS-5).",
      "acceptance_criteria": [
        "Render prod ma CronJob backup-verify bez dostępu do produkcyjnej bazy (tymczasowy Postgres na emptyDir), z limitami czasu i historii",
        "Skrypt na atrapach: snapshot nightly starszy niż 26 h, błąd restic check, błąd pg_restore, brak realmów shop i backoffice, pusta tabela migracji i liczba obiektów inna niż w manifeście kończą Job błędem z opisem; zdrowy przypadek kończy się sukcesem",
        "n w --read-data-subset to date +%u (1–7); verifyReadData all daje --read-data",
        "make -C infra/k8s smoke: backup-verify po kopii kończy się sukcesem",
        "Dokumentacja z zakresu opisuje stan po zadaniu; make check przechodzi"
      ],
      "risk": "STANDARD",
      "depends_on": ["DEPLOY-OPS-1"]
    },
    {
      "id": "DEPLOY-OPS-3",
      "title": "Restore of a chosen snapshot with make restore and the list of snapshots, tested on an empty cluster",
      "scope": "Spec: O4, „Smoke test i check” (restore, backups, kroki 4–5 smoke), „Runbook” (ćwiczenie odtworzenia, utrata serwera, Eksploatacja), „Scenariusze błędów” (restore). infra/k8s/charts/shop: wstrzymany CronJob restore z trybami list, check, restore i reindex (activeDeadlineSeconds), skrypt odtworzenia w files/scripts (restic restore na backup-work, kontrola migracji manifestu z listą klas migracji obrazu api, DROP/CREATE i pg_restore shop i keycloak, rclone sync z usuwaniem do product-images, czyszczenie Redisa i kolejek RabbitMQ, catalog:reindex w trybie reindex). infra/k8s/scripts: restore.sh i backups.sh przez KUBE_DIR/KUBE_NETWORK/KUBE_NAMESPACE jak rerun (kroki 1–7 O4: sprawdzenia bez zmian i odmowa przy aktywnym Jobie, trwającej synchronizacji i snapshocie nowszym niż kod, potwierdzenie tak, kopia pre-restore z PRE_RESTORE=skip, wyłączenie synchronizacji root i shop przy Argo CD, skalowanie i wstrzymanie cart-purge-expired i backup, ponowienie migrate, keycloak-config i purge-caches mechanizmem rerun, przywrócenie replik i synchronizacji, komunikat o kroku przy błędzie); Makefile: cele restore (SNAPSHOT, PRE_RESTORE) i backups; tests na atrapie kubectl (ścieżki błędu ze spec, z Argo CD i bez); selftest: restore bez potwierdzenia. scripts/smoke.sh: odtworzenie na pustym klastrze i odmowa snapshotu nowszego niż kod (kroki 4–5 smoke), dalsze fazy r2–r4 na odtworzonym klastrze. Dokumentacja: infra/k8s/README.md (ćwiczenie odtworzenia, utrata serwera, Eksploatacja: cofnięcie wersji przez restore, snapshoty starego hosta), CLAUDE.md (cele restore i backups, agent uruchamia restore tylko po zgodzie), docs/features/deploy/spec.md („Dane startowe” bez zapowiedzi initialize.enabled: false dla odtwarzania, „Wdrożenie nowej wersji”, „Ryzyka”, scenariusz utraty klucza), docs/adr/backups-observability.md (odtworzenie). Nie obejmuje wyciszania alertów (DEPLOY-OPS-5).",
      "acceptance_criteria": [
        "restore bez tak, bez SNAPSHOT, przy aktywnym Jobie w shop albo trwającej synchronizacji shop niczego nie zmienia (kod różny od 0, testy na atrapie)",
        "Snapshot z migracją nieznaną kodowi: odmowa przed potwierdzeniem, kopią pre-restore i skalowaniem (atrapa i smoke: aplikacje działają, dane bez zmian)",
        "Nieudana kopia pre-restore bez PRE_RESTORE=skip kończy restore bez zmian; z PRE_RESTORE=skip skrypt wypisuje to przed tak",
        "Kolejność z Argo CD: wyłączenie synchronizacji root, potem shop; na końcu włączenie root; bez Argo CD przywrócenie zapamiętanych replik (atrapa)",
        "Błąd Joba odtworzenia nie włącza aplikacji i wypisuje krok oraz snapshot pre-restore (atrapa)",
        "make -C infra/k8s smoke: po usunięciu przestrzeni shop z PVC i ponownej instalacji restore odtwarza snapshot: administrator z create-admin w backoffice, logowanie klienta sklepu, liczby produktów i zamówień jak przed kopią, zdjęcie przez imgproxy, produkt w wyszukiwarce, strona główna, snapshot pre-restore w repozytorium; fazy r2–r4 przechodzą na odtworzonym klastrze; czas fazy odtworzenia i szczytowy dysk wypisane",
        "backups wypisuje identyfikator, czas, host, tag i rewizję snapshotów, tylko odczyt",
        "Dokumentacja z zakresu opisuje stan po zadaniu; make check przechodzi"
      ],
      "risk": "CRITICAL",
      "depends_on": ["DEPLOY-OPS-2"]
    },
    {
      "id": "DEPLOY-OPS-4",
      "title": "Grafana Alloy with k8s-monitoring 4.x: masked pod logs, cluster events and metrics to Grafana Cloud",
      "scope": "Spec: O5, O7, „Smoke test i check” (manifesty, render k8s-monitoring, maskowanie, budżet, RBAC), „Runbook” (konta Grafana Cloud, adresy w monitoring.yaml, przegląd próbki logów), „Scenariusze błędów” (monitoring, logi). infra/k8s/argocd/apps: prometheus-crds (prometheus-operator-crds, ServerSideApply=true, fala -3) i monitoring (k8s-monitoring 4.x przypięty, valuesObject: clusterMetrics z listą dozwolonych metryk, clusterEvents, podLogsViaKubernetesApi, cele prometheus z rules.enabled i rules.address rulera oraz loki z adresami i identyfikatorami instancji, secret.create false i secret.namespace shop z kluczem GRAFANA_CLOUD_TOKEN, reguły kolektorów bez secrets i Role w shop tylko na grafana-cloud, extraLogProcessingStages dla logów podów i zdarzeń, extraDiscoveryRules wyłączające Mailpit, jawne zasoby, CreateNamespace, SkipDryRunOnMissingResource, fala 1). scripts/seal-secrets.sh: pozycja grafana (GRAFANA_CLOUD_TOKEN, przestrzeń shop), domyślne WHAT z grafana; tests: fale prometheus-crds -3, sealed-secrets -2, secrets -1, shop 0, monitoring 1, ta sama rewizja, render chartu k8s-monitoring z wartościami z manifestu (kubeconform ze schematem Alloy), RBAC sekretów, lista dozwolonych metryk z metrykami alertów ze spec, budżet shop + monitoring (spec.resources zasobów Alloy, operator, kube-state-metrics, node-exporter), EXPECTED_KEYS grafana-cloud, test maskowania z prawdziwym Alloy (loki.echo) na korpusie próbek źródeł z O7; selftest: usunięcie reguły maskowania e-maili, Role Alloy na wszystkie sekrety. Dokumentacja: infra/k8s/README.md (konta Grafana Cloud, token, adresy, przegląd logów), CLAUDE.md (nowe aplikacje Argo i fale, seal-secrets grafana, sprawdzenia check), docs/vision.md (Grafana Cloud z logami i metrykami, trace'y przeniesione do #180, gotowe charty platformy), docs/features/deploy/spec.md (D2 z nowymi chartami, fale aplikacji Argo), docs/adr/backups-observability.md (obserwowalność i maskowanie). Nie obejmuje reguł alertów (DEPLOY-OPS-5).",
      "acceptance_criteria": [
        "Manifesty prometheus-crds i monitoring przechodzą kubeconform; fale -3, -2, -1, 0, 1; ServerSideApply przy CRD, SkipDryRunOnMissingResource i CreateNamespace przy monitoring",
        "Render k8s-monitoring z wartościami z manifestu przechodzi kubeconform (także zasoby Alloy); żadna ClusterRole ani Role poza Role na grafana-cloud w shop nie daje dostępu do secrets",
        "Test maskowania: żadna podłożona wartość (hasło, token, JWT, Authorization, ciasteczko, e-mail, IP, parametry Doctrine, DETAIL Postgresa) nie wychodzi z Alloy ani z logów podów, ani ze zdarzeń klastra; linie Mailpita nie wychodzą; poziom, komunikat, kanał i kod odpowiedzi zostają",
        "Lista dozwolonych metryk nie jest pusta i zawiera metryki alertów ze spec; budżet shop + monitoring w prod mieści się w 7 vCPU requestów i 20 GB limitów",
        "seal-secrets WHAT=grafana po błędzie niczego nie zapisuje, drugi przebieg niczego nie pyta; EXPECTED_KEYS zna grafana-cloud",
        "selftest: usunięta reguła maskowania e-maili i Role Alloy na wszystkie sekrety kończą check błędem",
        "Dokumentacja z zakresu opisuje stan po zadaniu; make check przechodzi"
      ],
      "risk": "CRITICAL",
      "depends_on": ["DEPLOY-OPS-1"]
    },
    {
      "id": "DEPLOY-OPS-5",
      "title": "Alert rules as PrometheusRule with promtool tests: backups, verification, disk, silent cluster, workloads",
      "scope": "Spec: O6, „Konfiguracja” (alerts.enabled), „Runbook” (punkt kontaktowy, wyciszanie, BackupMissing przed pierwszą kopią), „Scenariusze błędów” (alerty). infra/k8s/charts/shop: templates/alerts.yaml (PrometheusRule przy alerts.enabled; BackupFailed, BackupMissing, BackupVerifyFailed, BackupVerifyMissing, NodeDiskFull, ClusterSilent, WorkloadNotReady z absent dla każdego Deploymentu i StatefulSetu z renderu), wartość alerts.enabled (prod true, ci false). tests: render alerts dla prod, promtool check rules i promtool test rules (obraz prom/prometheus przypięty digestem w tools.sh) ze scenariuszami odpalenia i milczenia ze spec, test, że WorkloadNotReady ma absent dla każdego workloadu z renderu prod; selftest: BackupMissing bez absent. Dokumentacja: infra/k8s/README.md (punkt kontaktowy e-mail, wyciszanie w Grafana Cloud przed restore i pracami, BackupMissing do pierwszej kopii, reakcja na alerty kopii), CLAUDE.md (sprawdzenia check), docs/features/hardening/spec.md (D7: WorkloadNotReady dla Redisa, ostrzeżenia cache w logach bez alertu, alerty z logów poza zakresem), docs/adr/backups-observability.md (alerty jako PrometheusRule).",
      "acceptance_criteria": [
        "Render prod ma PrometheusRule z siedmioma alertami; render ci go nie ma",
        "promtool test rules: każdy alert odpala w swoim scenariuszu i milczy, gdy wszystko jest zdrowe; alerty kopii milczą przy udanym ponowieniu i po powrocie do zdrowia, backup nie łapie Jobów backup-verify; NodeDiskFull milczy przy 85% i krótkim skoku; WorkloadNotReady odpala przy niedostępnej replice i brakującym workloadzie, milczy przy krótkiej niedostępności, zeskalowaniu do zera i ciszy klastra",
        "WorkloadNotReady ma absent dla każdego Deploymentu i StatefulSetu z renderu prod (test renderu)",
        "selftest: BackupMissing bez absent kończy check błędem",
        "Dokumentacja z zakresu opisuje stan po zadaniu (w tym D7 HARDENING i procedura wyciszania); make check przechodzi"
      ],
      "risk": "STANDARD",
      "depends_on": ["DEPLOY-OPS-2", "DEPLOY-OPS-4"]
    }
  ]
}
```
