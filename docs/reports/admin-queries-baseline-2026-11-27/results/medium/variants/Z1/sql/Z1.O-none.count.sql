-- orders, fragment none 'qqzzxx', count by the bigrams
-- cost variable: hits = 0 (scales with orders)
SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE perf_bigrams(lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name)) @> perf_bigrams(lower('qqzzxx')) AND lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('qqzzxx') || '%' LIMIT 10001) bounded;
