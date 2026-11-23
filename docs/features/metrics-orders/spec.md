# METRICS-ORDERS: zlecenia wznowionego subagenta w rekordzie natywnym

Propozycja do akceptacji, po pierwszej rundzie review Codexa. Dotyczy rekordu epiku natywnego
(`docs/features/metrics/spec.md`, „Epik natywny”) i dashboardu ASH (`docs/features/ash-dashboard/spec.md`). Po
wdrożeniu opis zachowania przechodzi do tych dwóch specyfikacji i do `tools/metrics/README.md`, a ten katalog
znika razem z planem.

Zakres: `tools/metrics`, `docs/features/metrics/spec.md`, `docs/features/ash-dashboard/spec.md`,
`docs/adr/agentic-software-house.md` (jedno zdanie o `summary`, decyzja D3); w `agent-observability`: `exporter/`,
`grafana/`. Bez zmian: dobór modeli, zakres testów, wymagane review, AO i rekordy AO, reguły `decide` i `effect`.

Dokumenty: plan pierwszego PR w [`plan.md`](plan.md), osobna propozycja procesu w
[`process-proposal.md`](process-proposal.md), pakiet do review Codexa w [`codex-review.md`](codex-review.md).

## Diagnoza

Stan sprawdzony 2026-11-23 na `main` = `708102d6` i ponownie po aktualizacji gałęzi do `main` = `480850f5` (#868).
#868 nie zmienia czytania transkryptów epiku natywnego (`read_native`, `task_of`, `native_fields`,
`reviews_of_run`); dodaje pomiar czasu CI (`ci.time`) i wynik CI commita merge'u. Numery linii niżej są z `480850f5`,
a próba z faktu 14 została powtórzona narzędziem z tego commita. Kod, rekordy i transkrypty tylko do odczytu; próby
przeliczenia wyłącznie na kopiach katalogu stanu w katalogu roboczym sesji.

### Fakty

1. **Czas próby to rozpiętość transkryptu.** `native_fields` (`tools/metrics/metrics.py:4148-4154`) liczy `wall_s`
   przebiegu subagenta jako różnicę między ostatnim i pierwszym znacznikiem czasu jego linii w przedziale epiku.
   Odstęp między raportem a wznowieniem jest więc w `wall_s`.
2. **Zadanie pochodzi z pierwotnego opisu.** `task_of` (`metrics.py:3978`) bierze pierwsze `<EPIK>-<cyfry>`
   z `description` w `meta.json` (`metrics.py:4132`). Wznowienie do innego zadania niczego nie zmienia: cały czas,
   tokeny, wartość i raporty przebiegu idą na pierwsze zadanie.
3. **Reprodukcja się zgadza.** Rekord `store-ux-fixes`, przebieg `acf91f0ba8405e393` (`code-reviewer`): `task`
   `STORE-UX-FIXES-1`, `wall_s` 12 225,555 (3 h 24 min), `value` 6,97 USD. W transkrypcie są cztery zlecenia:

   | Zlecenie | Od–do (UTC) | Czas | Wiadomości | Tokeny wejścia i cache | Tokeny wyjścia |
   |---|---|---|---|---|---|
   | zadanie 1, FULL | 16:02:41–16:11:56 | 554 s | 32 | 2 893 165 | 40 016 |
   | zadanie 1, DELTA | 16:17:32–16:20:18 | 166 s | 7 | 996 825 | 9 239 |
   | QA1, FULL | 18:58:37–19:06:29 | 472 s | 20 | 3 763 630 | 26 590 |
   | QA1, DELTA | 19:21:31–19:26:27 | 296 s | 5 | 1 141 662 | 10 697 |

   Razem 1 488 s (24 min 48 s) realizacji i 10 738 s (2 h 59 min) odstępów.
4. **Tokeny nie są liczone podwójnie.** Suma tokenów czterech zleceń równa się tokenom przebiegu w rekordzie
   (wyjście 86 542; wejście i cache 8 795 282). Błąd dotyczy przypisania: zlecenia QA1 to 56% tokenów wejścia
   i cache oraz 43% tokenów wyjścia tego przebiegu, a w rekordzie są pod `STORE-UX-FIXES-1`.
