-- customers, fragment punct-4 'ab-cd', page 1
-- cost variable: hits = 0 (scales with customers)
SELECT c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at, lower(c.full_name) AS sort_name FROM ordering_customer c WHERE (lower(c.email) LIKE '%' || lower('ab-cd') || '%' OR lower(c.full_name) LIKE '%' || lower('ab-cd') || '%') ORDER BY c.last_order_at DESC, c.customer_id LIMIT 26;
