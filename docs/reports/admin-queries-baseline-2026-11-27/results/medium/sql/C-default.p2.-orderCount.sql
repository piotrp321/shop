-- customers, no filter, sort -orderCount, page 2
-- cost variable: page = 25 (scales with const)
SELECT c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at, lower(c.full_name) AS sort_name FROM ordering_customer c WHERE c.order_count <= CAST('384' AS int) AND (c.order_count < CAST('384' AS int) OR c.customer_id > 'f85f2975-6878-5320-86d2-3db47ee94772') ORDER BY c.order_count DESC, c.customer_id LIMIT 26;
