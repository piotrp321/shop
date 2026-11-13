# `/prepare-epic`: przygotowanie epiku jednym skillem

Krok 6 planu [Agentic Software House](../agentic-software-house/plan.md). Skill przygotowuje jeden epik z backlogu
do nocnego przebiegu przez AO: `spec.md` i `plan.md` w `docs/features/<epik>/`, zmergowane do `main`. Łączy role
analityka (zasady `/brainstorm`), krytyka specyfikacji, architekta i plannera (zasady `/planner`). Decyzje
produktowe oraz istotne decyzje DDD i architektoniczne zostają u użytkownika. Kod AO się nie zmienia.
`/brainstorm` i `/planner` zostają do pracy poza epikami.

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

`/prepare-epic [nr]`. Pierwsza komenda to `git fetch origin main`: wybór issue i warunki startu czytają
`origin/main`.

- Z numerem: issue `#nr` w `piotrp321/shop`.
- Bez numeru: pierwsze otwarte issue z etykietą `epic` i bez `needs-info` na tablicy „Backlog sklepu”
  (`gh project item-list 1 --owner piotrp321 --format json --limit 200`), którego `docs/features/<epik>/spec.md`
  ani `plan.md` nie ma na `origin/main`. Zamknięte i z `needs-info` skill pomija. Skill w pierwszej linii mówi,
  które issue wziął.

ID epiku to prefiks tytułu issue przed dwukropkiem (`CART-FIXES: …` → `CART-FIXES`), katalog to ten prefiks małymi
literami (`docs/features/cart-fixes/`), gałąź epiku w AO to `epic/<katalog>`.

Odmowa startu, z powodem, bez zmian w repo:

- issue nie istnieje, jest zamknięte, nie ma etykiety `epic` albo ma `needs-info`;
- tytuł nie ma prefiksu ID w formacie `[A-Za-z0-9][A-Za-z0-9._-]*`;
- `docs/features/<epik>/spec.md` albo `plan.md` już jest na `origin/main`;
- gałąź `prepare/<katalog>` już jest lokalnie albo na `origin`;
- drzewo robocze nie jest czyste.

Ostrzeżenie i start dopiero po potwierdzeniu użytkownika: przygotowane, a nierozpoczęte epiki na `origin/main`
(`docs/features/<inny>/plan.md` z manifestem bez gałęzi `origin/epic/<inny>`) wypełniają już noc, czyli
`python3 .claude/skills/ao-run/night_queue.py ahead` zwraca `full: true`. Zasada planu: przygotowujemy tyle epików do
przodu, ile mieści noc (krok 8). Bez pomiaru czasu z `/ao-run` to jeden epik do przodu.

Epik poprawek: skill czyta każde issue wymienione w opisie epiku. Issue z `needs-info` albo zamknięte wypada
z zakresu i trafia do sekcji „Poza zakresem” specyfikacji z powodem.

Praca toczy się na gałęzi `prepare/<katalog>` od `origin/main`.

## Przebieg

