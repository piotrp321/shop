.PHONY: up down logs ps seed check test varnish-test e2e e2e-check e2e-agent api-client keycloak-reset

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

# Resets the catalog and loads the demo data; waits (60 s) until the worker has indexed every product.
# Then resets the prices and sets the demo price of every product (fails when a product has no price pattern).
# Then removes all orders, before the stocks: the reservations the orders hold go with the stocks.
# Then resets the stocks and sets the demo stock of every product (fails when a product has no stock pattern).
seed:
	$(RUN) api bin/console catalog:seed-demo
	$(RUN) api bin/console pricing:seed-demo
	$(RUN) api bin/console ordering:seed-demo
	$(RUN) api bin/console inventory:seed-demo
	$(RUN) api bin/console storefront:cache:purge-all

check:
	$(MAKE) -C apps/api check
	$(MAKE) -C apps/store check
	$(MAKE) -C apps/admin check

test:
	$(MAKE) -C apps/api test
	$(MAKE) -C apps/store test
	$(MAKE) -C apps/admin test
	$(MAKE) varnish-test

# VCL tests (infra/varnish/tests/*.vtc) with varnishtest from the image of the varnish service; no running stack and
# no network. The backend of default.vcl is api:80: in the test container api is 127.0.0.1, where the .vtc servers listen.
varnish-test:
	image="$$(docker compose config --images varnish | grep -m1 '^varnish:')" && \
	docker run --rm --network none --add-host api:127.0.0.1 -v "$(CURDIR)/infra/varnish:/etc/varnish:ro" \
		--entrypoint sh "$$image" -c 'varnishtest -j1 -k /etc/varnish/tests/*.vtc'

# Extra `docker compose run` flags for the e2e targets; CI passes -T (no terminal).
E2E_FLAGS ?=
# `--build` keeps the e2e image in step with e2e/package-lock.json (cached when nothing changed). CI builds the image
# with the GitHub Actions cache before and passes E2E_BUILD= to use it as it is.
E2E_BUILD ?= --build

# Playwright tests against the running stack (`make up` and `make seed` first). A failing test or an unreachable service
# fails the target.
e2e:
	mkdir -p e2e/test-results
	docker compose --profile e2e run --rm $(E2E_BUILD) $(E2E_FLAGS) e2e

# Type-checks the e2e package. Needs no running services, only the e2e image (not part of `check`, which would pull
# the Playwright image for every developer; CI runs it in the e2e job).
e2e-check:
	docker compose --profile e2e run --rm $(E2E_BUILD) --no-deps $(E2E_FLAGS) --entrypoint npx e2e tsc --noEmit

# Playwright scripts written by an agent, outside the repo (see docs/features/agent-tester-ux/spec.md). AGENT_DIR is the
# absolute path of an existing directory: scripts are read from $(AGENT_DIR)/tests, results go to $(AGENT_DIR)/results.
# Extra Playwright arguments go in E2E_ARGS, e.g. E2E_ARGS="--project mobile". Needs the running stack.
e2e-agent:
	@case "$(AGENT_DIR)" in \
		/*) ;; \
		*) echo "e2e-agent: AGENT_DIR must be the absolute path of an existing directory, got '$(AGENT_DIR)'" >&2; exit 2 ;; \
	esac; \
	if [ ! -d "$(AGENT_DIR)" ]; then echo "e2e-agent: AGENT_DIR '$(AGENT_DIR)' is not an existing directory" >&2; exit 2; fi
	mkdir -p "$(AGENT_DIR)/results"
	docker compose --profile e2e run --rm $(E2E_BUILD) $(E2E_FLAGS) -v "$(AGENT_DIR):/app/agent" e2e --config playwright.agent.config.ts $(E2E_ARGS)

# Generates the TypeScript API client types of the store and of the admin panel from the committed OpenAPI files.
api-client:
	$(RUN) store npx openapi-typescript /contract/openapi.json -o src/api/schema.d.ts
	$(RUN) admin npx openapi-typescript /contract/openapi-admin.json -o src/api/schema.d.ts

# Drops the Keycloak database and restarts Keycloak, which imports the realms from infra/keycloak/realms anew.
# Every Keycloak session, user and change made in the admin console is lost.
keycloak-reset:
	docker compose stop keycloak
	docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U postgres \
		-c 'DROP DATABASE IF EXISTS keycloak WITH (FORCE)' -c 'CREATE DATABASE keycloak OWNER keycloak'
	docker compose up -d --wait keycloak
