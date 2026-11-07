# VISUAL-DESIGN: projekt wizualny sklepu

Punkt 3 „Zakresu MVP” z `docs/vision.md`: makiety strony głównej, listingu i karty produktu ze zdjęciami, potem
docelowe tokeny i komponenty sklepu według sekcji „Wygląd sklepu” w `docs/vision.md`. Epik zmienia wygląd
`apps/store`, dodaje treść strony głównej, stopkę, galerię zdjęć z powiększeniem (nowy rozmiar zdjęć `zoom` w API)
i polską stronę 404 oraz zmienia nazwę sklepu na „Worf” (domena worf.pl). Adresy, odczyty danych i logika sklepu
(koszyk, kasa, filtry, wybór wariantu) zostają bez zmian; metadane zmieniają tylko nazwę sklepu.

Issues: #97
Zakres: `apps/store/src/styles`, `apps/store/src/components`, `apps/store/src/catalog`, `apps/store/src/cart`, `apps/store/src/checkout`, `apps/store/src/account`, `apps/store/src/home`, `apps/store/src/api/schema.d.ts`, `apps/store/app`, `apps/store/scripts`, `apps/store/tests`, `apps/store/CLAUDE.md`, `apps/api/src/Catalog`, `apps/api/config/packages/nelmio_api_doc.yaml`, `apps/api/tests/Unit/Catalog`, `apps/api/tests/Functional/Catalog`, `apps/api/tests/Integration/Catalog`, `apps/api/tests/Integration/Ordering`, `apps/api/openapi.json`, `apps/api/phpunit.dist.xml`, `apps/api/CLAUDE.md`, `infra/docker-compose.yml`, `CLAUDE.md`, `README.md`, `docs/vision.md`, `docs/features/agent-tester-ux/spec.md`, `docs/features/agentic-software-house/plan.md`, `docs/features/product-page/spec.md`, `docs/features/search/spec.md`, `docs/features/walking-skeleton/spec.md`, `docs/features/catalog-page-metadata/spec.md`, `docs/features/checkout/spec.md`, `.claude/agents/ux-reviewer.md`, `e2e/tests`, `e2e/support`

