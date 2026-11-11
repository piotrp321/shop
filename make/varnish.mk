.PHONY: varnish-test

TEST_TARGETS += varnish-test

# VCL tests (infra/varnish/tests/*.vtc) with varnishtest from the image of the varnish service; no running stack and
# no network. The backend of default.vcl is api:80: in the test container api is 127.0.0.1, where the .vtc servers listen.
varnish-test:
	image="$$(docker compose config --images varnish | grep -m1 '^varnish:')" && \
	docker run --rm --network none --add-host api:127.0.0.1 -v "$(CURDIR)/infra/varnish:/etc/varnish:ro" \
		--entrypoint sh "$$image" -c 'varnishtest -j1 -k /etc/varnish/tests/*.vtc'
