# Runbook: od pustego serwera do działającego środowiska

Uruchomienie środowiska wdrożeniowego (`docs/features/deploy/spec.md`) po merge'u epiku DEPLOY. Każdy krok ma znacznik:

- **agent** – wykonuje go Claude Code celem Makefile albo zmianą w repo (PR), gdy użytkownik zlecił uruchomienie środowiska;
- **zgoda użytkownika** – agent opisuje, co zrobi, pyta w rozmowie i czeka na odpowiedź; dopiero potem uruchamia (firewall,
  operacje kasujące dane, zakładanie kont);
- **użytkownik** – robi to użytkownik sam (zakupy, konta zewnętrzne, wartości sekretów, Cloudflare, ruleset, środowiska `production` i `sentry`, klucz GitHub App i sekret środowiska `merge-override`, deploy key,
  backup VPS-a); agent tego nie wykonuje i nie podejmuje tych decyzji.

Agent wykonuje operacje na serwerze wyłącznie celami `make -C infra/k8s …` (`bootstrap`, `kubeconfig`, `status`, `rerun`,
`create-admin`, `restore`, `backups`, `failed`, `failed-retry`, `failed-remove`, `reinitialize`, `generate`, `open`, `reindex`, `seal-secrets`, `cloudflare-ips`). Nie łączy się z serwerem inaczej i nie uruchamia `kubectl` ani
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
   buduje i testuje obrazy, a job `deploy` czeka na zatwierdzenie w środowisku `production` (1.3); bez sekretu `DEPLOY_KEY`
   tego środowiska kończy się po nim z ostrzeżeniem (nic nie publikuje).

## 1. GitHub: obrazy, ruleset, środowiska `production`, `sentry` i `merge-override`, deploy key

Wszystko **użytkownik** (wymaga uprawnień administratora repo), poza częścią kroku 6 oznaczoną inaczej.

1. Widoczność pakietów GHCR: po pierwszym udanym wdrożeniu (**zgoda użytkownika**, `gh workflow run deploy.yml --ref main`, sekcja 7) pakiety `shop/api`, `shop/store`, `shop/admin`
   i `shop/elasticsearch` ustaw na **publiczne** (Packages → pakiet → Package settings → Change visibility), żeby k3s
   pobierało je bez `imagePullSecret`. Nie włączaj żadnej retencji ani czyszczenia wersji pakietów: obrazy z digestami, do
   których odwołują się snapshoty `deploy-images`, są potrzebne do cofnięcia wersji (workflow też niczego nie usuwa).
2. Rulesety (każdy plik osobno; oba obejmują `deploy` i `deploy-images`):

   ```sh
   gh api repos/piotrp321/shop/rulesets -X POST --input tools/rulesets/deploy.json
   gh api repos/piotrp321/shop/rulesets -X POST --input tools/rulesets/deploy-push.json
   ```

3. Środowisko `production` i deploy key z prawem zapisu. Job `deploy` workflowu (jedyny, który używa `DEPLOY_KEY`) działa
   w środowisku `production`, a każdy sekret Actions poza `GITHUB_TOKEN` leży wyłącznie w środowisku, które przyjmuje tylko
   gałąź `main` (workflow z gałęzi zadania nie dostaje wtedy ani sekretu, ani zatwierdzenia). Środowisko trzeba skonfigurować
   **przed pierwszym przebiegiem** workflowu z `environment: production`: GitHub zakłada brakujące środowisko sam i bez żadnych
   zabezpieczeń. Settings → Environments → New environment `production`:
   - **Required reviewers**: użytkownik (zatwierdza wdrożenie; agenci nie mają uprawnienia, a token wykonawców AO nie ma
     Deployments: write). **Prevent self-review** wyłączone: użytkownik uruchamia wdrożenie i sam je zatwierdza, a przy
     włączonej opcji nikt nie mógłby go zatwierdzić.
   - **Deployment branches and tags** → Selected branches and tags → reguła `main` (nazwa gałęzi, bez wzorców i tagów).
   - **Environment secrets**: `DEPLOY_KEY` (poniżej). Sekret repozytorium o tej nazwie nie może istnieć: sekrety repozytorium
     są widoczne w każdym workflowu, także z gałęzi zadania.

   Repo ma mieć **tylko jeden** deploy key, bo aktor `DeployKey` obejmuje każdy:

   ```sh
   ssh-keygen -t ed25519 -N '' -C shop-deploy -f /tmp/shop-deploy-key
   gh repo deploy-key list                      # powinno być puste
   gh repo deploy-key add /tmp/shop-deploy-key.pub --allow-write --title shop-deploy
   gh secret set DEPLOY_KEY --env production < /tmp/shop-deploy-key
   rm -f /tmp/shop-deploy-key /tmp/shop-deploy-key.pub
   ```

   Sekret repozytorium `DEPLOY_KEY` założony wcześniej (zanim workflow dostał środowisko) **użytkownik** usuwa po merge'u końcowego
   PR epiku AGENT-SECURITY: `gh secret delete DEPLOY_KEY`. Wartości sekretu GitHub nie zwraca, więc klucz do środowiska bierze
   z menedżera haseł albo generuje nowy (stary deploy key wtedy usuwa: `gh repo deploy-key delete <id>`).

   Środowisko `sentry` (sekret `SENTRY_AUTH_TOKEN` map źródeł, sekcja 10, krok 4): **użytkownik** zakłada je przed pierwszym
   wdrożeniem z workflowem, który ma `environment: sentry` w jobie `images`. Settings → Environments → New environment
   `sentry`, **bez** Required reviewers (job `images` ma działać bez zatwierdzenia), **Deployment branches and tags** → Selected
   branches and tags → reguła `main`. Brakujące środowisko GitHub zakłada sam i bez zabezpieczeń, więc kolejność ma znaczenie.
   Sekret repozytorium `SENTRY_AUTH_TOKEN`, jeśli istnieje, **użytkownik** usuwa po merge'u końcowego PR epiku AGENT-SECURITY:
   `gh secret delete SENTRY_AUTH_TOKEN` (wartości GitHub nie zwraca, więc token bierze z menedżera haseł albo tworzy nowy w
   Sentry). Kolejny sekret Actions dostaje środowisko tylko dla `main` (bez zatwierdzenia, jeśli ma działać bez niego) i trafia
   do listy `MAIN_ONLY_ENVIRONMENTS` w `infra/k8s/tests/test_deploy_workflow.py` razem z opisem tutaj; test czyta pliki
   workflowów, ale ustawień środowisk w GitHubie nie widzi, więc te kroki **użytkownik** sprawdza sam.

4. **zgoda użytkownika** – uruchom wdrożenie ponownie: `gh workflow run deploy.yml --ref main` (obrazy z tagiem tego
   samego commita zostają, nie są przebudowywane). Krok `Publish deploy and deploy-images` tworzy gałęzie `deploy` i `deploy-images`.
