-- orders, fragment rare-surname 'kopczyński', count by the bigrams
-- cost variable: hits = 301 (scales with orders)
SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE perf_bigrams(lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name)) @> perf_bigrams(lower('kopczyński')) AND lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('kopczyński') || '%' LIMIT 10001) bounded;
