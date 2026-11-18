# Materiały audytu wydajności z 2026-11-18

Załączniki raportu [`../performance-audit-2026-11-18.md`](../performance-audit-2026-11-18.md). Rewizja aplikacji:
`1aeb507632ab4db4f79b97b88f1b5b9c744245f6`. Jak wszystkie raporty w `docs/reports`, po zapisaniu się nie zmieniają.

| Katalog albo plik | Zawartość |
|---|---|
| [`research.md`](research.md) | raport z researchu dokumentacji i dobrych praktyk używanych technologii, ze źródłami i z oznaczeniem klasy każdego twierdzenia (dokumentacja pierwotna, blog albo producent, wniosek) |
| [`findings/`](findings/) | cztery raporty analizy statycznej (backend, PostgreSQL, fronty, infrastruktura z cache i wyszukiwarką), napisane przed pomiarami przez niezależnych audytorów; hipotezy, których pomiar nie potwierdził, koryguje raport główny |
| [`results/`](results/) | wybrane surowe wyniki: `bench.jsonl` (każdy przebieg k6 z czasem CPU kontenerów, statystykami SQL i próbką obcego obciążenia maszyny), plany zapytań, rozbicie zapytania Elasticsearch, przebiegi kolejek, dwa przebiegi do nasycenia, mediany Lighthouse, odczyty jednorazowe |
| [`scripts/`](scripts/) | skrypty pomiarowe w postaci, w jakiej były użyte; jedyna zmiana to zmienna `SHOP_ROOT` w miejscu ścieżki lokalnej |

## Czego tu nie ma

- danych uwierzytelniających: pliku zmiennych kontenera `api`, tokenu panelu, list adresów z nagłówkiem `Authorization`;
- danych testowych i wolumenów (zbiór `medium` odtwarza `make seed-scale PROFILE=medium`);
- raportów HTML i JSON Lighthouse, logów generatora i notatek roboczych researchu.

## Uruchamianie skryptów

Skrypty zapisują `targets/` i `results/` w katalogu nad `scripts/`, więc trzeba je skopiować do katalogu roboczego
poza repozytorium. Zakładają stos compose o nazwie projektu `shop-perfaudit` z portami 52700–52706 i kontenery API
z obrazu produkcyjnego o nazwach `shop-perfaudit-api-prod`, `-prodw` i `-prodp`. Kolejność poleceń jest w sekcji 12
raportu.

| Skrypt | Rola |
|---|---|
| `gen_targets.py` | buduje listy adresów do pomiaru z danych działającego stosu (publiczne API, panel, strony sklepu, warianty do zakupu) |
| `bench.py`, `gen.js` | jeden przebieg k6 z odczytem czasu CPU kontenerów, `pg_stat_statements` i nowych sesji Postgresa; wynik do `results/bench.jsonl` |
| `sweep.sh`, `fmt.py` | seria przebiegów po scenariuszach i skrócony wydruk |
| `checkout.js` | koszyk gościa i złożenie zamówienia prosto w API, z opcją jednego gorącego wariantu |
| `prices.js`, `drain.py` | zmiany cen przez API panelu oraz próbkowanie outboxa i kolejki `events` |
| `explain.sql` | zestaw `EXPLAIN (ANALYZE, BUFFERS)` z parametrami dobieranymi z danych |
| `es_capture.sh`, `es_attrib.py` | przechwycenie zapytania Elasticsearch z własnego indeksu i rozbicie jego kosztu na części |
| `run_ramp.sh`, `ramp_stats.py` | przebieg do nasycenia projektowym `load/saturate.js` z próbkowaniem CPU kontenerów |
| `kc_token.sh` | testowe konto pracownika w lokalnym Keycloaku i token do `/api/v1/admin` (tylko własny stos lokalny) |
| `manual.sh` | odczyty jednorazowe zapisane w `results/manual-measurements.md` |
| `rest-of-generate.sh` | kroki `bin/generate-demo` po kroku kont, który na profilu `medium` kończy się błędem |
| `Caddyfile.worker`, `perf.override.yaml` | wariant worker mode FrankenPHP i lokalne nadpisanie compose na obraz produkcyjny |
