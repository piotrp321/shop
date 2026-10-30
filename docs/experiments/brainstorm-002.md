# Eksperyment brainstorm-002: `/brainstorm` v0.4 vs `/superpowers:brainstorming`

Data: 2026-10-30. A = nasz `/brainstorm` v0.4 (`578f125`), B = `superpowers:brainstorming` (plugin z
`.claude/settings.json`). Pięć zadań, po jednej sesji A i B na zadanie, ten sam etap końcowy: zapisana
i zaakceptowana specyfikacja, bez planu implementacji.

## Werdykt

**Superpowers dał lepsze specyfikacje we wszystkich pięciu zadaniach.** Średnia ocena ślepa: B 4,04, A 3,08
(różnica od +0,4 do +1,2 na zadanie). A był tańszy i szybszy: koszt 0,65× B, czas 0,59× B, rundy 0,32× B.
Celu eksperymentu („porównywalna lub lepsza jakość przy mniejszym nakładzie”) v0.4 **nie spełnia**: nakład jest
wyraźnie mniejszy, ale jakość nie jest porównywalna. Główna przyczyna: A zadaje za mało pytań biznesowych
i luki wypełnia założeniami sprzecznymi z oczekiwaniami właściciela. Architektura obu jest na tym samym poziomie
(3,8 vs 3,8).

## Metoda

- **Kod**: `git archive 578f125` w 10 katalogach `b002-runs/sNN/shop`, w każdym `git init` i jeden commit
  z ustaloną datą i autorem. Wszystkie mają ten sam commit `b59a4fa` i drzewo `5b27f36` (= drzewo `578f125`).
  Brak historii, więc brak dostępu do `docs/experiments`, innych branchy i wcześniejszych projektów. Auto-memory
  pusta (nowe ścieżki projektów).
- **Sesje**: `claude -p` (Claude Code 2.1.308), `--model claude-opus-5-5 --effort high --strict-mcp-config
  --permission-mode acceptEdits`, każda runda przez `--resume`. Narzędzia jak w brainstorm-001 (Read, Glob, Grep,
  Write, Edit, Skill, Agent, WebSearch, WebFetch, Bash tylko do odczytu) plus `git status/log/diff/show/add/commit`.
  Zablokowane: `docker`, `make`, `rm`, `git push`.
- **Przydział**: sesje losowane do A/B w parze (`mapping.json`): t1 s01/s02, t2 s03/s04, t3 s05/s06, t4 s08/s07,
  t5 s09/s10 (A/B).
- **Prompt**: `/brainstorm <zadanie>` albo `/superpowers:brainstorming <zadanie>`, treść zadania słowo w słowo
  z polecenia eksperymentu.
- **Weryfikacja Skilli**: w transkrypcie każdej sesji jest dokładnie jeden `<command-name>` i jeden
  „Base directory for this skill”: A → `.claude/skills/brainstorm`, B → `plugins/.../skills/brainstorming`.
  Żadna sesja nie załadowała Skilla drugiej strony.
- **Klucz odpowiedzi** (`answer-key.md`): spisany przed startem, 9 reguł odpowiadania i 13–15 decyzji biznesowych
  na zadanie, bez architektury. Punkt klucza przekazywałem tylko na pytanie, tymi samymi słowami w obu sesjach pary.
  Pytanie techniczne → „Decyzję techniczną zostawiam Tobie, przyjmuję Twoją rekomendację.” Pytanie biznesowe
  spoza klucza → „Nie mam tu wymagań. Zdecyduj sam i zapisz to jako jawne założenie.” Zatwierdzenie sekcji →
  „Tak, zgadza się.” Akceptacja projektu → „Akceptuję projekt.” (korekta tylko przy sprzeczności z już przekazanym
  punktem klucza; nie wystąpiła ani razu). Po zapisaniu specyfikacji obie strony dostały to samo: „Akceptuję
  specyfikację. Na tym etapie kończymy: nie przygotowuj planu implementacji i niczego nie implementuj.”
- **Ocena**: osobna sesja `claude -p` (Opus, effort high, tylko odczyt) na parę, w katalogu `b002-review/rN` bez
  `.claude` i bez wiedzy o Skillach, kosztach i innych ocenach. Dostała `design-X/Y.md` (spec + diff innych
  plików), eksport repo i klucz tego zadania. X/Y losowane (`blind-mapping.json`). Twierdzenia recenzentów
  sprawdziłem w kodzie (sekcja „Weryfikacja ocen”).

### Odstępstwa od porównywalnych warunków

