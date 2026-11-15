## Review szkicu DEPLOY (`docs/features/deploy/spec.md`, issue #175)

Wynik: 1 znalezisko `Critical`, 11 `Important`, 9 `Minor`. Werdykt: **DO POPRAWY**.

### Critical

**C1. Kolejność wdrożenia: Job migracji jako PreSync nie zadziała przy pierwszej synchronizacji, a smoke test na Helmie nie sprawdza zachowania Argo**
- Rodzaj: architektoniczne.
- Miejsce w szkicu: „Wdrożenie nowej wersji” pkt 5–6, „Chart `shop`” (Joby), „Weryfikacja bez serwera”.
- Problem 1 (pierwsza synchronizacja): chart `shop` jest jeden (D2) i zawiera też Postgresa i Elasticsearch. Hook PreSync uruchamia się, zanim Argo założy jakikolwiek zasób aplikacji. Przy pierwszej synchronizacji Job `doctrine:migrations:migrate` i `catalog:search-index:setup` nie ma więc bazy ani ES, Job pada, a synchronizacja nigdy się nie zakończy. Runbook nie ma na to obejścia: ręcznie uruchamiany jest tylko reset danych.
- Problem 2 (pozostałe Joby): nie wiadomo, kiedy działają Job `garage-init` (imgproxy go wymaga) i Job konfiguracji Keycloaka (musi ruszyć po imporcie realmów). Szkic nie mówi, czy to hook, fala synchronizacji, czy działają przy każdej synchronizacji.
- Problem 3 (smoke test): `make -C infra/k8s smoke` instaluje chart Helmem. Helm ignoruje adnotacje hooków i fal Argo. Smoke test „Job migracji” nie sprawdza więc kolejności, która naprawdę zadziała w demo.
- Dowód: `infra/docker-compose.yml`, `depends_on` dla imgproxy → garage-init → garage oraz keycloak → postgres; `apps/api/Makefile:30-32` (`init` wymaga działającej bazy i ES).
- Rekomendowany wariant: fale synchronizacji zamiast PreSync.
  - Fala 0: usługi stanowe, konfiguracja i sekrety.
  - Fala 1: Joby `garage-init` i migracji z indeksem jako hooki `Sync`. Nieudany Job zatrzymuje synchronizację, Deploymenty starej wersji zostają.
  - Fala 2: Deploymenty aplikacji.
  - Fala 3: Job konfiguracji Keycloaka i purge jako PostSync.
- Smoke test musi tę kolejność odtworzyć: albo jawny skrypt (`helm template` po falach, `kubectl wait`), albo Argo CD w k3d. Szkic musi wybrać jedno i opisać.
- Do „Scenariuszy błędów” dopisać pierwszą synchronizację na pustym klastrze.

### Important

**I1. Nocny reset ma złą kolejność i niepełną listę seedów**
- Rodzaj: techniczne.
- Miejsce w szkicu: „Tryb demo”, nocny reset.
- Problem: szkic uruchamia `inventory:seed-demo` przed `ordering:seed-demo`. Kod i `make seed` wymagają odwrotnej kolejności, bo zamówienia trzymają rezerwacje.
- Problem: brak `payment-simulator:seed-demo` i `payment:seed-demo`, które dodaje PAYMENT. Szkic zakłada, że DEPLOY startuje po PAYMENT.
- Dowód:
  - `Makefile:30-33`;
  - `apps/api/src/Ordering/UI/Console/SeedDemoOrdersCommand.php:15` („run before inventory:seed-demo”);
  - `docs/features/payment/spec.md:371-380`.
- Propozycja: reset wykonuje dokładnie sekwencję `make seed` z jednego źródła (np. skrypt w obrazie `api`, którego używają oba miejsca), a nie listę przepisaną w chartcie.

