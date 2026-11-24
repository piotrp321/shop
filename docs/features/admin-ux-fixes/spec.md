# ADMIN-UX-FIXES: poprawki UX panelu (etap 1 i etap 2)

Etap 1 (K1–K6) i etap 2 (B1–B4, bezpieczeństwo formularzy, sekcja „Etap 2” na końcu pliku) są w kodzie.

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

Właściciel potwierdził 2026-11-22 także, jak długo pracownik czeka:

| # | Decyzja | Przyjęty wariant | Odrzucone |
|---|---|---|---|
| D6 | K2: co dzieje się z formularzem promocji między udanym zapisem „Od teraz” a odczytem statusu | Blokada trwa dalej: zwykle 1–2 s, zawsze najwyżej 10 s od odpowiedzi zapisu (całkowity limit oczekiwania). Po zapisie nie da się wpisać zmiany, którą nadpisałby odczyt, i nie trzeba scalać formularza z odpowiedzią | Pola odblokowane od razu po zapisie, a odczyt zmienia tylko status i akcje (wymaga reguł dla pól zmienionych w tym czasie i dla pól, które aktywna promocja blokuje) |
| D7 | K3: zapis w oknie zamówienia, na który API nie odpowiada (formularze: E2, „Etap 2”) | Bez własnego limitu czasu zapisu: blokada trwa do odpowiedzi albo błędu sieci przeglądarki, a wyjściem jest Wstecz przeglądarki przy otwartym oknie zamówienia (okno modalne zasłania menu panelu). Opuszczenie ekranu nie anuluje operacji na serwerze: wynik takiego zapisu jest nieznany do ponownego wczytania zamówienia, więc panel nie pozwala wysłać go drugi raz z tego samego okna | Limit czasu z odblokowaniem i ponowieniem (ponawia zmianę o nieznanym wyniku); brak blokady zamknięcia okna (wraca wada #581 F4) |

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
- Oczekiwanie na koniec promocji (`watchEnd`). Blokada pól w formularzach innych niż formularz promocji i limit zapisu
  tych formularzy: „Etap 2”.

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
  wskazuje podświetloną opcję jak dotąd. Tak samo przy otwartej liście bez opcji do podświetlenia (wpisany tekst nie
  pasuje do żadnej kategorii albo pasują tylko opcje wyłączone, np. poddrzewo przenoszonej kategorii w „Przenieś
  do…”): pole nie ma `aria-activedescendant`.
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
  teraz”, „Usuń”) pola nie są blokowane i wyjątek działa jak dotąd. Po odpowiedzi udany zapis pokazuje promocję z API; 422 odblokowuje pola z wartościami,
  które pracownik wysłał; 409 nie zastępuje szkicu (E7). Brak odpowiedzi w limicie 10 s, błąd sieci i 5xx to nieznany wynik zapisu (B4 w
  „Etapie 2”): po limicie pola zostają zablokowane do odpowiedzi, „Sprawdź stan” (edycja) albo „Odblokuj formularz”
  (nowa promocja); po błędzie sieci i 5xx pola są odblokowane ze szkicem.
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
    nieudany (błąd sieci, odpowiedź inna niż 200, brak odpowiedzi w 10 s) zostawia formularz i pokazuje komunikat „Nie
    udało się odczytać stanu. Spróbuj ponownie.” z przyciskiem ponowienia (B3); w trakcie odczytu region
    `role="status"` formularza mówi „Odświeżamy…”.
  - Całkowity limit: gdy 10 s po odpowiedzi zapisu oczekiwanie jeszcze trwa (odczyt bez odpowiedzi, wolne odczyty),
    kończy się tak jak po nieudanym odczycie: blokada znika, widać ten sam komunikat z „Odśwież”. Odpowiedź odczytu,
    która wróci po limicie, jest pomijana i nie zmienia formularza, statusu ani wersji, także gdy pracownik zdążył
    zmienić pole: limit unieważnia trwający odczyt tak jak nowsze żądanie formularza. Opuszczenie widoku, przejście
    do innej promocji i „Odśwież” zatrzymują licznik limitu. Limit jest lokalnym licznikiem tego oczekiwania, nie
    limitem czasu klienta API; dotyczy tylko odczytów statusu (`GET`), nie zapisu. Przy wolnym, ale działającym API
    pracownik widzi wtedy komunikat o nieudanym odczycie, choć promocja mogła się już rozpocząć; „Odśwież” pokazuje
    jej stan.
