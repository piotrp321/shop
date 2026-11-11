# Kopie zapasowe poza serwerem (restic do Cloudflare R2)

## Kontekst

Sklep działa na jednym serwerze (`docs/adr/k3s-argo-cd.md`): dane (bazy PostgreSQL `shop` i `keycloak`, obiekty Garage ze
zdjęciami produktów) leżą na wolumenach tego serwera. Backup VPS-a od OVH jest tylko dodatkiem: leży u tego samego
dostawcy, a jego przywrócenie cofa cały serwer. Potrzebna jest kopia danych sklepu poza serwerem, szyfrowana, tania i
odtwarzalna na pustym klastrze. Specyfikacja: `docs/features/deploy-ops/spec.md` (O1, O2).

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
- **Dostęp: jeden sekret `shop-backup`** (`RESTIC_PASSWORD`, klucz i sekret S3), SealedSecret tworzony raz przez
  `make -C infra/k8s seal-secrets WHAT=backup`; adres repozytorium i region to wartości `environments/prod`.

## Konsekwencje

- Hasło restic jest jedyną rzeczą, bez której kopie są nie do odczytania; użytkownik trzyma je też w menedżerze haseł,
  poza serwerem i poza kluczem Sealed Secrets.
- Klucz R2 w klastrze pozwala usunąć kopie. Ograniczenie: token tylko do jednego bucketu. Kopie niezmienne (S3 Object
  Lock) są poza zakresem demo; decyzja wraca przed uruchomieniem publicznym.
- Dump i lustro obiektów powstają w różnych chwilach (sekundy–minuty), więc nie są ściśle spójne; odtworzenie tego nie
  wymaga.
- Dump logiczny rośnie z bazą; przy docelowej skali (`docs/vision.md`) potrzebne będą kopie fizyczne z WAL.
- Smoke test sprawdza kopię na osobnej instancji S3 (przestrzeń `smoke-backup`), bez kont zewnętrznych.
