# Eksperyment: ocena odroczeń na historycznym PR #811 (2026-11-24)

Jedno odtworzenie przeglądu odroczonych uwag na rewizji sprzed poprawki, w dwóch wariantach zlecenia: obecnym
i z pilotażu oceny odroczeń (`docs/features/ash-process-fixes/spec.md`, O8–O10; zmiana 1 z
`docs/reports/ash-workflow-audit-2026-11-24.md`). Raport niczego nie zmienia w procesie. Pilotaż zostaje do oceny na
kolejnych epikach.

## Wynik

Oba warianty przepuściły ten sam błąd: oba review zakończyły się `Werdykt: PASS` i żadne nie skierowało do naprawy
uwagi QA M2, którą zewnętrzne review potwierdziło jako błąd. Żadne nie zgłosiło fałszywego alarmu. W tym jednym
przebiegu zlecenie z pilotażu nie dało lepszej decyzji niż zlecenie obecne.

## Przedmiot

- **PR:** #811 (ASYNC-RELIABILITY), spoza pięciu epików audytu. Rewizja `0009ee68`, baza `f33ac36a`: ta sama, którą
  oceniało zewnętrzne review przed poprawką.
- **Odroczone uwagi:** 16 pozycji sekcji „Odroczone uwagi” opisu PR w wersji z 2026-11-20 12:31Z.
- **Potwierdzony błąd:** QA M2. `MessagingStatisticsAdapter::backlog` liczył jako gotowe tylko wiadomości
  z `delivered_at IS NULL`, zgodnie z definicją W7 specyfikacji. Wiadomość pobrana przez zabitego workera nie była
  liczona nigdy, także po `redeliver_timeout`, więc panel i `app:messaging:check` mogły pokazywać stan zdrowy przy
  zaległych wiadomościach. QA ocenił to jako opóźnienie sygnału o około 5 minut („Nie odtwarzałem tego”), sesja
  odroczyła. Poprawka: `3032f1ae` (cztery testy czerwone na tej rewizji), `ca4a0dad`.
- **Pozostałe 15 uwag:** trafiły do zgłoszeń #823–#827; żadnej nie uznano później za błąd do naprawy przed merge'em.

## Przebieg

Dwa przebiegi agenta `code-reviewer` (definicja z `c617e788`: Opus 5.5, effort high), równolegle, każdy w świeżym
kontekście i na własnej kopii repozytorium z historią tylko do `0009ee68`, z `apps/api/vendor` w wersjach
z `composer.lock` tej rewizji. Wspólna część zlecenia: katalog przeglądu, `base_sha`, `head_sha`, ścieżka
specyfikacji, zakaz czytania czegokolwiek poza katalogiem przeglądu, zakaz `gh` i sieci, brak stosu, zakres
ograniczony do odroczonych uwag.

- **Wariant obecny:** opis PR #811 z 12:31Z (uwagi w brzmieniu sesji, z jej oceną) i zlecenie z ADR, krok 7:
  „sekcja »Odroczone uwagi« opisu PR: dla każdej pozycji oceń, czy odroczenie jest uzasadnione, i wskaż te, które są
  błędem do naprawy przed merge'em”.
- **Wariant z pilotażu:** te same 16 uwag jako cytaty z raportów reviewerów (rola, zadanie), bez oceny sesji,
  i zlecenie `ASYNC-RELIABILITY-DEF1`: dla każdej uwagi naruszona gwarancja albo jej brak, wejście albo przeplot,
  skutek, odwracalność i werdykt „naprawić przed merge'em” albo „można odroczyć”, z dowodem w kodzie; rzadkość
  przeplotu i etykieta zakresu nie są podstawą werdyktu.

Porównanie z potwierdzonym błędem powstało po zakończeniu obu przebiegów.

## Wyniki

| | Wariant obecny | Wariant z pilotażu |
|---|---|---|
| Werdykt | PASS | PASS |
| QA M2 (potwierdzony błąd) | „odroczenie uzasadnione, SUGGESTION” | „można odroczyć” |
| Werdykty „naprawić” bez późniejszego potwierdzenia | 0 | 0 |
| Czas | 6,7 min | 5,7 min |
| Tokeny razem | 3,90 mln | 2,58 mln |
| w tym wyjście / zapis cache / odczyt cache | 37,8 tys. / 145,9 tys. / 3,71 mln | 31,9 tys. / 116,1 tys. / 2,43 mln |
| Wartość (cennik z `tools/metrics/config.json`, zapis cache 5 min) | ok. 2,2 USD | ok. 1,7 USD |
| Wywołania narzędzi | 36 | 28 |

