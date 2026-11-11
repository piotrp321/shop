.PHONY: keycloak-reset

# Drops the Keycloak database and restarts Keycloak, which imports the realms from infra/keycloak/realms anew.
# Every Keycloak session, user and change made in the admin console is lost.
keycloak-reset:
	docker compose stop keycloak
	docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U postgres \
		-c 'DROP DATABASE IF EXISTS keycloak WITH (FORCE)' -c 'CREATE DATABASE keycloak OWNER keycloak'
	docker compose up -d --wait keycloak
