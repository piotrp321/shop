# DEMO-SCALE etap 1: dane w skali na serwerze demo

Ręcznie uruchamiane generowanie danych demo (generator z `docs/features/demo-data/spec.md`) na serwerze worf.pl:
najpierw profil `medium`, po analizie wyników i zgodzie użytkownika profil `server`. Sklep jest na czas generowania
zamknięty i wraca do publicznej dostępności dopiero po sprawdzeniu danych, wyszukiwarki, listingu i checkoutu. Całość to
dwa polecenia: `make -C infra/k8s generate` i `make -C infra/k8s open`.

Etap 1 (#786) jest wydzielony z #484, które obejmuje resztę (pomiar k6 na serwerze, kopie przy dużej bazie,
konfiguracja usług). Opiera się na PR #756 (CronJob `demo-data`, `LOAD_DATA`, kalibracja `medium`). Specyfikacja opisuje
stan po tym etapie.

Issues: #786
Zakres: `infra/k8s`, `load`, `docs/features/demo-scale`, `docs/features/demo-data/spec.md`, `docs/features/deploy/spec.md`, `docs/features/deploy-ops/spec.md`, `docs/adr/backups-observability.md`, `docs/vision.md`, `docs/reports`, `CLAUDE.md`

## Cel i zakres

- Generator danych demo działa na serwerze jako Job `demo-data-run` z zawieszonego CronJoba `demo-data`, uruchamiany
  wyłącznie celem `make -C infra/k8s generate` po zgodzie użytkownika w rozmowie, która nazywa operację, host i profil.
- Sklep jest zamknięty od startu generowania do udanego sprawdzenia po nim (`make open`). Nieudane albo przerwane
  generowanie zostawia sklep zamknięty.
- Generator sam przerywa pracę, zanim skończy się miejsce na dysku serwera.
- Po generowaniu `make open` sprawdza indeksy, wyszukiwarkę, listing, stronę produktu, koszyk i checkout gościa.
- Logi generatora, czasy kroków, rozmiary i odczyty zasobów zostają poza kontenerem (Grafana Cloud), niezależnie od
  rotacji jego logów.
- Z każdego stanu (także po błędzie) da się wrócić do danych `seed` albo ponowić generowanie; odzyskiwanie po awarii
  jest ręczne.
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
- **U8. Wariant minimalny.** Dwa polecenia, `generate` i `open`, w zupełności wystarczą. Użytkownik akceptuje ręczne
  odzyskiwanie po awarii, ponowne generowanie od początku i dłuższą niedostępność sklepu. Wymagania: `make open` nie
  może wykorzystać sukcesu poprzedniego Joba po przerwaniu nowego generowania, a tymczasowy sekret jest usuwany.
  Rozwiązanie nie jest rozbudowywane ponad te wymagania.

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
  zostały `Ready`, 0 restartów). Usunięcie reguły przywraca ruch w kilkanaście sekund. Nowy pod przez ok. 0,1 s po
  starcie dostaje `connection refused`, więc Job `load` czeka na API (init container `api-reachable`).
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
- **Z7. Aplikacja `shop` Argo CD ma `selfHeal` i `prune`.** Zasób spoza chartu (reguła zamknięcia, Job z `create job
  --from`, sekret puli zdjęć) nie jest przez nią śledzony (bez etykiet i adnotacji śledzenia), więc synchronizacja go
  nie usuwa ani nie przywraca.
- **Z8. Jedyne publiczne wejście to Traefik.** Chart ma Ingressy tylko dla hostów sklepu, `admin.`, `api.`, `auth.` i `img.`,
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

## Rozwiązanie

### Stan sklepu i blokada

Stan nie jest zapisywany w osobnym obiekcie; wynika z obiektów klastra:

- **Zamknięty** znaczy: w przestrzeni `shop` istnieje NetworkPolicy `shop-closed` (ruch przychodzący do każdego poda
  tylko z podów tej przestrzeni). Wszystkie hosty (`worf.pl`, `api.`, `admin.`, `auth.`, `img.`) odpowiadają 502.
  Pody działają, więc alert `WorkloadNotReady` się nie odzywa. Regułę zakłada `generate`, usuwa wyłącznie `open`; nie
  jest częścią chartu i nie ma etykiet ani adnotacji śledzenia Argo CD, więc wdrożenie ani synchronizacja jej nie
  usuną (Z7). Cel `status` pokazuje, czy reguła istnieje.
- **Blokada `demo-data-lock`.** `generate` i `open` działają pojedynczo; tę samą blokadę bierze na czas przebudowy indeksu
  `make -C infra/k8s reindex` (`docs/features/search/spec.md`, „Infrastruktura”). Blokadą jest ConfigMap `demo-data-lock`
  w przestrzeni `shop` z dwoma polami: `holder` (polecenie, profil, host operatora, pid i czas UTC startu) i `token`
  (losowy). Nie ma etykiet ani adnotacji śledzenia Argo CD (jak reguła). Zakłada ją `kubectl create`, który przy
  istniejącej blokadzie kończy się błędem `AlreadyExists`, więc dostaje ją tylko jeden przebieg. `generate` sprawdza ją
  przed pytaniem, zakłada zaraz po `tak`, przed pierwszą zmianą, i trzyma do końca, razem z czekaniem na Job i z `open`,
  które sam woła. `open` uruchomiony ręcznie zakłada ją na starcie, przed pierwszą zmianą (także przed usunięciem
  pozostawionego sekretu), i trzyma do końca. `open` wołany przez `generate` własnej nie zakłada: dostaje token
  `generate` w zmiennej `DEMO_DATA_LOCK_TOKEN` i odmawia bez zmian, gdy blokady nie ma albo ma inny token (ręcznie
  ustawiona zmienna jej nie omija). Odmowa z powodu blokady podaje jej posiadacza. Każdy cel zwalnia blokadę przy każdym
  wyjściu, które przechwytuje (koniec, błąd, odmowa, HUP, INT, TERM), i tylko wtedy, gdy token w niej jest jego. Po
  zabiciu sygnałem KILL albo utracie połączenia przy zwalnianiu blokada zostaje: oba cele odmawiają, a usuwa ją ręcznie
  użytkownik, gdy żaden `generate` ani `open` nie działa (runbook). Decyzje `open` (stan Joba, sprawdzenia) i usunięcie
  reguły zapadają pod blokadą, więc w tym czasie żaden `generate` nie usunie Joba ani nie zmieni danych.
