-- orders, fragment common-surname 'kaczmarek', count
-- cost variable: hits = 5368 (scales with orders)
SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('kaczmarek') || '%' LIMIT 10001) bounded;
