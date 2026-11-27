-- customers, fragment domain 'example', count
-- cost variable: hits = 873 (scales with customers)
SELECT count(*) FROM (SELECT 1 FROM ordering_customer c WHERE (lower(c.email) LIKE '%' || lower('example') || '%' OR lower(c.full_name) LIKE '%' || lower('example') || '%') LIMIT 10001) bounded;
