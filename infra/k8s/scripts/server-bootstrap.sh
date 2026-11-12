#!/bin/sh
# Prepares a clean Ubuntu 26.04 server for the shop (runs ON the server, as root; `make -C infra/k8s bootstrap HOST=...`
# copies it there with argocd/ and runs it). Idempotent: a second run changes nothing that is already right.
#
#   server-bootstrap.sh <payload directory>
#
# Environment (set by bootstrap.sh):
#   K3S_VERSION  the k3s release to install, e.g. v1.36.6+k3s1 (at least v1.36: the chart needs image volumes)
#   TLS_SAN      the address the API of k3s is reached by (added to its certificate), optional
# The payload directory has argocd/ (the manifests of infra/k8s/argocd) and, optionally, sealed-secrets-key.yaml (the
# backup of the key of the Sealed Secrets controller: a Secret or the List that the runbook's command prints; then the
# certificate in the repository stays valid) and, when the repository has it, sealed-secrets.pem (that certificate: when the
# controller's differs, the script stops before it applies the root application).
#
# Before any change it checks: root, Ubuntu 26.04, the k3s version, the payload. Then: packages, automatic security
# updates, vm.max_map_count, the firewall (ufw: SSH and the API of k3s), k3s, Argo CD (the Helm controller of k3s installs
# the pinned chart), the key of Sealed Secrets (from the backup, when given), the Sealed Secrets controller (the same
# Application the root adopts later), the certificate of the controller written to /var/lib/shop-bootstrap/ for
# bootstrap.sh to copy to the repository, and the root application of Argo CD.
# Exit codes: 0 done; 1 a step failed; 2 refused before any change.
set -eu

fail() {
  echo "server-bootstrap: $1" >&2
  exit "${2:-1}"
}
log() { printf '\n== %s\n' "$1"; }

payload="${1:-}"
[ -n "$payload" ] && [ -d "$payload/argocd" ] || fail "usage: server-bootstrap.sh <payload directory with argocd/>" 2

# ---------------------------------------------------------------------------------------------------------------
# Checks before any change
# ---------------------------------------------------------------------------------------------------------------
[ "$(id -u)" -eq 0 ] || fail "must run as root (the user is $(id -un)); nothing was changed" 2

os_release=/etc/os-release
[ -r "$os_release" ] || fail "no $os_release: this is not Ubuntu 26.04; nothing was changed" 2
# Read as text, not sourced: the file is data of the system, not a script to run as root.
os_field() { sed -n "s/^$1=//p" "$os_release" | head -n 1 | tr -d '"'; }
os_id="$(os_field ID)"
os_version="$(os_field VERSION_ID)"
if [ "$os_id" != ubuntu ] || [ "$os_version" != 26.04 ]; then
  fail "supports only Ubuntu 26.04, this is '$os_id $os_version'; nothing was changed" 2
fi

k3s_version="${K3S_VERSION:-}"
case "$k3s_version" in
  v1.[0-9][0-9].[0-9]*+k3s[0-9]*) ;;
  *) fail "K3S_VERSION must look like v1.36.6+k3s1, got '$k3s_version'; nothing was changed" 2 ;;
esac
minor="${k3s_version#v1.}"
minor="${minor%%.*}"
[ "$minor" -ge 36 ] || fail "k3s $k3s_version is older than v1.36 (the chart needs image volumes); nothing was changed" 2

case "${TLS_SAN:-}" in
  *[!A-Za-z0-9.:-]*) fail "TLS_SAN '$TLS_SAN' is not a host name or an address; nothing was changed" 2 ;;
esac

for file in argocd/root.yaml argocd/apps/sealed-secrets.yaml argocd/install/argocd.yaml argocd/install/traefik.yaml; do
  [ -f "$payload/$file" ] || fail "the payload lacks $file; nothing was changed" 2
done
key_file=""
if [ -f "$payload/sealed-secrets-key.yaml" ]; then
  key_file="$payload/sealed-secrets-key.yaml"
  # A Secret, or a List of them (what `kubectl get secret -l ... -o yaml` of the runbook prints: kind: List with the Secrets
  # as items, so `kind: Secret` is indented).
  if ! { grep -q 'sealedsecrets.bitnami.com/sealed-secrets-key' "$key_file" && grep -Eq '^[[:space:]-]*kind: Secret$' "$key_file"; }; then
    fail "sealed-secrets-key.yaml is not a backup of the key of the controller (a Secret, or a List of Secrets, with the label sealedsecrets.bitnami.com/sealed-secrets-key); nothing was changed" 2
  fi
