-- customers, no filter, sort name, page 2
-- cost variable: page = 25 (scales with const)
SELECT c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at, lower(c.full_name) AS sort_name FROM ordering_customer c WHERE (lower(c.full_name), c.customer_id) > (CAST('adam augustyniak' AS text), 'd60b650f-6913-5117-b1b6-244b0ab3ba70') ORDER BY lower(c.full_name) ASC, c.customer_id LIMIT 26;
