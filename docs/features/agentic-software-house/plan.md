# Plan: Agentic Software House

Obowiązująca decyzja: [ADR](../../adr/agentic-software-house.md) (najpierw natywne, AO jako rozwiązanie awaryjne).
Ten plan ustala role, proces epiku prowadzonego przez AO, kroki wdrożenia i kroki przejścia na pracę natywną. Od
2026-11-18 obowiązuje moratorium na rozwój AO i narzędzi istniejących tylko dla niego (sekcja „Status”): AO zmienia się
tylko wtedy, gdy prawdziwy przebieg epiku stoi, najmniejszą poprawką i za zgodą użytkownika.

Cel: użytkownik poświęca około godziny dziennie na decyzje, a agenci budują sklep według „Zakresu MVP”
z [wizji](../../vision.md). Godzina dziennie to cel do zmierzenia (krok 7), nie potwierdzony fakt.

Kroki 16–22 i rozszerzony krok 11 pokrywają kompetencje wymagane od Agentic SDLC Engineera, AI-Native Engineera
i Harness Engineera. Rozwiązania mają być praktyczne, mierzalne i przenośne do innych repozytoriów. Optymalizujemy
przede wszystkim czas zegarowy epiku, pilnując jakości, liczby napraw, interwencji człowieka i zużycia limitów.

## Status

Od 2026-11-18 obowiązuje moratorium na rozwój AO, skilli istniejących tylko dla niego (`/ao-run`, `/delivery`,
`/sweep`) i narzędzi z nimi związanych (decyzja w ADR, podstawa w
`docs/reports/agentic-software-house-audit-2026-11-18.md`). Wstrzymane są niedokończone kroki 17–22 i epik ONCALL
(krok 11, `docs/features/oncall/`). Kolejność epików jest na tablicy „Backlog sklepu”.

Ostatni epik prowadzony przez AO: DEMO-DATA (#482), zakończony w AO 2026-11-18 (końcowy PR #744; integracja przeszła
w pierwszej rundzie).

