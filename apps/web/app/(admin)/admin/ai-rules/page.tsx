"use client";

import { AiRulesCard } from "@/components/admin/AiRulesCard";
import { AiUsageLimitsCard } from "@/components/admin/AiUsageLimitsCard";

export default function AdminAiRulesPage() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Drafting rules</h1>
        <p className="text-muted-foreground">
          Instructions sent with every draft in Compose, replies, and live posts. They are included
          before the draft is written.
        </p>
      </div>

      <AiUsageLimitsCard />
      <AiRulesCard />
    </div>
  );
}
