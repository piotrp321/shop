-- customers, fragment punct-3 'a.b', page 1
-- cost variable: hits = 35 (scales with customers)
SELECT c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at, lower(c.full_name) AS sort_name FROM ordering_customer c WHERE (lower(c.email) LIKE '%' || lower('a.b') || '%' OR lower(c.full_name) LIKE '%' || lower('a.b') || '%') ORDER BY c.last_order_at DESC, c.customer_id LIMIT 26;