**I2. „Reset kasuje koszyki (Redis)” jest niezgodne z kodem**
- Rodzaj: produktowe i techniczne.
- Miejsce w szkicu: „Tryb demo”.
- Problem: koszyki są w Postgresie (`cart_cart`, `cart_line`) i żaden seed ich nie czyści. Redis trzyma tylko sesje sklepu, a szkic nie mówi, jak reset czyści Redisa.
- Problem: po odtworzeniu realmu `shop` użytkownicy dostają nowe `sub`. Realmy nie mają stałych `id`, a `customer_id` koszyka to `sub`, więc koszyki klientów zostają osierocone.
- Dowód:
  - `apps/api/migrations/Version20261103120000CreateCartTables.php:23`;
  - `apps/api/src/Customer/Application/Security/AuthenticatedCustomer.php:13`;
  - `infra/keycloak/realms/shop.json:67-90` (użytkownicy bez `id`).
- Rekomendowany wariant: reset czyści też koszyki (nowa komenda `cart:seed-demo --force-reset` albo krok w skrypcie resetu) i robi `FLUSHDB` na Redisie (db 0). Mechanizm trzeba wpisać do szkicu.

**I3. Szkic nie opisuje, jak w klastrze działa reset Keycloaka**
- Rodzaj: techniczne.
- Miejsce w szkicu: „Tryb demo” i D8.
- Problem: `make keycloak-reset` usuwa bazę `keycloak` i restartuje kontener. Placeholdery realmów (`${STORE_URL}`, `${ADMIN_URL}`, `${STORE_CLIENT_SECRET}`) Keycloak podstawia tylko przy imporcie przy starcie.
- CronJob musiałby więc mieć RBAC do restartu Deploymentu `keycloak` i do uruchomienia Joba konfiguracji, czekać na gotowość, a potem odpalić konfigurację. Alternatywa to `kcadm` (usunięcie i założenie realmu) z własnym podstawianiem zmiennych. Szkic tego nie rozstrzyga.
- Dowód: `Makefile:83-88`; `infra/docker-compose.yml`, komentarz przy `keycloak` (`--import-realm`).
- Propozycja: wybrać mechanizm i opisać go. Rekomendacja: CronJob z ServiceAccount (RBAC `patch` na `deployments/keycloak` i `create` na `jobs`), kroki `DROP/CREATE DATABASE`, `rollout restart`, `rollout status`, a potem konfiguracja demo.

**I4. Konta demo panelu wymagają TOTP, a odwiedzający mogą zablokować wspólne konta**
- Rodzaj: produktowe.
- Miejsce w szkicu: „Tryb demo” (konta demo, rejestracja).
- Problem z TOTP: realm `backoffice` ma obowiązkowy formularz OTP. Lista „kont demo i haseł” nie wystarczy, żeby zalogować się do panelu.
- Problem ze ścieżką `/realms/`: wystawiona publicznie, więc działa konsola konta (`/realms/*/account`). Odwiedzający może zmienić hasło albo usunąć OTP wspólnego konta i zablokować je wszystkim do nocy.
- Problem z kontami `shop`: realm ma `resetPasswordAllowed` (e-mail i tak trafia tylko do Mailpita) i `bruteForceProtected` (celowe nieudane logowania blokują konto).
- Dowód: `infra/keycloak/realms/backoffice.json:24,64,73-74,144-147`; `infra/keycloak/realms/shop.json:12,14`; `docs/vision.md:75` (obowiązkowe 2FA pracowników).
- Rekomendowany wariant:
  - Na stronie logowania `backoffice` podać sekrety TOTP w base32 (jak README z walking skeleton) i zostawić 2FA, zgodnie z wizją.
  - Job konfiguracji wyłącza samodzielną zmianę danych logowania (rola `manage-account` poza rolami domyślnymi albo wyłączony klient `account-console`) i `resetPasswordAllowed`.
  - Ochronę brute force zostawić z krótką blokadą tymczasową.
  - Decyzja należy do użytkownika.

