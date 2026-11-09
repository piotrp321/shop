# Makieta wybranego kierunku wizualnego sklepu Worf

`kierunek.html` to makieta kierunku A „Granit” z czterema poprawkami właściciela, potwierdzona przez niego w VISUAL-DESIGN-4. To samodzielny, statyczny dokument (CSS w pliku, bez JavaScriptu, bez żądań sieciowych) z trzema ekranami: stroną główną, listingiem kategorii „Kajaki” i kartą produktu. Ekrany działają w 1440 i 375 px (responsywny CSS, bez poziomego przewijania) i mają podpisy; stany interfejsu są pokazane w podpisanych panelach „Stan: …” na dole ekranu. Zrzuty pełnej wysokości ekranów są obok pliku HTML: `kierunek-{glowna,listing,produkt}-{1440,375}.png` (6 plików).

Makieta jest ilustracją z chwili wyboru. Źródłem prawdy jest specyfikacja `docs/features/visual-design/spec.md` (sekcja „Wybrany kierunek”), a po VISUAL-DESIGN-5 także `apps/store/src/styles/tokens.css`; późniejsze zmiany wyglądu nie aktualizują makiety. Poniższe teksty to wskazówki wdrożenia makiety w zadaniach VISUAL-DESIGN-5 do 9.

## Poprawki właściciela i ich skutki w makiecie

1. **Różnice między produktami.** Listing to dopracowany przykład kategorii „Kajaki” (Sporty wodne › Kajaki, 4 produkty), w którym każdy model ma własne zdjęcie. Karta produktu (Narty skiturowe Stavro Grań 95) ma galerię trzech zdjęć w kolejności: cały sprzęt, istotny detal (końcówki nart), zdjęcie w użyciu; licznik „1 / 3”, trzy miniatury, powiększenie pokazuje pierwsze zdjęcie.
   - **Zdjęcia w sklepie.** Zdjęcia z `mockups/images/` są ilustracją tej poprawki i nie pasują dokładnie do danych: narty na zdjęciu 1 karty produktu są czarne, a wybrany kolor to „niebieskie”; Vilmar Rzeka 2 to w danych dwuosobowy kajak pompowany, a jego zdjęcie pokazuje dwa osobne kajaki (zielony i żółty) na brzegu; pod filtrem „Kolor: zielony” część kafelków pokazuje czerwone kajaki. Sklep pokaże własne zdjęcie każdego z czterech kajaków i galerię trzech zdjęć nart z danych demo, które dodaje VISUAL-DESIGN-11 (spec: „Zdjęcia produktów w danych demo (VISUAL-DESIGN-11)”); to te zdjęcia, nie zdjęcia makiety, są wzorem dla sklepu. Makieta pokazuje kompozycję galerii i listingu, nie dopasowanie zdjęć do opisów.
2. **Nagłówek na telefonie (< 1024 px).** Dwa rzędy: znak „Worf”, przycisk „Menu” (lista działów otwiera się jako panel pod nagłówkiem), koszyk i konto w pierwszym rzędzie, a pod nimi wyszukiwarka na pełną szerokość. Nagłówek ma 123 px wysokości (trzy rzędy miałyby 177 px). Desktop: jeden wiersz, 81 px. Hero (wędrowcy, „Sprzęt na każdy szlak”) i kafelki działów jak w kierunku A.
3. **Wybór wariantu na kafelku.** Produkt z wariantami ma jedną aktywną akcję „Wybierz wariant” (bez listy `<select>` i bez nieaktywnego „Do koszyka”). Po jej użyciu w miejscu przycisku pojawia się wybór wartości osi (przyciski jak na karcie produktu; wartość niedostępna ma przerywaną ramkę i tekst „niedostępny”; na telefonie przyciski zajmują całą szerokość kafelka), a po wyborze etykieta dostępności wariantu i aktywny „Do koszyka”. Produkt bez wariantów ma od razu aktywny „Do koszyka”. Ta sama reguła dotyczy kafelków na stronie głównej.
4. **Stopka.** Grafitowe tło. Znak „Worf” ma 24 px (telefon) i 28 px (desktop), pionowe odstępy są krótkie. Desktop: logo po lewej, linki działów po prawej w dwóch kolumnach (stopka ma 243 px wysokości). Telefon: małe logo nad linkami w dwóch kolumnach, linki mają 44 px wysokości (stopka ma 304 px). Pod cienką linią jeden dyskretny wiersz: „© 2026 Worf · Zdjęcia: Unsplash”.

## Wymagania dla wdrożenia (VISUAL-DESIGN-5 i kolejne)

Makieta jest statyczna i nie pokazuje zachowania klawiatury, więc te wymagania obowiązują wdrożenie niezależnie od zrzutów:

- **Kolejność tabulatora w nagłówku na telefonie (VISUAL-DESIGN-5).** Na 375 px fokus ma przechodzić po rzędach: znak, „Menu”, koszyk, konto, a dopiero potem wyszukiwarka z przyciskiem. Kolejność fokusu ma zgadzać się z kolejnością wizualną na każdej szerokości (WCAG 2.4.3). W makiecie DOM ma kolejność znak, „Menu”, wyszukiwarka, koszyk, konto (jak na desktopie), więc na telefonie, gdzie wyszukiwarka stoi w drugim rzędzie, tabulator odwiedza ją przed koszykiem i kontem: tego układu nie wolno przenieść do sklepu bez poprawki (np. inna kolejność w DOM i siatka z nazwanymi obszarami, osobno dla telefonu i desktopu, bez zdublowanego formularza).
- **Fokus po „Wybierz wariant” (kafelek, w tym strona główna).** Po aktywacji przycisku i rozwinięciu wyboru osi fokus przechodzi na pierwszą dostępną (niewyłączoną) wartość pierwszej osi; przycisk „Wybierz wariant” znika z miejsca wyboru, więc fokus nie może zostać na elemencie, którego już nie ma. Rozwinięcie i zmiana etykiety dostępności po wyborze wariantu są ogłaszane w obszarze `aria-live`. Wartość niedostępna nie jest pomijana przez czytnik: ma tekst „niedostępny”.

