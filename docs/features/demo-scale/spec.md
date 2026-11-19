# DEMO-SCALE etap 1: dane w skali na serwerze demo

Ręcznie uruchamiane generowanie danych demo (generator z `docs/features/demo-data/spec.md`) na serwerze worf.pl:
najpierw profil `medium`, po analizie wyników i zgodzie użytkownika profil `server`. Sklep jest na czas generowania
zamknięty i wraca do publicznej dostępności dopiero po sprawdzeniu danych, wyszukiwarki, listingu i checkoutu.

Etap 1 (#786) jest wydzielony z #484, które obejmuje resztę (pomiar k6 na serwerze, kopie przy dużej bazie,
konfiguracja usług). Wymaga zmergowanego PR #756 (CronJob `demo-data`, `LOAD_DATA`, kalibracja
`medium`): opisy „dziś” w tej specyfikacji dotyczą `main` po tym PR.

Issues: #786
Zakres: `infra/k8s`, `load`, `docs/features/demo-scale`, `docs/features/demo-data/spec.md`, `docs/features/deploy/spec.md`, `docs/features/deploy-ops/spec.md`, `docs/adr/backups-observability.md`, `docs/vision.md`, `docs/reports`, `CLAUDE.md`

## Cel i zakres

- Generator danych demo działa na serwerze jako Job z zawieszonego CronJoba `demo-data`, uruchamiany wyłącznie celem
  `make -C infra/k8s generate` po zgodzie użytkownika.
- Sklep jest zamknięty od startu generowania do udanego sprawdzenia po nim. Nieudane albo przerwane generowanie
  zostawia sklep zamknięty.
- Generator sam przerywa pracę, zanim skończy się miejsce na dysku serwera.
- Po generowaniu sprawdzane są dane, indeksy, wyszukiwarka, listing, strona produktu, koszyk i checkout gościa.
- Logi generatora, czasy kroków, rozmiary i odczyty zasobów zostają poza kontenerem, niezależnie od rotacji jego logów.
- Z każdego stanu (także po błędzie) da się wrócić do danych `seed` albo ponowić generowanie.
- Kolejne zwykłe wdrożenia zachowują wygenerowane dane; czas Joba `initialize` i zachowanie danych są sprawdzane po
  pierwszym wdrożeniu na danych `medium` i na danych `server`.
- Wynik przebiegu na serwerze (czasy, rozmiary, zasoby) trafia do raportu w `docs/reports/` jako pomiar tego serwera.

## Decyzje użytkownika

Z 2026-11-19:

- **U1. Kolejność.** Zamiast obowiązkowego pomiaru `full` na k3d przed wdrożeniem: najpierw dane w skali na worf.pl.
  Pomiar `full` na k3d jest odłożony. Zmienia to P2 i W3 w `docs/features/demo-data/spec.md`; zostaje zasada, że
  żaden dokument, PR ani raport nie przedstawia pełnej skali (10 mln produktów, 50 mln zamówień) jako zweryfikowanej.
- **U2. Dwa kroki.** Najpierw `medium` na worf.pl, potem `server` po analizie wyników `medium` i osobnej zgodzie.
- **U3. Kopie.** Kopie restic w klastrze zostają wyłączone (`backup.enabled: false`). Nie zakładamy, że automatyczna
  kopia OVH jest aktywna, dopóki użytkownik tego nie potwierdzi; żadne zabezpieczenie z tej specyfikacji się na niej
  nie opiera.
- **U4. Niedostępność.** Sklep może być w całości niedostępny na czas generowania, o dowolnej porze.
- **U5. Sekret puli zdjęć** jest tymczasowy: tworzony na czas przebiegu z pliku użytkownika i usuwany po nim.
- **U6. Limit czasu** pierwszego przebiegu `server`: 24 h.
- **U7. Priorytet.** Etap 1 idzie przed pozostałymi epikami z tablicy.

## Stan serwera (odczyt 2026-11-19)

Z Grafana Cloud (node-exporter, kubelet) i `make -C infra/k8s status`:

| | |
|---|---|
| Dysk (`/`, ext4, jedyny system plików z danymi) | 192,9 GiB, wolne 175,8 GiB |
| Pamięć | 22,9 GiB, dostępne 15,6 GiB, bez swapu |
| CPU | 8 vCPU, średnie zajęcie z doby 9% |
| Baza `shop` | seed: 191 produktów, 0 zamówień (log Joba `initialize` z 2026-11-18) |
| Wolumeny | `local-path` na tym samym dysku; rozmiary z `storage.*` nie są egzekwowane |

Szacunek szczytu dysku dla `server` (96 GB, `profiles.yaml`) jest ekstrapolacją z `medium` na innym sprzęcie. Miejsce
wystarcza z zapasem, ale bezpieczeństwo przebiegu zapewnia strażnik miejsca (niżej), nie ten szacunek.

## Założenia techniczne i eksperymenty

Przyjęte przez sesję; każde sprawdzone w kodzie albo eksperymentem na jednorazowym klastrze k3d
(`rancher/k3s:v1.36.6-k3s1`, 2026-11-19):

- **Z1. Zamknięcie regułą sieciową.** NetworkPolicy w przestrzeni `shop`, która dopuszcza ruch przychodzący tylko
  z podów tej przestrzeni, odcina Traefika (odpowiedź 502), a sondy kubeleta i ruch między podami działają dalej (pody
  zostały `Ready`, 0 restartów). Usunięcie reguły przywraca ruch w kilkanaście sekund.
- **Z2. Otwarte połączenia przeżywają regułę.** Przy backendzie z keep-alive żądania przez Traefika dostawały 200 jeszcze
  40 s po założeniu reguły (do końca próby). Dopiero usunięcie poda Traefika zamknęło ruch. Zamknięcie to zawsze
  reguła, a po niej restart poda Traefika.
- **Z3. `df -Pk /` w kontenerze** podaje wolne miejsce systemu plików węzła (ten sam, na którym leżą wolumeny
  `local-path`). Na serwerze wolumeny i warstwy kontenerów są na jednym systemie plików `/`.
- **Z4. Generator potrzebuje działającego stosu.** `bin/seed-demo` czeka na indeksowanie przez `worker`, krok kont
  woła Keycloaka, a `storefront:cache:purge-all` kończy się błędem, gdy Varnish albo sklep nie odpowiada
  (`PurgeAllStorefrontCacheCommand`). Dlatego wzorzec `restore` (skalowanie `api`, `worker`, `store`, `keycloak` do
  zera) tu nie pasuje.
- **Z5. Przebudowa indeksu sprząta po przerwanej.** `catalog:reindex` usuwa indeksy bez aliasu przed budową nowego
  (`ElasticsearchSearchIndexMaintenance`), więc przerwany przebieg nie zostawia trwale zajętego miejsca.