- **Śladem przebiegu jest Job `demo-data-run`** (stała nazwa, tworzony z CronJoba `demo-data` przez
  `kubectl create job --from`, poza chartem, bez śledzenia Argo CD). Dane zmienia wyłącznie nowy Job, a ten nie powstanie,
  dopóki istnieje stary o tej samej nazwie. `generate` usuwa Job poprzedniego przebiegu (razem z podem) tuż przed
  utworzeniem nowego: do tej chwili dane są nietknięte i stary Job je opisuje, a od niej do sukcesu nowego Joba nie ma
  żadnego `Complete`, więc `open` po przerwaniu nowego generowania odmawia: sukces poprzedniego przebiegu nie może
  zostać wzięty za sukces bieżącego. `open` otwiera sklep tylko po Jobie `demo-data-run`,
  który jest `Complete`. Przebieg trwa, dopóki Job jest aktywny; `generate` i `open` odmawiają przy aktywnym Jobie
  przestrzeni (poza Jobami CronJoba `rate-limit-check`, który działa co 5 minut przez najwyżej minutę: zgodność
  z ABUSE-LIMITS, L12).
- **Zakres zamknięcia.** Reguła obejmuje każdy pod przestrzeni, więc zamyka wszystkie publiczne wejścia naraz:
  sklep, API, panel (`admin.`), logowanie (`auth.`) i obrazy (`img.`); innych wejść nie ma (Z8). Połączenia otwarte
  przed regułą zrywa restart poda Traefika (Z2); tam też kończą się połączenia Cloudflare z serwerem.
- **Cloudflare i cache.** Cloudflare ma jedną regułę cache: cały host `img.` (runbook, sekcja 4); stron HTML ani
  odpowiedzi API domyślnie nie przechowuje. W trakcie zamknięcia brzeg może dalej podawać obrazy `img.` i pliki
  statyczne buildów sklepu i panelu: to treść niezależna od danych (adres obrazu wynika z sumy pliku, pliki builda są
  z obrazu aplikacji). Ustawień Cloudflare nie widać z repo, więc zamknięcie potwierdza sonda dwóch adresów przez
  Cloudflare: API i strony głównej sklepu. Cache Varnisha i sklepu czyści generator (`storefront:cache:purge-all`).
  Pula `cache.sitemap` (mapa strony, ważna godzinę) jest plikowa, osobna w każdym podzie `api`, więc czyści ją `open`
  w każdym z nich przed sprawdzeniem; bez tego `load/smoke.js` czytałby produkty starego katalogu.
- `make -C infra/k8s status` pokazuje, czy sklep jest zamknięty, blokadę i jej posiadacza, Job `demo-data-run` i jego
  stan, aktywne Joby i to, czy sekret puli zdjęć został.

### Cel `make -C infra/k8s generate`

`make -C infra/k8s generate PROFILE=<seed|medium|server> GENERATE_PROBE_URL=<adresy> KUBE_DIR=<katalog>`. Łączy się
z klastrem przez kubeconfig z `KUBE_DIR` jak `restore` (`scripts/kube-jobs.sh`), woła `scripts/ao-guard.sh`. Zastępuje
dane sklepu, więc agent uruchamia go tylko po zgodzie użytkownika w rozmowie, która nazywa operację, host i profil;
skrypt pyta o słowo `tak`.

`GENERATE_PROBE_URL` to lista publicznych adresów przez ingress (na serwerze `https://api.worf.pl/` i
`https://worf.pl/`), o które skrypt pyta z komputera operatora: 502, 503 i 504 znaczą „zamknięty”, kod poniżej 500
„otwarty”, każdy inny kod i brak odpowiedzi to odczyt nieudany (ponawiany do limitu czasu, nigdy nie liczony jako
„zamknięty” ani „otwarty”). Sklep jest zamknięty albo otwarty dopiero wtedy, gdy mówi to każdy adres z listy.
`GENERATE_PROBE_HOST` ustawia nagłówki `Host` (klaster testowy, gdzie adresem jest węzeł); na serwerze nie pomaga, bo
`ipAllowList` Traefika odpowiada 403 każdemu adresowi spoza Cloudflare, więc sonda zawsze przechodzi przez Cloudflare.
**Wyzwanie Cloudflare.** Sonda liczy każdy kod poniżej 500 jako „otwarty”, a nie odróżnia odpowiedzi sklepu od
wyzwania Cloudflare (Bot Fight Mode, reguła WAF). Gdy Cloudflare odpowiada curlowi operatora 403 z wyzwaniem, zamknięcia
w kroku 3 sonda nie potwierdzi nigdy: cel kończy się kodem 1, dane są bez zmian, ale sklep zostaje zamknięty (reguła
jest już założona; `make open` otworzy go na Jobie poprzedniego przebiegu, jeśli ten jest `Complete`). Adresy sondy muszą więc dostawać z Cloudflare odpowiedź sklepu (bez nagłówka `cf-mitigated`);
jeśli ją dostają, to ustawienie Cloudflare (wyjątek od wyzwania dla adresu operatora albo dla tych dwóch adresów)
i należy do użytkownika. Runbook ma sprawdzenie przed uruchomieniem.

Przebieg:

1. **Sprawdzenia bez zmian** (odmowa kodem 1 albo 2, nic nie jest zmienione):
   - blokady `demo-data-lock` nie ma (odmowa podaje jej posiadacza);
   - `PROFILE` to `seed` albo profil wpisany w CronJobie `demo-data` wdrożonej rewizji (`demoData.profile`); inny
     profil wymaga zmiany wartości i wdrożenia;
   - CronJob `demo-data` istnieje i jest zawieszony; żaden Job przestrzeni nie jest aktywny; każdy Deployment
     i StatefulSet przestrzeni jest gotowy (generator kończy się błędem, gdy Elasticsearch, Postgres, Keycloak,
     `worker`, Varnish albo sklep nie odpowiada);
   - gdy profil nie jest `seed`, a CronJob ma sekret puli zdjęć (`photosSecretName`): plik puli zdjęć
     (`DEMO_PHOTOS_ENV_FILE`, domyślnie `~/.config/shop/demo-photos.env`) ma cztery zmienne odczytu (brak: kod 2);
   - wolne miejsce każdego węzła (kubelet, `/api/v1/nodes/<węzeł>/proxy/stats/summary`) da się odczytać jako liczbę
     (odczyt nieudany albo nieliczbowy to odmowa) i dla profilu innego niż `seed` to co najmniej `demoData.minFreeGb`,
     a dla `medium` i `server` także co najmniej 1,25 × `diskPeakGb` profilu (obowiązuje większa z dwóch liczb;
     GB to 10^9 bajtów, jak w `load`). **`seed` nie ma progu wolnego miejsca**: po zatrzymaniu przez strażnika wolne
     miejsce jest poniżej `minFreeGb`, a powrót do seeda (zaczyna od `TRUNCATE`) to jedyna droga naprawy.