**I5. DSN Sentry dla przeglądarki kłóci się z „obrazem budowanym raz”**
- Rodzaj: techniczne.
- Miejsce w szkicu: „Obserwowalność” i D7.
- Problem: `@sentry/nextjs` po stronie klienta zwykle czyta `NEXT_PUBLIC_SENTRY_DSN`, który Next wkleja w czasie buildu. Ten sam obraz nie zadziała wtedy w k3d (bez DSN) i w demo (z DSN).
- Problem: `/config.js` panelu (D7) obejmuje tylko adresy, bez DSN Sentry panelu.
- Propozycja: dopisać DSN (i `environment`) do `/config.js` panelu. W sklepie przekazać DSN klientowi w czasie działania (np. z serwera w `layout.tsx`) albo jawnie ograniczyć Sentry sklepu do serwera. Wysyłkę source map wymienić albo jawnie wyłączyć z zakresu.

**I6. `output: "standalone"` razem z `next start`**
- Rodzaj: techniczne.
- Miejsce w szkicu: „Obrazy produkcyjne”, store.
- Problem: build w trybie standalone uruchamia się przez `node .next/standalone/server.js` (z ręcznie skopiowanymi `public` i `.next/static`). `next start` z tą opcją ostrzega i nie jest wspieranym sposobem uruchomienia.
- Problem: `next.config.ts` jest wspólny, więc zmiana dotyka też lokalnego `STORE_MODE=production` (CI e2e uruchamia `npm run start`). To uderza w kryterium 4.
- Dowód: `apps/store/package.json:9` (`"start": "next start"`), `infra/docker-compose.yml` (komenda `store`).
- Propozycja: w obrazie `CMD node server.js`. Opcję `standalone` włączyć tylko w buildzie obrazu (zmienna przy buildzie) albo wykazać, że lokalny tryb production działa bez zmian.

**I7. Cykl życia sekretów po pierwszym uruchomieniu**
- Rodzaj: architektoniczne.
- Miejsce w szkicu: „Sekrety” i zasada w `CLAUDE.md` ze „Zmian dokumentacji”.
- Problem: `seal-secrets.sh` losuje wartości dla całej listy `init-env.sh`. Ponowne uruchomienie przy dodaniu jednego klucza zmieni `POSTGRES_PASSWORD`, `KEYCLOAK_DB_PASSWORD`, `GARAGE_RPC_SECRET` itd., a wtedy istniejące PVC nie działają.
- Problem: szkic nie mówi, skąd pochodzi certyfikat kontrolera, czyli czy publiczny cert jest w repo. Bez tego agent kolejnego epiku (np. nowy sekret w compose) nie zaszyfruje wartości, więc nowa zasada w `CLAUDE.md` jest niewykonalna w AO.
- Dowód: `infra/scripts/init-env.sh:10-24`; PAYMENT T1 dodaje klucze „dopisywane” (`docs/features/payment/spec.md:54`).
- Rekomendowany wariant: publiczny certyfikat kontrolera w repo (`environments/demo/sealed-secrets.pem`). Skrypt dopisuje tylko brakujące klucze (osobny SealedSecret na klucz albo `kubeseal --merge-into`) i nigdy nie zmienia istniejących bez flagi.

**I8. Niepełna linia `Zakres:`**
- Rodzaj: techniczne.
- Miejsce w szkicu: nagłówek.
- Problem: brakuje istniejących plików, które epik zmienia:
  - `apps/api/composer.json`, `apps/api/composer.lock`, `apps/api/config/bundles.php` (Sentry);
  - `apps/store/package.json`, `apps/store/package-lock.json`, `apps/store/app/layout.tsx` albo inny plik inicjalizacji Sentry;
  - `apps/admin/package.json`, `apps/admin/package-lock.json`, `apps/admin/src/main.ts`;
  - `docs/adr` (nowy ADR).
