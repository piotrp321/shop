# CLAUDE.md – e2e

Testy end-to-end (Playwright) i pomiar Lighthouse na pełnym stosie. Cele są w `make/e2e.mk` (opis przy celu), tryb
sklepu (`STORE_MODE`) opisuje sekcja „Tryb sklepu” niżej.

## Tryb sklepu

- Tryb sklepu wybiera zmienna `STORE_MODE` dla `make up`: `dev` (domyślnie) uruchamia `next dev`, `production` robi
  `next build` i uruchamia `next start`. Inna wartość zatrzymuje kontener sklepu, a nieudany build też, więc `make up`
  kończy się błędem. Tryb podaje się przy każdym `make up` (`STORE_MODE=production make up`); bez zmiennej
  sklep wraca do `dev`. Powtórzone `STORE_MODE=production make up` nie przebuduje działającego sklepu (compose
  odtwarza kontener tylko przy zmianie konfiguracji): po zmianie kodu użyj `docker compose restart store`, który
  uruchamia `next build` od nowa w tym samym trybie. Kontener `e2e` czyta `STORE_MODE` ze środowiska `make e2e`,
  nie z działającego sklepu, więc ten sam tryb podaj też tam (`STORE_MODE=production make e2e`); bez tego testy
  tylko produkcyjne są pomijane, a asercje ISR nie przechodzą. Lokalnie E2E działa domyślnie na `next dev`;
  E2E w CI działa na buildzie produkcyjnym (`STORE_MODE=production`), bo tylko on ma cache ISR.

## CI

- Zestaw biegnie w trzech jobach `e2e shard 1`…`3` (macierz `e2e-shard` w `ci.yml`), każdy na własnym
  runnerze z własnym, świeżo zasianym stosem, bo testy dzielą stan jednego stosu i działają po kolei (`workers: 1`).
  Część to całe pliki spec: test nie może zależeć od stanu, który zostawia inny plik, bo ten może być w innej części.
  Granice części (`E2E_SHARD_WEIGHTS` w kroku `make e2e`; obecny podział opisuje komentarz tego kroku) leżą w środku
  pliku, więc kilka dodanych testów nie przesuwa podziału. Po dodaniu testów przesuń granice, żeby wszystkie części
  kończyły się mniej więcej razem (podział sprawdzisz bez stosu:
  `docker compose --profile e2e run --rm --no-deps -e PWTEST_SHARD_WEIGHTS=<wagi> --entrypoint npx e2e playwright test --list --shard=<i>/3`).
- Wymagany check `e2e` to osobny, lekki job, który jest zielony tylko wtedy, gdy zakończył się sukcesem job `scope`
  i wszystkie części (także po błędzie albo anulowaniu jednej z nich czerwony, nie pominięty). Części nie nazywają się `e2e`.
- Część z `after_e2e` (trzecia) po `make e2e` uruchamia `make ci-after-e2e`: każdy cel, który fragment `make/*.mk` dopisuje
  do listy `CI_AFTER_E2E` (Lighthouse, trasy robots i sitemap, `make load-smoke`, testy `init-env.sh`). Nowe sprawdzenie
  na stosie trafia do swojego fragmentu, nie do `ci.yml`. Kolejność to kolejność plików fragmentów, więc żaden cel listy
  nie może od niej zależeć.
- Lighthouse nie ma osobnego checka: niespełniony wynik SEO albo błąd pomiaru robi czerwony job `e2e shard 3`, a przez
  niego wymagany `e2e`.
