.PHONY: load-smoke load-cookies load-logic

CI_AFTER_E2E += load-smoke
CI_AFTER_E2E += load-cookies
CI_AFTER_E2E += load-logic
TEST_TARGETS += load-cookies
TEST_TARGETS += load-logic

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

# The state of the writes scenario (load/lib/write-state.js: the prices of a change, the expected states after confirmed, refused
# and unanswered writes, the comparison with the index, the choice of the sample) in the k6 container, without a network and
# without the stack (load/test/write-state.sh).
load-logic:
	sh load/test/write-state.sh
