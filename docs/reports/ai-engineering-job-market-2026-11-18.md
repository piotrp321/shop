# Rynek pracy: tworzenie oprogramowania z agentami AI (justjoin.it, 2026-11-18)

Raport z przeglądu ogłoszeń na justjoin.it według stanu z 2026-11-18. Zawiera wyłącznie fakty z ogłoszeń przeczytanych
w tej sesji. Każde twierdzenie wskazuje identyfikatory ofert (O1–O56, tabela w rozdziale 2). Raport niczego nie
rekomenduje i nie ocenia żadnego procesu.

## 1. Metoda i pokrycie

### Co zadziałało, a co nie

- **WebFetch** strony `https://justjoin.it/job-offers/all-locations/ai` zwrócił tylko pierwszą porcję listy
  (około 30 tytułów) i liczbę „1,307 job offers” w kategorii AI/ML. Nie daje treści ofert.
- **WebSearch** (`justjoin.it "Claude Code" job offer AI engineer`) zwrócił 19 linków. Sprawdzone przez API: 10 z nich
  to oferty już nieaktywne (`isActive: false`, data wygaśnięcia przed 2026-11-18, np. ITLT, CluePoints, H2B, Godel,
  N-iX „Lead AI Engineer”, Sigmoidal). Wyszukiwarka nie nadaje się więc do ustalenia stanu bieżącego. Odrzuciłem te
  oferty.
- **Publiczne API serwisu** (to samo, z którego korzysta front Next.js; adres podany w konfiguracji osadzonej w HTML,
  `cpApiUrl: /api/candidate-api`) zadziałało i było głównym źródłem:
  - lista: `https://justjoin.it/api/candidate-api/offers?categories=ai&from=<n>&itemsCount=100`
    (parametry `sortBy=published&orderBy=DESC` są odrzucane błędem 400),
  - wyszukiwanie: parametr `keywords=<fraza>` (a nie `keyword`). Wyszukiwanie jest rozmyte i sortowane według
    trafności: „claude” zwraca też „Cloud Architect”. Parametry `keyword`, `search`, `q` i `keywords[]` są ignorowane
    i zwracają cały katalog, a `skills=` filtruje po tagach umiejętności,
  - pojedyncza oferta: `https://justjoin.it/api/candidate-api/offers/<slug>` (pełny opis HTML w polu `body`,
    umiejętności wymagane i mile widziane z poziomem, widełki, tryb pracy, `isActive`, `expiredAt`).
- Strona WWW przyjmuje parametr `keyword=` w adresie (`/job-offers/all-locations?keyword=claude%20code` renderuje
  dopasowaną listę), natomiast `keywords=` w adresie WWW nie filtruje. Adres pojedynczej oferty
  `https://justjoin.it/job-offer/<slug>` jest poprawny.
- Wbudowanej przeglądarki nie użyłem, bo API wystarczyło. Nie logowałem się, nie aplikowałem, nie wysyłałem
  formularzy. Pliki robocze (odpowiedzi API w JSON) leżą wyłącznie w katalogu roboczym sesji.
- W żadnej z przeczytanych treści nie było tekstu skierowanego do agenta ani prób wstrzyknięcia poleceń.

### Liczby

| Etap | Wynik |
|---|---|
| Kategoria `ai` w API (2026-11-18) | 633 ogłoszenia (slugi); 485 po scaleniu tej samej firmy i tytułu (oferty wielomiastowe); strona WWW podaje 1307 (rozbieżności nie wyjaśniłem; prawdopodobnie liczy każdą lokalizację osobno) |
| Zakres dat publikacji w kategorii `ai` | 2026-10-23 – 2026-11-18 |
| Poziomy w kategorii `ai` (633) | senior 410, mid 167, c_level 30, manager 21, junior 4, intern 1 |
| Zapytania `keywords` (28, po maks. 300 najtrafniejszych wyników) | claude code (525 trafień), cursor (286), agentic (644), ai agents (881), copilot (496), codex (144), llm (668), ai-native (2290), ai-first (836), forward deployed (66), harness (67), mcp (447), langgraph (294), ai engineer (1789), genai (527), agent (555), windsurf (3), vibe coding (10), ai-assisted (549), rag (514), openai (318), anthropic (45), developer productivity (110), ai enablement (158), automation engineer (1133), prompt engineering (398), generative ai (477), langchain (282) |
| Kandydaci po scaleniu (firma + tytuł) | 1904; pobrane pełne opisy: 1903 (1 błąd pobrania); wszystkie aktywne |
| Kraj w pobranych opisach | PL 1821, pozostałe 82 (IN, PT, SE, DE, FR, GB, ES i inne); serwis publikuje też oferty spoza Polski |
| Automatyczny przesiew tekstu (wyrażenia regularne, nie lektura) | 627 opisów z jakimkolwiek terminem pracy z agentem kodującym; 521 z nazwą narzędzia lub praktyki (Claude Code, Cursor, Codex, GitHub Copilot, agentic SDLC, spec-driven i podobne) od 215 firm; 601 z silnym słownictwem LLM/agentów bez terminów agentów kodujących; 675 ze słabym albo żadnym |
| Kategoria `ai` bez żadnego terminu LLM/agentów (485 po scaleniu) | 88 opisów (głównie ML, computer vision, mowa, MLOps, ale też oferty niezwiązane, np. PLC, Power BI, konsultant M365); kolejne 35 ma tylko 1–2 wzmianki. Policzone, nieanalizowane |
| **Przeczytane w całości** | **56 ofert** od 48 firm (O1–O56) |

Przesiew automatyczny jest tylko tłem ilościowym (rozdział 4, ostatnia tabela). Wszystkie pozostałe ustalenia opierają się
na 56 przeczytanych ofertach.

### Dobór próby i ograniczenia

- Próba jest celowa, nie losowa: wybierałem oferty z najsilniejszym dopasowaniem tytułu i treści, różnicując firmy,
  poziomy i klasy. Liczby w raporcie opisują próbę, nie rynek.
- Skład próby: klasy (z połączeniami) A 27, B 22, C 19; tylko A 15, tylko B 14, tylko C 15, A+B 8, A+C 4. Poziomy
  według pola API: senior 37, mid 12, manager 4, junior 3.
- Duplikaty treści: O1, O2, O3, O4 i O6 (B3, Jit Team, emagine, IT Factory, Sorigo) mają niemal identyczny tekst
  i według O2, O3 i O4 dotyczą klienta z branży ubezpieczeniowej. To najpewniej jeden program rekrutowany przez kilka
  agencji (dalej: klaster K1). O5 (Antal) opisuje podobny program innymi słowami. O32 (Connectis) i O33 (act digital)
  mają zbieżny opis roli dla banku (klaster K2). O9 i O31 (Comarch) dotyczą tego samego nowego systemu ERP.
  Liczby ofert w raporcie liczą te ogłoszenia osobno; tam, gdzie to ważne, zaznaczam klaster.
- Pola widełek w API bywają opisane złą jednostką (np. „PLN/Hour” przy kwotach miesięcznych w O3, O15, O21, O25, O32,
  O33, O41; „PLN/Month” przy stawce godzinowej w O5 i O40; „USD/Hour” w O14; „Year” niezgodne z treścią w O42). W tabeli
  podaję kwotę z treści ogłoszenia, jeśli ją zawiera, a rozbieżność zaznaczam.
- Część ofert pochodzi od agencji i firm outsourcingowych (Antal, Jit Team, emagine, IT Factory, Sorigo, Link Group,
  Connectis, act digital, Kyotu, P&P Solutions, The Codest, Britenet, Hiberus); opisują wtedy projekt u klienta.
- Nie weryfikowałem, czy dana oferta wygasła później tego samego dnia; wszystkie miały `isActive: true` w chwili
  odczytu.

## 2. Tabela ofert

Klasy: A – agent jest metodą pracy; B – agent albo produkt LLM jest produktem; C – platforma, harness, zasady albo
wdrożenie pracy z agentami dla innych zespołów. Pierwsza litera wskazuje główny akcent. Tryb pracy i poziom według
ogłoszenia; „PL” przy lokalizacji oznacza Polskę.

