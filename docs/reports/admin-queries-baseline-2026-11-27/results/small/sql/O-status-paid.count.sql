-- orders with status paid, count
-- cost variable: orders with the status = 14 (scales with const)
SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE o.status = 'paid' LIMIT 10001) bounded;
