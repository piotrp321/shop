-- customers, no filter, sort -orderCount, page 2
-- cost variable: page = 25 (scales with const)
SELECT c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at, lower(c.full_name) AS sort_name FROM ordering_customer c WHERE c.order_count <= CAST('7' AS int) AND (c.order_count < CAST('7' AS int) OR c.customer_id > '210a1041-f686-566e-a35a-342ed9563c98') ORDER BY c.order_count DESC, c.customer_id LIMIT 26;
