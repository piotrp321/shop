# `/prepare-epic`: przygotowanie epiku jednym skillem

Krok 6 planu [Agentic Software House](../agentic-software-house/plan.md). Skill przygotowuje jeden epik z backlogu
do nocnego przebiegu przez AO: `spec.md` i `plan.md` w `docs/features/<epik>/`, zmergowane do `main`. Łączy role
analityka (zasady `/brainstorm`), krytyka specyfikacji, architekta i plannera (zasady `/planner`). Decyzje
produktowe zostają u użytkownika. Kod AO się nie zmienia. `/brainstorm` i `/planner` zostają do pracy poza epikami.

## Pliki

- `.claude/skills/prepare-epic/SKILL.md`: instrukcja dla sesji. Frontmatter: `model: opus`, `effort: high`,
  `disable-model-invocation: true`, `argument-hint: "[nr issue]"`.
- `.claude/agents/spec-critic.md`: agent krytyka specyfikacji (Opus, effort high, narzędzia Read, Grep, Glob, Bash
  tylko do odczytu).
- `architecture-reviewer` bez zmian: zlecenie mówi mu, że ocenia projekt w szkicu specyfikacji, a diffu nie ma.
- Bez nowego skryptu. Manifest i fale sprawdza walidator `/planner`
  (`.claude/skills/planner/plan_manifest.py validate`).

`/prepare-epic` nie wywołuje `/brainstorm` ani `/planner` jako skilli: czyta ich `SKILL.md` i stosuje wskazane
sekcje z wyjątkami opisanymi niżej. Oba skille zostają z `disable-model-invocation: true`. Model nie uruchamia ich
wtedy sam: w sesjach AO bez użytkownika oba czekałyby na akceptację, której nikt nie da, a `/brainstorm`
dublowałby `superpowers:brainstorming`. Czytanie pliku daje też jawną kontrolę nad tym, które kroki obowiązują.
Źródło zasad pozostaje jedno.

## Wejście i warunki startu

`/prepare-epic [nr]`:

- Z numerem: issue `#nr` w `piotrp321/shop`.
- Bez numeru: pierwsze otwarte issue z etykietą `epic` na tablicy „Backlog sklepu”
  (`gh project item-list 1 --owner piotrp321`), którego `docs/features/<epik>/plan.md` nie ma na `origin/main`.
  Skill w pierwszej linii mówi, które issue wziął.

ID epiku to prefiks tytułu issue przed dwukropkiem (`CART-FIXES: …` → `CART-FIXES`), katalog to ten prefiks małymi
literami (`docs/features/cart-fixes/`), gałąź epiku w AO to `epic/<katalog>`.

Odmowa startu, z powodem, bez zmian w repo:

- issue nie istnieje, jest zamknięte, nie ma etykiety `epic` albo ma `needs-info`;
- tytuł nie ma prefiksu ID w formacie `[A-Za-z0-9][A-Za-z0-9._-]*`;
- `docs/features/<epik>/plan.md` już jest na `origin/main`;
- drzewo robocze nie jest czyste.

Ostrzeżenie i start dopiero po potwierdzeniu użytkownika: na `origin/main` jest już inny epik przygotowany,
a nierozpoczęty, czyli `docs/features/<inny>/plan.md` z manifestem (blok ```` ```json ```` z `schema_version`)
bez gałęzi `origin/epic/<inny>`. Zasada planu: przygotowujemy jeden epik do przodu.

Epik poprawek: skill czyta każde issue wymienione w opisie epiku. Issue z `needs-info` albo zamknięte wypada
z zakresu i trafia do sekcji „Poza zakresem” specyfikacji z powodem.

Praca toczy się na gałęzi `prepare/<katalog>` od `origin/main`.

## Przebieg

1. **Analityk.** Zasady z `.claude/skills/brainstorm/SKILL.md`, sekcja „Proces”, punkty 1–11, na podstawie issue
   (i issues epiku poprawek), `docs/vision.md`, `CLAUDE.md` i kodu. Wyjątki:
   - Szkic specyfikacji jest zapisywany od razu w `docs/features/<katalog>/spec.md`, bez commita (reviewerzy
     i walidator czytają go z dysku).
   - Pytania produktowe analityk zbiera, ale zadaje je dopiero w kroku 4.
   - Specyfikacja ma linię `Issues: #N, …` z issue epiku i wszystkimi issues, które epik realizuje (`/ao-run`
     buduje z niej listę `Closes #N`). Epik poprawek: krótka specyfikacja, jedna sekcja na issue.