2. **Pytanie.** Skrypt wypisuje host klastra, liczby produktów i zamówień w bazie teraz, profil docelowy, wolne miejsce
   węzłów, że sklep będzie niedostępny do udanego `make open`, i czeka na `tak` (bez `tak` odmowa, nic bez zmian).
   Po `tak` zakłada blokadę; gdy w międzyczasie założył ją inny przebieg, odmawia kodem 1 bez zmian.
3. **Zamknięcie.** Zakłada regułę `shop-closed` (jeśli jej nie ma), usuwa pody Traefika (`kube-system`) i czeka, aż
   sonda poda „zamknięty” (`GENERATE_CLOSE_WAIT` sekund, 120). Bez potwierdzenia: kod 1, dane bez zmian, sklep
   zamknięty, Job poprzedniego przebiegu bez zmian (`make open` otwiera sklep na nim, jeśli jest `Complete`).
4. **Generowanie.** Dla profilu z sekretem puli zdjęć tworzy sekret `demo-photos` z czterech zmiennych odczytu (przez
   stdin, bez wartości w linii poleceń i wyjściu). Czyta aktywne Joby jeszcze raz (działający Job innej kopii celu:
   odmowa, nic nie usunięte), usuwa Job `demo-data-run` poprzedniego przebiegu razem z podem
   (`--cascade=foreground --wait`) i tworzy nowy Job `demo-data-run` z CronJoba ze zmienną
   `DEMO_DATA_CONFIRM=<PROFILE>` (także dla `seed`: ten sam Job uruchamia wtedy `bin/seed-demo`) i czeka na jego
   koniec (`GENERATE_POLL` sekund, 30; do `activeDeadlineSeconds` Joba). Skrypt tylko czeka: niczego, co ma zostać po
   przebiegu, nie zbiera (zapis logów i metryk nie zależy od komputera operatora, „Logi i metryki”).
5. **Po Jobie.** Job `Complete`: uruchamia `open.sh` (niżej) pod swoją blokadą (`DEMO_DATA_LOCK_TOKEN`). Job nieudany
   albo po czasie: kod 1, sklep zamknięty, komunikat z powodem z logu zakończenia poda (`/dev/termination-log`; bez poda:
   log w Grafana Cloud).

Sekret `demo-photos` i blokada są usuwane przy każdym wyjściu celu (także po błędzie, HUP, INT i TERM). Po zabiciu
skryptu sygnałem KILL sekret usuwa `open` (bez aktywnego Joba), a blokadę użytkownik; `status` pokazuje oba. Każdy
błąd po zamknięciu zostawia sklep zamknięty; komunikat mówi, czy dane są jeszcze nietknięte (wtedy także `make open`),
i podaje dwie drogi: ponowić `generate PROFILE=<profil>` albo wrócić do seeda (`PROFILE=seed`).

Kody wyjścia: 0 dane wygenerowane, sprawdzone, sklep otwarty; 1 odmowa, brak `tak` albo błąd (komunikat mówi, czy sklep
jest zamknięty); 2 złe argumenty albo środowisko.

Przerwany skrypt (sygnał, utrata połączenia, uśpiony komputer) niczego nie sprząta poza sekretem i blokadą: jeśli Job
działa dalej, `make status` pokazuje go jako aktywny, a po jego końcu (`Complete`) sklep otwiera ręcznie `make open`;
po `Failed` albo gdy Jobu nie ma, wraca się do `generate`. Przerwanie przed usunięciem Joba poprzedniego przebiegu zostawia dane
nietknięte i ten Job (`open` otwiera sklep na nim); po usunięciu `open` odmawia do sukcesu nowego Joba.

CronJob `cart-purge-expired` działa w trakcie bez zmian: usuwa tylko wygasłe koszyki, których generator nie pisze.

### Cel `make -C infra/k8s open`

`make -C infra/k8s open GENERATE_PROBE_URL=<adresy> KUBE_DIR=<katalog>` (te same zmienne sondy co w `generate`,
`GENERATE_OPEN_WAIT` sekund, 300, na „otwarty”). `generate` woła go po udanym Jobie; ręcznie uruchamia się go po
przebiegu przerwanym przed otwarciem. Zmienia serwer (otwiera sklep), więc agent uruchamia go po zgodzie, która nazywa
operację, host i profil, tak jak `generate`. Jest idempotentny. Przebieg:

1. **Blokada, potem odmowa przy aktywnym Jobie** przestrzeni, bez zmian. Uruchomiony ręcznie zakłada blokadę
   `demo-data-lock` (zajęta: odmowa bez zmian z jej posiadaczem) i trzyma ją do końca; wołany przez `generate`
   sprawdza, że blokada istnieje z tokenem `DEMO_DATA_LOCK_TOKEN`.
2. **Sekret puli zdjęć** pozostawiony (zabity `generate`) jest usuwany, gdy żaden Job nie działa.
3. **Odmowy bez dalszych zmian:** brak Joba `demo-data-run` albo Job inny niż `Complete` (np. po nowym `generate`,
   który przerwano po usunięciu Joba poprzedniego przebiegu).
4. **Sprawdzenia** (każde nieudane: kod 1, sklep zamknięty). Pula `cache.sitemap` wyczyszczona w każdym podzie `api`
   (`bin/console cache:pool:clear`); żaden indeks bazy `shop` nie jest nieważny (`pg_index.indisvalid`, `indisready`);
   alias katalogu wskazuje dokładnie jeden indeks, który ma dokumenty, stan klastra Elasticsearcha nie jest `red`, nie
   ma indeksów bez aliasu po przebudowie; Job z zawieszonego CronJoba `load` (niżej) wykonuje `load/smoke.js` na
   usługach klastra. Dane sprawdza już generator: `demo-data:verify` jest ostatnim krokiem `bin/generate-demo`, a Job
   z kodem 0 go przeszedł (dla `seed` kod 0 `bin/seed-demo`).
5. **Podsumowanie.** Liczby produktów i zamówień (obejmują zamówienie Joba `load`) i rozmiary tabel, indeksów
   i Elasticsearcha.
6. **Otwarcie.** Usuwa regułę `shop-closed`; sonda podaje „otwarty”. Gdy w limicie sonda tego nie poda (np. awaria
   Cloudflare), reguła już nie istnieje, kod 1, a ponowne `make open` kończy otwarcie.

