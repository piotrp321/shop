# `/ao-run`: przebieg epiku przez AO z powiadomieniami w aplikacji Claude

Krok 5 planu [Agentic Software House](../agentic-software-house/plan.md). Skill prowadzi nocny przebieg jednego epiku
przez AO z sesji Claude Code w aplikacji: uruchamia AO, przekazuje zdarzenia jako powiadomienia push do aplikacji Claude,
przyjmuje odpowiedzi na blokery i kończy raportem odbioru. Kod AO się nie zmienia.

## Wejście i warunki startu

`/ao-run <epik>`, gdzie `<epik>` to katalog planu w `docs/features/<epik>/` (np. `cart-fixes`).

Skill odmawia startu i mówi dlaczego, gdy:

- `docs/features/<epik>/spec.md` albo `plan.md` nie ma na `origin/main` (plan wiążący to plan na `origin/main`);
- checkout AO (`$HOME/Development/claude/agentic-orchestrator`) nie jest czysty albo nie jest na `origin/main`;
- `ao epic start --dry-run` kończy się odmową (np. epik już zarejestrowany z innego commita planu);
- sesja nie ma Remote Control albo komputer pracuje na baterii (ostrzeżenie, start dopiero po potwierdzeniu).

## Pliki

- `.claude/skills/ao-run/SKILL.md`: instrukcja dla sesji.
- `.claude/skills/ao-run/ao_events.py` (Python 3.9+, bez zależności) i `test_ao_events.py`:
  - `record <plik>`: czyta zdarzenie AO (JSON ze stdin, format z sekcji „Powiadomienia” README AO) i dopisuje je jako
    jedną linię do `<plik>`; kod wyjścia 0, także przy pustym albo niepoprawnym JSON-ie (zapisuje wtedy linię
    `{"type": "invalid_event", ...}`), żeby AO nie zgłaszało `notify_failed`.
  - `watch <plik>`: czyta nowe linie pliku zdarzeń i dla każdej wypisuje jedną linię komunikatu do 200 znaków,
    np. `cart-fixes: BLOCKED CART-FIXES-2: <pierwsze pytanie>`, `cart-fixes: końcowy PR <url>`; linie bez znaczenia
    dla człowieka pomija.
- Katalog przebiegu `$HOME/.local/state/ao-<epik>/` (`AO_HOME`, poza repo) z `events.jsonl` i dziennikiem `run.log`.

## Przebieg

1. **Przygotowanie.** Sprawdza warunki startu, zakłada `AO_HOME`, uruchamia `ao epic start --dry-run`, potem
   `ao epic start`.
2. **Start.** Uruchamia w tle (Bash w tle sesji) `caffeinate -dims` z `ao run --until-idle` i
   `--notify-cmd "python3 .claude/skills/ao-run/ao_events.py record $AO_HOME/events.jsonl"`, z limitami jak przy CART:
   `--max-budget-usd 30 --timeout 14400 --epic-max-attempts 24 --epic-timeout 57600 --max-qa-rounds 3`.
   Zmienne `CLAUDE*` usuwa ze środowiska AO (jak wrapper z README AO).
3. **Śledzenie.** Monitor sesji na `ao_events.py watch`: każda linia budzi sesję, a ta wysyła powiadomienie push
   (`PushNotification`). Monitor wygasa po 30 minutach, sesja uzbraja go ponownie, dopóki AO działa. Zakończenie
   procesu AO też budzi sesję.
4. **Bloker.** Push z pytaniem. Użytkownik odpowiada w sesji (także z telefonu). Sesja wykonuje
   `ao answer <ID> --text "<odpowiedź>"` z jego słowami i, jeśli `ao run` się zakończył, uruchamia go ponownie.
   Inne zatrzymania (`FAILED`, `epic_blocked`, `queue_held`) sesja diagnozuje przez `ao status` i opisuje w pushu;
   `ao resume`, `ao release` i `ao epic resume` wykonuje tylko na polecenie użytkownika.
5. **Raport odbioru.** Po `epic_done` (AO ustawia go dopiero przy zielonym CI końcowego PR) sesja czyta `ao report --epic <epik>`
   i sekcję „Lista odbioru” z ostatniego raportu `/integration` i zostawia w rozmowie raport odbioru:
   - rekomendacja: „merge” albo „najpierw sprawdź: …” (każda pozycja „Sprawdź:” z listy odbioru, znaleziska bez naprawy,
     ujawnione luki TDD);
   - zakres, zadania i PR, interwencje, czas, koszt;
   - link do końcowego PR i lista `Closes #N` do dopisania (issue epiku i issues, które epik realizuje).
   Push: „`<epik>`: końcowy PR gotowy, rekomendacja: …”. Sesja nigdy nie merguje do `main`.
6. **Pomiar.** Po merge'u sesja pyta o minuty użytkownika (spec, blokery, triage, raport) i po zgodzie dopisuje
   komentarz do issue epiku: minuty, liczba interwencji z `ao report`, liczba ręcznych poprawek.

## Czego skill nie robi

Nie zmienia kodu ani konfiguracji AO, nie merguje do `main`, nie odpowiada na blokery sam, nie uruchamia kilku epików
naraz.

## Testy

`python3 -m unittest discover -s .claude/skills/ao-run -p 'test_*.py'`: `record` (poprawne zdarzenie, pusty
i niepoprawny JSON, brak katalogu), `watch` (każdy typ zdarzenia z README AO, limit 200 znaków, pomijanie zdarzeń bez
znaczenia, linie dopisane w trakcie). CI uruchamia testy skilli tak jak dla `delivery` i `integration`. Sam przebieg
sprawdza krok 7 planu (CART-FIXES).

## Zmiana w planie

`docs/features/agentic-software-house/plan.md`: krok 5 wskazuje `/ao-run`. Odbiór (punkt 5 procesu) to przeczytanie
raportu odbioru i merge; trzy liczby obejmują minuty na spec, blokery, triage i raport. Krok 8 rusza po kroku 7, bo
lista odbioru ma najpierw zadziałać na prawdziwym epiku.
