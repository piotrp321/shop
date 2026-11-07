.PHONY: check selftest smoke rerun create-admin

# Static checks of the chart (lint, render, schema, kubeconform, shellcheck, actionlint of the workflows, tests of the
# render). Needs only Docker.
check:
	sh scripts/check.sh

# The checks fail on injected errors (a broken template, a secret key missing from the chart, an invalid manifest, an
# auth ingress exposing the master realm, a job in a wave before its dependency, an image without a digest, a chart
# allowed on Kubernetes older than 1.36, Brevo allowed without its secret, keycloak-config printing a secret,
# create-admin going on without tak, deploy.yml needing a job that does not exist, an unquoted variable in its scripts, no
# smoke test before deploy, images pushed for every event, runs for main cancelled, steps of deploy.yml without pipefail, or missing).
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

# The first administrator of the panel: make -C infra/k8s create-admin LOGIN=<login> EMAIL=<email>. Says what it will do
# and asks for the word tak; then creates the user in the realm backoffice with the role admin and no password and
# sends the e-mail to set the password and TOTP (valid 12 h). An existing login or e-mail fails without changes.
# KUBE_DIR (required), KUBE_NETWORK and KUBE_NAMESPACE as for rerun.
create-admin:
	sh scripts/create-admin.sh "$(LOGIN)" "$(EMAIL)"