5. **Skutki w rekordzie `store-ux-fixes`.** `by_task["STORE-UX-FIXES-1"].work` = 14 466,567 s (4,0 h przy
   `wall.to_done` 5,15 h). Oba przeglądy QA1 mają `task` `STORE-UX-FIXES-1` i `mode` `DELTA` (pierwszy był
   przeglądem FULL), a `CHANGES_REQUIRED` z QA1 jest liczony w `retries.fix` jako runda poprawek zadania 1.
6. **Skala.** Prototyp podziału na zlecenia (odczyt transkryptów ośmiu epików natywnych, bez zapisu): 157
   przebiegów, 190 zleceń, 25 przebiegów wznowionych, 33 wznowienia. Suma `wall_s` w rekordach 47,7 h, czas
   realizacji zleceń 41,7 h. Zawyżenie: `store-ux-fixes` 4,25 h (10,38 h → 6,14 h), `ash-v1` 0,83 h, pozostałe
   0,00–0,22 h.
7. **Które pola są błędne, a które nie.** Błędne: `attempts[].wall_s`, `by_task`, `value.by_task`,
   `value.by_task_role`, `integration.work_s`, `reviews[].task`, `reviews[].mode`, `retries.fix`; z nich korzystają
   `render` (linie „Integracja”, „najdroższe zadanie”, „FIX”), dashboard i fakty `/retro`. Poprawne:
   `breakdown.work` (linia wznowienia ma znacznik `wait`, `line_mark`, `metrics.py:3865`), `value.total`,
   `value.by_role`, `value.by_model`. Wąskie gardła, `trend`, `decide` i `effect` od błędnych pól w rekordach
   natywnych nie zależą: zadania wybierają po `by_task[].kind` (`metrics.py:4822-4831`, `4995`, `5344`, `5372`),
   które w rekordzie natywnym ma `null`, a `trend` dzieli pola `wall`, `breakdown` i `value.total` przez liczbę
   zadań planu (`metrics.py:5546`). Żaden z ośmiu rekordów natywnych nie ma wąskiego gardła rodzaju `task`.
8. **Granice zleceń są w transkrypcie.** We wszystkich 33 wznowieniach (osiem epików, siedem sesji prowadzących,
   2026-11-18…22):
   - w transkrypcie subagenta wznowienie to linia `user` z `origin.kind = "coordinator"`, zawsze po raporcie
     `SubagentHandback` poprzedniego zlecenia;
   - w transkrypcie sesji prowadzącej wynik wywołania `SendMessage` ma `toolUseResult.resumedAgentId` równe
     identyfikatorowi subagenta, a wywołanie ma pole `summary` (38 z 38 wywołań do subagentów);
   - liczba wznowień po obu stronach jest równa dla każdego subagenta.
9. **Doprecyzowanie w trakcie pracy wygląda inaczej.** Wiadomość do pracującego subagenta (5 przypadków) ma po
   stronie prowadzącego wynik „Message queued for delivery…” bez `resumedAgentId`, a w transkrypcie subagenta jest
   linią `attachment` typu `queued_command`, nie linią `user`. Nie jest granicą zlecenia.
10. **Zadanie wznowienia podaje `summary`, ale nie zawsze.** ID jest w `summary` 24 z 33 wznowień: w 22 zgadza się
    z opisem przebiegu, a w dwóch (wznowienia `acf91f…`) poprawnie wskazuje QA1. Dziewięć wznowień ma `summary` bez
    ID (np. „DELTA round 2 of K1 fix”, „task 9: also block …”, „UX DELTA: Dostawa screen and U1”). To 1,24 h
    z 41,7 h realizacji zleceń (3,0%) i 3,1% ważonych tokenów.
11. **Tekst wiadomości nie jest wiarygodnym źródłem zadania.** W `ash-v1` (`a79f31a…`) opis i `summary` wznowienia
    podają zadanie 4, a jedyny identyfikator w tekście wiadomości to zadanie 1.
