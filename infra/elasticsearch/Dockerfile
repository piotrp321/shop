# Elasticsearch with the Polish analysis plugin (stemmer `polish_stem`) used by the product search index. Compose
# builds this image and so does the deployment (ghcr.io/piotrp321/shop/elasticsearch): the plugin is installed here,
# at build time, never when a container starts. The base is pinned by the digest of its multi-arch index; the plugin
# installed by elasticsearch-plugin always has the version of this Elasticsearch.
FROM docker.elastic.co/elasticsearch/elasticsearch:9.4.0@sha256:754c3d8ca6f74acba58eb0610b8479a13c36df09ac1a4e0f2667dc167d377b84
RUN elasticsearch-plugin install --batch analysis-stempel
