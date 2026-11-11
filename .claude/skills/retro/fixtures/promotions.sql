-- Real AO schema and the rows /retro reads from the run of promotions (paths and payloads blanked).
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
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('PROMOTIONS-1','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794085793.427037,1794090203.518244,'promotions','[]','plan','new',NULL,'epic/promotions',269,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('PROMOTIONS-2','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794085793.4271219,1794139920.586687,'promotions','["PROMOTIONS-1"]','plan','new',NULL,'epic/promotions',275,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('PROMOTIONS-3','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794085793.427161,1794141118.145544,'promotions','["PROMOTIONS-1"]','plan','new',NULL,'epic/promotions',280,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('PROMOTIONS-4','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794085793.427181,1794143138.9634812,'promotions','["PROMOTIONS-2"]','plan','new',NULL,'epic/promotions',284,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('PROMOTIONS-5','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794085793.427201,1794142104.1043258,'promotions','["PROMOTIONS-1"]','plan','new',NULL,'epic/promotions',282,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('PROMOTIONS-6','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794085793.427219,1794147126.208734,'promotions','["PROMOTIONS-3", "PROMOTIONS-4", "PROMOTIONS-5"]','plan','new',NULL,'epic/promotions',288,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('PROMOTIONS-REPAIR-1','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794090915.457453,1794139713.275601,'promotions','[]','repair','new',NULL,'epic/promotions',281,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('PROMOTIONS-INTEGRATION-1','','','','','FINDINGS',NULL,0,0,NULL,NULL,'[]',1794147132.885212,1794150260.622612,'promotions','[]','integration','new',NULL,'epic/promotions',NULL,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('PROMOTIONS-FIX-1','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794150262.2432399,1794151294.9440901,'promotions','[]','fix','new',NULL,'epic/promotions',299,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('PROMOTIONS-INTEGRATION-2','','','','','PASSED',NULL,0,0,NULL,NULL,'[]',1794151301.1071548,1794153866.252594,'promotions','[]','integration','new',NULL,'epic/promotions',NULL,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('PROMOTIONS-FINALIZE','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794153867.803914,1794154335.224436,'promotions','[]','finalize','new',NULL,'epic/promotions',300,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('PROMOTIONS-REPAIR-2','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794154408.51986,1794156621.085788,'promotions','[]','repair','new',NULL,'epic/promotions',302,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('PROMOTIONS-INTEGRATION-3','','','','','FINDINGS',NULL,0,0,NULL,NULL,'[]',1794156627.573615,1794158927.243229,'promotions','[]','integration','new',NULL,'epic/promotions',NULL,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('PROMOTIONS-FIX-3','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794158987.493979,1794161066.507116,'promotions','[]','fix','new',NULL,'epic/promotions',303,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('PROMOTIONS-INTEGRATION-4','','','','','PASSED',NULL,0,0,NULL,NULL,'[]',1794161073.380094,1794162156.0349948,'promotions','[]','integration','new',NULL,'epic/promotions',NULL,NULL,NULL,NULL,NULL,0);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (1,'PROMOTIONS-1',1,NULL,'','',32858,1794086272.244891,1794090184.3997812,3912.155,'result','READY_FOR_INTEGRATION',0,20.897498500000008,3911308,25,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (2,'PROMOTIONS-REPAIR-1',1,NULL,'','',25142,1794129427.1165109,1794129529.484472,102.368,'result','BLOCKED',0,0.8960263999999999,101708,16,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (3,'PROMOTIONS-2',1,NULL,'','',29294,1794129599.32899,1794133039.325312,3439.996,'result','READY_FOR_INTEGRATION',0,13.3888737,3439269,39,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (4,'PROMOTIONS-REPAIR-1',2,NULL,'','',18351,1794133113.703647,1794133147.7302232,34.027,'invalid_output',NULL,0,0.38138380000000005,33330,4,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (5,'PROMOTIONS-REPAIR-1',3,NULL,'','',51006,1794133245.172936,1794133276.7307851,31.558,'invalid_output',NULL,0,0.1270682,30810,4,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (6,'PROMOTIONS-3',1,NULL,'','',78096,1794133386.4470952,1794136046.6450481,2660.198,'result','READY_FOR_INTEGRATION',0,9.072609400000005,2659205,31,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (7,'PROMOTIONS-REPAIR-1',4,NULL,'','',69751,1794138629.9532611,1794139696.3507688,1066.398,'result','READY_FOR_INTEGRATION',0,1.1490011000000002,1065727,17,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (8,'PROMOTIONS-5',1,NULL,'','',84992,1794139782.835912,1794141620.066937,1837.231,'result','READY_FOR_INTEGRATION',0,5.422312600000001,1836486,27,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (9,'PROMOTIONS-4',1,NULL,'','',26943,1794141707.831816,1794143058.5394182,1350.708,'result','READY_FOR_INTEGRATION',0,2.2565299999999997,1349952,21,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (10,'PROMOTIONS-6',1,NULL,'','',62917,1794143728.525386,1794147107.9762838,3379.451,'result','READY_FOR_INTEGRATION',0,8.661770699999998,2924267,46,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (11,'PROMOTIONS-INTEGRATION-1',1,NULL,'','',91156,1794147193.238514,1794150255.038742,3061.8,'result','FINDINGS',0,10.7317223,1278957,40,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (12,'PROMOTIONS-FIX-1',1,NULL,'','',11430,1794150326.1064692,1794151275.7070022,949.601,'result','READY_FOR_INTEGRATION',0,1.6661621,948889,16,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (13,'PROMOTIONS-INTEGRATION-2',1,NULL,'','',15171,1794151360.4383051,1794153861.155506,2500.717,'result','PASSED',0,4.1665396999999995,291776,36,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (14,'PROMOTIONS-FINALIZE',1,NULL,'','',23057,1794153928.061733,1794154316.927051,388.865,'result','READY_FOR_INTEGRATION',0,1.6324095,387981,16,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (15,'PROMOTIONS-REPAIR-2',1,NULL,'','',25787,1794154471.536365,1794156602.726547,2131.19,'result','READY_FOR_INTEGRATION',0,3.803649999999999,2130490,47,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (16,'PROMOTIONS-INTEGRATION-3',1,NULL,'','',39741,1794156687.424652,1794158921.509501,2234.085,'result','FINDINGS',0,7.330282699999998,408632,34,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (17,'PROMOTIONS-FIX-3',1,NULL,'','',49024,1794159050.7551901,1794161047.714493,1996.959,'result','READY_FOR_INTEGRATION',0,3.2789326999999995,1995526,24,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (18,'PROMOTIONS-INTEGRATION-4',1,NULL,'','',55956,1794161133.252192,1794162150.993196,1017.741,'result','PASSED',0,3.3533051,303738,22,NULL,NULL,NULL,NULL);
INSERT INTO epics (epic_id,repo,repo_slug,plan_path,plan_sha,spec_path,branch,base_sha,status,reason,round,final_pr_number,final_pr_url,config,created_at,updated_at) VALUES ('promotions','','piotrp321/shop','','',NULL,'epic/promotions','','DONE',NULL,4,301,NULL,'{}',1794085793.4267561,1794162226.862525);
