#!/bin/sh
# Sourced by the scripts of infra/k8s: runs every tool in a container with a pinned image, so nothing is installed on
# the host (only Docker and sh are needed, on macOS and on Linux alike). The repository is mounted read-only at /repo.
#
#   helm, kubeconform, shellcheck, actionlint, yq, py, kubeseal: tools working on files of the repository (cwd = /repo/infra/k8s)
#   k3d, kubectl: tools talking to Docker (k3d) and to a k3d cluster over its Docker network (kubectl)
#   browser_dom: the DOM of a page after its scripts ran, in headless Chromium on the Docker network of a cluster
#   browser_console: the console messages of the same page load (the CSP violations among them)

TOOLS_REPO="${REPO_ROOT:-$(cd "$(dirname "$0")/../../.." && pwd)}"

HELM_IMAGE="${HELM_IMAGE:-alpine/helm:3.22.1}"
KUBECONFORM_IMAGE="${KUBECONFORM_IMAGE:-ghcr.io/yannh/kubeconform:v0.7.0}"
SHELLCHECK_IMAGE="${SHELLCHECK_IMAGE:-koalaman/shellcheck:v0.11.0}"
# Also runs shellcheck on the scripts of the steps (run:) of the workflows; the image has it.
ACTIONLINT_IMAGE="${ACTIONLINT_IMAGE:-rhysd/actionlint:1.7.12}"
YQ_IMAGE="${YQ_IMAGE:-mikefarah/yq:4.47.1}"
PYTHON_IMAGE="${PYTHON_IMAGE:-python:3.13-alpine}"
K3D_IMAGE="${K3D_IMAGE:-ghcr.io/k3d-io/k3d:5.9.0}"
# The cluster runs k3s of this version; the manifests are validated against the same Kubernetes version.
K3S_VERSION="${K3S_VERSION:-v1.36.6-k3s1}"
K3S_IMAGE="${K3S_IMAGE:-rancher/k3s:$K3S_VERSION}"
KUBERNETES_VERSION="${KUBERNETES_VERSION:-${K3S_VERSION%%-*}}"
KUBERNETES_VERSION="${KUBERNETES_VERSION#v}"
KUBECTL_IMAGE="${KUBECTL_IMAGE:-registry.k8s.io/kubectl:v$KUBERNETES_VERSION}"
# kubeseal, the client of Sealed Secrets, of the version of the controller chart pinned in argocd/apps/sealed-secrets.yaml.
KUBESEAL_IMAGE="${KUBESEAL_IMAGE:-ghcr.io/bitnami/sealed-secrets-kubeseal:0.41.2@sha256:6697225a70537d94061b56e6a138ba9ca26307a702f827dac0a77b0a007bc84f}"
# Ubuntu 26.04, the system bootstrap.sh supports: the image the test of the bootstrap runs it in (infra/k8s/tests/bootstrap).
UBUNTU_IMAGE="${UBUNTU_IMAGE:-ubuntu:26.04@sha256:6a06111c3b0a8600feadb270aec771dc239bf407b25625f93f44ab00e11f4d3c}"
CURL_IMAGE="${CURL_IMAGE:-curlimages/curl:8.18.0}"
# The browser of the smoke test: the Chromium of the image the E2E tests are built on (e2e/Dockerfile, the same tag).
PLAYWRIGHT_IMAGE="${PLAYWRIGHT_IMAGE:-mcr.microsoft.com/playwright:v1.64.1-noble}"

# The arguments of helm that make the profile ci pull the images of the registry named in an images values file (the
# revision and the digests, scripts/images-values.sh) instead of the local ones: the profile ci names a local registry and
# never pulls. smoke.sh installs r1 with them (SMOKE_VALUES) and render.sh renders the same case, so the test of the render
# sees what the smoke test deploys.
smoke_pull_args() {
  printf '%s %s' "--set image.registry=$(sed -n 's/^  registry: //p' "$TOOLS_REPO/infra/k8s/charts/shop/values.yaml" | head -n 1)" \
    "--set image.pullPolicy=IfNotPresent"
}

# Downloaded schemas of kubeconform; survives between runs.
TOOLS_CACHE="${TOOLS_CACHE:-${TMPDIR:-/tmp}/shop-k8s-cache}"

tools_run() {
  mkdir -p "$TOOLS_CACHE"
  docker run --rm -i -u "$(id -u):$(id -g)" -e HOME=/tmp -v "$TOOLS_REPO:/repo:ro" -v "$TOOLS_CACHE:/cache" \
    -w /repo/infra/k8s "$@"
}

# Runs a command and drops the notes of helm about the symlinks it follows (the files of the repository in
# charts/shop/files) from its stderr: they are not errors.
without_symlink_notes() {
  notes_file="$(mktemp)"
  notes_status=0
  "$@" 2> "$notes_file" || notes_status=$?
  grep -v 'found symbolic link' "$notes_file" >&2 || true
  rm -f "$notes_file"
  return "$notes_status"
}