5. Próby rulesetów, osobno dla `deploy` i dla `deploy-images` (zapisz wyniki w komentarzu do zgłoszenia #175):

   | Próba | Oczekiwany wynik |
   |---|---|
   | push zwykłym poświadczeniem użytkownika, np. `git push origin origin/main:refs/heads/deploy` (dla `deploy-images` ten sam commit na `refs/heads/deploy-images`) | odrzucony („Repository rule violations”) |
   | usunięcie: `git push origin :refs/heads/deploy` (i `:refs/heads/deploy-images`) | odrzucone |
   | force push starszego commita: `git push --force origin origin/main~1:refs/heads/deploy` (i `deploy-images`) | odrzucony |
   | push deploy keyem | przyjęty: to udany krok `Publish deploy and deploy-images` ze zwykłego runu; ręcznie na `deploy-images` nie pushujemy (snapshot tworzy tylko workflow) |
6. Merge override: merge PR do `main` z pominięciem wymaganych checków (`docs/features/agent-security/spec.md`, R8).
   Kolejność ma znaczenie: środowisko musi istnieć **przed** pierwszym przebiegiem workflowu `merge-override.yml`,
   bo GitHub zakłada brakujące środowisko sam i bez zabezpieczeń. App i środowisko bez klucza (formularz App,
   zatwierdzający, polityka gałęzi, zmienna) agent może założyć na wyraźne polecenie w rozmowie (**zgoda
   użytkownika**). Klucz prywatny App, sekret `MERGE_APP_PRIVATE_KEY`, ruleset i zatwierdzanie przebiegów to zawsze
   **użytkownik**: agent klucza nie generuje, nie pobiera i nie wpisuje.
   1. GitHub App: Settings (konta) → Developer settings → GitHub Apps → New GitHub App. Nazwa dowolna, Homepage URL
      adres repozytorium, **Webhook** wyłączony, **Repository permissions**: Contents „Read and write”, Pull requests
      „Read and write”, Workflows „Read and write”; „Only on this account”. Po utworzeniu: zapisz **App ID** (liczba)
      i **Client ID**, wygeneruj klucz prywatny (plik `.pem`; GitHub wymaga go przed instalacją), a w **Install App**
      zainstaluj ją tylko na `piotrp321/shop` („Only select repositories”). App ID jest w `tools/rulesets/main-merge.json` i w jego teście
      (`MERGE_APP_ID`): nowa App to PR zmieniający oba miejsca, a test z innym identyfikatorem nie przechodzi.
   2. Środowisko: Settings → Environments → New environment `merge-override`:
      - **Required reviewers**: użytkownik; **Prevent self-review** wyłączone (jak w `production`);
      - **Deployment branches and tags** → Selected branches and tags → reguła `main`;
      - **Environment secrets**: `MERGE_APP_PRIVATE_KEY` (cała treść pliku `.pem`);
      - **Environment variables**: `MERGE_APP_CLIENT_ID` (Client ID App).

      Po zapisaniu sekretu usuń plik `.pem` z dysku: klucz pozwala zmergować dowolny PR z pominięciem checków.
   3. Ruleset, po merge'u zmiany pliku do `main` (agent rulesetów nie zmienia):

      ```sh
      id="$(gh api repos/piotrp321/shop/rulesets --jq '.[] | select(.name == "main-merge") | .id')"
      gh api -X PUT "repos/piotrp321/shop/rulesets/$id" --input tools/rulesets/main-merge.json
      gh api "repos/piotrp321/shop/rulesets/$id" --jq '{bypass_actors, current_user_can_bypass}'
      ```

      Oczekiwane: jeden aktor `Integration` w trybie `pull_request` i `current_user_can_bypass` równe `never`
      (bypass ma App, nie konto).
   4. Użycie: `gh workflow run merge-override.yml --ref main -f pr=<nr> -f sha=<commit głowy PR> -f reason=<powód>`
      (użytkownik albo sesja na jego wyraźne polecenie w rozmowie) albo Actions → Merge override → Run workflow. Job
      `validate` pokazuje w podsumowaniu, co zostanie zmergowane; job `merge` czeka na zatwierdzenie: **użytkownik**
      zatwierdza je na stronie przebiegu (Review deployments), z powiadomienia albo w aplikacji mobilnej GitHuba; agent
      tego nie robi. Odrzucenie (Reject) kończy przebieg bez merge'a. Tytuł przebiegu podaje numer PR i SHA: zatwierdzaj
      tylko przebieg, który sam zleciłeś, a po pushu do PR odrzuć stary przebieg i uruchom nowy z nowym SHA.
   5. Wyciek albo podejrzenie wycieku klucza: od razu usuń klucz w ustawieniach App (Private keys → Delete) albo
      odinstaluj App; od tej chwili nikt nim nie zmerguje. Potem wygeneruj nowy klucz i podmień sekret
      `MERGE_APP_PRIVATE_KEY` albo wycofaj obejście (niżej).
   6. Wycofanie: odinstaluj App i usuń środowisko `merge-override` (obejście przestaje działać), potem PR, który
      przywraca w `tools/rulesets/main-merge.json` i teście pustą listę `bypass_actors` i usuwa workflow, a po jego
      merge'u wgraj plik jak w kroku 3.

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
6. **agent** – `kubeconfig` pobiera kubeconfig klastra (dane administratora klastra, nie trafia do repo) do katalogu, z
   którego korzystają `rerun`, `create-admin`, `restore`, `backups`, `generate`, `open` i `reindex` (`KUBE_DIR`). Katalog powstaje na jedną operację i znika
   po niej:

   ```sh
   KUBE_DIR="$(mktemp -d)"                      # katalog udostępniony Dockerowi (cele montują go w kontenerze)
   make -C infra/k8s kubeconfig HOST=<user>@<adres> KUBE_DIR="$KUBE_DIR"
   make -C infra/k8s backups KUBE_DIR="$KUBE_DIR"   # albo rerun, create-admin, restore
   rm -rf "$KUBE_DIR"
   ```

   - Trwałych kubeconfigów produkcji nie trzyma się w katalogach udostępnionych Dockerowi ani w katalogu domowym: kontener
     wykonawcy AO i jego Docker widzą te katalogi, a kubeconfig daje administratora klastra. Przy każdej operacji pobiera się go
     od nowa.
   - `kubeconfig` zapisuje bezwzględną ścieżkę `KUBE_DIR` w rejestrze `~/.local/state/shop-ops/kube-dirs` (prywatny plik, jedna
     ścieżka w wierszu).
   - `kubeconfig`, `rerun`, `create-admin`, `restore`, `backups`, `generate`, `open` i `reindex` odmawiają pracy (kod różny od 0, bez żadnych zmian), gdy
     działa AO (`python3 -m orchestrator … run`) albo jego wykonawca (proces `claude-agent` albo runner AO): zatrzymaj AO albo
     poczekaj na koniec przebiegu (wykonawca przeżywa śmierć AO, więc samo zatrzymanie AO nie zawsze wystarcza: poczekaj, aż
     skończą się jego procesy). Błąd `ps` też jest odmową. Test dymny (`smoke`) tych odmów nie dotyczy. Słowo `tak` w
     `create-admin`, `restore`, `generate` i `reindex` podaje się na standardowym wejściu tego celu.

## 3. Sekrety

Wymaga certyfikatu w repo (2.5).

1. **agent** – `make -C infra/k8s seal-secrets WHAT=shop`: klucze z `infra/scripts/init-env.sh` z losowymi wartościami do
   `infra/k8s/environments/prod/secrets/shop-secrets.yaml`. Istniejących kluczy nie zmienia (wartości są już w bazach). Hasło
   roli `exporter` (`EXPORTER_DB_PASSWORD`) pieczętuje też w `shop-exporter.yaml` (DSN dla eksportera Postgresa w Alloy, który
   nie czyta `shop-secrets`): oba pliki powstają z jednej losowej wartości w jednym przebiegu. Zapieczętowanego hasła nie da się
   odczytać, więc `shop-secrets.yaml` z tym kluczem bez `shop-exporter.yaml` zatrzymuje skrypt; wtedy usuwa się z niego wiersz
   `EXPORTER_DB_PASSWORD` i uruchamia go jeszcze raz (migracja ustawia rolę na nowe hasło; baza ma je z `shop-secrets`).
2. **użytkownik** – w Brevo (SMTP & API) tworzy klucz SMTP; w Cloudflare (SSL/TLS → Origin Server) wystawia certyfikat Origin CA
   dla `<domena>` i `*.<domena>` (PEM certyfikatu i klucza). Na koncie Cloudflare zakłada bucket R2 na kopie zapasowe i token
   API R2 z dostępem tylko do tego bucketu (klucz i sekret S3); wymyśla hasło repozytorium restic i zapisuje je w menedżerze
   haseł (poza serwerem: bez niego kopie są nie do odczytania, także po utracie klucza Sealed Secrets). W Grafana Cloud zakłada
   stos i token dla Alloy (9.).
3. **agent** – wpisuje adres repozytorium (`s3:https://<id konta>.r2.cloudflarestorage.com/<bucket>`, id konta podaje
   użytkownik) w `backup.repository` i region `auto` w `environments/prod/values.yaml` (PR) oraz adresy i identyfikatory instancji
   Grafana Cloud w `argocd/apps/monitoring.yaml` (9.). Kopie są w `prod` wyłączone (`backup.enabled: false`), dopóki nie ma
   bucketu i sekretu `shop-backup` (krok 4); `backup.enabled: true` trafia do tego samego PR co zaszyfrowany `shop-backup` (krok 5).
   Wcześniej trzeba przynajmniej raz uruchomić `bootstrap` z wersją `argocd/install/argocd.yaml`, która ma customizację zdrowia
   PVC (inaczej synchronizacja `shop` czeka bez końca na PVC `backup-work`, który do pierwszej kopii jest `Pending`).
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
   Nieudany Job wersji: log (`status`), poprawka w repo (nowa rewizja) albo `make -C infra/k8s rerun JOB=<nazwa>`.
   `rerun` służy tylko Jobom wersji (`<job>-<hash>`): Joba `restore-run-*` nie ponawia się nim nigdy, bo powtórzyłby
   odtworzenie bez pytania o `tak` i bez kopii `pre-restore` (odtworzenie ponawia `make restore`, sekcja 8). Job
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
| Reguły alertów w Grafana Cloud i punkt kontaktowy e-mail (9.) | agent, konto serwisowe i punkt kontaktowy: użytkownik | jedenaście reguł zarządzanych przez Grafanę w folderze `agent/shop/…` (siedem przy wyłączonych kopiach), alert z reguły dochodzi e-mailem; `BackupMissing` i `BackupVerifyMissing` odpalają do pierwszej kopii i weryfikacji (oczekiwane) |
| Grafana Cloud (9.) | agent | metryki klastra i logi `namespace="shop"` są, logów `mailpit` nie ma, przegląd próbki logów bez sekretów i danych klientów |
| Sentry (10.): po wpisaniu DSN-ów i wdrożeniu | użytkownik | zdarzenia i trace'y w projektach `api`, `store` i `admin` z wersją (rewizja obrazu) i środowiskiem `demo`; przegląd próbki zdarzeń bez danych osobowych |
| AppArmor (sekcja 2, krok 3): na serwerze `sudo dmesg \| grep 'apparmor="DENIED"' \| grep -c cri-containerd` po `reinitialize` i `create-admin` | użytkownik | wpisy `signal` są oczekiwane (szum); oba cele kończą się sukcesem, a pody nie restartują się przez sondy |

## 7. Eksploatacja

- **Nowa wersja**: merge PR do `main` niczego nie wdraża. Wdrożenie startuje tylko na życzenie: **zgoda użytkownika**, potem
  `gh workflow run deploy.yml --ref main` (obrazy, smoke test i `make -C infra/k8s check`, publikacja snapshotu; postęp: `gh run list --workflow deploy.yml`,
  `gh run watch`), a Argo CD synchronizuje klaster. Job `deploy` czeka na zatwierdzenie w środowisku `production`: **użytkownik**
  zatwierdza je na stronie przebiegu (Review deployments), z powiadomienia albo w aplikacji mobilnej GitHuba; agent tego nie robi.
  Wdraża się bieżący commit `main`; przebieg czekający na zatwierdzenie zajmuje grupę `concurrency` dla `main`, więc nowsze
  przebiegi czekają za nim (najwyżej jeden, najnowszy: trzecie uruchomienie zastępuje drugie). Odrzucenie starszego
  (Reject) przepuszcza najnowszy, który wdraża bieżący commit `main`, a przebieg niezatwierdzony w ciągu 30 dni wygasa. Błąd renderu albo migracji zatrzymuje wdrożenie, a poprzednia wersja działa dalej
  (aplikacja `Degraded`). Uruchomienie na innej gałęzi (`--ref <gałąź>`) to tylko próba: smoke test, nic nie jest
  wypychane i `deploy` się nie przesuwa.
- **Cofnięcie wersji**: revert na `main` i nowe uruchomienie wdrożenia. Schemat bazy nie musi być zgodny z poprzednią wersją kodu (etap demo, `docs/vision.md`);
  cofnięcie wersji po zmianie niezgodnej to synchronizacja `shop` ze starszym snapshotem `deploy-images` (jak niżej) i odtworzenie kopii
  z czasu tej wersji (8.; `restore` odmawia kopii nowszej niż wdrożony kod) albo `reinitialize`. Awaryjnie **użytkownik** wyłącza w Argo CD
  automatyczną synchronizację aplikacji `root`, a potem aplikacji `shop` (kolejność ma znaczenie: `root` z `selfHeal`
  przywraca z gita `syncPolicy` aplikacji `shop`, a Argo CD nie synchronizuje do innej rewizji aplikacji z automatyczną
  synchronizacją) i synchronizuje `shop` z poprzednim snapshotem `deploy-images` (jego obrazy są w GHCR). Potem robi revert
  na `main`, uruchamia wdrożenie (**zgoda użytkownika**, `gh workflow run deploy.yml --ref main`), a po opublikowaniu
  nowego snapshotu włącza automatyczną synchronizację `root` (z `prune` i `selfHeal`, jak w
  `argocd/root.yaml`): `root` przywraca z gita automatyczną synchronizację `shop`, która wdraża nowy snapshot.
- **Przebudowa indeksu wyszukiwarki** – **zgoda użytkownika** (nazywa operację i host): `make -C infra/k8s reindex
  KUBE_DIR=<katalog z kubeconfigiem>`. Potrzebna po zmianie `elasticsearch.shards` (istniejący indeks zachowuje swoją
  liczbę shardów) i gdy indeks rozjechał się z bazą. Cel wypisuje indeks aliasu `products` i liczbę shardów nowego,
  pyta o `tak`, uruchamia Job `reindex-<czas UTC>` z zawieszonego CronJoba `reindex` i sprawdza indeks po nim.
  Sklep działa w trakcie na starym indeksie; milion produktów to na serwerze ok. 17 minut. Cel trzyma do końca blokadę
  `demo-data-lock`, więc `generate` i `open` w tym czasie odmawiają. **Nie wdrażaj nowej wersji w trakcie przebudowy**:
  Job `migrate` wdrożenia kończy się wtedy błędem (`catalog:search-index:setup` odmawia przy trwającej przebudowie),
  a po jej końcu trzeba go ponowić (`make -C infra/k8s rerun JOB=<migrate-…>`). Odmowa (kod 1, „nothing changed”):
  aktywny Job w przestrzeni nazw (poczekaj na koniec; Joby CronJobów `rate-limit-check`, `queue-check` i `order-expiry` nie
  są powodem odmowy), blokada `demo-data-lock` innego przebiegu albo nieczytelny indeks. Porażka (kod 1, „FAILED”):
  komunikat mówi, na którym indeksie jest alias (nieudany Job zostawia go na starym), log Joba jest w wyjściu celu i w Grafana Cloud; po usunięciu przyczyny uruchom cel ponownie (nie `rerun`). Liczba
  dokumentów w wyjściu celu to dokumenty Lucene (razem z zagnieżdżonymi wariantami), więcej niż produktów w logu Joba. Zmiana produktu, ceny albo stanu zapisana w trakcie budowy może nie trafić do nowego indeksu
  do następnej zmiany tego produktu, więc przebudowę uruchamiaj poza godzinami zmian w panelu. Powrót do poprzedniej
  liczby shardów: zmiana `elasticsearch.shards`, wdrożenie i ponowna przebudowa.
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
- **`RateLimitsOff`** (`warning`; od 12 do 17 minut od początku awarii i czas ewaluacji reguły): limity tras przepuszczają ruch
  bez ograniczeń, bo Redis limiterów nie przyjmuje zapisu (odrzuca zapisy, np. `MISCONF` po pełnym wolumenie, albo pod API go
  nie sięga; sonda Redisa i `/health` tego nie widzą) albo CronJob `rate-limit-check` nie działa (wstrzymany, usunięty, brak
  metryki). **Agent** czyta log ostatniego Joba `rate-limit-check` w sekcji „logs of the failed jobs” wyniku
  `make -C infra/k8s status HOST=…` (komunikat „The Redis of the rate limiters failed: …” podaje przyczynę: odmowa
  połączenia, limit czasu, błąd odpowiedzi Redisa, inna wartość) oraz stan poda i wolumenu Redisa (`status`: zdarzenia, miejsce
  na dysku węzła z Grafana Cloud). Wolumen pełny: miejsce zwalnia **użytkownik** (jak przy `NodeDiskFull`). CronJob wstrzymany
  po przerwanym `restore`: „Odtworzenie przerwane w trakcie” niżej (`restore` ponowiony przywraca `suspend: false`). Alert
  gaśnie po następnym udanym przebiegu (do 5 minut od naprawy). Po pierwszym wdrożeniu chartu z tym CronJobem metryki jeszcze
  nie ma (`absent_over_time` bez `for`, jak przy `BackupMissing`): alert odpala do pierwszego udanego przebiegu, najwyżej kilka
  minut, i to nie jest awaria. Awaria krótsza niż ok. 10 minut (mniej niż trzy przebiegi)
  nie wywołuje alertu; ostrzeżenia kanału `cache` w logach `api` pokazują pojedyncze błędy. Wyciszenie na czas `restore` i
  prac: 9. w sekcji 9.
- **`MessagingUnhealthy`** (`warning`, CronJob `queue-check`; odpala do ok. 15 minut od zdarzenia, gaśnie po następnym udanym
  przebiegu): w `failed` leży wiadomość albo najstarsza gotowa wiadomość jakiejś kolejki jest starsza niż 10 minut (worker stoi,
  zawisł albo nie nadąża). Log nieudanego Joba `queue-check` ma sekcja „logs of the failed jobs” wyniku
  `make -C infra/k8s status HOST=…` (bez treści wiadomości); ekran „Stan systemu” w panelu pokazuje, które kolejki stoją.
  1. **agent** – przegląd, tylko odczyt: `make -C infra/k8s failed HOST=…` (statystyki i do 200 najstarszych wiadomości: ID,
     klasa, błąd, data) i `status` (pody `worker` i `worker-commands`, restarty, zdarzenia). Stoi kolejka bez wiadomości
     w `failed`: worker nie działa (`WorkloadNotReady`, log poda w Grafana Cloud), a nie wiadomość.
  2. **agent** – ustala przyczynę z błędu przy wiadomości (`failed` pokazuje go przy każdej) i logu `worker` w Grafana Cloud.
     Poprawka w kodzie albo konfiguracji idzie normalnym PR i wdrożeniem (**zgoda użytkownika**); ponawianie przed nią tylko
     zużywa wiadomości na ten sam błąd.
  3. **zgoda użytkownika** – ponowienie po usunięciu przyczyny: `make -C infra/k8s failed-retry HOST=… ID=<id>` (jedna
     wiadomość: `messenger:failed:retry <id> --force`) albo bez `ID` ponowienie zbiorcze (`app:messaging:failed-retry`,
     najstarsze `MAX` wiadomości, domyślnie 200, najwyżej 1000; raport mówi, ile obsłużono, ile zachowano po błędzie i czy
     „zostały kolejne”: wtedy to samo polecenie jeszcze raz). Zbiorczego `messenger:failed:retry` bez ID ani
     `messenger:consume failed` aplikacja nie przepuszcza (kod 113), a cele ich nie wołają. Co trzeba wiedzieć:
     - **ID się zmienia.** Wiadomość, której ponowienie zawiodło, zostaje w `failed` pod nowym ID (raport podaje stare i nowe),
       więc kolejne ponowienie albo usunięcie bierze ID z `make failed`, nie z poprzedniego wyniku. Ponowienie z błędem,
       który Symfony każe ponowić (`Recoverable…`), zostawia wiadomość z opóźnieniem: przez chwilę nie ma jej na liście
       `failed`, choć pulpit ją liczy.
     - **Bez gwarancji „dokładnie raz”.** Przerwanie procesu (restart poda, `Ctrl-C`, utrata połączenia SSH) może zostawić
       duplikat wiadomości: obsługa jest idempotentna, a nadmiarową kopię usuwa `failed-remove`.
     - Proces ponowienia uruchamia się w podzie `worker-commands` z `php -d memory_limit=128M`, a przebieg nie kończy się
       sam: sprawdź wynik `make failed` po nim.
  4. **zgoda użytkownika** – usunięcie wiadomości, której nie da się ani nie warto obsłużyć (np. zamówienie już nie istnieje,
     duplikat): `make -C infra/k8s failed-remove HOST=… ID=<id>` pokazuje wiadomość i usuwa ją dopiero po słowie `tak`;
     nie do cofnięcia. **Nigdy nie usuwa się wiadomości z webhookiem płatności** (np. o opłaceniu): jej utrata zostawia
     opłacone zamówienie jako nieopłacone, a dostawca nie wyśle jej drugi raz. Takie wiadomości tylko ponawia się po
     naprawie przyczyny; gdy nie da się jej naprawić, decyzję podejmuje właściciel.
  5. **agent** – sprawdza wynik: `make failed` pokazuje pusty `failed`, ekran „Stan systemu” jest zdrowy, a następny Job
     `queue-check` kończy się sukcesem i alert gaśnie.
  - **Zator `worker-commands` dłuższy niż karencja wygaszania** (alert po 10 minutach zatoru): `order-expiry` anuluje
    wtedy zamówienia po terminie także wtedy, gdy webhook ich opłacenia czeka jeszcze w kolejce `payment_simulator`
    (obsługa komend stoi). Po przywróceniu workera i opróżnieniu kolejki **agent** sprawdza w logach `api` w Grafana Cloud
    z okresu zatoru wpisy poziomu `error` z komunikatem
    „Payment webhook: the payment cannot pay its order and requires a manual refund.” (webhook opłacenia zamówienia, które już anulowano; pola `orderId`, `paymentId`, `reason`, czyli
    `order_cancelled`, `paid_after_deadline` albo `duplicate`). Ten sam powód zapisuje kolumna `refund_required_reason`
    płatności `succeeded` w bazie (do odczytu zleca **użytkownikowi**, agent nie sięga do bazy). Zgłasza zamówienia
    użytkownikowi do zwrotu (rejestru zwrotów nie ma); sam zwrotów nie wykonuje. Wiadomości z webhookami płatności ze stojącej kolejki się nie usuwa.
- **`OrderExpiryFailed`** (`critical`, CronJob `order-expiry`; do ok. 15 minut): Job wygaszania zamówień po terminie i zamykania
  porzuconych płatności (`ordering:expire-overdue`, potem `payment:close-abandoned`, zawsze obie) kończy się błędem.
  **Agent** czyta log nieudanego Joba `order-expiry` w sekcji „logs of the failed jobs” wyniku `status`: log wskazuje
  identyfikator zamówienia albo płatności, której przebieg nie obsłużył (błąd stanu rezerwacji, niedostępny dostawca płatności,
  baza), albo przekroczony limit czasu (duża zaległość: kolejne przebiegi ją nadrabiają i kończą pracę, bo komendy są
  idempotentne). Jedna z przyczyn to wpis `payment:close-abandoned: the order of the payment could not be read` (pola
  `paymentId`, `orderId`, `reason`; szukaj go w logu Joba i w logach `api` w Grafana Cloud): zamówienie płatności `pending`,
  którego Ordering nie potrafi odczytać (brak zamówienia, uszkodzone dane). Płatność zostaje `pending`, a każdy kolejny
  przebieg kończy się tym samym błędem (kod 2), więc alert pali się stale, dopóki dane się nie zmienią; to błąd danych do
  decyzji **właściciela** (naprawa albo zamknięcie płatności), nie coś, co naprawi ponowienie. Pojedyncze zamówienie z błędem
  zostaje `new` do poprawki, a reszta jest wygaszona. Przyczynę usuwa poprawka
  w kodzie (PR, wdrożenie: **zgoda użytkownika**) albo decyzja właściciela co do zamówienia lub płatności (**użytkownik**:
  ręczne anulowanie, zwrot). Wiadomość `ExpireUnpaidOrder` w `failed` wygasza CronJob, więc jej ponawianie nie jest
  potrzebne; `MessagingUnhealthy` z tego powodu można zamknąć usunięciem wiadomości (4.), jeśli zamówienie jest już
  `cancelled`. Alert gaśnie po następnym udanym Jobie.
- **`OrderExpiryMissing`** (`critical`; brak udanego przebiegu `order-expiry` od 30 minut albo brak jego metryki): CronJob
  jest wstrzymany (`suspend: true`) po przerwanym `restore` albo ręcznie, usunięty albo Joby nie startują (węzeł wyłączony,
  Alloy bez metryk: razem z nim `ClusterSilent`). **Agent** czyta stan (`status`: Joby i zdarzenia) i wynik
  `ssh <user>@<adres> 'sudo k3s kubectl -n shop get cronjob order-expiry queue-check -o wide'` zleca **użytkownikowi** (agent nie
  uruchamia `kubectl` na serwerze). `suspend: true` po przerwanym odtworzeniu: „Odtworzenie przerwane w trakcie” w 8.
  (`restore` ponowiony przywraca `suspend: false`), a po ręcznym wstrzymaniu zdejmuje je **użytkownik**:
  `ssh <user>@<adres> 'sudo k3s kubectl -n shop patch cronjob order-expiry -p "{\"spec\":{\"suspend\":false}}"'`. Zaległe
  zamówienia i płatności nadrabia pierwszy udany przebieg. Alert gaśnie po nim.
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

  `restore` sprawdza (bez zmian) brak aktywnych Jobów (poza Jobami CronJobów `rate-limit-check`, `queue-check` i `order-expiry`, które trwają najwyżej 60, 120 i 600 s i nie są powodem odmowy), brak trwającej synchronizacji `shop` w Argo CD i zgodność migracji
  snapshotu z wdrożonym kodem, wypisuje snapshot i skutki i czeka na słowo `tak` (wpisuje je agent dopiero po zgodzie). Potem:
  kopia `pre-restore`, wyłączenie automatycznej synchronizacji `root`, potem `shop` (przejdzie też, gdy jej już nie ma),
  wstrzymanie CronJobów (`cart-purge-expired`, `backup`, `rate-limit-check`, `queue-check`, `order-expiry`), ponowne sprawdzenie Jobów, także Jobów trzech ostatnich (nocna kopia mogła wystartować po pierwszym sprawdzeniu: `restore` czeka
  na nią do 2 godzin, `RESTORE_ACTIVE_JOBS_WAIT_SECONDS`, a potem kończy się błędem z działającymi aplikacjami), `api`, `worker`,
  `worker-commands`, `store` i `keycloak` do zera, odtworzenie danych, `migrate`, `keycloak-config`, reindeks, powrót aplikacji, `purge-caches` i
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
  pierwszej próby już jest; aplikacje na zerze wracają z jedną repliką, a wstrzymane `cart-purge-expired`, `backup`, `rate-limit-check`, `queue-check` i `order-expiry` z `suspend: false`) albo wraca do stanu sprzed odtworzenia:
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
Alloy czyta z klastra tylko sekrety `grafana-cloud` i `shop-exporter` (DSN roli `exporter` Postgresa) z przestrzeni `shop`, a Alloy Operator działa tylko w przestrzeni `monitoring`,
bez ClusterRoli; RBAC operatora i kolektora jest w `extraObjects` manifestu, nie w chartach. Polityki dopuszczania z tego samego
manifestu odrzucają w `monitoring` pody z dostępem do hosta (poza node-exporterem) albo z klasą priorytetu `system-*`, Service
inne niż `ClusterIP` albo z `externalIPs` i sekrety z tokenami kont, także żądania administratora; `kubectl debug` poda
node-exportera też jest odrzucany. Klaster nie ma stałej NetworkPolicy (jedyna, tymczasowa `shop-closed` zamyka sklep
na czas generowania danych: sekcja 11), a Redis (sesje klientów) i Elasticsearch działają bez
uwierzytelnienia: każdy pod klastra, także w `monitoring`, sięga do nich po sieci (ryzyko opisane w
`docs/adr/backups-observability.md`).

