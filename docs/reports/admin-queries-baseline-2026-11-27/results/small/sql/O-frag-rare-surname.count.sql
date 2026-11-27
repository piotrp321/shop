-- orders, fragment rare-surname 'różański', count
-- cost variable: hits = 1 (scales with orders)
SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('różański') || '%' LIMIT 10001) bounded;
