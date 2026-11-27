-- customers, fragment full-email 'jan.piechocki.637@example.net', count
-- cost variable: hits = 1 (scales with customers)
SELECT count(*) FROM (SELECT 1 FROM ordering_customer c WHERE (lower(c.email) LIKE '%' || lower('jan.piechocki.637@example.net') || '%' OR lower(c.full_name) LIKE '%' || lower('jan.piechocki.637@example.net') || '%') LIMIT 10001) bounded;