1. **Analityk.** Zasady z `.claude/skills/brainstorm/SKILL.md`, sekcja „Proces”, punkty 1–11, na podstawie issue
   (i issues epiku poprawek), `docs/vision.md`, `CLAUDE.md` i kodu. Wyjątki:
   - Szkic specyfikacji jest zapisywany od razu w `docs/features/<katalog>/spec.md`, bez commita (reviewerzy
     i walidator czytają go z dysku).
   - Sekcja „Rezultat” nie obowiązuje, poza jedną zasadą: zmiany innych dokumentów (`docs/vision.md`, `CLAUDE.md`,
     specyfikacje istniejących funkcji) analityk wpisuje w specyfikacji jako zadania wykonywane razem
     z implementacją; sam ich nie edytuje. Resztę „Rezultatu” zastępują kroki 2–7.
   - Punkt 3: epiku analityk nie dzieli; podział tylko w kroku 6 („za duży”). Niezależne części opisuje jako sekcje
     jednej specyfikacji.
   - Pytania z punktu 8 analityk zbiera, ale zadaje je dopiero w kroku 4: produktowe i techniczne, o które punkt 8
     każe pytać (istotne decyzje DDD: agregaty, value objecty, granice modułów, persystencja; nowa zależność; zmiana
     zasady projektu).
   - Specyfikacja ma linię `Issues: #N, …` z issue epiku i wszystkimi issues, które epik realizuje (`/ao-run`
     buduje z niej listę `Closes #N`). Epik poprawek: krótka specyfikacja, jedna sekcja na issue.
   - Pod linią `Issues:` specyfikacja ma linię `Zakres: <ścieżka>, …` (bramka pracy równoległej i lista na noc w
     `/ao-run` porównują z niej pliki epików). Ścieżki w repo, bez `/` na początku i końcu, bez `..` i znaków `*?[`, rozdzielone
     przecinkami, opcjonalnie w odwrotnych apostrofach:
     - katalogi modułów, które epik zmienia (`apps/api/src/Catalog`, `apps/api/tests/Functional/Catalog`,
       `apps/store/src/catalog`, `apps/admin/src/prices`);
     - każdy istniejący plik spoza tych katalogów, który epik zmienia: kontrakt i wygenerowany klient
       (`apps/api/openapi.json`, `apps/store/src/api/schema.d.ts`, `apps/api/openapi-admin.json`,
       `apps/admin/src/api/schema.d.ts`), wspólna konfiguracja, `CLAUDE.md` (główny i aplikacji), `docs/vision.md`,
       specyfikacje innych funkcji, istniejące testy E2E;
     - nowe pliki o własnych nazwach w katalogach wspólnych (migracje, nowy plik w `e2e/tests`) nie wchodzą;
       własny `docs/features/<katalog>/` też nie (należy do zakresu zawsze).
     Analityk zapisuje linię w szkicu, planner uzupełnia ją po podziale na zadania (pola `scope` manifestu).
   - Specyfikacja ma sekcję `## Wymagane przed startem`: wszystko, czego zadania potrzebują spoza repo, zanim AO
     je uruchomi. Kategorie: sekrety (nazwa i miejsce), konta i usługi zewnętrzne (rejestr obrazów, DNS, bramka
     płatności, SMTP, serwer), uprawnienia (zakres tokenu, admin repo, dostęp CI do klastra) i decyzje
     infrastrukturalne (artefakty, których nikt nie buduje ani nie publikuje). Każda pozycja ma cel i źródło, które
     się nie starzeje (`istnieje: <miejsce>`, `użytkownik: <co i gdzie>`, `zadanie <ID>`). Gotowość pozycji
     użytkownika potwierdza się w kroku 6, nie w spec. Otwarta decyzja infrastrukturalna jest pytaniem z kroku 4. Epik bez takich potrzeb ma „Brak.” z jednym zdaniem
     uzasadnienia. Powód: bloker zgłoszony w trakcie zadania kosztuje godziny (DEPLOY-3: 2 h 24 min i $17, bo obrazu
     Elasticsearch z wtyczką nikt nie budował ani nie publikował).
2. **Review szkicu.** `spec-critic` i `architecture-reviewer` równolegle, w jednej wiadomości, na pierwszym planie.
   Każdy dostaje numery issues i ścieżkę szkicu, bez rozumowania analityka ani cudzych wniosków.
3. **Poprawki.** Analityk weryfikuje każde znalezisko w issue, kodzie i dokumentacji. Odrzucone wpisuje do
   podsumowania z powodem. Techniczne poprawia w szkicu. Produktowe i architektoniczne (także techniczne, które
   wymagają istotnej decyzji DDD albo architektonicznej) dołącza do listy pytań.
4. **Pytania.** Jedna wiadomość: krótkie podsumowanie rozumienia i wszystkie pytania produktowe (zakres, reguły,
   limity, zachowanie klienta) oraz istotne decyzje DDD i architektoniczne pogrupowane według ważności, każde
   z rekomendacją, z możliwością zbiorczej akceptacji rekomendacji jedną odpowiedzią. Bez otwartych decyzji krok
   odpada. Odpowiedzi trafiają do specyfikacji.
   Gdy użytkownik wybierze wariant inny niż rekomendowany i to zmienia projekt, skill uruchamia jeszcze raz
   obu reviewerów na zmienionej specyfikacji. Kolejna runda pytań tylko wtedy, gdy odpowiedzi otworzyły nową
   istotną decyzję. Odpowiedzi nie są akceptacją.
