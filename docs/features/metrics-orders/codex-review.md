# Pakiet do review Codexa: METRICS-ORDERS, runda 2

Review dotyczy dokumentów, nie kodu: implementacja zacznie się po akceptacji. Codex nie uruchamia narzędzi, które
zapisują do `~/.local/state/shop-metrics`, i niczego nie zmienia w repo. Dokumenty są w drzewie roboczym gałęzi
`claude/agent-metrics-analysis-plan-958a75` (worktree `api-input-contract-stage-1-974b89`), niezacommitowane.

## Co przejrzeć

| Dokument | Zawartość |
|---|---|
| [`spec.md`](spec.md) | diagnoza (fakty i hipotezy), proponowane zachowanie, kryteria, decyzje D1–D6 |
| [`plan.md`](plan.md) | zakres PR 1 i PR 1b, testy RED → GREEN, kontrolowane porównanie parserów, odświeżenie rekordów |
| [`process-proposal.md`](process-proposal.md) | propozycja PR 2: P1 i P3 ze zmianami tekstu, P2 bez zmiany |

Stan odniesienia: `main` = `480850f5` (gałąź przewinięta po #868; fakty diagnozy sprawdzone na `708102d6`
i powtórzone na `480850f5`).

## Po rundzie 1

| Uwaga | Wynik | Co się zmieniło | Dowód z weryfikacji |
|---|---|---|---|
| 1. Nieznane zadanie przy wznowieniu | przyjęta | Bez dziedziczenia. Wznowienie bez ID w `summary` i granica bez wznowienia u prowadzącego mają `assignment: "unknown"` i osobny klucz `unknown_task`; tokeny i wartość zostają w sumie epiku. `outside_plan` (znany `ref`) i `unknown` to różne stany. Kryterium 6, testy `…_without_id_is_unknown` i `…_lead_transcript_is_unknown` | 9 z 33 wznowień ma `summary` bez ID (3,0% czasu zleceń); zadania żadnego z nich nie da się ustalić z danych; tekst wiadomości myli się w `ash-v1` (`a79f31a…`), więc nie jest zastępczym dowodem |
| 2. Identyfikator pierwszego zlecenia QA1/CX1 | przyjęta | Rozpoznanie `ref` osobno od przypisania, tak samo dla `description` i `summary`. Wpływ na `reviews[].mode`, `retries.fix` i przekroje opisany w tabelach. Kryterium 7, testy `test_first_order_of_a_task_outside_the_plan_and_its_next_round` i `test_ref_is_the_first_identifier_not_the_first_number` | trzy przebiegi `store-ux-fixes` uruchomione do `QA1`, `CX1`, `CX2`; `task_of` (`metrics.py:3978`) wymaga cyfr po nazwie epiku |
| 3. Potwierdzenie błędu przed poprawką | przyjęta | Usunięta reguła „bez odtworzenia nie naprawia”. Weryfikacja reprodukcją albo dowodem w kodzie; brak reprodukcji nie obala ustalenia; bez dowodu zgłoszenie wyniku i niepewności. Sonda reviewera i zakres testu regresyjnego zostają | W2 z zadania 2 miało ścieżkę w kodzie i było „niepotwierdzone w silnikach Playwright”; `code-reviewer`, punkt 7, już dopuszcza „sposób odtworzenia lub dowód” |
| 4. Kontrolowane porównanie parserów | przyjęta | Stary i nowy parser na zamrożonych wejściach, osobne kopie stanu, `--no-gh` w obu, kontrola stary–stary dla pól czasu, odświeżenie rekordów jako osobny krok | zapisane rekordy różnią się od przeliczenia tym samym narzędziem w `gaps` (12–15 pozycji) i `process.loaded.checked_at`; dwa przeliczenia tym samym narzędziem różnią się tylko tym polem czasu |

Poprawki własne przy okazji, do sprawdzenia:

- Pierwsza wersja twierdziła, że zmiana wpływa na mediany `trend`, `decide` i `effect` oraz na wąskie gardła. To
  było niesprawdzone założenie. Te reguły wybierają zadania po `by_task[].kind`, które w rekordzie natywnym ma
  `null` (`spec.md`, fakt 7). Zmienione: uzasadnienie D2 i D4, lista pól równych w porównaniu (`bottlenecks`
  dochodzi do równych), ryzyka w planie.
