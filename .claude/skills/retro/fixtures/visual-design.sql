-- Real AO AO schema and the rows /retro reads from the run of visual-design (paths and payloads blanked).
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
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('VISUAL-DESIGN-1','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794223198.571094,1794226104.042339,'visual-design','[]','plan','new',NULL,'epic/visual-design',348,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('VISUAL-DESIGN-2','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794223198.5712,1794227767.720692,'visual-design','[]','plan','new',NULL,'epic/visual-design',350,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('VISUAL-DESIGN-3','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794223198.571222,1794229493.023067,'visual-design','[]','plan','new',NULL,'epic/visual-design',352,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('VISUAL-DESIGN-4','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794223198.571244,1794237791.684816,'visual-design','["VISUAL-DESIGN-1"]','plan','new',NULL,'epic/visual-design',355,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('VISUAL-DESIGN-5','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794223198.571281,1794242890.659009,'visual-design','["VISUAL-DESIGN-4", "VISUAL-DESIGN-2"]','plan','new',NULL,'epic/visual-design',356,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('VISUAL-DESIGN-6','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794223198.5713,1794246992.4144251,'visual-design','["VISUAL-DESIGN-5"]','plan','new',NULL,'epic/visual-design',358,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('VISUAL-DESIGN-7','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794223198.571319,1794254595.308699,'visual-design','["VISUAL-DESIGN-6"]','plan','new',NULL,'epic/visual-design',361,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('VISUAL-DESIGN-8','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794223198.571338,1794250968.607244,'visual-design','["VISUAL-DESIGN-5", "VISUAL-DESIGN-3"]','plan','new',NULL,'epic/visual-design',359,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('VISUAL-DESIGN-9','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794223198.57136,1794256457.689913,'visual-design','["VISUAL-DESIGN-5"]','plan','new',NULL,'epic/visual-design',362,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('VISUAL-DESIGN-10','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794223198.571455,1794230078.602776,'visual-design','[]','plan','new',NULL,'epic/visual-design',353,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('VISUAL-DESIGN-INTEGRATION-1','','','','','FINDINGS',NULL,0,0,NULL,NULL,'[]',1794256463.452232,1794261946.730129,'visual-design','[]','integration','new',NULL,'epic/visual-design',NULL,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('VISUAL-DESIGN-FIX-1','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794261948.420553,1794265595.249164,'visual-design','[]','fix','new',NULL,'epic/visual-design',363,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('VISUAL-DESIGN-INTEGRATION-2','','','','','FINDINGS',NULL,0,0,NULL,NULL,'[]',1794265601.558052,1794267663.901338,'visual-design','[]','integration','new',NULL,'epic/visual-design',NULL,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('VISUAL-DESIGN-FIX-2','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794267665.622057,1794270147.083052,'visual-design','[]','fix','new',NULL,'epic/visual-design',364,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('VISUAL-DESIGN-INTEGRATION-3','','','','','PASSED',NULL,0,0,NULL,NULL,'[]',1794270152.7332559,1794271981.250656,'visual-design','[]','integration','new',NULL,'epic/visual-design',NULL,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('VISUAL-DESIGN-FINALIZE','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794271982.8995042,1794272495.2846851,'visual-design','[]','finalize','new',NULL,'epic/visual-design',365,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('VISUAL-DESIGN-INTEGRATION-4','','','','','PASSED',NULL,0,0,NULL,NULL,'[]',1794272533.897799,1794275241.647331,'visual-design','[]','integration','new',NULL,'epic/visual-design',NULL,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('VISUAL-DESIGN-REPAIR-1','','','','','MERGED',NULL,0,0,NULL,NULL,'[]',1794275315.201455,1794276253.9614542,'visual-design','[]','repair','new',NULL,'epic/visual-design',367,NULL,NULL,NULL,NULL,0);
INSERT INTO tasks (task_id,spec_path,base_sha,workdir,state_file,status,reason,failures,limit_hits,not_before,blockers,answers,created_at,updated_at,epic_id,depends_on,kind,mode,brief_path,target_branch,pr_number,pr_url,head_sha,merged_sha,ci,fix_count) VALUES ('VISUAL-DESIGN-INTEGRATION-5','','','','','PASSED',NULL,0,0,NULL,NULL,'[]',1794276282.6776881,1794276993.5304,'visual-design','[]','integration','new',NULL,'epic/visual-design',NULL,NULL,NULL,NULL,NULL,0);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (1,'VISUAL-DESIGN-1',1,NULL,'','',29951,1794223287.608796,1794226081.8696918,2794.261,'result','READY_FOR_INTEGRATION',0,12.965865599999997,2691853,47,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (2,'VISUAL-DESIGN-2',1,NULL,'','',49649,1794226170.667351,1794227749.3131561,1578.646,'result','READY_FOR_INTEGRATION',0,2.083557799999999,962563,38,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (3,'VISUAL-DESIGN-3',1,NULL,'','',56664,1794227832.037119,1794229474.542333,1642.505,'result','READY_FOR_INTEGRATION',0,3.340353600000001,1641599,33,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (4,'VISUAL-DESIGN-4',1,NULL,'','',64380,1794229556.329089,1794229656.676238,100.347,'result','BLOCKED',0,0.888196,99636,13,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (5,'VISUAL-DESIGN-10',1,NULL,'','',65412,1794229724.202035,1794230058.3046882,334.103,'result','READY_FOR_INTEGRATION',0,1.5245343000000005,333373,22,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (6,'VISUAL-DESIGN-4',2,NULL,'','',79779,1794234392.2026849,1794236077.95525,1685.753,'result','BLOCKED',0,10.041812300000004,1684097,96,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (7,'VISUAL-DESIGN-4',3,NULL,'','',88429,1794236397.048109,1794237770.5101671,1373.462,'result','READY_FOR_INTEGRATION',0,6.817487300000003,1372708,35,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (8,'VISUAL-DESIGN-5',1,NULL,'','',94237,1794237857.694886,1794242085.885292,4228.19,'policy_blocked','READY_FOR_INTEGRATION',0,10.925155900000004,4227438,39,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (9,'VISUAL-DESIGN-5',2,NULL,'','',13372,1794242766.797112,1794242871.8568819,105.06,'result','READY_FOR_INTEGRATION',0,0.8981806000000001,104235,8,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (10,'VISUAL-DESIGN-6',1,NULL,'','',14668,1794242955.522909,1794246972.533876,4017.011,'result','READY_FOR_INTEGRATION',0,10.921993800000001,4016194,49,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (11,'VISUAL-DESIGN-8',1,NULL,'','',30146,1794247057.4969091,1794250946.9309819,3889.434,'result','READY_FOR_INTEGRATION',0,9.702839499999998,3888527,40,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (12,'VISUAL-DESIGN-7',1,NULL,'','',45926,1794251033.119704,1794254575.042888,3541.923,'result','READY_FOR_INTEGRATION',0,8.047999299999999,3541043,35,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (13,'VISUAL-DESIGN-9',1,NULL,'','',64349,1794254657.258788,1794256438.278074,1781.019,'result','READY_FOR_INTEGRATION',0,3.455462400000001,1780161,30,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (14,'VISUAL-DESIGN-INTEGRATION-1',1,NULL,'','',71301,1794256523.83661,1794259996.00818,3472.172,'result','BLOCKED',0,9.5242323,931284,32,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (15,'VISUAL-DESIGN-INTEGRATION-1',2,NULL,'','',88198,1794261079.834521,1794261940.9276779,861.093,'result','FINDINGS',0,1.8848933999999997,859956,24,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (16,'VISUAL-DESIGN-FIX-1',1,NULL,'','',92053,1794262010.631132,1794265574.027648,3563.397,'result','READY_FOR_INTEGRATION',0,8.7347348,3562676,46,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (17,'VISUAL-DESIGN-INTEGRATION-2',1,NULL,'','',5674,1794265660.933901,1794267659.1479738,1998.214,'result','FINDINGS',0,5.3923041000000005,827593,26,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (18,'VISUAL-DESIGN-FIX-2',1,NULL,'','',14261,1794267727.0685859,1794270128.723671,2401.655,'result','READY_FOR_INTEGRATION',0,3.251458499999999,2400798,25,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (19,'VISUAL-DESIGN-INTEGRATION-3',1,NULL,'','',21863,1794270211.744323,1794271975.851414,1764.107,'result','PASSED',0,3.638193000000001,791872,30,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (20,'VISUAL-DESIGN-FINALIZE',1,NULL,'','',29438,1794272050.6243231,1794272476.150393,425.526,'result','READY_FOR_INTEGRATION',0,2.5909206000000005,173370,26,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (21,'VISUAL-DESIGN-INTEGRATION-4',1,NULL,'','',32750,1794272594.722385,1794275236.251893,2641.53,'result','PASSED',0,7.2406121000000025,885749,29,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (22,'VISUAL-DESIGN-REPAIR-1',1,NULL,'','',43878,1794275382.247608,1794276235.023673,852.776,'result','READY_FOR_INTEGRATION',0,1.0080276000000001,851917,22,NULL,NULL,NULL,NULL);
INSERT INTO attempts (id,task_id,number,active,dir,command,pid,started_at,ended_at,wall_s,outcome,executor_status,exit_code,cost_usd,duration_ms,num_turns,session_id,error,warnings,result) VALUES (23,'VISUAL-DESIGN-INTEGRATION-5',1,NULL,'','',50159,1794276345.125258,1794276988.9589,643.834,'result','PASSED',0,1.197623,643108,20,NULL,NULL,NULL,NULL);
INSERT INTO epics (epic_id,repo,repo_slug,plan_path,plan_sha,spec_path,branch,base_sha,status,reason,round,final_pr_number,final_pr_url,config,created_at,updated_at) VALUES ('visual-design','','piotrp321/shop','','',NULL,'epic/visual-design','','DONE',NULL,5,366,NULL,'{}',1794223198.570775,1794277000.991105);
