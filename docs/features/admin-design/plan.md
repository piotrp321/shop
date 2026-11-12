# ADMIN-DESIGN – plan zadań

Specyfikacja: `docs/features/admin-design/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | ADMIN-DESIGN-1 | |
| 2 | ADMIN-DESIGN-2 | |
| 3 | ADMIN-DESIGN-3, ADMIN-DESIGN-4, ADMIN-DESIGN-5 | |
| 4 | ADMIN-DESIGN-6 | |

Stan epiku prowadzonego przez AO jest w `ao status`, a plan usuwa `FINALIZE`.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "ADMIN-DESIGN",
  "spec": "docs/features/admin-design/spec.md",
  "tasks": [
    {
      "id": "ADMIN-DESIGN-1",
      "title": "Fundament: Tailwind 4, shadcn-vue, tokeny, krój Nunito i reguły koloru",
      "scope": "apps/admin: zależności z sekcji „Biblioteka komponentów i zależności” (bez TanStack Table), `components.json`, `src/styles/tokens.css` z tabelą tokenów i mapowaniem nazw shadcn-vue („Tokeny i typografia”), pliki Nunito w `src/assets/fonts/` i `THIRD_PARTY_NOTICES.md`, komponenty bazowe w `src/components/ui/` potrzebne kolejnym zadaniom (Button, Input, Label, Textarea, Select, Combobox, Switch, Checkbox, Dialog, AlertDialog, Sheet, DropdownMenu, Tooltip, Badge, Card, Table, Skeleton, Alert, Sonner, Breadcrumb) z polskimi nazwami dostępnymi, `CategoryCombobox` na `useCategoryTree` (sekcja „Produkty”, tryb pojedynczy i wielokrotny; widoki produktów podłączają go zadania 4 i 5, ceny i stany ADMIN-SCREENS), reguły ESLint koloru i `<style>` z listą `LEGACY_FILES` (wszystkie dzisiejsze widoki i komponenty z PrimeVue), test kontrastu tokenów, atrapy jsdom dla Reka UI w konfiguracji Vitest. PrimeVue zostaje i działa obok; żaden widok nie zmienia wyglądu poza skutkami preflightu. Dokumentacja: nowy `docs/adr/admin-ui-library.md` (pakiety, wersje, licencje), `docs/vision.md` (tabela „Aplikacje”), `README.md`, główny `CLAUDE.md` (linia `apps/admin`), `apps/admin/CLAUDE.md` (nagłówek, „Wygląd”, „Struktura”, „Testy”), tabela zależności panelu w `docs/features/walking-skeleton/spec.md`, komentarz `style-src` w `docker/security-headers.conf`. Bez zmian w API, e2e i infra.",
      "acceptance_criteria": [
        "Kryterium 2 specyfikacji: `tests/tokens.test.ts` sprawdza kontrast każdej pary z tabeli tokenów; `muted-foreground` = `#6b7280` albo `input-border` = `#dfe2e8` oblewa test.",
        "Kryterium 3 specyfikacji dla plików spoza `LEGACY_FILES`: ESLint zgłasza kolory arbitralne w klasach szablonu i w łańcuchach `<script>`/`.ts`, `bg-red-500`, statyczny `style` i blok `<style>`; nie zgłasza klas z tokenów; test reguł sprawdza identyfikator i liczbę błędów; test sprawdza też, że każdy wpis `LEGACY_FILES` istnieje i importuje `primevue`.",
        "Kryterium 4 specyfikacji: Nunito z plików panelu (podzbiory `latin` i `latin-ext`, wagi 400–800), wpis OFL z adresem źródła, wersją i SHA-256 w `apps/admin/THIRD_PARTY_NOTICES.md`; sprawdzone `tnum` (albo stos systemowy dla kolumn liczbowych, zapisany w `apps/admin/CLAUDE.md`).",
        "Nowe zależności to dokładnie pakiety z tabeli specyfikacji bez `@tanstack/vue-table`; licencje rozwiązanych wersji (MIT, ISC, Apache-2.0) i wersje są w ADR.",
        "`CategoryCombobox`: rola `combobox` z nazwą z etykiety, opcje w kolejności drzewa z pełną ścieżką w nazwie dostępnej, filtrowanie po nazwie i ścieżce, tryb wielokrotny z „Usuń: <nazwa>”, brak nienazwanych przycisków (testy komponentu).",
        "Komponenty bazowe nie mają angielskich nazw dostępnych (np. „Close”); test komponentu Dialog i AlertDialog sprawdza „Zamknij”.",
        "Przechodzą `make -C apps/admin check`, `make -C apps/admin test` i `make e2e` (istniejące testy panelu bez zmian)."
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "ADMIN-DESIGN-2",
      "title": "Powłoka panelu: sidebar, topbar, okruszki, szuflada, toasty, okna potwierdzenia i adres sklepu",
      "scope": "apps/admin: sekcja „Powłoka” specyfikacji: `src/layout/` (AppLayout, AppMenu z grupami, podpozycjami i `aria-current`, AppTopbar z wyszukiwarką produktów 2–100 znaków, linkiem „Sklep”, kontem z polskimi rolami i „Wyloguj”), komponent okruszków i nagłówka strony, link „Przejdź do treści”, szuflada poniżej 1024 px, stopka, kontener toastów, `ConfirmDialog` i reguła fokusu, gdy wyzwalacz zniknął („Standard formularza i okien”), strażnik `/orders` i `/orders/:id` (z `history.state.denied` i komunikatem na pulpicie, błąd `/me` przepuszcza), wygląd `App.vue` (problem z logowaniem) i `AuthCallbackView`. Konfiguracja `VITE_STORE_URL`: `src/config`, `env.d.ts`, `docker/40-admin-config.sh`, `Dockerfile` (komentarz), `infra/docker-compose.yml` (usługa `admin`), `infra/k8s/charts/shop/templates/admin.yaml`, `infra/k8s/smoke/checks.py`, `infra/k8s/tests/test_workloads.py`, nowy `infra/k8s/tests/test_admin_config.py`. Lista zamówień (`useOrderList`, widok bez zmian wyglądu) czyta z adresu `status` i `refund=pending` przy wejściu i przy zmianie `route.query` (sekcja „Standard listy”, stare listy), bo prowadzi do niej pozycja menu „Zwroty do wykonania”. Pliki powłoki schodzą z `LEGACY_FILES`. E2E: pomocnicze nawigujące przez menu (`e2e/support/catalog-admin.ts`, `panel-orders.ts`, `price-change.ts`, `promotions.ts`, `discount-codes.ts`, `e2e/tests/stock-receipt.spec.ts`) używają nowych pozycji menu. Dokumentacja: `apps/admin/CLAUDE.md` („Struktura” `src/layout`, „Konfiguracja”: cztery adresy), opis layoutu w `docs/features/walking-skeleton/spec.md`, strażnik trasy i filtry z adresu w `docs/features/order-management/spec.md`, opis `useOrderList` w `apps/admin/CLAUDE.md`. Treść pulpitu, ekran „Stan systemu” i jego strażnik należą do ADMIN-DESIGN-3; widoki list i formularzy zostają na PrimeVue.",
      "acceptance_criteria": [
        "Menu według tabeli sekcji „Powłoka”: grupy z `aria-expanded`, grupa bieżącej trasy rozwinięta, tylko bieżąca pozycja ma `aria-current=\"page\"`, „Pulpit” wyróżniony wyłącznie na `/`, formularze i szczegóły podświetlają pozycję listy, `/orders?refund=pending` podświetla „Zwroty do wykonania” (tylko `admin` i `support`); testy komponentu dla każdej roli i przed wczytaniem `/me`.",
        "Kryterium 18 specyfikacji: wyszukiwarka topbaru (2–100 znaków, podpowiedź „Wpisz od 2 do 100 znaków.” bez nawigacji dla krótszej i dłuższej frazy), link „Sklep” z `VITE_STORE_URL` w nowej karcie, polskie role; `40-admin-config.sh` bez `VITE_STORE_URL`, z pustą wartością i z białym znakiem kończy start błędem z nazwą zmiennej, z czterema zmiennymi zapisuje cztery klucze (`test_admin_config.py`); `test_workloads.py` i test dymny sprawdzają zmienną.",
        "Link „Przejdź do treści” jest pierwszym elementem Tab i przenosi fokus na `<main>`; okruszki z `aria-current` na ostatniej pozycji; poniżej 1024 px szuflada z pułapką fokusu, `Escape` zamyka i fokus wraca na „Menu”, wybór pozycji zamyka szufladę (testy komponentu).",
        "Strażnik: rola bez dostępu do `/orders` ląduje na pulpicie z komunikatem „Nie masz dostępu do tej strony.”, który nie wraca po przeładowaniu; błąd `/me` przepuszcza trasę (testy routera).",
        "Kryterium 19 specyfikacji dla zamówień: `/orders?status=paid` i `/orders?refund=pending` pokazują przefiltrowaną listę, wybór „Zwroty do wykonania” w menu przełącza otwartą listę bez przeładowania, nieznana wartość jest pomijana (testy `useOrderList` i E2E).",
        "`ConfirmDialog`: tytuł z nazwą obiektu, przycisk skutku (wariant niszczący czerwony), „Wróć” z domyślnym fokusem, `Escape` oddaje fokus na wyzwalacz, a gdy wyzwalacza nie ma, na wskazany nagłówek; toast w regionie `aria-live=\"polite\"` (testy komponentu).",
        "Problem z logowaniem i powrót z logowania w nowym wyglądzie zachowują „Zaloguj ponownie” i dzisiejsze zachowanie (istniejące testy `App` i `AuthCallbackView` przechodzą).",
        "Kryterium 20 specyfikacji: istniejące testy E2E panelu przechodzą z nową nawigacją; przechodzą `make -C apps/admin check`, `make -C apps/admin test`, `make -C infra/k8s check` i `make e2e`."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "ADMIN-DESIGN-1"
      ]
    },
    {
      "id": "ADMIN-DESIGN-3",
      "title": "Pulpit i ekran „Stan systemu”",
      "scope": "apps/admin: sekcja „Pulpit i Stan systemu”: `DashboardView` (H1 „Pulpit”, `KpiTile`, „Do obsługi” według ról, „Ostatnie zamówienia” z `GET /orders?perPage=5` dla ról z dostępem, komunikat po strażniku), nowy widok i trasa `/system` tylko dla `admin` (strażnik, pozycja menu w sekcji System), `src/dashboard/` (polskie etykiety usług i zdarzeń, świeżość danych na obu ekranach, „Dane z …” po polsku). Listy promocji i kodów (`usePromotionList`, `useDiscountCodeList`, widoki bez zmian wyglądu) czytają `status` z adresu przy wejściu i przy zmianie `route.query`, bo prowadzą do nich linki „Do obsługi”. Pliki pulpitu schodzą z `LEGACY_FILES`. E2E: `e2e/tests/staff-dashboard.spec.ts` (sekcja „Stan systemu” na `/system`), `e2e/support/staff-login.ts` (nagłówek „Pulpit”). Dokumentacja: `apps/admin/CLAUDE.md` (`src/dashboard`, ekran „Stan systemu”), `docs/features/walking-skeleton/spec.md` („Layout i dashboard”), `docs/features/dashboard-data-freshness/spec.md`, opisy `usePromotionList` i `useDiscountCodeList` w `apps/admin/CLAUDE.md`. Bez kafli sprzedaży, liczników w „Do obsługi” i zmian API.",
      "acceptance_criteria": [
        "Kryterium 16 specyfikacji: świeżość danych pulpitu jak dziś, kafel „Produkty” linkiem do `/products`, kafle „Kategorie” i „Bez zdjęć” bez linku, „Do obsługi” według ról (test dla każdej roli, każda rola widzi co najmniej jeden wiersz), „Ostatnie zamówienia” do 5 pozycji z `itemCount`, bez `GET /orders` dla `marketing`, błąd tej karty nie psuje reszty pulpitu.",
        "Kryterium 17 specyfikacji: `/system` dla `admin` z „Działa”/„Nie działa”, „Zdarzenia czekające na wysłanie”, kolejkami i indeksem; testy świeżości przechodzą dla `/system`; `marketing` na `/system` ląduje na pulpicie z komunikatem o braku dostępu.",
        "Tekst „Dane z …” ma kolor `muted-foreground` i godzinę polską z `formatDateTime` (#346; weryfikacja E2E w ADMIN-DESIGN-6).",
        "Kryterium 19 specyfikacji dla promocji i kodów: `/promotions?status=active` i `/discount-codes?status=active` pokazują przefiltrowane listy, zmiana `route.query` przy zamontowanym widoku przełącza filtr, nieznana wartość jest pomijana; pozostałe testy tych list przechodzą bez zmian asercji.",
        "`staff-dashboard.spec.ts` i logowanie pracownika w E2E przechodzą z nagłówkiem „Pulpit” i sekcją systemu na `/system`.",
        "Przechodzą `make -C apps/admin check`, `make -C apps/admin test` i `make e2e`."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "ADMIN-DESIGN-2"
      ]
    },
    {
      "id": "ADMIN-DESIGN-4",
      "title": "Standard listy i lista produktów",
      "scope": "apps/admin: sekcja „Standard listy”: `src/components/list/` (`DataTable` na `Table` shadcn-vue z priorytetami kolumn i liniami kolumn ukrytych w komórce głównej, `ListToolbar`, `ListPager` z „Pokazano X–Y” i fokusem, `ListEmpty`, `RowActions` z podpowiedziami), `useListUrlState` (walidacja parametrów, obserwacja `route.query`, `router.replace`) i `useCursorPager` (stos kursorów, `latest`, `retry`, `perPage`, trzy przypadki błędu). Migracja listy produktów: `useProductList` na `useCursorPager` i `useListUrlState`, `ProductsView` według sekcji „Produkty” (kolumny, placeholder miniatury, „Podgląd w sklepie” z `VITE_STORE_URL` dla opublikowanego, `CategoryCombobox`, Select statusu, 10/25/50). Pliki listy produktów schodzą z `LEGACY_FILES`. Dokumentacja: `apps/admin/CLAUDE.md` (`src/components/list`, opis `useProductList`, standard listy w „Wygląd”). Pozostałe listy migruje ADMIN-SCREENS (#462); bez sortowania i zaznaczania.",
      "acceptance_criteria": [
        "Kryterium 9 specyfikacji: stan listy produktów z adresu i do adresu (`q`, `category`, `status`, `perPage`), nieznane wartości i nieistniejąca kategoria pominięte, za krótkie `q` pokazane bez filtrowania, zmiana `route.query` z zewnątrz przy zamontowanym widoku daje jedno żądanie od pierwszej strony (testy `useListUrlState`).",
        "Kryterium 10 specyfikacji: po błędzie zmiany zapytania brak wierszy i pagera, po błędzie sąsiedniej strony wyświetlona strona zostaje, po ponowieniu z 403 fokus na komunikacie; `useProductList` nie ma własnego stosu kursorów (#251 F10).",
        "Kryterium 11 specyfikacji: „Pokazano 26–50” na drugiej stronie przy 25, fokus pagera według standardu.",
        "Kryterium 12 specyfikacji: puste stany „Nie ma jeszcze produktów.” (z „Dodaj produkt” dla `admin` i `marketing`) i „Brak wyników dla wybranych filtrów.” z „Wyczyść filtry”.",
        "Lista produktów: kolumny, placeholder miniatury, godzina z sekundami, akcje z nazwą obiektu i podpowiedzią, „Podgląd w sklepie” tylko dla opublikowanego z adresem `<VITE_STORE_URL>/p/<slug>`; poniżej 1024 i 900 px kategoria i ostatnia zmiana jako linie komórki głównej (testy komponentu); kryteria 7 i 22 specyfikacji (weryfikacja E2E w ADMIN-DESIGN-6).",
        "Zachowane funkcje listy produktów ze spisu specyfikacji (wyszukiwanie od 2 znaków, filtry, stronicowanie, link do edycji) mają testy; testy `useProductList` przechodzą bez zmian asercji poza przeniesionym stronicowaniem.",
        "Przechodzą `make -C apps/admin check`, `make -C apps/admin test` i `make e2e`."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "ADMIN-DESIGN-2"
      ]
    },
    {
      "id": "ADMIN-DESIGN-5",
      "title": "Standard formularza i formularz produktu",
      "scope": "apps/admin: sekcja „Standard formularza i okien” (układ dwóch kolumn, `SaveBar` ze `scroll-padding-bottom`, `useUnsavedChangesGuard` zastępujący `window.confirm`, fokus po zapisie i po nieudanym zapisie, toasty) i sekcja „Produkty” dla formularza: `ProductFormView` (karty „Dane produktu”, „Zdjęcia”, „Warianty”, boczne „Status” i „Organizacja” z `CategoryCombobox`, Select klasy podatkowej z nazwą z etykiety, H1 błędu wczytania), `ProductImages.vue` (fokus po usunięciu i ukryciu wyniku, `role=\"alert\"` błędu), `ProductVariants.vue` (`ConfirmDialog` z nazwą wariantu). Pliki formularza produktu schodzą z `LEGACY_FILES`. E2E: `e2e/support/catalog-admin.ts` (wybór kategorii bez klas PrimeVue), `e2e/tests/catalog-admin.spec.ts` w razie potrzeby. Dokumentacja: `apps/admin/CLAUDE.md` (`src/products`, standard formularza w „Wygląd”), `docs/features/catalog-admin/spec.md` (wynik akcji, okno wariantu, strażnik). Logika `useProductForm` bez zmian; cena i stan w formularzu poza zakresem.",
      "acceptance_criteria": [
        "Kryterium 13 specyfikacji dla formularza produktu: pasek zapisu po pierwszej zmianie, „Nowy produkt” z „Anuluj” od początku, znika po zapisie; pole z fokusem nie jest pod paskiem (E2E: prostokąt pola nad prostokątem paska); przejście z niezapisanymi zmianami pyta oknem, „Zostań” zostawia formularz, zamknięcie karty ostrzega poza wylogowaniem.",
        "Po zapisie toast „Produkt zapisany.” i fokus na H1, chyba że pracownik przeniósł fokus do pola w trakcie żądania (#250 F16); po nieudanym fokus na pierwszym błędzie; publikacja i wycofanie z toastem i fokusem na nagłówku karty „Status” (kryteria 14 i 15).",
        "#251 F9: błąd wczytania istniejącego produktu ma H1 „Nie udało się wczytać produktu”; #251 F12: usunięcie istniejącego wariantu pyta `ConfirmDialog` „Usunąć wariant <etykieta>?” z „Zamknij” po polsku (testy komponentu, nazwy w testach z numerem zgłoszenia).",
        "#250 F8 i F14: po usunięciu zdjęcia fokus na następnym zdjęciu albo przycisku dodawania, po ukryciu wyniku wysyłania nie na `body`; błąd wysyłania w `role=\"alert\"` (testy komponentu).",
        "Klasa podatkowa ma nazwę dostępną „Klasa podatkowa”, kategorie przez `CategoryCombobox` bez nienazwanych przycisków (#250 F13).",
        "Zachowane funkcje formularza produktu ze spisu specyfikacji mają testy; testy `useProductForm` i `useImageUpload` przechodzą bez zmian asercji; `catalog-admin.spec.ts` przechodzi bez selektorów PrimeVue.",
        "Przechodzą `make -C apps/admin check`, `make -C apps/admin test` i `make e2e`."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "ADMIN-DESIGN-2"
      ]
    },
    {
      "id": "ADMIN-DESIGN-6",
      "title": "Test E2E wyglądu i dostępności części 1 i weryfikacja UX",
      "scope": "Nowy `e2e/tests/admin-design.spec.ts`: kryteria 5–8, 21 i 22 specyfikacji na ekranach tego epiku (pulpit z komunikatem braku dostępu, `/system`, lista produktów, nowy produkt, edycja produktu z oknami, szuflada i menu konta) oraz testy E2E z kolumny „Weryfikacja” (#250, #251 F15, #346) nazwane numerami zgłoszeń. Proces UX według sekcji „Weryfikacja UX i odbiór”: `.claude/agents/ux-reviewer.md` i `docs/features/agent-tester-ux/spec.md` (panel przy 1280, 768 i 375 px, wygląd na zrzutach każdego ekranu, przejście samą klawiaturą, reflow 320 px, tabela weryfikacji zgłoszeń, niezmigrowane ekrany tylko pod kątem regresji). Poprawki w `apps/admin` wymagane, żeby te testy przeszły, należą do zadania. Bez migracji ekranów ADMIN-SCREENS i bez usuwania PrimeVue.",
      "acceptance_criteria": [
        "Kryteria 5 i 6 specyfikacji: axe (`wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22aa`) bez naruszeń przy 1280 i 768 px na ekranach i oknach z kryterium 5; skip link na każdej stronie panelu, `aria-current` i szuflada.",
        "Kryteria 7, 8, 21 i 22 specyfikacji: akcje i status listy produktów przy 768 px, okna przy 375 px, reflow przy 320 × 640 px na ekranach tego epiku, kategoria i ostatnia zmiana w komórce głównej.",
        "Kryterium 24 specyfikacji: #250, #251 i #346 mają testy z numerem w nazwie (w tym zadaniu albo w zadaniu ekranu), a każde przechodzi.",
        "Kryterium 23 specyfikacji: pozycje spisu „Powłoka”, „Pulpit i Stan systemu” i „Produkty” mają test albo test E2E (lista w opisie PR).",
        "Kryterium 25 specyfikacji: definicja agenta UX i specyfikacja `agent-tester-ux` opisują 1280, 768 i 375 px, zrzuty, przejście klawiaturą, reflow i tabelę weryfikacji zgłoszeń; testy skryptów przechodzą.",
        "Kryteria 20 i 26 specyfikacji: istniejące testy E2E panelu przechodzą; przechodzą `make check`, `make -C apps/admin test`, `make e2e` i `make -C infra/k8s check`."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "ADMIN-DESIGN-3",
        "ADMIN-DESIGN-4",
        "ADMIN-DESIGN-5"
      ]
    }
  ]
}
```
