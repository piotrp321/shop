# 10. Gotowość produkcyjna

Audyt statyczny migawki `main` (`ef204620`). Ścieżki są względne wobec katalogu eksportu.

## Zakres i metoda

Przeczytałem w całości albo w przeważającej części około 75 plików, a przeszukałem (`grep`) dalsze kilkadziesiąt:

- chart Helm `infra/k8s/charts/shop`: wszystkie 24 szablony, `values.yaml`, `environments/prod/values.yaml`, skrypty
  `backup.sh`, `backup-verify.sh` (część), `initialize.sh`;
- `infra/docker-compose.yml`, `compose.yaml`, `infra/varnish/default.vcl`, `infra/garage/garage.toml`,
  `infra/postgres/init/10-create-databases.sh`, fragmenty `infra/k8s/scripts/server-bootstrap.sh`;
- `infra/k8s/README.md` (runbook, 515 linii, w całości), `infra/k8s/argocd/root.yaml`, `apps/shop.yaml`, lista metryk w
  `apps/monitoring.yaml`;
- `.github/workflows/deploy.yml` (w całości), `ci.yml` (struktura jobów);
- `docs/adr/k3s-argo-cd.md`, `docs/adr/backups-observability.md` (w całości), `docs/vision.md` (wymagania
  niefunkcjonalne i środowisko wdrożeniowe), `docs/features/deploy/spec.md` (zakres, chart, wdrożenie, ryzyka),
  `hardening/spec.md` (correlation ID, ryzyka), wyrywkowo `deploy-ops`, `payment`, `product-page`;
- API: `config/packages/{messenger,doctrine,framework,monolog,sentry,mailer,rate_limiter,flysystem}.yaml`,
  `config/services.yaml`, `Dockerfile`, `docker/php/conf.d/*.ini`, `docker/frankenphp/Caddyfile`, wszystkie 41 migracji
  (przeszukane pod kątem DDL, 4 przeczytane), `HealthController`, sondy, `RelayDomainEventToBroker`,
  `RoutedAmqpTransport`, `MessagingStatisticsAdapter`, `KeySourceOutageTokenHandler`, `ExpireUnpaidOrderHandler`,
  `HttpStorefrontCache`, reguły migracji i zgodności wersji w `apps/api/CLAUDE.md`;
- sklep: `Dockerfile`, `next.config.ts`, `src/api/client.ts`, `src/auth/{redis,deps}.ts`, `src/catalog/load-catalog.ts`,
  `src/cart/bff.ts`; panel: `Dockerfile`.

Niczego nie uruchamiałem. Wnioski o zachowaniu bibliotek (Symfony Messenger, FrankenPHP, undici) pochodzą z ich znanego
działania, bo `vendor` i `node_modules` nie są w eksporcie; zaznaczam to przy pewności.

## Ocena ogólna obszaru

**6/10 jako gotowość do przyjmowania prawdziwych zamówień; 8/10 jako środowisko demonstracyjne, którym jest dziś.**

Inżynieria wdrożenia jest ponadprzeciętna jak na projekt jednoosobowy: obrazy po digeście, smoke test na tych samych
obrazach przed publikacją, migracje jako Job przed rolloutem z regułą zgodności N−1, transakcyjny outbox, kopie restic
z codzienną weryfikacją odtworzenia, alerty testowane `promtool`, runbook z procedurami awaryjnymi i dokumenty, które
same nazywają swoje ograniczenia. To nie jest deklaracja: jest w kodzie i ma testy.

Ocenę obniżają cztery rzeczy:

1. W migawce kopie zapasowe są w `prod` wyłączone, a `docs/vision.md` opisuje je w czasie teraźniejszym.
2. Cały stan leży na jednym dysku jednego VPS-a przy RPO 24 h. To świadoma decyzja dla demo, ale ADR odkłada jej
   zmianę do „docelowej skali”, a nie do pierwszego prawdziwego zamówienia.
3. Przetwarzanie asynchroniczne (wygaszanie zamówień, e-maile, indeks, promocje) nie ma żadnego nadzoru: brak metryk
   kolejek, alertów i procedury dla kolejki `failed`.
4. Obserwowalność kończy się na infrastrukturze. Żaden alert nie powie, że checkout zwraca 500 przy zdrowych podach.

Nie znalazłem błędu klasy Critical ani wady, która dziś psuje działające demo.

## Znaleziska

### Z1. Kopie zapasowe są w `prod` wyłączone, a wizja opisuje je jako działające

- **Waga:** High. **Klasa:** RYZYKO (stan konfiguracji, nie wada kodu).
- **Dowód:**
  - `infra/k8s/environments/prod/values.yaml:74-78`:
    ```yaml
    backup:
      enabled: false
      repository: s3:https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com/shop-backups
      s3Region: auto
      secretName: shop-backup
    ```
    Adres repozytorium to atrapa (identyfikator konta `0123…cdef`).
  - `infra/k8s/environments/prod/secrets/` zawiera `grafana-cloud.yaml`, `shop-brevo-smtp.yaml`, `shop-origin-tls.yaml`,
    `shop-secrets.yaml`. Nie ma `shop-backup.yaml`.
  - `infra/k8s/charts/shop/templates/backup.yaml:11` i `00-alerts.yaml:56`: CronJoby `backup`, `backup-verify` oraz
    cztery alerty kopii renderują się tylko przy `backup.enabled`.
  - `docs/vision.md:344-345`: „**Kopie zapasowe**: codzienna, szyfrowana (restic) kopia baz PostgreSQL i obiektów
    Garage do bucketu Cloudflare R2”; `:350-354`: „siedem reguł … Kopie są codziennie weryfikowane”.
  - Runbook mówi prawdę: `infra/k8s/README.md:169-170` („Kopie są w `prod` wyłączone … dopóki nie ma bucketu i sekretu”)
    i `:445` („przy kopiach wyłączonych w `prod`: trzy reguły”).
