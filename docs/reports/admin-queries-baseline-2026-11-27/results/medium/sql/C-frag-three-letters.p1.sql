-- customers, fragment three-letters 'ann', page 1
-- cost variable: hits = 5121 (scales with customers)
SELECT c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at, lower(c.full_name) AS sort_name FROM ordering_customer c WHERE (lower(c.email) LIKE '%' || lower('ann') || '%' OR lower(c.full_name) LIKE '%' || lower('ann') || '%') ORDER BY c.last_order_at DESC, c.customer_id LIMIT 26;
