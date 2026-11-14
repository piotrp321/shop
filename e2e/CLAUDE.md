# CLAUDE.md – e2e

Testy end-to-end (Playwright) i pomiar Lighthouse na pełnym stosie. Cele są w `make/e2e.mk` (opis przy celu), tryb
sklepu (`STORE_MODE`) opisuje główny `CLAUDE.md`.

## Cele

- `make e2e`: testy Playwright na działającym stosie (`make up` i `make seed` przed nimi). `E2E_SHARD=<i>/<n>` (np.
  `1/2`) uruchamia tylko i-tą z n części zestawu: całe pliki spec w kolejności plików, podział według liczby testów
  (Playwright `--shard`). `E2E_SHARD_WEIGHTS=41:59` (po jednej wadze na część, te same w każdej części) przesuwa
  granicę; to nieudokumentowana zmienna `PWTEST_SHARD_WEIGHTS` Playwrighta, a bez niej podział jest równy i każdy test
  nadal biegnie dokładnie raz.
- `make e2e-check`: sprawdzenie typów pakietu `e2e` i testy skryptu median Lighthouse, bez działających usług.
- `make e2e-agent AGENT_DIR=<katalog> [E2E_ARGS=...]`: skrypty agentów tester i UX, wyniki poza repo.
- `make lighthouse`: pomiar Lighthouse trzech stron sklepu: `/`, `/c/turystyka/biwak/namioty`
  i `/p/namiot-turystyczny-norvane-biwak-2`, po 3 przebiegi w profilu mobilnym, w kontenerze `e2e`. Wymaga stosu
  ze sklepem w trybie produkcyjnym, `STORE_MODE=production make up` i `make seed`, a w innym trybie albo przy
  zatrzymanym sklepie kończy się komunikatem, bez pomiaru. Raporty, `manifest.json` i `medians.md` z medianami
  SEO, Performance, LCP, CLS i TBT są w `e2e/lighthouse-results/`. Blokuje tylko wynik SEO < 0,9 mediany adresu,
  pozostałe progi ostrzegają.

## CI

- Zestaw biegnie w dwóch jobach `e2e shard 1` i `e2e shard 2` (macierz `e2e-shard` w `ci.yml`), każdy na własnym
  runnerze z własnym, świeżo zasianym stosem, bo testy dzielą stan jednego stosu i działają po kolei (`workers: 1`).
  Część to całe pliki spec: test nie może zależeć od stanu, który zostawia inny plik, bo ten może być w drugiej części.
  Granica (`E2E_SHARD_WEIGHTS` w kroku `make e2e`) leży po ok. 41% testów, bo pierwsze pliki, przede wszystkim
  `admin-design`, są wolniejsze na test, a druga część robi jeszcze sprawdzenia po testach. Po dodaniu testów przesuń ją,
  żeby obie części kończyły się mniej więcej razem.
- Wymagany check `e2e` to osobny, lekki job, który jest zielony tylko wtedy, gdy zakończył się sukcesem job `scope`
  i obie części (także po błędzie albo anulowaniu jednej z nich czerwony, nie pominięty). Części nie nazywają się `e2e`.
- Część z `after_e2e` (druga) po `make e2e` uruchamia `make ci-after-e2e`: każdy cel, który fragment `make/*.mk` dopisuje
  do listy `CI_AFTER_E2E` (Lighthouse, trasy robots i sitemap, `make load-smoke`, testy `init-env.sh`). Nowe sprawdzenie
  na stosie trafia do swojego fragmentu, nie do `ci.yml`. Wszystkie cele listy działają także po błędzie jednego
  z nich (niespełniona asercja Lighthouse nie pomija pozostałych), a te, które się nie udały, są wypisane na końcu
  i robią czerwony krok. Kolejność to kolejność plików fragmentów, więc żaden cel listy nie może od niej zależeć.
- Ta część wysyła `e2e/lighthouse-results` jako artefakt `lighthouse-results` także po błędzie i dopisuje `medians.md`
  do podsumowania kroku (bez nowego checka: niespełniony wynik SEO albo błąd pomiaru robi czerwony job `e2e shard 2`,
  a przez niego wymagany `e2e`). Ślady Playwrighta po błędzie to artefakty `playwright-test-results-<część>`.
