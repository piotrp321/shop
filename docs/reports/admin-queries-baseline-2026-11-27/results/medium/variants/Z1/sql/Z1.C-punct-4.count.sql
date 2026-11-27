-- customers, fragment punct-4 'ab-cd', count by the bigrams
-- cost variable: hits = 0 (scales with customers)
SELECT count(*) FROM (SELECT 1 FROM ordering_customer c WHERE ((perf_bigrams(lower(c.email)) @> perf_bigrams(lower('ab-cd')) AND lower(c.email) LIKE '%' || lower('ab-cd') || '%') OR (perf_bigrams(lower(c.full_name)) @> perf_bigrams(lower('ab-cd')) AND lower(c.full_name) LIKE '%' || lower('ab-cd') || '%')) LIMIT 10001) bounded;
