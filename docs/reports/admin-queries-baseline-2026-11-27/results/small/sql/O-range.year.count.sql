-- orders, delivered over the whole range, count
-- cost variable: page = 10000 (scales with const)
SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE o.status = 'delivered' AND o.placed_at >= '2023-11-28' AND o.placed_at < '2026-11-26' LIMIT 10001) bounded;
