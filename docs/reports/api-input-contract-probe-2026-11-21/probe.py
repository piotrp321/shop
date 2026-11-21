#!/usr/bin/env python3
"""Sends malformed input to every operation of both OpenAPI documents on the local stack and records the answers.

Flags: 5xx, non-problem answers to an error, English texts in `detail` / `violations[].title`, 2xx for a mutated input.
Usage: probe.py <repo> <api base> <keycloak base> <admin base> <out.jsonl>
"""
import copy
import http.client
import json
import re
import subprocess
import sys
import urllib.parse
import uuid

REPO, BASE, KC, ADMIN, OUT = sys.argv[1:6]
HOST = urllib.parse.urlparse(BASE).netloc
NIL = '00000000-0000-0000-0000-000000000000'
NBSP = ' '
LONG = 'a' * 5000
TOKEN = {'v': None}


def staff_token():
    out = subprocess.run(['python3', f'{REPO}/.claude/skills/integration/kc_login.py', 'staff', '--keycloak', KC,
                          '--admin', ADMIN, '--account', 'admin'], capture_output=True, text=True, timeout=120)
    if out.returncode != 0:
        raise SystemExit('login failed: ' + out.stderr[-400:])
    TOKEN['v'] = out.stdout.strip()


def send(method, target, body=None, headers=None, admin=False, retry=True):
    h = dict(headers or {})
    if admin:
        h['Authorization'] = 'Bearer ' + TOKEN['v']
    conn = http.client.HTTPConnection(HOST, timeout=30)
    try:
        conn.putrequest(method, target, skip_accept_encoding=True)
        data = body if isinstance(body, (bytes, type(None))) else body.encode()
        for k, v in h.items():
            conn.putheader(k, v.encode('latin-1', 'replace') if isinstance(v, str) else v)
        if data is not None:
            conn.putheader('Content-Length', str(len(data)))
        conn.endheaders()
        if data is not None:
            conn.send(data)
        r = conn.getresponse()
        raw = r.read()
        status, ctype = r.status, r.getheader('Content-Type') or ''
    except Exception as e:  # noqa
        return {'status': 0, 'ctype': '', 'text': 'CLIENT ERROR ' + repr(e), 'json': None}
    finally:
        conn.close()
    if admin and status == 401 and retry:
        staff_token()
        return send(method, target, body, headers, admin, False)
    text = raw.decode('utf-8', 'replace')
    try:
        js = json.loads(text)
    except Exception:
        js = None
    return {'status': status, 'ctype': ctype, 'text': text[:600], 'json': js}


PL = re.compile('[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]')
EN = re.compile(r'\b(This|The|should|must|value|invalid|required|Method|Resource|Authentication|Unsupported|Access|'
                r'not|is|are|Too|request|allowed|found|type|Expected|expected)\b')


def texts(js):
    if not isinstance(js, dict):
        return []
    out = []
    if isinstance(js.get('detail'), str):
        out.append(('detail', '', js['detail']))
    for v in js.get('violations') or []:
        if isinstance(v, dict):
            out.append(('violation', str(v.get('propertyPath')), str(v.get('title'))))
    return out


def english(t):
    return bool(EN.search(t)) and not PL.search(t)


results = []
out_f = open(OUT, 'w')


def record(op, kind, what, value, method, target, res):
    flags = []
    s = res['status']
    if s >= 500 or s == 0:
        flags.append('5xx')
    if 200 <= s < 300:
        flags.append('2xx')
    if s >= 400 and 'application/problem+json' not in res['ctype']:
        flags.append('not-problem')
    en = [(k, p, t) for k, p, t in texts(res['json']) if english(t)]
    if en:
        flags.append('english')
    if s in (400, 422) and isinstance(res['json'], dict) and not res['json'].get('violations'):
        flags.append('no-violations')
    row = {'op': op, 'kind': kind, 'what': what, 'value': value if len(str(value)) < 80 else str(value)[:40] + '…',
           'status': s, 'flags': flags, 'texts': texts(res['json'])[:6], 'target': target[:200]}
    if '5xx' in flags or 'not-problem' in flags:
        row['body'] = res['text'][:300]
    out_f.write(json.dumps(row, ensure_ascii=False) + '\n')
    results.append(row)