Kody wyjścia: 0 sprawdzone, sklep otwarty; 1 odmowa, nieudane sprawdzenie albo sonda bez „otwarty”; 2 złe argumenty.

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
  2. odmawia bez zmian (kod 74), gdy wolne miejsce (`df -Pk /`, Z3) jest poniżej `minFreeGb` albo odczyt się nie udał
     albo nie jest liczbą; dla `seed` nie ma progu, tylko wymóg liczbowego odczytu (nieczytelny odczyt to kod 74);
  3. uruchamia w osobnej grupie procesów `bin/generate-demo --profile=… --anchor=… --force-reset` (dla `seed`:
     `bin/seed-demo`) i co `DEMO_DATA_SPACE_POLL` sekund (30) czyta wolne miejsce; co dziesiąty odczyt wypisuje;
  4. przy wolnym miejscu poniżej `minFreeGb` (nie dla `seed`), trzech nieudanych odczytach z rzędu albo odczycie
     nieliczbowym zatrzymuje wyłącznie grupę procesów generatora (także polecenie działające właśnie na pierwszym planie)
     i kończy się kodem 75. Sesje bazy generatora kończą się same (`PGOPTIONS`); sesji `api` i `worker`, innych
     podów ani usług skrypt nie rusza;
  5. to samo zatrzymanie wykonuje po sygnale TERM (limit czasu Joba, usunięcie poda);
  6. na końcu każdej ścieżki zapisuje jedną linię powodu (`exit <kod>: <powód>`) do `/dev/termination-log`, którą
     `generate` pokazuje w komunikacie błędu.
- **Zapas po zatrzymaniu.** Strażnik zatrzymuje zapis przy 32 GB wolnego (29,8 GiB): tuż powyżej progu alertu
  `NodeDiskFull` (15% z 192,9 GiB, czyli 28,9 GiB) i powyżej progów, przy których Elasticsearch przestaje
  przydzielać shardy (10% wolnego, 19,3 GiB) i blokuje zapis (5%), oraz progu eksmisji kubeleta. Między dwoma
  odczytami (30 s) generator dopisuje co najwyżej kilka GB (tempo `medium`: około 10 GB danych w pół godziny), a WAL
  Postgresa do 1 GB, więc zostaje ponad 20 GiB. Działania naprawcze potrzebują mniej niż 2 GiB i same zwalniają
  miejsce: powrót do seeda zaczyna od `TRUNCATE` (pliki tabel znikają od razu), a przebudowa indeksu najpierw usuwa
  indeksy bez aliasu (Z5). Ponowne generowanie tego samego profilu po zatrzymaniu przez strażnika skończyłoby się
  tak samo, więc decyzję (inny profil, zwolnienie miejsca) podejmuje użytkownik.
- **Seed bez progu miejsca.** Po zatrzymaniu przez strażnika wolne miejsce jest poniżej `minFreeGb`, a seed to jedyna
  droga naprawy, więc ani `generate`, ani Job nie stosują do `PROFILE=seed` progu `minFreeGb` (wymagany jest tylko
  liczbowy odczyt miejsca) i strażnik w Jobie seeda nie zatrzymuje go z powodu progu (zatrzymują go nieudane
  i nieliczbowe odczyty oraz TERM). Seed potrzebuje mniej niż 2 GiB. Ryzyko: poniżej górnej granicy (high watermark)
  Elasticsearcha (zajęte 90%, na serwerze około 20,7 GB wolnego) nowy indeks seeda może nie dostać shardu, dopóki
  Elasticsearch nie odświeży informacji o dysku (`cluster.info.update.interval`, 30 s): taka próba kończy się
  błędem, a ponowienie przechodzi.
- Wdrożony profil to deklaracja w repo: zmiana `medium` na `server` to PR z wartościami i zwykłe wdrożenie.

CronJob `load` (chart, wszystkie profile: `prod`, `ci` i `load`): zawieszony, obraz k6 przypięty digestem w `images.k6`
(ten sam co w `load/k6.sh`; pilnuje tego `check`), skrypty z ConfigMapy renderowanej z `load/` (dowiązanie w `files/`,
jak `default.vcl`), adresy usług klastra (`http://store:3000`, `http://api`), `STORE_ORIGIN` z domeny, bez sekretów
i bez tokenu konta usługi, `backoffLimit: 0`, init container `api-reachable` (Z1). Ten sam CronJob posłuży pomiarowi
obciążenia na serwerze w etapie 2. `load/smoke.js` obejmuje stronę główną, listing kategorii, stronę produktu,
wyszukiwarkę z filtrem i bez, koszyk i checkout gościa jednej sztuki z rozpoczętą płatnością; każda odpowiedź inna niż
oczekiwana kończy Job błędem. Checkout zostawia w danych jedno zamówienie `load-<n>@example.com`, które wygasa jak
każde nieopłacone.

### Powrót do `seed` albo `medium`

- **Do seeda:** `generate PROFILE=seed`: ten sam przebieg i ten sam Job (zamknięcie, `bin/seed-demo`, `open`), minuty,
  bez pliku puli zdjęć. `TRUNCATE` seedów zwalnia miejsce tabel od razu, przebudowa indeksu usuwa stary indeks.
  Konta generatora w Keycloaku zostają (wyłączone, bez poświadczeń; reset seedów ich nie usuwa) do następnego
  generowania, które je uzgadnia.
- **Do `medium` z `server`:** PR z `demoData.profile: medium`, wdrożenie, potem `generate PROFILE=medium`. Krok kont
  usuwa wtedy do 900 tys. kont pojedynczymi wywołaniami; czas jest niezmierzony.
- `make -C infra/k8s reinitialize` odmawia kodem 1 przed pytaniem, dopóki istnieje reguła `shop-closed` (dane są
  w trakcie zastępowania albo przebieg się nie udał): wraca się wtedy przez `generate PROFILE=seed`, który sprawdza
  i otwiera sklep.

### Logi i metryki

Zapis powstaje w klastrze i poza serwerem, bez udziału komputera operatora i bez sesji SSH:

- **Pełny log Joba i metryki** zbiera na bieżąco Alloy i wysyła do Grafana Cloud (logi podów; metryki węzła
  z node-exportera, pamięć i CPU kontenerów, restarty). Ta kopia nie zależy od rotacji logu kontenera (10 MiB na
  plik; przebudowa indeksu przy 10 mln produktów loguje postęp co partię i przekracza ją wielokrotnie), od istnienia
  poda ani od restartu serwera (Z10). Retencja planu to 14 dni.
- **Linia powodu** w stanie poda (`/dev/termination-log`) i odczyty strażnika (wolne miejsce co 5 minut) w logu Joba:
  czytelne z klastra, dopóki istnieje pod Joba; `generate` niczego z Jobów poza poprzednim `demo-data-run` nie usuwa.

