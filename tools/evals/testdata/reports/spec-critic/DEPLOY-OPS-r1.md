## Krytyka szkicu `docs/features/deploy-ops/spec.md` (DEPLOY-OPS, #178)

Szkic pokrywa cały zakres #178: kopie, weryfikację, `restore`, Alloy i alerty. Opcja `rules.enabled` w celu `prometheus` chartu `k8s-monitoring` istnieje (sprawdziłem dokumentację chartu 4.6.0), więc O6 da się zrealizować. Poniżej realne luki.

### Important

1. **architektoniczne – O5: `monitoring` w fali przed `shop` blokuje wdrożenie sklepu.** Root czeka na zdrowie aplikacji z wcześniejszych fal (`infra/k8s/tests/test_argocd.py:159`, health `Application` w `argocd/install`). Alloy bez sekretu `grafana-cloud` (konta zakłada się dopiero w runbooku) albo z błędnym tokenem zostawia `monitoring` w stanie Progressing lub Degraded. Wtedy `shop` się nie synchronizuje. Rekomendacja: przed `shop` tylko `prometheus-crds`, a `monitoring` w fali po `shop`. Szkic powinien podać numery fal; test wymaga unikalnych numerów (`test_argocd.py:93,137`).

2. **techniczne – O5 i Runbook: nie wiadomo, skąd SealedSecret `grafana-cloud` trafia do przestrzeni `monitoring`.** `seal-secrets.sh:30` ma na sztywno `namespace=shop`. Aplikacja `secrets` ma cel `shop` i `CreateNamespace` tylko dla niego (`argocd/apps/secrets.yaml`), a `environments/prod/secrets/README.md:4` mówi „applies them to the namespace shop”. Przestrzeni `monitoring` nie będzie w chwili synchronizacji `secrets` (fala -1). Propozycja: dopisać, że `seal-secrets` szyfruje `grafana` dla `monitoring`, kto tworzy tę przestrzeń przed `secrets` i jak zmieniają się testy plików i fal.

3. **techniczne – O5: CRD z `prometheus-operator-crds` nie zsynchronizują się zwykłym apply w Argo CD.** CRD Prometheusa przekraczają limit adnotacji `last-applied` i potrzebują `syncOptions: ServerSideApply=true`. Ani `kubeconform`, ani smoke test (bez Argo) tego nie wyłapią, a nieudana fala zatrzymuje kolejne. Propozycja: wpisać `ServerSideApply=true` w manifeście i sprawdzać to w teście manifestów.

4. **techniczne – O6: `kube_job_status_failed` daje fałszywe i niegasnące alerty.**
   - Metryka liczy nieudane pody, więc rośnie także wtedy, gdy ponowienie z `backoffLimit` się powiedzie (alert „natychmiast” bez awarii).
   - Nieudany Job zostaje w historii (`failedJobsHistoryLimit`), więc `BackupFailed` świeci także po kolejnej udanej kopii.
   - Wyrażenie regularne `backup-.*` łapie też Joby `backup-verify-*`.

   Propozycja: `kube_job_status_condition{condition="Failed"}` lub `kube_job_failed` z `kube_job_owner{owner_name="backup"}`, z warunkiem „nieudany Job nowszy niż `last_successful_time`”. Do `promtool test rules` dodać scenariusze: udane ponowienie i powrót do zdrowia po awarii.

5. **produktowe – O1 i Runbook (utrata serwera): retencja może skasować prawdziwe kopie.** Po `bootstrap` nowego serwera `initialize` wczytuje dane demo, a CronJob `backup` działa co noc. `restic forget --keep-within 7d` liczy 7 dni od najnowszego snapshotu, więc kolejne nocne kopie danych demo wypierają prawdziwe snapshoty. Przy przerwie dłuższej niż 7 dni znikają od razu. Szkic sam zakłada demo w najnowszym snapshocie (O4, krok 1), ale tego skutku nie opisuje. Rekomendacja: przy odtwarzaniu na nowym serwerze kopie są wstrzymane wartością (`backup.suspend: true` w `prod` na czas odtworzenia, opisane w runbooku), a `restore` zdejmuje wstrzymanie dopiero po sukcesie. Alternatywa: retencja `--keep-within 7d` plus `--keep-last 7` liczona tylko po snapshotach z tagiem `nightly`.

