# STORE-UX-FIXES, etap 1: ścieżka zakupowa bez zgubionych akcji, fokusu i ogłoszeń

Pierwszy etap epiku #777: poprawki użyteczności i dostępności koszyka, kasy i potwierdzenia płatności sklepu
(`apps/store`). Działanie tych ekranów opisują ich specyfikacje (`docs/features/cart/spec.md`,
`docs/features/checkout/spec.md`, `docs/features/payment/spec.md`); ta opisuje tylko zmiany i zachowanie, które
dochodzi. Twierdzenia o obecnym kodzie dotyczą `main` z 2026-11-22 (`38639e68`). Każdą pozycję odtworzono tego dnia
w przeglądarce (Chromium z Playwright, 1280 px z myszą i klawiaturą, 375 px z dotykiem) na lokalnym stosie w trybie
produkcyjnym z danymi demo, także z odpowiedziami opóźnionymi o 2–3 s i z błędem 500; przyczynę wskazano w kodzie.
Wyjątki są nazwane w sekcjach.

Issues: #210, #211, #293, #335, #390, #392, #445 (w całości); część #345 (S5, błędy wyboru dostawy i płatności); trzy
uwagi odroczone w SMALL-FIXES (#752), z których etap obejmuje „kliknięcie „Usuń” zaraz po wpisaniu ilości przepada”.
Zakres: `apps/store/src/cart`, `apps/store/src/checkout`, `apps/store/app/checkout/confirmation/page.test.tsx`, `apps/store/CLAUDE.md`, `apps/api/fixtures/stock.yaml`, `e2e/tests/store-ux-cart.spec.ts`, `e2e/tests/store-ux-checkout.spec.ts`, `e2e/tests/store-ux-payment.spec.ts`, `e2e/tests/store-ux-product.spec.ts`, `.github/workflows/ci.yml`, `docs/features/cart/spec.md`, `docs/features/checkout/spec.md`, `docs/features/payment/spec.md`, `docs/features/discount-codes/spec.md`, `docs/features/store-ux-fixes`

## Cel i zakres

- Akcja kliknięta w koszyku w trakcie zapisu nie przepada, a starsza odpowiedź nie nadpisuje nowszego wpisu (S1).
- „Przejdź do kasy” otwiera kasę dopiero po zapisaniu zmian zleconych w koszyku; po nieudanym zapisie klient zostaje
  w koszyku z komunikatem (S2).
- Po akcjach asynchronicznych koszyka, kasy i potwierdzenia płatności fokus klawiatury jest w przewidywalnym miejscu,
  a wynik operacji (w koszyku i kasie także jej początek) jest ogłaszany (S3, S4, S6; #392, #445, #210, #211).
- Kasa nie pokazuje „Zaloguj się” przy wyłączonych kontach klientów (S4; #293).
- Formularz kasy jest zablokowany na czas składania zamówienia; brak wyboru dostawy i płatności widać jednym
  kliknięciem (S5; część #345).
- #390 ma test regresyjny, a #335 kończy się poprawką specyfikacji (S7).

Etap nie zmienia API, `apps/api/openapi*.json` ani wygenerowanego `apps/store/src/api/schema.d.ts`. Wygląd
zmienia się tylko o stany nieaktywnych kontrolek, które sklep już ma (pole ilości i „Usuń” w koszyku w trakcie
zapisu), wiersz tekstu regionu statusu (F5) i komunikat przy „Przejdź do kasy” (S2).

## Decyzje

Właściciel zaakceptował 2026-11-22:

| # | Decyzja | Przyjęty wariant | Odrzucone |
|---|---|---|---|
| D1 | Zakres etapu 1 | Ścieżka zakupowa: koszyk, kasa, potwierdzenie płatności | Dołożenie poprawek nagłówka i katalogu (inne pliki i inny temat przeglądu) |
| D2 | Akcje koszyka w trakcie zapisu | Strona koszyka wysyła jedną operację naraz i pamięta przy pozycji najwyżej jedną akcję czekającą (S1) | Ogólna kolejka wszystkich akcji; wysyłanie od razu z poleganiem na kolejce `src/api/client.ts` (po wygaśnięciu sesji nie da się cofnąć żądań już oddanych do kolejki) |
| D3 | Fokus po odpowiedzi | Warunkowo: tylko gdy fokus został na elemencie, z którego wyszła akcja, albo zginął razem z nim (F2) | Bezwarunkowe przenoszenie fokusu po każdej odpowiedzi |
| D4 | #293 | Link logowania w kasie czeka na odczyt przełączników, jak link w nagłówku | Naprawa `/konto/zamowienia` przy wyłączonych kontach w tym etapie (#848) |
| D5 | #335 | Zostaje „Do zapłaty (brutto)”; poprawka `docs/features/discount-codes/spec.md` | Zmiana etykiety w kodzie |
| D6 | Formularz kasy w trakcie składania zamówienia | Blokada pól, wyboru dostawy i płatności oraz kodu rabatowego (S5) | Zachowywanie zmian wpisanych w trakcie (po sukcesie następuje przejście do płatności) |
| D7 | #345 | W etapie tylko część niezależna od API: oba błędy wyboru naraz (S5) | Walidacja pustych pól w sklepie (o tym, co jest puste, rozstrzyga API; etap 2 API-INPUT-CONTRACT to zmienia) |
| D8 | #262 (historia zamówień) | Etap 2 | Etap 1 (zakres urósł o S2 i pełną blokadę kasy; historia jest dostępna tylko dla zalogowanych) |
| D9 | Zamówienie a zapisy koszyka z innych kart | Poza etapem; granica ochrony opisana w „Granica ochrony zamówienia” (#853) | Ograniczona serializacja `placeOrder` z zapisami koszyka (zamyka jeden wąski przebieg, a nie obejmuje akcji czekających lokalnie ani zmiany zawartości przy tej samej kwocie) |

## Poza zakresem

- **Etap 2 (nawigacja i katalog):** #201, #202, #368, #369, #370, #371, #372, #422, #631, pasek demo zlewający się ze
  stopką, „Strona 99 z 8” za końcem listingu, #262. Wszystkie odtworzono 2026-11-22; #369 tylko przy 320 px po
  zalogowaniu (21 px nakładania, od 341 px brak), #370 tylko przy 1024–1279 px w jednym przebiegu klawiaturą, #631
  i pasek demo przez symulację (dane demo nie mają tak długiej nazwy, pasek jest w stosie wyłączony).
- **Pozostała część #345:** błędy pól tym samym kliknięciem co błędy wyboru. Bez wybranej dostawy sklep nie ma kwoty
  `expectedTotalGross` (nie liczy jej sam), a o tym, które pole jest puste, rozstrzyga API. Po etapie 2
  API-INPUT-CONTRACT.
- **Zgłoszenia założone przy przygotowaniu etapu:** #848 (`/konto/zamowienia` przy wyłączonych kontach), #849
  (nawigacja czekająca na odpowiedź wyszukiwania), #850 (kafelek z wieloma wariantami), #851 (długa nazwa działu przy
  1280 px), #852 (komunikat 409 bez informacji, co się zmieniło), #853 (granica ochrony zamówienia).
- Limit czasu żądań koszyka i kasy, link „Zaloguj się” i inne linki wyjścia z kasy w trakcie składania zamówienia.

## Reguły wspólne

Obowiązują w S1–S6; sekcje podają tylko cele i teksty.

- **F1. Element, z którego wyszła akcja, zachowuje fokus.** Na czas żądania ma `aria-disabled="true"` zamiast
  `disabled` i wygląd nieaktywny. Ponowna aktywacja (kliknięcie, dotyk, Enter, Spacja) nie wysyła drugiego żądania.
- **F2. Fokus przenosi się tylko warunkowo.** Punktem odniesienia jest element, z którego klient zlecił akcję (przycisk
  albo pole, z którego naciśnięto Enter), także gdy akcja czekała na wysłanie. Po odpowiedzi strona przenosi fokus
  tylko wtedy, gdy
  fokus nadal jest na tym elemencie albo zginął razem z nim (`document.activeElement` to `<body>`). Gdy klient
  przeszedł w tym czasie do innej kontrolki, fokus zostaje tam.
- **F3. Cel po odpowiedzi:** element zostaje i wynik nie ma własnego celu: fokus zostaje; element znika: cel
  zastępczy podany w sekcji; odrzucenie zamówienia w kasie: komunikat albo pierwsze błędne pole (S5). Każdy z tych
  przypadków pod warunkiem F2.
- **F4. Automatyczne odpytywanie płatności nie przenosi fokusu.** Wyjątek: gdy zmiana statusu usuwa element
  z fokusem, fokus idzie na nagłówek ekranu.
- **F5. Trwały region statusu.** Operacje tego etapu ogłasza jeden region `role="status"` na ekranie: istnieje od
  pierwszego renderu i przez wszystkie stany ekranu (wczytywanie, treść, pusty koszyk, zmiany statusu płatności),
  a zmienia się tylko jego tekst. Jest widoczny i jest jedynym miejscem tego tekstu w DOM. Tekst zostaje do
  startu następnej operacji ekranu, która go czyści albo zastępuje, więc taki sam komunikat po kolejnej operacji jest
  ogłaszany ponownie. Istniejące, niezależne
  ogłoszenia zostają bez zmian (dostawa w koszyku, podsumowanie i kod rabatowy w kasie). Błędy zostają w `role="alert"`.

## S1: koszyk – akcje w trakcie zapisu

**Problem.** `CartPage` ma jedną flagę `busy` (`apps/store/src/cart/CartPage.tsx:42`). Zapis ilości startuje na
`blur` pola (`:373`), więc naciśnięcie „Usuń” najpierw zaczyna zapis, a kliknięcie trafia już w `if (!busy)`
(`:303-305`) i jest po cichu odrzucane. Zmierzone: usunięcie przepada w 10 z 10 prób myszą i dotykiem bez sztucznego
opóźnienia (lokalny PATCH trwa ok. 120 ms), bez komunikatu; przycisk szarzeje dopiero po naciśnięciu. Ta sama flaga
blokuje pola pozostałych pozycji (`readOnly`, `:367`), więc wpis w drugiej pozycji w trakcie zapisu pierwszej też
ginie. Zapisy koszyka są już szeregowane w `apps/store/src/api/client.ts` (`queueWrite`); akcje gubi wyłącznie strona.

**Zachowanie.** Strona wysyła jedną operację naraz. Akcję wykonaną w trakcie zapisu zapamiętuje przy pozycji:
najwyżej jedną, nowsza zastępuje starszą, usunięcie zastępuje ilość; akcja zastępująca zajmuje
miejsce zastąpionej. Po odpowiedzi wysyła akcje czekające
w kolejności ich zlecenia.

| Sytuacja | Zachowanie |
|---|---|
| Zapis ilości pozycji jest w drodze | Tylko pole tej pozycji jest do odczytu (`aria-disabled`, jak dziś). „Usuń” tej pozycji działa: usunięcie wykonuje się po zapisie, także nieudanym. |
| Ilość pozycji czeka na wysłanie | Pole pokazuje wpisaną wartość i jest edytowalne. Zmiana wpisu porzuca wartość czekającą; nowa idzie po zatwierdzeniu (`blur`, Enter, aktywacja „Przejdź do kasy”). |
| Usunięcie zlecone (w drodze albo czeka) | Obie kontrolki pozycji mają `aria-disabled`; kolejna aktywacja „Usuń” nie wysyła żądania. |
| Odpowiedź operacji innej pozycji | Nie nadpisuje pola, które ma niezatwierdzony wpis albo wartość czekającą. Pole wraca do ilości z odpowiedzi tylko po odpowiedzi na własną operację. |
| Błąd operacji (5xx, sieć, 409, 429) | Komunikat przy pozycji (dzisiejsze teksty), który zostaje na czas następnej operacji tej pozycji i znika dopiero po jej udanej odpowiedzi (nie na starcie operacji: start wypada na naciśnięciu kontrolki niżej, a zniknięcie komunikatu przesunęłoby ją przed zwolnieniem przycisku). Ta sama zasada dotyczy odpowiedzi, która przychodzi między naciśnięciem a zwolnieniem przycisku: komunikaty pozycji (pojawienie się, zmiana, zniknięcie) czekają na zwolnienie wskaźnika. Pole wraca do ostatniej potwierdzonej ilości; to nie jest pewny stan serwera, bo wynik zapisu może być nieznany. Akcje czekające innych pozycji wykonują się dalej. |
| Sesja wygasła (401 operacji albo ponownego odczytu koszyka po 404) | Akcje czekające są porzucane bez wysłania. Strona przechodzi w istniejący widok wygasłej sesji (`loaded.status === "unauthorized"`, `:116-122`): sam komunikat z linkiem logowania, bez listy pozycji i bez „Przejdź do kasy”. Dziś 401 operacji zostawia listę pozycji pod komunikatem (`:131-137`). |
| Klient opuszcza koszyk (nawigacja w sklepie) | Akcje już zlecone wykonują się do końca, bez nawigacji i komunikatów; po 401 są porzucane. |

Pozostałe reguły koszyka zostają: jedna operacja w drodze, komunikat „Pozycja została już usunięta” po 404, wpis
spoza 1–99 wraca do poprzedniej ilości bez żądania.

**Kryteria.** „Wstrzymane” znaczy tu i dalej: żądanie zatrzymane w przeglądarce na 3 s przed dotarciem do serwera.
Szerokości: 1280 px (mysz, klawiatura) i 375 px (dotyk), chyba że kryterium mówi inaczej.

1. Koszyk z pozycjami A (1 szt.) i B. Wpis 3 w A i od razu „Usuń” A: A znika, B zostaje; wychodzi jeden PATCH i jeden
   DELETE. Bez opóźnienia i z PATCH wstrzymanym. To samo z „Usuń” B: A ma 3 szt., B jest usunięte.
2. PATCH A wstrzymany, wpis 2 w B zatwierdzony: po zwolnieniu A ma 3, B ma 2; pole B ani razu nie pokazuje starej
   wartości.
3. PATCH A wstrzymany: pole A jest do odczytu; Enter i `blur` z tą samą wartością dają jeden PATCH; po odpowiedzi
   zmiana na 5 kończy się A = 5.
4. PATCH A wstrzymany; B = 2 zatwierdzone; potem wpis 4 w B bez opuszczania pola; zwolnienie A. Żądanie B = 2 nie
   wychodzi nigdy; pole B pokazuje 4 i jest edytowalne. Po `blur`: jeden PATCH B = 4. Wariant z „Przejdź do kasy”
   zamiast `blur`, przy A nadal wstrzymanym: kasa pokazuje A = 3 i B = 4.
5. PATCH A kończy się 500, a w trakcie kliknięto „Usuń” B: komunikat przy A, pole A pokazuje ostatnią potwierdzoną
   ilość, B jest usunięte. To samo z „Usuń” A: A jest usunięte.
6. PATCH A kończy się 401, a w trakcie zlecono akcję dla B: widok wygasłej sesji bez listy i bez „Przejdź do kasy”;
   żadne żądanie dla B nie wychodzi.
7. Dwie aktywacje „Usuń” tej samej pozycji dają jeden DELETE.

## S2: koszyk – przejście do kasy

**Problem.** „Przejdź do kasy” to zwykły link (`CartPage.tsx:224-231`), a kasa czyta koszyk przy wejściu. Gdy zapis
ilości dociera do serwera po tym odczycie, kasa pokazuje starą ilość. Zmierzone: przy zapisie opóźnionym przed
wysłaniem o 200 ms i więcej kasa pokazuje starą ilość w 6 z 6 prób; gdy zapis dotrze przed zamówieniem, API odrzuca
je (409, zmiana kwoty); gdy dotrze po zamówieniu (opóźnienie 4 s), powstaje zamówienie ze starą ilością, a wpis
przepada bez komunikatu.

**Zachowanie.** „Przejdź do kasy” zostaje linkiem z `href="/checkout"`.

- Aktywacja (kliknięcie, dotyk, Enter) najpierw zatwierdza niezatwierdzony wpis w polu ilości, które ma fokus.
- Gdy żadna operacja nie trwa i nie czeka, link działa jak dziś.
- Gdy operacje trwają albo czekają, powstaje **zamiar przejścia**: nawigacji jeszcze nie ma, link ma
  `aria-disabled="true"` i zachowuje fokus, region statusu mówi „Zapisujemy zmiany w koszyku…”, a pola ilości i „Usuń”
  mają `aria-disabled` do końca oczekiwania (nowych edycji w tym czasie nie ma). Ponowna aktywacja nic nie zmienia.
- Wszystkie operacje udane i koszyk da się zamówić: przejście do `/checkout`.
- Błąd którejkolwiek operacji anuluje zamiar na stałe: późniejszy sukces innej operacji go nie przywraca. Klient
  zostaje w koszyku; przy przycisku jest `role="alert"`: „Nie udało się zapisać zmian w koszyku. Sprawdź pozycje
  i spróbuj ponownie.”, a przy pozycji jej komunikat. Fokus według F2 zostaje na linku.
- 401 w trakcie oczekiwania: widok wygasłej sesji (S1), bez nawigacji.
- 404 operacji (pozycji już nie ma) też anuluje zamiar: klient zostaje w koszyku, widzi koszyk z odpowiedzi i tekst
  regionu „Pozycja została już usunięta”.
- Koszyk po zapisach jest pusty albo ma pozycje nie do kupienia: dzisiejszy widok tych stanów, bez nawigacji. Link
  znika, więc fokus idzie na „Twój koszyk jest pusty” albo, przy pozycjach nie do kupienia, na nagłówek „Koszyk”
  (F2, F3).
- Opuszczenie koszyka anuluje zamiar: spóźniona odpowiedź nie nawiguje do kasy.
- Następne wejście do kasy czyta koszyk z serwera, więc kasa pokazuje stan faktyczny, także po nieudanym zapisie.

Aktywacja z Ctrl, Cmd albo środkowym przyciskiem zostaje zachowaniem przeglądarki (nowa karta); patrz „Granica ochrony
zamówienia”.

**Kryteria.**

8. Wpis 3 w A (było 1) i „Przejdź do kasy” trzema sposobami (kliknięcie; dotyk przy 375 px; Tab i Enter), PATCH
   wstrzymany: adres zostaje `/cart`, link ma `aria-disabled` i fokus, region statusu ma tekst, wpis w innym polu
   i „Usuń” niczego nie zmieniają. Po zwolnieniu: `/checkout` pokazuje 3 szt. i kwotę z serwera; wyszedł jeden PATCH;
   druga aktywacja w trakcie jest bez skutku. Zamówienie złożone z tej kasy w jednym z wariantów (kliknięcie przy
   1280 px) ma 3 szt.
9. Jak 8 z PATCH zakończonym 500: adres zostaje `/cart`, widać komunikat przy przycisku i przy pozycji, pole pokazuje
   ostatnią potwierdzoną ilość, fokus jest na linku. Gdy czekała jeszcze druga, udana operacja: adres nadal `/cart`.
10. Jak 8 z odpowiedzią 401: wynik jak w kryterium 6, bez nawigacji.
11. Jak 8, z drugą akcją czekającą („Usuń” B), a w trakcie oczekiwania przejście linkiem nagłówka na `/`: po
    zwolnieniu adres zostaje `/`, serwer ma A = 3, a B jest usunięte.

## S3: koszyk – fokus i ogłoszenia (#392, #445)

**Problem.** Po usunięciu pozycji wiersz znika razem z przyciskiem, który miał fokus, i nic go nie przejmuje: fokus
spada na `<body>`, także przy usunięciu jednej z kilku pozycji; po opróżnieniu koszyka w DOM nie ma żadnego regionu
ogłoszeń (zmierzone). „Spróbuj ponownie” po nieudanym wczytaniu (`CartPage.tsx:59-62`, `:123-130`) usuwa komunikat
razem z przyciskiem: fokus spada na `<body>` po sukcesie i po ponownym błędzie, a „Wczytywanie koszyka…” jest
zwykłym akapitem.

**Zachowanie.**

- Region statusu koszyka (F5) pokazuje: „Wczytywanie koszyka…”, „Usunięto z koszyka: <nazwa pozycji>.” (nazwa z
  etykietą wariantu, jak
  w etykiecie pola ilości; pozycja bez nazwy, czyli niedostępny produkt: „Usunięto pozycję z koszyka.”), „Zapisujemy
  zmiany w koszyku…” (S2) i istniejące
  „Pozycja została już usunięta”.
- Po usunięciu pozycji (F2, F3): fokus na „Usuń” następnej pozycji; gdy usunięto ostatnią na liście, na „Usuń”
  poprzedniej; gdy koszyk jest pusty, na tekst „Twój koszyk jest pusty” (występuje w DOM raz). Po nieudanym
  usunięciu wiersz i przycisk zostają, fokus zostaje.
- „Spróbuj ponownie” zostaje na ekranie na czas ponowienia (F1), a komunikat błędu (`role="alert"`, przycisk jest
  poza nim) na ten czas znika. Sukces: fokus na nagłówek „Koszyk”. Ponowny błąd: fokus zostaje na przycisku,
  a komunikat pojawia się znowu, więc jest ogłaszany ponownie.

**Kryteria.**

12. Usunięcie klawiaturą jednej z kilku pozycji: fokus na „Usuń” następnej (poprzedniej dla ostatniej na liście).
    Usunięcie jedynej: fokus na „Twój koszyk jest pusty”. Region statusu ma tekst usunięcia. Z DELETE wstrzymanym
    fokus w trakcie jest na „Usuń”; gdy klient przeniósł go w trakcie na inną kontrolkę, zostaje tam.
    Wariant z usunięciem czekającym: „Usuń” B zlecone w trakcie zapisu A, fokus przeniesiony na inną kontrolkę: po
    usunięciu zostaje tam.
13. `GET /api/cart` kończy się 500, Enter na „Spróbuj ponownie”, odpowiedź wstrzymana: przycisk jest na ekranie
    z fokusem, drugi Enter nie wysyła żądania, region statusu ma tekst wczytywania, komunikatu błędu nie ma.
    Sukces: fokus na „Koszyk”. Ponowny błąd: komunikat jest znowu, fokus na przycisku. Fokus przeniesiony w trakcie:
    bez zmiany.

## S4: kasa – ponowienie wczytania i link logowania (#445, #293)

**Problem.** „Spróbuj ponownie” w kasie (`apps/store/src/checkout/CheckoutPage.tsx:154-163`, `:401-408`) działa jak
w koszyku: fokus spada na `<body>` (zmierzone dla błędu `/api/cart` i `/auth/session`). Link „Masz konto? Zaloguj się”
(`:623-630`) nie czyta przełączników wdrożenia: przy `customerAccounts: false` zostaje w kasie i prowadzi do
logowania, choć nagłówek go chowa (`apps/store/src/account/AccountMenu.tsx:23`, `:41`). Odtworzone z podmienioną
odpowiedzią `/api/site`, bo lokalny stos ma konta włączone; w `prod` konta są wyłączone.

**Zachowanie.**

- „Spróbuj ponownie” jak w S3; region statusu kasy (F5) pokazuje „Wczytywanie…”, cel po sukcesie to nagłówek „Kasa”.
- „Masz konto? Zaloguj się” pokazuje się dopiero po odczycie przełączników (`useSiteSwitches`) i tylko przy
  włączonych kontach. Błąd odczytu `/api/site` daje stan domyślny, czyli link, jak w nagłówku.

**Kryteria.**

14. Jak 13 dla `/checkout`, osobno dla błędu `/api/cart` i `/auth/session`; cel po sukcesie to „Kasa”.
15. `/api/site` z `customerAccounts: false`: w kasie gościa nie ma „Zaloguj się” ani przed odpowiedzią (wstrzymaną),
    ani po niej. Z `true`: link jest. Błąd odczytu: link jest.

## S5: kasa – składanie zamówienia (część #345)

**Problem.** W trakcie `POST /api/checkout` zablokowane są tylko przycisk zamówienia i przyciski kodu rabatowego,
przez `disabled` (`CheckoutPage.tsx:658`, `:617`, `:839`, `:858`), więc gubią fokus. Pola i wybory zostają
edytowalne: zmiana wpisana po wysłaniu ginie po cichu (zmierzone: żądanie niosło „WarszawaX”, formularz pokazywał
„WarszawaXX”, potem nastąpiło przejście do płatności). Po odrzuceniu fokus idzie na komunikat bezwarunkowo
(`:150-152`), także gdy klient pisze już w innym polu. Brak wyboru dostawy i płatności sprawdzają dwa osobne wczesne
zakończenia `submit` (`:264-276`): przy dwóch sposobach dostawy potrzeba trzech kliknięć, żeby zobaczyć błędy pól.

**Zachowanie.**

- **Blokada** od wysłania do odpowiedzi z błędem albo do nawigacji po sukcesie:
  - pola tekstowe formularza i pole kodu rabatowego: wartość się nie zmienia, `aria-disabled="true"`, wygląd
    nieaktywny, fokus zostaje;
  - wybór dostawy i płatności: nie zmienia się po kliknięciu, dotyku, strzałkach ani Spacji; `aria-disabled="true"`,
    bez `disabled`;
  - „Zastosuj” i „Usuń kod”: nie wysyłają żądania, zachowują fokus (`aria-disabled` zamiast `disabled`);
  - przycisk zamówienia: F1; region statusu kasy mówi „Składamy zamówienie…”;
  - Enter w dowolnym polu, kliknięcie i Spacja na przycisku dają jedno żądanie.
- **Sprawdzanie kodu rabatowego** używa tej samej blokady przycisków: w jego trakcie „Zastosuj”, „Usuń kod”
  i przycisk zamówienia mają `aria-disabled` zamiast `disabled` i nie wysyłają żądania. Fokus po odpowiedzi idzie na
  dzisiejszy cel (pole kodu albo „Usuń kod”) pod warunkiem F2; reszta zachowania kodu zostaje.
  Przycisk zamówienia przy braku sposobów dostawy zostaje `disabled` (to stan trwały, nie operacja w drodze).
- **Po błędzie** kontrolki są odblokowane. Wartości pól zostają. Dostawa, płatność, kod i podsumowanie zmieniają się
  tylko tak, jak dziś określa `docs/features/checkout/spec.md` dla danej odpowiedzi (odrzucony kod jest zdejmowany,
  niedostępna dostawa przestaje być wybrana, zmiana kwoty odświeża podsumowanie).
- **Fokus po odrzuceniu** (F2, F3): cel jak dziś (komunikat, a po 422 pierwsze błędne pole; dla odrzuconego kodu pole
  kodu), ale tylko gdy fokus został na elemencie, z którego wyszło wysłanie (przycisk albo pole z Enterem), albo
  zginął. Komunikat jest w `role="alert"`, więc jest ogłaszany także bez fokusu.
- **Po sukcesie** blokada trwa do nawigacji.
- **Błędy wyboru (#345):** gdy brakuje wyboru dostawy i płatności, jedno wysłanie pokazuje oba komunikaty („Wybierz
  sposób dostawy.”, „Wybierz sposób płatności.”), obie grupy mają `aria-invalid` i `aria-describedby`, a fokus idzie
  na pierwszą opcję pierwszej grupy z błędem. Żądanie nie wychodzi. Gdy brakuje jednego wyboru, zachowanie jak dziś.

**Kryteria.**

16. Poprawny formularz, `POST /api/checkout` wstrzymany: wpisywanie w każde pole i w pole kodu nie zmienia wartości;
    dostawa i płatność nie zmieniają się myszą, dotykiem, strzałkami ani Spacją; „Zastosuj” nie wysyła żądania;
    przycisk zamówienia ma fokus i `aria-disabled`; region statusu ma tekst; Enter w polu, kliknięcie i Spacja dają
    jeden POST.
17. Po 500 i 422: kontrolki odblokowane; wartości pól, dostawa, płatność i kod bez zmian. Po 409: wartości pól bez
    zmian, a dostawa, kod i podsumowanie zmienione tylko według `docs/features/checkout/spec.md` dla danego powodu.
18. Wysłanie Enterem z pola „Miejscowość”, POST wstrzymany. Bez przeniesienia fokusu: po 422 fokus na pierwszym błędnym
    polu, po 500 na komunikacie. Po przejściu Tab do innego pola w trakcie: fokus zostaje tam, komunikat jest
    w `role="alert"`. To samo dla wysłania przyciskiem i dla „Zastosuj” (Tab do innego pola w trakcie
    sprawdzania kodu: fokus zostaje tam).
19. Po odpowiedzi 200 kontrolki są nadal zablokowane w chwili zmiany adresu.
20. Pusty formularz przy dwóch sposobach dostawy: pierwsze wysłanie pokazuje oba błędy wyboru, obie grupy mają
    `aria-invalid` i `aria-describedby`, fokus jest na pierwszym sposobie dostawy, POST nie wychodzi. Przy 375 px
    element z fokusem jest w widoku.

## S6: potwierdzenie płatności (#210, #211)

**Problem.** „Sprawdź ponownie” ma `disabled` na czas odczytu
(`apps/store/src/checkout/PaymentConfirmation.tsx:236-240`):
fokus spada na `<body>` po ok. 25 ms i nie wraca, także gdy przycisk po odpowiedzi znika (zmierzone). Ekran nie ma
żadnego regionu ogłoszeń: zdania statusu to osobne akapity renderowane warunkowo, przemieszane z przyciskami
(`:282-314`), więc „Płatność przyjęta.” po odpytywaniu (`:91-104`) nie jest ogłaszana. Stany płatności wywołano
w symulatorze płatności stosu.

**Zachowanie.**

- „Sprawdź ponownie”: F1. Przycisk po odpowiedzi nadal jest na ekranie: fokus zostaje na nim. Przycisku po odpowiedzi
  nie ma (status rozstrzygnięty albo znów trwa odpytywanie): fokus na nagłówek ekranu. Błąd odczytu: przycisk i fokus
  zostają, komunikat „Nie udało się sprawdzić statusu
  płatności.” jest w `role="alert"`.
- Zdania statusu płatności są w jednym trwałym regionie (F5); przyciski, link „Wróć do płatności” i formularz
  płatności są poza nim. Kolejność zdań i kontrolek może się zmienić tylko w obrębie tej sekcji ekranu.
- Zmiana statusu z odpytywania zmienia tekst regionu i nie przenosi fokusu (F4).
- Widok bez statusu (pierwszy odczyt się nie udał): „Nie udało się sprawdzić statusu płatności.” jest poza regionem,
  w `role="alert"`, i działa jak komunikat w S3: znika na czas sprawdzania i pojawia się znowu po ponownym błędzie;
  przycisk i fokus zostają.

**Kryteria.**

21. Enter na „Sprawdź ponownie”, odpowiedź wstrzymana: przycisk ma fokus, drugi Enter nie wysyła żądania. Przycisk
    zostaje po odpowiedzi: fokus na nim. Przycisku nie ma: fokus na nagłówku. 500: fokus na przycisku i komunikat,
    także w widoku bez statusu.
    Fokus przeniesiony w trakcie: bez zmiany.
22. Zmiana z odpytywania (oczekuje → opłacone; oczekuje → płatność nieudana; koniec okna odpytywania): fokus ustawiony
    wcześniej na innym elemencie zostaje; „Płatność przyjęta.” występuje w DOM raz.

## S7: regresja #390 i etykieta #335

- **#390** nie odtwarza się: „Do koszyka” ma od ABUSE-LIMITS `aria-disabled` zamiast `disabled`
  (`apps/store/src/cart/AddToCartButton.tsx`) i zachowuje fokus na karcie produktu i na kafelku (zmierzone, także
  z POST opóźnionym o 2 s). Etap dodaje test, który to utrwala, i zamyka zgłoszenie.
- **#335:** wiersz sumy kasy ma etykietę „Do zapłaty (brutto)”, a specyfikacje kasy i dostawy nazywają go „Do zapłaty”.
  Nieaktualne są dwa miejsca w `docs/features/discount-codes/spec.md` („Razem”). Etap poprawia je na „Do zapłaty”;
  kod zostaje.

**Kryteria.**

23. Enter i Spacja na „Do koszyka” (karta produktu, kafelek), POST wstrzymany: fokus na przycisku w trakcie i po;
    jeden POST.

## Kryteria akceptacji epiku

24. Region statusu koszyka, kasy i potwierdzenia: węzeł pobrany przed akcją jest po niej tym samym, podłączonym
    węzłem ze zmienionym tekstem. Sprawdzone przez przejście wczytywanie → treść → pusty koszyk i przez zmianę
    statusu płatności.
25. Nowe komunikaty nie powodują przewijania poziomego przy 320 i 375 px.
26. Każde zadanie pokazuje w raporcie komendy i rzeczywiste wyniki testów przed zmianą (RED) i po niej (GREEN).
    Wyjątki: test S7 utrwala zachowanie już naprawione, więc przechodzi także przed zmianą (raport pisze to wprost);
    przeliczenie wag części E2E to konfiguracja, weryfikowana komendą z `e2e/CLAUDE.md`.
27. `make -C apps/store check`, `make -C apps/store test` i `STORE_MODE=production make e2e` przechodzą.
28. `git diff` względem `main` nie zawiera `apps/api/openapi*.json`, `apps/store/src/api/schema.d.ts` ani
    `apps/admin`, a z `apps/api` tylko komentarz w `apps/api/fixtures/stock.yaml` (lista testów E2E, które zamawiają
    produkt; dochodzi test kryterium 8).
29. Po dodaniu testów granice części E2E (`E2E_SHARD_WEIGHTS` i komentarz kroku `make e2e`
    w `.github/workflows/ci.yml`) są przeliczone według `e2e/CLAUDE.md`.

## Testy

- **Vitest** (logika, na wstrzymanych obietnicach i fałszywych zegarach): akcje czekające i zamiar przejścia
  w koszyku (kryteria 1–11 na poziomie komponentu), blokada kasy i warunek F2, błędy wyboru, odpytywanie płatności,
  link logowania według przełączników.
- **Playwright** (nowe pliki `e2e/tests/store-ux-*.spec.ts`; test S7 w osobnym `store-ux-product.spec.ts`, bo dotyczy
  karty produktu i kafelka, nie koszyka): to, czego jsdom nie pokaże, czyli kolejność `blur`
  i kliknięcia, dotyk, utrata fokusu przy `disabled` i jej brak przy `aria-disabled`, nawigacja, tożsamość węzła
  regionu. Reprezentatywne próby, bez powielania całej macierzy na każdym ekranie: test jednostkowy
  `document.activeElement` nie daje prawdziwej fazy RED dla przycisku z `disabled`, więc kryteria 13, 14, 16 i 21 mają
  RED w przeglądarce.
- Testy lokalizują regiony po `data-testid`: na listingu i w kasie są inne elementy `role="status"`.
- Wstrzymywanie żądań (`page.route`) jest pomocnikiem lokalnym każdego nowego pliku; `e2e/support` zostaje bez zmian.
  Ponowną aktywację kontrolki z `aria-disabled` test wykonuje klawiszem albo kliknięciem wymuszonym: Playwright czeka
  z kliknięciem na aktywność elementu (do potwierdzenia w wersji z `e2e/package.json`, 1.64.1).
- Test, który dziś utrwala ignorowanie kliknięć w trakcie zapisu koszyka (`apps/store/src/cart/CartPage.test.tsx`),
  zmienia się razem z zachowaniem S1.

## Dokumentacja

Razem z kodem, w tym samym commicie co zmiana zachowania:

- `docs/features/cart/spec.md`: akcje w trakcie zapisu, przejście do kasy, fokus i region statusu (S1–S3).
- `docs/features/checkout/spec.md`: ponowienie wczytania, link logowania według przełączników, blokada i fokus po
  odrzuceniu, błędy wyboru (S4, S5).
- `docs/features/payment/spec.md`: „Sprawdź ponownie” i region statusu (S6).
- `docs/features/discount-codes/spec.md`: etykieta sumy (#335).
- `apps/store/CLAUDE.md`, „Kasa”: korekta zdania o fokusie po odrzuceniu („bo przycisk jest zablokowany na czas
  żądania”), które przestaje być prawdziwe. Opisu działania tam nie dopisujemy.

## Równoległość z ADMIN-UX-FIXES (#778)

Stan na 2026-11-22: specyfikacja i plan ADMIN-UX-FIXES są na `main` (`docs/features/admin-ux-fixes`, #847), bez
zaimplementowanych zadań.

- **Kod aplikacji.** Ten etap zmienia tylko `apps/store`, ADMIN tylko `apps/admin`; aplikacje nie mają wspólnego
  pakietu ani klienta API.
- **Jedyny wspólny plik:** `.github/workflows/ci.yml` (`E2E_SHARD_WEIGHTS` i komentarz kroku `make e2e`). Każdy epik
  zmienia je w swoim ostatnim zadaniu, na stanie po scaleniu aktualnego `main`. Epik mergowany jako drugi liczy wagi
  od nowa komendą z `e2e/CLAUDE.md` na połączonym zestawie testów; przy konflikcie nie scala liczb ręcznie. Nowe
  pliki `store-ux-*.spec.ts` sąsiadują alfabetycznie ze `staff-dashboard.spec.ts` z ADMIN (dziś część 3).
- **Pliki E2E.** Ten etap dodaje `e2e/tests/store-ux-*.spec.ts` i nie zmienia sześciu plików E2E, które zmienia ADMIN
  (`admin-screens-inventory`, `admin-screens-prices`, `admin-screens-promotions`, `admin-screens-orders`,
  `catalog-admin`, `staff-dashboard`). Plan ADMIN nie zmienia `e2e/support`.
- **Zachowania sklepu, od których zależą testy panelu** (składają zamówienia przez `placeOrderAsJan`
  i `placeAndPayOrder`, `e2e/support/panel-orders.ts`, `e2e/support/orders.ts`): „Przejdź do kasy” zostaje linkiem
  o tej nazwie; „Twój koszyk jest pusty” i „Płatność przyjęta.” występują w DOM raz; w `main` koszyka nie przybywa
  elementów listy. Ta specyfikacja je zachowuje; zadanie, które musiałoby któreś zmienić, poprawia helper w tym samym
  commicie.
- **Po scaleniu `main` z drugim epikiem** sesja prowadząca uruchamia testy łączące sklep z panelem
  (`order-management`, `delivery-tracking`, `admin-customers`, `admin-design`, `panel-orders-url`,
  `admin-screens-orders`, `discount-codes`, `delivery-checkout`, `checkout-*`, `cart-*`, `payment-resume`,
  `visual-screens`), a potem
  pełne `make e2e`.
- **Dane.** Testy tego etapu składają zamówienia gościa i klienta `jan` jak istniejące testy sklepu i nie zmieniają
  kategorii, produktów ani promocji. Test stopki z najdłuższą nazwą kategorii (#631) jest poza etapem.
- **API.** #293 czyta istniejące `/api/site`, #335 zmienia tylko dokumentację: żaden endpoint ani schemat się nie
  zmienia.
- **Zasoby.** Każda sesja ma własny stos i porty (`.env` worktree).
- **Do ponownego sprawdzenia przed implementacją:** czy ADMIN-UX-FIXES jest już na `main` i czy jego `Zakres:` się
  nie zmienił; `git diff HEAD..origin/main -- e2e .github make`; kolejność merge'u obu epików.

## Zależność od API-INPUT-CONTRACT

Stan na 2026-11-22: na `main` jest etap 1 (#843), który nie zmienił `apps/store`, `e2e` ani `apps/api/openapi.json`;
kolejne etapy nie mają planu. Ten etap nie zmienia mapowania błędów API, ale S1 i S5 zmieniają pliki, które je
czytają. **Do ponownego sprawdzenia przed implementacją**, na aktualnym `main`:

1. `showFailure` w `CheckoutPage.tsx` (mapowanie `violations[].propertyPath` na pola i komunikat zbiorczy)
   i `apps/store/src/checkout/problems.ts` (mapowanie `type` odpowiedzi 409).
2. Statusy odpowiedzi koszyka w `apps/store/src/api/client.ts` (`callCart`) i ich obsługa w `CartPage.tsx`.
3. Teksty `detail` używane w asercjach testów koszyka i kasy (m.in. odrzucony kod rabatowy).
4. Różnice w `apps/api/openapi.json` i `apps/store/src/api/schema.d.ts` od `38639e68`.

## Granica ochrony zamówienia

Etap gwarantuje jedno: po aktywacji „Przejdź do kasy” w tej samej karcie kasa otwiera się dopiero po zakończeniu
wszystkich zmian zleconych na tej stronie koszyka (niezatwierdzony wpis, operacja w drodze, akcje czekające).

Poza gwarancją zostaje stan dzisiejszy (#853):

- Kasa otwarta inną drogą niż link w tej karcie: nowa karta albo okno (Ctrl, Cmd, środkowy przycisk, menu kontekstowe,
  ręcznie), przyciski wstecz i dalej.
- Zmiana koszyka w innej karcie, przeglądarce albo na innym urządzeniu po wczytaniu kasy.
- W obu przypadkach API zamawia aktualną zawartość koszyka z serwera. Odpowiedź 409 pojawia się tylko wtedy, gdy
  różni się kwota (`expectedTotalGross`); zmiana zawartości przy tej samej kwocie przechodzi bez ostrzeżenia. Zapisy
  koszyka i zamówienie nie mają wspólnej kolejności między kartami (`queueWrite` obejmuje zapisy koszyka, nie
  `placeOrder`).
- Przeładowanie albo zamknięcie karty w trakcie zapisu: akcje jeszcze niewysłane przepadają.

## Ryzyka i ograniczenia

- Ogłaszanie sprawdzono po DOM (regiony i ich tekst); próby prawdziwym czytnikiem ekranu nie było.
- Pomiary wykonano tylko w Chromium.
- Żądania koszyka i kasy nie mają limitu czasu: zapis bez odpowiedzi trzyma zamiar przejścia (S2) i blokadę kasy (S5)
  do błędu sieci przeglądarki. Dziś taki zapis też blokuje cały koszyk. Etap nie dodaje limitu czasu.
- S1 zmienia zachowanie, które dziś utrwala test jednostkowy koszyka; S5 zmienia regułę fokusu po odrzuceniu opisaną
  w `apps/store/CLAUDE.md`.
- `CartPage.tsx` zmieniają S1, S2 i S3, a `CheckoutPage.tsx` S4 i S5: zadania na tych plikach idą szeregowo.
