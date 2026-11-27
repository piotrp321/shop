-- category mid + drafts, count
-- cost variable: products in the subtree = 10585 (scales with products)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.primary_category_id = ANY('{c2a92470-bddd-5c3a-9b84-ed06bfd4caad}'::uuid[]) AND p.status = 'draft' UNION ALL SELECT 1 FROM catalog_product_category pc JOIN catalog_product p ON p.id = pc.product_id WHERE pc.category_id = ANY('{c2a92470-bddd-5c3a-9b84-ed06bfd4caad}'::uuid[]) AND NOT (p.primary_category_id = ANY('{c2a92470-bddd-5c3a-9b84-ed06bfd4caad}'::uuid[])) AND p.status = 'draft' LIMIT 10001) bounded;