# ---------- real identifiers
def first(js, *keys):
    cur = js
    for k in keys:
        if isinstance(cur, dict):
            cur = cur.get(k)
        elif isinstance(cur, list) and cur:
            cur = cur[k] if isinstance(k, int) else None
        else:
            return None
    return cur


def items(js):
    if isinstance(js, dict):
        for k in ('items', 'data', 'results'):
            if isinstance(js.get(k), list):
                return js[k]
    return js if isinstance(js, list) else []


staff_token()
IDS = {}
DETAIL = {}
prod = items(send('GET', '/api/v1/products?perPage=5')['json'])
slug = prod[0].get('slug') if prod else None
pd = send('GET', f'/api/v1/products/{slug}')['json'] or {}
IDS['slug'] = slug
variant = first(pd, 'variants', 0, 'id')
IDS['variantId'] = variant
IDS['productId'] = pd.get('id')
cart = send('POST', '/api/v1/guest-carts', '', {'Content-Type': 'application/json'})['json'] or {}
IDS['cartId'] = cart.get('id') or cart.get('cartId')
for name, path, key in [('categories', '/api/v1/admin/categories', 'category'), ('products', '/api/v1/admin/products', 'product'),
                        ('delivery-methods', '/api/v1/admin/delivery-methods', 'delivery'),
                        ('orders', '/api/v1/admin/orders', 'orderId'), ('customers', '/api/v1/admin/customers', 'customerId'),
                        ('discount-codes', '/api/v1/admin/discount-codes', 'discount'),
                        ('promotions', '/api/v1/admin/promotions', 'promotion')]:
    js = send('GET', path, admin=True)['json']
    it = items(js)
    if name == 'categories' and it and isinstance(it[0], dict) and it[0].get('children'):
        pass
    rid = it[0].get('id') if it and isinstance(it[0], dict) else None
    IDS[name] = rid
    if rid:
        DETAIL[name] = send('GET', f'{path}/{rid}', admin=True)['json']
stock = items(send('GET', '/api/v1/admin/stock?perPage=1', admin=True)['json'])
IDS['variantId'] = first(stock, 0, 'variants', 0, 'variantId')
IDS['deliveryMethodId'] = IDS.get('delivery-methods')
DM = DETAIL.get('delivery-methods') or {}
price = first(items(send('GET', '/api/v1/admin/prices?perPage=1', admin=True)['json']), 0, 'variants', 0, 'price', 'gross') or 0
TOTAL = price + (DM.get('priceGross') or 0)


def fresh_cart():
    c = send('POST', '/api/v1/guest-carts', '', {'Content-Type': 'application/json'})['json'] or {}
    send('POST', f"/api/v1/guest-carts/{c.get('id')}/items", json.dumps({'variantId': IDS['variantId'], 'quantity': 1}),
         {'Content-Type': 'application/json'})
    return c.get('id')


ORDER = {'cartId': None, 'email': 'probe@example.com', 'fullName': 'Anna Nowak', 'phone': '600100200',
         'street': 'ul. D\u0142uga 5', 'postalCode': '00-950', 'city': 'Warszawa', 'expectedTotalGross': TOTAL,
         'deliveryMethodId': IDS['deliveryMethodId']}
