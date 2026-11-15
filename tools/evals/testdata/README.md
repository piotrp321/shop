# Dane testowe oceny

`reports/<agent>/*.md`: prawdziwe raporty agentów `code-reviewer` (z logów prób AO) i `spec-critic` (z sesji
`/prepare-epic`), oczyszczone przed trafieniem do repozytorium (ścieżki domowe jako `<home>`, bez adresów e-mail,
tokenów i identyfikatorów sesji). Testy parsera raportów (`test_reports.py`) sprawdzają na nich dopasowanie
i brak dopasowania znalezisk. Pliki są danymi: nie zmieniamy ich treści. `evals.py lint` skanuje je tymi samymi wzorcami co dane przypadków
i historię (ścieżki domowe, adresy e-mail, tokeny), a przed dodaniem raportu trzeba go oczyścić tak jak pakiet fixture'ów.