W toku: brak epiku. Blokery: brak.
Ostatni zmergowany epik: ADMIN-UX-FIXES etap 2 (#837, końcowy PR #896, zmergowany 2026-11-24; epik natywny
z danymi pomiaru; 8 wiadomości właściciela, 3 oczekiwania, łącznie 73 min czekania). Ostatni zmergowany epik serii:
DELIVERY etap 1 (#120, końcowy PR #819). Retro do zrobienia: brak (retro ADMIN-UX-FIXES etap 2: ten PR). Otwartych
issues z etykietą `agent` jest 142, zamkniętych 97.

Seria ASH v1.0 (specyfikacja `docs/features/ash-v1/spec.md`): trzy epiki prowadzone natywnie według „Przebiegu epiku”
w ADR, ocenianie metrykami i retrospektywami `/retro`. Seria jest w toku od założenia tagu `ash-v1.0` do retrospektywy
trzeciego epiku; w tym czasie pliki procesu zmienia tylko poprawka blokady albo zagrożenia, po zgodzie użytkownika
(reguła w ADR, koniec „Przebiegu epiku”).

| Seria ASH v1.0 | Stan |
|---|---|
| Stan serii | zakończona (tag `ash-v1.0` założony 2026-11-19; trzy epiki zmergowane; retrospektywa trzeciego epiku: PR #831) |
| Epiki serii | ABUSE-LIMITS (#181), ASYNC-RELIABILITY (#768), DELIVERY etap 1 (#120), w tej kolejności |
| Zmergowane epiki serii | ABUSE-LIMITS (#181, końcowy PR #802), ASYNC-RELIABILITY (#768, końcowy PR #811), DELIVERY etap 1 (#120, końcowy PR #819) |

Stan serii przyjmuje jedną z wartości: „przed startem”, „w toku”, „zakończona”.

Kroki przejścia na pracę natywną (obowiązują teraz, w tej kolejności):

| Krok | Zakres | Status |
|---|---|---|
| P1 | Zachowanie danych historycznych (transkrypty, stan i zrzuty AO) przed ich wygaśnięciem | zrobione (kopia poza repozytorium) |
| P2 | Pilne porządki z raportu audytu | w toku (pozostałości na dysku czekają na decyzję użytkownika) |
| P3 | DEMO-DATA kończy się w AO, ze skróconym `apps/api/CLAUDE.md` i nową polityką przeglądu | zrobione |
| P4 | Uproszczenie instrukcji i konfiguracji: `CLAUDE.md` główny i obszarów, agenci, `/planner`, `/brainstorm`, konfiguracja | zrobione |
| P5 | Seria ASH v1.0: trzy epiki natywnie (ABUSE-LIMITS → ASYNC-RELIABILITY → DELIVERY etap 1), każdy z punktem odniesienia i opisem końcowego PR | zrobione: trzy epiki serii zmergowane natywnie (ABUSE-LIMITS, końcowy PR #802; ASYNC-RELIABILITY, #811; DELIVERY etap 1, #819); seria zakończona retrospektywą DELIVERY (PR #831) |
| P6 | Decyzja o archiwizacji AO i usunięciu jego narzędzi | do zrobienia (po P5) |

Tabela niżej to stan kroków wdrożenia AO; role i proces w kolejnych sekcjach opisują drogę AO.

| Krok | Zakres | Status |
|---|---|---|
| 1 | AO v0.3.1 i zamrożenie AO | zrobione |
| 2 | Ten plan, zasada backlogu w `CLAUDE.md` i ADR | zrobione |
| 3 | Poprawki procesu w `shop` | zrobione |
| 4 | Backlog w GitHub Issues | zrobione |
| 5 | Powiadomienia i kolejka epików przez aplikację Claude | zrobione (sprawdzenie w kroku 7) |
| 7 | Pierwszy epik przez cały proces | zrobione |
| 8 | Nocna kolejka kilku epików | zrobione (sprawdzenie: pierwsza noc) |
| 9 | Agent tester i agent UX | zrobione |
| 10 | Przed publicznym demo: bezpieczeństwo i wydajność | zrobione (epik HARDENING #267, końcowy PR #402 zmergowany) |
| 11 | Po MVP: release, monitoring i dyżur agenta | wstrzymane (moratorium; epik SENTRY zmergowany, końcowy PR #539; dyżur i hypercare, epik ONCALL, wstrzymane) |
| 12 | `/sweep` uruchamiany przez `/ao-run` po merge'u epiku | zrobione; kryterium 7 potwierdzone: `/sweep` po merge'u LIGHTHOUSE i HARDENING (raporty z nazwą epiku); kryteria 8–9 bez dowodu w przebiegach |
| 15 | Retrospektywa po epiku (`/retro`) i aktualny „Status” | zrobione; zweryfikowane: pełne przebiegi `/retro` po epikach natywnych SMALL-FIXES (#799) i ASH-V1 (#801) |
| 16 | Bezpieczeństwo agentów | zrobione (epik AGENT-SECURITY #494, końcowy PR #548 zmergowany); sonda przed startem AO bez dowodu z kolejnego przebiegu |
| 17 | Evale agentów i workflowów | wstrzymane (moratorium) |
| 18 | Pomiar, koszty i optymalizacja | wstrzymane (moratorium; epik METRICS #496 zrobiony, końcowy PR #674 zmergowany: pomiar epiku, `tools/metrics`; epik TELEMETRY #577 zrobiony, końcowy PR #721 zmergowany: telemetria Claude Code do lokalnego stosu) |
| 19 | Praca równoległa | 19a: zrobione; pomiar par i 19b (AO `--max-parallel`, piotrp321/agentic-orchestrator#50) wstrzymane (moratorium) |
| 20 | Asystent zakupowy i serwer MCP katalogu (osobny epik poza MVP) | wstrzymane (moratorium) |
| 21 | Dokumentacja wdrożenia procesu i studium przypadku | wstrzymane (moratorium) |
| 22 | Porównanie Claude Code i OpenAI Codex | wstrzymane (moratorium) |

## Role

Testerów i projektantów UX nie zatrudniamy: obie role pełnią agenci. Człowiekiem jest tylko właściciel produktu.

| Dział | Rola | Kto | Stan |
|---|---|---|---|
| Produkt | Właściciel produktu: co budujemy, kolejność, triage, odbiór | użytkownik | działa |
| Analiza i projekt | Analityk: specyfikacja | `/brainstorm` z użytkownikiem | działa |
| | Architekt: ADR, review architektury epiku | `architecture-reviewer` w `/integration` | działa |
| | UX: ekrany, wersja mobilna, dostępność | `ux-reviewer` w `/sweep` i w `/integration` | działa |
| Planowanie | Plan i zadania | `/planner` | działa |
| | Koordynacja: przebieg nocny, stan, PR | AO | działa |
| | Kolejka epików: następny epik po merge'u i lista na noc | `/ao-run` w sesji z Remote Control | działa, lista na noc do sprawdzenia w pierwszej nocy |
| | Praca równoległa: epiki naraz i zadania fali naraz, bramka ryzyka | `/ao-run` z bramką `night_queue.py gate` (19a), AO `--max-parallel` (19b) | 19a działa, pomiar par trwa; 19b w toku |
| Wytwarzanie | Developer: zadanie z TDD | `developer` w `/delivery` | działa |
| | Code review zadania | `code-reviewer` w `/delivery` | działa |
| Jakość | QA epiku | `qa-adversarial` w `/integration` | działa |
| | Tester całego sklepu | `tester` w `/sweep`, uruchamiany przez `/ao-run` po merge'u | działa |
| | Bezpieczeństwo i wydajność | ustalane w kroku 10 | krok 10 |
| | Bezpieczeństwo agentów: niezaufane wejścia, izolacja uprawnień i sekretów, testy obejścia | sandbox i reguły w `shop` | krok 16 |
| | Evale agentów na prawdziwych zadaniach i porównanie wariantów agenta | EVALS 2.0: `tools/evals-pilot` (`code-reviewer`), uruchamiane ręcznie za zgodą właściciela | krok 17 |
| Backlog i proces | Retrospektywa epiku: poprawki procesu i „Status” planów | `/retro` z agentem `retro-analyst`, uruchamiany przez `/ao-run` po `/sweep` | działa, niezweryfikowane na prawdziwym epiku |
| | Pomiar czasu, kosztu i jakości z decyzjami optymalizacyjnymi | `tools/metrics` (`tools/metrics/README.md`), uruchamiany przez `/ao-run`; telemetria Claude Code w lokalnym stosie na Macu (TELEMETRY #577) | krok 18 |
| Wydanie i utrzymanie | Release na publiczne demo, rollback | k3s i Argo CD z `docs/vision.md` | krok 11 |
| | Monitoring → issues, dyżur i hypercare | agent dyżurny: Sentry i Grafana Cloud przez MCP, tylko odczyt | krok 11 |

## Proces

Proces epiku prowadzonego przez AO (rozwiązanie awaryjne; pracę natywną opisują ADR i główny `CLAUDE.md`). Elementy,
które jeszcze nie działają, mają w nawiasie numer kroku wdrożenia.

```mermaid
flowchart TB
    issues[GitHub Issues<br/>epic, bug, ux, tech-debt]
    triage{Triage i kolejność<br/>użytkownik}
    prep["Przygotowanie<br/>specyfikacja i plan<br/>epik albo epik poprawek"]
    accept{"Akceptacja specyfikacji<br/>końcowy PR do przejrzenia w jednym posiedzeniu?"}
    onmain[main: spec.md i plan.md]
    start{"Zacząć epik? albo lista na noc<br/>push w aplikacji Claude"}
    subgraph night[AO w nocy]
        delivery["/delivery × N"] --> integration["/integration<br/>architektura, QA, UX"] --> finalpr[Końcowy PR do main]
    end
    blocker[Bloker: push w aplikacji Claude i ao answer]
    review{Odbiór według ryzyka<br/>użytkownik}
    merge[Merge i zamknięcie issue]
    sweep["Agent tester i agent UX<br/>cały sklep na main (12: po merge'u sam)"]
    retro["/retro (15)<br/>PR z poprawkami procesu i Statusem"]
    oncall["Agent dyżurny (11)<br/>Sentry i Grafana, tylko odczyt"]

    issues --> triage --> prep --> accept
    merge --> retro
    accept -- za duży, podział --> prep
    accept -- tak --> onmain --> start
    start -- tak --> delivery
    delivery -. bloker .-> blocker -. odpowiedź .-> delivery
    finalpr --> review
    review -- błąd w zakresie epiku: PR do gałęzi epiku --> integration
    review -- odebrany --> merge --> sweep
    merge -. następny przygotowany epik .-> start
    finalpr -. epic_done: następny epik listy na noc .-> start
    review -. uwagi i problemy spoza zakresu .-> issues
    sweep -. zgłoszenia z etykietą agent .-> issues
    oncall -. zgłoszenia z etykietą agent .-> issues
```

1. **Backlog: GitHub Issues** w `piotrp321/shop`.
   - Etykieta `epic` to epik z roadmapy (spójna grupa zmian z własną specyfikacją), `bug` to błąd, `ux` to problem
     użyteczności albo wyglądu, `tech-debt` to porządki w kodzie, bazie albo dokumentacji bez zmiany zachowania.
   - Każde zgłoszenie należy do jednego backlogu: `app` (sklep, API, panel i ich infrastruktura), `ash` (proces,
     agenci, skille, narzędzia i CI repozytorium) albo `ao-frozen` (AO i skille istniejące tylko dla niego, bez
     priorytetu do decyzji o archiwizacji). Zgłoszenie epiku zawiera listę swoich pozycji i identyfikatory znalezisk
     z raportów w `docs/reports/`.
   - Zgłaszają: użytkownik, agent tester i agent UX. Zgłoszenia agentów mają etykietę `agent` i limit
     na jeden przebieg. Do backlogu trafiają dopiero po triage użytkownika.
   - Zgłoszenia `bug`, `ux` i `tech-debt` mają pola: środowisko, kroki odtworzenia (jeden krok w linii), wynik
     oczekiwany i faktyczny, kryterium akceptacji, niewiadome. Dla `bug` i `ux` jest formularz
     w `.github/ISSUE_TEMPLATE/`, agenci wypełniają te same pola. Zgłoszenie z brakami dostaje etykietę
     `needs-info` i nie idzie do przygotowania.
   - Kolejność ustala użytkownik na tablicy GitHub Projects
     [„Backlog sklepu”](https://github.com/users/piotrp321/projects/1): epik na górze idzie pierwszy. Na tablicy są
     tylko epiki i pozycje do zrobienia przed nimi; pole „Backlog” odróżnia aplikację od ASH.
2. **Przygotowanie (użytkownik, w dzień).**
   - Issue z góry listy → `/brainstorm` → `spec.md`, `/planner` → `plan.md`.
   - Decyzje produktowe (zakres, reguły, limity, zachowanie klienta) podejmuje wyłącznie użytkownik. Agenci ich
     nie rozstrzygają między sobą. Zbierają je w jedną listę pytań, każde z rekomendacją. Użytkownik dostaje
     pytania raz, przed akceptacją.
   - Akceptacja jest jedna: specyfikacja z pozycjami użytkownika z „Wymaganych przed startem” i jedno pytanie, czy
     końcowy PR da się przejrzeć w jednym posiedzeniu, z liczbą zadań i dotkniętymi aplikacjami. Jeśli PR jest
     za duży, epik dzielimy na dwa,
     mergowane po kolei. Rozmiar pojedynczych zadań zostaje bez zmian. Podziału na zadania użytkownik nie
     akceptuje: `plan.md` musi przejść walidator manifestu.
   - Przygotowujemy tyle epików do przodu, ile mieści noc: aż `night_queue.py ahead` pokaże pełną noc (suma
     szacunków z pomiaru; bez pomiaru jeden epik do przodu). Nie przygotowujemy wszystkich epików naraz, bo
     specyfikacja opisuje kod, którego jeszcze nie ma.
   - Specyfikacja ma linię `Zakres:`: ścieżki, które epik zmienia. Z niej bramka pracy równoległej i lista na noc
     porównują pliki epików.
   - Specyfikacja ma sekcję „Wymagane przed startem”: sekrety, konta, usługi zewnętrzne, uprawnienia i decyzje
     infrastrukturalne potrzebne zadaniom. Przy akceptacji użytkownik potwierdza, że jego pozycje są gotowe albo
     będą przed `/ao-run`, żeby taki bloker nie wyszedł dopiero w trakcie zadania.
   - Zgłoszenie `ux` zawsze idzie przez przygotowanie epiku. Drobne zgłoszenia `bug`
     i `tech-debt` trafiają razem do jednego epiku poprawek: krótka specyfikacja, jedno zadanie na issue.
   - Zaakceptowane `spec.md` i `plan.md` trafiają do `main` przez PR.
3. **Wykonanie (AO, w nocy).**
   - Start epiku: sesja z Remote Control bierze następny epik z tablicy, który ma zaakceptowane `spec.md`
     i `plan.md` na `main`, i pyta w powiadomieniu push „Zacząć epik X?”. Pyta po merge'u poprzedniego epiku, na
     prośbę użytkownika, a w dzień także po starcie epiku i po jego `epic_done` (bramka proponuje wtedy epik
     równoległy).
   - Lista na noc: przed snem `/ao-run --noc` proponuje przygotowane epiki w kolejności tablicy, bez wspólnego
     kodu aplikacji (reguła plików bramki), bez zależności od otwartych epików i, przy pomiarze czasu,
     mieszczące się do rana. Użytkownik zatwierdza ją jednym „tak”. Każdy epik listy rusza przez bramkę: równolegle
     z trwającym albo po `epic_done` poprzedniego, bez czekania na merge, tylko gdy jego szacunek mieści się do rana
     i w limitach subskrypcji. Gdy bramka nie przechodzi przy trwającym epiku, kolejny czeka; inaczej noc się kończy.
   - Bez zgody sesja niczego nie uruchamia. Naraz trwają najwyżej dwa aktywne epiki, z jednej sesji albo z dwóch.
     Każdy start idzie przez rezerwację w bramce (`docs/features/parallel-work/spec.md`), więc dwie sesje nie ruszą
     jednego epiku ani trzeciego naraz. Zasilania sesja nie sprawdza: decyduje o nim użytkownik.
   - Praca równoległa (19a) jest domyślna: kolejny przygotowany epik, także z listy na noc, rusza naraz z trwającym,
     gdy przejdzie bramkę ryzyka; inaczej czeka na swoją kolej (wykonanie szeregowe jako rozwiązanie awaryjne).
   - Sesja z `/ao-run <epik>` uruchamia `ao epic start` i `ao run --until-idle`. AO prowadzi zadania przez
     `/delivery` po jednym naraz (19b: niezależne zadania jednej fali po dwa naraz, merge'e po kolei),
     squash-merguje je do `epic/<epik>`, uruchamia `/integration` i otwiera końcowy
     PR `epic/<epik>` → `main` z raportem `ao report`. Czerwone CI gałęzi epiku i konflikt końcowego PR z `main`
     naprawia zadanie REPAIR (konflikt: prawdziwy `git merge origin/main`, zasady w `/delivery`), mergowane do
     `epic/<epik>` merge commitem.
   - `code-reviewer` w `/delivery` sprawdza każde zadanie, a `/integration` cały epik. Usunięty, wyłączony albo
     osłabiony istniejący test lub zmieniona wartość oczekiwana bez podstawy w specyfikacji to `BLOCKER` w zadaniu
     i `Important` w epiku. Plik albo zmiana spoza `scope` zadania to zgłoszenie. `code-reviewer` sprawdza też,
     czy RED poprzedził implementację.
   - Przy epikach, które zmieniają `apps/store` albo `apps/admin`, `/integration` uruchamia też agenta UX
     (`ux-reviewer`). Problem z zakresu epiku jest znaleziskiem do naprawy, pozostałe zostają w raporcie jako uwagi.
4. **Blokery.**
   - `BLOCKED` i powiadomienie w aplikacji Claude na telefonie tylko wtedy, gdy zadanie nie może iść dalej bez
     decyzji. Użytkownik odpowiada w sesji, która prowadzi przebieg, a ta wykonuje `ao answer`.
   - Wybory odwracalne agent rozstrzyga sam i wpisuje do sekcji „Decyzje bez pytania” w opisie PR zadania, a
     `/integration` przenosi je do swojego raportu.
   - Powtarzający się bloker naprawiamy w `/brainstorm` albo w `CLAUDE.md`, nie w AO.
5. **Odbiór (użytkownik).**
   - Odbiór to przeczytanie raportu odbioru z `/ao-run` i merge. Raport ma rekomendację „merge” albo „najpierw
     sprawdź: …”, zbudowaną z „Listy odbioru” raportu `/integration` (ścieżka raportu jest w opisie końcowego PR):
     dla każdej pozycji „OK” albo „Sprawdź”, czyli zmiany istniejących testów, migracje, pliki spoza zakresów zadań,
     odstępstwa od specyfikacji i „Decyzje bez pytania”.
   - Błąd w zakresie epiku: poprawka przez PR do `epic/<epik>` (np. `/delivery` uruchomione ręcznie z linią
     `Gałąź docelowa PR: epic/<epik>`), zmergowany przez użytkownika po zielonym CI. AO traktuje nowy HEAD jako
     kod po przeglądzie: robi rundę `DELTA` `/integration`, a końcowy PR czeka na jej wynik. Potem odbiór od nowa.
     Taka poprawka liczy się jako ręczna.
   - Konflikt końcowego PR z `main`, którego AO nie usunie (epik `DONE` przed zmianą `main`, `BLOCKED` po naprawach):
     `/ao-run` po zgodzie użytkownika robi PR `sync/<epik>-main` z prawdziwym merge'em `main` do `epic/<epik>`
     i merguje go merge commitem po zielonym CI. Końcowy PR zostaje ten sam. To też ręczna poprawka.
   - Problem spoza zakresu albo większy: nowe issue `bug` lub `ux`. Epik mergujemy bez tej poprawki, o ile
     niczego na `main` nie psuje. Uwagi `Minor` z raportu `/integration`, które warto zrobić, też stają się
     issues.
   - `/ao-run` dopisuje do opisu końcowego PR `Closes #N` każdego issue z linii `Issues:` specyfikacji. Użytkownik
     sprawdza je i merguje PR (squash). Issue zamykają się same. Merge do `main` zostaje wyłącznie u użytkownika.
   - Raport odbioru ma sekcje „Pomiar” (wąskie gardła, czas, wartość tokenów, próby, alarmy, pozycja na tle mediany;
     `metrics.py render --format report`) i, jako ostatnia sekcja raportu, „Decyzje optymalizacyjne” (najwyżej 3
     kandydatów z rekomendacją, efektem i liczbą epików, w których reguła zadziałała; `metrics.py decide`, decyzji nie
     zapisuje) (krok 18).
   - Po każdym epiku komentarz do issue epiku to blok „Pomiar epiku” (`metrics.py render --format comment`): trzy liczby
     (interwencje człowieka, czas czekania na decyzje użytkownika, liczba ręcznych poprawek) i pomiar po merge'u. Minut
     pracy użytkownika nie szacujemy. `/ao-run` pokazuje też trend (`metrics.py trend`) na starcie i po każdym merge'u,
     a w trakcie przebiegu wysyła pushem alarmy bezproduktywnego zużycia (`metrics.py waste`).
6. **Po merge'u (`/sweep [epik]`).** Sesja `/ao-run` uruchamia przegląd sama po merge'u końcowego PR: kolejka
   przeglądów, jeden naraz, nigdy równolegle z epikiem (następny epik rusza po kolejce), pominięcie przy oknie limitu
   ≥ 95% (`docs/features/ao-run/spec.md`). Użytkownik może go też uruchomić ręcznie.
   Agent tester i agent UX przechodzą cały sklep na świeżym stosie z `main`: krytyczne ścieżki z sekcji „Testy”
   w `docs/vision.md` i nową funkcję ze specyfikacji, jako klient i jako pracownik. Przed zaproponowaniem zgłoszenia
   sprawdzają otwarte issues, żeby nie dublować. Propozycje trafiają do triage (punkt 1).
7. **Retrospektywa (15, `/retro [epik]`).** Sesja `/ao-run` uruchamia ją sama po `/sweep` (ta sama kolejka, jeden
   naraz, nigdy razem z epikiem), a ręcznie `/retro [epik]`. Skrypt `retro.py` zbiera z całej historii przebiegów AO
   (`ao-<epik>/`, tylko do odczytu) **incydenty**: czekania na użytkownika, nieudane próby, znaleziska
   `/integration`, REPAIR, ręczne poprawki z komentarza z pomiarem, propozycje `/sweep` i decyzje bez pytania z PR
   zadań. Agent `retro-analyst` grupuje je w problemy i szuka przyczyny w instrukcjach procesu. Wynik:
   - jeden wspólny PR do `main` z gałęzi `retro/*` (merguje użytkownik): poprawki procesu w `shop` (`CLAUDE.md`,
     skille, agenci, specyfikacje skilli), każda w osobnym commicie z dowodami, przyczyną i sposobem sprawdzenia;
     poprawka agenta dostaje przypadek evali z incydentu, który ją wywołał (17); kolejne retro dopisują do otwartego
     PR. Próg: co najmniej dwa różne incydenty albo jeden naprawczy (wymagał działania człowieka z powodu
     nieprzewidzianej awarii); poprawka, po której problem wrócił, jest oznaczona jako nieskuteczna;
   - aktualna sekcja „Status” tego planu (plany epików prowadzonych przez AO nie są zmieniane);
   - propozycje issues (najwyżej 3): problem w AO jako issue w `piotrp321/agentic-orchestrator` (AO jest zamrożone),
     poprawka wymagająca kodu w `shop` jako `tech-debt` z etykietą `agent`, problem z otwartym issue jako komentarz do
     niego. Issues i komentarze powstają po „tak” użytkownika; problem odrzucony świadomie nie wraca bez nowego
     incydentu.

   Retro do zrobienia to lista wyliczana z danych (`retro.py pending`: zmergowane epiki bez stanu `done` albo
   `skipped`), więc odroczone (limit, brak `gh`) wraca przy następnym merge'u, po końcu procesu AO i na
   starcie `/ao-run`. Opis: `docs/features/retro/spec.md`.

## Kroki wdrożenia

1. **AO v0.3.1 i zamrożenie.** v0.3.1 zamknięte (piotrp321/agentic-orchestrator#32). Od teraz AO jest zamrożone.
2. **Plan i zasada backlogu.** Ten plan zastępuje poprzedni. `CLAUDE.md` dostaje zasadę backlogu, ADR wskazuje
   GitHub Issues jako intake i opisuje role agenta testera i agenta UX. Jeden PR.
3. **Poprawki procesu w `shop`.** Formularze `bug` i `ux` w `.github/ISSUE_TEMPLATE/`, kontrole istniejących
   testów, zakresu i RED w `code-reviewer`, sekcja „Decyzje bez pytania” w PR zadań i lista odbioru w raporcie
   `/integration`.
4. **Backlog.** Etykiety `epic`, `bug`, `ux`, `tech-debt`, `needs-info` i `agent` są założone. Epiki z niezrobionych
   punktów „Zakresu MVP” to issues #88–#97, w kolejności na tablicy „Backlog sklepu”.
5. **Powiadomienia i kolejka epików przez aplikację Claude.** Przebieg AO prowadzi sesja Claude Code z Remote
   Control (włączone globalnie w aplikacji). Sesja śledzi zdarzenia AO (bloker, awaria, koniec epiku) i wysyła
   powiadomienie push do aplikacji Claude na telefonie. Odpowiedź na bloker użytkownik pisze w tej sesji, a sesja
   wykonuje `ao answer`. Po merge'u końcowego PR, a w dzień także po starcie epiku i po jego `epic_done`, ta sama
   sesja bierze następny przygotowany epik z tablicy, który przechodzi bramkę, i pyta „Zacząć epik X?” (punkt 3
   procesu). Instrukcja dla sesji jest w `shop`, bez zmian w kodzie AO. Mechanizm sprawdza
   pierwszy przebieg (krok 7). Przebieg epiku prowadzi skill `/ao-run <epik>` (`docs/features/ao-run/spec.md`).
   Kolejkę prowadzi ten sam skill (sekcja „Następny epik”, bramka `night_queue.py gate`): `/ao-run` bez argumentu
   wybiera następny przygotowany epik z tablicy.
7. **Pierwszy epik.** Issue z góry backlogu przechodzi cały proces, od przygotowania do odbioru, razem z pomiarem
   z punktu 5 procesu. Każdy kolejny epik idzie tym samym procesem.
8. **Nocna kolejka kilku epików.** Przed snem użytkownik jedną zgodą zatwierdza listę przygotowanych epików na noc
   (`/ao-run --noc`, punkt 3 procesu). Każdy epik listy rusza przez bramkę: równolegle z trwającym albo po `epic_done`
   poprzedniego, bez czekania na merge. Rano użytkownik odbiera kilka raportów. Opis jest
   w `docs/features/ao-run/spec.md` (sekcja „Noc”), kod w `.claude/skills/ao-run/night_queue.py`, bez zmian w AO:
   - na jedną noc trafiają tylko epiki bez wspólnego kodu aplikacji (reguła plików bramki, krok 19a), bo każdy
     startuje z `main` bez poprzednich, a pozostałe wspólne pliki łączy REPAIR; zakres epiku jest w linii `Zakres:`
     specyfikacji, a lista porównuje z niej pliki epików;
   - przygotowanych jest tyle epików do przodu, ile mieści noc (`night_queue.py ahead`, punkt 2 procesu);
   - następny epik nie rusza, gdy jego szacunek nie mieści się do rana albo limit subskrypcji nie wystarczy na cały
     jego przebieg;
   - szacunki i liczba epików na noc wynikają z pomiaru każdego epiku prowadzonego przez `/ao-run`, nie z założenia.
   Mechanizm sprawdza pierwsza noc.
9. **Agent tester i agent UX.** Gotowe: agent tester i agent UX (`.claude/agents/`), skill `/sweep` uruchamiany
   po merge'u epiku (przez `/ao-run` od kroku 12), agent UX jako trzeci reviewer w `/integration` dla epików z frontem,
   etykieta `agent`, limit pięciu propozycji na przebieg i sprawdzanie duplikatów. Agent UX ocenia w sklepie
   użyteczność, dostępność, responsywność (375, 768 i 1440 px), spójność komponentów i jakość wizualną (zgodność z
   wybranym kierunkiem i tokenami, gdy kierunek jest w repo). Automatyczne uruchamianie: krok 12.
   Opis: `docs/features/agent-tester-ux/spec.md`.
10. **Przed publicznym demo: bezpieczeństwo i wydajność.** Przegląd bezpieczeństwa całego sklepu i testy
   obciążeniowe względem „Wymagań niefunkcjonalnych” z `docs/vision.md`. Zakres i narzędzia ustala specyfikacja
   przed demo.
11. **Po MVP: release, monitoring i dyżur agenta.** Publiczne demo zgodnie z `docs/vision.md` (k3s, Argo CD,
   rollback). Po epiku SENTRY agent dyżurny:
   - ma minimalne uprawnienia, technicznie ograniczone do odczytu: Sentry i Grafana Cloud przez ich serwery MCP,
     klaster przez istniejące cele `make -C infra/k8s`; dostępność i możliwości integracji MCP sprawdzamy przed
     zatwierdzeniem specyfikacji;
   - diagnozuje i koreluje błędy Sentry, alerty Grafany i stan klastra, proponuje naprawy i przygotowuje issues `bug`
     z etykietą `agent`, które przechodzą triage użytkownika (jak propozycje `/sweep`);
   - po wdrożeniu epiku prowadzi hypercare: porównuje błędy nowego wydania z poprzednim i wysyła push z rekomendacją;
   - nie wykonuje rollbacku ani innych operacji produkcyjnych: robi je wyłącznie użytkownik.

Kroki 12 i 15 domykają pętlę „zgłoszenia agentów → poprawki” i poprawiają proces z danych przebiegów. Oba są
skillami w `shop`, bez zmian w AO. Każdy zużywa limit subskrypcji, więc działa po epiku, nigdy
równolegle z przebiegiem AO, a jego czas i zużycie limitu są mierzone jak przebieg epiku (format ustala specyfikacja kroku).

12. **`/sweep` po merge'u epiku.** Trzy ręczne przebiegi (2026-11-04, 2026-11-07, 2026-11-09) dały 6 przyjętych
   zgłoszeń i 1 odrzucone, więc warunek z kroku 9 jest spełniony. `/ao-run` uruchamia `/sweep <epik>` po merge'u
   końcowego PR (kolejka przeglądów, następny epik rusza po niej) i wysyła push z liczbą propozycji. Triage zostaje
   u użytkownika. Opis: sekcja „Przegląd po merge'u” w `docs/features/ao-run/spec.md`.
15. **`/retro` i „Status”.** Skill `/retro` i agent `retro-analyst` (Opus) oraz skrypt `retro.py`: `/ao-run` uruchamia
   go po merge'u epiku w kolejce po `/sweep` (punkt 7 procesu). Zastępuje ręczne aktualizowanie sekcji „Status”
   i ręczne zbieranie wniosków z przebiegów. Opis: `docs/features/retro/spec.md`. Prawdziwy przebieg sprawdza
   pierwszy merge epiku po merge'u RETRO.

Kroki 16–22 pokrywają kompetencje Agentic SDLC, AI-Native i Harness Engineera. Każdy ma specyfikację zaakceptowaną
przed implementacją; jedna specyfikacja kroku 19 obejmuje 19a i 19b. Kolejność jest w sekcji „Status”.

16. **Bezpieczeństwo agentów.** Zabezpieczenia techniczne, nie tylko w promptach, w zakresie wynikającym z modelu
   zagrożeń, bez rozbudowy ponad uzasadnione potrzeby. Model zagrożeń trafia do ADR `docs/adr/agent-security.md`
   (publiczne repozytorium, AO uruchamia agentów z `--permission-mode auto` bez pytań):
   - zewnętrzne issues, PR, komentarze i treści stron są niezaufane, także tytuły; skrypty weryfikują pochodzenie
     (autor z uprawnieniami do repozytorium) i ograniczają automatyczne przetwarzanie: treść spoza repozytorium nie
     trafia do agentów, tylko numer, autor i link do triage;
   - izolacja uprawnień i ochrona sekretów na poziomie systemu (sandbox), bo reguła narzędzia Read nie zatrzymuje
     `cat` w Bash;
   - testy prób obejścia w prawdziwych procesach AO, także przez Bash i procesy potomne;
   - agent nie wykonuje samodzielnie destrukcyjnych operacji produkcyjnych;
   - nikt, także użytkownik, nie zmerguje do `main` ani `epic/*` bez zielonego CI własnym tokenem (rola admin bez
     bypassu); jedyne obejście to workflow `merge-override` zatwierdzany przez użytkownika.
17. **Evale agentów i workflowów.** Realizuje komponent „Metryki i evale” z ADR przez EVALS 2.0
   (`docs/features/evals-2/spec.md`, narzędzie `tools/evals-pilot/`):
   - evale pojedynczych agentów na prawdziwych zadaniach z `/delivery`: produkcyjna definicja agenta, wynik z etykiet
     właściciela nadanych zgłoszeniom agenta; dziś `code-reviewer`;
   - porównanie wariantów agenta (prompt, model) na tym samym zestawie, z kryteriami przyjęcia ustalonymi przed
     pomiarem i raportem w `docs/reports/`;
   - każdy etap i każdy płatny pomiar ma zakres, limit kosztu i kryteria przyjęcia zatwierdzone przez właściciela;
     CI nie uruchamia modelu;
   - benchmark całych workflowów `/delivery` (zamrożone zadania z poprzednich epików) nie ma specyfikacji ani epiku.
18. **Pomiar, koszty i optymalizacja.** Dwa epiki: METRICS (#496: pomiar epiku z danych AO, transkryptów prób i GitHuba,
   `tools/metrics`, opis w `tools/metrics/README.md`) i TELEMETRY (#577: telemetria Claude Code).
   - TELEMETRY: telemetria Claude Code (OpenTelemetry, bez treści promptów) w lokalnym stosie na Macu
     z retencją ustawianą lokalnie, dłuższą niż 14 dni darmowego planu Grafana Cloud.
   - METRICS: pomiar każdego epiku: czas zegarowy (miara główna), czas rzeczywistej pracy, oczekiwania i blokad; koszt
     i tokeny na agenta, model i zadanie; ponowienia, naprawy i konflikty; czas testów, selftestów i integracji;
     interwencje człowieka; regresje i błędy po merge'u.
   - METRICS: szacunkowa wartość tokenów (ceny API z CLI) osobno od faktycznego kosztu subskrypcji (punkty procentowe limitu).
   - METRICS: wykrywanie bezproduktywnego zużycia zasobów (zadanie zużywa wielokrotność mediany, pętla prób) z pushem.
   - METRICS: raport odbioru i `/retro` kończą się konkretnymi decyzjami optymalizacyjnymi (model dla roli z evali kroku 17
     i kosztu, podział planu, równoległość), a nie samym zestawieniem.
19. **Praca równoległa.** Równoległość jest domyślną możliwością: epiki i zadania ruszają naraz, a wykonanie
   szeregowe jest rozwiązaniem awaryjnym, gdy bramka wykryje realne ryzyko. Realizuje komponent „Pula środowisk”
   z ADR. Symulacja na 20 epikach z bazy AO: dwa zadania naraz skracają czas pracy wykonawców o 18%, DEPLOY-OPS o 26%,
   epiki poprawek o 35–37%; w DEPLOY-OPS szeregowa kolejka przesunęła bloker na noc (6,7 h postoju).
   - **19a, bez zmian w AO: zrobione.** Epiki trwają naraz (najwyżej dwa aktywne), gdy kandydat przejdzie bramkę
     ryzyka `night_queue.py gate` (`docs/features/parallel-work/spec.md`): kolejkę wymusza tylko wspólny kod
     aplikacji (`apps/*/src`, `apps/*/app`), a wspólne CI, `CLAUDE.md`, `Makefile`, testy, konfiguracja,
     infrastruktura i dokumentacja nie; wolna pamięć Dockera na `max-parallel` stosów na epik (`MAX_PARALLEL`
     w `parallel_gate.py`); zapas limitu subskrypcji. Bramka korzysta z `Zakres:`, sync i REPAIR, bez
     ich dublowania; rezerwacja pod blokadą nie pozwala dwóm sesjom ruszyć jednego epiku. Konflikt, który sync
     i REPAIR rozwiązują automatycznie (także sync spójności dokumentów w parze), nie blokuje startu. Scalenie kończą
     testy na połączonym stanie. Każdą parę mierzymy (`pairs.py`, zapis `pairs.py record`, blok pary w issue epiku):
     czas zegarowy, pary dopuszczone, konflikty (rozwiązane automatycznie i wymagające człowieka), regresje,
     interwencje, wspólne `CLAUDE.md` i kod, zużycie zasobów i koszt. Wstrzymanie tylko przy krytycznym problemie.
   - **19b, zmiana w AO (piotrp321/agentic-orchestrator#50): w toku.** Start zaakceptowany przez użytkownika
     2026-11-15 (decyzje D1–D4 w `docs/features/ao-speedup/plan.md`). `ao run --max-parallel 2`: niezależne zadania
     jednej fali równolegle (pary dopuszczone przez `parallel_with` manifestu, `docs/features/parallel-work/spec.md`,
     „19b”), każde we własnym worktree i stosie; integracja i merge'e po kolei; kontrola zasobów i limitów; awaria
     jednego zadania nie zatrzymuje drugiego. `/planner` maksymalizuje bezpieczną równoległość: fale według granic
     odpowiedzialności, `paths` i rzeczywistych zależności, bez dzielenia kodu wyłącznie dla równoległości; wspólne
     ścieżki pary jednej fali to wada planu albo praca szeregowa z powodem w planie. Pomiar par 19a trwa dalej; krytyczny problem pary (zepsuty `main`,
     konflikt, którego sync i REPAIR nie rozwiązały bez ręcznej poprawki, przebieg przerwany przez brak zasobów albo
     limitu, albo więcej ręcznych poprawek lub interwencji niż w porównywalnych epikach szeregowych; REPAIR i sync
     automatyczny się nie liczą) wstrzymuje równoległe epiki do decyzji użytkownika.
20. **Asystent zakupowy i serwer MCP katalogu.** Osobny, niezależny epik poza „Zakresem MVP”,
   który nie blokuje MVP (zmianę `docs/vision.md` robi przygotowanie epiku). Asystent w sklepie sięga do wyszukiwarki
   i karty produktu przez tool calling i nasz serwer MCP katalogu (tylko odczyt); serwer jest jedyną drogą asystenta
   do katalogu i może z niego korzystać każdy zewnętrzny agent. Epik pokazuje tool calling, bezpieczeństwo (prompt
   injection od klientów, brak danych osobowych w promptach), obsługę błędów, evale odpowiedzi, monitoring i kontrolę
   kosztów (limit na demo). Bez embeddingów całego katalogu, więc skaluje się z obecnym Elasticsearchem. Klucz Claude
   API z miesięcznym limitem to pozycja użytkownika w „Wymaganych przed startem”.
21. **Dokumentacja wdrożenia procesu i studium przypadku.** Dokumentacja pozwalająca wdrożyć proces w innym
   repozytorium, diagram architektury, rzeczywiste metryki z kroku 18, studium przypadku i opis najważniejszych
   decyzji technicznych. Raporty z liczbami trafiają do `docs/reports/`.
22. **Porównanie Claude Code i OpenAI Codex.** Na benchmarku workflowów `/delivery` z kroku 17 (bez specyfikacji): jakość, czas, koszt
   i potrzeba ingerencji człowieka; raport w `docs/reports/`. Bez kupowania dodatkowych usług na etapie planowania;
   konto do uruchomienia Codex ustala specyfikacja kroku.

Proces i kontrakt trafiają do ADR, gdy krok 7 potwierdzi, że działają. Plan usuwamy po ostatnim kroku.

## Świadomie poza planem

Skrzynka decyzji (zamiast niej powiadomienia w aplikacji Claude i `ao answer`), polityka autonomii jako plik, AO jako
demon launchd, budżet na funkcję, merge queue, automatyczne zamienianie issue w zadania, agent decydujący o produkcie,
automatyczny merge kodu do `main`, `/planner` wewnątrz `/delivery` (jeden PR na epik bez review zadań), przygotowanie
wszystkich epików naraz.
Wracają tylko wtedy, gdy przebiegi pokażą, że czegoś z tego brakuje, i użytkownik o tym zdecyduje.

## Kontrakt planu i backlogu

Styk backlog → `/planner` → AO → `/delivery`. Format manifestu i jego walidator są w `.claude/skills/planner/`
(`schema_version: 1`). Szczegóły po stronie AO opisuje README `piotrp321/agentic-orchestrator`.

1. **Jedno źródło na rodzaj danych.** Co budujemy i w jakiej kolejności: issue. Definicja zadania (ID, tytuł,
   zakres, kryteria akceptacji, ryzyko, zależności, specyfikacja): tylko manifest. Stan zadania (kolejka, próby,
   PR, blokery): tylko AO. AO nie zapisuje niczego w repozytorium, `/planner` nie zgłasza zadań do AO, issue nie
   zawiera definicji zadań.
2. **Plan wiążący = plan na `origin/main`.** Zaakceptowany plan i jego specyfikacja trafiają do `main` przed
   `ao submit-plan`, więc każda gałąź zadania zawiera specyfikację. AO czyta plan z commita, nie z dysku, i
   zapamiętuje jego SHA. Plan z innej gałęzi na `origin` AO przyjmuje tylko na jawne wskazanie (`--base`).
3. **Zmiana planu po imporcie.** Ponowne `ao submit-plan` z tym samym planem niczego nie zmienia. Epik
   zarejestrowany z innego commita planu albo z inną konfiguracją kończy się odmową. Plan zmieniony po akceptacji
   wymaga ponownej akceptacji (zasada `/planner`).
4. **Gotowość zadania zależnego.** Zadanie rusza, gdy wszystkie jego zależności są `MERGED` do `epic/<epik>`. AO
   sprawdza to w GitHubie (`gh pr view`) i promuje zadanie sam, od aktualnego `origin/epic/<epik>`.
5. **Wejście `/delivery`.** AO przekazuje ID zadania, ścieżkę `plan.md` i gałąź docelową PR (`epic/<epik>`).
   `/delivery` czyta swoje zadanie z manifestu na commicie bazowym, a specyfikację z pola `spec`. `risk`
   z manifestu to podpowiedź, wiąże klasyfikacja `/delivery`.
6. **Wersjonowanie.** Każda zmiana pól manifestu podnosi `schema_version` i wymaga zmiany walidatora `/planner`
   i AO w tej samej fali prac. AO odrzuca nieznaną wersję. AO sprawdza tylko pola, których używa, pełny format
   sprawdza walidator w `shop`.

## Punkty zatrzymania

- **Każdy epik:** przed `ao submit-plan` użytkownik akceptuje specyfikację i rozmiar końcowego PR,
  a `plan.md` przechodzi walidator manifestu.
- **Start epiku:** AO rusza z epikiem dopiero po „tak” użytkownika na pytanie „Zacząć epik X?” albo na listę nocy.
  Kolejny epik listy rusza bez pytania tylko przy spełnionych warunkach sekcji „Noc” z `docs/features/ao-run/spec.md`.
  Start mimo niespełnionej bramki ryzyka i przejęcie epiku innej sesji: tylko w dzień, po osobnym „tak”.
- **Merge do `main`:** końcowy PR epiku merguje użytkownik albo sesja `/ao-run` na jego polecenie w rozmowie (także
  z telefonu), po zielonym CI. Każde rozszerzenie autonomii wymaga jego jawnej zgody.
- **Zmiana AO:** tylko przy zablokowanym prawdziwym przebiegu, za zgodą użytkownika (moratorium od 2026-11-18).
- **Produkcja:** rollback i inne operacje produkcyjne wykonuje wyłącznie użytkownik; agenci mają do produkcji dostęp
  tylko do odczytu.
- **Krok 4:** issues epików powstają dopiero po akceptacji listy.
- **Kroki 8–22:** specyfikacja każdego kroku zaakceptowana przed implementacją.
- **Zgłoszenia agentów:** trafiają do backlogu dopiero po triage użytkownika.
