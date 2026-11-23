# CART-ORDER-CONSISTENCY: spójność koszyka i zamówienia

Issues: #853, #852

Zgłoszenia: #853 (kasa nie wykrywa zmiany zawartości koszyka przy tej samej kwocie), #852 (komunikat po 409 nie mówi,
co się zmieniło). Specyfikacja opisuje stan docelowy epiku; kasę i koszyk opisują `docs/features/checkout/spec.md`
i `docs/features/cart/spec.md`, które zadania epiku aktualizują razem z kodem.

## Cel

Klient zamawia dokładnie to, co kasa pokazała mu w chwili kliknięcia „Zamawiam z obowiązkiem zapłaty”: te same
pozycje, ilości, kwotę i tego samego kupującego. Gdy stan na serwerze jest inny (zmiana w innej karcie, przeglądarce
albo na innym urządzeniu, zmiana ceny, logowanie w innej karcie), zamówienie nie powstaje, a kasa pokazuje, co się
zmieniło, i aktualne podsumowanie. Nic nie zamawia się samo po raz drugi: klient klika ponownie.

## Odtworzenie na `main`

2026-11-23, commit `00f1fe6f`, lokalny stos w trybie produkcyjnym z danymi seed, Chromium (Playwright, 1280 px), gość,
produkt „Buty trailowe Norvane Hala 500” (warianty po 549,00 zł), dostawa „Kurier standardowy”, płatność „Szybki
przelew (demo)”. Dwie karty jednego kontekstu przeglądarki; zmiany w drugiej karcie wykonane żądaniami do BFF koszyka.