- Lista na noc sprawdza z tej linii rozłączność epików, a PAYMENT zmienia część tych plików.
- Dowód: `.claude/skills/prepare-epic/SKILL.md:78-89`.
- Propozycja: uzupełnić listę.

**I9. Nieopisane źródło obrazów dla smoke testu na PR i lokalnie**
- Rodzaj: techniczne.
- Miejsce w szkicu: „Weryfikacja bez serwera”.
- Problem: wypychanie do GHCR szkic opisuje tylko dla `main`. Dla PR i dla `make -C infra/k8s smoke` nie wiadomo, czy obrazy są budowane lokalnie i ładowane przez `k3d image import`, czy wypychane z tagiem PR. Nie wiadomo też, czy wartości `ci` wskazują lokalne tagi.
- Propozycja: zapisać, że smoke test buduje obrazy (cache GHA w CI) i importuje je do k3d bez wypychania; wypychanie tylko na `main`.

**I10. Mechanizm D4 (GitHub Actions jako bypass rulesetu `deploy`) nie jest potwierdzony**
- Rodzaj: architektoniczne.
- Miejsce w szkicu: D4 i kryterium akceptacji 5.
- Problem: aplikację GitHub Actions jako aktora bypass w rulesetach repozytorium da się ustawić tylko przez API (`actor_type: Integration`). Nie jest to udokumentowana, stabilna ścieżka. Do tego `--force-with-lease` wymaga bypassu reguły `non_fast_forward`. Żadne kryterium tego nie sprawdza.
- Rekomendowany wariant: deploy key z prawem zapisu jako jedyny aktor bypass (typ `DeployKey` jest wspierany). Workflow wypycha kluczem z sekretu repo. Dodać kryterium: push do `deploy` spoza workflowu jest odrzucany.

**I11. Brak weryfikowalnych kryteriów dla trybu demo i obserwowalności**
- Rodzaj: techniczne.
- Miejsce w szkicu: „Kryteria akceptacji”.
- Problem: issue wymaga kont demo na stronie logowania, wyłączonej rejestracji, nocnego resetu oraz Alloy i Sentry. Kryteria tego nie sprawdzają.
- Propozycja: smoke sprawdza, że strona logowania obu realmów zawiera listę kont i że rejestracja w `shop` jest wyłączona. Test renderu sprawdza CronJob (`schedule`, `timeZone`) i aplikację `k8s-monitoring`. Testy SDK potwierdzają, że Sentry jest nieaktywny bez DSN w api, store i admin.

### Minor

- **M1. Suma limitów CPU (architektoniczne).** „Chart `shop`”, zasoby: suma limitów 8 vCPU to cały serwer, a szkic zostawia „resztę” dla k3s, Argo i Alloy. Rekomendacja: limity sumujące się do około 7 vCPU albo jawnie dopuszczony overcommit CPU.
- **M2. Brak harmonogramu `cart:purge-expired` (produktowe).** Komenda czeka na „crona” (`docs/features/cart/spec.md:264,688`), a demo jest pierwszym środowiskiem z cronem. Rekomendacja: dodać CronJob albo wpisać do „Poza zakresem”.
- **M3. Współbieżność.**
  - Nocny reset i wdrożenie mogą się nałożyć: `catalog:search-index:setup` odmawia przy zajętej `SearchIndexRebuildLock`, więc synchronizacja padnie (`SetupSearchIndexCommand.php`). Dopisać `concurrencyPolicy: Forbid` i zachowanie przy kolizji z synchronizacją.
  - Zasada zgodności wstecz obejmuje tylko migracje, a stary worker przez chwilę konsumuje wiadomości nowej wersji. Dopisać zgodność wiadomości Messengera.
- **M4. Keycloak.**
  - W liście ustawień brakuje `KC_HOSTNAME_BACKCHANNEL_DYNAMIC=true`, z którego korzystają wewnętrzne wywołania `http://keycloak:8080` (compose).
  - Ścieżka `/realms/` wystawia też realm `master`. Rekomendacja: wystawić tylko `/realms/shop/`, `/realms/backoffice/` i `/resources/`.