- **Z6. `initialize` nie rusza wygenerowanych danych.** Przy niepustych produktach, cenach i stanach kończy się bez
  zmian; liczy jednak `count(*)` czterech tabel przy każdym wdrożeniu (`initialize.sh`).
- **Z8. Jedyne publiczne wejście to Traefik.** Chart ma pięć Ingressów (sklep, `admin.`, `api.`, `auth.`, `img.`),
  wszystkie usługi są typu ClusterIP, żaden pod nie używa sieci ani portu hosta, a firewall serwera przepuszcza
  z zewnątrz tylko SSH i API k3s (`server-bootstrap.sh`).
- **Z9. Usunięty pod Joba nie wraca.** Po usunięciu działającego poda Joba z `backoffLimit: 0` Job dostał warunek
  `Failed` i nie powstał nowy pod.
- **Z10. Restart węzła.** Przy zamkniętym sklepie i ciągłych żądaniach przez Traefika restart węzła nie dał ani jednej
  odpowiedzi 200 (502, brak odpowiedzi w czasie startu, znowu 502; jedna próba). Reguła przetrwała, działający Job
  dostał warunek `Failed` bez nowego poda, a **jego pod zniknął razem z logiem kontenera**. Log kontenera nie jest
  więc trwałym miejscem zapisu.
- **Z11. Sesje bazy po śmierci klienta.** Na Postgresie 18.6 zapytanie klienta zabitego sygnałem KILL działało
  dalej, a przy `PGOPTIONS=-c client_connection_check_interval=5s` serwer zakończył sesję w kilkanaście sekund
  („connection to client lost”); parametr może ustawić zwykły użytkownik. Sprawdzone z `psql`; PHP łączy się przez
  tę samą bibliotekę libpq, co zadanie potwierdza na działającym stosie.
- **Z7. Aplikacja `shop` Argo CD ma `selfHeal` i `prune`.** Zasób spoza chartu (reguła zamknięcia, zapis stanu, sekret
  puli zdjęć) nie jest przez nią śledzony, więc synchronizacja go nie usuwa ani nie przywraca.

## Rozwiązanie

### Stan sklepu i zapis stanu

- **Zamknięty** znaczy: w przestrzeni `shop` istnieje NetworkPolicy `shop-closed` (ruch przychodzący do każdego poda
  tylko z podów tej przestrzeni). Wszystkie hosty (`worf.pl`, `api.`, `admin.`, `auth.`, `img.`) odpowiadają 502.
  Pody działają, więc alert `WorkloadNotReady` się nie odzywa. Regułę zakłada i usuwa wyłącznie cel `generate`; nie
  jest częścią chartu i nie ma etykiet ani adnotacji śledzenia Argo CD, więc wdrożenie ani synchronizacja jej nie
  usuną (Z7).
- **Zakres zamknięcia.** Reguła obejmuje każdy pod przestrzeni, więc zamyka wszystkie publiczne wejścia naraz:
  sklep, API, panel (`admin.`), logowanie (`auth.`) i obrazy (`img.`); innych wejść nie ma (Z8). Połączenia otwarte
  przed regułą zrywa restart poda Traefika (Z2); tam też kończą się połączenia Cloudflare z serwerem.
- **Cloudflare i cache.** Cloudflare ma jedną regułę cache: cały host `img.` (runbook, sekcja 4); stron HTML ani
  odpowiedzi API domyślnie nie przechowuje. W trakcie zamknięcia brzeg może dalej podawać obrazy `img.` i pliki
  statyczne buildów sklepu i panelu: to treść niezależna od danych (adres obrazu wynika z sumy pliku, pliki builda są
  z obrazu aplikacji). Ustawień Cloudflare nie widać z repo, więc zamknięcie potwierdza sonda dwóch adresów przez
  Cloudflare: API i strony głównej sklepu. Strona główna podana z cache (kod poniżej 500 przy zamkniętym API) to
  niepotwierdzone zamknięcie i cofnięcie przed zastąpieniem danych. Cache Varnisha i sklepu czyści generator
  (`storefront:cache:purge-all`). Pula `cache.sitemap` (mapa strony, ważna godzinę) jest plikowa, osobna w każdym
  podzie `api`, więc czyści ją cel `generate` w każdym z nich przed sprawdzeniem; bez tego
  `load/smoke.js` czytałby produkty starego katalogu.
- **Zapis stanu** to ConfigMap `demo-data-state` w przestrzeni `shop` (poza chartem, bez śledzenia Argo CD):
  - `data`: co jest w bazie: `seed`, nazwa profilu albo `failed` (dane niepełne albo niesprawdzone);
  - `phase`: `idle`, `closing` (nic jeszcze nie zastąpione), `running` (Job utworzony), `checking` albo `opening`
    (dane sprawdzone, trwa otwieranie);
  - `target`, `job`, `anchor`, `revision` (tag obrazu), chwile startu i końca, `reason` (powód błędu), liczby
    produktów i zamówień po udanym przebiegu;
  - `rootAutomated`: ustawienie automatycznej synchronizacji aplikacji `root` sprzed jej wyłączenia; zapisane raz
    i usuwane tym samym zapisem, który następuje po jej przywróceniu.
  Brak zapisu znaczy: dane `seed`, faza `idle`.
- **Niezmiennik.** Przy `data: failed` reguła `shop-closed` istnieje. Usuwa ją tylko krok otwarcia po udanym
  sprawdzeniu oraz cofnięcie przy `data` innym niż `failed`.
- **Jeden piszący.** Każdy zapis stanu jest warunkowy względem wersji obiektu odczytanej przez ten proces
  (`resourceVersion`), a przed każdą zmianą w klastrze (synchronizacja, reguła, Traefik, sekret, Job) proces czyta
  zapis ponownie i sprawdza, że jego wersja jest tą z własnego ostatniego zapisu (przy wznowieniu, zanim proces sam
  coś zapisze: tą odczytaną na starcie). Inna wersja albo konflikt znaczy,
  że działała druga kopia celu: proces kończy się kodem 1 bez dalszych zmian.
- `make -C infra/k8s status` pokazuje `data`, `phase` i to, czy sklep jest zamknięty.

### Cel `make -C infra/k8s generate`

`make -C infra/k8s generate PROFILE=<seed|medium|server> REPLACE=<data z zapisu stanu> GENERATE_OUT=<katalog poza
repo> GENERATE_PROBE_URL=<adresy> KUBE_DIR=<katalog>`. Łączy się z klastrem przez kubeconfig z `KUBE_DIR` jak
`restore` (`scripts/kube-jobs.sh`), woła `scripts/ao-guard.sh`. Zastępuje dane sklepu, więc agent uruchamia go tylko
po zgodzie użytkownika w rozmowie, która nazywa operację, host i profil; skrypt pyta o słowo `tak`.

