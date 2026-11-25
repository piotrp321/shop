# Elasticsearch with the Polish analysis plugin (stemmer `polish_stem`) used by the product search index. Compose
# builds this image and so does the deployment (ghcr.io/piotrp321/shop/elasticsearch): the plugin is installed here,
# at build time, never when a container starts. The base is pinned by the digest of its multi-arch index; the plugin
# installed by elasticsearch-plugin always has the version of this Elasticsearch.
FROM docker.elastic.co/elasticsearch/elasticsearch:9.5.7@sha256:8aa3b18f6a26880f4c2fa1ffb37a48213e201b0b6a1a47412c66b1f5d533de91
RUN elasticsearch-plugin install --batch analysis-stempel
