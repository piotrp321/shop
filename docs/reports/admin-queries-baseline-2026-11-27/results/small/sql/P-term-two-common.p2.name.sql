-- phrase two-common 'er', page 2, sort name
-- cost variable: hits = 559 (scales with products)
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%er%' AND (p.name, p.id) > ('Bidon sportowy Ravvimon Trawers 500', CAST('5c4b22ff-990e-5a92-bc18-68ae334ffc00' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