6. **produktowe – O4, krok 2: brak zachowania przy nieudanej kopii `pre-restore`.** `restore` jest potrzebny właśnie wtedy, gdy dane albo Postgres są zepsute, a wtedy kopia `pre-restore` też się nie uda. Krok 6 opisuje tylko błędy kroków 3–5. Brakuje też mechanizmu nadania tagu `pre-restore` Jobowi utworzonemu z CronJoba `backup` (`kubectl create job --from` nie nadpisuje env). Rekomendacja: domyślnie błąd kopii zatrzymuje `restore` bez zmian; jawne `PRE_RESTORE=skip` jest wypisane w potwierdzeniu przed `tak`; tag przekazywany ConfigMapą jak snapshot w kroku 4.

7. **techniczne – O4, kroki 4–5: po odtworzeniu bazy `keycloak` brakuje ponownego `keycloak-config`.** Odtworzona baza cofa ustawienia, które ustawia Job: sekret klienta `store`, `redirectUris`, rejestrację, SMTP, a także klienty dodane po dacie snapshotu. Job nie uruchomi się sam, bo jego nazwa zależy od sumy kontrolnej plików (`templates/jobs.yaml`, `realmsChecksum`). Ma to znaczenie zwłaszcza przy nowej domenie albo po utracie klucza Sealed Secrets (`infra/k8s/README.md:183-186` odsyła tu do DEPLOY-OPS). Propozycja: krok 5 uruchamia `keycloak-config` (`rerun`) przed włączeniem aplikacji; smoke test to sprawdza.

8. **techniczne – O4 i „Smoke test”, krok 4: `restore` działa po SSH (`HOST`), a smoke test nie ma SSH.** `reinitialize` i `remote.sh` używają `ssh` i `k3s kubectl`, a smoke test woła skrypty przez `KUBE_DIR`/`KUBE_NETWORK` (`smoke.sh:383`, `rerun.sh`). Bez decyzji smoke test przetestuje kopię procedury, nie cel `restore`. Propozycja: `restore` i `backups` dostają drugi transport `KUBE_DIR` (jak `rerun`/`create-admin`) albo wspólną warstwę, z której smoke test korzysta bezpośrednio.

9. **techniczne – „Smoke test”: po dotychczasowych fazach baza nie ma produktów.** Faza r4 robi `TRUNCATE catalog_product CASCADE` i zostawia nieudane Joby `migrate` (r3) i `initialize` (r4) (`smoke.sh:575-585`). Kopia „po dotychczasowych fazach” ma 0 produktów, więc sprawdzenia „zdjęcie produktu przez imgproxy” i „produkt w wyszukiwarce” są niewykonalne. Propozycja: wskazać miejsce faz kopii, np. po `create-admin`/r2, przed r3, albo opisać przywrócenie stanu przed kopią. Znacznikiem odtworzenia ma być stan różny od świeżych danych demo (zamówienia z `checks`, administrator).

10. **techniczne – O1 i O3: wolumen i koszt weryfikacji są nieokreślone.**
    - „Wolumen roboczy” w O3 nie jest jednoznaczny. Jeśli to `backup-work`, `restic restore` nadpisze `objects/`, czyli lustro, na którym opiera się przyrostowy `rclone sync`. Jeśli to `emptyDir`, potrzeba drugiej pełnej kopii bucketu (do 20 Gi, `storage.garageData`) na dysku węzła.
    - Pełne odtworzenie co noc pobiera cały bucket z S3 (czas, transfer wychodzący). To przeczy uzasadnieniu z O1 („koszt rośnie ze zmianami”) i dubluje `--read-data-subset`.

    Propozycja: `backup-verify` odtwarza tylko dumpy i `manifest.json` (`--include`), liczbę obiektów bierze z `restic ls`, a integralność danych zapewnia `restic check` z podzbiorem. Szkic powinien podać wolumen i rozmiar `storage.backupWork` dla każdego z trzech podów (backup, verify, restore).

### Minor

