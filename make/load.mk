.PHONY: load-smoke load-cookies

CI_AFTER_E2E += load-smoke
CI_AFTER_E2E += load-cookies
TEST_TARGETS += load-cookies

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
