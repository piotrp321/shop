# Makiety kierunków wizualnych sklepu Worf

Trzy kierunki wizualne sklepu (VISUAL-DESIGN-1): `kierunek-a.html`, `kierunek-b.html`, `kierunek-c.html`. Każdy plik to samodzielny, statyczny dokument (CSS w pliku, bez JavaScriptu, bez żądań sieciowych) z trzema ekranami: stroną główną, listingiem kategorii i kartą produktu. Ekrany działają w 1440 i 375 px (responsywny CSS, bez poziomego przewijania) i mają podpisy; stany interfejsu są pokazane w podpisanych panelach „Stan: …” na dole ekranu. Zrzuty pełnej wysokości ekranów są obok plików HTML (`kierunek-{a,b,c}-{glowna,listing,produkt}-{1440,375}.png`, 18 plików).

Wyboru kierunku dokonuje właściciel (VISUAL-DESIGN-4). Makiety są ilustracją; źródłem prawdy po wyborze będzie specyfikacja `docs/features/visual-design/spec.md` i `tokens.css` sklepu.

## Jak obejrzeć makiety

- Otwórz wybrany plik `kierunek-*.html` w przeglądarce (z dysku, `file://`); fonty i zdjęcia są pobierane ze ścieżek względnych (`fonts/`, `../../../../apps/api/fixtures/images/`), więc plik trzeba otwierać z jego miejsca w repozytorium. Chromium otwiera makiety bez dodatkowych ustawień.
- Wersja 375 px: narzędzia deweloperskie przeglądarki, tryb responsywny (np. Chrome: `Ctrl/Cmd + Shift + M`, szerokość 375). Poniżej 1024 px działa układ telefonu, od 1024 px układ desktopu.
- Makiety nie używają `position: sticky` ani żadnego innego położenia zależnego od przewijania, więc zrzut pełnej wysokości pokazuje układ tak, jak widać go po przewinięciu strony od góry do dołu (filtry i dane produktu zaczynają się na wysokości galerii i siatki).
- Każdy ekran to osobna sekcja (`#glowna`, `#listing`, `#produkt`) z paskiem podpisu u góry; panele „Stan: …” są pod stroną.

## Zawartość i odstępstwa od danych demo

Nazwy produktów, kategorii, ceny i warianty pochodzą z `apps/api/fixtures` (`catalog.yaml`, `prices.yaml`, `stock.yaml`), teksty interfejsu z kodu `apps/store/src` („Do koszyka”, „Niedostępny”, „Ostatnie sztuki”, „Dostępny”, „Wybierz wariant”, „Sortuj”, „Nazwa A–Z”, „Cena rosnąco”, „Cena malejąco”, „Aktywne filtry”, „Wyczyść filtry”, „Strona 1 z 2”, „Następna”, „Filtry”, „Kategorie”, „Cena (zł)”, „Cena od”, „Cena do”, „Tylko dostępne”, „Wszystko z działu”, „Szukaj produktów”, „Koszyk”, „Zaloguj się”, „Menu”, „Wszystkie produkty”). Ceny są sformatowane jak w sklepie (`pl-PL`, np. `2999,00 zł`). Zdjęcia produktów i kafelków mają proporcje 1:1 (`aspect-ratio: 1; object-fit: cover` imituje kadr z rozmiarów `listing` i `product` z API); zdjęcia hero i działów mają własne kadry.

- **Listing** to kategoria „Sporty wodne” z aktywnymi filtrami „Cena od 1000 zł” i „Cena do 4000 zł” (10 produktów), sortowanie „Cena malejąco”. „Niedostępny”: Kajak turystyczny Vilmar Rzeka 2 (stan 0). „Ostatnie sztuki”: Kajak turystyczny Dravik Rzeka 1 po wybraniu wariantu „zielony” (stan 3; poziom produktu to najlepszy poziom z wariantów, więc „Ostatnie sztuki” widać po wyborze wariantu, jak w sklepie). Kajak Dravik Rzeka 2 ma wariant „pomarańczowy” niedostępny (na liście wyboru: „pomarańczowy – niedostępny”).
- **Karta produktu** to Narty skiturowe Stavro Grań 95: wartość „pomarańczowe” jest niedostępna (stan 0), „niebieskie” i długość „172 cm” wybrane, przycisk „Do koszyka” ma pokazany fokus klawiatury.
- **Odstępstwa od danych demo** (dla pokazania układu): (1) produkty demo mają jedno zdjęcie, a makieta pokazuje galerię dwóch zdjęć z tego samego szablonu (miniatury, licznik „1 / 2”, powiększenie); (2) w danych demo żaden produkt kategorii „Sporty wodne” nie ma zakresu cen, więc cenę „od” (`od 2999,00 zł`) pokazuje tylko kafelek Deski SUP Dravik Wyścig 12 (w danych demo zakres mają przewodniki, mapy i dania liofilizowane); (3) kategoria ma 10 produktów, czyli jedną stronę przy 24 produktach na stronę, a makieta pokazuje paginację „Strona 1 z 2” oraz 8 lub 9 kafelków, żeby ją zilustrować; (4) lista jest posortowana „Cena malejąco”, a produkt bez ceny („Niedostępny”) stoi na drugim miejscu, żeby widać go było na pierwszym ekranie; (5) tytuły i teksty hero oraz podpisy ekranów to propozycje na potrzeby makiety.
- Zdjęcia w kategorii „Sporty wodne” w danych demo powtarzają się (dwa zdjęcia na wszystkie produkty szablonu), więc kafelki też je powtarzają.

