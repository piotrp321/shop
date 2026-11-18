# Odczyty jednorazowe (2026-11-18 19:09)

Stan po wszystkich testach, chyba że zaznaczono inaczej.

## Postgres: ustawienia
```
                name                 | setting | unit |       source       
-------------------------------------+---------+------+--------------------
 effective_cache_size                | 524288  | 8kB  | default
 idle_in_transaction_session_timeout | 0       | ms   | default
 io_method                           | worker  |      | default
 jit                                 | on      |      | default
 lock_timeout                        | 0       | ms   | default
 maintenance_work_mem                | 65536   | kB   | default
 max_connections                     | 100     |      | configuration file
 max_wal_size                        | 1024    | MB   | configuration file
 random_page_cost                    | 4       |      | default
 shared_buffers                      | 16384   | 8kB  | configuration file
 statement_timeout                   | 0       | ms   | default
 work_mem                            | 4096    | kB   | default
(12 rows)

```
## Postgres: rozmiary tabel
```
           relname            | n_live_tup |  tbl   |   idx   |  total  
------------------------------+------------+--------+---------+---------
 catalog_product              |    1001258 | 980 MB | 604 MB  | 1584 MB
 ordering_order               |    1031093 | 428 MB | 770 MB  | 1198 MB
 inventory_stock_movement     |    3295180 | 492 MB | 604 MB  | 1095 MB
 pricing_price_history        |    3912906 | 479 MB | 436 MB  | 915 MB
 ordering_order_line          |    1937702 | 413 MB | 408 MB  | 821 MB
 catalog_product_variant      |    3009455 | 299 MB | 472 MB  | 771 MB
 ordering_order_status_change |    3978397 | 441 MB | 324 MB  | 765 MB
 payment_payment              |     960188 | 221 MB | 186 MB  | 407 MB
 pricing_variant_price        |    3009318 | 268 MB | 120 MB  | 387 MB
 inventory_stock_item         |    3009350 | 244 MB | 140 MB  | 385 MB
 ordering_customer            |      98544 | 28 MB  | 105 MB  | 133 MB
 messenger_messages           |      14762 | 61 MB  | 6064 kB | 67 MB
 inventory_reservation_line   |     268363 | 24 MB  | 30 MB   | 54 MB
 catalog_product_category     |     200192 | 12 MB  | 22 MB   | 34 MB
(14 rows)

  shop   
---------
 8712 MB
(1 row)

```
## Postgres: aktualizacje HOT, sesje, bufor, WAL, indeksy zamówień, połączenia
```
           relname           |   upd   |  hot   | hot_pct 
-----------------------------+---------+--------+---------
 catalog_product             | 1002000 |    642 |       0
 ordering_sales_hour         |  753888 | 732670 |      97
 ordering_customer           |   99432 |      0 |       0
 cart_cart                   |   73057 |  69047 |      95
 messenger_messages          |   70539 |      0 |       0
 inventory_stock_item        |   60757 |      0 |       0
 ordering_order_notification |   18353 |  18225 |      99
 inventory_reservation       |   16339 |  15087 |      92
 ordering_order              |   16339 |      0 |       0
 pricing_variant_price       |    2000 |     62 |       3
(10 rows)

 sessions | xact_commit | hit_pct | deadlocks 
----------+-------------+---------+-----------
   198177 |     3420686 |    91.0 |         0
(1 row)

  wal  
-------
 36 GB
(1 row)

               indexrelname               |  size  
------------------------------------------+--------
 ordering_order_search_trgm_idx           | 134 MB
 ordering_order_status_placed_at_id_idx   | 97 MB
 ordering_order_status_total_gross_id_idx | 94 MB
 ordering_order_idempotency_key_key       | 75 MB
 ordering_order_placed_at_id_idx          | 67 MB
 ordering_order_customer_placed_at_id_idx | 66 MB
 ordering_order_total_gross_id_idx        | 65 MB
 ordering_order_number_key                | 49 MB
 ordering_order_pkey                      | 48 MB
 ordering_order_lower_email_idx           | 41 MB
 ordering_order_paid_at_idx               | 33 MB
 ordering_order_pending_refund_idx        | 104 kB
(12 rows)

 usename  | connections 
----------+-------------
 postgres |           1
 shop     |          65
(2 rows)

(w sesji, po testach zamówień i przed wariantem trwałego połączenia: sessions=168827, xact_commit=2051507, hit_pct=90.5, wal=36 GB; inventory_stock_item upd=42118 hot=0)
```
## Pamięć kontenerów: bieżąca i szczytowa (cgroup memory.current, memory.peak; bajty)
```
api-prod 156663808 197931008 
api-prodw 210124800 270323712 
api-prodp 194125824 207380480 
worker-1 65077248 67887104 
store-1 366329856 3039850496 
varnish-1 170094592 173445120 
redis-1 28233728 41484288 
keycloak-1 637845504 884342784 
(store: szczyt obejmuje next build przy starcie kontenera; w sesji po testach trybów: api-prod peak 197931008, api-prodw peak 270323712)
```
## FrankenPHP: liczba wątków (log startu)
```
"total_threads":32
"total_threads":33,"worker_threads":32
```
## Varnish (varnishstat -1)
```
MAIN.client_req                    90608        12.84 Good client requests received
MAIN.cache_hit                     80699        11.43 Cache hits
MAIN.cache_miss                     7904         1.12 Cache misses
MAIN.n_object                       3568          .   object structs made
MAIN.n_lru_nuked                       0         0.00 Number of LRU nuked objects
SMA.s0.g_bytes                          9885247          .   Bytes outstanding
SMA.s0.g_space                         94972353          .   Bytes available
(po pierwszym przebiegu do nasycenia: client_req=49444 cache_hit=42504 cache_miss=4935 n_object=2956)
```
## Sklep: rozmiary HTML (curl bez kompresji; gzip -c)
```
/ raw=320586 gzip=45182 odnosniki_kategorii=400
/c/bieganie raw=310815 gzip=44399 odnosniki_kategorii=392
/cart raw=115067 gzip=19045 odnosniki_kategorii=377
(strona produktu, w sesji: raw=149397 gzip=25192, odnośników /c/: 389)
```
## Sklep: wpisy ISR na dysku (.next/server/route-cache)
```
wpisy html: 1227, w tym testowe 404: 200; razem KB: 293248
110182 bajtów html
35393 bajtów rsc
segmenty KB: 76
(w sesji, przed testem 404: 302 wpisy = 79276 KB, czyli 262 KB na wpis; 200 losowych adresów /p/... dodało 200 wpisów i 28000 KB)
```
## Bundle
```
sklep: 20 plików JS, 994.595 KB przed kompresją
panel (npx vite build w kontenerze admin): index-*.js 970.02 kB, gzip 294.39 kB; index-*.css 73.58 kB, gzip 14.52 kB
```
## Worker: tempo i CPU (odczyty z sesji)
```
zdarzenia zamówień: kolejka events mniejsza o 351 w ok. 20 s; CPU kontenera worker w tym oknie 746 ms (cpu.stat usage_usec)
pg_stat_statements w tym oknie: po 364 wywołania pięciu zapytań obsługi zdarzenia, razem ok. 0,45 ms na zdarzenie
kolejka events po pomiarze tempa została opróżniona ręcznie (rabbitmqctl purge_queue events), stąd skok do 0 w drain-after-checkout.txt
```
