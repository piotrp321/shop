.PHONY: load-smoke load-cookies load-panel load-panel-check

CI_AFTER_E2E += load-smoke
CI_AFTER_E2E += load-cookies
TEST_TARGETS += load-cookies
TEST_TARGETS += load-panel-check

# The k6 scripts of load/ against the running stack (`make up` and `make seed` first): one user, one iteration of every
# scenario, a guest checkout of one unit included, in a container on the network of the stack. Keeps the scripts working;
# measures nothing and has no limits of speed, but any scenario that fails is a non-zero exit code. The measurement is
# `make -C infra/k8s load`.
load-smoke:
	sh load/smoke.sh

# The cookies of the k6 client (load/lib/http.js) against a server that sets a Secure cookie and is called over plain HTTP, as the
# cluster calls the store behind TLS: in Docker on a network of its own, no stack needed (load/test/cookies.sh).
load-cookies:
	sh load/test/cookies.sh

# W7 (the lists, searches and filters of the panel at the rate of its users) and P4 (what the panel costs the shop) of the
# performance program, against the running stack with the data of a profile (`make seed-scale`): `make load-panel` is W7 (the
# mix at PANEL_RATE/s, 3 runs, the median and the spread), `make load-panel PANEL_MODE=p4 SHOP_URLS_FILE=<baseline.py urls file>`
# is P4. The settings and the results: load/README.md, "Panel (W7 i P4)"; the options are environment variables of load/panel.sh.
PANEL_MODE ?= w7
load-panel:
	sh load/panel.sh $(PANEL_MODE)

# The tools of load-panel on the paths of failure (a wrong mode, no stack, no Keycloak, no token, an API that does not answer, a missing
# or broken mix, in k6 too) and on what needs no stack (the one-time code against RFC 6238, the report on hand-written results).
load-panel-check:
	sh load/test/panel.sh
