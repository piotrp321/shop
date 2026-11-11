.PHONY: check selftest smoke load rerun create-admin seal-secrets bootstrap kubeconfig status reinitialize cloudflare-ips

# Static checks of the chart (lint, render, schema, kubeconform, shellcheck, actionlint of the workflows, tests of the
# render; git does not ignore the certificate of Sealed Secrets). Needs Docker, and git for that last step.
check:
	sh scripts/check.sh

# The checks fail on injected errors (a broken template, a secret key missing from the chart, an invalid manifest, an
# auth ingress exposing the master realm, a job in a wave before its dependency, an image without a digest, a chart
# allowed on Kubernetes older than 1.36, Brevo allowed without its secret, keycloak-config printing a secret,
# create-admin going on without tak, deploy.yml needing a job that does not exist, an unquoted variable in its scripts, no
# smoke test before deploy, images pushed for every event, runs for main cancelled, steps of deploy.yml without pipefail,
# the two deploy branches pushed without --atomic, no check of the render before the push, a short digest accepted by
# the images values generator, a workflow that deletes package versions, the certificate of Sealed Secrets ignored by git,
# or deploy.yml missing).
selftest:
	sh scripts/selftest.sh

# The smoke test on an empty k3d cluster with the production images built here (a few minutes; the first run pulls
# the images of the services). SMOKE_BREAK=migration|image makes it fail on purpose. Needs only Docker.
smoke:
	sh scripts/smoke.sh

# The load measurement on a throwaway k3d cluster with the production images built here and the profile `load`: the demo
# data, the stocks raised, a warm-up, the ramp to saturation and the steady run with a checkout (the k6 scripts of
# ../../load; tens of minutes). make -C infra/k8s load LOAD_OUT=<an existing directory outside the repository> (required).
# Exit code 0: measured; 2: the environment is not fit (no LOAD_OUT, too few CPUs or too little memory in the Docker VM,
# pods not ready), with a message. The cluster is deleted on every exit. Needs only Docker; see scripts/load.sh for the
# settings (a short run: RAMP_STEPS=2 RAMP_STEP_SECONDS=10 STEADY_SECONDS=20 WARMUP_SECONDS=10 LOAD_COOLDOWN=5).
load:
	sh scripts/load.sh

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

# Encrypts the secrets of the environment with the certificate of the controller (environments/prod/sealed-secrets.pem) into
# environments/prod/secrets: make -C infra/k8s seal-secrets [WHAT="shop brevo tls"]. shop: the missing keys of
# infra/scripts/init-env.sh with random values, existing ones are never changed. brevo and tls: asked for in the terminal
# without echo, once. An error writes nothing. Needs only Docker.
seal-secrets:
	sh scripts/seal-secrets.sh $(WHAT)

# Prepares a clean Ubuntu 26.04 server over SSH (k3s, firewall, security updates, Argo CD, Sealed Secrets):
# make -C infra/k8s bootstrap HOST=<user>@<address> [SEALED_KEY_FILE=<backup of the key of the controller>].
# Idempotent; refuses without root and on another system before any change. Writes the certificate of the controller to
# environments/prod/sealed-secrets.pem when the repository has none. SSH_OPTS adds options for ssh.
bootstrap:
	sh scripts/bootstrap.sh "$(HOST)"

# Copies the kubeconfig of the cluster from the server into KUBE_DIR (outside the repository) for rerun and create-admin:
# make -C infra/k8s kubeconfig HOST=<user>@<address> KUBE_DIR=<directory>.
kubeconfig:
	sh scripts/kubeconfig.sh "$(HOST)"

# The applications of Argo CD, the pods and the jobs of the shop, read over SSH: make -C infra/k8s status HOST=<user>@<address>.
status:
	sh scripts/status.sh "$(HOST)"

# Loads the demo data again and WIPES the current data: says what it will do and asks for the word tak.
# make -C infra/k8s reinitialize HOST=<user>@<address>
reinitialize:
	sh scripts/reinitialize.sh "$(HOST)"

# Brings the Cloudflare ranges of ipAllowList in environments/prod/values.yaml up to date with the ones Cloudflare publishes
# (rewrites the file; commit the change): make -C infra/k8s cloudflare-ips. With CHECK=1 it only reports (exit 1 when the
# list is out of date).
cloudflare-ips:
	sh scripts/update-cloudflare-ips.sh $(if $(CHECK),--check,)
