-- orders, fragment number-part '102', count
-- cost variable: hits = 1017 (scales with orders)
SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('102') || '%' LIMIT 10001) bounded;