## Kierunek A „Granit”

### Idea

„Granit” to jasna, ostra i przestrzenna strona: wysokie zdjęcie hero z tekstem prosto na zdjęciu, jedna geometryczna rodzina fontów, kanciaste (4 px) elementy i pomarańczowy akcent, który pojawia się tylko tam, gdzie klient ma kliknąć. Od B i C różni się jednym wierszem nagłówka z panelem mega menu na całą szerokość, równym rzędem kafelków działów, kafelkami bez ramek i filtrami w lewej kolumnie.

### Paleta (OKLCH)

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

### Kontrasty (WCAG 2.2 AA)

Stosunki policzone skryptem (OKLCH → sRGB → luminancja względna WCAG 2.x, zaokrąglone do 2 miejsc; pełny skrypt w sekcji „Jak policzono kontrasty”). Progi: tekst 4,5:1, duży tekst i elementy interfejsu 3:1. Wszystkie użyte pary w tym kierunku spełniają próg. Kolor fokusu zależy od tła, na którym leży pierścień: `accent-deep` na jasnym tle i na białych powierzchniach, `accent` na ciemnych (stopka, powiększenie zdjęcia), `on-dark` na zdjęciu hero pod nakładką.

| Tekst lub element | Tło | Gdzie | Rodzaj | Stosunek | Wymagany | Wynik |
|---|---|---|---|---|---|---|
| `ink` | `bg` | Tekst podstawowy na tle strony | tekst | 16,56:1 | 4,50:1 | spełnia |
| `ink` | `surface` | Tekst na białych polach i panelu mega menu | tekst | 17,77:1 | 4,50:1 | spełnia |
| `muted` | `bg` | Tekst drugorzędny (okruszki, liczba produktów, etykiety pól) | tekst | 6,66:1 | 4,50:1 | spełnia |
| `muted` | `surface` | Tekst drugorzędny na białym | tekst | 7,15:1 | 4,50:1 | spełnia |
| `on-accent` | `accent` | Przycisk główny „Do koszyka” i link hero | tekst | 7,52:1 | 4,50:1 | spełnia |
| `on-dark` | `dark` | Tekst stopki, pasek podpisu, powiększenie zdjęcia | tekst | 16,73:1 | 4,50:1 | spełnia |
| `on-dark-muted` | `dark` | Tekst drugorzędny w stopce | tekst | 9,42:1 | 4,50:1 | spełnia |
| `ok` | `bg` | Etykieta „Dostępny” | tekst | 6,91:1 | 4,50:1 | spełnia |
| `ok` | `surface` | Etykieta „Dostępny” na białym | tekst | 7,41:1 | 4,50:1 | spełnia |
| `low` | `bg` | Etykieta „Ostatnie sztuki” | tekst | 6,04:1 | 4,50:1 | spełnia |
| `low` | `surface` | Etykieta „Ostatnie sztuki” na białym | tekst | 6,48:1 | 4,50:1 | spełnia |
| `accent-deep` | `bg` | Link w tekście i wyróżniony tekst | tekst | 5,87:1 | 4,50:1 | spełnia |
| `accent-deep` | `surface` | Link „Wszystko z działu” w panelu mega menu | tekst | 6,30:1 | 4,50:1 | spełnia |
| `ink` | `bg` | Obrys aktualnej miniatury | element interfejsu | 16,56:1 | 3,00:1 | spełnia |
| `line` | `bg` | Obramowanie pól, selectów, przycisków i miniatur | element interfejsu | 3,97:1 | 3,00:1 | spełnia |
| `line` | `surface` | Obramowanie pól na białym | element interfejsu | 4,26:1 | 3,00:1 | spełnia |
| `accent-deep` | `bg` | Pierścień fokusu na tle strony | element interfejsu | 5,87:1 | 3,00:1 | spełnia |
| `accent-deep` | `surface` | Pierścień fokusu na białym | element interfejsu | 6,30:1 | 3,00:1 | spełnia |
| `accent` | `dark` | Pierścień fokusu na ciemnym tle (stopka, powiększenie zdjęcia) | element interfejsu | 7,79:1 | 3,00:1 | spełnia |
| `ink` | `accent` | Ramka fokusu na przycisku głównym | element interfejsu | 7,38:1 | 3,00:1 | spełnia |
| `on-dark` | `overlay` w 72% na zdjęciu (najjaśniejsze możliwe tło: biel `#ffffff`, po nakładce `#4e5053`) | Tekst hero na zdjęciu (nagłówek, akapit) | tekst | 7,21:1 | 4,50:1 | spełnia |
| `on-dark` | to samo (`overlay` w 72% nad bielą, `#4e5053`) | Pierścień fokusu przycisku hero na zdjęciu | element interfejsu | 7,21:1 | 3,00:1 | spełnia |
| `on-dark` | to samo, nagłówek hero 44 px i więcej (duży tekst) | Nagłówek hero | duży tekst | 7,21:1 | 3,00:1 | spełnia |

### Fonty

- **Outfit** (geometryczny sans, jedna rodzina, plik zmienny `wght` 100–900): nagłówki 600, tekst 400/500, znak słowny „Worf” 700.
- Pliki: `fonts/outfit-latin-wght.woff2`, `fonts/outfit-latin-ext-wght.woff2`. Licencja: `fonts/OFL-Outfit.txt`.
- Użyte wagi (sprawdza je skrypt zrzutów): 400, 500, 600, 700.

