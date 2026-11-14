.PHONY: check selftest smoke monitoring-smoke load rerun create-admin restore backups seal-secrets bootstrap kubeconfig status reinitialize cloudflare-ips

# Static checks of the chart (lint, render, schema, kubeconform, shellcheck, actionlint of the workflows, tests of the
# render; git does not ignore the certificate of Sealed Secrets). The render also covers the application monitoring: the chart
# k8s-monitoring of the pinned version (downloaded once, its sha256 checked) with the values of argocd/apps/monitoring.yaml
# (kubeconform with the schema of Alloy, the access to Secrets, the metrics of the alerts, the budget of the node) and the log
# masking in a real Alloy (the stages of the manifest on tests/log-corpus). The alert rules of the prod render go through promtool
# (check rules and the scenarios of tests/alerts). Needs Docker and the network once for the charts,
# and git for the last step.
check:
	sh scripts/check.sh

# The checks fail on injected errors (a broken template, a secret key missing from the chart, an invalid manifest, an
# auth ingress exposing the master realm, a job in a wave before its dependency, an image without a digest, a chart
# allowed on Kubernetes older than 1.36, Brevo allowed without its secret, keycloak-config printing a secret,
# create-admin going on without tak, deploy.yml needing a job that does not exist, an unquoted variable in its scripts, no
# smoke test before deploy, images pushed for every event, deploy.yml triggered by a push, a deployment for a dispatch on any
# branch, runs for main cancelled, steps of deploy.yml without pipefail,
# the two deploy branches pushed without --atomic, no check of the render before the push, a short digest accepted by
# the images values generator, a workflow that deletes package versions, the certificate of Sealed Secrets ignored by git,
# deploy.yml missing, a backup repository without its password, the backup CronJob without concurrencyPolicy Forbid, the
# backup script that goes on after an error or takes any access error for a missing repository, the verification of the
# backups that goes on after an error, takes a snapshot of any age, runs a temporary Postgres that is not a sidecar or
# reaches the production Postgres, runs restic without CA certificates, seal-secrets that asks for the backup password again, restore
# going on without tak, the monitoring (Alloy) with no masking of e-mail addresses in the logs, with a ClusterRole of Alloy or a Role that read all
# Secrets, with the RBAC of the chart of the operator, a Role of the operator that changes RBAC objects, the operator watching the whole
# cluster, the Alloy resource making its own RBAC, with the cluster events not masked like the logs of the pods, collecting the logs of Mailpit, a chart of the monitoring
# without a digest in monitoring-render.sh or with another one, stages that Alloy rejects, without the admission policy on the access to the
# host or the binding of the policy on the tokens, a policy that only warns or fails open, the policies in the wave of the workloads,
# node-exporter known by its labels, the operator with DaemonSets or changing the Deployment of kube-state-metrics, Alloy with the proxy
# of the nodes, the token of Alloy given to a group of service accounts, BackupMissing without the absent for a metric that is gone, NodeDiskFull
# firing at exactly 85 percent, an alert rule that PromQL cannot parse).
selftest:
	sh scripts/selftest.sh

# The smoke test on an empty k3d cluster with the production images built here (a few minutes; the first run pulls
# the images of the services). The images of each revision are checked in the node after the import and imported again
# when missing (scripts/import-images.sh). After create-admin it makes a backup: a Garage of its own in the namespace smoke-backup holds the
# restic repository, a Job from the CronJob backup runs, and the snapshot nightly (both dumps, the objects, the manifest) is
# checked; the time of that phase is printed. Then the restore on an empty cluster: the namespace shop is deleted with its volumes,
# the chart installed again and make restore brings the snapshot back (checked: the administrator, the customer, the numbers of
# products and orders, an image, the search, the snapshot pre-restore), and refuses a snapshot newer than the code; the later
# phases run on the restored cluster. SMOKE_BREAK=migration|image makes it fail on purpose. Needs only Docker.
smoke:
	sh scripts/smoke.sh