- **Ochrona zmian.** W trakcie oczekiwania nie da się nic wpisać, a po jego końcu panel sam nie czyta promocji
  ponownie, więc żaden automatyczny odczyt nie nadpisuje zmian pracownika. Pola są zablokowane także na czas odczytu
  „Odśwież” (jego odpowiedź zastępuje cały formularz), a akcje karty „Status” i „Zapisz” są wtedy wyłączone;
  odpowiedź odczytu po opuszczeniu widoku albo otwarciu innej promocji jest pomijana i nie zostawia blokady.
- **Nieaktualne odpowiedzi.** Odpowiedź odczytu jest pomijana, gdy w tym czasie pracownik opuścił widok, otworzył inną
  promocję albo formularz nowej, albo formularz wysłał nowsze żądanie (zapis, „Odśwież”). Opuszczenie widoku
  i przejście do innej promocji zatrzymują oczekiwanie. Przejście z `/promotions/new` na `/promotions/:id` po
  utworzeniu go nie przerywa. To samo dotyczy odpowiedzi samego zapisu: gdy wraca po przejściu do innej promocji albo
  formularza nowej, nie zmienia formularza, nie pokazuje toastu, nie nawiguje i nie uruchamia oczekiwania
  (`useFormSafety`).
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
   na nagłówku karty „Status”, nieudane zostawia formularz z komunikatem do ponowienia; (e) wstrzymana odpowiedź zapisu: pola i kontrolki
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

## Równoległość ze STORE-UX-FIXES (#777)

Stan na 2026-11-22: #777 nie ma specyfikacji ani planu (brak katalogu w `docs/features`, gałęzi i otwartego PR),
więc bezpieczeństwo równoległej pracy nie jest potwierdzone. **Warunek:** przed równoległą implementacją sesja
prowadząca porównuje `Zakres:` i plan #777 z tą specyfikacją w punktach poniżej.

- **Kod aplikacji.** Etap zmienia tylko `apps/admin`; aplikacje nie mają wspólnego pakietu, a klient panelu powstaje
  z osobnego `openapi-admin.json`.
- **Granice części E2E** (`.github/workflows/ci.yml`, `E2E_SHARD_WEIGHTS`): epiki ich nie zmieniają (`e2e/CLAUDE.md`,
  „CI”).
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
- **K3 (D7).** Klient API panelu nie ma limitu czasu żądania. Zapis w oknie zamówienia bez odpowiedzi trzyma blokadę do
  błędu sieci przeglądarki: wyjściem jest Wstecz przeglądarki (menu panelu jest pod oknem modalnym), które zamyka okno
  i pomija odpowiedź. To nie anuluje zapisu na serwerze, więc jego wynik jest nieznany do ponownego wczytania. Okna
  zamówienia nie mają limitu czasu zapisu. Zapis formularza promocji ma limit 10 s i stan „nieznany wynik” (E2).
- **K3 i K5.** Dane demo nie mają zamówień: test E2E K3 składa własne zamówienie (`placeOrderAsJan`,
  `e2e/support/panel-orders.ts`, bez zmiany helpera), a test K5 podstawia odpowiedź listy.
- Zadania zmieniają rozłączne pliki, poza K2 i K4, które dzielą wybór wariantów formularza promocji
  (`apps/admin/src/promotions`) i plik `e2e/tests/admin-screens-promotions.spec.ts`, oraz K2 i K5, które zmieniają
  dwie sekcje `docs/features/admin-design/spec.md`.

