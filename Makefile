.PHONY: up down logs ps seed check test api-client keycloak-reset

# Override RUN to run outside Docker Compose, e.g. inside CI.
RUN ?= docker compose exec

# One-shot services that nothing waits on yet. `docker compose up --wait` reports a service that
# exited as an error unless a dependent service waits for it, so these run separately.
ONESHOT := rabbitmq-init
# Started last: the worker needs the tables that `init` creates.
LAST := worker

up:
	sh infra/scripts/init-env.sh
	docker compose up -d --wait $$(docker compose config --services | grep -vx -e '$(ONESHOT)' -e '$(LAST)')
	docker compose run --rm $(ONESHOT)
	$(MAKE) -C apps/api init
	docker compose up -d --wait $(LAST)

down:
	docker compose down

logs:
	docker compose logs -f --tail=100

ps:
	docker compose ps

# Resets the catalog and loads the demo data; waits (60 s) until the worker has indexed every product.
seed:
	$(RUN) api bin/console catalog:seed-demo

check:
	$(MAKE) -C apps/api check
	$(MAKE) -C apps/store check
	$(MAKE) -C apps/admin check

test:
	$(MAKE) -C apps/api test
	$(MAKE) -C apps/store test
	$(MAKE) -C apps/admin test

# Generates the TypeScript API client types of the store from the committed OpenAPI file.
api-client:
	$(RUN) store npx openapi-typescript /contract/openapi.json -o src/api/schema.d.ts

# Drops the Keycloak database and restarts Keycloak, which imports the realms from infra/keycloak/realms anew.
# Every Keycloak session, user and change made in the admin console is lost.
keycloak-reset:
	docker compose stop keycloak
	docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U postgres \
		-c 'DROP DATABASE IF EXISTS keycloak WITH (FORCE)' -c 'CREATE DATABASE keycloak OWNER keycloak'
	docker compose up -d --wait keycloak
