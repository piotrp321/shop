# Image fixtures

Used by the image storage and imgproxy integration tests. Each file is below 3 KB.

- `sample.{jpg,png,webp,avif,heic}`: the same 64x48 sRGB image in each supported input format.
- `oversized.webp`: lossless black 8000x7000 image (56 MP), above the 50 MP limit.
- `sample.svg`: hand-written SVG with an XML prolog; SVG input is rejected.

Regenerate the generated files from the repository root:

```sh
docker run --rm -v "$PWD/apps/api/tests/Fixtures/images:/out" -w /out alpine:3.22 sh -c 'apk add -q vips-tools vips-heif && vips black b.v 64 48 --bands 3 && vips linear b.v c.v "1 1 1" "40 120 200" --uchar && vips copy c.v s.v --interpretation srgb && vips copy s.v sample.jpg[Q=80,strip] && vips copy s.v sample.png[strip] && vips copy s.v sample.webp[strip] && vips heifsave s.v sample.avif --compression av1 --strip && vips heifsave s.v sample.heic --compression hevc --strip && vips black b2.v 8000 7000 && vips copy b2.v oversized.webp[lossless,strip] && rm -f *.v'
```
