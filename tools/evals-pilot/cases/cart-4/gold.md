Źródło: zgłoszenie #81.
Pliki: `apps/store/src/cart/CartPage.tsx`.
Mechanizm: w `change()` gałąź `not_found` po ponownym wczytaniu koszyka zawsze ustawia komunikat „Pozycja została już usunięta”. Dla linii w stanie `unavailable_product` API przy zmianie ilości zwraca 404 (`ProductNotFound`), a linia zostaje w koszyku; pole ilości takiej linii jest aktywne.
Skutek: klient widzi komunikat o usuniętej pozycji, choć pozycja nadal jest w koszyku. Poprawnie: komunikat o niedostępnym produkcie albo wyłączone pole ilości dla takiej linii.
Zgłoszenie musi wskazać ten mechanizm (błędny komunikat po 404 dla linii, która zostaje, albo aktywne pole ilości niedostępnej linii). Inne uwagi o `CartPage.tsx` to nie ta wada.
