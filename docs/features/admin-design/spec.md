# ADMIN-DESIGN: profesjonalny wygląd panelu, powłoka i standardy list i formularzy

Panel `apps/admin` działa, ale wygląda jak niedostosowany szablon (domyślny motyw PrimeVue Aura), ma kontrasty poniżej
WCAG AA i każdy ekran ma inny układ listy i formularza. Epik przenosi panel z PrimeVue na Tailwind CSS 4 i shadcn-vue
(ten sam rodzaj komponentów co sklep), nadaje mu wygląd narzędzia marki Worf według zaakceptowanej makiety właściciela
i wprowadza jeden standard powłoki, listy, formularza i okna potwierdzenia. To część 1 z dwóch: ten epik buduje
fundament, powłokę, standardy i przenosi na nie pulpit, „Stan systemu” i produkty; ceny, stany, promocje, kody rabatowe,
zamówienia i usunięcie PrimeVue przenosi część 2, ADMIN-SCREENS (#462). Standardy z tej specyfikacji obowiązują
w całym panelu. Epik nie zmienia API ani wygenerowanego klienta: pokazuje wyłącznie dane, które API już zwraca. Funkcje wymagające API
(KPI sprzedaży, liczniki, sortowanie, kategorie, klienci, akcje masowe, wyszukiwarka globalna) są w ADMIN-FEATURES (#461).

Issues: #460, #250, #251, #346
Zakres: `apps/admin`, `infra/docker-compose.yml`, `infra/k8s/charts/shop/templates/admin.yaml`, `infra/k8s/smoke/checks.py`, `infra/k8s/tests/test_workloads.py`, `e2e/support/catalog-admin.ts`, `e2e/support/panel-orders.ts`, `e2e/support/staff-login.ts`, `e2e/support/price-change.ts`, `e2e/support/promotions.ts`, `e2e/support/discount-codes.ts`, `e2e/tests/staff-dashboard.spec.ts`, `e2e/tests/stock-receipt.spec.ts`, `e2e/tests/catalog-admin.spec.ts`, `e2e/tests/order-management.spec.ts`, `e2e/tests/price-change.spec.ts`, `e2e/tests/promotions.spec.ts`, `e2e/tests/discount-codes.spec.ts`, `CLAUDE.md`, `README.md`, `docs/vision.md`, `docs/features/walking-skeleton/spec.md`, `docs/features/dashboard-data-freshness/spec.md`, `docs/features/catalog-admin/spec.md`, `docs/features/order-management/spec.md`, `docs/features/agent-tester-ux/spec.md`, `.claude/agents/ux-reviewer.md`

## Cel i zakres

Cel: pracownik sklepu dostaje panel, który wygląda jak spójne, profesjonalne narzędzie Worf i spełnia WCAG 2.2 AA,
a standardy listy, formularza i okien są gotowe dla wszystkich ekranów. Kryterium sukcesu: axe (reguły WCAG 2.0–2.2
A i AA) nie zgłasza naruszeń na ekranach tego epiku przy 1280 i 768 px, ekrany jeszcze niezmigrowane działają jak dziś,
a zgłoszenia z linii `Issues` są zamknięte.

W zakresie: fundament (pakiety, tokeny, krój, reguły ESLint, komponenty bazowe), powłoka panelu (wspólna dla
wszystkich ekranów), pulpit, nowy ekran „Stan systemu”, produkty (lista, nowy produkt, edycja ze zdjęciami
i wariantami), ekran problemu z logowaniem (`App.vue`) i powrotu z logowania (`AuthCallbackView`), standardy listy,
formularza i okien (zastosowane do produktów) oraz odczyt filtrów z adresu w listach zamówień, promocji i kodów,
do których prowadzą menu i pulpit (sekcja „Standard listy”).

Poza tym epikiem, w ADMIN-SCREENS (#462): ekrany cen, stanów, promocji, kodów rabatowych i zamówień w nowym wyglądzie
i standardach, usunięcie PrimeVue. Do tego czasu te ekrany zostają na PrimeVue w nowej powłoce.

Użytkownicy i uprawnienia bez zmian: role `admin`, `support`, `warehouse`, `marketing` z `GET /api/v1/admin/me`; panel
tylko ukrywa elementy, uprawnienia egzekwuje API. Wszystkie liczby (ceny, sumy, liczniki, statusy, poziomy) liczy API.

## Wzorzec wyglądu

Wzorcem jest zaakceptowana 2026-11-12 makieta właściciela (dwa ekrany: lista produktów i pulpit, 1440 px). Makieta
nie jest w repo; ta specyfikacja opisuje ją słowami i tokenami i jest jedynym źródłem wymagań. Nie kopiujemy kodu,
ikon ani grafik żadnego cudzego szablonu.

Układ ekranu przy szerokości od 1024 px:

- **Sidebar** po lewej, 260 px, ciemny (grafit), na całą wysokość. U góry znak Worf: słowo „Worf” (biały, 800,
  ok. 27 px, ciasne odstępy liter) poprzedzone pomarańczowym rombem (kwadrat 10 px obrócony o 45°). Pod znakiem menu
  w grupach z nagłówkami sekcji (wielkie litery, 12 px, 700, rozstrzelone). Pozycja: ikona Lucide 20 px, etykieta
  15 px 600; rozwinięta grupa ma jaśniejsze tło. Podpozycje są wcięte (drugi i trzeci poziom), bieżąca jest biała
  i pogrubiona.
- **Topbar** biały, 70 px, z delikatnym cieniem: wyszukiwarka (zaokrąglone pole), link „Sklep” z ikoną otwarcia
  w nowej karcie, po prawej konto pracownika (kółko z inicjałami w pomarańczowym gradiencie, nazwa użytkownika, polska
  rola pod spodem, rozwijane menu z „Wyloguj”).
- **Nagłówek strony** w treści: tytuł H1 (20 px, 700) po lewej, okruszki po prawej (`Pulpit › Katalog › Produkty`,
  bieżąca pozycja bez linku).
- **Treść** na jasnoszarym tle, odstępy 24 px; elementy w białych kartach z promieniem 8 px i delikatnym cieniem.
  Karta listy: pasek narzędzi u góry (oddzielony przerywaną linią), tabela, stopka z zakresem i pagerem.
- **Kafel KPI**: tytuł (16 px, 700) z ikoną linku w prawym górnym rogu (gdy kafel jest linkiem), ikona w pastelowym
  kółku 46 px, liczba (26 px, 700, cyfry tabelaryczne), opcjonalna etykieta zmiany (pastelowa: zielona wzrost,
  czerwona spadek) i stopka: kolorowa kropka 10 px, etykieta i wartość.
- **Tabela**: nagłówki wielkimi literami (13 px, 700) na bardzo jasnym tle, wiersze 15 px z poziomymi liniami,
  podświetlenie wiersza pod kursorem, miniatura 42 px z promieniem 6 px, nazwa pogrubiona z drugą linią 13 px pod
  spodem, status jako pastelowa etykieta, akcje jako kwadratowe przyciski 34 px z ikoną i ramką.
- **Stopka strony**: „© 2026 Worf · Panel administracyjny” (bieżący rok) i link „Sklep”.
- **Przycisk główny** Worf: pomarańczowy z ciemnym tekstem, 40 px, promień 6 px, ikona i etykieta. Pozostałe
  przyciski: niebieski (akcja interfejsu, np. „Zapisz” w oknie), obrysowany (drugorzędny), czerwony (niszczący).

## Biblioteka komponentów i zależności

Decyzja właściciela (2026-11-12): PrimeVue znika z panelu. Panel używa Tailwind CSS 4 i komponentów shadcn-vue
(kod komponentów w repo, na prymitywach Reka UI) i ikon Lucide. TanStack Table (`@tanstack/vue-table`) wejdzie
w ADMIN-FEATURES razem z pierwszym sortowaniem i zaznaczaniem (D13): listy są stronicowane w API, a ukrywanie
kolumn według priorytetu to klasy CSS, więc w tym epiku model tabeli nic nie daje. Uzasadnienie: ten sam
rodzaj komponentów i tokenów co sklep (`apps/store`: Tailwind 4 i shadcn/ui), pełna kontrola nad wyglądem
i dostępnością (dziś defekty nazw dostępnych siedzą w wewnętrznych elementach `TreeSelect` i `Dialog` PrimeVue),
koniec ryzyka licencyjnego: zakresy z daszkiem wciągnęły `@primeuix/themes` 3.1.0 i `primeicons` 8.0.2, które nie są
już na licencji MIT.

Zależności (wymagają zgody właściciela przy akceptacji specyfikacji):

| Pakiet | Rodzaj | Po co |
|---|---|---|
| `tailwindcss`, `@tailwindcss/vite` | dev | style i tokeny (Tailwind 4, wtyczka Vite) |
| `tw-animate-css` | dev | animacje okien i rozwijanych elementów komponentów shadcn-vue |
| `eslint-plugin-better-tailwindcss` | dev | reguła nieznanych klas jak w sklepie |
| `reka-ui` | runtime | prymitywy dostępnych komponentów (okna, menu, combobox, select, tooltip, szuflada) |
| `class-variance-authority`, `clsx`, `tailwind-merge` | runtime | warianty i łączenie klas komponentów shadcn-vue |
| `@vueuse/core` | runtime | pomocnicze funkcje, których używają komponenty shadcn-vue |
| `lucide-vue-next` | runtime | ikony |
| `vue-sonner` | runtime | komunikaty sukcesu (toast), komponent „Sonner” shadcn-vue |

Usuwane w ADMIN-SCREENS (#462), gdy znikną ostatnie ekrany na PrimeVue: `primevue`, `@primeuix/themes`,
`primeicons`. Krój Nunito trafia do repo jako pliki WOFF2 (licencja SIL OFL
1.1, wpis w `apps/admin/THIRD_PARTY_NOTICES.md`), nie jako pakiet npm. Każdy pakiet z tabeli w rozwiązanej wersji ma
licencję MIT, ISC albo Apache-2.0; zadanie sprawdza to w `package-lock.json` i wpisuje wersje do ADR. Komponenty
shadcn-vue są kopiowane do `apps/admin/src/components/ui/` (CLI `shadcn-vue` uruchamiane w kontenerze, `components.json`
w `apps/admin`), więc są naszym kodem, który zmieniamy jak każdy inny.

Na czas migracji PrimeVue i Tailwind działają obok siebie: style PrimeVue są poza warstwami CSS, więc wygrywają
z preflightem Tailwinda na starych ekranach. PrimeVue, `src/primevue.ts` i ich konfigurację usuwa ADMIN-SCREENS.
Zasada panelu „własny komponent tylko, gdy biblioteka nie ma odpowiednika” zostaje: najpierw komponent shadcn-vue
z `src/components/ui/`, potem własny w `src/components/`.

Dokumentacja razem z kodem: nowy ADR `docs/adr/admin-ui-library.md` (kontekst, decyzja, uzasadnienie, konsekwencje,
lista pakietów i licencji), tabela stosu w `docs/vision.md`, `README.md` i główny `CLAUDE.md` (linia `apps/admin`),
`apps/admin/CLAUDE.md` (sekcje „Wygląd”, „Struktura”, „Testy”), tabela zależności i opis layoutu
w `docs/features/walking-skeleton/spec.md`, komentarz o `style-src` w `apps/admin/docker/security-headers.conf`
(style inline ustawiają teraz komponenty Reka UI przy pozycjonowaniu, więc `style-src` dalej nie jest ograniczony).

## Tokeny i typografia

Tokeny są w jednym pliku `apps/admin/src/styles/tokens.css` (`@theme` Tailwinda: warstwa palety i warstwa
semantyczna). Jak w sklepie, `@theme` zaczyna się od `--color-*: initial`, więc klasy domyślnej palety Tailwinda
(`bg-red-500`) nie istnieją, a `better-tailwindcss/no-unknown-classes` (punkt wejścia `tokens.css`) je zgłasza.
Kolor „na sztywno” poza `tokens.css` blokuje ESLint regułami, które widzą pliki `.vue`:

- `<script>` i pliki `.ts` (np. warianty `cva` komponentów): `no-restricted-syntax` sklepu na `Literal`
  i `TemplateElement` (wartości arbitralne `bg-[#…]`, `text-[rgb(…)]`, `bg-[oklch(…)]`, `color-mix`, nazwane kolory
  w nawiasach);
- klasy w `<template>`: `vue/no-restricted-class` z tymi samymi wzorcami;
- atrybut `style` w szablonie: `vue/no-restricted-static-attribute` (statyczny `style` zakazany; dynamiczny `:style`
  tylko z tokenami przez `var(--…)`, sprawdzane `vue/no-restricted-syntax`);
- bloki `<style>` w plikach `.vue` są zakazane (`vue/no-restricted-block`): style idą przez klasy Tailwinda
  i `tokens.css`.

Test reguł (`tests/lint/`) sprawdza identyfikator reguły i liczbę błędów dla każdej ścieżki i prób obejścia (wielkie
litery, `rgba`, `oklch`, `color-mix`, kolor nazwany, `style` statyczny).

Reguły działają od pierwszego zadania na każdym nowym i zmigrowanym pliku. Pliki jeszcze niezmigrowane (widoki
i komponenty z PrimeVue i blokami `<style>`) są wymienione z nazwy na liście `LEGACY_FILES` w `eslint.config.js`
i tylko te reguły ich nie obejmują; lista nie przyjmuje wzorców ani katalogów. Każde zadanie migracji usuwa z niej
swoje pliki, nowy plik nie może na nią trafić (test reguł sprawdza, że każdy wpis listy istnieje i ma import z
`primevue`), a ADMIN-SCREENS usuwa listę razem z PrimeVue. Wartości pochodzą z makiety; tam, gdzie
makieta nie spełnia WCAG 2.2 AA, token jest poprawiony (kolumna „Uwagi”). Kontrast liczony wzorem WCAG 2.x.

| Token semantyczny | Wartość | Użycie | Kontrast | Uwagi |
|---|---|---|---|---|
| `background` | `#f4f5f9` | tło aplikacji i stopki | — | |
| `card` | `#ffffff` | karty, topbar, okna | — | |
| `table-head` | `#f9fafc` | tło nagłówka tabeli | — | |
| `separator` | `#eceef2` | linie wierszy i kart (dekoracja) | — | |
| `foreground` | `#3e4255` | tekst | 9,1:1 na tle | |
| `heading` | `#3a3d4f` | tytuły, liczby KPI, nagłówki tabel | 10,7:1 na bieli | |
| `muted-foreground` | `#636a78` | tekst drugorzędny, podpowiedzi, placeholder, okruszki | 5,0:1 na tle, 5,4:1 na bieli | makieta `#6b7280` ma 4,44:1 na tle, a `#8a93a5` w okruszkach 2,84:1 |
| `input-border` | `#878e9f` | obramowanie pól, list wyboru i pól zaznaczenia | 3,3:1 na bieli | makieta `#dfe2e8` ma 1,3:1 (WCAG 1.4.11) |
| `interface` | `#2563c9` | linki, zaznaczenie, aktywna strona pagera, przycisk akcji interfejsu, fokus | 5,7:1 na bieli; biały na nim 5,7:1 | |
| `interface-hover` | `#1d4f9f` | stan najechania | 7,9:1 | |
| `interface-soft` | `#e3edfb` | tła pastelowe niebieskie | `interface` na nim 4,8:1 | |
| `primary` | `#f68c36` | przycisk główny Worf, romb znaku | tekst `#1d1f24` na nim 6,9:1 | |
| `primary-hover` | `#e97f2a` | | tekst 5,9:1 | |
| `primary-foreground` | `#1d1f24` | tekst przycisku głównego | | |
| `destructive` | `#c0283d` | przycisk niszczący, tekst niskiego stanu | biały na nim 5,8:1 | |
| `sidebar` | `#1f2029` | tło sidebaru | — | |
| `sidebar-accent` | `#272935` | tło rozwiniętej grupy | — | |
| `sidebar-foreground` | `#a3abbd` | pozycje menu | 7,0:1 | |
| `sidebar-section` | `#8a93a5` | nagłówki sekcji menu | 5,2:1 | |
| `sidebar-active` | `#ffffff` | bieżąca pozycja, najechanie | 16,2:1 | |
| `status-success` | `#0f7a55` na `#dcf5ee` | „Opublikowany”, „Dostępny”, „Wysłane”, „Aktywna” | 4,7:1 | |
| `status-warning` | `#8f5a0a` na `#fdf1dc` | „Szkic”, „Ostatnie sztuki”, „Czeka na płatność”, „Zaplanowana” | 5,2:1 | |
| `status-danger` | `#b4233a` na `#fde4e7` | „Niedostępny”, „Anulowane”, „Po terminie”, błędy | 5,4:1 | |
| `status-info` | `#1d4f9f` na `#e3edfb` | „Opłacone”, „Dostarczone” | 6,7:1 | |
| `status-neutral` | `#3e4255` na `#eef0f4` | „Zakończona”, „Wyłączony”, „Zwrócone” | ≥ 8:1 | |
| `focus-ring` | 2 px `interface`, odsunięcie 2 px | fokus na jasnym tle | 5,7:1 | makieta nie pokazuje fokusu |
| `focus-ring-dark` | 2 px `primary` | fokus w sidebarze | 6,7:1 na `sidebar` | `interface` na `sidebar` ma 2,9:1 |
| `kpi-*` | pary pastelowe: niebieska `#2563c9`/`#e3edfb`, fioletowa `#6d4fd8`/`#ece9fd`, zielona `#11875f`/`#dcf5ee`, turkusowa `#1f86b3`/`#e0f3fa`, bursztynowa `#b7791f`/`#fdf1dc` | ikony kafli KPI i kropki stopki | ≥ 3:1 | ikony ozdobne (`aria-hidden`), znaczenie niesie tekst |

Komponenty shadcn-vue używają własnych nazw zmiennych; `tokens.css` mapuje je na tokeny semantyczne z tabeli:
`--background`, `--foreground`, `--card`, `--card-foreground` i `--popover`, `--popover-foreground` (= `card`,
`foreground`), `--primary`, `--primary-foreground`, `--secondary` (= `background`) i `--secondary-foreground`
(= `foreground`), `--muted` (= `table-head`), `--muted-foreground`, `--accent` (= `interface-soft`),
`--accent-foreground` (= `interface-hover`), `--destructive`, `--border` (= `separator`), `--input` (= `input-border`),
`--ring` (= `interface`), `--sidebar-*` (= tokeny sidebaru) oraz `--overlay` (czerń o przezroczystości 50% dla tła
okien, zamiast `bg-black/50`, którego po `--color-*: initial` nie ma). Kopiowane komponenty używają wyłącznie tych
nazw; klasy palety Tailwinda w ich kodzie są zastępowane przy kopiowaniu.

Kształt i odstępy: promień 6 px kontrolek, 8 px kart, 4 px etykiet statusu; cień kart `0 1px 3px` w 6% czerni;
siatka 4 px. Fokus widoczny na każdym elemencie interaktywnym (`:focus-visible`), także w tabelach, menu i oknach.

Typografia: Nunito z plików WOFF2 w `apps/admin/src/assets/fonts/` (podzbiory `latin` i `latin-ext`, wagi 400–800,
`font-display: swap`), stos zapasowy `system-ui, sans-serif`. Źródło plików: Google Fonts CSS API
(`https://fonts.googleapis.com/css2?family=Nunito:wght@400..800`, pliki `fonts.gstatic.com` dla podzbiorów `latin`
i `latin-ext`); `THIRD_PARTY_NOTICES.md` zapisuje adres, wersję kroju z adresu pliku (np. `v32`), licencję i sumy
SHA-256 plików. Korzeń 16 px; tekst interfejsu 15 px, drugie linie
i podpowiedzi 13 px, H1 20 px 700, tytuł karty 16 px 700. Liczby w tabelach i kaflach mają cyfry tabelaryczne
(`tabular-nums`) i są wyrównane do prawej; zadanie fontu sprawdza, że Nunito ma `tnum` (szerokość „111” i „888” jest
równa), a gdy nie ma, kolumny liczbowe używają stosu systemowego. Polskie znaki (ą, ę, ł, ś, ź, ż, ó, ć, ń) są
w podzbiorze `latin-ext`.

Test Vitest `tests/tokens.test.ts` czyta `tokens.css` i sprawdza kontrast każdej pary z tabeli (tekst ≥ 4,5:1,
elementy interfejsu i fokus ≥ 3:1), więc zmiana tokenu, która psuje kontrast, nie przejdzie `make test`.

Tryb ciemny poza zakresem; tokeny semantyczne są nazwane tak, żeby dało się go dodać bez zmian w widokach.

## Powłoka

- **Menu** (`<nav aria-label="Menu główne">`), grupy i pozycje według ról (widoczność bez zmian względem dzisiejszej:
  ukrywa tylko link):

  | Sekcja | Pozycja | Podpozycje | Role |
  |---|---|---|---|
  | Główne | Pulpit (`/`) | — | wszyscy |
  | Sklep | Katalog | Produkty (Lista produktów `/products`, Dodaj produkt `/products/new`), Ceny `/prices`, Stany magazynowe `/inventory` | wszyscy; „Dodaj produkt” tylko `admin` i `marketing` |
  | Sklep | Zamówienia | Lista zamówień `/orders` (`admin`, `support`, `warehouse`), Zwroty do wykonania `/orders?refund=pending` (`admin`, `support`: tylko oni wykonują zwroty) | `admin`, `support`, `warehouse` |
  | Marketing | Promocje `/promotions`, Kody rabatowe `/discount-codes` | — | wszyscy |
  | System | Stan systemu `/system` | — | `admin` |

  Grupa (Katalog, Zamówienia, Produkty) to przycisk z `aria-expanded` i strzałką; grupa z bieżącą trasą jest rozwinięta
  przy wejściu. Bieżąca pozycja ma `aria-current="page"` i tylko ona jest wyróżniona: „Pulpit” jest aktywny wyłącznie
  na `/` (#251 F15). Formularze i szczegóły podświetlają pozycję listy (`/products/:id` → „Lista produktów”,
  `/orders/:id` → „Lista zamówień”, `/promotions/:id` → „Promocje”); `/orders?refund=pending` podświetla „Zwroty do
  wykonania”, a nie „Lista zamówień”. Pozycje do czasu wczytania `/me` wymagające roli są ukryte, jak dziś.
- **Topbar**: na początku przycisk „Menu” (tylko poniżej 1024 px, otwiera szufladę); pole „Szukaj produktu…”
  (`role="search"`, `searchbox` z etykietą). Enter z frazą, która po przycięciu ma 2–100 znaków (punkty kodowe, jak
  liczy API i lista produktów), przechodzi do `/products?q=` z tą frazą. Fraza pusta nic nie robi. Fraza krótsza niż
  2 znaki albo dłuższa niż 100 nie nawiguje: pod polem pojawia się „Wpisz od 2 do 100 znaków.” (połączona z polem
  przez `aria-describedby`, ogłaszana `role="status"`), a fokus zostaje w polu, więc nie ma pozornego wyszukiwania; link „Sklep” do adresu sklepu w nowej karcie (`rel="noopener"`, nazwa dostępna „Sklep (otwiera
  się w nowej karcie)”); konto: inicjały z `username`, `username`, polskie etykiety ról po przecinku (`admin` →
  Administrator, `support` → Obsługa klienta, `warehouse` → Magazyn, `marketing` → Marketing) i menu konta (Reka
  `DropdownMenu`) z „Wyloguj”.
- **Adres sklepu**: nowy klucz konfiguracji `VITE_STORE_URL`, czytany przez `readUrl` jak trzy obecne: `env.d.ts`,
  `ConfigKey`, `__ADMIN_CONFIG__`, `docker/40-admin-config.sh` (wymagany, błąd z nazwą przy braku, pustej wartości
  albo białym znaku), compose (`VITE_STORE_URL: http://localhost:${STORE_PORT:-3000}` w usłudze `admin`), chart
  (`templates/admin.yaml`, ten sam adres co `STORE_URL` sklepu: `shop.url` z hostem sklepu), test dymny
  (`checks.py`: `/config.js` ma `VITE_STORE_URL` środowiska) i `test_workloads.py`. To zmiana zmiennej środowiska
  w compose, więc chart zmienia się w tym samym zadaniu (zasada głównego `CLAUDE.md`).
- **Okruszki** (`<nav aria-label="Okruszki">`, ostatnia pozycja `aria-current="page"` bez linku) na każdym ekranie
  poza pulpitem, zaczynają się od „Pulpit”. Formularze i szczegóły kończą się nazwą obiektu (np. „Pulpit › Katalog ›
  Produkty › Buty trailowe Norvane Grań 300”, „Pulpit › Zamówienia › R-100002”); „Wróć do listy” znika, bo jego rolę
  przejmują okruszki.
- **Link „Przejdź do treści”**: pierwszy element w kolejności Tab, widoczny po fokusie, przenosi fokus na `<main>`
  (`tabindex="-1"`).
- **Szuflada poniżej 1024 px**: sidebar znika z układu, a przycisk „Menu” w topbarze otwiera go jako szufladę z lewej
  (Reka `Dialog`/shadcn-vue `Sheet`: pułapka fokusu, `Escape` i kliknięcie obok zamykają, fokus wraca na „Menu”);
  wybór pozycji zamyka szufladę.
- **Strażniki tras**: `/orders` i `/orders/:id` tylko dla `admin`, `support`, `warehouse`; `/system` tylko dla
  `admin`. Bez roli (po wczytaniu `/me`, jak `beforeEnter` dla `/products/new`) router przekierowuje na pulpit
  z `history.state.denied = true`, a pulpit pokazuje komunikat „Nie masz dostępu do tej strony.” (`role="alert"`);
  przeładowanie pulpitu go nie powtarza. Gdy `/me` się nie wczyta, strażnik przepuszcza trasę, a layout pokazuje
  dzisiejszy błąd wczytania pracownika (`staff-error`); dostęp i tak sprawdza API (403 widoku). `/prices` nie dostaje
  strażnika: API pozwala każdemu pracownikowi czytać ceny, a zmianę ceny ukrywa już widok (tylko `admin`).
- **Toast**: jeden kontener `vue-sonner` w layoucie, komunikaty sukcesu po polsku, region `aria-live="polite"`,
  znikają po 5 s i zostają, dopóki kursor albo fokus jest nad nimi. Błędy nie idą do toastu: zostają przy miejscu,
  którego dotyczą (`role="alert"`).
- **Ekrany poza layoutem**: problem z logowaniem (`App.vue`) i powrót z logowania (`AuthCallbackView`) dostają wygląd
  karty na tle aplikacji ze znakiem Worf, komunikatem i przyciskiem „Zaloguj ponownie”. Strona logowania Keycloaka
  jest poza zakresem.
- Bezczynność i wylogowanie (`useIdleLogout`, 15 min) bez zmian.

## Standard listy

Jeden zestaw komponentów w `src/components/list/` dla wszystkich sześciu list (produkty, ceny, stany, promocje, kody,
zamówienia; w tym epiku lista produktów, pozostałe w ADMIN-SCREENS): `DataTable` (na komponencie `Table` shadcn-vue), `ListToolbar`, `ListPager`, `ListEmpty`, `RowActions`
oraz dwa composables:

- `useListUrlState(schema)`: jedyne źródło wyszukiwania, filtrów i rozmiaru strony. Czyta je z query stringu przy
  wejściu, zapisuje zmiany (`router.replace`) i zwraca reaktywne zapytanie. Listy nie trzymają już własnych kopii
  filtrów (`selectCategory`, `selectStatus` i podobne zapisują do adresu), więc wejście z adresu wysyła jedno żądanie.
- `useCursorPager(fetchPage, query)`: jedyna implementacja stronicowania po kursorach, dziś skopiowana w sześciu
  composables (stos kursorów, `latest` dla spóźnionych odpowiedzi, `retry`, `goNext`, `goPrevious`, powrót na pierwszą
  stronę przy zmianie zapytania), z `perPage`, czyszczeniem wierszy po błędzie i zarządzaniem fokusem pagera.
  Każde zachowanie standardu powstaje i jest testowane raz.

Composables list (`useProductList` i pozostałe) zostają przy tym, co jest ich własne: budowie zapytania API,
komunikatach błędów, rozbiciu na wiersze wariantów i logice zapisu (wersje cen i stanów, idempotencja, `ignored`).
Wyszukiwanie z opóźnieniem i regułami długości (od 2 znaków dla nazw, od 1 znaku dla kodów, po Enterze dla zamówień)
zostaje w composable listy i dopiero zastosowana fraza trafia do adresu.

- **Pasek narzędzi**: wyszukiwarka (gdzie API ją ma, z dzisiejszymi regułami długości i opóźnienia), filtry listy
  (Select albo Combobox z etykietą widoczną albo dostępną), przycisk „Wyczyść filtry” widoczny, gdy jakikolwiek filtr
  albo wyszukiwanie jest ustawione, wybór liczby wierszy i przycisk główny listy (np. „Dodaj produkt”) po prawej.
- **Stan w adresie URL**: wyszukiwanie (`q`), filtry (np. `category`, `status`, `refund`) i rozmiar strony (`perPage`)
  są w query stringu (`router.replace`, bez nowego wpisu historii przy każdym znaku). Pozycja w stronicowaniu (kursor)
  nie trafia do adresu: przeładowanie wraca na pierwszą stronę, bo stos kursorów jest tylko w pamięci.
  - `useListUrlState` obserwuje `route.query`: zmiana z zewnątrz przy zamontowanym widoku (pozycja menu
    „Zwroty do wykonania” na `/orders`, wyszukiwarka topbaru na `/products`, link „Do obsługi”, Wstecz i Dalej
    przeglądarki) ustawia filtry, porzuca kursory i zaczyna od pierwszej strony; własny zapis listy przez
    `router.replace` nie wysyła drugiego żądania.
  - Walidacja parametrów przed pierwszym żądaniem: nieznana wartość `status`, `refund` albo `perPage` jest pomijana
    (lista jak bez niej, adres poprawiony); `category` jest sprawdzana w drzewie `useCategoryTree` (nieistniejąca
    pominięta, bo API dałoby 404; przy błędzie drzewa parametr też jest pominięty); `q` przechodzi reguły listy (np.
    produkty 2–100 znaków): tekst za krótki albo za długi jest pokazany w polu z dzisiejszą podpowiedzią, ale nie
    filtruje.
  - E-mail w `q` zamówień (także z linku „Inne zamówienia klienta”) trafia do adresu, historii przeglądarki i logów
    dostępu nginx panelu; API i tak dostaje `q` w query. Świadomie przyjęte, do uwzględnienia przy RODO (#119).
- **Stare listy do czasu ADMIN-SCREENS**: listy zamówień, promocji i kodów zostają na PrimeVue, ale czytają z adresu
  filtry, do których prowadzą menu i pulpit: zamówienia `status` i `refund=pending`, promocje i kody `status`. Przy
  wejściu i przy zmianie `route.query` przy zamontowanym widoku ustawiają filtr i wracają na pierwszą stronę;
  nieznana wartość jest pomijana. Nie zapisują filtrów do adresu ani nie zmieniają innego zachowania. Ponieważ adres
  nie śledzi filtrów ustawionych w liście, pozycja menu, której cel jest równy bieżącej lokalizacji (np. „Zwroty do
  wykonania” po wyłączeniu przełącznika w liście), nawiguje ponownie na ten adres (`force`, `replace`: bez nowego
  wpisu historii), a lista bierze filtry z adresu; klik z Ctrl, Cmd, Shift, Alt albo środkowym przyciskiem zostaje
  przeglądarce (nowa karta) i nie zamyka szuflady. Lista rozpoznaje „swój” widok po nazwie trasy, nie po ścieżce.
  ADMIN-SCREENS zastępuje to `useListUrlState`.
- **Rozmiar strony**: 10, 25 albo 50 (`perPage` istniejące w API, maksimum 100). Domyślnie 25; na Cenach i Stanach
  domyślnie 10, bo tam strona liczy produkty, a każdy ma wiersz na wariant. Zmiana rozmiaru wraca na pierwszą stronę.
  Wybór wariantów w formularzu promocji (`PromotionVariantPicker`, też na `usePriceList`) zostaje przy 25 produktach
  na stronę, bez wyboru rozmiaru i bez stanu w adresie.
- **Stopka**: „Pokazano X–Y” (`role="status"`, numer pierwszego i ostatniego wiersza strony liczony z numeru strony,
  rozmiaru i liczby elementów odpowiedzi; na Cenach i Stanach liczy produkty: „Pokazano produkty X–Y”) i pager
  z przyciskami „Poprzednia strona” / „Następna strona” (ikony ze strzałkami, nazwy dostępne) oraz numerem bieżącej
  strony. API nie liczy sumy, więc bez „z N” i bez numerów dalszych stron. Fokus po zmianie strony zostaje na
  naciśniętym przycisku, a gdy ten zostaje wyłączony, przechodzi na drugi przycisk pagera (dziś tak działa lista kodów;
  standard obejmuje wszystkie listy, #260 F7).
- **Puste stany** rozdzielone: bez danych w ogóle (tekst listy, z przyciskiem głównym listy, gdy rola może tworzyć)
  i bez wyników dla filtrów („Brak wyników dla wybranych filtrów.” z „Wyczyść filtry”, wspólny dla wszystkich list).
  Pager jest wtedy ukryty. Teksty „brak danych”: produkty „Nie ma jeszcze produktów.”, ceny i stany „Nie ma jeszcze
  produktów z wariantami.”, promocje „Nie ma jeszcze promocji.”, kody „Nie ma jeszcze kodów rabatowych.”, zamówienia
  „Nie ma jeszcze zamówień.” (zastępuje „Brak zamówień.”; „Nie znaleziono zamówień.” zastępuje wspólny tekst).
- **Błąd**: komunikat `role="alert"` z „Spróbuj ponownie” (403 bez ponowienia, jak dziś). Zależnie od żądania:
  - zmiana wyszukiwania, filtra albo rozmiaru strony (także pierwsze wczytanie): tabela i pager są ukryte do
    udanego ponowienia, bo wiersze należą do poprzedniego zapytania (#251 F10, #272);
  - przejście na sąsiednią stronę: wyświetlona strona zostaje, komunikat jest nad nią (jak dziś w zamówieniach,
    promocjach i kodach);
  - odświeżenie bieżącej strony po zapisie (409 ceny, przyjęcia, korekty): strona zostaje jak dziś.
  Fokus po nieudanym ponowieniu zostaje na „Spróbuj ponownie”; gdy ten znika (403), przechodzi na komunikat błędu
  (`tabindex="-1"`), nigdy na `body` (#337).
- **Ładowanie**: szkielet wierszy przy pierwszym wczytaniu; przy kolejnych żądaniach tabela zostaje z `aria-busy`.
- **Akcje wiersza**: kwadratowe przyciski z ikoną, nazwą dostępną zawierającą nazwę obiektu („Edytuj: Buty trailowe
  Norvane Grań 300”) i podpowiedzią (tooltip) z tą samą nazwą po najechaniu i fokusie. Akcja, której rola nigdy nie
  wykona, jest ukryta.
- **Bez zaznaczania i sortowania**: API nie ma akcji masowych ani sortowania, więc `DataTable` w tym epiku ich nie
  ma; dodaje je ADMIN-FEATURES razem z pierwszym użyciem. Nagłówki są zwykłym tekstem bez ikon sortowania.
- **Szerokość**: tabela mieści się w karcie; kolumny mają priorytety, a kolumny niższego priorytetu są ukryte jako
  kolumny poniżej ustalonej szerokości (opis per ekran niżej). Ukrycie kolumny nie ukrywa informacji: jej wartość
  pojawia się wtedy z etykietą jako dodatkowa linia w komórce głównej wiersza (np. „Kategoria: Namioty · Zmieniono
  12.11.2026, 10:34:05” pod nazwą produktu), więc każda informacja i każda akcja listy jest dostępna przy każdej
  szerokości od 320 px. Gdy tabela mimo to jest szersza od karty, przewija się w swoim pojemniku (`role="region"`,
  nazwa listy, `tabindex="0"`, dostępny z klawiatury) z widocznym cieniem przy krawędzi, za którą jest dalsza treść
  (wyjątek tabel danych z WCAG 1.4.10). Strona nigdy nie przewija się w poziomie.

## Standard formularza i okien

- **Układ**: od 1280 px dwie kolumny: główna (dane) i boczna 320 px (status, publikacja, organizacja); poniżej jedna
  kolumna z boczną nad główną. Sekcje to białe karty z tytułem. Pola: etykieta nad polem (`<label for>` albo
  `aria-labelledby` dla złożonych kontrolek), podpowiedź i błąd pod polem połączone przez `aria-describedby`, pole
  z błędem ma `aria-invalid`.
- **Pasek zapisu**: przyklejony do dołu obszaru treści, biały z cieniem, z tekstem „Niezapisane zmiany” i przyciskami
  „Odrzuć” (obrysowany; przywraca stan z API bez żądania) i „Zapisz” (przycisk główny). Pojawia się, gdy formularz ma
  niezapisane zmiany; w nowym obiekcie jest widoczny od początku („Nowy produkt” zamiast „Niezapisane zmiany”,
  „Anuluj” wraca do listy). Obszar treści ma `scroll-padding-bottom` równy wysokości paska, więc pole z fokusem nigdy nie
  jest pod paskiem (WCAG 2.4.11). Akcje stanu obiektu (publikacja, wycofanie, zakończenie, usunięcie) są w karcie
  bocznej „Status”, nie w pasku.
- **Po zapisie**: toast („Produkt zapisany.”, „Promocja zapisana.”, „Kod rabatowy zapisany.”), pasek znika, a fokus
  przechodzi na H1 formularza (`tabindex="-1"`), bo przycisk „Zapisz” zniknął razem z paskiem. Wyjątek (#250 F16):
  gdy w trakcie żądania pracownik przeniósł fokus do pola, fokus zostaje w tym polu. Po nieudanym zapisie fokus trafia
  na pierwsze pole z błędem albo komunikat formularza, jak dziś, a komunikat jest przewinięty do widoku (#304 F3).
- **Strażnik niezapisanych zmian** we wszystkich trzech formularzach (w tym epiku produkt; promocja i kod rabatowy
  w ADMIN-SCREENS), jeden
  composable `useUnsavedChangesGuard`: przejście na inną trasę panelu z niezapisanymi zmianami otwiera okno „Masz
  niezapisane zmiany” z „Zostań” (domyślny fokus) i „Opuść bez zapisu” (niszczący); zamknięcie karty ostrzega
  przeglądarka (`beforeunload`), poza wylogowaniem (`signingOut`, jak dziś w produkcie). Zastępuje `window.confirm`
  w `ProductFormView`.
- **Okno potwierdzenia** (`ConfirmDialog` na shadcn-vue `AlertDialog`) dla każdej akcji nieodwracalnej albo
  zmieniającej stan: tytuł z nazwą obiektu („Anulować zamówienie R-100002?”), opis skutku jednym zdaniem, przycisk
  akcji z etykietą opisującą skutek („Anuluj zamówienie”, „Usuń promocję”, „Zakończ promocję teraz”, „Usuń wariant
  czarny / 42”), czerwony przy akcjach niszczących (anulowanie i zwrot zamówienia, usunięcie, zakończenie promocji,
  usunięcie wariantu), oraz „Wróć” z domyślnym fokusem. `Escape` i „Wróć” zamykają i oddają fokus na wyzwalacz.
  Zastępuje potwierdzenie w treści strony w promocjach (#304 F6) i okno PrimeVue przy wariantach (#251 F12).
- **Fokus, gdy wyzwalacz zniknął**: po akcji, która zmienia zestaw przycisków (publikacja i wycofanie produktu,
  zakończenie i usunięcie promocji, zmiana statusu zamówienia, wykonanie zwrotu, usunięcie wariantu), fokus trafia na
  nagłówek karty, w której była akcja („Status”, „Akcje”, „Warianty”; nagłówek z `tabindex="-1"`), a gdy akcja
  przeniosła na inną trasę (usunięcie promocji), na H1 nowej strony. Gdy wyzwalacz nadal istnieje, fokus wraca na niego.
- **Okna z polami** (zmiana ceny, przyjęcie, korekta, zmiana statusu zamówienia, wykonanie zwrotu; stosuje je
  ADMIN-SCREENS): fokus po otwarciu
  w pierwszym polu (#273), przycisk zamykania „Zamknij”, szerokość `min(28rem, 100vw - 2rem)`, więc okno mieści się
  na ekranie 375 px (#393); po błędzie 403 albo innym komunikacie fokus trafia na komunikat, nie na `body` (#260 F8).
  Okna historii (ceny, stanu) mają szerokość do `min(56rem, 100vw - 2rem)` i tabelę z priorytetami kolumn.
- Daty i godziny w polach zostają natywnymi `datetime-local` w `Europe/Warsaw` (`src/promotions/time.ts`), bez
  zmian zachowania.

## Pulpit i Stan systemu

Pulpit (`/`, H1 „Pulpit”) korzysta z `GET /api/v1/admin/dashboard` (sekcja `catalog`) i, dla ról z dostępem do zamówień,
z `GET /api/v1/admin/orders?perPage=5`. Zachowanie świeżości zostaje jak dziś
(`docs/features/dashboard-data-freshness/spec.md`): „Odśwież”, odświeżanie co 30 s wstrzymane w ukrytej karcie,
„Dane z 14:03:12” i komunikat błędu z czasem pokazanych danych; dotyczy kafli katalogu i „Ostatnich zamówień”.

- **Kafle KPI** (komponent `KpiTile`: `title`, `icon`, `tone` z par `kpi-*`, `value` sformatowane z liczby z API,
  opcjonalnie `footer` z etykietą i wartością oraz `to` dla kafla-linku; etykietę zmiany i oznaczenie wartości
  szacunkowej doda ADMIN-FEATURES razem z kaflami sprzedaży):
  - „Produkty”: `productCount`, link do `/products`, stopka „Bez zdjęć” z `productsWithoutImages`;
  - „Kategorie”: `categoryCount`, bez linku (panel nie ma ekranu kategorii);
  - „Bez zdjęć”: `productsWithoutImages`, bez linku (API nie filtruje produktów bez zdjęć); stopka „Uzupełnij zdjęcia
    w formularzu produktu”.
  Kafle sprzedaży doda ADMIN-FEATURES tym samym komponentem.
- **„Do obsługi”** (karta z listą linków, bez liczb, bo API ich nie liczy): każdy wiersz to ikona w pastelowym kółku,
  etykieta, opis i strzałka; widoczny według ról:
  - „Opłacone do wysłania” → `/orders?status=paid` (`admin`, `support`, `warehouse`);
  - „Czekają na płatność” → `/orders?status=new` (`admin`, `support`);
  - „Zwroty do wykonania” → `/orders?refund=pending` (`admin`, `support`);
  - „Stany magazynowe” → `/inventory` (`admin`, `warehouse`);
  - „Aktywne promocje” → `/promotions?status=active` (`admin`, `marketing`);
  - „Kody rabatowe” → `/discount-codes?status=active` (`admin`, `marketing`).
  Rola `support` bez innych zadań widzi co najmniej pierwsze trzy; każda rola widzi co najmniej jeden wiersz.
- **„Ostatnie zamówienia”** (role `admin`, `support`, `warehouse`): tabela pięciu najnowszych (numer jako link, klient,
  data, pozycje `itemCount`, kwota, status) i link „Wszystkie zamówienia →”. Błąd tej karty nie psuje reszty pulpitu
  (komunikat w karcie z „Spróbuj ponownie”).
- Komunikat „Nie masz dostępu do tej strony.” po przekierowaniu ze strażnika (sekcja „Powłoka”).

**Stan systemu** (`/system`, H1 „Stan systemu”, tylko `admin`, pozycja menu w sekcji System): dzisiejsza zawartość
sekcji „Stan systemu” w nowym wyglądzie i po polsku: usługi z etykietą „Działa” / „Nie działa” (status-success /
status-danger) i opóźnieniem, „Zdarzenia czekające na wysłanie” zamiast „Outbox”, tabela kolejek (nazwa, wiadomości,
konsumenci), porównanie indeksu wyszukiwarki z katalogiem. Zachowanie świeżości z
`docs/features/dashboard-data-freshness/spec.md` działa na tym ekranie tak samo jak na pulpicie (odświeżanie co 30 s wstrzymane
w ukrytej karcie, „Dane z 14:03:12” (godzina polska przez `formatDateTime`), komunikat z czasem pokazanych danych),
a tekst „Dane z …” ma kontrast `muted-foreground` (#346). Rola inna niż `admin` dostaje z API `system: null`, a ekran
jest dla niej zablokowany strażnikiem.

## Produkty

- **Lista** (`/products`, H1 „Produkty”): wyszukiwanie, filtr kategorii (Combobox z drzewa, niżej), filtr statusu
  (Wszystkie, Szkice, Opublikowane), przycisk główny „Dodaj produkt” (`admin`, `marketing`). Kolumny: Produkt
  (miniatura 42 px albo szary placeholder tej samej wielkości z ikoną zdjęcia, nazwa jako link do edycji, pod nią slug),
  Kategoria (nazwa głównej), Warianty (`variantCount`), Status (etykieta), Ostatnia zmiana (data, pod nią godzina
  z sekundami, jak dziś), Akcje („Edytuj” i „Podgląd w sklepie” dla opublikowanego: `<VITE_STORE_URL>/p/<slug>` w nowej karcie).
  Poniżej 1024 px ukryta Kategoria, poniżej 900 px także Ostatnia zmiana.
- **Combobox kategorii** (`CategoryCombobox`, zastępuje `TreeSelect` na liście i w formularzu produktu, a w ADMIN-SCREENS
  na cenach i stanach): pole z listą wyboru (Reka `Combobox`, rola `combobox` z nazwą z etykiety), opcje to kategorie z drzewa
  `useCategoryTree` w kolejności drzewa, każda z wcięciem według poziomu i pełną ścieżką w nazwie dostępnej
  („Turystyka › Biwak › Namioty”); wpisywanie filtruje po nazwie i ścieżce. Tryb wielokrotny (kategorie dodatkowe
  w formularzu) pokazuje wybrane jako etykiety z przyciskiem „Usuń: Namioty”. Brak nienazwanych przycisków (#250 F13).
- **Formularz** (`/products/new`, `/products/:id`): H1 „Nowy produkt” albo nazwa produktu (także na ekranie błędu
  wczytania istniejącego produktu: „Nie udało się wczytać produktu”, nie „Nowy produkt”, #251 F9). Kolumna główna:
  „Dane produktu” (nazwa, slug, krótki opis, klasa podatkowa jako Select z nazwą z etykiety, #250 F13 i P2 przeglądu),
  „Zdjęcia”, „Warianty”. Kolumna boczna: „Status” (etykieta, „Opublikuj” albo „Wycofaj z publikacji”, wyłączone przy
  niezapisanych zmianach z widocznym powodem „Najpierw zapisz zmiany”), „Organizacja” (kategoria główna i dodatkowe).
  Zachowanie zapisu, błędów 409/422 i ról jak dziś (`apps/admin/CLAUDE.md`, `src/products/`).
- **Zdjęcia**: po usunięciu zdjęcia fokus przechodzi na następne zdjęcie, a bez niego na przycisk dodawania; po ukryciu
  wyniku wysyłania fokus nie spada na `body` (#250 F8); błąd wysyłania jest ogłaszany (`role="alert"` w wierszu pliku,
  #250 F14).
- **Warianty**: usunięcie istniejącego wariantu i ostatniej osi pyta przez `ConfirmDialog` z nazwą wariantu („Usunąć
  wariant czarny / 42?”) i polskim „Zamknij” (#251 F12).

## Ekrany w ADMIN-SCREENS

Ceny i Stany magazynowe, promocje i kody rabatowe, zamówienia (lista i szczegóły z osią czasu) oraz usunięcie PrimeVue
opisuje zgłoszenie #462 (część 2), które przygotuje własną specyfikację na standardach z tego dokumentu. W tym epiku
te ekrany zmieniają się tylko przez wspólną powłokę, preflight Tailwinda i odczyt filtrów z adresu (sekcja „Standard
listy”); ich testy E2E przechodzą po dostosowaniu nawigacji do nowego menu.

## Responsywność

- ≥ 1280 px: pełny układ; formularze w dwóch kolumnach.
- 1024–1279 px: sidebar stały, formularze w jednej kolumnie.
- < 1024 px: sidebar jako szuflada, przycisk „Menu” w topbarze.
- 768 px: lista pokazuje nazwę, status i akcje bez przewijania w poziomie (kolumny niższego priorytetu ukryte według
  sekcji ekranów); pulpit i formularze w jednej kolumnie.
- 375 px: każde okno mieści się w ekranie; lista i szczegóły zamówienia projektuje pod telefon ADMIN-SCREENS;
  pozostałe ekrany nie są projektowane pod telefon, ale spełniają regułę 320 px.
- 320 px (WCAG 1.4.10, reflow): na każdym ekranie tego epiku i w każdym jego oknie przy szerokości 320 px CSS
  (wysokość 640 px; ekrany ADMIN-SCREENS sprawdza część 2)
  strona nie przewija się w poziomie, a każda informacja i każda funkcja ekranu jest dostępna: menu przez szufladę,
  pola i przyciski formularzy w jednej kolumnie, pasek zapisu z przyciskami w całości widoczny, ukryte kolumny jako
  linie komórki głównej, tabele szersze od ekranu przewijane w swoim regionie dostępnym z klawiatury (wyjątek tabel
  danych). Tekst nie jest obcinany bez dostępu do pełnej wartości (podpowiedź albo zawijanie).

## Dostępność

WCAG 2.2 AA dla całego panelu: kontrasty z tokenów (test Vitest), widoczny fokus, link „Przejdź do treści”, nazwy
dostępne każdej kontrolki (także ikon, comboboxów i przycisków rozwijania), kolejność fokusu zgodna z układem, fokus
nigdy nie spada na `body` po akcji (zmiana strony, zamknięcie okna, błąd, usunięcie elementu), cele kliknięcia co
najmniej 24 × 24 px, komunikaty statusu przez `role="status"`/`aria-live`, błędy przez `role="alert"`, język `pl`
(`<html lang="pl">`), polskie nazwy wszystkich elementów komponentów (żadnego „Close”).

## Zachowane funkcje

Migracja nie usuwa żadnej istniejącej funkcji ani informacji panelu. Każde zachowanie opisane dziś
w `apps/admin/CLAUDE.md` zostaje, poza zmianami wymienionymi niżej, z których każda zastępuje funkcję innym
sposobem jej wykonania, a nie ją usuwa:

| Dziś | Po migracji |
|---|---|
| „Dashboard” w menu i H1, sekcja „Stan systemu” na pulpicie | „Pulpit”; „Stan systemu” na `/system` z tą samą treścią, odświeżaniem i świeżością danych |
| „Wróć do listy” w formularzach i szczegółach | okruszki z linkiem do listy |
| filtry statusu jako przyciski-zakładki (produkty, promocje, kody) | Select z tymi samymi wartościami |
| `window.confirm` przy opuszczeniu produktu, potwierdzenie w treści strony w promocjach, okno PrimeVue przy wariantach | okna `ConfirmDialog` i strażnika zmian |
| komunikat sukcesu z fokusem w formularzach | toast i fokus według „Standardu formularza” |
| 403 i „Nie masz dostępu do zamówień.” po wpisaniu `/orders` przez rolę bez dostępu | strażnik trasy i komunikat na pulpicie (403 widoku zostaje jako zabezpieczenie) |
| role jako etykiety z kodem roli i przycisk „Wyloguj” w topbarze | polskie nazwy ról i „Wyloguj” w menu konta |
| teksty pustych list | teksty ze „Standardu listy” |
| kolumny tabel przy każdej szerokości | kolumny niższego priorytetu jako linie komórki głównej na wąskim ekranie |

Spis funkcji do zachowania (sprawdza go przegląd zadania i `/integration`; ten epik weryfikuje pozycje „Powłoka”,
„Pulpit i Stan systemu” i „Produkty”, a pozostałe ekrany działają jak dziś i ich pozycje weryfikuje ADMIN-SCREENS):

- **Powłoka**: menu według ról (ukrywanie do wczytania `/me`), nazwa i role pracownika, wylogowanie, wylogowanie po
  15 min bezczynności, błąd wczytania pracownika, problem z logowaniem z „Zaloguj ponownie”.
- **Pulpit i Stan systemu**: liczniki katalogu (produkty, kategorie, bez zdjęć z ostrzeżeniem), usługi ze stanem
  i opóźnieniem, zdarzenia outboxu, kolejki (nazwa, wiadomości, konsumenci), porównanie indeksu, „Odśwież”,
  odświeżanie co 30 s z pauzą w ukrytej karcie, „Dane z …”, błąd z czasem danych.
- **Produkty**: wyszukiwanie od 2 znaków, filtr kategorii (z podkategoriami) i statusu, stronicowanie, miniatury, link
  do edycji, „Dodaj produkt” według roli; formularz: nazwa, slug (blokada po publikacji, błędy `slug-taken`
  i `slug-locked`), krótki opis, kategoria główna i dodatkowe, klasa podatkowa, zdjęcia (wysyłanie wielu po kolei
  ze statusem i ponowieniem, limit 10, alt, usunięcie, błędy 422 zdjęć), warianty (osie 0–2, zmiana nazwy,
  przestawianie, usuwanie, wiersze 1–100, blokada osi po publikacji z wyjaśnieniem, błędy sekcji), zapis, publikacja,
  wycofanie, obsługa 409 i 422, tryb tylko do odczytu, ignorowanie odpowiedzi po opuszczeniu.
- **Ceny**: wyszukiwanie, filtr kategorii, stronicowanie, wiersz na wariant, brutto, netto, VAT i stawka, zmiana ceny
  (`admin`) z obsługą wersji, 409 i blokady promocją, historia ceny z „Pokaż więcej” i źródłem zmiany.
- **Stany**: wyszukiwanie, filtr kategorii, stronicowanie, fizyczny, zarezerwowany i dostępny stan, poziom, wariant bez
  rekordu („—”), przyjęcie z kluczem idempotencji, korekta z powodem i notatką, obsługa 409 i 422, historia z „Pokaż
  więcej” i ponowieniem kolejnej strony.
- **Promocje**: lista z filtrem statusu, formularz (nazwa, procent, początek „od teraz”, koniec, warianty: „Dodaj
  wariant”, „Dodaj cały produkt”, usuwanie, „Wariant usunięty”, kolizje z inną promocją), reguły edycji według
  statusu, „Zakończ teraz”, „Usuń”, tylko odczyt, czas `Europe/Warsaw`.
- **Kody rabatowe**: lista z filtrem statusu i wyszukiwaniem po początku kodu (limit 32 znaków z komunikatem),
  kolumny kodu, rabatu, minimum, okresu, użyć i statusu, formularz z regułami pól (`termsLocked`, `limitLocked`),
  kwoty w złotych, obsługa 409 i 422.
- **Zamówienia**: lista z filtrem statusu, przełącznikiem zwrotów (kwota, termin, „Po terminie”), wyszukiwaniem po
  Enterze i stronicowaniem; szczegóły: kontakt, adres, pozycje z promocją i rabatem z kodu, sumy, daty statusów,
  termin płatności dla `new`, powód anulowania, historia z aktorem i notatką, zwrot (kwota, termin, wykonanie,
  płatność do zwrotu), przejścia z `allowedTransitions` z notatką (wymaganą przy anulowaniu i zwrocie), wykonanie
  zwrotu, obsługa 409, 422 i 403, 404 z linkiem do listy.

Istniejące testy composables przechodzą bez zmian asercji, poza tymi, które sprawdzają zachowanie zmienione w tej
specyfikacji (lista zmian wyżej, stronicowanie przeniesione do `useCursorPager`); każda taka zmiana asercji jest
w opisie PR zadania z odwołaniem do sekcji specyfikacji.

## Zgłoszenia zamykane przez epik

Zgłoszenie trafia do `Closes` końcowego PR tylko wtedy, gdy jego weryfikacja (kolumna „Weryfikacja”: kryterium
akceptacji z treści zgłoszenia przeniesione do testu) przechodzi na HEAD gałęzi epiku. Raport `/integration` ma
tabelę: zgłoszenie, test, wynik. Zgłoszenie bez pozytywnego wyniku jest usuwane z `Closes` końcowego PR i zostaje
otwarte z komentarzem, czego brakuje; właściciel sprawdza tę tabelę przed merge'em.

| Issue | Co zamyka | Gdzie | Weryfikacja |
|---|---|---|---|
| #250 | F8 fokus po zdjęciach, F11 kontrast przycisków, F13 nazwy rozwijania kategorii, F14 ogłaszanie błędu uploadu, F16 fokus po wyniku akcji | Produkty, Standard formularza | testy komponentów F8, F14, F16; E2E: axe na `/products` i formularzu bez `color-contrast` i bez nienazwanych przycisków |
| #251 | F9 nagłówek błędu produktu, F10 stare wiersze po błędzie, F12 okno usunięcia wariantu, F15 aktywny „Dashboard” | Produkty, Standard listy, Powłoka | testy komponentów F9, F10, F12; E2E F15 (`aria-current` na każdej trasie) |
| #346 | kontrast „Dane z …” | Pulpit, Stan systemu | E2E: kontrast tekstu „Dane z …” ≥ 4,5:1 i axe bez `color-contrast` |

Zgłoszenia #135, #260, #261, #272, #273, #304, #336, #337, #343, #393 i #446 zamyka ADMIN-SCREENS (#462).

## Weryfikacja UX i odbiór

- **Testy automatyczne** (CI): kryteria akceptacji niżej, w tym axe przy 1280 i 768 px, reflow przy 320 px i okna
  przy 375 px.
- **Agent `ux-reviewer` w `/integration`** ocenia każdy ekran tego epiku przy 1280, 768 i 375 px (a niezmigrowane
  ekrany tylko pod kątem regresji: działają i są dostępne z nowej powłoki):
  - użyteczność i dostępność jak dziś;
  - wygląd według tej specyfikacji (tokeny, układ powłoki, karty, kafle, tabela, pasek zapisu, okna) na zrzutach
    ekranu każdego ekranu i otwartego okna;
  - przejście samą klawiaturą każdego ekranu (Tab i Shift+Tab przez wszystkie elementy, Enter i spacja na
    przyciskach, Escape w oknach i szufladzie, strzałki w menu i listach wyboru): kolejność zgodna z układem, fokus
    zawsze widoczny i nigdy na `body`, brak pułapek fokusu poza oknami;
  - reflow przy 320 px.
  Zrzuty (każdy ekran przy 1280, 768 i 375 px) trafiają do katalogu wyników `/integration` poza repo, a raport
  wskazuje ich ścieżkę.
- **Odbiór przez właściciela** przed merge'em końcowego PR, na stosie z gałęzi epiku:
  - przegląd zrzutów z `/integration` (wygląd po migracji względem makiety);
  - ręczne przejście klawiaturą: logowanie, menu i szuflada, pulpit i „Stan systemu”, lista produktów z filtrem
    i paginacją, edycja produktu z zapisem, publikacją, usunięciem wariantu i opuszczeniem bez zapisu, przejście
    z menu na każdy niezmigrowany ekran;
  - tabela weryfikacji zgłoszeń z raportu `/integration`.

## Kryteria akceptacji

1. Nowe zależności w `apps/admin/package.json` to dokładnie pakiety z tabeli „Biblioteka komponentów”, a ich licencje
   (MIT, ISC albo Apache-2.0) i wersje są w ADR `docs/adr/admin-ui-library.md`; z `primevue` importują wyłącznie pliki
   z listy `LEGACY_FILES` (żaden plik powłoki, pulpitu, „Stanu systemu”, produktów ani `src/components/`).
2. `tests/tokens.test.ts` sprawdza kontrast każdej pary z tabeli tokenów i przechodzi; zmiana `muted-foreground` na
   `#6b7280` albo `input-border` na `#dfe2e8` sprawia, że test nie przechodzi.
3. ESLint zgłasza błąd dla `bg-[#fff]`, `text-[rgb(0,0,0)]`, `border-[color:red]` i `bg-[oklch(0.5_0.1_50)]`
   w klasie szablonu `.vue` i w łańcuchu w `<script>`/`.ts`, dla `bg-red-500` (nieznana klasa), dla statycznego
   `style="color: red"` i dla bloku `<style>` w pliku `.vue` spoza `LEGACY_FILES`; nie zgłasza go dla klas z tokenów
   (`bg-card`, `text-muted-foreground`). Test reguł sprawdza identyfikator reguły i liczbę błędów w każdym przypadku
   oraz to, że każdy wpis `LEGACY_FILES` istnieje i importuje `primevue`.
4. Nunito ładuje się z plików panelu (bez żądań do zewnętrznych hostów; CSP `script-src 'self'` bez naruszeń w teście
   dymnym), a `apps/admin/THIRD_PARTY_NOTICES.md` ma wpis licencji OFL z adresem źródła, wersją i SHA-256 plików.
5. Test E2E `e2e/tests/admin-design.spec.ts` uruchamia axe z regułami `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`,
   `wcag22aa` przy 1280 i 768 px na ekranach: `/` (także z komunikatem braku dostępu), `/system`, `/products`,
   `/products/new`, `/products/<id>` z otwartym oknem usunięcia wariantu i oknem niezapisanych zmian, oraz na otwartej
   szufladzie menu i menu konta, i nie dostaje żadnego naruszenia.
6. Ten sam test: pierwszy Tab na każdej stronie panelu (także niezmigrowanej) trafia na „Przejdź do treści”, a Enter
   przenosi fokus do `<main>`; na `/orders/<id>` tylko „Lista zamówień” ma `aria-current="page"`, a „Pulpit” nie jest
   wyróżniony na żadnej trasie poza `/`; przy 768 px sidebaru nie ma, „Menu” otwiera szufladę, `Escape` ją zamyka
   i fokus wraca na „Menu”.
7. Przy 768 px na liście produktów przyciski akcji pierwszego wiersza i jego status są w całości widoczne w oknie bez
   przewijania czegokolwiek w poziomie, a `scrollWidth` dokumentu równa się `clientWidth`.
8. Przy 375 px każde okno tego epiku (usunięcie wariantu, niezapisane zmiany, szuflada, menu konta) mieści się
   w szerokości ekranu (`x ≥ 0`, `x + width ≤ 375`).
9. Lista z adresu: `/products?q=buty&status=published&perPage=10` pokazuje wypełnione wyszukiwanie, filtr statusu
   i 10 wierszy; zmiana filtra zmienia adres bez nowego wpisu historii; „Wyczyść filtry” wraca do `/products`.
   Test Vitest `useListUrlState` sprawdza odczyt, zapis, nieznaną wartość, nieistniejącą kategorię, za krótkie `q`,
   zmianę rozmiaru strony i zmianę `route.query` z zewnątrz przy zamontowanym widoku (jedno żądanie, pierwsza
   strona).
10. Po błędzie 500 listy produktów przy zmianie zapytania nie ma wierszy ani pagera, jest komunikat z „Spróbuj
    ponownie”; po błędzie sąsiedniej strony wyświetlona strona zostaje; po ponowieniu z 403 fokus jest na komunikacie,
    nie na `body` (testy `useCursorPager`, `ListPager` i listy produktów). `useProductList` nie ma już własnego stosu
    kursorów.
11. „Pokazano 26–50” na drugiej stronie listy produktów przy 25 na stronę; „Następna strona” zostaje z fokusem
    po przejściu, a na ostatniej stronie fokus przechodzi na „Poprzednia strona” (test komponentu).
12. Pusta lista produktów bez filtrów pokazuje „Nie ma jeszcze produktów.”, a z filtrami „Brak wyników dla wybranych
    filtrów.” z „Wyczyść filtry” (test komponentu).
13. Formularz produktu: pasek zapisu pojawia się po pierwszej zmianie (dla nowego od początku) i znika po zapisie;
    pole z fokusem przewinięte do dołu obszaru nie jest zasłonięte paskiem (E2E: prostokąt pola nad prostokątem
    paska); przejście na inną trasę z niezapisanymi zmianami pyta oknem i „Zostań” zostawia formularz; zamknięcie
    karty ostrzega poza wylogowaniem (testy komponentu).
14. Okna potwierdzenia: usunięcie wariantu ma okno z nazwą wariantu i czerwonym przyciskiem skutku; `Escape` zamyka
    i fokus wraca na wyzwalacz; po publikacji, wycofaniu i usunięciu wariantu fokus jest na nagłówku karty („Status”,
    „Warianty”), nie na `body` (testy komponentów).
15. Toast w regionie `aria-live` pojawia się po zapisie, publikacji i wycofaniu produktu.
16. Pulpit: „Odśwież”, „Dane z …” i odświeżanie co 30 s wstrzymane w ukrytej karcie działają jak dziś (testy
    `dashboard-data-freshness` przechodzą dla pulpitu i dla `/system`); kafel „Produkty” jest linkiem do `/products`;
    „Do obsługi” pokazuje wiersze według ról z sekcji pulpitu (test komponentu dla każdej roli); „Ostatnie zamówienia”
    pokazuje do 5 zamówień z `itemCount` dla ról z dostępem i nie wysyła `GET /orders` dla `marketing`.
17. `/system` dla `admin` pokazuje usługi z „Działa”/„Nie działa” i „Zdarzenia czekające na wysłanie”; kryteria
    świeżości z `dashboard-data-freshness` przechodzą na tym ekranie; `marketing` wchodzący na `/system` albo
    `/orders` ląduje na pulpicie z komunikatem „Nie masz dostępu do tej strony.”, który nie wraca po przeładowaniu.
18. Topbar: Enter w „Szukaj produktu…” z „buty” przechodzi do `/products?q=buty` (także gdy lista produktów jest
    już otwarta: lista pokazuje wyniki dla „buty” od pierwszej strony); Enter z „b”, z samymi spacjami albo ze 101
    znakami nie zmienia trasy, a fraza „b” i 101 znaków pokazują „Wpisz od 2 do 100 znaków.”; link „Sklep” ma adres
    z `VITE_STORE_URL` i otwiera nową kartę; role są po polsku. `docker/40-admin-config.sh` bez `VITE_STORE_URL`
    kończy start błędem z nazwą zmiennej, także dla pustej wartości i białego znaku, a z czterema zmiennymi zapisuje
    `config.js` z czterema kluczami (nowy test `infra/k8s/tests/test_admin_config.py` uruchamia skrypt w `sh`
    z katalogiem wyjścia w katalogu tymczasowym; biegnie w `make -C infra/k8s check`); `test_workloads.py` i test
    dymny sprawdzają `VITE_STORE_URL` w środowisku kontenera i w `/config.js`.
19. Stare listy z adresu: `/orders?status=paid` i `/orders?refund=pending` pokazują przefiltrowaną listę zamówień,
    `/promotions?status=active` i `/discount-codes?status=active` przefiltrowane listy; wybór w menu „Zwroty do
    wykonania” na otwartej liście zamówień przełącza ją bez przeładowania; nieznana wartość jest pomijana (testy
    komponentów i E2E dla zamówień).
20. Istniejące testy E2E panelu (`catalog-admin`, `price-change`, `stock-receipt`, `promotions`, `discount-codes`,
    `order-management`, `staff-dashboard`) przechodzą po dostosowaniu nawigacji do nowego menu; `catalog-admin` nie
    używa klas PrimeVue; testy jednostkowe niezmigrowanych ekranów przechodzą bez zmian asercji.
21. Reflow (WCAG 1.4.10): test E2E przy 320 × 640 px na każdym ekranie i oknie z kryterium 5 sprawdza, że
    `scrollWidth` dokumentu równa się `clientWidth`, że każdy przycisk, link i pole ekranu (także pasek zapisu
    i akcje wierszy) da się osiągnąć Tabem i jest w całości widoczny po przewinięciu do niego (`scrollIntoView`),
    a menu otwiera się z szuflady.
22. Zachowane informacje: przy 768 i 320 px kategoria i ostatnia zmiana pierwszego wiersza listy produktów są widoczne
    w komórce głównej (E2E).
23. Zachowane funkcje: każda pozycja spisu „Powłoka”, „Pulpit i Stan systemu” i „Produkty” z sekcji „Zachowane
    funkcje” ma test (istniejący albo nowy) albo test E2E, który ją wykonuje; testy composables przechodzą bez zmian
    asercji poza zmianami opisanymi w tej specyfikacji.
24. Każde zgłoszenie z linii `Issues` (poza #460) ma test z kolumny „Weryfikacja” w sekcji „Zgłoszenia zamykane przez
    epik”, nazwany numerem zgłoszenia (np. `#251` w nazwie testu), więc raport `/integration` może wypisać wynik
    każdego.
25. `.claude/agents/ux-reviewer.md` i `docs/features/agent-tester-ux/spec.md` opisują ocenę panelu przy 1280, 768
    i 375 px, ocenę wyglądu na zrzutach każdego ekranu, przejście samą klawiaturą, reflow przy 320 px i tabelę
    weryfikacji zgłoszeń epiku w raporcie; testy skryptów (`.github/scripts/script-tests.sh`) przechodzą.
26. Przechodzą `make check`, `make -C apps/admin test`, `make e2e` i `make -C infra/k8s check`.

## Dokumenty do zmiany

Razem z kodem, w zadaniu, które zmienia opisane zachowanie:

- `docs/adr/admin-ui-library.md` (nowy), `docs/vision.md` (tabela „Aplikacje”), `README.md` (tabela stosu), `CLAUDE.md`
  (linia `apps/admin` w „Struktura”).
- `apps/admin/CLAUDE.md`: nagłówek ze stosem, „Struktura” (`src/components/ui`, `src/components/list`, `src/styles`,
  `src/layout`, ekran „Stan systemu”, `VITE_STORE_URL`), „Wygląd” (tokeny, reguła koloru, komponenty shadcn-vue,
  ikony Lucide, standard listy, formularza i okien), opisy widoków, które zmieniają zachowanie (strażnik zmian, okna
  potwierdzenia, stan listy w adresie, czyszczenie wierszy po błędzie, fokus, odczyt filtrów z adresu w starych
  listach), „Konfiguracja” (cztery adresy), lista ekranów jeszcze na PrimeVue (do ADMIN-SCREENS).
- `docs/features/walking-skeleton/spec.md`: „Layout i dashboard” (pulpit i osobny ekran „Stan systemu”) i tabela
  zależności panelu.
- `docs/features/catalog-admin/spec.md` (wynik akcji: toast i fokus według tej specyfikacji zamiast komunikatu sukcesu;
  okno potwierdzenia wariantu; strażnik niezapisanych zmian), `docs/features/order-management/spec.md` (strażnik trasy
  przed 403 przy bezpośrednim adresie, filtry listy z adresu).
- `e2e/support/staff-login.ts` czeka na nagłówek „Pulpit” zamiast „Dashboard”; pomocnicze E2E nawigujące przez menu
  (`catalog-admin.ts`, `panel-orders.ts`, `price-change.ts`, `promotions.ts`, `discount-codes.ts`,
  `stock-receipt.spec.ts`) używają nowych pozycji menu.
- `docs/features/dashboard-data-freshness/spec.md`: zachowanie dotyczy ekranu „Stan systemu”, czas po polsku.
- `docs/features/agent-tester-ux/spec.md` i `.claude/agents/ux-reviewer.md`: panel oceniany przy 1280, 768 i 375 px,
  także wizualnie według tej specyfikacji (tokeny, układ, standard listy i formularza) na zrzutach każdego ekranu,
  z przejściem samą klawiaturą i sprawdzeniem reflow przy 320 px, nie tylko pod kątem użyteczności i dostępności
  (sekcja „Weryfikacja UX i odbiór”).
- `apps/admin/docker/security-headers.conf` (komentarz o `style-src`).

## Wymagane przed startem

Brak. Epik zmienia tylko kod panelu, testy, dokumentację i jedną zmienną środowiska wyliczaną z domeny w compose
i chartcie; pakiety npm pochodzą z publicznego rejestru, a pliki Nunito (OFL) z publicznego repozytorium Google Fonts,
bez sekretów, kont i decyzji infrastrukturalnych.

## Decyzje

Podjęte przez właściciela 2026-11-12: dwa epiki (ten bez API, potem ADMIN-FEATURES); PrimeVue zastępują Tailwind
CSS 4 i shadcn-vue (Reka UI, TanStack Table, Lucide; TanStack Table do potwierdzenia w D13); wygląd według makiety (ciemny sidebar z grupami i zagnieżdżonym
menu, biały topbar, okruszki, pastelowe kafle KPI z kropką w stopce, białe karty z cieniem, niebieski kolor
interfejsu, pomarańczowy przycisk główny Worf z ciemnym tekstem), WCAG 2.2 AA.

Do potwierdzenia przy akceptacji (wpisane rekomendowane warianty):

- D1. Lista nowych zależności i usuniętych pakietów z sekcji „Biblioteka komponentów” oraz ADR.
- D2. Krój Nunito z plików w repo (z makiety) zamiast Outfit sklepu albo kroju systemowego.
- D3. Migracja nie usuwa żadnej istniejącej funkcji (sekcja „Zachowane funkcje”). Elementy makiety bez danych albo
  funkcji w API nie są pokazywane w tym epiku: powiadomienia, liczniki w menu,
  KPI i wykres sprzedaży, „Najczęściej kupowane”, kolumny SKU, Stan, Cena i Zamówienia na liście produktów,
  przełącznik siatka/lista, filtr zakresu cen, menu „Nowy”, pełny ekran, ustawienia, szybkie akcje, pozycje Klienci,
  Kategorie, Raporty i Dziennik zmian; zaznaczanie i sortowanie list.
- D4. Lista produktów nie ma rzędu kafli KPI; kafle są tylko na pulpicie.
- D5. Pole wyszukiwania w topbarze szuka produktów (przejście do listy z frazą 2–100 znaków; krótsza nie nawiguje
  i pokazuje podpowiedź).
- D6. Nowa zmienna `VITE_STORE_URL` (compose i chart) dla linku „Sklep” i akcji „Podgląd w sklepie”.
- D7. Telefon: lista i szczegóły zamówienia zaprojektowane pod telefon (ADMIN-SCREENS), okna mieszczą się na ekranie,
  a każdy ekran spełnia reflow przy 320 px (WCAG 1.4.10) z dostępem do wszystkich informacji i funkcji.
- D8. Zawartość pulpitu: kafle katalogu, „Do obsługi” jako linki według ról, „Ostatnie zamówienia”; „Stan systemu” na
  `/system` tylko dla `admin`.
- D9. Rozmiary strony 10/25/50, domyślnie 25 (Ceny i Stany: 10 produktów); filtry w adresie, pozycja stronicowania nie.
- D10. Strażniki tras `/orders` i `/system`; `/prices` bez strażnika, bo API daje ceny każdemu pracownikowi.
- D11. Zgłoszenia z opisu epiku zamykane są w całości (także #250, #260, #304, #337 i #393, w opisie „częściowo”);
  każde zamyka dopiero pozytywny wynik jego weryfikacji (sekcja „Zgłoszenia zamykane przez epik”). Ten epik zamyka
  #250, #251 i #346, pozostałe ADMIN-SCREENS.
- D12. Weryfikacja UX według sekcji „Weryfikacja UX i odbiór”: agent `ux-reviewer` przy 1280, 768 i 375 px (także
  wizualnie i przejściem klawiaturą), zrzuty ekranu każdego ekranu do przeglądu, ręczne sprawdzenie klawiaturą przez
  właściciela przy odbiorze.
- D13. TanStack Table nie wchodzi w tym epiku (zmiana decyzji z 2026-11-12); `DataTable` stoi na komponencie `Table`
  shadcn-vue, a TanStack Table dojdzie w ADMIN-FEATURES z sortowaniem i zaznaczaniem.
- D14. Podział na dwie części (2026-11-12, przy akceptacji): ten epik (fundament, powłoka, pulpit, „Stan systemu”,
  produkty, standardy) i ADMIN-SCREENS (#462: ceny, stany, promocje, kody, zamówienia, usunięcie PrimeVue). Między
  częściami panel na `main` ma nowy wygląd powłoki, pulpitu i produktów, a pozostałe ekrany stary.

## Poza zakresem

Każda zmiana API i klienta TS (sortowanie, liczniki wyników, KPI sprzedaży, kolejki z liczbami, filtr „bez zdjęć”,
filtr poziomu stanu, klienci, kategorie, akcje masowe, eksport, wyszukiwarka globalna Ctrl+K, dziennik zmian,
wyszukiwanie częściowe zamówień): ADMIN-FEATURES (#461). Tryb ciemny. Zwijanie sidebaru na desktopie do ikon. Wygląd
strony logowania Keycloaka. Ostrzeżenie przed wylogowaniem po bezczynności. Cena i stan w formularzu produktu
(ADMIN-FEATURES). Pełna wersja na telefon poza listą i szczegółami zamówienia. Ceny, stany, promocje, kody rabatowe
i zamówienia w nowym wyglądzie oraz usunięcie PrimeVue: ADMIN-SCREENS (#462).

## Ryzyka

- **Mieszany wygląd na `main`.** Do końca ADMIN-SCREENS działają PrimeVue i Tailwind; ceny, stany, promocje, kody
  i zamówienia mają stary wygląd w nowej powłoce, a preflight Tailwinda zmienia wygląd ich surowych elementów
  (nagłówki, listy). Dotyczy też panelu demo (za Cloudflare Access). Ich działanie sprawdzają istniejące testy E2E.
- **Regresje zachowania.** Widoki mają dużo dopracowanego zachowania (fokus, wersje, 409, idempotencja). Logika zostaje
  w composables z testami; widoki zmieniają szablon, a testy widoków przechodzą z selektorów PrimeVue na role, etykiety
  i `data-testid`.
- **Reka UI w jsdom.** Okna i listy wyboru Reka UI używają pozycjonowania i zdarzeń wskaźnika, których jsdom nie ma
  w pełni; testy komponentów mogą wymagać atrap (`ResizeObserver`, `scrollIntoView`) w konfiguracji Vitest.
- **Agent UX** przegląda panel przy 1280, 768 i 375 px; nie zastąpi oceny wyglądu i ręcznego przejścia klawiaturą
  przez właściciela na żywym stosie, dlatego odbiór ma oba kroki.