# The monitoring (argocd/apps/monitoring.yaml) running on a throwaway k3d cluster, with its RBAC and admission policies as the
# manifest gives them: the operator and its Alloy ready; requests with --dry-run=server, as the operator and as the administrator,
# that the admission policies must refuse (pods and workloads with access to the host, also with the labels or a forged owner of
# node-exporter, or with a priority class of the system; Services of the types NodePort, LoadBalancer and ExternalName or with
# external IPs; a Secret with a token of a service account) and plain ones they must let through; every pod of monitoring made again
# under the policies; a masked log line of a pod in shop, a cluster event and the metrics of kube-state-metrics, node-exporter, the
# kubelet and cAdvisor at a receiver on the Docker network of the cluster (the addresses of the destinations are the one overlay), no
# forbidden in the logs, and kubectl auth can-i for the service accounts of the operator, Alloy, kube-state-metrics and node-exporter
# (no Secret of shop or kube-system, no bind, escalate, impersonate or change of RBAC objects, no exec, attach, port-forward,
# ephemeral container or token in shop and kube-system, no proxy of a node; the operator without DaemonSets, StatefulSets, pods and
# new Alloy resources, changing only the objects of its Alloy). A few minutes; not in check or CI.
# MONITORING_SMOKE_BREAK=operator-cluster-role installs the RBAC of the chart of the operator, MONITORING_SMOKE_BREAK=no-admission-policy
# the extraObjects without the admission policies: the test must fail. MONITORING_SMOKE_KEEP=1 keeps a failed cluster. Needs only Docker.
monitoring-smoke:
	sh scripts/monitoring-smoke.sh

# The load measurement on a throwaway k3d cluster with the production images built here and the profile `load`: the demo
# data, the stocks raised, a warm-up, the ramp to saturation and the steady run with a checkout (the k6 scripts of
# ../../load; tens of minutes). make -C infra/k8s load LOAD_OUT=<an existing directory outside the repository> (required).
# Exit code 0: measured; 2: the environment is not fit (no LOAD_OUT, too few CPUs or too little memory in the Docker VM,
# images missing in the node after three imports, pods not ready), with a message. The cluster is deleted on every exit. Needs only Docker; see scripts/load.sh for the
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

# Restores a snapshot of the backups (docs/features/deploy-ops/spec.md, O4): make -C infra/k8s restore SNAPSHOT=<id> [PRE_RESTORE=skip].
# SNAPSHOT is an identifier from make backups (never latest). Refuses without changes when a Job of the namespace is active, Argo CD
# is synchronizing shop, or the snapshot has a migration that the deployed code does not know; says what it will do and asks for
# the word tak; makes a backup pre-restore first (its failure stops it without changes; PRE_RESTORE=skip makes none), stops
# Argo CD (root, then shop), the CronJobs and api, worker, store and keycloak, restores the data, runs migrate, keycloak-config,
# the reindex and purge-caches again and brings everything back. A failure leaves the applications off and says at which step
# and which pre-restore snapshot (infra/k8s/README.md). KUBE_DIR (required), KUBE_NETWORK and KUBE_NAMESPACE as for rerun.
restore:
	SNAPSHOT="$(SNAPSHOT)" PRE_RESTORE="$(PRE_RESTORE)" sh scripts/restore.sh

# The snapshots of the repository of the backups (identifier, time, host, tags, revision), the oldest first; only reads:
# make -C infra/k8s backups. KUBE_DIR (required), KUBE_NETWORK and KUBE_NAMESPACE as for rerun.
backups:
	sh scripts/backups.sh

# Encrypts the secrets of the environment with the certificate of the controller (environments/prod/sealed-secrets.pem) into
# environments/prod/secrets: make -C infra/k8s seal-secrets [WHAT="shop brevo tls backup grafana"]. shop: the missing keys
# of infra/scripts/init-env.sh with random values, existing ones are never changed. brevo, tls, backup (the password of the
# restic repository and the S3 key of its bucket: RESTIC_PASSWORD, BACKUP_S3_ACCESS_KEY_ID, BACKUP_S3_SECRET_ACCESS_KEY) and
# grafana (the access policy token of Grafana Cloud for Alloy: GRAFANA_CLOUD_TOKEN, the Secret grafana-cloud): asked for in the
# terminal without echo, once (the restic password twice: different values are an error). An error writes nothing. Needs
# only Docker.
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

# The applications of Argo CD, the pods and the jobs of the shop, and the logs of the failed jobs, read over SSH: make -C infra/k8s status HOST=<user>@<address>.
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
