-- customers, fragment rare-surname 'domagała', count by the bigrams
-- cost variable: hits = 115 (scales with customers)
SELECT count(*) FROM (SELECT 1 FROM ordering_customer c WHERE ((perf_bigrams(lower(c.email)) @> perf_bigrams(lower('domagała')) AND lower(c.email) LIKE '%' || lower('domagała') || '%') OR (perf_bigrams(lower(c.full_name)) @> perf_bigrams(lower('domagała')) AND lower(c.full_name) LIKE '%' || lower('domagała') || '%')) LIMIT 10001) bounded;
