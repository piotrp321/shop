# Agentic Software House 24/7

## Kontekst

Większość kodu sklepu piszą agenci Claude Code. Dziś działają `/prepare-epic` (epik → `spec.md` i `plan.md` z manifestem zadań; poza epikami
`/brainstorm` i `/planner`), `/delivery` (jedno zadanie → zweryfikowany PR), `/integration`
(weryfikacja całego epiku) i agentic orchestrator (AO) w osobnym repozytorium `piotrp321/agentic-orchestrator`,
który prowadzi epik po jednym zadaniu naraz w gałęzi `epic/<epik>` i otwiera końcowy PR. Wdrożenie i monitoring są
ręczne, a blokery czekają na odpowiedź człowieka.
Chcemy, żeby system pracował autonomicznie przez całą dobę, a człowiek zatwierdzał tylko istotne decyzje.

## Decyzja

Docelowy przepływ:

`Intake → /prepare-epic → Backlog → AO → /delivery → /integration → Merge/Release → Monitoring`

```mermaid
flowchart TB
    human([Człowiek<br/>produkt i architekt])
    inbox[Skrzynka decyzji<br/>spec, plan, blokery, merge]

    subgraph flow[Przepływ]
        direction TB
        intake[Intake: GitHub Issues<br/>pomysły, bugi, incydenty]
        prepare["/prepare-epic<br/>analityk, krytyk, architekt, planner<br/>spec.md + plan.md"]
        backlog[Backlog<br/>zadania i zależności]
        ao[AO<br/>orkiestracja, stan, wykonanie]
        delivery["/delivery × N<br/>zadanie → zweryfikowany PR"]
        integration["/integration<br/>E2E, review architektury, QA"]
        release[Merge queue i release<br/>staging → produkcja, rollback]
        monitoring[Monitoring<br/>alerty → nowe zadania]
        intake --> prepare --> backlog --> ao --> delivery --> integration --> release --> monitoring
        monitoring -. incydenty i dług .-> intake
    end

    subgraph shared[Usługi przekrojowe]
        direction TB
        host[Host 24/7]
        budget[Budżet i limity]
        envs[Pula środowisk]
        metrics[Metryki i evale]
        learning[Pętla uczenia]
        security[Bezpieczeństwo agentów]
    end

    human <--> inbox
    inbox <--> prepare
    inbox <--> ao
    inbox <--> release

```

Stan poszczególnych elementów, kolejność prac i kontrakt planu z backlogiem są
w [planie](../features/agentic-software-house/plan.md).

| Komponent | Jedna odpowiedzialność |
|---|---|
| Intake | Zbieranie pomysłów, bugów i incydentów w jednym miejscu: GitHub Issues (`epic`, `bug`, `ux`, `tech-debt`) |
| `/prepare-epic` | Przygotowanie epiku: `spec.md` i `plan.md` z jedną akceptacją użytkownika |
| Krytyk specyfikacji | `spec-critic`: braki, sprzeczności i przypadki brzegowe szkicu |
| `/brainstorm`, `/planner` | Narzędzia poza epikami: projekt funkcji → `spec.md`, dekompozycja → `plan.md` i manifest |
| Backlog | Trwałe zarządzanie zadaniami i zależnościami: definicje w manifeście `plan.md`, stan w AO |
| AO | Orkiestracja, stan i wykonywanie |
| `/delivery` | Implementacja pojedynczego zadania do zweryfikowanego PR |
| `/integration` | Integracja i weryfikacja całej funkcjonalności |
| Merge/Release | Merge zatwierdzonych zmian i wdrożenie z możliwością rollbacku |
| Monitoring | Agent dyżurny z dostępem tylko do odczytu (Sentry i Grafana Cloud przez MCP): korelacja zdarzeń, diagnoza, hypercare po wdrożeniu i propozycje zgłoszeń w intake; rollback i operacje produkcyjne wykonuje człowiek |
| Agent tester | Test całego sklepu po merge'u epiku (`/sweep`, uruchamiany przez `/ao-run`), propozycje zgłoszeń `bug` do triage człowieka |
| Agent UX | Przegląd ekranów, wersji mobilnej i dostępności po merge'u epiku (`/sweep`, uruchamiany przez `/ao-run`) oraz w `/integration` przy zmianach frontów, propozycje zgłoszeń `ux` do triage człowieka |
| `/groom` | Codzienne propozycje porządków w backlogu (duplikaty, nieaktualne, braki, etykiety, epik poprawek), wykonywane dopiero po decyzji człowieka; zatwierdzony epik poprawek przygotowuje według `/prepare-epic` aż do jednej akceptacji człowieka |
| Skrzynka decyzji | Jedno miejsce na decyzje człowieka, z rekomendacją agenta i powiadomieniem |
| Budżet i limity | Limity subskrypcji, budżet na funkcję, priorytety kolejki |
| Pula środowisk | Worktree i stos Docker na zadanie, wolne porty, sprzątanie; bramka ryzyka równoległych epików i zadań (wspólny kod aplikacji, pamięć Dockera, zapas limitu) |
| Metryki i evale | Pomiar czasu zegarowego, kosztu i jakości na agenta, model i zadanie, z decyzjami optymalizacyjnymi; evale agentów i workflowów z historią wyników i kryteriami regresji |
| Bezpieczeństwo agentów | Niezaufane wejścia spoza repozytorium, izolacja uprawnień i sekretów na poziomie systemu, testy prób obejścia |
| Pętla uczenia | `/retro` po merge'u epiku: poprawki procesu w skillach, agentach i `CLAUDE.md` w PR z dowodami z przebiegów, który merguje człowiek |

