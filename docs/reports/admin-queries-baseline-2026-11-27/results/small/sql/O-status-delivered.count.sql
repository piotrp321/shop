-- orders with status delivered, count
-- cost variable: orders with the status = 4466 (scales with const)
SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE o.status = 'delivered' LIMIT 10001) bounded;
