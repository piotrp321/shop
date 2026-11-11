-- Real AO AO schema and the rows /retro reads from the run of cart (paths and payloads blanked).
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
, epic_id TEXT, depends_on TEXT NOT NULL DEFAULT '[]', kind TEXT NOT NULL DEFAULT 'plan', mode TEXT NOT NULL DEFAULT 'new', brief_path TEXT, target_branch TEXT, pr_number INTEGER, pr_url TEXT, head_sha TEXT, merged_sha TEXT, ci TEXT, fix_count INTEGER NOT NULL DEFAULT 0);
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
CREATE TABLE epics (
    epic_id         TEXT PRIMARY KEY,
    repo            TEXT NOT NULL,
    repo_slug       TEXT NOT NULL,
    plan_path       TEXT NOT NULL,
    plan_sha        TEXT NOT NULL,
    spec_path       TEXT,
    branch          TEXT NOT NULL,
    base_sha        TEXT NOT NULL,
    status          TEXT NOT NULL,
    reason          TEXT,
    round           INTEGER NOT NULL DEFAULT 0,
    final_pr_number INTEGER,
    final_pr_url    TEXT,
    config          TEXT NOT NULL DEFAULT '{}',
    created_at      REAL NOT NULL,
    updated_at      REAL NOT NULL
);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('CART-1','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1793740925.814262,1793746932.654649,'cart','[]','plan','new',NULL,'epic/cart',73,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('CART-2','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1793740925.814359,1793750650.933866,'cart','["CART-1"]','plan','new',NULL,'epic/cart',74,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('CART-3','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1793740925.8144069,1793754351.551507,'cart','["CART-1"]','plan','new',NULL,'epic/cart',75,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('CART-4','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1793740925.81444,1793757056.782462,'cart','["CART-1"]','plan','new',NULL,'epic/cart',76,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('CART-5','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1793740925.8144681,1793759308.018795,'cart','["CART-2", "CART-4"]','plan','new',NULL,'epic/cart',77,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('CART-INTEGRATION-1','','','','','PASSED',NULL,0,0,NULL,NULL,'[]',1793759313.529355,1793760711.03905,'cart','[]','integration','new',NULL,'epic/cart',NULL,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('CART-FINALIZE','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1793760712.516802,1793761364.283041,'cart','[]','finalize','new',NULL,'epic/cart',78,NULL,NULL,NULL,NULL,0);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (1,'CART-1',1,NULL,'','',8017,1793743336.5670578,1793746913.856809,3577.29,'result','READY_FOR_INTEGRATION',0,15.970615100000009,3576280,31,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (2,'CART-2',1,NULL,'','',40078,1793747008.573625,1793750630.660566,3622.087,'result','READY_FOR_INTEGRATION',0,7.6107364,3621236,30,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (3,'CART-3',1,NULL,'','',70546,1793750725.8265212,1793754331.307224,3605.481,'result','READY_FOR_INTEGRATION',0,4.5610719,3604509,34,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (4,'CART-4',1,NULL,'','',1492,1793754422.9513931,1793757038.352716,2615.401,'result','READY_FOR_INTEGRATION',0,6.257090100000002,2614489,40,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (5,'CART-5',1,NULL,'','',27864,1793757131.875993,1793759289.924054,2158.048,'result','READY_FOR_INTEGRATION',0,5.245472000000002,2157114,25,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (6,'CART-INTEGRATION-1',1,NULL,'','',47484,1793759380.909566,1793760705.620876,1324.711,'result','PASSED',0,4.6319268,654521,20,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (7,'CART-FINALIZE',1,NULL,'','',59836,1793760785.62829,1793761346.431097,560.803,'result','READY_FOR_INTEGRATION',0,2.6960805999999997,559908,22,NULL,NULL,NULL,NULL);
INSERT INTO epics (epic_id,repo,repo_slug,plan_path,plan_sha,spec_path,branch,base_sha,status,reason,round,final_pr_number,final_pr_url,config,created_at,updated_at) VALUES ('cart','','piotrp321/shop','','',NULL,'epic/cart','','DONE',NULL,1,79,NULL,'{}',1793740925.813985,1793762911.366901);
