# k3s, Argo CD i gałąź deploy-images

## Kontekst

Sklep i panel mają działać poza komputerem dewelopera: jeden serwer (VPS, 8 vCore, 24 GB RAM), koszt rzędu kilkudziesięciu
euro miesięcznie, jeden pipeline i wdrożenie, które da się powtórzyć z pustego serwera. Klaster nie musi być odporny na
awarię węzła, ale ma wspierać późniejszy podział na kilka węzłów. Repo jest publiczne, więc sekrety nie mogą w nim być
jawne. Wdrożenie ma być obsługiwalne przez agenta (Claude Code) przez cele Makefile, z jawnymi punktami zgody
użytkownika. Szczegóły: `docs/features/deploy/spec.md`, uruchomienie: `infra/k8s/README.md`.

## Decyzja

- **k3s na jednym węźle**, Traefik z k3s jako ingress (z `externalTrafficPolicy: Local`, żeby widział prawdziwy adres
  klienta), `local-path` jako storage. Bez Ranchera.
- **Własny chart `infra/k8s/charts/shop`** na tych samych obrazach i wersjach co `infra/docker-compose.yml`. Gotowe charty
  tylko dla platformy: Argo CD i Sealed Secrets (wersje przypięte).
- **Argo CD z app-of-apps** (`infra/k8s/argocd/`): aplikacja `root` (jedyna, którą stosuje bootstrap) tworzy aplikacje
  `sealed-secrets`, `secrets` i `shop` w falach, w tej kolejności. Argo CD śledzi gałąź **`deploy-images`**.
- **Dwie gałęzie wdrożeniowe.** `deploy` przesuwa się wyłącznie fast-forwardem na zatwierdzony commit z `main`.
  `deploy-images` to kolejne snapshoty: drzewo commita z `deploy` plus wygenerowany plik
  `infra/k8s/environments/prod/values-images.yaml` (`image.tag` i cztery digesty obrazów). Snapshot ma rodziców: poprzedni
  `deploy-images` i commit z `deploy`, więc gałąź rośnie tylko do przodu. Oba przesunięcia robi workflow jednym
  `git push --atomic` deploy keyem, po zielonym smoke teście na tych obrazach i po sprawdzeniu renderu `prod` z tym
  plikiem. Aplikacja `shop` ma jedno źródło: chart z tej gałęzi z plikami wartości `prod` i `values-images.yaml`.
- **Obrazy po digeście**, nigdy `latest`; `image.requireDigests: true` w `prod`. Obrazy, do których odwołują się
  snapshoty, nie są usuwane z GHCR (cofnięcie wersji to powrót do starszego snapshotu).
- **Kolejność startu** wymuszają initContainery, fale Argo ją porządkują. Migracje i indeks to Job przed nową wersją.
- **Realmy Keycloaka** z chartu, aktualizowane addytywnie przez Job `keycloak-config` (bez usuwania użytkowników i haseł).
- **Sekrety: Sealed Secrets.** Certyfikat kontrolera jest w repo (`environments/prod/sealed-secrets.pem`), więc każdy
  zaszyfruje sekret bez dostępu do klastra (`make -C infra/k8s seal-secrets`); klucz prywatny jest w kopii u
  użytkownika. Zaszyfrowane sekrety: `environments/prod/secrets/`.
- **Cloudflare** przed całością: proxy, „Full (strict)” z certyfikatem Origin CA (zaszyfrowany sekret TLS ingressów),
  Access przed panelem. Serwer przyjmuje HTTP(S) tylko od Cloudflare (middleware `ipAllowList` z zakresami
  Cloudflare, lista aktualizowana skryptem); firewall hosta (ufw) chroni porty hosta (SSH, API k3s).
- **Serwer przygotowuje `make -C infra/k8s bootstrap HOST=…`** (idempotentny skrypt po SSH); `status`, `reinitialize`,
  `rerun` i `create-admin` to pozostałe operacje. Agent wykonuje operacje na serwerze tylko tymi celami; kroki „zgoda
  użytkownika” i „użytkownik” z runbooka nie są jego decyzją.

## Uzasadnienie

- k3s daje pełne API Kubernetesa przy jednym węźle i małym narzucie; ten sam chart zadziała na większym klastrze.
  Rancher przy jednym klastrze to narzut bez korzyści.
- Własny chart zamiast gotowych: zero rozjazdu z compose (pilnuje go `make -C infra/k8s check` i smoke test), pełna kontrola
  nad kolejnością startu i Jobami wersji.
- Jedno źródło Argo CD z jedną rewizją: to, co przetestowano (chart, wartości, sekrety, digesty), jest tym, co wdrożono.
  Alternatywy odrzucone: aplikacja z wieloma źródłami (osobna rewizja plików z digestami, brak jednej rewizji
  wdrożenia), parametr `$ARGOCD_APP_REVISION` jako tag obrazu (rewizja `deploy-images` nie jest tagiem obrazu),
  commity z tagami na `deploy` albo Image Updater (łamią fast-forward gałęzi `deploy` albo dokładają komponent).
- Snapshot z dwoma rodzicami zamiast merge'a i force-pusha: gałąź nigdy nie jest przepisywana, a ruleset może zabronić
  force-pusha i usunięcia (jedyny wyjątek: tworzenie i zwykły push deploy keyem).
- Sealed Secrets zamiast SOPS: jeden certyfikat publiczny w repo, bez kluczy po stronie dewelopera i agentów.

## Konsekwencje

- Rulesety `deploy` i `deploy-push` obejmują obie gałęzie; repo ma mieć jeden deploy key (aktor `DeployKey` obejmuje
  każdy). Ruleset i deploy key zakłada użytkownik po merge'u epiku (runbook).
- Rollback to revert na `main` (nowe wdrożenie); Argo może też wrócić do poprzedniego snapshotu `deploy-images`. Schemat
  bazy jest zgodny wstecz tylko z poprzednią wersją kodu.
- Żadna retencja GHCR ani workflow nie usuwa wersji pakietów (test w `make check`).
- Klucz prywatny Sealed Secrets jest jedyną rzeczą, której nie da się odtworzyć z repo; jego utrata oznacza nowy
  certyfikat i ponowne zaszyfrowanie sekretów oraz ponowne wczytanie danych albo odtworzenie z kopii (DEPLOY-OPS).
- Do czasu DEPLOY-OPS kopie poza serwerem to tylko backup VPS-a od OVH; prawdziwe dane i publiczny start dopiero po
  DEPLOY-OPS.
