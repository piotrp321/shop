# ASH-DASHBOARD: plan

Specyfikacja: `docs/features/ash-dashboard/spec.md`. Zakres zaakceptował właściciel w zleceniu (2026-11-21). Kod jest
w repo `piotrp321/agent-observability` (gałąź `feat/ash-native-epics`), w sklepie tylko dokumentacja. Ryzyko: FAST
(lokalne narzędzie tylko do odczytu, bez zmian aplikacji, procesu ASH i `tools/metrics`).

## Zadania

1. **Eksporter rekordów** (`exporter/ash_epics_exporter.py`, TDD): rekordy natywne i pliki przebiegu jako wskaźniki
   Prometheusa; `null` jako `NaN`; pominięcie AO; błąd pliku widoczny i lokalny; bez ścieżek katalogów.
2. **Stos:** usługa `epics-exporter` w Compose (katalog stanu tylko do odczytu), odczyt przez Prometheusa co 30 s,
   `make test`, `make dashboards`, kontrola eksportera w `make check` i `make status`.
3. **Dashboard** „ASH: epiki natywne” po polsku (generator `grafana/build_dashboards.py`), strona startowa Grafany;
   dashboard surowej telemetrii zostaje jako diagnostyka.
4. **Reset i import:** jedna kopia wolumenów (`make backup`), `docker compose down -v`, start nowej konfiguracji,
   odtworzenie rekordów epików natywnych komendą `metrics.py epic <epik>`.
5. **Weryfikacja:** porównanie wartości z rekordami, ponowne wczytanie, korekta rekordu, zepsuty rekord, restart
   stosu, wygląd w przeglądarce; przegląd całej zmiany w świeżym kontekście.

## Status

- Zadania 1–3 i 5 wykonane na próbnym stosie (osobny projekt Compose z kopią rekordów).
- Zadanie 4 czeka na udostępnienie katalogu `~/.local/state/shop-metrics` w Docker Desktop (File Sharing): bez tego
  usługa `epics-exporter` prawdziwego stosu nie wystartuje.
- Po merge'u obu PR-ów: przełączyć checkout `agent-observability` na `main` (`git switch main && git pull`,
  `make up`) i usunąć ten plan.
