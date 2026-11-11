# CLAUDE.md – e2e

Testy end-to-end (Playwright) i pomiar Lighthouse na pełnym stosie. Cele są w `make/e2e.mk` (opis przy celu), tryb
sklepu (`STORE_MODE`) opisuje główny `CLAUDE.md`.

## Cele

- `make e2e`: testy Playwright na działającym stosie (`make up` i `make seed` przed nimi).
- `make e2e-check`: sprawdzenie typów pakietu `e2e` i testy skryptu median Lighthouse, bez działających usług.
- `make e2e-agent AGENT_DIR=<katalog> [E2E_ARGS=...]`: skrypty agentów tester i UX, wyniki poza repo.
- `make lighthouse`: pomiar Lighthouse trzech stron sklepu: `/`, `/c/turystyka/biwak/namioty`
  i `/p/namiot-turystyczny-norvane-biwak-2`, po 3 przebiegi w profilu mobilnym, w kontenerze `e2e`. Wymaga stosu
  ze sklepem w trybie produkcyjnym, `STORE_MODE=production make up` i `make seed`, a w innym trybie albo przy
  zatrzymanym sklepie kończy się komunikatem, bez pomiaru. Raporty, `manifest.json` i `medians.md` z medianami
  SEO, Performance, LCP, CLS i TBT są w `e2e/lighthouse-results/`. Blokuje tylko wynik SEO < 0,9 mediany adresu,
  pozostałe progi ostrzegają.

## CI

- Job `e2e` po `make e2e` uruchamia `make ci-after-e2e`: każdy cel, który fragment `make/*.mk` dopisuje do listy
  `CI_AFTER_E2E` (Lighthouse, trasy robots i sitemap, `make load-smoke`, testy `init-env.sh`). Nowe sprawdzenie
  na stosie trafia do swojego fragmentu, nie do `ci.yml`. Wszystkie cele listy działają także po błędzie jednego
  z nich (niespełniona asercja Lighthouse nie pomija pozostałych), a te, które się nie udały, są wypisane na końcu
  i robią czerwony krok. Kolejność to kolejność plików fragmentów, więc żaden cel listy nie może od niej zależeć.
- Job wysyła `e2e/lighthouse-results` jako artefakt `lighthouse-results` także po błędzie i dopisuje `medians.md`
  do podsumowania kroku (bez nowego joba i checka: niespełniony wynik SEO albo błąd pomiaru robi czerwony job `e2e`).