### Skala typografii

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
| Znak słowny „Worf” | 28 px (stopka 48 px) | 28 px (stopka 96 px) | 700, −0,045 em |

### Promienie, cienie, odstępy

- Promień: 4 px wszędzie (zdjęcia, pola, przyciski, chipsy); przyciski galerii i wybór wariantu też 4 px (przyciski strzałek galerii okrągłe). Cieni brak.
- Odstępy: siatka 4 px (4, 8, 12, 16, 20, 24, 32, 48, 72, 112). Margines boczny 16 px (telefon) i 48 px (desktop), maksymalna szerokość treści 1440 px.
- Siatka produktów: 2 kolumny z odstępem 14 px (telefon), 4 kolumny z odstępem 20 px w poziomie i 48 px w pionie (desktop). Filtry: kolumna 260 px, odstęp 56 px.
- Cele dotyku: przyciski, pola i wybór wariantu 44 px (przycisk „Do koszyka” na karcie 56 px), miniatury galerii 72 px (telefon) i 84 px (desktop), chipsy aktywnych filtrów 36 px.

## Kierunek B „Szlak”

### Idea

„Szlak” to gęsta, techniczna strona na grafitowym tle: dwurzędowy ciemny nagłówek, krótkie zdjęcie hero z tekstem w pasie pod nim, mozaika działów z limonkowymi etykietami i białe karty z ramką. Od A i C różni się ciemnym nagłówkiem w dwóch wierszach z panelem w kolumnach pod przyciskiem, filtrami rozwijanymi nad siatką, galerią z miniaturami pod zdjęciem w proporcji 1:1 i kanciastą typografią z wersalikami.

### Paleta (OKLCH)

Wartości są w pliku HTML jako zmienne CSS `:root` (te same ciągi `oklch(...)`); kolumna sRGB to wartość po przeliczeniu, służy tylko do podglądu.

| Token (zmienna CSS) | OKLCH | sRGB | Zastosowanie |
|---|---|---|---|
| `--bg` | `oklch(0.955 0.006 240)` | `#edf1f4` | tło strony (chłodna złamana biel) |
| `--surface` | `oklch(1 0 0)` | `#ffffff` | karty produktów, panel filtrów, panel danych produktu |
| `--ink` | `oklch(0.2 0.012 250)` | `#12171b` | tekst podstawowy i obramowania (grafit) |
| `--muted` | `oklch(0.42 0.015 250)` | `#474e55` | tekst drugorzędny |
| `--line` | `oklch(0.2 0.012 250)` | `#12171b` | obramowania pól, kart i przycisków (to samo co ink) |
| `--hair` | `oklch(0.86 0.008 240)` | `#ccd2d6` | separatory (dekoracyjne) |
| `--accent` | `oklch(0.9 0.2 125)` | `#c0f447` | jedyny akcent (limonka): przycisk główny, etykiety działów, plakietki, aktywny dział na ciemnym |
| `--on-accent` | `oklch(0.2 0.012 250)` | `#12171b` | tekst na akcencie |
| `--dark` | `oklch(0.2 0.012 250)` | `#12171b` | nagłówek, pasek hero, stopka, wybrany wariant, powiększenie zdjęcia |
| `--on-dark` | `oklch(0.97 0.004 240)` | `#f3f5f7` | tekst na ciemnym |
| `--on-dark-muted` | `oklch(0.8 0.01 250)` | `#b9bec4` | tekst drugorzędny na ciemnym |
| `--line-dark` | `oklch(0.55 0.015 250)` | `#6b727a` | obramowania na ciemnym (pole wyszukiwarki, przycisk Menu, separatory) |
| `--ok` | `oklch(0.42 0.11 150)` | `#0b5d2a` | etykieta „Dostępny” |
| `--low` | `oklch(0.48 0.145 35)` | `#9f351b` | etykieta „Ostatnie sztuki” |
| `--overlay` | `oklch(0.15 0.012 250)` | `#080c10` | nie używana w B (tekst hero leży na pełnym bloku) |

### Kontrasty (WCAG 2.2 AA)

Stosunki policzone skryptem (OKLCH → sRGB → luminancja względna WCAG 2.x, zaokrąglone do 2 miejsc; pełny skrypt w sekcji „Jak policzono kontrasty”). Progi: tekst 4,5:1, duży tekst i elementy interfejsu 3:1. Wszystkie użyte pary w tym kierunku spełniają próg. Kolor fokusu zależy od tła, na którym leży pierścień: `ink` na jasnym tle, na białych kartach i na białych panelach w ciemnym nagłówku (mega menu, menu na telefonie), `accent` na ciemnych (nagłówek, pas hero, stopka, powiększenie zdjęcia).