| ID | Tytuł | Firma | Klasa | Poziom | Widełki i umowa | Tryb | Adres |
|---|---|---|---|---|---|---|---|
| O1 | Senior Agentic Engineer | B3 Consulting Poland | C | senior | B2B lub UoP, bez widełek | hybrydowo, Warszawa | https://justjoin.it/job-offer/b3-consulting-poland-senior-agentic-engineer-warszawa-ai |
| O2 | Agentic SDLC Engineer | Jit Team | C | senior | B2B 1000–1400 PLN/dzień (API: 20 000–27 998 PLN/mies.) | hybrydowo (1 dzień biuro), Warszawa | https://justjoin.it/job-offer/jit-team-agentic-sdlc-engineer-warszawa-ai |
| O3 | Agentic AI SDLC Engineer | emagine Polska | C | senior | B2B 23 520–28 560 PLN netto (API: „/Hour”) | hybrydowo, Warszawa | https://justjoin.it/job-offer/emagine-polska-agentic-ai-sdlc-engineer-warszawa-ai |
| O4 | AI Engineer (w treści: Agentic SDLC Senior Engineer) | IT Factory | C | senior | B2B, bez widełek | hybrydowo, Warszawa | https://justjoin.it/job-offer/it-factory-ai-engineer-warszawa-ai |
| O5 | AI Adoption & Enablement Lead | Antal | C | senior | B2B 150–180 PLN/h netto | hybrydowo, Warszawa | https://justjoin.it/job-offer/antal-sp-z-o-o--ai-adoption-enablement-lead-warszawa-ai |
| O6 | AI Enablement Engineer / Engineerka | Sorigo | C | mid | B2B, bez widełek | hybrydowo, Warszawa | https://justjoin.it/job-offer/sorigo-ai-enablement-engineer-engineerka-warszawa-ai |
| O7 | Agentic Product Manager | The Software House | A | senior | B2B 15 000–22 000 PLN netto | zdalnie | https://justjoin.it/job-offer/the-software-house-agentic-product-manager-warszawa-pm |
| O8 | Senior Software Engineer (Java) | B3 Consulting Poland | A | senior | B2B, bez widełek | zdalnie | https://justjoin.it/job-offer/b3-consulting-poland-senior-software-engineer-warszawa-java-053692fd |
| O9 | Senior Backend Engineer (AI-Native ERP) | Comarch | A | senior | B2B lub UoP, bez widełek | hybrydowo (3 dni biuro), Kraków/Warszawa | https://justjoin.it/job-offer/comarch-senior-backend-engineer-ai-native-erp--krakow-erp-2d9bf31f |
| O10 | Senior Full Stack Engineer (Python + React + SQL) | EPAM Systems | A | senior | bez widełek, UoP lub B2B | zdalnie (PL) | https://justjoin.it/job-offer/epam-systems-senior-full-stack-engineer-python-react-sql--warsaw-python-0887a13c |
| O11 | Senior Software Developer | Exponent CH | A+B | senior | UoP 21 000–26 000 PLN brutto | zdalnie, Warszawa | https://justjoin.it/job-offer/exponent-ch-senior-software-developer-warszawa-python |
| O12 | Principal Backend Engineer – Casino | Hard Rock Digital | A+C | senior (principal) | B2B 33 000–45 000 PLN netto | zdalnie (PL) | https://justjoin.it/job-offer/hard-rock-digital-principal-backend-engineer---casino-gdansk-java-e4811b6a |
| O13 | Python Engineer – Aviation Software Modernization | Jeppesen ForeFlight | A | senior (w treści: mid lub senior) | UoP 16 700–25 400 PLN brutto | zdalnie (PL) | https://justjoin.it/job-offer/jeppesen-foreflight-python-engineer---aviation-software-modernization-gdansk-python |
| O14 | Lead/Architect GenAI Adoption (freelance, part-time) | N-iX | C | manager | B2B, kwota w API nieczytelna („8400–10 080 USD/Hour”) | zdalnie | https://justjoin.it/job-offer/n-ix-lead-architect-genai-adoption-freelance-part-time--krakow-ai |
| O15 | Senior Fullstack Engineer (Python) | The Codest | A | senior | B2B 20 160–25 200 PLN netto (API: „/Hour”) | zdalnie | https://justjoin.it/job-offer/the-codest-senior-fullstack-engineer-python--warszawa-python |
| O16 | AI Dev Coach | Transition Technologies PSC | C | senior | bez widełek | hybrydowo, Warszawa | https://justjoin.it/job-offer/transition-technologies-psc-ai-dev-coach-warszawa-ai-54d0598f |
| O17 | AI Engineering Manager | ExpoPlatform | A+C | manager | B2B 5000–6000 EUR netto/mies. | zdalnie | https://justjoin.it/job-offer/expoplatform-ai-engineering-manager-druzbickiego-11-poland-ai |
| O18 | AI Native Full Stack Engineer Intern | Grid Dynamics Poland | A | junior (staż) | staż 5376 PLN brutto (API: „/Hour”), 30 h/tydz., 6 mies. | hybrydowo (4 dni biuro), Kraków/Warszawa | https://justjoin.it/job-offer/grid-dynamics-poland-ai-native-full-stack-engineer-intern-krakow-krakow-ai-0e0a47ad |
| O19 | Senior AI Native Developer | Startup House | A+B | senior | B2B 24 000–27 000 PLN netto | hybrydowo, Warszawa | https://justjoin.it/job-offer/startup-house-senior-ai-native-developer-warszawa-ai |
| O20 | AI-Native Engineer (Full-Stack / Agentic AI Engineer) | Vecten | A+B | mid | B2B 30 000–45 000 PLN netto | zdalnie lub hybrydowo, Warszawa | https://justjoin.it/job-offer/vecten-ai-native-engineer-full-stack-agentic-ai-engineer--warszawa-ai-499b9519 |
| O21 | Senior Golang Developer Remote | Superdevs | A | senior | B2B 23 520–26 880 PLN netto (API: „/Hour”) | zdalnie | https://justjoin.it/job-offer/superdevs-senior-golang-developer-remote-poland-remote--go-5d29385c |
| O22 | Senior Full-Stack Engineer | Vidoc Security Lab | B+A | senior | B2B 25 000–30 000 PLN netto lub UoP | zdalnie, Gdańsk | https://justjoin.it/job-offer/vidoc-security-lab-senior-full-stack-engineer-gdansk-javascript |
| O23 | Software Engineer | Upside | A+B | senior | B2B, bez widełek | hybrydowo lub zdalnie, Kraków | https://justjoin.it/job-offer/upside-software-engineer-krakow-ai |
| O24 | Senior Python Engineer/Tech Lead | Kyotu Technology | A | senior | B2B 140–170 PLN/h netto | zdalnie (PL) | https://justjoin.it/job-offer/kyotu-technology-senior-python-engineer-tech-lead-wroclaw-python |
| O25 | Forward Deployed Engineer | Link Group | B+A | senior | B2B 21 840–27 720 PLN netto (API: „/Hour”) | zdalnie | https://justjoin.it/job-offer/link-group--forward-deployed-engineer-warszawa-ai |
| O26 | Forward Deployed Engineer – AI Solutions | Sii | B+A | senior | B2B lub UoP, bez widełek | hybrydowo, 10 miast | https://justjoin.it/job-offer/sii-forward-deployed-engineer---ai-solutions-f-m-x--warszawa-ai |
| O27 | Head of Engineering – AdminBolt | webhosting.today | A+C | manager | B2B, bez widełek | zdalnie (strefa UE) | https://justjoin.it/job-offer/webhosting-today-inc-head-of-engineering---adminbolt-remote-eu--krakow-architecture |
| O28 | Vibe Coder | Fastline Advertising | A | mid | bez widełek | biuro, Warszawa | https://justjoin.it/job-offer/fastline-advertising-vibe-coder-warszawa-ai |
| O29 | AI-First Developer | House of Angular | A | mid | B2B lub zlecenie 10 000–18 000 PLN | zdalnie (z Warszawy ok. 3 dni biuro) | https://justjoin.it/job-offer/house-of-angular-fullstack-developer-ai-first--warszawa-ai |
| O30 | Product Engineer / Product Builder | Profitroom | A | senior | B2B 15 000–30 000 PLN netto | zdalnie | https://justjoin.it/job-offer/profitroom-product-engineer-product-builder---backend-or-full-stack-poznan-php |
| O31 | AI-Assisted QA Software Engineering Expert | Comarch | A | senior | UoP lub B2B, bez widełek | hybrydowo (3 dni biuro) | https://justjoin.it/job-offer/comarch-ai-assisted-qa-software-engineering-expert-krakow-ai |
| O32 | Solution Architect (Agentic AI) | Connectis | C | senior | B2B 26 880–30 240 PLN netto (API: „/Hour”) | hybrydowo, Warszawa | https://justjoin.it/job-offer/connectis-solution-architect-agentic-ai--warszawa-architecture |
| O33 | Architect – AI Engineering Lead | act digital | C | senior | B2B do 149 PLN/h netto | hybrydowo, Warszawa | https://justjoin.it/job-offer/act-digital-architect--ai-engineering-lead-warszawa-architecture |
| O34 | Senior AI Engineer (AI-Assisted Software Development) | Antal | A+C | senior | B2B 160–180 PLN/h | zdalnie (PL) | https://justjoin.it/job-offer/antal-sp-z-o-o--senior-ai-engineer-ai-assisted-software-development--warszawa-ai |
| O35 | Junior Backend Engineer | Synerise | A | junior | B2B lub zlecenie 7000–9000 PLN | hybrydowo, Kraków | https://justjoin.it/job-offer/synerise-junior-backend-engineer-krakow-scala |
| O36 | Senior Agentic AI Engineer – PDLC | UNIQA | C | manager (API) | B2B, bez widełek | biuro, Warszawa | https://justjoin.it/job-offer/uniqa-lead-agentic-ai-engineer---pdlc-warszawa-ai |
| O37 | Senior AI Engineer for QA and SDLC | Procter & Gamble | C | senior | UoP, bez widełek | hybrydowo, Warszawa | https://justjoin.it/job-offer/procter-gamble-senior-ai-engineer-for-qa-and-sdlc-warszawa-testing |
| O38 | Student Worker – AI Tools Administration & Governance Support | KMD Poland | C | junior (student) | bez widełek, do 30 h/tydz. | hybrydowo (2 dni biuro), Warszawa | https://justjoin.it/job-offer/kmd-poland-student-worker---ai-tools-administration-governance-support-warszawa-admin |
| O39 | Senior Platform Engineer | EPAM Systems | C | senior | bez widełek, UoP lub B2B | zdalnie (PL) | https://justjoin.it/job-offer/epam-systems-senior-platform-engineer-warsaw-devops |
| O40 | Senior Fullstack Developer (Java + React) | P&P Solutions | A | senior | B2B 130–160 PLN/h netto | zdalnie | https://justjoin.it/job-offer/p-p-solutions-senior-fullstack-developer-java-react--warszawa-java-162a8230 |
| O41 | Senior Agentic AI Engineer | Spyrosoft | B | senior | B2B 23 520–28 560 PLN netto (API: „/Hour”) | zdalnie | https://justjoin.it/job-offer/spyrosoft-senior-agentic-ai-engineer-wroclaw-ai-f8d32cfb |
| O42 | Advanced AI Engineer (Agent Harness) | Relativity | B | mid | UoP 188 000–282 000 PLN rocznie (z treści) | zdalnie (PL) | https://justjoin.it/job-offer/relativity-advanced-ai-engineer-krakow-ai |
| O43 | Senior AI Agent Engineer | Procter & Gamble | B | senior | UoP 20 450–33 550 PLN brutto | hybrydowo, Warszawa | https://justjoin.it/job-offer/procter-gamble-senior-ai-agent-engineer-warszawa-admin |
| O44 | Senior Python Engineer — MCP Protocol & Tool Integration | EPAM Systems | B | senior | bez widełek, UoP lub B2B | zdalnie (PL) | https://justjoin.it/job-offer/epam-systems-senior-python-engineer---mcp-protocol-tool-integration-poznan-python |
| O45 | Agentic Integration & MCP Engineer | Capgemini | B | mid | B2B, bez widełek | hybrydowo, 8 miast | https://justjoin.it/job-offer/capgemini-agentic-integration-mcp-engineer-wroclaw-java |
| O46 | Expert LLM Engineer (AI Security) | Framna | B | senior | B2B 29 600–35 500 PLN netto lub UoP 24 500–29 400 PLN brutto | hybrydowo, Gdańsk | https://justjoin.it/job-offer/framna-expert-llm-engineer-ai-security--gdansk-ai-011f30e4 |
| O47 | AI Tester / AI Evaluation Engineer | Hiberus Poland | B | senior | B2B, bez widełek | zdalnie | https://justjoin.it/job-offer/hiberus-poland-ai-tester-ai-evaluation-engineer-wroclaw-testing |
| O48 | AI Engineer – Agentic AI | PKO BP Finat | B | mid | B2B, bez widełek | hybrydowo, Warszawa | https://justjoin.it/job-offer/pko-bp-finat-ai-engineer---agentic-ai-warszawa-ai |
| O49 | Generative AI Engineer (Product Intelligence) | Tesco Technology | B | mid | UoP 20 000–25 000 PLN brutto | hybrydowo (3 dni biuro), Kraków | https://justjoin.it/job-offer/tesco-technology-generative-ai-engineer-product-intelligence--krakow-ai |
| O50 | AI Engineer – Agentic Workflows (Node.js/React) | Cloudfide | B | mid | B2B lub UZ, bez widełek | zdalnie | https://justjoin.it/job-offer/cloudfide-spolka-z-ograniczona-odpowiedzialnoscia-ai-engineer---agentic-workflows-node-js-react--warszawa-javascript |
| O51 | AI / LLM Engineer – infrastruktura inferencji i systemy RAG | allclouds.pl | B | mid | UoP, bez widełek | biuro, Warszawa | https://justjoin.it/job-offer/allclouds-pl-ai-llm-engineer---infrastruktura-inferencji-i-systemy-rag-warszawa-ai |
| O52 | Lead Forward Deployed Engineer | EPAM Systems | B | senior | bez widełek, UoP lub B2B | zdalnie (PL) | https://justjoin.it/job-offer/epam-systems-lead-forward-deployed-engineer-krakow-ai |
| O53 | LLMOps | Hiberus Poland | B | mid | B2B 23 500–28 496 PLN netto | hybrydowo, Warszawa | https://justjoin.it/job-offer/hiberus-poland-llmops-warszawa-devops |
| O54 | AI Automation Engineer | G2A | B | mid | B2B lub UoP, bez widełek | zdalnie lub hybrydowo | https://justjoin.it/job-offer/g2a-ai-automation-engineer-warszawa-ai |
| O55 | Python AI Engineer (Agentic Systems / LLM) Senior | Britenet | A+B | senior | B2B, bez widełek | zdalnie | https://justjoin.it/job-offer/britenet-python-ai-engineer-agentic-systems-llm-senior-warszawa-ai |
| O56 | Lead Responsible AI Engineer | HERE Technologies | C | senior | UoP 19 000–28 000 PLN brutto | hybrydowo, Kraków | https://justjoin.it/job-offer/here-technologies-lead-responsible-ai-engineer-krakow-ai |