Alerty to `PrometheusRule` `shop-alerts` chartu `shop` (`alerts.enabled: true` w `prod`; `docs/features/deploy-ops/spec.md`, O6):
`BackupFailed`, `BackupMissing`, `BackupVerifyFailed`, `BackupVerifyMissing`, `RateLimitsOff` (brak udanego sprawdzenia Redisa
limiterów od 17 minut), `MessagingUnhealthy` (nieudany Job `queue-check`: wiadomość w `failed` albo kolejka stoi),
`OrderExpiryFailed` (nieudany Job `order-expiry`), `OrderExpiryMissing` (brak udanego `order-expiry` od 30 minut), `NodeDiskFull` (ponad 85% przez 10 minut), `ClusterSilent` (brak metryk klastra przez 15 minut) i `WorkloadNotReady` (Deployment albo StatefulSet w `shop` niegotowy albo
usunięty przez 10 minut). Alloy synchronizuje je przez API importu Grafany stosu (`/api/convert`, cel `grafana-alerting`) jako
reguły zarządzane przez Grafanę; ewaluuje je Grafana Cloud poza serwerem, więc alert przychodzi także wtedy, gdy serwer albo Alloy
nie żyje, a powiadomienia idą przez Alertmanager Grafany. Ruler metryk stosu nie ma Alertmanagera (nowe stosy Grafana Cloud go nie
dostają), więc reguły nie trafiają do niego. Alerty z treści logów (np. ostrzeżenia kanału `cache` przy awarii Redisa) nie istnieją.

