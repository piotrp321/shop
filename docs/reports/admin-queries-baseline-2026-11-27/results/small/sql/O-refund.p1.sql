-- orders, pending refunds sorted by due date
-- cost variable: pending refunds = 0 (scales with const)
SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, o.refund_amount, o.refund_due_at, o.refund_completed_at FROM ordering_order o WHERE o.refund_amount IS NOT NULL AND o.refund_completed_at IS NULL ORDER BY o.refund_due_at ASC, o.id ASC LIMIT 26;
