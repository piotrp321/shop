"""The images of prod as Argo CD renders them: the profile prod and the images values file of the branch deploy-images
(scripts/images-values.sh), with no --set. Every image built by us is <registry>/<app>:<sha>@<digest> from the file."""

import re
import unittest

from support import DIGESTS, REVISION, WORKLOADS, docs, images, of_kind

REGISTRY = "ghcr.io/piotrp321/shop"


class ImagesFileRender(unittest.TestCase):
    def own_images(self, case):
        found = []
        for workload in of_kind(case, *WORKLOADS):
            found += [image for image in images(workload) if image.startswith(REGISTRY + "/")]
        return found

    def test_every_image_of_ours_is_the_sha_and_the_digest_of_the_file(self):
        expected = {f"{REGISTRY}/{app}:{REVISION}@{digest}" for app, digest in DIGESTS.items()}
        found = self.own_images("prod-images-file")
        self.assertEqual(expected, set(found))
        self.assertTrue(found)

    def test_it_is_the_render_the_flags_give(self):
        # The file replaces `--set image.tag=... --set image.digests.*=...`: the two renders are the same documents.
        self.assertEqual(docs("prod"), docs("prod-images-file"))

    def test_no_image_is_a_tag_without_a_digest_or_latest(self):
        for image in self.own_images("prod-images-file"):
            self.assertRegex(image, r":[0-9a-f]{40}@sha256:[0-9a-f]{64}$")
            self.assertNotIn("latest", image)

    def test_the_images_of_other_services_keep_their_digests(self):
        for workload in of_kind("prod-images-file", *WORKLOADS):
            for image in images(workload):
                if not image.startswith(REGISTRY + "/"):
                    self.assertRegex(image, r"@sha256:[0-9a-f]{64}$", image)


if __name__ == "__main__":
    unittest.main()
