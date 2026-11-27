-- orders, fragment punct-1 '#!#', page 1
-- cost variable: hits = 0 (scales with orders)
SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, o.refund_amount, o.refund_due_at, o.refund_completed_at FROM ordering_order o WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('#!#') || '%' ORDER BY o.placed_at DESC, o.id DESC LIMIT 26;
