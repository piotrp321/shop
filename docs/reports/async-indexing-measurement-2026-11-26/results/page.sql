WITH page AS (
    SELECT id, name, slug, short_description, primary_category_id, images, variant_axes, status
    FROM catalog_product
    WHERE id > CAST('7f000000-0000-4000-8000-000000000000' AS uuid)
    ORDER BY id
    LIMIT 500
)
SELECT p.id, p.name, p.slug, p.short_description, p.primary_category_id, p.images->0 AS main_image,
       main.path AS main_path, COALESCE(extra.ids, '[]') AS additional_ids, COALESCE(extra.paths, '[]') AS additional_paths,
       p.variant_axes, COALESCE(variants.list, '[]') AS variants, COALESCE(names.list, '[]') AS category_names, p.status
FROM page p
JOIN catalog_category main ON main.id = p.primary_category_id
LEFT JOIN LATERAL (
    SELECT json_agg(pc.category_id ORDER BY pc.category_id) AS ids, json_agg(c.path ORDER BY pc.category_id) AS paths
    FROM catalog_product_category pc
    JOIN catalog_category c ON c.id = pc.category_id
    WHERE pc.product_id = p.id
) extra ON true
LEFT JOIN LATERAL (
    SELECT json_agg(json_build_object('id', v.id, 'values', v.option_values) ORDER BY v.position) AS list
    FROM catalog_product_variant v
    WHERE v.product_id = p.id
) variants ON true
-- The names of the categories on the paths of the product's categories (each once), by primary key.
LEFT JOIN LATERAL (
    SELECT json_agg(a.name ORDER BY a.path) AS list
    FROM catalog_category a
    WHERE a.id IN (
        SELECT CAST(ancestor AS uuid)
        FROM catalog_category c
        CROSS JOIN LATERAL unnest(string_to_array(trim(BOTH '/' FROM c.path), '/')) AS ancestor
        WHERE c.id = p.primary_category_id
           OR c.id IN (SELECT pc.category_id FROM catalog_product_category pc WHERE pc.product_id = p.id)
    )
) names ON true
ORDER BY p.id