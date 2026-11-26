#!/bin/bash
# the recommended model on its own index (lean mapping, index sort by the category path) and the remaining measurements
cd "$(dirname "$0")"
merges() { while [ "$(curl -s 'localhost:19200/_stats/merge?filter_path=_all.total.merges.current' | grep -o '[0-9]*')" != "0" ]; do sleep 5; done; }
set -x
python3 mk.py r1 r 1 1 products sort_path | python3 wait.py
python3 check.py t_orig1 r1 plm | tail -1
MIX=1 python3 check.py t_orig1 r1 plm | tail -1
python3 mk.py r10 r 1 10 products sort_path | python3 wait.py > ../results/build_r10.json; cat ../results/build_r10.json
merges
python3 check.py c10 r10 plm ../results/equivalence_r10.json | tail -1
MIX=1 python3 check.py c10 r10 plm ../results/equivalence_mix_r10.json | tail -1
export LABEL=r10
python3 bench.py single r10 plm > /dev/null
python3 classes.py r10 plm | tail -1
python3 bench.py conc r10 plm 30 1,8,100 mix > /dev/null
python3 bench.py burst r10 plm 100 > /dev/null
python3 bench.py open r10 plm 15 60 > /dev/null
python3 bench.py open r10 plm 30 60 > /dev/null
python3 cachetest.py r10 > /dev/null
python3 refresh.py r10 plm all_name
python3 refresh.py c10 cur all_name
# capacity close to the server: 3 of the Mac cores
docker update --cpus 3 es-audit > /dev/null
export LABEL=r10-cpu3
python3 bench.py conc r10 plm 30 1,8,100 mix > /dev/null
python3 bench.py burst r10 plm 100 > /dev/null
python3 bench.py open r10 plm 5 60 > /dev/null
python3 bench.py open r10 plm 10 60 > /dev/null
LABEL=c10-cpu3 python3 bench.py conc c10 cur 30 8 mix > /dev/null
LABEL=c10-cpu3 python3 bench.py burst c10 cur 100 > /dev/null
docker update --cpus 8 es-audit > /dev/null
# indexing
python3 ingest.py c10 cur
python3 ingest.py r10 r
python3 ingest.py r10 r sort_path
# variant as a document (1M)
python3 mkv.py v1 products
merges
python3 vd.py v1 r1
# writes in parallel (50 rewrites of products per second), last because they change the indices
export LABEL=r10-w50-cold
python3 writes.py r10 50 45 & python3 bench.py conc r10 plm 30 8 mix > /dev/null; wait
LABEL=r10-tok-static CACHE=tok python3 bench.py conc r10 plm 30 8 mix > /dev/null
for iv in 1s 5s 30s; do
  curl -s -XPUT localhost:19200/r10/_settings -H 'content-type: application/json' -d "{\"index\":{\"refresh_interval\":\"$iv\"}}" > /dev/null
  python3 writes.py r10 50 75 & LABEL=r10-w50-tok-$iv CACHE=tok python3 bench.py conc r10 plm 60 8 mix > /dev/null; wait
  python3 writes.py r10 50 75 & LABEL=r10-w50-facets-$iv CACHE=facets python3 bench.py conc r10 plm 60 8 mix > /dev/null; wait
done
curl -s -XPUT localhost:19200/r10/_settings -H 'content-type: application/json' -d '{"index":{"refresh_interval":"1s"}}' > /dev/null
python3 writes.py c10 50 45 & LABEL=c10-w50-cold python3 bench.py conc c10 cur 30 8 mix > /dev/null; wait
echo BATCH3 DONE
