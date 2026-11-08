# Sealed secrets of the environment

SealedSecret resources written by `infra/k8s/scripts/seal-secrets.sh` (encrypted with the certificate
`../sealed-secrets.pem`; safe to commit). The `secrets` application of Argo CD applies them to the namespace `shop`:

- `shop-secrets.yaml`: the keys of `infra/scripts/init-env.sh`, read by every pod;
- `shop-brevo-smtp.yaml`: `BREVO_SMTP_LOGIN` and `BREVO_SMTP_PASSWORD`;
- `shop-origin-tls.yaml`: the Cloudflare Origin CA certificate and key (type `kubernetes.io/tls`).

The runbook (`infra/k8s/README.md`) says when each is written. Never put a plain Secret here.
