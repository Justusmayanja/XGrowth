"use client";

import { AiRulesCard } from "@/components/admin/AiRulesCard";
import { AiUsageLimitsCard } from "@/components/admin/AiUsageLimitsCard";

export default function AdminAiRulesPage() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">AI Rules</h1>
        <p className="text-muted-foreground">
          Define how the AI behaves across Content Studio, engagement replies, and live event posts.
          Rules are sent first in every system prompt so the model must follow them before generating output.
        </p>
      </div>

      <AiUsageLimitsCard />
      <AiRulesCard />
    </div>
  );
}
