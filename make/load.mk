.PHONY: load-smoke

CI_AFTER_E2E += load-smoke

# The k6 scripts of load/ against the running stack (`make up` and `make seed` first): one user, one iteration of every
# scenario, a guest checkout of one unit included, in a container on the network of the stack. Keeps the scripts working;
# measures nothing and has no limits of speed, but any scenario that fails is a non-zero exit code. The measurement is
# `make -C infra/k8s load`.
load-smoke:
	sh load/smoke.sh
