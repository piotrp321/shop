#!/bin/sh
# Sourced by the scripts of infra/k8s: runs every tool in a container with a pinned image, so nothing is installed on
# the host (only Docker and sh are needed, on macOS and on Linux alike). The repository is mounted read-only at /repo.
#
#   helm, kubeconform, shellcheck, yq, py: tools working on files of the repository (cwd = /repo/infra/k8s)
#   k3d, kubectl: tools talking to Docker (k3d) and to a k3d cluster over its Docker network (kubectl)

TOOLS_REPO="${REPO_ROOT:-$(cd "$(dirname "$0")/../../.." && pwd)}"

HELM_IMAGE="${HELM_IMAGE:-alpine/helm:3.22.1}"
KUBECONFORM_IMAGE="${KUBECONFORM_IMAGE:-ghcr.io/yannh/kubeconform:v0.7.0}"
SHELLCHECK_IMAGE="${SHELLCHECK_IMAGE:-koalaman/shellcheck:v0.11.0}"
YQ_IMAGE="${YQ_IMAGE:-mikefarah/yq:4.47.1}"
PYTHON_IMAGE="${PYTHON_IMAGE:-python:3.13-alpine}"
K3D_IMAGE="${K3D_IMAGE:-ghcr.io/k3d-io/k3d:5.9.0}"
# The cluster runs k3s of this version; the manifests are validated against the same Kubernetes version.
K3S_VERSION="${K3S_VERSION:-v1.36.6-k3s1}"
K3S_IMAGE="${K3S_IMAGE:-rancher/k3s:$K3S_VERSION}"
KUBERNETES_VERSION="${KUBERNETES_VERSION:-${K3S_VERSION%%-*}}"
KUBERNETES_VERSION="${KUBERNETES_VERSION#v}"
KUBECTL_IMAGE="${KUBECTL_IMAGE:-registry.k8s.io/kubectl:v$KUBERNETES_VERSION}"
CURL_IMAGE="${CURL_IMAGE:-curlimages/curl:8.18.0}"

# Downloaded schemas of kubeconform; survives between runs.
TOOLS_CACHE="${TOOLS_CACHE:-${TMPDIR:-/tmp}/shop-k8s-cache}"

tools_run() {
  mkdir -p "$TOOLS_CACHE"
  docker run --rm -i -u "$(id -u):$(id -g)" -e HOME=/tmp -v "$TOOLS_REPO:/repo:ro" -v "$TOOLS_CACHE:/cache" \
    -w /repo/infra/k8s "$@"
}

# helm notes every symlink it follows (the files of the repository in charts/shop/files) on stderr: not an error.
helm() {
  helm_err="$(mktemp)"
  helm_status=0
  tools_run -e HELM_CACHE_HOME=/tmp -e HELM_CONFIG_HOME=/tmp -e HELM_DATA_HOME=/tmp "$HELM_IMAGE" "$@" 2> "$helm_err" || helm_status=$?
  grep -v 'found symbolic link' "$helm_err" >&2 || true
  rm -f "$helm_err"
  return "$helm_status"
}
kubeconform() { tools_run "$KUBECONFORM_IMAGE" -cache /cache "$@"; }
shellcheck() { tools_run "$SHELLCHECK_IMAGE" "$@"; }
yq() { tools_run "$YQ_IMAGE" "$@"; }
# Python without third-party packages; -I ignores the environment and the current directory.
py() { tools_run "$PYTHON_IMAGE" python -I "$@"; }

# k3d talks to the Docker daemon through its socket.
k3d() { docker run --rm -v /var/run/docker.sock:/var/run/docker.sock "$K3D_IMAGE" "$@"; }
# kubectl reaches the cluster by the name of its server container, on the Docker network of the cluster.
# KUBE_NETWORK and KUBE_DIR (the directory with the kubeconfig) are set by smoke.sh.
kubectl() {
  docker run --rm -i --network "$KUBE_NETWORK" -v "$KUBE_DIR:/kube:ro" -e KUBECONFIG=/kube/config \
    "$KUBECTL_IMAGE" "$@"
}
