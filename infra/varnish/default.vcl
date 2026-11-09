vcl 4.1;

# Cache in front of the public catalog reads of the API (docs/features/product-page/spec.md, "Varnish").
# Only additions to the built-in VCL; every subroutine below falls through to it unless it returns.
# Tests: infra/varnish/tests/*.vtc (make varnish-test).

import std;
import xkey;

backend api {
    .host = "api";
    .port = "80";
}

# Senders allowed to PURGE: the private ranges (the subnet of the compose network differs between projects) and
# localhost. Inside the compose network it admits every service; the token is the real check.
acl purgers {
    "127.0.0.0"/8;
    "::1";
    "10.0.0.0"/8;
    "172.16.0.0"/12;
    "192.168.0.0"/16;
}

sub vcl_init {
    # The PURGE token. Without it the VCL does not load, so Varnish does not start.
    if (!std.getenv("VARNISH_PURGE_SECRET") || std.getenv("VARNISH_PURGE_SECRET") == "") {
        return (fail("VARNISH_PURGE_SECRET is not set"));
    }
}

sub vcl_recv {
    if (req.method == "PURGE") {
        # Never reaches the API. client.ip is the address from the PROXY protocol on the image's PROXY listener,
        # remote.ip the peer of the connection: both must be allowed.
        if (client.ip !~ purgers || remote.ip !~ purgers) {
            return (synth(403, "Forbidden"));
        }
        if (!req.http.X-Purge-Token || req.http.X-Purge-Token != std.getenv("VARNISH_PURGE_SECRET")) {
            return (synth(403, "Forbidden"));
        }
        if (!req.http.xkey-purge || req.http.xkey-purge == "") {
            return (synth(400, "Missing xkey-purge"));
        }
        # A hard purge (not a soft one): a page regenerated right after must not get the old response. The number is
        # summed over the tags, so an object with two of them counts twice.
        return (synth(200, "Purged " + xkey.purge(req.http.xkey-purge)));
    }

    # Only the public catalog reads are cached; anything else goes to the API as it is. The chunk list of the sitemap
    # is cached by its exact path (a query string would make a new object for every request); the chunks of products
    # (/api/v1/sitemap/products?chunk=...) are not cached: they are large and the key is free text.
    if (req.url !~ "^/api/v1/(products(/[^/?]+)?|categories|product-offers)(\?|$)" && req.url !~ "^/api/v1/sitemap/product-chunks$") {
        return (pass);
    }

    # No return here: the built-in VCL still passes Authorization, Cookie and methods other than GET and HEAD.
}

sub vcl_deliver {
    # Tags are for Varnish only.
    unset resp.http.xkey;
    if (obj.hits > 0) {
        set resp.http.X-Cache = "HIT";
    } else {
        set resp.http.X-Cache = "MISS";
    }
}
