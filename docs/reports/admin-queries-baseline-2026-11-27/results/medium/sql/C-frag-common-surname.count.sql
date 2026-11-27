-- customers, fragment common-surname 'mazurek', count
-- cost variable: hits = 234 (scales with customers)
SELECT count(*) FROM (SELECT 1 FROM ordering_customer c WHERE (lower(c.email) LIKE '%' || lower('mazurek') || '%' OR lower(c.full_name) LIKE '%' || lower('mazurek') || '%') LIMIT 10001) bounded;
