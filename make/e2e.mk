.PHONY: e2e e2e-check e2e-agent lighthouse

CI_AFTER_E2E += lighthouse

# Extra `docker compose run` flags for the e2e targets; CI passes -T (no terminal).
E2E_FLAGS ?=
# `--build` keeps the e2e image in step with e2e/package-lock.json (cached when nothing changed). CI builds the image
# with the GitHub Actions cache before and passes E2E_BUILD= to use it as it is.
E2E_BUILD ?= --build

# One part of the suite, e.g. E2E_SHARD=1/3 (Playwright `--shard`: whole spec files, in file order, split by the number of
# tests). CI runs each part on its own stack. E2E_SHARD_WEIGHTS=52:59:89 (one weight per part, the same in every part)
# moves the boundary by the share of tests; it is Playwright's PWTEST_SHARD_WEIGHTS, which is not documented: without
# it the split is even and every test still runs once. Both empty: the whole suite.
E2E_SHARD ?=
E2E_SHARD_WEIGHTS ?=

# Playwright tests against the running stack (`make up` and `make seed` first). A failing test or an unreachable service
# fails the target. With E2E_SHARD only that part of the suite runs.
e2e:
	mkdir -p e2e/test-results
	docker compose --profile e2e run --rm $(E2E_BUILD) $(E2E_FLAGS) $(if $(E2E_SHARD_WEIGHTS),-e PWTEST_SHARD_WEIGHTS=$(E2E_SHARD_WEIGHTS)) e2e $(if $(E2E_SHARD),--shard=$(E2E_SHARD))

# Type-checks the e2e package and runs the tests of the Lighthouse median script (e2e/lighthouse/summary.test.mjs). Needs
# no running services, only the e2e image (not part of `check`, which would pull the Playwright image for every
# developer; CI runs it in every e2e part).
e2e-check:
	docker compose --profile e2e run --rm $(E2E_BUILD) --no-deps $(E2E_FLAGS) --entrypoint npx e2e tsc --noEmit
	docker compose --profile e2e run --rm $(E2E_BUILD) --no-deps $(E2E_FLAGS) --entrypoint node e2e --test lighthouse/summary.test.mjs

# Lighthouse measurement of three store pages (home, category, product; 3 runs each) in the e2e container, against the
# running stack with the store in production mode (`STORE_MODE=production make up` and `make seed` first). The mode is
# read from the running store container, not from the environment of make. Reports, manifest.json and medians.md go to
# e2e/lighthouse-results. Only the SEO score fails the target; the other thresholds warn.
lighthouse:
	@if ! docker compose exec -T store true >/dev/null 2>&1; then \
		echo "lighthouse: the store container is not running. Start the stack first: STORE_MODE=production make up && make seed" >&2; exit 1; \
	fi; \
	mode="$$(docker compose exec -T store printenv STORE_MODE || true)"; \
	if [ "$$mode" != production ]; then \
		echo "lighthouse: the store runs in '$$mode' mode, the measurement needs the production build. Restart it with: STORE_MODE=production make up" >&2; exit 1; \
	fi
	mkdir -p e2e/lighthouse-results
	docker compose --profile e2e run --rm $(E2E_BUILD) $(E2E_FLAGS) e2e lighthouse

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
