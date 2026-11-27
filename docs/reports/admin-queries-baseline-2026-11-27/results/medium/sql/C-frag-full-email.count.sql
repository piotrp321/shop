-- customers, fragment full-email 'klaudia.domagala.3985@example.net', count
-- cost variable: hits = 1 (scales with customers)
SELECT count(*) FROM (SELECT 1 FROM ordering_customer c WHERE (lower(c.email) LIKE '%' || lower('klaudia.domagala.3985@example.net') || '%' OR lower(c.full_name) LIKE '%' || lower('klaudia.domagala.3985@example.net') || '%') LIMIT 10001) bounded;