| Tekst lub element | Tło | Gdzie | Rodzaj | Stosunek | Wymagany | Wynik |
|---|---|---|---|---|---|---|
| `ink` | `bg` | Tekst podstawowy na tle strony | tekst | 15,88:1 | 4,50:1 | spełnia |
| `ink` | `surface` | Tekst w kartach i panelu filtrów | tekst | 18,04:1 | 4,50:1 | spełnia |
| `muted` | `bg` | Tekst drugorzędny | tekst | 7,43:1 | 4,50:1 | spełnia |
| `muted` | `surface` | Tekst drugorzędny w kartach | tekst | 8,44:1 | 4,50:1 | spełnia |
| `on-accent` | `accent` | Przycisk główny i etykiety na limonce | tekst | 13,98:1 | 4,50:1 | spełnia |
| `on-dark` | `dark` | Nagłówek, pasek hero, stopka | tekst | 16,51:1 | 4,50:1 | spełnia |
| `on-dark-muted` | `dark` | Tekst drugorzędny na ciemnym | tekst | 9,64:1 | 4,50:1 | spełnia |
| `accent` | `dark` | Limonka jako tekst i link na ciemnym | tekst | 13,98:1 | 4,50:1 | spełnia |
| `ok` | `bg` | Etykieta „Dostępny” | tekst | 7,07:1 | 4,50:1 | spełnia |
| `ok` | `surface` | Etykieta „Dostępny” w karcie | tekst | 8,04:1 | 4,50:1 | spełnia |
| `low` | `bg` | Etykieta „Ostatnie sztuki” | tekst | 6,16:1 | 4,50:1 | spełnia |
| `low` | `surface` | Etykieta „Ostatnie sztuki” w karcie | tekst | 7,00:1 | 4,50:1 | spełnia |
| `line` | `bg` | Obramowanie pól, kart i przycisków | element interfejsu | 15,88:1 | 3,00:1 | spełnia |
| `line` | `surface` | Obramowanie pól w kartach | element interfejsu | 18,04:1 | 3,00:1 | spełnia |
| `line-dark` | `dark` | Obramowanie pola wyszukiwarki w ciemnym nagłówku | element interfejsu | 3,71:1 | 3,00:1 | spełnia |
| `ink` | `bg` | Pierścień fokusu na jasnym tle | element interfejsu | 15,88:1 | 3,00:1 | spełnia |
| `ink` | `surface` | Pierścień fokusu na białej karcie, w panelu mega menu i w menu na telefonie (białe pola w ciemnym nagłówku) | element interfejsu | 18,04:1 | 3,00:1 | spełnia |
| `accent` | `dark` | Pierścień fokusu na ciemnym tle (nagłówek, pas hero z przyciskiem, stopka, powiększenie zdjęcia) | element interfejsu | 13,98:1 | 3,00:1 | spełnia |
| `ink` | `accent` | Ramka fokusu na przycisku głównym | element interfejsu | 13,98:1 | 3,00:1 | spełnia |

### Fonty

- **Archivo** (grotesk, jedna rodzina, plik zmienny `wght` 100–900): nagłówki 800 wersalikami, tekst 400/500/600, etykiety 700/800.
- Pliki: `fonts/archivo-latin-wght.woff2`, `fonts/archivo-latin-ext-wght.woff2`. Licencja: `fonts/OFL-Archivo.txt`.
- Użyte wagi (sprawdza je skrypt zrzutów): 400, 500, 600, 700, 800.

### Skala typografii

| Element | Telefon (375 px) | Desktop (1440 px) | Waga, ustawienia |
|---|---|---|---|
| Nagłówek hero (`h1`) | 36 px / 1,0 | 56 px / 1,0 | 800, wersaliki |
| Akapit hero | 16 px | 17 px | 400 |
| `h1` listingu | 36 px / 1,05 | 52 px / 1,05 | 800, wersaliki, kreska 4 px pod nagłówkiem |
| Nazwa produktu na karcie (`h1`) | 30 px | 44 px | 800, wersaliki |
| Cena na karcie | 26 px | 32 px | 800, na limonkowej plakietce |
| `h2` sekcji | 24 px | 32 px | 800, wersaliki |
| Etykieta działu (mozaika) | 13 px (24 px duży kafelek na desktopie) | 16 px | 800, wersaliki, 0,04 em |
| Nazwa produktu na kafelku | 15 px | 17 px | 700 |
| Cena na kafelku | 18 px | 18 px | 800 |
| Etykiety grup filtrów i wariantów, przyciski | 13 px | 13 px | 800, wersaliki, 0,05 em |
| Tekst, pola | 16 / 15 px | 16 / 15 px | 400 |
| Znak słowny „Worf” | 24 px (stopka 36 px) | 30 px (stopka 56 px) | 800, wersaliki, 0,08 em |

### Promienie, cienie, odstępy

- Promień: 0 (wszystkie elementy kanciaste, także pola, przyciski i miniatury), przyciski strzałek galerii kwadratowe. Cieni brak: separacja obramowaniem 1–2 px w kolorze `ink` i kreską 4 px.
- Odstępy: siatka 4 px (4, 8, 12, 16, 20, 24, 32, 40, 56, 88). Margines boczny 16 px (telefon) i 32 px (desktop).
- Siatka produktów: 2 kolumny z odstępem 12 px (telefon), 3 kolumny z odstępem 16 px (desktop); karta ma wewnętrzny odstęp 12 px (telefon) i 16 px. Mozaika działów: 4 kolumny × 3 wiersze po 230 px, odstęp 12 px (telefon: 2 kolumny po 150 px, odstęp 8 px).
- Cele dotyku: przyciski, pola i wybór wariantu 44 px (przycisk „Do koszyka” na karcie 56 px), miniatury 72 px (telefon) i 96 px (desktop), chipsy 36 px.

## Kierunek C „Polana”

### Idea

„Polana” to spokojna, redakcyjna strona w ciepłej bieli: szeryfowe nagłówki, duże zaokrąglenia i dużo oddechu, hero z blokiem tekstu na krawędzi zdjęcia, poziomy pas działów i kafelki produktów z wyśrodkowanym tekstem. Od A i B różni się nagłówkiem z wyśrodkowanym znakiem i panelem ze zdjęciem, hero z blokiem na krawędzi zdjęcia, pasem działów przewijanym poziomo, pastylkowymi kontrolkami i szeryfowymi nagłówkami.