- **Konsekwencja:** awaria dysku albo błędna operacja (`reinitialize`, zła migracja) kasuje bazę `shop`, bazę `keycloak`
  (konta administratorów z TOTP) i zdjęcia produktów bez możliwości odtworzenia. Jedyną kopią jest wtedy backup VPS-a
  w OVH, który jest krokiem „użytkownik” runbooka (`README.md:227`) i z repo nie da się go sprawdzić. Dodatkowo
  mechanizm kopii nigdy nie działał na prawdziwym R2: sprawdza go tylko smoke test na lokalnym S3.
- **Rekomendacja:** dokończyć kroki 3.2–3.5 runbooka (bucket, `seal-secrets WHAT=backup`, `backup.enabled: true`),
  wykonać ćwiczenie odtworzenia na serwerze (sekcja 8) i zapisać czas. Do tego momentu poprawić `docs/vision.md`, żeby
  opisywała stan obecny (zasada projektu: dokumentacja opisuje wyłącznie stan obecny).
- **Pewność:** wysoka co do stanu migawki. Sprawdziłem wartości, katalog sekretów, warunki renderu i runbook. Nie wiem,
  czy stan serwera jest inny niż repo; Argo CD z `selfHeal` (`argocd/apps/shop.yaml:27-29`) na to nie pozwala.

### Z2. RPO 24 h, retencja 7 dni i jeden dysk dla danych transakcyjnych; RPO i RTO nie są nigdzie określone

- **Waga:** High (dla prawdziwych zamówień), Low (dla demo). **Klasa:** RYZYKO, świadomy kompromis.
- **Dowód:**
  - `infra/k8s/charts/shop/values.yaml:135` `schedule: "0 3 * * *"` i `:143` `retention: 7d`.
  - `_helpers.tpl:336` `storageClassName: {{ $.root.Values.storage.className }}` przy `values.yaml:161`
    `className: local-path`: każdy wolumen (Postgres, Garage, RabbitMQ, Redis, Elasticsearch) to katalog na dysku węzła.
  - `docs/adr/backups-observability.md:18-20`: „Dump logiczny zamiast kopii fizycznej … odtwarzanie do punktu w czasie
    (WAL) wymaga osobnej decyzji przy docelowej skali”.
  - `grep -rn "RPO\|RTO" docs infra/k8s/README.md` nie znajduje niczego.
- **Konsekwencja:** scenariusz: dysk pada o 22:00. Ostatnia kopia jest z 03:00, więc znika 19 godzin zamówień.
  Przy prawdziwym dostawcy płatności klienci zapłacili, a sklep nie ma ani zamówień, ani rezerwacji, ani adresów
  wysyłki. Retencja 7 dni oznacza też, że uszkodzenie logiczne zauważone po 8 dniach (np. błędny backfill) jest
  nieodwracalne. Sam ADR przyznaje (`:160-161`), że uszkodzony snapshot może wygasnąć, zanim weryfikacja go odczyta.
- **Ocena decyzji:** dla demo z danymi z seeda jest rozsądna. Niesłuszne jest przypisanie PITR do „docelowej skali”:
  to wymóg pierwszego prawdziwego zamówienia, niezależny od liczby produktów.
- **Rekomendacja:** przed prawdziwymi zamówieniami zapisać w ADR liczby RPO i RTO. Najtańsza droga do RPO rzędu minut
  na jednym węźle: archiwizacja WAL do tego samego R2 (pgBackRest albo WAL-G) obok nocnego dumpu, retencja 30 dni
  (restic deduplikuje, koszt rośnie ze zmianami). Rozważyć Object Lock na buckecie (ADR `:155-156` odkłada to „przed
  uruchomieniem publicznym”: klucz R2 w klastrze pozwala dziś usunąć kopie).
- **Pewność:** wysoka. Sprawdziłem ADR i specyfikację: kompromis jest opisany, liczby RPO i RTO nie.

### Z3. Kolejka `failed` i opóźnione komendy nie mają nadzoru ani procedury

- **Waga:** High (dla prawdziwych zamówień). **Klasa:** RYZYKO.
- **Dowód:**
  - `apps/api/config/packages/messenger.yaml:4` `failure_transport: failed`; `:59-64` (`scheduled`, 5 prób w około
    2 minuty): „after 5 tries … the message goes to `failed` and the order stays new”.
  - `docs/features/payment/spec.md:651-652`: „`ExpireUnpaidOrder` po wyczerpaniu ponowień trafia do `failed`:
    zamówienie zostaje `new`, a rezerwacja aktywna, aż ktoś ponowi wiadomość (`bin/console messenger:failed:retry`)”.
  - Panel pokazuje tylko outbox: `apps/api/src/Backoffice/Infrastructure/MessagingStatisticsAdapter.php:33-35`:
    ```php
    public function outboxPending(): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM messenger_messages WHERE queue_name = ?', [self::OUTBOX_QUEUE]);
    ```
    Liczby wiadomości w `failed`, `scheduled`, `promotions` i `jobs` nie pokazuje nic.
  - `infra/k8s/charts/shop/templates/00-alerts.yaml`: siedem reguł, wszystkie infrastrukturalne (`:59`, `:67`, `:75`,
    `:83`, `:96`, `:108`, `:121`). `README.md:414`: „Alerty z treści logów … nie istnieją”.
  - `infra/k8s/Makefile` nie ma celu dla `messenger:failed:retry`, a `grep -rn "messenger:failed" infra` nic nie
    znajduje. Runbook pozwala operować na serwerze wyłącznie celami make (`README.md:11-13`).
  - Jedyny sygnał to zdarzenie Sentry przy ostatniej próbie (`config/packages/sentry.yaml:36-37`
    `capture_soft_fails: false`), a reguły powiadomień Sentry są „poza repozytorium” (`README.md:512-513`).
  - Nie ma zadania zabezpieczającego: lista komend konsoli (`grep AsCommand`) nie zawiera przeglądu przeterminowanych
    zamówień `new`. Wygaszenie zależy od jednej wiadomości na zamówienie.
