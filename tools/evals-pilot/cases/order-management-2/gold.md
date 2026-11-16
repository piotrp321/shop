Źródło: zgłoszenie #256.
Pliki: `apps/api/src/Ordering/Domain/StaffNote.php` (także walidacja żądań `ChangeOrderStatusRequest.php`, `CompleteRefundRequest.php`).
Mechanizm (a), wada wzorcowa: notatka pracownika jest przycinana przez `trim()`, które działa tylko na ASCII, więc notatka złożona z samych białych znaków Unicode (np. NBSP, U+00A0) nie jest uznana za pustą.
Skutek (a): żądanie z notatką z samego NBSP daje 200 i notatka trafia do historii zamówienia, choć specyfikacja wymaga niepustej notatki (422).
Część (b) tego samego zgłoszenia: separatory linii Unicode (U+2028, U+2029, U+0085) w notatce przechodzą regułę „jedna linia”, bo wzorzec znaków sterujących ich nie obejmuje. Zgłoszenie opisujące wyłącznie część (b), bez białych znaków Unicode z części (a), oceń jako UNKNOWN: rozstrzyga właściciel.
