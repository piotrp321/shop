# Runbook: od pustego serwera do działającego środowiska

Uruchomienie środowiska wdrożeniowego (`docs/features/deploy/spec.md`) po merge'u epiku DEPLOY. Każdy krok ma znacznik:

- **agent** – wykonuje go Claude Code celem Makefile albo zmianą w repo (PR), gdy użytkownik zlecił uruchomienie środowiska;
- **zgoda użytkownika** – agent opisuje, co zrobi, pyta w rozmowie i czeka na odpowiedź; dopiero potem uruchamia (firewall,
  operacje kasujące dane, zakładanie kont);
- **użytkownik** – robi to użytkownik sam (zakupy, konta zewnętrzne, wartości sekretów, Cloudflare, ruleset i deploy key,
  backup VPS-a); agent tego nie wykonuje i nie podejmuje tych decyzji.

Agent wykonuje operacje na serwerze wyłącznie celami `make -C infra/k8s …` (`bootstrap`, `kubeconfig`, `status`, `rerun`,
`create-admin`, `reinitialize`, `seal-secrets`, `cloudflare-ips`). Nie łączy się z serwerem inaczej i nie uruchamia `kubectl` ani
`helm` na serwerze. Wszystkie cele potrzebują tylko Dockera (oraz `ssh` na komputerze użytkownika). Opcje `ssh` (klucz, port):
`SSH_OPTS="-i ~/.ssh/shop -p 2222"`.

Aplikacje Argo CD, Jobów, fale i reszta mechanizmu: `docs/features/deploy/spec.md`, uzasadnienie: `docs/adr/k3s-argo-cd.md`.

## 0. Przygotowanie

1. **użytkownik** – kupuje VPS (docelowo 8 vCore, 24 GB RAM, Ubuntu 24.04 LTS, OVH) i domenę; domena jest w Cloudflare
   (DNS). Zakłada konto Brevo. Powiadamia agenta o domenie i adresie IP serwera.
2. **użytkownik** – zapisuje odcisk klucza hosta SSH z panelu OVH, łączy się raz ręcznie (`ssh <user>@<adres>`) i porównuje
   odcisk (bootstrap odmawia nieznanego klucza hosta, bo przez SSH przesyła m.in. kopię klucza Sealed Secrets). Użytkownik
   serwera to root albo konto z `sudo` bez hasła (`sudo -n`).
3. **agent** – ustawia domenę w `infra/k8s/environments/prod/values.yaml` (`global.domain`) i nadawcę poczty (`mailer.from`,
   `keycloak.mailFrom`, jeśli ma być inny niż domyślny `zamowienia@`/`konto@<domena>`), PR do `main`; merge robi
   **użytkownik**. Na tym etapie pipeline `deploy.yml` buduje i testuje obrazy, ale bez sekretu `DEPLOY_KEY` kończy się po
   smoke teście z ostrzeżeniem (nic nie publikuje).

## 1. GitHub: obrazy, ruleset, deploy key

Wszystko **użytkownik** (wymaga uprawnień administratora repo).

1. Widoczność pakietów GHCR: po pierwszym udanym runie `deploy.yml` na `main` pakiety `shop/api`, `shop/store`, `shop/admin`
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

4. Uruchom ponownie ostatni run `Deploy` na `main` (Actions → Re-run all jobs; obrazy z tym tagiem zostają, nie są
   przebudowywane). Krok `Publish deploy and deploy-images` tworzy gałęzie `deploy` i `deploy-images`.
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

   Idempotentny: można go uruchomić ponownie. Bez roota (i bez `sudo -n`) albo na innym systemie niż Ubuntu 24.04
   kończy się błędem przed zmianami. Zapisuje certyfikat kontrolera do `infra/k8s/environments/prod/sealed-secrets.pem`,
   gdy repo go nie ma; inny certyfikat w repo niż na serwerze to błąd (nic nie jest nadpisywane: użyj kopii klucza albo
   zaszyfruj sekrety od nowa, zob. „Utrata klucza”). Aplikacja `root` czeka na gałąź `deploy-images` (krok 1.4).
4. **użytkownik** – robi kopię klucza prywatnego kontrolera Sealed Secrets i trzyma ją poza repo i poza serwerem
   (menedżer haseł). Jest jedyną rzeczą, której nie da się odtworzyć z repo:

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
   dla `<domena>` i `*.<domena>` (PEM certyfikatu i klucza).
