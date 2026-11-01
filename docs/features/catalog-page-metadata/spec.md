# Metadane strony katalogu

Status: zaakceptowana do realizacji (task `CATALOG-PAGE-METADATA`). Ryzyko: FAST.

## Problem

Strona katalogu sklepu (`apps/store/app/page.tsx`) ma dla każdego adresu te same metadane z layoutu: tytuł „Rytm”
i opis „Sklep internetowy Rytm”. Wyszukiwarka nie odróżnia kategorii po tytule, ten sam listing jest dostępny pod
wieloma adresami (`?page=1`, dodatkowe parametry), a strona „Nieprawidłowy filtr” może trafić do indeksu.

## Zachowanie

Tylko sklep (`apps/store`). Bez zmian w API, kliencie TS i panelu.

- Tytuł strony (`<title>`):
  - bez kategorii: `Wszystkie produkty | Rytm`;
  - z kategorią: `<nazwa kategorii> | Rytm` (nazwa z drzewa kategorii, które strona już pobiera);
  - strona listy większa niż 1: przed ` | Rytm` dochodzi ` – strona <n>`, np. `Buty – strona 2 | Rytm`.
- Opis (`description`): bez kategorii `Wszystkie produkty w sklepie Rytm.`, z kategorią
  `Produkty z kategorii <nazwa kategorii> w sklepie Rytm.`
- Adres kanoniczny (`<link rel="canonical">`), bezwzględny, na bazie `STORE_URL` (zmienna już ustawiona dla sklepu
  w compose):
  - zawiera tylko `category` (gdy jest) i `page` (gdy większe niż 1), w tej kolejności;
  - pomija `page=1`, inne parametry i powtórzenia parametru (liczy się pierwsza wartość, jak w stronie).
- `robots: noindex`, gdy strona pokazuje „Nieprawidłowy filtr” albo pustą stronę listy poza zakresem
  (`page` większe niż 1 i brak produktów). Pozostałe strony bez dyrektywy `robots`.
- Nieznana kategoria: bez zmian (404 z Next.js).
- Metadane nie dokładają żądań do API: jedno wejście na stronę wykonuje te same żądania co dziś
  (drzewo kategorii i lista produktów, po jednym).

## Kryteria akceptacji

1. `/` ma tytuł `Wszystkie produkty | Rytm` i kanoniczny `<STORE_URL>/`.
2. `/?category=<id>&page=2&utm_source=x` ma tytuł `<nazwa> – strona 2 | Rytm` i kanoniczny
   `<STORE_URL>/?category=<id>&page=2`.
3. `/?category=<id>&page=1` ma kanoniczny `<STORE_URL>/?category=<id>`.
4. Nieprawidłowy filtr i pusta strona poza zakresem mają `noindex`.
5. Wejście na stronę katalogu wykonuje po jednym żądaniu `GET /api/v1/categories` i `GET /api/v1/products`.
6. Przechodzą `make -C apps/store check` i `make -C apps/store test`.

## Zakres i ograniczenia

- Bez JSON-LD, Open Graph, sitemap, `robots.txt` i adresów `/c/<slug>` (osobne zadania SEO).
- Bez nowych zależności i nowych zmiennych środowiskowych.