### Paleta (OKLCH)

Wartości są w pliku HTML jako zmienne CSS `:root` (te same ciągi `oklch(...)`); kolumna sRGB to wartość po przeliczeniu, służy tylko do podglądu.

| Token (zmienna CSS) | OKLCH | sRGB | Zastosowanie |
|---|---|---|---|
| `--bg` | `oklch(0.955 0.014 85)` | `#f4f0e6` | tło strony (ciepła złamana biel) |
| `--surface` | `oklch(0.985 0.008 85)` | `#fdfaf4` | blok hero, karta filtrów, panel mega menu, pola |
| `--ink` | `oklch(0.26 0.025 160)` | `#192820` | tekst podstawowy (zieleń-czerń) |
| `--muted` | `oklch(0.45 0.025 150)` | `#4c594e` | tekst drugorzędny |
| `--line` | `oklch(0.56 0.03 150)` | `#687a6b` | obramowania pól, selectów, przycisków, miniatur |
| `--hair` | `oklch(0.88 0.015 85)` | `#dcd7cd` | separatory (dekoracyjne) |
| `--accent` | `oklch(0.5 0.14 38)` | `#a33f1e` | jedyny akcent (terakota): przycisk główny, plakietka koszyka, linki, fokus |
| `--on-accent` | `oklch(0.985 0.008 85)` | `#fdfaf4` | tekst na akcencie |
| `--dark` | `oklch(0.24 0.03 160)` | `#12241a` | stopka, pasek podpisu, wybrany wariant, powiększenie zdjęcia |
| `--on-dark` | `oklch(0.96 0.012 85)` | `#f5f1e9` | tekst na ciemnym |
| `--on-dark-muted` | `oklch(0.8 0.02 120)` | `#bcc0b2` | tekst drugorzędny na ciemnym |
| `--ok` | `oklch(0.42 0.1 155)` | `#095c34` | etykieta „Dostępny” |
| `--low` | `oklch(0.48 0.15 35)` | `#a03317` | etykieta „Ostatnie sztuki” |
| `--overlay` | `oklch(0.18 0.03 160)` | `#05160d` | nie używana w C (tekst hero leży na pełnym bloku) |

### Kontrasty (WCAG 2.2 AA)

Stosunki policzone skryptem (OKLCH → sRGB → luminancja względna WCAG 2.x, zaokrąglone do 2 miejsc; pełny skrypt w sekcji „Jak policzono kontrasty”). Progi: tekst 4,5:1, duży tekst i elementy interfejsu 3:1. Wszystkie użyte pary w tym kierunku spełniają próg. Kolor fokusu zależy od tła, na którym leży pierścień: `accent` na jasnym tle i na powierzchniach, `on-dark` na ciemnych (stopka, powiększenie zdjęcia); pas działów ma pierścień wewnątrz własnego marginesu, na tle strony.

| Tekst lub element | Tło | Gdzie | Rodzaj | Stosunek | Wymagany | Wynik |
|---|---|---|---|---|---|---|
| `ink` | `bg` | Tekst podstawowy na tle strony | tekst | 13,51:1 | 4,50:1 | spełnia |
| `ink` | `surface` | Tekst na jaśniejszych powierzchniach (blok hero, panel) | tekst | 14,76:1 | 4,50:1 | spełnia |
| `muted` | `bg` | Tekst drugorzędny | tekst | 6,48:1 | 4,50:1 | spełnia |
| `muted` | `surface` | Tekst drugorzędny na powierzchni | tekst | 7,08:1 | 4,50:1 | spełnia |
| `on-accent` | `accent` | Przycisk główny „Do koszyka” i link hero | tekst | 6,13:1 | 4,50:1 | spełnia |
| `accent` | `bg` | Linki w tekście i wyróżniony tekst | tekst | 5,61:1 | 4,50:1 | spełnia |
| `accent` | `surface` | Linki w bloku hero | tekst | 6,13:1 | 4,50:1 | spełnia |
| `on-dark` | `dark` | Tekst stopki, pasek podpisu, powiększenie zdjęcia | tekst | 14,42:1 | 4,50:1 | spełnia |
| `on-dark-muted` | `dark` | Tekst drugorzędny w stopce | tekst | 8,76:1 | 4,50:1 | spełnia |
| `ok` | `bg` | Etykieta „Dostępny” | tekst | 7,12:1 | 4,50:1 | spełnia |
| `ok` | `surface` | Etykieta „Dostępny” na powierzchni | tekst | 7,78:1 | 4,50:1 | spełnia |
| `low` | `bg` | Etykieta „Ostatnie sztuki” | tekst | 6,19:1 | 4,50:1 | spełnia |
| `low` | `surface` | Etykieta „Ostatnie sztuki” na powierzchni | tekst | 6,76:1 | 4,50:1 | spełnia |
| `line` | `bg` | Obramowanie pól, selectów, przycisków i miniatur | element interfejsu | 4,02:1 | 3,00:1 | spełnia |
| `line` | `surface` | Obramowanie pól na powierzchni | element interfejsu | 4,40:1 | 3,00:1 | spełnia |
| `accent` | `bg` | Pierścień fokusu na tle strony | element interfejsu | 5,61:1 | 3,00:1 | spełnia |
| `accent` | `surface` | Pierścień fokusu na powierzchni | element interfejsu | 6,13:1 | 3,00:1 | spełnia |
| `on-dark` | `dark` | Pierścień fokusu na ciemnym tle (stopka, powiększenie zdjęcia) | element interfejsu | 14,42:1 | 3,00:1 | spełnia |

