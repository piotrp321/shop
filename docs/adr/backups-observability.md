# Kopie zapasowe poza serwerem (restic do Cloudflare R2)

## Kontekst

Sklep działa na jednym serwerze (`docs/adr/k3s-argo-cd.md`): dane (bazy PostgreSQL `shop` i `keycloak`, obiekty Garage ze
zdjęciami produktów) leżą na wolumenach tego serwera. Backup VPS-a od OVH jest tylko dodatkiem: leży u tego samego
dostawcy, a jego przywrócenie cofa cały serwer. Potrzebna jest kopia danych sklepu poza serwerem, szyfrowana, tania i
odtwarzalna na pustym klastrze. Specyfikacja: `docs/features/deploy-ops/spec.md` (O1, O2, O3).

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
  (`restic dump` strumieniowo do `pg_restore`, nic nie trafia na dysk; tabela migracji `shop` niepusta, realmy `shop`
  i `backoffice` w `keycloak`) i zgodność liczby obiektów (`restic ls`) z manifestem. Osobny CronJob zamiast kroku
  kopii: czyta repozytorium tak, jak odczyta je odtworzenie, a jego wynik i brak można alertować niezależnie od kopii. Tymczasowy
  Postgres to natywny sidecar poda (`initContainers` z `restartPolicy: Always`, dane na `emptyDir`, tylko pętla zwrotna),
  więc Job kończy się razem ze skryptem; `restic` pochodzi z obrazu `restic/restic` zamontowanego jako wolumen obrazu
  w kontenerze z obrazem `postgres`, który ma `pg_restore`. Pod używa konta usługi i roli kopii (identyfikator klastra);
  sekret `shop-backup` czyta tylko kontener skryptu, bez tokenu, a sekretów produkcyjnej bazy nie ma żaden.
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
- Weryfikacja odczytuje w tygodniu całe repozytorium, ale tylko raz w tygodniu każdy jego fragment: uszkodzenie danych
  wychodzi najwyżej po 7 dniach (retencja kopii to 7 dni, więc uszkodzony snapshot może wygasnąć, zanim go odczyta). Dump
  odtwarza się w pustej bazie, więc nie wyłapie błędów widocznych dopiero przy pracy aplikacji.
- Smoke test sprawdza kopię i jej weryfikację na osobnej instancji S3 (przestrzeń `smoke-backup`), bez kont zewnętrznych.
