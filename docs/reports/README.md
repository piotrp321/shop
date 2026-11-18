# Raporty

Raporty z przeglądów i pomiarów, każdy przypięty do rewizji kodu. Raport opisuje wynik na konkretnej rewizji i po
zapisaniu się nie zmienia; kolejny pomiar albo przegląd to nowy plik (z datą w nazwie), a nie edycja poprzedniego.
Surowe wyniki pomiarów zostają poza repozytorium.

| Raport | Zakres | Rewizja |
|---|---|---|
| [ao-run-admin-design-2026-11-13](ao-run-admin-design-2026-11-13.md) | czas przebiegu AO epiku ADMIN-DESIGN: próby, fazy, największe straty czasu i propozycje przyspieszenia | `53bd073` |
| [ao-performance-agent-security-sentry-2026-11-15](ao-performance-agent-security-sentry-2026-11-15.md) | czas, koszt i próby AO w epikach AGENT-SECURITY i SENTRY: wąskie gardła, ranking optymalizacji i kryteria oceny | `a964e94` |
| [load-test-2026-11-10](load-test-2026-11-10.md) | pomiar obciążeniowy na klastrze k3d z obrazami produkcyjnymi (`make -C infra/k8s load`): przepustowość odczytu i koszyka do nasycenia oraz checkout przy ograniczonej równoległości | `43f2b15` |
| [security-review-2026-11-10](security-review-2026-11-10.md) | przegląd bezpieczeństwa sklepu, API i panelu według listy 11 punktów (limity i adres klienta, walidacja, CORS, uprawnienia, upload, nagłówki i CSRF, sekrety, ekspozycja `prod`, zależności, błędy i przekierowania, nadużycia stanów i koszyków), z blokerami publicznego demo i propozycjami zgłoszeń | `5f2d553` |
| [evals-2-pilot-code-reviewer-2026-11-15](evals-2-pilot-code-reviewer-2026-11-15.md) | pilot EVALS 2.0: 15 przebiegów `code-reviewer` na 5 prawdziwych zadaniach, wykrywanie wad wzorcowych, fałszywe alarmy, zgłoszenia nierozstrzygnięte, jakość werdyktu, koszt i czas | `e30554f` |
| [evals-2-replay-code-reviewer-2026-11-16](evals-2-replay-code-reviewer-2026-11-16.md) | EVALS 2.0 w trybie odtworzenia prawdziwego review: 10 przebiegów `code-reviewer` na 5 zadaniach, wykrywanie wad wzorcowych, powtarzalność, dodatkowe cele z oryginalnych review, koszt i czas | `e30554f` |
| [evals-2-ab-code-reviewer-value-flow-2026-11-16](evals-2-ab-code-reviewer-value-flow-2026-11-16.md) | EVALS 2.0, eksperyment A/B: obecny `code-reviewer` kontra wariant z punktem „Przepływ wartości wejściowych” na 6 przypadkach; wykrywanie wad, jakość werdyktu, koszt, ocena kryteriów i rekomendacja (nie wdrażać) | `46cb253` |
| [perf-audit-admin-features-metrics-2026-11-17](perf-audit-admin-features-metrics-2026-11-17.md) | audyt wydajności epików ADMIN-FEATURES i METRICS: chronologia, rozkład czasu na kategorie, ranking strat, przyczyny źródłowe, stan wcześniejszych poprawek AO, rekomendacje z priorytetami i metryki poprawy | `0e82a93` |
| [app-audit-2026-11-18](app-audit-2026-11-18.md) | niezależny audyt aplikacji (bez narzędzi agentów): architektura, poprawność biznesowa, bezpieczeństwo, wydajność, jakość kodu, testy, gotowość produkcyjna, kompletność produktu i porównanie z Sylius, Shopware, Medusa i Saleor; błędy, ryzyka, zbędne komplikacje, priorytety i propozycje epików; raporty cząstkowe 12 audytorów w `app-audit-2026-11-18/` | `ef204620` |
