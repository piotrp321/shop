## Krytyka szkicu `docs/features/deploy-ops/spec.md` (DEPLOY-OPS, #178)

Plik szkicu: <home>/Development/claude/shop/.claude/worktrees/catalog-admin-ao-run-ca9fc7/docs/features/deploy-ops/spec.md

### Important

**1. Identyfikator klastra wymaga dostępu do API, a pody kopii mają być bez tokenu.** Rodzaj: architektoniczne.
- **Miejsce:** O1 krok 4, O3 krok 1, „Smoke test i check”.
- **Dowód:** O1 (linia 63) każe ustawić `--host` na UID przestrzeni `kube-system`. `check` (linia 202) wymaga „brak tokenu API w podach kopii”. Bez tokenu pod nie odczyta UID. Helm `lookup` w Argo CD zwraca pusty wynik. Chart ma dziś tylko `Role` `shop-wait` w przestrzeni `shop` (`infra/k8s/charts/shop/templates/rbac.yaml`). Pody mają `automountServiceAccountToken: false`, a initContainery dostają osobny wolumen tokenu (`shop.kubeApiAccessVolume` w `jobs.yaml`).
- **Wariant odrzucony:** identyfikator jako wartość chartu. Nowy serwer z tymi samymi wartościami `prod` dostałby ten sam host, a `forget` usunąłby kopie starego serwera. To jest przypadek, przed którym szkic ma chronić.
- **Rekomendacja:** initContainer z wolumenem tokenu (wzorzec jak `purge-caches`). Dostaje `ClusterRole` z samym `get` na `namespaces`, `resourceNames: [kube-system]`, i zapisuje UID do `emptyDir`. Kontenery z sekretami restic zostają bez tokenu. Kryterium w `check` trzeba doprecyzować do „kontenery z sekretem `shop-backup`”.

**2. Odmowa „snapshot nowszy niż kod” przychodzi dopiero po wyłączeniu sklepu.** Rodzaj: techniczne.
- **Miejsce:** O4 kroki 3, 4 i 6; „Smoke test” krok 5; „Scenariusze błędów”.
- **Dowód:** sprawdzenie migracji jest w Jobie odtworzenia (krok 4). Wcześniej krok 3 wyłącza synchronizację Argo i skaluje `api`, `worker`, `store`, `keycloak` do zera. Krok 6 mówi, że błąd w krokach 3–5 nie włącza aplikacji. Smoke krok 5 („odmowa bez zmian”) i dalsze fazy r2–r4 („na odtworzonym klastrze”) zastałyby więc wyłączony sklep. Ta sama odmowa robi też niepotrzebną kopię `pre-restore`.
- **Propozycja:** sprawdzać migracje w kroku 1, w Jobie w trybie listy, który i tak czyta manifest (obraz `api` zna listę klas migracji). Odmowa ma zapaść przed potwierdzeniem, kopią `pre-restore` i skalowaniem. Ewentualnie jawnie opisać, że ta odmowa przywraca aplikacje.

**3. `restore` nie sprawdza innych aktywnych Jobów ani trwającej synchronizacji.** Rodzaj: techniczne.
- **Miejsce:** O4 krok 1; „Scenariusze błędów”.
- **Dowód:** szkic odmawia tylko przy aktywnym `backup` albo `restore`. Chart ma też Joby `migrate`, `keycloak-config`, `initialize` i `purge-caches` z każdej rewizji (`jobs.yaml` 205–216) oraz CronJob czyszczenia koszyków. Smoke krok 4 uruchamia `restore` zaraz po instalacji, gdy `initialize` może jeszcze pisać do bazy. `DROP DATABASE` w trakcie `migrate` albo `initialize` daje niezdefiniowany stan.
- **Propozycja:** odmowa bez zmian, gdy w przestrzeni `shop` jest aktywny dowolny Job. Przy Argo CD także, gdy aplikacja `shop` ma trwającą operację synchronizacji. Smoke czeka na zakończenie `initialize` przed `restore`. Dopisać wiersz do tabeli błędów.

**4. `k8s-monitoring` od wersji 3 instaluje Alloy Operator, a szkic tego nie uwzględnia.** Rodzaj: architektoniczne.
- **Miejsce:** O5; „Smoke test i check” (budżet, kubeconform).
- **Dowód:** zależności `Chart.yaml` wydania 4.6.0 (grafana/k8s-monitoring-helm) zawierają `alloy-operator`. Przykładowy render `docs/examples/features/pod-logs-via-kubernetes-api/output.yaml` zawiera zasób `kind: Alloy` (`collectors.grafana.com/v1alpha1`), Deployment operatora i hooki Helma (`post-install` dodający finalizer, `pre-delete`). Skutki:
  - DaemonSet i StatefulSet Alloy tworzy operator, więc nie ma ich w renderze, a szkic każe liczyć budżet „z DaemonSetami” z renderu;
  - zasób `Alloy` i jego CRD są w tej samej synchronizacji, co zwykle wymaga `SkipDryRunOnMissingResource`;
  - Argo CD wykonuje hooki Helma jako PostSync;
  - obrazy aplikacji `monitoring` nie są przypięte digestem.
  Szkic nie wybiera też głównej wersji chartu, a v4 zmienił format `destinations` i `collectors`.
