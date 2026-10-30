# Eksperymenty z agentami

Raporty z niezależnych audytów wykonanych agentami z `.claude/agents/`. Są bazą do porównań kolejnych audytów
(te same agenty na innym kodzie, zmienione definicje agentów na tym samym kodzie). Raport opisuje wynik na
konkretnej rewizji i po zapisaniu się nie zmienia.

| Raport | Zakres | Rewizja |
|---|---|---|
| [QA-001](qa-agent-001.md) | uwierzytelnianie | `660f3f9` |
| [QA-002](qa-agent-002.md) | Transactional Outbox (Task 14) | `660f3f9` |
| [QA-002/M3](qa-agent-002-m3.md) | weryfikacja zgłoszenia M3 z QA-002 przez orkiestratora | `660f3f9` |

## Protokół

1. Ustal rewizję i zakres. Drzewo musi być czyste.
2. Wyeksportuj rewizję bez wcześniejszych raportów do katalogu poza repozytorium:

   ```bash
   git archive <rewizja> | tar -x -C <katalog> --exclude='docs/experiments'
   ```

   Eksport nie ma `.git`, więc agent nie odczyta raportów z historii (`git show`, `git log -p`). Do CI agent może
   użyć `gh … -R <owner>/<repo>`.
3. Uruchom agenty równolegle, w tle, na eksporcie. Każdy dostaje to samo polecenie co w QA-001 (różni się tylko
   zakres, ścieżka i katalog na pliki tymczasowe). Bez wcześniejszych review, wyników drugiego agenta i wskazówek,
   czego szukać.
4. Agenty nie zmieniają repozytorium ani działającego stosu. Usługi do testów stawiają w osobnej sieci Docker
   i usuwają po audycie.
5. Orkiestrator weryfikuje zgłoszenia (powtarza komendy i PoC, czyta cytowany kod) i zapisuje raport
   `qa-agent-<nnn>.md`: warunki, zużycie (tokeny, narzędzia, czas), środowisko i ograniczenia, wyniki testów,
   zgłoszenia potwierdzone i przypuszczenia, porównanie agentów, zakres własnej weryfikacji.
6. Raport trafia do repozytorium osobnym commitem `docs:`, bez zmian w kodzie i definicjach agentów.
