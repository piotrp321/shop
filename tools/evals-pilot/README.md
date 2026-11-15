# Pilot ewaluacji `code-reviewer`

Mierzy, czy agent `.claude/agents/code-reviewer.md` znajduje znane wady w prawdziwych diffach zadań i ile fałszywych
alarmów zgłasza. Uruchamia prawdziwego agenta, zapisuje jego raport, a o wyniku decydują etykiety właściciela.
To pilot: nie ma tu progów, werdyktów regresji, joba CI ani testów bezpieczeństwa.

## Przepływ

    przypadek → run.py (agent w sandboksie) → report.md → grade.py extract → grade.py judge → etykiety → grade.py summary

1. `run.py` buduje jednorazowy checkout przypadku: commit bazowy z agentem z `origin/main`, na nim diff zadania.
   Agent działa przez `claude -p --agent code-reviewer` na swojej definicji (model i effort z frontmattera),
   z ustawieniami projektu i profilem sandboxa `tools/agent-security/render_settings.py` bez sieci i Dockera.
2. `grade.py extract` przepisuje raport do listy znalezisk (waga, pliki, tytuł, teza, cytat). Cytat, którego nie ma
   dosłownie w raporcie, dostaje `quote_found: false`.
3. `grade.py judge` pyta model, czy znalezisko opisuje wadę wzorcową z `gold.md` (TAK/NIE/NIE WIEM). To podpowiedź,
   nie wynik.
4. `grade.py table` tworzy `labels.md` (do przeglądu) i `labels.json`. Etykiety wpisuje się do `labels.json`:
   `wada` (wada wzorcowa), `czesciowe` (tylko jej część), `trafne` (inny prawdziwy problem), `falszywe`, `drobne`,
   `nieznane` (nie da się rozstrzygnąć z danych przypadku; nie liczy się ani jako trafienie, ani jako fałszywy alarm);
   `gold_detected` na przebieg z wadą (`true`, `false`, `"partial"`); `by: "owner"` przy etykiecie właściciela. Ponowne `table` zachowuje wpisane etykiety. Każdą ocenę da się zmienić ręcznie w tym pliku.
5. `grade.py summary` liczy tabelę z etykiet i kosztów. Dopóki `approved_by_owner` jest `false`, wynik jest
   propozycją.

## Przypadki

`cases/<id>/`: `case.json` (rodzaj, `base_sha`, `head_sha` squash commitu zadania na gałęzi epiku), `prompt.md`
(zlecenie w formacie `/delivery`), `tdd-evidence.jsonl` (dziennik dowodów TDD zadania), a dla przypadku z wadą
`gold.md` (opis wady ze zgłoszenia).

| Przypadek | Rodzaj | Źródło |
|---|---|---|
| `cart-fixes-3` | wada | zgłoszenie #113 |
| `search-4` | wada | zgłoszenie #197 |
| `order-management-2` | wada | zgłoszenie #256 |
| `catalog-admin-3` | czysty warunkowo (`known.md`: udokumentowany wyścig pierwszej ceny ze zmianą klasy podatkowej) | zadanie CATALOG-ADMIN-3, PR 220 |
| `order-management-1` | czysty | zadanie ORDER-MANAGEMENT-1, PR 231 |

„Czysty” znaczy tylko, że nie znamy wady: pierwsze review dało `PASS` i po merge'u nie było zgłoszeń do plików diffu.
Zgłoszenie `IMPORTANT` w czystym przypadku ocenia właściciel; nie jest z góry fałszywym alarmem.

## Limity

- `--run-cap` (domyślnie 3 USD) idzie do `--max-budget-usd`, więc CLI przerywa przebieg w trakcie.
- `--total-cap` (domyślnie 25 USD): przed każdym wywołaniem modelu skrypt sumuje `ledger.jsonl` i odmawia startu,
  gdy wydane + limit wywołania przekracza limit łączny (kod wyjścia 2).
- Koszt to szacunek CLI według cennika. Przebieg przerwany limitem albo błędem nie dostaje oceny.

## Uruchomienie

Wyniki są poza repo (`--out`), np. `~/.local/state/shop-evals-pilot/<data>`:

    python3 tools/evals-pilot/run.py --out "$OUT" --case cart-fixes-3
    python3 tools/evals-pilot/grade.py extract --out "$OUT"
    python3 tools/evals-pilot/grade.py judge --out "$OUT"
    python3 tools/evals-pilot/grade.py table --out "$OUT"
    python3 tools/evals-pilot/grade.py summary --out "$OUT"

Testy: `python3 -m unittest discover -s tools/evals-pilot -p 'test_*.py'`.
