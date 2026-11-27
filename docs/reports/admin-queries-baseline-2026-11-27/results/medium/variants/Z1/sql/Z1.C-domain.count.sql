-- customers, fragment domain 'example', count by the bigrams
-- cost variable: hits = 98570 (scales with customers)
SELECT count(*) FROM (SELECT 1 FROM ordering_customer c WHERE ((perf_bigrams(lower(c.email)) @> perf_bigrams(lower('example')) AND lower(c.email) LIKE '%' || lower('example') || '%') OR (perf_bigrams(lower(c.full_name)) @> perf_bigrams(lower('example')) AND lower(c.full_name) LIKE '%' || lower('example') || '%')) LIMIT 10001) bounded;
