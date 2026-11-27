-- customers, fragment none 'qqzzxx', count by the bigrams
-- cost variable: hits = 0 (scales with customers)
SELECT count(*) FROM (SELECT 1 FROM ordering_customer c WHERE ((perf_bigrams(lower(c.email)) @> perf_bigrams(lower('qqzzxx')) AND lower(c.email) LIKE '%' || lower('qqzzxx') || '%') OR (perf_bigrams(lower(c.full_name)) @> perf_bigrams(lower('qqzzxx')) AND lower(c.full_name) LIKE '%' || lower('qqzzxx') || '%')) LIMIT 10001) bounded;