# Etap 2: bezpieczeństwo formularzy

Drugi etap epiku #778: cztery formularze panelu (produkt, kod rabatowy, promocja, metoda dostawy) nie gubią zmian
pracownika w trakcie własnych żądań i nie zostają zablokowane, gdy API nie odpowiada. Sekcje B1–B4.

Issues: #837, #866 (w całości).
Zakres: `apps/admin/src/composables`, `apps/admin/src/components/FormSafetyNotices.vue`, `apps/admin/src/components/SaveBar.vue`, `apps/admin/src/promotions`, `apps/admin/src/discount-codes`, `apps/admin/src/products`, `apps/admin/src/delivery`, `apps/admin/src/views/PromotionFormView.vue`, `apps/admin/src/views/DiscountCodeFormView.vue`, `apps/admin/src/views/ProductFormView.vue`, `apps/admin/src/views/DeliveryMethodFormView.vue`, `apps/admin/tests`, `apps/admin/CLAUDE.md`, `e2e/tests/admin-screens-discount-codes.spec.ts`, `e2e/tests/admin-screens-promotions.spec.ts`, `e2e/tests/catalog-admin.spec.ts`, `docs/features/admin-ux-fixes`, `docs/features/admin-design/spec.md`, `docs/features/catalog-admin/spec.md`, `docs/features/promotions/spec.md`, `docs/features/discount-codes/spec.md`, `docs/features/delivery/spec.md`

Etap nie zmienia API, `apps/api/openapi*.json` ani `apps/admin/src/api/schema.d.ts`.

## Decyzje etapu 2

Właściciel zaakceptował 2026-11-23:

| # | Decyzja | Przyjęty wariant | Odrzucone |
|---|---|---|---|
| E1 | Ochrona zmian w formularzu kodu i produktu | Blokada pól na czas żądania, jak D3 | Zachowywanie zmian wpisanych w trakcie żądania |
| E2 | Zapis, na który nie ma wiarygodnej odpowiedzi (zmienia D7 dla czterech formularzy) | Stan „nieznany wynik” (B4): szkic zostaje, pracownik sam sprawdza stan albo odrzuca zmiany | Sam komunikat bez akcji; D7 bez zmian |
| E3 | Limity | 10 s dla każdego odczytu, który blokuje formularz, i dla komunikatu o zapisie w toku; jedna stała | Dłuższy limit dla zapisu |
| E4 | #866, punkt 3 (zapis „Od teraz” z odpowiedzią `active`) | Bez zmian: toast wystarcza | Dodatkowe ogłoszenie |
| E5 | Okna zamówienia przy zapisie bez odpowiedzi | Poza etapem; D7 obowiązuje dla nich dalej | Włączenie do etapu |
| E6 | Co jest nieznanym wynikiem | Brak odpowiedzi w limicie, błąd sieci i każda odpowiedź 5xx | Tylko 502 i 504 |
| E7 | 409 po zapisie | W czterech formularzach bez automatycznego odczytu: komunikat, szkic zostaje, stan z serwera pokazuje dopiero akcja pracownika | Tylko po nieznanym wyniku |
| E8 | Spóźniony sukces wcześniejszego zapisu | Toast informacyjny, bez wpływu na formularz | Pomijanie bez informacji |

## Zasady wspólne

- **Szkic zmienia tylko pracownik.** Szkic to wartości pól formularza; jego stan bazowy to wersja i wartości, z którymi
  formularz porównuje `dirty`. Żaden odczyt, 409 ani spóźniona odpowiedź nie zastępuje szkicu, stanu bazowego ani
  wersji poza przypadkami nazwanymi niżej: potwierdzony sukces zapisu, zgodność przy „Sprawdź stan” i udany odczyt po
  „Odrzuć moje zmiany i pokaż stan z serwera”.