3. **użytkownik** – wpisuje wartości w terminalu (bez echa, nigdy w argumentach, zmiennych ani rozmowie z agentem):

   ```sh
   make -C infra/k8s seal-secrets WHAT="brevo tls"
   ```

   Login i klucz SMTP Brevo, potem certyfikat i klucz Origin CA wklejone w całości (do wiersza `END`). Plik już istniejący nie
   jest ponownie pytany ani zmieniany. Błąd (pusta wartość, zły PEM, nieudany `kubeseal`) niczego nie zapisuje.
4. **agent** – PR z plikami z `environments/prod/secrets/` (są zaszyfrowane, bezpieczne do commitu); `make -C infra/k8s check`
   wymaga od tego momentu zaszyfrowanej wartości dla każdego klucza `init-env.sh`. Merge robi **użytkownik**.

Nowy sekret w przyszłości: klucz w `infra/scripts/init-env.sh`, szablon sekretu chartu i `make -C infra/k8s seal-secrets WHAT=shop`
w tym samym PR.

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

1. Po merge'ach z 2.5 i 3.4 workflow publikuje snapshot `deploy-images`, a Argo CD synchronizuje `root`, `sealed-secrets`,
   `secrets` i `shop` (fale: kontroler, sekrety, sklep). Pierwsza synchronizacja na pustym klastrze trwa kilkanaście minut
   (Elasticsearch, migracje, wczytanie danych demo).
2. **agent** – `make -C infra/k8s status HOST=<user>@<adres>`: aplikacje `Synced`/`Healthy`, pody `Running`, Joby `Complete`.
   Nieudany Job wersji: log (`status`), poprawka w repo (nowa rewizja) albo `make -C infra/k8s rerun JOB=<nazwa>`. Job
   `initialize` z błędem „mixed state” (przerwane wczytanie): **zgoda użytkownika**, potem
   `make -C infra/k8s reinitialize HOST=<user>@<adres>` (kasuje dane sklepu i wczytuje dane demo od nowa; wymaga słowa `tak`).
3. **zgoda użytkownika** – pierwszy administrator panelu (Keycloak, realm `backoffice`):

   ```sh
   make -C infra/k8s create-admin LOGIN=<login> EMAIL=<e-mail> KUBE_DIR=<katalog z kubeconfigiem>
   ```

   Wypisuje, co zrobi, i działa dopiero po słowie `tak`. Zakłada użytkownika z rolą `admin` bez hasła i wysyła mu e-mail
   (Brevo) z akcjami „ustaw hasło” i „skonfiguruj TOTP” (link ważny 12 h). Hasło i TOTP ustawia **użytkownik** w
   przeglądarce.
4. **użytkownik** – włącza automatyczny backup VPS-a w panelu OVH. To jedyna kopia do czasu DEPLOY-OPS (#178); prawdziwe dane i
   publiczny sklep dopiero po nim.

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

## 7. Eksploatacja

- **Nowa wersja**: merge PR do `main`; pipeline sam publikuje snapshot, a Argo CD synchronizuje klaster. Błąd renderu albo
  migracji zatrzymuje wdrożenie, a poprzednia wersja działa dalej (aplikacja `Degraded`).
- **Cofnięcie wersji**: revert na `main` (nowe wdrożenie). Schemat bazy jest zgodny wstecz tylko z poprzednią wersją kodu;
  cofnięcie o więcej wersji wymaga kopii (DEPLOY-OPS) albo `reinitialize`. Awaryjnie **użytkownik** może zatrzymać automatyczną
  synchronizację aplikacji `shop` w Argo CD i zsynchronizować ją z poprzednim snapshotem `deploy-images` (jego obrazy są w
  GHCR); potem robi revert na `main` i włącza synchronizację z powrotem.
- **Argo CD i inne narzędzia** (bez publicznego adresu): `ssh -L 8080:localhost:8080 <user>@<adres> 'sudo k3s kubectl -n argocd
  port-forward svc/argocd-server 8080:80'`, hasło w sekrecie `argocd-initial-admin-secret` (**użytkownik**).
- **Utrata klucza Sealed Secrets**: bez kopii (2.4) sekretów z repo nie da się odszyfrować. Nowy certyfikat: usuń
  `environments/prod/sealed-secrets.pem` i pliki z `environments/prod/secrets/`, `bootstrap` bez kopii klucza (zapisze nowy
  certyfikat), ponownie sekrety (3.), a bazy zainicjowane starymi hasłami wymagają ponownego wczytania danych albo
  odtworzenia z kopii (DEPLOY-OPS). Wymaga **zgody użytkownika**.
- **Restart serwera**: k3s i usługi wstają same, dane na wolumenach zostają.
