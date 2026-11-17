# ADMIN-SCREENS: ceny, stany, promocje, kody rabatowe i zamówienia w standardach panelu, bez PrimeVue

Część 2 przebudowy panelu `apps/admin` (część 1: ADMIN-DESIGN, `docs/features/admin-design/spec.md`). Ekrany cen,
stanów magazynowych, promocji, kodów rabatowych i zamówień są na tokenach, komponentach shadcn-vue i standardach
listy, formularza i okien z ADMIN-DESIGN, a PrimeVue nie ma w panelu. API i wygenerowany klient się nie zmieniły: panel
pokazuje wyłącznie dane, które API już zwraca, a wszystkie liczby (ceny, sumy, stany, poziomy, statusy) liczy API.

Issues: #462, #135, #260, #261, #272, #273, #304, #336, #337, #343, #393, #446, #507, #508
Zakres: `apps/admin`, `e2e/support/price-change.ts`, `e2e/support/panel-orders.ts`, `e2e/support/promotions.ts`, `e2e/support/catalog-admin.ts`, `e2e/tests/admin-design.spec.ts`, `e2e/tests/catalog-admin.spec.ts`, `e2e/tests/price-change.spec.ts`, `e2e/tests/stock-receipt.spec.ts`, `e2e/tests/promotions.spec.ts`, `e2e/tests/order-management.spec.ts`, `e2e/tests/panel-orders-url.spec.ts`, `e2e/tests/staff-dashboard.spec.ts`, `CLAUDE.md`, `README.md`, `docs/vision.md`, `docs/adr/admin-ui-library.md`, `docs/features/admin-design/spec.md`, `docs/features/walking-skeleton/spec.md`, `docs/features/catalog-admin/spec.md`, `docs/features/order-management/spec.md`, `docs/features/promotions/spec.md`, `docs/features/discount-codes/spec.md`, `docs/features/inventory/spec.md`, `docs/features/pricing/spec.md`

## Cel i zakres

Cel: każdy ekran panelu ma wygląd i zachowanie według standardów ADMIN-DESIGN, spełnia WCAG 2.2 AA, a panel nie ma
PrimeVue. Kryterium: axe (reguły WCAG 2.0–2.2 A i AA) nie zgłasza naruszeń na ekranach i oknach tej funkcji przy
1280 i 768 px, reflow przy 320 px przechodzi, okna mieszczą się przy 375 px, każda funkcja ze spisu „Zachowane
funkcje” działa, a `primevue`, `@primeuix/themes` i `primeicons` nie ma w `package.json` ani w kodzie.

W zakresie:

- Ceny (`/prices`) i Stany magazynowe (`/inventory`) z oknami zmiany ceny, przyjęcia, korekty i historii.
- Promocje (`/promotions`, `/promotions/new`, `/promotions/:id`) z wyborem wariantów, i kody rabatowe
  (`/discount-codes`, `/discount-codes/new`, `/discount-codes/:id`).
- Zamówienia: lista (`/orders`, także „Zwroty do wykonania” `?refund=pending`) i szczegóły (`/orders/:id`) z osią czasu.
- Panel bez PrimeVue, bez listy `LEGACY_FILES` w konfiguracji ESLint i bez mostu „starych list” w menu (sekcja
  „Usunięcie PrimeVue”).
- Toasty w całym panelu: położenie względem paska zapisu i nazwa regionu (#507, #508, sekcja „Toasty”).
- Wspólne wyszukiwanie po nazwie (`useNameSearch`) dla listy produktów, Cen, Stanów i wyboru wariantów promocji.
- Zgłoszenia #135, #260, #261, #272, #273, #304, #336, #337, #343, #393, #446, #507, #508 (sekcja „Zgłoszenia
  zamykane przez epik”).

Standardy, tokeny, powłoka i decyzje D1–D14 ADMIN-DESIGN obowiązują na tych ekranach; ta specyfikacja opisuje tylko
ekrany i dopowiedzenia standardów potrzebne tym ekranom. Użytkownicy i uprawnienia: role `admin`, `support`,
`warehouse`, `marketing` z `GET /api/v1/admin/me`; panel tylko ukrywa elementy, uprawnienia egzekwuje API (403 widoku
jest zabezpieczeniem).

## Dopowiedzenia standardów

- **Lista pogrupowana po produkcie** (Ceny, Stany): API stronicuje produkty (`perPage` liczy produkty, każdy ze
  wszystkimi wariantami). `useCursorPager` stronicuje produkty (jego `Row` to produkt z API), a composable listy
  rozbija je na wiersze w `computed`, więc „Pokazano produkty X–Y” liczy produkty. `DataTable` dostaje wiersz grupy
  produktu (`product-row`: komórka `<th scope="rowgroup">` na całą szerokość z nazwą `name` pogrubioną, na Cenach
  z klasą podatkową `tax-class`) i pod nim wiersze wariantów (`price-row` / `stock-row`, każdy z `variant-label`);
  wiersz grupy nie ma akcji i nie ma `price-row` / `stock-row`. Helpery E2E (`e2e/support/price-change.ts`,
  `e2e/support/catalog-admin.ts`) znajdują `name` w wierszu grupy, a wiersz wariantu bezpośrednio pod nim.
- **Nadpisanie po zapisie**: wynik zapisu ceny albo stanu (`savedPrices` w `usePriceList`, `savedStocks`
  w `useStockList`) jest nakładką w `computed` nad `items` pagera; composable nie zapisuje do `items`. Nakładka
  obowiązuje, dopóki wersja z listy nie jest nowsza od zapisanej (`saved.version > listed.version` albo wartość z listy
  jest `null`), i nie jest czyszczona na starcie żądania, więc w trakcie odświeżenia wiersz nie wraca do starej ceny.
- **Wybór wariantów promocji** (`PromotionVariantPicker`) nie korzysta z `usePriceList`: `src/prices/` eksportuje
  czyste funkcje `fetchPricePage({ query, after })` i `priceRows(items)`, z których `usePriceList` składa listę
  z `useListUrlState` i `useCursorPager` (jak `useProductList`), a wybór wariantów woła
  `useCursorPager(fetchPricePage, …)` z zapytaniem w pamięci: własne pole wyszukiwania, 25 produktów na stronę, bez
  wyboru rozmiaru i bez stanu w adresie (ADMIN-DESIGN, „Standard listy”). Klasy podatkowe Ceny czytają tą samą funkcją
  co formularz produktu (`fetchTaxClasses` z `GET /api/v1/admin/tax-classes` i `taxClassName`, wspólny moduł
  `src/products/taxClasses.ts`).
- **Dwie kolumny i kolejność fokusu**: formularze promocji i kodu oraz szczegóły zamówienia mają układ standardu
  formularza: kolumna boczna stoi w DOM przed główną, poniżej 1280 px jest nad nią, a od 1280 px po prawej i Tab zaczyna
  od niej. To ten sam wyjątek od „kolejności zgodnej z układem”, który ADMIN-DESIGN („Dostępność”) ma dla formularza
  produktu; obejmuje te trzy ekrany (A4).
