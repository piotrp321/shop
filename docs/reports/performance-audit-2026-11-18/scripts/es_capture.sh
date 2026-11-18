#!/bin/sh
# es_capture.sh NAME PATH: captures the Elasticsearch body of one API request (slow log of the local index at 0 ms).
S="$(cd "$(dirname "$0")/.." && pwd)"
ES=shop-perfaudit-elasticsearch-1
docker exec $ES curl -s -XPUT 'localhost:9200/products/_settings' -H 'Content-Type: application/json' -d '{"index.search.slowlog.threshold.query.trace":"0ms"}' >/dev/null
sleep 1
docker exec shop-perfaudit-api-1 curl -s -o /dev/null -w "$1 %{http_code} %{time_total}s\n" "http://api-prod$2"
sleep 2
docker logs --since 15s $ES 2>&1 | grep 'index.search.slowlog.query' | tail -1 > "$S/results/es-slowlog-$1.txt"
docker exec $ES curl -s -XPUT 'localhost:9200/products/_settings' -H 'Content-Type: application/json' -d '{"index.search.slowlog.threshold.query.trace":null}' >/dev/null
python3 - "$S" "$1" <<'PY'
import json,sys
S,name=sys.argv[1:3]
line=open(f"{S}/results/es-slowlog-{name}.txt").read()
d=json.loads(line[line.index('{'):])
body=json.loads(d['elasticsearch.slowlog.source'].replace('\\"','"'))
json.dump(body,open(f"{S}/results/es-{name}.json","w"))
print(name,'took',d['elasticsearch.slowlog.took'],'hits',d['elasticsearch.slowlog.total_hits'],'query:',json.dumps(body['query'])[:400])
PY
