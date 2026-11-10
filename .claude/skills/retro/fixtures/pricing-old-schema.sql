-- Real schema of an older AO (run of PRICING): no epics table, no task kinds.
CREATE TABLE tasks (
    task_id     TEXT PRIMARY KEY,
    spec_path   TEXT NOT NULL,
    base_sha    TEXT NOT NULL,
    workdir     TEXT NOT NULL,
    state_file  TEXT NOT NULL,
    status      TEXT NOT NULL,
    reason      TEXT,
    failures    INTEGER NOT NULL DEFAULT 0,
    limit_hits  INTEGER NOT NULL DEFAULT 0,
    not_before  REAL,
    blockers    TEXT,
    answers     TEXT NOT NULL DEFAULT '[]',
    created_at  REAL NOT NULL,
    updated_at  REAL NOT NULL
);
CREATE TABLE attempts (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id         TEXT NOT NULL REFERENCES tasks (task_id),
    number          INTEGER NOT NULL,
    -- 1 while the attempt is open, NULL once closed: at most one executor runs at a time.
    active          INTEGER UNIQUE CHECK (active = 1),
    dir             TEXT NOT NULL,
    command         TEXT NOT NULL,
    pid             INTEGER,
    started_at      REAL NOT NULL,
    ended_at        REAL,
    wall_s          REAL,
    outcome         TEXT,
    executor_status TEXT,
    exit_code       INTEGER,
    cost_usd        REAL,
    duration_ms     INTEGER,
    num_turns       INTEGER,
    session_id      TEXT,
    error           TEXT,
    warnings        TEXT,
    result          TEXT,
    UNIQUE (task_id, number)
);