12. **Subagent bywa uruchomiony od razu do zadania spoza planu.** Trzy przebiegi w `store-ux-fixes` mają w opisie
    `STORE-UX-FIXES-QA1`, `-CX1` i `-CX2`. `task_of` wymaga cyfr tuż po nazwie epiku, więc zwraca dla nich brak
    zadania. Ta sama reguła na opisie „…-QA1 … po …-1” zwróciłaby zadanie 1 (w historii taki opis nie występuje).
    Pozostałe opisy: 131 z zadaniem planu, 23 bez ID.
13. **Dashboard nie przelicza.** Eksporter (`agent-observability`, `exporter/ash_epics_exporter.py`) podaje
    `attempts[].wall_s` jako `ash_epic_attempt_wall_seconds` i `by_task[].work` jako `ash_epic_task_work_seconds`.
14. **Zapisany rekord a ponowne przeliczenie tym samym narzędziem.** Dwa przeliczenia obecnym narzędziem na dwóch
    kopiach stanu (`--no-gh`) różnią się tylko polem `process.loaded.checked_at`. Zapisane rekordy różnią się od
    takiego przeliczenia w `gaps` (12–15 dodatkowych pozycji „pominięto gh” w każdym z ośmiu rekordów) i w tym
    samym polu czasu. Porównanie zapisanego rekordu z wynikiem nowego parsera mieszałoby więc efekt parsera
    z efektem warunków uruchomienia.

### Hipotezy i ograniczenia

- Format transkryptów nie jest stabilnym interfejsem CLI. Punkty 8–10 to obserwacje z pięciu dni; inna wersja CLI
  może zapisać wznowienie inaczej. Dlatego granica wymaga potwierdzenia (niżej), a brak potwierdzenia jest jawny.
- Czy `summary` jest polem wymaganym przez narzędzie `SendMessage`, nie sprawdzałem; w danych ma je każde
  wywołanie.
- Nie zaobserwowałem linii `coordinator` bez wcześniejszego raportu (np. wznowienia subagenta przerwanego bez
  raportu). Reguła na ten przypadek jest projektem, nie obserwacją.
- Którego zadania dotyczyło dziewięć wznowień bez ID w `summary`, nie da się ustalić z danych. Streszczenia
  sugerują kontynuację poprzedniego zadania w ośmiu z nich; to domysł, nie dowód.
- Czas zlecenia obejmuje testy i czekanie na narzędzia. Nie jest czasem pracy modelu i ta zmiana go nie wydziela.
- Wartości 3,4 h na dashboardzie nie oglądałem w Grafanie: wynika z rekordu (12 225,555 s) i z punktu 13.
- Zapis cache po długiej przerwie (kontekst wczytywany od nowa) trafia do zlecenia, które go wywołało. To koszt
  wznowienia, nie błąd pomiaru.

## Proponowane zachowanie

### Zlecenie

**Zlecenie** to odcinek transkryptu subagenta od linii, która go zaczyna, do linii przed następnym zleceniem.
Pierwsze zlecenie zaczyna pierwsza linia transkryptu. Kandydatem na kolejne jest linia `user` z
`origin.kind = "coordinator"`, która nie jest wynikiem narzędzia. Kandydat jest granicą, gdy potwierdza go co
najmniej jedno z dwojga:

- **wznowienie po stronie prowadzącego:** w transkryptach sesji epiku jest wynik `SendMessage`
  z `toolUseResult.resumedAgentId` tego subagenta, kolejny w kolejności i nie dalej niż 60 s od kandydata;
- **raport:** poprzednie zlecenie ma raport `SubagentHandback`.

| Wznowienie u prowadzącego | Raport poprzedniego zlecenia | Wynik |
|---|---|---|
| jest | jest | granica, `boundary: "resume"` |
| jest | brak | granica, `boundary: "resume_unreported"`, pozycja w `gaps` |
| brak | jest | granica, `boundary: "resume_unconfirmed"`, pozycja w `gaps` |
| brak | brak | nie jest granicą: linia należy do trwającego zlecenia, pozycja w `gaps` |