- **Blokada.** W trakcie żądania, którego odpowiedź może zastąpić formularz, pola i kontrolki formularza są wyłączone
  (`disabled`; wzór: `fieldset` w `DeliveryMethodFormView.vue`), a akcje zapisu i statusu nieaktywne. W promocji karta
  „Warianty” jest poza `fieldset` (regiony przewijania tabel muszą zostać fokusowalne dla axe), a jej kontrolki są
  wyłączane po jednej. „Zapisz” paska zapisu jest wtedy nieaktywny
  przez `aria-disabled`, nie `disabled` (jak przycisk `ConfirmDialog`): przycisk, który pracownik nacisnął, zachowuje
  fokus do odpowiedzi, a kolejne naciśnięcie niczego nie wysyła.
- **Limit odczytu.** Każdy odczyt, który blokuje formularz, kończy się najpóźniej po 10 s: blokada znika, a odpowiedź,
  która wróci później, jest pomijana.
- **Opuszczenie w trakcie zapisu.** Przejście do innej trasy (także przyciskiem „Wstecz” przeglądarki) w trakcie zapisu
  pyta o niezapisane zmiany jak zawsze: szkic jest niezapisany do odpowiedzi. „Zostań” zostawia formularz z blokadą,
  a odpowiedź jest zwykłym wynikiem zapisu; „Opuść bez zapisu” opuszcza formularz, a odpowiedź jest pomijana (bez
  toastu i nawigacji).
- **Bez automatycznego zapisu.** Zapis wysyła wyłącznie „Zapisz” (albo akcja statusu) pracownika; odczyt ani przyjęcie
  wersji niczego nie wysyłają.
- **Nieaktualne odpowiedzi.** Odpowiedź żądania wysłanego dla innego obiektu albo przed opuszczeniem formularza jest
  pomijana: nie zmienia formularza, nie pokazuje toastu ani komunikatu, nie nawiguje i nie zdejmuje blokady nowszego
  żądania (także w `finally`).
- Wspólna logika (limit, klasyfikacja wyniku, spóźniona informacja) jest jednym kodem w `apps/admin/src/composables`,
  bez kopii w formularzach.

## B1: kod rabatowy (#837)

- Od wysłania zapisu do jego wyniku wszystkie pola są wyłączone; drugie „Zapisz” nie wysyła żądania.
- Po 422 i po nieznanym wyniku pola mają wartości, które pracownik wysłał.
- Odpowiedź zapisu, która wraca po przejściu do innego kodu albo formularza nowego, jest pomijana, nie tylko po
  opuszczeniu widoku.

## B2: produkt (#837)

- Na czas zapisu, publikacji, wycofania i odczytu stanu z serwera (B3) wyłączone są pola, wybór kategorii, edytor
  wariantów i zdjęcia (opis, kolejność, usuwanie, dodawanie plików); „Zapisz”, „Opublikuj” i „Wycofaj” są nieaktywne
  także w trakcie odczytu. Stan z serwera pokazuje w produkcie przycisk „Odrzuć moje zmiany i pokaż stan z serwera”
  (po 409 `product-modified`, B4); osobnego „Odśwież” produkt nie ma.
- Karta „Ceny i stany” nie jest blokowana: ma własne żądania i nie należy do szkicu.
- Odpowiedź zapisu po przejściu do innego produktu jest pomijana.

## B3: odczyty „Odśwież” (#866)

- Dotyczy każdego odczytu wywołanego przez pracownika, który zastępuje formularz: „Odśwież” promocji (po nieudanym
  odczycie początku albo końca) i odczyty B4 („Sprawdź stan”, „Odrzuć moje zmiany i pokaż stan z serwera”, także
  w produkcie).
- W trakcie odczytu region `role="status"` formularza pokazuje „Odświeżamy…”.
- Po 10 s bez odpowiedzi: blokada znika, a komunikat błędu (`role="alert"`) „Nie udało się odczytać stanu. Spróbuj
  ponownie.” ma przycisk ponowienia. Formularz zostaje taki, jaki był przed odczytem. To samo po błędzie sieci
  i odpowiedzi innej niż 200. W promocji nieudane „Odśwież” nie zastępuje formularza komunikatem wczytania.