Raport z przebiegu powstaje z Grafana Cloud po przebiegu, w dowolnej chwili w czasie retencji: sesja prowadząca
eksportuje pełny log i szczyty metryk i zapisuje raport w `docs/reports/` (czasy kroków z linii `[OK]` generatora,
rozmiary z podsumowania `open`, szczyt dysku, szczyty pamięci i CPU, restarty kontenerów, wynik sprawdzenia; liczby
opisują ten serwer i ten profil). Przebieg `medium` na serwerze sprawdza przed przebiegiem `server`, że log Joba
w Grafana Cloud jest kompletny (od pierwszej linii do końcowej linii `exit <kod>: <powód>`, z liniami `[OK]` wszystkich
kroków; Alloy pomija linie `DETAIL:` i `STATEMENT:`). To nie dowodzi kompletności przy wielkości logu `server`.

Funkcje odczytu pliku puli zdjęć, tworzenia sekretu i rozmiarów są we wspólnym pliku `scripts/demo-data-lib.sh`,
używanym przez `load.sh`, `generate.sh` i `open.sh`.

### Ochrona przed przypadkowym uruchomieniem

- CronJob `demo-data` jest zawieszony, a Argo CD przywraca zawieszenie (`selfHeal`).
- Job bez `DEMO_DATA_CONFIRM` kończy się kodem 64 bez zmian (także utworzony ręcznie albo z interfejsu Argo CD).
- Cel `generate` wymaga zgody użytkownika w rozmowie i słowa `tak`; profil musi być tym z wdrożonych wartości.
- `make -C infra/k8s rerun` odmawia Joba z etykietą generatora `app.kubernetes.io/name: demo-data` kodem 3 i wskazuje
  `make generate` (`rerun` odtworzyłby Job razem z `DEMO_DATA_CONFIRM`).
- Cele `generate` i `open` wołają `ao-guard.sh` jak pozostałe cele z kubeconfigiem produkcji.
- `generate`, `open` i `reindex` działają pojedynczo (blokada `demo-data-lock`, „Stan sklepu i blokada”).

### Kolejne wdrożenia

- Dane zostają w wolumenach `local-path`; `initialize` ich nie rusza (Z6), `migrate`, `purge-caches` i `backfill`
  działają jak dziś.
- Wdrożenie nie powinno zaczynać się w trakcie generowania: restartuje pody, których generator potrzebuje (Z4).
  Wdrożenie przy zamkniętym sklepie po nieudanym przebiegu jest możliwe (reguła zostaje), ale Job `initialize` może
  wtedy skończyć się błędem stanu mieszanego bez zmian (Z6) i zatrzymać synchronizację przed `purge-caches`
  i `backfill`; po udanym generowaniu albo powrocie do seeda domyka ją kolejne wdrożenie albo ręczna synchronizacja
  aplikacji `shop` w Argo CD (krok użytkownika). Po błędzie wymagającym poprawki kodu kolejność to: powrót do seeda,
  wdrożenie poprawki, ponowne generowanie.
- `make -C infra/k8s status` pokazuje dodatkowo ostatnią linię logu najnowszego Joba `initialize` (liczby wierszy).
- Sprawdzenie pierwszego wdrożenia po generowaniu (po `medium` i po `server`): aplikacje `Synced` i `Healthy`,
  Joby wersji `Complete`, czas `initialize` z kolumny `DURATION`, liczby z jego logu równe liczbom z podsumowania
  `open` (plus zamówienia złożone od tamtej pory), sklep otwarty. Wynik trafia do raportu. `initialize` dłuższe niż
  5 minut na danych `server` to zgłoszenie do backlogu (limit Joba to 30 minut).
- Rozmiarów `storage.*` w `prod` nie zmieniamy: `volumeClaimTemplates` StatefulSetu są niezmienne, a `local-path`
  i tak ich nie egzekwuje.
- Migracja na tabeli z milionami wierszy musi zmieścić się w limicie Joba `migrate` (30 minut); ocena należy do
  przeglądu każdej przyszłej migracji.

## Scenariusze awarii

Odzyskiwanie jest ręczne (U8): `make status`, potem ponowny `generate`, powrót do seeda albo `open`.

| Zdarzenie | Co się dzieje | Dalsze kroki |
|---|---|---|
| Przerwany skrypt, utrata połączenia albo uśpienie komputera operatora | Job działa dalej, strażnik i zbieranie logów działają w klastrze; sklep zamknięty | Po końcu Joba `Complete`: `make open`; `Failed`: ponowny `generate` albo seed |
| Drugi `generate` albo `open` w trakcie przebiegu | Odmowa bez zmian z posiadaczem blokady | Poczekać na koniec przebiegu (`make status`) |
| `generate`, `open` albo `reindex` zabity sygnałem KILL albo bez połączenia przy zwalnianiu blokady | Blokada `demo-data-lock` zostaje; `generate`, `open` i `reindex` odmawiają z jej posiadaczem | Gdy żaden `generate`, `open` ani `reindex` nie działa, użytkownik usuwa blokadę (runbook), potem jak w pozostałych wierszach |
| `generate` przerwany po `tak`, przed usunięciem Joba poprzedniego przebiegu | Dane nietknięte, Job poprzedniego przebiegu zostaje, sklep może być zamknięty | `make open` (jeśli ten Job jest `Complete`) albo ponowny `generate` |
| `generate` przerwany po usunięciu Joba poprzedniego przebiegu, przed końcem nowego | Joba `Complete` nie ma | `open` odmawia (brak Joba albo Job niezakończony); ponowny `generate` |
| Usunięty albo zabity pod Joba (brak pamięci, eksmisja, limit czasu) | Job kończy się błędem i nie startuje ponownie (Z9); przy TERM skrypt zatrzymuje generator; sesje bazy generatora kończą się same także po zabiciu bez sygnału (Z11) | Dane niepełne, sklep zamknięty; ponowny `generate` albo seed; log z Grafana Cloud |
| Restart Postgresa, Elasticsearcha albo Keycloaka w trakcie | Krok generatora kończy się błędem (przebudowa indeksu ponawia tylko błędy przejściowe), Job kończy się błędem | Jak wyżej |
| Restart Traefika | Reguła działa dla nowych połączeń | Sklep nadal zamknięty |
| Restart serwera w trakcie Joba | Job kończy się błędem, jego pod i log kontenera znikają (Z10); reguła i sekret puli zdjęć zostają | Sklep zamknięty; ponowny `generate` albo seed; sekret puli usuwa `generate` przy wyjściu, a po jego zabiciu `open`; log z Grafana Cloud |
| Restart serwera po udanym Jobie, przed otwarciem | Job `Complete`, reguła istnieje | `make open` |
| Nieudane sprawdzenie w `open` | Sklep zamknięty, Job `Complete` | Ponowny `generate` albo seed (`open` powtarza te same sprawdzenia, więc po usunięciu przyczyny bez nowego generowania może przejść) |
| Awaria Cloudflare albo sieci operatora w trakcie sondy | Odczyt nieudany, ponawiany do limitu | Przy zamykaniu: kod 1, dane bez zmian, sklep zamknięty, ponowny `generate`; przy otwieraniu: kod 1, reguła usunięta, ponowny `make open` |