Linia `attachment` typu `queued_command` (wiadomość w trakcie pracy, powiadomienie zadania w tle) nigdy nie jest
granicą. Każde wywołanie `SendMessage` liczy się raz (`id` bloku `tool_use`), także gdy wznowiona albo
rozgałęziona sesja prowadząca powtarza linie.

### Identyfikator i przypisanie zlecenia

Dwa osobne kroki, takie same dla startu i wznowienia.

**Krok 1: identyfikator (`ref`) albo oznaczenie całej zmiany.** Pierwsze wystąpienie `<EPIK>-<identyfikator>`
w źródle zlecenia, bez rozróżniania wielkości liter, zapisane wielkimi literami (np. `STORE-UX-FIXES-2`,
`STORE-UX-FIXES-QA1`). Identyfikator to litery i cyfry z co najmniej jedną cyfrą. Jedynym oznaczeniem przeglądu
całej zmiany jest `<EPIK>-EPIC` (np. `STORE-UX-FIXES-EPIC`), rozpoznawane tak samo; liczy się to, co w źródle
występuje pierwsze. Każdy inny wyraz po nazwie epiku i sama nazwa epiku niczego nie oznaczają. Źródłem jest
`description` przebiegu (pierwsze zlecenie) albo `summary` wywołania `SendMessage`, które potwierdza wznowienie.
Tekst wiadomości nie jest źródłem (fakt 11).

**Krok 2: przypisanie (`assignment`).**

| Źródło | `assignment` | `task` | `ref` | Klucz w przekrojach |
|---|---|---|---|---|
| `ref` z samych cyfr po nazwie epiku | `plan` | `<EPIK>-<n>` | ten sam | `<EPIK>-<n>` |
| `ref` z innym identyfikatorem (`QA1`, `CX2`) | `outside_plan` | `null` | pełny identyfikator | `no_task` (decyzja D2) |
| oznaczenie `<EPIK>-EPIC` (start albo wznowienie) | `no_task` | `null` | `null` | `no_task` |
| start: `description` bez `ref` i bez oznaczenia | `no_task` | `null` | `null` | `no_task` |
| wznowienie: `summary` bez `ref` i bez oznaczenia, także z samą nazwą epiku („STORE-UX-FIXES: popraw uwagi”) | `unknown` | `null` | `null` | `unknown_task` |
| granica bez wznowienia u prowadzącego (`resume_unconfirmed`) | `unknown` | `null` | `null` | `unknown_task` |

- Zadanie nie jest dziedziczone z poprzedniego zlecenia. Bez dowodu przypisanie jest nieznane (`unknown`),
  z pozycją w `gaps`; czas, tokeny i wartość takiego zlecenia są w sumie epiku i w przekrojach według roli
  i modelu, a w przekrojach według zadania pod osobnym kluczem `unknown_task`.
- `outside_plan` i `unknown` to różne stany: pierwszy ma znany identyfikator (`ref`), drugi nie ma żadnego.
- Start bez `ref` zostaje w `no_task`, jak dziś: krok 2 „Przebiegu epiku” definiuje to znaczenie dla `description`.
  Dla `summary` takiej reguły nie było, więc brak ID we wznowieniu niczego nie dowodzi, a nazwa epiku w `summary`
  („STORE-UX-FIXES: popraw uwagi”) nie mówi, czy zlecenie dotyczy całej zmiany, czy jednego zadania.
- Żadne `summary` w danych historycznych nie ma oznaczenia `<EPIK>-EPIC`, więc dziewięć wznowień bez ID zostaje
  w `unknown_task`. Dane historyczne nie są dopasowywane do nowej konwencji.
- Dla opisów z ośmiu epików krok 1 i 2 dają to samo zadanie planu co dzisiejsze `task_of` (131 opisów), a trzy
  opisy spoza planu dostają `ref` i `outside_plan` zamiast braku identyfikatora.

