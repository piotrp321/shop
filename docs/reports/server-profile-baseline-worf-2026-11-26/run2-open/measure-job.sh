set -u
apk add --no-cache curl > /dev/null
B="python3 /m/baseline.py"; API=http://varnish; STORE=http://store:3000
mkdir -p /w; cd /w
echo '##BEGIN open-product-2'
$B urls $API $STORE open-product-2-urls.json --listings 120 --products 120
$B run open-product-2-urls.json open-product-2.jsonl --mode open --rate 2 --duration 60 --kinds product || true
$B report open-product-2.jsonl || true
echo '##JSONL open-product-2'; cat open-product-2.jsonl; echo '##END open-product-2'
echo '##BEGIN open-product-5'
$B urls $API $STORE open-product-5-urls.json --listings 300 --products 300
$B run open-product-5-urls.json open-product-5.jsonl --mode open --rate 5 --duration 60 --kinds product || true
$B report open-product-5.jsonl || true
echo '##JSONL open-product-5'; cat open-product-5.jsonl; echo '##END open-product-5'
echo '##BEGIN open-product-10'
$B urls $API $STORE open-product-10-urls.json --listings 600 --products 600
$B run open-product-10-urls.json open-product-10.jsonl --mode open --rate 10 --duration 60 --kinds product || true
$B report open-product-10.jsonl || true
echo '##JSONL open-product-10'; cat open-product-10.jsonl; echo '##END open-product-10'
echo '##BEGIN open-product-20'
$B urls $API $STORE open-product-20-urls.json --listings 1200 --products 1200
$B run open-product-20-urls.json open-product-20.jsonl --mode open --rate 20 --duration 60 --kinds product || true
$B report open-product-20.jsonl || true
echo '##JSONL open-product-20'; cat open-product-20.jsonl; echo '##END open-product-20'
echo '##BEGIN open-product-40'
$B urls $API $STORE open-product-40-urls.json --listings 2400 --products 2400
$B run open-product-40-urls.json open-product-40.jsonl --mode open --rate 40 --duration 60 --kinds product || true
$B report open-product-40.jsonl || true
echo '##JSONL open-product-40'; cat open-product-40.jsonl; echo '##END open-product-40'
echo '##BEGIN open-leaf-1'
$B urls $API $STORE open-leaf-1-urls.json --listings 180 --products 60
$B run open-leaf-1-urls.json open-leaf-1.jsonl --mode open --rate 1 --duration 60 --kinds leaf,leaf_filter || true
$B report open-leaf-1.jsonl || true
echo '##JSONL open-leaf-1'; cat open-leaf-1.jsonl; echo '##END open-leaf-1'
echo '##BEGIN open-leaf-2'
$B urls $API $STORE open-leaf-2-urls.json --listings 360 --products 120
$B run open-leaf-2-urls.json open-leaf-2.jsonl --mode open --rate 2 --duration 60 --kinds leaf,leaf_filter || true
$B report open-leaf-2.jsonl || true
echo '##JSONL open-leaf-2'; cat open-leaf-2.jsonl; echo '##END open-leaf-2'
echo '##BEGIN open-leaf-4'
$B urls $API $STORE open-leaf-4-urls.json --listings 720 --products 240
$B run open-leaf-4-urls.json open-leaf-4.jsonl --mode open --rate 4 --duration 60 --kinds leaf,leaf_filter || true
$B report open-leaf-4.jsonl || true
echo '##JSONL open-leaf-4'; cat open-leaf-4.jsonl; echo '##END open-leaf-4'
echo '##BEGIN open-leaf-8'
$B urls $API $STORE open-leaf-8-urls.json --listings 1440 --products 480
$B run open-leaf-8-urls.json open-leaf-8.jsonl --mode open --rate 8 --duration 60 --kinds leaf,leaf_filter || true
$B report open-leaf-8.jsonl || true
echo '##JSONL open-leaf-8'; cat open-leaf-8.jsonl; echo '##END open-leaf-8'
echo '##BEGIN open-leaf-16'
$B urls $API $STORE open-leaf-16-urls.json --listings 2880 --products 960
$B run open-leaf-16-urls.json open-leaf-16.jsonl --mode open --rate 16 --duration 60 --kinds leaf,leaf_filter || true
$B report open-leaf-16.jsonl || true
echo '##JSONL open-leaf-16'; cat open-leaf-16.jsonl; echo '##END open-leaf-16'
