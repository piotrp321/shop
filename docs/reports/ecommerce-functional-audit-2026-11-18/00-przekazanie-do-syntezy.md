# Przekazanie do syntezy: audyt funkcjonalny (2026-11-18)

Notatka dla sesji, która po zakończeniu wszystkich audytów (funkcjonalnego, bezpieczeństwa, wydajności oraz
zależności, zbędnego kodu i nadmiernej złożoności) przygotuje jedną roadmapę. Raport główny:
`docs/reports/ecommerce-functional-audit-2026-11-18.md`. Rewizja audytu: `1aeb5076`.

Ta notatka nie jest specyfikacją, planem ani listą epików. Niczego nie rozstrzyga.

## 1. Stanowisko właściciela (18.11.2026, po przeczytaniu raportu)

- Raport jest przyjęty jako punkt wyjścia do dalszego rozwoju.
- Kolejny główny cel projektu: stan, w którym **rzeczywisty polski sprzedawca może uruchomić sklep i prowadzić
  sprzedaż produkcyjną**.
- Na tym etapie nie ma założonej grupy docelowej ani modelu biznesowego.
- Właściciel **nie zgadza się** na ograniczanie potencjału aplikacji do średnich sprzedawców bez uwzględnienia
  rzeczywistych kosztów wdrożenia, utrzymania i możliwości szybkiego dostosowywania aplikacji przy użyciu AI.
- Pięć epików z raportu i ich kolejność **nie są decyzją**.
- Istniejąca architektura **nie musi** zostać zachowana w obecnym kształcie.
- Preferowane są dojrzałe zewnętrzne rozwiązania i integracje zamiast własnych odpowiedników, jeżeli jest to
  uzasadnione biznesowo i technicznie.
- Istniejących możliwości skalowania nie porzucamy automatycznie, ale nie inwestujemy dalej w skalę bez konkretnego
  uzasadnienia.
- Do czasu syntezy: bez implementacji, bez nowych epików, zgłoszeń i specyfikacji.

Roadmapa po wszystkich audytach ma uwzględnić: możliwość uruchomienia rzeczywistego sklepu; bezpieczeństwo
i zgodność prawną; niezawodność i wydajność; uproszczenie architektury i ograniczenie kosztów utrzymania;
rzeczywistą wartość biznesową funkcji; zależności między zmianami i optymalną kolejność.

## 2. Co w raporcie jest ustaleniem, a co propozycją

| Część raportu | Charakter | Jak używać w syntezie |
|---|---|---|
| Rozdz. 3, 5, 6, 8 (dojrzałość, przegląd ścieżek, mocne strony, funkcje niekompletne) | Ustalenia: kod na `1aeb5076` z dowodami plik:linia, przejście w przeglądarce, weryfikacja wyszukiwaniem w kodzie | Traktować jako stan faktyczny na tę rewizję. Przed użyciem sprawdzić, czy `main` się nie zmienił. |
| Rozdz. 7 i 10 (braki, lista „dzień pierwszej sprzedaży”) | Ustalenia o brakach plus klasyfikacja audytora. Podstawy prawne z tekstów ustaw (ISAP, API Sejmu), część ze źródeł wtórnych, oznaczona w tekście | Lista braków jest wejściem do roadmapy. Klasyfikację „niezbędne z mocy prawa” potwierdza prawnik i doradca podatkowy. |
| Rozdz. 4 (macierz konkurencji) | Ustalenia o średniej pewności: komórki z gwiazdką i „?” nie mają odczytu oficjalnego źródła | Nie opierać decyzji na pojedynczej komórce z gwiazdką. |
| Rozdz. 9 („nadmiernie rozbudowane”) | Opinia audytora funkcjonalnego, bez pomiaru | Wejście do audytu złożoności, nie wniosek. |
| Rozdz. 11 (segment docelowy) | **Propozycja odrzucona przez właściciela w obecnej postaci** | Patrz punkt 3. |
| Rozdz. 12–13 (roadmapa, pięć epików, rozmiary) | Propozycja, nie decyzja. Rozmiary to ocena względna, nie estymacja | Materiał do porównania wariantów, w tym wariantów opartych na zewnętrznych rozwiązaniach. |
| Rozdz. 14 („nie budować”) | Propozycja | Zweryfikować po ustaleniu modelu kosztów (punkt 3) i wyników pozostałych audytów. |
| Rozdz. 10, „rekomendowany minimalny zestaw integracji” (PayU, InPost ShipX z Geowidget, wFirma albo Fakturownia) | Propozycja oparta na częściowo zweryfikowanych źródłach | Decyzja właściciela po rozpoznaniu technicznym; luki w punkcie 5. |

## 3. Czego raportowi brakuje, a synteza potrzebuje

**Model kosztów.** Wniosek rozdziału 11 („mały sklep wybierze SaaS, realny segment to średni sprzedawca”) opiera się
na trzech niepoliczonych założeniach. Raport nie zawiera liczb, które by je potwierdzały albo obalały:

1. Koszt utrzymania stosu. Lokalnie to 13 kontenerów (api, worker, store, admin, PostgreSQL, Redis, Elasticsearch,
   RabbitMQ, Keycloak, Garage, imgproxy, Varnish, Mailpit), a produkcyjnie jeden VPS 8 vCore / 24 GB z k3s
   (`docs/vision.md`, „Środowisko wdrożeniowe”). Raport nie podaje miesięcznego kosztu hostingu ani tego, ile z tych
   usług jest potrzebnych pierwszemu sklepowi. To pytanie do audytu złożoności i wydajności.
2. Koszt wdrożenia i dostosowania. Raport przyjął milcząco koszty typowe dla agencji i zespołu programistów. Nie
   uwzględnił, że w tym projekcie kod piszą agenci AI, a czas i koszt epików są mierzone
   (`docs/features/metrics/spec.md`, raporty `ao-performance-agent-security-sentry-2026-11-15.md`
   i `perf-audit-admin-features-metrics-2026-11-17.md` w `docs/reports/`). Rzetelne porównanie z abonamentem SaaS
   wymaga kosztu epiku i kosztu zmiany na życzenie sprzedawcy liczonych z tych danych.
3. Koszt alternatywy. Ceny konkurencji w rozdziale 11 pochodzą częściowo z artykułów konkurenta (Shoper o IdoSell)
   i szacunków agencji (Magento, Adobe). Opłaty zmienne (prowizje od płatności, opłaty od zamówień i od GMV) nie
   zostały przeliczone na konkretne wolumeny sprzedaży.

Bez tych trzech liczb pytanie „dla kogo ta aplikacja ma sens” pozostaje otwarte. Raport go nie rozstrzyga.

**Warianty „zbuduj albo zintegruj”.** Raport dla każdego braku opisuje, czego brakuje, i w części przypadków
wskazuje integrację. Nie porównuje systematycznie wariantów. Kandydaci zewnętrzni, którzy pojawili się
w notatkach (stopień weryfikacji w nawiasie):

| Brak | Kandydaci zewnętrzni z notatek | Źródło |
|---|---|---|
| Płatności i zwroty | PayU (API zwrotów potwierdzone), Przelewy24 (cennik potwierdzony, API nie), Tpay, imoje, Stripe (API potwierdzone, wyższa opłata stała), Autopay (niezweryfikowany) | `16` |
| Dostawa | InPost ShipX i Geowidget v5 (potwierdzone), brokerzy Furgonetka i Apaczka (API istnieje, dokumentacja nieczytana), BaseLinker (cennik potwierdzony, funkcje wysyłkowe nie) | `16`, `13` |
| Faktury i KSeF | wFirma, Fakturownia (API i KSeF potwierdzone), inFakt, iFirma (tylko wzmianka) | `16` |
| Marketplace, kurierzy, porównywarki | BaseLinker jako brama zamiast osobnych integracji (nie badano API) | `12`, `13` |
| Treści i strony prawne | Nie badano zewnętrznych CMS-ów; Medusa i Saleor polegają na zewnętrznym headless CMS | `10`, `11` |
| Poczta transakcyjna | Brevo jest już w konfiguracji wdrożenia (`docs/vision.md`) | — |
| Wyszukiwanie, tożsamość, cache, obrazy | Dziś własne usługi w stosie (Elasticsearch, Keycloak, Varnish, Garage, imgproxy). Raport nie oceniał zamienników usługowych | — |

**Alternatywa „inna podstawa”.** Raport nie rozważał wariantu, w którym część funkcji (albo cały rdzeń) przejmuje
gotowa platforma, a projekt wnosi to, w czym jest mocny. Notatki `08`–`11` opisują, co Sylius, Medusa, Saleor
i Shopware dają w darmowym rdzeniu, a co wymaga płatnej edycji lub wtyczek. Skoro architektura nie jest założeniem,
synteza powinna ten wariant przynajmniej nazwać i ocenić.

## 4. Punkty styku z pozostałymi audytami

Ustalenia tego audytu, które należą też do innych audytów. Nie dublować, tylko uzgodnić.