- **Okna z polami** stoją na `Dialog` shadcn-vue według „Standardu formularza i okien” ADMIN-DESIGN (fokus w pierwszym
  polu, „Zamknij”, szerokość `min(28rem, 100vw - 2rem)`, po błędzie fokus na komunikacie). Przycisk zapisu okna ma
  wariant `interface` z etykietą skutku („Zapisz cenę”, „Przyjmij”, „Zapisz korektę”), a przy anulowaniu i zwrocie
  zamówienia wariant `destructive`. Okna historii: szerokość `min(56rem, 100vw - 2rem)`, tabela w przewijanym regionie
  z priorytetami kolumn, „Pokaż więcej” i „Spróbuj ponownie”.
- **Kontrolki**: filtr kategorii to `CategoryCombobox`, filtr statusu `Select`, przełączniki `Switch` z etykietą, pola
  liczbowe `Input` (`inputmode="numeric"` albo `"decimal"`, parsowanie w `parseGross` i `parseCount` z komunikatami
  walidacji), rodzaj rabatu kodu grupa opcji `RadioGroup` (komponent shadcn-vue skopiowany do `src/components/ui/`,
  prymityw Reka UI), komunikaty `Alert` (błędy na stronach formularzy promocji i kodu, szczegółów zamówienia i w wyborze
  wariantów: tokeny `status-danger`, kontrast ≥ 4,5:1, #446; alerty błędów w oknach Cen, Stanów i zmiany statusu
  zamówienia: wariant `destructive`, ten sam kontrast), statusy `Badge` z tokenami `status-*`.
- **Wspólne wyszukiwanie po nazwie** (`src/composables/useNameSearch.ts`): jedno miejsce reguł wyszukiwania po nazwie
  dla listy produktów, Cen, Stanów i wyboru wariantów promocji: fraza po przycięciu ma od 2 do 100 znaków (punkty
  kodowe, jak liczy API), opóźnienie 300 ms, podpowiedź dla frazy za krótkiej („Wpisz co najmniej 2 znaki, aby
  wyszukać.”) i za długiej („Wpisz najwyżej 100 znaków, aby wyszukać.”), pusta fraza to brak filtra. Zwraca pole
  wyszukiwania, podpowiedź, `reset` (anuluje oczekujące opóźnienie, także przy odmontowaniu) i `setFromAddress`, a
  zastosowaną frazę (albo `null`) przekazuje funkcji `apply` podanej przez listę: na listach to zapis `q` do adresu
  (`useListUrlState`), w wyborze wariantów przypisanie do zapytania w pamięci. Wpisanie wartości z adresu (wejście,
  Wstecz: `setFromAddress`) ustawia pole bez ponownego `apply`. Testy reguł są raz, w `tests/useNameSearch.test.ts`;
  testy list sprawdzają tylko połączenie z adresem albo zapytaniem. Reguły kodów rabatowych (1–32 znaki, przycinanie
  `\p{Cf}`) i zamówień (po Enterze, 3–254 znaki) są w ich composables.
- **Fokus po zmianie strony listy, po błędzie i po 403** według standardu: widoki list cen, stanów, promocji, kodów
  i zamówień nie mają własnej obsługi fokusu. Fokus pagera prowadzi `usePagerFocus` (`ListPager`, wybór wariantów
  promocji): zostaje na naciśniętym przycisku, a gdy kolejna strona go wyłącza (ostatnia albo pierwsza), przechodzi na
  drugi, ale tylko wtedy, gdy fokus jest wolny (`body`) albo wciąż w pagerze, i najwyżej raz na naciśnięcie, więc
  późniejsza odpowiedź (np. wyszukiwania) nie zabiera go z pola, w którym jest pracownik. Błąd, ponowienie i 403
  obsługuje `DataTable`. „Wyczyść filtry” zostawia fokus na polu wyszukiwania, a na liście promocji, która go nie ma,
  na filtrze „Status”.
- **Testowe identyfikatory**: elementy ekranów mają stabilne `data-testid`, których używają testy komponentów i E2E;
  nowe i zmienione opisuje sekcja „Testowe identyfikatory”.

## Ceny i Stany magazynowe