1. **użytkownik** – zakłada konto Grafana Cloud (darmowy plan) ze stosem w regionie UE i retencją logów 14 dni oraz jeden token
   access policy dla metryk i logów z zakresami `metrics:write` i `logs:write`. W Grafanie stosu (Administration → Users and
   access → Service accounts) zakłada konto serwisowe `shop-alloy-rules` z rolą podstawową `None` i rolami: Alerting Rules Reader,
   Rules Writer, Set provisioning status, Data sources Reader, Folders Creator, Reader i Writer (to wykonuje też agent w przeglądarce
   użytkownika na jego prośbę), i generuje jego token (bez daty wygaśnięcia albo z przypomnieniem o odnowieniu). Podaje agentowi (bez
   tokenów) z portalu stosu: adres zapisu metryk (Prometheus, kończy się `/api/prom/push`), adres zapisu logów (Loki), identyfikatory
   instancji (nazwa użytkownika Prometheusa i Lokiego) i adres Grafany stosu (`https://<stos>.grafana.net`).
2. **agent** – wpisuje je w `argocd/apps/monitoring.yaml` (`destinations.grafana-metrics`: `url`, `auth.usernameFrom`
   i `tenantIdFrom`; `destinations.grafana-logs`: `url`, `auth.usernameFrom` i `tenantIdFrom`; `destinations.grafana-alerting`:
   `url` i `rules.address`, czyli `<adres Grafany>/api/convert`; przy zmianie stosu także UID źródła danych Prometheusa
   w nagłówku `X-Grafana-Alerting-Datasource-UID`) (PR). Merge robi **użytkownik**.