## Idea

„Granit” to jasna, ostra i przestrzenna strona: wysokie zdjęcie hero z tekstem prosto na zdjęciu (wędrowcy, „Sprzęt na każdy szlak”), jedna geometryczna rodzina fontów, kanciaste (4 px) elementy i pomarańczowy akcent, który pojawia się tylko tam, gdzie klient ma kliknąć. Nagłówek to jeden wiersz z panelem mega menu na całą szerokość, kafelki działów tworzą równy rząd, kafelki produktów nie mają ramek, a filtry stoją w lewej kolumnie.

## Paleta (OKLCH)

Wartości są w pliku HTML jako zmienne CSS `:root` (te same ciągi `oklch(...)`); kolumna sRGB to wartość po przeliczeniu, służy tylko do podglądu.

| Token (zmienna CSS) | OKLCH | sRGB | Zastosowanie |
|---|---|---|---|
| `--bg` | `oklch(0.975 0.004 95)` | `#f7f7f4` | tło strony (złamana biel) |
| `--surface` | `oklch(1 0 0)` | `#ffffff` | białe powierzchnie: pola, panel mega menu, karta filtrów |
| `--ink` | `oklch(0.21 0.008 260)` | `#16181c` | tekst podstawowy (grafit) |
| `--muted` | `oklch(0.46 0.012 260)` | `#54585f` | tekst drugorzędny: okruszki, liczba produktów, etykiety pól |
| `--line` | `oklch(0.58 0.012 260)` | `#767b82` | obramowania pól, selectów, przycisków, miniatur |
| `--hair` | `oklch(0.9 0.005 95)` | `#dfdeda` | separatory (dekoracyjne, nie niosą informacji) |
| `--accent` | `oklch(0.74 0.16 55)` | `#f68c36` | jedyny akcent: przycisk główny, plakietka koszyka, podkreślenie aktywnego działu |
| `--on-accent` | `oklch(0.2 0.008 260)` | `#14161a` | tekst na akcencie |
| `--accent-deep` | `oklch(0.5 0.125 50)` | `#9a490f` | ciemny odcień akcentu: linki, pierścień fokusu |
| `--dark` | `oklch(0.18 0.008 260)` | `#101215` | stopka, pasek podpisu, wybrany wariant, licznik, powiększenie zdjęcia |
| `--on-dark` | `oklch(0.96 0.004 95)` | `#f2f2ef` | tekst na ciemnym |
| `--on-dark-muted` | `oklch(0.78 0.01 260)` | `#b4b8be` | tekst drugorzędny na ciemnym |
| `--ok` | `oklch(0.44 0.09 155)` | `#1f613c` | etykieta „Dostępny” |
| `--low` | `oklch(0.5 0.16 38)` | `#aa3606` | etykieta „Ostatnie sztuki” |
| `--overlay` | `oklch(0.15 0.01 260)` | `#090b0f` | nakładka na zdjęciu hero (w obszarze tekstu kryje w 72%) |

## Kontrasty (WCAG 2.2 AA)

Stosunki policzone skryptem (OKLCH → sRGB → luminancja względna WCAG 2.x, zaokrąglone do 2 miejsc; pełny skrypt w sekcji „Jak policzono kontrasty”). Progi: tekst 4,5:1, duży tekst i elementy interfejsu 3:1. Wszystkie użyte pary spełniają próg. Kolor fokusu zależy od tła, na którym leży pierścień: `accent-deep` na jasnym tle i na białych powierzchniach, `accent` na ciemnych (stopka, powiększenie zdjęcia), `on-dark` na zdjęciu hero pod nakładką. Kolory poprawek właściciela to te same tokeny, więc nowe są tylko ich użycia (wiersze rozszerzone o kafelek z wyborem wariantu, przycisk „Menu” i nową stopkę).

