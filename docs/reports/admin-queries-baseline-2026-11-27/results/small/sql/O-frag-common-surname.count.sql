-- orders, fragment common-surname 'krajewski', count
-- cost variable: hits = 206 (scales with orders)
SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('krajewski') || '%' LIMIT 10001) bounded;