### Fonty

- **Newsreader** (szeryfowy, plik zmienny `wght` 200–800): nagłówki, nazwy produktów i działów, znak słowny „Worf” (waga 500/600).
- **Inter** (sans, plik zmienny `wght` 100–900): tekst, ceny, przyciski, pola i etykiety (400/500/600/700).
- Pliki: `fonts/newsreader-latin-wght.woff2`, `fonts/newsreader-latin-ext-wght.woff2`, `fonts/inter-latin-wght.woff2`, `fonts/inter-latin-ext-wght.woff2`. Licencje: `fonts/OFL-Newsreader.txt`, `fonts/OFL-Inter.txt`.
- Użyte wagi (sprawdza je skrypt zrzutów): Newsreader 400, 500, 600; Inter 400, 500, 600, 700.

### Skala typografii

| Element | Telefon (375 px) | Desktop (1440 px) | Rodzina, waga |
|---|---|---|---|
| Nagłówek hero (`h1`) | 44 px / 1,02 | 72 px / 1,02 | Newsreader 500, −0,025 em |
| Akapit hero | 17 px / 1,55 | 19 px / 1,55 | Inter 400 |
| `h1` listingu | 44 px | 64 px | Newsreader 500 |
| Nazwa produktu na karcie (`h1`) | 40 px | 64 px | Newsreader 500 |
| Cena na karcie | 28 px | 32 px | Inter 600 |
| `h2` sekcji | 36 px | 48 px | Newsreader 500 |
| Nazwa działu w pasie | 24 px | 28 px | Newsreader 500 |
| Nazwa produktu na kafelku | 17 px | 24 px | Newsreader 500 |
| Cena na kafelku | 16 px | 16 px | Inter 600 |
| Nagłówki grup filtrów i wariantów, panel mega menu | 20 px | 20 px (mega menu 24 px) | Newsreader 500 |
| Tekst, pola, przyciski | 16 / 15 px | 16 / 15 px | Inter 400 / 600 |
| Znak słowny „Worf” | 32 px (stopka 48 px) | 40 px (stopka 88 px) | Newsreader 600 |

### Promienie, cienie, odstępy

- Promienie: zdjęcia, bloki i karty 24 px, miniatury 12 px, przyciski, pola, selecty, chipsy i przyciski wariantów pastylkowe (999 px). Cienie miękkie tylko pod blokiem hero i panelem mega menu: `0 20px 40px -24px oklch(0.24 0.03 160 / .4)` (A i B nie mają cieni).
- Odstępy: siatka 8 px (8, 16, 24, 32, 48, 72, 96, 128). Margines boczny 20 px (telefon) i 64 px (desktop).
- Siatka produktów: 2 kolumny z odstępem 16 px w poziomie i 40 px w pionie (telefon), 3 kolumny z odstępem 40 px i 72 px (desktop). Pas działów: kafelki 300 px (68 vw na telefonie), odstęp 28 px. Filtry: karta w kolumnie 260 px, odstęp 64 px.
- Cele dotyku: przyciski, pola i wybór wariantu 44 px (przycisk „Do koszyka” na karcie 60 px), miniatury 72 px (telefon) i 80 px (desktop), chipsy 36 px.

## Porównanie obszarów kompozycji

Warianty obszarów nie zmieniają zachowania stron: hero jest zawsze na całą szerokość, zdjęcia produktów mają proporcje 1:1, filtry na telefonie to przycisk „Filtry” z `aria-expanded` i sekcja w treści strony (nie okno modalne; w makiecie pokazana w panelu stanu).

