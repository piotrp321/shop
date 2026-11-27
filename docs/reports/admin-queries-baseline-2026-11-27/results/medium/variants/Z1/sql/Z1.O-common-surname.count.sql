-- orders, fragment common-surname 'kaczmarek', count by the bigrams
-- cost variable: hits = 5368 (scales with orders)
SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE perf_bigrams(lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name)) @> perf_bigrams(lower('kaczmarek')) AND lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('kaczmarek') || '%' LIMIT 10001) bounded;