- **Konsekwencja:** scenariusz: krótka awaria bazy albo trwały błąd handlera podczas wygaszania. Wiadomość ląduje
  w `failed`. Zamówienie zostaje `new`, rezerwacja trzyma towar, a kod rabatowy pozostaje użyty. Sprzedawca widzi
  „brak w magazynie” dla towaru, który leży na półce, i nie ma w panelu żadnej wskazówki dlaczego. To samo dotyczy
  e-maili z potwierdzeniem, indeksu wyszukiwarki i granic promocji (cena promocyjna, która nie wygasła).
- **Rekomendacja (proporcjonalna):**
  1. jedna liczba w pulpicie panelu i jedna reguła alertu: liczba wiadomości w `failed` > 0 oraz wiek najstarszej
     wiadomości gotowej do pobrania (`available_at < now() − 5 min`) w `messenger_messages`. Ten jeden wskaźnik
     wykrywa martwego workera, zawieszonego workera i awarię RabbitMQ;
  2. cel `make -C infra/k8s failed-messages` (lista i ponowienie) z opisem w runbooku;
  3. dla zamówień: CronJob albo krok workera, który raz na kilka minut wygasza zamówienia `new` po terminie. Handler
     jest idempotentny (`ExpireUnpaidOrderHandler.php:20-23`), więc to kilkanaście linii.
- **Pewność:** wysoka. Sprawdziłem konfigurację transportów, adapter pulpitu, szablon alertów, Makefile, runbook
  i specyfikację płatności, która sama opisuje ten skutek.

### Z4. Jeden proces workera na sześć transportów: awaria RabbitMQ zatrzymuje też komendy z Postgresa

- **Waga:** Medium. **Klasa:** RYZYKO.
- **Dowód:**
  - `infra/k8s/charts/shop/templates/api.yaml:136`:
    ```yaml
    command: ["php", "bin/console", "messenger:consume", "outbox", "events", "scheduled", "payment_simulator", "promotions", "jobs"]
    ```
    Brak `--time-limit` i `--memory-limit`. `_helpers.tpl:244` `replicas: 1`.
  - `messenger.yaml:41`: `events` to AMQP, pozostałe pięć transportów to Doctrine. DSN AMQP nie ma `read_timeout`,
    `write_timeout` ani `heartbeat`.
  - `api.yaml:157`: `wait-for-rabbitmq` jest initContainerem, więc działa tylko przy tworzeniu poda, nie przy
    restarcie kontenera.
  - `api.yaml:140-148`: sonda gotowości workera sprawdza wyłącznie wiersz poleceń procesu 1.
- **Warunki i skutek:** pętla `Worker` Symfony odpytuje odbiorniki po kolei i nie łapie wyjątku z `get()`. Gdy
  RabbitMQ nie przyjmuje połączeń, odbiornik `events` rzuca `TransportException`, komenda kończy się błędem, a pod
  wpada w `CrashLoopBackOff` (odstępy do 5 minut). W każdym cyklu worker zdąża obsłużyć co najwyżej wiadomości
  `outbox` (które i tak nie mają dokąd trafić) i ginie, zanim dojdzie do `scheduled`, `promotions` i `jobs`. Awaria
  brokera opóźnia więc wygaszanie nieopłaconych zamówień i granice promocji, choć te komendy leżą w Postgresie
  i brokera nie potrzebują. Po powrocie RabbitMQ wszystko się nadrabia (wiadomości są trwałe), więc skutkiem jest
  opóźnienie, nie utrata. Przy awarii dłuższej niż około 25 minut zdarzenia z outboxa lądują w `failed`
  (`messenger.yaml:33-39`) i wracamy do Z3.
- **Prawdopodobieństwo:** niskie na jednym węźle (RabbitMQ pada zwykle razem ze wszystkim), wyższe przy aktualizacji
  RabbitMQ albo problemie z jego wolumenem.
- **Rekomendacja:** dwa Deploymenty z tego samego obrazu: `worker-events` (`outbox`, `events`) i `worker-commands`
  (`scheduled`, `promotions`, `payment_simulator`, `jobs`). To zmiana w szablonie, bez zmiany kodu, a przy okazji
  wolny handler e-maila (limit 10 s na operację gniazda) przestaje blokować wygaszanie. Dodać `--time-limit=3600`
  i `--memory-limit=200M` (limit PHP to 256M, `docker/php/conf.d/app.ini:2`), żeby proces kończył się czysto, a nie
  błędem krytycznym w środku wiadomości.
- **Pewność:** średnia. Konfigurację i szablony przeczytałem; zachowanie `Worker::run()` i `AmqpReceiver` znam
  z kodu Symfony, którego w eksporcie nie ma. W testach (`tests/Integration/Shared/Messaging`) nie znalazłem
  przypadku „worker przy niedostępnym brokerze”.

### Z5. Brak limitów czasu na bazie i w wywołaniach sklep → API

- **Waga:** Medium. **Klasa:** RYZYKO.
- **Dowód:**
  - `apps/api/config/packages/doctrine.yaml:2-9`: połączenie bez `options` (brak `connect_timeout`), a
    `grep -rnE "statement_timeout|lock_timeout|idle_in_transaction"` w `apps/api/src`, `config`, `migrations`
    i `infra` znajduje te ustawienia wyłącznie w testach.
  - `infra/k8s/charts/shop/templates/postgres.yaml`: kontener bez argumentów i bez pliku konfiguracji, czyli
    domyślne `max_connections=100` i `shared_buffers=128MB` przy limicie pamięci 2 Gi.
  - `apps/store/src/api/client.ts:14-21`: klient API bez `signal`. `grep -rnE "AbortSignal|AbortController"`
    w `apps/store/src` i `app` nie znajduje niczego poza OIDC (`src/auth/oidc.ts:123`).
  - `infra/varnish/default.vcl:10-13`: backend bez `.connect_timeout`, `.first_byte_timeout` i sondy.
  - Dla kontrastu, tam gdzie limity są: `framework.yaml:9-12` (klient HTTP 2 s i 5 s), `services.yaml:20-25`
    (Elasticsearch 1 s i 2 s), `rate_limiter.yaml:11` (Redis 1 s), `MAILER_DSN … ?timeout=10`, `sentry.yaml:34-35`.
