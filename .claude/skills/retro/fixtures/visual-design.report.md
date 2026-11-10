# Raport epika visual-design

- Status: DONE
- Gałąź epika: `epic/visual-design`, start: `015c52b93bc4`, plan: `015c52b93bc4`
- Końcowy PR: https://github.com/piotrp321/shop/pull/366, CI: success
- Rundy QA: 5
- Czas: od 2026-11-09 12:19:58 do 2026-11-10 03:16:40, razem 14h 56m 42s, w tym praca wykonawcy 12h 41m 36s; aktywnie 14h 56m 42s (przerwy hosta 0h 00m 00s, cooldown limitu 0h 00m 00s)
- Próby: 23, w tym 1 nieudanych
- Interwencje człowieka: 7
- Interwencje operacyjne: 0 (zmiany konfiguracji: 0, edycje pliku stanu: 0)

Znaleziska Critical i Important rundy 1:
- F1 [Important] nagłówek desktopowy od 1024 px nie mieści się w jednym wierszu (apps/store/src/catalog/SiteHeader.tsx, apps/store/src/catalog/MegaMenu.tsx, apps/store/src/account/AccountMenu.tsx), zadanie VISUAL-DESIGN-5
- F7 [Important] kolejność tabulatora w nagłówku na desktopie zależy od reading-flow (apps/store/src/catalog/SiteHeader.tsx, apps/store/src/cart/CartBadge.tsx, apps/store/CLAUDE.md, docs/features/visual-design/spec.md, docs/features/visual-design/mockups/kierunek.html, e2e/tests/visual-foundation.spec.ts), zadanie VISUAL-DESIGN-5
- F8 [Important] kolejność tabulatora w galerii na desktopie zależy od reading-flow (apps/store/src/catalog/ProductGallery.tsx, e2e/tests/product-gallery.spec.ts, docs/features/visual-design/spec.md), zadanie VISUAL-DESIGN-8

Znaleziska Critical i Important rundy 2:
- F9 [Important] Po wysłaniu z kompaktowej wyszukiwarki fokus nie wraca na przycisk „Wyszukiwarka” (apps/store/src/catalog/SearchBox.tsx), zadanie VISUAL-DESIGN-5

### Minor (tylko w raporcie, bez napraw)

- F2 „Do koszyka” na kafelku ma 36 px zamiast 44 px przycisku głównego (apps/store/src/catalog/TileOffer.tsx), runda 1
- F3 nieużywany komponent checkbox.tsx (apps/store/src/components/ui/checkbox.tsx), runda 1
- F4 blok ceny kafelka zdublowany w ProductTile i TileOffer (apps/store/src/catalog/ProductTile.tsx, apps/store/src/catalog/TileOffer.tsx), runda 1
- F5 rama strony 404 i strony błędu skopiowana (apps/store/app/not-found.tsx, apps/store/app/error.tsx), runda 1
- F6 kryterium 10 opisuje gest Input.synthesizeScrollGesture, a test używa Input.dispatchTouchEvent (docs/features/visual-design/spec.md), runda 1
- F10 „Menu” i koszyk nachodzą na siebie na 320 px po zalogowaniu (apps/store/src/catalog/SiteHeader.tsx), runda 2
- F11 Panel działu i panel wyszukiwarki mogą być otwarte naraz (apps/store/src/catalog/MegaMenu.tsx, apps/store/src/catalog/SearchBox.tsx), runda 2
- F3 Nieużywany komponent `checkbox.tsx` (apps/store/src/components/ui/checkbox.tsx), runda 2
- F4 Blok ceny kafelka zdublowany w `ProductTile` i `TileOffer` (apps/store/src/catalog/ProductTile.tsx, apps/store/src/catalog/TileOffer.tsx), runda 2
- F6 Kryterium 10 opisuje gest `Input.synthesizeScrollGesture`, a test używa `Input.dispatchTouchEvent` (docs/features/visual-design/spec.md), runda 2
- F12 Nieaktualny znacznik wysłania przenosi fokus na przycisk po przerwanej nawigacji (apps/store/src/catalog/SearchBox.tsx), runda 3
- F13 Negatywne testy E2E znacznika nie wykrywają usunięcia zabezpieczeń (e2e/tests/visual-foundation.spec.ts), runda 3
- F14 Specyfikacja rozszerza porównanie ze zrzutami makiety na wszystkie epiki z frontem (docs/features/visual-design/spec.md), runda 4
- F15 Specyfikacja i dokumenty sklepu odwołują się do zadań i stanu sprzed zmiany (apps/store/CLAUDE.md, docs/features/visual-design/mockups/README.md, docs/features/visual-design/spec.md), runda 4
- F16 Przeciągnięcie palcem zaczęte na strzałce galerii nie zmienia zdjęcia (apps/store/src/catalog/ProductGallery.tsx), runda 4
- F17 Zmiana szerokości okna przesuwa galerię na inne zdjęcie (apps/store/src/catalog/use-photo-strip.ts), runda 4

