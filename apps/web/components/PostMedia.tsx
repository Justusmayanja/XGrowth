"use client";

import { useEffect, useState } from "react";
import { getToken } from "@/lib/api";

export function AuthImage({
  path,
  className = "",
  alt = "",
}: {
  path: string;
  className?: string;
  alt?: string;
}) {
  const [src, setSrc] = useState<string | null>(null);

  useEffect(() => {
    let url: string | null = null;
    let cancelled = false;
    const token = getToken();
    const headers: Record<string, string> = {};
    if (token) headers.Authorization = `Bearer ${token}`;

    fetch(path, { headers })
      .then((response) => (response.ok ? response.blob() : null))
      .then((blob) => {
        if (!blob || cancelled) return;
        url = URL.createObjectURL(blob);
        setSrc(url);
      })
      .catch(() => {});

    return () => {
      cancelled = true;
      if (url) URL.revokeObjectURL(url);
    };
  }, [path]);

  if (!src) return null;

  return (
    <img
      src={src}
      alt={alt}
      className={`mb-3 max-h-80 w-full rounded-md object-cover ${className}`}
    />
  );
}

export function PostMedia({ postId, className = "" }: { postId: number; className?: string }) {
  return <AuthImage path={`/api/content/posts/${postId}/media`} className={className} />;
}
