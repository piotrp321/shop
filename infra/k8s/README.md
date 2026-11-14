# Runbook: od pustego serwera do działającego środowiska

Uruchomienie środowiska wdrożeniowego (`docs/features/deploy/spec.md`) po merge'u epiku DEPLOY. Każdy krok ma znacznik:

- **agent** – wykonuje go Claude Code celem Makefile albo zmianą w repo (PR), gdy użytkownik zlecił uruchomienie środowiska;
- **zgoda użytkownika** – agent opisuje, co zrobi, pyta w rozmowie i czeka na odpowiedź; dopiero potem uruchamia (firewall,
  operacje kasujące dane, zakładanie kont);
- **użytkownik** – robi to użytkownik sam (zakupy, konta zewnętrzne, wartości sekretów, Cloudflare, ruleset i deploy key,
  backup VPS-a); agent tego nie wykonuje i nie podejmuje tych decyzji.

Agent wykonuje operacje na serwerze wyłącznie celami `make -C infra/k8s …` (`bootstrap`, `kubeconfig`, `status`, `rerun`,
`create-admin`, `restore`, `backups`, `reinitialize`, `seal-secrets`, `cloudflare-ips`). Nie łączy się z serwerem inaczej i nie uruchamia `kubectl` ani
`helm` na serwerze. Wszystkie cele potrzebują tylko Dockera (oraz `ssh` na komputerze użytkownika). Opcje `ssh` (klucz, port):
`SSH_OPTS="-i ~/.ssh/shop -p 2222"`.

Aplikacje Argo CD, Jobów, fale i reszta mechanizmu: `docs/features/deploy/spec.md`, uzasadnienie: `docs/adr/k3s-argo-cd.md`.

## 0. Przygotowanie

1. **użytkownik** – kupuje VPS (docelowo 8 vCore, 24 GB RAM, Ubuntu 26.04 LTS, OVH) i domenę; domena jest w Cloudflare
   (DNS). Zakłada konto Brevo. Powiadamia agenta o domenie i adresie IP serwera.
2. **użytkownik** – zapisuje odcisk klucza hosta SSH z panelu OVH, łączy się raz ręcznie (`ssh <user>@<adres>`) i porównuje
   odcisk (bootstrap odmawia nieznanego klucza hosta, bo przez SSH przesyła m.in. kopię klucza Sealed Secrets). Użytkownik
   serwera to root albo konto z `sudo` bez hasła (`sudo -n`).
3. **agent** – ustawia domenę w `infra/k8s/environments/prod/values.yaml` (`global.domain`) i nadawcę poczty (`mailer.from`,
   `keycloak.mailFrom`, jeśli ma być inny niż domyślny `zamowienia@`/`konto@<domena>`), PR do `main`; merge robi
   **użytkownik**. Wdrożenie (`deploy.yml`) startuje tylko na życzenie użytkownika (sekcja 7), nie po merge'u. Po
   merge'u **zgoda użytkownika** i `gh workflow run deploy.yml --ref main`; na tym etapie workflow
   buduje i testuje obrazy, ale bez sekretu `DEPLOY_KEY` kończy się po smoke teście z ostrzeżeniem (nic nie publikuje).

## 1. GitHub: obrazy, ruleset, deploy key

Wszystko **użytkownik** (wymaga uprawnień administratora repo).

1. Widoczność pakietów GHCR: po pierwszym udanym wdrożeniu (**zgoda użytkownika**, `gh workflow run deploy.yml --ref main`, sekcja 7) pakiety `shop/api`, `shop/store`, `shop/admin`
   i `shop/elasticsearch` ustaw na **publiczne** (Packages → pakiet → Package settings → Change visibility), żeby k3s
   pobierało je bez `imagePullSecret`. Nie włączaj żadnej retencji ani czyszczenia wersji pakietów: obrazy z digestami, do
   których odwołują się snapshoty `deploy-images`, są potrzebne do cofnięcia wersji (workflow też niczego nie usuwa).
2. Rulesety (każdy plik osobno; oba obejmują `deploy` i `deploy-images`):

   ```sh
   gh api repos/piotrp321/shop/rulesets -X POST --input tools/rulesets/deploy.json
   gh api repos/piotrp321/shop/rulesets -X POST --input tools/rulesets/deploy-push.json
   ```

3. Deploy key z prawem zapisu i sekret repo `DEPLOY_KEY` (repo ma mieć **tylko jeden** deploy key, bo aktor `DeployKey`
   obejmuje każdy):

   ```sh
   ssh-keygen -t ed25519 -N '' -C shop-deploy -f /tmp/shop-deploy-key
   gh repo deploy-key list                      # powinno być puste
   gh repo deploy-key add /tmp/shop-deploy-key.pub --allow-write --title shop-deploy
   gh secret set DEPLOY_KEY < /tmp/shop-deploy-key
   rm -f /tmp/shop-deploy-key /tmp/shop-deploy-key.pub
   ```

4. **zgoda użytkownika** – uruchom wdrożenie ponownie: `gh workflow run deploy.yml --ref main` (obrazy z tagiem tego
   samego commita zostają, nie są przebudowywane). Krok `Publish deploy and deploy-images` tworzy gałęzie `deploy` i `deploy-images`.
