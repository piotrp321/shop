-- customers, fragment full-email 'klaudia.domagala.3985@example.net', count by the bigrams
-- cost variable: hits = 1 (scales with customers)
SELECT count(*) FROM (SELECT 1 FROM ordering_customer c WHERE ((perf_bigrams(lower(c.email)) @> perf_bigrams(lower('klaudia.domagala.3985@example.net')) AND lower(c.email) LIKE '%' || lower('klaudia.domagala.3985@example.net') || '%') OR (perf_bigrams(lower(c.full_name)) @> perf_bigrams(lower('klaudia.domagala.3985@example.net')) AND lower(c.full_name) LIKE '%' || lower('klaudia.domagala.3985@example.net') || '%')) LIMIT 10001) bounded;
