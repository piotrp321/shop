-- customers, fragment punct-4 'ab-cd', count
-- cost variable: hits = 0 (scales with customers)
SELECT count(*) FROM (SELECT 1 FROM ordering_customer c WHERE (lower(c.email) LIKE '%' || lower('ab-cd') || '%' OR lower(c.full_name) LIKE '%' || lower('ab-cd') || '%') LIMIT 10001) bounded;