**Zależności.** PRODUCT-PAGE (#93) i SEARCH (#94) są na `main`. Epik obejmuje ekrany sklepu, które są na `main`
w chwili startu epiku (gałąź `epic/visual-design` powstaje z `main`); ekrany ORDER-MANAGEMENT (#92: `/konto/zamowienia`)
i PROMOTIONS (#96: oznaczenie „Promocja” i linia Omnibus) wchodzą w zakres, jeśli są wtedy na `main` (D9). Zakres
pokrywa się z nimi i z CATALOG-ADMIN (#95, `apps/api/src/Catalog`), więc VISUAL-DESIGN rusza po nich, nie tej samej
nocy.

## Cel i zakres

- **Nazwa sklepu** „Worf” zamiast dotychczasowej w całym repo (VISUAL-DESIGN-2).
- **Rozmiar zdjęć `zoom`** w API: całe zdjęcie bez przycinania, do powiększenia na karcie produktu (VISUAL-DESIGN-3).
- **Makiety** (VISUAL-DESIGN-1): trzy wyraźnie różne kierunki wizualne, każdy z makietą strony głównej, listingu
  i karty produktu na desktopie (1440 px) i telefonie (375 px), z prawdziwymi zdjęciami z danych demo i zrzutami
  ekranu wszystkich wariantów.
- **Wybór kierunku** (VISUAL-DESIGN-4): bezwzględny punkt zatrzymania AO. Nic z wyglądu sklepu nie jest wdrażane
  przed odpowiedzią właściciela.
- **Tokeny i fundament** (VISUAL-DESIGN-5): paleta i warstwa semantyczna wybranego kierunku w `src/styles/tokens.css`,
  fonty, promienie, cienie, komponenty shadcn/ui, nagłówek z mega menu, nowa stopka, polska strona 404.
- **Strona główna** (VISUAL-DESIGN-6): hero i kafelki działów nad listingiem wszystkich produktów, responsywne zdjęcia.
- **Listing** (VISUAL-DESIGN-7) i **karta produktu z galerią** (VISUAL-DESIGN-8) według makiety.
- **Pozostałe ekrany** (VISUAL-DESIGN-9: koszyk, kasa, potwierdzenie, konto, błąd): tokeny i wspólne komponenty, bez
  nowych układów.
- **Agent UX** (VISUAL-DESIGN-10): ocenia też responsywność, spójność komponentów i jakość wizualną.

Poza zakresem:
- kopiowanie identyfikacji Arc'teryx i Decathlona (logotypy, nazwy, charakterystyczne elementy), szablony komercyjne;
- logo graficzne (marka to znak słowny „Worf” złożony fontem kierunku); sprawdzenie nazwy „Worf” w bazach znaków
  towarowych (UPRP, EUIPO) przed publicznym demo, jak dziś w `docs/vision.md`;
- adres worf.pl w środowisku demo (DEPLOY #175); lokalnie adresy zostają `*.localhost`;
- tryb ciemny; panel administracyjny (`apps/admin`; zmienia się tylko wygenerowany klient, jeśli zmieni się schemat);
- rozmiar miniatury (`thumbnail`) w API: miniatury galerii używają najmniejszej szerokości rozmiaru `product`;
- treści i strony, których nie ma (regulamin, kontakt, blog), CMS i edycja treści strony głównej w panelu;
- testy regresji wizualnej w CI i pomiar Lighthouse w CI;
- lupa (powiększanie fragmentu pod kursorem) i gest szczypania w powiększeniu;
- biblioteka karuzeli (shadcn `carousel` dociąga `embla-carousel-react`, nową zależność).

## Nazwa sklepu (VISUAL-DESIGN-2)

- Wszystkie wystąpienia dotychczasowej nazwy sklepu stają się „Worf”: tytuły i metadane sklepu (`<nazwa> | Worf`,
  `title` i `description` layoutu, Open Graph), znak słowny w nagłówku, nadawca e-maili `MAILER_FROM`
  (`Worf <zamowienia@worf.localhost>` w `infra/docker-compose.yml` i `apps/api/phpunit.dist.xml`), testy, które je
  sprawdzają, `README.md`, `docs/vision.md` („Nazwa sklepu: „Worf” (domena worf.pl)”), `CLAUDE.md` aplikacji
  i specyfikacje funkcji.
- Sprawdzenie: `git grep -n -w -i rytm -- ':!docs/features/visual-design'` nie zwraca wyników (całe słowo, więc
  „algorytm” się nie liczy; ta specyfikacja i plan epiku wymieniają starą nazwę).
- Zmiana nie dotyka wyglądu (zostaje tymczasowy motyw do VISUAL-DESIGN-5), więc idzie w pierwszej fali obok makiet.

## Rozmiar zdjęć `zoom` (VISUAL-DESIGN-3)

- `ImageSize` (Catalog, jedyne miejsce rozmiarów) dostaje `zoom`: `resize` `fit` (całe zdjęcie, bez przycinania),
  bez stałych proporcji (wysokość proporcjonalna, w adresie imgproxy wysokość 0), bez powiększania ponad oryginał
  (imgproxy nie powiększa: dla mniejszego oryginału wszystkie szerokości dają plik w rozmiarze oryginału), szerokości
  1280, 1920, 2560, format WebP. Adresy podpisuje istniejący serwis, jak dla pozostałych rozmiarów.
- Wspólny widok obrazu (`ImageView`, schemat `ProductImage` w `nelmio_api_doc.yaml`, używany przez listę, szczegóły
  i koszyk) zostaje bez zmian, z wymaganym `aspectRatio`. Zdjęcie `zoom` ma osobny widok w Catalog (`ZoomImageView`:
  `alt`, `size`, `variants`, bez `aspectRatio`) i osobny schemat OpenAPI `ZoomImage`; proporcje rozmiaru są potrzebne
  tylko generatorowi adresów (`rs:fit:<szerokość>:0`). Cart i jego port obrazów się nie zmieniają.
- `GET /api/v1/products/{slug}` (`ProductDetails`) dostaje pole `zoomImages` (lista `ZoomImage`): te same zdjęcia co
  `images`, w tej samej kolejności i liczbie, w rozmiarze `zoom` (zbudowane z tych samych kluczy i opisów, bez dodatkowego odczytu bazy).
  Nagłówki odpowiedzi (`Cache-Control`, `xkey`) bez zmian.
- Eksport `make -C apps/api openapi` i `make api-client` w tym samym zadaniu; testy jednostkowe adresów `zoom`
  (ścieżka imgproxy `rs:fit:<szerokość>:0`), testy funkcjonalne szczegółów produktu (`zoomImages` w kolejności
  `images`, produkt bez zdjęć: pusta lista).
- Sklep w tym zadaniu dostaje tylko nowy wygenerowany typ, bez zmian kodu i wyglądu; powiększenie dodaje
  VISUAL-DESIGN-8. Schemat panelu (`openapi-admin.json`) się nie zmienia.

## Kierunek (wspólny dla wszystkich wariantów)

Z `docs/vision.md`: outdoor premium (zdjęcia w terenie na całą szerokość, stonowana paleta: czerń, grafit, złamana
biel i jeden akcent, prosta i pewna typografia, dużo przestrzeni), struktura dużego katalogu (mega menu, filtry,
breadcrumby), własna marka „Worf”, baza układów z github.com/vercel/shop (MIT) z zasadami przenoszenia
z `apps/store/CLAUDE.md`.

Wymagania każdego kierunku:
- kontrast WCAG 2.2 AA: tekst 4,5:1, duży tekst i elementy interfejsu (obramowania pól, fokus) 3:1, także tekst na
  zdjęciu hero (nakładka albo tło pod tekstem);
- widoczny fokus na każdym elemencie interaktywnym; cele dotyku na telefonie co najmniej 24×24 px (WCAG 2.5.8),
  przyciski główne i elementy galerii 44 px;
- font na licencji SIL OFL (D4), najwyżej dwie rodziny, z polskimi znakami (zakres `latin-ext`);
- brak poziomego przewijania przy 375 px;
- zdjęcia produktów w kafelku i na karcie produktu mają proporcje 1:1 (rozmiary `listing` i `product` z API), bez
  przycinania w CSS; różnice kompozycji mieszczą się w tym ograniczeniu.

## Makiety (VISUAL-DESIGN-1)

- Katalog `docs/features/visual-design/mockups/`: `kierunek-a.html`, `kierunek-b.html`, `kierunek-c.html`, zrzuty PNG
  każdego ekranu każdego kierunku w obu szerokościach (`kierunek-a-glowna-1440.png`, `kierunek-a-glowna-375.png`,
  `kierunek-a-listing-1440.png` … `kierunek-c-produkt-375.png`: 18 plików, pełna wysokość ekranu) i `README.md`.
- Zrzuty robi Playwright przez `make e2e-agent` z katalogiem roboczym poza repo, do którego skrypt kopiuje
  `docs/features/visual-design/mockups/` i `apps/api/fixtures/images/` z zachowaniem struktury katalogów (ścieżki
  względne makiet działają). Przed zrzutem skrypt czeka na `document.fonts.ready` i kończy się błędem, gdy
  `document.fonts.check` nie potwierdza fontów kierunku (zrzut nie może pokazać fontu zastępczego). Pełny skrypt
  i komenda są w `README.md`, więc zrzuty da się powtórzyć.
- `README.md`: dla każdego kierunku idea w dwóch zdaniach i czym różni się od pozostałych, paleta z wartościami OKLCH,
  tabela kontrastów par tekst/tło, fonty, skala typografii, promienie, odstępy; tabela porównania obszarów kompozycji
  trzech kierunków; jak obejrzeć makiety i powtórzyć zrzuty.
- Każdy plik HTML to samodzielny statyczny dokument z CSS w pliku, bez JavaScriptu i bez zależności (D3), z trzema
  ekranami, każdy w 1440 i 375 px, z podpisami:
  - strona główna: nagłówek z mega menu, hero z tekstem, kafelki działów, początek listingu, stopka;
  - listing kategorii: breadcrumby, `h1` z liczbą produktów, panel filtrów, aktywne filtry, sortowanie, siatka
    kafelków z ceną „od”, etykietą dostępności i wyborem wariantu, paginacja;
  - karta produktu: breadcrumby, galeria z miniaturami i licznikiem, nazwa, cena, dostępność, wybór wariantu
    z wartością niedostępną, przycisk „Do koszyka” (tekst ze sklepu).
- Treści i teksty interfejsu są takie jak w sklepie: nazwy produktów, kategorii, ceny i warianty z
  `apps/api/fixtures/catalog.yaml` i `prices.yaml`, nazwa „Worf”. Zdjęcia: pliki z `apps/api/fixtures/images/` przez
  ścieżki względne. Fonty makiet to lokalne pliki WOFF2 (OFL, `latin-ext`) w `mockups/fonts/`, te same, które
  wybrany kierunek dostanie w sklepie (D4); makieta nie pobiera niczego z sieci.
- **Kierunki są wyraźnie różne wizualnie**, nie tylko kolorem i fontem (D7). Każde dwa różnią się co najmniej w czterech
  obszarach kompozycji. Warianty obszarów nie zmieniają zachowania stron:
  - hero: zawsze na całą szerokość; różni się wysokością, położeniem i formą tekstu (na zdjęciu z nakładką, w pasie
    pod zdjęciem, w bloku na krawędzi zdjęcia) i kadrem;
  - kafelki działów: siatka równa, mozaika o różnych rozmiarach, poziomy pas przewijany;
  - siatka produktów i kafelek: liczba kolumn i odstępy, zdjęcie na całą szerokość kafelka bez ramki albo karta
    z ramką i tłem, położenie ceny i dostępności;
  - nagłówek i mega menu: jeden albo dwa wiersze, panel na całą szerokość albo w kolumnach;
  - filtry na desktopie: kolumna po lewej albo sekcja rozwijana przyciskiem „Filtry” nad siatką, z dzisiejszym
    zachowaniem panelu na telefonie (`aria-expanded`, sekcja w treści strony, nie okno modalne, działa bez
    JavaScriptu);
  - karta produktu: galeria z miniaturami z boku albo pod zdjęciem, proporcje kolumn galerii i danych;
  - typografia (bezszeryfowa geometryczna, grotesk, szeryfowe nagłówki), promienie i cienie, akcent.
- Makiety pokazują stany: wartość wariantu niedostępna, produkt „Niedostępny” na kafelku, „Ostatnie sztuki”,
  aktywne filtry, fokus klawiatury na jednym elemencie, otwarte mega menu (desktop), menu działów na telefonie,
  otwarte powiększenie zdjęcia.

## Wybór kierunku: bezwzględny punkt zatrzymania (VISUAL-DESIGN-4)

- Wyboru dokonuje wyłącznie właściciel. AO ani żaden agent nie wybiera kierunku i nie zaczyna wdrażania wyglądu
  przed jego odpowiedzią.
- VISUAL-DESIGN-4 zależy od VISUAL-DESIGN-1 i zmienia wyłącznie dokumentację (katalog makiet i tę specyfikację).
  VISUAL-DESIGN-5 zależy od VISUAL-DESIGN-4, a VISUAL-DESIGN-6 do 9 od VISUAL-DESIGN-5, więc żadne zadanie wdrażające
  wygląd nie startuje przed rozstrzygnięciem i integracją VISUAL-DESIGN-4.
- VISUAL-DESIGN-4 bez odpowiedzi właściciela w promptcie, przed jakąkolwiek zmianą w repo, zwraca `BLOCKED`
  (`business`), bez commita i bez PR, z pytaniem „Który kierunek wdrażamy i z jakimi poprawkami?”:
  - opcje: A, B, C i „nowa runda kierunków”, każda ze skutkami;
  - rekomendacja z uzasadnieniem (kontrast, czytelność na telefonie, zgodność z `docs/vision.md`); rekomendacja nie
    jest wyborem;
  - dowody: ścieżki wszystkich 18 zrzutów PNG, trzech makiet i `README.md` na `origin/epic/visual-design`.
  `BLOCKED` jest jedynym dopuszczalnym wynikiem bez odpowiedzi. Odpowiedź przekazuje `/ao-run` (`ao answer`)
  słowami właściciela, także z poprawkami (np. „B, akcent z A”).
- Odpowiedź obsługuje VISUAL-DESIGN-4:
  - wybór (z poprawkami): w katalogu makiet zostaje jeden kierunek jako `kierunek.html` z poprawkami naniesionymi na
    makietę, jego sześć zrzutów (`kierunek-glowna-1440.png` itd., zrobione od nowa po poprawkach) i `README.md`
    skrócony do wybranego kierunku; pliki odrzuconych kierunków są usuwane; w tej specyfikacji powstaje sekcja
    „Wybrany kierunek” (charakter jakościowo: akcent, fonty, gęstość, układy z listy obszarów, promienie, poprawki
    właściciela; wartości tylko w `tokens.css` po VISUAL-DESIGN-5, sekcja do niego odsyła), a sekcje „Makiety”
    i „Wybór kierunku” zastępuje sekcja „Makieta” (plik, zrzuty, lista stanów, sposób powtórzenia zrzutów, makieta
    jako ilustracja z chwili wyboru, D11); kryterium 1 dostaje brzmienie stanu końcowego. Zwykły PR do gałęzi epiku
    (zmiana wyłącznie dokumentacji);
  - „nowa runda” (z uwagami): nowe kierunki według zasad sekcji „Makiety” w miejsce poprzednich, commit na gałęzi
    zadania (wypchniętej, bez PR) i ponowne `BLOCKED` z tym samym pytaniem i ścieżkami nowych zrzutów na tej gałęzi;
  - poprawki zmieniające kompozycję (układ któregoś z obszarów z listy, nie tylko kolor, font czy odstęp): zadanie
    nanosi je na `kierunek.html`, robi nowe zrzuty, commituje na gałęzi zadania (bez PR) i zwraca jeszcze jedno
    `BLOCKED` z prośbą o potwierdzenie i ścieżkami nowych zrzutów; dopiero potwierdzenie kończy wybór;
  - odpowiedź niejednoznaczna (nie wskazuje kierunku ani nowej rundy): ponowne `BLOCKED` z prośbą o wybór.
- Zadania 5–9 wdrażają „Wybrany kierunek” i makietę `kierunek.html`. Tekst hero i układ ekranów pochodzą z makiety;
  implementer ich nie wymyśla. Szczegół, którego makieta nie pokazuje (np. stan ładowania kasy), implementer
  wyprowadza z tokenów i wspólnych komponentów i wpisuje do „Decyzji bez pytania” w PR.

## Tokeny i fundament (VISUAL-DESIGN-5)

- `src/styles/tokens.css` zostaje jedynym miejscem wyglądu (komentarz pliku bez „tymczasowy”): paleta `--palette-*`
  wybranego kierunku zamiast tymczasowej, warstwa semantyczna shadcn/ui, `--radius`, cienie i skala typografii.
  Wszystkie tokeny potrzebne makiecie definiuje to zadanie, także nowe semantyczne (np. `success` dla „Dostępny”,
  `warning` dla „Ostatnie sztuki”, nakładka hero). Zadania 6–9 nie zmieniają palety ani istniejących tokenów; mogą
  dopisać nowy token semantyczny wskazujący istniejącą paletę, z uzasadnieniem w PR. Domyślna paleta Tailwinda dalej wyłączona, reguła ESLint przeciw kolorom „na sztywno” bez zmian
  i bez wyjątków.
- Fonty: pliki WOFF2 z zakresem `latin-ext` w `apps/store/src/styles/fonts/` ładowane przez `next/font/local` (bez
  żądań do zewnętrznych hostów w przeglądarce i w buildzie), zmienna CSS fontu w tokenach. Tekst licencji OFL i lista
  plików fontów w `apps/store/THIRD_PARTY_NOTICES.md` (nowy plik; D4).
- Komponenty `src/components/ui/` (obecne: `button`, `card`, `input`, `pagination`; nowe tylko przez CLI shadcn,
  według `apps/store/CLAUDE.md`) dopasowane do kierunku. Wszystkie komponenty shadcn potrzebne epikowi, także `dialog`
  galerii, dodaje to zadanie (CLI shadcn dopisuje do `tokens.css`). Stany: `hover`, `focus-visible`, `disabled`, błąd pola.
  Ekrany używają tych komponentów zamiast własnych odpowiedników (spójność komponentów).
- Nagłówek (`SiteHeader`, `MegaMenu`, `SearchBox`, `CartBadge`, `AccountMenu`) według makiety: znak słowny „Worf”
  (link do `/`), wyszukiwarka, koszyk, konto, mega menu działów. Zachowanie (otwieranie, zamykanie, klawiatura,
  `aria-expanded`, linki w HTML przy zamkniętym menu, wiersz wyszukiwarki pod 640 px) bez zmian.
- Nowa stopka (`SiteFooter`, Server Component w layoucie): znak słowny, linki działów (kategorie główne z tego samego
  odczytu drzewa co nagłówek, bez dodatkowego żądania; błąd drzewa: stopka bez linków), tekst „Zdjęcia: Unsplash”,
  rok i nazwa sklepu. Bez linków do stron, których nie ma.
- Strona 404 (`app/not-found.tsx`, dziś domyślna angielska strona Next.js; D10): „Nie znaleźliśmy tej strony.” i link
  „Wróć do sklepu” (`/`), w layoucie sklepu, z `noindex` jak dziś.
- Pliki przeniesione z vercel/shop: komentarz nagłówkowy i wpis w `apps/store/THIRD_PARTY_NOTICES.md` (pełny tekst
  licencji MIT), jak w `apps/store/CLAUDE.md`.

## Strona główna `/` (VISUAL-DESIGN-6)

- `/` w stanie domyślnym listingu (bez filtrów, bez sortowania innego niż domyślne, `page` brak albo 1; parametry
  spoza listingu, np. `utm_*`, się nie liczą): nad listingiem hero (zdjęcie na całą szerokość, nagłówek, krótki
  tekst, link do działu) i kafelki działów (każda kategoria główna z drzewa: zdjęcie, nazwa, link `/c/<slug>`).
  W innym stanie `/` to sam listing, jak dziś.
- Treść hero (tekst z makiety, dział linku) i przypisanie zdjęcia do działu po slugu kategorii głównej są w kodzie
  sklepu (`src/home/`), bo nie ma CMS. Drzewo pochodzi z `loadMenuCategories` (to samo `React.cache` co nagłówek, bez
  dodatkowego żądania; `app/page.requests.test.tsx` to potwierdza). Kategoria główna bez przypisanego zdjęcia ma
  kafelek bez zdjęcia (tło z tokenu). Link hero do działu, którego nie ma w drzewie, i każdy link hero przy błędzie
  drzewa są pomijane (hero bez linku); błąd drzewa daje stronę bez kafelków, jak menu.
- Nagłówki na `/` w stanie domyślnym: `h1` to nagłówek hero, nazwy działów w kafelkach i „Wszystkie produkty” nad
  listingiem to `h2`, nazwy produktów w kafelkach `h3`. W innym stanie `h1` „Wszystkie produkty” i `h2` nazw produktów
  jak dziś. Tytuł, opis, kanoniczny i `noindex` `/` bez zmian (poza nazwą sklepu).
- Priorytet ładowania w stanie domyślnym ma tylko zdjęcie hero; kafelki produktów pod nim nie dostają `priority`
  (dziś pierwsze kafelki listingu mają `fetchpriority="high"`). W innym stanie jak dziś.

### Zdjęcia strony głównej (D5)

- Lista zdjęć to czyste dane w `src/home/marketing-images.ts` (bez importów plików): plik źródłowy z
  `apps/api/fixtures/images/`, kadr, opis (`alt`) i szerokości każdego wariantu. To jedyne miejsce rozmiarów zdjęć
  marketingowych.
- Skrypt `apps/store/scripts/marketing-images.mjs` czyta tę listę i generuje pliki WebP w `src/home/images/` oraz moduł
  `src/home/images.generated.ts` ze statycznymi importami plików (bundler nadaje im niezmienne adresy z hashem,
  `/_next/static/media/...`). Wynik jest commitowany; test (Vitest, bez usług) sprawdza, że moduł wygenerowany
  odpowiada liście (nazwy plików i szerokości).
- Skrypt uruchamia się w kontenerze `store` (`make -C apps/store shell`, potem `node scripts/marketing-images.mjs`).
  Źródła są w kontenerze pod `/fixtures/images` z montowania tylko do odczytu
  `./apps/api/fixtures/images:/fixtures/images:ro` w usłudze `store` (`infra/docker-compose.yml`); ścieżki na liście
  są względne do tego katalogu. Skrypt używa `sharp`, które instaluje Next.js (sprawdzone 2026-11-07 w kontenerze `store`: `sharp` 0.35.5, libvips
  8.18.7, zapis WebP działa), bez zmiany `package.json`. Najpierw sprawdza wszystko (jest `sharp`, są pliki źródłowe,
  kadr w źródle jest co najmniej tak szeroki jak każda szerokość: bez powiększania), dopiero potem zapisuje pliki.
  Brak `sharp`, brak pliku źródłowego, szerokość ponad kadr albo plik ponad budżet: komunikat na stderr i kod wyjścia
  różny od 0 (częściowy wynik cofa `git checkout`). Ścieżki błędu mają testy w `apps/store/tests` (kod wyjścia
  i komunikat). W Vitest import `.webp` daje napis zamiast `StaticImageData` Next.js: testy modułu i strony głównej
  używają wspólnego mocka plików obrazów.
- Kadry i szerokości (źródła mają 1600 px na dłuższym boku, więc kadr pionowy ze zdjęcia poziomego ma około 850 px
  szerokości):
  - hero przez `<picture>`: telefon (`media="(max-width: 767px)"`, kadr pionowy 4:5, szerokości 480, 640, 828)
    i szeroki ekran (kadr poziomy z makiety, szerokości 1280, 1600);
  - kafelki działów: szerokości 320, 480, 768 z `sizes` odpowiadającym kolumnom siatki z makiety.
- Każdy `<img>` ma `srcset`, `sizes`, `width` i `height` (bez przeskoku układu). Hero ma `fetchpriority="high"`,
  kafelki `loading="lazy"` i `decoding="async"`. Sklep renderuje zwykłe `<picture>`/`<img srcset>`, bez `next/image`
  (globalny loader rzuca błąd).
- Budżet rozmiaru: WebP o jakości około 75; hero 1600 ≤ 250 KB, hero 828 (telefon) ≤ 120 KB, kafelek 768 ≤ 80 KB.
- Na telefonie (375 px, DPR 2 i 3) strona pobiera kadr telefonu hero w szerokości najwyżej 828 px i żadnego pliku
  hero szerszego ekranu (test E2E: żądania obrazów strony głównej na 375 px). Przy DPR 3 obraz 828 px jest lekko
  mniej ostry niż ekran (potrzeba około 1125 px); większa szerokość wymagałaby powiększania źródeł.
- Teksty alternatywne: zdjęcie hero ma opis z listy; zdjęcie kafelka działu ma `alt=""`, bo nazwa działu jest tekstem
  linku.
- Źródło zdjęć w `THIRD_PARTY_NOTICES.md` z odesłaniem do `apps/api/fixtures/images/CREDITS.md`.

## Listing `/`, `/c/[...path]`, `/szukaj` (VISUAL-DESIGN-7)

- `CatalogListing`, `Breadcrumbs`, `ListingFilters` (na telefonie i, jeśli makieta tak pokazuje, na desktopie panel
  wysuwany przyciskiem „Filtry” z dzisiejszym zachowaniem), `ActiveFilters`, `SortSelect`, `ProductGrid`,
  `ProductTile`, `TileOffer`, `Pagination`, `EmptyResults`, `InvalidFilter` według makiety. Etykiety dostępności
  z tokenów semantycznych. Zachowanie, teksty, adresy, formularz bez JavaScriptu i liczba żądań do API bez zmian.
- Oznaczenie „Promocja” i linia Omnibus (PROMOTIONS), jeśli są na `main`: styl z tokenów, bez zmiany treści (D9).

## Karta produktu `/p/[slug]` (VISUAL-DESIGN-8)

- `ProductDetailsView`, `VariantChoice`, `AddToCartButton` według makiety (na desktopie galeria obok danych, na
  telefonie galeria nad danymi).
- Galeria (D6), zdjęcia `images` (rozmiar `product`) i `zoomImages` (rozmiar `zoom`) z API:
  - HTML z serwera zawiera całą galerię: pasek wszystkich zdjęć z przewijaniem poziomym i przyciąganiem (CSS
    `scroll-snap`, każde zdjęcie z `id`, np. `zdjecie-2`), miniatury jako linki `#zdjecie-n` („Pokaż zdjęcie n z m”,
    obraz miniatury z `alt=""`, najmniejsza szerokość rozmiaru `product`, `loading="lazy"`) i licznik „1 / m”.
    Pierwsze zdjęcie paska ma `priority`. Bez JavaScriptu zdjęcia przewija się palcem i miniaturami. Układ ma te same
    wymiary przed hydratacją i po niej (bez przesunięcia nazwy, ceny i przycisku); HTML ISR nie zależy od sesji ani
    stanu galerii, a pierwszy render w przeglądarce jest taki sam jak na serwerze;
  - JavaScript dodaje: `aria-current` przy miniaturze widocznego zdjęcia, licznik „n / m” (`aria-live="polite"`)
    podążający za przewijaniem (także palcem), przyciski „Poprzednie zdjęcie” i „Następne zdjęcie”, płynne
    przewijanie po kliknięciu miniatury (bez zmiany adresu i bez wpisu w historii: domyślna akcja linku jest
    blokowana) i ← → w pasku z fokusem; bez JavaScriptu link kotwicy przewija pasek, a przeglądarka może dopisać
    `#zdjecie-n` do adresu;
  - dotyk: przesunięcie palcem w pasku i w powiększeniu zmienia zdjęcie (natywne przewijanie z `scroll-snap`, bez
    własnej obsługi gestów); cele dotyku miniatur i przycisków co najmniej 44×44 px na telefonie;
  - klawiatura: Tab przechodzi przez miniatury, przyciski i zdjęcia, Enter i Spacja je uruchamiają;
  - powiększenie: zdjęcie paska jest w przycisku z nazwą „Powiększ zdjęcie n z m: <alt>” (opis zdjęcia jest częścią
    nazwy, bo nazwa przycisku zastępuje `alt` zawartości); kliknięcie, dotknięcie albo Enter otwiera
    shadcn `dialog` (radix-ui, już w zależnościach) z nazwą produktu jako tytułem; w środku pasek zdjęć `zoomImages`
    z `scroll-snap`, całe zdjęcie mieszczące się w oknie (`object-fit: contain`), `srcset` z `sizes="100vw"` (telefon
    nie pobiera 2560 px), obrazy poza widocznym `loading="lazy"`; przyciski i klawisze ← → zmieniają zdjęcie,
    licznik „n / m”, Escape i „Zamknij” zamykają, fokus wraca na przycisk, który otworzył okno; okno otwiera się na
    zdjęciu, które było widoczne;
  - teksty alternatywne: każde zdjęcie w pasku i w powiększeniu ma `alt` z API (`image.alt`); miniatury są
    dekoracyjne (`alt=""`), bo nazwę ma link;
  - jedno zdjęcie: bez miniatur, licznika i strzałek, z powiększeniem; bez zdjęć: `PhotoPlaceholder` bez powiększenia.
- Promocja i Omnibus (PROMOTIONS), jeśli są na `main`: styl z tokenów, bez zmiany treści (D9).

## Pozostałe ekrany (VISUAL-DESIGN-9) i agent UX (VISUAL-DESIGN-10)

- `/cart` (`CartPage`), `/checkout` (`CheckoutPage`), `/checkout/confirmation` (`PaymentConfirmation`), konto
  (`/konto/zamowienia` i szczegóły, ORDER-MANAGEMENT, jeśli są na `main`), `app/error.tsx`: tokeny, typografia
  i wspólne komponenty (przyciski, pola, karty, komunikaty `role="alert"`), bez zmiany treści i zachowania.
- Agent UX (D8; VISUAL-DESIGN-10, osobne zadanie narzędzi repo bez zależności od wyglądu) ocenia, w `/sweep` i w `/integration` epików z frontem sklepu, oprócz użyteczności i dostępności także:
  responsywność (375, 768 i 1440 px: układ, brak poziomego przewijania, cele dotyku, obrazy w rozmiarze ekranu),
  dostępność (klawiatura, fokus, nazwy, `alt`, kontrast, axe), spójność komponentów (te same przyciski, pola, karty
  i komunikaty na wszystkich ekranach, tylko tokeny) i jakość wizualną (zgodność z „Wybranym kierunkiem” i tokenami,
  hierarchia, odstępy, wyrównanie, kadrowanie zdjęć). Makieta `kierunek.html` jest dla niego ilustracją, nie wzorcem
  pikselowym. W `/integration` tego epiku porównuje ekrany sklepu ze zrzutami makiety.

## Kryteria akceptacji

1. Makiety: VISUAL-DESIGN-1 dodaje trzy kierunki, 18 zrzutów i `README.md` z tabelą porównania; każde dwa kierunki
   różnią się co najmniej w czterech obszarach kompozycji. Każda makieta ma trzy ekrany w 1440 i 375 px ze stanami
   z listy; skrypt z `README.md` (`make e2e-agent`) otwiera ją z `file://` bez błędów konsoli i brakujących zasobów
   (także fontów: `document.fonts.check` potwierdza fonty kierunku); tabela kontrastów: każda para tekst/tło spełnia AA.
2. Zatrzymanie: VISUAL-DESIGN-4 bez odpowiedzi właściciela kończy się `BLOCKED` (`business`) z pytaniem, opcjami,
   rekomendacją i ścieżkami wszystkich 18 zrzutów, bez commita i PR; zadania 5–9 nie startują przed jego integracją.
   Po wyborze w repo są `kierunek.html`, jego sześć zrzutów, `README.md` i sekcja „Wybrany kierunek” zgodna
   z odpowiedzią; „nowa runda” daje nowe makiety na gałęzi zadania i kolejne `BLOCKED` bez zmian w kodzie sklepu.
3. Nazwa: `git grep -n -w -i rytm -- ':!docs/features/visual-design'` nie zwraca wyników; tytuły stron sklepu kończą
   się „| Worf”; e-mail potwierdzenia w Mailpit ma nadawcę „Worf <zamowienia@worf.localhost>”; testy API, sklepu
   i E2E przechodzą.
4. Rozmiar `zoom`: `GET /api/v1/products/{slug}` zwraca `zoomImages` w kolejności i liczbie `images`, z adresami
   `rs:fit:<szerokość>:0` w szerokościach 1280, 1920, 2560 (schemat `ZoomImage`); schemat `ProductImage`, rozmiary
   `listing` i `product` oraz koszyk bez zmian; `openapi.json` i klient TS sklepu zaktualizowani w tym samym zadaniu; `make -C apps/api check` i testy API przechodzą.
5. Tokeny: `tokens.css` zawiera wyłącznie paletę wybranego kierunku; `make -C apps/store check` przechodzi bez
   wyłączania reguł; `bg-[#000]`, `text-red-500` i kolor w `style` nadal kończą się błędem ESLint.
6. Fonty: test E2E zbiera żądania przeglądarki na stronie głównej, listingu i karcie produktu, a każde idzie do hosta
   stosu; build sklepu w CI (`STORE_MODE=production`) przechodzi z fontami z plików w repo; polskie znaki („ąćęłńóśźż”)
   w nagłówku i tekście są w foncie kierunku (pliki `latin-ext`); licencja OFL w `THIRD_PARTY_NOTICES.md`.
7. Strona główna: `/` w stanie domyślnym (także z `?utm_source=x` i `?page=1`) ma hero i kafelki wszystkich kategorii
   głównych; `/?page=2`, `/` z filtrem albo sortowaniem nie mają ich; jeden `h1` i hierarchia nagłówków z sekcji
   „Strona główna”; priorytet ładowania ma tylko hero; tytuł, kanoniczny i `noindex` jak przed zmianą (poza nazwą
   sklepu); błąd drzewa: hero bez linku, bez kafelków.
8. Zdjęcia strony głównej: na 375 px (DPR 2 i 3) strona pobiera kadr telefonu hero w szerokości ≤ 828 px i żadnego
   pliku hero szerszego ekranu; każdy obraz ma `srcset`, `sizes`, `width`, `height`; pliki mieszczą się w budżecie;
   ponowne uruchomienie skryptu daje ten sam zestaw nazw plików i wymiarów w budżecie (nie identyczne bajty); bez
   `sharp`, bez pliku źródłowego (także bez montowania `/fixtures/images`), przy szerokości ponad kadr i ponad budżet
   skrypt kończy się kodem różnym od 0 z komunikatem (testy).
9. Wygląd: ekrany sklepu odpowiadają makiecie `kierunek.html` na 1440 i 375 px; zrzuty ekranu sklepu robione
   `make e2e-agent` zostają w katalogu wyników poza repo, a PR podaje ich ścieżki; zgodność ocenia agent UX
   w `/integration` (D8). Istniejące testy Vitest i E2E przechodzą; zmienić wolno tylko asercje elementów, które epik
   zmienia: nazwa sklepu, nagłówki na `/` w stanie domyślnym (`e2e/tests/catalog.spec.ts`, `e2e/tests/search.spec.ts`,
   `apps/store/app/page.test.tsx` i testy, które szukają kafelków produktów na `/` po poziomie nagłówka:
   `e2e/support/listing.ts`, `cart-guest.spec.ts`, `listing-filters.spec.ts`, `checkout-guest.spec.ts`,
   `checkout-customer.spec.ts`; pomocnik siatki przestaje zależeć od poziomu nagłówka, np. `data-testid` siatki),
   otwarcie panelu filtrów przyciskiem na desktopie (tylko gdy makieta tak pokazuje, w pomocniku E2E), priorytet
   kafelków na `/`, zdjęcia strony produktu (galeria), nagłówek i stopka; każda zmiana z uzasadnieniem w PR.
10. Galeria: miniatury, przyciski i powiększenie działają myszą, klawiaturą (Tab, Enter, Spacja, ← →, Escape, powrót
    fokusu) i dotykiem (przesunięcie w pasku i w powiększeniu zmienia zdjęcie; E2E z emulacją urządzenia dotykowego
    375 px, gest przez CDP `Input.synthesizeScrollGesture`); kliknięcie miniatury z JavaScriptem nie zmienia adresu; każde zdjęcie ma `alt` z API, a przycisk powiększenia ma go w nazwie; miniatury `alt=""` w linku z nazwą; bez JavaScriptu wszystkie zdjęcia
    i miniatury są w HTML i działają; pozycja `h1` i przycisku „Do koszyka” jest taka sama przed hydratacją i po niej;
    powiększenie na 375 px nie pobiera pliku 2560 px; testy komponentu (Vitest) i E2E na produkcie demo z kilkoma
    zdjęciami.
11. Strona 404: nieznany adres i nieznany produkt pokazują „Nie znaleźliśmy tej strony.” z linkiem do `/`, nagłówkiem
    i stopką sklepu, z `noindex`.
12. Pozostałe ekrany: brak klas spoza tokenów, wspólne komponenty zamiast własnych odpowiedników; teksty i zachowanie
    bez zmian (istniejące testy przechodzą).
13. Dostępność: axe (`@axe-core/playwright`, już w `e2e`) bez naruszeń `serious` i `critical` na stronie głównej,
    listingu, karcie produktu (także z otwartym powiększeniem), koszyku, kasie i stronie 404, na 1440 i 375 px; brak
    poziomego przewijania przy 375 px na tych stronach (`site-header.spec.ts` i nowe testy).
14. Agent UX: `docs/features/agent-tester-ux/spec.md` i `.claude/agents/ux-reviewer.md` opisują ocenę responsywności,
    dostępności, spójności komponentów i jakości wizualnej z sekcji „Pozostałe ekrany (VISUAL-DESIGN-9) i agent UX (VISUAL-DESIGN-10)”.

## Zadania dokumentacji (razem z implementacją)

- Nazwa „Worf” (VISUAL-DESIGN-2): `docs/vision.md`, `README.md`, `apps/store/CLAUDE.md`, `apps/api/CLAUDE.md`
  (`MAILER_FROM`), `docs/features/catalog-page-metadata/spec.md`, `docs/features/search/spec.md`,
  `docs/features/product-page/spec.md`, `docs/features/checkout/spec.md`, `docs/features/walking-skeleton/spec.md`.
- Rozmiar `zoom` (VISUAL-DESIGN-3): `docs/vision.md` („Zdjęcia produktów”: rozmiar `zoom` bez przycinania),
  `apps/api/CLAUDE.md` (zdjęcia szczegółów w rozmiarach `product` i `zoom`), `docs/features/product-page/spec.md`
  (`zoomImages` w odczycie strony).
- `docs/vision.md` („Wygląd sklepu”): bez zdania o tymczasowym motywie; wybrany kierunek jednym zdaniem z odesłaniem
  do tej specyfikacji (VISUAL-DESIGN-5); strona główna z hero i kafelkami działów; zdjęcia marketingowe strony głównej
  jako statyczne, responsywne pliki sklepu, wyjątek od zdjęć przez imgproxy (D5), w „Zdjęcia produktów”
  (VISUAL-DESIGN-6).
- Główny `CLAUDE.md` („Obrazy”): wyjątek dla zdjęć marketingowych strony głównej i ich jedna lista rozmiarów w sklepie
  (D5) (VISUAL-DESIGN-6).
- `apps/store/CLAUDE.md`: „Wygląd” (wybrany kierunek, fonty lokalne, paleta tylko z VISUAL-DESIGN-5, stopka, 404),
  struktura (`SiteFooter`, `not-found.tsx`) (VISUAL-DESIGN-5); „Obrazy” i struktura (`src/home/`, skrypt, lista
  zdjęć marketingowych, montowanie `/fixtures/images` w kontenerze `store`) (VISUAL-DESIGN-6); galeria i powiększenie w „Strona produktu” (VISUAL-DESIGN-8).
- `docs/features/catalog-page-metadata/spec.md` (strona 404 z Next.js) (VISUAL-DESIGN-5).
- `docs/features/search/spec.md` (nagłówek, nagłówki na `/`, tymczasowy motyw, pozycje „Poza zakresem” z VISUAL-DESIGN)
  i `docs/features/walking-skeleton/spec.md` (tymczasowy motyw): stan obecny z odesłaniem do tej specyfikacji
  (VISUAL-DESIGN-6).
- `docs/features/product-page/spec.md` (zdjęcia strony i pozycja „Poza zakresem” z VISUAL-DESIGN): galeria
  z powiększeniem, odesłanie do tej specyfikacji (VISUAL-DESIGN-8).
- `docs/features/agent-tester-ux/spec.md`, `.claude/agents/ux-reviewer.md` i `docs/features/agentic-software-house/plan.md`
  (krok 9): zakres oceny agenta UX z D8 (VISUAL-DESIGN-10).

## Decyzje

| # | Decyzja |
|---|---|
| D1 | Makiety robi AO w pierwszej fali (VISUAL-DESIGN-1). Wybór kierunku to bezwzględny punkt zatrzymania: osobne zadanie dokumentacji VISUAL-DESIGN-4 z pytaniem blokującym do właściciela; AO nie wybiera kierunku i nie wdraża wyglądu przed odpowiedzią. |
| D2 | Makiety trafiają do repo w `docs/features/visual-design/mockups/` przez PR zadań epiku; końcowy PR epiku merguje użytkownik. |
| D3 | Makiety to statyczny HTML w `docs` ze zrzutami PNG wszystkich ekranów obu szerokości, nie strony w sklepie. |
| D4 | Fonty na licencji OFL z polskimi znakami, lokalnie przez `next/font/local`, pliki WOFF2 w repo. |
| D5 | Zdjęcia strony głównej to statyczne pliki WebP sklepu (wyjątek od imgproxy), zoptymalizowane i responsywne: osobny kadr i szerokości dla telefonu, budżet rozmiaru, telefon nie pobiera pliku szerokiego ekranu. Generuje je skrypt na `sharp` z Next.js (sprawdzone w kontenerze). |
| D6 | Galeria: pasek `scroll-snap`, miniatury i licznik w HTML z serwera, przyciski, powiększenie w shadcn `dialog` na nowym rozmiarze `zoom` z API (całe zdjęcie bez przycinania); dotyk, klawiatura, teksty alternatywne z API; bez lupy i biblioteki karuzeli. |
| D7 | Trzy kierunki, wyraźnie różne w kompozycji (co najmniej cztery obszary), nie tylko kolorem i fontem; warianty obszarów nie zmieniają zachowania stron. |
| D8 | Agent UX ocenia responsywność, dostępność, spójność komponentów i jakość wizualną względem „Wybranego kierunku” i tokenów; makieta jest ilustracją. |
| D9 | Ekrany ORDER-MANAGEMENT i PROMOTIONS są w zakresie, jeśli są na `main` przy starcie; VISUAL-DESIGN rusza po tych epikach. |
| D10 | Własna polska strona 404 w layoucie sklepu. |
| D11 | Makieta wybranego kierunku i jej sześć zrzutów zostają w repo jako ilustracja z chwili wyboru; źródłem prawdy są ta specyfikacja i `tokens.css`. |
| D12 | Nazwa sklepu „Worf” (domena worf.pl) w całym repo, w pierwszej fali. |

## Ryzyka

- Makiety AO mogą być słabe wizualnie: właściciel odpowiada poprawkami albo „nową rundą” (VISUAL-DESIGN-4, kolejne
  pytanie blokujące).
- Pytanie blokujące zatrzymuje przebieg AO do odpowiedzi: epik nie przejdzie w całości w jedną noc. Długie czekanie
  albo kilka rund może wyczerpać limit czasu epiku w `/ao-run` (`--epic-timeout`); wznowienie (`ao epic resume`)
  tylko na polecenie użytkownika.
- Zadania 6, 8 i 9 idą równolegle (7 po 6, bo oba zmieniają `CatalogListing` i siatkę) na wspólnych plikach sklepu (np. `CLAUDE.md` sklepu, layout): możliwe konflikty merge'a
  w gałęzi epiku; `tokens.css` zmienia tylko VISUAL-DESIGN-5.
- `sharp` jest zależnością Next.js, nie sklepu: aktualizacja Next.js może go zmienić albo usunąć. Skrypt kończy się
  wtedy jasnym błędem; commitowane pliki działają dalej.
- Miniatury galerii pobierają najmniejszą szerokość rozmiaru `product` (640 px) zamiast miniatury; osobny rozmiar
  `thumbnail` w API to późniejsze issue.
- Na gałęzi epiku (chronionej, bez usuwania) zostają zrzuty wszystkich rund makiet; do `main` trafia tylko wybrany
  kierunek.
- Pomiar Lighthouse w CI z `docs/vision.md` („Wydajność”) zostaje poza epikiem; bez niego nie twierdzimy, że strony
  spełniają Core Web Vitals.
- Nazwa „Worf” to też imię postaci ze znanej franczyzy filmowej; przed publicznym demo trzeba ją sprawdzić w bazach
  znaków towarowych (UPRP, EUIPO), jak każdą nazwę sklepu.
- Makieta zostaje ilustracją z chwili wyboru: późniejsze zmiany wyglądu jej nie aktualizują (D11).
