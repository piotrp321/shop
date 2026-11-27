-- customers, fragment three-letters 'ann', page 1, sort name
-- cost variable: hits = 46 (scales with customers)
SELECT c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at, lower(c.full_name) AS sort_name FROM ordering_customer c WHERE (lower(c.email) LIKE '%' || lower('ann') || '%' OR lower(c.full_name) LIKE '%' || lower('ann') || '%') ORDER BY lower(c.full_name) ASC, c.customer_id LIMIT 26;
