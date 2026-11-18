#!/usr/bin/env python3
"""Polls the outbox (Postgres) and the events queue (RabbitMQ) every second until both are empty; prints the series."""
import subprocess, sys, time
def sh(*a): return subprocess.run(a, capture_output=True, text=True).stdout.strip()
def outbox(): return int(sh("docker","exec","shop-perfaudit-postgres-1","psql","-U","postgres","-d","shop","-Atc","select count(*) from messenger_messages where queue_name='outbox'") or 0)
def events():
    out = sh("docker","exec","shop-perfaudit-rabbitmq-1","rabbitmqctl","-q","list_queues","name","messages")
    return sum(int(l.split()[-1]) for l in out.splitlines() if l.strip() and l.split()[0].startswith("events"))
t0=time.time(); empty=0; limit=float(sys.argv[1]) if len(sys.argv)>1 else 900
while time.time()-t0 < limit:
    o,e=outbox(),events()
    print(f"{time.time()-t0:7.1f}s outbox={o} events={e}", flush=True)
    empty = empty+1 if o==0 and e==0 else 0
    if empty>=5 and time.time()-t0>45: break
    time.sleep(1)