fi

state_dir=/var/lib/shop-bootstrap
kubectl() { k3s kubectl "$@"; }

# wait_until <seconds> <what> <command...>: runs the command every 5 s until it succeeds; fails after the time.
wait_until() {
  limit="$1" what="$2"
  shift 2
  waited=0
  until "$@" > /dev/null 2>&1; do
    [ "$waited" -lt "$limit" ] || fail "timed out after $limit s waiting for $what"
    sleep 5
    waited=$((waited + 5))
  done
}

# write_if_different <path> <mode>: the content on the standard input; leaves a file that is already right alone. Sets
# `written` to 1 when it wrote the file, 0 when it was already right.
write_if_different() {
  new="$(mktemp)"
  cat > "$new"
  if [ -f "$1" ] && cmp -s "$new" "$1"; then
    rm -f "$new"
    written=0
    echo "unchanged: $1"
    return 0
  fi
  written=1
  mkdir -p "$(dirname "$1")"
  install -m "$2" "$new" "$1"
  rm -f "$new"
  echo "written: $1"
}

# ---------------------------------------------------------------------------------------------------------------
log "packages: ufw, unattended-upgrades"
# ---------------------------------------------------------------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq ufw unattended-upgrades curl ca-certificates

# ---------------------------------------------------------------------------------------------------------------
log "automatic security updates"
# ---------------------------------------------------------------------------------------------------------------
write_if_different /etc/apt/apt.conf.d/20auto-upgrades 644 << 'EOF2'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF2

# ---------------------------------------------------------------------------------------------------------------
log "vm.max_map_count (Elasticsearch)"
# ---------------------------------------------------------------------------------------------------------------
write_if_different /etc/sysctl.d/90-shop.conf 644 << 'EOF2'
vm.max_map_count=262144
EOF2
if [ "$(sysctl -n vm.max_map_count)" != 262144 ]; then
  sysctl -w vm.max_map_count=262144
fi

# ---------------------------------------------------------------------------------------------------------------
log "firewall: SSH and the API of k3s (ufw)"
# ---------------------------------------------------------------------------------------------------------------
# The port of the SSH session this runs in (22 when it is not run over SSH). Ports 80 and 443 are not here: the traffic to
# Traefik is DNAT-ed past the INPUT chain, Cloudflare and the ipAllowList of the chart guard it (D6).
ssh_port="$(printf '%s' "${SSH_CONNECTION:-}" | awk '{print $4}')"
case "$ssh_port" in "" | *[!0-9]*) ssh_port=22 ;; esac
ufw default deny incoming
ufw default allow outgoing
ufw allow "$ssh_port/tcp"
ufw allow 6443/tcp
# Pods and services of k3s reach the host (recommended by the k3s documentation for ufw).
ufw allow from 10.42.0.0/16 to any
ufw allow from 10.43.0.0/16 to any
ufw --force enable

# ---------------------------------------------------------------------------------------------------------------
log "k3s $k3s_version"
# ---------------------------------------------------------------------------------------------------------------
write_if_different /etc/rancher/k3s/config.yaml 600 << EOF2
write-kubeconfig-mode: "0600"
${TLS_SAN:+tls-san:
  - $TLS_SAN}
EOF2
k3s_config_written="$written"
wanted="$(printf '%s' "$k3s_version" | sed 's/+/-/')"
installed=""
if command -v k3s > /dev/null 2>&1; then
  installed="$(k3s --version | sed -n 's/^k3s version \(v[^ ]*\).*/\1/p' | head -n 1 | sed 's/+/-/')"
fi
if [ "$installed" = "$wanted" ]; then
  echo "k3s $k3s_version is installed"
  if [ "$k3s_config_written" = 1 ]; then
    # k3s reads its config only at start. (A fresh install or an upgrade by the installer starts k3s with the new config.)
    echo "the config of k3s changed: restarting k3s"
    systemctl restart k3s
  fi