Widełki podało 31 z 56 ofert. Wszystkie oferty mają lokalizację w Polsce; O17 i O27 dopuszczają pracę z dowolnego
miejsca z odpowiednią strefą czasową.

## 3. Kompetencje

Kategorie wyprowadziłem z treści ofert. „Wymagane” oznacza sekcję wymagań albo obowiązków; „mile widziane” – sekcję
nice-to-have albo bonus.

### 3.1. Kompetencje techniczne: wymagane

| Kompetencja (jak opisują ją oferty) | Ofert | ID |
|---|---|---|
| Praktyczna praca z narzędziami AI do wytwarzania kodu (agenci kodujący, asystenci) | 36 | O1–O17, O19–O35, O40, O55 |
| – w tym wprost jako codzienne albo główne środowisko pracy | 19 | O9, O11, O12, O17, O19, O20, O22, O23, O24, O25, O26, O27, O28, O29, O30, O34, O35, O40, O55 |
| Weryfikacja, przegląd i branie odpowiedzialności za kod lub wynik wygenerowany przez AI | 16 | O7, O11, O13, O17, O19, O20, O21, O22, O23, O24, O27, O28, O29, O31, O34, O55 |
| Specyfikacje przed implementacją, spec-driven development, dzielenie pracy na zadania wykonalne przez agenta | 16 (+1 mile widziane) | O1, O2, O3, O4, O6, O7, O11, O13, O17, O19, O23, O27, O30, O34, O40, O55; mile widziane O16 |
| Budowa agentów, workflowów, skilli i promptów dla procesu wytwórczego i ich integracja z repozytorium, CI/CD, code review i testami | 13 | O1, O2, O3, O4, O5, O6, O14, O16, O17, O32, O33, O34, O36 |
| Kontekst dla agentów: pliki instrukcji, repozytorium kontekstu, pamięć projektu, konfiguracja harnessu | 8 (+1 mile widziane) | O7, O11, O19, O23, O27, O28, O29, O55; mile widziane O13 |
| Budowa systemów LLM w produkcji: RAG, tool calling, orkiestracja, multi-agent | 27 z RAG w treści | O1–O4, O6, O9, O11, O14, O16, O19, O20, O22, O25, O26, O36, O41–O43, O45–O48, O50, O51, O53–O55 (w O1–O4, O6, O9, O16 jako znajomość pojęć albo mile widziane) |
| Ewaluacja systemów LLM i agentów (zbiory testowe, regresja, benchmarki) | 22 | O11 (bonus), O14, O17, O19, O23, O25, O33, O36, O37, O41, O42 (preferowane), O43, O45, O46, O47, O48, O49, O50, O53, O54 (mile widziane), O55, O56 |
| CI/CD, testy automatyczne, praktyki produkcyjne | większość; CI/CD w 36 (wyszukiwanie w treści) | m.in. O8, O9, O13, O17, O21, O24, O27, O30, O55 |
| Bezpieczeństwo i zgodność: dane osobowe, poufność, regulacje, sekrety | 31 z tematem bezpieczeństwa (wyszukiwanie w treści) | m.in. O1, O3, O11 (nFADP, GDPR), O13, O17 (PII), O27, O28 (klucze API), O45, O46, O51, O54 (GDPR), O56 |
| Wiedza o działaniu i ograniczeniach LLM (halucynacje, kontekst, koszt, ryzyka) | 7 z halucynacjami wprost | O17, O19, O25, O31, O33, O48, O53; ograniczenia GenAI także O5, O54 |
| Pomiar efektu wdrożenia AI (KPI, OKR, ROI, DORA, przepustowość) | 11 (+1 analiza użycia) | O1, O2, O3, O4, O6, O14, O16, O17, O27, O36, O39; analiza wykorzystania narzędzi O5 |
| Optymalizacja albo monitoring kosztów użycia modeli | 11 (+2 mile widziane) | O1, O3, O4, O6, O19, O46, O48, O50, O53, O54, O55; FinOps mile widziane O16, O32 |
| Praca ze starym kodem, migracje, refaktoryzacja z użyciem agentów | 6 | O13, O17, O24, O27 (migracje danych jako ryzyko), O31 (zgodność z legacy core), O40 |
| Klasyczne podstawy przy rolach A: transakcje, idempotencja, architektura | wybrane | O8 (idempotencja, kwoty, Clean/Hexagonal, ADR), O9 (izolacja transakcji, saldo a zapis księgowy, audytowalność), O24, O27 (Linux, aktualizacje na tysiącach maszyn) |

