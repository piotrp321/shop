-- dashboard, the five last orders
-- cost variable: page = 5 (scales with const)
SELECT o.id, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) FROM ordering_order o ORDER BY o.placed_at DESC, o.id DESC LIMIT 6;
