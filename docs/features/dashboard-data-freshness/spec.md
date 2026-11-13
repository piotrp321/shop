# Świeżość danych dashboardu panelu

Status: zaimplementowana. Ryzyko: FAST.

## Problem

Ekrany panelu z danymi z `GET /api/v1/admin/dashboard` (`apps/admin`, `useDashboard`: pulpit `DashboardView.vue`
i „Stan systemu” `SystemView.vue`) odświeżają dane co 30 s. Gdy odświeżenie się nie uda, zostają poprzednie dane
i komunikat błędu, ale pracownik nie wie, z której chwili są dane, więc „zielony” stan usług może być nieaktualny.
Ekrany odpytują też API co 30 s w karcie, której nikt nie ogląda, a każde zapytanie uruchamia sondy usług (Postgres,
Elasticsearch, RabbitMQ i inne).

## Zachowanie

Tylko panel (`apps/admin`). Bez zmian w API, kliencie TS i sklepie. Dotyczy pulpitu (kafle katalogu i karta
„Ostatnie zamówienia”, odświeżana razem z nimi, ze stanem w swojej karcie; jej błąd też podaje czas pokazanych zamówień) oraz ekranu „Stan systemu”
(`/system`), każdy z własnym `useDashboard` i tym samym `FreshnessBar`.

- Obok przycisku „Odśwież” widać czas ostatniego udanego pobrania danych: `Dane z <data, GG:MM:SS>` w czasie polskim
  niezależnie od strefy przeglądarki (`formatDateTime`, np. `Dane z 01.11.2026, 14:03:12`), kolorem `muted-foreground`,
  w regionie `aria-live="polite"`. Przed pierwszym udanym pobraniem napisu nie ma.
- Gdy ostatnie odświeżenie się nie udało, a na ekranie są wcześniejsze dane, komunikat błędu (`role="alert"`) mówi,
  z której chwili są pokazane dane, np. `… Pokazane dane z 01.11.2026, 14:03:12 mogą być nieaktualne.` Bez
  wcześniejszych danych komunikat zostaje jak dziś.
- Odświeżanie co 30 s nie wysyła żądań, gdy karta jest ukryta (`document.visibilityState === "hidden"`).
  Po powrocie do karty dashboard odświeża się od razu, jeśli od ostatniego pobrania minęło co najmniej 30 s,
  i dalej co 30 s.
- Ręczne „Odśwież” działa jak dziś. Zasada „tylko najnowsze żądanie zmienia stan” zostaje.
- Po opuszczeniu dashboardu nie zostają timery ani nasłuch zdarzeń.

## Kryteria akceptacji

1. Po udanym pobraniu widać `Dane z <data, GG:MM:SS>` z czasem tego pobrania (czas polski).
2. Nieudane odświeżenie przy wcześniejszych danych: dane zostają, błąd podaje ich czas.
3. W ukrytej karcie kolejne interwały nie wywołują `GET /api/v1/admin/dashboard`.
4. Powrót do karty po co najmniej 30 s wywołuje jedno odświeżenie; po krótszym nie.
5. Odmontowanie widoku usuwa timer i nasłuch `visibilitychange`.
6. Przechodzą `make -C apps/admin check` i `make -C apps/admin test`.

## Zakres i ograniczenia

- Bez zmian w zawartości kafli katalogu i ekranu „Stan systemu”.
- Bez nowych zależności.