| Obszar | A „Granit” | B „Szlak” | C „Polana” |
|---|---|---|---|
| Hero (zawsze na całą szerokość) | 680 px wysokości, tekst na zdjęciu pod grafitową nakładką, przy lewej krawędzi u dołu | zdjęcie 440 px wysokości bez tekstu i pas grafitowy pod nim (nagłówek, akapit i przycisk w trzech kolumnach) | zdjęcie 640 px wysokości i jasny blok tekstu zachodzący na jego dolną krawędź (w połowie na zdjęciu) |
| Kafelki działów | równy rząd siedmiu kafelków 3:4, nazwa pod zdjęciem (telefon: dwie kolumny) | mozaika 4 × 3 kafelków różnej wielkości, nazwa w limonkowym bloku w rogu (telefon: dwie kolumny, pierwszy kafelek na całą szerokość) | poziomy pas przewijany z przyciąganiem, kafelki 4:5 z zaokrągleniem, nazwa szeryfem pod zdjęciem; ostatni kafelek ucięty krawędzią |
| Siatka produktów i kafelek | 4 kolumny, odstęp 20 / 48 px, kafelek bez ramki i tła, tekst do lewej, cena pod nazwą | 3 kolumny, odstęp 16 px, karta z ramką na białym tle, zdjęcie przy ramce, cena i dostępność w jednym wierszu | 3 kolumny, odstęp 40 / 72 px, kafelek bez ramki, zdjęcie z zaokrągleniem 24 px, tekst wyśrodkowany |
| Nagłówek i mega menu | jeden jasny wiersz: znak, dział po dziale, wyszukiwarka, koszyk, konto; panel na całą szerokość w czterech kolumnach | dwa wiersze na grafitowym tle (znak, wyszukiwarka, koszyk, konto; pasek działów wersalikami); panel 900 px w czterech kolumnach pod paskiem, wyrównany do lewej | dwa jasne wiersze: wyszukiwarka z lewej, wyśrodkowany znak, koszyk i konto z prawej; wyśrodkowany pasek działów; panel na całą szerokość ze zdjęciem i szeryfowymi nagłówkami kolumn |
| Filtry na desktopie | kolumna po lewej (260 px, bez ramki, grupy rozdzielone liniami) | sekcja rozwijana przyciskiem „Filtry” (aria-expanded) nad siatką, grupy w czterech kolumnach w ramce | kolumna po lewej (260 px) w zaokrąglonej karcie z szeryfowymi nagłówkami grup |
| Karta produktu (galeria, proporcje) | miniatury w pionowym pasie po lewej od zdjęcia, kolumny galerii i danych 7 : 5, licznik na zdjęciu (lewy dół), dane bez ramki | miniatury pod zdjęciem, kolumny 1 : 1, licznik w rogu zdjęcia (limonkowa plakietka), dane w ramce z ceną na limonkowej plakietce | miniatury pod zdjęciem, licznik obok miniatur (poza zdjęciem), węższa galeria i szersza kolumna danych 5 : 6, grupy wariantów rozdzielone liniami |
| Typografia | jedna rodzina geometryczna (Outfit), nagłówki 600 małymi i wielkimi literami | jedna rodzina grotesk (Archivo), nagłówki 800 wersalikami | szeryfowe nagłówki (Newsreader) i sans do tekstu (Inter) |
| Promienie, cienie, akcent | promień 4 px, bez cieni, akcent pomarańczowy | promień 0, bez cieni (obramowania 1–2 px), akcent limonkowy | promienie 12–24 px i pastylki, miękkie cienie pod blokiem hero i panelem, akcent terakota |

Liczba obszarów (z ośmiu), w których para kierunków różni się wariantem:

| Para | Różne obszary | Wspólne |
|---|---|---|
| A i B | 8 | brak |
| A i C | 7 | Filtry na desktopie |
| B i C | 8 | brak |


## Fonty i licencje

Wszystkie fonty są na licencji SIL Open Font License 1.1 (OFL), z polskimi znakami (podzbiory `latin` i `latin-ext`: „ąćęłńóśźż” w obu plikach razem), w plikach WOFF2 w `fonts/`. Pliki pochodzą z pakietów Fontsource (`@fontsource-variable/<rodzina>` 5.3.0, pliki `<rodzina>-<podzbiór>-wght-normal.woff2`), zmienne osie `wght`. Sklep dostanie te same pliki przez `next/font/local`. Makiety nie ładują niczego z sieci. Każdy kierunek ma w `@font-face` oba podzbiory z `unicode-range`.

| Rodzina | Kierunek | Pliki | Licencja i prawa autorskie |
|---|---|---|---|
| Outfit | A | `outfit-latin-wght.woff2` (32 KB), `outfit-latin-ext-wght.woff2` (15 KB) | `OFL-Outfit.txt`: Copyright 2021 The Outfit Project Authors |
| Archivo | B | `archivo-latin-wght.woff2` (35 KB), `archivo-latin-ext-wght.woff2` (33 KB) | `OFL-Archivo.txt`: Copyright 2020 The Archivo Project Authors |
| Newsreader | C | `newsreader-latin-wght.woff2` (58 KB), `newsreader-latin-ext-wght.woff2` (36 KB) | `OFL-Newsreader.txt`: Copyright 2020 The Newsreader Project Authors |
| Inter | C | `inter-latin-wght.woff2` (48 KB), `inter-latin-ext-wght.woff2` (85 KB) | `OFL-Inter.txt`: Copyright 2016 The Inter Project Authors |

## Zrzuty ekranu

Zrzuty robi Playwright przez `make e2e-agent` z katalogiem roboczym poza repozytorium. Skrypt otwiera każdą makietę z `file://` w dwóch szerokościach (1440 i 375 px, `deviceScaleFactor` 1) i zapisuje zrzut każdej z trzech sekcji w pełnej wysokości (`locator(...).screenshot`). Test kończy się błędem, gdy wystąpi którekolwiek z poniższych:

- błąd konsoli (`console` typu `error`), `pageerror`, `requestfailed` albo odpowiedź ≥ 400;
- każde żądanie poza `file://` (makieta niczego nie pobiera z sieci);
- fonty kierunku: skrypt czeka na `document.fonts.ready`, ładuje każdą użytą rodzinę i wagę z tekstem „ąćęłńóśźż Worf”, a potem wymaga `document.fonts.check(...)` dla każdej z nich, wczytanej (`status === "loaded"`) rodziny w `document.fonts`, braku fontów w stanie `error` i tego, że każda rodzina i waga faktycznie użyta na stronie jest na liście sprawdzanych (zrzut nie może pokazać fontu zastępczego);
- niezaładowane zdjęcie (`naturalWidth` równe 0);
- poziome przewijanie (`document.documentElement.scrollWidth` większe od szerokości okna);
- brak widocznego elementu stanu w danej szerokości (mega menu tylko przy 1440 px, menu działów i panel filtrów na telefonie tylko przy 375 px; aktywne filtry, kafelek „Niedostępny”, „Ostatnie sztuki”, fokus, powiększenie zdjęcia, miniatury i licznik przy obu), a także brak kafelka z wartością wariantu niedostępną.