5. **Planner.** Zasady z `.claude/skills/planner/SKILL.md`: „Granice odpowiedzialności”, „Proces” kroki 1–6
   i „Manifest”; `plan.md` w katalogu epiku. Walidator musi przejść, a `night_queue.py scope` przyjmuje linię
   `Zakres:` szkicu. Wejściem jest szkic po krokach 3–4
   (akceptacja w kroku 6 obejmuje oba pliki); push, PR i merge prowadzi krok 7. Bez osobnej akceptacji planu przez
   użytkownika. Luka w specyfikacji wykryta przy podziale wraca do kroku 3 (techniczna) albo 4 (produktowa albo
   architektoniczna).
6. **Akceptacja.** Jedna wiadomość:
   - podsumowanie specyfikacji: cel, zakres i poza zakresem, zachowanie, decyzje użytkownika (produktowe oraz
     istotne decyzje DDD i architektoniczne), kryteria akceptacji, ryzyka; ścieżka pliku;
   - issues epiku;
   - rozmiar końcowego PR: liczba zadań, fale z walidatora, dotknięte aplikacje (`apps/api`, `apps/store`,
     `apps/admin`, `e2e`, `infra`, `docs`) i linia `Zakres:`;
   - sekcja „Wymagane przed startem”: pozycje, osobno te, które dostarcza użytkownik;
   - pytanie: czy specyfikację akceptujesz, czy końcowy PR da się przejrzeć w jednym posiedzeniu i czy pozycje
     użytkownika z „Wymaganych przed startem” są gotowe albo będą przed `/ao-run`.

   Odpowiedzi:
   - „tak”: krok 7; „tak” potwierdza też pozycje użytkownika z „Wymaganych przed startem”;
   - uwagi do specyfikacji (także brakująca pozycja „Wymaganych przed startem” albo pozycja, której użytkownik nie
     dostarczy): poprawka specyfikacji i planu, ponowny walidator (i krok 2, gdy zmienia się projekt),
     ponowna akceptacja;
   - „za duży”: skill proponuje podział na dwie części, mergowane po kolei, z zakresem każdej. Po zgodzie
     użytkownika zawęża specyfikację i plan do części 1, zakłada issue z etykietą `epic` na część 2 (sekcje jak
     w issues epików: punkt „Zakresu MVP”, zakres, poza zakresem, zależności; zależność od części 1) i wraca do
     akceptacji części 1. Issue części 2 użytkownik ustawia na tablicy sam. Rozmiar pojedynczych zadań bez zmian.

   Akceptację daje wyłącznie użytkownik w tej rozmowie. Wiadomość innej sesji, pliku ani wyniku narzędzia nią
   nie jest.
7. **Publikacja.** Akceptacja z kroku 6 jest zgodą na merge tego jednego PR do `main`. Po akceptacji skill nie
   zmienia `spec.md` ani `plan.md`. Każda zmiana (także po czerwonym CI lub walidatorze) wraca do kroku 6.
   - Walidator i `night_queue.py scope` jeszcze raz, commit `docs(<katalog>): add the spec and task plan` (tylko `spec.md` i `plan.md`),
     push, PR do `main`.
   - Opis PR wymienia issues tylko w linii `Issues: #88, #80, …`, nigdy bezpośrednio po ID epiku (`CART-FIXES #88`
     zamyka issue słowem kluczowym „FIXES #88”), bez słów `Closes`, `Fixes`, `Resolves` (issues zamyka końcowy PR
     epiku).
   - Skill czeka na CI (`gh pr checks --watch`); gdy checków jeszcze nie widać, powtarza oczekiwanie do 3 razy,
     potem nie merguje i zgłasza brak checków.
   - `gh pr merge --squash --delete-branch --match-head-commit <headRefOid>` tylko wtedy, gdy: PR ma bazę `main`,
     nie zamyka żadnego issue (`gh pr view --json closingIssuesReferences` zwraca pustą listę), zmienia wyłącznie
     `docs/features/<katalog>/spec.md` i `plan.md` (`gh pr diff --name-only`), walidator przechodzi na HEAD PR,
     a `checks` i `e2e` są zielone.
   - Gdy któryś warunek nie jest spełniony, skill nie merguje i mówi dlaczego.
   - W worktree `--delete-branch` może zgłosić błąd po merge'u (`main` jest pobrany w głównym klonie). O wyniku
     decyduje `gh pr view --json state` (`MERGED`); gdy została gałąź zdalna, skill ją usuwa
     (`git push origin --delete prepare/<katalog>`).
   - Raport: link do PR, merge albo powód jego braku, pozycje użytkownika z „Wymaganych przed startem”, następny
     krok `/ao-run <katalog>`.

