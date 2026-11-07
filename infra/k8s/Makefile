.PHONY: check selftest smoke rerun

# Static checks of the chart (lint, render, schema, kubeconform, shellcheck, tests of the render). Needs only Docker.
check:
	sh scripts/check.sh

# The checks fail on injected errors (a broken template, a secret key missing from the chart, an invalid manifest, an
# auth ingress exposing the master realm, a job in a wave before its dependency, an image without a digest, a chart
# allowed on Kubernetes older than 1.36).
selftest:
	sh scripts/selftest.sh

# The smoke test on an empty k3d cluster with the production images built here (a few minutes; the first run pulls
# the images of the services). SMOKE_BREAK=migration|image makes it fail on purpose. Needs only Docker.
smoke:
	sh scripts/smoke.sh

# Runs a job of a version again under its name (a finished or failed one): make -C infra/k8s rerun JOB=<name>.
# KUBE_DIR is the directory with the kubeconfig of the cluster (required), KUBE_NETWORK the Docker network of kubectl.
rerun:
	@test -n "$(JOB)" || { echo "rerun: set JOB=<job name>, e.g. make -C infra/k8s rerun JOB=initialize-3fa9c1d2e4" >&2; exit 2; }
	sh scripts/rerun.sh "$(JOB)"
