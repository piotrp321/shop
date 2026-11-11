.PHONY: init-env-test

CI_AFTER_E2E += init-env-test

# Tests of infra/scripts/init-env.sh in a temporary copy (a real .env.local is never touched); runs on the host, like
# the script itself, and needs only sh and openssl. Not part of `test`; CI runs it with `ci-after-e2e`.
init-env-test:
	sh infra/scripts/test-init-env.sh
