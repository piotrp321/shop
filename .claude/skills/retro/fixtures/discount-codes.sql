-- Real AO AO schema and the rows /retro reads from the run of discount-codes (paths and payloads blanked).
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
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('DISCOUNT-CODES-1','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794165781.412658,1794170378.4328032,'discount-codes','[]','plan','new',NULL,'epic/discount-codes',312,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('DISCOUNT-CODES-2','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794165781.412888,1794173645.098193,'discount-codes','["DISCOUNT-CODES-1"]','plan','new',NULL,'epic/discount-codes',313,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('DISCOUNT-CODES-3','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794165781.412965,1794177728.268854,'discount-codes','["DISCOUNT-CODES-2"]','plan','new',NULL,'epic/discount-codes',318,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('DISCOUNT-CODES-4','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794165781.4131489,1794179385.449653,'discount-codes','["DISCOUNT-CODES-3"]','plan','new',NULL,'epic/discount-codes',319,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('DISCOUNT-CODES-5','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794165781.4132118,1794180922.2260249,'discount-codes','["DISCOUNT-CODES-1", "DISCOUNT-CODES-3"]','plan','new',NULL,'epic/discount-codes',320,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('DISCOUNT-CODES-6','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794165781.413256,1794182811.957431,'discount-codes','["DISCOUNT-CODES-4", "DISCOUNT-CODES-5"]','plan','new',NULL,'epic/discount-codes',321,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('DISCOUNT-CODES-INTEGRATION-1','','','','','FINDINGS',NULL,0,0,NULL,NULL,'[]',1794182818.683713,1794186963.631992,'discount-codes','[]','integration','new',NULL,'epic/discount-codes',NULL,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('DISCOUNT-CODES-FIX-1','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794186965.22383,1794188044.2828841,'discount-codes','[]','fix','new',NULL,'epic/discount-codes',322,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('DISCOUNT-CODES-INTEGRATION-2','','','','','FINDINGS',NULL,0,0,NULL,NULL,'[]',1794188050.463783,1794189284.1387498,'discount-codes','[]','integration','new',NULL,'epic/discount-codes',NULL,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('DISCOUNT-CODES-FIX-2','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794189285.7279518,1794190187.884732,'discount-codes','[]','fix','new',NULL,'epic/discount-codes',323,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('DISCOUNT-CODES-INTEGRATION-3','','','','','PASSED',NULL,0,0,NULL,NULL,'[]',1794190193.685617,1794210486.2094998,'discount-codes','[]','integration','new',NULL,'epic/discount-codes',NULL,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('DISCOUNT-CODES-FINALIZE','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794210487.679283,1794210954.355456,'discount-codes','[]','finalize','new',NULL,'epic/discount-codes',328,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('DISCOUNT-CODES-INTEGRATION-4','','','','','PASSED',NULL,0,0,NULL,NULL,'[]',1794213630.6924748,1794214128.7254999,'discount-codes','[]','integration','new',NULL,'epic/discount-codes',NULL,NULL,NULL,NULL,NULL,0);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (1,'DISCOUNT-CODES-1',1,NULL,'','',66146,1794165868.2491,1794169836.858668,3968.61,'invalid_output',NULL,0,20.127219000000014,3967468,28,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (2,'DISCOUNT-CODES-1',2,NULL,'','',94776,1794169911.418303,1794170359.518552,448.1,'result','READY_FOR_INTEGRATION',0,0.6206803999999999,447239,7,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (3,'DISCOUNT-CODES-2',1,NULL,'','',98693,1794170443.4914858,1794173627.358523,3183.867,'result','READY_FOR_INTEGRATION',0,15.134678299999997,3183171,29,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (4,'DISCOUNT-CODES-3',1,NULL,'','',21454,1794173714.6491368,1794177709.293057,3994.644,'result','READY_FOR_INTEGRATION',0,21.35327999999999,3993390,28,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (5,'DISCOUNT-CODES-4',1,NULL,'','',44079,1794177793.496056,1794179367.1234188,1573.627,'result','READY_FOR_INTEGRATION',0,4.997385800000002,1572694,28,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (6,'DISCOUNT-CODES-5',1,NULL,'','',49887,1794179448.5613608,1794180903.476467,1454.915,'result','READY_FOR_INTEGRATION',0,4.694051199999998,1454161,22,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (7,'DISCOUNT-CODES-6',1,NULL,'','',55461,1794180988.705486,1794182793.626352,1804.921,'result','READY_FOR_INTEGRATION',0,4.1005471999999985,1803931,22,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (8,'DISCOUNT-CODES-INTEGRATION-1',1,NULL,'','',61956,1794182878.747638,1794186957.365945,4078.618,'result','FINDINGS',0,11.155934600000002,819871,39,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (9,'DISCOUNT-CODES-FIX-1',1,NULL,'','',75218,1794187029.714509,1794188026.0068989,996.292,'result','READY_FOR_INTEGRATION',0,1.6397163,995283,18,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (10,'DISCOUNT-CODES-INTEGRATION-2',1,NULL,'','',79811,1794188112.7656178,1794189278.50542,1165.74,'result','FINDINGS',0,2.8454784999999987,641452,25,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (11,'DISCOUNT-CODES-FIX-2',1,NULL,'','',84698,1794189347.8658419,1794190169.773302,821.907,'result','READY_FOR_INTEGRATION',0,1.5443066,821005,22,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (12,'DISCOUNT-CODES-INTEGRATION-3',1,NULL,'','',93460,1794209930.89035,1794210480.902046,550.012,'result','PASSED',0,2.0658940999999995,220474,15,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (13,'DISCOUNT-CODES-FINALIZE',1,NULL,'','',251,1794210549.523402,1794210936.647489,387.124,'result','READY_FOR_INTEGRATION',0,2.0719799,386218,18,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (14,'DISCOUNT-CODES-INTEGRATION-4',1,NULL,'','',5011,1794213691.047564,1794214123.750531,432.703,'result','PASSED',0,2.872938600000001,239864,21,NULL,NULL,NULL,NULL);
INSERT INTO epics (epic_id,repo,repo_slug,plan_path,plan_sha,spec_path,branch,base_sha,status,reason,round,final_pr_number,final_pr_url,config,created_at,updated_at) VALUES ('discount-codes','','piotrp321/shop','','',NULL,'epic/discount-codes','','DONE',NULL,4,329,NULL,'{}',1794165781.4118972,1794215218.4398878);
