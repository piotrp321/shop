---
name: planner
description: Podział zatwierdzonej specyfikacji epica na zadania dla /delivery (plan.md z walidowanym manifestem JSON).
argument-hint: "[ścieżka spec.md]"
model: opus
effort: high
disable-model-invocation: true
---

Podziel zatwierdzoną specyfikację z $ARGUMENTS na zadania, z których każde `/delivery` zrealizuje jako jeden PR.
Wynik to `plan.md` w katalogu specyfikacji, z sekcją „Status” i manifestem zadań w jednym bloku ```` ```json ````.

## Granice odpowiedzialności

- **Wejście:** `spec.md` zapisana przez `/brainstorm` (albo inna zaakceptowana specyfikacja), `CLAUDE.md`, kod.
- **Wyjście:** `docs/features/<epic>/plan.md`. Manifest jest jedyną definicją zadań; nie powtarzaj go prozą.
- **Nie robisz:** projektu ani zmian specyfikacji (to `/brainstorm`), kroków implementacji, kodu i testów (to
  `/delivery` albo `superpowers:writing-plans` przy pracy ręcznej), zgłaszania zadań do AO, push, PR ani merge.
- Gdy specyfikacja ma otwarte decyzje, sprzeczności albo brakuje w niej zachowania potrzebnego do podziału,
  zatrzymaj się: wypisz braki z rekomendacją i zaproponuj `/brainstorm`. Nie rozstrzygaj ich sam.

## Proces

1. Przeczytaj specyfikację, `CLAUDE.md` (główny i aplikacji) i kod, którego dotyczy. Nie pytaj o fakty z repo.
2. Podziel na zadania:
   - Zadanie = jedna logiczna, weryfikowalna zmiana i jeden PR, po którym przechodzą `check` i testy dotkniętych
     aplikacji. Preferuj pionowe plastry (API z ekranem, który go używa) zamiast warstw bez wartości.
   - Najmniej zadań, które dają sensowne PR. Nie dziel tylko dla równoległości ani nie łącz niezależnych zmian.
   - `depends_on` tylko przy rzeczywistej zależności: zadanie używa kodu, kontraktu, migracji lub danych innego.
     Zadanie zależne rusza dopiero po integracji (merge) swoich zależności.
   - Zmiany dokumentacji, które specyfikacja wpisała jako zadania (`docs/vision.md`, `CLAUDE.md` i inne), przypisz
     do zadania, które zmienia opisane zachowanie.
3. Dla każdego zadania:
   - `scope`: aplikacje i moduły, kontrakty (endpointy, schematy OpenAPI, zdarzenia, migracje), czego zadanie nie
     obejmuje, gdy granica nie jest oczywista; z odwołaniem do sekcji specyfikacji.
   - `acceptance_criteria`: sprawdzalne kryteria wzięte ze specyfikacji, bez nowych wymagań.
   - `risk` według `/delivery`: `FAST` (lokalna zmiana niskiego ryzyka), `STANDARD` (funkcjonalność, API,
     integracja), `CRITICAL` (auth, pieniądze, migracje danych, transakcje, współbieżność, bezpieczeństwo).
4. Kontrola przed przedstawieniem: każde kryterium akceptacji i każde zadanie dokumentacji ze specyfikacji jest
   w dokładnie jednym zadaniu (albo jawnie rozdzielone między kilka); żadne zadanie nie wnosi zakresu spoza
   specyfikacji.
5. Nie wymyślaj decyzji biznesowych. Gdy podział zależy od decyzji użytkownika (np. co wchodzi w pierwszą falę,
   kolejność wydania), zadaj wszystkie takie pytania w jednej wiadomości, każde z rekomendacją. Odpowiedzi nie są
   akceptacją planu.
6. Zapisz `plan.md` według wzoru i uruchom walidator:

   ```bash
   python3 .claude/skills/planner/plan_manifest.py validate docs/features/<epic>/plan.md
   ```

   Popraw każdy zgłoszony problem. Wyjście zawiera `waves`: fala 1 to zadania bez zależności.
7. Przedstaw plan zwięźle: tabela fal i zadań (ID, tytuł, ryzyko, zależności), pokrycie specyfikacji, ryzyka
   podziału. Poczekaj na jedną, jednoznaczną akceptację całego planu.
8. Po akceptacji uruchom walidator jeszcze raz i zacommituj tylko `plan.md`
   (`docs(<epic>): add the task plan`). Bez akceptacji nie commituj. Zmiana planu po akceptacji wymaga ponownej
   akceptacji.

## Manifest

Wzór `plan.md`:

````markdown
# <Epic> – plan zadań

Specyfikacja: `docs/features/<epic>/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | <ID>, <ID> | |
| 2 | <ID> | |

Stan prób zadań zgłoszonych do AO: `ao status`.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "<EPIC>",
  "spec": "docs/features/<epic>/spec.md",
  "tasks": [
    {
      "id": "<EPIC>-1",
      "title": "...",
      "scope": "...",
      "acceptance_criteria": ["..."],
      "risk": "FAST | STANDARD | CRITICAL",
      "depends_on": []
    }
  ]
}
```
````

- `epic` i `id` w formacie `[A-Za-z0-9][A-Za-z0-9._-]*`; `id` zaczyna się od `<EPIC>-` i jest unikalne. ID staje
  się ID zadania w AO i nazwą gałęzi.
- `spec` to ścieżka względem katalogu głównego repo. Wszystkie pola są wymagane, innych pól nie ma.
- `depends_on` wskazuje istniejące zadania, bez cykli i bez zależności od siebie.

Pisz zwięźle po polsku.
