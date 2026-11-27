-- customers, fragment common-surname 'mazurek', count by the bigrams
-- cost variable: hits = 234 (scales with customers)
SELECT count(*) FROM (SELECT 1 FROM ordering_customer c WHERE ((perf_bigrams(lower(c.email)) @> perf_bigrams(lower('mazurek')) AND lower(c.email) LIKE '%' || lower('mazurek') || '%') OR (perf_bigrams(lower(c.full_name)) @> perf_bigrams(lower('mazurek')) AND lower(c.full_name) LIKE '%' || lower('mazurek') || '%')) LIMIT 10001) bounded;
