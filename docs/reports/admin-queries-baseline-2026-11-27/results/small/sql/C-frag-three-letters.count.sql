-- customers, fragment three-letters 'ann', count
-- cost variable: hits = 46 (scales with customers)
SELECT count(*) FROM (SELECT 1 FROM ordering_customer c WHERE (lower(c.email) LIKE '%' || lower('ann') || '%' OR lower(c.full_name) LIKE '%' || lower('ann') || '%') LIMIT 10001) bounded;