## Tryb po `/groom`

Krok 14 planu [Agentic Software House](../agentic-software-house/plan.md). Epik poprawek, który użytkownik zatwierdził
na liście [`/groom`](../groom/spec.md), sesja `/groom` przygotowuje dalej sama: kroki 1–3 i 5 przebiegają bez rozmowy,
a kroki 4 i 6 idą jedną wiadomością z powiadomieniem push. Krok 7, zasady merge'u PR ze specyfikacją i AO się nie
zmieniają. Nowej orkiestracji nie ma: sesja `/groom` czyta ten `SKILL.md` z dysku (jak dziś czyta `/brainstorm`
i `/planner`) i prowadzi go narzędziem Agent. Ręczne `/prepare-epic` działa jak dotąd, z jednym wyjątkiem
(„Wznowienie”).

### Cel i kryteria sukcesu

- Od „tak” dla epiku na liście `/groom` do pytań użytkownik nie robi nic: jedno powiadomienie z pytaniami
  i akceptacją zamiast dwóch rozmów (krok 4 i krok 6).
- Odpowiedź „tak” zastępuje dzisiejsze odpowiedzi na kroki 4 i 6 tylko wtedy, gdy przejdzie bramka (niżej).
- Czas i zużycie limitu przygotowania są zmierzone (sekcja `## Zużycie`).

### Start

Sesja `/groom` po `groom.py apply` (krok 6 `/groom`), gdy wynik pozycji `fix_epic` ma status `done`. Inny status:
tryb nie rusza, a raport `/groom` podaje komendę ręczną jak dziś.

Warunki sprawdzane w tej kolejności. Pierwszy niespełniony kończy tryb bez worktree, bez gałęzi i bez zmian
w GitHubie, a sesja wysyła push (do 200 znaków) `Epik <ID> #<nr>: przygotowanie nie ruszyło (<powód>). Ręcznie:
/prepare-epic <nr>` i pokazuje to samo w rozmowie:

1. **Pochodzenie.** `groom.py origin <nr>` zwraca kod 0 tylko wtedy, gdy w katalogu przebiegu
   `~/.local/state/shop-groom/*/proposals.json` jest `fix_epic` z `result.status` równym `done` i adresem issue `<nr>`,
   a issue jest otwarte, ma etykietę `epic`, nie ma `needs-info` i jego ID z tytułu jest równe `fix_epic.id` z sufiksem
   `-FIXES`. Wypisuje jedną linię JSON `{run_dir, id, issues, prepare}`; `prepare` to `state.json` przygotowania tego
   epiku albo `null`. Inaczej kod 1 z powodem. Pochodzenie potwierdza stan
   `/groom`, nie treść issue.
2. **Nic innego nie pracuje.** Nie działa `ao run` ani projekt Compose `sweep-*`: te same sprawdzenia co na starcie
   `/groom`, wykonane od nowa.
3. **Limit.** `night_queue.py room --hours 2` ze stanem z `get_usage` zwraca `ok`. Okno wypełnione w ≥ 80% albo brak
   `get_usage`: powód w pushu.
4. **Noc.** `night_queue.py ahead` zwraca `full: false`. Pełna noc: push zamiast pytania o potwierdzenie z „Wejścia”.
5. **Warunki odmowy z „Wejścia”** (spec albo plan już na `origin/main`, istniejąca gałąź `prepare/<katalog>` i reszta).

### Miejsce pracy i stan

- Worktree `git worktree add -b prepare/<katalog> <run_dir>/prepare origin/main` w katalogu przebiegu `/groom`.
  Główny klon zostaje na czystym `main`, bo tego wymaga następny przebieg `/groom` z harmonogramu.