- **Respondent zna przydział.** Odpowiadałem ja (ten sam model), znając A/B. Ograniczyłem to mechanicznymi
  regułami klucza i jednakowym tekstem odpowiedzi; dziennik jest w `answers.md`.
- **Reguła akceptacji sprzyja B.** Akceptowałem projekty sprzeczne z punktami klucza, o które sesja nie
  zapytała. Realny właściciel poprawiłby je przy akceptacji, co dodałoby A rundy, ale też podniosło jakość.
  Recenzent ocenia względem całego klucza, więc liczba zadanych pytań wprost przekłada się na kryterium 1.
- **Pary częściowo się nakładały**: kolejną parę startowałem, gdy w poprzedniej zostawała jedna sesja.
- **B nie mógł zacommitować specyfikacji.** Wszystkie 5 sesji B próbowało utworzyć branch (`git checkout -b`
  albo `git switch -c`), co odrzuciła konfiguracja uprawnień, i żadna nie commitowała na `main`. Krok „commit”
  Skilla B nie został więc wykonany.
- **Brak uruchomionego stosu.** Krok 4 Skilla A (mały eksperyment) był niemożliwy: s08 i s09 próbowały
  `docker compose`, zablokowane. Obie sesje zapisały niesprawdzone założenia jako zadanie na początek planu.
- **n = 1 na zadanie.** Nie znamy wariancji wewnątrz zadania; spójny kierunek w 5/5 zadaniach jest jedynym
  argumentem za tym, że różnica nie jest szumem.
- **Wspólne dla obu**: hook SessionStart superpowers i reguła „Tymczasowe” z `CLAUDE.md`. s07 (B) zapisała własną
  auto-memory w swoim katalogu projektu (`delegated-technical-decisions.md`), poza repo, bez wpływu na inne sesje.

## Wyniki zbiorcze

Czas = suma `wall` wywołań (bez mojego czasu odpowiedzi). Koszt = `total_cost_usd` (cennik API). Rundy = liczba
wywołań `claude -p` łącznie z zamknięciem. Ocena = średnia 5 kryteriów recenzenta.

| Zadanie | Skill | Czas | Wyjście (tok.) | Odczyt cache | Koszt | Rundy | Ocena |
|---|---|---:|---:|---:|---:|---:|---:|
| 1 Historia oglądanych | A | 225 s | 20 709 | 0,86 M | $1,19 | 4 | 3,0 |
| | B | 361 s | 26 412 | 1,76 M | $1,53 | 12 | **4,2** |
| 2 Import CSV | A | 403 s | 38 247 | 1,31 M | $1,81 | 4 | 3,2 |
| | B | 490 s | 42 524 | 2,51 M | $2,24 | 13 | **4,0** |
| 3 Opinie | A | 213 s | 20 159 | 1,10 M | $1,20 | 4 | 3,0 |
| | B | 416 s | 38 273 | 2,36 M | $2,17 | 11 | **4,2** |
| 4 Rezerwacja stanów | A | 332 s | 29 512 | 1,03 M | $1,49 | 4 | 3,2 |
| | B | 710 s | 61 403 | 3,74 M | $3,01 | 15 | **3,6** |
| 5 Wyszukiwarka | A | 281 s | 26 527 | 1,16 M | $1,47 | 4 | 3,0 |
| | B | 476 s | 39 804 | 2,20 M | $2,06 | 12 | **4,2** |
| **Średnia** | A | 291 s | 27 031 | 1,09 M | $1,43 | 4,0 | 3,08 |
| | B | 491 s | 41 683 | 2,51 M | $2,20 | 12,6 | 4,04 |

- Rozrzut: A 213–403 s, $1,19–1,81, ocena 3,0–3,2. B 361–710 s, $1,53–3,01, ocena 3,6–4,2.
- Wejście bez cache: 30–94 tokeny na sesję. Zapis cache: A 72–98 tys., B 81–129 tys.
- Suma: A $7,17, B $11,01. Ocena ślepa (5 sesji): $8,40, 190–243 s każda.
- Długość specyfikacji: A 1084–1672 słów (śr. 1417), B 1506–2938 (śr. 2364).

### Oceny według kryteriów (średnia z 5 zadań)

