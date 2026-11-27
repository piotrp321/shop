-- stock list, low-stock filter count
-- cost variable: bound = 10000 (scales with const)
SELECT count(*) FROM (SELECT 1 FROM inventory_stock_item WHERE physical - reserved BETWEEN 1 AND 5 AND product_id IS NOT NULL GROUP BY product_id LIMIT 10001) bounded;
