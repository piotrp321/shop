-- customers, no filter, sort -paidGross, page 2
-- cost variable: page = 25 (scales with const)
SELECT c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at, lower(c.full_name) AS sort_name FROM ordering_customer c WHERE c.paid_gross <= CAST('710988' AS bigint) AND (c.paid_gross < CAST('710988' AS bigint) OR c.customer_id > 'fd494a10-e7ef-5326-848f-654a9d5168e5') ORDER BY c.paid_gross DESC, c.customer_id LIMIT 26;
