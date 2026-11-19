# DEMO-SCALE – plan zadań

Specyfikacja: `docs/features/demo-scale/spec.md`. Epik prowadzi główna sesja Claude Code z natywnymi subagentami. Epik ma
jedną gałąź (poza `epic/*`) z PR-em do `main` otwartym od początku jako szkic. Każde zadanie realizuje subagent we
własnym worktree na gałęzi zadania; sesja orkiestrująca merguje gotowe gałęzie zadań do gałęzi epiku i wypycha ją.
Zadania nie mają własnych PR. Zadania jednej fali z listą `parallel_with` mogą iść naraz. Plan jest dokumentem
roboczym: po ukończeniu epiku usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | DEMO-SCALE-1, DEMO-SCALE-2, DEMO-SCALE-3 | zmergowane: DEMO-SCALE-1 `cf2ac610`, DEMO-SCALE-2 `fcfb9ef5`, DEMO-SCALE-3 `b4276bd5` |
| 2 | DEMO-SCALE-4 | zmergowane `23df42db` |
| 3 | DEMO-SCALE-8 | zmergowane `47dbab53` |
| 4 | DEMO-SCALE-9 | zmergowane `e9252415` |
| 5 | DEMO-SCALE-10, DEMO-SCALE-11 | zmergowane: DEMO-SCALE-10 `40edbde2`, DEMO-SCALE-11 `13fa3a9a` |

Kolumnę „Status” (zmergowane do gałęzi epiku, commit) uzupełnia sesja orkiestrująca po merge'u gałęzi zadania.
Numery 5–7 nie są używane.

Warunek przed startem: PR #756 zmergowany do `main` (gałąź epiku powstaje od `origin/main` po tym merge'u).

Kroki na serwerze (wdrożenia, `generate PROFILE=medium`, analiza, `generate PROFILE=server`, sprawdzenie wdrożeń po
generowaniu, raporty) nie są zadaniami: wykonuje je sesja prowadząca po merge'u epiku, każdy po osobnej zgodzie
użytkownika (specyfikacja, „Przebieg na serwerze”).

