-- orders, fragment punct-3 'a.b', count by the bigrams
-- cost variable: hits = 36835 (scales with orders)
SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE perf_bigrams(lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name)) @> perf_bigrams(lower('a.b')) AND lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('a.b') || '%' LIMIT 10001) bounded;