ORDER['cartId'] = fresh_cart()
placed = send('POST', '/api/v1/orders', json.dumps(ORDER), {'Content-Type': 'application/json', 'Idempotency-Key': str(uuid.uuid4())})
print('order', placed['status'], placed['text'][:300], file=sys.stderr)
PJ = placed['json'] or {}
IDS['orders'] = PJ.get('orderId') or PJ.get('id')
ACCESS = PJ.get('accessToken') or first(PJ, 'access', 'token') or ''
IDS['cartId'] = fresh_cart()
custs = items(send('GET', '/api/v1/admin/customers?perPage=1', admin=True)['json'])
IDS['customers'] = first(custs, 0, 'id') or first(custs, 0, 'customerId')
print('ids', json.dumps(IDS), 'total', TOTAL, 'access', bool(ACCESS), file=sys.stderr)


def path_value(path, name):
    if name in ('variantId', 'cartId', 'slug'):
        return IDS.get(name)
    if name in ('orderId', 'customerId'):
        return IDS.get('orders' if name == 'orderId' else 'customers')
    if name == 'provider':
        return 'demo-card'
    if name == 'id':
        for seg in ('categories', 'products', 'delivery-methods', 'discount-codes', 'promotions'):
            if f'/{seg}/' in path:
                return IDS.get(seg)
    return None


def resolve(doc, schema, depth=0):
    if not isinstance(schema, dict) or depth > 6:
        return {}
    if '$ref' in schema:
        return resolve(doc, doc['components']['schemas'][schema['$ref'].split('/')[-1]], depth + 1)
    for k in ('allOf', 'oneOf', 'anyOf'):
        if k in schema:
            merged = {'properties': {}}
            for s in schema[k]:
                r = resolve(doc, s, depth + 1)
                merged['properties'].update(r.get('properties', {}))
                if r.get('type') and 'type' not in merged:
                    merged['type'] = r['type']
            return merged
    return schema


KNOWN = {'cartId': lambda: IDS.get('cartId'), 'guestCartId': lambda: IDS.get('cartId'), 'variantId': lambda: IDS.get('variantId'),
         'deliveryMethodId': lambda: IDS.get('deliveryMethodId'), 'expectedTotalGross': lambda: TOTAL, 'quantity': lambda: 1,
         'provider': lambda: 'demo-transfer', 'phone': lambda: '600100200', 'postalCode': lambda: '00-950'}


def sample(doc, schema, name='', depth=0):
    if name in KNOWN and KNOWN[name]() is not None:
        return KNOWN[name]()
    s = resolve(doc, schema)
    if 'example' in s:
        return s['example']
    if s.get('enum'):
        return s['enum'][0]
    t = s.get('type')
    if isinstance(t, list):
        t = [x for x in t if x != 'null'][0] if [x for x in t if x != 'null'] else 'null'
    if s.get('properties') or t == 'object':
        return {k: sample(doc, v, k, depth + 1) for k, v in (s.get('properties') or {}).items()} if depth < 4 else {}
    if t == 'array':
        return [sample(doc, s.get('items', {}), name, depth + 1)] if depth < 4 else []
    if t == 'integer':
        return 1
    if t == 'number':
        return 1
    if t == 'boolean':
        return True
    if t == 'null':
        return None
    if s.get('format') == 'uuid' or name.lower().endswith('id'):
        return IDS.get(name) or str(uuid.uuid4())
    if s.get('format') == 'date-time':
        return '2030-01-01T00:00:00+00:00'
    if s.get('format') == 'email' or name == 'email':
        return 'probe@example.com'
    return 'Probe'


BODY_VALUES = [None, 123, -1, 1.5, True, '', NBSP, ' 　 ', '​', 'a\u0000b', 'a b', 'a\u0085b',
               'Promo \U0001F3F4\U000e0067\U000e0062\U000e0065\U000e006e\U000e0067\U000e007f',
               LONG, [], {}, [[]], {'a': 1}, 99999999999999999999, NIL, 'not-a-uuid', '1e3', '-0']