5. Próby rulesetów, osobno dla `deploy` i dla `deploy-images` (zapisz wyniki w komentarzu do zgłoszenia #175):

   | Próba | Oczekiwany wynik |
   |---|---|
   | push zwykłym poświadczeniem użytkownika, np. `git push origin origin/main:refs/heads/deploy` (dla `deploy-images` ten sam commit na `refs/heads/deploy-images`) | odrzucony („Repository rule violations”) |
   | usunięcie: `git push origin :refs/heads/deploy` (i `:refs/heads/deploy-images`) | odrzucone |
   | force push starszego commita: `git push --force origin origin/main~1:refs/heads/deploy` (i `deploy-images`) | odrzucony |
   | push deploy keyem | przyjęty: to udany krok `Publish deploy and deploy-images` ze zwykłego runu; ręcznie na `deploy-images` nie pushujemy (snapshot tworzy tylko workflow) |

## 2. Serwer: bootstrap

1. **zgoda użytkownika** – agent opisuje, co zrobi na serwerze: zainstaluje pakiety (`ufw`, `unattended-upgrades`), ustawi
   `vm.max_map_count`, **włączy firewall `ufw`** (otwarte tylko SSH i API k3s `6443`; porty 80/443 chroni Cloudflare i
   `ipAllowList`), zainstaluje k3s w przypiętej wersji, Argo CD i kontroler Sealed Secrets oraz aplikację `root`. Uwaga: firewall
   może odciąć SSH, jeśli sshd nie działa na porcie, na którym jest sesja (skrypt otwiera port bieżącej sesji).
2. **użytkownik** – jeśli odtwarza środowisko z istniejącym kluczem Sealed Secrets, ma kopię klucza (plik YAML, zob. 4.).
3. **agent** (po zgodzie):

   ```sh
   make -C infra/k8s bootstrap HOST=<user>@<adres> [SEALED_KEY_FILE=<kopia klucza>]
   ```

   Idempotentny: można go uruchomić ponownie. Bez roota (i bez `sudo -n`) albo na innym systemie niż Ubuntu 26.04
   kończy się błędem przed zmianami. Zapisuje certyfikat kontrolera do `infra/k8s/environments/prod/sealed-secrets.pem`,
   gdy repo go nie ma. Gdy repo ma certyfikat, serwer porównuje go z certyfikatem kontrolera **przed** zastosowaniem aplikacji
   `root`: inny certyfikat to błąd (root nie jest zastosowany, nic nie jest nadpisywane: użyj kopii klucza albo zaszyfruj
   sekrety od nowa, zob. „Utrata klucza”). Kontroler nie rotuje klucza (`keyrenewperiod: "0"` w aplikacji `sealed-secrets`),
   więc certyfikat w repo i jedna kopia klucza zostają aktualne. Aplikacja `root` czeka na gałąź `deploy-images` (krok 1.4).
   Zmiana adresu serwera (`tls-san`) przy tej samej wersji k3s restartuje k3s.

   AppArmor na Ubuntu 26.04: profil `cri-containerd.apparmor.d` blokuje sygnały między procesami uruchomionymi przez
   exec (otwarty błąd containerd/containerd#12886). Spodziewane są wpisy `apparmor="DENIED"` z `cri-containerd`
   (zwykle `signal`) w `sudo dmesg` przy exec probe usług i przy `kubectl exec` (`reinitialize`, `create-admin`); same
   wpisy to szum. Sprawdzenie jest w sekcji 6. Gdy sonda podu faktycznie zawodzi (pod `NotReady` albo restartuje się,
   a `kubectl describe` pokazuje nieudaną sondę) albo `reinitialize` lub `create-admin` kończą się błędem z takimi
   wpisami, obejściem jest `securityContext.appArmorProfile: { type: Unconfined }` dla kontenera tej usługi w chartcie
   (zmiana przez PR). Obejście jest włączone dla RabbitMQ: jego sonda (`gosu` i CLI Erlanga) na pierwszym serwerze
   przekraczała limit czasu przy wpisach `DENIED … comm="gosu"`, a pod nie stawał się gotowy. Lista kontenerów bez
   profilu jest w teście `AppArmorTest` (`infra/k8s/tests/test_workloads.py`); po poprawce containerd w k3s wraca pusta.
4. **użytkownik** – robi kopię klucza prywatnego kontrolera Sealed Secrets i trzyma ją poza repo i poza serwerem
   (menedżer haseł). Jest jedyną rzeczą, której nie da się odtworzyć z repo. Plik (`kind: List` z Secretami) jest podawany
   bootstrapowi jako `SEALED_KEY_FILE`:

   ```sh
   ssh <user>@<adres> 'sudo k3s kubectl -n kube-system get secret -l sealedsecrets.bitnami.com/sealed-secrets-key -o yaml' > sealed-secrets-key-backup.yaml
   ```

5. **agent** – commituje certyfikat (jest publiczny) w PR do `main`; merge robi **użytkownik**.
6. **agent** – `make -C infra/k8s kubeconfig HOST=<user>@<adres> KUBE_DIR=<katalog poza repo>` (kubeconfig jest potrzebny do
   `rerun` i `create-admin`; zawiera dane administratora klastra, nie trafia do repo).

## 3. Sekrety

Wymaga certyfikatu w repo (2.5).

1. **agent** – `make -C infra/k8s seal-secrets WHAT=shop`: klucze z `infra/scripts/init-env.sh` z losowymi wartościami do
   `infra/k8s/environments/prod/secrets/shop-secrets.yaml`. Istniejących kluczy nie zmienia (wartości są już w bazach).
2. **użytkownik** – w Brevo (SMTP & API) tworzy klucz SMTP; w Cloudflare (SSL/TLS → Origin Server) wystawia certyfikat Origin CA
   dla `<domena>` i `*.<domena>` (PEM certyfikatu i klucza). Na koncie Cloudflare zakłada bucket R2 na kopie zapasowe i token
   API R2 z dostępem tylko do tego bucketu (klucz i sekret S3); wymyśla hasło repozytorium restic i zapisuje je w menedżerze
   haseł (poza serwerem: bez niego kopie są nie do odczytania, także po utracie klucza Sealed Secrets). W Grafana Cloud zakłada
   stos i token dla Alloy (9.).
3. **agent** – wpisuje adres repozytorium (`s3:https://<id konta>.r2.cloudflarestorage.com/<bucket>`, id konta podaje
   użytkownik) w `backup.repository` i region `auto` w `environments/prod/values.yaml` (PR) oraz adresy i identyfikatory instancji
   Grafana Cloud w `argocd/apps/monitoring.yaml` (9.).
4. **użytkownik** – wpisuje wartości w terminalu (bez echa, nigdy w argumentach, zmiennych ani rozmowie z agentem):

   ```sh
   make -C infra/k8s seal-secrets WHAT="brevo tls backup grafana"
   ```

   Login i klucz SMTP Brevo, certyfikat i klucz Origin CA wklejone w całości (do wiersza `END`), potem hasło restic (dwa razy: różne
   wartości to błąd), klucz i sekret S3 bucketu kopii, na końcu token Grafana Cloud. Plik już istniejący nie jest ponownie pytany ani zmieniany (nowe hasło restic uczyniłoby
   dotychczasowe kopie nieczytelnymi). Błąd (pusta wartość, zły PEM, nieudany `kubeseal`) niczego nie zapisuje.
5. **agent** – PR z plikami z `environments/prod/secrets/` (są zaszyfrowane, bezpieczne do commitu); `make -C infra/k8s check`
   wymaga od tego momentu zaszyfrowanej wartości dla każdego klucza `init-env.sh`. Merge robi **użytkownik**.

Nowy sekret w przyszłości: klucz w `infra/scripts/init-env.sh`, szablon sekretu chartu i `make -C infra/k8s seal-secrets WHAT=shop`
w tym samym PR.

Po pierwszym wdrożeniu z kopiami **agent** sprawdza następnego dnia `make -C infra/k8s status HOST=<user>@<adres>`: Job `backup-<id>`
z CronJoba `backup` jest `Complete`, a jego log kończy się wierszem `backup: done`. Przed pierwszą udaną kopią repozytorium nie istnieje: pierwsza kopia zakłada je sama.
Po niej (05:00) Job `backup-verify-<id>` z CronJoba `backup-verify` czyta repozytorium z R2 i odtwarza oba dumpy do tymczasowej bazy: jest `Complete`, a jego log (kontener `verify`) kończy się wierszem `backup-verify: ok`; błąd kończy Job z opisem w logu.

## 4. Cloudflare i poczta

Wszystko **użytkownik**.

1. DNS: rekordy `<domena>`, `admin`, `api`, `auth`, `img` na adres serwera, **proxy włączone**. SSL/TLS: tryb **Full (strict)**.
   Reguła cache dla całego hosta `img.<domena>`.
2. Cloudflare Access: aplikacja dla `admin.<domena>` z listą uprawnionych adresów e-mail. `auth.<domena>` zostaje bez Access
   (panel wymienia kod na token zapytaniem z innego originu); realm `backoffice` chronią hasło, TOTP i blokada brute force.
3. Brevo: domena nadawcy dodana i zweryfikowana; rekordy SPF i DKIM (z panelu Brevo) oraz DMARC w Cloudflare, np.
   `_dmarc` TXT `v=DMARC1; p=quarantine; rua=mailto:<adres użytkownika>`.
4. Zakresy Cloudflare w `ipAllowList`: **agent** sprawdza je co jakiś czas `make -C infra/k8s cloudflare-ips CHECK=1`; gdy lista
   się zmieniła, `make -C infra/k8s cloudflare-ips` przepisuje ją w `environments/prod/values.yaml` (PR, merge: **użytkownik**).
   Nieaktualna lista odcina nowe adresy Cloudflare (błąd 403 dla części ruchu).

## 5. Pierwsze wdrożenie

1. **zgoda użytkownika** – po merge'ach z 2.5 i 3.5 agent uruchamia wdrożenie (`gh workflow run deploy.yml --ref main`, sekcja 7).
   Workflow publikuje snapshot `deploy-images`, a Argo CD synchronizuje `root`, `sealed-secrets`,
   `secrets` i `shop` (fale: kontroler, sekrety, sklep). Pierwsza synchronizacja na pustym klastrze trwa kilkanaście minut
   (Elasticsearch, migracje, wczytanie danych demo).
2. **agent** – `make -C infra/k8s status HOST=<user>@<adres>`: aplikacje `Synced`/`Healthy`, pody `Running`, Joby `Complete`.
   Nieudany Job wersji: log (`status`), poprawka w repo (nowa rewizja) albo `make -C infra/k8s rerun JOB=<nazwa>`. Job
   `initialize` z błędem „mixed state” (przerwane wczytanie): **zgoda użytkownika**, potem
   `make -C infra/k8s reinitialize HOST=<user>@<adres>` (kasuje dane sklepu i wczytuje dane demo od nowa; wymaga słowa `tak`).
   Pod usługi stale `0/1` mimo działającej usługi: sonda (np. AppArmor, sekcja 2, krok 3), poprawka przez PR; po niej
   zwykle trzeba przerwać zawieszoną synchronizację i usunąć stary pod (sekcja 7, dwa ostatnie punkty).
3. **zgoda użytkownika** – pierwszy administrator panelu (Keycloak, realm `backoffice`):

   ```sh
   make -C infra/k8s create-admin LOGIN=<login> EMAIL=<e-mail> KUBE_DIR=<katalog z kubeconfigiem>
   ```

   Wypisuje, co zrobi, i działa dopiero po słowie `tak`. Zakłada użytkownika z rolą `admin` bez hasła i wysyła mu e-mail
   (Brevo) z akcjami „ustaw hasło” i „skonfiguruj TOTP” (link ważny 12 h). Hasło i TOTP ustawia **użytkownik** w
   przeglądarce.
4. **użytkownik** – włącza automatyczny backup VPS-a w panelu OVH. To dodatkowa kopia całego serwera (obok nocnych kopii
   restic w R2: dane sklepu i obrazy produktów); prawdziwe dane i publiczny sklep dopiero po obu.
5. **zgoda użytkownika** – weryfikacja originu (`docs/features/hardening/spec.md`, „Weryfikacja originu na serwerze”):
   limity API liczą się po `CF-Connecting-IP`, któremu można ufać tylko wtedy, gdy nic poza Cloudflare nie dociera do
   originu. Agent opisuje żądania i po zgodzie wysyła je z komputera spoza Cloudflare (`<IP>` – adres serwera):

   ```sh
   # Z pominięciem Cloudflare każdy host odmawia (403 z ipAllowList albo odrzucone połączenie, kod 000).
   for url in <domena>/ api.<domena>/health admin.<domena>/ auth.<domena>/realms/shop/ img.<domena>/; do
     host="${url%%/*}"
     curl -sk -o /dev/null -w "$url %{http_code}\n" --resolve "$host:443:<IP>" "https://$url"
   done
   # Przez Cloudflare z własnym CF-Connecting-IP: Cloudflare odrzuca takie żądanie sam (403, "error code: 1000",
   # nagłówek server: cloudflare), więc podrobiony adres nie dociera do originu.
   curl -s -w "\n%{http_code}\n" -X POST -H 'Content-Type: application/json' -H "Idempotency-Key: origin-check-spoof" \
     -H "CF-Connecting-IP: 203.0.113.1" -d '{}' "https://api.<domena>/api/v1/orders"
   # Bez podrabiania: limit złożenia zamówienia (10 na 15 min) liczy się na prawdziwy adres nadawcy, więc 11. żądanie
   # to 429, a nie kolejne 422.
   for n in $(seq 1 11); do
     curl -s -o /dev/null -w "%{http_code}\n" -X POST -H 'Content-Type: application/json' \
       -H "Idempotency-Key: origin-check-$n" -d '{}' "https://api.<domena>/api/v1/orders"
   done
   ```

   Oczekiwane: pierwsza pętla tylko `403` albo `000`; żądanie z podrobionym nagłówkiem `403` z `error code: 1000`;
   ostatnia pętla dziesięć razy `422`, potem `429`. Inny wynik (origin odpowiada z pominięciem Cloudflare, podrobiony
   nagłówek dociera do API albo 11. żądanie przechodzi) to błąd do naprawy przed publicznym demo:
   do tego czasu ten krok jest blokerem trybu publicznego demo (#179). Wynik zapisz w komentarzu do #179. Po teście adres
   nadawcy ma wyczerpany limit zamówień na 15 minut.

## 6. Sprawdzenia po uruchomieniu

Zapisz wyniki w komentarzu do zgłoszenia #175.

| Sprawdzenie | Kto | Oczekiwany wynik |
|---|---|---|
| Żądanie na IP serwera spoza Cloudflare: `curl -sk -o /dev/null -w '%{http_code}\n' --resolve <domena>:443:<IP> https://<domena>/` | użytkownik (z komputera spoza Cloudflare) | `403` (`ipAllowList`) |
| `https://<domena>/` przez Cloudflare | użytkownik | sklep z paskiem „Sklep demonstracyjny…”, bez linku „Zaloguj się”, nagłówek `X-Robots-Tag: noindex, nofollow` |
| `https://admin.<domena>/` | użytkownik | najpierw logowanie Cloudflare Access (adres z listy), potem logowanie do panelu (Keycloak, hasło i TOTP) |
| E-mail z `create-admin` | użytkownik | dotarł (SPF/DKIM/DMARC bez ostrzeżeń), link działa |
| `https://auth.<domena>/realms/master/` | użytkownik | niedostępny (404); dostępne tylko `/realms/shop/`, `/realms/backoffice/`, `/resources/` |
| Próby rulesetów (1.5) | użytkownik | zgodnie z tabelą |
| `make -C infra/k8s status HOST=…` | agent | wszystko `Synced`, `Healthy`, Joby `Complete` |
| Reguły alertów w Grafana Cloud i punkt kontaktowy e-mail (9.) | agent, punkt kontaktowy: użytkownik | siedem reguł w rulerze; `BackupMissing` i `BackupVerifyMissing` odpalają do pierwszej kopii i weryfikacji (oczekiwane) |
| Grafana Cloud (9.) | agent | metryki klastra i logi `namespace="shop"` są, logów `mailpit` nie ma, przegląd próbki logów bez sekretów i danych klientów |
| Sentry (10.): po wpisaniu DSN-ów i wdrożeniu | użytkownik | zdarzenia i trace'y w projektach `api`, `store` i `admin` z wersją (rewizja obrazu) i środowiskiem `demo`; przegląd próbki zdarzeń bez danych osobowych |
| AppArmor (sekcja 2, krok 3): na serwerze `sudo dmesg \| grep 'apparmor="DENIED"' \| grep -c cri-containerd` po `reinitialize` i `create-admin` | użytkownik | wpisy `signal` są oczekiwane (szum); oba cele kończą się sukcesem, a pody nie restartują się przez sondy |

## 7. Eksploatacja

- **Nowa wersja**: merge PR do `main` niczego nie wdraża. Wdrożenie startuje tylko na życzenie: **zgoda użytkownika**, potem
  `gh workflow run deploy.yml --ref main` (obrazy, smoke test, publikacja snapshotu; postęp: `gh run list --workflow deploy.yml`,
  `gh run watch`), a Argo CD synchronizuje klaster. Wdraża się bieżący commit `main`; drugie uruchomienie w trakcie pierwszego
  czeka, a trzecie zastępuje drugie. Błąd renderu albo migracji zatrzymuje wdrożenie, a poprzednia wersja działa dalej
  (aplikacja `Degraded`). Uruchomienie na innej gałęzi (`--ref <gałąź>`) to tylko próba: smoke test, nic nie jest
  wypychane i `deploy` się nie przesuwa.
- **Cofnięcie wersji**: revert na `main` i nowe uruchomienie wdrożenia. Schemat bazy jest zgodny wstecz tylko z poprzednią wersją kodu;
  cofnięcie o więcej wersji to synchronizacja `shop` ze starszym snapshotem `deploy-images` (jak niżej) i odtworzenie kopii
  z czasu tej wersji (8.; `restore` odmawia kopii nowszej niż wdrożony kod) albo `reinitialize`. Awaryjnie **użytkownik** wyłącza w Argo CD
  automatyczną synchronizację aplikacji `root`, a potem aplikacji `shop` (kolejność ma znaczenie: `root` z `selfHeal`
  przywraca z gita `syncPolicy` aplikacji `shop`, a Argo CD nie synchronizuje do innej rewizji aplikacji z automatyczną
  synchronizacją) i synchronizuje `shop` z poprzednim snapshotem `deploy-images` (jego obrazy są w GHCR). Potem robi revert
  na `main`, uruchamia wdrożenie (**zgoda użytkownika**, `gh workflow run deploy.yml --ref main`), a po opublikowaniu
  nowego snapshotu włącza automatyczną synchronizację `root` (z `prune` i `selfHeal`, jak w
  `argocd/root.yaml`): `root` przywraca z gita automatyczną synchronizację `shop`, która wdraża nowy snapshot.
- **Argo CD i inne narzędzia** (bez publicznego adresu): `ssh -L 8080:localhost:8080 <user>@<adres> 'sudo k3s kubectl -n argocd
  port-forward svc/argocd-server 8080:80'`, hasło w sekrecie `argocd-initial-admin-secret` (**użytkownik**).
- **Utrata klucza Sealed Secrets**: bez kopii (2.4) sekretów z repo nie da się odszyfrować. Nowy certyfikat: usuń
  `environments/prod/sealed-secrets.pem` i pliki z `environments/prod/secrets/`, `bootstrap` bez kopii klucza (zapisze nowy
  certyfikat), ponownie sekrety (3.), a bazy zainicjowane starymi hasłami wymagają ponownego wczytania danych albo
  odtworzenia z kopii (8.; hasło restic jest u użytkownika). Realm `master` Keycloaka wraca z kopii ze starym hasłem
  administratora, więc `restore` stanie na kroku 6 (`keycloak-config` nie zaloguje się nowym `KC_BOOTSTRAP_ADMIN_PASSWORD`):
  **użytkownik** ustawia w Keycloaku (konsola `master` z hasłem z kopii, jeśli je ma, albo tymczasowy administrator z
  `kc.sh bootstrap-admin`) hasło administratora `admin` na wartość z nowego sekretu, a potem agent kończy jak przy
  „Odtworzenie przerwane w trakcie” (8.). Wymaga **zgody użytkownika**.
- **Alerty kopii** (`BackupFailed`, `BackupMissing`, `BackupVerifyFailed`, `BackupVerifyMissing`; punkt kontaktowy i wyciszanie: 9.):
  **agent** czyta logi nieudanego Joba w sekcji „logs of the failed jobs” wyniku `make -C infra/k8s status HOST=…`
  (ostatnie 100 linii każdego kontenera każdego poda Joba, więc każdej próby, każda linia z nazwą poda i kontenera; nazwę Joba
  podaje też etykieta `job_name` alertu `*Failed`; gdy podów Joba już nie ma, `status` mówi o tym i odsyła do Grafana Cloud),
  a dłuższą historię ma Grafana Cloud (logi podów) i usuwa przyczynę (dostęp do R2, miejsce na `backup-work`, uszkodzone
  repozytorium, dump, który się nie odtwarza). Ponowienie to nowy Job z CronJoba, który robi **użytkownik** (agent nie uruchamia
  `kubectl` na serwerze): `ssh <user>@<adres> 'sudo k3s kubectl -n shop create job --from=cronjob/backup backup-reczny-$(date +%s)'`
  (dla weryfikacji `--from=cronjob/backup-verify`). Job z `--from` należy do CronJoba, więc jego sukces odświeża metrykę ostatniej
  udanej kopii i gasi `*Failed` i `*Missing`; `make rerun` tu nie pasuje (odtwarza Job bez właściciela, którego alert nie widzi).
  `*Missing` bez nieudanego Joba to CronJob, który się nie uruchomił (węzeł wyłączony), albo brak metryk (serwer albo Alloy nie
  działa; razem z nim odpala `ClusterSilent`).
- **Pozostałe alerty**: `NodeDiskFull` (system plików węzła zajęty w ponad 85% przez 10 minut: miejsce zwalnia albo dysk powiększa
  **użytkownik**), `ClusterSilent` (brak metryk klastra przez 15 minut: serwer, k3s albo Alloy; `status` i panel OVH),
  `WorkloadNotReady` (Deployment albo StatefulSet w `shop`, w tym Redis, ma mniej gotowych replik niż oczekiwane albo go nie ma
  przez 10 minut; etykieta `deployment` albo `statefulset` mówi który: `status`, zdarzenia i log poda).
- **Restart serwera**: k3s i usługi wstają same, dane na wolumenach zostają.
- **Poprawka nie wchodzi, bo synchronizacja wisi** (**użytkownik**, po wskazaniu przez agenta; agent nie uruchamia `kubectl` na
  serwerze). Argo CD nie zaczyna nowej synchronizacji aplikacji, dopóki trwa poprzednia, a ta czeka bez końca na zdrowie
  zasobu, którego stara wersja nigdy nie będzie zdrowa. Objaw: `status` pokazuje `shop` jako `OutOfSync`/`Progressing`
  mimo nowego snapshotu `deploy-images`. Sprawdzenie:

  ```sh
  ssh <user>@<adres> 'sudo k3s kubectl -n argocd get application shop -o jsonpath="{.status.operationState.phase} {.status.operationState.syncResult.revision} | sync={.status.sync.revision} | {.status.operationState.message}{\"\n\"}"'
  ```

  `Running` ze starą rewizją, inną niż `sync=`, i komunikatem `waiting for healthy state of …` oznacza zawieszoną operację.
  Przerwanie (to samo co `argocd app terminate-op shop`; CRD aplikacji nie ma podzasobu `status`, więc bez `--subresource`):

  ```sh
  ssh <user>@<adres> 'sudo k3s kubectl -n argocd patch application shop --type merge -p "{\"status\":{\"operationState\":{\"phase\":\"Terminating\"}}}"'
  ```

  Automatyczna synchronizacja zaczyna od razu nową operację na bieżącej rewizji; to samo sprawdzenie pokazuje ją z nową
  datą startu. Dane, wolumeny i sekrety zostają.
- **Pod StatefulSetu, który nigdy nie był gotowy, nie dostaje nowej wersji** (**użytkownik**). Kontroler StatefulSetu
  czeka na gotowość starego poda przed jego wymianą (w Kubernetes „forced rollback”), więc poprawka sondy albo obrazu nie
  dociera do niego sama, nawet po udanej synchronizacji. Gdy nowy szablon jest już w StatefulSecie
  (`sudo k3s kubectl -n shop get statefulset <nazwa> -o yaml`), pod usuwa się ręcznie, a StatefulSet zakłada go od razu
  z nowym szablonem; wolumen z danymi zostaje:

  ```sh
  ssh <user>@<adres> 'sudo k3s kubectl -n shop delete pod <nazwa>-0'
  ```

## 8. Kopie zapasowe: lista, ćwiczenie odtworzenia, utrata serwera

Mechanizm: `docs/features/deploy/spec.md` („Kopie zapasowe”), decyzje: `docs/features/deploy-ops/spec.md` (O4). Cele
łączą się z klastrem przez kubeconfig z `KUBE_DIR` (2.6), jak `rerun` i `create-admin`.

- **Lista snapshotów** – **agent**: `make -C infra/k8s backups KUBE_DIR=<katalog z kubeconfigiem>` wypisuje snapshoty
  repozytorium (identyfikator, czas, host, tagi `nightly` albo `pre-restore`, rewizja kodu), najstarsze pierwsze; tylko odczyt.
  Host to identyfikator klastra (UID przestrzeni `kube-system`): snapshoty innego serwera mają inny host.
- **Ćwiczenie odtworzenia** – **zgoda użytkownika** (raz po pierwszym wdrożeniu z kopiami i po większych zmianach chartu):
  agent opisuje skutki (dane wracają do chwili snapshotu, klienci są wylogowani, kolejki opróżnione, sklep jest wyłączony na
  czas odtworzenia, przed nim powstaje snapshot `pre-restore`), a po zgodzie:

  ```sh
  make -C infra/k8s backups KUBE_DIR=<katalog>
  make -C infra/k8s restore SNAPSHOT=<identyfikator świeżego snapshotu nightly tego serwera> KUBE_DIR=<katalog>
  ```

  `restore` sprawdza (bez zmian) brak aktywnych Jobów, brak trwającej synchronizacji `shop` w Argo CD i zgodność migracji
  snapshotu z wdrożonym kodem, wypisuje snapshot i skutki i czeka na słowo `tak` (wpisuje je agent dopiero po zgodzie). Potem:
  kopia `pre-restore`, wyłączenie automatycznej synchronizacji `root`, potem `shop` (przejdzie też, gdy jej już nie ma),
  wstrzymanie CronJobów, ponowne sprawdzenie Jobów (nocna kopia mogła wystartować po pierwszym sprawdzeniu: `restore` czeka
  na nią do 2 godzin, `RESTORE_ACTIVE_JOBS_WAIT_SECONDS`, a potem kończy się błędem z działającymi aplikacjami), `api`, `worker`,
  `store` i `keycloak` do zera, odtworzenie danych, `migrate`, `keycloak-config`, reindeks, powrót aplikacji, `purge-caches` i
  włączenie synchronizacji `root` (ten przywraca `shop`). Kończy się wierszem `restore: done` z identyfikatorem snapshotu
  `pre-restore`. Sprawdzenie: `make -C infra/k8s status HOST=…` (aplikacje `Synced`/`Healthy`, pody `Running`), sklep i
  panel w przeglądarce (**użytkownik**: logowanie do panelu). Wynik (czas, snapshot, `pre-restore`) w komentarzu do #178.
- **Odmowa** (kod 1, „nothing changed”): aktywny Job (poczekaj na koniec, np. nocnej kopii), synchronizacja `shop` w toku,
  snapshot nowszy niż kod (migracja, której kod nie zna: odtwórz go dopiero na wersji, która ją ma), nieudana kopia
  `pre-restore` (log w komunikacie; `PRE_RESTORE=skip` pomija kopię, tylko po zgodzie użytkownika, bo stanu sprzed
  odtworzenia nie da się wtedy przywrócić).
- **Odtworzenie przerwane w trakcie** (kod 1, „FAILED at step N”): aplikacje zostają wyłączone (bez sklepu na częściowo
  odtworzonych danych), przy Argo CD synchronizacja `root` i `shop` zostaje wyłączona; komunikat podaje krok, log Joba i
  snapshot `pre-restore`. Agent czyta przyczynę (komunikat, `status`), po usunięciu jej (np. poprawka w repo, dostęp do R2) i
  po **zgodzie użytkownika** uruchamia `restore` ponownie z tym samym `SNAPSHOT` i `PRE_RESTORE=skip` (kopia stanu sprzed
  pierwszej próby już jest; aplikacje na zerze wracają z jedną repliką, a wstrzymane `cart-purge-expired` i `backup` z `suspend: false`) albo wraca do stanu sprzed odtworzenia:
  `restore SNAPSHOT=<pre-restore>`. Ponowne uruchomienie przy wyłączonej synchronizacji `root` jej nie włącza: włącza ją
  **użytkownik** w Argo CD (z `prune` i `selfHeal`, jak w `argocd/root.yaml`).
- **Utrata serwera** – **zgoda użytkownika** przy każdym kroku z niej: nowy VPS (**użytkownik**, 0.), `bootstrap` z kopią
  klucza Sealed Secrets (`SEALED_KEY_FILE`, 2.: te same sekrety i hasła baz), pierwsza synchronizacja wczytuje dane demo
  (`initialize`), `kubeconfig`, `make backups` (snapshoty starego serwera mają inny host niż nowy), `restore
  SNAPSHOT=<ostatni snapshot nightly starego hosta> PRE_RESTORE=skip` (na nowym serwerze są tylko dane demo), potem `create-admin` niepotrzebne (administratorzy wracają z kopii).
  Nowy serwer robi od tej nocy własne kopie i przycina tylko je.
- **Snapshoty starego serwera** po odtworzeniu na nowym nie wygasają same (`forget` każdego klastra dotyczy tylko jego
  hosta): zostają w R2, dopóki **użytkownik** nie zdecyduje o ich usunięciu (`restic forget --host <stary host> --prune`
  z hasłem restic, poza celami agenta).

## 9. Logi, metryki i alerty: Grafana Cloud i Alloy

Aplikacje Argo CD `prometheus-crds` (fala −3) i `monitoring` (fala 1, po `shop`) stawiają CRD Prometheusa oraz Grafana Alloy
(chart `k8s-monitoring`), który wysyła do Grafana Cloud logi podów wszystkich przestrzeni, zdarzenia klastra i metryki z listy
dozwolonych (`docs/features/deploy-ops/spec.md`, O5 i O7). Logi są maskowane w Alloy przed wysłaniem, a Mailpit nie jest zbierany.
Alloy czyta z klastra tylko sekret `grafana-cloud` z przestrzeni `shop`, a Alloy Operator działa tylko w przestrzeni `monitoring`,
bez ClusterRoli; RBAC operatora i kolektora jest w `extraObjects` manifestu, nie w chartach. Polityki dopuszczania z tego samego
manifestu odrzucają w `monitoring` pody z dostępem do hosta (poza node-exporterem) albo z klasą priorytetu `system-*`, Service
inne niż `ClusterIP` albo z `externalIPs` i sekrety z tokenami kont, także żądania administratora; `kubectl debug` poda
node-exportera też jest odrzucany. Klaster nie ma NetworkPolicy, a Redis (sesje klientów) i Elasticsearch działają bez
uwierzytelnienia: każdy pod klastra, także w `monitoring`, sięga do nich po sieci (ryzyko opisane w
`docs/adr/backups-observability.md`).

Alerty to `PrometheusRule` `shop-alerts` chartu `shop` (`alerts.enabled: true` w `prod`; `docs/features/deploy-ops/spec.md`, O6):
`BackupFailed`, `BackupMissing`, `BackupVerifyFailed`, `BackupVerifyMissing`, `NodeDiskFull` (ponad 85% przez 10 minut),
`ClusterSilent` (brak metryk klastra przez 15 minut) i `WorkloadNotReady` (Deployment albo StatefulSet w `shop` niegotowy albo
usunięty przez 10 minut). Alloy synchronizuje je do rulera Grafana Cloud, który działa poza serwerem, więc alert przychodzi także
wtedy, gdy serwer albo Alloy nie żyje. Alerty z treści logów (np. ostrzeżenia kanału `cache` przy awarii Redisa) nie istnieją.

1. **użytkownik** – zakłada konto Grafana Cloud (darmowy plan) ze stosem w regionie UE i retencją logów 14 dni oraz jeden token
   access policy dla metryk i logów z zakresami `metrics:write`, `logs:write`, `rules:read` i `rules:write`. Podaje agentowi (bez
   tokenu) z portalu stosu: adres zapisu metryk (Prometheus, kończy się `/api/prom/push`) i bazowy adres rulera (ten sam host bez
   `/api/prom/push`), adres zapisu logów (Loki) i identyfikatory instancji (nazwa użytkownika Prometheusa i Lokiego).
2. **agent** – wpisuje je w `argocd/apps/monitoring.yaml` (`destinations.grafana-metrics`: `url`, `rules.address`,
   `auth.usernameFrom` i `tenantIdFrom`; `destinations.grafana-logs`: `url`, `auth.usernameFrom` i `tenantIdFrom`; wartości w
   pliku to przykłady) i, jeśli ruler stosu ma inny prefiks niż `/api/prom`, `rules.prometheusHttpPrefix` (PR). Merge robi
   **użytkownik**.
3. **użytkownik** – token wpisuje w terminalu: `make -C infra/k8s seal-secrets WHAT=grafana` (3.4); **agent** dodaje powstały
   `grafana-cloud.yaml` do PR z sekretami.
4. **agent** – po wdrożeniu `make -C infra/k8s status HOST=<user>@<adres>`: `prometheus-crds` i `monitoring` są `Synced` i
   `Healthy`. Brak konta albo zły token zostawia `Degraded` tylko `monitoring` (log Alloy w przestrzeni `monitoring`); sklep działa i
   się wdraża. W Grafana Cloud widać metryki klastra (np. `up`, `kube_node_info`) i logi (`namespace="shop"`), a logów podów
   `mailpit` nie ma.
5. **agent** – po wdrożeniu przegląd próbki logów w Grafana Cloud (Explore, logi każdego źródła: `api`, `worker`, `store`, `admin`,
   `keycloak`, `postgres`, `imgproxy` i zdarzenia klastra) pod kątem sekretów i danych klientów: haseł, tokenów, nagłówków,
   ciasteczek, adresów e-mail i IP, imion i adresów w treści wyjątków. Znalezisko to nowa reguła maskowania w
   `extraLogProcessingStages` manifestu `monitoring` z wierszem w korpusie `infra/k8s/tests/log-corpus` albo wyłączenie źródła w
   `extraDiscoveryRules` (PR); do czasu poprawki źródło jest wyłączone.
6. **agent** – po wdrożeniu sprawdza w Grafana Cloud liczbę aktywnych serii (darmowy plan: 10 tys.); przy przekroczeniu Grafana Cloud
   odrzuca nadmiar, a agent zawęża listę metryk w manifeście.
7. **użytkownik** – w Grafana Cloud (Alerting → Contact points) dodaje punkt kontaktowy e-mail i ustawia go jako domyślny w drzewie
   powiadomień. Alerty to `NodeDiskFull` z `severity: warning` i reszta z `critical`; czy reguły z rulera trafiają do tego punktu
   kontaktowego, potwierdza dopiero krok 8.
8. **agent** – po wdrożeniu w Grafana Cloud (Alerting → Alert rules) widać siedem reguł grupy `shop-backup` i `shop-cluster`
   (źródło: `PrometheusRule` `shop-alerts` w przestrzeni `shop`, synchronizowany przez Alloy do rulera). **`BackupMissing` i
   `BackupVerifyMissing` odpalają od startu aż do pierwszej udanej kopii i weryfikacji (metryki jeszcze nie ma, reguła ma
   `absent`): to oczekiwane, nie awaria.** Po pierwszej nocnej kopii i weryfikacji gasną.
   To też sprawdzenie od końca do końca, które wykonuje **użytkownik** (agent nie ma dostępu do jego skrzynki): jeśli Grafana
   Cloud powstała przed pierwszą udaną kopią, `BackupMissing` odpala zaraz po załadowaniu reguły (`absent_over_time` bez `for`),
   więc e-mail o nim powinien przyjść wkrótce po synchronizacji reguły. Jeśli nie przyszedł, reguły z rulera mogą trafiać do Alertmanagera
   źródła danych, a nie do Alertmanagera Grafany: punkt kontaktowy i drzewo powiadomień trzeba wtedy ustawić w tym Alertmanagerze
   (jego wybór jest na stronie Contact points), a po zmianie użytkownik sprawdza, że e-mail przychodzi. Jeśli Grafana Cloud
   powstała dopiero po pierwszej udanej kopii, `BackupMissing` nie odpala i brak e-maila niczego nie dowodzi: dostarczenie
   sprawdza wtedy inny alert, który odpala (użytkownik wysyła w Grafana Cloud testowe powiadomienie punktu kontaktowego albo
   czeka na najbliższy alert).
9. **użytkownik** – przed świadomym `restore` i planowanymi pracami (restart serwera, aktualizacja k3s, ćwiczenie odtworzenia)
   wycisza alerty w Grafana Cloud (Alerting → Silences): dla `restore` dopasowanie `alertname=~"WorkloadNotReady|Backup.*"`, dla
   innych prac `alertname="WorkloadNotReady"` (przy wyłączeniu całego serwera także `ClusterSilent`), na czas prac z zapasem.
   Wyciszenia nie zakłada automat. Po pracach sprawdza, że wyciszenie wygasło, albo je usuwa. Brak CRD `PrometheusRule`
   (aplikacja `prometheus-crds` niezsynchronizowana) kończy synchronizację `shop` błędem (operacja sync nieudana, aplikacja `OutOfSync`), a nie
   cichym brakiem reguł.

Aktualizacja chartu `k8s-monitoring` to osobna zmiana: nowa wersja w `argocd/apps/monitoring.yaml`, sha256 archiwum w
`infra/k8s/scripts/monitoring-render.sh` oraz tag i digest obrazu Alloy z chartu w `infra/k8s/scripts/tools.sh` (i tag w
manifeście); `make -C infra/k8s check` pilnuje zgodności, a `make -C infra/k8s monitoring-smoke` uruchamia nową wersję w k3d z RBAC
manifestu: błąd `forbidden` w logu operatora albo Alloy to reguła do dopisania w `extraObjects` (bez sekretów i bez uprawnień do
obiektów RBAC), nie powrót do RBAC chartu. Pod nowej wersji odrzucony przez politykę dopuszczania (zdarzenie `FailedCreate`
z `ValidatingAdmissionPolicy` w przestrzeni `monitoring`, wypisuje je `monitoring-smoke`) to zmiana do przeglądu, nie powód do
poszerzenia wyjątku node-exportera albo usunięcia polityki.

## 10. Błędy i trace'y: Sentry

Błędy i trace'y api (z workerem i Jobami), serwera i przeglądarki sklepu oraz przeglądarki panelu idą do Sentry SaaS
(`docs/features/sentry/spec.md`, `docs/adr/backups-observability.md`). Każda aplikacja włącza SDK tylko przy swoim DSN: chart
przekazuje go jako `SENTRY_DSN` razem z `SENTRY_ENVIRONMENT` (`sentry.environment`, w `prod` `demo`), `SENTRY_RELEASE` (rewizja
obrazów, `image.tag`) i `SENTRY_TRACES_SAMPLE_RATE` (`sentry.tracesSampleRate`, w `prod` 0,1). `prod` ma puste DSN-y, więc do
kroku 3 działa bez Sentry; profil `ci` ich nie ma nigdy. DSN nie jest sekretem (jest w przeglądarce), więc leży w
`environments/prod/values.yaml`, nie w Sealed Secrets; `make -C infra/k8s check` sprawdza jego format
(`https://<klucz>@<host>/<id projektu>`) i `tracesSampleRate` (0 do 1).

1. **użytkownik** – zakłada konto Sentry (darmowy plan) i organizację w regionie UE (adresy `*.ingest.de.sentry.io`) oraz trzy projekty:
   `api` (PHP/Symfony; obejmuje api, worker i Joby), `store` (Next.js) i `admin` (Vue). Z każdego bierze DSN (Settings → Client Keys)
   i podaje go agentowi (DSN nie jest tajny).
2. **użytkownik** – w ustawieniach Sentry (limity darmowego planu są małe, a DSN jawny, więc każdy może wysyłać zdarzenia do
   projektu): włącza ochronę przed skokami (Spike Protection) dla organizacji i ustawia limit zdarzeń na każdym projekcie
   (Client Keys → Configure → Rate Limiting), tak żeby jeden projekt nie zużył miesięcznego limitu. „Data Scrubber” (Security & Privacy)
   zostaje włączony: filtr aplikacji nie zna każdego formatu danych, a Data Scrubber jest drugą warstwą po stronie Sentry.
3. **agent** – wpisuje trzy DSN-y w `sentry.dsn.api`, `sentry.dsn.store` i `sentry.dsn.admin` w
   `infra/k8s/environments/prod/values.yaml` (osobny mały PR, `make -C infra/k8s check`); merge robi **użytkownik**. Zdarzenia zaczynają
   płynąć po następnym wdrożeniu (sekcja 7).
4. **użytkownik** – dla map źródeł sklepu i panelu (wysyłane z budowania obrazów w workflowie `deploy.yml`, tylko przy wdrożeniu z
   `main`) tworzy w Sentry token (Organization Auth Token z prawem wysyłki wydań i map) i zapisuje go w GitHub jako sekret
   Actions `SENTRY_AUTH_TOKEN` (wartość tylko w tym miejscu, nigdy w rozmowie z agentem), a w zmiennych repozytorium (Actions
   variables) `SENTRY_ORG` (slug organizacji), `SENTRY_PROJECT_STORE` i `SENTRY_PROJECT_ADMIN` (slugi projektów). Bez sekretu
   workflow działa, a obrazy powstają bez map (w podsumowaniu runu jest wpis o pominięciu); z sekretem błąd wysyłki kończy
   budowanie błędem.
5. **agent** – po pierwszym wdrożeniu z DSN-ami (`make -C infra/k8s status HOST=<user>@<adres>`: aplikacje `Synced`, `Healthy`) sprawdza, że
   kontenery `api`, `worker`, `store` i `admin` mają `SENTRY_*` (`SENTRY_DSN`, `SENTRY_ENVIRONMENT=demo`, `SENTRY_RELEASE` równe
   rewizji `image.tag`), a **użytkownik** w Sentry, że w trzech projektach pojawiają się zdarzenia z tą wersją i środowiskiem `demo`
   oraz trace'y (próbkowanie 0,1, więc widać je po pewnym ruchu).
6. **użytkownik** (z agentem) – po wdrożeniu przegląd próbki zdarzeń w Sentry (Issues i Performance każdego z trzech projektów,
   także zdarzenie z przeglądarki) pod kątem danych osobowych i tajnych: adresów e-mail, imion i adresów w treści wyjątków,
   tokenów, ciasteczek, nagłówków, treści formularzy, query stringów w adresach, parametrów SQL i identyfikatora koszyka gościa.
   Znalezisko to nowy wzorzec w filtrze zdarzeń aplikacji i w maskowaniu Alloy (`extraLogProcessingStages`) w jednym PR (agent) albo
   wyłączenie źródła (usunięcie DSN z `values.yaml` aplikacji); do czasu poprawki zdarzenia z tym formatem usuwa się w Sentry.
7. **użytkownik** – po pierwszym miesiącu sprawdza zużycie limitów darmowego planu (Usage Stats); przy przekroczeniu Sentry gubi zdarzenia do
   końca okresu rozliczeniowego (aplikacje działają dalej). Powiadomienia i reguły alertów Sentry ustawia w jego ustawieniach, poza
   repozytorium.

Gdy klaster dostanie NetworkPolicy dla ruchu wychodzącego, trzeba dopuścić ruch do `*.ingest.de.sentry.io`.