- **techniczne – O4, krok 4:** brak sposobu ustalenia, które migracje zna bieżący kod, przed `DROP` (np. lista klas w `migrations/` obrazu `api`). `doctrine:migrations:list` wymaga bazy.
- **techniczne – O4:** brak zachowania, gdy w chwili `restore` trwa Job `backup` (blokada restic przy `forget --prune`) albo zostały po nim nieaktualne blokady po przerwanym podzie. Propozycja: `restore` odmawia przy aktywnym Jobie `backup`; kopia przed `forget` robi `restic unlock` (usuwa tylko nieaktualne blokady).
- **techniczne – O3:** `--read-data-subset=n/7` wymaga n od 1 do 7 (`date +%u`, nie `%w`). W smoke teście, krok 3, uszkodzony plik musi należeć do danych, które weryfikacja odczytuje.
- **techniczne – Konfiguracja:** O2 wymienia region repozytorium jako wartość, ale lista wartości (`backup.*`) go nie ma. Brakuje też wartości `startingDeadlineSeconds` i rozmiaru `storage.backupWork`.
- **techniczne – O1:** retencja snapshotów `pre-restore` jest nieopisana. `forget` „w obrębie tagu” nigdy ich nie usuwa; trzeba to zapisać jako świadomą decyzję albo dodać regułę.
- **techniczne – O5, budżet:** `test_prod_fits_the_server` (`tests/test_workloads.py:166`) liczy tylko Deployment i StatefulSet, a node-exporter i Alloy to zwykle DaemonSet. Trzeba dopisać, że budżet obejmuje DaemonSety. Dziś limity `shop` w `prod` to ok. 17 GiB z 20.
- **produktowe – O5:** logi wszystkich podów (także `api`, z danymi klientów) trafiają do Grafana Cloud. Rekomendacja: wprost zaakceptować to w specyfikacji (region UE jest już wymagany) albo wyłączyć logi wybranych kontenerów.
- **techniczne – „Zadania dokumentacji”:** `docs/features/deploy/spec.md:247` opisuje `initialize.enabled: false` „na potrzeby odtwarzania kopii w DEPLOY-OPS”, a szkic go nie używa (odtwarza na danych demo). Trzeba usunąć to zdanie albo uzasadnić tę wartość.
- **techniczne – O6, scenariusze:** zaraz po pierwszym wdrożeniu `BackupMissing` odpala z `absent`, bo `last_successful_time` nie istnieje przed pierwszą kopią. Warto to opisać w runbooku przy ręcznej kopii po wdrożeniu.

### Werdykt

**DO POPRAWY**. Jest 10 znalezisk `Important`: 6 technicznych, 2 produktowe (5, 6) i 1 architektoniczne (1). Sekcja „Wymagane przed startem: Brak” jest zasadna: obrazy i charty są publiczne, smoke test ma własne S3, a konta potrzebne są dopiero przy wdrożeniu.

Pliki, na których opierają się znaleziska:
- <home>/Development/claude/shop/.claude/worktrees/catalog-admin-ao-run-ca9fc7/docs/features/deploy-ops/spec.md
- <home>/Development/claude/shop/.claude/worktrees/catalog-admin-ao-run-ca9fc7/infra/k8s/argocd/apps/secrets.yaml
- <home>/Development/claude/shop/.claude/worktrees/catalog-admin-ao-run-ca9fc7/infra/k8s/tests/test_argocd.py
- <home>/Development/claude/shop/.claude/worktrees/catalog-admin-ao-run-ca9fc7/infra/k8s/scripts/seal-secrets.sh
- <home>/Development/claude/shop/.claude/worktrees/catalog-admin-ao-run-ca9fc7/infra/k8s/scripts/smoke.sh
- <home>/Development/claude/shop/.claude/worktrees/catalog-admin-ao-run-ca9fc7/infra/k8s/scripts/remote.sh
- <home>/Development/claude/shop/.claude/worktrees/catalog-admin-ao-run-ca9fc7/infra/k8s/charts/shop/templates/jobs.yaml
- <home>/Development/claude/shop/.claude/worktrees/catalog-admin-ao-run-ca9fc7/infra/k8s/tests/test_workloads.py
- <home>/Development/claude/shop/.claude/worktrees/catalog-admin-ao-run-ca9fc7/docs/features/deploy/spec.md
- <home>/Development/claude/shop/.claude/worktrees/catalog-admin-ao-run-ca9fc7/infra/k8s/README.md