else
  if [ -n "$installed" ] && [ "$(printf '%s\n%s\n' "$installed" "$wanted" | sort -V | tail -n 1)" = "$installed" ]; then
    fail "k3s $installed is installed, newer than $k3s_version: refusing to downgrade"
  fi
  # The installer of the same release as the binary (the tag of the repository), not whatever get.k3s.io serves today.
  installer="$(mktemp)"
  curl -fsSL "https://raw.githubusercontent.com/k3s-io/k3s/$(printf '%s' "$k3s_version" | sed 's/+/%2B/')/install.sh" -o "$installer" ||
    fail "could not download the installer of k3s $k3s_version"
  INSTALL_K3S_VERSION="$k3s_version" sh "$installer"
  rm -f "$installer"
fi
echo "waiting for the node"
wait_until 300 "the API of k3s" kubectl get nodes
kubectl wait --for=condition=Ready node --all --timeout=300s
wait_until 300 "the Helm controller of k3s" kubectl get crd helmcharts.helm.cattle.io

# ---------------------------------------------------------------------------------------------------------------
log "Traefik: the address of the client is kept (externalTrafficPolicy Local)"
# ---------------------------------------------------------------------------------------------------------------
kubectl apply -f "$payload/argocd/install/traefik.yaml"

# ---------------------------------------------------------------------------------------------------------------
log "Argo CD"
# ---------------------------------------------------------------------------------------------------------------
kubectl apply -f "$payload/argocd/install/argocd.yaml"
wait_until 900 "the CRD of Argo CD" kubectl get crd applications.argoproj.io
kubectl wait --for=condition=Established crd/applications.argoproj.io --timeout=300s
wait_until 900 "the deployments of Argo CD" kubectl -n argocd get deployment argocd-server argocd-repo-server
kubectl -n argocd rollout status deployment/argocd-server --timeout=600s
kubectl -n argocd rollout status deployment/argocd-repo-server --timeout=600s

# ---------------------------------------------------------------------------------------------------------------
log "Sealed Secrets"
# ---------------------------------------------------------------------------------------------------------------
if [ -n "$key_file" ]; then
  # Before the controller starts, so it uses this key instead of making a new one.
  kubectl apply -f "$key_file"
  echo "the key of the controller from the backup is in place"
else
  echo "no backup of the key given: the controller makes a new key (the certificate goes to the repository)"
fi
kubectl apply -f "$payload/argocd/apps/sealed-secrets.yaml"
wait_until 900 "the Sealed Secrets controller" kubectl -n kube-system get deployment sealed-secrets-controller
kubectl -n kube-system rollout status deployment/sealed-secrets-controller --timeout=600s
mkdir -p "$state_dir"
chmod 755 "$state_dir"
cert_tmp="$(mktemp)"
kubectl get --raw "/api/v1/namespaces/kube-system/services/http:sealed-secrets-controller:8080/proxy/v1/cert.pem" > "$cert_tmp" ||
  fail "could not read the certificate of the Sealed Secrets controller"
head -n 1 "$cert_tmp" | grep -q -- '-----BEGIN CERTIFICATE-----' || fail "the controller did not return a certificate"
install -m 644 "$cert_tmp" "$state_dir/sealed-secrets.pem"
rm -f "$cert_tmp"
echo "the certificate is in $state_dir/sealed-secrets.pem"
# The sealed secrets of the repository are encrypted with the certificate in the repository: if the controller has another,
# they could not be decrypted, so the root application (and with it the deployment) is not applied.
if [ -f "$payload/sealed-secrets.pem" ] && ! cmp -s "$payload/sealed-secrets.pem" "$state_dir/sealed-secrets.pem"; then
  fail "the certificate of the controller is NOT the one in the repository (infra/k8s/environments/prod/sealed-secrets.pem): the sealed secrets of the repository could not be decrypted. The root application was not applied. Restore the key from the backup (SEALED_KEY_FILE) or seal the secrets again (see the runbook)"
fi

# ---------------------------------------------------------------------------------------------------------------
log "the root application of Argo CD (app-of-apps, branch deploy-images)"
# ---------------------------------------------------------------------------------------------------------------
kubectl apply -f "$payload/argocd/root.yaml"

echo
echo "server-bootstrap: done"