| Tekst lub element | Tło | Gdzie | Rodzaj | Stosunek | Wymagany | Wynik |
|---|---|---|---|---|---|---|
| `ink` | `bg` | Tekst podstawowy na tle strony; napis „Wybierz wariant” na kafelku | tekst | 16,56:1 | 4,50:1 | spełnia |
| `ink` | `surface` | Tekst na białych polach i panelu mega menu; napis na przyciskach wartości osi na kafelku; napis „Menu” w pierwszym rzędzie nagłówka na telefonie (przycisk ma białe tło) | tekst | 17,77:1 | 4,50:1 | spełnia |
| `muted` | `bg` | Tekst drugorzędny (okruszki, liczba produktów, etykiety pól); etykieta osi „Kolor” i napis „niedostępny” na kafelku | tekst | 6,66:1 | 4,50:1 | spełnia |
| `muted` | `surface` | Tekst drugorzędny na białym | tekst | 7,15:1 | 4,50:1 | spełnia |
| `on-accent` | `accent` | Przycisk główny „Do koszyka” (karta produktu i kafelek) i link hero | tekst | 7,52:1 | 4,50:1 | spełnia |
| `on-dark` | `dark` | Tekst stopki (znak „Worf”, linki działów), pasek podpisu, powiększenie zdjęcia; wybrana wartość osi na kafelku | tekst | 16,73:1 | 4,50:1 | spełnia |
| `on-dark-muted` | `dark` | Tekst drugorzędny w stopce, w tym wiersz „© 2026 Worf · Zdjęcia: Unsplash” pod linią | tekst | 9,42:1 | 4,50:1 | spełnia |
| `ok` | `bg` | Etykieta „Dostępny” | tekst | 6,91:1 | 4,50:1 | spełnia |
| `ok` | `surface` | Etykieta „Dostępny” na białym | tekst | 7,41:1 | 4,50:1 | spełnia |
| `low` | `bg` | Etykieta „Ostatnie sztuki” (także na kafelku po wyborze wariantu) | tekst | 6,04:1 | 4,50:1 | spełnia |
| `low` | `surface` | Etykieta „Ostatnie sztuki” na białym | tekst | 6,48:1 | 4,50:1 | spełnia |
| `accent-deep` | `bg` | Link w tekście i wyróżniony tekst | tekst | 5,87:1 | 4,50:1 | spełnia |
| `accent-deep` | `surface` | Link „Wszystko z działu” w panelu mega menu | tekst | 6,30:1 | 4,50:1 | spełnia |
| `ink` | `bg` | Obrys aktualnej miniatury; obramowanie przycisku „Wybierz wariant” | element interfejsu | 16,56:1 | 3,00:1 | spełnia |
| `line` | `bg` | Obramowanie pól, selectów, przycisków i miniatur; obramowanie przycisków wartości osi na kafelku (także przerywane, wartość niedostępna) | element interfejsu | 3,97:1 | 3,00:1 | spełnia |
| `line` | `surface` | Obramowanie pól na białym; obramowanie przycisku „Menu” na telefonie | element interfejsu | 4,26:1 | 3,00:1 | spełnia |
| `accent-deep` | `bg` | Pierścień fokusu na tle strony | element interfejsu | 5,87:1 | 3,00:1 | spełnia |
| `accent-deep` | `surface` | Pierścień fokusu na białym | element interfejsu | 6,30:1 | 3,00:1 | spełnia |
| `accent` | `dark` | Pierścień fokusu na ciemnym tle (stopka, powiększenie zdjęcia) | element interfejsu | 7,79:1 | 3,00:1 | spełnia |
| `ink` | `accent` | Ramka fokusu na przycisku głównym | element interfejsu | 7,38:1 | 3,00:1 | spełnia |
| `on-dark` | `overlay` w 72% na zdjęciu (najjaśniejsze możliwe tło: biel `#ffffff`, po nakładce `#4e5053`) | Tekst hero na zdjęciu (nagłówek, akapit) | tekst | 7,21:1 | 4,50:1 | spełnia |
| `on-dark` | to samo (`overlay` w 72% nad bielą, `#4e5053`) | Pierścień fokusu przycisku hero na zdjęciu | element interfejsu | 7,21:1 | 3,00:1 | spełnia |
| `on-dark` | to samo, nagłówek hero 44 px i więcej (duży tekst) | Nagłówek hero | duży tekst | 7,21:1 | 3,00:1 | spełnia |

Cienka linia nad wierszem praw autorskich w stopce (`oklch(0.32 0.01 260)` na `dark`, 1,48:1) jest dekoracyjnym separatorem i nie niesie informacji (jak `--hair`).

## Fonty

Jedna rodzina, na licencji SIL Open Font License 1.1 (OFL), z polskimi znakami (podzbiory `latin` i `latin-ext`: „ąćęłńóśźż” w obu plikach razem), w plikach WOFF2 w `fonts/`. Pliki pochodzą z pakietu Fontsource (`@fontsource-variable/outfit` 5.3.0, pliki `outfit-<podzbiór>-wght-normal.woff2`), zmienna oś `wght`. Sklep dostanie te same pliki przez `next/font/local`. Makieta nie ładuje niczego z sieci; `@font-face` ma oba podzbiory z `unicode-range`.

- **Outfit** (geometryczny sans, plik zmienny `wght` 100–900): nagłówki 600, tekst 400/500, znak słowny „Worf” 700.
- Pliki: `fonts/outfit-latin-wght.woff2` (32 KB), `fonts/outfit-latin-ext-wght.woff2` (15 KB). Licencja: `fonts/OFL-Outfit.txt` (Copyright 2021 The Outfit Project Authors).
- Użyte wagi (sprawdza je skrypt zrzutów): 400, 500, 600, 700.

## Skala typografii

| Element | Telefon (375 px) | Desktop (1440 px) | Waga, ustawienia |
|---|---|---|---|
| Nagłówek hero (`h1`) | 44 px / 1,02 | 84 px / 1,02 | 600, odstęp liter −0,04 em |
| Akapit hero | 18 px | 22 px | 400 |
| `h1` listingu | 36 px / 1,1 | 48 px / 1,1 | 600, −0,025 em |
| Nazwa produktu na karcie (`h1`) | 32 px | 44 px | 600 |
| Cena na karcie | 28 px | 34 px | 600 |
| `h2` sekcji („Wszystkie produkty”) | 28 px | 36 px | 600 |
| Nazwa działu na kafelku | 16 px | 17 px | 600 |
| Nazwa produktu na kafelku | 15 px | 16 px | 500 |
| Cena na kafelku | 17 px | 17 px | 600 |
| Tekst, pola, przyciski | 16 / 15 px | 16 / 15 px | 400 / 600 |
| Etykieta dostępności, etykiety pól | 13 px | 13 px | 600 / 400 |
| Okruszki, liczba produktów | 14–18 px | 14–18 px | 400 |
| Znak słowny „Worf” | 28 px (stopka 24 px) | 28 px (stopka 28 px) | 700, −0,045 em |
| Wiersz praw autorskich w stopce | 13 px / 1,4 | 13 px / 1,4 | 400 |