Wymagane doświadczenie zawodowe, jeśli podane: 2 lata (O29), 3 lata (O13, O39, O42, O47, O54; O17: 3 lata
prowadzenia zespołów), 4 lata (O26, O36, O45), 5 lat (O2–O4, O6, O9, O10, O11, O16, O19, O22, O32, O44, O48, O55), 5–8 lat (O33), 6 lat (O15, O21, O25),
7 lat (O27, O46), 8 lat (O14), 10 lat (O12, O24). Wykształcenie wymagają albo wysoko cenią O23, O41 (MSc), O42, O43;
O18 wymaga statusu studenta albo absolwenta.

### 3.2. Kompetencje techniczne: mile widziane

| Kompetencja | Ofert | ID |
|---|---|---|
| LangGraph i MCP jako dodatek do roli wdrożeniowej | 6 | O1–O6 (identyczny zapis w klastrze K1 i O5) |
| Tworzenie serwerów MCP, własnych agentów, skilli, narzędzi wewnętrznych | 5 | O19, O28, O29 (wymagane), O30 (mocny atut), O34 |
| Ewaluacje funkcji LLM, uruchamianie agentów równolegle (subagenci, worktree) | 1 | O11 |
| Narzędzia obserwowalności LLM (Langfuse i podobne) | 4 | O19, O46, O49, O55 |
| Duże refaktoryzacje i migracje agentami, instrukcje repozytorium, automatyczna weryfikacja | 1 | O13 |
| Testy bezpieczeństwa GenAI (prompt injection, jailbreak) | 2 | O47, O54 (w O46 wymagane) |
| Narzędzia specyfikacji (OpenSpec, GitHub Spec Kit) | 1 | O34 |
| Branża regulowana (finanse, ubezpieczenia, pharma, healthcare, kasyna, lotnictwo) | 13 (+4 wymagane) | mile widziane: O6, O10, O12, O13, O14, O15, O16, O20, O24, O25, O26, O36, O48; wymagane: O2, O3, O4 (realia dużej organizacji finansowej), O7 (fintech) |
| Publiczne materiały: wystąpienia, wpisy, playbooki, open source | 4 | O16, O17, O19, O23 (i O28: portfolio) |

### 3.3. Kompetencje pozatechniczne

| Kompetencja | Ofert | ID |
|---|---|---|
| Bezpośredni kontakt z klientem, ekspertem domenowym albo biznesem | 12 | O7, O11, O15, O20 (rozmowy z CFO i partnerami funduszy), O22 (founderzy), O23, O24, O26, O41, O52, O54, O55 |
| Odpowiedzialność za funkcję albo rozwiązanie od początku do produkcji | 18 | O9, O11, O13, O15, O19, O20, O21, O22, O23, O24, O26, O27, O30, O42, O50, O54, O55, O7 (projekt fixed-price od estymacji po wdrożenie) |
| Warsztaty, szkolenia, coaching, transfer wiedzy do zespołów | 14 | O1, O2, O3, O4, O5, O6 (mile widziane), O14, O16, O17, O32, O33, O34, O45, O38 (onboarding użytkowników) |
| Rozmowy z kadrą zarządzającą, rekomendacje i raporty dla zarządu | 7 | O1, O3, O4, O14, O16, O32, O33 |
| Zarządzanie zmianą i oporem wobec zmiany | 3 | O1, O3, O16 |
| Prowadzenie ludzi: zespół, mentoring, rekrutacja | 8 (+1 mile widziane) | O10 (mentoring juniorów), O12, O14, O17, O19, O27 (zespół, zatrudnianie), O34, O37 (zespół QA); mile widziane O24 |
| Ocena, czy coś warto budować; pragmatyzm w doborze rozwiązania | 6 | O19 (agent, pojedyncze wywołanie modelu albo zwykły kod), O20, O22, O26, O30, O54 |
| Estymacja, zakres, budżet, ryzyka | 6 | O7, O24, O26, O27, O54, O14 (roadmapa z ryzykami) |
| Samodzielność przy niejasnych wymaganiach | wiele | m.in. O14, O20, O23, O26, O49, O55 |
| Angielski | 34 (w treści) | najczęściej B2 albo C1; C2 w O13 i O22. Język polski w polu języków: 18 ofert (B2–C2) |

## 4. Narzędzia i technologie

Nazwy zapisane tak jak w ogłoszeniach. Liczba oznacza przeczytane oferty, w których nazwa występuje (w wymaganiach,
obowiązkach albo w mile widzianych); liczyłem wyszukiwaniem w ich pełnej treści i poprawiałem fałszywe trafienia ręcznie.
Dla chmur i języków liczby są przybliżone, bo nazwy często występują jako alternatywy.

### Agenci i asystenci kodujący

