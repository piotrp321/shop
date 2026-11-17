Źródło: zgłoszenie #113.
Pliki: `apps/api/src/Cart/UI/Http/AddCartItemRequest.php`, `apps/api/src/Cart/UI/Http/MergeGuestCartRequest.php`.
Mechanizm: `Assert\Uuid` dla UUID z samych zer (nil) albo samych `f` (max) dodaje dwa identyczne naruszenia tego samego pola (`productId`, `guestCartId`); `Assert\Sequentially` tego nie zatrzymuje.
Skutek: odpowiedź 422 ma dwa naruszenia jednego pola, wbrew kryterium akceptacji 2 („jedno pole daje najwyżej jedno naruszenie”).
