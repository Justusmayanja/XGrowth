import Link from "next/link";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

export function PrivacySettingsCard() {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Privacy & data</CardTitle>
        <CardDescription>Legal information and data controls</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3 text-sm">
        <p className="text-muted-foreground">
          You can disconnect a platform and request account deletion. Export and the audit log are not available yet.
        </p>
        <div className="flex flex-wrap gap-3">
          <Link href="/privacy" className="text-primary hover:underline">
            Privacy policy
          </Link>
          <Link href="/terms" className="text-primary hover:underline">
            Terms of service
          </Link>
          <Link href="/about" className="text-primary hover:underline">
            About Mackie17
          </Link>
        </div>
      </CardContent>
    </Card>
  );
}
