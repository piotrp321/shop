#!/bin/sh
# The test of scripts/server-bootstrap.sh in a container with Ubuntu 24.04, as root, without k3s and without systemd
# (`make -C infra/k8s check`, step bootstrap, runs it: docker run ... ubuntu:24.04 sh /repo/infra/k8s/tests/bootstrap/test.sh).
#
# Real in the container: the checks (root, os-release, versions), every file the script writes (/etc/apt/apt.conf.d,
# /etc/sysctl.d, /etc/rancher/k3s, /var/lib/shop-bootstrap), the ordering and the idempotence of the steps.
# Stubbed (they need the network, the kernel or a running cluster): apt-get, sysctl, ufw, curl (the installer of k3s, which
# only leaves a stub k3s behind), k3s (its kubectl logs the calls and answers what the script asks for).
set -u

REPO_ROOT=/repo
export REPO_ROOT
# shellcheck source=../../scripts/tools.sh
. /repo/infra/k8s/scripts/tools.sh
k3s_version="v${K3S_VERSION#v}"
k3s_version="$(printf '%s' "$k3s_version" | sed 's/-k3s/+k3s/')"
script=/repo/infra/k8s/scripts/server-bootstrap.sh

fake=/tmp/fake
bin="$fake/bin"
rm -rf "$fake"
mkdir -p "$bin" "$fake/state"
failures=0
ok() { echo "ok   $1"; }
fail() { echo "FAIL $1: $2"; failures=$((failures + 1)); }

cat > "$bin/apt-get" << 'STUB'
#!/bin/sh
echo "apt-get $*" >> /tmp/fake/log
STUB
cat > "$bin/sysctl" << 'STUB'
#!/bin/sh
state=/tmp/fake/state/max_map_count
[ -f "$state" ] || echo 65530 > "$state"
case "$1" in
  -n) cat "$state" ;;
  -w) echo "sysctl $*" >> /tmp/fake/log; echo "${2#*=}" > "$state" ;;
esac
STUB
cat > "$bin/systemctl" << 'STUB'
#!/bin/sh
echo "systemctl $*" >> /tmp/fake/log
STUB
cat > "$bin/ufw" << 'STUB'
#!/bin/sh
echo "ufw $*" >> /tmp/fake/log
echo "$*" >> /tmp/fake/state/ufw-rules.all
sort -u /tmp/fake/state/ufw-rules.all > /tmp/fake/state/ufw-rules
STUB
# curl: the installer of k3s. It records the version it was asked for and leaves a stub k3s on the PATH.
cat > "$bin/curl" << 'STUB'
#!/bin/sh
echo "curl $*" >> /tmp/fake/log
[ -z "${FAKE_CURL_FAIL:-}" ] || { echo "curl: (22) The requested URL returned error: 404" >&2; exit 22; }
while [ $# -gt 0 ]; do [ "$1" = -o ] && out="$2"; shift; done
cat > "$out" << 'INSTALLER'
echo "installer INSTALL_K3S_VERSION=$INSTALL_K3S_VERSION" >> /tmp/fake/log
echo "$INSTALL_K3S_VERSION" > /tmp/fake/state/k3s-version
cp /tmp/fake/k3s-stub /tmp/fake/bin/k3s
INSTALLER
STUB
cat > "$fake/k3s-stub" << 'STUB'
#!/bin/sh
version="$(cat /tmp/fake/state/k3s-version)"
if [ "$1" = --version ]; then
  echo "k3s version $version (abcdef12)"
  echo "go version go1.25.0"
  exit 0
fi
shift # kubectl
echo "kubectl $*" >> /tmp/fake/log
case "$1" in
  apply) shift; [ "$1" = -f ] && echo "applied $(basename "$2") $(sha256sum < "$2" | cut -d' ' -f1)" >> /tmp/fake/log ;;
  get) [ "$2" = --raw ] && cat /tmp/fake/cert.pem ;;