| # | Przebieg | Wynik (pomiar) |
|---|---|---|
| R1 (#853 a) | Kasa wczytana z „czarne, 40” × 2 i „czarne, 41” × 1 (1 647,00 zł). Druga karta zamienia ilości na 1 i 2. Pierwsza karta wysyła zamówienie. | `POST /api/checkout` → 201. E-mail zamówienia R-100001: „czarne, 40 × 1”, „czarne, 41 × 2”, 1 647,00 zł. Zamówienie różni się od pokazanego w kasie, bez komunikatu. |
| R2 (#852) | Kasa wczytana z 1 szt. (549,00 zł). Druga karta zmienia ilość na 3. Pierwsza karta wysyła zamówienie. | 409 `order-total-changed`. Jedyny komunikat: „Kwota zamówienia się zmieniła. Sprawdź podsumowanie.”, bez linku. Podsumowanie po odczycie: 3 szt., 1 647,00 zł. Region statusu podsumowania pusty. Fokus na komunikacie. |
| R3 (#853 b, c) | Na stronie koszyka wpis 3 (było 1), PATCH wstrzymany w drodze (karta trzyma blokadę `store-cart-write`). Druga karta otwiera kasę (1 szt.) i wysyła zamówienie. Potem PATCH zostaje zwolniony. | Zamówienie 201 z 1 szt., zgodne z kasą. Spóźniony PATCH: 404 „Tej pozycji nie ma w koszyku.”; strona koszyka: „Twój koszyk jest pusty”, „Pozycja została już usunięta”. |
| R4 | Kasa gościa wczytana i wypełniona (e-mail `repro-r4+…@example.com`). Druga karta loguje klienta Jan. Pierwsza karta wysyła zamówienie. | 201. Zamówienie R-100004 jest zamówieniem konta: potwierdzenie przyszło na adres konta, a nie na adres wpisany w kasie. Bez komunikatu. |

Wnioski:

- R1 i R4: zamówienie inne niż pokazane w kasie. To luka, którą epik zamyka.
- R2: zamówienie nie powstaje, ale komunikat nie mówi, co się zmieniło.
- R3: zamówienie jest zgodne z kasą. Niezapisana zmiana z innej karty przepada z komunikatem, który nie mówi o
  zamówieniu. Epik zostawia ten przebieg (decyzja D4).

Nie sprawdzano: innej przeglądarki i innego urządzenia (ten sam mechanizm po stronie API co druga karta), Firefoksa
i Safari, zalogowanego klienta w R1–R3, wylogowania w innej karcie i zmiany konta A na konto B. Dwa ostatnie to
wniosek z kodu: `runCheckout` wybiera kupującego wyłącznie po ciasteczku sesji w chwili wysłania, więc zamówienie
kasy konta A wysłane przy sesji konta B byłoby zamówieniem konta B, a kasa klienta wysłana bez sesji poszłaby ścieżką
gościa (dziś kończy się 422 o brakującym e-mailu).

## Decyzje

Właściciel zaakceptował 2026-11-23 (D6 po poprawce o zmianę konta A na konto B).

| # | Decyzja | Przyjęty wariant | Odrzucone |
|---|---|---|---|
| D1 | Jak API poznaje zawartość pokazaną w kasie | Żądanie niesie `expectedLines`: listę `{variantId, quantity}` z podsumowania, które widział klient. API tylko ją porównuje z zablokowanym koszykiem, jak `expectedTotalGross` (P2); zamówienie nadal powstaje wyłącznie z koszyka. Nowy value object `ExpectedLines` w domenie Ordering. | (b) Znacznik zawartości liczony przez Cart, zwracany w `CartView` i odsyłany przez kasę: mniejsze żądanie i mniej walidacji, ale nowe pojęcie w Cart i w każdej odpowiedzi koszyka. (c) Licznik wersji koszyka w bazie: migracja i fałszywe konflikty (zmiana tam i z powrotem). |
| D2 | Co jest porównywane | Pozycje i ilości (`expectedLines`) oraz kwota (`expectedTotalGross`, bez zmian). | Dodatkowo wartość każdej linii. Zamyka przypadek dwóch zmian cen, które się znoszą przy tych samych ilościach, kosztem drugiej formuły wartości linii w Ordering obok wyceny Pricing. |
| D3 | Zgodność wstecz | `expectedLines` jest wymagane; żądanie bez niego dostaje 422. Wszystkie wywołania (sklep, k6, test dymny wdrożenia) zmieniają się w epiku. Etap demo dopuszcza zmianę niezgodną z poprzednią wersją. | Pole opcjonalne: stara kasa działa dalej bez ochrony. |
| D4 | Kolejność zamówienia względem zapisów koszyka z innych kart | Bez zmian: `placeOrder` nie bierze blokady `store-cart-write`. Gwarancję daje porównanie po stronie API. Przebieg R3 zostaje opisaną granicą. | `placeOrder` pod blokadą: zapis będący w drodze w innej karcie tej przeglądarki dociera przed zamówieniem i daje 409 z listą zmian. Koszt: zapis bez odpowiedzi w innej karcie wstrzymuje zamówienie (żądania nie mają limitu czasu, #865). |
| D5 | Skąd komunikat wie, co się zmieniło | Kasa po 409 czyta koszyk i wypisuje różnice między podsumowaniem pokazanym a nowym (dwa widoki z API; sklep niczego nie liczy, tylko porównuje pokazane wartości). Komunikat ma link „Przejdź do koszyka”. | API zwraca listę różnic w odpowiedzi 409: wymaga nazw produktów w Ordering przed wyceną i drugiego formatu problemu. |
| D6 | Zmiana kupującego w innej karcie (R4): logowanie, wylogowanie, zmiana konta A na konto B | W zakresie, tylko sklep, bez zmiany API. `GET /auth/session` zwraca znacznik kupującego: HMAC z `sub` sesji (`SessionData.sub`), bez identyfikatora sesji, tokenów i samego `sub`. Kasa odsyła znacznik, który dostała przy wczytaniu (albo „gość”), a BFF porównuje go z `sub` sesji z chwili wysłania i przy różnicy odrzuca zamówienie przed scaleniem koszyka i przed API. Znacznik służy tylko do porównania: konto zamówienia nadal wynika wyłącznie z uwierzytelnionej sesji. Odświeżenie tokenu nie zmienia `sub`, więc nie daje konfliktu. | (a) Tylko rodzaj kupującego (gość albo klient): nie wykrywa zmiany konta A na B. (b) Porównanie adresu e-mail konta: dodatkowy odczyt `/api/v1/me` przy każdym zamówieniu. (c) Osobne zgłoszenie poza epikiem. |
| D7 | Odświeżanie kasy po powrocie do karty | Poza epikiem: rozbieżność wychodzi przy wysłaniu. | Ponowny odczyt przy `visibilitychange` i `pageshow`: wcześniejsza informacja, ale odczyt podmienia podsumowanie w trakcie wypełniania formularza. |
| D8 | Miejsce porównania w `PlaceOrder` | Po `CartEmpty`, przed limitem zamówienia gościa, limitem nieopłaconych zamówień i wyceną. Klient najpierw dowiaduje się, że koszyk jest inny. | Po wycenie, razem z kwotą. |

## Zakres

- **Z1. API: porównanie zawartości.** `POST /api/v1/orders` i `POST /api/v1/me/orders` przyjmują `expectedLines`
  i odrzucają zamówienie, gdy koszyk ma inne pozycje albo ilości.
- **Z2. Sklep: kasa wysyła pokazaną zawartość** i obsługuje nowe odrzucenie.
- **Z3. Sklep: komunikat rozbieżności (#852)** dla zmiany zawartości i zmiany kwoty.
- **Z4. Sklep: zmiana kupującego (R4).**
- **Z5. Pozostałe wywołania składania zamówienia:** `load/lib/flows.js` (k6, `make load-smoke` w CI) i
  `infra/k8s/smoke/checks.py` (test dymny wdrożenia) z testem `infra/k8s/tests/test_smoke_messaging.py`.

Poza zakresem:

- Blokada `placeOrder` z zapisami koszyka (D4) i odświeżanie kasy po powrocie do karty (D7).
- Limit czasu żądań koszyka i kasy, linki wyjścia z kasy w trakcie składania zamówienia (#865).
- Dwie zmiany cen, które się znoszą przy tych samych ilościach i tej samej kwocie (D2).
- Komunikat strony koszyka w przebiegu R3 („Pozycja została już usunięta” po zamówieniu z innej karty).
- Panel administracyjny i `apps/api/openapi-admin.json`.

## Z1. API

### Kontrakt

`PlaceGuestOrderRequest` i `PlaceCustomerOrderRequest` dostają wymagane pole:

```json
"expectedLines": [{ "variantId": "2ee34480-d689-5319-8c53-756d2adcb889", "quantity": 2 }]
```

- Lista 1–50 elementów (`Cart::MAX_LINES`). `variantId`: UUID w dowolnej wielkości liter. `quantity`: liczba
  całkowita 1–99 (`Cart::MAX_QUANTITY`). Kolejność elementów nie ma znaczenia.
- Naruszenia dają 422 z polem i komunikatem po polsku, nigdy 500 (Lista jakości, „Błędy API”):

  | Dane | `propertyPath` | `title` |
  |---|---|---|
  | brak klucza, `null` | `expectedLines` | „Kasa została zaktualizowana. Odśwież stronę.” |
  | nie lista, lista pusta, ponad 50 elementów | `expectedLines` | „Nieprawidłowa lista pozycji zamówienia. Odśwież stronę.” |
  | element nie jest obiektem; brak albo zły typ `variantId`; `variantId` spoza UUID | `expectedLines[n]` albo `expectedLines[n].variantId` | „Nieprawidłowa lista pozycji zamówienia. Odśwież stronę.” |
  | brak, zły typ (tekst, ułamek, `true`), 0, liczba ujemna albo ponad 99 w `quantity` | `expectedLines[n].quantity` | „Nieprawidłowa lista pozycji zamówienia. Odśwież stronę.” |
  | ten sam `variantId` dwa razy, także różniący się wielkością liter | `expectedLines` | „Nieprawidłowa lista pozycji zamówienia. Odśwież stronę.” |

- Nowy typ 409 w `OrderConflictProblem`: `tag:piotrp321.github.io,2026:shop/problems/cart-changed`, `detail`
  „Zawartość koszyka się zmieniła. Sprawdź podsumowanie.”. Odpowiedź ma tylko `type` i `detail`.
- `apps/api/openapi.json` i klient TS sklepu są wygenerowane w tym samym commicie; `openapi-admin.json` się nie
  zmienia.

### Złożenie zamówienia

- `PlaceOrder` niesie `expectedLines`; domena ma value object `ExpectedLines` (unikalne warianty małymi literami,
  ilości 1–99, 1–50 linii; porównanie z liniami koszyka jako zbiorami par `variantId` i `quantity`).
- Nowy krok po kroku 3 (`CartEmpty`), przed limitem zamówienia gościa, limitem nieopłaconych zamówień i wyceną: linie
  zablokowanego koszyka inne niż `expectedLines` → `CartContentChanged` (409 `cart-changed`). Transakcja kończy się
  bez zapisu: koszyk bez zmian, bez numeru z sekwencji, bez rezerwacji, bez użycia kodu, bez roszczenia limitu.
- Porównanie działa pod blokadą koszyka z kroku 1, więc żadna zmiana koszyka nie wchodzi między porównanie
  a opróżnienie koszyka. Kolejność blokad się nie zmienia.
- Pusty koszyk daje jak dziś `CartEmpty` (krok 3 jest przed porównaniem).
- Odcisk żądania (`RequestFingerprint`) obejmuje `expectedLines` w postaci kanonicznej (posortowane po `variantId`).
  Ten sam klucz z inną listą to inne żądanie (`IdempotencyKeyReused`). Powtórzenie z tym samym kluczem i tą samą
  treścią zwraca zamówienie jak dziś, także po opróżnieniu koszyka (kroki 0 i 2 są przed porównaniem).
- `expectedLines` służy wyłącznie do porównania i nie trafia do zamówienia: linie, ilości i ceny pochodzą z koszyka
  i wyceny jak dziś.

## Z2 i Z3. Kasa

- Kasa wysyła `expectedLines` z linii podsumowania, które pokazuje (`variantId` i `quantity` z `CartView`), razem
  z `expectedTotalGross` wybranej opcji dostawy. BFF przekazuje pole do API bez zmian (`pickFields`).
- `cart_changed` to nowa przyczyna 409 w `src/checkout/problems.ts` (mapa sprawdzana kompilatorem względem schematu).

Po 409 `cart-changed` albo `order-total-changed`:

1. Formularz zostaje zablokowany jak w trakcie składania zamówienia (pola, dostawa, płatność, kod), a region statusu
   mówi dalej „Składamy zamówienie…”. Kasa czyta koszyk z tym samym kodem rabatowym.
2. Po odczycie, w jednym renderze: komunikat `role="alert"` z listą zmian, podsumowanie z serwera i odblokowany
   formularz. Fokus idzie na komunikat według reguły F2 (`focusStayed`). Dane formularza, wybór płatności i dostawy
   (gdy metoda jest nadal dostępna) zostają. Następne kliknięcie to nowa próba z nowym `Idempotency-Key`.
3. Koszyk pusty albo z pozycjami nie do kupienia po odczycie: dzisiejszy widok tych stanów zamiast formularza.
4. 401 odczytu: dzisiejszy widok wygasłej sesji.
5. Odczyt nieudany: komunikat „Koszyk albo kwota zamówienia się zmieniły, ale nie udało się wczytać aktualnego
   podsumowania. Spróbuj ponownie.”. Podsumowanie jest oznaczone jako nieaktualne: następne kliknięcie „Zamawiam
   z obowiązkiem zapłaty” najpierw czyta koszyk i pokazuje zmiany (punkt 2), a niczego nie zamawia.

Komunikat:

- Pierwszy akapit: „Zawartość koszyka zmieniła się od wczytania kasy. Nie złożyliśmy zamówienia.” (`cart-changed`)
  albo „Kwota zamówienia zmieniła się od wczytania kasy. Nie złożyliśmy zamówienia.” (`order-total-changed`).
- Lista zmian, po jednej pozycji na zmianę, w kolejności podsumowania; `<nazwa>` to nazwa produktu z wariantem:

  | Zmiana między podsumowaniem pokazanym a nowym | Tekst |
  |---|---|
  | inna ilość | „<nazwa>: było 2 szt., jest 1 szt.” |
  | pozycji już nie ma | „<nazwa>: usunięto z koszyka” |
  | nowa pozycja | „<nazwa>: dodano, 1 szt.” |
  | inna wartość linii przy tej samej ilości | „<nazwa>: wartość była 549,00 zł, jest 499,00 zł” |
  | inny rabat kodu | „Rabat: był 50,00 zł, jest 0,00 zł” |
  | inny koszt wybranej dostawy | „Dostawa „<metoda>”: była 0,00 zł, jest 14,99 zł” |
  | wybrana metoda dostawy zniknęła | „Dostawa „<metoda>” nie jest już dostępna. Wybierz sposób dostawy.” |
  | inna kwota do zapłaty (wybrana dostawa w obu podsumowaniach) | „Do zapłaty: było 549,00 zł, jest 1 647,00 zł” |

- Ostatni akapit: „Sprawdź podsumowanie i zamów ponownie.” i link „Przejdź do koszyka”.
- Gdy porównanie nie znajduje żadnej różnicy (stan wrócił do pokazanego między odrzuceniem a odczytem): tylko
  pierwszy i ostatni akapit.
- Kwoty formatuje `formatPrice`. Różnice wyznacza czysta funkcja z dwóch `CartView` i id wybranej metody; sklep
  porównuje wartości, które pokazał, i niczego nie liczy.
- Region statusu podsumowania (`checkout-summary-status`) ogłasza dostawę jak dziś (`deliveryAnnouncement`).
- Komunikat nie powoduje przewijania poziomego przy 320 i 375 px, także z najdłuższą nazwą produktu danych demo.

Pozostałe odrzucenia (kod rabatowy, niedobór, limit, klucz, metoda dostawy) działają jak dziś.

## Z4. Zmiana kupującego

Kupujący to gość albo konkretne konto klienta (`sub` sesji sklepu). Zamówienie powstaje tylko dla kupującego, którego
kasa pokazała.

### Znacznik kupującego

- `GET /auth/session` dla zalogowanego klienta zwraca obok `name` i `email` pole `buyer`: HMAC-SHA256 z tekstu
  `checkout-buyer:<sub>` kluczem `STORE_SESSION_SECRET`, w base64url. `sub` pochodzi z `SessionData` tej samej sesji,
  z której pochodzi token użyty do odczytu `/api/v1/me`. Gość dostaje jak dziś `null`.
- Znacznik nie jest danymi uwierzytelniającymi: niczego nie otwiera i nie zastępuje sesji. Odpowiedź nie zawiera
  identyfikatora sesji, tokenów ani `sub`. Ten sam `sub` daje zawsze ten sam znacznik, także po odświeżeniu tokenu
  i w nowej sesji tego samego konta, więc ponowne logowanie na to samo konto nie daje konfliktu.
- Kasa wysyła do BFF pole `buyer`: `"guest"`, gdy pokazuje pole e-mail, albo znacznik z odczytu sesji, z którego
  pochodzi pokazany adres konta. BFF nie przekazuje pola do API.

### Sprawdzenie w BFF

`runCheckout` czyta sesję raz (ciasteczko, potem `SessionData`), porównuje znacznik w stałym czasie i dopiero potem
scala koszyk gościa i woła API tokenem tej samej sesji. „Ta sama sesja” oznacza zgodność tożsamości: `sub` użyty do
porównania i `sub` tokenu, którym BFF woła API, to ten sam `sub`. Mechanizm odświeżania tokenów (`freshAccessToken`)
zostaje bez zmian i może czytać Redis ponownie; gdy po odświeżeniu `sub` sesji jest inny niż porównany, BFF odpowiada
409 `checkout-buyer-changed` bez wywołania API.

| Kasa pokazała (`buyer`) | Sesja przy wysłaniu | Odpowiedź BFF | Kasa |
|---|---|---|---|
| gość | brak ciasteczka sesji | zamówienie gościa jak dziś | bez zmian |
| gość | sesja klienta | 409 `checkout-buyer-changed`, bez scalania i bez API | ponowny odczyt i komunikat (niżej) |
| konto A | sesja konta A (także po odświeżeniu tokenu albo ponownym logowaniu na A) | zamówienie konta A jak dziś | bez zmian |
| konto A | sesja konta B | 409 `checkout-buyer-changed`, bez scalania i bez API | ponowny odczyt i komunikat (niżej) |
| konto A | brak ciasteczka sesji, zły podpis, sesji nie ma w Redisie albo tokenu nie da się odświeżyć | 401 (sesja usunięta jak dziś), bez API; nigdy zamówienie gościa, także gdy przeglądarka ma ciasteczko koszyka gościa i żądanie niesie pole `email` | dzisiejszy widok wygasłej sesji z linkiem logowania |
| dowolne | sesji nie da się odczytać (Redis albo Keycloak niedostępny) | 503, sesja zostaje, bez API i bez przejścia do zamówienia gościa | „Nie udało się złożyć zamówienia. Spróbuj ponownie.”; formularz i klucz zostają |
| brak pola, nie tekst, pusty tekst | dowolna | 422 „Nieprawidłowe dane.” bez API | komunikat zbiorczy |

`checkout-buyer-changed` to własny typ BFF (jak `cart-empty` bez koszyka); odpowiedź ma tylko `type` i `detail`, bez
adresu i znacznika drugiego konta.

### Kasa po `checkout-buyer-changed`

1. Formularz zostaje zablokowany, region statusu mówi „Składamy zamówienie…”. Kasa czyta od nowa sesję i koszyk.
2. Po odczycie, w jednym renderze: komunikat `role="alert"`, kupujący i podsumowanie z odczytu. Wpisane pola
   (imię i nazwisko, telefon, adres), wybór płatności i dostawy (gdy metoda jest dostępna) zostają; kod rabatowy jest
   czytany od nowa z koszykiem. Pole e-mail pojawia się albo znika według nowej sesji.
3. Tekst: „Od wczytania kasy zmieniło się konto: teraz to <adres konta>. Nie złożyliśmy zamówienia. Zamówienie
   będzie zamówieniem tego konta: sprawdź dane i podsumowanie, potem zamów ponownie.” Gdy po odczycie sesji nie ma
   (wylogowanie po odrzuceniu): „Od wczytania kasy nastąpiło wylogowanie. Nie złożyliśmy zamówienia. Podaj adres
   e-mail, sprawdź dane i podsumowanie, potem zamów ponownie.”
4. Koszyk może być inny (inny klient, scalenie przy logowaniu): kasa pokazuje go bez listy zmian, bo komunikat prosi
   o sprawdzenie podsumowania.
5. Następne kliknięcie to świadome potwierdzenie: nowy `Idempotency-Key`, `buyer` i `expectedLines` z ostatniego
   odczytu. BFF sprawdza je od nowa, więc kolejna zmiana konta między odczytem a wysłaniem daje kolejne 409.
6. Odczyt nieudany: komunikat „Kupujący się zmienił, ale nie udało się wczytać kasy. Spróbuj ponownie.”; kasa jest
   oznaczona jako nieaktualna i następne kliknięcie najpierw czyta sesję i koszyk, a niczego nie zamawia. 401 odczytu
   koszyka: widok wygasłej sesji.

## Granica ochrony zamówienia

Gwarancja: zamówienie powstaje tylko wtedy, gdy koszyk na serwerze ma w chwili złożenia te same pozycje i ilości, co
podsumowanie pokazane w kasie, ta sama jest kwota do zapłaty i ten sam kupujący (gość albo to samo konto). Obejmuje każdą drogę
otwarcia kasy (link, nowa karta, wstecz i dalej) i zmianę z innej karty, przeglądarki albo urządzenia.

Poza gwarancją:

- Zmiana zlecona w innej karcie, która nie dotarła do serwera przed zamówieniem (R3): zamówienie jest zgodne z kasą,
  a spóźniony zapis trafia w opróżniony koszyk (zmiana ilości: 404, usunięcie: 200 bez zmian, dodanie: nowa pozycja w koszyku).
- Dwie zmiany cen, które się znoszą przy tej samej kwocie i tych samych ilościach.
- Przeładowanie albo zamknięcie karty koszyka w trakcie zapisu: akcje jeszcze niewysłane przepadają (bez zmian).

## Testy

### API

- **Jednostkowe (TDD):** `ExpectedLines` (duplikat, wielkość liter, granice 1, 50, 99, kolejność bez znaczenia);
  `PlaceOrderHandler`: porównanie po `CartEmpty` i przed limitami oraz wyceną, brak zapisów po odrzuceniu, odcisk
  z listą (ten sam klucz z inną listą → `IdempotencyKeyReused`, z tą samą → powtórzenie).
- **Integracyjne (PostgreSQL):** przebieg R1 (ilości zamienione przy tej samej kwocie → `CartContentChanged`, koszyk,
  stany, sekwencja kodu i roszczenia bez zmian); powtórzenie po opróżnieniu koszyka.
- **Funkcjonalne:** wiersz dla każdego naruszenia z tabeli Z1 na obu endpointach (`OrderRequestViolationCases`;
  zestaw `InputContract` obejmuje tylko parametry zapytania i nagłówki, nie treść żądania), 409 `cart-changed` dla gościa i klienta, dopasowanie bez względu na wielkość liter
  i kolejność.
- **Współbieżność** (`PlaceOrderConcurrencyTest`, osobne procesy, 5 rund, kontrole po rundzie jak O1–O7):

  | ID | Scenariusz | Oczekiwany wynik |
  |---|---|---|
  | O8 | złożenie z listą sprzed zmiany równolegle ze zmianą ilości tej linii | albo zamówienie z ilością z listy i `CartLineNotFound` zmiany, albo `CartContentChanged`, brak zamówienia i koszyk z nową ilością; nigdy zamówienie z nową ilością |
  | O9 | złożenie równolegle z dodaniem innego wariantu | albo zamówienie z liniami z listy i koszyk z samą nową linią, albo `CartContentChanged` i koszyk z obiema; nigdy zamówienie z dodaną linią ani utracona linia |
  | O10 | złożenie koszyka z dwiema liniami równolegle z usunięciem jednej | albo zamówienie z obiema i pusty koszyk (usunięcie brakującej linii kończy się bez zmian), albo `CartContentChanged` i koszyk z jedną linią |
  | O11 | dwa złożenia z różnymi kluczami z jednego koszyka: lista aktualna i lista nieaktualna | dokładnie jedno zamówienie, z listą aktualną; nieaktualne dostaje `CartContentChanged` albo `CartEmpty` |
  | O5 (zmiana) | złożenie klienta równolegle ze scaleniem koszyka gościa; rundy nieparzyste z listą sprzed scalenia, parzyste z listą po scaleniu | zamówienie dokładnie z listy żądania albo `CartContentChanged` i wszystkie linie w koszyku; żadna linia nie ginie, brak zakleszczeń |
  | O12 | dwa warianty w tej samej cenie, złożenie z listą A × 2, B × 1 równolegle ze zmianą ilości na A × 1, B × 2 (ta sama kwota) | albo zamówienie dokładnie z listy i `CartLineNotFound` zmian, albo `CartContentChanged` i koszyk A × 1, B × 2; nigdy zamówienie A × 1, B × 2 |

  Po każdej rundzie dodatkowo: linie każdego zamówienia testu są równe `expectedLines` żądania, które je złożyło.

### Sklep (Vitest)

- Funkcja różnic: każdy wiersz tabeli zmian, brak różnic, kolejność, pozycja bez nazwy produktu.
- `CheckoutPage` na wstrzymanych obietnicach: 409 → odczyt wstrzymany → formularz zablokowany, komunikatu nie ma,
  region mówi „Składamy zamówienie…”; próba zmiany pola i wyboru w tym czasie nic nie zmienia; po zwolnieniu
  komunikat z listą i nowe podsumowanie są w tym samym renderze. Odczyt nieudany, 401, pusty koszyk, pozycje nie do
  kupienia, kod odrzucony po zmianie koszyka, metoda dostawy niedostępna. Podsumowanie nieaktualne: kliknięcie czyta
  koszyk i nie wysyła zamówienia. Nowy klucz po odrzuceniu. Fokus przeniesiony przez klienta w trakcie zostaje.
- Treść żądania: `expectedLines` równe liniom podsumowania pokazanego, także po zastosowaniu kodu i po odczycie.
- `GET /auth/session`: znacznik jest ten sam dla tego samego `sub` w dwóch sesjach i po odświeżeniu tokenu, inny
  dla innego `sub`; odpowiedź nie zawiera `sub`, identyfikatora sesji ani tokenów; gość dostaje `null`.
- BFF: `expectedLines` przekazane bez zmian; `buyer` nieprzekazany; każdy wiersz tabeli Z4 z liczbą wywołań API
  i scalenia (zero przy 409, 401, 503 i 422). Kasa konta A wysłana przy sesji konta B z tym samym koszykiem i tą samą
  kwotą: 409, koszyk gościa z ciasteczka niescalony. Kasa konta A bez sesji, z ciasteczkiem koszyka gościa i polem
  `email` w żądaniu: 401, bez zamówienia gościa. Odświeżenie tokenu w trakcie żądania: zamówienie konta A.
- Kasa po `checkout-buyer-changed` na wstrzymanych obietnicach: blokada do końca odczytu, komunikat i nowy kupujący
  w jednym renderze, zachowane pola; następne wysłanie niesie nowy znacznik i nowy klucz; drugie 409 po kolejnej
  zmianie konta między odczytem a wysłaniem; odczyt nieudany i kasa nieaktualna (kliknięcie czyta, nie zamawia).

### E2E (`e2e/tests/cart-order-consistency.spec.ts`, dwie karty jednego kontekstu)

- R1: zamówienie nie powstaje, komunikat wymienia obie pozycje, podsumowanie ma nowe ilości; drugie kliknięcie składa
  zamówienie, a e-mail ma ilości z nowego podsumowania.
- R2: w chwili pojawienia się komunikatu podsumowanie pokazuje już 3 szt. i nową kwotę; komunikat ma „było 1 szt.,
  jest 3 szt.”, „Do zapłaty: było …, jest …” i link do koszyka. Także przy 375 px z dotykiem, bez przewijania poziomego.
- R3 (granica): zamówienie zgodne z kasą, spóźniony zapis nie zmienia zamówienia.
- R4: logowanie w drugiej karcie → brak zamówienia, komunikat z adresem konta, formularz bez pola e-mail z zachowanymi
  danymi; drugie kliknięcie składa zamówienie konta.
- R5: kasa konta A (Jan), w drugiej karcie wylogowanie i logowanie na konto B z tymi samymi pozycjami i tą samą
  kwotą w koszyku → brak zamówienia na obu kontach (brak e-maila zamówienia, koszyk B bez zmian), komunikat z adresem
  konta B, ponowny odczyt; drugie kliknięcie składa zamówienie konta B z potwierdzeniem na adres B.
- R6: kasa konta A, w drugiej karcie wylogowanie → brak zamówienia, widok wygasłej sesji, żadnego zamówienia gościa.
- Istniejące przepływy zamówień (`checkout-guest`, `checkout-customer`, `delivery-checkout`, `discount-codes`,
  `store-ux-checkout`, `placeAndPayOrder` w `e2e/support/orders.ts`) przechodzą bez zmiany asercji i helperów.

### Pozostałe wywołania

- `make load-smoke` i testy `infra/k8s` (`make -C infra/k8s check`) przechodzą; ścieżka błędu: żądanie bez
  `expectedLines` daje 422, a skrypt kończy się błędem z tym statusem.

## Zależności i równoległość

- **API-INPUT-CONTRACT:** naruszenia nowego pola są w `OrderRequestViolationCases` (treść żądania); zestaw
  `tests/Functional/Shared/InputContract` nie obejmuje treści żądań i dostaje tylko poprawną listę w ciałach swoich
  wierszy. Kolejny etap tego epiku, który zmieni te pliki, uwzględnia nowe pole.
- **STORE-UX-FIXES etap 1** jest na `main`: reguły F1, F2 i blokada formularza w trakcie zamówienia (S5) obowiązują
  i ten epik je rozszerza o czas odczytu po 409.
- **ADMIN-UX-FIXES etap 2** (przygotowywany równolegle; zakres według komentarza w #778: #573, #574, #576, #581
  F3/F6/F7/F8/F11, #584, #628, #629, #630, #866, #867; specyfikacji i planu nie ma jeszcze w repo). Epiki nie mają
  wspólnego kodu aplikacji: ten zmienia `apps/api` (Ordering) i `apps/store`, tamten `apps/admin`. Punkty styku:

  | Plik albo kontrakt | Ten epik | ADMIN-UX-FIXES etap 2 | Ustalenie |
  |---|---|---|---|
  | `e2e/support/orders.ts`, `shop.ts`, `panel-orders.ts` | nie zmienia | testy panelu składają nimi zamówienia przez kasę sklepu | nazwy „Przejdź do kasy” i „Zamawiam z obowiązkiem zapłaty”, pola kasy i przebieg bez rozbieżności zostają bez zmian |
  | `apps/api/fixtures/stock.yaml` (stany i lista testów E2E, które zamawiają produkt) | dopisuje nowe testy do komentarza | może dopisać testy okien zamówień (#581) | nowe testy tego epiku zamawiają „Buty trailowe Norvane Hala 500” (50 szt. na wariant); konflikt tylko w komentarzu |
  | `apps/api/openapi.json`, `apps/store/src/api/schema.d.ts` | zmienia | nie powinien zmieniać | gdyby etap 2 zmieniał API, eksport robi epik mergowany jako drugi |
  | `apps/api/openapi-admin.json`, `apps/admin/src/api/schema.d.ts` | nie zmienia | może zmieniać | bez styku |
  | `.github/workflows/ci.yml`, wagi części E2E | nie zmienia | nie zmienia (`e2e/CLAUDE.md`) | nowe pliki spec biegną bez zmiany wag |
  | `e2e/playwright.config.ts`, `e2e/package.json` | nie zmienia | do sprawdzenia | pomocniki nowych testów są lokalne w pliku testu |
  | `docs/features/checkout/spec.md`, `cart/spec.md`, `store-ux-fixes/spec.md`, `apps/store/CLAUDE.md` | zmienia | nie zmienia | bez styku |

  Do ponownego sprawdzenia przed implementacją: stan ADMIN-UX-FIXES etap 2 na `main` i `paths` jego planu;
  `git diff HEAD..origin/main -- e2e .github make apps/api/fixtures apps/api/openapi.json`. Epik mergowany jako drugi
  scala `main` i uruchamia pełne `make e2e`.

## Ryzyka i ograniczenia

- Wdrożenie: API i sklep wymieniają się osobno, więc w trakcie wdrożenia stara kasa może dostać 422 „Kasa została
  zaktualizowana. Odśwież stronę.” (dopuszczalne na etapie demo). Karta kasy otwarta przed wdrożeniem dostaje ten sam
  komunikat.
- Zmienia się zasada z `docs/features/checkout/spec.md` i `apps/store/CLAUDE.md` „żądanie nie niesie pozycji ani
  ilości”: niesie je wyłącznie do porównania.
- Pomiary wykonano tylko w Chromium; ogłaszanie sprawdzane po DOM, bez czytnika ekranu.
- Lista zmian porównuje dwa widoki API po stronie sklepu: zmiana pól `CartView` wymaga zmiany funkcji różnic (pilnuje
  tego typ wygenerowany ze schematu).
