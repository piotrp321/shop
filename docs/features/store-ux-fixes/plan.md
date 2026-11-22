# STORE-UX-FIXES – plan zadań

Specyfikacja: `docs/features/store-ux-fixes/spec.md`. Epik prowadzi główna sesja Claude Code z natywnymi subagentami.
Epik ma jedną gałąź (poza `epic/*`) z PR-em do `main` otwartym od początku jako szkic. Każde zadanie realizuje
subagent we
własnym worktree na gałęzi zadania; sesja orkiestrująca merguje gotowe gałęzie zadań do gałęzi epiku i wypycha ją.
Zadania nie mają własnych PR. Zadania jednej fali z listą `parallel_with` mogą iść naraz. Plan jest dokumentem
roboczym: po ukończeniu epiku usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | STORE-UX-FIXES-1, STORE-UX-FIXES-4, STORE-UX-FIXES-6, STORE-UX-FIXES-7 | w toku |
| 2 | STORE-UX-FIXES-3, STORE-UX-FIXES-5 | |
| 3 | STORE-UX-FIXES-2 | |
| 4 | STORE-UX-FIXES-8 | |

Kolumnę „Status” (zmergowane do gałęzi epiku, commit) uzupełnia sesja orkiestrująca po merge'u gałęzi zadania.

## Przed startem

Sesja prowadząca sprawdza na aktualnym `main` warunki specyfikacji i zapisuje wynik w opisie PR epiku:

