FROM perf4-api-prod:local
ARG PERSISTENT=0
COPY bench.yaml /app/config/packages/prod/zz_bench.yaml
COPY persistent.yaml /tmp/persistent.yaml
RUN if [ "$PERSISTENT" = 1 ]; then cp /tmp/persistent.yaml /app/config/packages/prod/zz_persistent.yaml; fi \
 && rm -rf var/cache/prod && SHOP_DB_HOST=warmup SHOP_DB_NAME=warmup SHOP_DB_USER=warmup SHOP_DB_PASSWORD=warmup bin/console cache:warmup --env=prod \
 && chown -R www-data:www-data var 2>/dev/null; ls /app/config/packages/prod/
