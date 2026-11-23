# METRICS-ORDERS – plan pierwszego PR

Specyfikacja: [`spec.md`](spec.md). Plan jest dokumentem roboczym: po wdrożeniu znika razem z katalogiem, a opis
zachowania zostaje w `docs/features/metrics/spec.md`, `docs/features/ash-dashboard/spec.md`
i `tools/metrics/README.md`.

## Status

| Krok | Stan |
|---|---|
| Diagnoza, specyfikacja, plan | gotowe, czekają na akceptację |
| Review Codexa, runda 1 | cztery uwagi wprowadzone 2026-11-23 ([`codex-review.md`](codex-review.md), „Po rundzie 1”) |
| Aktualizacja do `main` = `480850f5` (#868) | zrobiona 2026-11-23; dokumenty uzgodnione z nowym stanem |
| Review Codexa, runda 2 | zrobione; jedno doprecyzowanie (oznaczenie `<EPIK>-EPIC` zamiast samej nazwy epiku) wprowadzone 2026-11-23 |
| Decyzje D1–D3, D5, D6 | zatwierdzone przez właściciela 2026-11-23 w zakresie przygotowania zmian i PR-ów; D4 osobno, po wynikach porównania |
| PR 1 (sklep): `tools/metrics` i dokumentacja | w toku |
| PR 1b (`agent-observability`): eksporter i dashboard | w toku |
| PR 2 (proces) | w toku |
| Kontrolowane porównanie parserów | nie zaczęte |
| Odświeżenie rekordów historycznych | nie zaczęte (po zgodzie D4) |

Zapisane rekordy w `~/.local/state/shop-metrics` są bez zmian do decyzji D4. PR-ów nie merguje sesja.

## Zakres PR 1 (repo sklepu)

| Plik | Zmiana |
|---|---|
| `tools/metrics/metrics.py` | `read_native`: linie zleceń i raporty z pozycją w pliku, wznowienia z transkryptu prowadzącego (`SendMessage`: `to`, `summary`, `resumedAgentId`); rozpoznanie identyfikatora (`ref`) osobno od przypisania (`assignment`); `native_fields`: podział na zlecenia, `orders`, `wall_s`, `idle_s`, `by_task` z kluczem `unknown_task`, pozycje `value` na zlecenie; `reviews_of_run`: `task`, `ref`, `assignment` i `mode` według zlecenia |
| `tools/metrics/testkit.py` | budowniczy `Native`: `order` (linia `coordinator`), `queued` (załącznik `queued_command`), `resume` (wywołanie `SendMessage` z `summary` i jego wynik u prowadzącego) |
| `tools/metrics/test_native_orders.py` | nowe testy (niżej) |
| `tools/metrics/test_native.py`, `test_native_process.py` | bez zmian asercji |
| `tools/metrics/README.md` | pola `orders`, `idle_s`, `by_task.orders`, klucz `unknown_task`, reguły granic, identyfikatora i przypisania |
| `docs/features/metrics/spec.md` | „Epik natywny”: „Rola i zadanie (D4)”, tabela pól, „Przeglądy (M4)”, czytane pola transkryptu |
| `docs/features/ash-dashboard/spec.md` | „Kontrakt z METRICS” i lista paneli |
| `docs/adr/agentic-software-house.md` | krok 2: zdanie o `summary` wiadomości wznawiającej (D3) |
| `docs/features/metrics-orders/` | usunięty w tym PR |

`tools/metrics/config.json` (cennik, progi `ci_time`) się nie zmienia; zmiana tego pliku w PR zatrzymuje porównanie parserów.

Poza zakresem: rekordy AO, `process_version.py`, reguły `decide` i `effect`, wydzielenie czasu pracy modelu,
własne klucze zadań spoza planu (D2), zmiany agentów i pozostałe zmiany procesu (osobny PR:
[`process-proposal.md`](process-proposal.md)).

## Zakres PR 1b (`piotrp321/agent-observability`)

| Plik | Zmiana |
|---|---|
| `exporter/ash_epics_exporter.py` | `ash_epic_attempt_idle_seconds`, `ash_epic_task_orders`, `ash_epic_order_wall_seconds`, `ash_epic_order_value_usd`, `ash_epic_order_started_timestamp_seconds`; rekord bez `orders` to brak danych; przegląd z `mode: null` jako osobna wartość etykiety |
| `exporter/test_ash_epics_exporter.py` | testy nowych metryk, pola o złym typie, rekordu bez `orders`, dwóch zleceń jednego przebiegu z tym samym zadaniem (różne etykiety `order`), klucza `unknown_task`, zlecenia `outside_plan` z `ref`, przeglądu z `mode: null` |
| `grafana/build_dashboards.py` i zbudowany JSON | kolumna „Odstępy” w „Przebiegach subagentów”, tabela „Zlecenia”, kolumna „Zlecenia” i etykieta „zadanie nieustalone (wznowienie bez ID)” w „Zadaniach”, wiersz „tryb nieustalony” w przeglądach, opisy „czas realizacji zleceń” |
| `README.md` | lista metryk |

PR 1b powstaje równolegle i jest mergowany zaraz po PR 1. Do tego czasu dashboard działa na starym eksporterze:
nieznane pola pomija, a `wall_s` i `by_task.work` pokazuje już poprawione.

## Kolejność pracy

Jeden PR w sklepie, trzy commity logiczne, każdy z testami przed kodem.

1. **Zlecenia i czas.** Granice (tabela w specyfikacji), `orders` z czasem, `wall_s`, `idle_s`, `by_task.work`
   i `orders`, `integration.work_s`. Kryteria 1 (czas), 2, 4, 5.
2. **Identyfikator, przypisanie, tokeny, wartość, przeglądy.** `ref` i `assignment` dla startu i wznowienia,
   `unknown_task`, wiadomości na zlecenie, `value.by_task`, `value.by_task_role`, pola przeglądów, `retries.fix`.
   Kryteria 1 (reszta), 3, 6, 7, 8, 9.
3. **Zgodność i dokumentacja.** Rekord bez `orders` i rekord z `unknown_task` w `trend`, `decide`, `effect`,
   `render`; README, specyfikacje, zdanie w ADR. Kryterium 10.

Potem kontrolowane porównanie parserów (kryterium 11), PR 1b (kryterium 12) i, po zgodzie D4, odświeżenie
rekordów.

Ryzyko `STANDARD`: przegląd `code-reviewer` w trybie FULL. Zmiana dotyczy wyniku, który narzędzie zapisuje i który
jest używany dalej, więc według kroku 6 „Przebiegu epiku” `qa-adversarial` sprawdza na kopii stanu komendy `epic`,
`trend`, `decide`, `effect` i `render` oraz eksporter jako konsumenta rekordu.

## Testy RED → GREEN

Testy są syntetyczne (`testkit.Native`), bez treści prawdziwych transkryptów. Każdy powstaje i jest uruchamiany
przed kodem, który go spełnia; raport podaje komendę i wynik obu uruchomień.

Komendy:

```bash
python3 -m unittest discover -s tools/metrics -p 'test_native_orders.py'
```

```bash
python3 -m unittest discover -s tools/metrics -p 'test_*.py'
```

```bash
make check
```

**Granice, czas, sumy**

| Test (`test_native_orders.py`) | Co sprawdza | RED przed zmianą |
|---|---|---|
| `test_reviewer_resumed_for_another_task` | reprodukcja: zlecenia 0–554 s, 891–1 057 s, 10 556–11 028 s, 11 930–12 226 s; `summary` `DEMO-1`, `DEMO-QA1`, `DEMO-QA1`; cztery `orders`, `wall_s` 1 488, `idle_s` 10 738, `by_task["DEMO-1"].work` bez zleceń QA1 | `wall_s` 12 226, brak `orders` |
| `test_value_and_tokens_follow_the_order` | wartość i tokeny zleceń QA1 są w `no_task`, suma zleceń równa się przebiegowi, suma kluczy `value.by_task` równa się `value.total`, a `value.total` jest jak w rekordzie bez podziału | wartość pod `DEMO-1` |
| `test_one_order_gives_the_record_of_today` | przebieg bez wznowień: pola poza `orders`, `idle_s` (0) i `by_task.orders` równe dzisiejszym | brak `orders` |
| `test_message_while_working_does_not_split` | `queued_command` w środku zlecenia: jedno zlecenie | brak `orders` |
| `test_coordinator_line_without_report_or_resume_does_not_split` | jedna pozycja `orders`, pozycja w `gaps` | brak `orders` |
| `test_resume_without_report_is_marked` | `boundary: "resume_unreported"`, pozycja w `gaps` | brak `orders` |
| `test_repeated_lines_count_once` | powtórzony `message.id` w zleceniu i w kopii sesji, powtórzone wywołanie `SendMessage` we wznowionej sesji prowadzącej: tokeny i liczba zleceń bez zmian | brak `orders` |
| `test_order_without_timestamps_is_incomplete` | `wall_s: null` zlecenia, `incomplete` przebiegu i wpisu `by_task`, pozycja w `gaps`, nie zero | brak `orders` |
| `test_order_outside_the_epic_window` | zlecenie po merge'u pominięte, przecięte liczy linie z przedziału | brak `orders` |
| `test_resumed_integration_reviewer` | `integration.work_s` bez odstępu między zleceniami | odstęp w `work_s` |

**Identyfikator i przypisanie**

| Test | Co sprawdza | RED przed zmianą |
|---|---|---|
| `test_resume_to_another_task_without_id_is_unknown` | zlecenie `DEMO-1`, potem wznowienie z `summary` bez ID (w teście dotyczy innego zadania): drugie zlecenie `assignment: "unknown"`, `task` i `ref` `null`; jego czas, tokeny i wartość pod `unknown_task`, nie pod `DEMO-1` ani w `no_task`; `value.total` bez zmian; pozycja w `gaps` | całość pod `DEMO-1` |
| `test_resume_missing_in_the_lead_transcript_is_unknown` | granica `resume_unconfirmed` (także przy nieczytelnym transkrypcie prowadzącego): `assignment: "unknown"`, pozycja w `gaps` | całość pod zadaniem opisu |
| `test_first_order_of_a_task_outside_the_plan_and_its_next_round` | reviewer uruchomiony z opisem `DEMO-QA1 code-reviewer FULL`, raport, wznowienie z `summary` z `DEMO-QA1`, raport: oba zlecenia `outside_plan` z `ref` `DEMO-QA1`; przeglądy `FULL` i `DELTA` z `ref`; przekroje w `no_task`; brak klucza `DEMO-QA1` w `by_task` | brak `orders` i `ref` |
| `test_ref_is_the_first_identifier_not_the_first_number` | opis `DEMO-QA1 … po DEMO-1`: `ref` `DEMO-QA1`, `assignment: "outside_plan"`, nic pod `DEMO-1` | przebieg pod `DEMO-1` |
| `test_plan_task_is_read_the_same_at_launch_and_resume` | `DEMO-2` w `description` i w `summary` (także małymi literami): `assignment: "plan"`, `task` i `ref` `DEMO-2` | brak `orders` |
| `test_summary_with_the_epic_marker_is_no_task` | wznowienie reviewera całości z `summary` „DEMO-EPIC qa-adversarial DELTA” (także małymi literami): `assignment: "no_task"`, przegląd `DELTA`; to samo oznaczenie w `description` daje `no_task` | brak `orders` |
| `test_summary_with_only_the_epic_name_is_unknown` | wznowienie z `summary` „DEMO: popraw uwagi” i „DEMO qa-adversarial DELTA”: `assignment: "unknown"`, przekroje w `unknown_task`, pozycja w `gaps` | całość pod zadaniem opisu |
| `test_launch_without_id_stays_no_task` | opis bez ID: `assignment: "no_task"`, przekroje jak dziś | brak `orders` |
| `test_reviews_take_the_assignment_of_their_order` | reprodukcja: `FULL`, `DELTA`, `FULL`, `DELTA`; `task` dwóch ostatnich `null`, `ref` `DEMO-QA1`; raport w zleceniu `unknown` ma `mode: null` i pozycję w `gaps`; `retries.fix` liczy tylko przeglądy z zadaniem planu, z pozycją „dolna granica” przy blokującym przeglądzie `unknown` | dwa ostatnie: `DEMO-1`, `DELTA` |

**Zgodność**

| Test | Co sprawdza | RED przed zmianą |
|---|---|---|
| `test_record_holds_no_text_of_summaries_or_orders` | brak `TAJNE-…` w rekordzie z `orders`, `ref` i `unknown_task` | brak `orders` |
| `test_unknown_task_is_no_sample_of_plan_tasks` | `trend`, `decide`, `effect` i wąskie gardła dają ten sam wynik dla rekordu z `unknown_task` i zleceniem `outside_plan` co dla rekordu bez nich | test ochronny |
| `test_record_without_orders_still_renders` | `trend`, `decide`, `effect`, `render` na rekordzie bez `orders` | test ochronny |

Dwa testy ochronne nie mają fazy RED: mają przechodzić przed zmianą i po niej, a raport powie to wprost. Test
„jedno zlecenie” porównuje z wartościami pól rekordu zbudowanego przed zmianą, zapisanymi w teście.

## Kontrolowane porównanie starego i nowego parsera

Cel: pokazać efekt samej zmiany parsera. Stary i nowy parser działają na tych samych, zamrożonych wejściach,
w tych samych warunkach, na osobnych kopiach stanu. Nic nie jest zapisywane do `~/.local/state/shop-metrics`.

Dlaczego nie porównanie z zapisanymi rekordami: zapisany rekord różni się od przeliczenia tym samym narzędziem
z `--no-gh` w `gaps` (12–15 pozycji w każdym z ośmiu rekordów) i w `process.loaded.checked_at` (`spec.md`, fakt 14).

### Zamrożone wejścia

Katalog `F` w katalogu roboczym sesji. `BASE` to `git merge-base` gałęzi PR i `origin/main` (stary parser), `HEAD`
to czoło gałęzi PR (nowy).

| Wejście | Jak zamrożone |
|---|---|
| transkrypty | `cp -Rc ~/.claude/projects "$F/projects"`: klon APFS całego katalogu (potrzebny w całości, bo `subscription.share` czyta wszystkie sesje lokalne z przedziału epiku) |
| pliki przebiegów i początkowe rekordy | `cp -R ~/.local/state/shop-metrics "$F/state0"` |
| konfiguracja i cennik | `tools/metrics/config.json` z `BASE`, skopiowany do `$F/config.json` |
| stary parser | `git archive "$BASE" \| tar -x -C "$F/old"` (całe drzewo, bo `metrics.py` importuje moduły spoza `tools/metrics`) |
| nowy parser | `git archive "$HEAD" \| tar -x -C "$F/new"` |

Po zamrożeniu `chmod -R a-w` na `projects`, `state0` i `config.json`. Lista sum kontrolnych (`shasum`) plików
`state0`, `config.json` i transkryptów sesji z plików przebiegów jest zapisana przed pierwszym przebiegiem
i sprawdzona po ostatnim. Gdy klon APFS nie jest dostępny, kopiowane są tylko katalogi projektów sesji z plików
przebiegów; `subscription.share` różni się wtedy od zapisanego, ale tak samo w obu parserach.

### Przebiegi

Trzy przebiegi, każdy na własnej kopii `state0`: `old` i `old2` starym parserem, `new` nowym. Ta sama maszyna,
ten sam Python, po kolei, epiki w kolejności `started_at`. Komenda różni się tylko ścieżką narzędzia i stanu:

```bash
SHOP_METRICS_AO_ROOT="$F/ao-root" python3 "$F/<old|new>/tools/metrics/metrics.py" epic <epik> --state "$F/state-<przebieg>" --projects "$F/projects" --config "$F/config.json" --worktree <repozytorium> --no-gh
```

- `--no-gh` w każdym przebiegu: bez sieci, a pola z GitHuba (`issue`, `merged_at`, `ci`, `regressions`,
  `found_after_merge`, `acceptance`, w tym `ci.time` i wynik CI commita merge'u z #868) i czas merge'u jako górna granica wiadomości pochodzą z `state0`, takie same
  dla obu parserów.
- `--worktree` wskazuje to samo repozytorium w każdym przebiegu; służy tylko do odczytu treści bazy epiku z gita.
- `SHOP_METRICS_AO_ROOT` wskazuje pusty katalog `$F/ao-root`: komenda `epic` wybiera źródło AO, gdy istnieje
  katalog `ao-<epik>` (`metrics.py:6426`), więc przebieg nie może zależeć od zawartości `~/.local/state`.
- Pozostałe wejścia komendy `epic` dla epiku natywnego to `--state`, `--projects`, `--config`, `--worktree` i czas
  uruchomienia; `--night` i baza AO nie są czytane. Czas uruchomienia obsługuje kontrola niżej.

### Porównania

1. **Kontrola `old` z `old2`.** Wyznacza pola, które różnią się bez zmiany narzędzia. Dziś to tylko
   `process.loaded.checked_at` (próba z 2026-11-23). Te pola są wyłączone z dalszych porównań. Każda inna różnica
   w kontroli oznacza niezamrożone wejście albo niedeterminizm i zatrzymuje pracę.
2. **`old` z `new`: efekt parsera.**
   - **równe:** `value.total`, `by_role`, `by_model`, `by_role_model`; `breakdown`; `human`; `in_attempts`;
     `versions`; `subscription`; `process` poza polami z kontroli; `acceptance`; `ci`; `regressions`;
     `found_after_merge`; `bottlenecks`; `wall`; `transcripts_read`; liczba wpisów `attempts` i `reviews`;
     w każdym przebiegu `tokens`, `value`, `started_at`, `ended_at`, `role`, `model`, `task`; w każdym przeglądzie
     `at`, `verdict`, `blocking`;
   - **nowe pola:** `attempts[].orders`, `attempts[].idle_s`, `by_task[].orders`, `reviews[].ref`,
     `reviews[].assignment`;
   - **różnice dozwolone:** `attempts[].wall_s` tylko w przebiegach wznowionych; `by_task`, `value.by_task`
     i `value.by_task_role` tylko w kluczach, których dotyczą zlecenia przebiegów wznowionych; `integration.work_s`;
     `reviews[].task` i `mode` tylko w raportach przebiegów wznowionych; `retries.fix`;
   - **`gaps`** porównywane jako zbiory: nic nie znika, a dochodzą wyłącznie pozycje o zleceniach (granica
     niepotwierdzona, zadanie nieustalone, tryb nieustalony, dolna granica `retries.fix`).
3. **Niezmienniki w `new`:** suma `orders[].wall_s` = `wall_s`; `wall_s + idle_s` = `ended_at − started_at`; suma
   `orders[].tokens` = `tokens`; suma `orders[].value` = `value` (do 1e-6); suma kluczy `value.by_task` =
   `value.total`; przebieg z jednym zleceniem ma `wall_s` jak w `old`; zlecenie `unknown` nie jest pod zadaniem
   planu ani w `no_task`.
4. **Oczekiwane liczby** (z prototypu, do potwierdzenia): `acf91f0ba8405e393` ma cztery zlecenia o czasach
   z diagnozy; suma `wall_s` w `store-ux-fixes` spada z 10,38 h do około 6,14 h; dziewięć zleceń w ośmiu epikach ma
   `assignment: "unknown"`; trzy przebiegi uruchomione do `QA1`, `CX1` i `CX2` mają `ref` i te same sumy co w `old`.
5. **Komendy pochodne** na `state-old` i `state-new`, każda w obu wersjach narzędzia na swoim stanie: `trend --json
   --no-gh` i `decide <epik>` dają równy wynik; `render <epik>` różni się tylko liniami „Integracja”, „najdroższe
   zadanie” i „FIX”. Pola czasu w ich wyjściu, jeśli są, wyznacza ta sama kontrola `old` z `old2`.
6. Każda różnica spoza list zatrzymuje pracę: przyczyna trafia do opisu PR przed dalszymi krokami. Wynik
   porównania (liczba różnic według pola i epiku) jest w opisie PR.

Różnica między `state0` a `old` (zapisany rekord wobec przeliczenia starym parserem) jest raportowana osobno jako
dryf zapisanych rekordów. Nie jest efektem zmiany i nie wchodzi do kryterium 11.

### Eksporter i ścieżki błędu

- Eksporter z gałęzi PR 1b uruchomiony na `state-new`: `make test` stosu, a w lokalnej Grafanie tabela „Zlecenia”
  dla `store-ux-fixes` pokazuje cztery wiersze reviewera.
- Ścieżki błędu: epik bez pliku przebiegu (kod 2), katalog `--projects` bez transkryptów (rekord zachowuje zapisane
  wartości z pozycją w `gaps`), rekord bez `orders` w eksporterze (brak danych).

## Odświeżenie rekordów historycznych

Osobny krok po merge'u PR 1 i po zgodzie D4, na prawdziwym katalogu stanu. Jego wynik nie jest dowodem
poprawności parsera: tym jest kontrolowane porównanie.

1. kopia zapasowa `~/.local/state/shop-metrics/epics` z datą w nazwie, poza katalogiem stanu;
2. `python3 tools/metrics/metrics.py epic <epik>` dla ośmiu epików natywnych, z `gh`, jak przy zwykłym odświeżeniu;
3. porównanie kopii zapasowej z nowymi rekordami w dwóch częściach: pola z tabeli „Rekord” specyfikacji (efekt
   parsera, oczekiwany jak w `new`) oraz wszystko inne (odświeżenie danych: pola z GitHuba, `gaps`, pola czasu
   i `process`), raportowane osobno i bez przypisywania parserowi;
4. `python3 tools/metrics/metrics.py trend` i sprawdzenie dashboardu.

Transkrypty wszystkich ośmiu epików są na dysku, a `cleanupPeriodDays` w ustawieniach użytkownika to 3650 dni,
więc odświeżenie nie jest pilne. Rekordy AO się nie zmieniają.

## Ryzyka

- **Zmiana formatu transkryptu.** Granica bez potwierdzenia jest oznaczona, a linia bez potwierdzenia nie dzieli
  zlecenia. Najgorszy skutek to powrót do dzisiejszego czasu dla danego przebiegu, z pozycją w `gaps`.
- **`summary` bez ID.** Zlecenie trafia do `unknown_task`, więc koszt nie obciąża błędnego zadania, ale nie jest
  przypisany. W historii to 3,0% czasu zleceń. Zdanie w ADR (D3) usuwa ten brak dla nowych epików.
- **Dwa sposoby liczenia do czasu odświeżenia.** Dashboard i `render` pokazują stare `wall_s` i `by_task` dla
  rekordów nieodświeżonych. Mediany `trend`, `decide` i `effect` od tych pól nie zależą (`spec.md`, fakt 7).
