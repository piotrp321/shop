set -u
apk add --no-cache curl > /dev/null
B="python3 /m/baseline.py"; API=http://varnish; STORE=http://store:3000
mkdir -p /w; cd /w
echo '##BEGIN single'
$B urls $API $STORE single-urls.json --listings 300 --products 150 || true
$B run single-urls.json single.jsonl --mode single || true
$B report single.jsonl || true
echo '##JSONL single'; cat single.jsonl; echo '##END single'
echo '##BEGIN open-2'
$B urls $API $STORE open-2-urls.json --listings 120 --products 120
$B run open-2-urls.json open-2.jsonl --mode open --rate 2 --duration 60 || true
$B report open-2.jsonl || true
echo '##JSONL open-2'; cat open-2.jsonl; echo '##END open-2'
echo '##BEGIN open-5'
$B urls $API $STORE open-5-urls.json --listings 300 --products 300
$B run open-5-urls.json open-5.jsonl --mode open --rate 5 --duration 60 || true
$B report open-5.jsonl || true
echo '##JSONL open-5'; cat open-5.jsonl; echo '##END open-5'
echo '##BEGIN open-10'
$B urls $API $STORE open-10-urls.json --listings 600 --products 600
$B run open-10-urls.json open-10.jsonl --mode open --rate 10 --duration 60 || true
$B report open-10.jsonl || true
echo '##JSONL open-10'; cat open-10.jsonl; echo '##END open-10'
echo '##BEGIN open-20'
$B urls $API $STORE open-20-urls.json --listings 1200 --products 1200
$B run open-20-urls.json open-20.jsonl --mode open --rate 20 --duration 60 || true
$B report open-20.jsonl || true
echo '##JSONL open-20'; cat open-20.jsonl; echo '##END open-20'
echo '##BEGIN open-40'
$B urls $API $STORE open-40-urls.json --listings 2400 --products 2400
$B run open-40-urls.json open-40.jsonl --mode open --rate 40 --duration 60 || true
$B report open-40.jsonl || true
echo '##JSONL open-40'; cat open-40.jsonl; echo '##END open-40'
echo '##BEGIN burst'
$B urls $API $STORE burst-urls.json --listings 300 --products 300
$B run burst-urls.json burst.jsonl --mode burst --n 100 --rounds 3 --pause 90 || true
$B report burst.jsonl || true
echo '##JSONL burst'; cat burst.jsonl; echo '##END burst'