- **Warunki i skutek:** wolne zapytanie albo blokada wiersza (np. `SELECT … FOR UPDATE` czekający na zawieszoną
  transakcję, `ALTER TABLE` z migracji). Wątki FrankenPHP czekają bez ograniczenia, pula się wyczerpuje i przestają
  odpowiadać także ścieżki, które bazy nie potrzebują, łącznie z `/health`. Sklep czeka na API bez limitu (domyślne
  limity `fetch` w Node to minuty), więc renderowanie SSR też się kolejkuje, aż Cloudflare zwróci klientowi błąd.
  Jedno zablokowane zapytanie potrafi w ten sposób zatrzymać cały sklep.
- **Prawdopodobieństwo:** niskie przy 200 produktach, rosnące z danymi i z liczbą zadań w tle (backfill, reindeks).
- **Rekomendacja:** na roli `shop` ustawić `statement_timeout` (np. 15 s), `lock_timeout` (5 s)
  i `idle_in_transaction_session_timeout` (60 s), z jawnym podniesieniem w komendach wsadowych. W sklepie
  `AbortSignal.timeout()` w `createApiClient` (5–10 s). W VCL `.first_byte_timeout` i sonda backendu.
- **Pewność:** wysoka co do braku ustawień; średnia co do dokładnego czasu, po jakim PHP przerwie żądanie czekające
  na bazę (zależy od trybu `max_execution_time` w FrankenPHP, czego statycznie nie sprawdzę).

### Z6. Żaden workload nie ma sondy liveness

- **Waga:** Medium. **Klasa:** RYZYKO.
- **Dowód:** `grep -rnE "livenessProbe|startupProbe" infra/k8s/charts/shop` zwraca jeden wynik: `startupProbe`
  tymczasowego Postgresa w `backup-verify.yaml:51`. `api.yaml:122-128`, `store.yaml:38-45` i pozostałe szablony mają
  tylko `readinessProbe`. ADR i specyfikacja DEPLOY nie wspominają o tej decyzji.
- **Skutek:** proces, który żyje, ale nie pracuje (worker czekający na gnieździe AMQP bez `read_timeout`, zakleszczony
  serwer Node, FrankenPHP z wyczerpaną pulą), nie jest restartowany. Dla API i sklepu `WorkloadNotReady` odpali po
  100 s niegotowości plus 10 minut, a naprawa jest ręczna. Dla workera sonda sprawdza tylko wiersz poleceń, więc
  zawieszony worker pozostaje „gotowy” i alert nie odpala wcale.
- **Rekomendacja:** liveness HTTP dla `api` (`/health`), `store` i `admin` z łagodnymi progami. Dla workera plik
  dotykany w `WorkerRunningEvent` i sonda `exec` sprawdzająca jego wiek. Wskaźnik z Z3 (wiek najstarszej wiadomości)
  jest tu drugą linią obrony.
- **Mocna strona tej samej decyzji:** `/health` API nie sprawdza zależności (`HealthController.php:13-19`), więc
  awaria Redisa albo Elasticsearcha nie wyłącza podów. To jest zrobione dobrze i nie należy tego zmieniać.
- **Pewność:** wysoka.

### Z7. Nieudane wdrożenie jest ciche

- **Waga:** Medium. **Klasa:** RYZYKO.
- **Dowód:**
  - `.github/workflows/deploy.yml:432-479`: job `deploy` kończy się pushem gałęzi `deploy-images`. Synchronizacja
    Argo CD dzieje się potem i workflow jej nie obserwuje.
  - `README.md:283`: „Błąd renderu albo migracji zatrzymuje wdrożenie, a poprzednia wersja działa dalej (aplikacja
    `Degraded`)”.
  - `00-alerts.yaml:121-151`: `WorkloadNotReady` porównuje repliki dostępne z oczekiwanymi. Przy `maxUnavailable: 0`
    (`_helpers.tpl:251`) stary pod dalej obsługuje ruch, więc reguła nie odpala. Nie ma reguły dla nieudanego Joba
    wersji (`migrate`, `backfill`, `purge-caches`) ani dla stanu aplikacji Argo CD. Reguły `cronjobFailed` dotyczą
    tylko `backup` i `backup-verify`, nie `cart-purge-expired`.
- **Skutek:** operator zatwierdza wdrożenie w telefonie, workflow jest zielony, a wersja nie weszła, bo migracja
  się nie powiodła. Dowie się o tym, gdy uruchomi `make status`. Poprawka bezpieczeństwa może w ten sposób nie
  trafić na serwer. Nieudany `backfill` zostawia dane bez wypełnionych kolumn, również bez sygnału.
- **Rekomendacja:** reguła na `kube_job_status_condition{condition="Failed"}` dla Jobów z etykietą
  `shop/job-kind: version` (etykieta już jest, `_helpers.tpl:358`) i dla CronJoba `cart-purge-expired`. Opcjonalnie
  krok workflow, który po pushu czeka na `Synced/Healthy` z rewizją snapshotu.
- **Pewność:** wysoka. Sprawdziłem wszystkie reguły alertów i koniec workflow.

### Z8. Obserwowalność kończy się na infrastrukturze: brak metryk aplikacji, logów dostępu i alertów biznesowych