`GENERATE_PROBE_URL` to lista publicznych adresów przez ingress (na serwerze `https://api.worf.pl/` i
`https://worf.pl/`), o które skrypt pyta z komputera operatora: 502, 503 i 504 znaczą „zamknięty”, kod poniżej 500
„otwarty”, każdy inny kod i brak odpowiedzi to odczyt nieudany (ponawiany do limitu czasu, nigdy nie liczony jako
„zamknięty” ani „otwarty”). Sklep jest zamknięty albo otwarty dopiero wtedy, gdy mówi to każdy adres z listy.
`GENERATE_PROBE_HOST` ustawia nagłówki `Host` (klaster testowy, gdzie adresem jest węzeł).

W klastrze bez Argo CD (brak CRD `Application` albo aplikacji `shop`; test dymny k3d) kroki synchronizacji są
pomijane, jak w `restore`.

W krokach, które kończą przebieg (cofnięcie, krok 6, krok 7), zapis stanu jest zawsze ostatni: przerwanie przed nim
zostawia fazę, z której wznowienie powtarza ten sam krok, a każda czynność kroku jest powtarzalna.

Przebieg przy fazie `idle`:

1. **Sprawdzenia bez zmian** (odmowa kodem 1 albo 2, nic nie jest zmienione):
   - `PROFILE` to `seed` albo profil wpisany w CronJobie `demo-data` wdrożonej rewizji (`demoData.profile`); inny
     profil wymaga zmiany wartości i wdrożenia;
   - `REPLACE` jest podane i równe `data` z zapisu stanu; komunikat odmowy podaje `data` i szacowaną liczbę
     produktów i zamówień (`pg_class.reltuples`);
   - CronJob `demo-data` istnieje i jest zawieszony; żaden Job przestrzeni nie jest aktywny; Argo CD nie
     synchronizuje aplikacji `root` ani `shop`; każdy Deployment i StatefulSet przestrzeni jest gotowy (generator
     kończy się błędem, gdy Elasticsearch, Postgres, Keycloak, `worker`, Varnish albo sklep nie odpowiada);
   - gdy profil nie jest `seed`, a CronJob ma sekret puli zdjęć (`photosSecretName`): plik puli zdjęć
     (`DEMO_PHOTOS_ENV_FILE`, domyślnie `~/.config/shop/demo-photos.env`) ma cztery zmienne odczytu;
   - wolne miejsce węzła (kubelet, `/api/v1/nodes/<węzeł>/proxy/stats/summary`) to co najmniej `demoData.minFreeGb`,
     a dla profili `medium` i `server` także co najmniej 1,25 × `diskPeakGb` profilu (obowiązuje większa z dwóch
     liczb; GB to 10^9 bajtów, jak w `load`); odczyt nieudany albo nieliczbowy to odmowa;
   - `GENERATE_OUT` istnieje, jest poza repo i da się w nim pisać; sonda zgadza się ze stanem: przy `data: failed`
     „zamknięty” i reguła istnieje, w pozostałych przypadkach „otwarty” i reguły nie ma.
2. **Pytanie.** Skrypt wypisuje host klastra, `data` i szacowane liczby, profil docelowy, że sklep będzie niedostępny
   do udanego sprawdzenia, i czeka na `tak`.
3. **Zamknięcie** (nic nie jest jeszcze zastąpione). Zapis stanu `closing` z `rootAutomated` (gdy zapis ma już to
   pole, zostaje stara wartość) **przed** pierwszą zmianą w klastrze; automatyczna synchronizacja `root`, potem
   `shop`, wyłączona (jak w `restore`); oczekiwanie na Joby, które ruszyły od kroku 1; ponowne sprawdzenie, że żadna
   operacja synchronizacji `root` ani `shop` nie trwa i że `shop` nie ma z powrotem automatycznej synchronizacji;
   reguła `shop-closed`; usunięcie podów Traefika (`kube-system`) i oczekiwanie na jego gotowość (Z2); sonda musi
   podać „zamknięty” w `GENERATE_CLOSE_WAIT` sekund (120).
   **Cofnięcie** (trwająca synchronizacja albo sonda bez potwierdzenia): sekret `demo-photos` usunięty, jeśli
   istnieje; reguła usunięta tylko przy `data` innym niż `failed`; synchronizacja przywrócona do `rootAutomated`;
   na końcu zapis `idle` bez zmiany `data` i bez `rootAutomated`. Kod 1.
4. **Generowanie.** Zapis stanu `running` z nazwą Joba i `data: failed`; dla profilu z sekretem puli zdjęć sekret
   `demo-photos` z czterech zmiennych odczytu (przez stdin, bez wartości w linii poleceń, wyjściu
   i `GENERATE_OUT`); Job `demo-data-<znacznik czasu>` z CronJoba ze zmienną
   `DEMO_DATA_CONFIRM=<PROFILE>` (także dla `seed`: ten sam Job uruchamia wtedy `bin/seed-demo`); oczekiwanie do
   `activeDeadlineSeconds` Joba. Skrypt tylko czeka i co 10 minut wypisuje czas przebiegu: niczego, co ma zostać po
   przebiegu, nie zbiera w trakcie (zapis logów i metryk nie zależy od komputera operatora, „Logi i metryki”).
5. **Po udanym Jobie.** Zapis stanu `checking`; sekret `demo-photos` usunięty; log kontenera (tyle, ile podaje
   kubelet), blok podsumowania i `sizes.txt` zapisane w `GENERATE_OUT` (gdy poda Joba już nie ma, skrypt to
   odnotowuje i idzie dalej); pula `cache.sitemap` wyczyszczona w każdym podzie `api`
   (`bin/console cache:pool:clear`; nieudane czyszczenie zatrzymuje cel w fazie `checking` z kodem 1, ponowne
   uruchomienie powtarza krok); sprawdzenie po generowaniu (niżej).
6. **Otwarcie** tylko po udanym sprawdzeniu: zapis `opening` z `data` równym profilowi (dane są sprawdzone);
   usunięcie reguły `shop-closed`; przywrócenie
   automatycznej synchronizacji do `rootAutomated` (`root` przywraca `shop`); sonda podaje „otwarty”; na końcu zapis
   `idle` z liczbami, chwilą końca i bez `rootAutomated`. Kod 0. Gdy sonda nie poda
   „otwarty” (np. awaria Cloudflare), faza zostaje `opening`, kod 1, a ponowne uruchomienie kończy krok bez
   powtarzania sprawdzeń.
7. **Błąd** w krokach 4–5 (Job nieudany albo po czasie, strażnik miejsca, nieudane sprawdzenie), w tej kolejności:
   log zapisany; sekret usunięty; reguła `shop-closed` założona, jeśli jej nie ma (z restartem Traefika);
   synchronizacja przywrócona do `rootAutomated`; na końcu zapis `idle` z `data: failed`, powodem i bez
   `rootAutomated`. **Sklep jest zamknięty.** Skrypt wypisuje powód i dwie drogi: ponowienie (`PROFILE=<profil>
   REPLACE=failed`) albo powrót do seeda (`PROFILE=seed REPLACE=failed`). Kod 1.