## Promienie, cienie, odstępy

- Promień: 4 px wszędzie (zdjęcia, pola, przyciski, chipsy); przyciski galerii i wybór wariantu też 4 px (przyciski strzałek galerii okrągłe). Cieni brak.
- Odstępy: siatka 4 px (4, 8, 12, 16, 20, 24, 32, 48, 72, 112). Margines boczny 16 px (telefon) i 48 px (desktop), maksymalna szerokość treści 1440 px.
- Siatka produktów: 2 kolumny z odstępem 14 px (telefon), 4 kolumny z odstępem 20 px w poziomie i 48 px w pionie (desktop). Filtry: kolumna 260 px, odstęp 56 px.
- Stopka: pionowe odstępy 28 px u góry i 16 px na dole (telefon), 36 px i 18 px (desktop); linki w dwóch kolumnach z odstępem 24 px (telefon) i 64 px (desktop).
- Cele dotyku: przyciski, pola i wybór wariantu 44 px (przycisk „Do koszyka” na karcie 56 px), miniatury galerii 72 px (telefon) i 84 px (desktop), chipsy aktywnych filtrów 36 px, linki stopki 44 px (telefon) i 24 px (desktop, WCAG 2.5.8).

## Zawartość listingu i karty produktu

Nazwy, ceny i stany pochodzą z `apps/api/fixtures` (`catalog.yaml`, `prices.yaml`, `stock.yaml`), teksty interfejsu z kodu `apps/store/src` („Do koszyka”, „Niedostępny”, „Ostatnie sztuki”, „Dostępny”, „Wybierz wariant”, „Sortuj”, „Nazwa A–Z”, „Aktywne filtry”, „Wyczyść filtry”, „Następna”, „Filtry”, „Cena (zł)”, „Cena od”, „Cena do”, „Tylko dostępne”, „Wszystko z działu”, „Szukaj produktów”, „Koszyk”, „Zaloguj się”, „Menu”, „Wszystkie produkty”). Ceny są sformatowane jak w sklepie (`pl-PL`, np. `2999,00 zł`). Zdjęcia produktów i kafelków mają proporcje 1:1 (`aspect-ratio: 1; object-fit: cover` imituje kadr z rozmiarów `listing` i `product` z API); zdjęcia hero i działów mają własne kadry.

- **Listing „Kajaki”**: jeden aktywny filtr, chip „Kolor: zielony” (tekst jak w `ActiveFilters.tsx`: `<oś>: <wartość>`; każdy kajak ma wariant zielony, więc zostają wszystkie 4 produkty). Filtra ceny nie ma: filtr ceny dokłada warunek wariantu możliwego do kupienia (`variants.purchasable`), więc z nim Vilmar Rzeka 2 (stan 0) wypadłby z wyników. Pola „Cena od” i „Cena do” są puste. Panel filtrów ma grupy: „Kolor” z zaznaczonym polem „zielony” (`ListingFilters.tsx`: `checked`), „Cena (zł)” i „Tylko dostępne (3)”. Grupy „Kategorie” nie ma: „Kajaki” to liść drzewa, faseta kategorii to dzieci kategorii z filtra, więc sklep jej tu nie pokazuje (`facets.categories` jest puste). Sortowanie „Nazwa A–Z”. Kafelki: Kajak turystyczny Dravik Rzeka 1 (2499,00 zł, „Dostępny”, „Wybierz wariant”), Dravik Rzeka 2 (3499,00 zł, rozwinięty: „pomarańczowy niedostępny”, „zielony” wybrany, „Dostępny”, „Do koszyka”), Vilmar Rzeka 1 (2499,00 zł, „Wybierz wariant”), Vilmar Rzeka 2 („Niedostępny”, wszystkie warianty bez stanu, zdjęcie wyszarzone, bez przycisku). Kolejność wynika z sortowania po nazwie.
- **Liczby fasety listingu** (wyprowadzone z `stock.yaml`, `catalog.yaml`, `prices.yaml` i `ProductSearchQuery.php`; nie sprawdzone na działającym stosie): „pomarańczowy (4)” i „zielony (4)”: faseta wybranej osi liczy się bez jej własnego warunku i bez warunku dostępności, a każdy z czterech kajaków ma oba kolory (`axisValues: [pomarańczowy, zielony]`), więc obie wartości mają 4 produkty. „Tylko dostępne (3)”: `availableCount` to liczba produktów po dodaniu `available=true` do bieżących warunków, czyli z wariantem zielonym możliwym do kupienia; Dravik Rzeka 1 (zielony: 3), Dravik Rzeka 2 (zielony: 22) i Vilmar Rzeka 1 (40) mają taki wariant, Vilmar Rzeka 2 (wszystkie stany 0) nie. „Ceny od 2499,00 zł do 3499,00 zł”: min i max brutto wariantów możliwych do kupienia z warunkiem koloru, bez warunku ceny (2499,00 zł Rzeka 1, 3499,00 zł Rzeka 2; ceny nie mają promocji w `promotions.yaml`). Założenie: wariant ze stanem większym od 0 jest „możliwy do kupienia” (regułę trzyma Cart `GetProductOffers`).
- **Panele stanów listingu**: (a) kafelek Dravik Rzeka 1 po wybraniu „zielony” (stan 3, więc „Ostatnie sztuki”) obok kafelka Vilmar Rzeka 1 rozwiniętego bez wyboru (bez ceny wariantu i bez „Do koszyka”); (b) „produkt bez wariantów”: Hantle regulowane Lumora Para 10 (399,00 zł) i Hantle regulowane Dravik Para 24 (899,00 zł), szablon `Hantle regulowane` w `catalog.yaml` nie ma `variantAxes`; (c) paginacja „Poprzednia”, „Strona 2 z 3”, „Następna”; (d) panel filtrów na telefonie (przycisk „Filtry” z `aria-expanded`, sekcja w treści strony, nie okno modalne).
- **Strona główna**: nagłówek z mega menu (desktop) albo menu działów (telefon), hero, kafelki działów, początek listingu „Wszystkie produkty” z kafelkami jak na listingu (też z „Wybierz wariant”), stopka.
- **Karta produktu**: Narty skiturowe Stavro Grań 95, wartość „pomarańczowe” niedostępna (stan 0), „niebieskie” i długość „172 cm” wybrane, fokus klawiatury na „Do koszyka”, galeria trzech zdjęć (cały sprzęt, detal, w użyciu) z licznikiem „1 / 3”, miniaturami i powiększeniem.
- **Odstępstwa od danych demo** (dla pokazania układu): (1) zdjęcia kajaków i nart (pierwsze dwa w galerii) pochodzą z `mockups/images/` (źródła i licencje: `images/CREDITS.md`), bo dane demo nie mają jeszcze osobnych zdjęć tych produktów (dodaje je VISUAL-DESIGN-11); trzecie zdjęcie galerii i zdjęcia hantli oraz kafelków działów pochodzą z `apps/api/fixtures/images`; (2) w danych demo produkt ma dziś jedno zdjęcie z puli szablonu, a makieta pokazuje galerię trzech; (3) w sklepie 4 produkty to jedna strona i paginacji nie ma, dlatego jej styl pokazuje panel stanu; (4) hantle w panelu „produkt bez wariantów” mają zdjęcia szablonu (zajęcia jogi), bo to jedyne zdjęcia tego szablonu w danych demo; (5) tytuły i teksty hero oraz podpisy ekranów to propozycje na potrzeby makiety.

