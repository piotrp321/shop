#!/usr/bin/env python3
"""Sends texts of blank, invisible and control characters to the free-text inputs of the API on the local stack.

Every request has valid other data, so the answer is about the text under test. For a 2xx the stored value is read
back where the API returns it. The stack has the demo data (`make seed`). Orders placed here stay unpaid.
Usage: probe.py <repo> <api base> <keycloak base> <admin base> <out.jsonl>
"""
import http.client
import json
import subprocess
import sys
import urllib.parse
import uuid

REPO, BASE, KC, ADMIN, OUT = sys.argv[1:6]
HOST = urllib.parse.urlparse(BASE).netloc
TOKEN = {'v': None}
J = {'Content-Type': 'application/json'}

# label -> (class, text). {} is replaced by a short unique visible word where a value has to be unique.
TEXTS = {
    'nbsp': ('blank', ' '),
    'spaces-mix': ('blank', ' 　  '),
    'zwsp': ('invisible', '​'),
    'bom': ('invisible', '﻿'),
    'soft-hyphen': ('invisible', '­'),
    'word-joiner': ('invisible', '⁠'),
    'lrm': ('invisible', '‎'),
    'rlo': ('invisible', '‮'),
    'mvs': ('invisible', '᠎'),
    'zwj': ('invisible', '‍'),
    'hangul-filler': ('invisible', 'ㅤ'),
    'nul-inside': ('control', 'a\u0000b'),
    'tab-inside': ('control', 'a\tb'),
    'lf-inside': ('control', 'a\nb'),
    'cr-inside': ('control', 'a\rb'),
    'del-inside': ('control', 'a\u007fb'),
    'nel-inside': ('control', 'a\u0085b'),
    'c1-inside': ('control', 'a\u009fb'),
    'ls-inside': ('control', 'a b'),
    'ps-inside': ('control', 'a b'),
    'nbsp-edges': ('edges', ' Tekst {} '),
    'zwsp-edges': ('edges', '​Tekst {}​'),
    'flag-end': ('keep', 'Promo {} \U0001F3F4\U000e0067\U000e0062\U000e0065\U000e006e\U000e0067\U000e007f'),
    'heart-end': ('keep', 'Serce {} ❤️'),
    'zwj-emoji': ('keep', 'Rodzina \U0001F468‍\U0001F469‍\U0001F467 {}'),
    'polish': ('keep', 'Zażółć gęślą jaźń {}'),
}


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
    data = None if body is None else (body if isinstance(body, bytes) else json.dumps(body).encode())
    if data is not None:
        h.setdefault('Content-Type', 'application/json')
    conn = http.client.HTTPConnection(HOST, timeout=30)
    try:
        conn.request(method, '/api/v1' + target, body=data, headers=h)
        r = conn.getresponse()
        raw = r.read()
        status = r.status
    finally:
        conn.close()
    if admin and status == 401 and retry:
        staff_token()
        return send(method, target, body, headers, admin, False)
    try:
        js = json.loads(raw.decode('utf-8', 'replace'))
    except Exception:
        js = None
    return status, js


def q(text):
    return urllib.parse.quote(text, safe='')


def word():
    return uuid.uuid4().hex[:6]


out_f = open(OUT, 'w')


def record(op, field, label, status, js, stored=None, sent=None):
    viol = []
    detail = None
    if isinstance(js, dict):
        detail = js.get('detail')
        viol = [(v.get('propertyPath'), v.get('title')) for v in js.get('violations') or [] if isinstance(v, dict)]
    row = {'op': op, 'field': field, 'label': label, 'class': TEXTS.get(label, ('other',))[0], 'status': status, 'violations': viol,
           'detail': detail, 'sent': sent, 'stored': stored, 'changed': None if stored is None or sent is None else stored != sent}
    out_f.write(json.dumps(row, ensure_ascii=False) + '\n')
    out_f.flush()


def each():
    for label, (_, text) in TEXTS.items():
        yield label, text.replace('{}', word())


staff_token()

# ---------- identifiers of the demo data
SLUG = 'baton-energetyczny-lumora-daktylowy'
PRODUCT = send('GET', f'/products/{SLUG}')[1]['id']
stock = send('GET', f'/admin/stock?productId={PRODUCT}', admin=True)[1]['items'][0]
VARIANT = stock['variants'][1]['variantId']
ORDER_VARIANT = stock['variants'][2]['variantId']
# Every order placed below reserves one unit and staff cannot cancel an unpaid order.
send('POST', f'/admin/variants/{ORDER_VARIANT}/stock/receipts', {'quantity': 300, 'note': None},
     {'Idempotency-Key': str(uuid.uuid4())}, admin=True)
DELIVERY = send('GET', '/admin/delivery-methods', admin=True)[1]['items'][0]['id']
cats = send('GET', '/admin/categories', admin=True)[1]
LEAF = cats[0]['children'][0]['id']
IMAGE_KEY = send('GET', f'/admin/products/{PRODUCT}', admin=True)[1]['images'][0]['key']