Automatyczne aktualizacje serwera nie restartują go same (`unattended-upgrades` bez automatycznego restartu).

## Przebieg na serwerze

Kroki sesji prowadzącej po zmergowaniu epiku; każdy krok ze zgodą jest osobną zgodą użytkownika w rozmowie.

1. Wdrożenie `main` (polecenie i zatwierdzenie użytkownika); `status`.
2. Użytkownik podaje stan automatycznej kopii OVH; trafia do raportu jako fakt (U3).
3. `generate PROFILE=medium` (zgoda), po wdrożeniu i weryfikacji L9 z „Wymagane przed startem”. Eksport logu
   i metryk z Grafana Cloud, sprawdzenie kompletności logu, raport `medium` na serwerze. Przegląd sklepu i panelu.
4. Analiza przed `server`: czas `medium` na serwerze × 10 wobec limitu 24 h, rozmiary `medium` × 10 (produkty)
   i × 5 (zamówienia) wobec wolnego miejsca, szczyty pamięci Postgresa i Elasticsearcha wobec limitów, restarty
   kontenerów. To projekcja do decyzji użytkownika, nie weryfikacja.
5. PR z `demoData.profile: server`; jego wdrożenie jest pierwszym wdrożeniem po generowaniu `medium` (sprawdzenie
   z „Kolejne wdrożenia”).
6. `generate PROFILE=server` (zgoda). Eksport logu i metryk z Grafana Cloud, raport `server` na serwerze.
7. Następne zwykłe wdrożenie (np. PR raportu): sprawdzenie na danych `server`.

## Model zagrożeń

- **Aktywa.** Dane sklepu na serwerze przed przypadkowym zastąpieniem; dostępność i spójność publicznego demo; dane
  dostępu do puli zdjęć; dysk serwera.
- **Drogi w zakresie i sprawdzenia:**
  - przypadkowe albo powtórne generowanie: „Ochrona przed przypadkowym uruchomieniem”; testy: render (`suspend`),
    Job bez `DEMO_DATA_CONFIRM` (kod 64, dane bez zmian), `generate` bez `tak`, `rerun` Joba generatora;
  - sklep otwarty na niepełnych danych: reguła poza chartem, restart Traefika, sonda przed zastąpieniem danych,
    otwarcie tylko przez `open` po Jobie `Complete` i udanych sprawdzeniach, a Job poprzedniego przebiegu usuwany
    tuż przed nowym, `generate` i `open` pojedynczo pod blokadą; test dymny z ciągłym ruchem przez ingress w trakcie
    zamykania, testy nieudanego Joba, przerwanego generowania (`open` odmawia), nieudanego sprawdzenia (sklep
    zamknięty) i `generate` w trakcie `open` (odmawia przy blokadzie), brak śledzenia Argo CD na obiektach celu;
  - zapełnienie dysku: strażnik w podzie niezależny od komputera operatora, zatrzymanie całej grupy procesów,
    sesje bazy kończone przez serwer po zniknięciu klienta; testy skryptu z podstawionym `df` (próg, odczyt nieudany,
    odczyt nieliczbowy), z poleceniem generatora na pierwszym planie i z sygnałem TERM;
  - wyciek danych dostępu do puli zdjęć: tylko cztery zmienne odczytu, sekret na czas przebiegu, usuwany przy każdym
    wyjściu `generate` (także INT i TERM) i przez `open` po zabiciu; testy jak w `load` (brak wartości w wyjściu
    i linii poleceń);
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
   i opcjonalny) i zawieszony CronJob `load` (obraz z digestem, bez sekretów, `automountServiceAccountToken: false`);
   CronJob `load` renderuje się we wszystkich profilach (`prod`, `ci`, `load`). Schemat odrzuca `prod` bez `anchor`,
   bez `photosSecretName`, z profilem innym niż `medium` i `server` albo z `minFreeGb` poniżej 10. Selftest łapie
   CronJob `demo-data` i `load` bez `suspend` oraz skrypt Joba bez sprawdzenia `DEMO_DATA_CONFIRM` i bez strażnika
   miejsca.
2. Skrypt `demo-data.sh` (testy z podstawionymi poleceniami): `DEMO_DATA_CONFIRM` puste albo inne niż profil i `seed`
   daje kod 64 i nic się nie uruchamia; `seed` uruchamia `bin/seed-demo`, profil `bin/generate-demo`; za mało miejsca
   (profil; `seed` nie ma progu) albo nieudany albo nieliczbowy odczyt przed startem (także `seed`) kod 74; spadek
   poniżej progu w trakcie (profil, nie `seed`), trzy nieudane odczyty z rzędu i odczyt nieliczbowy zatrzymują grupę
   procesów generatora razem z poleceniem działającym na pierwszym planie i niczego poza nią, kod 75; sygnał TERM robi
   to samo; kod generatora przechodzi na wyjście; linia powodu trafia do `/dev/termination-log` na każdej z tych
   ścieżek. Kontener CronJoba ma `PGOPTIONS` z `client_connection_check_interval`; na działającym stosie sesja procesu
   PHP z tą zmienną kończy się po zabiciu klienta sygnałem KILL w trakcie długiego zapytania (Z11).
3. `generate` odmawia bez zmian (testy skryptu na atrapach `kubectl`): brak albo zły `PROFILE`, profil inny niż
   w CronJobie, brak `tak`, aktywny Job (poza Jobami `rate-limit-check`), niegotowy Deployment albo StatefulSet, brak
   pliku puli zdjęć albo zmiennej odczytu (tylko gdy CronJob ma sekret puli i profil nie jest `seed`), za mało
   wolnego miejsca (próg `minFreeGb` dla każdego profilu poza `seed`, próg `diskPeakGb` tylko dla `medium` i `server`;
   `seed` odmawia tylko przy nieczytelnym odczycie), nieczytelny odczyt miejsca, brak `KUBE_DIR`, blokada innego
   przebiegu (przed `tak` i przy zakładaniu po `tak`).