- **Rekomendacja:** przypiąć 4.x i nazwać operator w O5. Budżet liczyć z `spec.alloy.resources` zasobów `Alloy`, z deploymentu operatora, z kube-state-metrics i z node-exportera. Do `monitoring` dodać `SkipDryRunOnMissingResource`. Napisać wprost, czy obrazy `monitoring` muszą mieć digest. Schemat `alloy_v1alpha1.json` jest w przypiętym commicie CRDs-catalog, więc kubeconform zadziała.

**5. HARDENING przekazuje DEPLOY-OPS alerty, których szkic nie zawiera.** Rodzaj: produktowe.
- **Miejsce:** „Cel i zakres”, O6, „Poza zakresem”.
- **Dowód:** `docs/features/hardening/spec.md`, D7 (linia 51): „alerty z logów i health checku należą do monitoringu (DEPLOY-OPS, #178)”, w kontekście awarii Redisa przy rate limitingu działającym w trybie fail open. Szkic ma tylko 6 alertów: kopie, dysk i brak sygnału.
- **Rekomendacja:** jeden alert z kube-state-metrics na niegotowy Deployment albo StatefulSet `shop` dłużej niż 10 min (obejmuje readinessProbe i health check), z testem `promtool`. Wariant B: jawnie wyłączyć ten alert i poprawić D7 w specyfikacji HARDENING.

### Minor

6. **Zła nazwa CronJoba (O4 krok 3).** CronJob nazywa się `cart-purge-expired` (`infra/k8s/charts/shop/templates/jobs.yaml:221`), nie `cart-purge`. Rodzaj: techniczne.

7. **Sprzeczne miejsce adresów Grafana Cloud (Runbook, linia 239).** Runbook każe wpisać adresy do `environments/prod/values.yaml`. O5 i O7 umieszczają je w `valuesObject` aplikacji `monitoring`, a zdalny chart nie czyta wartości `prod`. Rodzaj: techniczne. Propozycja: runbook wskazuje `argocd/apps/monitoring.yaml`.

8. **Niedookreślone sekrety (Konfiguracja, O2, O5).** Rodzaj: techniczne.
   - Nie wiadomo, jak profil `ci` dostarcza sekret `backup.secretName`: `secrets.create` renderuje dziś tylko `shop-secrets` z `secrets.values`.
   - Brak nazw kluczy sekretu `grafana-cloud`. Chart domyślnie używa kluczy `username` i `password`, a Loki i Prometheus mają różne identyfikatory instancji. Nazwy są potrzebne testowi kluczy (`EXPECTED_KEYS` w `tests/test_sealed_secrets.py`).
   - Nie wiadomo, czy `seal-secrets` bez `WHAT` obejmuje `backup` i `grafana`.

9. **Brak limitu czasu Jobów (O1, O3, O4).** `backup`, `backup-verify` i `restore` nie mają `activeDeadlineSeconds`. Zawieszony `rclone` albo `pg_dump` przy `concurrencyPolicy: Forbid` blokuje kolejne kopie: wykryje to dopiero `BackupMissing` po 26 h. Skrypt `restore` nie ma określonego limitu czekania na Joby. Rodzaj: techniczne.

10. **Blokady restic (Scenariusze błędów).** `restic check` bierze blokadę wyłączną. Kopia `pre-restore` albo ręczna w trakcie `backup-verify` (i odwrotnie) kończy się błędem od razu i odpala fałszywy `BackupFailed` albo `BackupVerifyFailed`. Rodzaj: techniczne. Propozycja: `--retry-lock` z limitem czasu.

11. **Maskowanie tylko w logach podów (O7).** Etapy maskowania są tylko w funkcji logów podów, a `clusterEvents` też idą do Loki. To przeczy zdaniu „maskowanie we wszystkich źródłach”. Rodzaj: techniczne. Propozycja: te same etapy dla zdarzeń klastra albo zdanie, że zdarzeń się nie maskuje.

12. **Zadania dokumentacji pomijają dwa zdania wizji.** `docs/vision.md` (linie 247 i 326–327) obiecuje w DEPLOY-OPS trace'y przez Alloy, a szkic przenosi je do #180. Zdanie „gotowe charty tylko dla platformy (Argo CD, Sealed Secrets)” trzeba poszerzyć o nowe charty. Rodzaj: techniczne.

13. **HARDENING nie jest wpisany jako warunek startu.** HARDENING ma dziś na `main` tylko `plan.md`, czyli jest niezrealizowany, i zmienia `infra/k8s`. Szkic wspomina o tym tylko w „Ryzyka”. Warto dopisać „HARDENING na `main`” do „Wymagane przed startem”. Reszta tej sekcji jest kompletna: obrazy i charty są publiczne, a smoke test ma własne S3. Rodzaj: techniczne.

Opcje chartu `k8s-monitoring`, na których opiera się szkic, istnieją w wydaniu 4.6.0: `rules.enabled`, `secret.namespace`, `podLogsViaKubernetesApi`, `extraLogProcessingStages`, `extraDiscoveryRules`. Komendy `catalog:reindex` i `storefront:cache:purge-all` istnieją w API.

### Werdykt
**DO POPRAWY.** Jest 5 znalezisk Important: 1 i 4 to decyzje architektoniczne dla użytkownika, 5 to decyzja produktowa.