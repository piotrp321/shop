# Praca równoległa: zadania fali naraz (19b)

Krok 19 planu [Agentic Software House](../agentic-software-house/plan.md). Naraz idą zadania jednej fali, które plan
dopuścił do pracy równoległej (pole `parallel_with`); ile z nich pójdzie naraz, rozstrzyga sesja prowadząca epik
według izolacji i zasobów ([ADR](../../adr/agentic-software-house.md), „Przebieg epiku”, krok 3). Zadanie bez
`parallel_with` idzie szeregowo.

Miara główna: czas zegarowy epiku. Pilnujemy przy tym jakości, liczby napraw, interwencji człowieka i zużycia limitów.

## Manifest planu

- **Pola zadania** (`/planner`, `plan_manifest.py`), oba opcjonalne:
  - `paths`: niepusta lista ścieżek względem katalogu głównego repo, które zadanie zmieni: plik albo katalog
    zakończony `/` (bez ścieżek absolutnych, segmentów `.` i `..`, znaków globa `*?[]{}`, `~` na początku
    i powtórzeń; pierwszy segment istnieje w katalogu głównym repo, z tą samą wielkością liter, bez `.git`). Plik,
    który zadanie na pewno zmieni (np. `spec.md` epiku, `CLAUDE.md`), `/planner` wpisuje jako plik; katalog tylko dla
    plików nieznanych z góry, a przy niepewności szerszy katalog.
  - `parallel_with`: ID zadań, z którymi zadanie może trwać naraz. `/planner` wpisuje tu wynik `plan_manifest.py
    parallel --blocked`: wszystkie pary dopuszczone przez regułę niżej. Wynik z `--blocked` to
    `{"parallel_with": {ID: [ID]}, "blocked": [...]}`; `blocked` (`{"tasks": [A, B], "paths": [...]}`)
    wymienia pary zadań jednej fali, które obie mają `paths`, a nie mogą iść naraz, bo reguła poniżej zwraca niepustą
    część wspólną (`paths`); kolejność: fala, potem plan; brak par to `[]`.
    Bez `--blocked` wynik to sama mapa `{ID: [ID]}`.
- **Podział planu** (`/planner`, `.claude/skills/planner/SKILL.md`, kroki 2 i 6) maksymalizuje bezpieczną
  równoległość: zadania tnie po rzeczywistych granicach odpowiedzialności, `paths` i zależnościach, a zadania
  z rozłącznymi `paths` i bez zależności są w jednej fali z `parallel_with`. `depends_on` tylko przy zależności od
  kodu, kontraktu, migracji lub danych innego zadania. Para z `blocked` to wada planu: planner zmienia podział po
  granicach odpowiedzialności albo zostawia parę szeregowo z powodem w sekcji „Praca szeregowa” planu. Kodu nie dzieli
  się na moduły wyłącznie dla równoległości; wydzielenie kilku rzeczywistych odpowiedzialności z jednego pliku może
  być pierwszym zadaniem, z uzasadnieniem odpowiedzialnościami w `scope`. Sekcji „Praca szeregowa” walidator nie
  sprawdza; para z `parallel_with` i wspólnymi ścieżkami jest odrzucana (reguła pary niżej).
  Zadanie bez `parallel_with` (także cały manifest bez tych pól) idzie szeregowo; `schema_version` zostaje 1.

## Reguła pary

`plan_manifest.py validate` odrzuca manifest, który ją łamie. Relacja jest symetryczna (każde zadanie pary wymienia
drugie), oba zadania mają `paths`, żadne nie zależy od drugiego bezpośrednio ani przez `depends_on` innych zadań,
a część wspólna ich `paths` (dla każdej pary ścieżek, z których jedna leży w drugiej, węższa z nich) nie zawiera kodu
wykonywanego (`plan_manifest.is_executable_code`), `CLAUDE.md` ani pliku.

- **Kod aplikacji** (`is_app_code`): ścieżka w `apps/<aplikacja>/src` albo `apps/<aplikacja>/app` (po segmentach)
  i ścieżka, która taki katalog zawiera (`apps`, `apps/<aplikacja>`), bo deklaracja szersza niż kod obejmuje kod.
  Kodem aplikacji nie są pliki generowane (`apps/api/openapi.json`, `apps/api/openapi-admin.json`,
  `apps/store/src/api/schema.d.ts`, `apps/admin/src/api/schema.d.ts`), pliki rejestracji, do których zadania
  dopisują pozycje (`apps/admin/src/layout/AppMenu.vue`, `apps/admin/src/router/index.ts`), każdy `CLAUDE.md`
  i `README.md`.