Ocena QA M2:

- **Wariant obecny** zauważył, że wiadomość z ustawionym `delivered_at` „nie liczy się nigdy, nie tylko do
  `redeliver_timeout`”, i zalecił poprawienie opisu skutku w zgłoszeniu. Wagę zostawił niską: założył, że worker
  pobierze wiadomość ponownie po 300 s, i rozważył tylko wiadomość, która za każdym razem zabija proces. Stojącego
  workera, czyli awarii, którą nadzór ma wykryć, nie rozważył.
- **Wariant z pilotażu** uznał, że gwarancja nie jest naruszona, bo W7 definiuje gotowe wiadomości dosłownie jako
  `delivered_at IS NULL`, i powtórzył ocenę QA o opóźnieniu sygnału. Warunku ponownego dostarczenia w `vendor` nie
  sprawdził.

Pozostałe uwagi oba warianty odroczyły, zgodnie z ich późniejszym losem. Oba odnotowały, że uwagi S1 i S2 zadania 9
formalnie łamią W12 przy jawnym i odwracalnym skutku. Wariant obecny wskazał trzy niedokładne uzasadnienia sesji.
Przy QA M1 oceny się różnią (usunięta klasa jako zwykła eksploatacja albo wyłącznie wiersz wstawiony ręcznie); tej
różnicy eksperyment nie rozstrzyga.

## Ograniczenia

- **Późniejsza wskazówka w kontekście.** Obaj reviewerzy mieli w kontekście indeks pamięci projektu z wpisem
  o epiku ASYNC-RELIABILITY, który mówi, że zewnętrzne review znalazło błąd P2 naprawiony osobnym zadaniem (bez
  wskazania uwagi). Działali też na dzisiejszej definicji agenta i dzisiejszym `CLAUDE.md`, które zawierają reguły
  dopisane po tym epiku. Warunek „wyłącznie materiały z chwili pierwotnego review” jest więc spełniony dla kodu,
  specyfikacji, opisu PR i raportów, a nie dla kontekstu agenta. Mimo wskazówki żaden nie podniósł wagi QA M2.
- **Brak działającego stosu.** Żaden reviewer nie mógł odtworzyć przypadku. Zewnętrzne review potwierdziło błąd
  zapytaniem dla wiadomości pobranej godzinę wcześniej.
- **Jeden przebieg na wariant.** Różnice między wariantami (trafność opisu skutku, czas, tokeny) mogą być zmiennością
  modelu.
- **Świeży kontekst w obu wariantach.** Eksperyment porównuje treść zlecenia. Nie sprawdza głównego założenia
  pilotażu, czyli odebrania decyzji o odroczeniu sesji prowadzącej.
- **Zakres wariantu z pilotażu.** Dostał wszystkie 16 uwag, a pilotaż przekazuje tylko uwagi o konkretnym błędzie
  (wyboru nie robiłem, żeby nie korzystać ze znajomości wyniku). Jego koszt i czas to górna granica.
- **Punkt odniesienia.** Zewnętrzne review znalazło ten błąd przeglądem całej zmiany, nie oceną listy odroczeń.

## Wnioski

Obserwacja: na tym PR sama zmiana zlecenia (uwagi w brzmieniu reviewera, bez oceny sesji, werdykt dla każdej uwagi)
nie wystarczyła do wykrycia błędnego odroczenia, a ocena z czytania kodu i `vendor` nie zastąpiła próby.

Hipotezy, niesprawdzone:

- Błąd, który siedzi w definicji ze specyfikacji, nie wychodzi w ocenie zgodności z tą specyfikacją; wymóg podania
  naruszonej gwarancji mógł utrwalić ocenę „zgodne z W7”.
- Dla uwag z niesprawdzonym założeniem („nie odtwarzałem”) rozstrzyga próba na stosie, która w pilotażu zostaje przy
  sesji (O3, O8), a nie werdykt reviewera.

Wynik to jeden eksperyment i nie jest podstawą decyzji o pilotażu: ocena odroczeń zostaje pilotażem do oceny na
kolejnych epikach (O10).