- **Waga:** Medium. **Klasa:** RYZYKO.
- **Dowód:**
  - `grep -rnE "prometheus|/metrics|opentelemetry"` w `apps/api/src`, `config`, `composer.json`
    i `apps/store/package.json` nic nie znajduje. Alloy zbiera kube-state-metrics i node-exporter
    (`argocd/apps/monitoring.yaml:84-114`).
  - `apps/api/docker/frankenphp/Caddyfile:7-11`: brak dyrektywy `log`, więc nie ma logu dostępu.
  - `apps/api/config/packages/monolog.yaml:38-44`: `fingers_crossed` z `action_level: error`. Hardening sam to nazywa:
    „W `prod` logi udanych żądań nie są zapisywane” (`docs/features/hardening/spec.md`, „Ryzyka i ograniczenia”).
  - Alerty: tylko siedem reguł infrastruktury (Z3).
- **Skutek:** pody są zdrowe, a checkout zwraca 500 każdemu klientowi (np. po zmianie konfiguracji dostawcy
  płatności). Nie odpala żaden alert z repo. Zostaje Sentry, o ile użytkownik ustawił w nim powiadomienia. Nie da
  się też odpowiedzieć na pytania „ile żądań na sekundę”, „jaki p95” ani „ile płatności się nie powiodło dziś”.
  Nie ma też zewnętrznego sprawdzenia dostępności: nieaktualna lista adresów Cloudflare daje 403 części ruchu
  (`README.md:204`), a `ClusterSilent` tego nie widzi, bo klaster raportuje.
- **Co działa i jest dojrzałe:** correlation ID przez HTTP i Messenger, logi JSON, Sentry z filtrem danych osobowych
  i trace'ami, maskowanie logów testowane na korpusie, alerty z `absent` oceniane poza serwerem.
- **Rekomendacja (minimum przed prawdziwymi zamówieniami):** zewnętrzny test syntetyczny strony głównej i `/health`
  (Grafana Cloud ma go w darmowym planie); log dostępu Caddy w JSON (status, czas) i dwie reguły z logów albo metryk:
  odsetek 5xx oraz liczba nieudanych płatności i wiek kolejek z Z3. Reguły alertów Sentry opisać w runbooku.
- **Pewność:** wysoka co do braku. Nie widzę ustawień Sentry ani Grafana Cloud poza repo.

### Z9. Migracje transakcyjne bez `lock_timeout`; Job `migrate` ma 30 minut na indeksy `CONCURRENTLY`

- **Waga:** Medium (przy skali), Low (dziś). **Klasa:** RYZYKO.
- **Dowód:**
  - `values.yaml:122` `activeDeadlineSeconds: 1800`, `jobs.yaml:235`: `migrate` używa domyślnego limitu (wyższy ma
    tylko `backfill`, `values.yaml:125`).
  - `migrations/Version20261115140000IndexOrderSearch.php:41`: indeks GIN trigramowy na `ordering_order` budowany
    `CONCURRENTLY`. Wzorzec ponowienia usuwa indeks `INVALID` i buduje od zera
    (`Version20261115120100IndexStockLevels.php:38-43`).
  - Żadna migracja nie ustawia `lock_timeout` (grep). `Version20261115170000AddCategoryOrderAndRedirects.php:24-25`
    wykonuje `ALTER TABLE … ADD … DEFAULT … NOT NULL` w zwykłej transakcji, podczas gdy stara wersja obsługuje ruch.
- **Warunki i skutek:** (a) na tabeli z dziesiątkami milionów wierszy budowa indeksu GIN trwa ponad 30 minut. Job
  jest zabijany, zostaje indeks `INVALID`, ponowienie zaczyna od zera i też nie zdąża. Po `backoffLimit: 3` wdrożenie
  stoi, a każda następna wersja też. (b) `ALTER TABLE` czeka na blokadę `ACCESS EXCLUSIVE` za długą transakcją
  (backfill, raport), a za nim ustawiają się w kolejce wszystkie zapytania do tej tabeli: przestój do końca tamtej
  transakcji.
- **Rekomendacja:** `SET lock_timeout = '5s'` na początku migracji transakcyjnych (ponowienie Joba już istnieje).
  Osobny, dłuższy limit czasu dla `migrate` albo budowanie dużych indeksów krokiem `app:backfill`, który ma 6 godzin.
- **Pewność:** wysoka co do konfiguracji; czas budowy indeksu na docelowych danych to szacunek, nie pomiar.
- **Kontekst:** poza tym dyscyplina migracji jest wzorowa (patrz „Mocne strony”). Wcześniejsze migracje z `RENAME`
  i `UPDATE` (do `Version20261110`) powstały przed pierwszym wdrożeniem i nie są problemem.

### Z10. Chart nie jest gotowy na więcej niż jedną replikę, wbrew uzasadnieniu ADR

- **Waga:** Medium (przed skalowaniem). **Klasa:** RYZYKO, częściowo świadome.
- **Dowód:**
  - `docs/adr/k3s-argo-cd.md` („Uzasadnienie”): „ten sam chart zadziała na większym klastrze”.
  - `_helpers.tpl:244` i `:300`: `replicas: 1` wpisane w szablon, bez wartości w `values.yaml`. Brak
    `PodDisruptionBudget`, `HorizontalPodAutoscaler`, anty-afiniczności (grep bez wyników).
  - Wszystkie PVC na `local-path` (przywiązane do węzła).
  - Cache ISR sklepu na dysku poda: `apps/store/Dockerfile:40-41`. Specyfikacja to przyznaje:
    `docs/features/product-page/spec.md:429` („przy kilku instancjach rewalidacja trafia tylko do jednej”).
  - Purge Varnisha i rewalidacja idą na adres Service (`api.yaml:76-79`), czyli do jednej losowej repliki.
  - `framework.yaml:13-18`: pule `cache.oidc` i `cache.sitemap` na domyślnym adapterze plikowym (per pod; to akurat
    nieszkodliwe).
- **Skutek:** druga replika sklepu albo Varnisha oznacza stare ceny i stany na części odpowiedzi do godziny
  (`revalidate = 3600`). Przejście na kilka węzłów wymaga zmiany storage usług stanowych. Restart węzła (aktualizacja
  jądra, awaria) to pełny przestój wszystkiego jednocześnie, razem z logowaniem i panelem.