`description` i `summary` służą tylko do odczytu identyfikatora i oznaczenia; ich treść nie trafia do rekordu.

**Zdanie w ADR (D3)**, „Przebieg epiku”, krok 2, po zdaniu „Po ID pomiar przypisuje przebieg do zadania, bez niego
trafia on do pozycji »bez zadania«.”:

> `summary` wiadomości, która wznawia subagenta (`SendMessage`), zaczyna się od ID zadania, którego dotyczy
> wznowienie (`<EPIK>-<n>`, dla zadania poprawkowego jego identyfikator, np. `<EPIK>-QA1`), a przy przeglądzie
> całej zmiany od `<EPIK>-EPIC`. Wznowienie bez jednego z nich pomiar zapisuje jako „zadanie nieustalone”: sama
> nazwa epiku nie wystarcza.

### Rekord

`attempts` zostaje listą przebiegów subagentów (jeden wpis na `agent_id`), więc `process.loaded`,
`attempts[].instructions`, `retries.failed_by_outcome` i `integration.attempts` nie zmieniają znaczenia.

| Pole | Zachowanie |
|---|---|
| `attempts[].orders` | lista zleceń w kolejności transkryptu: `n` (od 1), `boundary` (`launch` albo wartość z tabeli granic), `assignment`, `task`, `ref`, `task_source` (`description`, `summary` albo `null` przy `unknown`), `started_at`, `ended_at`, `wall_s`, `reported_at` (pierwszy raport zlecenia albo `null`), `tokens`, `value`, `estimated` |
| `attempts[].wall_s` | suma `wall_s` zleceń: czas realizacji (decyzja D1). Przebieg z jednym zleceniem ma wartość jak dziś |
| `attempts[].idle_s` | odstępy między zleceniami: `ended_at − started_at − wall_s` |
| `attempts[].started_at`, `ended_at`, `task`, `tokens`, `value` | bez zmian; `task` to zadanie planu pierwszego zlecenia albo `null` |
| `by_task` | klucze: zadania planu, `no_task`, `unknown_task` (tylko gdy są takie zlecenia). `work`: suma `wall_s` zleceń klucza; `attempts`: liczba przebiegów, które mają zlecenie tego klucza; nowe `orders`: liczba zleceń. `kind` zostaje `null` |
| `value.by_task`, `value.by_task_role` | wiadomości liczone do klucza zlecenia, w którym leżą; suma kluczy równa się `value.total` |
| `value.total`, `by_role`, `by_model`, `by_role_model` | bez zmian co do wartości |
| `integration.work_s` | suma `wall_s` przebiegów ról przeglądu całej zmiany, czyli bez odstępów |
| `reviews[]` | `task`, `ref` i `assignment` zlecenia, w którym jest raport |
| `reviews[].mode` | `FULL` dla pierwszego raportu przebiegu z danym `ref`, a przy `no_task` dla pierwszego raportu przebiegu w tym stanie; `DELTA` dla kolejnych; `null` z pozycją w `gaps` dla raportu w zleceniu `unknown` |
| `retries.fix` | reguła bez zmian: przeglądy z zadaniem planu i werdyktem blokującym. Blokujący przegląd w zleceniu `unknown` nie jest liczony, a pozycja w `gaps` mówi, że liczba jest dolną granicą |
| `breakdown`, `human`, `in_attempts`, `versions`, `subscription`, `process`, `acceptance`, `ci`, `regressions`, `bottlenecks` | bez zmian |

Czas zlecenia to różnica między ostatnią i pierwszą linią `user` albo `assistant` zlecenia w przedziale epiku.
Wiadomość należy do zlecenia, w którym jest jej pierwsza linia; deduplikacja po `message.id` w całym epiku zostaje.

Klucze `no_task` i `unknown_task` oraz zlecenia `outside_plan` nie są próbkami zadań planu w `decide`, `effect`
ani w wąskich gardłach: te reguły wybierają wpisy po `kind`, a wpisy rekordu natywnego mają `kind: null` (fakt 7).
Zmiana tego nie rusza.

