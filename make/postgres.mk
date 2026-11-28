.PHONY: postgres-config-test

CI_AFTER_E2E += postgres-config-test

# Tests of the configuration of the compose service postgres on the running stack (infra/postgres/test-config.sh): SHOW of
# every setting of infra/postgres/postgresql.conf, pg_stat_statements and the metrics role, a parallel hash join of more than
# 64 MB (/dev/shm), the log of Postgres after a violation of a unique constraint, a deadlock, a wait for a lock, a cancelled
# query and errors of data (no DETAIL, CONTEXT, STATEMENT, value or text of a query), and the failed start on an unknown
# setting. It works in its own database and drops it. POSTGRES_LOG_CORPUS_OUT=<file> writes the lines of the errors of data
# (class 22) to a file, for the log corpus of the masking test (infra/k8s/tests/log-corpus/postgres.log).
postgres-config-test:
	sh infra/postgres/test-config.sh
