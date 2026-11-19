# DEMO-SCALE – plan zadań

Specyfikacja: `docs/features/demo-scale/spec.md`. Epik prowadzi główna sesja Claude Code z natywnymi subagentami. Epik ma
jedną gałąź (poza `epic/*`) z PR-em do `main` otwartym od początku jako szkic. Każde zadanie realizuje subagent we
własnym worktree na gałęzi zadania; sesja orkiestrująca merguje gotowe gałęzie zadań do gałęzi epiku i wypycha ją.
Zadania nie mają własnych PR. Zadania jednej fali z listą `parallel_with` mogą iść naraz. Plan jest dokumentem
roboczym: po ukończeniu epiku usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | DEMO-SCALE-1, DEMO-SCALE-2, DEMO-SCALE-3 | |
| 2 | DEMO-SCALE-4 | |
| 3 | DEMO-SCALE-5 | |
| 4 | DEMO-SCALE-6 | |
| 5 | DEMO-SCALE-7, DEMO-SCALE-8 | |

Kolumnę „Status” (zmergowane do gałęzi epiku, commit) uzupełnia sesja orkiestrująca po merge'u gałęzi zadania.

Warunek przed startem: PR #756 zmergowany do `main` (gałąź epiku powstaje od `origin/main` po tym merge'u).

Kroki na serwerze (wdrożenia, `generate PROFILE=medium`, analiza, `generate PROFILE=server`, sprawdzenie wdrożeń po
generowaniu, raporty) nie są zadaniami: wykonuje je sesja prowadząca po merge'u epiku, każdy po osobnej zgodzie
użytkownika (specyfikacja, „Przebieg na serwerze”).