### Braki i niejednoznaczność

- Zlecenie `unknown`: opisane wyżej; nigdy nie trafia pod zadanie planu ani do `no_task`.
- Zlecenie bez linii ze znacznikiem czasu: `wall_s`, `started_at` i `ended_at` mają `null`, a `wall_s` przebiegu
  i wpis `by_task` mają `incomplete: true` z pozycją w `gaps` (suma to dolna granica), nigdy zero.
- Zlecenie w całości poza przedziałem epiku nie wchodzi do rekordu; przecięte granicą przedziału liczy tylko linie
  z przedziału.
- Utracony transkrypt sesji prowadzącej: granice z samych raportów (`resume_unconfirmed`), przypisanie `unknown`.
- Rekord zbudowany starszym narzędziem nie ma `orders`, `idle_s` ani `unknown_task`: `trend`, `decide`, `effect`,
  `render` i eksporter traktują brak pola jak brak danych.

### Dashboard

- `ash_epic_attempt_wall_seconds` i `ash_epic_task_work_seconds` pokazują poprawione wartości bez zmiany nazw;
  opisy paneli mówią „czas realizacji zleceń”. Klucz `unknown_task` ma w tabeli „Zadania” etykietę „zadanie
  nieustalone (wznowienie bez ID)”, obok istniejącej etykiety `no_task`.
- Nowe: `ash_epic_attempt_idle_seconds`, `ash_epic_task_orders` i wiersz na zlecenie
  (`ash_epic_order_wall_seconds`, `ash_epic_order_value_usd`, `ash_epic_order_started_timestamp_seconds` z etykietami
  `agent`, `order`, `assignment`, `task`, `ref`, `role`, `model`, `boundary`). Tabela „Przebiegi subagentów” dostaje
  kolumnę „Odstępy”, a pod nią jest tabela „Zlecenia”.
- Przegląd z `mode: null` jest w „Przeglądach według roli i werdyktu” osobnym wierszem „tryb nieustalony”, nie
  wierszem `FULL` ani `DELTA`.
- Rekord bez `orders` daje w nowych panelach „brak danych”.

## Kryteria akceptacji

1. Syntetyczny przebieg reviewera z czterema zleceniami o czasach z reprodukcji (dwa dla zadania planu, dwa dla
   zadania spoza planu podanego w `summary`) ma `orders` z czterema wpisami, `wall_s` 1 488, `idle_s` 10 738,
   a czas, tokeny, wartość i raporty dwóch ostatnich zleceń nie są pod pierwszym zadaniem.
2. Przebieg z jednym zleceniem daje te same wartości pól rekordu co przed zmianą, także przebieg uruchomiony od
   razu do zadania spoza planu (dochodzi tylko `ref` i `assignment` w `orders`).
3. `value.total`, `value.by_role` i `value.by_model` są równe przed zmianą i po niej; suma tokenów i wartości
   zleceń równa się tokenom i wartości przebiegu; suma kluczy `value.by_task` (zadania planu, `no_task`,
   `unknown_task`) równa się `value.total`; każdy `message.id` liczy się raz, także przy powtórzonych liniach.
4. Wiadomość w trakcie zlecenia (`queued_command`) i linia `coordinator` bez raportu i bez wznowienia
   u prowadzącego nie dzielą zlecenia; druga daje pozycję w `gaps`.
5. Każdy wiersz tabeli granic ma test: wartość `boundary` i pozycję w `gaps` tam, gdzie tabela ją podaje.
6. Wznowienie z `summary` bez ID po zleceniu zadania planu ma `assignment: "unknown"`: jego czas, tokeny i wartość
   są pod `unknown_task`, nie pod poprzednim zadaniem ani w `no_task`, i są w `value.total`; raport w nim ma
   `mode: null`; `gaps` ma pozycję. To samo dla granicy `resume_unconfirmed`.
