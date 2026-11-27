-- customers, fragment punct-3 'a.b', count
-- cost variable: hits = 3440 (scales with customers)
SELECT count(*) FROM (SELECT 1 FROM ordering_customer c WHERE (lower(c.email) LIKE '%' || lower('a.b') || '%' OR lower(c.full_name) LIKE '%' || lower('a.b') || '%') LIMIT 10001) bounded;