## Jak obejrzeć makietę

- Otwórz `kierunek.html` w przeglądarce (z dysku, `file://`); fonty i zdjęcia są pobierane ze ścieżek względnych (`fonts/`, `images/`, `../../../../apps/api/fixtures/images/`), więc plik trzeba otwierać z jego miejsca w repozytorium. Chromium otwiera makietę bez dodatkowych ustawień.
- Wersja 375 px: narzędzia deweloperskie przeglądarki, tryb responsywny (np. Chrome: `Ctrl/Cmd + Shift + M`, szerokość 375). Poniżej 1024 px działa układ telefonu, od 1024 px układ desktopu.
- Makieta nie używa `position: sticky` ani żadnego innego położenia zależnego od przewijania, więc zrzut pełnej wysokości pokazuje układ tak, jak widać go po przewinięciu strony od góry do dołu (filtry i dane produktu zaczynają się na wysokości galerii i siatki).
- Każdy ekran to osobna sekcja (`#glowna`, `#listing`, `#produkt`) z paskiem podpisu u góry; panele „Stan: …” są pod stroną: mega menu (desktop), menu działów (telefon), panel filtrów (telefon), kafelek po wyborze wariantu, produkt bez wariantów, paginacja, powiększenie zdjęcia.

## Zrzuty ekranu

Zrzuty robi Playwright przez `make e2e-agent` z katalogiem roboczym poza repozytorium. Skrypt otwiera `kierunek.html` z `file://` w dwóch szerokościach (1440 i 375 px, `deviceScaleFactor` 1) i zapisuje zrzut każdej z trzech sekcji w pełnej wysokości (`locator(...).screenshot`) jako `kierunek-<ekran>-<szerokość>.png`. Test kończy się błędem, gdy wystąpi którekolwiek z poniższych:

- błąd konsoli (`console` typu `error`), `pageerror`, `requestfailed` albo odpowiedź ≥ 400;
- każde żądanie poza `file://` (makieta niczego nie pobiera z sieci);
- fonty: skrypt czeka na `document.fonts.ready`, ładuje każdą użytą wagę Outfit z tekstem „ąćęłńóśźż Worf”, a potem wymaga `document.fonts.check(...)` dla każdej z nich, wczytanej (`status === "loaded"`) rodziny w `document.fonts`, braku fontów w stanie `error` i tego, że każda rodzina i waga faktycznie użyta na stronie jest na liście sprawdzanych (zrzut nie może pokazać fontu zastępczego);
- niezaładowane zdjęcie (`naturalWidth` równe 0);
- poziome przewijanie (`document.documentElement.scrollWidth` większe od szerokości okna);
- brak widocznego elementu stanu w danej szerokości: mega menu tylko przy 1440 px, menu działów i panel filtrów na telefonie tylko przy 375 px; aktywny filtr, kafelek „Niedostępny”, „Ostatnie sztuki”, fokus, powiększenie zdjęcia, miniatury i licznik przy obu, przycisk „Wybierz wariant” na kafelkach (także na stronie głównej), rozwinięty wybór osi z wartością niedostępną i wybraną, aktywny „Do koszyka” na kafelku, panele „kafelek po wyborze wariantu”, „produkt bez wariantów” i „paginacja” oraz wiersz praw autorskich w stopce;
- pomiary wyrenderowanej strony: kafelek ma jedną akcję (nie ma w nim `<select>` ani nieaktywnego „Do koszyka”, „Wybierz wariant” nie stoi obok rozwiniętego wyboru) i nic nie wystaje poza kafelek (ramki paneli stanów ucinają nadmiar, więc ten pomiar łapie to, czego nie widać na zrzucie); cele dotyku głównych kontrolek mają co najmniej 44 px (linki stopki na desktopie 24 px); nagłówek na telefonie ma dwa rzędy (znak, „Menu” i koszyk w jednym rzędzie, wyszukiwarka pod nimi na pełną szerokość, wysokość nagłówka najwyżej 140 px); stopka ma jeden wiersz praw autorskich, linki w dwóch kolumnach, logo po lewej i linki po prawej na desktopie, logo nad linkami na telefonie i nie przekracza 260 px (desktop) ani 400 px (telefon) wysokości.

