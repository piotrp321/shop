-- orders, fragment punct-4 'ab-cd', count by the bigrams
-- cost variable: hits = 0 (scales with orders)
SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE perf_bigrams(lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name)) @> perf_bigrams(lower('ab-cd')) AND lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('ab-cd') || '%' LIMIT 10001) bounded;
