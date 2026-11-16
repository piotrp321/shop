# Ewaluacja `code-reviewer` (EVALS 2.0)

Mierzy, czy agent `.claude/agents/code-reviewer.md` znajduje znane wady w prawdziwych diffach zadań i ile fałszywych
alarmów zgłasza. Uruchamia prawdziwego agenta, zapisuje jego raport, a o wyniku decydują etykiety właściciela.
Nie ma tu progów, werdyktów regresji, joba CI ani testów bezpieczeństwa. Zasady i stan prac:
`docs/features/evals-2/spec.md` i `plan.md`.

## Przepływ

    przypadek → run.py (agent w sandboksie) → report.md → grade.py extract → grade.py judge → etykiety → grade.py summary

1. `run.py` buduje jednorazowy checkout przypadku i uruchamia agenta (tryby niżej). Agent działa na swojej definicji
   z `origin/main` (model i effort z frontmattera), z ustawieniami projektu i profilem sandboxa
   `tools/agent-security/render_settings.py` bez sieci i Dockera.
2. `grade.py extract` przepisuje raport do listy znalezisk (waga, pliki, tytuł, teza, cytat). Cytat, którego nie ma
   dosłownie w raporcie, dostaje `quote_found: false`.
3. `grade.py judge` pyta model, czy znalezisko opisuje wadę wzorcową z `gold.md` (TAK/NIE/NIE WIEM). To podpowiedź,
   nie wynik.
4. `grade.py table` tworzy `labels.md` (do przeglądu) i `labels.json`. Etykiety wpisuje się do `labels.json`:
   `wada` (wada wzorcowa), `czesciowe` (tylko jej część), `trafne` (inny prawdziwy problem), `falszywe`, `drobne`,
   `nieznane` (nie da się rozstrzygnąć z danych przypadku; nie liczy się ani jako trafienie, ani jako fałszywy alarm);
   `gold_detected` na przebieg z wadą (`true`, `false`, `"partial"`); `by: "owner"` przy etykiecie właściciela.
   Ponowne `table` zachowuje wpisane etykiety. Każdą ocenę da się zmienić ręcznie w tym pliku.
5. `grade.py summary` liczy tabelę z etykiet i kosztów. Dopóki `approved_by_owner` jest `false`, wynik jest
   propozycją.

## Tryby uruchomienia

### `--mode replay`: odtworzenie prawdziwego review

Odtwarza pierwsze review FULL zadania tak, jak zrobił je `/delivery`:

- **Stan repo:** commit, który reviewer faktycznie recenzował (`review_sha`, HEAD gałęzi zadania, osiągalny przez
  `refs/pull/<nr>/head`), na gałęzi `task/<ID>`, z historią do `base_sha` i z `origin/epic/<epik>` ustawionym na bazę.
  Commity nie są przepisywane. Bieżąca definicja agenta jest w drzewie roboczym i ukryta przed gitem
  (`skip-worktree`), więc `git status` jest czysty, a `git diff base..HEAD` to dokładnie diff z tamtego review.
- **Zlecenie:** `cases/<id>/replay/order.md`, czyli zlecenie, które orkiestrator `/delivery` wysłał wtedy do
  `code-reviewer` (z transkryptu AO). Zmienione są tylko ścieżki checkoutu i dziennika TDD oraz dopisana jedna uwaga,
  że stos Dockera nie działa. `order.orig.md` to oryginał, `order.diff` to różnica; test pilnuje, że się zgadzają.
- **Wywołanie:** sesja nadrzędna (`--parent-model`, domyślnie `sonnet`) dostaje stały prompt i wywołuje agenta
  `code-reviewer` narzędziem Agent, tak jak `/delivery`. Raport to wynik subagenta, a nie odpowiedź sesji nadrzędnej.
- **Kontrola wywołania:** przebieg kończy się `infra_error` bez raportu i bez oceny, gdy sesja nadrzędna nie wywołała
  agenta dokładnie raz, użyła innego narzędzia, wywołała inny typ agenta, uruchomiła go w tle, zmieniła treść
  zlecenia albo subagent nie oddał raportu.

`replay.json` przypadku z wadą ma pole `gold_in_reviewed_diff`: dowód, że wada wzorcowa jest w recenzowanym diffie.

### `--mode squash` (domyślny): tryb pilota