| Kryterium | A | B | Zakres A | Zakres B |
|---|---:|---:|---|---|
| 1. Kompletność wymagań biznesowych | 2,4 | 4,0 | 2–3 | 4–4 |
| 2. Jakość i prostota architektury | 3,8 | 3,8 | 3–4 | 3–4 |
| 3. Zgodność z repozytorium | 3,2 | 3,8 | 2–4 | 3–4 |
| 4. Przypadki brzegowe | 3,0 | 4,6 | 3–3 | 4–5 |
| 5. Gotowość do implementacji | 3,0 | 4,0 | 3–3 | 4–4 |

### Interakcja, zmiany plików, założenia, błędy

| Miara | A (suma / 5 sesji) | B (suma / 5 sesji) |
|---|---|---|
| Rundy z pytaniami merytorycznymi | 5 (po jednej, zawsze 2 pytania) | 30 (5–8 na sesję) |
| Zatwierdzenia rozumienia i sekcji | 0 | 28 (wszystkie „Tak, zgadza się”, żadne nic nie zmieniło) |
| Punkty klucza przekazane, bo sesja zapytała | 13 (1–5 na sesję) | 34 (3–10 na sesję) |
| Nieautoryzowane zmiany plików | 0 | 2 sesje: s06 `docs/vision.md` (+3 wiersze), s07 `docs/vision.md` i `apps/api/CLAUDE.md` (14 wierszy) |
| Próby operacji spoza zakresu | 2× `docker compose` (eksperyment, zablokowane) | 5× tworzenie brancha (zablokowane) |
| Nieuzasadnione założenia (lista recenzenta) | 20 | 19 |
| Błędy obiektywne (lista recenzenta) | 40 | 24 |

Zmiany B w dokumentacji zasad przed implementacją łamią `CLAUDE.md` („Zmiana architektury, zasady… = aktualizacja
dokumentacji w tym samym commicie co kod”). A nie zmienił żadnego pliku poza specyfikacją; błąd z brainstorm-001
(przedwczesna zmiana `vision.md`) w v0.4 się nie powtórzył.

## Wyniki A/B według zadań

### 1. Historia oglądanych produktów (A 3,0 / B 4,2)

- **A (s01)**: 2 pytania (synchronizacja między urządzeniami, termin). Reszta jako założenia: limit 12 (klucz 20),
  brak czyszczenia i usuwania pojedynczych pozycji, brak retencji 90 dni, brak informacji o niedostępności.
  Fałszywe uzasadnienie CSRF: „`SameSite=Lax`, więc żądanie z innej strony nie niesie sesji”, choć
  `apps/store/app/auth/logout/route.ts:6-9` opisuje, że Lax nie chroni przed żądaniem z innego portu tego samego
  hosta. Tagi `product-<id>` „jak reszta listy”, choć lista nie ma tagów. Plus: najprostsza architektura (BFF
  składa ids z Customer i dane z Catalog, bez łamania Deptrac).
- **B (s02)**: 6 pytań biznesowych, pokrył prawie cały klucz. Błąd: endpoint Customer zwraca `ProductListItem`
  z `Catalog\Application`, a `Customer_UI/Application` nie mogą zależeć od `Catalog_Application`
  (`apps/api/deptrac.yaml:115-135`). Brak informacji o niedostępności.

### 2. Import CSV (A 3,2 / B 4,0)

- **A (s03)**: zapytał o zakres i rozmiar, nie o politykę błędów. Sam wybrał zapis paczkami, przez co „zapis nie
  jest atomowy dla całego pliku” wbrew kluczowi („wszystko albo nic”). Lista błędów obcięta do 1000. Fałszywe:
  „Kolejki są widoczne na dashboardzie” (dashboard pokazuje tylko outbox i kolejki RabbitMQ,
  `MessagingStatisticsAdapter.php`). Panel odpytuje bez końca, gdy paczka trafi do `failed`. Plus: mało nowej
  infrastruktury, historia importów zgodnie z kluczem.
- **B (s04)**: zapytał o cel, błędy, kategorie, slug, role i rozmiar; po odpowiedzi „50 000 wierszy” zmienił
  rekomendację z synchronicznej na asynchroniczną. Błędy: historia importów „poza zakresem” (klucz jej wymaga),
  „firewall `backoffice`” (jest `admin`, `security.yaml:13`), „transliteracja wymaga biblioteki” (jest `ext-intl`),
  brak opisu routingu do nowej kolejki i przejścia w `failed`.

### 3. Opinie o produktach (A 3,0 / B 4,2)