**Przerwanie i wznowienie.** Sygnał albo utrata połączenia kończy skrypt bez żadnego sprzątania: Job działa dalej,
reguła zostaje, synchronizacja zostaje wyłączona, a zapis stanu mówi, w której fazie. Cel nie używa SSH; po starcie
Joba nic w klastrze nie zależy od komputera operatora. Uśpiony komputer nie kończy skryptu: po wybudzeniu proces
działa dalej, a gdy w tym czasie ruszyła druga kopia celu, pierwszy jego zapis stanu kończy się konfliktem wersji
i proces wychodzi bez zmian („Jeden piszący”).

Ponowne uruchomienie celu przy fazie innej niż `idle` jest wznowieniem. Wymaga `KUBE_DIR`, `GENERATE_OUT`,
`GENERATE_PROBE_URL` i `PROFILE` równego `target` z zapisu (inny to odmowa bez zmian); nie pyta o `tak`, nie wymaga
`REPLACE`, nie sprawdza miejsca ani aktywnych Jobów i niczego nie zastępuje:

- `closing`: cofnięcie jak w kroku 3 (kod 1; kolejne uruchomienie zaczyna od nowa);
- `running`: dołącza do Joba z zapisu albo czyta jego wynik i idzie do kroku 5 albo 7; gdy tego Joba nie ma, krok 7
  (dane uznane za niepełne). Job nieudany bez poda (restart serwera, usunięty pod; Z9, Z10) to krok 7 z powodem
  „pod Joba nie istnieje”, a log pochodzi wtedy z Grafana Cloud;
- `checking`: powtarza krok 5 od usunięcia sekretu (sprawdzenia są powtarzalne; reguła w tej fazie zawsze istnieje)
  i idzie do kroku 6 albo 7;
- `opening`: kończy krok 6.

Kody wyjścia: 0 dane wygenerowane, sprawdzone, sklep otwarty; 1 odmowa, brak `tak`, cofnięcie, konflikt zapisu albo
błąd (komunikat mówi, czy sklep jest zamknięty); 2 złe argumenty albo środowisko.

CronJob `cart-purge-expired` działa w trakcie bez zmian: usuwa tylko wygasłe koszyki, których generator nie pisze.

Po błędzie wymagającym poprawki kodu kolejność to: powrót do seeda (sklep otwarty na seedzie), wdrożenie poprawki,
ponowne generowanie. Wdrożenie przy `data: failed` jest możliwe (synchronizacja jest włączona, reguła zostaje), ale
Job `initialize` może wtedy skończyć się błędem stanu mieszanego bez zmian (Z6) i zatrzymać synchronizację przed
`purge-caches` i `backfill`; po udanym generowaniu albo powrocie do seeda domyka ją kolejne wdrożenie albo ręczna
synchronizacja aplikacji `shop` w Argo CD (krok użytkownika).

### Job generatora na serwerze (chart)

- Profil `prod` włącza zawieszony CronJob `demo-data`: `demoData.profile` (`medium`, po zgodzie na drugi krok
  `server`), stała chwila odniesienia `demoData.anchor` (ta sama dla obu profili, żeby ponowne generowanie dawało te
  same dane), `demoData.photosSecretName: demo-photos` (odwołania do sekretu są opcjonalne, żeby Job `seed` ruszył
  bez niego; brak zmiennych przy profilu ze zdjęciami wykrywa `bin/generate-demo` przed kasowaniem),
  `demoData.minFreeGb: 32`, limity czasu `medium` 6 h i `server` 24 h (U6), `terminationGracePeriodSeconds: 120`,
  zasoby `resources.demoData` (żądanie 500 m CPU i 1 GiB, limit 4 GiB; poza budżetem stanu ustalonego, bo to Job).
  Profile `ci` i `load` mają `minFreeGb: 2`.
- Kontener ma `PGOPTIONS=-c client_connection_check_interval=10s`: sesje bazy generatora same się kończą kilka
  sekund po zniknięciu klienta (Z11), także gdy kontener zostanie zabity bez sygnału (brak pamięci, eksmisja).
  Zapytanie kroku stanów ani `ANALYZE` nie piszą więc dalej po śmierci generatora.
- Schemat wartości przestaje odrzucać `demoData.enabled` z `image.requireDigests`. W zamian przy `requireDigests`
  wymaga niepustych `anchor` i `photosSecretName`, profilu `medium` albo `server` i `minFreeGb` co najmniej 10.
- Kontener uruchamia skrypt chartu `files/scripts/demo-data.sh` (obraz api), który:
  1. odmawia bez zmian (kod 64), gdy `DEMO_DATA_CONFIRM` nie jest nazwą profilu CronJoba ani słowem `seed`. Zmienną
     ustawia tylko cel `generate` (oraz `load` i test dymny na k3d), więc Job utworzony ręcznie z CronJoba
     (`kubectl create job --from`) niczego nie kasuje;
  2. odmawia bez zmian (kod 74), gdy wolne miejsce (`df -Pk /`, Z3) jest poniżej `minFreeGb` albo odczyt się nie udał;
  3. uruchamia w osobnej grupie procesów `bin/generate-demo --profile=… --anchor=… --force-reset` (dla `seed`:
     `bin/seed-demo`) i co `DEMO_DATA_SPACE_POLL` sekund (30) czyta wolne miejsce; co dziesiąty odczyt wypisuje;
  4. przy wolnym miejscu poniżej `minFreeGb`, trzech nieudanych odczytach z rzędu albo odczycie nieliczbowym
     zatrzymuje wyłącznie grupę procesów generatora (także polecenie działające właśnie na pierwszym planie)
     i kończy się kodem 75. Sesje bazy generatora kończą się same (`PGOPTIONS`); sesji `api` i `worker`, innych
     podów ani usług skrypt nie rusza;
  5. to samo zatrzymanie wykonuje po sygnale TERM (limit czasu Joba, usunięcie poda);
  6. na końcu każdej ścieżki wypisuje blok podsumowania: kod wyjścia, ostatni ukończony krok, czas każdego kroku
     (liczony przez skrypt z linii `[OK]` generatora, więc niezależny od rotacji logu), najmniejsze wolne miejsce;
     jedną linię powodu zapisuje do `/dev/termination-log`, którą cel `generate` przepisuje do zapisu stanu.