Kontrakty między zadaniami są w specyfikacji: nazwy `shop-closed`, `demo-data-run`, `demo-photos`, zmienna
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
      "scope": "infra/k8s/charts/shop i profile prod, ci (spec: „Cel make -C infra/k8s open” i akapit o CronJobie load; kryterium 1, część load; kryterium 11). Szablon templates/load.yaml, ConfigMapa ze skryptów load/ przez dowiązanie w files/ (podkatalog lib zachowany w montowaniu), images.k6 z digestem równym temu z load/k6.sh (sprawdzenie w check), wartości i schemat, adresy http://store:3000 i http://api, STORE_ORIGIN z domeny. Nie obejmuje uruchamiania Joba (DEMO-SCALE-9, DEMO-SCALE-10) ani pomiaru obciążenia (etap 2).",
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
      "title": "status, rerun i reinitialize znają zamknięcie sklepu i Job generatora",
      "scope": "infra/k8s/scripts/status.sh, rerun.sh, reinitialize.sh i ich testy (spec: „Stan sklepu i blokada”, „Ochrona przed przypadkowym uruchomieniem”, „Powrót do seed albo medium”, ostatni punkt, „Kolejne wdrożenia”; kryteria 8 i 9). status wypisuje, czy istnieje reguła shop-closed, Job demo-data-run i jego stan, aktywne Joby, pozostawiony sekret puli zdjęć i ostatnią linię logu najnowszego Joba initialize; rerun odmawia Joba z etykietą generatora; reinitialize odmawia, dopóki istnieje reguła shop-closed. Nazwy zasobów i etykieta: kontrakty z planu.",
      "acceptance_criteria": [
        "status wypisuje, czy sklep jest zamknięty (reguła shop-closed), Job demo-data-run i jego stan (albo że go nie ma), aktywne Joby i czy sekret puli zdjęć został; błąd odczytu któregokolwiek z nich daje kod 1 jak inne odczyty status.",
        "status wypisuje ostatnią linię logu najnowszego Joba initialize, a gdy jego podów już nie ma, mówi to wprost.",
        "rerun Joba z etykietą generatora kończy się kodem 3 z komunikatem wskazującym make generate i nie usuwa ani nie tworzy Joba; Job wersji ponawia jak dziś.",
        "reinitialize przy regule shop-closed kończy się kodem 1 przed pytaniem o tak i wskazuje generate PROFILE=seed; bez reguły działa jak dziś; błąd odczytu reguły to odmowa.",
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
        "DEMO-SCALE-2"
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
      "id": "DEMO-SCALE-8",
      "title": "Dokumentacja: zmiana kolejności, runbook generowania i zasady zgody",
      "scope": "Dokumenty z sekcji „Dokumentacja” specyfikacji (kryterium 12), opisujące stan po epiku bez historii zmian: docs/features/demo-data/spec.md, docs/vision.md, infra/k8s/README.md (sekcja 11: CronJoby demo-data i load, runbook generowania danych na serwerze), infra/k8s/CLAUDE.md i główny CLAUDE.md (zgoda nazywająca operację, host i profil; rerun nie ponawia Joba generatora: kod 3), docs/features/deploy/spec.md, docs/features/deploy-ops/spec.md, docs/adr/backups-observability.md, docs/features/demo-scale/spec.md, nagłówek infra/k8s/scripts/rerun.sh. DEMO-SCALE-11 przycina je do wariantu minimalnego.",
      "acceptance_criteria": [
        "Każdy dokument z listy opisuje stan po epiku, bez historii zmian; zasady w obu CLAUDE.md mówią, że generate wymaga zgody użytkownika w rozmowie, która nazywa operację, host i profil, a rerun nie ponawia Joba generatora (kod 3).",
        "Żaden tekst nie przedstawia pełnej skali jako zweryfikowanej ani automatycznej kopii OVH jako aktywnej; liczby server i full są opisane jako ekstrapolacja.",
        "Specyfikacja opisuje PROFILE=seed bez progu wolnego miejsca (cel i Job) i CronJob load w każdym profilu; punkt o cache ISR w „Ryzyka” i warunek w „Wymagane przed startem” bez zmian.",
        "make -C infra/k8s check przechodzi (testy dokumentów i zasad, które czytają te pliki)."
      ],
      "risk": "FAST",
      "depends_on": [
        "DEMO-SCALE-3"
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
        "infra/k8s/scripts/rerun.sh",
        "CLAUDE.md"
      ],
      "parallel_with": []
    },
    {
      "id": "DEMO-SCALE-9",
      "title": "Cele generate i open w wariancie minimalnym",
      "scope": "infra/k8s/scripts/generate.sh, open.sh, shop-closed.sh, Makefile (z ao-guard.sh) i testy na atrapach kubectl (spec: „Stan sklepu i blokada”, „Cel make -C infra/k8s generate”, „Cel make -C infra/k8s open”; kryteria 3–6). generate: odmowy bez zmian, pytanie o tak, usunięcie Joba demo-data-run poprzedniego przebiegu, reguła shop-closed, restart Traefika, sonda zamknięcia, sekret puli zdjęć (usuwany przy każdym wyjściu), Job demo-data-run z CronJoba, oczekiwanie, wywołanie open. open: odmowy (aktywny Job, brak Joba albo Job inny niż Complete), usunięcie pozostawionego sekretu, czyszczenie puli cache.sitemap, indeksy Postgresa, alias wyszukiwarki, Job load, otwarcie i sonda. Decyzja U8: bez zapisu stanu, faz, wznawiania i zmian w Argo CD.",
      "acceptance_criteria": [
        "Każda odmowa z kryterium 3 specyfikacji kończy generate bez zmian w klastrze i z komunikatem nazywającym przyczynę; złe argumenty i środowisko dają kod 2, pozostałe odmowy kod 1; Joby CronJoba rate-limit-check nie liczą się jako aktywne.",
        "Ścieżka poprawna (profil i seed) wykonuje kroki w kolejności specyfikacji; Job poprzedniego przebiegu jest usunięty tuż przed nowym (po zamknięciu i sekrecie), a open po przerwaniu nowego generowania po tym usunięciu odmawia (brak Joba albo Job niezakończony); sekret puli zdjęć jest usunięty przy każdym wyjściu generate (także HUP, INT i TERM), a po zabiciu skryptu przez open.",
        "Sonda zamknięcia bez zgody wszystkich adresów, Job nieudany (także bez poda) albo po czasie i kod 75 kończą generate kodem 1 ze sklepem zamkniętym i dwiema drogami wyjścia; nieudane sprawdzenie w open zostawia regułę; sonda bez „otwarty” po usunięciu reguły to kod 1.",
        "Wartości DEMO_PHOTOS_* nie pojawiają się w wyjściu (także z sh -x) ani linii poleceń; reguła, Job demo-data-run i sekret nie mają etykiet ani adnotacji śledzenia Argo CD.",
        "Bez słowa tak nic się nie zmienia; generate i open wołają ao-guard.sh i odmawiają przy działającym AO.",
        "make -C infra/k8s check przechodzi; raport zadania pokazuje wynik ścieżki poprawnej i ścieżek błędu."
      ],
      "risk": "CRITICAL",
      "depends_on": [
        "DEMO-SCALE-1",
        "DEMO-SCALE-2",
        "DEMO-SCALE-3",
        "DEMO-SCALE-4"
      ],
      "paths": [
        "infra/k8s/scripts/generate.sh",
        "infra/k8s/scripts/open.sh",
        "infra/k8s/scripts/shop-closed.sh",
        "infra/k8s/scripts/kube-jobs.sh",
        "infra/k8s/scripts/ao-guard.sh",
        "infra/k8s/Makefile",
        "infra/k8s/tests/test_generate_script.py",
        "infra/k8s/tests/test_ao_guard.py"
      ],
      "parallel_with": []
    },
    {
      "id": "DEMO-SCALE-10",
      "title": "Test dymny k3d przechodzi przez generate i open",
      "scope": "infra/k8s/scripts/smoke.sh, infra/k8s/smoke i ich testy (spec: kryterium 7; Z1 i Z2). Ostatnia faza testu dymnego uruchamia generate PROFILE=small i open zamiast samego Joba z CronJoba, z ruchem przez ingress; SMOKE_BREAK=demo-data sprawdza nieudany generator i powrót do seeda.",
      "acceptance_criteria": [
        "make -C infra/k8s smoke (bez pliku puli zdjęć i bez Argo CD): żądania przez ingress wysyłane bez przerwy przestają dostawać 200 po zamknięciu i dostają je znowu po otwarciu; Job load przeszedł.",
        "SMOKE_BREAK=demo-data: generate kończy się kodem 1, sklep jest zamknięty, open odmawia, a generate PROFILE=seed kończy się kodem 0 i otwiera sklep.",
        "Pozostałe fazy testu dymnego przechodzą bez zmiany swoich sprawdzeń; czas fazy generatora jest wypisany.",
        "Raport zadania zawiera wynik pełnego make -C infra/k8s smoke i przebiegu z SMOKE_BREAK=demo-data."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "DEMO-SCALE-9"
      ],
      "paths": [
        "infra/k8s/scripts/smoke.sh",
        "infra/k8s/smoke/",
        "infra/k8s/tests/test_smoke_demo_data.py"
      ],
      "parallel_with": [
        "DEMO-SCALE-11"
      ]
    },
    {
      "id": "DEMO-SCALE-11",
      "title": "Dokumentacja przycięta do wariantu minimalnego",
      "scope": "Dokumenty z sekcji „Dokumentacja” specyfikacji: specyfikacja, plan i runbook opisują dwa polecenia, generate i open, bez zapisu stanu, faz, wznawiania, przełączania Argo CD, REPLACE i GENERATE_OUT; CLAUDE.md (główny i infra/k8s): generate i open wymagają zgody nazywającej operację, host i profil. Tylko dokumentacja, bez zmian kodu.",
      "acceptance_criteria": [
        "Żaden dokument poza docs/reports/ nie opisuje zapisu stanu, faz, wznawiania, przełączania Argo CD, REPLACE ani GENERATE_OUT (przeszukanie repo).",
        "Runbook (sekcja 11) podaje polecenia generate i open z KUBE_DIR, GENERATE_PROBE_URL i GENERATE_PROBE_HOST, warunek wstępny (L9 i osobna zgoda), kody wyjścia, postępowanie po błędzie, ostrzeżenie o wyzwaniu Cloudflare i sprawdzenie wdrożenia po generowaniu.",
        "make -C infra/k8s check przechodzi."
      ],
      "risk": "FAST",
      "depends_on": [
        "DEMO-SCALE-9"
      ],
      "paths": [
        "docs/features/demo-scale/spec.md",
        "docs/features/demo-scale/plan.md",
        "docs/features/demo-data/spec.md",
        "docs/features/deploy/spec.md",
        "docs/features/deploy-ops/spec.md",
        "docs/adr/backups-observability.md",
        "docs/vision.md",
        "infra/k8s/README.md",
        "infra/k8s/CLAUDE.md",
        "CLAUDE.md"
      ],
      "parallel_with": [
        "DEMO-SCALE-10"
      ]
    }
  ]
}
```