3. **użytkownik** – tokeny wpisuje w terminalu: `make -C infra/k8s seal-secrets WHAT=grafana` (3.4; pyta tylko o tokeny, których
   `grafana-cloud.yaml` jeszcze nie ma: `GRAFANA_CLOUD_TOKEN`, potem `GRAFANA_ALERTING_TOKEN`); **agent** dodaje powstały
   `grafana-cloud.yaml` do PR z sekretami.
4. **agent** – po wdrożeniu `make -C infra/k8s status HOST=<user>@<adres>`: `prometheus-crds` i `monitoring` są `Synced` i
   `Healthy`. Brak konta albo zły token zostawia `Degraded` tylko `monitoring` (log Alloy w przestrzeni `monitoring`); sklep działa i
   się wdraża. W Grafana Cloud widać metryki klastra (np. `up`, `kube_node_info`) i logi (`namespace="shop"`), a logów podów
   `mailpit` nie ma.
5. **agent** – po wdrożeniu przegląd próbki logów w Grafana Cloud (Explore, logi każdego źródła: `api`, `worker`, `worker-commands`, `store`, `admin`,
   `keycloak`, `postgres`, `imgproxy` i zdarzenia klastra) pod kątem sekretów i danych klientów: haseł, tokenów, nagłówków,
   ciasteczek, adresów e-mail i IP, imion i adresów w treści wyjątków. Znalezisko to nowa reguła maskowania w
   `extraLogProcessingStages` manifestu `monitoring` z wierszem w korpusie `infra/k8s/tests/log-corpus` albo wyłączenie źródła w
   `extraDiscoveryRules` (PR); do czasu poprawki źródło jest wyłączone.
6. **agent** – po wdrożeniu sprawdza w Grafana Cloud liczbę aktywnych serii (darmowy plan: 10 tys.); przy przekroczeniu Grafana Cloud
   odrzuca nadmiar, a agent zawęża listę metryk w manifeście.
7. **użytkownik** – w Grafana Cloud (Alerting → Contact points) dodaje punkt kontaktowy e-mail i ustawia go jako domyślny w drzewie
   powiadomień Alertmanagera Grafany. Alerty `NodeDiskFull`, `RateLimitsOff` i `MessagingUnhealthy` mają `severity: warning`, reszta `critical`; że alerty reguł trafiają
   do tego punktu kontaktowego, potwierdza dopiero krok 8.
8. **agent** – po wdrożeniu w Grafana Cloud (Alerting → Alert rules) widać jedenaście reguł grup `shop-backup`, `shop-rate-limits`, `shop-messaging` i `shop-cluster`
   (przy kopiach wyłączonych w `prod`, 3.: siedem reguł grup `shop-rate-limits`, `shop-messaging` i `shop-cluster`)
   w folderze `agent/shop/shop-alerts/…`, oznaczonych jako provisioned (źródło: `PrometheusRule` `shop-alerts` w przestrzeni
   `shop`, synchronizowany przez Alloy przez API importu Grafany; zmiana reguły to zmiana chartu, nie edycja w UI). Brak reguł
   po 10 minutach od wdrożenia: log Alloy (`mimir.rules.kubernetes`, np. 401 przy złym tokenie konta serwisowego). **`BackupMissing` i
   `BackupVerifyMissing` odpalają od startu aż do pierwszej udanej kopii i weryfikacji (metryki jeszcze nie ma, reguła ma
   `absent`): to oczekiwane, nie awaria.** Po pierwszej nocnej kopii i weryfikacji gasną. Tak samo `OrderExpiryMissing` (i
   `RateLimitsOff`) po pierwszym wdrożeniu z regułą odpala, dopóki CronJob nie ma pierwszego udanego Joba (kilka minut).
   To też sprawdzenie od końca do końca, które wykonuje **użytkownik** (agent nie ma dostępu do jego skrzynki): jeśli Grafana
   Cloud powstała przed pierwszą udaną kopią, `BackupMissing` odpala zaraz po załadowaniu reguły (`absent_over_time` bez `for`),
   więc e-mail o nim powinien przyjść wkrótce po synchronizacji reguły. Agent widzi stronę alertu: w Alertmanagerze Grafany
   (Alerting → Groups) alert ma odbiorcę `email`, a punkt kontaktowy `email` ma ostatnią próbę wysyłki bez błędu. Testowe
   powiadomienie punktu kontaktowego niczego nie dowodzi o regułach. Jeśli nie odpala żaden alert (kopie wyłączone, klaster
   zdrowy), agent w przeglądarce użytkownika wysyła przez to samo API importu tymczasową regułę `vector(1)` w osobnym folderze
   (`agent/shop/delivery-test`), sprawdza odbiorcę `email` i próbę wysyłki bez błędu, a potem usuwa regułę i folder; e-mail
   potwierdza użytkownik.
9. **użytkownik** – przed świadomym `restore` i planowanymi pracami (restart serwera, aktualizacja k3s, ćwiczenie odtworzenia)
   wycisza alerty w Grafana Cloud (Alerting → Silences): dla `restore` dopasowanie `alertname=~"WorkloadNotReady|Backup.*|RateLimitsOff|OrderExpiryMissing"` (`restore` wstrzymuje CronJoby
   `rate-limit-check` i `order-expiry`, więc po 17 i po 30 minutach alerty odpaliłyby bez awarii), dla innych prac `alertname=~"WorkloadNotReady|RateLimitsOff|OrderExpiryMissing"`
   (Redis wyłączony razem z serwerem albo restartem k3s kończy sprawdzenie błędem; przy wyłączeniu całego serwera także
   `ClusterSilent`), na czas prac z zapasem.
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

Błędy i trace'y api (z workerami i Jobami), serwera i przeglądarki sklepu oraz przeglądarki panelu idą do Sentry SaaS
(`docs/features/sentry/spec.md`, `docs/adr/backups-observability.md`). Każda aplikacja włącza SDK tylko przy swoim DSN: chart
przekazuje go jako `SENTRY_DSN` razem z `SENTRY_ENVIRONMENT` (`sentry.environment`, w `prod` `demo`), `SENTRY_RELEASE` (rewizja
obrazów, `image.tag`) i `SENTRY_TRACES_SAMPLE_RATE` (`sentry.tracesSampleRate`, w `prod` 0,1). `prod` ma DSN-y trzech projektów
organizacji `worf` (krok 3); profil `ci` ich nie ma nigdy. DSN nie jest sekretem (jest w przeglądarce), więc leży w
`environments/prod/values.yaml`, nie w Sealed Secrets; `make -C infra/k8s check` sprawdza jego format
(`https://<klucz>@<host>/<id projektu>`) i `tracesSampleRate` (0 do 1).

1. **użytkownik** – zakłada konto Sentry (darmowy plan) i organizację w regionie UE (adresy `*.ingest.de.sentry.io`) oraz trzy projekty:
   `api` (PHP/Symfony; obejmuje api, oba workery i Joby), `store` (Next.js) i `admin` (Vue). Z każdego bierze DSN (Settings → Client Keys)
   i podaje go agentowi (DSN nie jest tajny).
2. **użytkownik** – w ustawieniach Sentry (limity darmowego planu są małe, a DSN jawny, więc każdy może wysyłać zdarzenia do
   projektu): włącza ochronę przed skokami (Spike Protection) dla organizacji i ustawia limit zdarzeń na każdym projekcie
   (Client Keys → Configure → Rate Limiting), tak żeby jeden projekt nie zużył miesięcznego limitu. „Data Scrubber” (Security & Privacy)
   zostaje włączony: filtr aplikacji nie zna każdego formatu danych, a Data Scrubber jest drugą warstwą po stronie Sentry.
3. **agent** – wpisuje trzy DSN-y w `sentry.dsn.api`, `sentry.dsn.store` i `sentry.dsn.admin` w
   `infra/k8s/environments/prod/values.yaml` (osobny mały PR, `make -C infra/k8s check`); merge robi **użytkownik**. Zdarzenia zaczynają
   płynąć po następnym wdrożeniu (sekcja 7).
