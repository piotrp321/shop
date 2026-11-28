ES cause (2026-11-27, request_cache=false, 3 reps, quiet cluster, index products_20261126053552075_f3e89f 4 shards 9.6GB, 88.9M Lucene docs = 10.0M products + 30.1M variants + ~48.7M pairs):
home total=9,800,130: none 1.1-1.3s; +available 1.9-2.0; +price 2.1-2.2; +free 4.0-4.3; all 5.2-6.1 s
large dept 1.52M: none 0.25-0.66; +avail 0.9-1.2; +price 1.24; +free 1.55; all 1.9
leaf median 102,794: none 0.94-0.97; +avail 0.94-1.03; +price 1.03-1.16; +free 1.15-1.26; all 1.15-1.24 (these 'none' not cold-cache: repeated in-process? first leaf none 411ms, again 59ms)
device read during one query (fs io_stats): leaf none 1006 MiB (411 ms) / again 132 MiB (59 ms); leaf all 3059 MiB (1086 ms); home none 3436 MiB (1226 ms); home all 4122 MiB (5350 ms)
cgroup ES: limit 4294967296, usage 4290830336 (page cache full), os free 3 MiB; heap max 2048 MiB used 50%, GC young 653 / 6.3 s, old 0; search pool threads 13 queue 0 rejected 0; query_cache 156MB hit 14223 miss 626452 evictions 0; request_cache 3360B hit 0 miss 8; 29-30 segments/shard
profile home-all shard0 (took 7.7 s with profile): QueryPhaseCollector 6.3 s; available 1.1 s; price 1.3 s (min .72 max .60); free 3.8-5.7 s (pairs nested terms axes: collect 5.0 s, values 2.4 s, reverse_nested product .87+.92); query status term .46, match_all .3
GRAFANA (grafanacloud-prom, window 2026-11-25T19:40Z..2026-11-26T21:40Z, step 5m; avg/max; cpu cores, MB/s):
no ES/JVM/PG exporter metrics in Grafana (only container_*, node_cpu, node_filesystem, node_memory, kube_*); no node_disk_*; no cfs throttling series for ES
products: PG cpu 1.00/2.31 rd 113/191 wr 186/405; node iowait .14/.23; gen cpu .25/.80
customers: PG cpu .33/.97 rd 12/143 wr 22/173; node usr .50 (Keycloak import)
orders: PG cpu 1.25/1.38 rd 180/209 wr 39/49 (read-bound), node iowait .19
readmodels: PG cpu .57/.88 rd 58/79 wr 137/352
reindex 05:40-12:45: PG cpu .31/.33 rd 37/42 MB/s; ES cpu .14/.52 mem 3921MiB; gen cpu .11; node iowait .06 usr .11  => nothing saturated: serial loop
verify 13:00-19:35: PG cpu .29/.74 rd 67/157 MB/s; node iowait .09
bench1 20:43-21:13: ES cpu 2.92/3.20, mem 3512MiB, container disk read 1383/1573 MB/s; node iowait .05 usr .31
bench2 21:21-21:36: ES cpu .64/.97 rd 606/827 MB/s
