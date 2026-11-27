-- orders, fragment three-letters 'ann', page 1, sort -total
-- cost variable: hits = 195 (scales with orders)
SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, o.refund_amount, o.refund_due_at, o.refund_completed_at FROM ordering_order o WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('ann') || '%' ORDER BY o.total_gross DESC, o.id DESC LIMIT 26;