Kontrakty między zadaniami są w specyfikacji: nazwy `shop-closed`, `demo-data-state`, `demo-photos`, zmienna
`DEMO_DATA_CONFIRM`, kody 64, 74 i 75, etykieta Joba generatora (`app.kubernetes.io/name: demo-data`), CronJob `load`.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "DEMO-SCALE",
  "spec": "docs/features/demo-scale/spec.md",
  "tasks": [
    {
      "id": "DEMO-SCALE-1",
      "title": "Wspólny plik funkcji generatora dla load i generate",
      "scope": "infra/k8s/scripts (spec: „Logi i metryki”, ostatni akapit). Funkcje z load.sh, których użyje też generate.sh, przechodzą do scripts/demo-data-lib.sh: odczyt pliku puli zdjęć, sekret demo-photos przez stdin, zapis logu Joba z maskowaniem, czasy kroków z linii [OK] (zostają dla load), rozmiary tabel, indeksów i Elasticsearcha, odczyt diskPeakGb profilu. Nazwy plików wyników i komunikaty load zostają. Bez zmiany zachowania load i bez nowych funkcji.",
      "acceptance_criteria": [
        "load.sh korzysta z funkcji z demo-data-lib.sh; istniejące testy test_load_script.py przechodzą bez zmiany asercji (kryterium 10).",
        "Funkcje wspólnego pliku nie zależą od zmiennych, które ustawia tylko load.sh: każda bierze dane z argumentów albo z nazwanych zmiennych opisanych w nagłówku pliku.",
        "make -C infra/k8s check przechodzi (shellcheck obejmuje nowy plik)."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "infra/k8s/scripts/load.sh",
        "infra/k8s/scripts/demo-data-lib.sh",
        "infra/k8s/tests/test_load_script.py",
        "infra/k8s/tests/test_demo_data_lib.py"
      ],
      "parallel_with": [
        "DEMO-SCALE-2",
        "DEMO-SCALE-3"
      ]
    },
    {
      "id": "DEMO-SCALE-2",
      "title": "Chart: zawieszony CronJob load (k6 smoke na usługach klastra)",
      "scope": "infra/k8s/charts/shop i profile prod, ci (spec: „Sprawdzenie po generowaniu”, punkt 4 i akapit o CronJobie load; kryterium 1, część load; kryterium 11). Szablon templates/load.yaml, ConfigMapa ze skryptów load/ przez dowiązanie w files/ (podkatalog lib zachowany w montowaniu), images.k6 z digestem równym temu z load/k6.sh (sprawdzenie w check), wartości i schemat, adresy http://store:3000 i http://api, STORE_ORIGIN z domeny. Nie obejmuje uruchamiania Joba (DEMO-SCALE-5, DEMO-SCALE-7) ani pomiaru obciążenia (etap 2).",
      "acceptance_criteria": [
        "Render prod i ci zawiera CronJob load: suspend true, backoffLimit 0, obraz k6 z digestem, load/smoke.js jako skrypt, bez envFrom i bez odwołań do sekretów, automountServiceAccountToken false, zasoby z wartości.",
        "ConfigMapa skryptów zawiera każdy plik .js z load/ i load/lib/, a kontener widzi je pod ścieżkami, które importuje smoke.js (test renderu porównuje listę plików z katalogiem load/).",
        "check kończy się błędem, gdy digest images.k6 różni się od digestu w load/k6.sh.",
        "Nowy przypadek selftestu (CronJob load bez suspend) kończy check błędem; wynik obu uruchomień w raporcie zadania.",
        "make -C infra/k8s check przechodzi."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "infra/k8s/charts/shop/templates/load.yaml",
        "infra/k8s/charts/shop/templates/configmaps.yaml",
        "infra/k8s/charts/shop/files/load",
        "infra/k8s/charts/shop/values.yaml",
        "infra/k8s/charts/shop/values.schema.json",
        "infra/k8s/environments/prod/values.yaml",
        "infra/k8s/environments/ci/values.yaml",
        "infra/k8s/scripts/check.sh",
        "infra/k8s/scripts/selftest.sh",
        "infra/k8s/tests/test_load_cronjob.py",
        "infra/k8s/tests/test_images.py",
        "load/k6.sh"
      ],
      "parallel_with": [
        "DEMO-SCALE-1",
        "DEMO-SCALE-3"
      ]
    },
    {
      "id": "DEMO-SCALE-3",
      "title": "status, rerun i reinitialize znają stan danych demo",
      "scope": "infra/k8s/scripts/status.sh, rerun.sh, reinitialize.sh i ich testy (spec: „Stan sklepu i zapis stanu”, „Ochrona przed przypadkowym uruchomieniem”, „Powrót do seed albo medium”, ostatni punkt, „Kolejne wdrożenia”, drugi punkt; kryteria 8 i 9). status wypisuje bieżące dane i fazę z ConfigMapy demo-data-state, obecność reguły shop-closed i ostatnią linię logu najnowszego Joba initialize; rerun odmawia Joba z etykietą generatora; reinitialize działa tylko przy danych seed w fazie idle (także bez zapisu stanu). Nazwy zasobów i etykieta: kontrakty z planu.",
      "acceptance_criteria": [
        "status bez zapisu stanu wypisuje data seed, phase idle i sklep otwarty; z zapisem wypisuje data, phase, target i powód błędu; z regułą shop-closed wypisuje, że sklep jest zamknięty; błąd odczytu któregokolwiek z nich daje kod 1 jak inne odczyty status.",
        "status wypisuje ostatnią linię logu najnowszego Joba initialize, a gdy jego podów już nie ma, mówi to wprost.",
        "rerun Joba z etykietą generatora kończy się kodem różnym od 0 z komunikatem wskazującym make generate i nie usuwa ani nie tworzy Joba; Job wersji ponawia jak dziś.",
        "reinitialize przy zapisie stanu z data innym niż seed albo fazą inną niż idle kończy się kodem 1 przed pytaniem o tak i wskazuje generate PROFILE=seed; bez zapisu i przy data seed w fazie idle działa jak dziś; błąd odczytu zapisu to odmowa.",
        "make -C infra/k8s check przechodzi."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "infra/k8s/scripts/status.sh",
        "infra/k8s/scripts/rerun.sh",
        "infra/k8s/scripts/reinitialize.sh",
        "infra/k8s/tests/test_server_scripts.py",
        "infra/k8s/tests/test_demo_data_state.py"
      ],
      "parallel_with": [
        "DEMO-SCALE-1",
        "DEMO-SCALE-2",
        "DEMO-SCALE-5",
        "DEMO-SCALE-6"
      ]
    },
    {
      "id": "DEMO-SCALE-4",
      "title": "Chart: Job generatora w prod ze skryptem potwierdzenia, strażnikiem miejsca i podsumowaniem",
      "scope": "infra/k8s/charts/shop, profile prod, ci i load oraz miejsca, które tworzą Job z CronJoba demo-data (spec: „Job generatora na serwerze (chart)”; „Model zagrożeń”; kryteria 1, część demo-data, 2 i 11). Skrypt files/scripts/demo-data.sh w ConfigMapie skryptów (profil albo seed według DEMO_DATA_CONFIRM); wartości prod: demoData włączone z profilem medium, stałą chwilą odniesienia, photosSecretName demo-photos (odwołania opcjonalne), minFreeGb 32, limity czasu medium 6 h i server 24 h, terminationGracePeriodSeconds 120, zmienna PGOPTIONS z client_connection_check_interval=10s w kontenerze, resources.demoData; minFreeGb 2 w ci i load; schemat z nowymi warunkami zamiast zakazu przy requireDigests. load.sh i smoke.sh tworzą Job generatora ze zmienną DEMO_DATA_CONFIRM (funkcja cronjob_job), bo bez niej Job odmawia. Nie obejmuje celu generate.",
      "acceptance_criteria": [
        "Render prod zawiera zawieszony CronJob demo-data: backoffLimit 0, activeDeadlineSeconds profilu, terminationGracePeriodSeconds 120, PGOPTIONS z client_connection_check_interval=10s, kontener uruchamia demo-data.sh, odwołania do sekretu puli zdjęć tylko w jego kontenerze i opcjonalne, zasoby resources.demoData, bez tokenu konta usługi.",
        "Schemat odrzuca wartości z image.requireDigests i demoData.enabled, gdy anchor albo photosSecretName jest pusty, profil jest inny niż medium i server albo minFreeGb jest poniżej 10; profile ci i load renderują się dalej z profilem small i minFreeGb 2.",
        "demo-data.sh (testy z podstawionymi df, bin/generate-demo, bin/seed-demo i bin/console): DEMO_DATA_CONFIRM puste albo inne niż profil i seed daje kod 64 i nic nie startuje; seed uruchamia bin/seed-demo, profil bin/generate-demo z profilem, chwilą odniesienia i --force-reset; za mało miejsca albo nieudany odczyt przed startem kod 74 i nic nie startuje.",
        "Spadek wolnego miejsca poniżej progu w trakcie, trzy nieudane odczyty z rzędu i odczyt nieliczbowy zatrzymują całą grupę procesów generatora (test z atrapą, której proces potomny działa na pierwszym planie i ignoruje pułapkę rodzica) i nic poza nią (skrypt nie zamyka sesji bazy ani nie dotyka innych procesów), kod 75; sygnał TERM do skryptu robi to samo; kod generatora inny niż 0 przechodzi na wyjście.",
        "Na każdej ścieżce (sukces, 64, 74, 75, TERM, błąd generatora) skrypt wypisuje blok podsumowania (kod, ostatni ukończony krok, czas każdego kroku liczony przez skrypt z linii [OK], najmniejsze wolne miejsce) i zapisuje linię powodu do /dev/termination-log; odczyt strażnika jest w logu co dziesiąty raz.",
        "Raport zadania pokazuje z działającego stosu (compose albo k3d), że sesja bazy procesu PHP uruchomionego z PGOPTIONS z client_connection_check_interval kończy się po zabiciu tego procesu sygnałem KILL w trakcie długiego zapytania, a bez tej zmiennej działa dalej (Z11); gdy tak nie jest, zadanie zatrzymuje się i zgłasza to sesji prowadzącej.",
        "Selftest łapie: CronJob demo-data bez suspend w prod, skrypt bez sprawdzenia DEMO_DATA_CONFIRM, skrypt bez strażnika miejsca; wynik obu uruchomień w raporcie zadania.",
        "make -C infra/k8s load LOAD_DATA i faza generatora testu dymnego tworzą Job z DEMO_DATA_CONFIRM i działają bez pliku puli zdjęć przy profilu small (ścieżka deploy.yml); testy test_load_script.py i test_smoke_demo_data.py zmieniają się tylko w asercjach tworzenia Joba.",
        "make -C infra/k8s check przechodzi."
      ],
      "risk": "CRITICAL",
      "depends_on": [
        "DEMO-SCALE-1",
        "DEMO-SCALE-2"
      ],
      "paths": [
        "infra/k8s/charts/shop/",
        "infra/k8s/environments/",
        "infra/k8s/scripts/load.sh",
        "infra/k8s/scripts/smoke.sh",
        "infra/k8s/scripts/check.sh",
        "infra/k8s/scripts/selftest.sh",
        "infra/k8s/scripts/render.sh",
        "infra/k8s/tests/"
      ],
      "parallel_with": []
    },
    {
      "id": "DEMO-SCALE-5",
      "title": "Cel generate: odmowy, zamknięcie sklepu, Job, sprawdzenie, otwarcie i ścieżki błędu",
      "scope": "infra/k8s/scripts/generate.sh, cel generate w infra/k8s/Makefile (z ao-guard.sh) i testy na atrapach kubectl (spec: „Stan sklepu i zapis stanu”; „Cel make -C infra/k8s generate”, przebieg przy fazie idle, kroki 1–7, i kody wyjścia; „Sprawdzenie po generowaniu”; „Powrót do seed albo medium”, pierwszy punkt; kryteria 3, 4 i 6). Obejmuje PROFILE=seed tym samym Jobem, sondę listy adresów GENERATE_PROBE_URL z GENERATE_PROBE_HOST, zapisy stanu warunkowe względem resourceVersion, ścieżkę bez Argo CD (jak restore), regułę shop-closed z restartem poda Traefika, wyłączenie i przywrócenie automatycznej synchronizacji Argo CD, zapis stanu, sekret puli zdjęć, zapis logu kontenera, podsumowania i rozmiarów po zakończeniu Joba (bez zbierania czegokolwiek w trakcie). Nie obejmuje wznowienia (DEMO-SCALE-6): przy fazie innej niż idle cel w tym zadaniu odmawia bez zmian.",
      "acceptance_criteria": [
        "Każda odmowa z kryterium 3 specyfikacji kończy cel bez zmian w klastrze i z komunikatem nazywającym przyczynę (w tym niegotowy Deployment albo StatefulSet); złe argumenty i środowisko dają kod 2, pozostałe odmowy kod 1; odmowa REPLACE podaje data z zapisu stanu i szacowane liczby produktów i zamówień; plik puli zdjęć jest wymagany tylko wtedy, gdy CronJob ma sekret puli i profil nie jest seed; próg 1,25 × diskPeakGb obowiązuje tylko medium i server, minFreeGb zawsze.",
        "Sonda pyta każdy adres z GENERATE_PROBE_URL: 502, 503 i 504 to „zamknięty”, kod poniżej 500 to „otwarty”, każdy inny kod i brak odpowiedzi to odczyt nieudany, ponawiany do limitu i nigdy nie liczony jako zamknięty ani otwarty; wynik całości wymaga zgody wszystkich adresów (test: API zamknięte, strona sklepu z kodem 200 z cache daje cofnięcie).",
        "Ścieżka poprawna dla profilu z CronJoba i dla seed wykonuje kroki w kolejności specyfikacji: zapis closing z rootAutomated przed pierwszą zmianą; synchronizacja root, potem shop, wyłączona; oczekiwanie na Joby, które ruszyły po sprawdzeniu; ponowne sprawdzenie, że żadna operacja synchronizacji root ani shop nie trwa i że shop nie ma z powrotem automatycznej synchronizacji; reguła; usunięcie podów Traefika i jego gotowość; sonda zamknięcia; zapis running z nazwą Joba i data failed; sekret (gdy wymagany); Job z DEMO_DATA_CONFIRM; zapis checking; usunięcie sekretu; zapis logu kontenera, podsumowania i sizes.txt w GENERATE_OUT; czyszczenie puli cache.sitemap w każdym podzie api; cztery sprawdzenia; zapis opening z data równym profilowi; usunięcie reguły; przywrócenie synchronizacji; sonda otwarcia; na końcu zapis idle z liczbami i bez rootAutomated; kod 0. W klastrze bez CRD Application albo bez aplikacji shop kroki synchronizacji są pominięte, reszta bez zmian.",
        "Cofnięcie (sonda bez potwierdzenia zamknięcia w GENERATE_CLOSE_WAIT albo synchronizacja, która ruszyła po wyłączeniu) kończy cel kodem 1, zanim powstanie Job: sekret usunięty, jeśli istnieje, reguła usunięta tylko przy data innym niż failed (test startu z data failed: reguła zostaje), synchronizacja przywrócona, na końcu zapis idle bez zmiany data.",
        "Job nieudany (także bez poda: powód „pod Joba nie istnieje”), Job po czasie, kod 75 Joba i każde nieudane sprawdzenie (nieważny indeks Postgresa, alias na zero albo wiele indeksów, stan red, indeks bez aliasu, nieudany Job load) dają w tej kolejności: log zapisany, sekret usunięty, reguła shop-closed istnieje (założona z restartem Traefika, jeśli jej nie było), synchronizacja przywrócona do rootAutomated, na końcu zapis idle z data failed i powodem (linia z termination-log, gdy jest); kod 1 i komunikat z dwiema drogami wyjścia. Nieudana sonda otwarcia zostawia fazę opening i kod 1.",
        "W trakcie oczekiwania na Job cel wypisuje tylko czas przebiegu; nie czyta logu na bieżąco, nie próbkuje węzła i nie używa SSH; cel nie usuwa żadnego Joba.",
        "Gdy zapis stanu ma już rootAutomated, start nie nadpisuje go bieżącym ustawieniem; pole znika tym samym zapisem, który następuje po przywróceniu synchronizacji (test: start po przebiegu przerwanym przed przywróceniem).",
        "Każdy zapis stanu jest warunkowy względem resourceVersion odczytanego przez proces, a przed każdą zmianą w klastrze (synchronizacja, reguła, Traefik, sekret, Job) proces czyta zapis ponownie i sprawdza, że wersja jest tą z jego ostatniego zapisu; konflikt albo inna wersja kończy cel kodem 1 bez dalszych zmian (testy: zapis zmieniony przez drugi proces między odczytem a zapisem oraz między dwiema zmianami w klastrze).",
        "Reguła, zapis stanu i sekret tworzone przez cel nie mają etykiet ani adnotacji śledzenia Argo CD (app.kubernetes.io/instance, argocd.argoproj.io/tracking-id).",
        "Wartości DEMO_PHOTOS_* nie pojawiają się w wyjściu (także z sh -x), linii poleceń ani GENERATE_OUT; token zapisu nie trafia do klastra.",
        "Bez słowa tak nic się nie zmienia; cel woła ao-guard.sh i odmawia przy działającym AO.",
        "make -C infra/k8s check przechodzi; raport zadania pokazuje wynik ścieżki poprawnej i ścieżek błędu."
      ],
      "risk": "CRITICAL",
      "depends_on": [
        "DEMO-SCALE-1",
        "DEMO-SCALE-2",
        "DEMO-SCALE-4"
      ],
      "paths": [
        "infra/k8s/scripts/generate.sh",
        "infra/k8s/scripts/kube-jobs.sh",
        "infra/k8s/scripts/ao-guard.sh",
        "infra/k8s/Makefile",
        "infra/k8s/tests/test_generate_script.py",
        "infra/k8s/tests/test_ao_guard.py"
      ],
      "parallel_with": [
        "DEMO-SCALE-3"
      ]
    },
    {
      "id": "DEMO-SCALE-6",
      "title": "Cel generate: wznowienie po przerwaniu i zachowanie na sygnał",
      "scope": "infra/k8s/scripts/generate.sh i jego testy (spec: „Cel make -C infra/k8s generate”, „Przerwanie i wznowienie”; „Scenariusze awarii”; kryterium 5). Wznowienie z faz closing, running, checking i opening, Job nieudany bez poda, zachowanie na sygnał.",
      "acceptance_criteria": [
        "Wznowienie (faza inna niż idle) wymaga KUBE_DIR, GENERATE_OUT, GENERATE_PROBE_URL i PROFILE równego target z zapisu; nie pyta o tak, nie wymaga REPLACE, nie sprawdza miejsca ani aktywnych Jobów, nie tworzy sekretu ani Joba; PROFILE inny niż target to odmowa bez zmian.",
        "closing: cofnięcie jak w kroku 3 specyfikacji (sekret usunięty, reguła usunięta tylko przy data innym niż failed), kod 1. running: dołączenie do Joba z zapisu albo odczyt jego wyniku, potem krok 5 albo 7; bez tego Joba albo z Jobem nieudanym bez poda krok 7. checking: powtórzenie kroku 5 od usunięcia sekretu (brak poda Joba nie jest błędem), potem krok 6 albo 7. opening: dokończenie kroku 6 bez powtarzania sprawdzeń.",
        "Testy przerywają skrypt po każdym wywołaniu kubectl na ścieżce poprawnej, ścieżce błędu i cofnięciu (atrapa kończy proces po n-tym wywołaniu) i uruchamiają cel ponownie: po żadnym przerwaniu reguła nie znika przed krokiem 6, przy data failed reguła istnieje, a po zakończeniu sekretu nie ma, rootAutomated nie ma w zapisie i synchronizacja jest równa wartości sprzed pierwszego wyłączenia.",
        "INT i TERM w trakcie oczekiwania kończą skrypt bez usunięcia Joba, bez usunięcia reguły i bez przywrócenia synchronizacji; komunikat mówi, jak wznowić.",
        "make -C infra/k8s check przechodzi; raport zadania pokazuje wynik wznowienia z każdej fazy i przerwania sygnałem."
      ],
      "risk": "CRITICAL",
      "depends_on": [
        "DEMO-SCALE-5"
      ],
      "paths": [
        "infra/k8s/scripts/generate.sh",
        "infra/k8s/Makefile",
        "infra/k8s/tests/test_generate_script.py",
        "infra/k8s/tests/test_generate_resume.py"
      ],
      "parallel_with": [
        "DEMO-SCALE-3"
      ]
    },
    {
      "id": "DEMO-SCALE-7",
      "title": "Test dymny k3d przechodzi przez cel generate",
      "scope": "infra/k8s/scripts/smoke.sh, infra/k8s/smoke i ich testy (spec: kryterium 7; „Założenia techniczne”, Z1 i Z2). Ostatnia faza testu dymnego uruchamia cel generate z profilem small zamiast samego Joba z CronJoba, z ciągłym ruchem przez ingress w trakcie zamykania; SMOKE_BREAK=demo-data sprawdza nieudany generator i powrót do seeda.",
      "acceptance_criteria": [
        "make -C infra/k8s smoke (bez pliku puli zdjęć i bez Argo CD, jak w deploy.yml): żądania przez ingress wysyłane bez przerwy przestają dostawać 200 po zamknięciu i dostają je znowu po otwarciu; po przebiegu zapis stanu to idle z data small, Job load zakończył się sukcesem, a liczby produktów i zamówień są te z profilu small; faza wypisuje wolne miejsce węzła przed startem.",
        "SMOKE_BREAK=demo-data: cel generate kończy się kodem 1, ingress odpowiada 502, zapis stanu ma data failed; potem generate PROFILE=seed REPLACE=failed kończy się kodem 0, sklep odpowiada, a dane to seed.",
        "Pozostałe fazy testu dymnego przechodzą bez zmiany swoich sprawdzeń; czas fazy generatora jest wypisany.",
        "Raport zadania zawiera wynik pełnego make -C infra/k8s smoke i przebiegu z SMOKE_BREAK=demo-data."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "DEMO-SCALE-3",
        "DEMO-SCALE-6"
      ],
      "paths": [
        "infra/k8s/scripts/smoke.sh",
        "infra/k8s/smoke/",
        "infra/k8s/tests/test_smoke_demo_data.py"
      ],
      "parallel_with": [
        "DEMO-SCALE-8"
      ]
    },
    {
      "id": "DEMO-SCALE-8",
      "title": "Dokumentacja: zmiana kolejności, runbook generowania i zasady zgody",
      "scope": "Dokumenty z sekcji „Dokumentacja” specyfikacji (kryterium 12): docs/features/demo-data/spec.md (P2 i W3 według U1, „Po epiku”, „Poza zakresem”, „Model zagrożeń”, opis CronJoba), docs/vision.md („Skala docelowa”, „Dane demo”), infra/k8s/README.md (sekcja generowania danych na serwerze: przygotowanie, generate, wznowienie, błędy, powrót do seeda i medium, scenariusze awarii, wdrożenie po generowaniu, eksport logu i metryk z Grafana Cloud, adresy sondy przez Cloudflare), infra/k8s/CLAUDE.md i główny CLAUDE.md (generate na liście celów wymagających zgody, rerun nie ponawia Joba generatora), docs/features/deploy/spec.md i docs/features/deploy-ops/spec.md (CronJoby demo-data i load w prod, zamknięcie sklepu a alerty), docs/adr/backups-observability.md (tymczasowa NetworkPolicy), docs/features/demo-scale/spec.md (stan po implementacji).",
      "acceptance_criteria": [
        "Każdy dokument z listy opisuje stan po epiku, bez historii zmian; zasady w obu CLAUDE.md mówią, że generate wymaga zgody użytkownika w rozmowie, która nazywa operację, host i profil.",
        "Żaden tekst nie przedstawia pełnej skali jako zweryfikowanej ani automatycznej kopii OVH jako aktywnej; liczby server i full są opisane jako ekstrapolacja.",
        "Runbook podaje pełne polecenia celu generate z KUBE_DIR, GENERATE_OUT i GENERATE_PROBE_URL oraz sprawdzenie wdrożenia po generowaniu (czas initialize, liczby, próg 5 minut).",
        "make -C infra/k8s check przechodzi (testy dokumentów i zasad, które czytają te pliki)."
      ],
      "risk": "FAST",
      "depends_on": [
        "DEMO-SCALE-3",
        "DEMO-SCALE-6"
      ],
      "paths": [
        "docs/features/demo-data/spec.md",
        "docs/features/demo-scale/spec.md",
        "docs/features/deploy/spec.md",
        "docs/features/deploy-ops/spec.md",
        "docs/adr/backups-observability.md",
        "docs/vision.md",
        "infra/k8s/README.md",
        "infra/k8s/CLAUDE.md",
        "CLAUDE.md"
      ],
      "parallel_with": [
        "DEMO-SCALE-7"
      ]
    }
  ]
}
```
