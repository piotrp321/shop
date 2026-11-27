-- orders, a page in the middle of the dates
-- cost variable: page = 25 (scales with const)
SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, o.refund_amount, o.refund_due_at, o.refund_completed_at FROM ordering_order o WHERE (o.placed_at, o.id) < (CAST('2025-10-01 17:19:11+00' AS timestamptz), CAST('0199a0c8-fe98-7b7b-a09f-5743b81d1b0c' AS uuid)) ORDER BY o.placed_at DESC, o.id DESC LIMIT 26;
