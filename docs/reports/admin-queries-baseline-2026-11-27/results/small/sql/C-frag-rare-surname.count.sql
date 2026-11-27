-- customers, fragment rare-surname 'piechocki', count
-- cost variable: hits = 2 (scales with customers)
SELECT count(*) FROM (SELECT 1 FROM ordering_customer c WHERE (lower(c.email) LIKE '%' || lower('piechocki') || '%' OR lower(c.full_name) LIKE '%' || lower('piechocki') || '%') LIMIT 10001) bounded;