4. **użytkownik** – dla map źródeł sklepu i panelu (wysyłane z budowania obrazów w workflowie `deploy.yml`, tylko przy wdrożeniu z
   `main`) tworzy w Sentry token (Organization Auth Token; wysyłka map nie tworzy wydań) i zapisuje go w GitHub jako sekret
   środowiska `sentry` (sekcja 1, krok 3; job `images` działa w tym środowisku, bez zatwierdzenia, tylko z `main`):
   `gh secret set SENTRY_AUTH_TOKEN --env sentry` (wartość tylko w tym miejscu, nigdy w rozmowie z agentem; sekret repozytorium
   o tej nazwie nie może istnieć), a w zmiennych repozytorium (Actions variables) `SENTRY_ORG` (slug organizacji), `SENTRY_PROJECT_STORE` i `SENTRY_PROJECT_ADMIN` (slugi projektów). Bez sekretu
   workflow działa, a obrazy powstają bez map (w podsumowaniu runu jest wpis o pominięciu); z sekretem błąd wysyłki kończy
   budowanie błędem, a brak którejś z trzech zmiennych kończy job `images` błędem z jej nazwą, przed budowaniem. Mapy
   nie zostają w obrazach (etapy `prod` nie mają plików `.map`).
5. **agent** – po pierwszym wdrożeniu z DSN-ami (`make -C infra/k8s status HOST=<user>@<adres>`: aplikacje `Synced`, `Healthy`) sprawdza, że
   kontenery `api`, `worker`, `worker-commands`, `store` i `admin` mają `SENTRY_*` (`SENTRY_DSN`, `SENTRY_ENVIRONMENT=demo`, `SENTRY_RELEASE` równe
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

## 11. Pomiar obciążenia na k3d i dane demo w skali

Pomiar `make -C infra/k8s load` działa na jednorazowym klastrze k3d na komputerze użytkownika, nie na serwerze
(`docs/features/hardening/spec.md`, „Testy obciążeniowe”; dane: `docs/features/demo-data/spec.md`, „k3d”). Opcje, pliki
wyniku i kody wyjścia: komentarz celu w `Makefile` i nagłówek `scripts/load.sh`. Dane w skali na serwerze generuje
osobne cele `generate` i `open` (od „Generowanie danych na serwerze” niżej); pomiaru k6 na serwerze jeszcze nie ma.

Profile `prod`, `ci` i `load` chartu mają zawieszony CronJob `demo-data` (`suspend: true`), który sam nigdy się nie
uruchamia, a każdy profil ma zawieszony CronJob `load` (k6, `load/smoke.js` na usługach klastra, bez sekretów). Job
z `demo-data` kasuje dane każdego modułu i generuje profil `demoData.profile` (`bin/generate-demo --force-reset`; dla
`seed` `bin/seed-demo`) z limitem czasu `demoData.activeDeadlineSeconds` profilu, bez ponowień, a jego skrypt
(`files/scripts/demo-data.sh`) odmawia kodem 64, gdy `DEMO_DATA_CONFIRM` nie jest nazwą profilu ani słowem `seed`:
Job utworzony ręcznie (`kubectl create job --from=cronjob/demo-data`) niczego nie kasuje. `DEMO_DATA_CONFIRM` ustawiają
tylko `generate` (serwer), `load` (jeden Job na przebieg) i test dymny (przez `generate` z profilem `small`);
`concurrencyPolicy` nie chroni przed dwoma Jobami utworzonymi ręcznie. Profile inne niż `small` czytają zdjęcia
z prywatnego bucketu puli przez sekret wskazany w `demoData.photosSecretName` (klucze `DEMO_PHOTOS_ENDPOINT`,
`DEMO_PHOTOS_BUCKET`, `DEMO_PHOTOS_ACCESS_KEY_ID`, `DEMO_PHOTOS_SECRET_ACCESS_KEY`); chart go nie tworzy, w klastrze
pomiaru zakłada go `load`, a na serwerze `generate` na czas przebiegu.

1. **użytkownik** – zakłada prywatny bucket puli zdjęć, tokeny i plik `~/.config/shop/demo-photos.env`
   (`docs/features/demo-data/spec.md`, „Wymagane przed startem”); wartości nie przechodzą przez rozmowę, a agent nie czyta
   tego pliku.
2. **agent** – pomiar na danych z seeda: `make -C infra/k8s load LOAD_OUT=<katalog poza repo>` (`LOAD_DATA=seed`,
   domyślnie; dziesiątki minut, VM Dockera co najmniej 10 CPU i 20 GiB).
   `LOAD_OUT` musi być katalogiem, który Docker może zamontować do zapisu (Docker Desktop: udostępniony w Settings →
   Resources → File Sharing); cel sprawdza to przed budowaniem obrazów i inaczej kończy się kodem 2.
3. **zgoda użytkownika** – pomiar na danych z generatora: `make -C infra/k8s load LOAD_OUT=<katalog poza repo>
   LOAD_DATA=medium` (albo `server`, `full`; inny plik puli: `DEMO_PHOTOS_ENV_FILE=<plik>`). Generator działa godzinami
   i zajmuje dziesiątki do setek GB dysku (szacunki w specyfikacji DEMO-DATA, „Środowiska”). Cel sprawdza plik puli przed
   utworzeniem klastra (brak pliku albo zmiennej odczytu: kod 2), sprawdza zapas miejsca (niżej), zakłada sekret
   `demo-photos` tylko z czterema zmiennymi odczytu (token zapisu `DEMO_PHOTOS_WRITE_*` nie trafia do klastra), czeka na
   Job generatora (`LOAD_DATA_TIMEOUT`, domyślnie limit Joba i 10 minut; błąd albo przekroczenie czasu: kod 2 z logiem
   Joba), podnosi stany tylko wariantów z losowych porcji mapy strony i przekazuje ich klucze k6. Klaster z danymi
   i sekretem jest usuwany przy każdym wyjściu.

   **Zapas miejsca** (`medium`, `server`, `full`; `seed` go nie sprawdza). `diskPeakGb` profilu
   (`apps/api/fixtures/demo-data/profiles.yaml`) to szacunek szczytowego zużycia dysku całego stosu. Przed zbudowaniem
   obrazów i utworzeniem klastra cel czyta wolne miejsce w dwóch miejscach: w VM Dockera (`df -Pk /` w kontenerze, którego
   system plików leży na dysku z danymi Dockera) i na dysku hosta, na którym leży dysk VM (`df -Pk` katalogu z opcji
   `LOAD_DISK_PATH`, domyślnie katalog domowy; dysk VM Dockera Desktop rośnie w miarę zapisu, więc granicą jest host).
   Oba muszą mieć co najmniej 1,25 × `diskPeakGb` (1 GB to 10^9 bajtów); inaczej cel kończy się kodem 2 bez budowania
   i bez klastra, podając wymagane i dostępne miejsce obu dysków. Gdy obraz VM leży poza katalogiem domowym, ustaw
   `LOAD_DISK_PATH` na katalog z tego samego dysku. Od początku przebiegu (przed budowaniem obrazów) co
   `LOAD_SPACE_POLL` sekund (domyślnie 30) cel czyta oba miejsca ponownie; spadek któregokolwiek poniżej 10% `diskPeakGb`
   zatrzymuje Job generatora i k6, usuwa klaster i kończy cel kodem 2 z przyczyną. Wolnego miejsca ani `diskPeakGb` nie da
   się odczytać (polecenie zawodzi albo odpowiada czymś innym niż liczba): kod 2, nigdy „wystarczy miejsca”. Cel
   reaguje na spadek dopiero po zakończeniu bieżącego polecenia pierwszego planu (budowanie obrazu, import, `helm`,
   zapytania SQL); w czasie czekania na Job generatora i na k6 reaguje od razu. Każdy odczyt (także ten sprzed startu,
   jako pierwszy wiersz) trafia do `LOAD_OUT/space.log` (czas, wolne KiB w VM i na hoście); na końcu przebiegu, także
   nieudanego, plik dostaje podsumowanie: najmniejsze wolne miejsce obu dysków i zużycie względem pierwszego odczytu
   w GB, z którego uaktualnia się `diskPeakGb`. Plik, którego nie da się zapisać, to ostrzeżenie, nie koniec przebiegu.

   **Uśpienie komputera.** Usypiający się komputer zatrzymuje VM Dockera i unieważnia czasy, więc na macOS cel przez cały
   przebieg blokuje uśpienie z bezczynności, a na zasilaniu sieciowym także systemowe (`caffeinate -ims -w <pid>`).
   Nie chroni przed zamkniętą klapą ani uśpieniem ręcznym: przy pomiarze klapa ma zostać otwarta, a komputer podłączony
   do prądu. Gdzie indziej cel nic nie blokuje, a `environment.txt` ma w linii `sleep lock:` wzmiankę, że nic nie
   blokuje uśpienia. Na końcu cel szuka przerw w odczytach (`space.log`,
   `node-stats.log`, koniec przebiegu): odstęp dłuższy niż 3 × `LOAD_SPACE_POLL` i co najmniej 120 s to uśpienie. Zapisuje
   je w `space.log` (linia `#`) i w `environment.txt` (`host sleep:`), ostrzega na standardowym wyjściu błędów i kończy
   przebieg kodem 1 zamiast 0: wyniki są w `LOAD_OUT`, ale czasów ani przepustowości z takiego przebiegu nie podajemy.
4. **agent** – raport z wyników w `LOAD_OUT`: `environment.txt` (w nim `LOAD_DATA` i porcje), `sizes.txt` (rozmiary baz,
   tabel i indeksów Postgresa i indeksów Elasticsearcha), `top.log` i `node-stats.log` (próbkowane co 10 s od chwili, gdy
   klaster jest gotowy, więc obejmują także Job generatora i fazę danych: stąd szczyt pamięci Postgresa i Joba), przy danych
   z generatora `demo-data.log` (log Joba z czasem każdej linii), `demo-data-steps.txt` (czasy kroków generatora)
   i `space.log` (wolne miejsce w czasie przebiegu i szczytowe zużycie dysku). Wynik `medium` i ekstrapolacja nie są pomiarem pełnej
   skali; `server` i `full` uznaje się za zweryfikowane dopiero po ich własnym przebiegu
   (`docs/features/demo-data/spec.md`, W3).

### Generowanie danych na serwerze (`generate` i `open`)

`make -C infra/k8s generate` zastępuje dane sklepu na serwerze danymi profilu `medium` albo `server` (generator
`docs/features/demo-data/spec.md`) albo wraca do seeda; po udanym Jobie woła `make -C infra/k8s open`, który sprawdza
dane i otwiera sklep. Sklep jest od startu do udanego `open` zamknięty (wszystkie hosty odpowiadają 502), a nieudany
albo przerwany przebieg zostawia go zamkniętego. Odzyskiwanie po awarii jest ręczne: ponowny `generate`, powrót do
seeda albo `open`. Opis rozwiązania i ochron: `docs/features/demo-scale/spec.md`; zmienne i kody wyjścia: nagłówki
`scripts/generate.sh` i `scripts/open.sh`.

**Warunek wstępny.** `generate` z profilem `medium` albo `server` na worf.pl dopiero po wdrożeniu i weryfikacji na
serwerze poprawki L9 (cache ISR sklepu w pamięci; `docs/features/product-page/spec.md`, „Cache ISR”) oraz po **zgodzie użytkownika**
w rozmowie, która nazywa operację (`generate`), host (worf.pl) i profil. Zgoda na `generate` nie zastępuje weryfikacji
L9, a zakończenie ASH-V1 ani ABUSE-LIMITS nie jest warunkiem. Powrót do seeda (`PROFILE=seed`) też wymaga zgody, ale nie
czeka na L9. `open` zmienia serwer (otwiera sklep), więc też wymaga zgody, która nazywa operację, host i profil.

**Przygotowanie.**

1. **użytkownik** – plik `~/.config/shop/demo-photos.env` z czterema zmiennymi odczytu puli zdjęć na komputerze, z którego
   idzie `generate` (inny plik: `DEMO_PHOTOS_ENV_FILE=<plik>`). Wartości nie przechodzą przez rozmowę, a agent nie czyta
   pliku. Do klastra trafiają tylko cztery zmienne odczytu, nigdy token zapisu (`DEMO_PHOTOS_WRITE_*`), jako sekret
   `demo-photos` na czas przebiegu (usuwany przy każdym wyjściu `generate`, także po błędzie; po jego zabiciu usuwa go
   `open`). Profil `seed` pliku nie potrzebuje.
2. **agent** – wdrożona rewizja ma ten profil: `generate` przyjmuje `seed` albo `demoData.profile` CronJoba
   (`environments/prod/values.yaml`: `server`; powrót do `medium` to zmiana wartości w PR i zwykłe wdrożenie). Wdrożenie:
   sekcja 7 (zgoda użytkownika); nie w trakcie generowania, bo restartuje pody, których generator potrzebuje.
3. **agent** – `make -C infra/k8s status HOST=<user>@<adres>`: sklep otwarty, bez blokady `demo-data-lock`, Job
   `demo-data-run` (poprzedniego przebiegu), aktywne Joby, aplikacje `Synced` i `Healthy`, wszystkie Deploymenty
   i StatefulSety gotowe (generator potrzebuje Elasticsearcha, Postgresa, Keycloaka, `worker`, Varnisha i sklepu), żaden
   Job nie jest aktywny poza Jobami CronJobów `rate-limit-check`, `queue-check` i `order-expiry` (co 5 minut, najwyżej
   60, 120 i 600 s; `generate` i `open` je pomijają, bo generator nie zapisuje niczego, co zmieniają).
4. **agent** – wolne miejsce: `medium` wymaga co najmniej 32 GB (`demoData.minFreeGb`; 1,25 × `diskPeakGb` `medium`, czyli
   28,75 GB, jest mniejsze), `server` co najmniej 142,5 GB (1,25 × `diskPeakGb` 114 GB; to szacunek, nie pomiar:
   `docs/features/demo-data/spec.md`, „Rozmiar i czas pól treści produktów”). Odczytem miejsca jest kubelet; `generate` sam odmawia bez zapasu. Strażnik w Jobie zatrzymuje generator, gdy
   wolne miejsce spadnie poniżej 32 GB.
5. **agent** – adresy sondy: `generate` i `open` pytają z komputera operatora o `https://api.worf.pl/` i
   `https://worf.pl/` przez Cloudflare (502, 503 i 504 to „zamknięty”, kod poniżej 500 „otwarty”, każdy inny kod i brak
   odpowiedzi to odczyt nieudany, ponawiany do limitu czasu). **Uwaga:** sonda liczy każdy kod poniżej 500 jako
   „otwarty”, więc odpowiedź 403 z wyzwania Cloudflare (Bot Fight Mode, reguła WAF) dla curla operatora wygląda jak
   otwarty sklep: zamknięcia sonda nigdy nie potwierdzi, a `generate` kończy się po `GENERATE_CLOSE_WAIT` kodem 1 ze
   sklepem zamkniętym i danymi bez zmian. Adresów sondy nie da się obejść adresem origin: `ipAllowList` odpowiada 403
   każdemu adresowi spoza Cloudflare (`GENERATE_PROBE_HOST` służy klastrowi testowemu, w którym adresem jest węzeł).
   Przed uruchomieniem sprawdź, że żądanie z komputera operatora dostaje odpowiedź sklepu, a nie wyzwanie:
   `curl -sSI https://worf.pl/` i `curl -sSI https://api.worf.pl/` bez nagłówka `cf-mitigated`. Wyjątek od wyzwania dla
   adresu operatora albo dla tych dwóch adresów ustawia w Cloudflare **użytkownik**.
6. **agent** – `KUBE_DIR`: jak w sekcji 2, krok 6 (`kubeconfig`), ale katalog zostaje do końca przebiegu i ewentualnego
   `open` (przebieg trwa godziny), a potem `rm -rf "$KUBE_DIR"`. Cele wołają `scripts/ao-guard.sh` i odmawiają, gdy
   działa AO albo jego wykonawca.
7. Stan kopii: kopie restic w klastrze są w `prod` wyłączone (`backup.enabled: false`). Nie zakładamy, że automatyczny
   backup VPS-a od OVH jest włączony, dopóki **użytkownik** tego nie potwierdzi; zabezpieczenia generowania na nim nie
   polegają: dane generatora odtwarza ponowne generowanie tego samego profilu, a zamówienia złożone po generowaniu
   i zmiany z panelu nie mają kopii w klastrze.

**Uruchomienie** (po zgodzie użytkownika; przebieg trwa godziny i wymaga komputera, który nie śpi):

```sh
KUBE_DIR="$(mktemp -d)"
make -C infra/k8s kubeconfig HOST=<user>@<adres> KUBE_DIR="$KUBE_DIR"
make -C infra/k8s generate PROFILE=medium \
  GENERATE_PROBE_URL='https://api.worf.pl/ https://worf.pl/' KUBE_DIR="$KUBE_DIR"
```

- `PROFILE` to `seed` albo profil CronJoba (`server` po `medium`: wdrożona rewizja musi mieć wtedy
  `demoData.profile: server`).
- `GENERATE_PROBE_HOST='<host> ...'` (po jednym nagłówku `Host` na adres) tylko w klastrze testowym.
- Cel wypisuje host klastra, liczby produktów i zamówień w bazie, profil docelowy, wolne miejsce i że sklep będzie
  niedostępny, i czeka na słowo `tak` na standardowym wejściu; agent podaje je dopiero po zgodzie użytkownika
  (`printf 'tak\n' | make … generate …`).
- Zmienne dodatkowe (wartości domyślne): `GENERATE_CLOSE_WAIT` 120 s (czas, w którym sonda ma podać „zamknięty” po regule
  i restarcie Traefika), `GENERATE_OPEN_WAIT` 300 s (czas, w którym ma podać „otwarty” po usunięciu reguły),
  `GENERATE_POLL` 30 s (odstęp odczytów Joba), `GENERATE_PROBE_POLL` 5 s (odstęp rund sondy). Wartością jest liczba
  całkowita sekund; inna to kod 2.
- `generate`, `open` i `reindex` działają pojedynczo: blokadą jest ConfigMap `demo-data-lock` (kto ją trzyma: polecenie, profil,
  host operatora, pid, czas startu; `status` ją pokazuje). `generate` zakłada ją po `tak` i trzyma do końca, razem
  z `open`, który woła; `open` uruchomiony ręcznie zakłada ją na starcie. Drugi `generate` albo `open` odmawia w tym
  czasie bez zmian i podaje posiadacza. Oba cele zwalniają ją przy każdym wyjściu poza zabiciem sygnałem KILL (i utratą
  połączenia przy zwalnianiu): ręczne usunięcie w „Po błędzie albo przerwaniu”.
- Przebieg `generate`: sprawdzenia bez zmian, pytanie, blokada, reguła `shop-closed`, restart podów Traefika, sonda
  „zamknięty”, sekret puli zdjęć, usunięcie Joba `demo-data-run` poprzedniego przebiegu, generowanie (nowy Job
  `demo-data-run`; skrypt tylko czeka), `open`. `open`: wyczyszczona pula `cache.sitemap`, indeksy Postgresa, alias wyszukiwarki, Job `load` ze ścieżkami
  sklepu (strona główna, listing, produkt, wyszukiwarka, koszyk i checkout gościa), liczby produktów i zamówień
  (obejmują zamówienie checkoutu gościa `load-<n>@example.com`, które wygasa jak każde nieopłacone) i rozmiary, usunięcie
  reguły, sonda „otwarty”. `status` w tym czasie pokazuje zamknięcie i stan Joba.
- `open` ręcznie, po przebiegu przerwanym przed otwarciem (Job `demo-data-run` jest `Complete`; po zgodzie):

  ```sh
  make -C infra/k8s open GENERATE_PROBE_URL='https://api.worf.pl/ https://worf.pl/' KUBE_DIR="$KUBE_DIR"
  ```

  `open` odmawia przy blokadzie innego przebiegu, przy aktywnym Jobie (poza Jobami CronJobów `rate-limit-check`,
  `queue-check` i `order-expiry`, tak jak `generate`), bez Joba `demo-data-run` i z Jobem innym niż
  `Complete`; `generate` usuwa Job poprzedniego przebiegu tuż przed nowym (po zamknięciu), więc sukces poprzedniego
  przebiegu nie otworzy sklepu po przerwanym nowym. Gdy `generate` skończył się przed tym usunięciem (np. sonda nie
  potwierdziła zamknięcia), dane są nietknięte, a `open` otwiera sklep na Jobie poprzedniego przebiegu, jeśli jest
  `Complete`.

**Kody wyjścia.** `generate`: `0` dane wygenerowane, sprawdzone, sklep otwarty; `1` odmowa bez zmian, brak `tak` albo
błąd (komunikat mówi, czy sklep jest zamknięty); `2` złe argumenty albo środowisko (`PROFILE`, `GENERATE_PROBE_URL`,
`GENERATE_PROBE_HOST`, `KUBE_DIR`, zmienna czasu, brak pliku puli zdjęć albo zmiennej odczytu, `diskPeakGb`). `open`:
`0` sprawdzone, sklep otwarty; `1` odmowa, nieudane sprawdzenie (sklep zostaje zamknięty) albo sonda bez „otwarty”
(reguła już usunięta, ponowne `open` kończy); `2` złe argumenty. Job generatora kończy się kodem `64` (odmowa bez zmian:
brak albo zły `DEMO_DATA_CONFIRM`), `74` (za mało wolnego miejsca albo nieczytelny odczyt przed startem, nic nie ruszyło;
`seed` nie ma progu, tylko wymóg liczbowego odczytu) albo `75` (strażnik albo TERM zatrzymał generator: dane niepełne);
inny kod to kod generatora. Powód z Joba (`/dev/termination-log`) jest w komunikacie celu. `rerun` nie ponawia Joba
generatora: odmawia kodem 3 i wskazuje `generate`.

**Po błędzie albo przerwaniu.** `make status`, potem:

- Job `demo-data-run` nadal aktywny: czekać (strażnik i zbieranie logów działają w klastrze); po `Complete` `open`.
- Job `Failed` (także bez poda, po czasie `demoData.activeDeadlineSeconds`: `medium` 6 h, `server` 24 h, albo po kodzie
  75), brak Joba albo nieudane sprawdzenie w `open`: sklep zostaje zamknięty, a dane są niepełne. Dwie drogi:
  - ponowienie: `generate PROFILE=<profil>` (po zatrzymaniu przez strażnika miejsca skończy się tak samo, więc najpierw
    decyzja użytkownika: inny profil albo zwolnienie miejsca);
  - powrót do seeda: `generate PROFILE=seed`. Ten sam przebieg i ten sam Job (zamknięcie, `bin/seed-demo`, `open`),
    minuty, bez pliku puli zdjęć i bez progu wolnego miejsca (po zatrzymaniu przez strażnika miejsce zostaje poniżej
    progu, a seed zaczyna od `TRUNCATE` i zwalnia je od razu). Przy wysokim zapełnieniu dysku (powyżej 90%, na serwerze
    około 20,7 GB wolnego) nowy indeks seeda może nie dostać shardu, dopóki Elasticsearch nie odświeży informacji
    o dysku (około 30 s): ta próba kończy się błędem, a ponowienie przechodzi. Konta generatora w Keycloaku zostają
    (wyłączone, bez poświadczeń).
- Sonda zamknięcia bez „zamknięty” (np. wyzwanie Cloudflare): sklep zamknięty, dane i Job poprzedniego przebiegu bez
  zmian; `make open` otwiera sklep na tym Jobie, jeśli jest `Complete`, a po poprawie adresów sondy można ponowić
  `generate`.
- Pozostawiony sekret `demo-photos` (zabity skrypt; `status` go pokazuje): usuwa go `open`, gdy żaden Job nie działa.
- Pozostawiona blokada `demo-data-lock` (`status`: `lock demo-data-lock: HELD by …`; `generate`, `open` i `reindex` odmawiają z jej
  posiadaczem) po `generate`, `open` albo `reindex` zabitym sygnałem KILL albo bez połączenia przy zwalnianiu: **użytkownik**
  sprawdza, że żaden `generate` ani `open` nie działa (komputer i proces z danych posiadacza), i usuwa ją na serwerze:

  ```sh
  ssh <user>@<adres> sudo k3s kubectl -n shop delete configmap demo-data-lock
  ```

  Usunięcie blokady działającego przebiegu pozwala drugiemu `generate` albo `open` działać równolegle z nim. Potem jak
  w punktach wyżej (`status`, `open` albo ponowny `generate`).
- Log Joba po restarcie serwera albo usunięciu poda: Grafana Cloud (Explore, przestrzeń `shop`).

Po błędzie wymagającym poprawki kodu kolejność to: powrót do seeda (sklep otwarty na seedzie), wdrożenie poprawki,
ponowne generowanie. Wdrożenie przy zamkniętym sklepie jest możliwe (reguła zostaje), ale Job `initialize` może skończyć
się błędem stanu mieszanego i zatrzymać synchronizację przed `purge-caches` i `backfill`; po udanym generowaniu albo
powrocie do seeda domyka ją kolejne wdrożenie albo ręczna synchronizacja aplikacji `shop` w Argo CD (**użytkownik**).

**Powrót do `medium` z `server`.** PR z `demoData.profile: medium`, wdrożenie, potem `generate PROFILE=medium`. Krok kont
usuwa wtedy do 900 tys. kont pojedynczymi wywołaniami, a jego czas jest niezmierzony. `make -C infra/k8s reinitialize`
odmawia kodem 1 przed pytaniem, dopóki sklep jest zamknięty (reguła `shop-closed`); wraca się wtedy przez
`generate PROFILE=seed`.

W trakcie zamknięcia pody działają, więc alert `WorkloadNotReady` się nie odzywa, a strażnik zatrzymuje generator tuż
przed progiem alertu `NodeDiskFull` (85% zajętego miejsca). Ewentualne wyciszenie alertów na czas przebiegu robi ręcznie
**użytkownik** (sekcja 9).

**Wdrożenie po generowaniu.** Pierwsze zwykłe wdrożenie po `medium` i po `server` (zgoda użytkownika, sekcja 7) nie rusza
wygenerowanych danych (`initialize` kończy się bez zmian przy niepustych produktach, cenach i stanach). **Agent**
sprawdza po nim `status`: aplikacje `Synced` i `Healthy`, Joby wersji `Complete`, czas Joba `initialize` z kolumny
`DURATION` listy Jobów, liczby z ostatniej linii jego logu równe liczbom produktów i zamówień z wyniku `open` (plus
zamówienia złożone od tamtej pory) i sklep otwarty. Czas `initialize` dłuższy niż 5 minut na danych `server` to
zgłoszenie do backlogu (limit Joba to 30 minut; `initialize` liczy `count(*)` czterech tabel przy każdym wdrożeniu).
Wynik trafia do raportu. Migracja na tabeli z milionami wierszy musi zmieścić się w limicie Joba `migrate` (30 minut).

**Logi i metryki.** Pełny log Joba i metryki zbiera Alloy do Grafana Cloud (retencja logów 14 dni; kopia nie zależy
od rotacji logu kontenera ani od istnienia poda, a po restarcie serwera w trakcie przebiegu jest jedyną kopią logu).
**Agent** w ciągu retencji eksportuje z Grafana Cloud (Explore: logi przestrzeni `shop` poda Joba `demo-data-run`;
metryki węzła, pamięć i CPU kontenerów, restarty) pełny log i szczyty (dysk, pamięć i CPU Postgresa i Elasticsearcha,
restarty kontenerów) i zapisuje raport w `docs/reports/` (czasy kroków z linii `[OK]` generatora, rozmiary, szczyt
dysku, wynik sprawdzenia). Liczby z raportu opisują ten serwer i ten profil; liczby `server` przed jego przebiegiem
i wszystkie liczby `full` to ekstrapolacja z `medium`, a pełna skala (10 mln produktów, 50 mln zamówień) nie jest
zweryfikowana.