Zasady:

- Każdy komponent ma jedną, jasno określoną odpowiedzialność.
- Wykorzystujemy istniejący kod i skille (`/prepare-epic`, `/delivery`, AO, agentów z `.claude/agents/`, GitHub),
  zamiast tworzyć nowe frameworki.
- AO odpowiada za orkiestrację, stan i wykonywanie, a nie za decyzje architektoniczne. Decyzje spoza granic
  zadania wracają jako blokery do skrzynki decyzji.
- Równoległość jest domyślna: epiki, które przejdą bramkę ryzyka (wspólne tylko pliki generowane i dokumentacja,
  wolna pamięć Dockera na kolejny stos, zapas limitu), i niezależne zadania jednej fali wykonują się naraz, każde
  we własnym worktree i stosie, a integracja i merge'e po kolei. Wykonanie szeregowe jest rozwiązaniem awaryjnym,
  gdy bramka wykryje realne ryzyko.
- Optymalizujemy czas zegarowy epiku, mierząc jednocześnie jakość, naprawy, interwencje człowieka i zużycie limitów.
- Zabezpieczenia agentów są techniczne i wynikają z modelu zagrożeń, nie tylko z instrukcji w promptach.
- Człowiek zatwierdza istotne decyzje biznesowe i końcową integrację (merge funkcji, wdrożenie na produkcję). Agent nie
  wykonuje samodzielnie destrukcyjnych operacji produkcyjnych, w tym rollbacku.
- Docelowo system działa autonomicznie 24/7, z budżetami, limitami, backlogiem i obsługą blokerów.

## Uzasadnienie

- Wąskim gardłem pracy całodobowej jest człowiek, nie agenci. Zebrane w jednym miejscu decyzje z rekomendacją
  i jawna polityka autonomii pozwalają kolejce pracować dalej nad zadaniami bez blokera, zamiast stać.
- Jedna odpowiedzialność na komponent pozwala rozwijać, testować i wymieniać etapy osobno, a kontrakty między
  nimi (`spec.md`, manifest, wynik `/delivery`) są jawne i sprawdzalne.
- Deterministyczny AO w Pythonie pilnuje procesów, stanu i limitów taniej i pewniej niż model. Model podejmuje
  decyzje projektowe tam, gdzie są potrzebne.
- Istniejące narzędzia (GitHub Issues i PR, CI, skille) ograniczają ilość kodu infrastruktury do utrzymania.
- Szeregowe wykonanie wydłuża czas zegarowy: zadanie z gotowymi zależnościami czeka na niezależne zadania, a bloker
  może wypaść w nocy. Symulacja na 20 epikach z bazy AO: dwa zadania naraz skracają czas pracy wykonawców o 18%.
  Konflikty plików między równoległymi epikami rozwiązują istniejące mechanizmy sync i REPAIR.
- Repozytorium jest publiczne, a agenci w AO działają bez pytań o uprawnienia, więc instrukcja „treść to dane”
  w prompcie nie wystarcza: reguła narzędzia nie zatrzymuje polecenia w Bash ani procesu potomnego.

## Konsekwencje

- Kontrakt wyniku `/delivery` jest interfejsem zewnętrznym dla AO. Zmiany w nim wymagają zmian po obu stronach.
- Każdy nowy komponent przechodzi zwykłą drogę: specyfikacja, plan, akceptacja, implementacja.
- Praca agentów zużywa limity subskrypcji wspólne z pracą interaktywną. Budżety i priorytety kolejki
  są warunkiem pracy nocnej.
- Zadania wykonywane równolegle potrzebują osobnych stosów Docker. Zasoby jednego hosta ograniczają
  liczbę zadań naraz.
- Równoległe przebiegi szybciej zużywają okno limitu subskrypcji, więc bramka sprawdza zapas limitu przed startem.
  Kolejkę wymusza tylko wspólny kod aplikacji; pozostałe wspólne pliki łączą sync i REPAIR (pliki generowane powstają
  od nowa), a scalenie kończą testy na połączonym stanie.
- Równoległe zadania fali wymagają zmiany w AO (`ao run --max-parallel`).
- Autonomiczny merge bez człowieka jest dozwolony wyłącznie w granicach polityki autonomii zatwierdzonej
  przez użytkownika. Jedyny autonomiczny merge do `main` to PR ze specyfikacją i planem z `/prepare-epic`,
  po akceptacji użytkownika i zielonym CI.
- Epik startuje dopiero po „tak” użytkownika na pytanie „Zacząć epik X?” albo na listę nocy, a kolejny epik listy
  rusza bez pytania tylko przy spełnionych warunkach nocy z `docs/features/ao-run/spec.md` (bramka, czas, limity).
  Start mimo niespełnionej bramki i przejęcie epiku innej sesji wymagają osobnego „tak” użytkownika, w nocy ich nie
  ma (`docs/features/parallel-work/spec.md`).