Skrypt zapisuje się jako `$AGENT_DIR/tests/mockups.spec.ts` (katalog `tests` czyta `playwright.agent.config.ts`). Zrzuty trafiają do `$AGENT_DIR/shots/`, stamtąd do katalogu makiet. Konfiguracja agenta uruchamia projekt `desktop`; szerokość okna skrypt ustawia sam.

Komendy (z katalogu głównego repozytorium; `AGENT_DIR` musi istnieć i być ścieżką bezwzględną):

```sh
AGENT_DIR="$HOME/.local/state/worf-mockups"   # poza repozytorium
mkdir -p "$AGENT_DIR/tests" "$AGENT_DIR/shots" "$AGENT_DIR/docs/features/visual-design" "$AGENT_DIR/apps/api/fixtures"
rm -rf "$AGENT_DIR/docs/features/visual-design/mockups" "$AGENT_DIR/apps/api/fixtures/images"
cp -R docs/features/visual-design/mockups "$AGENT_DIR/docs/features/visual-design/mockups"
cp -R apps/api/fixtures/images "$AGENT_DIR/apps/api/fixtures/images"
# zapisz skrypt z bloku poniżej jako "$AGENT_DIR/tests/mockups.spec.ts", potem:
make e2e-agent AGENT_DIR="$AGENT_DIR" E2E_ARGS="--project=desktop mockups.spec.ts"
cp "$AGENT_DIR"/shots/kierunek-*.png docs/features/visual-design/mockups/
```

Stos (`make up`) musi działać, bo kontener `e2e` sprawdza jego usługi przed uruchomieniem testów, choć sama makieta z niego nie korzysta.

Skrypt `mockups.spec.ts`:

```ts
import { expect, test } from "@playwright/test";

// Screenshots of the three visual direction mockups (VISUAL-DESIGN-1), opened from file://.
// Fails on: console errors, page errors, failed requests, responses >= 400, any request outside file://,
// fonts of the direction that document.fonts does not confirm (also for the Polish letters), horizontal scroll at 375 px.
const ROOT = "file:///app/agent/docs/features/visual-design/mockups";
const SHOTS = "/app/agent/shots";
const POLISH = "ąćęłńóśźż";

const DIRECTIONS = [
  { id: "a", families: { Outfit: [400, 500, 600, 700] } },
  { id: "b", families: { Archivo: [400, 500, 600, 700, 800] } },
  { id: "c", families: { Newsreader: [400, 500, 600], Inter: [400, 500, 600, 700] } },
];
// Elements that must be visible, with the widths where they are shown (the mega menu only at 1440, the department menu only at 375).
type Wanted = [selector: string, widths: number[]];
const BOTH = [1440, 375];
const SCREENS: { id: string; wanted: Wanted[] }[] = [
  { id: "glowna", wanted: [[".state-mega", [1440]], [".state-mobile-menu", [375]], [".dept", BOTH], [".hero-h1", BOTH], [".tile", BOTH], [".ftr", BOTH]] },
  {
    id: "listing",
    wanted: [[".crumbs", BOTH], [".page-head h1", BOTH], [".chips li", BOTH], [".avail--out", BOTH], [".avail--low", BOTH], [".tile-price", BOTH], [".pager", BOTH], [".panel.is-open", BOTH], [".state-filters", [375]], [".select.is-focus", BOTH]],
  },
  { id: "produkt", wanted: [[".thumb", BOTH], [".counter", BOTH], [".opt.is-unavail", BOTH], [".opt.is-chosen", BOTH], [".btn--buy.is-focus", BOTH], [".state-zoom", BOTH]] },
];
const WIDTHS = [1440, 375];

test.use({ trace: "off", screenshot: "off" });
test.describe.configure({ mode: "serial" });

for (const direction of DIRECTIONS) {
  for (const width of WIDTHS) {
    test(`kierunek-${direction.id} at ${width} px`, async ({ page }) => {
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
      await page.goto(`${ROOT}/kierunek-${direction.id}.html`, { waitUntil: "load" });

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
        { families: direction.families, polish: POLISH },
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
        // A tile with an unavailable variant value: only an <option disabled>, which is never "visible" itself.
        if (screen.id === "listing" && (await section.locator("option[disabled]").filter({ hasText: "niedostępny" }).count()) === 0) {
          problems.push("listing: no tile with an unavailable variant value");
        }
        await section.screenshot({ path: `${SHOTS}/kierunek-${direction.id}-${screen.id}-${width}.png`, animations: "disabled", scale: "css" });
      }

      expect(problems, problems.join("\n")).toEqual([]);
    });
  }
}
```

### Jak policzono kontrasty

Stosunki kontrastu w tabelach policzono skryptem w `python3` (OKLCH → liniowe sRGB wg Björna Ottossona → kodowanie sRGB zaokrąglone do 8 bitów → luminancja względna WCAG 2.x → stosunek `(L1 + 0,05) / (L2 + 0,05)`). Wartości `oklch(...)` leżą w gamucie sRGB (przycinanie nie zmienia wyniku). Tekst na zdjęciu hero (kierunek A): najjaśniejszy możliwy piksel pod nakładką to biel, nakładka kryje w 72% w całym obszarze tekstu (gradient zaczyna się blednąć dopiero za nim), więc podany stosunek to dolna granica; w B i C tekst hero leży na pełnym bloku (`dark`, `surface`).

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
