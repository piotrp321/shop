# Weryfikacja dashboardu ASH w lokalnej Grafanie: zużycie modeli i czas epików (2026-11-24)

Sprawdzenie, czy lokalna Grafana poprawnie pokazuje zużycie modeli i czas realizacji epików natywnych, na których
liczbach można oprzeć decyzje i gdzie dane pokazują realną możliwość oszczędności. Bez zmian w dashboardach,
konfiguracji i rekordach.

| | |
|---|---|
| Rewizja `shop` | `c617e788` (`tools/metrics`, cennik w `tools/metrics/config.json` odczytany 2026-11-19) |
| Rewizja `agent-observability` | `29bb92c`; dashboardy w działającej Grafanie są równe plikom `grafana/dashboards/*.json` tej rewizji |
| Epiki | `cart-order-consistency` (dalej COC; PR #894, sesja `49065246…`) i `admin-ux-2` (dalej AUX2; PR #896, sesja `58c0279c…`) |
| Dlaczego te | COC: do trzech subagentów naraz, orkiestrator na Fable, noc w środku. AUX2: zapisana przerwa komputera, subagenci wznawiani do sześciu zleceń. Oba epiki biegły w tym samym czasie |
| Czego nie sprawdzono | sesji prowadzącej wznowionej pod nowym identyfikatorem: żaden z 10 rekordów natywnych nie ma więcej niż jednej sesji |
| Czasy | UTC (czas lokalny to UTC+1) |

## 1. Wynik w skrócie

- Tokeny, wartość według modelu i roli, czas do gotowości i czekanie na człowieka z dashboardu „ASH: epiki natywne”
  są zgodne z rekordem, a rekord jest zgodny z transkryptami i z surową telemetrią co do pojedynczego żądania.
  Na tych liczbach można opierać decyzje.
- Wartość w dolarach to tokeny razy cennik API. Nie jest rachunkiem ani miarą limitu subskrypcji. Z danych nie da
  się dziś wnioskować o zużyciu limitu Fable.
- Trzech rzeczy dashboard nie pokazuje wiarygodnie: zużycia limitu Fable przez epik, czekania na CI i kosztu pracy
  agentów w czasie uśpienia komputera.
- Dashboard „Claude Code: surowa telemetria” nie nadaje się do oceny epiku natywnego: nie umie go wybrać.
- Dwie możliwości oszczędności z potwierdzonymi liczbami: praca subagenta podczas uśpienia komputera (do 19%
  wartości AUX2) i odbudowa kontekstu orkiestratora Fable po długiej przerwie (51% wartości Fable w COC).

## 2. Metoda

Trzy źródła są od siebie niezależne:

1. **Transkrypty** sesji prowadzącej i subagentów (`~/.claude/projects/…`). Z nich `metrics.py epic` buduje rekord,
   a eksporter i dashboard ASH tylko go pokazują. Zgodność dashboardu z rekordem nie jest więc potwierdzeniem.
2. **Liczniki Prometheusa** (`claude_code_token_usage_tokens_total`, `claude_code_cost_usage_USD_total`), wysyłane
   przez klienta co minutę.
3. **Zdarzenia Loki** (`api_request`): jedno zdarzenie na żądanie do modelu, z tokenami, kosztem i `request_id`.

Kroki:

- wartości paneli odczytane zapytaniami paneli przez API Grafany i porównane z rekordem;
- tokeny policzone od nowa z transkryptów (okno od startu do merge'u, każdy `message.id` raz) i porównane z rekordem;
- każda wiadomość transkryptu połączona ze zdarzeniem `api_request` po `requestId` = `request_id`;
- sumy liczników Prometheusa dla `session_id` epiku liczone jednym zakresem (`increase(...[zakres] anchored)`);
- oś czasu COC odtworzona z transkryptu sesji prowadzącej: wiadomości człowieka, starty i wznowienia subagentów,
  ich zwroty, czekania `ci-wait.sh`.

## 3. Tabela wyników

| Metryka | Grafana | Wynik odtworzony | Przyczyna różnicy lub ograniczenie |
|---|---|---|---|
| Wartość epiku COC | $151,30 | $151,30 z transkryptów; $152,79 z żądaniami spoza transkryptu; liczniki $153,15 | Brakuje 10 żądań podpowiedzi promptu na Fable ($1,49): nie trafiają do transkryptu. Reszta różnicy liczników to brzegi okna przy minutowej rozdzielczości (hipoteza) |
| Wartość epiku AUX2 | $98,72 | $98,72 z transkryptów; liczniki $102,35; zdarzenia Loki ok. $87 | Loki zgubił 36 żądań z czasu uśpienia. Liczniki mają o $3,37 więcej na Sonnecie niż transkrypt (sekcja 6.1) |
| Orkiestrator i subagenci liczeni raz | 23 (COC) i 15 (AUX2) przebiegów | 1516 z 1516 i 1285 z 1321 żądań z tokenami ma parę w telemetrii, zawsze z identycznymi tokenami; żaden `message.id` nie powtarza się między plikami | Wznowione przebiegi subagentów są liczone raz. Nieudany start subagenta w COC ma 0 żądań |
| Tokeny według rodzaju (COC) | wejście 3 028; wyjście 1 467 471; odczyt cache 330 292 755; zapis cache 8 236 479 | identyczne | „Wejście” to tylko część poza cache; realne wejście to odczyt i zapis cache razem (338,5 mln). Panel nie rozdziela zapisu na 5 minut i na godzinę, choć ceny różnią się 1,6 raza |
| Wartość według modelu (COC) | Fable $48,17; Opus $93,92; Sonnet $9,21 | równe sumie `cost_usd` klienta dla tych samych żądań | Tokeny razy cennik API, ten sam, którym liczy klient. Nie rachunek i nie jednostki limitu |
| Limit Fable | „brak danych” w obu epikach | odczyty konta w COC: Fable 82% → 3%, wszystkie modele 89% → 59%, bez zmiany terminu odnowienia okna | Odczyt dotyczy konta, nie epiku (sekcja 4.3) |
| Udział epiku w zużyciu (COC) | 51,7% | 59,9% według liczników w oknie od startu do gotowości ($152,52 z $254,72, trzy sesje) | Inna podstawa obliczenia; rekord oznacza wartość jako szacunek. Przyczyny nie ustalono |
| Czas do gotowości (COC) | 17,78 h | 17,78 h; PR zielony i po wszystkich przeglądach po 4,08 h | „Gotowość” to polecenie właściciela następnego dnia (sekcja 5) |
| Praca przy równoległych agentach | COC: 4,63 h w rozkładzie, 4,96 h w panelu „Zadania” | zgodne z osią czasu | Rozkład liczy równoległą pracę raz (suma odcinków), panel „Zadania” sumuje czas agentów |
| Czekanie na człowieka (COC) | 12,68 h | 10,58 h noc + 1,47 h + 0,27 h + 0,37 h | 16 minut z tego to nieudany start subagenta, po którym sesja czekała na „Try again” |
| Czekanie na CI (COC) | „brak danych”; „reszta” 0,47 h; panel CI: checks 9,2 min i e2e 13,5 min ostatniego przebiegu | pięć czekań, razem 75 min; 30 min blokowało pracę, 45 min szło równolegle z nią | Przebieg natywny nie ma kategorii CI. Blokujące czekanie jest w „reszcie” (28 min wobec 30 min; nie rozdzielano dokładniej) |
| Przerwa komputera (AUX2) | przerwa 10,15 h; praca 6,02 h; „poza zadaniami” 12,9 h; ux-reviewer 598 min | w czasie przerwy 48 żądań za $15,84 | Przerwa nie jest bezczynnością. Panele przebiegów liczą ją jako czas pracy agenta, a kosztu z przerwy dashboard nie wyróżnia |
| „Surowa telemetria”: koszt w oknie COC | $254,72 | $152,52 tej sesji | Sesje aplikacji desktopowej nie mają etykiety `shop_epic`, więc filtr epiku nie działa i zakres czasu miesza trzy sesje |
| „Surowa telemetria”: Active time (COC) | 0,47 h | praca według rekordu 4,63 h | Licznik nie mierzy czasu epiku |

## 4. Tokeny, modele i koszt

### 4.1 Co wchodzi do sumy epiku

Suma obejmuje wiadomości sesji prowadzącej i wszystkich subagentów od startu epiku do merge'u, każdą raz. Przebieg
wznowiony przez `SendMessage` zostaje w tym samym pliku transkryptu i nie dubluje żądań. Proces klienta przetrwał noc
bez restartu w obu sesjach (numeracja zdarzeń telemetrii nie zaczyna się od nowa).

Poza sumą są z założenia: praca tej samej sesji przed startem epiku (w COC 16 minut przygotowania specyfikacji)
i po merge'u (w COC $14 na Fable według liczników).

Poza sumą są też, bez śladu w rekordzie:

- żądania podpowiedzi promptu (`prompt_suggestion`): w COC 10 żądań, $1,49, każde czyta cały kontekst sesji
  prowadzącej (6,5 mln tokenów odczytu cache na Fable); w AUX2 8 żądań, $0,91. Ustawienie „Prompt suggestions” było
  włączone w dniu weryfikacji;
- żądania kompaktowania (`compact`): w obu sesjach wystąpiły dopiero po merge'u;
- odpowiedzi urwane w trakcie (sekcja 6.1).

### 4.2 Znaczenie liczników

| Rodzaj | Znaczenie | Udział w wartości COC |
|---|---|---|
| wejście | tokeny wejściowe poza cache | poniżej 0,1% |
| odczyt cache | kontekst przeczytany z cache przy każdym żądaniu | 41% ($62,02) |
| zapis cache 5 min | kontekst zapisany do cache przez subagentów | 19% ($29,43) |
| zapis cache 1 h | kontekst zapisany do cache przez sesję prowadzącą | 20% ($30,07) |
| wyjście | tokeny odpowiedzi | 20% ($29,77) |

80% wartości epiku to wielokrotne czytanie i zapisywanie kontekstu, nie generowany tekst. Mediana kontekstu jednego
żądania w COC: orkiestrator 496 tys. tokenów, developer 257 tys., qa-adversarial 196 tys., ux-reviewer 182 tys.,
code-reviewer 91 tys.

### 4.3 Koszt a limit Fable

Kwota na dashboardzie to szacunek według cennika API. Nie pozwala wnioskować o limicie Fable:

- panel „Limity subskrypcji” pokazuje różnicę dwóch odczytów całego konta. W COC i AUX2 odczyt spadł (Fable z 82% do
  3–4%) bez zmiany terminu odnowienia okna, więc rekord słusznie podaje „brak danych”;
- w żadnym z 10 rekordów natywnych nie ma jednocześnie wartości Fable i przyrostu okna Fable. Trzy epiki z Fable
  (`cart-order-consistency`, `admin-ux-2`, `small-fixes`) nie mają przyrostu, a sześć epików z przyrostem okna Fable
  (0–8 pp) nie używało Fable, czyli przyrost pochodził z innych sesji konta;
- nie wiadomo, jak limit waży zapis i odczyt cache wobec tokenów wyjścia.

Brakujący pomiar: odczyt limitu tuż przed i tuż po pojedynczym, znanym żądaniu Fable, gdy nie działa żadna inna sesja.

## 5. Oś czasu COC

| Czas (UTC) | Etap | Długość |
|---|---|---|
| 23.11, 17:40–19:54 | cztery zadania z przeglądami i poprawkami; zadania 2 i 3 równolegle | 2 h 14 min |
| 19:55–21:29 | przegląd całej zmiany (architektura, QA, UX) i poprawki QA1; sam przegląd QA 44 min, przegląd UX wystartował dopiero po nim | 1 h 34 min |
| 21:29–21:45 | końcowe CI; PR zielony i po przeglądach 4 h 05 min od startu | 16 min |
| 21:46–08:21 | noc, czekanie na właściciela | 10 h 35 min |
| 24.11, 08:21–09:51 | omówienie zewnętrznego przeglądu Codexa, czekanie na „Zacznij teraz” | 1 h 30 min |
| 09:51–10:07 | start subagenta nie powiódł się, sesja czekała na „Try again” | 16 min |
| 10:08–10:49 | poprawki CX1: trzy rundy developer i code-reviewer ($13,6 na subagentów) | 41 min |
| 10:49–11:04 | CI | 14 min |
| 11:04–11:26 | czekanie na „Oznacz jako gotowy” | 22 min |
| 11:27 / 11:29 | gotowość / merge | |

Gotowość opóźniły noc i pętla po zewnętrznym przeglądzie (3 h 06 min, z czego agenci 41 min i CI 14 min), nie CI
ani praca agentów. Trzy z pięciu czekań na CI biegły w tle równolegle z kolejnym zadaniem albo przeglądem.

Jak dashboard traktuje poszczególne rodzaje czasu:

- **praca równoległa**: rozkład czasu liczy sumę odcinków aktywności, więc trzy subagenty naraz to jeden odcinek;
  panele „Zadania” i „Przebiegi subagentów” sumują czas każdego agenta osobno;
- **czekanie na CI**: brak kategorii; czas trafia do „pracy”, gdy coś biegnie równolegle, i do „reszty”, gdy nie;
- **przerwa w sesji**: przerwa przed wiadomością człowieka to „czekanie na człowieka”; uśpienie komputera jest
  osobną kategorią tylko wtedy, gdy sesja zapisała je przez `metrics.py pause`.

## 6. Oszczędności

### 6.1 Praca subagenta podczas uśpienia komputera (AUX2)

Przebieg ux-reviewer `a34a030b602d040ad` na Sonnecie, noc 23/24.11, zapisana przerwa 21:58–08:07.

- Komputer budził się co około 16 minut. Przy każdym wybudzeniu agent wysyłał żądanie, a 5-minutowy cache już nie
  istniał, więc cały kontekst około 210 tys. tokenów był zapisywany od nowa. W całym przebiegu było 31 takich
  odbudów (6,49 mln tokenów zapisu cache).
- Koszt żądań z czasu przerwy według transkryptów: $15,84 (ux-reviewer $15,61, sesja prowadząca $0,23), czyli 16%
  wartości epiku.
- Liczniki klienta pokazują na Sonnecie o 1,27 mln tokenów zapisu i 1,27 mln odczytu cache więcej niż transkrypt
  ($3,37). Nadwyżka przypada na noc. Hipoteza: to odpowiedzi urwane w trakcie przez uśpienie (transkrypt ma z tej
  nocy co najmniej 8 komunikatów o urwanej odpowiedzi), policzone przez klienta, ale niezapisane w transkrypcie.
  Razem do $19, czyli do 19% wartości epiku.
- Przegląd UX, który w COC trwał 15 minut, tu zajął całą noc i został dokończony po wybudzeniu.
- Koszt jest w całości na Sonnecie, więc nie dotyczy limitu Fable.

Czasu do odzyskania nie da się oszacować: nie wiadomo, ile z 3,7 h pracy po wybudzeniu wykonałoby się w nocy bez
udziału właściciela.

Ustawienie „Keep computer awake while Claude works” było włączone w dniu weryfikacji; nie chroni przed uśpieniem po
zamknięciu pokrywy. Nie ustalono, czy to pokrywa była przyczyną: rekord mówi tylko o cyklach uśpienia i wybudzeń.

Sprawdzenie w kolejnym epiku, który przechodzi przez noc: rekord nie ma zapisanej przerwy, a w transkryptach
subagentów nie ma nocnych żądań z odczytem cache równym 0 i zapisem powyżej 50 tys. tokenów.

### 6.2 Odbudowa kontekstu orkiestratora Fable po przerwie (COC)

Trzy żądania sesji prowadzącej zapisały prawie cały kontekst od nowa:

| Czas (UTC) | Przerwa przed żądaniem | Zapis cache (1 h) | Wartość |
|---|---|---|---|
| 23.11, 20:40 | 30 min (czekanie na przegląd QA) | 446 671 | $7,15 |
| 24.11, 08:21 | 635 min (noc) | 541 039 | $8,66 |
| 24.11, 09:51 | 88 min (czekanie na decyzję) | 549 664 | $8,79 |

Razem 1,54 mln tokenów i $24,60: 51% wartości Fable w epiku ($48,17) i 16% wartości epiku. Dwie odbudowy ($17,45)
nastąpiły po przerwie dłuższej niż godzinny czas życia cache. Trzecia nastąpiła po 30 minutach, mimo godzinnego cache;
przyczyny nie ustalono.

Wznowienie ze skompaktowanym kontekstem około 100 tys. tokenów kosztowałoby około $1,6 zamiast $8,7, więc do
odzyskania jest do około $14 z $17,45. Mniejszy kontekst obniża też koszt każdego kolejnego żądania (dziś około
$0,10 samego odczytu).

Czy przekłada się to na limit Fable, nie wiadomo (sekcja 4.3). Sprawdzenie w kolejnym epiku: lista żądań sesji
prowadzącej z zapisem cache powyżej 100 tys. tokenów (czas, przerwa przed żądaniem, tokeny) oraz odczyt limitu tuż
przed pierwszym żądaniem po długiej przerwie i tuż po nim.

### 6.3 Obserwacja bez rekomendacji

Wznowienie developera po przeglądzie też odbudowuje jego kontekst, bo przegląd trwa zwykle dłużej niż 5 minut:
w COC sześć odbudów na Opusie, 1,88 mln tokenów zapisu cache, około $9 ponad cenę odczytu. Nie porównano tego
z kosztem świeżego przebiegu, który musiałby czytać pliki od nowa.

## 7. Ograniczenia źródeł

- **Loki gubi zdarzenia podczas uśpienia komputera.** W AUX2 brakuje 36 żądań (33 ux-reviewer, 3 sesji prowadzącej),
  w COC żadnego. Liczba zdarzeń z nocy nie jest miarą aktywności.
- **Liczniki Prometheusa są kompletne tylko w jednym zakresie.** Przy rzadkich eksportach okna godzinne
  `increase(...[1h] anchored)` zwracają zero, choć licznik rośnie; panele „over time” zaniżają wtedy aktywność.
  Suma jednym zakresem zgadza się z surowymi próbkami.
- **Liczniki odbiegają od żądań w obie strony.** W COC Opus ma w licznikach o 0,9% mniej tokenów wyjścia niż
  zdarzenia i transkrypty; w AUX2 Sonnet ma o 13,6% więcej zapisu cache niż transkrypt.
- **Wiersz „Aktywność na żywo” dashboardu ASH** łączy telemetrię po `session_id` bez granic czasu epiku: pokazuje
  też pracę sesji po merge'u, a dla `abuse-limits` i `async-reliability` (jedna sesja) oba epiki naraz.
- **Próba to dwa epiki.** Liczby z sekcji 6 opisują te dwa przebiegi i nie są progiem ani średnią.

## 8. Pierwsza rzecz do przetestowania

Przy najbliższym epiku, który przechodzi przez noc, nie dopuścić do uśpienia komputera z pracującymi subagentami,
a potem sprawdzić rekord i transkrypty według sekcji 6.1. To największa potwierdzona strata, test nie wymaga zmian
w kodzie, a wynik widać po jednym przebiegu.
