"use client";

import { useEffect, useState } from "react";
import { getToken } from "@/lib/api";

export function PostMedia({ postId, className = "" }: { postId: number; className?: string }) {
  const [src, setSrc] = useState<string | null>(null);

  useEffect(() => {
    let url: string | null = null;
    let cancelled = false;
    const token = getToken();
    const headers: Record<string, string> = {};
    if (token) headers.Authorization = `Bearer ${token}`;

    fetch(`/api/content/posts/${postId}/media`, { headers })
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
  }, [postId]);

  if (!src) return null;

  return (
    <img
      src={src}
      alt=""
      className={`mb-3 max-h-80 w-full rounded-md object-cover ${className}`}
    />
  );
}
