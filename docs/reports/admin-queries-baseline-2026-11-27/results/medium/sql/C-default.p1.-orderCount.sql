-- customers, no filter, sort -orderCount, page 1
-- cost variable: page = 25 (scales with const)
SELECT c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at, lower(c.full_name) AS sort_name FROM ordering_customer c ORDER BY c.order_count DESC, c.customer_id LIMIT 26;