- **Zapas po zatrzymaniu.** Strażnik zatrzymuje zapis przy 32 GB wolnego (29,8 GiB): tuż powyżej progu alertu
  `NodeDiskFull` (15% z 192,9 GiB, czyli 28,9 GiB) i powyżej progów, przy których Elasticsearch przestaje
  przydzielać shardy (10% wolnego, 19,3 GiB) i blokuje zapis (5%), oraz progu eksmisji kubeleta. Między dwoma
  odczytami (30 s) generator dopisuje co najwyżej kilka GB (tempo `medium`: około 10 GB danych w pół godziny), a WAL
  Postgresa do 1 GB, więc zostaje ponad 20 GiB. Działania naprawcze potrzebują mniej niż 2 GiB i same zwalniają
  miejsce: powrót do seeda zaczyna od `TRUNCATE` (pliki tabel znikają od razu), a przebudowa indeksu najpierw usuwa
  indeksy bez aliasu (Z5). Ponowne generowanie tego samego profilu po zatrzymaniu przez strażnika skończyłoby się
  tak samo, więc decyzję (inny profil, zwolnienie miejsca) podejmuje użytkownik.
- Wdrożony profil to deklaracja w repo: zmiana `medium` na `server` to PR z wartościami i zwykłe wdrożenie.

### Sprawdzenie po generowaniu

Wykonywane przy zamkniętym sklepie; każde nieudane zostawia sklep zamknięty (krok 7):

1. **Dane.** `demo-data:verify` jest ostatnim krokiem `bin/generate-demo` (lista naruszeń w specyfikacji DEMO-DATA);
   Job z kodem 0 je przeszedł. Dla `seed` kod 0 `bin/seed-demo` w tym samym Jobie.
2. **Indeksy Postgresa.** Żaden indeks bazy `shop` nie jest nieważny (`pg_index.indisvalid`, `indisready`).
3. **Wyszukiwarka.** Alias katalogu wskazuje dokładnie jeden indeks, indeks ma dokumenty, stan klastra
   Elasticsearcha nie jest `red`, nie ma indeksów bez aliasu po przebudowie.
4. **Ścieżki sklepu.** Job z zawieszonego CronJoba `load` (niżej) wykonuje `load/smoke.js` na usługach klastra:
   strona główna, listing kategorii, strona produktu, wyszukiwarka z filtrem i bez, koszyk i checkout gościa jednej
   sztuki z rozpoczętą płatnością. Każda odpowiedź inna niż oczekiwana kończy Job błędem. Checkout zostawia w danych
   jedno zamówienie `load-<n>@example.com`, które wygasa jak każde nieopłacone.

CronJob `load` (chart, profile `prod` i `ci`): zawieszony, obraz k6 przypięty digestem w `images.k6` (ten sam co
w `load/k6.sh`; pilnuje tego `check`), skrypty z ConfigMapy renderowanej z `load/` (dowiązanie w `files/`, jak
`default.vcl`), adresy usług klastra (`http://store:3000`, `http://api`), `STORE_ORIGIN` z domeny, bez sekretów i bez
tokenu konta usługi, `backoffLimit: 0`. Ten sam CronJob posłuży pomiarowi obciążenia na serwerze w etapie 2.

### Powrót do `seed` albo `medium`

- **Do seeda:** `generate PROFILE=seed REPLACE=<data>`: ten sam przebieg i ten sam Job (zamknięcie, `bin/seed-demo`,
  sprawdzenie, otwarcie), minuty, bez pliku puli zdjęć. `TRUNCATE` seedów zwalnia miejsce tabel od razu, przebudowa
  indeksu usuwa stary indeks. Konta generatora w Keycloaku zostają (wyłączone, bez poświadczeń; reset seedów ich nie
  usuwa) do następnego generowania, które je uzgadnia.
- **Do `medium` z `server`:** PR z `demoData.profile: medium`, wdrożenie, potem `generate PROFILE=medium
  REPLACE=<data>`. Krok kont usuwa wtedy do 900 tys. kont pojedynczymi wywołaniami; czas jest niezmierzony.
- `make -C infra/k8s reinitialize` działa tylko przy danych `seed` w fazie `idle` (także bez zapisu stanu); w każdym
  innym stanie odmawia i wskazuje `generate PROFILE=seed` (inaczej zapis stanu przestałby opisywać dane).

### Logi i metryki

Zapis powstaje w klastrze i poza serwerem, bez udziału komputera operatora i bez sesji SSH:

- **Pełny log Joba i metryki** zbiera na bieżąco Alloy i wysyła do Grafana Cloud (logi podów; metryki węzła
  z node-exportera, pamięć i CPU kontenerów, restarty). Ta kopia nie zależy od rotacji logu kontenera (10 MiB na
  plik; przebudowa indeksu przy 10 mln produktów loguje postęp co partię i przekracza ją wielokrotnie), od istnienia
  poda ani od restartu serwera (Z10). Retencja planu to 14 dni.
- **Blok podsumowania** na końcu logu kontenera (czasy kroków, najmniejsze wolne miejsce, kod) i linia powodu
  w stanie poda: czytelne z klastra, dopóki istnieje pod Joba; cel `generate` niczego z Jobów nie usuwa.
- **Odczyty strażnika** (wolne miejsce co 5 minut) są w logu Joba.

Trwała kopia powstaje z tych źródeł po przebiegu, w dowolnej chwili w czasie retencji: cel `generate` (także przy
wznowieniu) zapisuje w `GENERATE_OUT` log kontenera, podsumowanie i `sizes.txt` (tabele, indeksy, Elasticsearch),
a sesja prowadząca eksportuje z Grafana Cloud pełny log i szczyty metryk i zapisuje raport w `docs/reports/`
(czasy kroków, rozmiary, szczyt dysku, szczyty pamięci i CPU, restarty kontenerów, wynik sprawdzenia; liczby opisują
ten serwer i ten profil). Przebieg `medium` na serwerze sprawdza kompletność kopii w Grafana Cloud przed przebiegiem
`server`: pierwsza i ostatnia linia logu Joba oraz liczba linii `[OK]` zgadzają się z logiem kontenera (Alloy pomija
linie `DETAIL:` i `STATEMENT:`, więc pełna liczba linii może się różnić). To nie dowodzi kompletności przy wielkości
logu `server`.

Funkcje zapisu logu, rozmiarów, odczytu pliku puli zdjęć i tworzenia sekretu są dziś w `load.sh`; przechodzą do
wspólnego pliku `scripts/demo-data-lib.sh` używanego przez `load.sh` i `generate.sh`, bez zmiany zachowania `load`.

### Ochrona przed przypadkowym uruchomieniem

- CronJob `demo-data` jest zawieszony, a Argo CD przywraca zawieszenie (`selfHeal`).
- Job bez `DEMO_DATA_CONFIRM` kończy się kodem 64 bez zmian (także utworzony ręcznie albo z interfejsu Argo CD).
- Cel `generate` wymaga zgody użytkownika w rozmowie, słowa `tak` i `REPLACE` równego `data` z zapisu stanu; profil
  musi być tym z wdrożonych wartości.
