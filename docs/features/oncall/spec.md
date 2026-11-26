# ONCALL: hypercare po wdrożeniu

Issues: #669
Zakres: .claude/skills/oncall, infra/k8s/tests/test_oncall_cluster.py, CLAUDE.md, docs/features/agentic-software-house/plan.md

## Cel

Pierwsza część kroku 11 planu Agentic Software House (`docs/features/agentic-software-house/plan.md`). Po każdym
wdrożeniu worf.pl skrypt deterministyczny porównuje stan nowej wersji z poprzednią i użytkownik dostaje push
z werdyktem `OK`, `NIEROZSTRZYGNIĘTE` albo `ROLLBACK ZALECANY`. Rollback i wszystkie operacje produkcyjne wykonuje
wyłącznie użytkownik. Druga część kroku, czyli agent śledczy przez MCP, propozycje issues i triage, to osobny epik
ONCALL-INVESTIGATE (#708). Ten epik od niego nie zależy i nie zawiera żadnej jego funkcji.

Kryteria sukcesu:
- każde wdrożenie (pojawienie się nowego tagu obrazu w podzie `api`) dostaje co najmniej jeden push z werdyktem,
  a każdy z punktów +1 h, +4 h i +24 h jest policzony albo zapisany w raporcie jako pominięty;
- `ROLLBACK ZALECANY` wynika wyłącznie z metryk klastra o nowej wersji, których nie da się podrobić jawnym DSN-em
  Sentry;
- brak danych, błąd źródła i zawieszone wdrożenie dają `NIEROZSTRZYGNIĘTE`, nigdy `OK` ani rekomendację rollbacku;
- werdykt liczy skrypt bez LLM i MCP. Sesja `/oncall` tylko uruchamia skrypt i przekazuje gotowy tekst pushu z liczb
  i identyfikatorów;
- liczba przebiegów i zużycie limitu sesji są mierzone od pierwszego przebiegu.

Wejście: research „Agent dyżurny SRE z MCP” (2026-11-17). Przy ruchu dema porównanie stóp błędów zwykle nie ma
wystarczającej próby. Sygnały Sentry może podrobić każdy, kto zna DSN, a wszystkie trzy DSN-y są jawne w
`infra/k8s/environments/prod/values.yaml`. Dlatego Sentry daje tylko liczby informacyjne i najwyżej
`NIEROZSTRZYGNIĘTE`.

## Przegląd rozwiązania

- **`oncall.py`** (`.claude/skills/oncall/oncall.py`, Python ze standardową biblioteką) to cała logika wejścia
  i wyjścia.
  - Czyta metryki z endpointu zapytań Prometheus stosu Grafana Cloud i Sentry REST API tokenami tylko do odczytu.
  - Prowadzi stan hypercare, zapisuje raport i zwraca tekst pushu.
  - Nie wywołuje LLM, MCP, `gh`, `kubectl` ani SSH.
- **`verdict.py`** (`.claude/skills/oncall/verdict.py`) zawiera czyste funkcje bez wejścia i wyjścia: werdykt, tekst
  pushu i sekcję raportu ze słownika sygnałów. Kontrakt słownika opisuje plan.
- **`cluster.json`** (`.claude/skills/oncall/cluster.json`) to jedyne miejsce zamkniętej listy workloadów wdrażanych
  i progów sygnałów klastra. Czytają go `oncall.py` i test chartu w `infra/k8s/tests/test_oncall_cluster.py`.
- **Skill `/oncall`** (`.claude/skills/oncall/SKILL.md`) to sesja aplikacji desktop na Haiku, otwierana przez zadanie
  zaplanowane albo ręcznie.
  - Wywołuje `oncall.py run` i `finish`, a pole `push` z wyniku wysyła dosłownie przez `PushNotification`.
  - Nie czyta raportu, stanu, odpowiedzi API ani treści zdarzeń Sentry.

Stan jest poza repo, w `~/.local/state/shop-oncall/`:
- `state.json`, zapisywany atomowo (plik tymczasowy i `rename`);
- `runs.jsonl`;
- `lock`;
- `history/hypercare-<sha7>-<T0>.md`.

Konfiguracja jest w `~/.config/shop/oncall/` (katalog `0700`, pliki `0600`):
- `prometheus.url`: adres `https` endpointu zapytań stosu, na przykład
  `https://prometheus-prod-72-prod-eu-west-4.grafana.net/api/prom`;
- `prometheus.user`: identyfikator instancji metryk;
- `prometheus.token`: token access policy z samym `metrics:read`;
- `sentry.token`.

Brak pliku, plik z innymi prawami albo adres inny niż `https` na hoście `*.grafana.net` to błąd źródła, a nie
wyjątek.

## Przebieg

`oncall.py run`:

1. **Blokada.** Skrypt bierze `fcntl.flock(LOCK_EX | LOCK_NB)` na pliku `lock`. Kernel zwalnia blokadę przy końcu
   procesu, także po awarii. Zajęta blokada kończy przebieg wynikiem `{"push": null, "kind": "pusty", "note":
   "przebieg trwa"}`.
2. **Stan.** Brak `state.json` albo plik nieczytelny lub niezgodny ze schematem to pierwszy przebieg: skrypt zapisuje
   w raporcie, że stan zainicjowano od nowa.
3. **Wersja i odcinki tagu.** Zapytanie zakresowe (ostatnie 26 h, krok 1 min) daje historię tagów obrazu kontenera
   `api` we wszystkich podach należących do ReplicaSetu Deploymentu `api`, gotowych i niegotowych:
   `kube_pod_container_info` złączone z `kube_pod_owner`. Pody Jobów z obrazem `api` się nie liczą.
   - Tag pochodzi z etykiety `image_spec` (`<rejestr>/api:<sha>@sha256:<digest>`, wyrażenie
     `.*:([0-9a-f]{40})(@.*)?`), a tag to SHA commita `main`. Etykieta `image` zawiera sam digest obrazu i nie niesie
     tagu. Release z Sentry, który może utworzyć każdy, kto zna DSN, nie ma tu znaczenia.
   - **Nowy tag** to tag najnowszej próbki różny od `state.json.current`. Hypercare otwiera samo pojawienie się
     nowego tagu w dowolnym podzie `api`, także niegotowym, więc wdrożenie, którego pod nigdy nie staje się gotowy,
     też dostaje hypercare.
   - **`T0`** to początek ostatniego ciągłego odcinka, w którym nowy tag występuje, czyli pierwszy pomiar z nim po
     ostatniej chwili bez niego. Przy sekwencji A→B→A `T0` przywróconej wersji A to chwila rollbacku.
     - Wersja poprzednia to `state.json.current`. Bez stanu jest to tag ostatniej próbki przed `T0`.
     - Tagi, których odcinki zaczęły się i skończyły między przebiegami, trafiają do raportu jako „wersja pośrednia
       bez hypercare”.
   - **Brak przejścia** w historii: pierwszy przebieg, w którym tag trwa od początku zakresu, albo pierwsze próbki
     metryki po jej wdrożeniu. Skrypt zapisuje `current` bez hypercare i wysyła jeden push „Dyżur aktywny: wersja
     <sha7>”.
   - `T0` starszy niż 24 h zamyka hypercare od razu pushem „Hypercare <sha7>: pominięty, wdrożenie sprzed ponad 24 h”.
4. **Punkty.** Punkty kontrolne to `T0` + 1 h, + 4 h i + 24 h. Przebieg liczy tylko najpóźniejszy zaległy punkt,
   a wcześniejsze niepoliczone zapisuje w raporcie jako „pominięty (brak przebiegu)”.
   - Okna mają stałe granice: `[T0, T0 + h]` dla nowej wersji i `[T0 − h, T0)` jako okno odniesienia. Okno odniesienia
     jest oknem czasu, a nie wersji, i może obejmować starsze wersje; raport podaje wtedy ich tagi.
   - Nowe wdrożenie w trakcie zamyka poprzedni hypercare werdyktem ostatniego policzonego punktu.
   - Po punkcie +24 h hypercare jest zamknięty.
5. **Wynik.** Jedna linia JSON:
   - `push`: tekst albo `null`;
   - `kind`: `pusty`, `hypercare` albo `błąd`;
   - `sources`: stan każdego źródła;
   - `report`: ścieżka.

   Gdy w jednym przebiegu przypada punkt i zmienia się stan źródła, push to tekst punktu. Komunikat źródła dochodzi
   po „ | ” tylko wtedy, gdy mieści się w 200 znakach; w przeciwnym razie zostaje w raporcie.
6. **Błędy źródeł.** Powodem błędu jest jedno z zamkniętej listy:
   - `token`: token odrzucony;
   - `network`: sieć;
   - `config`: konfiguracja;
   - `rate_limit`: limit API;
   - `page_limit`: odpowiedź z liczbą wyników równą limitowi strony;
   - `http`: inny kod, także przekierowanie;
   - `no_metric`: brak metryki `kube_pod_container_info` lub `kube_pod_owner`, na przykład przed wdrożeniem nowych
     metryk.

   Push „Dyżur: błąd <źródło>: <powód>” wychodzi raz przy przejściu źródła ze stanu „działa” w „błąd”,
   a „Dyżur: <źródło> znowu działa” raz przy powrocie. Błąd w punkcie kontrolnym daje `NIEROZSTRZYGNIĘTE`.

`oncall.py finish --usage-before <json> --usage-after <json>` dopisuje do `runs.jsonl`:
- czas;
- rodzaj przebiegu;
- przyrost okien limitu z dwóch wywołań `get_usage` sesji, a bez nich lukę.

`oncall.py stats` sumuje za 7 i 30 dni przebiegi według rodzaju, przyrost okien limitu i werdykty.

Wyzwalacz to zadanie zaplanowane aplikacji Claude: `/oncall` co godzinę w godzinach 7–23 (decyzja D3). Wariant
zapasowy, gdy aplikacja nie pozwala ograniczyć godzin, to co godzinę całą dobę. Zadanie zakłada użytkownik po
merge'u. Przebieg nie zależy od AO ani od bramki limitu: jest krótki i działa także w trakcie epiku. Gdy wyczerpany
limit uniemożliwia sesję, zaległy punkt liczy następny przebieg.

## Hypercare

**Workloady wdrażane** to zamknięta lista z `cluster.json`: Deploymenty `api`, `worker`, `worker-commands`, `store` i `admin` oraz
StatefulSet `elasticsearch`. Wszystkie dostają przy wdrożeniu obraz z nowym tagiem.
- Przynależność poda ustala `kube_pod_owner` (ReplicaSet `<deployment>-<hash>` albo StatefulSet).
- Pody Jobów i usług bez naszego kodu się nie liczą.
- `infra/k8s/tests/test_oncall_cluster.py` porównuje listę z workloadami, które chart renderuje z `shop.appImage`.
  Test działa w `make -C infra/k8s check`, więc nowy workload w PR samego chartu też go uruchamia.

**Sygnały klastra** to zapytania PromQL do metryk kube-state-metrics, których nie da się podrobić DSN-em. Skrypt nie
korzysta z historii alertów: od #692 reguły są zarządzane przez Grafanę i nie zapisują serii `ALERTS`.
- **K1 workload niegotowy po `T0`:** warunek reguły `WorkloadNotReady` z `00-alerts.yaml` dla workloadu wdrażanego,
  spełniony nieprzerwanie co najmniej 10 min w odcinku, który zaczął się po `T0` w oknie nowej wersji.
  - Dla Deploymentu warunek to `kube_deployment_status_replicas_available < kube_deployment_spec_replicas`, a dla
    StatefulSetu `kube_statefulset_status_replicas_ready < kube_statefulset_replicas`.
  - Próg 10 min odpowiada `for: 10m` reguły, więc zwykła wymiana poda `elasticsearch` nie daje K1.
  - Test chartu pilnuje, że metryki i `for` reguły są takie jak w `cluster.json`.
- **K2 restarty nowej wersji:** przyrost `kube_pod_container_status_restarts_total` w oknie, liczony osobno dla
  każdego workloadu wdrażanego, tylko w kontenerach z obrazem nowego tagu.
- **K3 wdrożenie zawieszone:** w chwili punktu workload wdrażany nie ma gotowego poda z nowym tagiem
  (`kube_pod_status_ready`) albo nadal ma pod ze starym tagiem. Przy `maxUnavailable: 0` nowy pod, który nie staje się
  gotowy, zostawia w ruchu stary, więc K1 się nie pojawi.
- **K4 cisza klastra:** brak `kube_node_info` przez co najmniej 15 min w oknie, jak reguła `ClusterSilent`.
- **K5 awaria sprzed wdrożenia:** odcinek K1 workloadu wdrażanego trwający od chwili sprzed `T0`.
- **K6 brak danych:** brak próbek `kube_pod_container_info` z nowym tagiem w oknie nowej wersji.

**Liczby Sentry** są informacyjne, bo podrobi je każdy, kto zna DSN. Liczone są w obu oknach dla projektów `api`,
`store` i `admin` w środowisku `demo`:
- nowe issues (`firstRelease` równy wersji, poziom `error` albo `fatal`) i liczba ich zdarzeń;
- regresje (`regressed` w oknie);
- liczba zdarzeń błędów;
- zdarzenia odrzucone (`stats_v2`, kategoria `error`, wynik `rate_limited` albo `filtered`).

Stopy błędów skrypt nie liczy (decyzja D4).

**Werdykt** (pierwsza pasująca reguła, decyzja D4):
1. `ROLLBACK ZALECANY`: K1 albo co najmniej 3 restarty K2 w jednym workloadzie.
2. `NIEROZSTRZYGNIĘTE`: co najmniej jedno z poniższych:
   - K3, K4, K5 albo K6;
   - błąd odczytu metryk albo Sentry;
   - zdarzenia odrzucone przez Sentry w oknie nowej wersji, bo dane są niepełne;
   - nowe issue nowej wersji z co najmniej 20 zdarzeniami.

   Rollback nie jest wtedy rekomendowany.
3. `OK`: żadna z powyższych.

**Push** po każdym policzonym punkcie, do 200 znaków. Zawiera tylko werdykt, liczby, nazwy workloadów z listy
i krótkie ID issues Sentry. Nie zawiera tytułów ani treści zdarzeń:

`Hypercare <sha7> +<h> h: <WERDYKT>. <powód>. Sentry: nowe <n>/<zd> zd., regr. <r>, błędy <a>→<b>.`

- Powód to pierwsza spełniona przesłanka, na przykład „api niegotowy”, „restarty worker 4”, „wdrożenie zawieszone:
  store”, „cisza klastra”, „brak danych”, „błąd: sentry”, „STORE-1A 31 zd.”, a przy `OK` pusty.
- Przy `ROLLBACK ZALECANY` push ma dopisek „Rollback: infra/k8s/README.md, sekcja 7”.
- Przy przekroczeniu 200 znaków najpierw znikają liczby Sentry, potem skraca się powód. Werdykt i SHA zostają zawsze.
- Brak wersji poprzedniej daje w liczbach okna odniesienia „?”.

**Raport** `history/hypercare-<sha7>-<T0>.md` jest dokumentem dla użytkownika i sesja go nie czyta. Zawiera:
- tabelę sygnałów i liczb obu okien dla każdego punktu;
- użyte zapytania;
- adresy issues Sentry zbudowane przez skrypt z identyfikatorów;
- wersje pośrednie, tagi w oknie odniesienia i punkty pominięte.

## Model zagrożeń

**Aktywa:**
- A1: decyzja o rollbacku produkcji;
- A2: tokeny dyżuru (metryki i Sentry, tylko odczyt);
- A3: sesja `/oncall`, która ma uprawnienia sesji aplikacji;
- A4: limit subskrypcji Claude.

**Strony:**
- S1: każdy, kto zna DSN albo wysyła żądania do sklepu;
- S2: pomyłka skryptu albo awaria źródła.

| # | Droga | Zabezpieczenie | Sprawdzenie |
|---|---|---|---|
| O1 | Fałszywe zdarzenia Sentry wymuszają `ROLLBACK ZALECANY` albo otwierają hypercare | wersja i rollback tylko z metryk klastra; release Sentry nie otwiera hypercare | test werdyktu na samych polach Sentry; test release'u bez zmiany tagu |
| O2 | Treść od S1 (tytuł, komunikat, `release`, `environment`, tagi) trafia do sesji i działa na nią jak polecenie | tytuły i treści nie są pobierane; wynik `run` tylko z wartości zamkniętych list, liczb, SHA (`^[0-9a-f]{40}$`) i ID `^[A-Z0-9-]{1,32}$`, inaczej „?” | test atrapy z instrukcją ataku w każdym polu Sentry |
| O3 | Token trafia na inny host: przekierowanie, adres paginacji z nagłówka `Link`, zły adres w konfiguracji | brak podążania za przekierowaniem (3xx to błąd `http`); następna strona tylko z kursora, z adresem budowanym przez skrypt na tym samym hoście; `prometheus.url` tylko `https` w `*.grafana.net`, Sentry ze stałym hostem regionu | testy: atrapa zwraca 302 na inny host i `Link` z obcym hostem, token tam nie trafia; zły adres → błąd `config` |
| O4 | Token w wyniku, raporcie albo logu | token tylko w nagłówku `Authorization` | test skanuje wynik, raport, `state.json` i `runs.jsonl` |
| O5 | Zapis w Sentry albo w stosie | tokeny bez zapisu (`metrics:read`; Sentry `org:read`, `project:read`, `event:read`); skrypt wysyła tylko `GET` | atrapa odrzuca każdą inną metodę i ścieżkę; eksperyment: zapis tokenem → odmowa |
| O6 | Zalew zdarzeń wyczerpuje limit Sentry i ukrywa prawdziwe błędy | odrzucone zdarzenia dają `NIEROZSTRZYGNIĘTE` | test werdyktu |
| O7 | Fałszywe `OK` przy braku danych | K3, K4, K6, błąd źródła i brak przejścia nigdy nie dają `OK` z hypercare | testy werdyktu i przebiegu |

**Poza zakresem (świadomie):**
- `oncall.py` jest uruchamiany z głównego klonu. Zmieniony lokalnie skrypt działa z
  uprawnieniami użytkownika i jego tokenami tylko do odczytu; to ta sama granica co przy innych skillach.
- Python, aplikacja Claude i endpointy Grafana Cloud i Sentry są zaufane.
- Ograniczenie wysyłki przez jawne DSN-y to ustawienia konta Sentry (#557).

## Kryteria akceptacji

1. Wersja i `T0`, sprawdzane na atrapie HTTP:
   - nowy tag otwiera hypercare z `T0` równym początkowi ostatniego odcinka;
   - pod `api` z nowym tagiem, który nigdy nie jest gotowy, otwiera hypercare;
   - pod Joba z obrazem `api` nie zmienia wersji;
   - sekwencja A→B→A w ciągu 26 h daje `T0` w chwili powrotu do A;
   - dwa wdrożenia między przebiegami dają hypercare ostatniego tagu i wersję pośrednią w raporcie;
   - `T0` starszy niż 24 h zamyka hypercare pushem „pominięty”;
   - brak przejścia w historii, bez stanu albo przy pierwszych próbkach metryki, daje „Dyżur aktywny” bez hypercare;
   - uszkodzony `state.json` daje ponowną inicjalizację;
   - release Sentry bez zmiany tagu nie otwiera hypercare.
2. Punkty:
   - liczony jest tylko najpóźniejszy zaległy punkt, a wcześniejsze są w raporcie jako pominięte;
   - okna mają stałe granice;
   - nowe wdrożenie w trakcie zamyka poprzedni hypercare;
   - po +24 h hypercare jest zamknięty;
   - punkt i zmiana stanu źródła w jednym przebiegu dają jeden push z pierwszeństwem punktu.
3. Werdykt, sprawdzany na słownikach sygnałów i na przebiegu z atrapą:

   | Sytuacja | Werdykt |
   |---|---|
   | brak sygnałów | `OK` |
   | `api` niegotowy przez 10 min od chwili po `T0` | `ROLLBACK ZALECANY` |
   | `elasticsearch` niegotowy przez 10 min od chwili po `T0` | `ROLLBACK ZALECANY` |
   | `elasticsearch` niegotowy przez 9 min | `OK` |
   | 3 restarty nowej wersji `worker` | `ROLLBACK ZALECANY` |
   | 2 restarty w `api` i 2 w `worker` | `OK` |
   | restarty kontenerów poprzedniej wersji | brak rollbacku |
   | `postgres` niegotowy | brak rollbacku |
   | niegotowość workloadu wdrażanego trwająca od chwili sprzed `T0` (K5) | `NIEROZSTRZYGNIĘTE` |
   | wdrożenie zawieszone: nowy pod niegotowy, stary w ruchu | `NIEROZSTRZYGNIĘTE` |
   | cisza klastra (K4) | `NIEROZSTRZYGNIĘTE` |
   | brak próbek nowego tagu | `NIEROZSTRZYGNIĘTE` |
   | błąd metryk albo Sentry | `NIEROZSTRZYGNIĘTE` |
   | odrzucone zdarzenia Sentry | `NIEROZSTRZYGNIĘTE` |
   | nowe issue z 20 zdarzeniami | `NIEROZSTRZYGNIĘTE` |
   | nowe issue z 19 zdarzeniami | `OK` |
   | zalew fałszywych zdarzeń z nowym release'em bez sygnałów klastra | najwyżej `NIEROZSTRZYGNIĘTE` |
   | nowe issue przy K1 | `ROLLBACK ZALECANY` (pierwsza reguła wygrywa) |

4. Push:
   - ma najwyżej 200 znaków przy najdłuższych danych, a werdykt i SHA zostają zawsze;
   - przy `ROLLBACK ZALECANY` ma dopisek o runbooku;
   - wartości spoza wzorców i list stają się „?”;
   - żadna treść z atrapy Sentry (z instrukcją ataku) nie trafia do wyniku `run`.
5. Źródła i model zagrożeń:
   - każdy powód z zamkniętej listy daje błąd źródła, kod 0 i poprawną linię JSON, a nie wyjątek;
   - plik konfiguracji z prawami innymi niż `0600` i adres spoza `https://*.grafana.net` dają błąd `config`;
   - push błędu wychodzi tylko przy zmianie stanu źródła i przy powrocie;
   - pusty przebieg zwraca `push: null`;
   - testy O2–O5: przekierowanie, obcy host w `Link`, skan tokenu, tylko `GET` na dozwolonych ścieżkach.
6. Blokada i pomiar:
   - druga instancja `run` w trakcie pierwszej kończy się „przebieg trwa”;
   - blokada jest wolna po wyjątku i po zabiciu procesu;
   - `finish` zapisuje rodzaj i przyrost okien albo lukę;
   - `stats` sumuje 7 i 30 dni.
7. `infra/k8s/tests/test_oncall_cluster.py`:
   - lista workloadów w `cluster.json` jest równa liście workloadów chartu z `shop.appImage`;
   - metryki i `for` reguł `WorkloadNotReady` i `ClusterSilent` są zgodne z `cluster.json`;
   - test nie przechodzi na kopii szablonów z dodanym workloadem i ze zmienionym `for`;
   - `make -C infra/k8s check` przechodzi.
8. Testy `python3 -m unittest` w `.claude/skills/oncall` przechodzą w CI (wykrywa je `.github/scripts/script-tests.sh`).
   Przebieg na żywych danych worf.pl to odbiór użytkownika, a nie kryterium CI.

## Zmiany innych dokumentów (razem z implementacją)

- `CLAUDE.md`: jeden punkt: `/oncall` (zadanie zaplanowane albo ręcznie) liczy deterministycznie
  hypercare po wdrożeniu i wysyła push; tokeny tylko do odczytu; rollback wykonuje wyłącznie użytkownik.
- `docs/features/agentic-software-house/plan.md`, krok 11:
  - klaster czytany z metryk Grafana Cloud zamiast celów `make -C infra/k8s` (decyzja D2);
  - podział na ONCALL (hypercare) i ONCALL-INVESTIGATE #708 (agent przez MCP, propozycje i triage);
  - MCP sprawdzany w ONCALL-INVESTIGATE.

## Wymagane przed startem

- **PR metryk do `main`, poza epikiem.** Do `includeMetrics` kube-state-metrics w
  `infra/k8s/argocd/apps/monitoring.yaml` dochodzą `kube_pod_container_info`, `kube_pod_owner`,
  `kube_pod_status_ready` i `kube_pod_container_status_restarts_total`, z komentarzem o konsumencie. Test w
  `infra/k8s/tests/test_monitoring.py` wymaga każdej z nich, a `infra/k8s/CLAUDE.md` mówi, że od nich zależy
  hypercare.
  - PR przygotowuje sesja po akceptacji, na polecenie użytkownika.
  - Merge i wdrożenie (`deploy.yml`, zatwierdzenie w środowisku `production`) wykonuje **użytkownik**.
- Token access policy Grafana Cloud z samym `metrics:read` (stos `cosmiccinnamon1835`) w
  `~/.config/shop/oncall/prometheus.token`, identyfikator instancji metryk w `prometheus.user`, adres w
  `prometheus.url`: **użytkownik**.
- Token Sentry tylko do odczytu (Internal Integration organizacji `worf`: `org:read`, `project:read`, `event:read`)
  w `~/.config/shop/oncall/sentry.token`: **użytkownik**.
- **Eksperyment na rzeczywistych API** (decyzja D7), `istnieje: 2026-11-17`, Python `urllib` bez podążania za
  przekierowaniami, tokeny z `~/.config/shop/oncall/`:
  - metryki (`https://prometheus-prod-72-prod-eu-west-4.grafana.net/api/prom`, Basic z identyfikatorem instancji
    i tokenem `metrics:read`, `GET /api/v1/query` i `/api/v1/query_range`):
    - cztery metryki hypercare są zbierane już przez domyślną listę chartu `k8s-monitoring` (PR #711 je utrwala);
    - `image` to `sha256:<digest>`, a tag niesie `image_spec`; wszystkie pięć workloadów wdrażanych ma ten sam tag;
    - `query_range` z zakresem 26 h i krokiem 60 s zwraca rozłączne odcinki tagu `api` (5 wdrożeń w dobie);
    - `kube_pod_owner` ma `owner_kind` `ReplicaSet` z `owner_name` `api-<hash>` oraz `StatefulSet` `elasticsearch`,
      a złączenie `on(pod)` z `kube_pod_container_info` działa;
    - `count(ALERTS)` nie zwraca serii;
    - wyrażenia K1, K2 i K4 odpowiadają 200 bez serii przy zdrowym klastrze;
    - `POST /push` i `POST /rules/<przestrzeń>` kończą się 401 „invalid scope requested”;
  - Sentry (`https://de.sentry.io/api/0/organizations/worf/`, token Internal Integration):
    - projekty `api`, `store` i `admin`, jedno środowisko `demo`, release to SHA commita (ten sam co tag obrazu);
    - `issues/?project=-1&environment=demo&query=firstRelease:<sha>` i `query=is:regressed` zwracają `shortId`,
      `count`, `level` i `firstSeen`;
    - `events/?dataset=errors&field=count()&start=…&end=…` zwraca liczbę zdarzeń w oknie;
    - `stats_v2/?field=sum(quantity)&groupBy=outcome&category=error` zwraca liczby według `outcome`;
    - paginacja przez nagłówek `Link` z `cursor`. Odpowiedź `events/` na `de.sentry.io` ma w `Link` host
      `sentry.io`, więc skrypt buduje następną stronę sam z kursora (O3);
    - `POST releases/` i `PUT issues/` kończą się 403.

- **Eksperyment z zadaniem zaplanowanym**, `istnieje: 2026-11-17`, aplikacja Claude (zadanie testowe uruchomione
  ręcznie, potem usunięte):
  - harmonogram `0 7-23 * * *` jest przyjmowany jako „co godzinę 7:00–23:00”;
  - folder roboczy to główny klon, zadanie zapisane w trybie auto: `python3 -I -c …` przechodzi bez pytania o zgodę
    (Python 3.9.6);
  - `get_usage` działa; plan zwraca tylko okna tygodniowe, więc `finish` zapisuje przyrost okien tygodniowych;
  - sesja startuje na modelu domyślnym (Opus) i nie może sama zmienić modelu (`set_session_model` dla `self` jest
    odrzucane). Model Haiku wybiera użytkownik w edycji zadania w aplikacji;
  - `PushNotification` nie wysyła pushu, gdy aplikacja jest aktywna („Not sent”); wynik widać wtedy jako sesję
    przebiegu na pasku bocznym. Push na telefon wychodzi, gdy aplikacja nie jest aktywna.
- Zadanie zaplanowane z poleceniem `/oncall` w głównym klonie, według D3, z modelem Haiku wybranym w edycji zadania:
  **użytkownik**, po merge'u.

## Decyzje użytkownika

- D2: klaster z metryk Grafana Cloud, bez SSH i celów `make -C infra/k8s`, bo `status` wykonuje `kubectl` jako root
  na serwerze (`infra/k8s/scripts/remote.sh`, `remote_root`). Cztery metryki w `includeMetrics`; wdrożenie wykonuje
  użytkownik.
- D3: zadanie zaplanowane `/oncall` co godzinę w godzinach 7–23, sesja na Haiku; koszt pustych przebiegów mierzony.
- D4:
  - punkty +1 h, +4 h i +24 h;
  - rollback tylko z sygnałów klastra o nowej wersji (K1, K2);
  - zawieszone wdrożenie, brak danych, cisza klastra i sygnały Sentry dają najwyżej `NIEROZSTRZYGNIĘTE`;
  - stopa błędów Sentry nie jest liczona; liczby obu okien są informacyjne.
- D7: tokeny lokalne, tylko do odczytu, minimalne zakresy; eksperyment na rzeczywistych API przed implementacją,
  a nie przed akceptacją specyfikacji.
- Podział kroku 11: ONCALL bez LLM i MCP, niezależny od ONCALL-INVESTIGATE. Agent, serwery MCP (D1), dobowy limit
  śledztw (D5), odkładanie przy AO (D6), triage i etykieta `oncall` w bramce pochodzenia należą do
  ONCALL-INVESTIGATE.

## Poza zakresem

- Rollback, restart i każda operacja na serwerze, klastrze, Sentry i Grafanie.
- Agent LLM, serwery MCP, wykrywanie nowych błędów i alertów poza hypercare, propozycje issues i triage
  (ONCALL-INVESTIGATE).
- Stopa błędów, metryki żądań HTTP i historia stanów alertów Grafany.
- Zmiana alertów i ich punktów kontaktowych (#594), kroki po wdrożeniu SENTRY (#557), błąd #559.
