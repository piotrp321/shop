-- customers, fragment three-letters 'ann', count by the bigrams
-- cost variable: hits = 5121 (scales with customers)
SELECT count(*) FROM (SELECT 1 FROM ordering_customer c WHERE ((perf_bigrams(lower(c.email)) @> perf_bigrams(lower('ann')) AND lower(c.email) LIKE '%' || lower('ann') || '%') OR (perf_bigrams(lower(c.full_name)) @> perf_bigrams(lower('ann')) AND lower(c.full_name) LIKE '%' || lower('ann') || '%')) LIMIT 10001) bounded;