**Bezpieczeństwo i zgodność prawna**
- Podgląd kodu rabatowego bez limitu prób i blokowanie stanu przez nieopłacone zamówienia gościa (specyfikacja
  ABUSE-LIMITS, zgłoszenie #181) – `03`, `04`.
- Brak anonimizacji i obsługi żądań RODO (#119) – `05`.
- Konta pracowników zakładane w konsoli Keycloaka przez port-forward; brak dziennika zmian – `05`.
- Wywołanie dostawcy płatności w transakcji bazy (w specyfikacji ABUSE-LIMITS oznaczone SA-03) – `04`.
- Prawdziwy operator płatności wprowadzi nowe sekrety, webhooki z internetu i zwroty pieniędzy: nowa powierzchnia
  ataku, której audyt bezpieczeństwa na symulatorach nie mógł ocenić.
- Obowiązki z ustawy o prawach konsumenta, GPSR, Omnibus przy kodach rabatowych, cookies, dostępność – `15`,
  rozdział 7 raportu.

**Niezawodność i wydajność**
- Wygasanie nieopłaconych zamówień zależy od jednej opóźnionej wiadomości; płatność `pending` może nie mieć końca
  (specyfikacja ASYNC-RELIABILITY) – `03`, `04`.
- Raport nie twierdzi, że system spełnia wymagania wydajnościowe. Nie wykonano pomiarów.
- Pulpit sprzedaży z tabeli godzinowej (dane „do pełnej godziny”) i liczniki list z górnym limitem – `05`,
  rozdział 9.

**Zależności, zbędny kod i złożoność**
- Lista z rozdziału 9: architektura pod 10 mln produktów (porcjowana mapa strony, Varnish z tagami, ISR
  z rewalidacją), model odczytu klientów pod milion kont, symulator płatności (ok. 1300 linii,
  `apps/api/src/PaymentSimulator/`), rozbudowane zabezpieczenia wokół stawek VAT, których nie da się edytować
  z panelu.
- Pytanie otwarte: które z 13 usług stosu są potrzebne, żeby pierwszy sklep sprzedawał, a które służą skali.
- Samoobsługa właściciela: stawki VAT, termin płatności, próg niskiego stanu, treści e-maili i strona główna są
  w kodzie. Każda zmiana wymaga wdrożenia – `03`, `05`.

## 5. Luki i zastrzeżenia do wyników

- **Konkurencja.** Badania miały mały budżet na platformę. Wiele komórek macierzy ma gwiazdkę albo „?”,
  najwięcej przy Shoperze, IdoSell, Sylius i Saleorze. Dokumentacji Sylius nie udało się odczytać (wymagała
  logowania). Vendure i PrestaShop opisano skrótowo, commercetools nie zbadano.
- **Prawo.** Teksty polskich ustaw odczytano z ISAP i API Sejmu. Ze źródeł wtórnych pochodzą: treść art. 19 GPSR,
  art. 11a dyrektywy 2011/83/UE, status projektu UC169 (funkcja odstąpienia online; na 18.11.2026 bez polskiej
  ustawy w mocy), termin KSeF dla najmniejszych podatników, warunki zwolnienia z kasy fiskalnej. To nie jest porada
  prawna ani podatkowa.
- **Płatności i dostawa.** Nie odczytano dokumentacji API Przelewy24, imoje, Furgonetki i Apaczki; nie
  zweryfikowano BLIK-a wpisywanego w sklepie u polskich operatorów ani cennika Autopay.
- **Przegląd w przeglądarce.** Lokalny stos w trybie `dev` z danymi `make seed`. Nie sprawdzano: worf.pl, panelu
  na 375 px, pełnego przejścia klawiaturą i czytnikiem ekranu, ról innych niż `admin`, czasu propagacji zmiany ceny
  do sklepu.
- **Rynek.** Udziały metod płatności i dostawy pochodzą ze streszczeń raportu Gemius 2025 publikowanych przez
  operatora płatności. Nie przeprowadzono rozmów ze sprzedawcami.

## 6. Stan zgłoszeń na 18.11.2026 (odczyt tytułów przez bramkę pochodzenia)

Istnieją zgłoszenia: #120 metody i koszty dostawy w kasie, #119 anonimizacja RODO, #117 zapisane adresy,
#118 zamówienia gościa przypisane do konta o tym samym e-mailu, #586 ADMIN-TOOLS (notatki, zwroty płatności, akcje
masowe, eksport, dziennik zmian), #684 wiele magazynów, #221 zwroty płatności widoczne w panelu, #181 limity
nadużyć, #296 utwardzenie środowiska przed publicznym startem, #179 tryb publicznego demo. Specyfikacje i plany
z pustym statusem: SMALL-FIXES, ABUSE-LIMITS, ASYNC-RELIABILITY.

Nie ma zgłoszeń dla: prawdziwego operatora płatności, faktur i KSeF, stron prawnych i treści, zgód w kasie,
opisu, parametrów, SKU/EAN i danych GPSR produktu, importu i eksportu katalogu, numerów przesyłek i integracji
kurierskich, ścieżki odstąpienia i zwrotu po stronie klienta, zwrotów częściowych, ustawień sklepu i zarządzania
pracownikami w panelu, feedów produktowych, analityki i zgody na cookies. W ramach audytu nie utworzono żadnego
zgłoszenia.

## 7. Jak odtworzyć środowisko przeglądu

- Worktree `ecommerce-audit-competition-43f1b4`, gałąź `claude/ecommerce-audit-competition-43f1b4`.
- Stos lokalny: projekt Compose `shop-funcaudit`, porty 34700 (sklep), 34701 (panel), 34702 (API), 34703
  (Keycloak), 34705 (Mailpit) w lokalnym `.env` worktree (poza repozytorium). Stos jest zatrzymany, wolumeny
  zostały. Zawierają dwa zamówienia testowe (R-100001 gościa: wysłane i zwrócone; R-100002 klienta demo: opłacone).
  Uruchomienie: `make up`, czysty stan: `make seed`.
- Konta demo są w `README.md`.