- **Co jest gotowe:** API jest bezstanowe (brak sesji PHP, blokad plikowych i lokalnych uploadów: zdjęcia idą do
  Garage, limity do Redisa), transporty Doctrine pozwalają na wielu workerów, koszyk gościa nie zależy od Redisa.
- **Rekomendacja:** nic przed pierwszym zamówieniem. Przed skalowaniem: liczba replik w wartościach, wspólny
  `cacheHandler` Next.js (Redis) albo rozgłaszanie rewalidacji do wszystkich podów, purge do wszystkich instancji
  Varnisha (headless Service), zarządzany Postgres albo operator z repliką. Zdanie w ADR doprecyzować.
- **Pewność:** wysoka.

### Z11. Obraz API działa jako root; chart nie ustawia `securityContext`

- **Waga:** Low. **Klasa:** RYZYKO.
- **Dowód:** `apps/api/Dockerfile:8-18` i `:62-68`: etapy `runtime` i `prod` bez `USER`. W chartcie jedyny
  `securityContext` to wyjątek AppArmor RabbitMQ (`rabbitmq.yaml:17-19`). Dla kontrastu: `apps/store/Dockerfile:52`
  `USER node`, `apps/admin/Dockerfile:36` `nginx-unprivileged`.
- **Skutek:** błąd wykonania kodu w API albo w bibliotece obróbki obrazów daje roota w kontenerze, który ma w
  środowisku wszystkie sekrety (Z12). Ucieczka z kontenera jest wtedy łatwiejsza.
- **Rekomendacja:** port 8080, `USER www-data`, w chartcie `runAsNonRoot: true`, `allowPrivilegeEscalation: false`,
  `capabilities.drop: [ALL]`. Katalog `var/` trzeba wtedy oddać temu użytkownikowi.
- **Pewność:** wysoka.

### Z12. Jeden Secret ze wszystkimi kluczami trafia do każdego kontenera; brak NetworkPolicy

- **Waga:** Low/Medium. **Klasa:** RYZYKO, w części opisane w ADR.
- **Dowód:** `_helpers.tpl:49-53` (`envFrom` całego `shop-secrets`) użyte w Postgresie, RabbitMQ, Keycloaku, Garage,
  imgproxy, Varnishu, sklepie, API i Jobach. `environments/prod/secrets/README.md`: „read by every pod”.
  `README.md:404-406` i ADR: klaster bez NetworkPolicy, Redis bez hasła, Elasticsearch bez security.
- **Skutek:** kompromitacja najsłabszego kontenera (np. imgproxy, który przetwarza pliki z zewnątrz) daje hasło
  superużytkownika Postgresa, sekrety webhooków płatności i klucz sesji sklepu.
- **Rekomendacja:** przed prawdziwymi płatnościami podzielić Secret na usługi (`secretKeyRef` per kontener) i dodać
  NetworkPolicy z domyślną odmową. To zadanie na dzień lub dwa, a ADR już je zapowiada.
- **Pewność:** wysoka. Szczegółową ocenę zostawiam audytowi bezpieczeństwa.

### Z13. Konfiguracja demo na środowisku nazwanym `prod`

- **Waga:** Low. **Klasa:** PREFERENCJA (świadoma decyzja, `docs/vision.md`, „Środowisko wdrożeniowe”).
- **Dowód:** `environments/prod/values.yaml:21-23` (`demoNotice: true`, `customerAccounts: false`,
  `orderMail: mailpit`), `:36` `environment: demo`. `api.yaml:80-86`: symulator płatności jest częścią API
  i nie ma przełącznika. `values.yaml:117-118` `initialize.enabled: true` (dane demo w pustej bazie).
  `infra/postgres/init/10-create-databases.sh` tworzy też `shop_test`, a `imgproxy.yaml:26-27` dopuszcza bucket
  `product-images-test`.
- **Skutek:** dziś żaden. Ryzyko pojawia się przy przejściu na prawdziwy sklep: trzeba pamiętać o pięciu
  przełącznikach, a symulatora nie da się wyłączyć konfiguracją. Każdy, kto zna adres API, „opłaca” zamówienie.
- **Rekomendacja:** przed prawdziwymi zamówieniami jeden test renderu „profil produkcyjny nie zawiera symulatora,
  Mailpita, `initialize` ani zasobów testowych”. Symulator za flagą środowiska.
- **Pewność:** wysoka.

### Z14. Redis i Postgres na ustawieniach domyślnych

- **Waga:** Low. **Klasa:** RYZYKO (przy wzroście).
- **Dowód:** `redis.yaml`: obraz bez argumentów (brak `maxmemory`, domyślne migawki RDB bez AOF) przy limicie 512 Mi.
  `postgres.yaml`: bez konfiguracji (Z5).
- **Skutek:** Redis po przekroczeniu limitu jest zabijany przez OOM zamiast usuwać klucze, a klienci tracą sesje
  z ostatnich minut. Postgres używa 128 MB bufora przy 2 Gi limitu.
- **Rekomendacja:** `--maxmemory 400mb --maxmemory-policy volatile-lru --appendonly yes`; dla Postgresa
  `shared_buffers`, `effective_cache_size`, `max_connections` w argumentach kontenera.
- **Pewność:** wysoka co do braku ustawień.

## Mocne strony

1. **Transakcyjny outbox z potwierdzeniem routingu.** Zdarzenie zapisuje się w transakcji komendy
   (`messenger.yaml:26-30`), przekaźnik potwierdza publikację (`confirm_timeout: 5`), a `RoutedAmqpTransport.php:28-55`
   publikuje z flagą `mandatory` i rzuca wyjątek, gdy broker nie miał dokąd skierować wiadomości. Opóźnione komendy
   leżą w Postgresie, więc przeżywają restart workera i brokera. To poziom rzadko spotykany w projektach tej wielkości.