# ---------- catalog: category
for label, text in each():
    s, js = send('POST', '/admin/categories', {'name': text, 'slug': 'p-' + word(), 'parentId': None}, admin=True)
    record('POST /admin/categories', 'name', label, s, js, (js or {}).get('name') if s < 300 else None, text)


# ---------- catalog: product
def product(**over):
    body = {'name': 'Produkt próby ' + word(), 'slug': 'produkt-proby-' + word(), 'shortDescription': 'Opis.',
            'mainCategoryId': LEAF, 'additionalCategoryIds': [], 'taxClass': 'standard',
            'images': [{'key': IMAGE_KEY, 'alt': 'Zdjęcie'}], 'variantAxes': ['Kolor'],
            'variants': [{'variantId': None, 'values': ['czerwony']}]}
    body.update(over)
    return body


def product_case(field, build, read):
    for label, text in each():
        s, js = send('POST', '/admin/products', build(text), admin=True)
        stored = None
        if s < 300 and isinstance(js, dict):
            det = send('GET', f"/admin/products/{js.get('id')}", admin=True)[1] or {}
            stored = read(det)
        record('POST /admin/products', field, label, s, js, stored, text)


product_case('name', lambda t: product(name=t), lambda d: d.get('name'))
product_case('shortDescription', lambda t: product(shortDescription=t), lambda d: d.get('shortDescription'))
product_case('images[0].alt', lambda t: product(images=[{'key': IMAGE_KEY, 'alt': t}]), lambda d: (d.get('images') or [{}])[0].get('alt'))
product_case('variantAxes[0]', lambda t: product(variantAxes=[t]), lambda d: (d.get('variantAxes') or [None])[0])
product_case('variants[0].values[0]', lambda t: product(variants=[{'variantId': None, 'values': [t]}]),
             lambda d: ((d.get('variants') or [{}])[0].get('values') or [None])[0])


# ---------- delivery
def delivery(**over):
    body = {'name': 'Dostawa próby ' + word(), 'description': None, 'priceGross': 1000, 'freeFromGross': None,
            'active': False, 'position': 900, 'trackingUrlTemplate': None}
    body.update(over)
    return body


for field in ('name', 'description'):
    for label, text in each():
        s, js = send('POST', '/admin/delivery-methods', delivery(**{field: text}), admin=True)
        record('POST /admin/delivery-methods', field, label, s, js, (js or {}).get(field) if s < 300 else None, text)


# ---------- inventory
def stock_of(variant):
    for p in send('GET', f'/admin/stock?productId={PRODUCT}', admin=True)[1]['items']:
        for v in p['variants']:
            if v['variantId'] == variant:
                return v['stock']


def last_note(variant):
    h = send('GET', f'/admin/variants/{variant}/stock-history?perPage=1', admin=True)[1] or {}
    it = (h.get('items') or [{}])[0]
    return it.get('note')


for label, text in each():
    st = stock_of(VARIANT)
    s, js = send('POST', f'/admin/variants/{VARIANT}/stock/corrections',
                 {'physical': st['physical'] + 1, 'version': st['version'], 'reason': 'other', 'note': text}, admin=True)
    record('POST /admin/variants/{variantId}/stock/corrections', 'note (reason other)', label, s, js,
           last_note(VARIANT) if s < 300 else None, text)
for label, text in each():
    s, js = send('POST', f'/admin/variants/{VARIANT}/stock/receipts', {'quantity': 1, 'note': text},
                 {'Idempotency-Key': str(uuid.uuid4())}, admin=True)
    record('POST /admin/variants/{variantId}/stock/receipts', 'note', label, s, js, last_note(VARIANT) if s < 300 else None, text)


# ---------- ordering: the guest order
def place(**over):
    cart = send('POST', '/guest-carts', b'', J)[1]['id']
    send('POST', f'/guest-carts/{cart}/items', {'variantId': ORDER_VARIANT, 'quantity': 1})
    view = send('GET', f'/guest-carts/{cart}')[1]
    options = (view.get('delivery') or {}).get('options') or []
    total = next(o['totalGross'] for o in options if o.get('methodId') == DELIVERY)
    body = {'cartId': cart, 'email': f'proba-{word()}@example.com', 'fullName': 'Anna Nowak', 'phone': '600100200',
            'street': 'ul. Długa 5', 'postalCode': '00-950', 'city': 'Warszawa',
            'expectedTotalGross': total, 'deliveryMethodId': DELIVERY,
            'expectedLines': [{'variantId': ORDER_VARIANT, 'quantity': 1}]}
    body.update(over)
    return send('POST', '/orders', body, {'Idempotency-Key': str(uuid.uuid4())})


def admin_order(order_id):
    return send('GET', f'/admin/orders/{order_id}', admin=True)[1] or {}


