# Metadane strony katalogu

Status: zaakceptowana do realizacji (task `CATALOG-PAGE-METADATA`). Ryzyko: FAST.

## Problem

Strony katalogu sklepu (`apps/store/app/page.tsx` i `apps/store/app/c/[...path]/page.tsx`) bez własnych metadanych
miałyby te same dane z layoutu: tytuł „Rytm” i opis „Sklep internetowy Rytm”. Wyszukiwarka nie odróżniałaby kategorii
po tytule, ten sam listing byłby dostępny pod wieloma adresami (`?page=1`, dodatkowe parametry), a strona
„Nieprawidłowy filtr” mogłaby trafić do indeksu.

## Zachowanie

Tylko sklep (`apps/store`). Bez zmian w API, kliencie TS i panelu.

- Adresy: `/` (wszystkie produkty), `/c/<ścieżka slugów>` (kategoria, np. `/c/bieganie/buty`) i `/szukaj` (wyniki
  wyszukiwania, osobna sekcja niżej). Stary adres `/?category=<id>` istniejącej kategorii przekierowuje trwale (308) na
  `/c/<ścieżka>`, z pozostałymi parametrami.
- Tytuł strony (`<title>`):
  - `/`: `Wszystkie produkty | Rytm`;
  - `/c/<ścieżka>`: `<nazwa kategorii> | Rytm` (nazwa z drzewa kategorii);
  - strona listy większa niż 1: przed ` | Rytm` dochodzi ` – strona <n>`, np. `Buty – strona 2 | Rytm`.
- Opis (`description`): `/` `Wszystkie produkty w sklepie Rytm.`, `/c/` `Produkty z kategorii <nazwa kategorii> w
  sklepie Rytm.`
- Adres kanoniczny (`<link rel="canonical">`), bezwzględny, na bazie `STORE_URL` (zmienna już ustawiona dla sklepu
  w compose):
  - `/c/<ścieżka>`: `<STORE_URL>/c/<ścieżka>`, z `?page=<n>`, gdy `n` jest większe niż 1;
  - `/`: `<STORE_URL>/`, z `?page=<n>`, gdy `n` jest większe niż 1;
  - pomija `page=1`, inne parametry i powtórzenia parametru (liczy się pierwsza wartość, jak w stronie);
  - `q` i `kategoria` (parametry `/szukaj`) są na `/` i `/c/` pomijane: nie trafiają do API ani do adresów.
- `robots: noindex`, gdy strona pokazuje „Nieprawidłowy filtr” (niepoprawny `page`, `category` na `/`, nieznana wartość
  `sort` albo `dostepne`, wartość odrzucona przez API) albo pustą stronę listy poza zakresem (`page` większe niż 1
  i brak produktów). Pozostałe strony bez dyrektywy `robots`. Kanoniczny adres „Nieprawidłowego filtra” na `/c/` to
  adres kategorii bez błędnego `page`.
- Filtry i sortowanie: którykolwiek niepusty parametr `sort`, `cena-od`, `cena-do`, `dostepne` albo `o` (wartość osi,
  powtarzany) daje `robots: noindex, follow` i adres kanoniczny listingu bez nich i bez `page` (`<STORE_URL>/` albo
  `<STORE_URL>/c/<ścieżka>`), także na stronie dalszej niż pierwsza i przy pustym wyniku. Sam `page` (i pominięte `q`,
  `kategoria`) nie zmienia indeksowania. Pusty parametr (formularz bez JavaScriptu wysyła każde pole) liczy się jak brak.
- `/szukaj`: zawsze `robots: noindex, follow` i bez adresu kanonicznego (także przy zbyt krótkiej frazie i po
  nieprawidłowym filtrze, który ma sam `noindex`). Tytuł `Wyniki dla „<fraza>” | Rytm` (z ` – strona <n>` po stronie 1;
  fraza przycięta i do 100 znaków), bez frazy albo przy frazie krótszej niż 2 znaki `Wyszukiwanie | Rytm`, przy
  nieprawidłowym filtrze `Nieprawidłowy filtr | Rytm`. Opis `Wyniki wyszukiwania w sklepie Rytm.`
- Nieznana ścieżka `/c/…` i nieznana kategoria: 404 z Next.js (z `noindex`).
- JSON-LD `BreadcrumbList` na `/c/<ścieżka>`: „Wszystkie produkty” i kategorie ścieżki z bezwzględnymi adresami
  `/c/…` (jak na stronie produktu).
- Metadane nie dokładają żądań do API: jedno wejście na stronę wykonuje po jednym żądaniu na endpoint (drzewo
  kategorii, lista produktów, oferty).

## Kryteria akceptacji

1. `/` ma tytuł `Wszystkie produkty | Rytm` i kanoniczny `<STORE_URL>/`.
2. `/c/bieganie/buty?page=2&utm_source=x` ma tytuł `Buty biegowe – strona 2 | Rytm` i kanoniczny
   `<STORE_URL>/c/bieganie/buty?page=2`.
3. `/c/bieganie/buty?page=1` ma kanoniczny `<STORE_URL>/c/bieganie/buty`.
4. Nieprawidłowy filtr i pusta strona poza zakresem mają `noindex`.
5. Wejście na stronę katalogu wykonuje po jednym żądaniu `GET /api/v1/categories` i `GET /api/v1/products`.
6. `/c/<ścieżka>` ma JSON-LD `BreadcrumbList`.
7. Przechodzą `make -C apps/store check` i `make -C apps/store test`.
8. `/c/bieganie?sort=cena-rosnaco&page=2` i `/?o=Kolor%3Aczarne` mają `noindex, follow` i kanoniczny `<STORE_URL>/c/bieganie`
   i `<STORE_URL>/`; `/c/bieganie?page=2` nie ma `robots`.
9. `/szukaj?q=kurtki` ma `noindex, follow` i nie ma adresu kanonicznego.

## Zakres i ograniczenia

- Bez Open Graph strony katalogu, sitemap i `robots.txt` (osobne zadania SEO).
- Bez nowych zależności i nowych zmiennych środowiskowych.
