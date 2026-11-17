# EVALS 2.0: ewaluacja agentów na prawdziwych review

Mierzy jakość agenta na prawdziwych zadaniach: uruchamia produkcyjną definicję agenta, zbiera jego raport, a wynik
powstaje z etykiet właściciela nadanych konkretnym zgłoszeniom. Obejmuje dziś jednego agenta, `code-reviewer`,
i sześć przypadków. Narzędzie: `tools/evals-pilot/` (opis uruchomienia w jego `README.md`). Stan prac: `plan.md`.

## Zasady

1. **Najpierw pomiar, potem narzędzia.** Nowy mechanizm powstaje dopiero wtedy, gdy pomiar pokaże, że jest potrzebny.
   Każdy etap ma zakres, limit kosztu i kryteria akceptacji zatwierdzone przez właściciela przed startem.
2. **Prawdziwy agent bez zmian.** Agent działa na definicji z `origin/main`, z modelem i effortem z frontmattera.
   Ewaluacja nie zmienia promptu agenta ani formatu jego wyniku.
3. **Etykiety człowieka są źródłem prawdy.** Każde zgłoszenie `IMPORTANT` albo `BLOCKER` dostaje etykietę właściciela.
   Model może przepisać raport do listy zgłoszeń i podpowiedzieć, czy zgłoszenie opisuje wadę wzorcową, ale jego
   odpowiedź nigdy nie jest etykietą.
4. **Bez dopasowań na prozie.** Nie ma wyrażeń regularnych oceniających treść raportu ani parserów poleceń powłoki.
5. **Jakość osobno od bezpieczeństwa.** Zestaw nie ma przypadków prompt injection ani detektorów komend. Granicę
   daje sandbox AGENT-SECURITY, a jej testem jest sonda `tools/agent-security/probe.py`, która sprawdza skutki.
6. **Każdy wynik da się prześledzić:** etykieta → zgłoszenie z dosłownym cytatem → raport agenta → transkrypt.
7. **Twarde limity kosztu:** na przebieg (egzekwowany przez CLI w trakcie wywołania) i łączny (sprawdzany przed każdym
   wywołaniem modelu). Płatny pomiar wymaga osobnej zgody właściciela.

## Przypadek

Prawdziwe zadanie zrealizowane przez `/delivery`:

- **z wadą:** błąd, który przeszedł code review i wrócił jako zgłoszenie `bug`; opis wady (pliki, mechanizm, skutek)
  jest w `gold.md`, a dowód, że wada jest w recenzowanym diffie, w `replay/replay.json`;
- **czysty:** zadanie bez znanej wady. Czystość wymaga potwierdzenia właściciela; brak zgłoszenia jej nie dowodzi.
  Znane ograniczenia diffu są w `known.md`, a zgłoszenie o nich liczy się jako trafne.

Przypadek odtwarza pierwsze review FULL: commit, który reviewer faktycznie recenzował, zlecenie, które orkiestrator
`/delivery` wtedy wysłał (z zapisaną różnicą wobec oryginału), i `code-reviewer` uruchomiony jako subagent. Przebieg,
w którym subagent nie został wywołany dokładnie raz z niezmienionym zleceniem, nie dostaje wyniku.

## Etykiety

| Etykieta | Znaczenie | Liczy się jako |
|---|---|---|
| `wada` | zgłoszenie identyfikuje mechanizm wady wzorcowej | wykrycie wady wzorcowej |
| `czesciowe` | zgłoszenie opisuje tylko część wady wzorcowej | wykrycie częściowe, osobno |
| `trafne` | inny prawdziwy problem | trafna usterka, nie wykrycie wady wzorcowej |
| `falszywe` | zgłoszenie nietrafne | fałszywy alarm |
| `drobne` | prawdziwe, ale bez wagi `IMPORTANT` | osobno |
| `nieznane` | nie da się rozstrzygnąć z danych przypadku | osobno; ani trafienie, ani fałszywy alarm |

Etykieta `trafne` może być warunkowa (PROVISIONAL, `provisional: true` w `labels.json`): problem jest wiarygodny, ale
nie został odtworzony. Takie zgłoszenia liczy się osobno i nie są potwierdzonymi usterkami, dopóki ktoś ich nie odtworzy.
Zgłoszenie o naruszeniu procesu (np. brak fazy RED) ma kategorię „proces TDD” i też jest liczone osobno od usterek kodu.
Wykrycie wady wzorcowej oznacza zidentyfikowanie jej mechanizmu, niezależnie od wagi nadanej przez agenta; poprawność
wagi raportuje się osobno.

Etykieta przeniesiona na to samo zgłoszenie w powtórzeniu wymaga sprawdzenia w raporcie, że to ten sam problem bez
nowych okoliczności, i zatwierdzenia przez właściciela.

## Miary

Dwie osobne tabele; żadna nie jest wyprowadzana z drugiej.

1. **Wykrywanie wad:** dla każdej wady wzorcowej wykryta, częściowo albo przeoczona w każdym przebiegu; do tego inne
   trafne usterki, fałszywe alarmy i liczba zgłoszeń nierozstrzygniętych.
2. **Jakość werdyktu** `PASS`/`CHANGES_REQUIRED`, liczona z zatwierdzonych etykiet: uzasadniony (jest zgłoszenie `wada`,
   `czesciowe` albo `trafne`), zgodny (`PASS` bez `IMPORTANT` w przypadku czystym), nieuzasadniony (`CHANGES_REQUIRED`
   tylko z fałszywego alarmu), błędny (`PASS` przy przeoczonej wadzie), nierozstrzygnięty (oparty tylko na `nieznane`).

Wyniki podaje się w liczbach przebiegów, bez odsetków i bez progów. Przebieg przerwany limitem albo błędem
infrastruktury nie wchodzi do żadnej tabeli.

## Porównanie wariantów agenta

Zmianę agenta (promptu, modelu) ocenia się eksperymentem na tych samych przypadkach, zleceniach i wersji CLI:

- wariant to commit na osobnej gałęzi, podawany przez `--agent-rev`; do `main` trafia dopiero po przyjęciu zmiany;
- kryteria przyjęcia, kolejność przebiegów i limit kosztu są zapisane przed pomiarem i nie zmieniają się po wynikach;
- rundy są kompletne (wszystkie przypadki w obu wariantach albo żaden), a wynik niepełnej rundy jest raportowany jako
  niepełny;
- nowe zgłoszenia `IMPORTANT` właściciel ocenia bez informacji o wariancie tam, gdzie działały oba warianty;
- wynik na wadach, dla których zmianę napisano, nie jest dowodem ogólnej poprawy: o braku szkody mówią przypadki
  kontrolne, których zmiana nie dotyczy.

## Ograniczenia

- Sześć przypadków po dwa albo trzy przebiegi pokazuje tylko duże różnice; nie jest miarą skuteczności produkcyjnego
  reviewera.
- Wada #256 jest przykładem w prompcie agenta, więc jej wykrycie mierzy też znajomość wzorca.
- Odtworzenie różni się od produkcji: nie ma działającego stosu Dockera, zlecenia pochodzą z ówczesnej wersji
  `/delivery`, a sesja nadrzędna to krótki stały prompt (lista w `tools/evals-pilot/README.md`).
- Nie ma joba CI, historii wyników ani werdyktów regresji.

## Wyniki

Raporty pomiarów są w `docs/reports/` (`evals-2-…`). Surowe wyniki (raporty agenta, transkrypty, etykiety) zostają
poza repozytorium, w katalogu podanym w raporcie.