4. `generate` na ścieżce poprawnej (profil i `seed`): po `tak` zakłada regułę, usuwa pody Traefika, czeka na
   „zamknięty” od każdego adresu sondy, tworzy sekret (gdy wymagany), usuwa poprzedni Job `demo-data-run` i tworzy Job
   z `DEMO_DATA_CONFIRM`, czeka na niego, woła `open` i usuwa sekret przy wyjściu; kod 0. Sonda bez „zamknięty”
   (adres z kodem poniżej 500 albo nieczytelny), Job nieudany (także bez poda) albo po czasie, kod 75 Joba: kod 1,
   reguła zostaje, sekret usunięty, komunikat z powodem i dwiema drogami wyjścia; przy sondzie zamknięcia Job nie
   powstaje, a Job poprzedniego przebiegu zostaje. Sekret jest usunięty także po HUP, INT i TERM; Job z poprzedniego
   przebiegu nigdy nie przechodzi do `open` jako sukces tego przebiegu (po jego usunięciu `open` odmawia do sukcesu
   nowego Joba).
5. `open` odmawia bez zmian przy aktywnym Jobie, bez Joba `demo-data-run` i z Jobem innym niż `Complete`; usuwa
   pozostawiony sekret puli zdjęć; po nieudanym sprawdzeniu (nieważny indeks, alias na zero albo wiele indeksów, stan
   `red`, indeks bez aliasu, nieudany Job `load`) kończy się kodem 1 z regułą na miejscu; po udanych sprawdzeniach
   czyści pulę `cache.sitemap` w każdym podzie `api` przed Jobem `load`, usuwa regułę i kończy kodem 0 po „otwarty”
   od każdego adresu sondy; sonda bez „otwarty” to kod 1, a ponowne `open` kończy otwarcie. `open` odmawia bez zmian
   także przy blokadzie innego przebiegu i, wołany z `DEMO_DATA_LOCK_TOKEN`, bez blokady z tym tokenem.
   Blokada: `generate` zakłada ją po `tak` przed pierwszą zmianą i trzyma razem z `open`, który woła, a `open` ręczny
   na starcie; oba zwalniają ją przy każdym przechwyconym wyjściu (sukces, błąd, odmowa, HUP, INT, TERM) i nigdy nie
   usuwają blokady innego przebiegu; po KILL zostaje, a oba cele odmawiają do jej ręcznego usunięcia. `generate`
   uruchomiony w trakcie `open` odmawia przy blokadzie, więc `open` nie usuwa reguły na Jobie innym niż sprawdzony.
6. Wartości `DEMO_PHOTOS_*` nie pojawiają się w wyjściu (także z `sh -x`) ani linii poleceń; token zapisu nie trafia
   do klastra (testy jak dla `load`). Reguła `shop-closed`, blokada `demo-data-lock`, Job `demo-data-run` i sekret nie
   mają etykiet ani adnotacji śledzenia Argo CD.
7. Test dymny k3d (`make -C infra/k8s smoke`, także w `deploy.yml`, bez pliku puli zdjęć) w ostatniej fazie uruchamia
   `generate PROFILE=small` i `open` zamiast samego Joba, z ruchem przez ingress bez przerwy: żądania przestają dostawać
   200 po zamknięciu (Z2), a po przebiegu sklep odpowiada. `SMOKE_BREAK=demo-data` (nieudany generator): `generate`
   kończy się kodem 1, sklep jest zamknięty, `open` odmawia, a `generate PROFILE=seed` kończy się kodem 0 i otwiera
   sklep. Po każdym z tych przebiegów nie ma blokady `demo-data-lock`. Raport zawiera wynik obu ścieżek.
8. `rerun` odmawia Joba generatora (kod 3, komunikat wskazuje `make generate`); `reinitialize` odmawia przy regule
   `shop-closed`; oba bez zmian w klastrze (testy).
9. `status` pokazuje, czy sklep jest zamknięty, blokadę `demo-data-lock` i jej posiadacza, Job `demo-data-run` i jego
   stan, aktywne Joby, pozostawiony sekret puli zdjęć i linię `initialize`.
10. `load` z `LOAD_DATA` działa jak przed wydzieleniem wspólnego pliku (istniejące testy `test_load_script.py`
    przechodzą bez zmiany asercji poza utworzeniem Joba z `DEMO_DATA_CONFIRM`).
11. `make -C infra/k8s check` przechodzi; nowe przypadki selftestu kończą `check` błędem (wynik obu uruchomień
    w raporcie zadania).
12. Dokumentacja z sekcji „Dokumentacja” opisuje stan po epiku, bez historii zmian; obie wersje `CLAUDE.md` mówią, że
    `generate` i `open` wymagają zgody użytkownika w rozmowie, która nazywa operację, host i profil, a `rerun` nie
    ponawia Joba generatora; runbook podaje pełne polecenia celów `generate` i `open` i sprawdzenie wdrożenia po
    generowaniu. Żaden tekst nie przedstawia pełnej skali (10 mln produktów, 50 mln zamówień) jako zweryfikowanej ani
    automatycznej kopii OVH jako aktywnej, a liczby `server` i `full` są opisane jako ekstrapolacja.

Kroki z „Przebieg na serwerze” nie są kryteriami epiku: wykonuje je sesja po merge'u, za zgodą.

## Dokumentacja

Utrzymywana razem z kodem. Dokumenty, które opisują tę funkcję albo jej zasady:

- `docs/features/demo-data/spec.md`: P2 i W3 według U1, „Po epiku”, „Poza zakresem”, „Model zagrożeń” (CronJob
  także w `prod`), opis CronJoba (skrypt, `DEMO_DATA_CONFIRM`, strażnik miejsca).
- `docs/vision.md`: „Skala docelowa” (dane w skali na serwerze demo, pomiar `full` odłożony, brak weryfikacji pełnej
  skali) i „Dane demo”.
- `infra/k8s/README.md`: sekcja 11 (CronJoby, `generate` i `open` na serwerze: przygotowanie, polecenia, błędy
  i kody wyjścia, powrót do seeda i `medium`, scenariusze awarii, wdrożenie po generowaniu, logi w Grafana Cloud,
  adresy sondy przez Cloudflare).
- `infra/k8s/CLAUDE.md` i główny `CLAUDE.md`: `generate` i `open` na liście celów wymagających zgody; `rerun` nie
  ponawia Joba generatora.
- `docs/features/deploy/spec.md` i `docs/features/deploy-ops/spec.md`: CronJoby `demo-data` i `load` w chartcie,
  zamknięcie sklepu a alerty, test dymny.
- `docs/adr/backups-observability.md`: klaster ma jedną, tymczasową NetworkPolicy (zamknięcie sklepu).
- Komentarze celów w `infra/k8s/Makefile` i nagłówki skryptów (`generate.sh`, `open.sh`, `demo-data.sh`, `rerun.sh`).

## Ryzyka