Squash commit zadania z gałęzi epiku na commicie bazowym z nałożonym agentem, zlecenie z `prompt.md`, agent jako
wątek główny (`--agent code-reviewer`). W tym trybie powstał raport `docs/reports/evals-2-pilot-code-reviewer-2026-11-15.md`.
Różni się od produkcji bardziej niż `replay` (inny stan repo, zlecenie bez wyników orkiestratora, brak subagenta).

## Różnice wobec produkcyjnego `/delivery`, które zostają w trybie `replay`

- **Brak stosu Dockera i sieci.** W produkcji reviewer ma działający stos i może uruchamiać celowane testy; tutaj
  czyta tylko kod i dziennik dowodów. Zlecenie mówi o tym w dopisanej uwadze.
- **Wersja agenta i zleceń.** Testowany jest bieżący agent na zleceniach napisanych przez ówczesną wersję `/delivery`
  (np. inne nazwy wag w oczekiwanym wyniku).
- **Sesja nadrzędna.** W produkcji to orkiestrator `/delivery` na Opus z kontekstem całego zadania; tutaj krótki
  stały prompt na tańszym modelu. Model subagenta wynika z jego frontmattera i jest zapisany w `meta.json`
  (`subagent_model`).
- **Profil sandboxa.** Ten sam szablon co u wykonawców AO, ale bez sieci, Dockera i tokenu GitHub.
- **Wersja CLI.** Bieżąca, zapisana w `meta.json`; oryginalne review działały na wersji z `replay.json` (`original_cli`).

## Przypadki

`cases/<id>/`: `case.json` (rodzaj, `base_sha`, `head_sha` squash commitu zadania na gałęzi epiku), `prompt.md`
(zlecenie trybu `squash`), `tdd-evidence.jsonl` (dziennik dowodów TDD zadania), `replay/` (dane trybu `replay`),
a dla przypadku z wadą `gold.md` (opis wady ze zgłoszenia).

| Przypadek | Rodzaj | Źródło | Recenzowany commit |
|---|---|---|---|
| `cart-fixes-3` | wada | zgłoszenie #113 | `bbabb283`, PR 107 |
| `search-4` | wada | zgłoszenie #197 | `78ddc299`, PR 184 |
| `order-management-2` | wada | zgłoszenie #256 | `541d06e9`, PR 233 |
| `catalog-admin-3` | czysty warunkowo (`known.md`: udokumentowany wyścig pierwszej ceny ze zmianą klasy podatkowej) | zadanie CATALOG-ADMIN-3 | `1c35b5d4`, PR 220 |
| `order-management-1` | czysty (niepotwierdzony przez właściciela) | zadanie ORDER-MANAGEMENT-1 | `8803554a`, PR 231 |

„Czysty” znaczy tylko, że nie znamy wady: pierwsze review dało `PASS` i po merge'u nie było zgłoszeń do plików diffu.
Zgłoszenie `IMPORTANT` w czystym przypadku ocenia właściciel; nie jest z góry fałszywym alarmem.

## Limity

- `--run-cap` (domyślnie 3 USD) idzie do `--max-budget-usd`, więc CLI przerywa przebieg w trakcie (sprawdzone w trybie
  `squash`: limit 0,10 USD przerwał przebieg przy 0,16 USD). W trybie `replay` limit dotyczy sesji nadrzędnej; tego,
  że wlicza koszt subagenta, nie sprawdzono na prawdziwym przebiegu.
- `--total-cap` (domyślnie 25 USD): przed każdym wywołaniem modelu skrypt sumuje `ledger.jsonl` i odmawia startu,
  gdy wydane + limit wywołania przekracza limit łączny (kod wyjścia 2).
- Przebieg bez znanego kosztu (limit czasu, awaria) jest liczony w `ledger.jsonl` po pełnym limicie przebiegu.
- Koszt to szacunek CLI według cennika. Przebieg przerwany limitem albo błędem nie dostaje oceny.

## Uruchomienie

Każde uruchomienie `run.py`, `grade.py extract` i `grade.py judge` wywołuje model i kosztuje. Wyniki są poza repo
(`--out`), np. `~/.local/state/shop-evals-pilot/<data>`:

    python3 tools/evals-pilot/run.py --mode replay --out "$OUT" --case cart-fixes-3
    python3 tools/evals-pilot/grade.py extract --out "$OUT"
    python3 tools/evals-pilot/grade.py judge --out "$OUT"
    python3 tools/evals-pilot/grade.py table --out "$OUT"
    python3 tools/evals-pilot/grade.py summary --out "$OUT"

Testy (bez modelu): `python3 -m unittest discover -s tools/evals-pilot -p 'test_*.py'`.
