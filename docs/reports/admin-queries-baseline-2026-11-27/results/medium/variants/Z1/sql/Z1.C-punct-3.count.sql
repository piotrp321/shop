-- customers, fragment punct-3 'a.b', count by the bigrams
-- cost variable: hits = 3440 (scales with customers)
SELECT count(*) FROM (SELECT 1 FROM ordering_customer c WHERE ((perf_bigrams(lower(c.email)) @> perf_bigrams(lower('a.b')) AND lower(c.email) LIKE '%' || lower('a.b') || '%') OR (perf_bigrams(lower(c.full_name)) @> perf_bigrams(lower('a.b')) AND lower(c.full_name) LIKE '%' || lower('a.b') || '%')) LIMIT 10001) bounded;
