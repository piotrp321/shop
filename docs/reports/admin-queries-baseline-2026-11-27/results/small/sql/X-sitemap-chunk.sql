-- sitemap chunk (a range of the key, limit 50001)
-- cost variable: chunk = 25 (scales with const)
SELECT slug, status, updated_at FROM catalog_product WHERE id >= CAST('42304c70-29a4-5c1d-bc4b-ae8f0b695d6b' AS uuid) AND id < CAST('42445927-4a14-5f1f-a174-9fd97cadf196' AS uuid) ORDER BY id LIMIT 50001;