- #866, punkt 3: bez zmian (E4).

## B4: nieznany wynik zapisu

Dotyczy zapisu czterech formularzy oraz publikacji i wycofania produktu. Akcje „Zakończ teraz” i „Usuń” promocji
zostają jak po etapie 1.

**Wynik zapisu.**
- Potwierdzony sukces: odpowiedź 2xx z danymi. Formularz pokazuje obiekt z odpowiedzi.
- Potwierdzona odmowa: odpowiedź 4xx. 422 pokazuje błędy przy polach, 403 i 404 swoje komunikaty; 409 według E7: komunikat z `detail` albo, dla wersji, komunikat że zmiany zostają w formularzu (bez zapowiedzi pokazania stanu z serwera), szkic, stan bazowy
  i wersja zostają, bez odczytu. Przy 409 wersji (i każdym innym 409, który mówi, że obiekt na serwerze jest inny,
  np. `promotion-not-editable`) komunikat ma przycisk „Odrzuć moje zmiany i pokaż stan z serwera”.
- Nieznany wynik: brak odpowiedzi przez 10 s, błąd sieci albo odpowiedź 5xx.

**Stan „nieznany wynik”.** Komunikat (`role="alert"`, z fokusem): „Nie wiemy, czy zapis się powiódł. Twoje zmiany
zostają w formularzu.”
- Żądanie trwa (minął limit): pola zostają zablokowane, żądanie nie jest przerywane, a odpowiedź, która wróci, działa
  jak zwykły wynik. Komunikat ma przycisk „Sprawdź stan” (edycja) albo „Odblokuj formularz” (nowy obiekt).
- Żądanie się skończyło (błąd sieci, 5xx): pola są odblokowane ze szkicem; ten sam komunikat, w edycji ze „Sprawdź
  stan”.
- Po „Sprawdź stan” albo „Odblokuj formularz” odpowiedź wcześniejszego zapisu jest spóźniona (niżej).

**„Sprawdź stan”** (edycja): odczyt obiektu według B3, pola zablokowane na jego czas.
- Zgodność: żądanie zapisu zbudowane z aktualnego szkicu jest równe żądaniu zbudowanemu tą samą funkcją formularza
  z odpowiedzi odczytu, bez `version`. Porównanie jest ścisłe, pole po polu; daty jako momenty w czasie; listy (osie
  i wiersze wariantów, zdjęcia z opisami, kategorie dodatkowe, warianty promocji) w kolejności szkicu; wiersz wariantu
  bez identyfikatora po wartościach i tylko z wierszem serwera, którego `variantId` nie występował w stanie bazowym
  (wariant utworzony na serwerze): wiersz usunięty i dodany od nowa z tą samą wartością zostaje przy starym
  identyfikatorze na serwerze, który nie wykonał zapisu, więc nie jest zgodny. Nie ma zgodności, gdy ze szkicu nie da się zbudować żądania, gdy szkic zawiera
  wartość nadawaną przez serwer (początek „Od teraz”, pusty slug) albo gdy nieznany wynik dotyczy publikacji lub
  wycofania, a status w odpowiedzi nie jest docelowy. Każda inna różnica, także przycięte spacje, to brak zgodności.
  Porównywany jest szkic z chwili odpowiedzi odczytu, nie treść wysłanego zapisu: zmiana wpisana po odblokowaniu,
  której serwer nie ma, daje brak zgodności.
- Zgodne: formularz przyjmuje obiekt z odpowiedzi (wersja, status, stan bazowy), komunikat nieznanego wyniku znika,
  a region statusu pokazuje „Stan na serwerze odpowiada formularzowi.”
  do pierwszej zmiany szkicu przez pracownika (komunikat znika wtedy bez przenoszenia fokusu).
- Niezgodne, wersja jak w stanie bazowym: szkic, stan bazowy i wersja zostają, pola odblokowane; komunikat „Zapisu
  nie widać na serwerze. Może jeszcze dotrzeć.”.