- **A (s05)**: 2 pytania (termin, kto). Założenia sprzeczne z kluczem: tekst wymagany, brak edycji i usuwania,
  odrzucenie bez powodu, klient nie widzi statusu, anonimizacja zamiast usunięcia. Błędy repo: 422 zamiast 400
  (`ProblemDetailsSubscriber.php:113`), formularz zależny od sesji w HTML renderowanym na serwerze (wbrew
  `apps/store/CLAUDE.md`), publiczny `GET` bez zmiany `access_control` (`security.yaml:41`). Po akceptacji dodał do
  specyfikacji porty `ReviewAuthor`/`Moderator` bez ponownego pokazania projektu (poprawnie co do Deptrac, ale
  wbrew własnemu Skillowi).
- **B (s06)**: pokrył prawie cały klucz, twierdzenia o repo trafne. Błędy: brak informacji prawnej o weryfikacji
  opinii, diff `vision.md` (opinie jako punkt 11 MVP) sprzeczny ze specyfikacją i wprowadzony przed implementacją.

### 4. Rezerwacja stanów (A 3,2 / B 3,6)

- **A (s08)**: 2 pytania (termin, blokada wiersza zamiast optimistic locking). Najczystszy model w eksperymencie
  (dwa agregaty, `FOR UPDATE` w kolejności SKU), ale zakres obcięty: bez panelu magazynu, bez dostępności
  w sklepie, bez danych demo, stan per nieistniejące SKU. `CHECK (reserved <= on_hand)` zakazuje korekty poniżej
  rezerwacji, którą klucz dopuszcza. Test „B czeka na blokadę A” niewykonalny w jednym procesie PHP.
- **B (s07)**: 8 rund pytań, cały klucz. Przy pisaniu specyfikacji sam wykrył błąd wersjonowania dokumentu ES
  w zatwierdzonym projekcie i wrócił z wariantami. Błędy: „503 z `Retry-After: 1`, tak jak `SearchUnavailable`”
  (w repo nie ma `Retry-After`, `ListProductsController.php:75`), limity 1–999 sztuk i 100 linii wbrew
  „brak limitu”, zmiany `vision.md` i `apps/api/CLAUDE.md` przed kodem, zdarzenie bez odbiorcy.

### 5. Wyszukiwarka (A 3,0 / B 4,2)

- **A (s09)**: zauważył, że wyszukiwania tekstowego nie ma; zapytał o kolejność i wtyczkę `analysis-stempel`.
  Błędy repo: 422 dla błędnego `q` (endpoint daje 400, `ListProductsController.php:66`), a sklep zamienia na
  „Nieprawidłowy filtr” tylko 400 (`load-catalog.ts:37`), więc błąd trafi do `error.tsx`. Fałszywe „nazwy są
  markowe” (`DemoCatalogExpander.php:50` składa typ + marka + model + kolor). Brak synonimów z klucza.
- **B (s10)**: zapytał o synonimy, odmianę, łączenie słów; synonimy i lista startowa zgodne z kluczem, mocne
  przypadki brzegowe (puste `q`, `q[]`, walidacja pliku synonimów). Błędy: kolejność „dokładne przed literówką”
  niezagwarantowana, kryterium „buty do biegania” nieosiągalne opisanym mechanizmem.

## Weryfikacja ocen

Sprawdziłem w eksporcie repo 14 istotnych twierdzeń recenzentów. Wszystkie się potwierdziły, m.in.:
`logout/route.ts:6-18` (Lax i sprawdzanie `Origin`), `deptrac.yaml:115-135` (Customer_UI/Application bez
Catalog_Application), `MessagingStatisticsAdapter.php` (dashboard: outbox + RabbitMQ), `security.yaml:13` (firewall
`admin`) i `:41` (publiczne tylko `products|categories`), `ProblemDetailsSubscriber.php:113` (400 z `violations`),
brak `Retry-After` w `src/`, `config/`, `tests/`, `load-catalog.ts:37-40`, `DemoCatalogExpander.php:50`,
`composer.json:11` (`ext-intl`), `CHECK` w spec s08, limity 999/100 w spec s07.

**Obiektywne vs subiektywne.** Do błędów obiektywnych liczę sprzeczność z kluczem, z kodem, z zasadą repo
i wewnętrzną sprzeczność. Subiektywne (recenzent sam je tak oznaczył) to m.in. agregat vs zapis SQL w t1, osobny
endpoint `lookup` vs filtr `ids`, agregat `ProductImport` vs proces aplikacyjny w t2, `/search` vs wyniki na `/`
w t5. Kryterium 2 (architektura) wyszło remisowo właśnie dlatego, że różnice architektoniczne były głównie
subiektywne. Przewaga B pochodzi z kryteriów 1, 4 i 5 oraz z mniejszej liczby błędów zgodności z repo.