7. Każdy wiersz tabeli przypisań ma test, w tym: start do `…-QA1` i kolejna runda z `summary` z `…-QA1` (oba
   zlecenia `outside_plan` z tym samym `ref`, przeglądy `FULL` i `DELTA`, przekroje w `no_task`); opis
   „…-QA1 … po …-1” daje `ref` QA1; `summary` z `<EPIK>-EPIC` daje `no_task`; `summary` „<EPIK>: popraw uwagi”
   (sama nazwa epiku) daje `unknown`.
8. Przeglądy: `task`, `ref`, `assignment` i `mode` według zlecenia; `integration.work_s` wznowionego reviewera
   całości nie zawiera odstępu; `retries.fix` liczy tylko przeglądy z zadaniem planu.
9. Rekord nie zawiera treści `summary`, `description`, zlecenia ani raportu.
10. Rekord bez `orders` nie psuje `trend`, `decide`, `effect` ani `render`; rekord z `unknown_task` też nie,
    a wyniki `trend` i `decide` są takie same jak dla rekordu sprzed zmiany.
11. Kontrolowane porównanie starego i nowego parsera na zamrożonych wejściach (plan, „Kontrolowane porównanie”):
    przebieg kontrolny stary–stary nie pokazuje różnic poza polami metadanych, a porównanie stary–nowy pokazuje
    różnice tylko w polach z tabeli „Rekord”.
12. Eksporter: testy nowych metryk, klucza `unknown_task`, przeglądu z `mode: null` i rekordu bez `orders` jako
    braku danych; `make test` stosu przechodzi.

## Decyzje właściciela

| Nr | Decyzja | Rekomendacja |
|---|---|---|
| D1 | `attempts[].wall_s` zmienia znaczenie na czas realizacji zleceń, a rozpiętość zostaje w `started_at`/`ended_at` i `idle_s`. Alternatywa: `wall_s` bez zmian i nowe pole `work_s` | zmiana `wall_s`: `by_task.work` i `integration.work_s` są zdefiniowane jako suma `wall_s`, a w rekordach AO `wall_s` to czas próby |
| D2 | Zlecenia `outside_plan` (`QA1`, `CX1`) są w przekrojach pod `no_task`, a pełny identyfikator jest w `orders[].ref` i `reviews[].ref`. Alternatywa: własne klucze w `by_task` i `value.by_task` | `no_task` w tym PR: przebieg z jednym zleceniem zostaje identyczny z dzisiejszym, a porównanie parserów ma mniej pól różnic. Własne klucze nie zmieniłyby `decide` ani `effect` (fakt 7), więc można je dodać później; zmieniłyby `retries.fix` i listy `render` |
| D3 | Zadanie wznowienia tylko z `summary`, bez dziedziczenia: wznowienie bez ID ma zadanie nieustalone (`unknown_task`). W danych historycznych to 9 z 33 wznowień, 3,0% czasu zleceń. ADR („Przebieg epiku”, krok 2) dostaje zdanie, że `summary` wiadomości wznawiającej zaczyna się od ID zadania, a przy przeglądzie całej zmiany od `<EPIK>-EPIC`; sama nazwa epiku nie jest oznaczeniem | tak; błędne przypisanie kosztu jest gorsze niż jawny brak przypisania, a zdanie w ADR usuwa brak dla nowych epików. To jedyna zmiana instrukcji w PR 1 |
| D4 | Odświeżenie ośmiu rekordów natywnych w `~/.local/state/shop-metrics/epics` nowym narzędziem, z kopią zapasową, jako osobny krok po kontrolowanym porównaniu | tak, po pokazaniu wyniku porównania. Bez odświeżenia dashboard i `render` pokazują stare wartości; mediany `trend`, `decide` i `effect` od tych pól nie zależą |
| D5 | Drugi PR w prywatnym repo `agent-observability` (eksporter i dashboard), mergowany zaraz po PR sklepu | tak |
| D6 | Propozycja procesu ([`process-proposal.md`](process-proposal.md)): zmiany P1 i P3, bez zmiany P2 | tak, jako osobny PR między epikami |
