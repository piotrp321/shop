# ADMIN-UX-FIXES, etap 1: fokus, stan po zapisie i tabele panelu przy zwykłych danych

Pierwszy etap epiku #778: sześć poprawek użyteczności panelu (`apps/admin`), które występują w codziennej pracy na
desktopie przy zwykłych danych. Każda sekcja K1–K6 to jedna poprawka. Działanie ekranów opisują ich specyfikacje
(`docs/features/admin-screens/spec.md`, `docs/features/admin-design/spec.md`); ta opisuje tylko zmiany i zachowanie,
które dochodzi. Twierdzenia o obecnym kodzie dotyczą `origin/main` z 2026-11-22 (`1161b6f3`). Każdą pozycję odtworzono
tego dnia w przeglądarce na lokalnym stosie z danymi demo (konto administratora, Chromium), a przyczynę wskazano
w kodzie; wyjątki są nazwane w sekcjach.

Issues: #509, #572, #575, #579, #580, #627 (w całości); część #581 (F4) i część #584 (region wyboru wariantów).
Zakres: `apps/admin/src/components/CategoryCombobox.vue`, `apps/admin/src/promotions`, `apps/admin/src/views/PromotionFormView.vue`, `apps/admin/src/views/OrderDetailView.vue`, `apps/admin/src/orders`, `apps/admin/src/dashboard`, `apps/admin/src/prices`, `apps/admin/src/views/PricesView.vue`, `apps/admin/src/products/ProductPriceStock.vue`, `apps/admin/src/views/ProductFormView.vue`, `apps/admin/src/components/list`, `apps/admin/tests`, `e2e/tests/admin-screens-inventory.spec.ts`, `e2e/tests/admin-screens-prices.spec.ts`, `e2e/tests/catalog-admin.spec.ts`, `e2e/tests/admin-screens-promotions.spec.ts`, `e2e/tests/admin-screens-orders.spec.ts`, `e2e/tests/staff-dashboard.spec.ts`, `.github/workflows/ci.yml`, `docs/features/admin-design/spec.md`, `docs/features/admin-ux-fixes`

## Cel i zakres

