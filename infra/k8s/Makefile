.PHONY: check smoke rerun selftest render

# Static checks of the chart (lint, render, schema, kubeconform, shellcheck, tests of the render). Needs only Docker.
check:
	sh scripts/check.sh

# The checks fail on injected errors (a broken template, a missing secret key, an invalid manifest, ...).
selftest:
	sh scripts/selftest.sh