helm() {
  without_symlink_notes tools_run -e HELM_CACHE_HOME=/tmp -e HELM_CONFIG_HOME=/tmp -e HELM_DATA_HOME=/tmp "$HELM_IMAGE" "$@"
}
# helm against a cluster: on its Docker network, with its kubeconfig (KUBE_NETWORK and KUBE_DIR, see kubectl below).
helm_cluster() {
  without_symlink_notes tools_run --network "$KUBE_NETWORK" -v "$KUBE_DIR:/kube:ro" -e KUBECONFIG=/kube/config \
    -e HELM_CACHE_HOME=/tmp -e HELM_CONFIG_HOME=/tmp -e HELM_DATA_HOME=/tmp "$HELM_IMAGE" "$@"
}
kubeconform() { tools_run "$KUBECONFORM_IMAGE" -cache /cache "$@"; }
shellcheck() { tools_run "$SHELLCHECK_IMAGE" "$@"; }
# actionlint works in the root of the repository (the workflows are in .github/workflows).
actionlint() { tools_run -w /repo "$ACTIONLINT_IMAGE" "$@"; }
yq() { tools_run "$YQ_IMAGE" "$@"; }
# kubeseal reads the secret on its standard input; the certificate is a file of the repository (mounted at /repo).
kubeseal() { tools_run "$KUBESEAL_IMAGE" "$@"; }
# Python without third-party packages; -I ignores the environment and the current directory.
py() { tools_run "$PYTHON_IMAGE" python -I "$@"; }

# k3d talks to the Docker daemon through its socket. K3D_MOUNT (<host dir>:<dir in the container>[:ro]), when set, mounts
# a directory with a file the command reads (the registries file of the smoke test).
k3d() { docker run --rm -v /var/run/docker.sock:/var/run/docker.sock ${K3D_MOUNT:+-v "$K3D_MOUNT"} "$K3D_IMAGE" "$@"; }
# kubectl reaches the cluster by the name of its server container, on the Docker network of the cluster.
# KUBE_NETWORK and KUBE_DIR (the directory with the file `config`) are set by smoke.sh; rerun.sh takes them from the
# environment (KUBE_NETWORK defaults to host, KUBE_DIR to ~/.kube).
kubectl() {
  docker run --rm -i -u "$(id -u):$(id -g)" -e HOME=/tmp --network "$KUBE_NETWORK" -v "$KUBE_DIR:/kube:ro" \
    -e KUBECONFIG=/kube/config "$KUBECTL_IMAGE" "$@"
}
# browser_dom <proxy> <url>: prints the DOM of the page once its scripts and requests are done (--virtual-time-budget).
# Every request, also to *.localhost, goes through the HTTP proxy <proxy> (Traefik of the cluster), which routes by host.
browser_dom() {
  # shellcheck disable=SC2016 # expanded by the shell of the container
  docker run --rm --network "$KUBE_NETWORK" "$PLAYWRIGHT_IMAGE" sh -c '
    chrome="$(ls /ms-playwright/chromium_headless_shell-*/chrome-headless-shell-*/chrome-headless-shell | head -n 1)"
    exec "$chrome" --no-sandbox --disable-gpu --proxy-server="$1" --proxy-bypass-list="<-loopback>" \
      --virtual-time-budget=30000 --dump-dom "$2" 2>/dev/null' browser "$@"
}
# browser_console <proxy> <url>: loads the page like browser_dom and prints its console messages, one per line, with the
# prefix of Chromium (`[pid:tid:time:INFO:CONSOLE:line] "message", source: url`): errors, warnings and the violations of
# the Content Security Policy ("Refused to ..." and "Executing inline script violates ..."). No messages is success with
# no output; a failure of docker or of Chromium is its non-zero status (the end of the Chromium log goes to stderr).
browser_console() {
  # shellcheck disable=SC2016 # expanded by the shell of the container
  docker run --rm --network "$KUBE_NETWORK" "$PLAYWRIGHT_IMAGE" sh -c '
    chrome="$(ls /ms-playwright/chromium_headless_shell-*/chrome-headless-shell-*/chrome-headless-shell | head -n 1)"
    log="$(mktemp)"
    "$chrome" --no-sandbox --disable-gpu --proxy-server="$1" --proxy-bypass-list="<-loopback>" --enable-logging=stderr --v=0 \
      --virtual-time-budget=30000 --dump-dom "$2" 2>"$log" >/dev/null
    status=$?
    if [ "$status" -ne 0 ]; then tail -n 5 "$log" >&2; exit "$status"; fi
    grep ":CONSOLE" "$log" || true' browser "$@"
}