- „Zależność od API-INPUT-CONTRACT”, punkty 1–4 (dotyczą zadań 1, 2, 4 i 5).
- „Równoległość z ADMIN-UX-FIXES (#778)”, punkt „Do ponownego sprawdzenia przed implementacją”: stan ADMIN-UX-FIXES na
  `main`, jego `Zakres:` i `paths` (wspólny z tym planem ma być tylko `.github/workflows/ci.yml`),
  `git diff HEAD..origin/main -- e2e .github make`, kolejność merge'u obu epików.
- Po scaleniu `main` z drugim epikiem: testy łączące sklep z panelem z tej sekcji specyfikacji, potem pełne `make e2e`.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "STORE-UX-FIXES",
  "spec": "docs/features/store-ux-fixes/spec.md",
  "tasks": [
    {
      "id": "STORE-UX-FIXES-1",
      "title": "Koszyk: akcje zlecone w trakcie zapisu nie przepadają",
      "scope": "Sklep, CartPage (spec, S1; decyzja D2; reguła F1). Jedna operacja w drodze, przy pozycji najwyżej jedna akcja czekająca, wysyłane po odpowiedzi w kolejności zlecenia (akcja zastępująca zajmuje miejsce zastąpionej). Kolejka zapisów queueWrite w apps/store/src/api/client.ts bez zmian; bez zmian API. Poza zadaniem: przejście do kasy (zadanie 2), fokus po usunięciu, region statusu i ponowienie wczytania (zadanie 3), wariant kryterium 4 z „Przejdź do kasy” (zadanie 2), limit czasu żądań. E2E: pomocnik wstrzymywania żądań jest lokalny w pliku testu, e2e/support bez zmian.",
      "acceptance_criteria": [
        "Zapis ilości pozycji w drodze: tylko pole tej pozycji jest do odczytu z aria-disabled (jak dziś); pola innych pozycji są edytowalne; „Usuń” tej pozycji działa, a usunięcie wykonuje się po zapisie, także nieudanym. Ilość czekająca na wysłanie: pole pokazuje wpisaną wartość i jest edytowalne, zmiana wpisu porzuca wartość czekającą, nowa idzie po zatwierdzeniu (blur, Enter). Usunięcie zlecone (w drodze albo czeka): pole i „Usuń” pozycji mają aria-disabled (F1: fokus zostaje, bez disabled), kolejna aktywacja „Usuń” nie wysyła żądania.",
        "Odpowiedź operacji innej pozycji nie nadpisuje pola z niezatwierdzonym wpisem albo wartością czekającą; pole wraca do ilości z odpowiedzi tylko po odpowiedzi na własną operację.",
        "Błąd operacji (5xx, sieć, 409, 429): komunikat przy pozycji z dzisiejszymi tekstami, pole wraca do ostatniej potwierdzonej ilości, akcje czekające innych pozycji wykonują się dalej. 401 odpowiedzi operacji albo ponownego odczytu koszyka po 404: akcje czekające są porzucane bez wysłania, a strona przechodzi w widok wygasłej sesji (loaded.status \"unauthorized\": sam komunikat z linkiem logowania, bez listy pozycji i bez „Przejdź do kasy”). Opuszczenie koszyka nawigacją w sklepie: akcje już zlecone wykonują się do końca, bez nawigacji i komunikatów; po 401 są porzucane.",
        "Zostają reguły: jedna operacja w drodze, „Pozycja została już usunięta” po 404, wpis spoza 1–99 wraca do poprzedniej ilości bez żądania.",
        "Vitest (CartPage.test.tsx, wstrzymane obietnice), na poziomie komponentu: kryteria 1, 2, 3, 4 (bez wariantu z „Przejdź do kasy”), 5, 6 i 7 specyfikacji, z liczbą wysłanych PATCH i DELETE w każdym.",
        "Zmiana istniejącego zachowania: kliknięcie „Usuń” w trakcie zapisu ilości przestaje być ignorowane, a 401 zmiany ilości albo ponownego odczytu po 404 przestaje zostawiać listę pozycji; testy CartPage.test.tsx „keeps the quantity field and Usuń focusable but inactive while the request runs”, „says the session expired on a 401” i „says the session expired when the reload after a 404 gets a 401, with the quantity of the last view” zmieniają się razem z tym zachowaniem. Pozostałe testy koszyka przechodzą bez zmiany asercji.",
        "E2E (e2e/tests/store-ux-cart.spec.ts): kryterium 1 w przeglądarce, mysz przy 1280 px i dotyk przy 375 px, bez opóźnienia i z PATCH wstrzymanym na 3 s przez page.route: wpis 3 w A i od razu „Usuń” A daje A usunięte, B zostaje, jeden PATCH i jeden DELETE; to samo z „Usuń” B daje A = 3 szt. i B usunięte. Istniejące E2E koszyka i przepływy zamówień (cart-guest, cart-variants, delivery-cart, checkout-guest-limits, discount-codes) przechodzą bez zmian, a helpery e2e/support/shop.ts i orders.ts zostają bez zmian.",
        "docs/features/cart/spec.md opisuje akcje w trakcie zapisu (tabela S1), w tym 401 operacji i ponownego odczytu po 404.",
        "Raport zawiera komendy i rzeczywiste wyniki testów przed zmianą (RED: testy Vitest kryteriów 1, 2, 4, 5, 6 i test E2E kryterium 1 nie przechodzą) i po niej (GREEN); make -C apps/store check i make -C apps/store test przechodzą."
      ],
      "risk": "CRITICAL",
      "depends_on": [],
      "paths": [
        "apps/store/src/cart/",
        "e2e/tests/store-ux-cart.spec.ts",
        "docs/features/cart/spec.md"
      ],
      "parallel_with": [
        "STORE-UX-FIXES-4",
        "STORE-UX-FIXES-5",
        "STORE-UX-FIXES-6",
        "STORE-UX-FIXES-7"
      ]
    },
    {
      "id": "STORE-UX-FIXES-2",
      "title": "Koszyk: „Przejdź do kasy” czeka na zapisanie zmian",
      "scope": "Sklep, CartPage: zamiar przejścia do kasy (spec, S2 i wariant kryterium 4 z „Przejdź do kasy”; „Granica ochrony zamówienia”; reguły F1, F2, F5). Używa akcji czekających z zadania 1 i regionu statusu koszyka z zadania 3. Link zostaje linkiem o tej nazwie z href=\"/checkout\" (helpery E2E sklepu i panelu). Poza zadaniem: kasa otwarta inną drogą (nowa karta, Ctrl, Cmd, środkowy przycisk, menu kontekstowe, wstecz i dalej), zmiana koszyka w innej karcie, serializacja placeOrder z zapisami koszyka (D9, #853), limit czasu żądań; bez zmian API. E2E: pomocnik wstrzymywania żądań jest lokalny w pliku testu, e2e/support bez zmian.",
      "acceptance_criteria": [
        "„Przejdź do kasy” zostaje elementem o roli link, nazwie „Przejdź do kasy” i href=\"/checkout\". Aktywacja (kliknięcie, dotyk, Enter) najpierw zatwierdza niezatwierdzony wpis w polu ilości z fokusem. Gdy żadna operacja nie trwa i nie czeka, link działa jak dziś. Aktywacja z Ctrl, Cmd albo środkowym przyciskiem zostaje zachowaniem przeglądarki.",
        "Zamiar przejścia (operacje trwają albo czekają): nawigacji nie ma, link ma aria-disabled=\"true\" i zachowuje fokus (F1), region statusu koszyka z zadania 3 mówi „Zapisujemy zmiany w koszyku…” (F5, ten sam węzeł), pola ilości i „Usuń” mają aria-disabled do końca oczekiwania i nie przyjmują edycji, ponowna aktywacja nic nie zmienia.",
        "Wszystkie operacje udane i koszyk da się zamówić: przejście do /checkout. Błąd którejkolwiek operacji anuluje zamiar na stałe (późniejszy sukces innej go nie przywraca): adres zostaje /cart, przy przycisku role=\"alert\" „Nie udało się zapisać zmian w koszyku. Sprawdź pozycje i spróbuj ponownie.”, przy pozycji jej komunikat, fokus według F2 zostaje na linku. 401: widok wygasłej sesji z zadania 1, bez nawigacji. 404 operacji (pozycji już nie ma) też anuluje zamiar: klient zostaje w koszyku z koszykiem z odpowiedzi i tekstem regionu „Pozycja została już usunięta”. Koszyk po zapisach pusty albo z pozycjami nie do kupienia: dzisiejszy widok tych stanów, bez nawigacji; link znika, więc fokus idzie na „Twój koszyk jest pusty” albo, przy pozycjach nie do kupienia, na nagłówek „Koszyk” (F2, F3). Opuszczenie koszyka anuluje zamiar: spóźniona odpowiedź nie nawiguje.",
        "Vitest (CartPage.test.tsx): kryteria 8–11 specyfikacji na poziomie komponentu (wstrzymane obietnice, nawigacja podmieniona w teście) i wariant kryterium 4: PATCH A wstrzymany, B = 2 zatwierdzone, wpis 4 w B bez opuszczania pola, aktywacja „Przejdź do kasy”: żądanie B = 2 nie wychodzi, po zwolnieniu A wychodzi jeden PATCH B = 4, potem przejście do /checkout.",
        "E2E (e2e/tests/store-ux-cart.spec.ts): kryterium 8 trzema sposobami (kliknięcie przy 1280 px, dotyk przy 375 px, Tab i Enter), PATCH wstrzymany na 3 s: adres zostaje /cart, link ma aria-disabled i fokus, region ma tekst, wpis w innym polu i „Usuń” niczego nie zmieniają, druga aktywacja jest bez skutku; po zwolnieniu /checkout pokazuje 3 szt. i kwotę z serwera, wyszedł jeden PATCH, a zamówienie złożone z tej kasy w jednym wariancie (kliknięcie przy 1280 px) ma 3 szt.; komentarz w apps/api/fixtures/stock.yaml (lista testów E2E, które zamawiają produkt) wymienia ten test, i to jest jedyna zmiana w apps/api. Kryterium 11: w trakcie oczekiwania przejście linkiem nagłówka na /; po zwolnieniu adres zostaje /, serwer ma A = 3, a B jest usunięte.",
        "Komunikat przy przycisku i tekst regionu nie powodują przewijania poziomego przy 320 i 375 px (E2E).",
        "Istniejące testy, które klikają „Przejdź do kasy” (CartPage.test.tsx „links to the checkout…”, E2E checkout-guest, checkout-customer, delivery-checkout, discount-codes, delivery-cart, checkout-guest-limits i łańcuch placeAndPayOrder w e2e/support/orders.ts), przechodzą bez zmian; helpery e2e/support zostają bez zmian.",
        "docs/features/cart/spec.md opisuje przejście do kasy (S2) z granicą z „Granica ochrony zamówienia”.",
        "Raport zawiera komendy i rzeczywiste wyniki testów przed zmianą (RED: testy Vitest kryteriów 8–11 i wariantu kryterium 4 oraz test E2E kryterium 8 nie przechodzą) i po niej (GREEN); make -C apps/store check i make -C apps/store test przechodzą."
      ],
      "risk": "CRITICAL",
      "depends_on": [
        "STORE-UX-FIXES-1",
        "STORE-UX-FIXES-3"
      ],
      "paths": [
        "apps/store/src/cart/",
        "apps/api/fixtures/stock.yaml",
        "e2e/tests/store-ux-cart.spec.ts",
        "docs/features/cart/spec.md"
      ],
      "parallel_with": [
        "STORE-UX-FIXES-4",
        "STORE-UX-FIXES-5",
        "STORE-UX-FIXES-6",
        "STORE-UX-FIXES-7"
      ]
    },
    {
      "id": "STORE-UX-FIXES-3",
      "title": "Koszyk: fokus po usunięciu i ponowieniu wczytania, trwały region statusu",
      "scope": "Sklep, CartPage, na stanie akcji pozycji z zadania 1: region statusu koszyka, fokus po usunięciu pozycji i „Spróbuj ponownie” po nieudanym wczytaniu (spec, S3; #392, #445; reguły F1, F2, F3, F5; kryterium 24 dla koszyka). Region ma data-testid i jest jedynym regionem tego etapu na ekranie koszyka; tekst „Zapisujemy zmiany w koszyku…” dopisuje zadanie 2. Region dostawy (cart-delivery) bez zmian. Bez zmian API. E2E: pomocnik wstrzymywania żądań jest lokalny w pliku testu, e2e/support bez zmian.",
      "acceptance_criteria": [
        "F5: jeden region role=\"status\" z data-testid istnieje od pierwszego renderu przez wczytywanie, błąd wczytania, treść, pusty koszyk i widok wygasłej sesji, zmienia się tylko jego tekst, jest widoczny i jest jedynym miejscem swojego tekstu w DOM; tekst zostaje do startu następnej operacji ekranu, która go czyści albo zastępuje. Teksty: „Wczytywanie koszyka…”, „Usunięto z koszyka: <nazwa pozycji>.” (nazwa z etykietą wariantu, jak w etykiecie pola ilości; pozycja bez nazwy: „Usunięto pozycję z koszyka.”), „Pozycja została już usunięta” (zamiast osobnego Alert role=\"status\"). Region dostawy cart-delivery zostaje bez zmian; błędy zostają w role=\"alert\".",
        "Po usunięciu pozycji (F2, F3): fokus na „Usuń” następnej pozycji; gdy usunięto ostatnią na liście, na „Usuń” poprzedniej; gdy koszyk jest pusty, na tekst „Twój koszyk jest pusty”, który występuje w DOM raz. Po nieudanym usunięciu wiersz i przycisk zostają, fokus zostaje. Fokus przenosi się tylko wtedy, gdy w chwili odpowiedzi jest na „Usuń”, z którego wyszła akcja, albo na body; przeniesiony w trakcie na inną kontrolkę zostaje tam, także gdy usunięcie czekało na wysłanie (zlecone w trakcie zapisu innej pozycji, zadanie 1).",
        "„Spróbuj ponownie” zostaje na ekranie na czas ponowienia z aria-disabled zamiast zniknięcia (F1), drugi Enter nie wysyła żądania, region ma „Wczytywanie koszyka…”, a komunikat błędu (role=\"alert\", przycisk poza nim) na ten czas znika. Sukces: fokus na nagłówek „Koszyk”. Ponowny błąd: fokus zostaje na przycisku, komunikat pojawia się znowu. Fokus przeniesiony w trakcie: bez zmiany.",
        "Vitest (CartPage.test.tsx): kryterium 12 specyfikacji (usunięcie jednej z kilku, ostatniej na liście i jedynej, DELETE wstrzymany, fokus przeniesiony w trakcie, usunięcie czekające z fokusem przeniesionym) i logika kryterium 13. Zmiana istniejącego zachowania: „Spróbuj ponownie” nie znika w trakcie ponowienia, a „Pozycja została już usunięta” jest tekstem regionu; testy „offers a retry when the cart cannot be loaded…”, „reloads the cart and says the line is gone on a 404” i „says it is loading until the cart arrives” zmieniają się razem z tym zachowaniem. Testy regionu dostawy przechodzą bez zmian.",
        "E2E (e2e/tests/store-ux-cart.spec.ts): kryterium 13 (GET /api/cart kończy się 500 przez page.route, Enter na „Spróbuj ponownie”, odpowiedź wstrzymana: przycisk na ekranie z fokusem, drugi Enter bez żądania, region ma tekst; sukces: fokus na „Koszyk”; ponowny błąd: fokus na przycisku; fokus przeniesiony: bez zmiany); usunięcie klawiaturą jednej z kilku pozycji i jedynej (kryterium 12); kryterium 24 dla koszyka: węzeł regionu pobrany w trakcie wczytywania jest po wczytaniu treści i po opróżnieniu koszyka tym samym podłączonym węzłem ze zmienionym tekstem.",
        "Tekst usunięcia w regionie nie powoduje przewijania poziomego przy 320 i 375 px (E2E).",
        "„Twój koszyk jest pusty” występuje w DOM raz, a w main koszyka nie przybywa elementów listy: helper emptyCart (e2e/support/shop.ts) i istniejące E2E koszyka (cart-guest, delivery-cart, checkout-guest, payment-resume) przechodzą bez zmian.",
        "docs/features/cart/spec.md opisuje fokus po usunięciu i po ponowieniu wczytania oraz region statusu (S3).",
        "Raport zawiera komendy i rzeczywiste wyniki testów przed zmianą (RED: testy Vitest kryterium 12 i testy E2E kryteriów 13 i 24 nie przechodzą) i po niej (GREEN); make -C apps/store check i make -C apps/store test przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "STORE-UX-FIXES-1"
      ],
      "paths": [
        "apps/store/src/cart/",
        "e2e/tests/store-ux-cart.spec.ts",
        "docs/features/cart/spec.md"
      ],
      "parallel_with": [
        "STORE-UX-FIXES-4",
        "STORE-UX-FIXES-5",
        "STORE-UX-FIXES-6",
        "STORE-UX-FIXES-7"
      ]
    },
    {
      "id": "STORE-UX-FIXES-4",
      "title": "Kasa: fokus po ponowieniu wczytania, region statusu i link logowania według przełączników",
      "scope": "Sklep, CheckoutPage: region statusu kasy, „Spróbuj ponownie” i link „Masz konto? Zaloguj się” (spec, S4; #445, #293; decyzja D4; reguły F1, F2, F3, F5; kryterium 24 dla kasy). Przełączniki z istniejącego useSiteSwitches (apps/store/src/components/SiteSwitches.tsx bez zmian), istniejące /api/site. Tekst „Składamy zamówienie…” dopisuje zadanie 5. Poza zadaniem: /konto/zamowienia przy wyłączonych kontach (#848), linki wyjścia z kasy w trakcie składania zamówienia, blokada formularza (zadanie 5); bez zmian API. E2E: pomocnik wstrzymywania żądań jest lokalny w pliku testu, e2e/support bez zmian.",
      "acceptance_criteria": [
        "F5: jeden region role=\"status\" kasy z data-testid istnieje od pierwszego renderu przez wczytywanie, błąd wczytania, treść, pusty koszyk i wygasłą sesję, zmienia się tylko jego tekst, jest widoczny i jest jedynym miejscem swojego tekstu w DOM; pokazuje „Wczytywanie…”. Regiony checkout-summary-status i checkout-code-status zostają bez zmian; błędy zostają w role=\"alert\".",
        "„Spróbuj ponownie” po nieudanym wczytaniu (błąd /api/cart albo /auth/session) zostaje na ekranie na czas ponowienia z aria-disabled (F1), drugi Enter nie wysyła żądania, region ma „Wczytywanie…”, a komunikat błędu (role=\"alert\", przycisk poza nim) na ten czas znika. Sukces: fokus na nagłówek „Kasa”. Ponowny błąd: fokus zostaje na przycisku, komunikat pojawia się znowu. Fokus przeniesiony w trakcie: bez zmiany (F2).",
        "„Masz konto? Zaloguj się” pokazuje się dopiero po odczycie przełączników (useSiteSwitches, loaded) i tylko przy customerAccounts true; błąd odczytu /api/site daje stan domyślny, czyli link, jak w nagłówku.",
        "Vitest (CheckoutPage.test.tsx): logika kryterium 14 dla obu błędów i kryterium 15: przy customerAccounts false brak linku przed odpowiedzią (wstrzymaną) i po niej, przy true link jest, przy błędzie odczytu link jest. Zmiana istniejącego zachowania: „Spróbuj ponownie” nie znika w trakcie ponowienia; test „offers a retry when %s cannot be loaded” zmienia się razem z nim. Test „asks a guest for the e-mail and offers the login…” przechodzi bez zmian.",
        "E2E (e2e/tests/store-ux-checkout.spec.ts): kryterium 14 osobno dla błędu /api/cart i /auth/session (page.route, odpowiedź ponowienia wstrzymana), cel po sukcesie „Kasa”; kryterium 24 dla kasy: węzeł regionu pobrany w trakcie wczytywania jest po wczytaniu treści tym samym podłączonym węzłem; kryterium 15 dla customerAccounts false podstawionego przez page.route na /api/site (odpowiedź wstrzymana, potem zwolniona): w kasie gościa nie ma „Zaloguj się” ani przed odpowiedzią, ani po niej. checkout-guest.spec.ts (link logowania w kasie przy kontach włączonych na stosie) przechodzi bez zmian.",
        "Tekst regionu nie powoduje przewijania poziomego przy 320 i 375 px (E2E).",
        "docs/features/checkout/spec.md opisuje ponowienie wczytania i link logowania według przełączników (S4).",
        "Raport zawiera komendy i rzeczywiste wyniki testów przed zmianą (RED: testy E2E kryteriów 14, 15 i 24 oraz test Vitest kryterium 15 nie przechodzą) i po niej (GREEN); make -C apps/store check i make -C apps/store test przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/store/src/checkout/CheckoutPage.tsx",
        "apps/store/src/checkout/CheckoutPage.test.tsx",
        "e2e/tests/store-ux-checkout.spec.ts",
        "docs/features/checkout/spec.md"
      ],
      "parallel_with": [
        "STORE-UX-FIXES-1",
        "STORE-UX-FIXES-2",
        "STORE-UX-FIXES-3",
        "STORE-UX-FIXES-6",
        "STORE-UX-FIXES-7"
      ]
    },
    {
      "id": "STORE-UX-FIXES-5",
      "title": "Kasa: blokada formularza w trakcie składania zamówienia, fokus po odrzuceniu, oba błędy wyboru",
      "scope": "Sklep, CheckoutPage: składanie zamówienia (spec, S5; część #345; decyzje D6, D7; reguły F1, F2, F3, F5) i zdanie o fokusie po odrzuceniu w apps/store/CLAUDE.md („Kasa”). Tekst „Składamy zamówienie…” idzie do regionu statusu kasy z zadania 4. Mapowanie błędów (showFailure, problems.ts) i odpowiedzi API bez zmian. Poza zadaniem: błędy pól tym samym kliknięciem co błędy wyboru (etap 2 API-INPUT-CONTRACT), walidacja pól w sklepie, limit czasu żądań, link „Zaloguj się” i inne linki wyjścia w trakcie składania zamówienia. E2E: pomocnik wstrzymywania żądań jest lokalny w pliku testu, e2e/support bez zmian.",
      "acceptance_criteria": [
        "Blokada od wysłania do odpowiedzi z błędem albo do nawigacji po sukcesie: pola tekstowe formularza i pole kodu rabatowego nie zmieniają wartości, mają aria-disabled=\"true\", wygląd nieaktywny i zachowują fokus; wybór dostawy i płatności nie zmienia się po kliknięciu, dotyku, strzałkach ani Spacji i ma aria-disabled=\"true\" bez disabled; „Zastosuj” i „Usuń kod” nie wysyłają żądania i zachowują fokus (aria-disabled zamiast disabled); przycisk „Zamawiam z obowiązkiem zapłaty” według F1 (aria-disabled, fokus zostaje); region statusu kasy mówi „Składamy zamówienie…”; Enter w dowolnym polu, kliknięcie i Spacja na przycisku dają jedno żądanie. W trakcie sprawdzania kodu rabatowego „Zastosuj”, „Usuń kod” i przycisk zamówienia też mają aria-disabled zamiast disabled i nie wysyłają żądania; reszta zachowania kodu zostaje. Przycisk zamówienia przy braku sposobów dostawy zostaje disabled.",
        "Po błędzie kontrolki są odblokowane i wartości pól zostają. Po 500 i 422 dostawa, płatność i kod są bez zmian. Po 409 dostawa, kod i podsumowanie zmieniają się tylko według docs/features/checkout/spec.md dla danego powodu (odrzucony kod jest zdejmowany, niedostępna dostawa przestaje być wybrana, zmiana kwoty odświeża podsumowanie). Po sukcesie blokada trwa do nawigacji.",
        "Fokus po odrzuceniu (F2, F3): cel jak dziś (komunikat; po 422 pierwsze błędne pole; dla odrzuconego kodu pole kodu), tylko gdy fokus został na elemencie, z którego wyszło wysłanie (przycisk albo pole z Enterem), albo jest na body; przeniesiony w trakcie zostaje tam, a komunikat jest w role=\"alert\". To samo po odpowiedzi sprawdzenia kodu („Zastosuj”, „Usuń kod”): fokus idzie na dzisiejszy cel (pole kodu albo „Usuń kod”) pod warunkiem F2.",
        "Błędy wyboru (#345): bez wyboru dostawy i płatności jedno wysłanie pokazuje „Wybierz sposób dostawy.” i „Wybierz sposób płatności.”, obie grupy mają aria-invalid i aria-describedby, fokus idzie na pierwszą opcję pierwszej grupy z błędem, żądanie nie wychodzi. Gdy brakuje jednego wyboru, zachowanie jak dziś.",
        "Vitest (CheckoutPage.test.tsx, wstrzymana odpowiedź placeOrder): logika kryteriów 16–20 specyfikacji, w tym warunek F2 dla wysłania przyciskiem i Enterem z pola „Miejscowość” po 422 i po 500. Zmiana istniejącego zachowania: przycisk zamówienia w trakcie żądania i po sukcesie ma aria-disabled zamiast disabled, a fokus po odrzuceniu przenosi się warunkowo; testy „locks the button while the request runs”, „goes to the page of the provider when the order has an address, and keeps the button locked”, „locks the code controls and the order button while a code is being checked” i testy fokusu po odrzuceniu zmieniają się razem z tym zachowaniem. Blokada przycisku bez sposobów dostawy zostaje jak dziś i jej test przechodzi bez zmian.",
        "E2E (e2e/tests/store-ux-checkout.spec.ts): kryterium 16 (POST /api/checkout wstrzymany na 3 s przez page.route: wpisywanie w pola i w pole kodu nie zmienia wartości, dostawa i płatność nie zmieniają się myszą, dotykiem, strzałkami ani Spacją, „Zastosuj” nie wysyła żądania, przycisk ma fokus i aria-disabled, region ma tekst, jeden POST); kryterium 18 dla Entera z pola z przejściem Tab w trakcie; kryterium 20 przy 375 px (element z fokusem jest w widoku). delivery-checkout.spec.ts (błąd wyboru dostawy i fokus na pierwszej opcji) i checkout-guest-limits.spec.ts przechodzą bez zmian.",
        "„Składamy zamówienie…” i oba komunikaty wyboru nie powodują przewijania poziomego przy 320 i 375 px (E2E).",
        "docs/features/checkout/spec.md opisuje blokadę, fokus po odrzuceniu i błędy wyboru (S5); w apps/store/CLAUDE.md („Kasa”) poprawione jest tylko zdanie o fokusie po odrzuceniu („bo przycisk jest zablokowany na czas żądania”), bez opisu działania.",
        "Raport zawiera komendy i rzeczywiste wyniki testów przed zmianą (RED: test E2E kryterium 16 i testy Vitest kryteriów 16–20 nie przechodzą) i po niej (GREEN); make -C apps/store check i make -C apps/store test przechodzą."
      ],
      "risk": "CRITICAL",
      "depends_on": [
        "STORE-UX-FIXES-4"
      ],
      "paths": [
        "apps/store/src/checkout/CheckoutPage.tsx",
        "apps/store/src/checkout/CheckoutPage.test.tsx",
        "e2e/tests/store-ux-checkout.spec.ts",
        "docs/features/checkout/spec.md",
        "apps/store/CLAUDE.md"
      ],
      "parallel_with": [
        "STORE-UX-FIXES-1",
        "STORE-UX-FIXES-2",
        "STORE-UX-FIXES-3",
        "STORE-UX-FIXES-6",
        "STORE-UX-FIXES-7"
      ]
    },
    {
      "id": "STORE-UX-FIXES-6",
      "title": "Potwierdzenie płatności: „Sprawdź ponownie” bez utraty fokusu, trwały region statusu",
      "scope": "Sklep, PaymentConfirmation (spec, S6; #210, #211; reguły F1, F2, F3, F4, F5; kryterium 24 dla potwierdzenia). Strona potwierdzenia (Server Component) i odczyt statusu bez zmian. Poza zadaniem: przycisk „Zapłać” i jego blokada, odświeżanie co 2 s przez 30 s (bez zmiany czasu i warunków), bez zmian API. E2E: pomocnik wstrzymywania żądań jest lokalny w pliku testu, e2e/support bez zmian.",
      "acceptance_criteria": [
        "„Sprawdź ponownie” ma na czas odczytu aria-disabled zamiast disabled i zachowuje fokus, drugi Enter nie wysyła żądania (F1). Przycisk po odpowiedzi nadal jest na ekranie: fokus zostaje na nim. Przycisku po odpowiedzi nie ma (status rozstrzygnięty albo znów trwa odpytywanie): fokus na nagłówek ekranu. Błąd odczytu: przycisk i fokus zostają, „Nie udało się sprawdzić statusu płatności.” jest w role=\"alert\". W widoku bez statusu (pierwszy odczyt nieudany) to zdanie jest poza regionem, w role=\"alert\": znika na czas sprawdzania i pojawia się znowu po ponownym błędzie; przycisk i fokus zostają. Fokus przeniesiony w trakcie: bez zmiany (F2).",
        "Zdania statusu płatności („Płatność przyjęta. Potwierdzenie wysłaliśmy na adres …”, „Zamówienie zostało anulowane.”, „Opłać do …”, „Czekamy na potwierdzenie płatności…”, „Nie mamy jeszcze potwierdzenia płatności.”, „Minął termin płatności…”, „Płatność nie powiodła się.”) są w jednym regionie role=\"status\" z data-testid, który istnieje od pierwszego renderu przez wszystkie zmiany statusu, zmienia się tylko jego tekst, jest widoczny i jest jedynym miejscem tych zdań w DOM (F5). Przyciski, link „Wróć do płatności” i formularz płatności są poza nim; kolejność zdań i kontrolek zmienia się tylko w tej sekcji ekranu.",
        "Zmiana statusu z odpytywania zmienia tekst regionu i nie przenosi fokusu; wyjątek: gdy zmiana usuwa element z fokusem, fokus idzie na nagłówek ekranu (F4).",
        "Vitest (PaymentConfirmation.test.tsx, fałszywe zegary): kryterium 22 specyfikacji (oczekuje → opłacone, oczekuje → płatność nieudana, koniec okna odpytywania: fokus ustawiony wcześniej na innym elemencie zostaje, „Płatność przyjęta.” występuje w DOM raz) i logika kryterium 21. Zmiana istniejącego zachowania: zdania statusu przechodzą do regionu, a „Sprawdź ponownie” nie dostaje disabled; testy PaymentConfirmation.test.tsx i apps/store/app/checkout/confirmation/page.test.tsx, które sprawdzają te zdania i ten przycisk, zmieniają się razem z tym zachowaniem tylko w selektorach, bez zmiany tekstów.",
        "E2E (e2e/tests/store-ux-payment.spec.ts): kryterium 21 (Enter na „Sprawdź ponownie”, odczyt wstrzymany przez page.route: przycisk ma fokus, drugi Enter bez żądania; przycisk zostaje: fokus na nim; przycisku nie ma: fokus na nagłówku; 500: fokus na przycisku i komunikat; fokus przeniesiony: bez zmiany); kryterium 24 dla potwierdzenia: węzeł regionu pobrany przed zmianą statusu płatności jest po niej tym samym podłączonym węzłem ze zmienionym tekstem. Istniejące E2E płatności (payment-resume, checkout-guest, checkout-customer, delivery-checkout, discount-codes) i helper placeAndPayOrder (e2e/support/orders.ts) przechodzą bez zmian.",
        "Region i komunikat błędu odczytu nie powodują przewijania poziomego przy 320 i 375 px (E2E).",
        "docs/features/payment/spec.md opisuje „Sprawdź ponownie” i region statusu (S6).",
        "Raport zawiera komendy i rzeczywiste wyniki testów przed zmianą (RED: test E2E kryterium 21 w przeglądarce, test E2E kryterium 24 i testy Vitest kryterium 22 nie przechodzą) i po niej (GREEN); make -C apps/store check i make -C apps/store test przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/store/src/checkout/PaymentConfirmation.tsx",
        "apps/store/src/checkout/PaymentConfirmation.test.tsx",
        "apps/store/app/checkout/confirmation/page.test.tsx",
        "e2e/tests/store-ux-payment.spec.ts",
        "docs/features/payment/spec.md"
      ],
      "parallel_with": [
        "STORE-UX-FIXES-1",
        "STORE-UX-FIXES-2",
        "STORE-UX-FIXES-3",
        "STORE-UX-FIXES-4",
        "STORE-UX-FIXES-5",
        "STORE-UX-FIXES-7"
      ]
    },
    {
      "id": "STORE-UX-FIXES-7",
      "title": "Test regresyjny #390 i etykieta sumy w specyfikacji kodów rabatowych (#335)",
      "scope": "Test E2E utrwalający zachowanie „Do koszyka” (spec, S7; #390) i poprawka zdania o etykiecie sumy w docs/features/discount-codes/spec.md (spec, S7; #335; decyzja D5). Kod sklepu bez zmian: AddToCartButton ma już aria-disabled, a etykieta „Do zapłaty (brutto)” zostaje. E2E: pomocnik wstrzymywania żądań jest lokalny w pliku testu, e2e/support bez zmian.",
      "acceptance_criteria": [
        "E2E (e2e/tests/store-ux-product.spec.ts, osobny plik: karta produktu i kafelek, nie koszyk): Enter i Spacja na „Do koszyka” na karcie produktu i na kafelku listingu, POST /api/cart/items wstrzymany na 3 s przez page.route: fokus na przycisku w trakcie i po odpowiedzi, wychodzi jeden POST (kryterium 23).",
        "docs/features/discount-codes/spec.md nazywa sumę kasy „Do zapłaty” zamiast „Razem” w obu miejscach, jak specyfikacje kasy i dostawy; etykieta w kodzie („Do zapłaty (brutto)”) i jej testy bez zmian.",
        "Zadanie nie zmienia zachowania, więc istniejące testy przechodzą bez zmian. Raport zawiera komendę i rzeczywisty wynik nowego testu E2E i pisze wprost, że test przechodzi także przed zmianą (#390 się nie odtwarza, test jest strażnikiem regresji, bez fazy RED); make -C apps/store check i make -C apps/store test przechodzą."
      ],
      "risk": "FAST",
      "depends_on": [],
      "paths": [
        "e2e/tests/store-ux-product.spec.ts",
        "docs/features/discount-codes/spec.md"
      ],
      "parallel_with": [
        "STORE-UX-FIXES-1",
        "STORE-UX-FIXES-2",
        "STORE-UX-FIXES-3",
        "STORE-UX-FIXES-4",
        "STORE-UX-FIXES-5",
        "STORE-UX-FIXES-6"
      ]
    },
    {
      "id": "STORE-UX-FIXES-8",
      "title": "Granice części E2E po dodanych testach i kryteria epiku",
      "scope": "CI: E2E_SHARD_WEIGHTS i komentarz kroku make e2e w .github/workflows/ci.yml, przeliczone według e2e/CLAUDE.md po testach zadań 1–7, na stanie po scaleniu aktualnego main (spec, „Kryteria akceptacji epiku”, punkty 27–29; „Równoległość z ADMIN-UX-FIXES”). Gdy ADMIN-UX-FIXES zmergował się wcześniej, wagi są liczone od nowa na połączonym zestawie testów, bez ręcznego scalania liczb przy konflikcie.",
      "acceptance_criteria": [
        "E2E_SHARD_WEIGHTS i komentarz kroku make e2e odpowiadają liczbie testów po zadaniach 1–7 na stanie po scaleniu aktualnego main; raport pokazuje wynik komendy listującej testy części z e2e/CLAUDE.md dla każdej z trzech części.",
        "make -C apps/store check, make -C apps/store test i STORE_MODE=production make e2e przechodzą na gałęzi epiku.",
        "git diff względem main nie zawiera apps/api/openapi*.json, apps/store/src/api/schema.d.ts ani apps/admin, a z apps/api tylko komentarz w apps/api/fixtures/stock.yaml."
      ],
      "risk": "FAST",
      "depends_on": [
        "STORE-UX-FIXES-1",
        "STORE-UX-FIXES-2",
        "STORE-UX-FIXES-3",
        "STORE-UX-FIXES-4",
        "STORE-UX-FIXES-5",
        "STORE-UX-FIXES-6",
        "STORE-UX-FIXES-7"
      ],
      "paths": [
        ".github/workflows/ci.yml"
      ],
      "parallel_with": []
    }
  ]
}
```
