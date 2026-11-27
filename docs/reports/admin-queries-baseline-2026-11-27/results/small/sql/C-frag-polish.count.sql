-- customers, fragment polish 'ąęś', count
-- cost variable: hits = 0 (scales with customers)
SELECT count(*) FROM (SELECT 1 FROM ordering_customer c WHERE (lower(c.email) LIKE '%' || lower('ąęś') || '%' OR lower(c.full_name) LIKE '%' || lower('ąęś') || '%') LIMIT 10001) bounded;