- Wybór kategorii myszą nie zabiera fokusu, a pole nie wskazuje usuniętej opcji (K1; #572, #627).
- Promocja zapisana z „Od teraz” sama pokazuje status „Aktywna”; zmiana wpisana w trakcie zapisu nie przepada (K2; #580).
- Okna zamówienia nie dają się zamknąć w trakcie zapisu i nie gubią fokusu ani wyniku (K3; #581 F4).
- Wybór wariantów promocji przewija się w swoim regionie i nie rozciąga strony (K4; #575, część #584).
- „Ostatnie zamówienia” na pulpicie mieszczą się w karcie przy 1280 i 1440 px (K5; #509).
- Historia ceny pokazuje polską nazwę klasy podatkowej (K6; #579).

Etap nie zmienia API, `apps/api/openapi*.json` ani `apps/admin/src/api/schema.d.ts`. Przyczyna każdej pozycji leży
w panelu; w K1 mechanizm jest w bibliotece reka-ui 2.11.0, a poprawka w naszym komponencie.

## Decyzje

Właściciel zaakceptował 2026-11-22:

| # | Decyzja | Przyjęty wariant | Odrzucone |
|---|---|---|---|
| D1 | Zakres etapu 1 | K1–K6 | Dołożenie #629 albo grupy długich nazw (końcowy PR przestaje być do przejrzenia w jednym posiedzeniu) |
| D2 | Gdzie naprawić status po „Od teraz” (#580) | Panel czyta promocję ponownie po jej początku; status zawsze pochodzi z API | Zmiana API, żeby zapis od razu odpowiadał `active` (przekłamuje stan: początek „od teraz” to następna pełna sekunda, co chroni historię cen, `docs/features/promotions/spec.md`, „`startOfNow`”) |
| D3 | Zapis w toku (formularz promocji, okna zamówienia) | Blokada pól i zamknięcia okna na czas zapisu | Zachowywanie zmian wpisanych w trakcie zapisu; toast po zamknięciu okna |
| D4 | Co ustępuje miejsca w „Ostatnich zamówieniach” (#509) | Data jako druga linia komórki klienta; „Pozycje” jako kolumna tylko tam, gdzie tabela mieści się z nią w karcie | Karty pulpitu jedna pod drugą |
| D5 | #581 F5 (wymagana notatka z samym znakiem niewidocznym) | Poza tym etapem: przyczyna jest w walidacji API | Naprawa w tym etapie |

Do potwierdzenia przez właściciela przy akceptacji planu (specyfikacja przyjmuje rekomendację):

| # | Decyzja | Rekomendacja przyjęta w specyfikacji | Inne warianty |
|---|---|---|---|
| D6 | K2: co dzieje się z formularzem promocji między udanym zapisem „Od teraz” a odczytem statusu | Blokada trwa dalej: zwykle 1–2 s, zawsze najwyżej 10 s od odpowiedzi zapisu (całkowity limit oczekiwania). Po zapisie nie da się wpisać zmiany, którą nadpisałby odczyt, i nie trzeba scalać formularza z odpowiedzią | Pola odblokowane od razu po zapisie, a odczyt zmienia tylko status i akcje (wymaga reguł dla pól zmienionych w tym czasie i dla pól, które aktywna promocja blokuje) |
| D7 | K2 i K3: zapis, na który API nie odpowiada | Bez własnego limitu czasu zapisu: blokada trwa do odpowiedzi albo błędu sieci przeglądarki, a wyjściem jest opuszczenie ekranu: Wstecz przeglądarki przy otwartym oknie zamówienia (okno modalne zasłania menu panelu) albo opuszczenie formularza promocji. Opuszczenie ekranu nie anuluje operacji na serwerze: wynik takiego zapisu jest nieznany do ponownego wczytania zamówienia albo promocji, więc panel nie pozwala wysłać go drugi raz z tego samego okna | Limit czasu z odblokowaniem i ponowieniem (ponawia zmianę o nieznanym wyniku); brak blokady zamknięcia okna (wraca wada #581 F4) |

D3 dotyczy zapisu w toku i nie przesądza D6 ani D7: obie zmieniają to, jak długo pracownik czeka.

Zdania specyfikacji ekranów zostają prawdziwe po tym etapie; nowe zachowanie opisuje ta specyfikacja. Dwa wyjątki
w `docs/features/admin-design/spec.md`: wyjątek #250 F16 w „Po zapisie” (zmienia K2) i opis kolumn „Ostatnich
zamówień” (zmienia K5).

## Poza zakresem

- **Długie teksty bez spacji:** #573, #574, #628, #630, #581 F3 (jedna przyczyna: łamanie tekstu; objaw wymaga
  skrajnych danych).
- **#629** (karta „Ceny i stany” w formularzu produktu): przy 1280 px ukrycie kolumn ukrywanych przy 768 px nie
  wystarcza (tabela 683 px w kolumnie 586 px), więc poprawka wymaga wyboru wzorca tabeli dla wąskiej kolumny.
- **#576, #581 F6, F7, F8, F11.**
- **#581 F5.** Zgłoszenie zostaje otwarte jako pozycja #581. Żadne zgłoszenie ani zaplanowany etap API-INPUT-CONTRACT
  nie obejmuje dziś notatki zmiany statusu: `docs/features/api-input-contract/spec.md` („Poza etapem 1”) wymienia przy
  białych i niewidocznych znakach #305, #443, #641 i #632 F4, a #443 dotyczy adresu zamówienia i notatki korekty
  stanu. Przypisanie F5 do #779 jest decyzją właściciela, nie skutkiem tej specyfikacji.
- **Pozostałe części #584:** wyłączenia `expectControlsReachable` dla drzewa, pól daty, grupy opcji i podpowiedzi oraz
  sprawdzenie podpowiedzi przy 320 px (z #576) i akcji schowanych w przewijaniu poziomym (z #629). Helper
  `e2e/support/panel-layout.ts` zostaje bez zmian.
- Blokada pól w formularzach innych niż formularz promocji oraz oczekiwanie na koniec promocji (`watchEnd`).

## K1: wybór w comboboxie kategorii (#572, #627)

**Problem.** `CategoryCombobox` (`apps/admin/src/components/CategoryCombobox.vue:221-238`) w trybie pojedynczym:
po kliknięciu opcji fokus trafia na `<body>` (opcja ma `tabindex="-1"`, więc `mousedown` przenosi na nią fokus, a po
zamknięciu listy reka-ui oddaje go wyzwalaczowi, którego komponent nie ma). Zmierzone na `/prices`, `/inventory`,
`/products`, w polu „Kategoria główna” formularza produktu i w oknie „Przenieś do…”, gdzie fokus opuszcza okno
modalne. Po każdym wyborze, także z klawiatury, pole zachowuje `aria-activedescendant` z identyfikatorem opcji
usuniętej z DOM (axe: `aria-valid-attr-value`, critical).

**Zachowanie.**
- Po wyborze opcji myszą fokus jest w polu tego comboboxu; kolejny wpisany znak filtruje listę, a `Tab` przechodzi do
  następnej kontrolki. W oknie „Przenieś do…” fokus zostaje w oknie.
- Przy zamkniętej liście pole nie ma `aria-activedescendant` albo wskazuje element istniejący w DOM; przy otwartej
  wskazuje podświetloną opcję jak dotąd.
- Wybór z klawiatury, tryb wielokrotny, opcje wyłączone i `Escape` działają jak dotąd.

**Kryteria.**
1. `apps/admin/tests/CategoryCombobox.test.ts`: po wyborze kliknięciem i Enterem, przy zamkniętej liście, pole nie
   wskazuje nieistniejącego elementu; po kliknięciu opcji `document.activeElement` to pole. Asercję, której środowisko
   testu komponentu nie odtwarza przed zmianą (przeniesienie fokusu przy `mousedown`), pokrywa kryterium 2; raport
   zadania mówi, która to.
2. E2E (`admin-screens-inventory.spec.ts`, `catalog-admin.spec.ts`): na filtrze `/inventory`, w oknie „Przenieś do…”
   i w polu „Kategoria główna” po kliknięciu opcji pole ma fokus (w oknie: fokus wewnątrz okna), wpisany znak otwiera
   przefiltrowaną listę, a axe nie zgłasza `aria-valid-attr-value` po wyborze myszą i po wyborze Enterem. Testy nie
   przechodzą przed zmianą.

## K2: promocja zapisana z „Od teraz” (#580)

**Problem.** Początek „od teraz” to następna pełna sekunda po zapisie (`Promotion::startOfNow`,
`apps/api/src/Pricing/Domain/Promotion/Promotion.php:96`), więc odpowiedź zapisu ma zawsze `status: planned`
(zmierzone: `POST` 201 `planned`, `GET` sekundę później `active`). Formularz pokazuje status z odpowiedzi zapisu
(`usePromotionForm.ts:424-427`) i nie czyta promocji ponownie: „Zaplanowana” z „Usuń” zostaje do przeładowania
strony. Zegar przeglądarki i cache nie mają udziału (różnica zegarów poniżej 0,1 s, `Cache-Control: no-store`). E2E
obchodzi to pętlą przeładowań (`e2e/tests/admin-screens-promotions.spec.ts:415-420`). Istniejące `reloadQuietly`
(`usePromotionForm.ts:225-235`) nie nadaje się do wywołania z timera: nadpisuje cały formularz i przemilcza nieudany
odczyt. Osobno: w trakcie zapisu pola są edytowalne (`PromotionFormView.vue:405` blokuje tylko tryb do odczytu),
a odpowiedź nadpisuje formularz (`apply`, `:184-188`), więc tekst wpisany w tym czasie znika.

**Zachowanie.**
- **Blokada w trakcie zapisu.** Od wysłania zapisu do odpowiedzi pola formularza (nazwa, procent, początek, „Od
  teraz”, koniec) i kontrolki wariantów (lista wybranych, „Dodaj warianty”, otwarty wybór) są wyłączone (`disabled`)
  tak jak pola formularza metody dostawy (`DeliveryMethodFormView.vue:141-143`); akcje karty „Status” i pasek zapisu
  są wyłączone jak dotąd. Blokada niczego nie ukrywa: otwarty wybór wariantów zostaje otwarty. Ponowne „Zapisz” nie
  wysyła drugiego żądania. `SaveBar` zostaje bez zmian, a fokus po zapisie działa według standardu formularza
  (`docs/features/admin-design/spec.md`, „Po zapisie”); wyjątek #250 F16 (fokus przeniesiony do pola w trakcie
  żądania) nie występuje przy zapisie formularza promocji, bo pola są wyłączone, więc jego test w
  `PromotionFormView.test.ts` i zdanie o nim w `docs/features/admin-design/spec.md` („Po zapisie”: wyjątek nie
  dotyczy zapisu formularza promocji) zmieniają się razem z zachowaniem. Przy akcjach karty „Status” („Zakończ
  teraz”, „Usuń”) pola nie są blokowane i wyjątek działa jak dotąd. Po odpowiedzi udany zapis i 409 pokazują promocję z API jak dotąd, a 422 i błąd
  sieci odblokowują pola z wartościami, które pracownik wysłał.
- **Oczekiwanie na początek.** Gdy udany zapis (utworzenie albo zmiana) był wysłany z „Od teraz”, a odpowiedź ma
  `status: planned`, blokada trwa dalej, region `role="status"` karty „Status” pokazuje „Promocja rozpoczyna się.”,
  a panel czyta promocję (`GET /api/v1/admin/promotions/{id}`). Pierwszy odczyt wychodzi sekundę po `startsAt`
  z odpowiedzi według zegara przeglądarki, ale nie później niż 3 s po odpowiedzi zapisu. Zegar przeglądarki wyznacza
  tylko moment odczytu; status zawsze pochodzi z API. Zapis bez „Od teraz” niczego nie czyta ponownie.
  - Odpowiedź ze statusem innym niż `planned`: formularz pokazuje promocję z tej odpowiedzi (status, akcje, blokady
    trwającej promocji), blokada znika; dla `active` region statusu ogłasza „Promocja jest aktywna.”. Przy tworzeniu
    karta „Status” pojawia się z odpowiedzią zapisu, więc to ogłoszenie trafia do regionu, który już istnieje.
  - Odpowiedź `planned`: kolejny odczyt po sekundzie, najwyżej 5 razy. Po ostatniej blokada znika, status zostaje
    „Zaplanowana”, a region statusu pokazuje „Promocja jeszcze się nie rozpoczęła. Początek: <data i godzina>.”.
  - Nieudany odczyt (błąd sieci, odpowiedź inna niż 200): oczekiwanie się kończy, blokada znika, widok zostaje jak po
    zapisie, a komunikat błędu (`role="alert"`) „Nie udało się odczytać statusu promocji. Odśwież, żeby zobaczyć jej
    stan.” ma przycisk „Odśwież”. „Odśwież” działa jak ten sam przycisk po nieudanym odczycie końca (`refresh`,
    `usePromotionForm.ts:266-273`): udany odczyt pokazuje promocję i przenosi fokus na nagłówek karty „Status”,
    nieudany zastępuje formularz komunikatem wczytania z linkiem do listy promocji.
  - Całkowity limit: gdy 10 s po odpowiedzi zapisu oczekiwanie jeszcze trwa (odczyt bez odpowiedzi, wolne odczyty),
    kończy się tak jak po nieudanym odczycie: blokada znika, widać ten sam komunikat z „Odśwież”. Odpowiedź odczytu,
    która wróci po limicie, jest pomijana i nie zmienia formularza, statusu ani wersji, także gdy pracownik zdążył
    zmienić pole: limit unieważnia trwający odczyt tak jak nowsze żądanie formularza. Opuszczenie widoku, przejście
    do innej promocji i „Odśwież” zatrzymują licznik limitu. Limit jest lokalnym licznikiem tego oczekiwania, nie
    limitem czasu klienta API; dotyczy tylko odczytów statusu (`GET`), nie zapisu. Przy wolnym, ale działającym API
    pracownik widzi wtedy komunikat o nieudanym odczycie, choć promocja mogła się już rozpocząć; „Odśwież” pokazuje
    jej stan.
- **Ochrona zmian.** W trakcie oczekiwania nie da się nic wpisać, a po jego końcu panel sam nie czyta promocji
  ponownie, więc żaden automatyczny odczyt nie nadpisuje zmian pracownika.
- **Nieaktualne odpowiedzi.** Odpowiedź odczytu jest pomijana, gdy w tym czasie pracownik opuścił widok, otworzył inną
  promocję albo formularz nowej, albo formularz wysłał nowsze żądanie (zapis, „Odśwież”). Opuszczenie widoku
  i przejście do innej promocji zatrzymują oczekiwanie. Przejście z `/promotions/new` na `/promotions/:id` po
  utworzeniu go nie przerywa. To samo dotyczy odpowiedzi samego zapisu: gdy wraca po przejściu do innej promocji albo
  formularza nowej, nie zmienia formularza, nie pokazuje toastu, nie nawiguje i nie uruchamia oczekiwania (dziś
  `perform` sprawdza tylko opuszczenie widoku, `usePromotionForm.ts:420-423`).
- **Czas.** Liczony od chwili, w której przeglądarka dostała odpowiedź zapisu. Gdy zegary przeglądarki i serwera
  różnią się o mniej niż 0,5 s, pierwszy odczyt wychodzi najpóźniej po 2,5 s, więc „Aktywna” i „Zakończ teraz” są
  widoczne w ciągu 3 s, jeśli odczyt wraca w czasie poniżej 0,5 s. Niezależnie od zegarów i od czasu odpowiedzi API
  pierwszy odczyt wychodzi najpóźniej po 3 s, a blokada po udanym zapisie kończy się najpóźniej po 10 s.

**Kryteria.**
1. Testy `usePromotionForm.test.ts` i `PromotionFormView.test.ts` z fałszywym zegarem (jak testy #304 F7):
   (a) zapis z „Od teraz” i odpowiedzią `planned` ze `startsAt` sekundę po odpowiedzi daje jeden `GET` dokładnie 2 s
   po odpowiedzi, a po odpowiedzi `active` status „Aktywna”, „Zakończ teraz”, „Promocja jest aktywna.”
   w `role="status"` i odblokowane pola; (b) `startsAt` minutę po odpowiedzi (spóźniony zegar przeglądarki) daje
   pierwszy `GET` 3 s po odpowiedzi; (c) sześć odpowiedzi `planned` kończy oczekiwanie komunikatem i odblokowaniem,
   dalszych `GET` nie ma; (d) nieudany odczyt pokazuje błąd z „Odśwież”; udane „Odśwież” pokazuje promocję z fokusem
   na nagłówku karty „Status”, nieudane daje komunikat wczytania; (e) wstrzymana odpowiedź zapisu: pola i kontrolki
   wariantów są wyłączone, otwarty wybór zostaje otwarty, drugie „Zapisz” nie wysyła żądania; po 422 i po błędzie
   sieci pola mają wysłane wartości i są odblokowane; (f) w trakcie oczekiwania pola, kontrolki wariantów i akcje
   karty „Status” są wyłączone; (g) opuszczenie widoku i otwarcie innej promocji w trakcie oczekiwania: żadnego
   kolejnego `GET` oczekiwania, a odpowiedź odczytu, która wróci później, niczego nie zmienia; odpowiedź zapisu, która
   wraca po otwarciu innej promocji, nie zmienia jej formularza i nie daje toastu ani oczekiwania; (h) zapis
   z początkiem w przyszłości (bez „Od teraz”) nie daje dodatkowego `GET`; (i) `GET`, którego odpowiedź nie wraca:
   10 s po odpowiedzi zapisu pola są odblokowane i widać komunikat z „Odśwież”; po zmianie pola nazwy zwolniona
   odpowiedź `active` nie zmienia nazwy, statusu ani komunikatu. Testy (a)–(f), (i) i część (g) o odpowiedzi zapisu
   nie przechodzą przed zmianą; reszta (g) i (h) to kontrole do (a).
2. E2E (`admin-screens-promotions.spec.ts`): po toaście „Promocja zapisana.” karta „Status” pokazuje „Aktywna”
   i „Zakończ teraz” w ciągu 5 s bez przeładowania strony; pętla przeładowań znika. Drugi test wstrzymuje odpowiedź
   zapisu o 3 s (`page.route`), sprawdza w tym czasie, że pole nazwy jest wyłączone, i ten sam wynik po odpowiedzi.
   Testy nie przechodzą przed zmianą.

## K3: okna zamówienia w trakcie zapisu (#581 F4)

**Problem.** W oknie zmiany statusu przycisk skutku ma `disabled` na czas zapisu
(`apps/admin/src/views/OrderDetailView.vue:1014`), więc fokus spada na `<body>`. `Escape`, kliknięcie obok, „Wróć”
i „Zamknij” zamykają okno (`onOpenChange`, `:178-182`), a `closeDialog` (`:173-176`) zwiększa licznik sesji, przez co
`submit` odrzuca wynik (`:247-251`): status zmienia się na serwerze bez toastu i z fokusem na `<body>` (zmierzone
z odpowiedzią wstrzymaną o 3 s, na podstawionych odpowiedziach API, bo dane demo nie mają zamówień). Okno numeru
przesyłki ma ten sam wzorzec (`:1092`, `closeTracking` `:305-308`); potwierdzone w kodzie, więc zadanie zaczyna od
odtworzenia go testem.

**Zachowanie** (okno zmiany statusu i zwrotu oraz okno numeru przesyłki).
- **W trakcie zapisu** okno, z którego wyszło żądanie, zostaje otwarte: `Escape`, kliknięcie obok, „Wróć” i „Zamknij”
  nic nie robią. Pola „Notatka” i „Numer przesyłki” są tylko do odczytu. Przycisk skutku ma `aria-disabled="true"`
  zamiast `disabled` i wygląda jak przycisk `ConfirmDialog` z `busy`; jego aktywacja (kliknięcie, Enter, Spacja)
  i Enter w polu numeru przesyłki nie wysyłają drugiego żądania. Fokus zostaje na kontrolce, która go miała.
- **Po odpowiedzi** blokada znika w każdym przypadku:
  - zapis udany: toast, zamknięcie okna i fokus jak dotąd (`docs/features/admin-screens/spec.md`, „Okno zmiany
    statusu”, „Okno numeru przesyłki”);
  - 422: okno zostaje, pola zachowują wpisane wartości i znów przyjmują zmiany, błąd przy polu z fokusem w polu;
  - 409: w oknie zmiany statusu okno się zamyka, komunikat `conflict` dostaje fokus, zamówienie jest wczytywane
    ponownie; w oknie numeru przesyłki okno zostaje z komunikatem i wartością pola, zamówienie jest wczytywane
    ponownie (oba jak dotąd);
  - błąd sieci, 403 i inne: komunikat w oknie z fokusem na nim, pola zachowują wartości; ponowna próba wysyła nowe
    żądanie.
- **Zamknięcie przez panel działa jak dotąd.** Przejście do innego zamówienia albo opuszczenie ekranu w trakcie
  zapisu zamyka okno, a odpowiedź dotycząca poprzedniego zamówienia jest pomijana (bez toastu, bez zmiany nowego
  widoku). Przy otwartym oknie modalnym drogą dostępną dla pracownika jest Wstecz przeglądarki; menu panelu jest pod
  oknem. Zamknięcie okna tą drogą nie anuluje zapisu na serwerze. Blokada zamknięcia dotyczy tylko okna, z którego wyszło żądanie w toku: okno otwarte potem dla innego
  zamówienia zamyka się normalnie, a jego przycisk skutku jest nieaktywny do końca tamtego żądania (stan zapisu jest
  wspólny, `apps/admin/src/orders/useOrderDetail.ts:115`, `:186`).

**Kryteria.**
1. `apps/admin/tests/OrderDetailView.test.ts`, dla obu okien, ze wstrzymaną odpowiedzią: (a) `Escape`, kliknięcie
   obok, „Wróć” i „Zamknij” nie zamykają okna, a przycisk skutku ma `aria-disabled="true"` i nie ma `disabled`;
   (b) drugie kliknięcie, Enter i Spacja na przycisku skutku (i Enter w polu numeru) dają dokładnie jedno żądanie,
   a wpisywanie nie zmienia pól; (c) po zwolnieniu odpowiedzi udanej, 422, 409 i błędu sieci blokada znika,
   a wartości pól, komunikat i fokus są jak w sekcji „Zachowanie”; po 422 i po błędzie sieci kolejna próba wysyła
   nowe żądanie; (d) zmiana trasy na inne zamówienie w trakcie wstrzymanej odpowiedzi zamyka okno, późniejsza
   odpowiedź nie daje toastu ani zmiany widoku, a okno otwarte w tym czasie dla nowego zamówienia zamyka się
   `Escape`. Testy (a), wpisywanie z (b) i udany zapis po `Escape` z (c) nie przechodzą przed zmianą.
2. E2E (`admin-screens-orders.spec.ts`, na zamówieniu złożonym w teście; komentarz nagłówka pliku o testach, które
   niczego nie zmieniają, dostaje ten wyjątek): odpowiedź zmiany statusu wstrzymana o 3 s (`page.route`); w tym
   czasie przycisk skutku ma fokus, a `Escape` zostawia okno; po odpowiedzi jest toast „Status zamówienia
   zmieniony: …” i fokus na nagłówku karty. Test nie przechodzi przed zmianą. Drugi test (kontrola, przechodzi także
   przed zmianą): przy otwartym oknie i wstrzymanej odpowiedzi Wstecz przeglądarki (`page.goBack()`) opuszcza ekran
   zamówienia, okno znika, a po zwolnieniu odpowiedzi nie ma toastu.

## K4: wybór wariantów promocji (#575, część #584)

**Problem.** Region „Warianty do wyboru” (`apps/admin/src/promotions/PromotionVariantPicker.vue:179-187`) ma tylko
`overflow-x-auto`: przy 261 wierszach strony wyboru z danych demo ma 16 568 px wysokości, a strona 17 629 px
(1280×720; 17 838 px przy 375 px). Pole szukania i pager są od siebie o kilkanaście ekranów. `expectControlsReachable`
sprawdza region wyższy niż okno tylko w poziomie (`e2e/support/panel-layout.ts:95-98`), więc tego nie wykrywa.

**Zachowanie.**
- Region ma wysokość najwyżej 60% wysokości okna (nie mniej niż 12 rem), dobraną tak, żeby pole szukania, region
  i pager były naraz widoczne między górnym paskiem panelu (`AppTopbar`, przyklejony) a paskiem zapisu (`SaveBar`,
  przyklejony do dołu). Ta jednoczesna widoczność dotyczy okien o wysokości co najmniej 720 px; w niższych region
  ma dolną granicę 12 rem, a resztę przewija strona. Region przewija się w sobie w pionie; w poziomie jak dotąd. Pole
  szukania i pager leżą poza regionem.
- Region zachowuje `tabindex="0"`: po fokusie przewija się klawiaturą. Przycisk „Dodaj…” wiersza, który dostaje fokus
  z klawiatury, jest w całości w widocznej części regionu i okna.
- Po „Dodaj” fokus wraca na pole szukania jak dotąd.

**Kryteria** (E2E w `admin-screens-promotions.spec.ts`, przy 1280×720 i 375×812, w formularzu nowej promocji
z widocznym paskiem zapisu, na stronie wyboru z ponad 100 wierszami z danych demo; asercje geometrii, bez asercji klas
CSS; nie przechodzą przed zmianą).
1. Wysokość regionu jest nie większa niż 60% wysokości okna (z tolerancją 1 px), a `scrollHeight` regionu większe
   niż `clientHeight`.
2. Po otwarciu wyboru („Dodaj warianty”) test przewija stronę tak, żeby pole szukania było tuż pod górnym paskiem
   panelu (albo do końca strony, gdy dalej przewinąć się nie da), i sprawdza rzeczywiste krawędzie: górna krawędź pola nie wyżej niż dolna krawędź górnego paska, dolna
   krawędź przycisków pagera nie niżej niż górna krawędź paska zapisu, a `elementFromPoint` w środku pola i w środku
   każdego przycisku pagera trafia w ten element (nie zasłania ich przyklejony pasek).
3. Klawiatura: po fokusie regionu `End` zwiększa jego `scrollTop`; `Tab` z regionu trafia na pierwszy „Dodaj…”,
   a `Shift+Tab` z pierwszego aktywnego przycisku pagera na ostatni; każdy z nich po fokusie leży w całości w widocznym prostokącie regionu i okna
   i nie jest zasłonięty (`elementFromPoint` w jego środku trafia w niego).
4. Po „Dodaj” na ostatnim wierszu fokus jest na polu szukania, a pole leży w oknie.

## K5: „Ostatnie zamówienia” na pulpicie (#509)

**Problem.** Tabela karty (`apps/admin/src/dashboard/RecentOrders.vue:93-171`) potrzebuje 762 px z najdłuższą
etykietą statusu („Czeka na płatność”; 638 px z „Opłacone”), a region ma 600 px przy oknie 1280 px i 707 px przy
1440 px (obok karty „Do obsługi”, `DashboardView.vue:68-75`). Region przewija się w poziomie bez cienia krawędzi,
który ma `DataTable`, więc ucięty „Status” nie ma podpowiedzi. Zmierzone na podstawionej odpowiedzi listy zamówień
(dane demo nie mają zamówień).

**Zachowanie** (D4).
- Kolumny: Numer, Klient, Pozycje, Kwota, Status. Data zamówienia jest drugą linią komórki „Klient”. „Pozycje” są
  kolumną tylko wtedy, gdy tabela mieści się z nią w karcie; próg liczy się od szerokości karty (container query),
  nie okna, bo karta ma 2/3 albo całą szerokość pulpitu (`DashboardView.vue:74`). Poniżej progu liczba pozycji jest
  kolejną linią komórki „Klient” (jak ukryte kolumny `DataTable`). Żadna informacja nie znika.
- Region, którego treść wychodzi poza krawędź, ma przy niej cień jak `DataTable`. Logika krawędzi (`DataTable.vue:122-136`)
  staje się wspólnym kodem w `apps/admin/src/components/list`, bez drugiej kopii; zachowanie `DataTable` się nie
  zmienia.
- Stany wczytywania, pusty, błędu z „Spróbuj ponownie” i 403 oraz link „Wszystkie zamówienia →” zostają bez zmian.
- `docs/features/admin-design/spec.md` („Ostatnie zamówienia”) dostaje nowy opis kolumn.

**Kryteria** (E2E w `staff-dashboard.spec.ts`; odpowiedź listy zamówień karty podstawiona przez `page.route`: pięć
zamówień, w tym ze statusem o najdłuższej etykiecie; rozmiary okna ustawia test; asercje geometrii, bez asercji klas
CSS; kryteria 1 i 3 nie przechodzą przed zmianą).
1. Przy 1280×720 i 1440×900 (administrator, karta obok „Do obsługi”): `scrollWidth` regionu nie większe niż
   `clientWidth`, a etykieta statusu każdego wiersza leży w całości w regionie.
2. Przy 1280×720 każdy wiersz pokazuje numer, klienta, datę, kwotę i status.
3. Przy 375×812 strona nie przewija się w poziomie; gdy region się przewija, cień przy końcowej krawędzi jest
   widoczny, a po przewinięciu do końca widoczny jest cień przy początkowej. Raport zadania podaje zmierzone
   `scrollWidth` i `clientWidth` regionu przy 375 px; gdy tabela mieści się tam bez przewijania, test sprawdza cień
   przy 320×640.
4. `apps/admin/tests/DashboardView.test.ts` sprawdza nową strukturę wiersza (data i liczba pozycji w komórce klienta)
   i niezmienione stany karty; testy `DataTable` przechodzą bez zmiany asercji.

## K6: klasa podatkowa w historii ceny (#579)

**Problem.** Okno „Historia ceny” pokazuje kod klasy (`energy_bar`), a lista cen jej polską nazwę („Batony
energetyczne”): `apps/admin/src/prices/PriceDialogs.vue:184` i `:347` wyświetlają `row.taxClass`. API podaje w liście
i w historii tylko kod; nazwę panel bierze ze słownika `GET /api/v1/admin/tax-classes` (`taxClassName`,
`apps/admin/src/products/taxClasses.ts:22`). Słownik mają już wczytany lista cen (`PricesView.vue`) i formularz
produktu (`useProductForm`), ale karta „Ceny i stany” (`ProductPriceStock.vue`) go nie dostaje.

**Zachowanie.**
- Kolumna „Klasa” historii ceny (i jej linia w komórce daty poniżej 1024 px) pokazuje polską nazwę klasy, w oknie
  otwartym z `/prices` i z karty „Ceny i stany” formularza produktu.
- Formularz produktu przekazuje karcie „Ceny i stany” słownik, który już wczytał; nie ma dodatkowego żądania.
- Gdy słownik się nie wczytał albo nie ma w nim klasy z wiersza historii, widać kod, jak na liście cen.

**Kryteria.**
1. `PricesView.test.ts` i `ProductPriceStock.test.ts`: historia pokazuje nazwę ze słownika; bez słownika (błąd
   `GET /tax-classes` na liście cen, pusty słownik w karcie produktu) i dla kodu spoza słownika pokazuje kod. Test
   nazwy nie przechodzi przed zmianą.
2. E2E (`admin-screens-prices.spec.ts`): klasa w historii ceny wariantu jest tym samym tekstem co klasa w jego
   wierszu listy.

## Kryteria akceptacji epiku

1. Każde zadanie pokazuje w raporcie komendy i rzeczywiste wyniki uruchomienia testów przed zmianą (RED) i po niej
   (GREEN). Dla K4 i K5 dowodem RED są testy przeglądarkowe z asercjami geometrii.
2. `make -C apps/admin check`, `make -C apps/admin test` i `make e2e` przechodzą.
3. `git diff` względem `main` nie zawiera `apps/api`, `apps/api/openapi*.json` ani `apps/admin/src/api/schema.d.ts`.
4. Po dodaniu testów granice części E2E (`E2E_SHARD_WEIGHTS` i komentarz kroku `make e2e` w
   `.github/workflows/ci.yml`) są przeliczone według `e2e/CLAUDE.md`.

## Równoległość ze STORE-UX-FIXES (#777)

Stan na 2026-11-22: #777 nie ma specyfikacji ani planu (brak katalogu w `docs/features`, gałęzi i otwartego PR),
więc bezpieczeństwo równoległej pracy nie jest potwierdzone. **Warunek:** przed równoległą implementacją sesja
prowadząca porównuje `Zakres:` i plan #777 z tą specyfikacją w punktach poniżej.

- **Kod aplikacji.** Etap zmienia tylko `apps/admin`; aplikacje nie mają wspólnego pakietu, a klient panelu powstaje
  z osobnego `openapi-admin.json`.
- **Granice części E2E** (`.github/workflows/ci.yml`, `E2E_SHARD_WEIGHTS` z komentarzem): zmienia je każdy epik, który
  dodaje testy. Propozycja: przelicza je ten epik, który merguje drugi, po rebase na `main`.
- **Pliki `e2e/support` używane przez testy sklepu i panelu** (`accounts.ts`, `urls.ts`, `shop.ts`, `orders.ts`,
  `promotions.ts`, `discount-codes.ts`, `price-change.ts`): ten etap ich nie zmienia.
- **Dane.** Testy tego etapu nie zostawiają kategorii ani produktów; promocje utworzone w teście są kończone jak
  dotąd. Do sprawdzenia w planie #777: test stopki z najdłuższą nazwą kategorii (#631).
- **Zasoby.** Każda sesja ma własny stos i porty (`.env` worktree); rezerwa pamięci na stos to 6 GiB
  (`docs/features/parallel-work/spec.md`, „Zasoby”).
- **API.** Do sprawdzenia w planie #777: czy #293 i #335 zmieniają endpoint albo wspólny schemat (`Problem`,
  `CategoryNode`).

## Zależność od API-INPUT-CONTRACT

Stan na 2026-11-22: na `main` jest etap 1 (#843); kolejne etapy są tylko w #779 i w sekcji „Poza etapem 1”
specyfikacji tego epiku, bez planu. Etap 1 ADMIN-UX-FIXES nie zmienia kontraktu, ale K2 i K3 zmieniają pliki, które
czytają odpowiedzi błędów. **Do ponownego sprawdzenia przed implementacją**, na aktualnym `main`:

1. `violations[].propertyPath` i liczba naruszeń dla promocji i zmiany statusu zamówienia
   (`apps/admin/src/promotions/usePromotionForm.ts:42`, `:342-353`; `apps/admin/src/orders/useOrderDetail.ts:58-70`).
2. Teksty `detail` używane w asercjach testów panelu i E2E ekranów promocji i zamówień.
3. Różnice w `apps/api/openapi-admin.json` i `apps/admin/src/api/schema.d.ts` od `1161b6f3`.
4. Odtworzenie #580 jednym zapisem „Od teraz”: odpowiedź zapisu nadal `planned`, `GET` sekundę później `active`.

## Ryzyka i założenia

- **K1.** Sposób usunięcia nieaktualnego `aria-activedescendant` nie jest sprawdzony: wartość ustawia `ListboxFilter`
  reka-ui. Zadanie zaczyna od testu i wybiera sposób w naszym komponencie (nadpisanie atrybutu przy zamkniętej
  liście albo zostawienie listy w DOM); miarą jest kryterium axe.
- **K2 (D6).** Formularz jest zablokowany przez oczekiwanie na początek: zwykle 1–2 s, najwyżej 10 s od odpowiedzi
  zapisu, także przy rozjechanych zegarach i odczycie bez odpowiedzi. Po limicie panel pokazuje „Zaplanowana”, choć
  promocja może już trwać; wyjaśnia to komunikat z „Odśwież”.
- **K2 i K3 (D7).** Klient API panelu nie ma limitu czasu żądania. Zapis bez odpowiedzi trzyma blokadę do błędu sieci
  przeglądarki: w oknie zamówienia wyjściem jest Wstecz przeglądarki (menu panelu jest pod oknem modalnym), które
  zamyka okno i pomija odpowiedź; w formularzu promocji jego opuszczenie. Żadna z tych dróg nie anuluje zapisu na
  serwerze, więc jego wynik jest nieznany do ponownego wczytania. Ten etap nie dodaje limitu czasu zapisu.
- **K3 i K5.** Dane demo nie mają zamówień: test E2E K3 składa własne zamówienie (`placeOrderAsJan`,
  `e2e/support/panel-orders.ts`, bez zmiany helpera), a test K5 podstawia odpowiedź listy.
- Zadania zmieniają rozłączne pliki, poza K2 i K4, które dzielą wybór wariantów formularza promocji
  (`apps/admin/src/promotions`) i plik `e2e/tests/admin-screens-promotions.spec.ts`, oraz K2 i K5, które zmieniają
  dwie sekcje `docs/features/admin-design/spec.md`.
