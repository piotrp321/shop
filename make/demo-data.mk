.PHONY: seed-scale

# Profile of apps/api/fixtures/demo-data/profiles.yaml for `seed-scale`: small, medium, server or full.
PROFILE ?= small
# Reference moment of every generated time (a date, default: the start of the current UTC day) and the number of processes
# that write the parts of the products (default 4). Both empty: the defaults of bin/generate-demo.
ANCHOR ?=
PROCESSES ?=

# Generates the demo data of PROFILE on top of the seed with apps/api/bin/generate-demo in the api container (the order of
# the steps, the checks and the exit codes are described there; a failing step stops the target). It resets the data of
# every module first, like `make seed`; run `make seed` afterwards to get back the data the E2E tests work on.
# Only `small` works until the pool of photos of the larger profiles exists (the photos step refuses them).
seed-scale:
	$(RUN) api bin/generate-demo --profile=$(PROFILE) $(if $(ANCHOR),--anchor=$(ANCHOR)) $(if $(PROCESSES),--processes=$(PROCESSES))
