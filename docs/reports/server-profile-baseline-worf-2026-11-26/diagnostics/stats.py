import json,subprocess,os
K=open('/private/tmp/claude-501/kubedir.txt').read().strip()
ENV=dict(os.environ,KUBECONFIG=K+'/config')
def get(path):
    r=subprocess.run(['kubectl','-n','shop','exec','elasticsearch-0','--','curl','-s',f'localhost:9200{path}'],capture_output=True,env=ENV)
    return r.stdout.decode()
print(get('/_cat/shards/products*?v&h=index,shard,prirep,docs,store,segments.count'))
n=json.loads(get('/_nodes/stats/jvm,thread_pool,indices,fs,os'))['nodes']
for nid,x in n.items():
    j=x['jvm']; print('heap used %',j['mem']['heap_used_percent'],'heap max MiB',j['mem']['heap_max_in_bytes']//2**20,'gc young',j['gc']['collectors']['young']['collection_count'],'ms',j['gc']['collectors']['young']['collection_time_in_millis'],'old',j['gc']['collectors']['old']['collection_count'],'ms',j['gc']['collectors']['old']['collection_time_in_millis'])
    tp=x['thread_pool']['search']; print('search pool threads',tp['threads'],'queue',tp['queue'],'rejected',tp['rejected'],'completed',tp['completed'])
    i=x['indices']; print('query_cache',i['query_cache']['memory_size_in_bytes'],'hit',i['query_cache']['hit_count'],'miss',i['query_cache']['miss_count'],'evictions',i['query_cache']['evictions'])
    print('request_cache',i['request_cache']['memory_size_in_bytes'],'hit',i['request_cache']['hit_count'],'miss',i['request_cache']['miss_count'],'evictions',i['request_cache']['evictions'])
    print('fielddata',i['fielddata']['memory_size_in_bytes'],'segments',i['segments']['count'],'seg mem',i['segments']['memory_in_bytes'],'search query_total',i['search']['query_total'],'query_time_ms',i['search']['query_time_in_millis'])
    print('os mem total MiB',x['os']['mem']['total_in_bytes']//2**20,'free',x['os']['mem']['free_in_bytes']//2**20,'cgroup',x['os'].get('cgroup',{}).get('memory',{}).get('limit_in_bytes'),'usage',x['os'].get('cgroup',{}).get('memory',{}).get('usage_in_bytes'))
    print('fs total GB',x['fs']['total']['total_in_bytes']//10**9,'io_stats',json.dumps(x['fs'].get('io_stats',{}).get('total',{})))
print(get('/products/_settings?filter_path=*.settings.index.number_of_shards,*.settings.index.refresh_interval,*.settings.index.codec'))