| Narzędzie | Ofert | ID |
|---|---|---|
| Claude Code | 38 | O1–O35, O38, O39, O55 (w O18, O38, O39 tylko mile widziane; w O34 obok „OpenSpec”; w umiejętnościach O32 zapisane „claud code”) |
| Cursor | 30 | O1–O16, O18–O20, O22, O24–O27, O30, O31, O35, O38, O39, O55 |
| Codex (także „OpenAI Codex”) | 23 | O1–O11, O13, O14, O17, O18, O20, O21, O23, O27, O29, O32, O33, O55 |
| GitHub Copilot | 20 | O1–O9, O12, O14–O16, O24–O26, O35, O36, O38, O40; „Copilot” bez nazwy GitHub w O19 |
| Microsoft 365 Copilot (M365 Copilot) | 9 | O1–O6, O16, O38, O51 |
| OpenCode | 2 | O39, O40 |
| Trae, Antigravity | 1 | O39 |
| Databricks AI Assistant | 1 | O25 |
| GitHub Agentic Workflows | 1 | O50 |
| „Copilot agent”, Gemini CLI, Kiro, Windsurf | 0 w próbie | występują w przesiewie automatycznym poza próbą |

Konkretne narzędzie bez alternatyw wymagają O28 (Claude Code) i O34 („Daily commercial experience using Claude Code”);
O29 wymaga Claude Code albo Codex. O30 pisze, że Claude Code jest w firmie głównym narzędziem, ale od kandydata
przyjmuje też Cursor albo odpowiednik. Pozostałe oferty podają listę z dopiskiem „or similar” albo „np.”.

### Dostawcy, modele i platformy LLM

OpenAI (API, Codex) – 11 (O14, O20, O23, O32, O41, O42, O43, O44, O45, O49, O54); Gemini – 4 jako model albo
platforma (O20, O42, O43 „Gemini Agent Platform”, O52 „Google Gemini Enterprise”) i 3 jako certyfikacja w benefitach
EPAM (O10, O44, O52); Claude jako
model, API albo platforma – 10 (O1, O11, O19, O20, O26, O28, O29, O38 „Anthropic Claude platform”, O45 „Claude APIs”,
O54); Anthropic – 6, w tym certyfikacja Anthropic jako benefit w O10, O44, O52 (EPAM); Azure OpenAI – 2 (O41, O49
„Azure OpenAI GPT-4”); AWS Bedrock i AgentCore – 4 (O39, O41, O44, O55); Google Vertex AI – 2 (O39, O41); Imagen, Veo –
1 (O43); modele lokalne albo open-source – 6 (O1, O3, O11, O27, O51 vLLM, O54). Bramy do modeli z routingiem i limitami:
O51; model routing w O14.

### Frameworki agentowe i automatyzacja

LangGraph – 17 (O1–O6, O16, O20, O26, O36, O41–O45, O48, O55); LangChain – 10 (O11, O16, O20, O26, O41–O45, O48);
Semantic Kernel – 4 (O26, O36, O41, O45); CrewAI – 3 (O20, O36, O43); LlamaIndex – 2 (O11, O48); Agno – 2 (O20, O48,
w O48 jako baza platformy); AutoGen – 1 (O36); Deep Agents („deepagents”) – 1 (O42); Strands Agents – 1 (O44); Google
Agent Development Kit (ADK) – 1 (O43); Pydantic AI – 1 (O55); AutoGPT – 1 (O44); Azure AI Foundry – 2 (O36, O45);
Copilot Studio – 1 (O36); n8n – 3 (O17, O52, O54); UIPath – 1 (O52).

### Protokoły

MCP (Model Context Protocol) – 25 ofert: O1–O7, O11, O17, O19, O22, O25, O28–O30, O32, O34, O42–O45, O50, O54–O56.
W tym budowa serwerów i klientów MCP jako główne zadanie: O44 (tools/list, tools/call, Streamable HTTP, OpenAPI-to-MCP),
O45, O50, O25; skanowanie serwerów MCP pod kątem ryzyka: O56. A2A (Agent-to-Agent) – 1 (O42).

### Ewaluacja i obserwowalność

Langfuse – 6 (O19, O45, O46, O48, O49, O55); Promptfoo – 2 (O46, O48); LangSmith – 1 (O48); DeepEval – 1 (O48);
garak – 1 (O46); Arize Phoenix – 1 (O45); OpenTelemetry – 3 (O35, O42, O45); MLflow – 1 (O42); Jaeger – 1 (O35).
Metryki procesu: DORA – 2 (O17, O39), SPACE i SLO – 1 (O17).

### Chmura i infrastruktura

AWS – 18, Azure – 18, GCP – 16 (często jako alternatywy). Kubernetes (w tym AKS, OpenShift) – 13; Docker – większość
ofert technicznych; Terraform – 4 (O20, O39, O42, O49); Pulumi – 3 (O22, O39, O42); Backstage, Humanitec, OpsLevel,
Crossplane, Flux, ArgoCD – 1 (O39); Temporal – 1 (O22); GPU NVIDIA, CUDA, vLLM – 2 (O51, O53).

### Języki

Python – 31; TypeScript – 18; Java – 13; Go/Golang – 4 (O9, O19, O21, O25); Node.js – 8; C#/.NET – 5; PHP – 3 (O17,
O27, O30); Scala, Rust, Kotlin – 1–2 (O8, O9, O35). Frontend React – 14, Vue.js – 2 (O15, O30), Angular – 3 (O24, O29,
O18).

### Tło ilościowe z przesiewu automatycznego (1903 opisy, nie lektura)

Liczba opisów, w których pada nazwa (w dowolnym miejscu, także jako mile widziane): Claude Code 258, GitHub Copilot 230,
Cursor 204, „AI-assisted development” 136, „AI coding” 119, Codex 98, „agentic coding” 28, „spec-driven” 23, „agentic
SDLC” 21, „coding agent” 21, „vibe coding” 10, Kiro 6, Gemini CLI 5, Windsurf 2. 521 opisów z nazwą narzędzia albo
praktyki pochodzi z kategorii: ai 90, java 76, python 52, data 51, javascript 39, devops 33, architecture 28, testing 23,
net 20, pm 20, mobile 18 i inne. Wzmianki o agentach kodujących występują więc także w ogłoszeniach dla QA, danych,
DevOps, produktu i UX, nie tylko programistów.

## 5. Praktyki inżynierskie

| Praktyka | Ofert | ID i sposób ujęcia |
|---|---|---|
| Przegląd kodu wygenerowanego przez AI przez człowieka | 16 | patrz 3.1; najdokładniej O13 (poprawność, bezpieczeństwo, utrzymywalność, zgodność z istniejącym systemem), O19 (halucynacje, ryzyka bezpieczeństwa, „architectural drift”), O27 (bezpieczeństwo, uprawnienia, migracje danych, kod systemowy), O31 (ciche błędy logiczne, przypadki brzegowe) |
| Code review w ogóle (ludzie wzajemnie, AI code review) | 21 | O2, O3, O4, O6, O10, O11, O12, O13, O15, O17, O22, O24, O27, O28, O29, O30, O34, O42, O49, O50, O55; AI code review jako element procesu: O17, O27 („AI-assisted review before human review”), O2–O4, O6 |
| Specyfikacje i plany przed przekazaniem pracy agentowi | 16 | O11 („writing clear specs and plans before handing work to an agent”), O13 (kryteria akceptacji i „well-scoped units of work”), O23 (rozkład specyfikacji architektury na zadania), O55 (specyfikacje zadań), O7 (wymagania dla agenta); SDD w O1–O4, O6, O17, O19, O30, O34, O40 |
| Testy jako zabezpieczenie wyniku agenta | 7 | O11 (TDD jako „guardrail”, jedyna oferta z TDD), O13 (testy, analiza statyczna, narzędzia bezpieczeństwa, obserwowalność), O19, O23 (testy, evals, kontrole w czasie działania), O24 (generowanie testów), O27 („CI that blocks merges”), O55 (testy i dane; mypy, ruff, pytest) |
| Ewaluacje na danych przed wydaniem (systemy LLM) | 22 | patrz 3.1; regresja po zmianie modelu, promptu albo danych: O47, O49, O53, O55; „evals for safe model upgrades” O42 |
| Wersjonowanie promptów, narzędzi, konfiguracji i zbiorów ewaluacyjnych | 3 | O45, O49 (kryteria wydania zmian promptów i modeli), O53 |
| Zarządzanie kontekstem agenta | 8 (+1 mile widziane) | patrz 3.1; O11 (CLAUDE.md, AGENTS.md, narzędzia MCP, zakres zadania), O28 („drugi mózg”: baza wiedzy, pamięć projektowa), O7 („context repository”), O55 (instrukcje projektu, skille, hooki, subagenci, serwery MCP) |
| Guardrails i kontrola działań agentów | 11 | O11, O14, O17, O20, O27, O33 (reguły wytwarzania i kontroli jakości dla agentów), O36, O41, O45 (tożsamość, autoryzacja, sekrety, audyt akcji agentów), O55, O56 (ochrona w czasie działania, egzekwowanie polityk) |
| Bezpieczeństwo danych przy użyciu zewnętrznych modeli | 6 | O11 (kiedy dane klienta mogą trafić do zewnętrznego API), O17 (PII), O28 (klucze API, zmienne środowiskowe), O51 (dane poufne nigdy do zewnętrznych modeli), O54 (dane osobowe w promptach i logach), O3 (kontrola dostępu) |
| Testy bezpieczeństwa systemów LLM (prompt injection, tool misuse, red teaming) | 3 | O46 (wymagane), O47, O54 (mile widziane) |
| Kontrola kosztów modeli | 13 | patrz 3.1; w rolach C jako optymalizacja kosztów użycia modeli; w B jako monitoring kosztu i tokenów (O19, O53, O55), dobór modelu, cache i limity (O46) |
| Obserwowalność | 17 | O13, O14, O19, O20, O22, O35, O36, O37, O42–O46, O48, O49, O53, O55 |
| CI/CD jako miejsce integracji agentów | 13 | O1–O6, O14, O17, O27, O32, O33, O36, O37 |
| Zapisywanie decyzji (ADR, RFC, dokumentacja decyzji) | 6 | O8 (ADR), O9, O17 (RFC, ADR, post-mortem), O23 (dla ludzi i agentów), O33 (HLD), O51 (ISO) |
| Równoległa praca wielu agentów | 3 | O11 (subagenci, worktree – bonus), O23 („run work in parallel under strict quality control”), O27 („run parallel agent sessions and merge their output”) |
| Pomiar wpływu AI na dostarczanie | 11 | patrz 3.1; O17 (cycle time, throughput, defect rate, DORA), O3 (m.in. „kosztu jednostkowego zmiany”), O27 („measure the actual throughput gain”) |
| Wspólne standardy pracy z AI zamiast indywidualnych preferencji | 9 | O1–O4, O6, O17, O19, O27 („not individual tooling preferences”), O29 |

