.PHONY: up down logs ps

# One-shot services that nothing waits on yet. `docker compose up --wait` reports a service that
# exited as an error unless a dependent service waits for it, so these run separately.
ONESHOT := rabbitmq-init

up:
	sh infra/scripts/init-env.sh
	docker compose up -d --wait $$(docker compose config --services | grep -vx '$(ONESHOT)')
	docker compose run --rm $(ONESHOT)

down:
	docker compose down

logs:
	docker compose logs -f --tail=100

ps:
	docker compose ps
