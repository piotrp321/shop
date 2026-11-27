-- orders with status delivered, count
-- cost variable: orders with the status = 891040 (scales with orders)
SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE o.status = 'delivered' LIMIT 10001) bounded;