- **Kod wykonywany** to kod aplikacji oraz ścieżki w katalogach `tools`, `.claude/skills`, `.ao`, `.github/scripts`
  i `infra/scripts` (po segmentach, bez rozróżniania wielkości liter) i ścieżki, które taki katalog zawierają
  (`.claude`, `.github`, `infra`; tak jak `apps` zawiera kod aplikacji); pliki generowane i rejestracji, `CLAUDE.md`
  i `README.md` kodem nie są.
- Ścieżka części wspólnej jest katalogiem tylko wtedy, gdy obie strony deklarują ją i każdą ścieżkę, która ją
  zawiera, jako katalog. Dwa nakładające się katalogi poza kodem wykonywanym (workflowy CI, testy, konfiguracja,
  `infra/k8s`, dokumentacja, `.claude/agents`) nie blokują, chyba że wspólny katalog zawiera `CLAUDE.md` śledzony
  w repo (`git ls-files`, np. `infra/k8s/` albo `e2e/`); wtedy ten plik blokuje parę.
- Gdy git nie poda listy śledzonych `CLAUDE.md`, `validate` odrzuca każdą parę z `parallel_with`, a `parallel` kończy
  się kodem 2. Brak danych albo niepewność oznacza pracę szeregową.
- Regułę ma jedno miejsce: `plan_manifest.task_conflicts` (`.claude/skills/planner/plan_manifest.py`).

Zmiana reguły to PR do tej specyfikacji i `plan_manifest.py`.

## Pliki

- `.claude/skills/planner/plan_manifest.py`, `test_plan_manifest.py` i `SKILL.md`: pola `paths` i `parallel_with`,
  reguła pary (`plan_manifest.task_conflicts`) i komenda `parallel` (z `--blocked`: także pary zablokowane).
- `tools/ash/parallel_gate.py` i `tools/ash/night_queue.py` (z testami): `parallel_gate.py` importuje klasy plików
  i `task_conflicts` z `plan_manifest.py`; `tools/metrics/metrics.py` czyta przez nie pomiary i dziennik bramki
  (`gate.jsonl`) historycznych przebiegów.
- Dokumentacja: plan Agentic Software House (status kroku 19).

## Testy

- `python3 -m unittest discover -s tools/ash -p 'test_*.py'`: klasy plików i `task_conflicts` w
  `test_parallel_gate.py` (kod aplikacji, także przez szerszy katalog; kod narzędzi: `tools/` z `tools/` i z
  `tools/metrics/`, `.claude/` z `.claude/skills/x/`, `.ao/`, `.github/scripts/`, `infra/` z `infra/scripts/`
  odrzucone, a dwa różne katalogi w `.claude/skills/`, `.github/workflows/`, `.claude/agents/`, `docs/`
  i `infra/k8s/` dozwolone, `toolsx/` z `tools/` dozwolone, `Tools/metrics/` z `tools/metrics/` odrzucone,
  `tools/README.md` nie jest kodem wykonywanym; `CLAUDE.md`, ten sam plik, plik w katalogu drugiej strony, pliki
  generowane i rejestracji, nakładające się katalogi poza kodem dozwolone, wspólny katalog ze śledzonym `CLAUDE.md`
  w obu kierunkach odrzucony, bez niego dozwolony).
- `python3 -m unittest discover -s .claude/skills/planner -p 'test_*.py'`: manifest bez nowych pól poprawny; zły
  typ `paths` i `parallel_with`, ścieżka absolutna, `..`, `.`, pusty segment, znak sterujący, znak globa, `~` na
  początku, pierwszy segment spoza katalogu głównego (także inna wielkość liter i `.git`), powtórzenie; nieznane ID,
  samo siebie, powtórzenie w `parallel_with`; brak symetrii; para bez `paths` po jednej stronie; para z zależnością
  bezpośrednią i przechodnią; wspólny kod aplikacji, ten sam plik dokumentacji, wspólny `CLAUDE.md`, wspólny katalog
  ze śledzonym `CLAUDE.md` (odrzucone), nakładające się katalogi dokumentacji, testów, CI i infrastruktury, także
  z nieśledzonym `CLAUDE.md` (dozwolone); błąd `git ls-files` (każda para odrzucona, `parallel` z kodem 2, manifest
  bez par poprawny); `parallel --blocked`: wspólny plik jednej fali i wspólny kod aplikacji w `blocked`, para
  dozwolona poza `blocked` i w `parallel_with`, zadania z różnych fal i zadanie bez `paths` poza `blocked`, kolejność
  fala-plan, `[]` bez par, wynik z flagą w osobnych kluczach `parallel_with` i `blocked`, wynik bez flagi jako sama
  mapa; `parallel`: lista maksymalna i symetryczna, zadanie bez `paths` z `[]`, wynik wpisany do planu przechodzi
  `validate`, obecne `parallel_with` bez wpływu, odmowa przy innym błędzie manifestu, brak pliku i zły JSON (kod 2).