**Zależność ocena–pytania.** Większość sprzeczności A z kluczem dotyczy punktów, o które A nie zapytała, a B
zapytała (t1: limit, czyszczenie, retencja; t3: tekst, edycja, powód, status; t4: panel, korekta, sklep). To jest
efekt mierzony, a nie artefakt, bo właściciel naprawdę ma te oczekiwania. Część przewagi B nie zależy jednak od
klucza: zgodność z repo (3,8 vs 3,2) i przypadki brzegowe (4,6 vs 3,0).

## Konkretne błędy obu Skilli

| Typ | A (`/brainstorm` v0.4) | B (Superpowers) |
|---|---|---|
| Założenie biznesowe sprzeczne z kluczem | t1 limit 12, brak czyszczenia; t2 zapis nieatomowy; t3 tekst wymagany, brak edycji; t4 brak panelu, `CHECK` blokuje korektę | t2 historia importów poza zakresem; t4 limity 999/100 |
| Fałszywe twierdzenie o kodzie | t1 CSRF i tagi; t2 dashboard; t5 „nazwy markowe” | t2 firewall `backoffice`, „transliteracja wymaga biblioteki”; t4 `Retry-After` |
| Niezgodność ze wzorcem repo | t3 i t5: 422 zamiast 400; t3 HTML zależny od sesji, brak `access_control` | t1 typ Catalog w Customer_UI (Deptrac) |
| Zmiana plików poza zakresem | brak | t3 i t4: `vision.md`, `apps/api/CLAUDE.md` przed implementacją |
| Proces | t3 zmiana projektu po akceptacji bez pokazania | 28 zatwierdzeń sekcji bez żadnej zmiany; 5× próba brancha |

## Powtarzające się problemy v0.4

1. **Jedna runda, dwa pytania, reszta założeniami.** We wszystkich 5 sesjach A zadała dokładnie 2 pytania w jednej
   rundzie (zwykle termin i jedna decyzja zakresu), a potem przedstawiła kompletny projekt. Reguła „maksymalnie
   2 pytania w jednej rundzie” w praktyce działa jak „maksymalnie 2 pytania w ogóle”, bo po odpowiedziach v0.4
   od razu prosi o akceptację całości.
2. **Założenia biznesowe podawane jak techniczne.** Limity, uprawnienia klienta, politykę błędów i zakres UI A
   rozstrzyga sama i chowa w sekcji „Założenia” na końcu długiego projektu. Użytkownik akceptuje projekt, nie
   widząc, że to decyzje biznesowe.
3. **„Najprostsze rozwiązanie” obcina wymagania, a nie architekturę.** s08 bez panelu i sklepu, s01 bez czyszczenia
   historii, s05 bez edycji. Architektura A jest równie dobra jak B (3,8), ale zakres jest węższy niż funkcja.
4. **Mniej czytania kodu, więcej błędów zgodności.** A: 12–21 wywołań modelu na sesję, B: 26–51. Krok 10 Skilla
   („nie formułuj niesprawdzonych twierdzeń”) nie zapobiegł 422 zamiast 400 (dwa razy), CSRF, dashboardowi,
   „markowym nazwom”.
5. **Brak przeglądu zapisanej specyfikacji.** B po zapisaniu robi self-review i w 5/5 sesji wprowadził poprawki
   (s07 znalazł błąd wersjonowania ES). A zapisuje specyfikację i kończy; poprawki po akceptacji (s05) wprowadza
   bez pokazania.

## Czy dodatkowe pytania Superpowers poprawiły projekt?

- **Pytania biznesowe: tak, wyraźnie.** B zadała 30 rund pytań i dostała 34 punkty klucza (A: 13). W t1, t3 i t4
  odpowiedzi bezpośrednio usunęły luki, które w A recenzent oznaczył jako błędy. W t2 pytanie o politykę błędów
  dało B atomowy import, a A bez tego pytania wybrała zapis nieatomowy. W t4 odpowiedź o korekcie poniżej rezerwacji
  zmieniła propozycję B (chciała odrzucać korektę).
- **Zatwierdzenia sekcji: nie.** 28 zatwierdzeń rozumienia i sekcji, każde „Tak, zgadza się”, żadne nie zmieniło
  projektu. To główny koszt B (ok. połowa rund). Tak jak w brainstorm-001, tego kroku nie da się ocenić bez
  sprzeciwu użytkownika, ale przy tym kluczu nie wniósł nic mierzalnego.