first = place()
if first[0] not in (200, 201):
    print('order base failed', first, file=sys.stderr)
    cartv = send('POST', '/guest-carts', b'', J)[1]
    print(json.dumps(cartv)[:600], file=sys.stderr)
    raise SystemExit(1)


def find(obj, key):
    if isinstance(obj, dict):
        if key in obj:
            return obj[key]
        for v in obj.values():
            r = find(v, key)
            if r is not None:
                return r
    return None


for field in ('fullName', 'street', 'city', 'postalCode', 'phone', 'email', 'discountCode'):
    for label, text in each():
        s, js = place(**{field: text})
        stored = None
        if s < 300 and isinstance(js, dict):
            oid = js.get('orderId') or js.get('id')
            stored = find(admin_order(oid), field)
        record('POST /orders', field, label, s, js, stored, text)

# A valid value between no-break spaces and between ASCII spaces, for the fields with a format.
for field, value in (('postalCode', '00-950'), ('phone', '600100200'), ('email', None)):
    for label, pad in (('valid-in-nbsp', '\u00a0'), ('valid-in-ascii-spaces', ' ')):
        text = pad + (value or f'proba-{word()}@example.com') + pad
        s, js = place(**{field: text})
        stored = find(admin_order(js.get('orderId') or js.get('id')), field) if s < 300 and isinstance(js, dict) else None
        record('POST /orders', field, label, s, js, stored, text)


# ---------- lists sent as JSON objects with the keys "0".."n" (#903)
s, js = place(expectedLines={'0': {'variantId': ORDER_VARIANT, 'quantity': 1}})
record('POST /orders', 'expectedLines as an object', 'object-for-list', s, js)
s, js = send('POST', '/admin/promotions', {'name': 'Obiekt ' + word(), 'percentOff': 5, 'startsAt': '2031-02-01T00:00:00+00:00',
                                           'endsAt': '2031-02-02T00:00:00+00:00', 'variantIds': {'0': VARIANT}}, admin=True)
record('POST /admin/promotions', 'variantIds as an object', 'object-for-list', s, js)
if s < 300:
    send('DELETE', f"/admin/promotions/{js.get('id')}?version={js.get('version')}", admin=True)
s, js = send('POST', '/admin/products', product(additionalCategoryIds={}, images={}, variantAxes={'0': 'Kolor'},
                                                variants={'0': {'variantId': None, 'values': {'0': 'czerwony'}}}), admin=True)
record('POST /admin/products', 'every list as an object', 'object-for-list', s, js)


# ---------- pricing
for label, text in each():
    s, js = send('POST', '/admin/promotions', {'name': text, 'percentOff': 5, 'startsAt': '2031-01-01T00:00:00+00:00',
                                               'endsAt': '2031-01-02T00:00:00+00:00', 'variantIds': [VARIANT]}, admin=True)
    stored = None
    if s < 300 and isinstance(js, dict):
        stored = js.get('name')
        send('DELETE', f"/admin/promotions/{js.get('id')}?version={js.get('version')}", admin=True)
    record('POST /admin/promotions', 'name', label, s, js, stored, text)

for label, (cls, text) in TEXTS.items():
    code = text.replace('Tekst {}', 'KOD' + word().upper()).replace('{}', word().upper())
    s, js = send('POST', '/admin/discount-codes', {'code': code, 'kind': 'percent', 'value': 5, 'minimumOrderGross': None,
                                                   'startsAt': '2031-01-01T00:00:00+00:00', 'endsAt': None,
                                                   'usageLimit': None, 'enabled': False}, admin=True)
    record('POST /admin/discount-codes', 'code', label, s, js, (js or {}).get('code') if s < 300 else None, code)


# ---------- search phrases and the discount code of a cart
QUERY = [('GET /products', '/products?q=', False), ('GET /admin/products', '/admin/products?q=', True),
         ('GET /admin/stock', '/admin/stock?q=', True), ('GET /admin/prices', '/admin/prices?q=', True),
         ('GET /admin/customers', '/admin/customers?q=', True), ('GET /admin/orders', '/admin/orders?q=', True),
         ('GET /admin/discount-codes', '/admin/discount-codes?q=', True)]
cart = send('POST', '/guest-carts', b'', J)[1]['id']
QUERY.append(('GET /guest-carts/{cartId}', f'/guest-carts/{cart}?discountCode=', False))
for op, target, admin in QUERY:
    for label, (cls, text) in TEXTS.items():
        # A phrase of a single blank or invisible character is shorter than the minimum; repeat it to pass the length.
        value = text.replace('{}', 'abc') * (3 if cls in ('blank', 'invisible') else 1)
        s, js = send('GET', target + q(value), admin=admin)
        record(op, target.split('?')[1].rstrip('='), label, s, js)
    for label, value in (('ascii-spaces', '   '), ('empty', '')):
        s, js = send('GET', target + q(value), admin=admin)
        record(op, target.split('?')[1].rstrip('='), label, s, js)

out_f.close()
print('done', file=sys.stderr)