Skrypt zapisuje się jako `$AGENT_DIR/tests/mockups.spec.ts` (katalog `tests` czyta `playwright.agent.config.ts`). Zrzuty trafiają do `$AGENT_DIR/shots/`, stamtąd do katalogu makiety. Konfiguracja agenta uruchamia projekt `desktop`; szerokość okna skrypt ustawia sam.

Komendy (z katalogu głównego repozytorium; `AGENT_DIR` musi istnieć i być ścieżką bezwzględną):

```sh
AGENT_DIR="$HOME/.local/state/worf-mockups"   # poza repozytorium
mkdir -p "$AGENT_DIR/tests" "$AGENT_DIR/shots" "$AGENT_DIR/docs/features/visual-design" "$AGENT_DIR/apps/api/fixtures"
rm -rf "$AGENT_DIR/docs/features/visual-design/mockups" "$AGENT_DIR/apps/api/fixtures/images"
cp -R docs/features/visual-design/mockups "$AGENT_DIR/docs/features/visual-design/mockups"
cp -R apps/api/fixtures/images "$AGENT_DIR/apps/api/fixtures/images"
# zapisz skrypt z bloku poniżej jako "$AGENT_DIR/tests/mockups.spec.ts", potem:
make e2e-agent AGENT_DIR="$AGENT_DIR" E2E_ARGS="--project=desktop mockups.spec.ts"
cp "$AGENT_DIR"/shots/kierunek-{glowna,listing,produkt}-{1440,375}.png docs/features/visual-design/mockups/
```

Stos (`make up`) musi działać, bo kontener `e2e` sprawdza jego usługi przed uruchomieniem testów, choć sama makieta z niego nie korzysta.

Skrypt `mockups.spec.ts`:

```ts
import { expect, test } from "@playwright/test";

// Screenshots of the chosen visual direction mockup `kierunek.html`, opened from file:// (VISUAL-DESIGN-4).
// Fails on: console errors, page errors, failed requests, responses >= 400, any request outside file://,
// fonts that document.fonts does not confirm (also for the Polish letters), horizontal scroll at 375 px,
// images that did not load, missing state elements and layout rules checked by measuring the page.
const ROOT = "file:///app/agent/docs/features/visual-design/mockups";
const SHOTS = "/app/agent/shots";
const FILE = "kierunek.html";
const POLISH = "ąćęłńóśźż";
const FAMILIES: Record<string, number[]> = { Outfit: [400, 500, 600, 700] };

type Wanted = [selector: string, widths: number[]];
const BOTH = [1440, 375];
const WIDTHS = [1440, 375];
type Screen = { id: string; wanted: Wanted[] };

// Elements that must be visible, with the widths where they are shown (the mega menu only at 1440, the department menu only at 375).
const SCREENS: Screen[] = [
  {
    id: "glowna",
    wanted: [[".state-mega", [1440]], [".state-mobile-menu", [375]], [".dept", BOTH], [".hero-h1", BOTH], [".tile .btn--variant", BOTH], [".ftr .ftr-bottom", BOTH]],
  },
  {
    id: "listing",
    wanted: [
      [".crumbs", BOTH], [".page-head h1", BOTH], [".chips li", BOTH], [".select.is-focus", BOTH], [".panel.is-open", BOTH], [".state-filters", [375]],
      [".tile .btn--variant", BOTH], [".tile .tile-axis .opt.is-unavail", BOTH], [".tile .tile-axis .opt.is-chosen", BOTH], [".tile .btn--primary", BOTH],
      [".avail--out", BOTH], [".avail--low", BOTH], [".tile-price", BOTH], [".state-variant-open", BOTH], [".state-no-variants", BOTH], [".state-pager .pager", BOTH], [".ftr .ftr-bottom", BOTH],
    ],
  },
  { id: "produkt", wanted: [[".thumb", BOTH], [".counter", BOTH], [".opt.is-unavail", BOTH], [".opt.is-chosen", BOTH], [".btn--buy.is-focus", BOTH], [".state-zoom", BOTH], [".ftr .ftr-bottom", BOTH]] },
];

test.use({ trace: "off", screenshot: "off" });
test.describe.configure({ mode: "serial" });

for (const width of WIDTHS) {
  test(`kierunek.html at ${width} px`, async ({ page }) => {
    const problems: string[] = [];
    page.on("console", (message) => {
      if (message.type() === "error") problems.push(`console error: ${message.text()}`);
    });
    page.on("pageerror", (error) => problems.push(`page error: ${error.message}`));
    page.on("requestfailed", (request) => problems.push(`request failed: ${request.url()} (${request.failure()?.errorText})`));
    page.on("request", (request) => {
      if (!request.url().startsWith("file://")) problems.push(`request outside file://: ${request.url()}`);
    });
    page.on("response", (response) => {
      if (response.status() >= 400) problems.push(`response ${response.status()}: ${response.url()}`);
    });

    await page.setViewportSize({ width, height: width === 1440 ? 900 : 812 });
    await page.goto(`${ROOT}/${FILE}`, { waitUntil: "load" });

    // Wait for the fonts, then make sure each used family and weight really loaded (check() alone is true for an unknown family).
    const fontReport = await page.evaluate(
      async ({ families, polish }) => {
        await document.fonts.ready;
        const wanted: string[] = [];
        const loadErrors: string[] = [];
        for (const [family, weights] of Object.entries(families as Record<string, number[]>)) {
          for (const weight of weights) {
            const spec = `${weight} 16px "${family}"`;
            wanted.push(spec);
            try {
              await document.fonts.load(spec, `${polish} Worf`);
            } catch (error) {
              loadErrors.push(`${spec}: ${String(error)}`);
            }
          }
        }
        await document.fonts.ready;
        const notConfirmed = wanted.filter((spec) => !document.fonts.check(spec, `${polish} Worf`));
        const faces = [...document.fonts].map((face) => ({ family: face.family.replaceAll('"', ""), status: face.status }));
        const notLoaded = Object.keys(families).filter((family) => !faces.some((face) => face.family === family && face.status === "loaded"));
        const notLoadedFaces = faces.filter((face) => face.status === "error");
        // Every family and weight that the page really uses must be in the list that was checked.
        const used = new Set<string>();
        for (const element of document.querySelectorAll("body *")) {
          if (!(element.textContent ?? "").trim() || !(element as HTMLElement).offsetParent) continue;
          const style = getComputedStyle(element);
          const first = style.fontFamily.split(",")[0].trim().replaceAll('"', "");
          used.add(`${Math.round(Number(style.fontWeight) / 100) * 100}|${first}`);
        }
        const unlisted = [...used].filter((entry) => {
          const [weight, family] = entry.split("|");
          const list = (families as Record<string, number[]>)[family];
          return !list || !list.includes(Number(weight));
        });
        return { loadErrors, notConfirmed, notLoaded, notLoadedFaces, unlisted };
      },
      { families: FAMILIES, polish: POLISH },
    );
    if (fontReport.loadErrors.length > 0) problems.push(`font failed to load: ${fontReport.loadErrors.join("; ")}`);
    if (fontReport.notConfirmed.length > 0) problems.push(`document.fonts.check does not confirm: ${fontReport.notConfirmed.join(", ")}`);
    if (fontReport.notLoaded.length > 0) problems.push(`font family not loaded: ${fontReport.notLoaded.join(", ")}`);
    if (fontReport.notLoadedFaces.length > 0) problems.push(`font faces with an error: ${JSON.stringify(fontReport.notLoadedFaces)}`);
    if (fontReport.unlisted.length > 0) problems.push(`weight|family used but not checked (add it to the script): ${fontReport.unlisted.join(", ")}`);

    // Every image has loaded (a missing photo is also a failed request, this is the second net).
    const brokenImages = await page.evaluate(() =>
      [...document.images].filter((image) => !image.complete || image.naturalWidth === 0).map((image) => image.getAttribute("src")),
    );
    if (brokenImages.length > 0) problems.push(`images not loaded: ${brokenImages.join(", ")}`);

    // No horizontal scroll.
    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    if (scrollWidth > width) problems.push(`horizontal scroll: scrollWidth ${scrollWidth} > ${width}`);

    for (const screen of SCREENS) {
      const section = page.locator(`#${screen.id}`);
      await expect(section).toHaveCount(1);
      for (const [selector, widths] of screen.wanted) {
        if (!widths.includes(width)) continue;
        if ((await section.locator(`${selector} >> visible=true`).count()) === 0) problems.push(`${screen.id}: no visible element ${selector} at ${width} px`);
      }
      // Layout rules, measured on the rendered page (the frames of the states hide overflow, so tiles are measured too).
      const measured = await section.evaluate((root, viewport) => {
        const found: string[] = [];
        const box = (element: Element) => element.getBoundingClientRect();
        const visible = (element: Element) => (element as HTMLElement).offsetParent !== null;
        // A tile has one action: a variant button, an axis (values, then "Do koszyka" once one is chosen), or "Do koszyka"; never a <select> or a disabled "Do koszyka".
        for (const tile of root.querySelectorAll(".tile")) {
          if (!visible(tile)) continue;
          const name = tile.querySelector(".tile-name")?.textContent ?? "?";
          if (tile.querySelector("select, .btn--tile:disabled")) found.push(`tile "${name}": a select or a disabled button`);
          const actions = ["btn--variant", "tile-axis", "btn--primary"].filter((cls) => tile.querySelector(`.${cls}`));
          if (!tile.classList.contains("tile--out") && actions.length === 0) found.push(`tile "${name}": no action`);
          if (tile.querySelector(".btn--variant") && tile.querySelector(".btn--primary, .tile-axis")) found.push(`tile "${name}": "Wybierz wariant" next to the expanded choice`);
          const tileBox = box(tile);
          for (const inner of tile.querySelectorAll("*:not(.sr-only)")) {
            const innerBox = box(inner);
            if (innerBox.width > 0 && (innerBox.right > tileBox.right + 1 || innerBox.left < tileBox.left - 1)) found.push(`tile "${name}": ${inner.className || inner.tagName} sticks out of the tile`);
          }
        }
        // Touch targets: 44 px for the main controls.
        for (const control of root.querySelectorAll(".menu-btn, .util, .btn--variant, .tile .opt, .tile .btn--primary, .btn--pager, .ftr-links a")) {
          if (!visible(control)) continue;
          // Footer links on desktop only need the WCAG 2.5.8 minimum of 24 px (a pointer, a compact block).
          const minimum = control.matches(".ftr-links a") && viewport >= 1024 ? 24 : 43.5;
          if (box(control).height < minimum) found.push(`target below 44 px: ${control.className || control.tagName} ${control.textContent?.trim().slice(0, 20)} (${Math.round(box(control).height)} px)`);
        }
        // Header on a phone: two rows (wordmark, menu, cart and account; below them the search at full width).
        const header = root.querySelector(".hdr");
        if (header && visible(header) && viewport < 1024) {
          const brand = box(header.querySelector(".brand")!);
          const menu = box(header.querySelector(".menu-btn")!);
          const utils = box(header.querySelector(".utils")!);
          const search = box(header.querySelector(".search")!);
          const centre = (b: DOMRect) => b.top + b.height / 2;
          if (Math.abs(centre(brand) - centre(menu)) > 4 || Math.abs(centre(menu) - centre(utils)) > 4) found.push("header: wordmark, menu and cart are not in one row");
          if (search.top < Math.max(brand.bottom, menu.bottom, utils.bottom) - 1) found.push("header: the search is not under the first row");
          if (search.width < viewport - 2 * 16 - 2) found.push(`header: the search is narrower than the content (${Math.round(search.width)} px)`);
          if (box(header).height > 140) found.push(`header: taller than two rows (${Math.round(box(header).height)} px)`);
        }
        // Footer: compact, one discreet bottom row, links in two columns (right side on desktop).
        const footer = root.querySelector(".ftr");
        if (footer && visible(footer)) {
          const bottom = footer.querySelector(".ftr-bottom") as HTMLElement;
          const lineHeight = parseFloat(getComputedStyle(bottom).lineHeight);
          const paddingTop = parseFloat(getComputedStyle(bottom).paddingTop);
          if (box(bottom).height - paddingTop - 1 > lineHeight * 1.2) found.push("footer: the copyright row is not one line");
          const lefts = new Set([...footer.querySelectorAll(".ftr-links a")].map((link) => Math.round(box(link).left)));
          if (lefts.size !== 2) found.push(`footer: links are in ${lefts.size} columns, expected 2`);
          const brandBox = box(footer.querySelector(".brand")!);
          const linksBox = box(footer.querySelector(".ftr-links")!);
          if (viewport >= 1024 && !(brandBox.right < linksBox.left && linksBox.right > viewport - 100)) found.push("footer: logo is not on the left and the links on the right");
          if (viewport < 1024 && !(brandBox.bottom <= linksBox.top + 1)) found.push("footer: logo is not above the links");
          if (viewport < 1024 && box(footer).height > 400) found.push(`footer: too tall (${Math.round(box(footer).height)} px)`);
          if (viewport >= 1024 && box(footer).height > 260) found.push(`footer: too tall (${Math.round(box(footer).height)} px)`);
        }
        return found;
      }, width);
      for (const issue of measured) problems.push(`${screen.id}: ${issue}`);
      await section.screenshot({ path: `${SHOTS}/kierunek-${screen.id}-${width}.png`, animations: "disabled", scale: "css" });
    }

    expect(problems, problems.join("\n")).toEqual([]);
  });
}
```

## Jak policzono kontrasty

Stosunki kontrastu w tabelach policzono skryptem w `python3` (OKLCH → liniowe sRGB wg Björna Ottossona → kodowanie sRGB zaokrąglone do 8 bitów → luminancja względna WCAG 2.x → stosunek `(L1 + 0,05) / (L2 + 0,05)`). Wartości `oklch(...)` leżą w gamucie sRGB (przycinanie nie zmienia wyniku). Tekst na zdjęciu hero: najjaśniejszy możliwy piksel pod nakładką to biel, nakładka kryje w 72% w całym obszarze tekstu (gradient zaczyna się blednąć dopiero za nim), więc podany stosunek to dolna granica.

```python
"""OKLCH -> sRGB -> WCAG 2.x relative luminance and contrast ratio."""
import math, re