2. **Degradacja zamiast kaskady.** `/health` nie sprawdza zależności. Limiter przy awarii Redisa przepuszcza żądania
   i loguje ostrzeżenie poza buforem (`rate_limiter.yaml:5-8`, `monolog.yaml:45-53`). Awaria Keycloaka daje 503, nie
   401, więc klienci nie tracą sesji (`KeySourceOutageTokenHandler.php:30-41`). Sklep ma klienta Redisa, który
   odrzuca komendy zamiast je kolejkować (`src/auth/redis.ts:8-16`). Koszyk gościa działa bez Redisa
   (`src/cart/bff.ts`, `runForGuest`). Lista produktów bez ofert pokazuje się bez cen zamiast błędu
   (`load-catalog.ts:34-53`). Strona produktu czyta z Postgresa, więc działa bez Elasticsearcha.
3. **Wdrożenie.** Obrazy z tagiem SHA wdrażane po digeście, smoke test na k3d instaluje chart z tymi samymi
   digestami przed publikacją (`deploy.yml:278-402`), zatwierdzenie w środowisku `production`, atomowy push dwóch
   gałęzi, obrazy usług obcych przypięte digestem (`values.yaml:34-47`), `set -o pipefail` pilnowany komentarzem
   i testem. Jeden plik wartości przechodzi przez wszystkie joby z sumą kontrolną.
4. **Migracje i zgodność wersji.** Job `migrate` przed rolloutem, initContainer `wait-for-migrate`
   (`api.yaml:94-110`), `maxUnavailable: 0`, pisemna reguła zgodności N−1 dla schematu i wiadomości
   (`apps/api/CLAUDE.md`, „Zgodność wersji”), indeksy na dużych tabelach `CONCURRENTLY` z obsługą indeksu `INVALID`
   i testem, dane wypełniane po rolloucie przez `app:backfill`. Nazwa Joba z sumy kontrolnej szablonu
   (`_helpers.tpl:354`), więc ta sama rewizja nie uruchamia go drugi raz.
5. **Kopie zapasowe jako projekt.** restic z szyfrowaniem, snapshot powstaje tylko przy komplecie etapów
   (`backup.sh:76-85`), host snapshotów to identyfikator klastra, więc nowy serwer nie przycina kopii starego
   (`backup.sh:135-141`). Osobny CronJob codziennie odtwarza oba dumpy do tymczasowego Postgresa. `restore` robi
   snapshot `pre-restore`, odmawia kopii nowszej niż kod i zostawia sklep wyłączony przy częściowym odtworzeniu.
   Alerty rozróżniają „kopia nieudana” i „kopii brak” i są testowane.
6. **Obrazy.** Wieloetapowe, wersje bazowe przypięte, obraz `prod` bez Composera, gita i SPX, opcache bez sprawdzania
   znaczników czasu (`prod.ini:2-4`), `zend.exception_ignore_args`, sklep i panel bez roota, mapy źródeł usuwane.
   Żaden kontener poza trzema initContainerami nie dostaje tokenu API Kubernetes.
7. **Runbook i uczciwość dokumentów.** `infra/k8s/README.md` opisuje utratę serwera, utratę klucza Sealed Secrets,
   zawieszoną synchronizację Argo i StatefulSet, który nie dostaje poprawki. ADR wymienia ryzyka rezydualne (brak
   NetworkPolicy, Redis bez hasła, klucz R2, opóźnienie weryfikacji) zamiast je przemilczać.
8. **Testy infrastruktury.** 40 plików testów w `infra/k8s/tests` (render, fale, sekrety, alerty przez `promtool`,
   maskowanie logów na prawdziwym Alloy, skrypty na atrapie `kubectl`) oraz smoke test z kopią i odtworzeniem.

## Zbędna złożoność

- **RabbitMQ.** Zdarzenie idzie Postgres (outbox) → RabbitMQ → ten sam jeden proces workera. Cztery inne kolejki
  działają już na transporcie Doctrine. Przy jednym konsumencie i tej skali broker dokłada usługę stanową, wolumen,
  wyjątek AppArmor i tryb awarii z Z4, a nie daje niczego, czego nie dałaby piąta kolejka w Postgresie. Ma sens jako
  przygotowanie pod wielu niezależnych konsumentów, ale dziś jest kosztem operacyjnym. PREFERENCJA.
- **Dwie warstwy cache z dwoma mechanizmami unieważniania** (Varnish po tagach i ISR Next.js po tagach) przed
  katalogiem 200 produktów. Każda zmiana ceny wymaga dwóch udanych wywołań HTTP, a obie warstwy mają ten sam problem
  z wieloma replikami (Z10).
- **Trzynaście workloadów na jednym węźle** (sześć stanowych) dla jednej osoby to duża powierzchnia do
  aktualizowania. Repo nie ma Dependabota ani Renovate, więc przypięte digesty nie mają mechanizmu odświeżania.
- **Około 5 tys. linii skryptów powłoki i 40 plików testów** dla wdrożenia jednego węzła. Znaczna część obsługuje
  ograniczenia pracy agenta (poza zakresem tego audytu), ale utrzymuje ją ta sama osoba.

Nie uważam za zbędne: Argo CD z jedną rewizją, Sealed Secrets, osobnego CronJoba weryfikacji kopii.

## Braki

- Zdefiniowane RPO i RTO oraz zmierzony czas odtworzenia na serwerze.
- PITR (archiwizacja WAL) i kopie niezmienne.
- Metryki aplikacji, log dostępu, alerty na odsetek błędów, nieudane płatności, wiek kolejek i kolejkę `failed`.
- Zewnętrzny test dostępności sklepu i API.
- Sondy liveness.
- Alert na nieudany Job wersji i na stan aplikacji Argo CD.
- Procedura i cel make dla `messenger:failed:retry`; zadanie zabezpieczające dla przeterminowanych zamówień.
- Limity czasu zapytań w bazie i w kliencie API sklepu.
- Prawdziwy dostawca płatności i przełącznik wyłączający symulator.
- Mechanizm aktualizacji przypiętych obrazów i zależności.
- NetworkPolicy, podział sekretów, hasło Redisa.
- Pulpity w repo (są tylko domyślne pulpity Kubernetes w Grafana Cloud).
- Parametr liczby replik, PDB, HPA, wspólny cache ISR, pula połączeń do bazy.

