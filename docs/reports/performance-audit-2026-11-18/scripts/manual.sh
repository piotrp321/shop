#!/bin/bash
# One-off readings of the audit stack, written as Markdown to stdout.
pg() { docker exec shop-perfaudit-postgres-1 psql -U postgres -d shop "$@"; }
echo "# Odczyty jednorazowe ($(date '+%Y-%m-%d %H:%M'))"; echo
echo "Stan po wszystkich testach, chyba że zaznaczono inaczej."; echo
echo '## Postgres: ustawienia'; echo '```'
pg -c "select name, setting, unit, source from pg_settings where name in ('shared_buffers','work_mem','max_connections','effective_cache_size','random_page_cost','maintenance_work_mem','max_wal_size','statement_timeout','lock_timeout','idle_in_transaction_session_timeout','io_method','jit')"
echo '```'; echo '## Postgres: rozmiary tabel'; echo '```'
pg -c "select relname, n_live_tup, pg_size_pretty(pg_table_size(relid)) tbl, pg_size_pretty(pg_indexes_size(relid)) idx, pg_size_pretty(pg_total_relation_size(relid)) total from pg_stat_user_tables order by pg_total_relation_size(relid) desc limit 14" -c "select pg_size_pretty(pg_database_size('shop')) shop"
echo '```'; echo '## Postgres: aktualizacje HOT, sesje, bufor, WAL, indeksy zamówień, połączenia'; echo '```'
pg -c "select relname, n_tup_upd upd, n_tup_hot_upd hot, case when n_tup_upd>0 then round(100.0*n_tup_hot_upd/n_tup_upd) end hot_pct from pg_stat_user_tables where n_tup_upd > 1000 order by n_tup_upd desc" -c "select sessions, xact_commit, round(100.0*blks_hit/(blks_hit+blks_read),1) hit_pct, deadlocks from pg_stat_database where datname='shop'" -c "select pg_size_pretty(wal_bytes) wal from pg_stat_wal" -c "select indexrelname, pg_size_pretty(pg_relation_size(indexrelid)) size from pg_stat_user_indexes where relname='ordering_order' order by pg_relation_size(indexrelid) desc" -c "select usename, count(*) connections from pg_stat_activity where datname='shop' group by 1"
echo '(w sesji, po testach zamówień i przed wariantem trwałego połączenia: sessions=168827, xact_commit=2051507, hit_pct=90.5, wal=36 GB; inventory_stock_item upd=42118 hot=0)'
echo '```'; echo '## Pamięć kontenerów: bieżąca i szczytowa (cgroup memory.current, memory.peak; bajty)'; echo '```'
for c in api-prod api-prodw api-prodp worker-1 store-1 varnish-1 redis-1 keycloak-1; do echo "$c $(docker exec shop-perfaudit-$c sh -c 'cat /sys/fs/cgroup/memory.current; cat /sys/fs/cgroup/memory.peak 2>/dev/null' | tr '\n' ' ')"; done
echo "(store: szczyt obejmuje next build przy starcie kontenera; w sesji po testach trybów: api-prod peak 197931008, api-prodw peak 270323712)"
echo '```'; echo '## FrankenPHP: liczba wątków (log startu)'; echo '```'
docker logs shop-perfaudit-api-prod 2>&1 | grep -o '"total_threads":[0-9]*' | head -1
docker logs shop-perfaudit-api-prodw 2>&1 | grep -o '"total_threads":[0-9]*,"worker_threads":[0-9]*' | head -1
echo '```'; echo '## Varnish (varnishstat -1)'; echo '```'
docker exec shop-perfaudit-varnish-1 varnishstat -1 | grep -E "MAIN.cache_hit |MAIN.cache_miss |MAIN.n_object |MAIN.n_lru_nuked|SMA.s0.g_bytes|SMA.s0.g_space|MAIN.client_req "
echo "(po pierwszym przebiegu do nasycenia: client_req=49444 cache_hit=42504 cache_miss=4935 n_object=2956)"
echo '```'; echo '## Sklep: rozmiary HTML (curl bez kompresji; gzip -c)'; echo '```'
for u in "/" "/c/bieganie" "/cart"; do docker exec shop-perfaudit-api-1 sh -c "curl -s 'http://store:3000$u' -o /tmp/p.html; echo \"$u raw=\$(wc -c < /tmp/p.html) gzip=\$(gzip -c /tmp/p.html | wc -c) odnosniki_kategorii=\$(grep -o '/c/' /tmp/p.html | wc -l)\""; done
echo "(strona produktu, w sesji: raw=149397 gzip=25192, odnośników /c/: 389)"
echo '```'; echo '## Sklep: wpisy ISR na dysku (.next/server/route-cache)'; echo '```'
docker exec shop-perfaudit-store-1 sh -c 'cd .next/server/route-cache; echo "wpisy html: $(find . -name "*.html" | wc -l), w tym testowe 404: $(find . -name "nie-ma-takiego*.html" | wc -l); razem KB: $(du -sk . | cut -f1)"; e=$(find . -name "*.html" ! -name "nie-ma*" | head -1); b=${e%.html}; for f in "$b.html" "$b.rsc"; do echo "$(wc -c < "$f") bajtów ${f##*.}"; done; echo "segmenty KB: $(du -sk "$b.segments" | cut -f1)"'
echo "(w sesji, przed testem 404: 302 wpisy = 79276 KB, czyli 262 KB na wpis; 200 losowych adresów /p/... dodało 200 wpisów i 28000 KB)"
echo '```'; echo '## Bundle'; echo '```'
docker exec shop-perfaudit-store-1 sh -c 'cd .next; find static/chunks -name "*.js" -type f -exec ls -l {} + | awk "{s+=\$5; n++} END {print \"sklep: \" n \" plików JS, \" s/1024 \" KB przed kompresją\"}"'
echo "panel (npx vite build w kontenerze admin): index-*.js 970.02 kB, gzip 294.39 kB; index-*.css 73.58 kB, gzip 14.52 kB"
echo '```'; echo '## Worker: tempo i CPU (odczyty z sesji)'; echo '```'
echo "zdarzenia zamówień: kolejka events mniejsza o 351 w ok. 20 s; CPU kontenera worker w tym oknie 746 ms (cpu.stat usage_usec)"
echo "pg_stat_statements w tym oknie: po 364 wywołania pięciu zapytań obsługi zdarzenia, razem ok. 0,45 ms na zdarzenie"
echo "kolejka events po pomiarze tempa została opróżniona ręcznie (rabbitmqctl purge_queue events), stąd skok do 0 w drain-after-checkout.txt"
echo '```'