- Niezgodne, wersja inna: szkic, stan bazowy i wersja zostają, pola odblokowane; komunikat „Na serwerze jest inna
  wersja niż ta, którą edytujesz.” z przyciskiem „Odrzuć moje zmiany i pokaż stan z serwera”.
- Odczyt nieudany (błąd sieci, odpowiedź inna niż 200, limit): pola odblokowane, szkic bez zmian, komunikat nieznanego
  wyniku zostaje ze „Sprawdź stan”.

**„Odrzuć moje zmiany i pokaż stan z serwera”.** Najpierw odczyt według B3; szkic jest zastępowany dopiero po
odpowiedzi 200. Po nieudanym odczycie szkic i komunikaty zostają, pola się odblokowują, przycisk jest dostępny
ponownie.

**Nowy obiekt.** „Odblokuj formularz” (albo koniec żądania błędem sieci lub 5xx) zostawia szkic w odblokowanych polach
z komunikatem „Nie wiemy, czy <obiekt> został utworzony. Wcześniejszy zapis może się jeszcze wykonać. Sprawdź listę
przed ponownym zapisem.” i linkiem do listy (nowa karta). Gdy kolejny zapis dostanie 409 pola unikalnego albo
`promotion-overlap`, komunikat dodaje „Możliwe, że utworzył go Twój wcześniejszy zapis.”.

**„Odrzuć” szkic** (przycisk paska zapisu) przywraca stan bazowy i usuwa tylko to, co po odrzuceniu przestaje być prawdą;
wiedza o serwerze zostaje.
- Nieznany wynik zostaje ze „Sprawdź stan”, bez zdania „Twoje zmiany zostają w formularzu.” (komunikat „Nie wiemy, czy
  zapis się powiódł.”); „Sprawdź stan” porównuje wtedy przywrócony stan bazowy z serwerem. Wynik „Sprawdź stan”
  (brak zapisu na serwerze, inna wersja) zostaje.
- Po 409 komunikat 409 znika razem ze szkicem, ale zostaje droga do stanu z serwera: przycisk „Odrzuć moje zmiany i pokaż
  stan z serwera” z komunikatem, co ten 409 mówi o serwerze: dla 409 wersji „Na serwerze jest inna wersja niż ta, którą
  edytujesz.”, dla innego 409 (np. `promotion-not-editable`) jego `detail`. Po takim 409 nic nie jest nieznane, więc nie
  ma „Sprawdź stan”. Komunikat dostaje fokus. Przycisk ma zawsze tę samą etykietę, także gdy szkic jest czysty: etykieta
  zależna od stanu mogłaby zastąpić zmiany wpisane po odrzuceniu bez zapowiedzi.
- Spóźniona odpowiedź zapisu nadal tylko toast, bez wpływu na pola.

**Spóźniona odpowiedź wcześniejszego zapisu** (po „Sprawdź stan”, „Odblokuj formularz”, po nowszym zapisie albo po
odblokowaniu z innego powodu): nie zmienia pól, wersji, stanu bazowego ani `dirty`, nie czyści błędów i komunikatów
nowszego żądania, nie zdejmuje jego blokady i nie nawiguje. Sukces pokazuje toast „Wcześniejszy zapis został
wykonany.”, tylko gdy formularz nadal pokazuje ten sam obiekt (dla nowego: ten sam formularz nowego obiektu); odmowa
i błąd są pomijane bez komunikatu.

**Ograniczenia bez zmian API.**
- Klient API panelu (`apps/admin/src/api/client.ts`) nie ma limitu czasu ani przerywania żądań; limit 10 s jest
  w formularzach. Edycja wysyła `version` (409 przy nieaktualnej). Utworzenie nie ma klucza idempotencji; API odrzuca
  tylko duplikat pola unikalnego (kod, nazwa metody, slug) albo promocję o okresie nakładającym się na inną ze wspólnym
  wariantem.
