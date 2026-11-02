import type { ReactNode } from "react";
import { PageHeader } from "@/components/layout/PageHeader";

export function DeskHeading({
  title,
  children,
  actions,
}: {
  icon?: unknown;
  title: string;
  children?: ReactNode;
  actions?: ReactNode;
}) {
  return <PageHeader title={title} description={children} actions={actions} />;
}