- `make -C infra/k8s rerun` odmawia Joba z etykietą generatora (`rerun` odtworzyłby Job razem z `DEMO_DATA_CONFIRM`).
- Cel woła `ao-guard.sh` jak pozostałe cele z kubeconfigiem produkcji.

### Kolejne wdrożenia

- Dane zostają w wolumenach `local-path`; `initialize` ich nie rusza (Z6), `migrate`, `purge-caches` i `backfill`
  działają jak dziś.
- `make -C infra/k8s status` pokazuje dodatkowo: `data` i `phase` z zapisu stanu, czy sklep jest zamknięty
  (reguła `shop-closed`) i ostatnią linię logu najnowszego Joba `initialize` (liczby wierszy).
- Sprawdzenie pierwszego wdrożenia po generowaniu (po `medium` i po `server`): aplikacje `Synced` i `Healthy`,
  Joby wersji `Complete`, czas `initialize` z kolumny `DURATION`, liczby z jego logu równe liczbom z zapisu stanu
  (plus zamówienia złożone od tamtej pory), sklep otwarty. Wynik trafia do raportu. `initialize` dłuższe niż 5 minut
  na danych `server` to zgłoszenie do backlogu (limit Joba to 30 minut).
- Rozmiarów `storage.*` w `prod` nie zmieniamy: `volumeClaimTemplates` StatefulSetu są niezmienne, a `local-path`
  i tak ich nie egzekwuje.
- Migracja na tabeli z milionami wierszy musi zmieścić się w limicie Joba `migrate` (30 minut); ocena należy do
  przeglądu każdej przyszłej migracji.

## Scenariusze awarii

| Zdarzenie | Co się dzieje | Stan i dalsze kroki |
|---|---|---|
| Przerwany skrypt, utrata połączenia albo uśpienie komputera operatora | Job działa dalej, strażnik i zbieranie logów działają w klastrze | Sklep zamknięty, faza z zapisu stanu; wznowienie celem `generate` |
| Usunięty albo zabity pod Joba (brak pamięci, eksmisja, limit czasu) | Job kończy się błędem i nie startuje ponownie (Z9); przy TERM skrypt zatrzymuje generator; sesje bazy generatora kończą się same także po zabiciu bez sygnału (Z11) | Dane niepełne, sklep zamknięty; wznowienie ustawia `data: failed`, potem ponowienie albo seed |
| Restart Postgresa, Elasticsearcha albo Keycloaka w trakcie | Krok generatora kończy się błędem (przebudowa indeksu ponawia tylko błędy przejściowe), Job kończy się błędem | Jak wyżej |
| Restart Traefika | Reguła działa dla nowych połączeń | Sklep nadal zamknięty |
| Restart serwera w fazie `closing` | Nic nie zostało zastąpione; reguła i wyłączona synchronizacja zostają | Wznowienie cofa do stanu sprzed startu |
| Restart serwera w fazie `running` | Job kończy się błędem, jego pod i log kontenera znikają (Z10); reguła, zapis stanu, wyłączona synchronizacja i sekret puli zdjęć zostają | Sklep zamknięty; wznowienie usuwa sekret, przywraca synchronizację i ustawia `data: failed`; log z Grafana Cloud |
| Restart serwera w fazie `checking` albo `opening` | Dane są kompletne; w `checking` sprawdzenie nieukończone, reguła istnieje; poda Joba może już nie być | Wznowienie powtarza sprawdzenie (bez logu kontenera, jeśli poda nie ma) i otwiera albo ustawia `data: failed`; z `opening` tylko kończy otwarcie |
| Dwie kopie celu naraz (np. wznowienie przy uśpionym pierwszym procesie) | Zapis stanu drugiej w kolejności kopii kończy się konfliktem wersji | Ta kopia wychodzi bez zmian; działa jedna |
| Restart serwera przy `data: failed` | Reguła zostaje | Sklep zamknięty do ponowienia albo powrotu do seeda |
| Awaria Cloudflare albo sieci operatora w trakcie sondy | Odczyt nieudany, ponawiany do limitu | Przed generowaniem: cofnięcie, dane bez zmian; przy otwieraniu: faza zostaje `opening`, ponowne uruchomienie kończy otwarcie |

Automatyczne aktualizacje serwera nie restartują go same (`unattended-upgrades` bez automatycznego restartu).

## Przebieg na serwerze

Kroki sesji prowadzącej po zmergowaniu epiku; każdy krok ze zgodą jest osobną zgodą użytkownika w rozmowie.

1. Wdrożenie `main` (polecenie i zatwierdzenie użytkownika); `status`.
2. Użytkownik podaje stan automatycznej kopii OVH; trafia do raportu jako fakt (U3).
3. `generate PROFILE=medium REPLACE=seed` (zgoda). Eksport logu i metryk z Grafana Cloud, porównanie z logiem
   kontenera (pierwsza i ostatnia linia, liczba linii `[OK]`), raport `medium` na serwerze. Przegląd sklepu i panelu.
4. Analiza przed `server`: czas `medium` na serwerze × 10 wobec limitu 24 h, rozmiary `medium` × 10 (produkty)
   i × 5 (zamówienia) wobec wolnego miejsca, szczyty pamięci Postgresa i Elasticsearcha wobec limitów, restarty
   kontenerów. To projekcja do decyzji użytkownika, nie weryfikacja.
5. PR z `demoData.profile: server`; jego wdrożenie jest pierwszym wdrożeniem po generowaniu `medium` (sprawdzenie
   z „Kolejne wdrożenia”).
6. `generate PROFILE=server REPLACE=medium` (zgoda). Eksport logu i metryk z Grafana Cloud, raport `server` na
   serwerze.
7. Następne zwykłe wdrożenie (np. PR raportu): sprawdzenie na danych `server`.

## Model zagrożeń

- **Aktywa.** Dane sklepu na serwerze przed przypadkowym zastąpieniem; dostępność i spójność publicznego demo; dane
  dostępu do puli zdjęć; dysk serwera.
- **Drogi w zakresie i sprawdzenia:**
  - przypadkowe albo powtórne generowanie: „Ochrona przed przypadkowym uruchomieniem”; testy: render (`suspend`),
    Job bez `DEMO_DATA_CONFIRM` (kod 64, dane bez zmian), `generate` bez `tak`, bez `REPLACE` i ze złym `REPLACE`,
    `rerun` Joba generatora;
  - sklep otwarty na niepełnych danych: reguła poza chartem, restart Traefika, sonda przed zastąpieniem danych
    i przed zakończeniem; test dymny z ciągłym ruchem przez ingress w trakcie zamykania, test nieudanego Joba
    i nieudanego sprawdzenia (sklep zamknięty), cofnięcie przy `data: failed` (reguła zostaje), brak śledzenia
    Argo CD na obiektach celu, przerwanie skryptu po każdym kroku (sklep nigdy nie jest otwarty przed krokiem 6),
    dwie kopie celu naraz (zapis warunkowy);
  - zapełnienie dysku: strażnik w podzie niezależny od komputera operatora, zatrzymanie całej grupy procesów,
    sesje bazy kończone przez serwer po zniknięciu klienta; testy skryptu z podstawionym `df` (próg, odczyt nieudany, odczyt nieliczbowy), z poleceniem
    generatora na pierwszym planie i z sygnałem TERM;
  - wyciek danych dostępu do puli zdjęć: tylko cztery zmienne odczytu, sekret na czas przebiegu, usuwany także po
    błędzie i przy wznowieniu; testy jak w `load` (brak wartości w wyjściu, linii poleceń i `GENERATE_OUT`);
  - Job k6 bez sekretów i tokenu; ruch tylko do usług przestrzeni.
