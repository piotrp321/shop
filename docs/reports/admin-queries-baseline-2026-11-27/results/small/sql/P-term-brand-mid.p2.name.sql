-- phrase brand-mid 'treningowe', page 2, sort name
-- cost variable: hits = 35 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%treningowe%' AND (p.name, p.id) > ('Rękawice treningowe Olgon Szlak 700', CAST('d34656b6-b039-506b-9b8b-d90cfad1ea5c' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