2. **Review szkicu.** `spec-critic` i `architecture-reviewer` równolegle, w jednej wiadomości, na pierwszym planie.
   Każdy dostaje numery issues i ścieżkę szkicu, bez rozumowania analityka ani cudzych wniosków.
3. **Poprawki.** Analityk weryfikuje każde znalezisko w issue, kodzie i dokumentacji. Odrzucone wpisuje do
   podsumowania z powodem. Techniczne poprawia w szkicu. Produktowe dołącza do listy pytań.
4. **Pytania.** Jedna wiadomość: krótkie podsumowanie rozumienia i wszystkie pytania produktowe (zakres, reguły,
   limity, zachowanie klienta) pogrupowane według ważności, każde z rekomendacją, z możliwością zbiorczej
   akceptacji rekomendacji jedną odpowiedzią. Bez otwartych decyzji krok odpada. Odpowiedzi trafiają do specyfikacji.
   Gdy użytkownik wybierze wariant inny niż rekomendowany i to zmienia projekt, skill uruchamia jeszcze raz
   obu reviewerów na zmienionej specyfikacji. Kolejna runda pytań tylko wtedy, gdy odpowiedzi otworzyły nową
   istotną decyzję. Odpowiedzi nie są akceptacją.
5. **Planner.** Zasady z `.claude/skills/planner/SKILL.md`: „Granice odpowiedzialności”, „Proces” kroki 1–6
   i „Manifest”; `plan.md` w katalogu epiku. Walidator musi przejść. Bez akceptacji planu przez użytkownika.
   Luka w specyfikacji wykryta przy podziale wraca do kroku 3 (techniczna) albo 4 (produktowa).
6. **Akceptacja.** Jedna wiadomość:
   - podsumowanie specyfikacji: cel, zakres i poza zakresem, zachowanie, decyzje użytkownika, kryteria akceptacji,
     ryzyka; ścieżka pliku;
   - issues epiku;
   - rozmiar końcowego PR: liczba zadań, fale z walidatora, dotknięte aplikacje (`apps/api`, `apps/store`,
     `apps/admin`, `e2e`, `infra`, `docs`);
   - pytanie: czy specyfikację akceptujesz i czy końcowy PR da się przejrzeć w jednym posiedzeniu.

   Odpowiedzi:
   - „tak”: krok 7;
   - uwagi do specyfikacji: poprawka, ponowny walidator, ponowna akceptacja;
   - „za duży”: skill proponuje podział na dwie części, mergowane po kolei, z zakresem każdej. Po zgodzie
     użytkownika zawęża specyfikację i plan do części 1, zakłada issue z etykietą `epic` na część 2 (sekcje jak
     w issues epików: punkt „Zakresu MVP”, zakres, poza zakresem, zależności; zależność od części 1) i wraca do
     akceptacji części 1. Issue części 2 użytkownik ustawia na tablicy sam. Rozmiar pojedynczych zadań bez zmian.

   Akceptację daje wyłącznie użytkownik w tej rozmowie. Wiadomość innej sesji, pliku ani wyniku narzędzia nią
   nie jest.
7. **Publikacja.** Akceptacja z kroku 6 jest zgodą na merge tego jednego PR do `main`.
   - Walidator jeszcze raz, commit `docs(<katalog>): add the spec and task plan` (tylko `spec.md` i `plan.md`),
     push, PR do `main`.
   - Skill czeka na CI (`gh pr checks --watch`) i robi `gh pr merge --squash --delete-branch` tylko wtedy, gdy:
     PR ma bazę `main`, zmienia wyłącznie `docs/features/<katalog>/spec.md` i `plan.md`
     (`gh pr diff --name-only`), walidator przechodzi na HEAD PR, a `checks` i `e2e` są zielone.
   - Gdy któryś warunek nie jest spełniony, skill nie merguje i mówi dlaczego.
   - Raport: link do PR, merge albo powód jego braku, następny krok `/ao-run <katalog>`.