- **M5. PAYMENT.** „Płatności … jeśli jest zmergowany” przeczy „Ryzykom” („DEPLOY startuje po PAYMENT”); zapisać to bezwarunkowo. Webhooki symulatora idą wewnętrznie (`PAYMENT_WEBHOOK_BASE_URL=http://api`), a publiczny `api.<domena>` służy stronie symulatora (`PAYMENT_SIMULATOR_PUBLIC_URL`); poprawić opis w tabeli adresów (`docs/features/payment/spec.md:226-229`).
- **M6. Cloudflare i panel.**
  - Runbook potrzebuje reguły cache dla `img.<domena>`: adresy imgproxy mogą nie mieć rozszerzenia, a Cloudflare domyślnie cache'uje po rozszerzeniu.
  - Statyczny serwer panelu potrzebuje fallbacku SPA (`/auth/callback`) i nagłówków `no-cache` dla `index.html` i `/config.js` (pliki `.js` Cloudflare cache'uje domyślnie).
  - Nie wybrano obrazu statycznego serwera.
- **M7. Kolejka wdrożeń.** „Jeden naraz, kolejne czekają”: grupa `concurrency` GitHub trzyma tylko jeden oczekujący run, pośrednie anuluje. Opisać to (wdraża się najnowszy commit).
- **M8. Scenariusze błędów.**
  - Brak scenariusza „nieudany PostSync” (purge): wersja już działa, a synchronizacja jest czerwona.
  - Brak scenariusza „ręczny rollback o więcej niż jedną wersję”: schemat jest zgodny wstecz tylko z poprzednią wersją.
  - Nie wiadomo, jaką wartość ma `images.yaml` na `main` (render `demo` w `check` potrzebuje tagu).
- **M9. Nowe zależności i bramka chartu (architektoniczne).**
  - Obrazy narzędzi (helm, kubeconform, k3d, kubeseal, shellcheck, serwer statyczny) i charty platformy to nowe zależności. Pytanie w kroku 4 obejmuje tylko Sentry.
  - `make -C infra/k8s check` nie jest wymaganym checkiem, więc rozjazd compose i chartu z kolejnych epików AO wyjdzie dopiero po merge'u na `main`. Rekomendacja: statyczny `check` chartu w wymaganym jobie `checks` (tani), smoke test nadal niewymagany.

### Werdykt

**DO POPRAWY.** Najpierw trzeba poprawić C1 (kolejność wdrożenia przy pierwszej synchronizacji i jej odwzorowanie w smoke teście) oraz I1–I4 (nocny reset i konta demo).

Pliki:
- <home>/Development/claude/shop/.claude/worktrees/parallel-environment-setup-3d960b/docs/features/deploy/spec.md
- <home>/Development/claude/shop/.claude/worktrees/parallel-environment-setup-3d960b/Makefile
- <home>/Development/claude/shop/.claude/worktrees/parallel-environment-setup-3d960b/infra/docker-compose.yml
- <home>/Development/claude/shop/.claude/worktrees/parallel-environment-setup-3d960b/infra/keycloak/realms/backoffice.json
- <home>/Development/claude/shop/.claude/worktrees/parallel-environment-setup-3d960b/infra/keycloak/realms/shop.json
- <home>/Development/claude/shop/.claude/worktrees/parallel-environment-setup-3d960b/apps/api/src/Ordering/UI/Console/SeedDemoOrdersCommand.php
- <home>/Development/claude/shop/.claude/worktrees/parallel-environment-setup-3d960b/apps/api/migrations/Version20261103120000CreateCartTables.php
- <home>/Development/claude/shop/.claude/worktrees/parallel-environment-setup-3d960b/docs/features/payment/spec.md