## 6. Organizacja pracy

**Wielkość i skład zespołu.** Oferty podają: pierwsze trzy osoby nowego huba (O11); mały zespół cross-funkcyjny (O13);
3–9 osób na projekt (O23); firma 20-osobowa (O22); organizacja inżynierska 50 osób (O17); małe zespoły i płaska
struktura (O21, O20, O27); po stronie klienta ponad 1000 osób w IT (O2). Skład: inżynier z PM i architektem, przy czym
architektura chmury zostaje po stronie architekta (O9); PM odpowiada za „co i dlaczego”, inżynier za „jak” (O30);
QA w zespołach i „konwojach” (O31); zespół Agentic PDLC współpracujący z architekturą, AI Platform, DevSecOps i ITSM
(O36); zespół inżynierów jakości prowadzony przez autora roli (O37).

**Samodzielność.** Wprost wysoka: „Real Autonomy” i praca z ograniczonym wsparciem przy kilku projektach klientów
równolegle (O20), „Real Autonomy” bez zbędnego nadzoru (O23), praca przy minimalnym nadzorze (O26), wysoka
samodzielność zespołu (O55), decyzje o kierunku projektu (O19), niezależność (O21). Odwrotny akcent: szczegółowe
planowanie i rozliczanie, kto co robi i do kiedy (O27).

**Odpowiedzialność od początku do wdrożenia.** 18 ofert (3.3). Formuła „you build it, you run it” pada w O19 i O21;
O30 zaznacza, że gotowość nie kończy się na merge i że jakość produkcyjna nie jest zadaniem wyłącznie QA; O13 obejmuje
utrzymanie stabilności w produkcji i wsparcie produkcyjne.

**Dyżury i incydenty.** Żadna oferta nie używa słów „on-call” ani „dyżur”. Obsługa produkcji i incydentów pojawia się
w O13 (production support), O27 (SLA dla poprawek blokujących klientów, komunikacja incydentów, zakaz wydania z otwartymi
błędami High/Highest), O53 (obsługa incydentów), O33 (analiza incydentów powdrożeniowych z udziałem AI).

**Metodyka i rytm.** Agile pojawia się w O10, O12, O17, O19, O31, O41; Scrum tylko jako nazwa roli w O5. Stały rytm
wydań, code freeze, decyzja go/no-go i cotygodniowy status dla CEO: O27. Projekty fixed-price: O7. Rozliczenie
etapami i protokołami odbioru: O32. Podział czasu 70% nowe funkcje, 20% błędy i utrzymanie, 10% spotkania: O29.

**Tempo i produktywność.** Oczekiwanie wzrostu tempa jest wprost w O12 („engineering velocity”), O15, O17 (zysk
efektywności udowodniony danymi, nie szacunkiem), O25, O26 (produkty „in weeks, not quarters”), O27 (zmierzony wzrost
przepustowości), O31 („hyper-delivery”, „wielokrotnego przyspieszenia procesu QA”). Żadna oferta nie podaje
docelowej liczby (np. procentu przyspieszenia). Wynagrodzenie zależne od wpływu na wyniki klientów: O20.

**Narzędzia opłacane przez pracodawcę.** O11, O20 (licencje Cursor, Claude Pro), O23 (Claude Code, OpenAI Codex), O26
(Claude, Cursor, GitHub Copilot), O17 (dostęp do narzędzi i swoboda testowania nowych); „zaawansowane narzędzia AI” jako
benefit w O9 i O31.

**Rekrutacja sprawdzająca pracę z agentem.** O11: sesja techniczna z własnymi narzędziami AI, oceniane jest, jak kandydat
zakreśla, specyfikuje i weryfikuje pracę. O27: zadanie polega na znalezieniu błędów w PR wygenerowanym przez AI. O30:
code review albo projekt systemu. O17: „Be ready to show what you built.”

## 7. Podział odpowiedzialności człowiek–agent (stwierdzenia wprost)

**Kod i wynik należą do człowieka.**
- O13: „The code is still yours when it ships.”
- O19: odpowiedzialność obejmuje „every line an agent wrote”.
- O11: kandydat przegląda, testuje i rozumie każdą zmianę przed wydaniem, „whoever wrote it”; wie, kiedy ufać agentowi,
  a kiedy wkroczyć.
- O24: samodzielny przegląd i walidacja wygenerowanego kodu oraz branie za niego odpowiedzialności.
- O31: przy kodzie współtworzonym przez Agentic AI „to Ty mówisz ostatnie słowo”.
- O51 (zasady firmy): „Człowiek decyduje”; człowiek weryfikuje, poprawia i podpisuje wynik; miejsca użycia AI są
  oznaczane; kwestionowanie wyniku AI jest zawsze dopuszczalne.
- O20: kandydat wie, kiedy akceptacja wszystkiego bez czytania („accept all”) jest bezpieczna, a kiedy nie.

**Człowiek ustala, co budować; agent realizuje.**
- O7: PM przygotowuje wymagania tak precyzyjne, by agent mógł nad nimi pracować samodzielnie; AI pomaga tworzyć
  i weryfikować epiki i backlog, ale priorytety i decyzje produktowe zostają po stronie człowieka.
- O13, O23, O55: człowiek dzieli pracę na zadania dla agentów i ocenia ich wynik testami i danymi.
- O33: rozwiązania pozwalające delegować agentom złożone zadania z zachowaniem założeń architektonicznych,
  bezpieczeństwa i kontroli jakości.

**Zaprojektowane punkty kontroli człowieka.**
- O17: guardrails określają m.in., „when a human must stay in the loop”.
- O14: „human-in-the-loop controls” i quality gates w workflowach agentowych SDLC.
- O36: punkty Human-in-the-Loop, zasady akceptacji rezultatów i kontrola działań agentów w cyklu dostarczania.
- O27: testy generowane, CI blokujące merge, przegląd wspomagany AI przed przeglądem człowieka.
- O54: wiedza, kiedy nadzór człowieka jest konieczny.