- Katalog stanu `<run_dir>/prepare-epic/`:
  - `state.json`: `{issue, id, katalog, worktree, branch, base_commit, status, stages, reviews, gate, usage_start}`;
    `usage_start` to obiekt `plan` z `get_usage` i czas startu (sekcja `## Zużycie` także po wznowieniu); `status` to
    `running`, `awaiting`, `interactive`, `published` albo `failed`; `base_commit` to `origin/main` z chwili startu;
    `stages` to ukończone etapy w kolejności (`analyst`, `review`, `fixes`, `review2`, `planner`, `acceptance`),
    dopisywane zaraz po ukończeniu etapu; `reviews` to lista `{agent, round, file, ok, critical_resolved,
    critical_open}`: wpis powstaje na końcu review (`agent`, `round`, `file` jako nazwa pliku w katalogu stanu, `ok`),
    liczniki uzupełnia etap poprawek (ocena raportu przez sesję: liczba znalezisk `Critical` poprawionych albo
    odrzuconych i liczba otwartych, z tymi, które wymagają decyzji użytkownika); `gate` to ostatni wynik
    `groom.py gate` albo `null`, gdy bramki nie dało się policzyć;
  - raporty recenzentów, każdy w osobnym pliku (`review-<agent>-<runda>.md`), dosłownie;
  - `acceptance.md`: wiadomość z kroku „Pytania i akceptacja”.
- Gałąź, worktree i stan zostają do odpowiedzi użytkownika. Brak terminu i przypomnień. Worktree usuwa krok 7 po
  merge'u albo użytkownik.

### Przebieg

Sesja `/groom` (Sonnet) tylko prowadzi: uruchamia podagentów, skrypty i push, przekazuje raporty recenzentów
dosłownie, zapisuje stan. Treść `spec.md` i `plan.md` pisze wyłącznie podagent na Opusie.

1. **Analityk.** Podagent `general-purpose` z `model: opus` i `effort: high` dostaje: numer issue, ścieżkę worktree,
   ścieżkę tego `SKILL.md` i polecenie wykonania kroku 1 bez rozmowy. Pytania z punktu 8 `/brainstorm` zbiera
   w raporcie, każde z rekomendacją. Do szkicu wpisuje rekomendowany wariant jako decyzję i oznacza go w raporcie
   (lista decyzji do akceptacji). Nie wymyśla wymagań, których nie da się wyprowadzić z issue, kodu i dokumentacji:
   takie wymaganie to pytanie.
2. **Review.** Sesja uruchamia `spec-critic` i `architecture-reviewer` równolegle, jak w kroku 2 (oba na Opusie
   z własnego frontmattera). Review kończy się poprawnie, gdy agent zwrócił raport, w którym każde znalezisko ma rodzaj
   i wagę, albo raport „brak znalezisk”. Błąd, przerwanie albo raport bez wag: jedno ponowienie, potem review
   niepoprawne.
3. **Poprawki.** Analityk dostaje raporty (kontynuacja tego samego podagenta przez `SendMessage`; gdy kontynuacja jest
   niedostępna, nowy podagent jak w kroku 1 ze ścieżką szkicu i raportami) i postępuje jak w kroku 3. Gdy któreś
   znalezisko `Critical` zostało poprawione albo odrzucone, recenzent, który je zgłosił, sprawdza poprawiony szkic
   jeszcze raz (runda 2, nowy agent, bez raportu z rundy 1). `Critical` zgłoszony w rundzie 2 zostaje otwarty, bez
   rundy 3.
4. **Planner.** Ten sam podagent wykonuje krok 5. Sesja uruchamia walidator manifestu i `night_queue.py scope`
   i zapisuje ich kody wyjścia.
5. **Pytania i akceptacja.** Jedna wiadomość w rozmowie, zapisana też w `acceptance.md`: treść kroku 6, przed nią
   pytania z kroku 4 (każde z rekomendacją) i sekcja „Bramka”. `get_usage` przed krokiem 1 i tutaj; sekcja `## Zużycie`
   (czas, przyrost każdego okna) na końcu `acceptance.md`. Push (do 200 znaków):
   `Epik <ID>: <k> pytań, specyfikacja do akceptacji` albo, gdy bramka nie przechodzi,
   `Epik <ID>: przygotowanie wymaga decyzji (<pierwszy powód>)`. `status` = `awaiting`.