## Lista kontrolna

### Przed przyjęciem pierwszego prawdziwego zamówienia (blokujące)

1. Włączyć kopie w `prod` (bucket R2, `shop-backup`, `backup.enabled: true`), zobaczyć pierwszą udaną kopię
   i weryfikację, wykonać ćwiczenie odtworzenia na serwerze i zapisać czas (Z1).
2. Zapisać RPO i RTO. Dodać archiwizację WAL albo świadomie zaakceptować na piśmie utratę do 24 h zamówień. Wydłużyć
   retencję do co najmniej 30 dni. Potwierdzić backup VPS-a w OVH i kopię klucza Sealed Secrets oraz hasła restic
   poza serwerem (Z2).
3. Nadzór nad kolejkami: liczba `failed` i wiek najstarszej wiadomości w pulpicie i w alercie, cel make do
   ponowienia, zadanie zabezpieczające dla przeterminowanych zamówień `new` (Z3).
4. Alert na błędy aplikacji: zewnętrzny test syntetyczny, odsetek 5xx, nieudane płatności; reguły powiadomień Sentry
   opisane w runbooku (Z8).
5. Alert na nieudany Job wersji (Z7).
6. Limity czasu: `statement_timeout`, `lock_timeout`, limit w kliencie API sklepu (Z5).
7. Liveness dla `api`, `store` i workera; worker rozdzielony na dwa Deploymenty z `--time-limit` (Z4, Z6).
8. Prawdziwy dostawca płatności, symulator wyłączony w produkcji, `orderMail: brevo`, `initialize.enabled: false`,
   `demoNotice: false`, `sentry.environment` inne niż `demo` (Z13).
9. Podział sekretów na usługi, NetworkPolicy z domyślną odmową, hasło Redisa, API bez roota (Z11, Z12).
10. Weryfikacja originu z runbooka (sekcja 5.5) i polityka prywatności, które specyfikacja DEPLOY sama wskazuje jako
    warunki publicznego startu.

### Przed skalowaniem (nieblokujące)

1. Liczba replik w wartościach chartu, PDB, anty-afiniczność; storage usług stanowych inny niż `local-path`.
2. Wspólny cache ISR albo rozgłaszanie rewalidacji; purge do wszystkich instancji Varnisha (Z10).
3. Postgres: strojenie, pula połączeń (PgBouncer), replika albo usługa zarządzana, kopie fizyczne.
4. Workery skalowane per transport; metryki przepustowości kolejek.
5. `lock_timeout` w migracjach i dłuższy limit Joba `migrate` albo budowa dużych indeksów poza nim (Z9).
6. FrankenPHP w trybie worker po pomiarze; `maxmemory` Redisa (Z14).
7. Elasticsearch z replikami (`app.search.replicas: 0` w `services.yaml:9`) i z security.
8. Kopia obiektów bez pełnego lustra na dysku węzła (`rclone sync` do `backup-work` podwaja miejsce zajmowane przez
   zdjęcia, `backup.sh:56-60`).
9. Pomiar obciążenia na danych zbliżonych do docelowych. `docs/vision.md` słusznie zastrzega, że obecne wyniki
   opisują demo.

### Co jest dojrzałe, a co jest deklaracją

| Obszar | Stan |
|---|---|
| Pipeline wdrożenia, GitOps, obrazy po digeście | dojrzałe, w kodzie i testach |
| Migracje, zgodność wersji, backfill | dojrzałe, z regułami i testami |
| Outbox, ponowienia, idempotencja odbiorców | dojrzałe |
| Timeouty i degradacja wobec Redisa, Keycloaka, Elasticsearcha | dojrzałe |
| Mechanizm kopii i odtworzenia | dojrzały jako kod; **w `prod` wyłączony**, na prawdziwym R2 niesprawdzony |
| Alerty | infrastruktura tak; aplikacja i biznes: brak |
| Obserwowalność aplikacji | Sentry i correlation ID tak; metryki i logi dostępu: brak |
| Odporność na awarię węzła | brak, świadomie |
| Skala 10 mln produktów i 60 tys. użytkowników | deklaracja architektoniczna; chart wymaga zmian (Z10), pomiaru brak |
| „Ten sam chart zadziała na większym klastrze” (ADR) | deklaracja, nieprawdziwa bez zmian |

## Czego nie zweryfikowano

- Stanu faktycznego serwera (czy kopie działają, czy backup OVH jest włączony, ustawień Grafana Cloud, Sentry,
  Cloudflare i środowisk GitHub). Oceniam wyłącznie repo.
- Zachowania workera Symfony przy niedostępnym RabbitMQ (Z4) i sposobu liczenia `max_execution_time` we FrankenPHP
  (Z5): `vendor` nie jest w eksporcie, niczego nie uruchamiałem.
- Czasu odtworzenia kopii i czasu budowy indeksów na dużych danych: brak pomiarów.
- Raportów z testów obciążeniowych: `docs/vision.md` odsyła do `docs/reports/`, którego w eksporcie nie ma.
- Skryptów `restore.sh`, `restore-job.sh`, `bootstrap.sh`, `smoke.sh` i testów `infra/k8s/tests` linia po linii
  (znam je z opisu w ADR i runbooku oraz z listy plików).
- Pełnej treści `ci.yml` (przejrzałem strukturę jobów) oraz specyfikacji `sentry/spec.md` i `deploy-ops/spec.md`
  poza fragmentami.
- Zgodności listy adresów Cloudflare w `ipAllowList` z aktualną listą (wymaga sieci).
