"use client";

// Global loader of next/image. Product photos only come from the signed variants returned by the API
// (see ProductPhoto), so any use of <Image> is a mistake and must fail loudly instead of optimizing images.
export default function forbiddenLoader({ src }: { src: string; width: number; quality?: number }): string {
  throw new Error(`next/image is not allowed (asked for "${src}"): render product photos with ProductPhoto and the variants from the API.`);
}
