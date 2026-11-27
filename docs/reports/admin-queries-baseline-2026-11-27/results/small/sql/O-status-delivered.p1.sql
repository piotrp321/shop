-- orders with status delivered, page 1
-- cost variable: orders with the status = 4466 (scales with const)
SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, o.refund_amount, o.refund_due_at, o.refund_completed_at FROM ordering_order o WHERE o.status = 'delivered' ORDER BY o.placed_at DESC, o.id DESC LIMIT 26;
