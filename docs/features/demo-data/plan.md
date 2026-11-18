# DEMO-LOAD – plan zadań

Specyfikacja: `docs/features/demo-data/spec.md` (sekcje i kryteria oznaczone „część 2”). Epik prowadzi główna sesja
Claude Code z natywnymi subagentami. Epik ma jedną gałąź (poza `epic/*`) z PR-em do `main` otwartym od początku jako
szkic. Każde zadanie realizuje subagent we własnym worktree na gałęzi zadania; sesja orkiestrująca merguje gotowe
gałęzie zadań do gałęzi epiku i wypycha ją. Zadania nie mają własnych PR. Zadania jednej fali z listą `parallel_with`
mogą iść naraz. Plan jest dokumentem roboczym: po ukończeniu epiku usuń go.

DEMO-LOAD-6 uruchamia sesja prowadząca na komputerze użytkownika (potrzebuje pliku `~/.config/shop/demo-photos.env`
i dostępu do bucketu puli zdjęć, których subagent w worktree nie ma); pozostałe zadania realizuje subagent `developer`.

## Status

Epik w toku od 2026-11-18: gałąź `feat/demo-load`, szkic PR do `main`.

| Fala | Zadania | Status |
|---|---|---|
| 1 | DEMO-LOAD-1, DEMO-LOAD-3 | DEMO-LOAD-3 zmergowane (c9a03bbe, przegląd PASS); DEMO-LOAD-1 w toku |
| 2 | DEMO-LOAD-2, DEMO-LOAD-4 | |
| 3 | DEMO-LOAD-5 | |
| 4 | DEMO-LOAD-6 | |

