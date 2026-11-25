.PHONY: base-images-check

CHECK_TARGETS += base-images-check

# Every external image of every Dockerfile of the repository (FROM, COPY --from, RUN --mount from) is pinned by
# name:tag@sha256:<digest>; a reference to an earlier stage and `scratch` are allowed. The script and its tests (found by
# .github/scripts/script-tests.sh) are in tools/base-images/. It runs on the host and needs python3 and git; a directory
# that is not a git repository, or has no Dockerfile, is an error. Reading the digest of a tag: the "Pinned:" comments in
# the Dockerfiles.
base-images-check:
	python3 -I tools/base-images/check_base_images.py
