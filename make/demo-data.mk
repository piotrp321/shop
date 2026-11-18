.PHONY: seed-scale upload-demo-photos

# Profile of apps/api/fixtures/demo-data/profiles.yaml for `seed-scale`: small, medium, server or full.
PROFILE ?= small
# Reference moment of every generated time (a date, default: the start of the current UTC day) and the number of processes
# that write the parts of the products (default 4). Both empty: the defaults of bin/generate-demo.
ANCHOR ?=
PROCESSES ?=
# The file of the user, outside the repository, with the access to the private bucket of the demo photo pool
# (DEMO_PHOTOS_* variables, described in docs/features/demo-data/spec.md, "Wymagane przed startem").
DEMO_PHOTOS_ENV_FILE ?= $(HOME)/.config/shop/demo-photos.env
# The photos to upload (a directory inside apps/api) for `upload-demo-photos`.
DIR ?=

# Generates the demo data of PROFILE on top of the seed with apps/api/bin/generate-demo in the api container (the order of
# the steps, the checks and the exit codes are described there; a failing step stops the target). It resets the data of
# every module first, like `make seed`; run `make seed` afterwards to get back the data the E2E tests work on. The step of
# the accounts logs in to the Keycloak of the stack as its administrator: the api container has KC_BOOTSTRAP_ADMIN_PASSWORD
# from .env.local, and the generated accounts go to the realms shop and backoffice of the stack (`make seed` leaves them).
# A profile other than `small` takes its photos from the private bucket of the photo pool: the four read variables
# (DEMO_PHOTOS_ENDPOINT, DEMO_PHOTOS_BUCKET, DEMO_PHOTOS_ACCESS_KEY_ID, DEMO_PHOTOS_SECRET_ACCESS_KEY) are read from
# DEMO_PHOTOS_ENV_FILE as data (no value is evaluated) and passed to the container by name (`-e NAME`: the value comes from
# the environment of the shell, so it is never in a command line, in the output of make or in `make -n`). The token that
# writes is not passed. A missing file stops the target before anything runs; a variable that is empty is named by the script.
seed-scale:
	@set -eu; \
	if [ "$(PROFILE)" = "small" ]; then \
		$(RUN) api bin/generate-demo --profile=$(PROFILE) $(if $(ANCHOR),--anchor=$(ANCHOR)) $(if $(PROCESSES),--processes=$(PROCESSES)); \
	else \
		if [ ! -r "$(DEMO_PHOTOS_ENV_FILE)" ]; then \
			echo "The profile $(PROFILE) needs the photo pool: $(DEMO_PHOTOS_ENV_FILE) does not exist or cannot be read (set DEMO_PHOTOS_ENV_FILE; the variables are described in docs/features/demo-data/spec.md)." >&2; \
			exit 1; \
		fi; \
		while IFS= read -r line || [ -n "$$line" ]; do \
			line="$${line%"$$(printf '\r')"}"; \
			case "$$line" in \
				DEMO_PHOTOS_ENDPOINT=* | DEMO_PHOTOS_BUCKET=* | DEMO_PHOTOS_ACCESS_KEY_ID=* | DEMO_PHOTOS_SECRET_ACCESS_KEY=*) \
					value="$${line#*=}"; \
					case "$$value" in \
						\"*\") value="$${value#\"}"; value="$${value%\"}" ;; \
						\'*\') value="$${value#\'}"; value="$${value%\'}" ;; \
					esac; \
					export "$${line%%=*}=$$value" ;; \
			esac; \
		done < "$(DEMO_PHOTOS_ENV_FILE)"; \
		$(RUN) -e DEMO_PHOTOS_ENDPOINT -e DEMO_PHOTOS_BUCKET -e DEMO_PHOTOS_ACCESS_KEY_ID -e DEMO_PHOTOS_SECRET_ACCESS_KEY api bin/generate-demo --profile=$(PROFILE) $(if $(ANCHOR),--anchor=$(ANCHOR)) $(if $(PROCESSES),--processes=$(PROCESSES)); \
	fi

# Checks the photos in DIR (a directory inside apps/api, e.g. DIR=apps/api/var/demo-photos) against the manifest and uploads
# them as the archive of the version of apps/api/fixtures/demo-data/photos.yaml to the private bucket, with the write token
# of DEMO_PHOTOS_ENV_FILE and `rclone copyto --immutable` (apps/api/bin/upload-demo-photos runs on the host, the check in
# the api container). A version that is in the bucket is never overwritten.
upload-demo-photos:
	@[ -n "$(DIR)" ] || { echo "Set DIR to the directory of the photos inside apps/api, e.g. make upload-demo-photos DIR=apps/api/var/demo-photos" >&2; exit 2; }
	apps/api/bin/upload-demo-photos "$(DIR)" --env-file="$(DEMO_PHOTOS_ENV_FILE)"
