.PHONY: evals-lint evals-check

CHECK_TARGETS += evals-lint

# Extra arguments of evals-check: --enforce makes a stale or regressed suite with a measurement an error, --diff
# <base>...<head> limits that to the suites the diff changes (CI: .github/scripts/evals-args.sh).
EVALS_CHECK_ARGS ?=

# The formats of the evals of the agents (suite registry, config, cases, history files), the scan of the case data and
# of the history for home paths, e-mail addresses and tokens, the rules of the injection payloads and, with the full
# history, the commits of the cases (tools/evals/README.md). Runs python3 on the host, like the script tests; no model.
evals-lint:
	python3 tools/evals/evals.py lint

# The fingerprint of every suite against its current result in tools/evals/history: without arguments only warnings
# (a suite without a measurement, a stale one). Not part of `check`: a stale fingerprint must not turn the required
# `checks` red; the `evals` job of CI runs it with the arguments of the event.
evals-check:
	python3 tools/evals/evals.py check $(EVALS_CHECK_ARGS)
