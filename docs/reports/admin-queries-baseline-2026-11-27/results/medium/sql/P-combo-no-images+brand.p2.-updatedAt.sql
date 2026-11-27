-- without images + brand, page 2, sort -updatedAt
-- cost variable: hits = 1239 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%rowerowa%' AND p.images = '[]'::jsonb AND (p.updated_at, p.id) < (CAST('2026-10-17 12:14:17+00' AS timestamptz), CAST('a7a7329d-82d8-5db9-a4af-c1fb64584e74' AS uuid)) ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
