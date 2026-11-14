.PHONY: up down logs ps seed check test api-client ci-after-e2e

# Targets of one area live in make/<area>.mk with their own .PHONY line. A fragment adds its target to `check` with
# CHECK_TARGETS += <target>, to `test` with TEST_TARGETS += <target> and to the CI stack checks after the E2E tests with
# CI_AFTER_E2E += <target>. Fragments are read in file name order, which is the order of each list; no target may depend
# on that order. The include is at the end of this file, so `up` stays the default target.
CHECK_TARGETS :=
TEST_TARGETS :=
CI_AFTER_E2E :=

# One recipe line per target of a list: the first failing one stops the target. The `+` marks each line as a recursive
# make (run by `make -n` too, like a line that names $(MAKE) itself).
define newline


endef
run_each = $(foreach t,$(1),+$(MAKE) $(t)$(newline))

# Override RUN to run outside Docker Compose, e.g. inside CI.
RUN ?= docker compose exec

# One-shot services that nothing waits on yet. `docker compose up --wait` reports a service that
# exited as an error unless a dependent service waits for it, so these run separately.
ONESHOT := rabbitmq-init
# Started last: the worker needs the tables that `init` creates. It is recreated on every `make up`, so that it
# always runs the code (and handler wiring) as of that moment.
LAST := worker

up:
	sh infra/scripts/init-env.sh
	docker compose up -d --wait $$(docker compose config --services | grep -vx -e '$(ONESHOT)' -e '$(LAST)')
	docker compose run --rm $(ONESHOT)
	$(MAKE) -C apps/api init
	docker compose up -d --wait --force-recreate $(LAST)

down:
	docker compose down

logs:
	docker compose logs -f --tail=100

ps:
	docker compose ps

# Loads the demo data with apps/api/bin/seed-demo (the order of the seeds, the reindex and the cache purge are described
# there); the first failing step stops the target.
seed:
	$(RUN) api bin/seed-demo

# The last one checks the Helm chart of infra/k8s (render, manifests, scripts, tests of the render); it needs only Docker.
check:
	$(MAKE) -C apps/api check
	$(MAKE) -C apps/store check
	$(MAKE) -C apps/admin check
	$(MAKE) -C infra/k8s check
	$(call run_each,$(CHECK_TARGETS))

test:
	$(MAKE) -C apps/api test
	$(MAKE) -C apps/store test
	$(MAKE) -C apps/admin test
	$(call run_each,$(TEST_TARGETS))

# Generates the TypeScript API client types of the store and of the admin panel from the committed OpenAPI files.
api-client:
	$(RUN) store npx openapi-typescript /contract/openapi.json -o src/api/schema.d.ts
	$(RUN) admin npx openapi-typescript /contract/openapi-admin.json -o src/api/schema.d.ts

# The CI checks on the running stack after `make e2e` (in the first e2e part of CI): every target of CI_AFTER_E2E runs,
# also after one of them failed (a failed Lighthouse assertion must not hide the other checks), and the failed ones are
# listed at the end. An empty list is an error, so missing fragments cannot pass for green.
ci-after-e2e:
	@[ -n "$(strip $(CI_AFTER_E2E))" ] || { echo "ci-after-e2e: CI_AFTER_E2E is empty (no make/*.mk fragment adds to it)" >&2; exit 2; }
	+@failed=""; for t in $(CI_AFTER_E2E); do $(MAKE) $$t || failed="$$failed $$t"; done; \
	if [ -n "$$failed" ]; then echo "ci-after-e2e: failed:$$failed" >&2; exit 1; fi

include $(sort $(wildcard make/*.mk))
