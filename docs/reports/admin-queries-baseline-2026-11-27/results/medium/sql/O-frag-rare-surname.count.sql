-- orders, fragment rare-surname 'kopczyński', count
-- cost variable: hits = 301 (scales with orders)
SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('kopczyński') || '%' LIMIT 10001) bounded;
