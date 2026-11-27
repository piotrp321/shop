-- dashboard, out of stock (limit 1000)
-- cost variable: bound = 1001 (scales with const)
SELECT count(*) FROM (SELECT 1 FROM inventory_stock_item WHERE physical - reserved = 0 AND product_id IS NOT NULL LIMIT 1001) bounded;