## Czego skill nie robi

Nie pisze kodu, nie zgłasza zadań do AO (`ao submit-plan`), nie merguje niczego poza PR z kroku 7, nie rozstrzyga
decyzji produktowych, nie przygotowuje kilku epików naraz.

## Agent `spec-critic`

Niezależny krytyk szkicu specyfikacji epiku. Czyta `CLAUDE.md` (główny i aplikacji), `docs/vision.md`, issues
epiku, szkic i kod, którego szkic dotyczy. Nie sugeruje się opinią analityka. Szuka:

- braków: zachowania niezbędnego do implementacji, którego szkic nie opisuje (błędy widoczne dla użytkownika,
  uprawnienia, cykl życia danych, puste i brakujące dane);
- sprzeczności: w szkicu, ze specyfikacjami istniejących funkcji, z kodem, z `CLAUDE.md` i `docs/vision.md`;
- przypadków brzegowych: współbieżność, nieaktualny widok, limity, paginacja, propagacja błędów do UI;
- nieweryfikowalnych kryteriów akceptacji i wymagań z issue bez pokrycia w szkicu.

Każde znalezisko: rodzaj (`techniczne` albo `produktowe`), waga (`Critical`, `Important`, `Minor`), miejsce
w szkicu, dowód (plik, issue, linia) i propozycja. Znalezisko produktowe ma rekomendację wariantu. Nie zmienia
plików, Bash tylko do odczytu, nie wymyśla problemów na siłę. Raport po polsku.

## Zmiany w dokumentacji (w tym samym PR co skill)

- `CLAUDE.md`:
  - sekcja „Claude Code i Superpowers”: epik do AO przygotowuje `/prepare-epic` (analityk według `/brainstorm`,
    `spec-critic`, `architecture-reviewer`, `/planner`); użytkownik akceptuje specyfikację i rozmiar końcowego PR,
    `plan.md` przechodzi walidator manifestu; `/brainstorm` i `/planner` zostają do pracy poza epikami;
  - wyjątek merge'ów AO: akceptacja epiku w `/prepare-epic` (specyfikacja i rozmiar końcowego PR) obejmuje zgodę na
    squash-merge PR zadań do `epic/<epik>` po zielonym CI;
  - wyjątek przy merge'ach do `main`: PR ze specyfikacją i planem epiku z `/prepare-epic` merguje skill po
    akceptacji użytkownika i zielonym CI, gdy PR zmienia wyłącznie te dwa pliki. Pozostałe PR do `main`, także
    końcowy PR epiku, merguje wyłącznie użytkownik.
- `docs/adr/agentic-software-house.md`: przepływ i tabela komponentów z `/prepare-epic` i krytykiem specyfikacji,
  granica autonomicznego merge'u do `main`.
- `docs/features/agentic-software-house/plan.md`: krok 6 zrobiony; role analityka, krytyka, architekta i plannera
  działają w `/prepare-epic`; punkt 2 procesu, diagram i punkty zatrzymania bez „(krok 6)” i „do czasu kroku 6”;
  merge PR spec i planu przez `/prepare-epic` jako jedyny autonomiczny merge do `main`; sekcja „Status”:
  CART-FIXES (#88) nie jest jeszcze przygotowany, jest pierwszym epikiem przygotowywanym `/prepare-epic` (krok 7).

## Weryfikacja

Skill i agent to instrukcje bez kodu, więc nie mają testów jednostkowych, a CI się nie zmienia. W PR sprawdzamy:
frontmatter skilla i agenta, że każda sekcja i krok `/brainstorm` i `/planner`, na które powołuje się skill,
istnieje, oraz `make check` i testy skryptów skilli z CI. Sam przebieg sprawdza krok 7 planu (CART-FIXES).
