# Eksperyment AO-REVIEW-001: 7 epików przez AO, 2026-11-04 wieczór – 2026-11-07

Przegląd przebiegów AO v0.3.1 (z poprawkami #33–#36) na siedmiu epikach i baza do porównania po poprawkach
z 2026-11-08. Raport opisuje stan sprzed poprawek i po zapisaniu się nie zmienia. Porównanie trafia do
osobnego raportu (AO-REVIEW-002).

Źródła: `~/.local/state/ao-*/` (`run.log`, `events.jsonl`, `orchestrator.db`), `ao-night/measurements.jsonl`,
GitHub `piotrp321/shop` (PR, runy CI, issues), repo AO (PR #33–#36), skille `.claude/skills/`.
Czasy lokalne (CET). Koszt to szacunek AO z `claude -p` (ekwiwalent API, nie faktura).

Korekta zakresu: w tym okresie były też CHECKOUT (#148) i PAYMENT (#192), a DEPLOY jest w toku (nie tylko przygotowany).

## (a) Przebiegi

| Epik | Start → końcowy PR | Zadania / próby | Integracja: próby, min | FIX / REPAIR | Interwencje | Koszt | Follow-upy |
|---|---|---|---|---|---|---|---|
| VARIANTS | 11-04 19:52 → 11-05 10:42 (14 h 50 min, w tym 7,6 h wstrzymania: w nocy padł Docker, potem pin CLI) | 5 / 8 (VARIANTS-3 `invalid_output` tdd) | 1, 22 | 0 / 0 | 2: środowisko, pin CLI (AO #33) | $90 | 3 (#130–#132) |
| CHECKOUT | 11-05 13:04 → 11-06 00:02 (11 h, w tym 2 h `CI_PENDING` przez incydent GH Actions i 53 min czekania na decyzję) | 4 / 9 | 3, 88 (BLOCKED B1: 409 a spec) | 1 / 0 | 2: decyzja B1 (AO #34), ręczne close/reopen PR #142 (#144) | $70 | 0 |
| PRODUCT-PAGE | 11-06 09:47 → 17:28 (7 h 41 min) | 5 / 11 | 2, 26 (DELTA przerwana merge'em) | 0 / 3 | 2: ręczny sync #158, REPAIR-1 bez wyjścia (AO #35); AO zatrzymany ręcznie | $59 | 3 (#165–#167) |
| PAYMENT ∥ SEARCH | 11-06 18:24 → 11-07 01:43 (7 h 19 min), potem BLOCKED o 03:31 | 5 / 12 | 3, **208** | 1 / 2 | 2: BLOCKED `max_qa_rounds` (AO #36), ręczne PR #206 i #207 | $93 | 8 (#209–#216) |
| SEARCH ∥ PAYMENT | 11-06 19:12 → 11-07 02:16 (7 h 04 min) | 6 / 11 (SEARCH-4 BLOCKED) | 2, 82 | 1 / 0 | 1: decyzja o fasetach | $76 | 9 (#197–#205) |
| CATALOG-ADMIN ∥ OM, DEPLOY | 11-07 08:45 → 15:53 (7 h 08 min), DONE 17:18 | 7 / 14 | 4, **190** (65 min straty na nieprawidłowym JSON-ie) | 1 / 1 | 0 (ręczne zamknięcie #95) | $92 | 8 (#247–#254) |
| ORDER-MGMT ∥ CA, DEPLOY | 11-07 12:35 → 17:58 (5 h 23 min) | 6 / 8 | 1, 66 | 0 / 0 | 0 w AO; konflikt po DONE → ręczny PR #255 (ok. 30 min) | $67 | 9 (#256–#264) |
| DEPLOY (w toku) | 11-07 14:57 → — | 2 z 6 zmergowane | — | — | DEPLOY-3 BLOCKED po 2 h 24 min ($17) | $25 | — |

Razem 7 epików zakończonych w ok. 3 dni: 38 zadań planu, 73 próby (wszystkie próby executora: zadania planu,
integracja, FIX, REPAIR i FINALIZE; tak samo w kolumnie „Zadania / próby”), **36 z 38 zadań przeszło za pierwszym razem**,
0 porażek kodu. Czas executora 2971 min: zadania planu 68%, integracja 23%, FIX 5%, REPAIR 3%, FINALIZE 2%.
Koszt $546 (suma dokładnych kwot; zaokrąglone kwoty w tabeli dają $547; średnio $78 na epik, $11 na zadanie). Limit tygodniowy wzrósł z 13% do 40% przy 5 epikach i części DEPLOY
(ok. 5 pp na epik).

Przykład czystego przebiegu (OM, 5 h 23 min): zadania 239 min (średnio 40 min na zadanie, w tym ok. 7 min CI),
integracja 66 min, FINALIZE 5 min, przerwy 11 min. Większość czasu zajmuje praca developera i reviewera w `/delivery`.

Czerwone CI (257 runów): 6 porażek i 9 ponownych uruchomień. Flaky testy: `MeEndpointTest` (wygasały tokeny,
naprawione w #157), `AccessTokenSignatureSpeedTest` (asercja czasu, poszerzona w #170), `PriceChangeOutboxTest`
(PAYMENT-4, bez zgłoszenia), `listing-query.test.ts` „linear time” (push epiku CA, bez zgłoszenia), test bucketów (#249).
CI zadania po przyspieszeniu: mediana 6,7 min (wcześniej 13–16 min).

## (b) Co działa dobrze

- **Delivery jest przewidywalne.** 36 z 38 zadań przeszło za pierwszym razem, każde ma PR, review, TDD w dowodach
  i zielone CI przed merge'em do `epic/*`.
- **Przyspieszenie CI się zwróciło.** PR zadania od otwarcia do merge'a: średnio 40 min w CHECKOUT (z incydentem GH),
  16 min w VARIANTS, ok. 7,4 min w CA, OM i PAYMENT. Dziedziczenie zielonego CI z PR przez gałąź epika działa
  (`epic_ci_inherited`), a `epic_ci_rerun` sam obszedł dwa flaky.
- **Integracja wyłapuje prawdziwe problemy.** 5 znalezisk Important w 4 epikach, każde naprawione w jednej rundzie FIX.
  PAYMENT F1 to prawdziwy wyścig w `StartPaymentHandler` (#189). Pozostałe to a11y i UX.
- **Blokery nie zatrzymują kolejki.** Gdy SEARCH-4 czekał na decyzję, AO zrobił w tym czasie SEARCH-5
  (`dependency_blocked`), więc epik nie stracił czasu.
- **Równoległe epiki działają operacyjnie.** Osobne `AO_HOME` i porty, bez kolizji. SEARCH i PAYMENT zajęły razem
  ok. 9 h zamiast ok. 16 h po kolei. CA, OM i DEPLOY szły w tym samym czasie.
- **Zamrożenie AO działa zgodnie z założeniem.** 4 poprawki AO (#33–#36), każda mała, wymuszona przez zablokowany
  przebieg i zweryfikowana na następnym epiku.

## (c) Co jest źle (z dowodem)

1. **Squash-sync z `main` nie usuwa konfliktu, tylko go odracza.** Najdroższy problem tego okresu. Ruleset `epic`
   dopuszcza tylko squash (`tools/rulesets/epic.json`), a AO merguje PR REPAIR przez `--squash` (`orchestrator/github.py:364`).
   Merge-base się nie przesuwa, więc te same pliki są w konflikcie przy każdym końcowym PR.
   - PRODUCT-PAGE: ręczny sync #158, a potem i tak REPAIR-2 (#164).
   - PAYMENT: REPAIR-1 (#193) nie pomógł, potem REPAIR-2 (#196), runda DELTA-3 (72 min), BLOCKED `max_qa_rounds`
     i dwa ręczne PR (#206, #207). #207 zostawił w dokumentacji „cztery komendy” seeda zamiast sześciu, żeby ominąć
     konflikt (dług trafił do #209).
   - CATALOG-ADMIN: REPAIR-1 (#241, +22 980 linii z PAYMENT i SEARCH) i runda DELTA-3 (55 min).
   - OM: konflikt po DONE (nelmio, `openapi-admin.json`, `schema.d.ts`, menu panelu, `apps/admin/CLAUDE.md`),
     ręczny PR zastępczy #255 (#264).
   - Koszt w okresie: ok. 4,5 h executora i ok. 2 h pracy człowieka.
2. **Gorące pliki wspólne dla epików:** `.github/workflows/ci.yml`, `apps/*/CLAUDE.md`, `openapi*.json`,
   klienci TS, konfiguracja nelmio, menu panelu. Konflikt PAYMENT wywołała przebudowa CI (#159, #169), zmergowana
   do `main` 20–30 min po starcie epika. Konflikt CA wywołał merge PAYMENT, a konflikt OM merge CA.
3. **Rundy DELTA po samym syncu z `main` powtarzają pełny przegląd:** PAYMENT-INTEGRATION-3 72 min / $6,43,
   CA-INTEGRATION-3 55 min / $6,59. Diff epika się nie zmienił, a architekt, QA i UX liczą się od nowa.
4. **`/integration` straciło 65 min ($4,78) przez końcową wiadomość, która nie była JSON-em** (#254). Przyczyna z
   `stdout.jsonl`: poprawny JSON PASSED był w przedostatnim wyniku, a potem przyszła spóźniona pobudka zadania w tle
   (`make e2e`). Ostatnią wiadomością został tekst „Ta pobudka jest już nieaktualna…”, więc AO powtórzył całą rundę.
5. **Integracja jest długa i wciąż się wydłuża:** rundy FULL 22–35 min w VARIANTS/PP, 47–104 min w CA, OM i PAYMENT.
   Lokalne `make check/test/e2e` powtarzają CI tego samego SHA. W CA dwa przebiegi e2e padły przez pętlę HMR
   `next dev` (#205, naprawione w #223).
6. **Luki w specyfikacji wychodzą w trakcie delivery, a blokery zgłaszane są późno.** SEARCH-4: BLOCKED po 44 min
   (limit 400 par w fasetach), druga próba 42 min. DEPLOY-3: BLOCKED dopiero po 2 h 24 min i $17 (obraz Elasticsearch
   z wtyczką nie jest publikowany). CHECKOUT: spec rozróżniała przyczyny 409, a API tego nie robiło (B1).
7. **„Minor” przepuszcza błędy do backlogu.** OM PASSED z 11 Minor, a z nich bugi #256 i #257. CA PASSED z 15–16 Minor,
   a z nich #247 i #248 (500 zamiast 422). Po 3 dniach jest ok. 40 follow-upów, w tym 7 `bug`, 8 a11y i 3 o znakach
   Unicode lub sterujących (#198, #247, #256).
8. **Powtarzające się kategorie znalezisk.** 4 z 5 znalezisk Important to a11y lub UX frontu (fokus po akcji,
   `aria-live`, ekran 375 px), a te same kategorie wracają w follow-upach (#201, #202, #210, #211, #250, #260, #262).
9. **Brak `Closes #N` w końcowych PR:** #195, #239 i #245. Epiki trzeba było zamykać ręcznie, a w CA klasyfikator
   odmówił zamknięcia #95.
10. **Środowisko hosta.** Każdy przebieg ma zdarzenie `host_on_battery` z poziomem 19–20% („low”). VARIANTS stracił
    7,6 h w nocy (padł demon Dockera, `queue_held`). Do tego `Permission denied (publickey)` i 502/500 z GitHuba
    w CHECKOUT, obsłużone przez ponawianie.
11. **Pomiar czasu człowieka nie jest pomiarem.** Każdy komentarz pomiarowy podaje 30 min, także OM, gdzie sam PR
    zastępczy zajął ok. 30 min.
12. **`/sweep` nie był uruchomiony po żadnym z 7 epików.** Jedyny przebieg jest z 2026-11-04, a poprawki promptów
    testera i UX nadal nie są sprawdzone.

## (d) Co poprawić

### Skille i proces repo (bez zmian w AO)

| # | Zmiana | Oczekiwany efekt |
|---|---|---|
| R1 | W `/delivery`, w trybie naprawy konfliktu: pliki generowane (`openapi*.json`, `schema.d.ts`) brać z `main` i generować od nowa (`make -C apps/api openapi`, `make api-client`). W listach w `CLAUDE.md` i `ci.yml` zostawić linie `main` dosłownie, a własne dopisać w miejscu niesąsiadującym (technika z #196 i #207). | Jedna próba REPAIR zamiast dwóch, bez DELTA-3 i BLOCKED. W PAYMENT oszczędność ok. 1,5 h, bez psucia dokumentacji. |
| R2 | W `/ao-run` opisana ścieżka „konflikt końcowego PR po DONE albo powtórny konflikt”: skrypt `sync/<epik>-main` (epik + prawdziwy merge `main`, regeneracja kontraktu, `make check`), zastępczy PR z `Closes #N`, zamknięcie starego. To sformalizowana procedura z OM (#264). | 30 min ręcznej pracy → ok. 5 min, bez improwizacji. |
| R3 | W `/integration`: gdy od poprzedniej rundy doszedł tylko merge `main` (to samo drzewo epika w `git merge-tree` względem `main`), runda ogranicza się do bramek i przenosi werdykty reviewerów. | 55–72 min i ok. $6 mniej na każdą taką rundę. |
| R4 | W `/integration` przed końcowym JSON-em: poczekać na wszystkie zadania w tle albo je zatrzymać. Jeśli przyjdzie późniejsza pobudka, odpowiedzieć tym samym zwalidowanym blokiem JSON. | Koniec utraty całych rund (#254, 65 min). |
| R5 | W `/integration`, w trybie DELTA: gdy CI `checks` i `e2e` jest zielone dla tego SHA, użyć go jako dowodu bramek zamiast lokalnych `make check/test/e2e`. | Szacunkowo 10–25 min na rundę, bez lokalnych flaky `next dev`. |
| R6 | Kalibracja wagi w `/integration`: 500 na danych użytkownika, komunikat 4xx po angielsku i dziura w walidacji tekstu to Important (naprawa w przebiegu), nie Minor. | Mniej bugów w backlogu (7 w okresie) kosztem jednej rundy FIX (15–25 min, $2–4). |
| R7 | Lista kontrolna w agentach `developer` i `code-reviewer`. Front: fokus po akcji, `aria-live` dla asynchronicznych statusów i błędów, szerokość 375 px. API: znaki sterujące i białe znaki Unicode, komunikaty po polsku. | Mniej rund FIX+DELTA (każda 40–130 min) i mniej follow-upów a11y. |
| R8 | W `/prepare-epic`: sekcja „Decyzje przed uruchomieniem” dla artefaktów infrastruktury (obrazy, rejestr), limitów i agregacji. W `/delivery`: zgłaszać BLOCKED zaraz po rozpoznaniu decyzji, przed implementacją. | Mniej przypadków takich jak DEPLOY-3 (2 h 24 min, $17) i SEARCH-4 (ok. 45 min). |
| R9 | `/ao-run` przy `epic_done` dopisuje `Closes #<issue epiku>` do końcowego PR (`gh pr edit`). Wymaga zmiany zasady w `CLAUDE.md` („dopisuje użytkownik”). | Koniec ręcznego zamykania epików (3 z 7). |
| R10 | Planowanie równoległości: przed startem kolejnego epika porównać `scope` z działającymi epikami na gorących plikach. W czasie przebiegów nie mergować do `main` zmian w CI i infrastrukturze. Końcowe PR mergować w kolejności DONE, a pozostałe synchronizować od razu. | Mniej konfliktów (przyczyny wszystkich 4 przypadków są wyżej). |
| R11 | Flaky testy: usunąć asercje czasu ściennego z testów jednostkowych (`AccessTokenSignatureSpeedTest`, „linear time” w `listing-query`), zgłosić `PriceChangeOutboxTest`. | Mniej reruns (5–7 min każdy) i mniej czerwonych pushy epika. |
| R12 | Długie przebiegi tylko na zasilaniu i pod `caffeinate`, a w `/ao-run` start dopiero po sprawdzeniu Dockera. | Bez nocnych przestojów takich jak 7,6 h w VARIANTS. |
| R13 | Czas człowieka mierzyć z sesji (znaczniki czasu) albo nie podawać go wcale. Jeden `/sweep` na `main` po tej serii. | Rzetelne liczby; weryfikacja poprawek testera i UX. |

### AO (zamrożone; tylko gdy przebieg jest zablokowany)

| # | Zmiana | Uzasadnienie wobec zamrożenia |
|---|---|---|
| A1 | Merge PR REPAIR do `epic/*` przez merge commit, nie squash (`github.py:364`), oraz `allowed_merge_methods: ["squash","merge"]` w rulesecie `epic`. To usuwa przyczynę z (c)1. | Spełnia warunek: PAYMENT był BLOCKED, a OM wymagał ręcznego PR. Zmiana mała (metoda merge'a zależna od rodzaju zadania). R1 i R2 zmniejszają problem, ale go nie usuwają. |
| A2 | Runda DELTA po REPAIR, który tylko zsynchronizował `main`, nie liczy się do `max_qa_rounds` (#216). | Częściowo obchodzi to `--add-qa-rounds` (#36). Robić dopiero, gdy znowu zablokuje. |
| A3 | Równoległe zadania w obrębie epika (dziś jeden executor na `AO_HOME`, `store.py:42`). | Największy zysk czasu (e), ale duża zmiana. Nie teraz; równoległe epiki dają przepustowość bez zmian w AO. |
| A4 | `CI_PENDING` bez żadnego runu (#144), wykrywanie konfliktu po DONE. | Pojedyncze przypadki, które R2 i obejście ręczne pokrywają. Zostawić. |

## (e) Co można przyspieszyć i o ile (szacunki)

- **Konflikty (R1, R2, ewentualnie A1):** w tym okresie ok. 4,5 h executora i ok. 2 h człowieka na 4 epiki.
  Po zmianach: 1–2,5 h mniej na każdy epik z konfliktem i 30 min mniej pracy człowieka.
- **DELTA po syncu (R3):** ok. 2 h w okresie (72 + 55 min); dalej ok. 1 h na każdy konflikt.
- **Duplikat bramek w DELTA (R5):** 10–25 min na rundę × 8 rund DELTA ≈ 1,5–3 h w okresie. To szacunek: czasu kroków
  bramek nie zmierzyłem.
- **Wczesne blokery (R8):** DEPLOY-3 ok. 2 h, SEARCH-4 ok. 45 min.
- **Pojedyncze straty (R4, R12):** 65 min (JSON) i 7,6 h (noc VARIANTS) w okresie.
- **Równoległość zadań w epiku (A3):** ścieżka krytyczna jest krótsza od sumy zadań o 34–100 min na epik
  (razem 468 min na 7 epików, ok. 25% czasu zadań). Największy zysk, ale wymaga zmiany AO.
- **Typowy epik:** dziś 5,5–7,5 h od startu do końcowego PR. Po R1–R5 i R8 realnie 4,5–6 h (−15–20%), z A3 3,5–5 h.
  Przepustowość dnia zależy bardziej od równoległych epików i szybkiego merge'a końcowych PR niż od pojedynczego przebiegu.

## (f) Priorytety

1. **R1 + R2: konflikty końcowego PR** (reguła naprawy w `/delivery` i procedura `sync/<epik>-main` w `/ao-run`).
   Jeśli konflikt znowu zablokuje przebieg, **A1** (REPAIR przez merge commit) za zgodą użytkownika.
2. **R3 + R4: tańsza i odporna integracja** (lekka DELTA po syncu, JSON odporny na pobudki w tle).
3. **R10 + R9: zasady równoległych epików** (gorące pliki, bez zmian CI na `main` w trakcie przebiegów,
   merge w kolejności DONE, automatyczne `Closes #N`).
4. **R6 + R7: mniej bugów w backlogu** (kalibracja wagi i lista a11y/Unicode dla developera i reviewera).
5. **R8: decyzje przed uruchomieniem** w `/prepare-epic`, do sprawdzenia już na DEPLOY, który właśnie stoi na takim blokerze.

## (g) Wdrożone poprawki (2026-11-08)

| PR | Zakres |
|---|---|
| #277 | R1, R2, R8 (`/delivery`), R9, R10, R12, R13, ruleset `epic` z `allowed_merge_methods: ["squash", "merge"]` |
| #279 | R3, R4, R5, R6 w `/integration` |
| #276 | R6 i R7 w agentach, R8 w `/prepare-epic` |
| AO #37 | A1: AO merguje PR zadania REPAIR merge commitem, pozostałe squashem |

Stan po wdrożeniu: `main` `4fc8291`, AO `main` `a178eaf`, ruleset `epic` na GitHubie zaktualizowany 2026-11-08.
Poza zakresem: A2, A3, A4, R11 (flaky testy na tablicę) i `/sweep`.

Zasięg w trwających przebiegach:
- **DEPLOY** od `DEPLOY-INTEGRATION-1` działa na AO z A1, ale ze skillami z `epic/deploy` sprzed poprawek.
- **PROMOTIONS** wystartował z `main` sprzed #276, #277 i #279, więc sprawdza tylko A1 i ruleset.
- Pełny zestaw (skille i AO) sprawdzi dopiero epik założony od `4fc8291` albo później (DISCOUNT-CODES).

## (h) Baza do porównania

Czas „start → końcowy PR” jest skorygowany o przestoje hosta (noc VARIANTS, 7,6 h), incydent GitHub Actions
i czekanie na decyzję w CHECKOUT (2 h 53 min). Pozostałe liczby pochodzą z (a) i (c).

| Metryka | Baza (7 epików) | Cel po poprawkach |
|---|---|---|
| Start → końcowy PR | mediana ok. 7 h 14 min, zakres 5 h 23 min – ok. 8 h 07 min | 4,5–6 h (hipoteza) |
| Epiki z konfliktem końcowego PR | 4 z 7 | bez celu: zależy od równoległości |
| Konflikt powtórzony po naprawie | 2 (PRODUCT-PAGE, PAYMENT) | 0 |
| Ręczne PR naprawcze lub zastępcze | 4 (#158, #206, #207, #255) | 0 |
| Rundy DELTA po samym syncu | 2 (72 i 55 min) | do 15 min każda |
| Utracone wyniki integracji | 1 (65 min) | 0 |
| Interwencje człowieka | 10 | mniej |
| Bloker zgłoszony po ponad 60 min | 1 (DEPLOY-3, 2 h 24 min) | 0 |
| Koszt na epik | średnio $78, mediana $76 | nie wyższy przy porównywalnym zakresie |
| Follow-upy na epik | ok. 5,7 (40 na 7), w tym 7 `bug` i 8 a11y w sumie | wyraźnie mniej `bug` i a11y |

Źródła do porównania: `~/.local/state/ao-<epik>/` (`run.log`, `events.jsonl`, `orchestrator.db`), PR i issues
w `piotrp321/shop`. W porównaniu podajemy medianę i zakres, a szacunki oddzielamy od pomiarów.