Obie listy: H1 i okruszki („Pulpit › Katalog › Ceny”, „Pulpit › Katalog › Stany magazynowe”), karta listy ze
standardowym paskiem narzędzi: wyszukiwanie po nazwie produktu (`useNameSearch`: od 2 do 100 znaków, z opóźnieniem i
podpowiedzią; pole `searchbox` „Szukaj po nazwie produktu”), filtr kategorii `CategoryCombobox` (z podkategoriami, nazwa
dostępna „Kategoria”, #343), rozmiar strony 10/25/50 produktów (domyślnie 10; wartość 10 nie trafia do adresu, a do
żądania API trafia zawsze, bo domyślne API to 25), „Wyczyść filtry”. Stan w adresie: `q`, `category`, `perPage`
(`useListUrlState`). Pusta lista: „Nie ma jeszcze produktów z wariantami.” albo wspólne „Brak wyników dla wybranych
filtrów.”. Błąd przy zmianie zapytania ukrywa tabelę i pager do udanego ponowienia (#272); 403 bez ponowienia.

Wiersz produktu: nazwa pogrubiona; na Cenach obok polska nazwa klasy podatkowej z `GET /api/v1/admin/tax-classes`
(`{ code, name }`), a dla kodu spoza listy albo przy błędzie tego żądania sam kod, bez komunikatu. Pod nim wiersze
wariantów z etykietą wariantu („Wariant domyślny” dla pustej etykiety).

- **Ceny** (`/prices`, H1 „Ceny”): kolumny Wariant, Brutto (wyróżnione, pogrubione; „Brak ceny” dla wariantu bez ceny),
  Netto, VAT, Stawka (z etykietą nieaktualnej stawki, `outdated`), Akcje: „Zmień cenę” (tylko `admin`) i „Historia”
  (wszyscy), przyciski ikon z nazwą dostępną i podpowiedzią z nazwą produktu i wariantu („Zmień cenę: Hantle regulowane
  Lumora Para 10 / 10 kg”). Poniżej 1024 px Netto i VAT są ukryte jako kolumny i pojawiają się z etykietą jako linia
  komórki Wariant.
- **Okno „Zmień cenę”** (`change-dialog`): tytuł „Zmień cenę”, nazwa produktu i wariantu, bieżąca cena brutto, podgląd
  stawki, pole „Nowa cena brutto (zł)” (`gross-input`) z fokusem po otwarciu także z klawiatury (#273), przycisk „Zapisz
  cenę” (wariant `interface`, #135) i „Anuluj”. Szerokość mieści się w 375 px (#393). Błędy (`usePriceList`): 422 i 409
  `price-locked-by-promotion` przy polu (z komunikatem z API), 403 i 404 w komunikacie okna (`change-error`); inny 409
  to komunikat `change-conflict` i odświeżenie bieżącej strony bez gubienia jej. Po zapisie okno się zamyka, fokus wraca
  na „Zmień cenę” wiersza, toast „Cena zapisana: Hantle regulowane Lumora Para 10 / 10 kg”, a wiersz ma cenę z
  odpowiedzi API.
- **Okno „Historia ceny”** (`history-dialog`): kolumny Data zmiany, Brutto, Netto, VAT, Stawka, Klasa, Źródło,
  Pracownik; poniżej 1024 px szerokości ekranu zostają Data zmiany, Brutto i Źródło, a pozostałe są liniami komórki
  daty.
- **Stany** (`/inventory`, H1 „Stany magazynowe”): kolumny Wariant, Fizycznie, Zarezerwowane, Dostępne, Poziom (etykieta
  `Badge`: „Dostępny”, „Ostatnie sztuki”, „Niedostępny”; wariant bez rekordu stanu: „—” w liczbach i bez etykiety),
  Akcje: „Przyjęcie” i „Korekta” (`admin`, `warehouse`), „Historia” (wszyscy). Poniżej 1024 px Zarezerwowane jako linia
  komórki Wariant.
- **Okna przyjęcia i korekty** (`receive-dialog`, `correct-dialog`): klucz idempotencji tworzony przy otwarciu okna
  przyjęcia, wersja wiersza w korekcie, powód korekty jako `Select` (Inwentaryzacja, Uszkodzenie, Zaginięcie, Inne),
  notatka wymagana przy powodzie „Inne”, etykiety „Przyjęta ilość (szt.)” i „Stan policzony (szt.)”, fokus w pierwszym
  polu. 422 trafia do pól, które API wskaże (`quantity`, `physical`, `reason`, `note`); reszta 422 oraz 403, 404 i inne
  błędy do komunikatu okna. 409 przyjęcia (użyty klucz) i 409 korekty odświeżają bieżącą stronę. Po 409 korekty okno
  pokazuje polski komunikat „Stan został w międzyczasie zmieniony – odśwież” (`correct-conflict`), gdy wiersz po
  odświeżeniu ma inną wersję, odświeżenie się nie udało albo wiersza już nie ma, a gdy wersja jest ta sama (korekta
  poniżej zarezerwowanych sztuk), `detail` z API; po błędzie odświeżenia nigdy nie jest to angielski `detail`. Po
  zapisie toast „Przyjęto 10 szt.: Namiot turystyczny Hallen Biwak 2 / zielony” albo „Stan skorygowany: Namiot
  turystyczny Hallen Biwak 2 / zielony” i fokus na wyzwalaczu.
- **Okno „Historia stanu”**: 11 kolumn w przewijanym regionie tabeli (`role="region"`, `tabindex="0"`);
  poniżej 1024 px szerokości ekranu zostają Data, Rodzaj, Zmiana fizycznego i Stan fizyczny po zmianie, a pozostałe są
  liniami komórki daty.

## Promocje i kody rabatowe

- **Listy** (`/promotions` H1 „Promocje”, `/discount-codes` H1 „Kody rabatowe”; okruszki „Pulpit › Marketing › …”):
  filtr statusu jako `Select` „Status” (promocje: Wszystkie, Zaplanowane, Aktywne, Zakończone; kody: Wszystkie,
  Zaplanowane, Aktywne, Wygasłe, Wyłączone), na kodach wyszukiwanie po początku kodu (od 1 do 32 znaków, dłuższa fraza
  nie idzie do API, a podpowiedź mówi „Kod ma najwyżej 32 znaki.”), rozmiar strony 10/25/50 (domyślnie 25, nie trafia
  do adresu), stan w adresie (`status` i `perPage`, na kodach także `q`; API promocji nie ma wyszukiwania), przycisk
  główny „Dodaj promocję” / „Dodaj kod” tylko dla ról z prawem zmiany (`admin`, `marketing`), także w pustym stanie
  bez filtrów. Kolumny (promocje: Nazwa, Obniżka, Początek, Koniec, Status, Warianty; kody: Kod, Rabat, Minimum,
  Okres, Użycia, Status; obie z kolumną Akcje), status jako `Badge`, nazwa albo kod jako link, akcja wiersza „Edytuj:
  <nazwa albo kod>” dla ról z prawem zmiany. Puste stany: „Nie ma jeszcze promocji.”, „Nie ma jeszcze kodów
  rabatowych.”. Priorytety kolumn: poniżej 1024 px Początek i Koniec promocji oraz Minimum i Okres kodu jako linie
  komórki nazwy albo kodu.
- **Wyszukiwanie kodów** przycina znaki niewidoczne tak jak API (`/^[\s\p{Z}\p{Cf}]+|[\s\p{Z}\p{Cf}]+$/gu`), więc sam
  U+200B, U+00AD albo U+2060 to puste wyszukiwanie: pełna lista, bez żądania z `q` (#336).
- **Formularz promocji** (standard formularza; H1 „Nowa promocja” albo nazwa promocji, okruszki kończą się nazwą):
  - kolumna główna: karta „Dane promocji” (nazwa, procent obniżki, początek z przełącznikiem „Od teraz”, koniec;
    `datetime-local` w `Europe/Warsaw`, blokada procentu, początku i wariantów trwającej promocji z notatką „Promocja
    trwa. Możesz zmienić jej nazwę i koniec; procent, początek i warianty są zablokowane.”) i karta „Warianty”
    (liczba wybranych, tabela wybranych w przewijanym regionie: Produkt, Wariant, Cena katalogowa, Cena promocyjna,
    Akcje (bez Akcji przy zablokowanych wariantach); znaczniki „Wariant usunięty” i „Koliduje z inną promocją”;
    przycisk „Dodaj warianty” otwierający wybór wariantów);
  - wybór wariantów: przyciski z nazwami z nazwą wariantu („Dodaj: Hantle regulowane Lumora Para 10 / 10 kg”, „Dodaj
    cały produkt: Hantle regulowane Lumora Para 10”, „Usuń: …”, #304 F5); wariant bez ceny nie jest oferowany (wyłączony
    „Dodaj”), a dodany ma przycisk „Dodano: …”; po dodaniu fokus wraca na pole wyszukiwania wyboru; po usunięciu
    wybranego wariantu fokus trafia na przycisk usuwania następnego wiersza listy wybranych, a bez następnego na pole
    wyszukiwania wyboru, gdy wybór jest otwarty, albo na przycisk otwierający wybór (#304 F4);
  - kolumna boczna: karta „Status” (dla zapisanej promocji, także w trybie tylko do odczytu: etykieta statusu; „Zakończ
    teraz” dla aktywnej i „Usuń” dla zaplanowanej, gdy rola może zmieniać promocje; obie przez `ConfirmDialog`:
    „Zakończyć promocję <nazwa> teraz?” z przyciskiem „Zakończ promocję teraz” i „Usunąć promocję <nazwa>?” z „Usuń
    promocję”, czerwone, „Wróć” z domyślnym fokusem, `Escape` zamyka, #304 F6). Przy niezapisanych zmianach obie akcje
    są wyłączone z opisem „Najpierw zapisz zmiany.”. Po usunięciu przejście na listę i fokus na jej H1, toast
    „Promocja usunięta.”;
  - pasek zapisu, strażnik niezapisanych zmian, toast „Promocja zapisana.” i fokus po zapisie według standardu; po
    nieudanym zapisie fokus na pierwszym polu z błędem albo komunikacie formularza przewiniętym do widoku (#304 F3).
    Po utworzeniu przejście na `/promotions/:id` (`replace`);
  - tryb tylko do odczytu (rola bez prawa zmiany albo promocja zakończona): pola wyłączone, bez paska zapisu, bez
    przycisków zmiany wariantów i bez akcji karty „Status”; notatka trybu tylko do odczytu („Nie masz uprawnień do
    zmiany promocji. Widok jest tylko do odczytu.” albo „Zakończonej promocji nie można zmienić. Widok jest tylko do
    odczytu.”) jest w karcie „Status” pod etykietą;
  - 404: H1 „Nie udało się wczytać promocji”, komunikat „Ta promocja nie istnieje.” (`Alert`, #446) i link do listy.
- **„Zakończ teraz” i ponowny odczyt** (#304 F7): po potwierdzeniu w `ConfirmDialog` fokus trafia na nagłówek karty
  „Status” (karta zostaje przez cały przebieg). Odpowiedź `PUT` ze `status: ended` kończy przebieg od razu. Zwykle API
  ustawia koniec chwilę w przyszłości, więc odpowiedź `PUT` ma `status: active`. Widok czeka
  `max(endsAt − teraz, 0) + 1 s` i odczytuje promocję z API ponownie; gdy dalej jest `active` (zegar przeglądarki
  różni się od serwera), ponawia odczyt co 1 s najwyżej 5 razy, a potem pokazuje stan
  z ostatniej odpowiedzi z komunikatem „Koniec promocji ustawiony na 12.11.2026, 14:03:12.” (data i godzina końca
  z API w czasie polskim, `formatDateTime`). Do pierwszego z tych zdarzeń „Zapisz” i „Zakończ teraz” są wyłączone
  z widocznym opisem „Promocja kończy się”. Odczyt ze `status: ended` (albo taka odpowiedź `PUT`) pokazuje toast
  „Promocja zakończona.” i formularz w trybie tylko do odczytu (karta „Status” z etykietą „Zakończona” i notatką; fokus
  zostaje na jej nagłówku). Błąd odczytu kończy oczekiwanie komunikatem „Nie udało się odczytać promocji po zakończeniu.
  Odśwież, żeby zobaczyć jej stan.” z przyciskiem „Odśwież” (po nieudanym odświeżeniu fokus na H1). Opuszczenie
  ekranu przerywa oczekiwanie (bez żądań po odmontowaniu).
- **Formularz kodu** (standard formularza; H1 „Nowy kod rabatowy” albo kod): kolumna główna: karta „Kod i rabat”
  (kod, rodzaj rabatu jako `RadioGroup` Procent/Kwota, wartość, minimum zamówienia) i „Okres i limit” (początek,
  koniec, limit użyć z notatką blokady); boczna: karta „Status” (etykieta, przełącznik „Aktywny”, „Aktywne użycia”).
  Przełącznik „Aktywny” jest polem formularza: jego zmiana pokazuje pasek zapisu i trafia do API dopiero z „Zapisz”.
  Kod, który już obowiązuje (`termsLocked`), ma zablokowane rodzaj, wartość, minimum i początek (notatka „Kod już
  obowiązuje: …”), a limit użyć nie da się dodać, gdy go nie miał (`limitLocked`); kwoty są w złotych (API dostaje
  grosze). 422 trafia do pól, 409 `discount-code-taken` do pola kodu, inne 409, 403 i 404 do komunikatu formularza.
  Pasek zapisu, strażnik zmian, toast „Kod rabatowy zapisany.”, fokus po zapisie i po błędzie według standardu
  (wynik zapisu to toast, nie komunikat w treści). 404: H1 „Nie udało się wczytać kodu
  rabatowego”, „Ten kod rabatowy nie istnieje.” (#446) i link do listy.

## Zamówienia

- **Lista** (`/orders`, H1 „Zamówienia”; na `?refund=pending` H1 „Zwroty do wykonania”; okruszki „Pulpit › Zamówienia”
  albo „Pulpit › Zamówienia › Zwroty do wykonania”): wyszukiwanie numeru, e-maila albo fragmentu numeru, e-maila lub nazwiska po Enterze (3–254 znaki
  po przycięciu, z podpowiedzią; pola daty „Od” i „Do”; ponowny Enter z tą samą frazą odświeża listę), filtr statusu (`Select` „Status”),
  przełącznik `Switch` „Zwroty do wykonania” (`refund-pending`), rozmiar strony 10/25/50 (domyślnie 25), stan w adresie
  (`q`, `status`, `refund`, `placedFrom`, `placedTo`, `perPage`; e-mail w `q` trafia do adresu, przyjęte w ADMIN-DESIGN). Kolumny: Numer (link,
  bez łamania, #261), Data (data i godzina), Klient (imię i nazwisko, pod spodem e-mail obcięty (`truncate`) z pełną
  wartością w podpowiedzi `title`), Pozycje (`itemCount`), Kwota (do prawej), Status (`Badge`); przy zwrotach dodatkowo
  Kwota zwrotu i Termin zwrotu (z etykietą „Po terminie”). Poniżej 1024 px Pozycje i Data jako linie komórki Numer;
  poniżej 640 px tabela zamienia się w listę kart (numer jako link, status, klient z e-mailem, kwota, liczba pozycji,
  data; w widoku „Zwroty do wykonania” także kwota i termin zwrotu, jak kolumny tabeli), bez przewijania w poziomie.
  Widok renderuje dokładnie jeden układ według `useMediaQuery("(min-width: 640px)")` (`@vueuse/core`, jak `AppLayout`),
  więc `order-row` i jego identyfikatory (`number`, `status`, `total`, …) są na wierszu tabeli albo na karcie, nigdy w
  obu; karty to lista `<ul>` z nazwą „Zamówienia”. Puste stany: „Nie ma jeszcze zamówień.” i wspólne „Brak wyników dla
  wybranych filtrów.”; 403 „Nie masz dostępu do zamówień.” bez ponowienia, 400 „Nieprawidłowe parametry wyszukiwania
  (HTTP 400).”.
- **Szczegóły** (`/orders/:id`, H1 „Zamówienie R-100002” z etykietą statusu obok; okruszki „Pulpit › Zamówienia ›
  R-100002” zamiast „Wróć do listy”), od 1280 px dwie kolumny, poniżej jedna (boczna nad główną):
  - kolumna główna: karta „Pozycje” (tabela `line-row` w przewijanym regionie z kolumnami: Produkt (`line-name` z
    wariantem i opisem promocji `line-promotion`), Ilość (`line-quantity`), Cena brutto (jednostkowa), a przy `discount`
    dodatkowo Wartość przed rabatem (`line-gross-before-discount`) i Rabat (`line-discount`); na końcu „Wartość brutto”,
    a przy `discount` „Wartość po rabacie” (`line-gross`); linia kodu rabatowego `discount-line` i sumy `totals` z API
    (Netto, VAT, Brutto albo „Brutto po rabacie”); poniżej 640 px widoczne Produkt, Ilość i ostatnia Wartość, a
    pozostałe kolumny są liniami komórki produktu), karta „Historia” z osią czasu i karta „Zwrot pieniędzy” (gdy
    `refund`: kwota, termin z „Po terminie”, stan „Do wykonania” albo „Wykonany”, wykonanie: data, pracownik, notatka,
    płatność do zwrotu);
  - kolumna boczna: karta „Akcje” (przyciski z `allowedTransitions` i „Oznacz zwrot jako wykonany” przy
    `canCompleteRefund`; karta jest ukryta bez żadnej akcji), „Klient” (imię i nazwisko, e-mail jako `mailto:` z
    zawijaniem w karcie, telefon, link „Inne zamówienia klienta” → `/orders?q=<e-mail>`), „Adres dostawy”, „Płatność”
    (data złożenia, termin płatności dla `new`, data opłacenia, daty wysłania, dostarczenia, anulowania z powodem i
    zwrócenia, identyfikator płatności `paymentId` krojem o stałej szerokości z przyciskiem „Kopiuj identyfikator
    płatności”).
  - **Kopiowanie**: przycisk kopiuje `paymentId` do schowka (`navigator.clipboard.writeText`) i ogłasza „Skopiowano”
    (`role="status"`); gdy schowek jest niedostępny albo odmówiono zgody, ogłasza „Nie udało się skopiować – zaznacz
    identyfikator ręcznie” i zaznacza tekst identyfikatora.
  - **Okno zmiany statusu** (okno z polami): tytuł ze skutkiem i numerem („Anulować zamówienie R-100002?”, „Oznaczyć
    zamówienie R-100002 jako wysłane?”, „Oznaczyć zamówienie R-100002 jako dostarczone?”, „Przyjąć zwrot zamówienia
    R-100002?”, „Oznaczyć zwrot pieniędzy za R-100002 jako wykonany?”), pole „Notatka” z fokusem po otwarciu (wymagane
    przy anulowaniu i przyjęciu zwrotu, 500 znaków), przycisk skutku (czerwony przy anulowaniu i zwrocie) i „Wróć”. 422
    przy polu notatki, 403 i inne błędy w komunikacie okna z fokusem na nim (#260 F8), 409 zamyka okno, pokazuje
    komunikat `conflict` (`tabindex="-1"`) z fokusem na nim i wczytuje zamówienie ponownie. Po udanej akcji toast
    „Status zamówienia zmieniony: Wysłane.” (polska etykieta pola `status` z odpowiedzi API na zmianę statusu, ta sama
    co w etykiecie statusu, `statusLabel`; nigdy pusta: nieznany status pokazuje wartość z API) albo „Zwrot oznaczony
    jako wykonany.”, a fokus trafia na nagłówek karty „Akcje” (zestaw przycisków się zmienił); gdy karta „Akcje”
    zniknęła (brak dalszych akcji, np. po anulowaniu nieopłaconego zamówienia albo po wykonaniu zwrotu), na nagłówek
    karty „Historia”.
  - 404: H1 „Nie znaleziono zamówienia” z linkiem do listy; błąd wczytania z „Spróbuj ponownie” (403 bez ponowienia).
  - Szczegóły działają przy 375 px bez przewijania strony w poziomie.
- **Oś czasu** (`OrderTimeline`): wpisy `history` (`BackofficeOrderStatusChange`: `from`, `to`, `occurredAt`,
  `actorType`, `staffUsername`, `note`) w kolejności z API, od najstarszego; złożenie to wpis z `from: null`. Każdy wpis
  (`history-row`, element `<li>`): czas w `<time datetime>` (`history-at`, data i godzina polska), zmiana
  (`history-change`: status wyjściowy, strzałka i status docelowy jako `Badge`, tekst „Opłacone → Wysłane”; przy `from:
  null` sam status docelowy), aktor (`history-actor`: `staff` to `staffUsername`, a bez loginu „Pracownik” w
  `actorLabel`; `customer` „Klient”; `system` „System”, z etykietą „Kto:”) i notatka (`history-note`). Lista `<ol>`, bez
  sortowania i bez dokładania zdarzeń z innych pól; zwrot nie jest wpisem osi (karta „Zwrot pieniędzy”).

## Toasty

Powłoka, dla całego panelu (formularz produktu, formularze promocji i kodu, okna list, szczegóły zamówienia):

- **Nazwa regionu** (#508): region toastów ma nazwę dostępną dokładnie „Powiadomienia”: `Toaster` (`Sonner.vue`)
  ustawia skrót klawiszowy `["Key"]`: `vue-sonner` nie dokleja jego etykiety do nazwy, a żadne zdarzenie klawiatury go
  nie wywoła (kod klawisza to `KeyT`, nie `Key`), więc skrót fokusu na toastach nie działa (nie jest opisany w panelu
  ani wymagany).
- **Położenie względem paska zapisu** (#507): toast nigdy nie zasłania paska zapisu ani żadnego jego przycisku.
  Toasty są w prawym górnym rogu obszaru treści, pod topbarem (`position: top-right` z odsunięciem o wysokość
  topbaru i 12 px, `AppLayout.vue`), więc nie nachodzą na przyklejony do dołu `SaveBar` przy żadnej szerokości. Przy
  szerokości poniżej 640 px toast zajmuje szerokość ekranu z marginesem 16 px i też jest u góry, pod topbarem. Toast
  nie zasłania też przycisku „Menu” ani konta w topbarze. Czas to 5 s, a pod kursorem toast się zatrzymuje.

## Testowe identyfikatory

Identyfikatory, które mają szczególne znaczenie na tych ekranach (pozostałe `data-testid` stoją na elementach, które
nazywają):

| Identyfikator | Znaczenie |
|---|---|
| `product-row` (Ceny, Stany) | wiersz grupy produktu z `name` (i `tax-class` na Cenach); `name` nie jest w `price-row` / `stock-row` |
| `order-row` (lista zamówień) | na wierszu tabeli albo na karcie, zależnie od układu (jeden w DOM) |
| `history-row` (szczegóły zamówienia) | wpis osi czasu (`<li>`), a w nim `history-change`, `history-at`, `history-actor`, `history-note` |
| `copy-payment-id`, `copy-status` (szczegóły zamówienia) | przycisk kopiowania i komunikat jego wyniku |
| `refund-pending` (lista zamówień) | `id` i `data-testid` przełącznika `Switch`, etykieta `label for="refund-pending"` |

Powrót do listy w formularzach i szczegółach zamówienia to okruszki, wynik zapisu formularzy promocji i kodu to toast,
a potwierdzenia w formularzu promocji to `ConfirmDialog` (rola `alertdialog` z nazwą z tytułu), więc nie ma
identyfikatorów `back`, `success-message`, `confirm-box`, `confirm-action` ani `cancel-confirm`.

## Usunięcie PrimeVue

- `primevue`, `@primeuix/themes` i `primeicons` nie ma w `apps/admin/package.json` ani `package-lock.json`;
  `src/main.ts` nie rejestruje PrimeVue ani `primeicons.css`; nie ma `src/primevue.ts`; testy jednostkowe nie montują
  PrimeVue (`primevue/config`).
- `eslint.config.js` nie ma listy `LEGACY_FILES` ani wyjątków z nią związanych: reguły koloru, `<style>` i nieznanych
  klas obejmują cały `src/`, a `no-restricted-imports` blokuje import `primevue`, `@primeuix/*` i `primeicons`. Test
  reguł (`tests/lint/color-rules.test.ts`) sprawdza próbę importu i że reguły stoją na każdym pliku `.ts` i `.vue` w
  `src/`; `eslint.config.d.ts` nie deklaruje `LEGACY_FILES`.
- Menu nie ma mostu „starych list”: `src/layout/AppMenuItem.vue` nie ma gałęzi `force`. Lista na `useListUrlState` ma
  filtry i rozmiar strony w adresie (pozycji w stronicowaniu adres nie ma), a `useListUrlState` obserwuje
  `route.query`, więc wybór w menu „Zwroty do wykonania” na otwartej liście zamówień przełącza ją; klik z Ctrl, Cmd,
  Shift, Alt albo środkowym przyciskiem zostaje przeglądarce, a klik w pozycję menu z adresem, na którym lista już
  jest, nie wraca na pierwszą stronę. Composables zamówień, promocji i kodów nie mają obserwatorów `route.query` ani
  `filtersOf` / `statusOf`.
- `useCategoryTree` zwraca drzewo w własnym typie `CategoryTreeNode` (`key`, `label`, `children`), który czytają
  `CategoryCombobox` i filtry list; nie ma typu w kształcie PrimeVue `TreeNode`.
- Komentarz `style-src` w `apps/admin/docker/security-headers.conf` wymienia tylko Reka UI (inline style przy
  pozycjonowaniu okien i list), więc `style-src` nie jest ograniczony.

## Responsywność i dostępność

Reguły ADMIN-DESIGN („Responsywność”, „Dostępność”) na tych ekranach: przy 768 px lista pokazuje nazwę, status
i akcje bez przewijania w poziomie; przy 375 px każde okno mieści się w ekranie, a lista i szczegóły zamówienia są
zaprojektowane pod telefon; przy 320 × 640 px (WCAG 1.4.10) strona nie przewija się w poziomie, a każda informacja
i funkcja ekranu i jego okien jest dostępna. Fokus nigdy nie spada na `body` po akcji, zmianie strony, błędzie ani
zamknięciu okna.

## Zachowane funkcje

Każda funkcja i informacja ze spisu „Ceny”, „Stany”, „Promocje”, „Kody rabatowe” i „Zamówienia” z sekcji „Zachowane
funkcje” `docs/features/admin-design/spec.md` jest w panelu. Funkcje, które mają w nim inny sposób wykonania:

| Funkcja | Sposób wykonania |
|---|---|
| powrót do listy (formularze, szczegóły zamówienia) | okruszki z linkiem do listy |
| filtry statusu promocji i kodów | `Select` „Status” |
| wynik zapisu promocji i kodu | toast i fokus według „Standardu formularza” |
| potwierdzenie „Zakończ teraz” i „Usuń” | `ConfirmDialog` (okno potwierdzenia) |
| e-mail na liście zamówień | druga linia kolumny Klient |
| klasa podatkowa na Cenach | polska nazwa w wierszu produktu |
| opuszczenie formularza promocji i kodu z niezapisanymi zmianami | okno strażnika zmian |
| teksty pustych list | teksty ze „Standardu listy” |
| kolumny tabel przy każdej szerokości | kolumny niższego priorytetu jako linie komórki głównej, lista zamówień jako karty poniżej 640 px |

Logika ekranów jest w composables (`usePriceList`, `usePriceHistory`, `useStockList`, `useStockHistory`,
`usePromotionList`, `usePromotionForm`, `useDiscountCodeList`, `useDiscountCodeForm`, `useOrderList`,
`useOrderDetail`) z ich testami w `apps/admin/tests`: stronicowanie idzie przez `useCursorPager`, a stan listy
w adresie przez `useListUrlState`.

## Zgłoszenia zamykane przez epik

Zgłoszenie trafia do `Closes` końcowego PR tylko wtedy, gdy jego weryfikacja przechodzi na HEAD gałęzi epiku (raport
`/integration`: tabela zgłoszenie, test, wynik). Zgłoszenie bez pozytywnego wyniku jest usuwane z `Closes` i zostaje
otwarte z komentarzem, czego brakuje. Test weryfikacji ma numer zgłoszenia w nazwie.

| Issue | Co zamyka | Gdzie | Weryfikacja |
|---|---|---|---|
| #135 | kontrast „Zapisz” w oknie ceny i numeru strony | Ceny | E2E: axe w otwartym oknie „Zmień cenę” i na `/prices` bez `color-contrast` |
| #260 | F7 fokus po zmianie strony listy zamówień, F8 fokus po 403 w oknie, F9 kontrasty | Zamówienia | test komponentu F7 i F8; E2E: axe na liście i szczegółach (z otwartym oknem) bez `color-contrast` |
| #261 | numer zamówienia w jednej linii | Zamówienia | E2E: wysokość elementu `number` nie większa niż 1,5 × jego `line-height` przy 1280 px na `/orders` i `/orders?refund=pending` |
| #272 | brak starych wierszy po błędzie wyszukiwania cen | Ceny | E2E z kryterium zgłoszenia (500 przy wyszukiwaniu „hantle”: komunikat i 0 `price-row`) |
| #273 | fokus w polu ceny po otwarciu okna klawiaturą | Ceny | E2E z kryterium zgłoszenia |
| #304 | F3 komunikat zapisu w widoku z fokusem, F4 fokus w wyborze wariantów, F5 nazwy przycisków, F6 okno potwierdzenia, F7 ponowny odczyt po „Zakończ teraz” | Promocje | testy komponentu F3, F4, F6, F7 (z fałszywym zegarem); E2E: F4, F5 (nazwy z nazwą wariantu), F6 (`Escape` zamyka, fokus na „Wróć”) i F7 |
| #336 | wyszukiwanie samym znakiem niewidocznym | Kody | Vitest dla U+200B, U+00AD, U+2060 i „spacja U+200B spacja”: bez żądania z `q` |
| #337 | fokus po ponowieniu listy kodów zakończonym 403 | Kody | test `DiscountCodesView` (`attachTo: document.body`): 500, ponowienie z 403, fokus nie na `body` |
| #343 | nazwa przycisku rozwijania filtra kategorii | Ceny, Stany | E2E: axe na `/prices` i `/inventory` bez `aria-command-name` i bez nienazwanych przycisków |
| #393 | okno „Zmień cenę” mieści się przy 375 px | Ceny | E2E z kryterium zgłoszenia (`x ≥ 0`, `x + width ≤ 375`) |
| #446 | kontrast komunikatu 404 formularza | Kody, Promocje | E2E: axe na `/discount-codes/<nieistniejący>` i `/promotions/<nieistniejący>` bez `color-contrast` |
| #507 | toast nie zasłania paska zapisu | Powłoka, wszystkie formularze | E2E z kryterium zgłoszenia (pole przecięcia prostokątów toastu i każdego przycisku paska równe 0, potem klik „Zapisz” w 3 s) na formularzu produktu i kodu przy 1280 × 720, 375 × 812 i 320 × 640 px |
| #508 | nazwa regionu toastów | Powłoka | E2E: `getByRole("region", { name: "Powiadomienia", exact: true })` ma 1 element |

## Weryfikacja UX i odbiór

- **Testy automatyczne** (CI): kryteria akceptacji niżej.
- **Agent `ux-reviewer` w `/integration`** ocenia każdy ekran i okno tej funkcji przy 1280, 768 i 375 px według sekcji
  „Weryfikacja UX i odbiór” ADMIN-DESIGN (użyteczność, dostępność, wygląd na zrzutach, przejście samą klawiaturą,
  reflow przy 320 px), a ekrany ADMIN-DESIGN pod kątem regresji po usunięciu PrimeVue.
- **Odbiór przez właściciela** przed merge'em końcowego PR, na stosie z gałęzi epiku: przegląd zrzutów
  z `/integration`; ręczne przejście klawiaturą: zmiana ceny, przyjęcie i korekta stanu z historią, utworzenie
  promocji z wyborem wariantów i „Zakończ teraz”, utworzenie kodu, lista zamówień z filtrami, szczegóły z akcją
  i kopiowaniem, lista zamówień i szczegóły przy 375 px; tabela weryfikacji zgłoszeń.

## Kryteria akceptacji

1. Test E2E przy 1280 i 768 px (axe z `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22aa`) nie dostaje żadnego
   naruszenia na: `/prices` z otwartym oknem „Zmień cenę” i oknem historii, `/inventory` z oknami przyjęcia, korekty
   i historii, `/promotions`, `/promotions/new` z otwartym wyborem wariantów, `/promotions/<aktywna>` z oknem „Zakończ
   teraz”, `/promotions/<nieistniejąca>`, `/discount-codes`, `/discount-codes/new`, `/discount-codes/<nieistniejący>`,
   `/orders`, `/orders?refund=pending`, `/orders/<id>` z otwartym oknem zmiany statusu, oraz na oknie niezapisanych
   zmian formularza promocji.
2. Reflow przy 320 × 640 px na każdym ekranie i oknie z kryterium 1: `scrollWidth` dokumentu równa się `clientWidth`,
   każdy przycisk, link i pole (także akcje wierszy, pasek zapisu, wybór wariantów) da się osiągnąć Tabem i jest
   w całości widoczny po przewinięciu do niego.
3. Przy 375 px każde okno z kryterium 1 mieści się w szerokości ekranu (`x ≥ 0`, `x + width ≤ 375`); lista zamówień
   ma karty z numerem, statusem i kwotą widocznymi bez przewijania w poziomie; szczegóły zamówienia nie przewijają
   strony w poziomie, a tabela pozycji pokazuje produkt, ilość i wartość.
4. Przy 768 px akcje pierwszego wiersza wariantu na Cenach i Stanach (i poziom na Stanach), akcja i status pierwszego
   wiersza na Promocjach i Kodach oraz numer, status i kwota pierwszego wiersza na Zamówieniach są w całości widoczne
   bez przewijania w poziomie; ukryte kolumny są liniami komórki głównej (E2E przy 768 i 320 px: Netto ceny,
   Zarezerwowane stanu; data zamówienia przy 768 px jako linia komórki Numer, a przy 320 px jako data na karcie
   zamówienia).
5. Lista z adresu: `/prices?q=hantle&perPage=25` i `/inventory?category=<id>` pokazują wypełnione filtry i wyniki;
   `/promotions?status=active`, `/discount-codes?status=active&q=LATO` (na danych `make seed` bez wyników: stan pusty z
   „Wyczyść filtry”), `/orders?status=paid&perPage=10` i `/orders?refund=pending` pokazują przefiltrowane listy; zmiana
   filtra zmienia adres bez nowego wpisu historii; „Wyczyść filtry” wraca do adresu bez parametrów; wybór „Zwroty do
   wykonania” w menu na otwartej liście zamówień przełącza ją bez przeładowania (E2E, `panel-orders-url.spec.ts`).
6. Ceny i Stany: druga strona przy 10 produktach na stronę mówi „Pokazano produkty 11–20” niezależnie od liczby
   wariantów; zmiana rozmiaru strony wraca na pierwszą stronę (test composable). Wiersz produktu na Cenach ma polską
   nazwę klasy podatkowej, a przy błędzie `GET /tax-classes` kod (test komponentu).
7. Zmiana ceny, przyjęcie i korekta: toast z nazwą produktu i wariantu, fokus po zapisie na wyzwalaczu, po błędzie
   na komunikacie albo polu z błędem; 409 korekty odświeża bieżącą stronę i pokazuje polski komunikat, także gdy
   odświeżenie się nie powiodło (`InventoryChange.test.ts` i `useStockList.test.ts`);
   istniejące `price-change.spec.ts` i `stock-receipt.spec.ts` przechodzą (przycisk „Zapisz cenę”).
8. Formularz promocji i kodu: pasek zapisu, strażnik niezapisanych zmian („Zostań” zostawia formularz), toast i fokus
   na H1 po zapisie, fokus na pierwszym polu z błędem po nieudanym zapisie, tryb tylko do odczytu bez paska (testy
   komponentów); `promotions.spec.ts` tworzy promocję przez formularz i widzi toast w regionie `aria-live`.
9. Promocja: „Zakończ teraz” i „Usuń” przez `ConfirmDialog` (`Escape` i „Wróć” zamykają, fokus wraca na wyzwalacz;
   po potwierdzeniu „Zakończ teraz” fokus na nagłówku „Status”, po usunięciu na H1 listy); ponowny odczyt po „Zakończ
   teraz” z wyłączonymi „Zapisz” i „Zakończ teraz” i opisem „Promocja kończy się”; `ended` w odpowiedzi `PUT` albo
   odczytu: toast „Promocja zakończona.” i tryb tylko do odczytu z kartą „Status”; ponowienia co 1 s najwyżej 5 razy,
   potem komunikat z godziną końca i stan z ostatniej odpowiedzi; błąd z „Odśwież”, a gdy
   odświeżenie też zawiedzie, fokus na H1; brak żądań po odmontowaniu (testy komponentu z fałszywym
   zegarem).
10. Szczegóły zamówienia: oś czasu pokazuje wpisy `history` w kolejności z API z czasem w `<time>`, zmianą („Opłacone →
    Wysłane”), aktorem („Klient”, „System”, login pracownika, „Pracownik” bez loginu) i notatką; „Inne zamówienia
    klienta” prowadzi do `/orders?q=<e-mail>`; „Kopiuj identyfikator płatności” ogłasza „Skopiowano”, a przy odmowie
    schowka komunikat i zaznaczenie tekstu; po akcji fokus na nagłówku „Akcje”, a gdy karta zniknęła, na nagłówku
    „Historia”; 409 zamyka okno, pokazuje `conflict` z fokusem na nim i wczytuje zamówienie (testy komponentów);
    tabela pozycji ma kolumny z sekcji „Szczegóły” z kodem i bez kodu; `order-management.spec.ts` przechodzi przez
    okruszki zamiast `back`.
11. `apps/admin/package.json` nie ma `primevue`, `@primeuix/themes` ani `primeicons`; żaden plik `apps/admin/src`
    i `apps/admin/tests` poza `tests/lint/` nie zawiera `primevue` ani `primeicons` (`grep`); `eslint.config.js`
    i `eslint.config.d.ts` nie mają `LEGACY_FILES`; ESLint zgłasza błąd dla importu `primevue/button`,
    `@primeuix/themes/aura` i `primeicons/primeicons.css` i dla `bg-[#fff]` w każdym pliku `src/` (test reguł
    w `tests/lint/`); `AppMenuItem` nie ma gałęzi `force`.
12. Każde zgłoszenie z linii `Issues` (poza #462) ma test z kolumny „Weryfikacja”, nazwany jego numerem.
13. Toasty: region ma nazwę dokładnie „Powiadomienia” (#508); przy 1280 × 720, 375 × 812 i 320 × 640 px toast po
    zapisie formularza produktu i kodu nie przecina prostokąta żadnego przycisku paska zapisu ani topbaru, a „Zapisz”
    da się kliknąć, gdy toast jest widoczny (#507, E2E).
14. Wspólne wyszukiwanie: `tests/useNameSearch.test.ts` sprawdza 1 znak, 2 znaki, 100 i 101 znaków (punkty kodowe),
    same spacje, opóźnienie 300 ms, `reset` i odmontowanie przed upływem opóźnienia; lista produktów, Ceny, Stany
    i wybór wariantów używają `useNameSearch`, a testy wyszukiwania listy produktów (`useProductList`, `ProductsView`,
    `catalog-admin.spec.ts`) przechodzą.
15. Toast po zmianie statusu zamówienia zawiera polską etykietę statusu z odpowiedzi API („Status zamówienia
    zmieniony: Wysłane.”), nigdy pustą (test komponentu).
16. Zachowane funkcje: każda pozycja spisu „Ceny”, „Stany”, „Promocje”, „Kody rabatowe” i „Zamówienia” (ADMIN-DESIGN,
    „Zachowane funkcje”) ma test komponentu, composable albo E2E, który ją wykonuje.
17. Przechodzą `make check`, `make -C apps/admin test` i `make e2e`.

## Dokumenty opisujące funkcję

- `apps/admin/CLAUDE.md`: stos bez PrimeVue, opisy widoków cen, stanów, promocji, kodów i zamówień (stan listy
  w adresie, okna, toasty, fokus, oś czasu, kopiowanie), komentarz `style-src`.
- `CLAUDE.md` (linia `apps/admin` w „Struktura”), `README.md` (tabela stosu), `docs/vision.md` (tabela „Aplikacje”),
  `docs/adr/admin-ui-library.md` (wybór biblioteki komponentów).
- `docs/features/admin-design/spec.md`: standardy, powłoka (położenie i nazwa regionu toastów, sekcja „Toasty”),
  wyjątek kolejności fokusu w „Dostępności” (A4), wyszukiwanie po nazwie w `useNameSearch` w „Standardzie listy” (D16);
  dla ekranów części 2 odsyła do tej specyfikacji.
- `docs/features/walking-skeleton/spec.md` (tabela zależności panelu), `docs/features/order-management/spec.md`,
  `docs/features/promotions/spec.md`, `docs/features/discount-codes/spec.md`, `docs/features/inventory/spec.md`,
  `docs/features/pricing/spec.md`, `docs/features/catalog-admin/spec.md`: zdania o wyglądzie i zachowaniu ekranów
  panelu (filtry jako `Select`, okna potwierdzenia, oś czasu, toasty), tylko tam, gdzie te dokumenty opisują panel.

## Decyzje

Obowiązują decyzje D1–D14 ADMIN-DESIGN (`docs/features/admin-design/spec.md`, „Decyzje”) i opis ekranów ze zgłoszenia
#462. Decyzje właściciela (2026-11-14):

- D15. #507 i #508 są w zakresie tej funkcji i działają w całym panelu; #509 (pulpit) jest poza zakresem.
- D16. Wspólne wyszukiwanie po nazwie (`useNameSearch`) dla listy produktów, Cen, Stanów i wyboru wariantów (2–100
  znaków, 300 ms).
- D17. Po usunięciu wybranego wariantu bez następnego wiersza fokus trafia na pole wyszukiwania otwartego wyboru, a przy
  zamkniętym na przycisk otwierający wybór.
- D18. Toast po zmianie statusu zamówienia podaje nazwę nowego statusu z odpowiedzi API.

Decyzje techniczne:

- A1. Ceny i Stany stronicują produkty przez `useCursorPager` i rozbijają je na wiersze wariantów w composable; wybór
  wariantów promocji składa własną listę z czystych funkcji `fetchPricePage` i `priceRows`, bez `usePriceList`.
- A2. Rodzaj rabatu kodu jako `RadioGroup` shadcn-vue (kod w repo, prymityw Reka UI już w zależnościach).
- A3. Lista zamówień poniżej 640 px jako karty z tych samych danych co tabela; widok renderuje jeden układ według
  `useMediaQuery`, nie oba ukrywane CSS-em.
- A4. Wyjątek kolejności fokusu (kolumna boczna przed główną) obejmuje formularze promocji i kodu oraz szczegóły
  zamówienia, jak formularz produktu.

## Poza zakresem

Każda zmiana API i klienta TS oraz funkcje ADMIN-FEATURES (#461): sortowanie, liczniki wyników, filtr poziomu stanu,
wyszukiwanie częściowe zamówień, notatki wewnętrzne, cena i stan w formularzu produktu, akcje masowe, eksport. Tryb
ciemny. Zmiany powłoki, pulpitu, „Stanu systemu”, listy i formularza produktu poza tymi, których wymaga panel bez
PrimeVue i bez mostu „starych list”, toasty (#507, #508) i wspólne wyszukiwanie po nazwie (D16). #509 (tabela „Ostatnie
zamówienia” na pulpicie). Pełna wersja na telefon ekranów innych niż lista i szczegóły zamówienia (spełniają reflow przy
320 px).

## Ryzyka

- **Regresje zachowania.** Ekrany mają dużo dopracowanej logiki (wersje, idempotencja, 409, blokada promocją, reguły
  edycji promocji i kodów, przejścia zamówień). Logika jest w composables z testami, a testy widoków używają ról,
  etykiet i `data-testid`.
- **Wspólne stronicowanie Cen, Stanów i wyboru wariantów.** `useCursorPager` i `perPage` w żądaniu stronicują Ceny,
  Stany i (przez `fetchPricePage`) wybór wariantów promocji; testy obu ekranów i wyboru wariantów to sprawdzają.
- **Zegar przy „Zakończ teraz”.** Oczekiwanie zależy od różnicy zegarów przeglądarki i serwera; ponowienia mają górną
  granicę, a stan po niej jest jawnie opisany.
- **Reka UI w jsdom.** Okna, `Select`, `RadioGroup` i `Switch` wymagają atrap z `tests/setup/jsdom-mocks.ts`.
