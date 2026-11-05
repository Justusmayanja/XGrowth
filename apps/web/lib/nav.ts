import {
  BarChart3,
  Clapperboard,
  Clock,
  FileText,
  Hash,
  Lightbulb,
  ListOrdered,
  MessageCircle,
  PenLine,
  Settings,
  Shield,
  Sun,
  Video,
  type LucideIcon,
} from "lucide-react";
import type { Platform } from "@/lib/profile";

export type NavLink = { href: string; label: string; icon: LucideIcon };
export type NavGroup = { label: string; links: NavLink[] };

export const NAV: Record<Platform, NavGroup[]> = {
  x: [
    {
      label: "Desk",
      links: [
        { href: "/dashboard/x", label: "Today", icon: Sun },
        { href: "/content-studio", label: "Compose", icon: PenLine },
        { href: "/drafts", label: "Drafts", icon: FileText },
        { href: "/growth-agent", label: "Queue", icon: ListOrdered },
      ],
    },
    {
      label: "Coverage",
      links: [
        { href: "/trends", label: "Topics", icon: Hash },
        { href: "/engagement", label: "Replies", icon: MessageCircle },
        { href: "/analytics", label: "Results", icon: BarChart3 },
      ],
    },
    {
      label: "Account",
      links: [
        { href: "/safety", label: "Safety", icon: Shield },
        { href: "/settings", label: "Settings", icon: Settings },
      ],
    },
  ],
  tiktok: [
    {
      label: "Studio",
      links: [
        { href: "/dashboard/tiktok", label: "Today", icon: Sun },
        { href: "/content-studio", label: "New clip", icon: Clapperboard },
        { href: "/dashboard/tiktok#clip", label: "Waiting for you", icon: Clock },
      ],
    },
    {
      label: "Coverage",
      links: [
        { href: "/trends", label: "Ideas", icon: Lightbulb },
        { href: "/analytics", label: "What landed", icon: BarChart3 },
      ],
    },
    {
      label: "Account",
      links: [{ href: "/settings", label: "Settings", icon: Settings }],
    },
  ],
  youtube: [
    {
      label: "Studio",
      links: [
        { href: "/dashboard/youtube", label: "Today", icon: Sun },
        { href: "/content-studio", label: "New video", icon: Video },
        { href: "/drafts", label: "Titles and scripts", icon: FileText },
      ],
    },
    {
      label: "Coverage",
      links: [
        { href: "/trends", label: "Ideas", icon: Lightbulb },
        { href: "/analytics", label: "What landed", icon: BarChart3 },
      ],
    },
    {
      label: "Account",
      links: [{ href: "/settings", label: "Settings", icon: Settings }],
    },
  ],
};

export function navHrefActive(pathname: string, hash: string, href: string): boolean {
  const [path, fragment] = href.split("#");
  if (pathname !== path) return false;
  if (fragment) return hash === `#${fragment}`;
  if (path === "/dashboard/tiktok" && hash === "#clip") return false;
  return true;
}
