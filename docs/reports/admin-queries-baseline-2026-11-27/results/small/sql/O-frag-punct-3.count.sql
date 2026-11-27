-- orders, fragment punct-3 'a.b', count
-- cost variable: hits = 134 (scales with orders)
SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('a.b') || '%' LIMIT 10001) bounded;
