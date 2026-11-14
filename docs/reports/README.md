# Raporty

Raporty z przeglądów i pomiarów, każdy przypięty do rewizji kodu. Raport opisuje wynik na konkretnej rewizji i po
zapisaniu się nie zmienia; kolejny pomiar albo przegląd to nowy plik (z datą w nazwie), a nie edycja poprzedniego.
Surowe wyniki pomiarów zostają poza repozytorium.

| Raport | Zakres | Rewizja |
|---|---|---|
| [ao-run-admin-design-2026-11-13](ao-run-admin-design-2026-11-13.md) | czas przebiegu AO epiku ADMIN-DESIGN: próby, fazy, największe straty czasu i propozycje przyspieszenia | `53bd073` |
| [load-test-2026-11-10](load-test-2026-11-10.md) | pomiar obciążeniowy na klastrze k3d z obrazami produkcyjnymi (`make -C infra/k8s load`): przepustowość odczytu i koszyka do nasycenia oraz checkout przy ograniczonej równoległości | `43f2b15` |
| [security-review-2026-11-10](security-review-2026-11-10.md) | przegląd bezpieczeństwa sklepu, API i panelu według listy 11 punktów (limity i adres klienta, walidacja, CORS, uprawnienia, upload, nagłówki i CSRF, sekrety, ekspozycja `prod`, zależności, błędy i przekierowania, nadużycia stanów i koszyków), z blokerami publicznego demo i propozycjami zgłoszeń | `5f2d553` |
