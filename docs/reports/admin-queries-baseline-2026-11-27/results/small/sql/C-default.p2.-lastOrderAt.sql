-- customers, no filter, sort -lastOrderAt, page 2
-- cost variable: page = 25 (scales with const)
SELECT c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at, lower(c.full_name) AS sort_name FROM ordering_customer c WHERE c.last_order_at <= CAST('2026-11-22 21:30:15+00' AS timestamptz) AND (c.last_order_at < CAST('2026-11-22 21:30:15+00' AS timestamptz) OR c.customer_id > '948b7847-648c-56c4-9051-fca86e9b8303') ORDER BY c.last_order_at DESC, c.customer_id LIMIT 26;
