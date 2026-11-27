-- customers, fragment punct-1 '#!#', count
-- cost variable: hits = 0 (scales with customers)
SELECT count(*) FROM (SELECT 1 FROM ordering_customer c WHERE (lower(c.email) LIKE '%' || lower('#!#') || '%' OR lower(c.full_name) LIKE '%' || lower('#!#') || '%') LIMIT 10001) bounded;
