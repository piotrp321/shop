"""CPU time of the Elasticsearch container from its cgroup (exact, not the 1 s samples of _nodes/stats)."""
import os, subprocess
CONTAINER = os.environ.get('ES_CONTAINER', 'es-audit')


def usage_ms():
    out = subprocess.run(['docker', 'exec', CONTAINER, 'cat', '/sys/fs/cgroup/cpu.stat'], capture_output=True, text=True, check=True).stdout
    return int(out.split('usage_usec ')[1].split()[0]) / 1000.0


def overhead_ms(n=5):
    """CPU the reading itself adds to the cgroup between two readings (median)"""
    v = [usage_ms() for _ in range(n + 1)]
    d = sorted(b - a for a, b in zip(v, v[1:]))
    return d[len(d) // 2]


OVERHEAD = overhead_ms()