def oklch_to_srgb(L, C, h):
    a = C * math.cos(math.radians(h)); b = C * math.sin(math.radians(h))
    l_ = L + 0.3963377774 * a + 0.2158037573 * b
    m_ = L - 0.1055613458 * a - 0.0638541728 * b
    s_ = L - 0.0894841775 * a - 1.2914855480 * b
    l, m, s = l_**3, m_**3, s_**3
    r = 4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s
    g = -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s
    bb = -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s
    def enc(x):
        x = min(1.0, max(0.0, x))
        return 12.92 * x if x <= 0.0031308 else 1.055 * x ** (1 / 2.4) - 0.055
    return enc(r), enc(g), enc(bb), (r, g, bb)

def parse(s):
    m = re.fullmatch(r"oklch\(\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\)", s.strip())
    if not m: raise ValueError(s)
    return tuple(float(x) for x in m.groups())

def in_gamut(s):
    return all(-0.0005 <= v <= 1.0005 for v in oklch_to_srgb(*parse(s))[3])

def rgb(s):
    r, g, b, _ = oklch_to_srgb(*parse(s)); return (r, g, b)

def lin(c):
    return c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4

def luminance_rgb(rgbt):
    r, g, b = (lin(round(c * 255) / 255) for c in rgbt)
    return 0.2126 * r + 0.7152 * g + 0.0722 * b

def luminance(s): return luminance_rgb(rgb(s))

def blend(fg_rgb, bg_rgb, alpha):
    return tuple(fg_rgb[i] * alpha + bg_rgb[i] * (1 - alpha) for i in range(3))

def ratio_l(l1, l2):
    hi, lo = max(l1, l2), min(l1, l2)
    return (hi + 0.05) / (lo + 0.05)

def ratio(a, b): return ratio_l(luminance(a), luminance(b))

def hexof(s):
    r, g, b, _ = oklch_to_srgb(*parse(s)); return "#%02x%02x%02x" % (round(r*255), round(g*255), round(b*255))
```