### Bramka

Bramkę liczy `groom.py gate <run_dir>`, nie model. Kod 0: przechodzi, kod 3: nie przechodzi (także błąd walidatora
manifestu i niepoprawny wpis `reviews`), kod 1: błąd `git` (zapisany `gate` jest wtedy czyszczony; przygotowanie
zostaje w `running` i da się je wznowić), kod 2: brak albo zły `state.json`. Wypisuje jedną linię JSON `{ok, checks: [{name, ok, detail}]}`
i zapisuje ją w `state.json` jako `gate`. Sesja uruchamia ją przed wysłaniem wiadomości i po każdej odpowiedzi „tak”,
także po wznowieniu, niezależnie od wcześniejszego wyniku.

Odpowiedź „tak” akceptuje rekomendacje, specyfikację, rozmiar końcowego PR i pozycje użytkownika z „Wymaganych przed
startem” tylko wtedy, gdy w chwili odpowiedzi wszystkie warunki są spełnione:

- oba review z kroku 2 i każda runda 2 zakończyły się poprawnie (`reviews`: każdy wpis poprawny — agent z dwóch
  recenzentów, runda ≥ 1, `ok` true/false, liczniki ≥ 0, plik w katalogu stanu; runda 1 obu agentów z `ok`, runda 2
  z `ok` dla każdego agenta z `critical_resolved` > 0 w rundzie 1, plik raportu istnieje);
- nie ma otwartego znaleziska `Critical` (`critical_open` = 0 w każdej rundzie każdego agenta; runda 2 nie zamyka
  pytań z rundy 1, bo jej recenzent nie widzi raportu rundy 1): poprawionego bez
  potwierdzenia w rundzie 2, zgłoszonego w rundzie 2 albo wymagającego decyzji użytkownika (produktowego lub
  architektonicznego). Takie znalezisko jest osobnym pytaniem, którego „tak” nie rozstrzyga;
- walidator manifestu i `night_queue.py scope` przeszły na obecnych plikach (sesja uruchamia je jeszcze raz po
  odpowiedzi);
- od `base_commit` żaden commit na `origin/main` nie zmienił ścieżek z linii `Zakres:` ani `spec.md`/`plan.md`
  epiku (`git log <base_commit>..origin/main -- <ścieżki>`), bo specyfikacja opisuje kod z chwili przygotowania.

„Bramka” w wiadomości ma jedną linię na warunek z wynikiem i dowodem (pliki raportów, kody wyjścia, lista commitów).
Bramka bez powodzenia: wiadomość nie proponuje „tak”, tylko wymienia, co blokuje i czego trzeba (odpowiedzi na pytanie
`Critical`, decyzji o ponownym przygotowaniu przy zmienionym zakresie).

### Odpowiedzi

Akceptację daje wyłącznie użytkownik w rozmowie, jak w kroku 6.

- **„tak” i bramka przechodzi:** krok 7 bez zmian (commit, PR, CI, merge według dzisiejszych warunków).
  `status` = `published` albo `failed` z powodem z kroku 7.
- **„tak” i bramka nie przechodzi** (także gdy przestała przechodzić od wysłania wiadomości): to nie jest akceptacja.
  Sesja podaje powód i czeka dalej.
- **Każda inna odpowiedź:** `status` = `interactive`. Odpowiedzi trafiają do specyfikacji (podagent jak w kroku 3),
  przy zmianie projektu krok 2, potem walidator i zwykła akceptacja z kroku 6. Od tej chwili obowiązuje dzisiejszy
  przebieg skilla, w tej samej sesji i tym samym worktree.

### Wznowienie

Gdy sesja `/groom` się zamknęła w trakcie przygotowania albo przy czekającej akceptacji (wznowienie tylko przy
zamkniętej albo zatrzymanej sesji `/groom`: dwie sesje nie pracują w jednym worktree), ręczne `/prepare-epic <nr>`
nie odmawia startu z powodu istniejącej gałęzi `prepare/<katalog>`, jeśli `groom.py origin <nr>` zwraca stan
przygotowania (`prepare`) ze `status` `running`, `awaiting` albo `interactive`, a worktree istnieje. Wtedy:

- Etapy z `stages` nie są powtarzane: bez nowych agentów i bez ponownego review dla ukończonych etapów. Sesja zaczyna
  od pierwszego nieukończonego etapu na plikach z worktree i raportach z katalogu stanu. Etap przerwany w połowie
  zaczyna się od nowa. Przy `awaiting` jedynym krokiem jest bramka i pokazanie `acceptance.md`.
- Bramka jest liczona od nowa (`groom.py gate`) przed pokazaniem akceptacji i po „tak”, bez względu na zapisany
  `gate`.
- Sesja działa dalej według „Odpowiedzi”; przy `interactive` według dzisiejszego przebiegu.

Każdy inny stan gałęzi (bez stanu, `published`, `failed`, brak worktree): odmowa jak dziś.

### Pliki

- `.claude/skills/prepare-epic/SKILL.md`: sekcja trybu po `/groom` i wznowienie.
- `.claude/skills/groom/SKILL.md`: nowy krok po kroku 6, który przy `fix_epic` ze statusem `done` uruchamia ten tryb.
  „Czego nie robisz” bez punktu o `/prepare-epic`.
- `.claude/skills/groom/groom.py` i `test_groom.py`: komendy `origin <nr>` (pochodzenie i stan przygotowania)
  i `gate <run_dir>` (bramka). Testy są już w CI.
- Bez nowych agentów, plików skryptów i zmian w AO, `ci.yml` i regułach merge'u.

### Wymagane przed startem

- Narzędzia w sesji `/groom` z harmonogramu: Agent z parametrem `model`, `PushNotification`, `get_usage`; `SendMessage`
  opcjonalnie (bez niego nowy podagent w kroku 3). Źródło: istnieją w sesjach aplikacji desktop (kryterium 4).
- Uprawnienia sesji z harmonogramu do `git worktree`, `git push` i `gh pr create`/`merge` w trybie Auto: istnieją
  dla `/groom` i `/prepare-epic`; pierwszy przebieg to potwierdzi.

### Kryteria akceptacji

1. Testy `groom.py origin`: `fix_epic` z `done` → kod 0 i JSON, z `prepare` przy istniejącym `state.json`;
   `fix_epic` `skipped`, `error`, `rejected`, bez decyzji albo `null` → kod 1; issue zamknięte, bez `epic`,
   z `needs-info` albo z innym ID → kod 1; brak katalogów przebiegów → kod 1; błąd `gh` → kod 1.
   Testy `groom.py gate`: wszystko spełnione → kod 0; brak rundy 1 agenta, review bez `ok`, brak pliku raportu,
   `critical_resolved` bez rundy 2, `critical_open` > 0 w dowolnej rundzie (także pytanie z rundy 1 po rundzie 2),
   niepoprawny wpis `reviews` (brak liczników, typy, plik poza katalogiem stanu), błąd walidatora, linia `Zakres:`
   nie do odczytania (także spoza UTF-8), commit w zakresie albo w plikach epiku po `base_commit` → kod 3 z nazwą
   warunku; błąd `git` → kod 1 i wyczyszczony `gate`; brak `state.json` → kod 2; wynik zapisany w `state.json`.
2. Ścieżka błędu startu: każdy z warunków 1–5 kończy tryb pushem z powodem, bez worktree i bez gałęzi. Pokazane na
   atrapach (`ao run`, projekt `sweep-*`, okno ≥ 80%, `ahead` z `full: true`, istniejąca gałąź `prepare/*`).
3. Bramka na prawdziwym repo: `groom.py gate` na przygotowanym stanie (worktree ze szkicem, `Critical` otwarty,
   walidator z błędem, commit w `Zakres:` po `base_commit`) kończy się kodem 3 z każdym z tych warunków, a po ich
   usunięciu kodem 0. Pokazane.
4. Prawdziwy przebieg: pierwszy epik poprawek zatwierdzony w `/groom` przechodzi do jednej wiadomości z pushem;
   `acceptance.md` ma bramkę i `## Zużycie`. Dziś nie może nastąpić, bo otwarty jest QUALITY-FIXES (jeden epik
   poprawek naraz); kryterium sprawdza pierwszy epik `-FIXES` po nim, jak kryteria 7–9 kroku 12.
