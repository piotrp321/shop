-- orders, fragment number-part '909', count by the bigrams
-- cost variable: hits = 5394 (scales with orders)
SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE perf_bigrams(lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name)) @> perf_bigrams(lower('909')) AND lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('909') || '%' LIMIT 10001) bounded;
