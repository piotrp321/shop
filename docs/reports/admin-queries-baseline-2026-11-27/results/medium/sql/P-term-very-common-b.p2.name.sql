-- phrase very-common-b 'ka', page 2, sort name
-- cost variable: hits = 459399 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%ka%' AND (p.name, p.id) > ('Atlas szlaków Calvavon Kamień 500', CAST('e6393e22-ae9c-583f-91da-999cf475a475' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