- Liczba wznowień to 33, nie 32; liczby o `summary` są policzone dla wznowień, bez wiadomości w trakcie pracy.
- W2 w `process-proposal.md` jest opisane jako ustalenie z dowodem w kodzie, nie jako naprawa bez podstaw.

Po aktualizacji gałęzi do `main` = `480850f5` (#868, zmiany procesu ASH z innej sesji):

- #868 nie zmienia czytania transkryptów epiku natywnego; numery linii w dokumentach są z `480850f5`, a próba
  z faktu 14 została powtórzona narzędziem z tego commita z tym samym wynikiem.
- #868 dodał do „Oceny przed odroczeniem” podstawę oceny i scenariusz dla QA (O6, O7), z przykładem „stara kasa
  dostanie 409”. P2 zostaje bez zmiany, ale z nowym uzasadnieniem: przypadek zamknął #868.
- Zdania kroku 4 o zleceniu poprawek i zleceniu przeglądu oraz pliki agentów `developer` i `code-reviewer` są bez
  zmian, więc zmiany 1–4 z P1 i P3 odnoszą się do aktualnego tekstu.

## Źródła do sprawdzenia faktów (tylko odczyt)

- Kod: `tools/metrics/metrics.py` (`read_native` 3879, `task_of` 3978, `native_fields` 4080, `reviews_of_run` 4192,
  `line_mark` 3865; wybór zadań po `kind`: 4822–4831, 4995, 5344, 5372; `per_task_sources` 5546),
  `tools/metrics/testkit.py` (`Native`), `docs/features/metrics/spec.md` („Epik natywny”),
  `docs/features/ash-dashboard/spec.md` („Kontrakt z METRICS”).
- Eksporter: `~/Development/claude/agent-observability/exporter/ash_epics_exporter.py` (`_attempts`, `by_task`,
  przeglądy), `grafana/build_dashboards.py` (tabele „Zadania”, „Przebiegi subagentów”, przeglądy).
- Rekordy i przebiegi: `~/.local/state/shop-metrics/epics/*.json`, `~/.local/state/shop-metrics/runs/*.json`
  (osiem epików natywnych).
- Transkrypty: `~/.claude/projects/*/<sesja>.jsonl` i `<sesja>/subagents/` sesji z plików przebiegów. Dla
  reprodukcji: sesja `2791e97b-b103-4712-93ea-9ba381bb0470`, reviewer `agent-acf91f0ba8405e393.jsonl`, wykonawca
  zadania 2 `agent-a635f9d3505053d22.jsonl`, wykonawca QA1 `agent-a653ae9a37e9e955b.jsonl`.
- Instrukcje: `docs/adr/agentic-software-house.md` („Przebieg epiku”, kroki 2 i 4), `.claude/agents/developer.md`,
  `.claude/agents/code-reviewer.md`.
- Próbę z faktu 14 można powtórzyć na kopii: `cp -R ~/.local/state/shop-metrics <kopia>`, potem
  `python3 tools/metrics/metrics.py epic <epik> --state <kopia> --no-gh` dwa razy na dwóch kopiach.

## Pytania

**Metryki**

1. Czy reguła granicy zlecenia (linia `coordinator` potwierdzona wznowieniem u prowadzącego albo raportem) jest
   poprawna dla wszystkich wznowień w transkryptach ośmiu epików? Czy istnieje przypadek, który dzieli zlecenie
   błędnie albo nie dzieli go wcale: przerwany subagent, subagent uruchomiony przez subagenta, epik wznowiony
   w nowej sesji, sesja rozgałęziona?
2. Czy tabela przypisań jest zupełna i jednoznaczna dla startu i wznowienia? Czy asymetria (start bez ID to
   `no_task`, wznowienie bez ID to `unknown`) jest uzasadniona zapisem kroku 2 „Przebiegu epiku”, czy start bez
   ID też powinien być nieustalony?
3. Rozstrzygnięte po rundzie 2: sama nazwa epiku w `summary` niczego nie oznacza (`unknown_task`); przegląd całej
   zmiany oznacza wyłącznie `<EPIK>-EPIC`.
4. Czy definicja identyfikatora (litery i cyfry z co najmniej jedną cyfrą, pierwsze wystąpienie) daje dla
   wszystkich 157 opisów i 33 `summary` z historii wynik zgodny z treścią zlecenia?
5. `reviews[].mode: null` dla raportu w zleceniu `unknown` i `retries.fix` jako dolna granica: czy to właściwy
   sposób pokazania niepewności, czy jest konsument, który potraktuje `null` jak `FULL` albo zero?
6. D1: czy poprawienie znaczenia `attempts[].wall_s` jest bezpieczniejsze niż nowe pole? Czy jest konsument,
   który zakłada dawne znaczenie i nie jest wymieniony w fakcie 7?
7. Czy fakt 7 jest prawdziwy w całości: żadna reguła `trend`, `decide`, `effect` ani wąskich gardeł nie czyta
   w rekordzie natywnym `attempts[].wall_s`, `by_task`, `value.by_task` ani `reviews[].task`?
8. Kontrolowane porównanie: czy zamrożone wejścia obejmują wszystko, co czyta `metrics.py epic` dla epiku
   natywnego? Czy jest wejście poza `--projects`, `--state`, `--config`, `--worktree`, `SHOP_METRICS_AO_ROOT`,
   czasem uruchomienia i kodem narzędzia (inna zmienna środowiska, stan gita), które może się różnić między
   przebiegami?
9. Czy listy „równe”, „nowe pola”, „różnice dozwolone” i reguła dla `gaps` są kompletne i dość ścisłe, żeby
   wykryć podwójne albo zgubione tokeny i błędne przypisanie?
10. Czy testy z planu pokrywają niepełne granice, duplikaty, wiadomości w trakcie zlecenia i wszystkie wiersze
    tabeli przypisań? Którego przypadku brakuje?
11. Czy kontrakt z dashboardem (stare metryki z poprawionymi wartościami, nowe metryki zleceń, `unknown_task`,
    `mode: null`, rekord bez `orders` jako brak danych) nie tworzy kolizji etykiet ani zera w miejscu braku danych?

**Proces**

12. Czy nowy tekst zmian 1 i 2 (P1) realizuje zasadę „weryfikacja reprodukcją albo dowodem w kodzie, brak
    reprodukcji nie obala, bez dowodu zgłoszenie niepewności” bez sprzeczności z punktami 6 i 7 agenta `developer`,
    punktem 7 agenta `code-reviewer` i „Oceną przed odroczeniem”? Czy coś się powtarza?
13. P2: czy podstawa oceny i scenariusz dla QA z #868 (O6, O7) w pełni zamykają przypadek N2, czy zostaje luka,
    której ta propozycja powinna dotyczyć?
14. P3: czy reviewer z narzędziami tylko do odczytu może rzetelnie potwierdzić RED w transkrypcie wykonawcy
    i jaki jest koszt tej kontroli?
15. Czy oceny P1–P3 zgadzają się z przebiegiem zadania 2, CX2/N2 i QA1? Który cytat albo czas jest nieścisły?

## Zlecenie do wklejenia

```text
Zrób drugą rundę niezależnego review dokumentów docs/features/metrics-orders/ (spec.md, plan.md,
process-proposal.md) w repo sklepu. Pliki są niezacommitowane w worktree api-input-contract-stage-1-974b89
(gałąź claude/agent-metrics-analysis-plan-958a75), stan odniesienia main 480850f5. To review planu przed
implementacją: nie zmieniaj plików i nie uruchamiaj komend zapisujących do ~/.local/state/shop-metrics.

1. Sprawdź, czy cztery uwagi z rundy 1 są zamknięte tak, jak opisuje tabela „Po rundzie 1” w codex-review.md.
   Dla każdej: zamknięta, częściowo, niezamknięta, z dowodem.
2. Sprawdź fakty diagnozy (spec.md, fakty 1–14) na źródłach z codex-review.md. Wskaż każdy fakt, który się nie
   potwierdza, i każdą hipotezę podaną jako fakt. Osobno potwierdź albo obal fakt 7 i fakt 14.
3. Oceń proponowane zachowanie i plan PR 1: pytania 1–11, każda uwaga z dowodem i wagą.
4. Oceń propozycję procesu: pytania 12–15. Dla P2 napisz wprost, czy zgadzasz się, że po #868 ta propozycja nie
   musi niczego dodawać.
5. Sprawdź spójność między trzema dokumentami: nazwy pól, stany przypisania, numery kryteriów, decyzje D1–D6.
6. Wskaż decyzje D1–D6, w których rekomendacja jest błędna albo niepełna.

Wynik: lista uwag (blokujące przed implementacją, do poprawy w planie, drobne) i lista pytań otwartych.
```