esac
exit 0
STUB
chmod +x "$bin"/* "$fake/k3s-stub"
printf -- '-----BEGIN CERTIFICATE-----\nMIIDfakecertificate\n-----END CERTIFICATE-----\n' > "$fake/cert.pem"
PATH="$bin:$PATH"
export PATH

payload="$fake/payload"
mkdir -p "$payload"
cp -R /repo/infra/k8s/argocd "$payload/argocd"
keyfile="$fake/key.yaml"
KEY_MATERIAL="PRIVATEKEYMATERIALDONOTPRINT"
cat > "$keyfile" << YAML
apiVersion: v1
kind: Secret
metadata:
  name: sealed-secrets-key
  namespace: kube-system
  labels:
    sealedsecrets.bitnami.com/sealed-secrets-key: active
data:
  tls.key: $KEY_MATERIAL
YAML

touched_files="/etc/apt/apt.conf.d/20auto-upgrades /etc/sysctl.d/90-shop.conf /etc/rancher/k3s/config.yaml /var/lib/shop-bootstrap/sealed-secrets.pem"
clean_system() {
  for file in $touched_files; do rm -f "$file"; done
  rm -f "$fake/log" "$fake/state/"* "$bin/k3s"
  : > "$fake/log"
}
nothing_changed() {
  for file in $touched_files; do [ ! -e "$file" ] || return 1; done
  [ ! -s "$fake/log" ]
}
fingerprint() { for file in $touched_files; do [ -f "$file" ] && sha256sum "$file"; done; cat "$fake/state/ufw-rules" 2> /dev/null; }

run() { K3S_VERSION="$k3s_version" sh "$script" "$@" > "$fake/out" 2>&1; status=$?; }

# --- refusals: before any change -------------------------------------------------------------------------------

clean_system
setpriv --reuid=65534 --regid=65534 --clear-groups env K3S_VERSION="$k3s_version" sh "$script" "$payload" > "$fake/out" 2>&1
status=$?
if [ "$status" -eq 2 ] && grep -q 'must run as root' "$fake/out" && nothing_changed; then ok "without root: refused with exit 2, nothing changed"; else
  fail "no root" "status $status: $(cat "$fake/out")"; fi

cp /etc/os-release "$fake/os-release.orig"
for system in 'ID=debian\nVERSION_ID="12"' 'ID=ubuntu\nVERSION_ID="22.04"' 'ID=ubuntu\nVERSION_ID="25.04"'; do
  printf '%b\n' "$system" > /etc/os-release
  run "$payload"
  if [ "$status" -eq 2 ] && grep -q 'supports only Ubuntu 24.04' "$fake/out" && nothing_changed; then ok "another system ($(printf '%b' "$system" | tr '\n' ' ')): refused with exit 2, nothing changed"; else
    fail "other system" "status $status: $(cat "$fake/out")"; fi
done
cp "$fake/os-release.orig" /etc/os-release

for version in "" "v1.35.9+k3s1" "v1.36.6-k3s1" "latest" "v1.9.0+k3s1"; do
  K3S_VERSION="$version" sh "$script" "$payload" > "$fake/out" 2>&1
  status=$?
  if [ "$status" -eq 2 ] && nothing_changed; then ok "k3s version '$version' is refused with exit 2, nothing changed"; else
    fail "version" "'$version' status $status: $(cat "$fake/out")"; fi
done

for san in 'a b' 'x;rm -rf /' "\$(id)" '"quoted"'; do
  TLS_SAN="$san" run "$payload"
if   [ "$status" -eq 2 ] && nothing_changed; then ok "TLS_SAN '$san' is refused, nothing changed"; else fail "tls san" "'$san' status $status"; fi
done

run "$fake/does-not-exist"
if [ "$status" -eq 2 ] && nothing_changed; then ok "a missing payload is refused, nothing changed"; else fail "payload" "status $status"; fi
run
if [ "$status" -eq 2 ] && nothing_changed; then ok "no argument is a usage error, nothing changed"; else fail "usage" "status $status"; fi

cp -R "$payload" "$fake/payload-broken"
rm "$fake/payload-broken/argocd/root.yaml"
run "$fake/payload-broken"
if [ "$status" -eq 2 ] && nothing_changed; then ok "a payload without root.yaml is refused, nothing changed"; else fail "payload file" "status $status"; fi

cp -R "$payload" "$fake/payload-badkey"
echo "kind: Secret" > "$fake/payload-badkey/sealed-secrets-key.yaml"
run "$fake/payload-badkey"
if [ "$status" -eq 2 ] && nothing_changed; then ok "a key backup without the label of the key is refused, nothing changed"; else fail "bad key" "status $status"; fi

# --- the first run -----------------------------------------------------------------------------------------------

clean_system
TLS_SAN=203.0.113.7 run "$payload"
log="$(cat "$fake/log")"
if ! grep -q '^systemctl' "$fake/log"; then ok "the first run does not restart a freshly installed k3s"; else fail "first restart" "$(grep systemctl "$fake/log")"; fi
if [ "$status" -eq 0 ]; then ok "the first run ends with exit 0"; else fail "first run" "status $status: $(tail -n 15 "$fake/out")"; fi
if grep -q 'APT::Periodic::Unattended-Upgrade "1";' /etc/apt/apt.conf.d/20auto-upgrades; then ok "automatic security updates are configured"; else fail "unattended" "missing"; fi
if [ "$(cat /etc/sysctl.d/90-shop.conf)" = "vm.max_map_count=262144" ] && [ "$(cat "$fake/state/max_map_count")" = 262144 ]; then ok "vm.max_map_count is set now and kept in /etc/sysctl.d"; else fail "sysctl" "wrong"; fi
rules="$(cat "$fake/state/ufw-rules")"
for rule in "default deny incoming" "allow 22/tcp" "allow 6443/tcp" "--force enable"; do
if   printf '%s\n' "$rules" | grep -qxF -- "$rule"; then ok "ufw: $rule"; else fail "ufw" "no '$rule' in: $rules"; fi
done
if printf '%s\n' "$rules" | grep -Eq 'allow (80|443)(/tcp)?$'; then fail "ufw" "ports 80 or 443 are opened"; else ok "ufw does not open 80 and 443 (Cloudflare and the ipAllowList guard them)"; fi
if grep -q 'tls-san:' /etc/rancher/k3s/config.yaml && grep -q '^  - 203.0.113.7$' /etc/rancher/k3s/config.yaml; then ok "k3s config has the address of the server in tls-san"; else fail "tls-san" "$(cat /etc/rancher/k3s/config.yaml)"; fi
if [ "$(printf '%s\n' "$log" | grep -c '^installer ')" -eq 1 ] && printf '%s\n' "$log" | grep -qx "installer INSTALL_K3S_VERSION=$k3s_version"; then ok "k3s is installed once, in the pinned version $k3s_version"; else fail "installer" "$log"; fi
if printf '%s\n' "$log" | grep -q "curl .*raw.githubusercontent.com/k3s-io/k3s/$(printf '%s' "$k3s_version" | sed 's/+/%2B/')/install.sh"; then ok "the installer comes from the tag of the release, not from a moving URL"; else fail "installer url" "$log"; fi
order="$(printf '%s\n' "$log" | grep '^applied ' | cut -d' ' -f2)"
if [ "$order" = "$(printf 'traefik.yaml\nargocd.yaml\nsealed-secrets.yaml\nroot.yaml')" ]; then ok "applied in order: Traefik, Argo CD, Sealed Secrets, root"; else fail "order" "$order"; fi
if [ "$(cat /var/lib/shop-bootstrap/sealed-secrets.pem)" = "$(cat "$fake/cert.pem")" ]; then ok "the certificate of the controller is in /var/lib/shop-bootstrap"; else fail "cert" "differs"; fi
if grep -q 'no backup of the key given' "$fake/out"; then ok "without a backup the output says the controller makes a new key"; else fail "no backup message" "missing"; fi
first="$(fingerprint)"
first_applied="$(printf '%s\n' "$log" | grep '^applied ')"

# --- the second run: nothing breaks, nothing is done twice -------------------------------------------------------

: > "$fake/log"
TLS_SAN=203.0.113.7 run "$payload"
log2="$(cat "$fake/log")"
if [ "$status" -eq 0 ]; then ok "the second run ends with exit 0"; else fail "second run" "status $status: $(tail -n 15 "$fake/out")"; fi
if [ "$(fingerprint)" = "$first" ]; then ok "the second run leaves the files and the firewall rules as they were"; else fail "idempotent" "the state changed"; fi
if printf '%s\n' "$log2" | grep -q '^installer '; then fail "reinstall" "k3s was installed again"; else ok "the second run does not install k3s again"; fi
if printf '%s\n' "$log2" | grep -q '^sysctl -w'; then fail "sysctl again" "set again"; else ok "the second run does not set vm.max_map_count again"; fi
if [ "$(printf '%s\n' "$log2" | grep '^applied ')" = "$first_applied" ]; then ok "the second run applies the same manifests"; else fail "manifests" "differ"; fi
if [ "$(grep -c 'unchanged:' "$fake/out")" -ge 3 ]; then ok "the second run reports the files as unchanged"; else fail "unchanged" "$(cat "$fake/out")"; fi

# --- a different tls-san rewrites only the k3s config ------------------------------------------------------------

TLS_SAN=198.51.100.9 run "$payload"
if grep -q '^  - 198.51.100.9$' /etc/rancher/k3s/config.yaml; then ok "a new address changes the k3s config"; else fail "new san" "$(cat /etc/rancher/k3s/config.yaml)"; fi
if [ "$(grep -c '^systemctl restart k3s$' "$fake/log")" -eq 1 ]; then ok "a changed k3s config (same version) restarts k3s once"; else fail "restart" "$(grep systemctl "$fake/log")"; fi
: > "$fake/log"
TLS_SAN=198.51.100.9 run "$payload"
if ! grep -q '^systemctl' "$fake/log"; then ok "an unchanged k3s config does not restart k3s"; else fail "restart again" "$(grep systemctl "$fake/log")"; fi

# --- the key from the backup -------------------------------------------------------------------------------------

clean_system
cp -R "$payload" "$fake/payload-key"
cp "$keyfile" "$fake/payload-key/sealed-secrets-key.yaml"
run "$fake/payload-key"
order="$(grep '^applied ' "$fake/log" | cut -d' ' -f2)"
if [ "$status" -eq 0 ] && [ "$order" = "$(printf 'traefik.yaml\nargocd.yaml\nsealed-secrets-key.yaml\nsealed-secrets.yaml\nroot.yaml')" ]; then ok "with a backup the key is applied before the controller"; else fail "key order" "status $status: $order"; fi
if grep -q "$KEY_MATERIAL" "$fake/out" "$fake/log"; then fail "key printed" "the key material is in the output or the log"; else ok "the key material is not printed"; fi

# --- the backup in the format of the command of the runbook (kind: List) ------------------------------------------

clean_system
cp -R "$payload" "$fake/payload-list"
cat > "$fake/payload-list/sealed-secrets-key.yaml" << YAML
apiVersion: v1
items:
- apiVersion: v1
  data:
    tls.crt: AAAA
    tls.key: $KEY_MATERIAL
  kind: Secret
  metadata:
    labels:
      sealedsecrets.bitnami.com/sealed-secrets-key: active
    name: sealed-secrets-key7xk2p
    namespace: kube-system
  type: kubernetes.io/tls
kind: List
metadata:
  resourceVersion: ""
YAML
run "$fake/payload-list"
if [ "$status" -eq 0 ] && grep -q '^applied sealed-secrets-key.yaml' "$fake/log"; then ok "a backup in the format of the runbook (a List of Secrets) is accepted and applied"; else fail "list backup" "status $status: $(tail -n 3 "$fake/out")"; fi

# --- the certificate of the repository against the controller's, before the root ---------------------------------

clean_system
cp -R "$payload" "$fake/payload-cert"
cp "$fake/cert.pem" "$fake/payload-cert/sealed-secrets.pem"
run "$fake/payload-cert"
if [ "$status" -eq 0 ] && grep -q '^applied root.yaml' "$fake/log"; then ok "the certificate of the repository equals the controller's: the root is applied"; else fail "same cert" "status $status"; fi
clean_system
printf -- '-----BEGIN CERTIFICATE-----\nMIIDanothercert\n-----END CERTIFICATE-----\n' > "$fake/payload-cert/sealed-secrets.pem"
run "$fake/payload-cert"
if [ "$status" -eq 1 ] && grep -q 'NOT the one in the repository' "$fake/out" && ! grep -q '^applied root.yaml' "$fake/log"; then ok "another certificate than the repository's: exit 1 and the root is not applied"; else fail "other cert" "status $status: $(tail -n 3 "$fake/out")"; fi

# --- downgrade and a failing installer --------------------------------------------------------------------------

echo "v1.37.1+k3s1" > "$fake/state/k3s-version"
run "$payload"
if [ "$status" -eq 1 ] && grep -q 'refusing to downgrade' "$fake/out"; then ok "an installed k3s newer than the pinned one is not downgraded"; else fail "downgrade" "status $status: $(tail -n 3 "$fake/out")"; fi

clean_system
FAKE_CURL_FAIL=1 run "$payload"
if [ "$status" -eq 1 ] && grep -q 'could not download the installer' "$fake/out" && ! grep -q '^installer ' "$fake/log"; then ok "a failing download of the installer stops the script with exit 1"; else
  fail "curl fails" "status $status: $(tail -n 3 "$fake/out")"; fi

[ "$failures" -eq 0 ] || { echo "$failures failure(s)"; exit 1; }
echo "all passed"
