-- customers, fragment common-surname 'nowak', count
-- cost variable: hits = 14 (scales with customers)
SELECT count(*) FROM (SELECT 1 FROM ordering_customer c WHERE (lower(c.email) LIKE '%' || lower('nowak') || '%' OR lower(c.full_name) LIKE '%' || lower('nowak') || '%') LIMIT 10001) bounded;
