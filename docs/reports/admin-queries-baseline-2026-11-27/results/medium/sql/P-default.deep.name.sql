-- all products, a page in the middle of the names
-- cost variable: page = 25 (scales with const)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE (p.name, p.id) > ('Licznik rowerowy Wolakos Połonina 450 Lite', CAST('f413dd19-6a3e-571a-968f-08e522d99835' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
