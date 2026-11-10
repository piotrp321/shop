# Raporty

Raporty z przeglądów i pomiarów, każdy przypięty do rewizji kodu. Raport opisuje wynik na konkretnej rewizji i po
zapisaniu się nie zmienia; kolejny pomiar albo przegląd to nowy plik (z datą w nazwie), a nie edycja poprzedniego.
Surowe wyniki pomiarów zostają poza repozytorium.

| Raport | Zakres | Rewizja |
|---|---|---|
| [load-test-2026-11-10](load-test-2026-11-10.md) | pomiar obciążeniowy na klastrze k3d z obrazami produkcyjnymi (`make -C infra/k8s load`): przepustowość odczytu i koszyka do nasycenia oraz checkout przy ograniczonej równoległości | `43f2b15` |