QUERY_VALUES = [('array', None), ('map', None), ('empty', ''), ('nbsp', '%C2%A0%C2%A0'), ('nul', '%00'), ('a-nul-b', 'a%00b'),
                ('u2028', '%E2%80%A8'), ('bad-utf8', '%FF%FE'), ('long', LONG), ('minus', '-1'), ('zero', '0'),
                ('float', '1.5'), ('huge', '99999999999999999999999'), ('text', 'abc'), ('nil-uuid', NIL),
                ('max-uuid', 'ffffffff-ffff-ffff-ffff-ffffffffffff'), ('true', 'true'), ('exp', '1e3'), ('twice', None),
                ('zwsp', '%E2%80%8B%E2%80%8B')]
PATH_VALUES = ['abc', NIL, 'ffffffff-ffff-ffff-ffff-ffffffffffff', '%00', 'a' * 3000, '%C2%A0', '123', '%FF', '-1',
               '..%2F..', 'UPPER']
RAW_BODIES = [('no body', None, 'application/json'), ('empty', '', 'application/json'), ('broken json', '{', 'application/json'),
              ('null', 'null', 'application/json'), ('list', '[]', 'application/json'), ('string', '"x"', 'application/json'),
              ('number', '123', 'application/json'), ('empty object', '{}', 'application/json'),
              ('text/plain', '{}', 'text/plain'), ('form', 'a=b', 'application/x-www-form-urlencoded'),
              ('no content type', '{}', None), ('deep', '[' * 600 + ']' * 600, 'application/json'),
              ('bad utf8', b'{"a":"\xff"}', 'application/json'), ('xml', '<a/>', 'application/xml'),
              ('json charset', '{}', 'application/json; charset=iso-8859-1'), ('nul in key', '{"a\\u0000b":1}', 'application/json')]