5. Wznowienie: sprawdza je pierwszy epik poprawek, gdy sesja zostanie zamknięta przed odpowiedzią; do tego czasu
   regułę wznowienia pokrywają testy `origin` (stan przygotowania) i `gate`.

Do przebiegu z kryterium 4 krok 14 ma w planie status „zrobione, niezweryfikowane na prawdziwym epiku”.

### Zmiany w dokumentacji (w PR implementacji)

- `docs/features/groom/spec.md`: przebieg kończy się trybem po `/groom`, „Poza zakresem” bez przygotowania epiku.
- `docs/features/agentic-software-house/plan.md`: status kroku 14, opis kroku 14 i punkt procesu (14) zgodnie z tą
  sekcją.
- `CLAUDE.md`: w opisie `/groom` zdanie, że zatwierdzony epik poprawek sesja przygotowuje sama aż do pytań i akceptacji.
- `docs/adr/agentic-software-house.md`: przepływ `/groom` → `/prepare-epic` w tabeli komponentów.

### Ryzyka i ograniczenia

- Sesję prowadzi Sonnet. Bramkę liczy z plików raportów i kodów wyjścia, a „Bramka” w wiadomości pokazuje dowody,
  ale ocena, czy raport ma wagi, nadal należy do modelu.
- Przygotowanie zużywa limit w czasie, gdy `/ao-run` może chcieć wystartować epik; `/ao-run` na nie nie czeka (jak na
  `/groom`). `room --hours 2` sprawdza limit tylko na starcie.
- Akceptacja po wielu dniach: bramka odrzuca „tak”, gdy kod w zakresie się zmienił, ale nie wykryje zmiany poza
  linią `Zakres:`, która wpływa na epik.
- Sesja z harmonogramu działa tylko przy otwartej aplikacji Claude; zamknięta sesja wymaga wznowienia ręcznego.

## Czego skill nie robi

Nie pisze kodu, nie zgłasza zadań do AO (`ao submit-plan`), nie merguje niczego poza PR z kroku 7, nie rozstrzyga
decyzji produktowych ani istotnych decyzji DDD i architektonicznych, nie przygotowuje kilku epików naraz.

## Agent `spec-critic`

Niezależny krytyk szkicu specyfikacji epiku. Czyta `CLAUDE.md` (główny i aplikacji), `docs/vision.md`, issues
epiku, szkic i kod, którego szkic dotyczy. Nie sugeruje się opinią analityka. Szuka:

- braków: zachowania niezbędnego do implementacji, którego szkic nie opisuje (błędy widoczne dla użytkownika,
  uprawnienia, cykl życia danych, puste i brakujące dane);
- sprzeczności: w szkicu, ze specyfikacjami istniejących funkcji, z kodem, z `CLAUDE.md` i `docs/vision.md`;
- przypadków brzegowych: współbieżność, nieaktualny widok, limity, paginacja, propagacja błędów do UI;
- nieweryfikowalnych kryteriów akceptacji i wymagań z issue bez pokrycia w szkicu;
- niekompletnej sekcji „Wymagane przed startem”: sekret, konto, usługa zewnętrzna, uprawnienie albo artefakt,
  którego wymagają zadania, a którego dziś nikt nie dostarcza, bez pozycji albo źródła. Brak sekcji albo
  pozycja, bez której zadanie zatrzyma się w trakcie, to `Important`; otwarta decyzja infrastrukturalna to
  znalezisko `architektoniczne`.

Każde znalezisko: rodzaj (`techniczne`, `produktowe` albo `architektoniczne`), waga (`Critical`, `Important`,
`Minor`), miejsce w szkicu, dowód (plik, issue, linia) i propozycja. Decyzji produktowych (zakres, reguły, limity,
zachowanie klienta) ani istotnych decyzji DDD i architektonicznych (agregaty, value objecty, granice modułów,
persystencja, nowa zależność, zmiana zasady projektu) nie rozstrzyga: zgłasza je jako znalezisko `produktowe` albo
`architektoniczne` z rekomendacją wariantu. Nie zmienia
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
