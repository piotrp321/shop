# Retrospektywa serii ASH v1.0 (2026-11-21)

Zbiorcza retrospektywa trzech epików serii ASH v1.0: ABUSE-LIMITS (#181, PR #802), ASYNC-RELIABILITY (#768, PR #811)
i DELIVERY etap 1 (#120, PR #819). Rewizja: `origin/main` `7935d611`. Czasy w UTC. Raport zawiera ustalenia
i rekomendacje; niczego nie zmienia w procesie, konfiguracji ani kodzie. Sekcje 2–6 to obserwacje z danych serii.
Sekcje 7–10 to propozycje; tam, gdzie mowa o skutku zmiany, obserwacja jest oddzielona od hipotezy. Decyzja
o wdrożeniu i jego kształcie należy do właściciela.

## 1. Werdykt

1. **Wersja ASH: tylko częściowo właściwa.** Sesje prowadzące czytały `CLAUDE.md` i „Przebieg epiku” w wersji z bazy
   epiku. Subagenci (`code-reviewer`, `qa-adversarial`, `architecture-reviewer`, `retro-analyst`) we wszystkich trzech
   epikach dostali definicje z commita `9ed889e2`, czyli sprzed tagu `ash-v1.0`: bez zmian z #785, #798 i #812.
   Przyczyna: aplikacja desktopowa czyta agentów i skille z głównego checkoutu, a ten stał na `9ed889e2` od 19.11.
   Seria nie sprawdziła więc reguł przeglądu z wersji 1.0.
2. **Proces był w większości przestrzegany**, a odstępstwa sesje same ujawniały w opisach PR. Żadne odstępstwo nie
   było ukryte.
3. **Najważniejsza luka jakości:** błąd zauważony przez wewnętrzny przegląd dostaje niską wagę, a sesja prowadząca
   odracza go w kilka–kilkadziesiąt sekund bez własnej oceny skutku. Tak było w trzech z czterech błędów, które
   potem zgłosił Codex.
4. **Koszt:** trzy epiki to 277 USD wartości tokenów według cennika API (nie rachunek) i około 12 pp tygodniowego okna
   subskrypcji. 54% tej wartości to developerzy, 25% sesja prowadząca, 20% wszystkie przeglądy. Sam udział w wartości
   nie rozstrzyga o opłacalności żadnej roli; przeglądy zostają na razie ze względu na błędy, które wykrywają.
5. **Seria i AO:** dowodów wystarcza, żeby zamknąć serię jako pomiar natywnego dostarczania i podjąć decyzję o AO.
   Nie wystarcza, żeby uznać reguły przeglądu v1.0 za sprawdzone, ani żeby mówić o jakości na produkcji (nic nie jest
   wdrożone).

## 2. Zakres i dane

| Zestaw | Epiki |
|---|---|
| Formalna seria ASH v1.0 (zakres raportu) | ABUSE-LIMITS, ASYNC-RELIABILITY, DELIVERY etap 1 |
| Trzy ostatnio zmergowane epiki natywne | DEMO-SCALE etap 1 (#788), ASYNC-RELIABILITY, DELIVERY etap 1 |

DEMO-SCALE biegł równolegle poza serią i nie ma pliku przebiegu metryk; jest tu tylko przy wersjach i znaleziskach
review. ASH-V1 (#785) i SMALL-FIXES (#752) są tłem sprzed tagu.

| Epik | Baza | Start → done | Merge | Sesja prowadząca, worktree |
|---|---|---|---|---|
| ABUSE-LIMITS | `8d8df386` (= tag) | 19.11 16:29 → 20:35 | 20.11 07:36 | `f3ac74d4`, `epik-ash-v1-bebe16` |
| ASYNC-RELIABILITY | `8ddd00ab` | 20.11 09:08 → 13:41 | 20.11 13:42 | ta sama sesja `f3ac74d4` |
| DELIVERY etap 1 | `143c6994` | 20.11 13:50 → 21:03 | 20.11 21:44 | `c4cf0ee8`, `demo-scale-f23cde` |

Tag `ash-v1.0` wskazuje `8d8df386` (merge #801, 19.11 16:06). „Status” planu: seria „zakończona” (#831), P6 do
zrobienia.

Źródła: transkrypty sesji prowadzących i 74 subagentów (`~/.claude/projects/...`), pliki przebiegu i rekordy
`tools/metrics`, raporty `/retro` (`~/.local/state/shop-retro/`), gałęzie epików na origin, metadane CI (`gh run`),
lokalne sesje Codexa (`~/.codex/sessions/2026/11/19–21`), issues #813, #818, #821, #822, #832 przez bramkę pochodzenia.

Rekord metryk ABUSE-LIMITS nie istniał w `~/.local/state/shop-metrics/epics/`. Na potrzeby raportu powstał
w katalogu tymczasowym tą samą komendą (`metrics.py epic abuse-limits --state <kopia>`, kod 0); stan narzędzia jest
nietknięty.

## 3. Wersja ASH faktycznie używana

### 3.1. Agenci i skille z głównego checkoutu (potwierdzone odstępstwo)

- Główny checkout `/Users/pp/Development/claude/shop` stoi na `9ed889e2` od 19.11 08:18 (ostatni wpis reflogu:
  `merge origin/main: Fast-forward`). Żaden późniejszy wpis nie istnieje.
- Każdy subagent ma plik `subagents/agent-<id>.prefix.json` z długościami bloków promptu systemowego. Drugi blok to
  treść definicji agenta plus stałe 1662 znaki. Zgadza się co do znaku z wersją z `9ed889e2`, a nie z bazą epiku:

| Agent | Blok w transkrypcie | `9ed889e2` + 1662 | tag + 1662 | po #812 + 1662 | Uruchomień w serii |
|---|---|---|---|---|---|
| `code-reviewer` | 7148 | **7148** | 8130 | 8746 | 31 |
| `qa-adversarial` | 6655 | **6655** | 7998 | 7998 | 3 |
| `architecture-reviewer` | 2896 | **2896** | 3208 | 3208 | 3 |
| `retro-analyst` | 12893 | **12893** | 14064 | 14064 | 1 |
| `developer`, `ux-reviewer` | 5683, 21517 | zgodne (plik bez zmian do #831) | | | 31, 4 |

- Lista agentów pokazana sesjom prowadzącym ma opis `retro-analyst` sprzed #785 („…z przebiegów AO (input.json)…”).
  Ta sama stara lista jest w sesji, która pisała ten raport.
- Żaden z 26 raportów reviewerów w ABUSE-LIMITS i ASYNC-RELIABILITY nie zaczyna się od `Werdykt: …` (reguła #798);
  w DELIVERY 1 z 11. To źródło wpisów „bez rozpoznanego werdyktu” w `gaps` rekordów.
- `/retro abuse-limits` (20.11 09:40) wczytało `SKILL.md` z `/Users/pp/Development/claude/shop/.claude/skills/retro`,
  czyli wersję sprzed ASH-V1, bez części o epikach natywnych i zamrożeniu. Skrypty biegły z worktree. Sesja po
  6 minutach doczytała nową wersję z worktree retro.
- Mechanizm jest udokumentowany: sesja w worktree uruchomiona z aplikacji desktopowej czyta ustawienia, hooki, skille,
  agentów i komendy z katalogu głównego checkoutu, a `CLAUDE.md` z worktree
  (`https://code.claude.com/docs/en/worktrees.md`, „What worktrees share with the main checkout”). Zmiana pliku agenta
  na dysku działa od następnego zlecenia, bez restartu (`.../sub-agents.md`).

Czego reviewerzy nie dostali przez plik agenta:

- z #785: wymóg numerowanych kryteriów akceptacji w zleceniu QA;
- z #798: werdykt w pierwszej linii, obowiązek kwestionowania założeń i decyzji sesji zlecającej, ocena całej reguły
  w trybie DELTA, IMPORTANT dla „wyniku błędnego bez śladu”, utrata danych wejściowych jako zwykłe użycie;
- z #812: IMPORTANT dla skutku nieodwracalnego poza transakcją, „wąskie okno wyścigu nie obniża wagi”, „błąd sprzed
  zmiany ma tę samą wagę”.

### 3.2. Tabela wersji i odstępstw

| Epik | Oczekiwana wersja | Sesja prowadząca | Subagenci | Odstępstwa | Możliwy wpływ |
|---|---|---|---|---|---|
| ABUSE-LIMITS | tag `8d8df386` | zgodna (z dowodem): `CLAUDE.md` z worktree (blob `558509bf`), cały ADR przeczytany 16:27:56 | `9ed889e2` | potwierdzone: agenci sprzed #785 i #798 | brak danych: reguły z tagu nie obejmowały oceny S1 (to dopiero #812), więc związku z odroczeniem S1 nie da się wykazać |
| ASYNC-RELIABILITY | `8ddd00ab`; #812 na `main` od 09:57, w gałęzi od 12:21 | zgodna: po kompakcji `CLAUDE.md` wstrzyknięty ponownie, ADR czytany częściami przed startem; treść #812 w zleceniach zadań 3, 9 i QA od 09:47 | `9ed889e2` | potwierdzone: agenci sprzed #798; rubryka #812 nie dotarła do żadnego reviewera także po 12:21; `CLAUDE.md` po merge'u `main` nie wczytany ponownie (bez wpływu) | ograniczony: ryzykowne zadania dostały regułę w zleceniu; wniosek retro „wzorzec S1 wrócił” nie mówi nic o skuteczności #812 |
| DELIVERY etap 1 | `143c6994` (tag + #812) | zgodna: nowa sesja bez kompakcji, cały „Przebieg epiku” w wersji z #812 | `9ed889e2`, także `retro-analyst` sprzed #785 | potwierdzone: zlecenia odsyłają do „pliku agenta” i nie cytują #798 ani #812, więc żadna z tych reguł nie dotarła do reviewerów | uzasadnione podejrzenie, słabe: błędu P2 nikt wewnątrz nie zauważył, więc rubryka wagi nie miała czego ocenić; retro bez instrukcji dla epiku natywnego |
| DEMO-SCALE (poza serią) | `bd5ccf81` + merge `main` | zgodna | `9ed889e2` | potwierdzone od 19.11 15:05 (po #798) | poza serią |

Pozostałe ustalenia:

- **Wznowiona sesja.** `f3ac74d4` prowadziła dwa epiki. Jedna kompakcja (20.11 07:36, 548 tys. → 14 tys. tokenów)
  przypadła między epikami; instrukcje zostały wstrzyknięte ponownie. Brak dowodu pracy na nieaktualnych zasadach
  w sesji prowadzącej.
- **Worktree subagentów:** zgodne. Developerzy pracowali we własnych worktree i startowali z
  `origin/<gałąź epiku>`; reviewerzy wchodzili do worktree zadania.
- **Lokalne nadpisania:** bez wpływu. `~/.claude/CLAUDE.md` dotyczy tylko języka, `.claude/settings.json` jest pusty,
  `settings.local.json` ma jedno pozwolenie WebFetch, Superpowers jest wyłączony i nie pojawia się w transkryptach.
- **Instrukcje z rozmowy:** „dobieraj modele i efforty, żeby nie zżerać limitów” (19.11 16:38), zgodne z ADR pkt 5;
  „na razie nie wprowadzamy zmian, zapiszmy problemy w retro” (20.11 13:08), zgodne z zamrożeniem.
- **Narzędzia pomiaru:** `metrics.py` miał ten sam blob przez całą serię; CLI 2.1.325 we wszystkich sesjach.
- **Modele:** sesje prowadzące Opus 5.5 (high); CRITICAL na Opus, STANDARD na Sonnet; zgodne z ADR i instrukcją
  właściciela.

## 4. Przestrzeganie procesu

Kroki „Przebiegu epiku” były wykonane: gałąź i draft PR od startu, `metrics.py start`/`done`, każde zadanie planu przez
`developer` w osobnym worktree, `code-reviewer` dla każdego zadania STANDARD i CRITICAL, przegląd całej zmiany przez
trzy role, 9 sekcji opisu PR, ready po zielonym CI, merge tylko przez właściciela, `/retro` po merge'u.

Odstępstwa (wszystkie ujawnione w opisach PR, o ile nie zaznaczono inaczej):

| Odstępstwo | ABUSE | ASYNC | DELIVERY | Skutek |
|---|---|---|---|---|
| Zadanie zależne startuje z gałęzi poprzednika przed jego merge'em | 3 | 2 | 0 | brak szkody; oszczędność czasu |
| Przegląd epiku zaczęty przed ostatnim merge'em albo zielonym CI | tak (bez wpisu w PR) | tak | tak | brak szkody |
| Kod napisany przez sesję prowadzącą, bez TDD i przeglądu zadania | `b2007c8e` (+4/−3) | nie | DELIVERY-8: 3 commity (+37/−12) | brak znanego |
| Braki RED zgłoszone przez reviewera | 0 | zadanie 3 (zmergowane z otwartym IMPORTANT po 2 rundach) | zadanie 1 (bez DELTA) | ujawnione; RED nie odtwarzano po fakcie |
| Rundy CHANGES_REQUIRED | 1 | 3 | 3 (zadanie 4: trzy przebiegi) | w budżecie; 3 z 7 dotyczyły tylko TDD |
| Równoległość ponad zalecane 2–3 stosy | 6 stosów: wyczerpana pula sieci Dockera | 5 subagentów | 3 | 2 nieudane `make up` |
| Punkt odniesienia | `done` zapisane 2 razy | `usage_start: unreadable` (#821) | przerwa hosta liczona jako czekanie (#832) | zniekształcone pola rekordu |
| Uwaga z przeglądu odroczona bez własnej oceny skutku | S1 (4 s po raporcie) | M2 (17 s po raporcie) | brak | 2 błędy P2 znalezione dopiero przez Codexa |

Odroczenie S1 było zgodne z literą zasad obowiązujących 19.11 („wagę nadaje reviewer”, „SUGGESTION i Minor trafiają do
opisu PR jako odroczone”). Odroczenie M2 nie podpadało pod #812, bo nie miało skutku poza bazą.

## 5. Znaleziska zewnętrznego review

Review prowadził Codex Desktop (modele według rolloutów: `gpt-6-astra/medium`, raz `gpt-6.1-sol/medium`). Sesje
„Guardian review” to automatyczne zatwierdzanie poleceń Codexa, nie review kodu.

### 5.1. Błędy w kodzie

| Epik, PR | Znalezisko | Klasyfikacja | Skutek i waga | Pochodzenie | Kto wewnątrz miał szansę | Czego zabrakło (hipoteza) | Naprawa i zabezpieczenie |
|---|---|---|---|---|---|---|---|
| ABUSE-LIMITS, #802 | Dwa starty płatności z tym samym kluczem idempotencji: drugi anuluje u operatora sesję pierwszego | potwierdzony błąd, duplikat uwagi S1 `code-reviewer` | klient zostaje z martwym linkiem do płatności; skutek u operatora nieodwracalny; Important | sprzed epiku (PAYMENT #192); zadanie 5 przebudowało ten fragment i nie zamknęło okna | `code-reviewer` (Opus/medium): „S1 – SUGGESTION… okno rzędu mikrosekund”; sesja 4 s później: „odraczam do opisu PR” | reguły wagi dla skutku poza transakcją; własnej oceny skutku przez sesję; fałszywe zdanie w `apps/api/CLAUDE.md` o rollbacku wywołań operatora | ABUSE-LIMITS-12: RED `0664e28d`, fix `a07cb8b4`; test w CI `StartPaymentReplayRaceTest`; #812 (rubryka, ADR krok 4, `apps/api/CLAUDE.md`) |
| DEMO-SCALE, #788 | `make open` otwiera sklep, gdy równoległe `generate` podmienia dane | potwierdzony błąd, duplikat uwagi S1 QA (QA go odtworzyło) | publicznie widoczne dane w trakcie kasowania; Important (operacje) | powstał w epiku | `qa-adversarial` (Opus): „S1 (SUGGESTION)… trzeba uruchomić dwa polecenia naraz”; sesja 49 s później odroczyła | reguły „waga według złamanej gwarancji; dwie sesje naraz to zwykłe użycie” | ConfigMap `demo-data-lock`; test w `test_generate_script.py`; propozycja F1 w #818 (otwarta) |
| ASYNC-RELIABILITY, #811 | Panel i `app:messaging:check` nigdy nie liczą wiadomości porzuconej przez zabitego workera | potwierdzony błąd, duplikat uwagi M2 QA o gorszym skutku, niż ocenił QA | ślepe pole nadzoru w awarii, którą nadzór ma wykryć; Important | powstał w epiku: definicja W7 w specyfikacji (#739) | developer opisał `redeliver_timeout` w komentarzu; `code-reviewer` zadania 4 uznał zgodność ze zdaniem, którego w specyfikacji nie ma; QA: „Minor… Nie odtwarzałem tego… ok. 5 minut”; sesja 17 s później odroczyła | sprawdzenia zachowania zależności w kodzie `vendor` (w specyfikacji i w przeglądzie); eksperymentu dla szacunku „5 minut” | ASYNC-RELIABILITY-10: RED `3032f1ae`, fix `ca4a0dad`; test w CI `MessagingDatabaseQueuesTest`; zmiany procesu brak (#822, pozycje 1–2) |
| DELIVERY, #819 | Pola formularza metody dostawy edytowalne w trakcie zapisu: odpowiedź nadpisuje wpisane zmiany | potwierdzony błąd, nowy (nikt wewnątrz go nie zgłosił) | utrata danych wpisanych przez administratora; Important | wzorzec sprzed epiku (DISCOUNT-CODES #329), skopiowany zgodnie z planem zadania 5 | `code-reviewer` sprawdził tylko podwójne kliknięcie; UX: trzy uruchomienia, pierwsze dwa bez panelu; QA: „w przeglądarce nie sprawdzałem” | testu z wstrzymaną odpowiedzią; przeglądu przy realnym opóźnieniu API | DELIVERY-10: RED `d7cc2138`, fix `c281de93`; Vitest i E2E w CI; #831 („zapis w toku” w liście jakości, `apps/admin/CLAUDE.md`, `ux-reviewer.md`) |

Wszystkie cztery naprawiono przed merge'em, w 11–68 minut od wiadomości. Fałszywych alarmów w review kodu nie było.
Żadne znalezisko nie dotyczyło bezpieczeństwa ani danych zapisanych w bazie.

Kolumna „Czego zabrakło” to hipoteza autora raportu, nie obserwacja. Dane pokazują, co reviewer i sesja napisali
i kiedy; nie pokazują, czy wymieniona reguła albo sprawdzenie zmieniłyby wynik. Reviewerzy nie mieli reguł z #798
i #812 (sekcja 3.1), więc skuteczności tych reguł w opisanych przypadkach nie da się ocenić.

Otwarte po serii: wzorzec `apply(result.data)` bez blokady pól jest na `main` w `useDiscountCodeForm.ts`,
`usePromotionForm.ts` i `useProductForm.ts`; blokadę (`fieldset`) ma tylko formularz metody dostawy. Issue nie istnieje.
Działania tych formularzy nie sprawdzałem.

### 5.2. Uwagi poza kodem

- **ASYNC-RELIABILITY, decyzja D6** (cztery korekty, wszystkie trafne): zachowanie `Unrecoverable…` i `Recoverable…`
  w Symfony Messenger, awaria zapisu kopii, pętla zbiorczego `failed:retry`. Skutek: nowe zadanie 9 (CRITICAL).
- **DELIVERY, specyfikacja i plan przed implementacją:** sześć uwag przyjętych (m.in. sprzeczność rabatu z progiem
  darmowej dostawy, edycja numeru przesyłki, okno wdrożenia), dwie odrzucone przez właściciela.
- **ASH-V1 (tło, przed tagiem):** pięć znalezisk z rozmowy ChatGPT; jedno w układzie „reviewer widział, sesja zawęziła”.

### 5.3. Wzorce powtarzalne

1. **Wykryte wewnątrz, zaniżona waga, odroczenie bez analizy:** ASH-V1, ABUSE-LIMITS, DEMO-SCALE, ASYNC-RELIABILITY
   (4 z 5 epików natywnych z zewnętrznym review). Czas od raportu do decyzji: 4 s, 49 s, 17 s. Wagę zaniżył zarówno
   Opus (ABUSE, DEMO), jak i Sonnet (ASYNC); trzy przypadki nie pozwalają ocenić wpływu modelu.
2. **Poprawki procesu zamykają wąski przypadek:** #798 (utrata wejścia), #812 (skutek poza bazą), #831 (zapis w toku).
   Sesja `f3ac74d4` zastosowała lekcję S1 o 10:37, a o 12:15 odroczyła M2, bo „nie było skutku poza bazą”. Lekcje nie
   przechodzą między równoległymi sesjami: DEMO-SCALE odroczył swój S1 półtorej godziny po analizie S1 w ABUSE-LIMITS.
3. **Semantyka zależności przyjęta bez sprawdzenia:** W7 (Doctrine), D6 (Symfony Messenger), zdanie o rollbacku
   płatności w `apps/api/CLAUDE.md`.
4. **Przeplot w czasie testowany w jednym wariancie:** stan bazy bez wywołań operatora (ABUSE), jeden operator (DEMO),
   stan przed upływem limitu czasu (ASYNC), podwójne kliknięcie bez edycji pól (DELIVERY).

## 6. Czas, koszty i udział właściciela

### 6.1. Czas

| Miara | ABUSE-LIMITS | ASYNC-RELIABILITY | DELIVERY etap 1 |
|---|---|---|---|
| Zadania: plan / dostarczone | 9 / 11 | 8 / 10 | 7 / 10 |
| Start → gotowy PR | 4 h 06 min (pierwsze ready po 3 h 10 min) | 4 h 33 min | 7 h 13 min (4 h 55 min bez przerwy hosta) |
| Start → merge | 15 h 07 min (noc) | 4 h 33 min | 7 h 54 min |
| Praca / czekanie na człowieka / bezczynność (rekord) | 3,05 / 0,41 / 0,65 h | 3,63 / 0,76 / 0,15 h | 3,72 / 2,33 / 1,17 h |
| Suma czasu subagentów | 6,44 h | 7,24 h | 6,08 h |
| Subagenci naraz: maks. / średnio | 6 / 2,1 | 5 / 2,2 | 3 / 1,3 |
| CI: ostatni push → zielone | 14,8 min | 17,8 min | 23,0 min |
| Przebiegi CI / Deploy (anulowane, czerwone) | 13 / 12 (14 anul., 1 czerw.) | 16 / 13 (15 anul., 0 czerw.) | 16 / 14 (14 anul., 6 czerw.) |
| Zadania naprawcze poza planem | 2 (UX, Codex) | 2 (D6, Codex) | 3 (smoke/k6, UX, Codex) |

Suma czasu subagentów nie dodaje się do czasu zegarowego. `human_wait` DELIVERY zawiera 2,28 h przerwy hosta
(15:25–17:43), a ASYNC-RELIABILITY 32 minuty, w których CI było zielone od 20 minut, a sesja o tym nie wiedziała.

### 6.2. Właściciel

| Miara | ABUSE-LIMITS | ASYNC-RELIABILITY | DELIVERY etap 1 |
|---|---|---|---|
| Wiadomości w oknie epiku | 5 | 12 | 8 |
| Pytania o status | 2 | 7 | 4 |
| Przekazanie uwag zewnętrznego review | 2 | 1 | 1 |
| Decyzje wymagane przez proces | 0 | 2 (D6, zakres zadania 9) | 0 w oknie; P4 (VAT) przed startem |
| Zmiana zasad albo zakresu | 1 | 1 | 0 |
| Sterowanie środowiskiem | 0 | 1 (Auto-fix) | 3 (przerwa hosta) |
| Sesja czekała na odpowiedź, nie pracując | 24 min (po ready) | 7 min (Auto-fix) + 32 min (CI) | 10 min (po ready) + przerwa hosta |

Na pytania o decyzję właściciel odpowiadał po 3–28 minutach; sesja w tym czasie pracowała (4 developerów przy D6).
Rzeczywistego czasu pracy człowieka nie da się ustalić z transkryptów: znana jest liczba wiadomości i odstępy. Poza
oknami epików właściciel prowadził rozmowy z Codexem (D6: 8 wiadomości; plan DELIVERY: 13 wiadomości, 70 min).

### 6.3. Wartość tokenów według cennika API

To szacunek, nie rachunek. Stawki z `tools/metrics/config.json`, odczytane 2026-11-19
z `https://platform.claude.com/docs/en/about-claude/pricing` (USD za milion tokenów, wejście / wyjście / odczyt cache):
Opus 5.5 4 / 20 / 0,2; Sonnet 5.5 2 / 10 / 0,1; zapis cache 5 i 8 (Opus), 2,5 i 4 (Sonnet).

| Pozycja | ABUSE-LIMITS | ASYNC-RELIABILITY | DELIVERY etap 1 | Razem |
|---|---|---|---|---|
| **Epik, start → done** | **73,60** | **88,11** | **115,34** | **277,05** |
| developer | 39,02 | 45,35 | 66,31 | 150,68 (54%) |
| sesja prowadząca | 18,15 | 26,54 | 25,90 | 70,59 (25%) |
| `code-reviewer` (przegląd zadań) | 7,71 | 10,49 | 10,06 | 28,26 (10%) |
| przegląd epiku (QA, UX, architektura) | 8,72 | 5,73 | 13,08 | 27,53 (10%) |
| w tym Opus / Sonnet | 50,65 / 22,95 | 65,29 / 22,83 | 93,72 / 21,62 | 209,66 / 67,40 |
| w tym zadanie po review Codexa | 1,16 | 1,04 | 0,76 | 2,96 |
| w tym zadanie dodane decyzją D6 | – | 10,84 | – | 10,84 |
| Udział odczytu cache w wartości | 56% | 53% | 55% | |
| Przygotowanie (specyfikacja i plan) | 11,98 + brak danych (#795) | 13,73 | 22,43 | ≥ 48,14 |
| Retro | 4,07 | 5,04 | ok. 8 (w sesji prowadzącej) | ok. 17 |
| Sesja prowadząca po done (issues, sprzątanie) | 4,94 | 4,11 | w retro | 9,05 |

Dolna granica całości z przygotowaniem i retro: około 350 USD wartości tokenów. Nieprzypisana do zadania jest tylko
sesja prowadząca i przegląd epiku (`no_task`: 26,88 / 32,27 / 38,97).

Największe pojedyncze przebiegi developera (wszystkie Opus): ASYNC-RELIABILITY-3 23,41 USD (67 min), DELIVERY-4 24,89
(88 min) plus 10,07 przebiegu przerwanego wyłączeniem komputera, DELIVERY-1 14,03, ABUSE-LIMITS-1 10,99,
ABUSE-LIMITS-4 10,19. Razem 93,58 USD, czyli 34% wartości serii. Developer na Opus/high kosztował 0,29 USD za
minutę (7 przebiegów), na Sonnet/high 0,09 (14 przebiegów). Opus dostawał zadania CRITICAL, Sonnet zadania STANDARD,
więc te liczby opisują koszt różnych zadań i nie są porównaniem efektywności modeli.

Sesja prowadząca: 166–213 wywołań API na epik, średnio 364–439 tys. tokenów kontekstu na wywołanie. Odczyt własnego
kontekstu to 56–71% jej wartości.

### 6.4. Subskrypcja i wydatki

| Miara | ABUSE-LIMITS | ASYNC-RELIABILITY | DELIVERY etap 1 |
|---|---|---|---|
| Okno tygodniowe, wszystkie modele | 25% → 31% | 32% → 38% | 38% → 43% |
| Udział epiku w przyroście (szacunek narzędzia) | 0,58 → ok. 3,5 pp | 0,67 → ok. 4,0 pp | 0,95 → ok. 4,7 pp |
| Okno tygodniowe, Fable | 27% → 27% | 27% → 28% | 28% → 28% |

- **Faktyczne wydatki:** brak danych. Płatne przekroczenia były wyłączone (`extraUsage.enabled: false`), więc kosztem
  jest abonament, którego ceny nie ma w źródłach.
- **Codex:** review PR zużyły 1,1–4,3 mln tokenów każde (prawie wszystko to odczyt cache). Koszt w pieniądzach:
  nieznany. Limit 5-godzinny Codexa wyczerpał się dwa razy.

### 6.5. Porównanie z AO (tabela `metrics.py trend`, na zadanie planu)

| Miara | Mediana AO (25 epików) | ABUSE-LIMITS | ASYNC-RELIABILITY | DELIVERY etap 1 |
|---|---|---|---|---|
| Czas do gotowego PR | 1,73 h | 0,46 h | 0,57 h | 1,03 h |
| Regresje w oknie 14 dni | 0 | 0 (okno otwarte) | 0 (okno otwarte) | 0 (okno otwarte) |
| Wartość albo koszt | ≈ 14,9 USD (koszt z CLI) | ≈ 8,2 USD (cennik) | ≈ 11,0 USD (cennik) | ≈ 16,5 USD (cennik) |

Porównywalne są tylko czas do gotowego PR i regresje. USD liczy się inaczej w obu źródłach. Trzy epiki różnią się
zakresem i ryzykiem (limity i płatności; kolejki i ponowienia; nowy moduł z migracją), więc nie pokazują trendu.

## 7. Możliwości optymalizacji

Oszczędność liczbową podaję tylko tam, gdzie wynika z danych serii. Pozostałe pozycje to hipotezy.

| Obszar | Dowód | Spodziewany efekt | Koszt wdrożenia | Ryzyko dla jakości | Jak sprawdzić |
|---|---|---|---|---|---|
| Czekanie na zielone CI | ASYNC: 20 min bezczynności po zielonym CI; Auto-fix zgłasza tylko porażki. DELIVERY z `gh run watch`: 13 min czekania, ready 2 min po zielonym | do 20 min na epik, w którym sesja nie śledzi przebiegu (jeden przypadek) | jedno zdanie w kroku 7 | brak | odstęp zielone CI → ready poniżej 3 min |
| Review Codexa przed ready, nie po | w serii poprawka po review trafiała na PR już oznaczony jako gotowy: nowy push i 15–23 min CI po ready | PR jest oznaczany jako gotowy raz, po poprawkach; poprawka po review nadal wymaga pushu i przebiegu CI, więc oszczędności cyklu CI nie wykazano | zmiana kolejności, bez narzędzi | brak | liczba pushów po ready = 0; liczba przebiegów CI od końca przeglądu wewnętrznego do gotowości (porównanie z serią) |
| Środowisko przeglądów | UX w DELIVERY: 3 uruchomienia (`mounts denied`, kolizja TOTP z QA); QA w ASYNC: 41 fałszywych niepowodzeń (#645); 6 stosów wyczerpało pulę sieci | mniej zmarnowanych uruchomień; wartość mała (UX 3,20 USD), czas 20–35 min | pozycja 5 retro DELIVERY i #645 | brak | uruchomienia UX na epik = 1 + DELTA; 0 fałszywych czerwonych |
| Przerwa hosta | DELIVERY-4: przebieg przerwany (10,07 USD, 19 min) i 137 min przestoju | zależny od właściciela | brak (pomiar: #832) | brak | `host_pause` w rekordzie |
| Developer na Opus | 9 przebiegów = 107,79 USD (39% serii); Opus/medium w ABUSE-4 przeszedł przegląd za pierwszym razem | hipoteza: medium zamiast high dla części zadań CRITICAL; brak podstawy do liczby (1 przypadek), a koszt na minutę przy różnych zadaniach nie porównuje modeli | brak | realne: błędy w płatnościach i współbieżności | nie zalecam zmian; ewentualnie osobny eksperyment na tym samym zadaniu (EVALS wstrzymane) |
| Kontekst sesji prowadzącej | 25% wartości; 56–71% to odczyt własnego kontekstu | górna granica: ok. 23 USD na serię przy kontekście o połowę mniejszym | nowa sesja na każdy epik (już zwykle tak jest) | realne: sesja z mniejszą wiedzą gorzej ocenia odroczenia | nie zalecam zmian |
| Anulowane przebiegi CI | 14–15 anulowanych przebiegów na epik; push po każdym merge'u zadania | minuty GitHuba, bez wpływu na czas epiku | – | realne: czerwone CI draftu wykryło brak `deliveryMethodId` w smoke i k6 | nie zalecam zmian |
| Powtórzenia testów | 26–43 powtórzone uruchomienia, 5–18 min na epik | pomijalny | – | – | nie zalecam zmian |
| Powielanie przeglądów | 5 z około 40 odroczonych uwag zgłosiły 2–3 role; `architecture-reviewer`: 3 × KEEP za 4,60 USD | nieznany: udział 20% w wartości nie mówi, czy każda rola się opłaca | – | realne: trzy z czterech błędów serii zgłosił najpierw przegląd wewnętrzny | przeglądy zostają na razie w obecnym składzie ze względu na wykrywane błędy; ocena ról po kolejnych trzech epikach według znalezisk każdej roli |

Jakość przygotowania jako źródło poprawek (3 przypadki, bez wniosków o trendzie):

- ASYNC-RELIABILITY: specyfikację i plan napisał subagent w 43 minuty 18.11, bez dialogu i bez odświeżenia przed
  startem. Skutek: konflikt E5 z W10 (decyzja D6 w trakcie, nowe zadanie CRITICAL za 10,84 USD) i definicja W7 (błąd P2).
- ABUSE-LIMITS: specyfikacja odświeżona z decyzjami właściciela dzień przed startem (#795). Zero decyzji w trakcie.
- DELIVERY: specyfikacja w dialogu i z uwagami Codexa do planu. Jedno pytanie przed startem; plan pominął wywołania
  spoza `apps` (smoke, k6), co dało 4 czerwone przebiegi i DELIVERY-8.

Kontrole, które można zastąpić deterministycznym sprawdzeniem zamiast instrukcji:

- zgodność wersji agentów z bazą epiku (porównanie treści definicji w miejscu, z którego ładuje je aplikacja,
  z bazą);
- werdykt w pierwszej linii raportu: `metrics.py` już go czyta; czy reviewerzy z aktualną definicją będą go podawać,
  pokaże dopiero następny epik;
- blokada pól w trakcie zapisu: wspólny test dla wszystkich formularzy panelu (zadanie aplikacji, nie procesu).

## 8. Priorytetowe zmiany procesu (trzy)

### Z1. Agenci i skille w wersji bazy epiku

- **Obserwacja:** sekcja 3.1. Dotyczy każdej sesji desktopowej w worktree, także dzisiejszych: poprawka
  `ux-reviewer.md` z #831 nie dociera do subagentów, dopóki główny checkout stoi na `9ed889e2`.
- **Zmiana:** na starcie epiku i na początku `/retro` sesja ustala, z jakiej wersji pochodzą definicje agentów
  i skilli, których rzeczywiście używa, porównuje je z bazą epiku i przy różnicy zatrzymuje się przed pracą. Wersja
  i odstępstwa trafiają do pomiaru. Sposób doprowadzenia do zgodności wymaga osobnej decyzji; proste
  `git -C <główny checkout> pull --ff-only` ma dwie wady: daje najnowszy `main`, a nie bazę danego epiku, i zmienia
  definicje subagentom wszystkich trwających sesji (plik agenta działa od następnego zlecenia).
- **Hipoteza o efekcie:** reguły z #785, #798, #812 i #831 zaczną docierać do reviewerów. Ich wpływ na jakość jest
  nieznany; seria go nie zmierzyła.
- **Koszt:** kontrola na starcie epiku i retro; bez nowych narzędzi poza istniejącym pomiarem.
- **Ryzyko:** aktualizacja wspólnych definicji w trakcie epiku innej sesji zmienia jej zasady bez jej wiedzy;
  aktualizacja głównego checkoutu może też zderzyć się z lokalnymi zmianami właściciela. Kontrola musi więc
  uwzględniać równoległe sesje.
- **Weryfikacja:** treść definicji w miejscu ładowania równa treści z bazy epiku (porównanie skrótów); raporty
  reviewerów zaczynają się od `Werdykt:`; zero wpisów „bez rozpoznanego werdyktu” w `gaps`. Stała 1662 z sekcji 3.1
  to obserwacja jednej wersji CLI i nie nadaje się na trwałą kontrolę.

### Z2. Jedna ogólna reguła odroczeń zamiast trzech wąskich

- **Obserwacja:** sekcja 5.3, wzorce 1 i 2. Trzy z czterech błędów Codexa były już w raportach wewnętrznych; sesja
  odroczyła je w 4–49 s; naprawa każdego zajęła potem 11–21 minut i 0,8–1,2 USD.
- **Zmiana:** łączy istniejące propozycje (F1 z #818, pozycja 2 z #822, pozycja 2 retro DELIVERY) i nie tworzy
  czwartej. Uwaga, która opisuje konkretną ścieżkę błędu w zmienianym zachowaniu, nie jest odraczana po samej
  etykiecie. Sesja zapisuje krótką ocenę: którą gwarancję łamie, jaki jest rzeczywisty skutek, czy da się go cofnąć.
  Istotne podejrzenie oparte na niesprawdzonym założeniu („nie odtwarzałem”, założenie o zachowaniu zależności) sesja
  sprawdza w kodzie albo eksperymentem. Wagę wyznacza złamana gwarancja, nie rzadkość wyzwalacza; dwa żądania, dwie
  sesje i edycja formularza w trakcie zapisu to zwykłe użycie. Styl, drobne sugestie i przypadki bez istotnego skutku
  dalej można odraczać.
- **Hipoteza o efekcie:** część takich błędów zostanie naprawiona przed zewnętrznym review. Danych historycznych nie
  da się do tego użyć jako dowodu: nie wiadomo, jak sesje oceniłyby S1 i M2 z tą regułą.
- **Koszt:** akapit w ADR (kroki 4 i 6) i po zdaniu w `code-reviewer.md` i `qa-adversarial.md`.
- **Ryzyko:** więcej zadań naprawczych i rund; reguła nie może stać się obowiązkiem naprawy każdej uwagi.
  Zabezpieczenie w sekcji 10.
- **Weryfikacja:** odsetek odroczonych uwag z zapisaną oceną skutku; liczba potwierdzonych znalezisk zewnętrznego
  review, osobno tych, które powtarzają odroczoną uwagę wewnętrzną (punkt odniesienia: 3 w 4 PR).

### Z3. Ryzykowne założenia o zależnościach sprawdzone przed akceptacją specyfikacji

- **Obserwacja:** W7 i D6 w ASYNC-RELIABILITY, zdanie o rollbacku płatności w ABUSE-LIMITS. Istniejąca propozycja:
  pozycja 1 z #822 (pozycja 4 retro DELIVERY). Poprzednia poprawka (#561) trafiła do usuniętego `/prepare-epic`.
  W ASYNC-RELIABILITY skutkiem była jedna decyzja właściciela w trakcie epiku, jedno dodane zadanie CRITICAL (10,84
  USD) i jeden błąd P2. Podstawa to jeden epik.
- **Zmiana:** niepewne założenie o zachowaniu zależności, od którego zależy poprawność rozwiązania (transakcje, skutki
  zewnętrzne, ponowienia, limity czasu, współbieżność), jest sprawdzane przed akceptacją specyfikacji: w dokumentacji
  właściwej wersji, w kodzie zależności albo celowanym eksperymentem, odpowiednio do ryzyka. Zwykłe użycie frameworka
  nie wymaga eksperymentu.
- **Hipoteza o efekcie:** mniej decyzji i zadań dodanych w trakcie epiku z powodu sprzeczności specyfikacji
  z zależnością.
- **Koszt:** akapit w `/brainstorm`; dłuższe przygotowanie specyfikacji (wielkość nieznana).
- **Ryzyko:** nadmiar eksperymentów przy prostych wymaganiach, jeśli reguła zostanie zapisana zbyt szeroko.
- **Weryfikacja:** liczba decyzji właściciela w trakcie epiku spowodowanych sprzecznością specyfikacji z zależnością
  (punkt odniesienia: 2 w ASYNC-RELIABILITY, 0 w pozostałych).

Nie rekomenduję dodatkowych agentów ani reviewerów, zmiany modeli reviewerów (trzy przypadki nie dają do tego
podstawy) ani nowych narzędzi orkiestracji. Przeglądy zostają na razie w obecnym składzie ze względu na wykrywane
błędy; ich udział w wartości tokenów nie rozstrzyga o opłacalności poszczególnych ról.

## 9. Dalsze review Codexa

Rekomendacja: **utrzymać na kolejne trzy epiki, w zmienionej formie, i ocenić ponownie.**

- **Wartość:** cztery potwierdzone błędy Important w czterech PR, zero fałszywych alarmów, wszystkie naprawione przed
  merge'em. Trzy z nich powtarzały uwagę znaną wewnątrz i odroczoną; liczą się tak samo, bo bez zewnętrznego review
  trafiłyby na `main`.
- **Czego nie wiadomo:** reviewerzy działali na starych definicjach, więc seria nie pokazuje, ile z tych błędów
  zatrzymałby proces wewnętrzny w wersji 1.0 ani po Z1 i Z2.
- **Zmiana formy:**
  1. review po wewnętrznym przeglądzie epiku, przed oznaczeniem PR jako gotowy. Sama kolejność nie usuwa cyklu CI:
     poprawka po review to nadal push i zielone CI przed gotowością;
  2. stałe zlecenie: przegląd całej zmiany i osobno podważenie listy „Odroczone uwagi” (tam były 3 z 4 trafień);
  3. sesja nie uruchamia Codexa sama: przekazuje właścicielowi gotowe zlecenie i stan PR.
- **Review specyfikacji i planu:** jeden przypadek (DELIVERY, 6 przyjętych uwag). Warto powtórzyć przy epiku
  z integracją zewnętrzną; za mało danych na regułę.
- **Koszt:** nieznany w pieniądzach; po stronie właściciela 2–11 wiadomości na epik i ręczne przenoszenie treści.
- **Ocena po trzech epikach:** wszystkie potwierdzone znaleziska (także te, które powtarzają odroczoną uwagę), czas,
  koszt i udział właściciela, z uwzględnieniem różnic zakresu i ryzyka epików. Bez automatycznego warunku ograniczenia.

## 10. Plan sprawdzenia na kolejnych epikach

- **Punkt odniesienia:** ta seria. Na zadanie planu: czas do gotowego PR 0,46 / 0,57 / 1,03 h; wartość 8,2 / 11,0 /
  16,5 USD; rundy CHANGES_REQUIRED 1 / 3 / 3 na epik; zadania naprawcze poza planem 2 / 2 / 3; błędy z zewnętrznego
  review 1 na PR, w tym 3 z 4 powtarzające uwagę wewnętrzną.
- **Miary:**
  1. Z1: wersja definicji agentów i skilli zapisana w pomiarze i zgodna z bazą epiku; werdykt w pierwszej linii
     każdego raportu.
  2. Z2: każda odroczona uwaga o konkretnej ścieżce błędu ma zapisaną ocenę skutku; liczba potwierdzonych znalezisk
     zewnętrznych, osobno powtarzających odroczoną uwagę.
  3. Z3: liczba decyzji właściciela w trakcie epiku z powodu sprzeczności specyfikacji z zależnością.
- **Zabezpieczenia jakości:** regresje w oknie 14 dni; sprawdzenia po wdrożeniu (#829) wykonane; liczba testów RED
  przed implementacją; review Codexa utrzymane przez cały okres próby.
- **Sygnały do ponownej oceny Z2 (nie automatyczne cofnięcie):** zadania naprawcze z podniesionych uwag, wśród których
  żadna nie okazała się błędem; wyraźnie dłuższy czas do gotowego PR bez zmiany liczby znalezisk zewnętrznych. Progu
  liczbowego nie podaję: trzy epiki o różnym zakresie i ryzyku nie dają do niego podstawy, więc ocena porównuje epiki
  z uwzględnieniem tych różnic.
- **Horyzont:** trzy epiki. To nadal mała próba; wynik pokaże kierunek, nie trend.

## 11. Zamknięcie serii i decyzja o AO

- **Zamknięcie serii:** dowodów wystarcza. Trzy epiki zmergowane natywnie, rekordy metryk da się zbudować dla
  wszystkich trzech, retrospektywy są. Do uzupełnienia: rekord ABUSE-LIMITS w stanie narzędzia
  (`metrics.py epic abuse-limits`).
- **Czego seria nie dowodzi:**
  - skuteczności reguł przeglądu z `ash-v1.0`, #798 i #812 (reviewerzy ich nie mieli);
  - jakości na produkcji (nic nie wdrożono; #829 otwarte; okna regresji otwarte do 2026-12-04);
  - przewagi kosztowej nad AO (USD liczone inaczej).
- **Decyzja o AO (P6):** dowodów wystarcza, żeby ją podjąć. W sześciu epikach natywnych (SMALL-FIXES, ASH-V1, trzy
  epiki serii, DEMO-SCALE) AO nie był potrzebny ani razu jako rozwiązanie awaryjne, a w trzech epikach serii czas do
  gotowego PR na zadanie był 1,7–3,8 razy krótszy od mediany AO. Znalezione luki (wersja agentów, odroczenia) nie są
  czymś, co AO rozwiązuje.
- **Przy archiwizacji do zachowania**, bo używa ich ścieżka natywna: `.ao/setup` i `.ao/teardown` (ADR, krok 2),
  `/retro`, `tools/metrics`, `plan_manifest.py`, `test_delta.py` (#813).
- Odroczone propozycje do decyzji właściciela po serii: #818 (F1–F5), #822 (pozycje 1–3), pozycje 2–5 retro DELIVERY,
  #813, #821, #832. Z2 i Z3 obejmują F1, #822/1, #822/2 oraz pozycje 2 i 4 retro DELIVERY.

## 12. Niewiadome i ograniczenia

- Treść oryginalnego review ChatGPT dla ASH-V1 i ewentualne inne review w rozmowach ChatGPT poza Codexem: brak danych.
  Do pełnej analizy potrzebny eksport tych rozmów; nie odtwarzałem ich z domysłów.
- Czy reviewerzy z poprawnymi definicjami złapaliby którykolwiek z czterech błędów: nie do ustalenia bez powtórzenia
  przeglądów.
- Faktyczny koszt (abonament Claude, Codex) i rzeczywisty czas pracy właściciela: brak danych.
- Koszt przygotowania ABUSE-LIMITS (sesja #795) i udział sesji koordynującej w specyfikacjach z 18.11: brak danych.
- Udział epiku w przyroście okna subskrypcji to szacunek narzędzia; okno jest wspólne dla wszystkich sesji konta.
- Autorstwo wiadomości „przez Codexa” w sesjach Claude: w transkrypcie mają pochodzenie `human`; nie da się odróżnić
  tekstu właściciela od tekstu przygotowanego przez Codexa.
- Przyczyny czterech czerwonych przebiegów CI (ABUSE `1a8c120b`, DELIVERY `dd242609`, `api integration` na `83379f0f`,
  Deploy `b8730e9e`): logów nie czytano.
- Skąd AO ładuje definicje agentów (`--setting-sources project`, własne worktree): nie sprawdzano.
- Formularze kodów rabatowych, promocji i produktu: ten sam wzorzec w kodzie, zachowania nie sprawdzano.
- Częstość wyścigu S1 i opóźnienia zapisu na produkcji: brak danych (nic nie wdrożono).
- Liczby uwag na rolę pochodzą z opisów PR i werdyktów, nie z przeliczenia każdego raportu.
- Trzy epiki o różnym zakresie i ryzyku to za mało na wnioski o trendach.
