#!/bin/bash
# equivalence of the whole mix on 10M, cache behaviour, engine variants of plm
cd "$(dirname "$0")"
set -x
MIX=1 python3 check.py c10 h10 plm ../results/equivalence_mix_10m.json | tail -1
python3 check.py c10 h10 plm | tail -1
LABEL=10m-class2 python3 classes.py h10 plm | tail -12
python3 cachetest.py h10
# intra-shard parallel collection off
curl -s -XPUT localhost:19200/_cluster/settings -H 'content-type: application/json' -d '{"transient":{"search.query_phase_parallel_collection_enabled":false}}' >/dev/null
LABEL=10m-nopar python3 classes.py h10 plm | tail -1
LABEL=10m-nopar python3 bench.py conc h10 plm 30 1,8,100 mix
LABEL=10m-nopar python3 bench.py burst h10 plm 100
curl -s -XPUT localhost:19200/_cluster/settings -H 'content-type: application/json' -d '{"transient":{"search.query_phase_parallel_collection_enabled":null}}' >/dev/null
# 4 shards
SLICES=8 python3 mk.py h10s4 h 4 1 h10 | python3 wait.py
while [ "$(curl -s 'localhost:19200/_stats/merge?filter_path=_all.total.merges.current' | grep -o '[0-9]*')" != "0" ]; do sleep 5; done
LABEL=10m-s4 python3 classes.py h10s4 plm | tail -1
LABEL=10m-s4 python3 bench.py single h10s4 plm all_name,all_color,all_price,root_name,root_color,leaf_name
LABEL=10m-s4 python3 bench.py conc h10s4 plm 30 1,8,100 mix
LABEL=10m-s4 python3 bench.py burst h10s4 plm 100
curl -s -XDELETE localhost:19200/h10s4
# index sorted by the main category
SLICES=8 python3 mk.py h10sort h 1 1 h10 main_category_id | python3 wait.py
while [ "$(curl -s 'localhost:19200/_stats/merge?filter_path=_all.total.merges.current' | grep -o '[0-9]*')" != "0" ]; do sleep 5; done
LABEL=10m-sort python3 classes.py h10sort plm | tail -12
LABEL=10m-sort python3 bench.py conc h10sort plm 30 8 mix
curl -s -XDELETE localhost:19200/h10sort
echo BATCH2 DONE