- Panel nie rozstrzyga losu żądania, na które nie dostał odpowiedzi; lista też nie, bo żądanie może się wykonać po jej
  sprawdzeniu.
- Po nieznanym wyniku utworzenia pracownik może zmienić kod, nazwę, slug albo zakres promocji i zapisać ponownie:
  mogą powstać dwa obiekty. Panel temu nie zapobiega; mówi o tym komunikat. Rozwiązaniem jest klucz idempotencji
  w API panelu (poza etapem).
- Szkicu, którego wersja jest nieaktualna, nie da się zapisać (409); panel nie scala zmian. Szkic zostaje widoczny do
  świadomego odrzucenia.

## Kryteria etapu 2

Testy Vitest używają wstrzymanych obietnic mocka i fałszywego zegara. „Każdy formularz” to produkt, kod rabatowy,
promocja i metoda dostawy.

1. **B1, B2.** Dla każdego żądania, które może zastąpić formularz (zapis; w produkcie także publikacja, wycofanie
   i odczyt stanu z serwera): przy wstrzymanej odpowiedzi pola i kontrolki są wyłączone, wpisywanie niczego nie zmienia, drugie
   „Zapisz” nie wysyła żądania; po 422 pola mają wysłane wartości i są odblokowane. Nie przechodzi przed zmianą dla
   kodu i produktu.
2. **B3.** Odczyt bez odpowiedzi: po 10 s blokada znika i widać komunikat z ponowieniem; odpowiedź zwolniona potem,
   także po zmianie pola, niczego nie zmienia. W trakcie odczytu „Odświeżamy…” jest w `role="status"`.
3. **B4, każdy formularz.**
   (a) Limit 10 s zapisu: komunikat, pola zablokowane; zwolniony sukces daje zwykły wynik.
   (b) Błąd sieci, 502 i 504: nieznany wynik, pola odblokowane ze szkicem; 422 daje błędy przy polach bez tego
   komunikatu.
   (c) „Sprawdź stan”: zgodne; niezgodne z tą samą wersją; niezgodne z inną wersją; błąd sieci; limit. Szkic, stan
   bazowy i wersja zmieniają się tylko przy zgodności; po zgodności nie wychodzi żadne `PUT` ani `POST`.
   (d) Zgodność: różnica jednego pola, kolejności listy i przyciętej spacji daje brak zgodności; „Od teraz” (promocja)
   i pusty slug (produkt) nigdy jej nie dają; publikacja ze zgodnymi polami i statusem `draft` jej nie daje.
   (e) Szkic zmieniony po odblokowaniu: zapis A z nazwą „Pierwsza” kończy się nieznanym wynikiem, pracownik wpisuje
   „Druga”, „Sprawdź stan” zwraca „Pierwsza”: pole ma „Druga”, `dirty` jest prawdą, wersja i stan bazowy bez zmian,
   nie ma komunikatu o zgodności i nie wychodzi żadne `PUT` ani `POST`.
   (f) Sekwencja: zapis A w toku, limit, „Sprawdź stan” zwraca starą wersję, pracownik zmienia pole, A wykonuje się,
   zapis B dostaje 409: zmienione pole zostaje, widać komunikat i „Odrzuć moje zmiany…”, nie ma automatycznego `GET`.
   (g) „Odrzuć moje zmiany…”: szkic zostaje do odpowiedzi 200 oraz po błędzie i po limicie odczytu.
   (h) Wyścig: odpowiedź A wraca w trakcie „Sprawdź stan”, przed i po odpowiedzi odczytu: szkic zostaje, blokada nie
   zostaje w żadnej kolejności.
   (i) Spóźniony błąd i zakończenie A po starcie B nie zdejmują blokady B i nie pokazują komunikatu A.
   (j) Spóźniony sukces A: po sukcesie B; po 422 B (błędy pól B zostają); w trakcie B (blokada B zostaje); po przejściu
   do innego obiektu (bez toastu). W każdym przypadku pola, wersja i `dirty` są jak przed odpowiedzią A.
   (k) Przejście do innego obiektu w trakcie zapisu i w trakcie odczytu: odpowiedzi pomijane, bez toastu, nawigacji
   i blokady.
   (l) Nowy obiekt: „Odblokuj formularz”, zmiana pola unikalnego, spóźniony sukces A nie zmienia pól ani trasy
   i pokazuje toast; 409 unikalności po nieznanym wyniku ma dopisane zdanie.
