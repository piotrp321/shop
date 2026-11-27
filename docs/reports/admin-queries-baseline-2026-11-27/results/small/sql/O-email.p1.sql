-- orders, full e-mail (exact), page 1
-- cost variable: page = 1 (scales with const)
SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, o.refund_amount, o.refund_due_at, o.refund_completed_at FROM ordering_order o WHERE lower(o.email) = lower('wiktor.rozanski.g102076@example.org') ORDER BY o.placed_at DESC, o.id DESC LIMIT 26;