**W produktach (klasa B).**
- O49: 100% przeglądu przez człowieka przed publikacją jako podstawowa kontrola platformy.
- O55: system przygotowuje szkic decyzji, który ekspert domenowy zatwierdza albo poprawia; harness ewaluacyjny porównuje
  wynik systemu z decyzjami ekspertów.
- O42, O43: wzorce human-in-the-loop w runtime agentów.

**Agenci jako uczestnicy dokumentacji.** O23 wymaga dokumentowania decyzji „for both human and synthetic contributors”;
O7 i O28 opisują repozytorium kontekstu i pamięć projektową, z których korzystają zespół i agenci.

**Rekrutacja.** O32: wirtualny rekruter AI, ale „Finalną decyzję zawsze podejmuje Opiekun Projektu”. O51: automat
porządkuje zgłoszenia, każde CV czyta człowiek.

Żadna oferta nie opisuje agentów pracujących bez przeglądu człowieka ani nie podaje, ile zadań lub zmian agent wykonuje
samodzielnie.

## 8. Różnice między klasami A, B i C

| Wymiar | A (agent jako metoda) | B (agent jako produkt) | C (platforma, zasady, wdrożenie) |
|---|---|---|---|
| Nazwy agentów kodujących | we wszystkich 27 ofertach A | w żadnej z 14 ofert „tylko B” (O41–O54); w ofertach A+B tak | w 17 z 19 (bez O37 i O56; w O36 tylko GitHub Copilot jako mile widziany) |
| Rdzeń wymagań | przegląd wyniku agenta, specyfikacje, kontekst repo, testy, odpowiedzialność za produkcję, klasyczny stos | frameworki (LangGraph, LangChain, Semantic Kernel, Agno), RAG, MCP, ewaluacje, obserwowalność, bezpieczeństwo LLM | wdrożenie Agentic SDLC, standardy i governance, pomiar (KPI, ROI, DORA), warsztaty, zarządzanie zmianą |
| Ewaluacje | rzadko (O11 bonus, O17, O19, O23, O55) | prawie zawsze (O41–O50, O53, O54) | w części (O14, O33, O36, O37, O56) |
| Koszt modeli | w żadnej ofercie „tylko A” | monitoring kosztu, dobór modelu, cache (O46, O48, O50, O53, O54) | optymalizacja kosztów użycia modeli (O1, O3, O4, O6, O16) |
| Kontakt z ludźmi | klient i ekspert domenowy (O7, O11, O15, O20, O23, O24, O55) | biznes i klient (O26, O41, O52, O54) | kadra zarządzająca i liderzy (O1, O3, O4, O14, O16, O32, O33) |
| Środowisko | software house'y, produkty, startupy, korporacje | korporacje (P&G, Tesco, PKO BP Finat, Relativity), konsulting | ubezpieczenia (K1), bank (K2), pharma (O14), korporacje (UNIQA, P&G, HERE, KMD); 10 z 19 przez agencje albo outsourcing (O1–O6, O14, O32–O34) |
| Tryb | przeważnie zdalnie | mieszany | przeważnie hybrydowo w Warszawie (O1–O6, O16, O32, O33, O38), biuro w O36 |
| M365 Copilot | brak | O51 (zasady firmy) | O1–O6, O16, O38 |
| Widełki (jeśli podane) | od 7000–9000 PLN (O35, junior) do 33 000–45 000 PLN B2B (O12, principal) | od 20 000–25 000 PLN UoP (O49) do 29 600–35 500 PLN B2B (O46) | 1000–1400 PLN/dzień (O2), 150–180 PLN/h (O5), do 149 PLN/h (O33), 19 000–28 000 PLN UoP (O56) |

Oferty C z klastra K1 (O1–O4, O6) mają ten sam zestaw: GitHub Copilot, Claude Code, Cursor, Codex, M365 Copilot,
Spec-Driven Development, LangGraph i MCP jako mile widziane, pair/mob programming, wspólne code review i stopniowe
przekazywanie odpowiedzialności zespołom. Ponieważ to najpewniej jeden program, nie należy traktować tych pięciu ofert
jako pięciu niezależnych sygnałów rynku.

## 9. Sygnały pojedyncze albo nowe (1–3 oferty)

- **Harness jako pojęcie.** „harness engineering patterns” i agenci „headless/background” (O25); zespół „Agent Harness”
  odpowiedzialny za runtime agentów produktowych (O42); konfiguracja „agent harness” agenta kodującego: instrukcje
  projektu, skille, hooki, subagenci, serwery MCP (O55). Trzy oferty, trzy różne znaczenia.
- **Równoległe sesje agentów i scalanie ich wyników:** O11, O23, O27.
- **Hooki agenta kodującego:** tylko O55. **Worktree:** tylko O11. **AGENTS.md:** tylko O11; **CLAUDE.md:** O11, O19,
  O28, O29.
- **Zadanie rekrutacyjne: znajdź błędy w PR wygenerowanym przez AI** (O27); rozmowa techniczna z własnymi narzędziami AI
  (O11).
- **Product Manager w zespole z agentami** z obowiązkiem prowadzenia repozytorium kontekstu dla zespołu i agentów (O7).
- **QA jako ostatnia instancja nad kodem współtworzonym przez agentów** oraz „konwoje” i „hyper-delivery” (O31).
- **Skanery MCP** do automatycznej oceny ryzyka agentów i serwerów MCP w CI/CD (O56).
- **Narzędzia specyfikacji** OpenSpec i GitHub Spec Kit (O34). **GitHub Agentic Workflows** (O50). **OpenCode** (O39,
  O40), **Trae, Antigravity** (O39).
- **Firma sama przebudowana wokół AI** („tools, policies, roles, delivery models”) i wynagrodzenie zależne od wpływu (O20).
- **Wewnętrzne porozumienie o pracy z AI:** oznaczanie artefaktów AI, ISO/IEC 42001, rada AI i „AI Champion” w każdym
  zespole (O51).
- **Rola administracyjna dla studenta:** wnioski o dostęp i licencje do M365 Copilot i platformy Claude, FAQ i
  dokumentacja governance, społeczności użytkowników (O38).
- **„Drugi mózg” dla Claude Code:** baza wiedzy, pamięć projektowa, system zarządzania kontekstem (O28).
- **Miara „kosztu jednostkowego zmiany”** jako KPI wdrożenia AI w SDLC (O3).
- **Decyzja o miejscu przetwarzania danych** (zewnętrzne API albo model hostowany samodzielnie) jako wymaganie wobec
  programisty (O11).
- **Zarządzanie flotą agentów** („managing agent fleets”) i analiza aktywności agentów w aplikacjach klientów pod kątem
  nadużyć (O46).
- **A2A i checkpoint/resume („time-travel”)** w runtime agentów (O42).
- **Przekazanie rozwiązań zespołom:** wspieranie zespołów w stopniowym przejmowaniu wdrożonych rozwiązań agentowych (O3, O4).
- **Junior z obowiązkowym codziennym AI coding** (O35) oraz staż nazwany „AI Native”, w którym agent jest tylko atutem
  (O18).

## 10. Czego w ofertach nie ma

Stwierdzenia dotyczą wyłącznie 56 przeczytanych ofert.

- **Dyżurów.** Żadna oferta nie używa słów „on-call” ani „dyżur” (rozdział 6).
- **Uprawnień i izolacji agentów kodujących.** Żadna oferta nie wspomina sandboxa, izolacji środowiska wykonawczego
  agenta ani ograniczania poleceń, które agent kodujący może uruchomić. Kontrola dostępu i akcji agentów pojawia się
  tylko przy agentach produktowych i integracjach (O45, O56) oraz ogólnie w zasadach użycia AI (O3).
- **Prompt injection w kontekście agentów kodujących.** Temat występuje tylko przy systemach LLM jako produkcie (O46,
  O47, O54).
- **Kosztów agentów kodujących.** W żadnej ofercie „tylko A” nie ma kosztów ani budżetu tokenów; koszt pojawia się
  w klasie C (koszt modeli w organizacji) i B (koszt funkcji produktu).
- **Liczbowych celów produktywności.** Oferty mówią o wzroście tempa i jego pomiarze, ale żadna nie podaje celu
  liczbowego ani proporcji pracy agenta do pracy człowieka.
- **Pełnej autonomii agentów.** Żadna oferta nie opisuje agentów działających bez przeglądu człowieka; nie pada też
  liczba agentów na inżyniera.
