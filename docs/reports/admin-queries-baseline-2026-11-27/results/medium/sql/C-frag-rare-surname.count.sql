-- customers, fragment rare-surname 'domagała', count
-- cost variable: hits = 115 (scales with customers)
SELECT count(*) FROM (SELECT 1 FROM ordering_customer c WHERE (lower(c.email) LIKE '%' || lower('domagała') || '%' OR lower(c.full_name) LIKE '%' || lower('domagała') || '%') LIMIT 10001) bounded;