Kolumnę „Status” (zmergowane do gałęzi epiku, commit) uzupełnia sesja orkiestrująca po merge'u gałęzi zadania.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "DEMO-LOAD",
  "spec": "docs/features/demo-data/spec.md",
  "tasks": [
    {
      "id": "DEMO-LOAD-1",
      "title": "Chart: zawieszony CronJob demo-data w profilach ci i load",
      "scope": "infra/k8s/charts/shop i profile ci, load (spec: „k3d (chart w profilach ci i load; część 2)”, punkt pierwszy i ostatnie zdanie punktu trzeciego; „Model zagrożeń”, przypadkowe zastąpienie danych i wyciek danych dostępu do bucketu). CronJob według wzorca CronJoba restore: obraz api, bin/generate-demo z profilem i chwilą odniesienia z wartości, sekret stosu, login administratora Keycloaka jak w Jobie keycloak-config, zasoby resources.demoData, limit czasu z wartości per profil, bez ponowień; opcjonalne odwołanie do sekretu odczytu puli zdjęć (tylko w tym CronJobie). Profil load wyłącza kopie zapasowe. Nie obejmuje uruchamiania Joba (DEMO-LOAD-2 i DEMO-LOAD-4) ani profilu prod.",
      "acceptance_criteria": [
        "CronJob demo-data renderuje się tylko z demoData.enabled (profile ci i load), ma suspend: true, backoffLimit 0, zasoby resources.demoData i activeDeadlineSeconds z wartości profilu generatora (początkowo small 30 min, medium 2 h, server 8 h, full 16 h).",
        "Profil prod renderuje się tak jak przed zadaniem (test porównuje render bez CronJoba demo-data i bez nowych zmiennych w innych obiektach).",
        "Profil load renderuje się bez CronJobów backup, backup-verify i restore.",
        "Sekret odczytu puli zdjęć jest przekazany wyłącznie kontenerowi CronJoba demo-data i tylko wtedy, gdy wartości go podają; bez niego render się udaje (profil small go nie potrzebuje).",
        "make -C infra/k8s check przechodzi, a nowy przypadek selftestu (usunięte suspend w szablonie) kończy check błędem; wynik obu uruchomień w raporcie."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "infra/k8s/charts/shop/",
        "infra/k8s/environments/ci/values.yaml",
        "infra/k8s/environments/load/values.yaml",
        "infra/k8s/tests/test_demo_data.py",
        "infra/k8s/tests/test_load_profile.py",
        "infra/k8s/scripts/selftest.sh"
      ],
      "parallel_with": ["DEMO-LOAD-3"]
    },
    {
      "id": "DEMO-LOAD-2",
      "title": "Test dymny k3d uruchamia generator z profilem small",
      "scope": "infra/k8s/scripts/smoke.sh i jego testy (spec: „k3d”, punkt drugi; kryterium 10). Job z CronJoba demo-data z profilem small, sprawdzenie kodu 0. Generator zastępuje dane sklepu, więc faza biegnie po fazach, które sprawdzają dane seeda i kopie, albo przywraca po sobie stan, którego potrzebują kolejne fazy.",
      "acceptance_criteria": [
        "make -C infra/k8s smoke tworzy Job z CronJoba demo-data z profilem small, czeka na niego i kończy się błędem z logiem Joba, gdy Job zakończy się błędem albo nie skończy się w limicie czasu.",
        "Pozostałe fazy testu dymnego przechodzą bez zmiany swoich sprawdzeń.",
        "Raport zawiera wynik pełnego make -C infra/k8s smoke (ścieżka poprawna) i przebiegu z wymuszonym błędem Joba generatora (ścieżka błędu)."
      ],
      "risk": "STANDARD",
      "depends_on": ["DEMO-LOAD-1"],
      "paths": [
        "infra/k8s/scripts/",
        "infra/k8s/smoke/",
        "infra/k8s/tests/"
      ],
      "parallel_with": ["DEMO-LOAD-3", "DEMO-LOAD-4"]
    },
    {
      "id": "DEMO-LOAD-3",
      "title": "k6: produkty z losowych porcji mapy strony",
      "scope": "load/lib/data.js i skrypty k6 (spec: „Pomiar k6 (część 2)”; kryterium 13). Klucze porcji z /api/v1/sitemap/product-chunks albo z opcji, produkty z losowych porcji (/api/v1/sitemap/products?chunk=…), frazy wyszukiwania z nazw tych produktów w API, facety z listingu kategorii; liczba porcji i produktów z opcji. Dokumentacja: load/README.md (wybór danych i opcje). Nie obejmuje przekazywania kluczy przez load.sh (DEMO-LOAD-4).",
      "acceptance_criteria": [
        "discover() nie stronicuje listingu całego katalogu: produkty pochodzą z porcji mapy strony, a facety z listingu kategorii.",
        "Klucze porcji podane opcją zastępują losowanie; liczba porcji i produktów jest ograniczona opcjami.",
        "make load-smoke na danych z seeda przechodzi jak dziś (każdy scenariusz, checkout gościa); pusty katalog nadal zatrzymuje przebieg komunikatem.",
        "load/README.md opisuje wybór danych i nowe opcje."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "load/"
      ],
      "parallel_with": ["DEMO-LOAD-1", "DEMO-LOAD-2"]
    },
    {
      "id": "DEMO-LOAD-4",
      "title": "make load z LOAD_DATA: Job generatora, stany wylosowanych porcji, sekret puli zdjęć",
      "scope": "infra/k8s/scripts/load.sh, jego testy i dokumentacja (spec: „k3d”, punkt trzeci i ostatni; „Model zagrożeń”, wyciek danych dostępu do bucketu; kryteria 11 bez zapasu miejsca i 14). LOAD_DATA=seed|medium|server|full (domyślnie seed, jak dziś); dla pozostałych po initialize Job generatora z osobnym limitem czasu, potem pomiar; podniesienie stanu tylko wariantów produktów z wylosowanych porcji mapy strony i przekazanie kluczy porcji k6; sekret odczytu puli zdjęć w klastrze z pliku użytkownika (cztery zmienne odczytu, bez tokenu zapisu); czasy kroków generatora i rozmiary tabel i indeksów w LOAD_OUT. Dokumentacja: komentarz celu w infra/k8s/Makefile, runbook infra/k8s/README.md (LOAD_DATA i CronJob demo-data), infra/k8s/CLAUDE.md (tylko zasada), docs/features/hardening/spec.md (opis load), load/README.md. Nie obejmuje zapasu miejsca W2 (DEMO-LOAD-5).",
      "acceptance_criteria": [
        "Bez LOAD_DATA i z LOAD_DATA=seed cel działa jak dziś (istniejące testy skryptu przechodzą bez zmian).",
        "Nieznana wartość LOAD_DATA kończy się kodem 2 przed utworzeniem klastra.",
        "Dla profilu innego niż seed brak pliku z danymi puli zdjęć kończy się kodem 2 przed utworzeniem klastra; wartości DEMO_PHOTOS_* nie pojawiają się w wyjściu, w linii poleceń ani w LOAD_OUT, a token zapisu nie trafia do klastra (test na atrapach).",
        "Job generatora zakończony błędem albo po czasie kończy cel kodem 2 z logiem Joba, klaster usunięty (testy jak istniejące ścieżki load).",
        "Stany rosną tylko dla wariantów produktów z wylosowanych porcji, a k6 dostaje klucze tych porcji; brak podniesionych stanów kończy cel kodem 2.",
        "LOAD_OUT zawiera czasy kroków generatora oraz rozmiary tabel i indeksów.",
        "Dokumentacja z zakresu opisuje LOAD_DATA i CronJob demo-data; żaden tekst nie przedstawia pełnej skali jako zweryfikowanej (W3)."
      ],
      "risk": "CRITICAL",
      "depends_on": ["DEMO-LOAD-1", "DEMO-LOAD-3"],
      "paths": [
        "infra/k8s/scripts/",
        "infra/k8s/tests/",
        "infra/k8s/Makefile",
        "infra/k8s/README.md",
        "infra/k8s/CLAUDE.md",
        "docs/features/hardening/spec.md",
        "load/README.md"
      ],
      "parallel_with": ["DEMO-LOAD-2"]
    },
    {
      "id": "DEMO-LOAD-5",
      "title": "Zapas miejsca przed generowaniem i w trakcie (W2)",
      "scope": "infra/k8s/scripts/load.sh i jego testy (spec: W2; „k3d”, „Zapas miejsca (W2)”; kryterium 11, część o zapasie miejsca). diskPeakGb profilu z apps/api/fixtures/demo-data/profiles.yaml; sprawdzenie wolnego miejsca w VM Dockera i na dysku hosta (ścieżka z opcji, domyślnie katalog domowy) przed utworzeniem klastra; kontrola co 30 s w trakcie. Dokumentacja: komentarz celu i runbook (opcja ścieżki, kody wyjścia).",
      "acceptance_criteria": [
        "Dla LOAD_DATA medium, server i full cel odmawia startu kodem 2, gdy VM albo host ma mniej niż 1,25 × diskPeakGb (test także dokładnie na granicy minus 1 GB), z wymaganym i dostępnym miejscem w komunikacie; klaster nie powstaje.",
        "Gdy w trakcie wolne miejsce w VM albo na hoście spada poniżej 10% diskPeakGb, cel zatrzymuje Job generatora i k6, usuwa klaster i kończy się kodem 2 (miejsce podawane przez podmienione polecenie w teście).",
        "LOAD_DATA=seed nie sprawdza miejsca i działa jak dziś.",
        "Błąd odczytu wolnego miejsca (polecenie zawodzi albo odpowiada czymś innym niż liczba) kończy cel kodem 2, nie jest traktowany jako wystarczające miejsce."
      ],
      "risk": "STANDARD",
      "depends_on": ["DEMO-LOAD-4"],
      "paths": [
        "infra/k8s/scripts/load.sh",
        "infra/k8s/tests/",
        "infra/k8s/Makefile",
        "infra/k8s/README.md"
      ],
      "parallel_with": []
    },
    {
      "id": "DEMO-LOAD-6",
      "title": "Przebieg LOAD_DATA=medium na k3d i kalibracja",
      "scope": "Przebieg na komputerze użytkownika i wynikające z niego liczby (spec: kryterium 12; W3; „Wymagane przed startem”, część 2). make -C infra/k8s load LOAD_DATA=medium LOAD_OUT=<katalog poza repo>; uaktualnienie diskPeakGb i limitów czasu Joba według pomiaru; docs/vision.md („Skala docelowa”: pomiar na danych w skali i obowiązkowy pomiar full przed optymalizacjami i wdrożeniem na serwer); spec.md: stan części 2 i skalibrowane szacunki. Nie obejmuje pomiaru full ani raportu w docs/reports (krok po epiku).",
      "acceptance_criteria": [
        "Przebieg LOAD_DATA=medium kończy się kodem 0 na k3d na komputerze użytkownika.",
        "Opis PR podaje: tempo ładowania (wiersze/s na tabelę), czas kont i przebudowy indeksu, rozmiary, szczyt pamięci Postgresa i Joba, uaktualnione diskPeakGb oraz ekstrapolację dla server i full opisaną jako niezweryfikowana.",
        "diskPeakGb i limity czasu Joba w repo odpowiadają pomiarowi medium; wartości server i full są oznaczone jako ekstrapolacja.",
        "docs/vision.md („Skala docelowa”) i spec.md opisują wyniki medium jako medium; żaden dokument ani PR nie przedstawia pełnej skali jako zweryfikowanej (W3).",
        "make -C infra/k8s check i make -C apps/api check przechodzą po zmianie wartości."
      ],
      "risk": "STANDARD",
      "depends_on": ["DEMO-LOAD-2", "DEMO-LOAD-5"],
      "paths": [
        "apps/api/fixtures/demo-data/profiles.yaml",
        "infra/k8s/environments/",
        "infra/k8s/charts/shop/values.yaml",
        "docs/vision.md",
        "docs/features/demo-data/spec.md"
      ],
      "parallel_with": []
    }
  ]
}
```