- **TDD.** Test-driven development występuje w jednej ofercie (O11).
- **Metodyk nazwanych wprost.** Scrum tylko jako nazwa roli (O5), Kanban w żadnej.
- **Ról juniorskich.** 3 z 56 w próbie (O18, O35, O38); w całej kategorii `ai` 4 junior i 1 intern na 633.
- **Widełek.** 25 z 56 ofert ich nie podaje, w tym większość ofert agencji z klastra K1.

## 11. Źródła

Wszystkie adresy odczytane 2026-11-18.

Listingi, wyszukiwanie i API:
- https://justjoin.it/job-offers/all-locations/ai (HTML i WebFetch)
- https://justjoin.it/job-offers/all-locations?keyword=claude%20code (HTML, sprawdzenie parametru)
- https://justjoin.it/api/candidate-api/offers?categories=ai&from=0..600&itemsCount=100 (7 stron)
- https://justjoin.it/api/candidate-api/offers?keywords=<fraza>&from=0..200&itemsCount=100 dla 28 fraz z rozdziału 1
- https://justjoin.it/api/candidate-api/offers/<slug> dla 1903 ofert (przesiew) oraz 13 ofert z wyników WebSearch
  (10 nieaktywnych odrzuconych)
- WebSearch: `justjoin.it "Claude Code" job offer AI engineer`

Przeczytane oferty (adres WWW; treść pobrana z `https://justjoin.it/api/candidate-api/offers/<ten sam slug>`):

- O1 https://justjoin.it/job-offer/b3-consulting-poland-senior-agentic-engineer-warszawa-ai
- O2 https://justjoin.it/job-offer/jit-team-agentic-sdlc-engineer-warszawa-ai
- O3 https://justjoin.it/job-offer/emagine-polska-agentic-ai-sdlc-engineer-warszawa-ai
- O4 https://justjoin.it/job-offer/it-factory-ai-engineer-warszawa-ai
- O5 https://justjoin.it/job-offer/antal-sp-z-o-o--ai-adoption-enablement-lead-warszawa-ai
- O6 https://justjoin.it/job-offer/sorigo-ai-enablement-engineer-engineerka-warszawa-ai
- O7 https://justjoin.it/job-offer/the-software-house-agentic-product-manager-warszawa-pm
- O8 https://justjoin.it/job-offer/b3-consulting-poland-senior-software-engineer-warszawa-java-053692fd
- O9 https://justjoin.it/job-offer/comarch-senior-backend-engineer-ai-native-erp--krakow-erp-2d9bf31f
- O10 https://justjoin.it/job-offer/epam-systems-senior-full-stack-engineer-python-react-sql--warsaw-python-0887a13c
- O11 https://justjoin.it/job-offer/exponent-ch-senior-software-developer-warszawa-python
- O12 https://justjoin.it/job-offer/hard-rock-digital-principal-backend-engineer---casino-gdansk-java-e4811b6a
- O13 https://justjoin.it/job-offer/jeppesen-foreflight-python-engineer---aviation-software-modernization-gdansk-python
- O14 https://justjoin.it/job-offer/n-ix-lead-architect-genai-adoption-freelance-part-time--krakow-ai
- O15 https://justjoin.it/job-offer/the-codest-senior-fullstack-engineer-python--warszawa-python
- O16 https://justjoin.it/job-offer/transition-technologies-psc-ai-dev-coach-warszawa-ai-54d0598f
- O17 https://justjoin.it/job-offer/expoplatform-ai-engineering-manager-druzbickiego-11-poland-ai
- O18 https://justjoin.it/job-offer/grid-dynamics-poland-ai-native-full-stack-engineer-intern-krakow-krakow-ai-0e0a47ad
- O19 https://justjoin.it/job-offer/startup-house-senior-ai-native-developer-warszawa-ai
- O20 https://justjoin.it/job-offer/vecten-ai-native-engineer-full-stack-agentic-ai-engineer--warszawa-ai-499b9519
- O21 https://justjoin.it/job-offer/superdevs-senior-golang-developer-remote-poland-remote--go-5d29385c
- O22 https://justjoin.it/job-offer/vidoc-security-lab-senior-full-stack-engineer-gdansk-javascript
- O23 https://justjoin.it/job-offer/upside-software-engineer-krakow-ai
- O24 https://justjoin.it/job-offer/kyotu-technology-senior-python-engineer-tech-lead-wroclaw-python
- O25 https://justjoin.it/job-offer/link-group--forward-deployed-engineer-warszawa-ai
- O26 https://justjoin.it/job-offer/sii-forward-deployed-engineer---ai-solutions-f-m-x--warszawa-ai
- O27 https://justjoin.it/job-offer/webhosting-today-inc-head-of-engineering---adminbolt-remote-eu--krakow-architecture
- O28 https://justjoin.it/job-offer/fastline-advertising-vibe-coder-warszawa-ai
- O29 https://justjoin.it/job-offer/house-of-angular-fullstack-developer-ai-first--warszawa-ai
- O30 https://justjoin.it/job-offer/profitroom-product-engineer-product-builder---backend-or-full-stack-poznan-php
- O31 https://justjoin.it/job-offer/comarch-ai-assisted-qa-software-engineering-expert-krakow-ai
- O32 https://justjoin.it/job-offer/connectis-solution-architect-agentic-ai--warszawa-architecture
- O33 https://justjoin.it/job-offer/act-digital-architect--ai-engineering-lead-warszawa-architecture
- O34 https://justjoin.it/job-offer/antal-sp-z-o-o--senior-ai-engineer-ai-assisted-software-development--warszawa-ai
- O35 https://justjoin.it/job-offer/synerise-junior-backend-engineer-krakow-scala
- O36 https://justjoin.it/job-offer/uniqa-lead-agentic-ai-engineer---pdlc-warszawa-ai
- O37 https://justjoin.it/job-offer/procter-gamble-senior-ai-engineer-for-qa-and-sdlc-warszawa-testing
- O38 https://justjoin.it/job-offer/kmd-poland-student-worker---ai-tools-administration-governance-support-warszawa-admin
- O39 https://justjoin.it/job-offer/epam-systems-senior-platform-engineer-warsaw-devops
- O40 https://justjoin.it/job-offer/p-p-solutions-senior-fullstack-developer-java-react--warszawa-java-162a8230
- O41 https://justjoin.it/job-offer/spyrosoft-senior-agentic-ai-engineer-wroclaw-ai-f8d32cfb
- O42 https://justjoin.it/job-offer/relativity-advanced-ai-engineer-krakow-ai
- O43 https://justjoin.it/job-offer/procter-gamble-senior-ai-agent-engineer-warszawa-admin
- O44 https://justjoin.it/job-offer/epam-systems-senior-python-engineer---mcp-protocol-tool-integration-poznan-python
- O45 https://justjoin.it/job-offer/capgemini-agentic-integration-mcp-engineer-wroclaw-java
- O46 https://justjoin.it/job-offer/framna-expert-llm-engineer-ai-security--gdansk-ai-011f30e4
- O47 https://justjoin.it/job-offer/hiberus-poland-ai-tester-ai-evaluation-engineer-wroclaw-testing
- O48 https://justjoin.it/job-offer/pko-bp-finat-ai-engineer---agentic-ai-warszawa-ai
- O49 https://justjoin.it/job-offer/tesco-technology-generative-ai-engineer-product-intelligence--krakow-ai
- O50 https://justjoin.it/job-offer/cloudfide-spolka-z-ograniczona-odpowiedzialnoscia-ai-engineer---agentic-workflows-node-js-react--warszawa-javascript
- O51 https://justjoin.it/job-offer/allclouds-pl-ai-llm-engineer---infrastruktura-inferencji-i-systemy-rag-warszawa-ai
- O52 https://justjoin.it/job-offer/epam-systems-lead-forward-deployed-engineer-krakow-ai
- O53 https://justjoin.it/job-offer/hiberus-poland-llmops-warszawa-devops
- O54 https://justjoin.it/job-offer/g2a-ai-automation-engineer-warszawa-ai
- O55 https://justjoin.it/job-offer/britenet-python-ai-engineer-agentic-systems-llm-senior-warszawa-ai
- O56 https://justjoin.it/job-offer/here-technologies-lead-responsible-ai-engineer-krakow-ai
