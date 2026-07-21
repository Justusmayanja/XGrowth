"use client";

import { useEffect } from "react";

type ToastProps = {
  message: string;
  onClose: () => void;
  duration?: number;
};

export function Toast({ message, onClose, duration = 3000 }: ToastProps) {
  useEffect(() => {
    const timer = window.setTimeout(onClose, duration);
    return () => window.clearTimeout(timer);
  }, [duration, onClose]);

  return (
    <div className="fixed bottom-6 right-6 z-50 rounded-lg border border-accent/50 bg-surface px-4 py-3 text-sm shadow-lg">
      {message}
    </div>
  );
}