4. **E2E** (`admin-screens-discount-codes.spec.ts`, `catalog-admin.spec.ts`; `page.route`, odpowiedzi zapisu
   podstawiane albo na obiekcie testu): (a) kod rabatowy i (b) produkt: odpowiedź zapisu wstrzymana o 3 s, w tym czasie
   pole jest wyłączone, po odpowiedzi toast; (c) produkt: wstrzymany odczyt „Odrzuć moje zmiany i pokaż stan z serwera” po 409 blokuje pola; (d) kod rabatowy:
   zapis bez odpowiedzi daje po 10 s komunikat, „Sprawdź stan” odblokowuje pola ze szkicem, po zmianie pola zapis
   z odpowiedzią 409 zostawia zmianę w polu. Testy (a)–(d) nie przechodzą przed zmianą. (e) kod rabatowy:
   „Wstecz” przeglądarki w trakcie zapisu (zasada „Opuszczenie w trakcie zapisu”, oba wyjścia z okna). (f) promocja
   przy 375 px w trakcie zapisu: axe bez naruszeń (regiony przewijania tabel wariantów zostają fokusowalne).
5. Każde zadanie pokazuje komendy i wyniki RED i GREEN. Przechodzą `make -C apps/admin check`,
   `make -C apps/admin test` i `make e2e`. `git diff` względem `main` nie zawiera `apps/api`,
   `apps/api/openapi*.json` ani `apps/admin/src/api/schema.d.ts`.

## Poza zakresem etapu 2

- Okna zamówienia przy zapisie bez odpowiedzi (E5); okna kategorii, cen i stanów (przycisk `disabled`, okno zamykalne
  w trakcie zapisu; z kodu, nieodtwarzane).
- Klucz idempotencji w API panelu; scalanie szkicu z nowszą wersją.
- Akcje „Zakończ teraz” i „Usuń” promocji; karta „Ceny i stany” produktu; wysyłanie zdjęć.
- Pozostałe pozycje #778: długie teksty (#573, #574, #628, #630, #581 F3), #629, #576, #581 F6–F8 i F11, reszta #584,
  #867.

## Równoległość z epikiem spójności koszyka i zamówienia

Stan na 2026-11-23: gałąź `claude/cart-order-consistency-epic-d2fbe5` nie ma commitów ponad `main` ani specyfikacji.
**Warunek:** przed równoległą implementacją sesja prowadząca porównuje jej `Zakres:` z tą specyfikacją.

- **Kod.** Etap zmienia tylko `apps/admin`; zgłoszenia, które tamten epik najpewniej obejmie (#853, #865, #643,
  #887), dotyczą `apps/store` i `apps/api` (Cart, Ordering).
- **Kontrakt.** Etap nie zmienia specyfikacji OpenAPI ani klienta panelu; zmiana schematów zamówienia po tamtej
  stronie przegeneruje `openapi-admin.json` i `schema.d.ts` bez konfliktu z plikami tego etapu.
- **E2E.** Etap zmienia `admin-screens-discount-codes.spec.ts` i `catalog-admin.spec.ts`; nie zmienia `e2e/support`
  ani wag części.
- **Wspólna zasada.** Brak odpowiedzi nie oznacza braku zapisu i komunikat nigdy nie twierdzi, że zapis się nie
  powiódł. Sklep ponawia złożenie zamówienia z tym samym `Idempotency-Key` (#865); panel nie ma klucza i dlatego
  niczego nie ponawia sam. Limit 10 s (E3) warto przyjąć w obu epikach.
