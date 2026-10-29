.PHONY: up down logs ps check test

# One-shot services that nothing waits on yet. `docker compose up --wait` reports a service that
# exited as an error unless a dependent service waits for it, so these run separately.
ONESHOT := rabbitmq-init

up:
	sh infra/scripts/init-env.sh
	docker compose up -d --wait $$(docker compose config --services | grep -vx '$(ONESHOT)')
	docker compose run --rm $(ONESHOT)
	$(MAKE) -C apps/api init

down:
	docker compose down

logs:
	docker compose logs -f --tail=100

ps:
	docker compose ps

check:
	$(MAKE) -C apps/api check
	$(MAKE) -C apps/store check
	$(MAKE) -C apps/admin check

test:
	$(MAKE) -C apps/api test
	$(MAKE) -C apps/store test
	$(MAKE) -C apps/admin test
