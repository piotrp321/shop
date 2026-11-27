-- customers, no filter, sort -paidGross, page 2
-- cost variable: page = 25 (scales with const)
SELECT c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at, lower(c.full_name) AS sort_name FROM ordering_customer c WHERE c.paid_gross <= CAST('20774682' AS bigint) AND (c.paid_gross < CAST('20774682' AS bigint) OR c.customer_id > 'e5d7e6d9-ba6c-5a5e-91ae-e6cad53dfe1e') ORDER BY c.paid_gross DESC, c.customer_id LIMIT 26;