- **Pytania techniczne: neutralne.** Na wszystkie odpowiadałem „przyjmuję rekomendację”, więc nie zmieniły wyniku.

## Porównanie orientacyjne z brainstorm-001

| | brainstorm-001 | brainstorm-002 |
|---|---|---|
| Zadania | 1 (wishlista) | 5 |
| Wersja A | v0.3 (`57613c8`) | v0.4 (`578f125`) |
| Git w katalogu | brak | jeden commit, bez historii |
| Klucz | ustny, ad hoc | spisany przed startem, 9 reguł |
| Zamknięcie | na pierwszej bramce po spec | ta sama wiadomość zamykająca w A i B |
| Ocena | 1 subagent, inne kryteria (m.in. „nieuzasadnione założenia”) | 5 sesji `claude -p`, 5 kryteriów z polecenia |
| Rundy A / B | 2 / 8 | 4 / 12,6 (łącznie z akceptacją i zamknięciem) |
| Koszt A / B | $1,28 / $1,52 (0,84×) | $1,43 / $2,20 (0,65×) |
| Jakość | B nieco lepsza w gotowości (4 vs 3) | B lepsza w 5/5 zadaniach (4,04 vs 3,08) |

Kierunek jest ten sam: A taniej i szybciej, B dokładniej. Przy 5 różnych i trudniejszych zadaniach różnica jakości
jest większa niż przy wishliście, a różnica kosztu też urosła, bo B zadaje więcej pytań przy złożonych funkcjach.
v0.4 usunęła problem przedwczesnych zmian dokumentacji z v0.3. Liczb z obu eksperymentów nie należy porównywać
wprost: inne kryteria oceny, inny recenzent, inne zamknięcie sesji.

## Rekomendacje do ewentualnego v0.5 (maks. 5)

1. **Jedna pełna runda pytań biznesowych przed projektem.** Zamiast „maks. 2 pytania w rundzie”: wypisać wszystkie
   otwarte decyzje biznesowe (zakres, limity, uprawnienia i kontrola użytkownika, retencja, polityka błędów, role,
   co widzi klient) w jednej wiadomości, każdą z rekomendowanym wariantem do wyboru. To zachowuje mało rund,
   a daje pokrycie B.
2. **Założenia biznesowe zawsze do potwierdzenia.** Samodzielnie rozstrzygać tylko kwestie techniczne. Każde
   założenie biznesowe pokazywać na początku projektu jako listę „przyjęłam X, potwierdź lub zmień”, a nie w sekcji
   na końcu.
3. **Prostota dotyczy rozwiązania, nie zakresu.** Nie usuwać z zakresu elementów, które wynikają z opisu funkcji
   (panel, kontrola klienta, widok w sklepie), bez pytania.
4. **Lista kontrolna zgodności z repo przed pokazaniem projektu**, z plikiem przy każdej pozycji: kody błędów
   walidacji (400 i `violations`), `access_control`, reguły Deptrac dla nowych zależności między contextami,
   zasady renderowania sklepu z `apps/store/CLAUDE.md`, wzorce przywoływane „jak w X”.
5. **Przegląd zapisanej specyfikacji i powrót do użytkownika przy zmianie.** Po zapisaniu: sprawdzić sprzeczności,
   luki i zgodność z odpowiedziami użytkownika. Każdą zmianę względem zaakceptowanego projektu pokazać przed
   zakończeniem. Zachować obecną zasadę v0.4: poza specyfikacją żadnych zmian plików.

## Artefakty

Wszystko poza repo, niezacommitowane:
- `~/Development/claude/bench-brainstorm-002/`: `answer-key.md`, `answers.md` (dziennik pytań, odpowiedzi
  i zatwierdzeń), `prompts/` (dosłowne wiadomości), `logs/` (stream-json każdej rundy, `.wall`, `.git` ze stanem
  repo po rundzie), `stats.jsonl`, `mapping.json`, `blind-mapping.json`, `specs/sNN/` (końcowe specyfikacje
  i `tracked.diff`), `judge/` (prompt, surowe logi i oceny `r1–r5.md`), skrypty `turn.sh`, `judge.sh`, `stats.py`.
- `~/Development/claude/b002-runs/sNN/shop`: katalogi sesji w stanie końcowym.
- `~/Development/claude/b002-review/rN`: materiały recenzentów.
- Transkrypty sesji Claude Code: `~/.claude/projects/-Users-<user>-Development-claude-b002-runs-sNN-shop/`.
