# ADMIN-UX-FIXES – plan zadań

Specyfikacja: `docs/features/admin-ux-fixes/spec.md`. Epik prowadzi główna sesja Claude Code z natywnymi subagentami. Epik ma
jedną gałąź (poza `epic/*`) z PR-em do `main` otwartym od początku jako szkic. Każde zadanie realizuje subagent we
własnym worktree na gałęzi zadania; sesja orkiestrująca merguje gotowe gałęzie zadań do gałęzi epiku i wypycha ją.
Zadania nie mają własnych PR. Zadania jednej fali z listą `parallel_with` mogą iść naraz. Plan jest dokumentem
roboczym: po ukończeniu epiku usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | ADMIN-UX-FIXES-1, ADMIN-UX-FIXES-2, ADMIN-UX-FIXES-3, ADMIN-UX-FIXES-4, ADMIN-UX-FIXES-5, ADMIN-UX-FIXES-6 | zmergowane: 3 (`4cb72462`), 1 (`cd639f3f`), 6 (`f61c7189`), 2 (`09839d04`), 4 (`41546740`), 5 (`049d7718`), poprawka testu 2 (`72dfec62`); fala zakończona |
| 2 | ADMIN-UX-FIXES-7 | zmergowane: 7 (`b31ffe63`) |

Kolumnę „Status” (zmergowane do gałęzi epiku, commit) uzupełnia sesja orkiestrująca po merge'u gałęzi zadania.

## Przed startem

Sesja prowadząca sprawdza na aktualnym `main` dwa warunki specyfikacji i zapisuje wynik w opisie PR epiku:

- „Zależność od API-INPUT-CONTRACT”, punkty 1–4 (dotyczą zadań 2 i 3).
- „Równoległość ze STORE-UX-FIXES (#777)”: gdy #777 ma już specyfikację albo plan, porównanie jego `Zakres:` i `paths`
  z tym planem; bez tego epiki nie idą równolegle.

## Praca szeregowa

- ADMIN-UX-FIXES-2, ADMIN-UX-FIXES-4: `apps/admin/src/promotions/PromotionVariantPicker.vue`,
  `e2e/tests/admin-screens-promotions.spec.ts`; blokada formularza w zadaniu 2 obejmuje wybór wariantów, a zadanie 4
  zmienia jego region, czyli jeden komponent i jeden plik testów ekranu promocji.
- ADMIN-UX-FIXES-2, ADMIN-UX-FIXES-5: `docs/features/admin-design/spec.md`; zadanie 2 zmienia w nim wyjątek #250 F16
  („Po zapisie”), zadanie 5 opis kolumn „Ostatnich zamówień”. Każda zmiana idzie w commicie swojego kodu, więc
  zadania zmieniają ten sam plik i idą jedno po drugim, w dowolnej kolejności.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "ADMIN-UX-FIXES",
  "spec": "docs/features/admin-ux-fixes/spec.md",
  "tasks": [
    {
      "id": "ADMIN-UX-FIXES-1",
      "title": "Combobox kategorii: fokus po wyborze myszą i aria-activedescendant",
      "scope": "Panel, komponent CategoryCombobox w trybie pojedynczym (spec, K1; #572, #627). Bez zmian w miejscach użycia komponentu, bez zmian API. Tryb wielokrotny, opcje wyłączone i Escape zostają jak dotąd.",
      "acceptance_criteria": [
        "CategoryCombobox.test.ts: po wyborze kliknięciem i Enterem, przy zamkniętej liście, pole nie ma aria-activedescendant wskazującego nieistniejący element; po kliknięciu opcji document.activeElement to pole. Raport nazywa asercję, której środowisko testu komponentu nie odtwarza przed zmianą (pokrywa ją E2E).",
        "E2E (admin-screens-inventory.spec.ts, catalog-admin.spec.ts): na filtrze /inventory, w oknie „Przenieś do…” i w polu „Kategoria główna” po kliknięciu opcji pole ma fokus (w oknie fokus zostaje wewnątrz okna), wpisany znak otwiera przefiltrowaną listę, a axe nie zgłasza aria-valid-attr-value po wyborze myszą i po wyborze Enterem.",
        "Wybór z klawiatury, tryb wielokrotny, opcje wyłączone i Escape działają jak dotąd: ich istniejące testy przechodzą bez zmian.",
        "Raport zawiera komendy i rzeczywiste wyniki testów przed zmianą (RED: testy E2E z drugiego kryterium nie przechodzą) i po niej (GREEN); make -C apps/admin check i make -C apps/admin test przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/admin/src/components/CategoryCombobox.vue",
        "apps/admin/tests/CategoryCombobox.test.ts",
        "e2e/tests/admin-screens-inventory.spec.ts",
        "e2e/tests/catalog-admin.spec.ts"
      ],
      "parallel_with": [
        "ADMIN-UX-FIXES-2",
        "ADMIN-UX-FIXES-3",
        "ADMIN-UX-FIXES-4",
        "ADMIN-UX-FIXES-5",
        "ADMIN-UX-FIXES-6"
      ]
    },
    {
      "id": "ADMIN-UX-FIXES-2",
      "title": "Formularz promocji: blokada w trakcie zapisu i odczyt statusu po „Od teraz”",
      "scope": "Panel, formularz promocji: usePromotionForm, PromotionFormView i blokada kontrolek wariantów (spec, K2; #580; decyzje D2, D3, D6 i D7) oraz zdanie o wyjątku #250 F16 w docs/features/admin-design/spec.md. Blokada przez disabled jak w DeliveryMethodFormView; SaveBar bez zmian. Status zawsze z API; bez zmian API. Poza zadaniem: oczekiwanie na koniec promocji (watchEnd), region wyboru wariantów (zadanie 4), ogólny mechanizm synchronizacji i scalanie stanu formularza z odpowiedzią.",
      "acceptance_criteria": [
        "Blokada w trakcie zapisu (wstrzymana odpowiedź): pola formularza i kontrolki wariantów (lista wybranych, „Dodaj warianty”, otwarty wybór) są wyłączone, otwarty wybór wariantów zostaje otwarty, drugie „Zapisz” nie wysyła żądania. Po 422 i po błędzie sieci pola mają wysłane wartości i są odblokowane; udany zapis i 409 pokazują promocję z API jak dotąd.",
        "Zmiana istniejącego zachowania: wyjątek #250 F16 (fokus przeniesiony do pola w trakcie żądania) nie występuje przy zapisie formularza promocji, bo pola są wyłączone; jego test w PromotionFormView.test.ts i zdanie o nim w docs/features/admin-design/spec.md („Po zapisie”: wyjątek nie dotyczy zapisu formularza promocji) zmieniają się razem z tym zachowaniem. Przy akcjach karty „Status” („Zakończ teraz”, „Usuń”) pola nie są blokowane i wyjątek działa jak dotąd. Fokus po zapisie działa według standardu formularza.",
        "Oczekiwanie na początek (fałszywy zegar): zapis z „Od teraz” i odpowiedzią planned ze startsAt sekundę po odpowiedzi daje jeden GET dokładnie 2 s po odpowiedzi zapisu; startsAt minutę po odpowiedzi daje pierwszy GET 3 s po odpowiedzi. W trakcie oczekiwania pola, kontrolki wariantów i akcje karty „Status” są wyłączone, a region role=\"status\" pokazuje „Promocja rozpoczyna się.”; po odpowiedzi active widać „Aktywna”, „Zakończ teraz”, ogłoszenie „Promocja jest aktywna.” i odblokowane pola.",
        "Ograniczone ponowienia: odpowiedź planned daje kolejny odczyt po sekundzie, najwyżej 5 razy; po ostatniej blokada znika, status zostaje „Zaplanowana”, region statusu pokazuje „Promocja jeszcze się nie rozpoczęła. Początek: <data i godzina>.” i dalszych GET nie ma.",
        "Nieudany odczyt (błąd sieci, odpowiedź inna niż 200): oczekiwanie się kończy, blokada znika, widok zostaje jak po zapisie, komunikat role=\"alert\" „Nie udało się odczytać statusu promocji. Odśwież, żeby zobaczyć jej stan.” ma przycisk „Odśwież”; udane „Odśwież” pokazuje promocję i przenosi fokus na nagłówek karty „Status”, nieudane zastępuje formularz komunikatem wczytania z linkiem do listy promocji.",
        "Całkowity limit oczekiwania (fałszywy zegar, GET bez odpowiedzi): 10 s po odpowiedzi zapisu pola są odblokowane i widać ten sam komunikat z „Odśwież”; limit unieważnia trwający odczyt tak jak nowsze żądanie formularza, więc po zmianie pola nazwy zwolniona odpowiedź active nie zmienia nazwy, statusu, wersji ani komunikatu. Opuszczenie widoku, przejście do innej promocji i „Odśwież” zatrzymują licznik limitu. Limit dotyczy tylko odczytów statusu, nie zapisu.",
        "Nieaktualne odpowiedzi: po opuszczeniu widoku, otwarciu innej promocji albo formularza nowej oraz po nowszym żądaniu formularza (zapis, „Odśwież”) nie ma kolejnych GET oczekiwania, a odpowiedź odczytu, która wróci później, niczego nie zmienia. Odpowiedź zapisu, która wraca po przejściu do innej promocji albo formularza nowej, nie zmienia formularza, nie pokazuje toastu, nie nawiguje i nie uruchamia oczekiwania. Przejście z /promotions/new na /promotions/:id po utworzeniu nie przerywa oczekiwania.",
        "Zapis z początkiem w przyszłości (bez „Od teraz”) nie daje dodatkowego GET. Panel nie wylicza statusu z zegara: zegar przeglądarki wyznacza tylko moment odczytu.",
        "E2E (admin-screens-promotions.spec.ts): po toaście „Promocja zapisana.” karta „Status” pokazuje „Aktywna” i „Zakończ teraz” w ciągu 5 s bez przeładowania strony, a pętla przeładowań znika z testu; drugi test wstrzymuje odpowiedź zapisu o 3 s (page.route), sprawdza w tym czasie, że pole nazwy jest wyłączone, i ten sam wynik po odpowiedzi.",
        "Raport zawiera komendy i rzeczywiste wyniki testów przed zmianą (RED: testy blokady, obu momentów pierwszego odczytu, ponowień, błędu odczytu, całkowitego limitu, odpowiedzi zapisu po zmianie promocji i oba testy E2E nie przechodzą) i po niej (GREEN); make -C apps/admin check i make -C apps/admin test przechodzą."
      ],
      "risk": "CRITICAL",
      "depends_on": [],
      "paths": [
        "apps/admin/src/promotions/usePromotionForm.ts",
        "apps/admin/src/promotions/PromotionVariantPicker.vue",
        "apps/admin/src/views/PromotionFormView.vue",
        "apps/admin/tests/usePromotionForm.test.ts",
        "apps/admin/tests/PromotionFormView.test.ts",
        "e2e/tests/admin-screens-promotions.spec.ts",
        "docs/features/admin-design/spec.md"
      ],
      "parallel_with": [
        "ADMIN-UX-FIXES-1",
        "ADMIN-UX-FIXES-3",
        "ADMIN-UX-FIXES-6"
      ]
    },
    {
      "id": "ADMIN-UX-FIXES-3",
      "title": "Okna zamówienia: zapis w toku bez zamknięcia, utraty fokusu i wyniku",
      "scope": "Panel, szczegóły zamówienia: okno zmiany statusu i zwrotu oraz okno numeru przesyłki (spec, K3; #581 F4; decyzje D3 i D7; bez własnego limitu czasu zapisu). Zadanie zaczyna od testu, który odtwarza wadę w oknie numeru przesyłki (potwierdzona dotąd tylko w kodzie). Bez zmian API i bez zmiany zachowania po odpowiedzi poza opisanym w K3. Poza zadaniem: #581 F3 i F5.",
      "acceptance_criteria": [
        "W trakcie zapisu (wstrzymana odpowiedź), w obu oknach: Escape, kliknięcie obok, „Wróć” i „Zamknij” nie zamykają okna, z którego wyszło żądanie; pola „Notatka” i „Numer przesyłki” nie przyjmują wpisywania; przycisk skutku ma aria-disabled=\"true\" zamiast disabled i wygląda jak przycisk ConfirmDialog z busy.",
        "Drugie kliknięcie, Enter i Spacja na przycisku skutku oraz Enter w polu numeru przesyłki dają w trakcie zapisu dokładnie jedno żądanie.",
        "Po odpowiedzi blokada znika w każdym przypadku: zapis udany (toast, zamknięcie, fokus jak dotąd); 422 (okno zostaje, pola zachowują wartości i przyjmują zmiany, błąd przy polu z fokusem w polu); 409 (okno zmiany statusu się zamyka, komunikat conflict dostaje fokus, zamówienie wczytane ponownie; okno numeru przesyłki zostaje z komunikatem i wartością pola, zamówienie wczytane ponownie); błąd sieci, 403 i inne (komunikat w oknie z fokusem na nim, pola zachowują wartości). Po 422 i po błędzie sieci kolejna próba wysyła nowe żądanie.",
        "Zmiana trasy na inne zamówienie w trakcie wstrzymanej odpowiedzi zamyka okno, późniejsza odpowiedź nie daje toastu ani zmiany nowego widoku, a okno otwarte w tym czasie dla nowego zamówienia zamyka się Escape.",
        "E2E (admin-screens-orders.spec.ts, na zamówieniu złożonym w teście; komentarz nagłówka pliku dostaje ten wyjątek): odpowiedź zmiany statusu wstrzymana o 3 s (page.route); w tym czasie przycisk skutku ma fokus, a Escape zostawia okno; po odpowiedzi jest toast „Status zamówienia zmieniony: …” i fokus na nagłówku karty.",
        "E2E, kontrola drogi wyjścia (przechodzi także przed zmianą): przy otwartym oknie i wstrzymanej odpowiedzi Wstecz przeglądarki (page.goBack()) opuszcza ekran zamówienia, okno znika, a po zwolnieniu odpowiedzi nie ma toastu. Menu panelu nie jest tą drogą: jest pod oknem modalnym.",
        "Raport zawiera komendy i rzeczywiste wyniki testów przed zmianą (RED: brak zamknięcia przy Escape, kliknięciu obok, „Wróć” i „Zamknij”, wpisywanie w trakcie zapisu, udany zapis po Escape, test E2E) i po niej (GREEN); make -C apps/admin check i make -C apps/admin test przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/admin/src/views/OrderDetailView.vue",
        "apps/admin/src/orders/",
        "apps/admin/tests/OrderDetailView.test.ts",
        "e2e/tests/admin-screens-orders.spec.ts"
      ],
      "parallel_with": [
        "ADMIN-UX-FIXES-1",
        "ADMIN-UX-FIXES-2",
        "ADMIN-UX-FIXES-4",
        "ADMIN-UX-FIXES-5",
        "ADMIN-UX-FIXES-6"
      ]
    },
    {
      "id": "ADMIN-UX-FIXES-4",
      "title": "Wybór wariantów promocji: region o ograniczonej wysokości z testem geometrii i klawiatury",
      "scope": "Panel, PromotionVariantPicker: region „Warianty do wyboru” (spec, K4; #575 i część #584). Bezpośredni test E2E regionu; helper e2e/support/panel-layout.ts zostaje bez zmian. Poza zadaniem: pozostałe części #584 i blokada wyboru w trakcie zapisu (zadanie 2).",
      "acceptance_criteria": [
        "E2E (admin-screens-promotions.spec.ts) przy 1280×720 i 375×812, w formularzu nowej promocji z widocznym paskiem zapisu, na stronie wyboru z ponad 100 wierszami z danych demo: wysokość regionu nie większa niż 60% wysokości okna (tolerancja 1 px), a scrollHeight regionu większe niż clientHeight.",
        "Po otwarciu wyboru („Dodaj warianty”) test przewija stronę tak, żeby pole szukania było tuż pod górnym paskiem panelu (albo do końca strony, gdy dalej przewinąć się nie da), i sprawdza rzeczywiste krawędzie: górna krawędź pola nie wyżej niż dolna krawędź górnego paska, dolna krawędź przycisków pagera nie niżej niż górna krawędź paska zapisu, a elementFromPoint w środku pola i w środku każdego przycisku pagera trafia w ten element (nie zasłania ich przyklejony pasek).",
        "Klawiatura: po fokusie regionu End zwiększa jego scrollTop; Tab z regionu trafia na pierwszy „Dodaj…”, a Shift+Tab z pierwszego aktywnego przycisku pagera na ostatni; każdy z nich po fokusie leży w całości w widocznym prostokącie regionu i okna, a elementFromPoint w jego środku trafia w niego.",
        "Po „Dodaj” na ostatnim wierszu fokus jest na polu szukania, a pole leży w oknie.",
        "Asercje są asercjami geometrii, nie obecności klas CSS. Raport zawiera komendy i rzeczywiste wyniki tych testów E2E przed zmianą (RED) i po niej (GREEN); make -C apps/admin check i make -C apps/admin test przechodzą."
      ],
      "risk": "FAST",
      "depends_on": [],
      "paths": [
        "apps/admin/src/promotions/PromotionVariantPicker.vue",
        "apps/admin/tests/PromotionVariantPicker.test.ts",
        "e2e/tests/admin-screens-promotions.spec.ts"
      ],
      "parallel_with": [
        "ADMIN-UX-FIXES-1",
        "ADMIN-UX-FIXES-3",
        "ADMIN-UX-FIXES-5",
        "ADMIN-UX-FIXES-6"
      ]
    },
    {
      "id": "ADMIN-UX-FIXES-5",
      "title": "Pulpit: „Ostatnie zamówienia” mieszczą się w karcie przy 1280 i 1440 px",
      "scope": "Panel, karta RecentOrders pulpitu (spec, K5; #509; decyzja D4), wspólny kod cienia krawędzi z DataTable w apps/admin/src/components/list (bez zmiany zachowania DataTable) i opis kolumn karty w docs/features/admin-design/spec.md („Ostatnie zamówienia”). Bez zmian API, układu kart pulpitu i stanów karty.",
      "acceptance_criteria": [
        "E2E (staff-dashboard.spec.ts), odpowiedź listy zamówień karty podstawiona przez page.route (pięć zamówień, w tym ze statusem o najdłuższej etykiecie), rozmiary okna ustawia test: przy 1280×720 i 1440×900 (administrator, karta obok „Do obsługi”) scrollWidth regionu nie większe niż clientWidth, a etykieta statusu każdego wiersza leży w całości w regionie.",
        "Przy 1280×720 każdy wiersz pokazuje numer, klienta, datę, kwotę i status; data jest drugą linią komórki „Klient”; „Pozycje” są kolumną tylko wtedy, gdy tabela mieści się z nią w karcie (próg od szerokości karty, container query), a poniżej progu liczba pozycji jest linią komórki „Klient”.",
        "Przy 375×812 strona nie przewija się w poziomie; gdy region się przewija, cień przy końcowej krawędzi jest widoczny, a po przewinięciu do końca widoczny jest cień przy początkowej. Raport podaje zmierzone scrollWidth i clientWidth regionu przy 375 px; gdy tabela mieści się tam bez przewijania, test sprawdza cień przy 320×640.",
        "Cień krawędzi karty i DataTable korzystają ze wspólnego kodu w apps/admin/src/components/list, bez drugiej kopii logiki; testy DataTable przechodzą bez zmiany asercji.",
        "DashboardView.test.ts sprawdza nową strukturę wiersza (data i liczba pozycji w komórce klienta); stany wczytywania, pusty, błędu z „Spróbuj ponownie” i 403 oraz link „Wszystkie zamówienia →” zostają bez zmian i ich testy przechodzą bez zmian.",
        "docs/features/admin-design/spec.md opisuje nowe kolumny karty.",
        "Asercje E2E są asercjami geometrii, nie obecności klas CSS. Raport zawiera komendy i rzeczywiste wyniki tych testów przed zmianą (RED: szerokość regionu przy 1280 i 1440 px oraz cień krawędzi) i po niej (GREEN); make -C apps/admin check i make -C apps/admin test przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/admin/src/dashboard/",
        "apps/admin/src/components/list/",
        "apps/admin/tests/DashboardView.test.ts",
        "apps/admin/tests/DataTable.test.ts",
        "e2e/tests/staff-dashboard.spec.ts",
        "docs/features/admin-design/spec.md"
      ],
      "parallel_with": [
        "ADMIN-UX-FIXES-1",
        "ADMIN-UX-FIXES-3",
        "ADMIN-UX-FIXES-4",
        "ADMIN-UX-FIXES-6"
      ]
    },
    {
      "id": "ADMIN-UX-FIXES-6",
      "title": "Historia ceny: polska nazwa klasy podatkowej",
      "scope": "Panel, okno „Historia ceny” (PriceDialogs) otwierane z /prices i z karty „Ceny i stany” formularza produktu (spec, K6; #579). Lista cen ma słownik klas; formularz produktu przekazuje karcie słownik, który już wczytał (bez dodatkowego żądania). Bez zmian API.",
      "acceptance_criteria": [
        "PricesView.test.ts i ProductPriceStock.test.ts: kolumna „Klasa” historii ceny (i jej linia w komórce daty poniżej 1024 px) pokazuje nazwę ze słownika klas podatkowych.",
        "Bez słownika (błąd GET /tax-classes na liście cen, pusty słownik w karcie produktu) i dla kodu spoza słownika historia pokazuje kod, jak lista cen.",
        "Formularz produktu przekazuje karcie „Ceny i stany” słownik, który już wczytał; otwarcie historii z karty nie wysyła dodatkowego GET /tax-classes.",
        "E2E (admin-screens-prices.spec.ts): klasa w historii ceny wariantu jest tym samym tekstem co klasa w jego wierszu listy.",
        "Raport zawiera komendy i rzeczywiste wyniki testów przed zmianą (RED: test nazwy nie przechodzi) i po niej (GREEN); make -C apps/admin check i make -C apps/admin test przechodzą."
      ],
      "risk": "FAST",
      "depends_on": [],
      "paths": [
        "apps/admin/src/prices/PriceDialogs.vue",
        "apps/admin/src/views/PricesView.vue",
        "apps/admin/src/views/ProductFormView.vue",
        "apps/admin/src/products/ProductPriceStock.vue",
        "apps/admin/tests/PricesView.test.ts",
        "apps/admin/tests/ProductPriceStock.test.ts",
        "apps/admin/tests/ProductFormView.test.ts",
        "e2e/tests/admin-screens-prices.spec.ts"
      ],
      "parallel_with": [
        "ADMIN-UX-FIXES-1",
        "ADMIN-UX-FIXES-2",
        "ADMIN-UX-FIXES-3",
        "ADMIN-UX-FIXES-4",
        "ADMIN-UX-FIXES-5"
      ]
    },
    {
      "id": "ADMIN-UX-FIXES-7",
      "title": "Granice części E2E po dodanych testach i kryteria epiku",
      "scope": "CI: E2E_SHARD_WEIGHTS i komentarz kroku make e2e w .github/workflows/ci.yml, przeliczone według e2e/CLAUDE.md po testach zadań 1–6 (spec, „Kryteria akceptacji epiku”, punkty 2–4). Gdy #777 zmergował się wcześniej, przeliczenie następuje po rebase na main (spec, „Równoległość ze STORE-UX-FIXES”).",
      "acceptance_criteria": [
        "E2E_SHARD_WEIGHTS i komentarz kroku make e2e odpowiadają liczbie testów po zadaniach 1–6; raport pokazuje wynik komendy listującej testy części z e2e/CLAUDE.md dla każdej z trzech części.",
        "make -C apps/admin check, make -C apps/admin test i make e2e przechodzą na gałęzi epiku.",
        "git diff względem main nie zawiera apps/api, apps/api/openapi*.json ani apps/admin/src/api/schema.d.ts."
      ],
      "risk": "FAST",
      "depends_on": [
        "ADMIN-UX-FIXES-1",
        "ADMIN-UX-FIXES-2",
        "ADMIN-UX-FIXES-3",
        "ADMIN-UX-FIXES-4",
        "ADMIN-UX-FIXES-5",
        "ADMIN-UX-FIXES-6"
      ],
      "paths": [
        ".github/workflows/ci.yml"
      ],
      "parallel_with": []
    }
  ]
}
```