- Czas `server` na tym serwerze jest nieznany. Postgres ma limit 2 GiB i ustawienia domyślne, a indeksy na losowych
  UUID przy 10 mln produktów nie mieszczą się w pamięci, więc czas może rosnąć szybciej niż liniowo. Odpowiedź:
  przebieg `medium` na serwerze, projekcja przed `server`, limit 24 h, sklep zamknięty do skutku.
- Zachowanie sklepu przy 10 mln produktów jest niezmierzone (H1–H4 w specyfikacji DEMO-DATA). Sprawdzenie po
  generowaniu potwierdza poprawność odpowiedzi, nie ich szybkość; `smoke.js` ma limit czasu scenariusza 30 s.
  Zbyt wolny sklep kończy się nieudanym sprawdzeniem i zamkniętym sklepem, a decyzję (powrót do `medium`, poprawki)
  podejmuje użytkownik.
- Usunięcie podów Traefika zrywa na kilkanaście sekund cały ruch przychodzący klastra (na serwerze tylko sklep).
- Przerwany generator zostawia dane niepełne do ponowienia albo powrotu do seeda; nic ich nie przywraca samo, a każdy
  błąd po zamknięciu zostawia sklep zamknięty do ręcznego `generate` (U8: dłuższa niedostępność jest przyjęta).
- Po zamknięciu niepotwierdzonym przez sondę (np. wyzwanie Cloudflare) sklep zostaje zamknięty z danymi bez zmian;
  `make open` otwiera go na Jobie poprzedniego przebiegu, jeśli ten jest `Complete`, a ponowny `generate` wymaga
  poprawy adresów sondy.
- Sonda z komputera operatora przechodzi przez Cloudflare; jej kody spoza 502–504 i poniżej 500 są odczytem
  nieudanym, więc awaria Cloudflare zatrzymuje cel, a nie otwiera ani nie zamyka sklepu. Wyzwanie Cloudflare (403)
  jest dla niej kodem poniżej 500, czyli „otwarte”: skutki w „Cel `make -C infra/k8s generate`”; ochroną jest
  sprawdzenie adresów przed uruchomieniem (runbook).
- Blokadę pozostawioną po zabitym `generate` albo `open` usuwa ręcznie użytkownik; jej usunięcie w trakcie działającego
  przebiegu znosi ochronę przed drugim `generate` albo `open`, więc przed usunięciem sprawdza, że żaden nie działa.
- Konta generatora zostają w Keycloaku po powrocie do seeda; przejście z `server` do `medium` usuwa je pojedynczo.
- Retencja Grafana Cloud (14 dni) ogranicza czas na eksport pełnego logu i odczyt szczytów do raportu; po restarcie
  serwera w trakcie przebiegu to jedyna kopia logu. Kompletność logu `medium` nie dowodzi kompletności przy wielkości
  logu `server`.
- Brak przecieku ruchu po restarcie węzła (Z10) potwierdziła jedna próba na k3d; na serwerze nie był sprawdzany.
- Mapa strony podaje adres każdego produktu, a `robots.txt` pozwala na strony produktów, więc dane w skali sprowadzają
  ruch robotów po unikalnych stronach. Cache ISR sklepu zapisywany na dysku poda (ok. 262 KB na stronę produktu, limit
  `ephemeral-storage` 2 GiB) eksmitowałby jedyny pod sklepu co ok. 8 tys. stron; cache ISR tylko w pamięci, z limitem
  (`docs/features/product-page/spec.md`, „Cache ISR”, poprawka L9 z PR #800), nie zapisuje na dysk, a pamięć sklepu pod takim
  ruchem stabilizuje się poniżej limitu kontenera. Dlatego dane w skali trafiają na serwer dopiero po wdrożeniu tej
  poprawki i jej weryfikacji (warunek w „Wymagane przed startem”). Poza limitem cache zostaje lista rewalidowanych
  tagów (ok. 180 B na tag, do restartu poda); sklep rewaliduje kubełki zamiast tagów z identyfikatorem i slugiem
  produktu, więc lista ma najwyżej 65 537 wpisów przy każdej liczbie produktów (`docs/features/isr-tag-limit/spec.md`,
  product-page spec, „Cache ISR”). `server` czeka na weryfikację tej zmiany na serwerze (warunek w „Wymagane przed
  startem”).
- `count(*)` w `initialize` przy 10 mln produktów: obserwowane w krokach na serwerze, poprawka poza tym etapem.

## Wymagane przed startem

- PR #756 zmergowany do `main`.
- Plik `~/.config/shop/demo-photos.env` z czterema zmiennymi odczytu na komputerze, z którego idzie `generate`
  (istnieje; wartości nie przechodzą przez rozmowę).
- Przed krokami na serwerze: wdrożenie `main` z tym epikiem i zgody użytkownika z „Przebieg na serwerze”.
- Przed `generate` z profilem `medium` albo `server` na serwerze: wdrożona rewizja zawiera poprawkę L9 (PR #800: cache
  ISR tylko w pamięci, `docs/features/product-page/spec.md`, „Cache ISR”) i jej weryfikacja na serwerze przeszła. Warunek
  nie wymaga zakończenia ASH-V1 ani ABUSE-LIMITS, a zgoda na `generate` go nie zastępuje. Weryfikacja po wdrożeniu,
  wynik w komentarzu w #181:
  - odczyt bez zgody: `make -C infra/k8s status` pokazuje wdrożoną rewizję z poprawką i pod sklepu bez restartów;
  - próba po zgodzie użytkownika, jak inne sprawdzenia po wdrożeniu: 3000 żądań przez `https://<domena>/p/<slug>`
    z unikalnymi, nieistniejącymi slugami w formacie sluga produktu, najwyżej 10 na sekundę (więcej wpisów 404, niż
    mieści limit cache);
  - odczyt w Grafana Cloud (tylko do odczytu) w czasie próby i godzinę po niej: brak plików cache na dysku poda, czyli
    wolne miejsce węzła (`node_filesystem_avail_bytes`) spada o mniej niż 200 MB (bez poprawki próba zapisałaby ok.
    400 MB wpisów 404); pamięć kontenera `store` (`container_memory_working_set_bytes`) przestaje rosnąć i zostaje
    poniżej 1 GiB przy limicie 1536 MiB; liczba restartów poda sklepu (`kube_pod_container_status_restarts_total`)
    się nie zmienia.
- Przed `generate` z profilem `server` na serwerze dodatkowo: wdrożone ograniczenie listy rewalidowanych tagów cache ISR
  sklepu (`docs/features/isr-tag-limit/spec.md`) i jego weryfikacja na serwerze według tej specyfikacji („Weryfikacja na
  serwerze (D4)”, wynik w komentarzu w #781).