for fname in ('apps/api/openapi.json', 'apps/api/openapi-admin.json'):
    doc = json.load(open(f'{REPO}/{fname}'))
    for path, item in doc['paths'].items():
        admin = path.startswith('/api/v1/admin')
        for method, op in item.items():
            if method not in ('get', 'post', 'put', 'patch', 'delete'):
                continue
            M = method.upper()
            opname = f'{M} {path}'
            params = (op.get('parameters') or []) + (item.get('parameters') or [])
            pnames = re.findall(r'{(\w+)}', path)
            real = {n: (path_value(path, n) or str(uuid.uuid4())) for n in pnames}

            def target(overrides=None, query=''):
                vals = dict(real)
                vals.update(overrides or {})
                t = path
                for n, v in vals.items():
                    t = t.replace('{' + n + '}', str(v))
                return t + (('?' + query) if query else '')

            base_headers = {}
            if any(p.get('in') == 'header' and p['name'] == 'Order-Access-Token' for p in params):
                base_headers['Order-Access-Token'] = ACCESS
            is_order = opname == 'POST /api/v1/orders'
            if any(p.get('in') == 'header' and p['name'] == 'Idempotency-Key' for p in params):
                base_headers['Idempotency-Key'] = str(uuid.uuid4())
            schema = None
            ctypes = list((op.get('requestBody') or {}).get('content', {}).keys())
            if 'application/json' in ctypes:
                schema = op['requestBody']['content']['application/json'].get('schema')
            baseline = sample(doc, schema) if schema else None
            if isinstance(baseline, dict) and M == 'PUT':
                for seg, det in DETAIL.items():
                    if f'/{seg}/' in path and isinstance(det, dict):
                        for k in baseline:
                            if k in det:
                                baseline[k] = det[k]
                        if 'expectedVersion' in baseline and 'version' in det:
                            baseline['expectedVersion'] = det['version']
            jh = dict(base_headers)
            if schema is not None:
                jh['Content-Type'] = 'application/json'
            base_body = json.dumps(baseline) if baseline is not None else None

            # baseline (to know what the unmutated request answers)
            if M in ('GET',) or schema is not None:
                record(opname, 'baseline', '', '', M, target(), send(M, target(), base_body, jh, admin))

            # path parameters
            for n in pnames:
                for v in PATH_VALUES:
                    vv = str(real[n]).upper() if v == 'UPPER' else v
                    t = target({n: vv})
                    record(opname, 'path', n, v, M, t, send(M, t, base_body, jh, admin))

            # query parameters
            for p in params:
                if p.get('in') != 'query':
                    continue
                n = p['name']
                for label, v in QUERY_VALUES:
                    if label == 'array':
                        q = f'{n}[]=x'
                    elif label == 'map':
                        q = f'{n}[a][b]=x'
                    elif label == 'twice':
                        q = f'{n}=1&{n}=2'
                    else:
                        q = f'{n}={v}'
                    t = target(None, q)
                    record(opname, 'query', n, label, M, t, send(M, t, base_body, jh, admin))
            if M == 'GET':
                t = target(None, 'zzzUnknown=1&zzz[]=2')
                record(opname, 'query', '(unknown key)', '', M, t, send(M, t, None, jh, admin))

            # headers
            for p in params:
                if p.get('in') != 'header':
                    continue
                n = p['name']
                for label, v in [('missing', None), ('empty', ''), ('space', ' '), ('long', 'k' * 3000), ('text', 'abc'),
                                 ('nil-uuid', NIL), ('non-ascii', 'zażółć'), ('ctl', 'a\tb')]:
                    hh = {k: x for k, x in jh.items() if k != n}
                    if v is not None:
                        hh[n] = v
                    record(opname, 'header', n, label, M, target(), send(M, target(), base_body, hh, admin))

            # bodies
            if schema is not None:
                for label, raw, ct in RAW_BODIES:
                    hh = dict(base_headers)
                    if ct:
                        hh['Content-Type'] = ct
                    record(opname, 'body', '(whole)', label, M, target(), send(M, target(), raw, hh, admin))
                if isinstance(baseline, dict):
                    for v in BODY_VALUES:
                        allv = {k: v for k in baseline}
                        record(opname, 'body', '(all keys)', v, M, target(), send(M, target(), json.dumps(allv), jh, admin))
                    for k in baseline:
                        missing = {x: y for x, y in baseline.items() if x != k}
                        record(opname, 'body', k, '(missing)', M, target(), send(M, target(), json.dumps(missing), jh, admin))
                        for v in BODY_VALUES:
                            b = copy.deepcopy(baseline)
                            if is_order:
                                b['cartId'] = fresh_cart()
                            b[k] = v
                            hh = dict(jh)
                            if 'Idempotency-Key' in hh:
                                hh['Idempotency-Key'] = str(uuid.uuid4())
                            record(opname, 'body', k, v, M, target(), send(M, target(), json.dumps(b), hh, admin))
                        # nested: one level down
                        if isinstance(baseline[k], dict):
                            for k2 in baseline[k]:
                                for v in BODY_VALUES:
                                    b = copy.deepcopy(baseline)
                                    b[k][k2] = v
                                    record(opname, 'body', f'{k}.{k2}', v, M, target(), send(M, target(), json.dumps(b), jh, admin))
                        if isinstance(baseline[k], list) and baseline[k] and isinstance(baseline[k][0], dict):
                            for k2 in baseline[k][0]:
                                for v in BODY_VALUES:
                                    b = copy.deepcopy(baseline)
                                    b[k][0][k2] = v
                                    record(opname, 'body', f'{k}[0].{k2}', v, M, target(), send(M, target(), json.dumps(b), jh, admin))
            elif M in ('POST', 'PUT', 'DELETE'):
                record(opname, 'baseline', '', '', M, target(), send(M, target(), None, base_headers, admin))
            # wrong method
            record(opname, 'method', '', 'PATCH', 'PATCH', target(), send('PATCH', target(), None, {}, admin))
    out_f.flush()

out_f.close()
print('requests', len(results), file=sys.stderr)