- **Stan obecny bez zmian.** Hasło administratora Keycloaka we wspólnym sekrecie stosu (#485).
- **Poza zakresem.** Utrata serwera albo dysku: kopie restic są wyłączone (U3), a dane generatora odtwarza ponowne
  generowanie z tym samym profilem i chwilą odniesienia; zamówienia złożone po generowaniu i zmiany z panelu nie
  mają kopii w klastrze.

## Poza zakresem

- Pomiar obciążenia k6 na serwerze (cel `measure`, zamówienia z pomiaru, wyniki): etap 2, na CronJobie `load`.
- Kopie restic przy dużej bazie (weryfikacja bez drugiej kopii bazy, limity, R2 ponad 10 GB): #484.
- Zmiana zasobów i ustawień Postgresa i Elasticsearcha w `prod`: po raportach z serwera.
- Poprawki wydajności listingu, wyszukiwarki i panelu przy dużym katalogu: epiki z tablicy (#781).
- Pomiar `full` na k3d: odłożony (U1).
- Stały SealedSecret puli zdjęć (U5).

## Kryteria akceptacji

1. Render `prod` zawiera zawieszony CronJob `demo-data` (`backoffLimit: 0`, limity czasu `medium` 6 h i `server`
   24 h, `terminationGracePeriodSeconds: 120`, zasoby `resources.demoData`, sekret puli tylko w jego kontenerze
   i opcjonalny) i zawieszony CronJob `load` (obraz z digestem, bez sekretów, `automountServiceAccountToken: false`).
   Schemat odrzuca `prod` bez `anchor`, bez `photosSecretName`, z profilem innym niż `medium` i `server` albo
   z `minFreeGb` poniżej 10. Selftest łapie CronJob `demo-data` i `load` bez `suspend` oraz skrypt Joba bez
   sprawdzenia `DEMO_DATA_CONFIRM` i bez strażnika miejsca.
2. Skrypt `demo-data.sh` (testy z podstawionymi poleceniami): `DEMO_DATA_CONFIRM` puste albo inne niż profil i `seed`
   daje kod 64 i nic się nie uruchamia; `seed` uruchamia `bin/seed-demo`, profil `bin/generate-demo`; za mało miejsca
   albo nieudany odczyt przed startem kod 74; spadek poniżej progu w trakcie, trzy nieudane odczyty z rzędu i odczyt
   nieliczbowy zatrzymują grupę procesów generatora razem z poleceniem działającym na pierwszym planie i niczego
   poza nią, kod 75; sygnał TERM robi to samo; kod generatora przechodzi na wyjście; blok podsumowania z czasami kroków i linia powodu powstają na każdej z tych ścieżek. Kontener
   CronJoba ma `PGOPTIONS` z `client_connection_check_interval`; na działającym stosie sesja procesu PHP z tą
   zmienną kończy się po zabiciu klienta sygnałem KILL w trakcie długiego zapytania (Z11).
3. `generate` odmawia bez zmian (testy skryptu na atrapach `kubectl`): brak albo zły `PROFILE`, profil inny niż
   w CronJobie, brak albo zły `REPLACE`, brak `tak`, aktywny Job, trwająca synchronizacja, niegotowy Deployment albo
   StatefulSet, brak pliku puli zdjęć albo zmiennej odczytu (tylko gdy CronJob ma sekret puli i profil nie jest
   `seed`), za mało wolnego miejsca (próg `diskPeakGb` tylko dla `medium` i `server`), nieczytelny odczyt miejsca,
   `GENERATE_OUT` w repo albo bez prawa zapisu, brak `KUBE_DIR`, sonda któregokolwiek adresu niezgodna ze stanem albo
   nieczytelna.
4. `generate` na ścieżce poprawnej (profil i `seed`, z Argo CD i bez niego) wykonuje kroki i zapisy stanu
   w kolejności ze specyfikacji: zapis `closing` przed pierwszą zmianą, `running` z nazwą Joba przed jego
   utworzeniem sekretu i Joba, `opening` z `data` równym profilowi przed usunięciem reguły, `idle` jako ostatni krok
   po sondzie otwarcia; zapisuje log kontenera, podsumowanie i `sizes.txt` i czyści pulę `cache.sitemap` w każdym
   podzie `api` przed Jobem `load`. Zamknięcie jest potwierdzone dopiero, gdy każdy
   adres sondy podaje „zamknięty”; adres z kodem poniżej 500 (strona z cache) albo nieczytelny oraz synchronizacja,
   która ruszyła po wyłączeniu, dają cofnięcie z kodem 1 przed utworzeniem Joba: sekret usunięty, a reguła zostaje
   przy starcie z `data: failed`. Job nieudany (także bez poda), Job po czasie, kod 75 i każde nieudane sprawdzenie
   po generowaniu (nieważny indeks, alias na zero albo wiele indeksów, stan `red`, indeks bez aliasu, nieudany Job
   `load`): sekret usunięty, reguła istnieje (założona, jeśli jej nie było), synchronizacja przywrócona do
   `rootAutomated`, na końcu `data: failed` z powodem, kod 1. Nieudana sonda otwarcia zostawia fazę `opening`
   i kod 1. Każdy zapis stanu jest warunkowy względem odczytanej wersji, a przed każdą zmianą w klastrze proces
   sprawdza, że wersja zapisu jest tą z jego ostatniego zapisu; konflikt albo inna wersja kończy proces kodem 1 bez
   dalszych zmian (test: druga kopia działa między dwiema zmianami w klastrze pierwszej). Obiekty tworzone przez cel (reguła, zapis stanu, sekret) nie mają etykiet ani adnotacji śledzenia Argo CD.
5. Przerwanie i wznowienie (testy z przerwaniem skryptu po każdym wywołaniu `kubectl` na każdej ścieżce, potem
   ponowne uruchomienie): po żadnym przerwaniu reguła nie znika przed krokiem 6, przy `data: failed` reguła istnieje,
   a po zakończeniu sekretu nie ma i synchronizacja jest równa wartości sprzed pierwszego wyłączenia. Wznowienie nie
   pyta o `tak`, nie wymaga `REPLACE`, nie tworzy sekretu ani Joba: `closing` cofa, `running` dołącza do Joba albo
   czyta jego wynik (bez Joba albo z Jobem nieudanym bez poda: krok 7), `checking` powtarza sprawdzenia (także gdy
   poda Joba już nie ma), `opening` kończy otwarcie; `PROFILE` inny niż `target` to odmowa. Sygnał w trakcie
   oczekiwania kończy skrypt bez otwarcia sklepu i bez usunięcia Joba. Cel w trakcie oczekiwania niczego nie zbiera
   i nie używa SSH.
6. Wartości `DEMO_PHOTOS_*` nie pojawiają się w wyjściu, linii poleceń ani `GENERATE_OUT`; token zapisu nie trafia do
   klastra (testy jak dla `load`).
7. Test dymny k3d (`make -C infra/k8s smoke`, także w `deploy.yml`, bez pliku puli zdjęć) uruchamia cel `generate`
   z profilem `small` zamiast samego Joba: żądania przez ingress wysyłane bez przerwy przestają dostawać 200 po
   zamknięciu (Z2), po przebiegu sklep odpowiada, zapis stanu to `idle` z `data: small`, Job `load` przeszedł, liczby
   produktów i zamówień są te z profilu. `SMOKE_BREAK=demo-data` (nieudany generator): cel kończy się kodem 1,
   ingress odpowiada 502, `data: failed`; potem `generate PROFILE=seed REPLACE=failed` otwiera sklep na danych
   seeda. Raport zawiera wynik obu ścieżek.
8. `rerun` odmawia Joba generatora; `reinitialize` odmawia poza danymi `seed` w fazie `idle`; oba bez zmian
   w klastrze (testy).
9. `status` pokazuje `data`, `phase`, zamknięcie i linię `initialize`; brak zapisu stanu wypisuje jako `seed`, `idle`.
10. `load` z `LOAD_DATA` działa jak przed wydzieleniem wspólnego pliku (istniejące testy `test_load_script.py`
    przechodzą bez zmiany asercji poza utworzeniem Joba z `DEMO_DATA_CONFIRM`).
11. `make -C infra/k8s check` przechodzi; nowe przypadki selftestu kończą `check` błędem (wynik obu uruchomień
    w raporcie zadania).
12. Dokumentacja z sekcji „Dokumentacja” jest zmieniona; żaden tekst nie przedstawia pełnej skali jako
    zweryfikowanej ani kopii OVH jako aktywnej.

Kroki z „Przebieg na serwerze” nie są kryteriami epiku: wykonuje je sesja po merge'u, za zgodą.

## Dokumentacja

Zmieniana razem z implementacją:

- `docs/features/demo-data/spec.md`: P2 i W3 według U1, „Po epiku”, „Poza zakresem”, „Model zagrożeń” (CronJob
  także w `prod`), opis CronJoba (skrypt, `DEMO_DATA_CONFIRM`, strażnik miejsca).
- `docs/vision.md`: „Skala docelowa” (dane w skali na serwerze demo, pomiar `full` odłożony, brak weryfikacji pełnej
  skali) i „Dane demo”.
- `infra/k8s/README.md`: sekcja generowania danych na serwerze (przygotowanie, `generate`, wznowienie, błędy,
  powrót do seeda i `medium`, wdrożenie po generowaniu, eksport logu z Grafana Cloud).
- `infra/k8s/CLAUDE.md` i główny `CLAUDE.md`: `generate` na liście celów wymagających zgody; `rerun` nie ponawia
  Joba generatora.
- `docs/features/deploy/spec.md` i `docs/features/deploy-ops/spec.md`: CronJoby `demo-data` i `load` w `prod`,
  zamknięcie sklepu a alerty.
- `docs/adr/backups-observability.md`: klaster ma jedną, tymczasową NetworkPolicy (zamknięcie sklepu).
- Komentarze celów w `infra/k8s/Makefile` i nagłówki skryptów.

## Ryzyka

- Czas `server` na tym serwerze jest nieznany. Postgres ma limit 2 GiB i ustawienia domyślne, a indeksy na losowych
  UUID przy 10 mln produktów nie mieszczą się w pamięci, więc czas może rosnąć szybciej niż liniowo. Odpowiedź:
  przebieg `medium` na serwerze, projekcja przed `server`, limit 24 h, sklep zamknięty do skutku.
- Zachowanie sklepu przy 10 mln produktów jest niezmierzone (H1–H4 w specyfikacji DEMO-DATA). Sprawdzenie po
  generowaniu potwierdza poprawność odpowiedzi, nie ich szybkość; `smoke.js` ma limit czasu scenariusza 30 s.
  Zbyt wolny sklep kończy się nieudanym sprawdzeniem i zamkniętym sklepem, a decyzję (powrót do `medium`, poprawki)
  podejmuje użytkownik.
- Usunięcie podów Traefika zrywa na kilkanaście sekund cały ruch przychodzący klastra (na serwerze tylko sklep).
- Przerwany generator zostawia dane niepełne do ponowienia albo powrotu do seeda; nic ich nie przywraca samo.
- Awaria skryptu między zapisem `running` a utworzeniem Joba (w tym przy tworzeniu sekretu) oznacza dane jako
  `failed`, choć są nietknięte
  (ostrożnie: brak Joba nie dowodzi, że nie działał); kosztem jest ponowne generowanie albo powrót do seeda.
- Sonda z komputera operatora przechodzi przez Cloudflare; jej kody spoza 502–504 i poniżej 500 są odczytem
  nieudanym, więc awaria Cloudflare zatrzymuje cel, a nie otwiera ani nie zamyka sklepu.
- Konta generatora zostają w Keycloaku po powrocie do seeda; przejście z `server` do `medium` usuwa je pojedynczo.
- Retencja Grafana Cloud (14 dni) ogranicza czas na eksport pełnego logu i odczyt szczytów do raportu; po restarcie
  serwera w trakcie przebiegu to jedyna kopia logu. Przebieg `medium` porównuje ją z logiem kontenera, co nie dowodzi
  kompletności przy wielkości logu `server`.
- Brak przecieku ruchu po restarcie węzła (Z10) potwierdziła jedna próba na k3d; na serwerze nie był sprawdzany.
- Cache ISR sklepu na dysku poda (limit 2 GiB) i `count(*)` w `initialize` przy 10 mln produktów: obserwowane
  w krokach na serwerze, poprawki poza tym etapem.

## Wymagane przed startem

- PR #756 zmergowany do `main`.
- Plik `~/.config/shop/demo-photos.env` z czterema zmiennymi odczytu na komputerze, z którego idzie `generate`
  (istnieje; wartości nie przechodzą przez rozmowę).
- Przed krokami na serwerze: wdrożenie `main` z tym epikiem i zgody użytkownika z „Przebieg na serwerze”.
