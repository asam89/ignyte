import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { redirect } from "next/navigation";
import { PLANS } from "@/lib/stripe";
import { Card, CardHeader, CardTitle } from "@/components/ui/card";
import { SubscribeButton } from "./subscribe-button";

export default async function BillingPage() {
  const session = await auth();
  if (!session?.user) redirect("/login");

  const user = session.user as { role: string; organizationId: string | null };

  if (!user.organizationId) {
    return (
      <div className="max-w-4xl">
        <h1 className="text-2xl font-bold mb-4">Billing</h1>
        <p className="text-gray-500">No organization associated with your account.</p>
      </div>
    );
  }

  const subscription = await prisma.subscription.findUnique({
    where: { organizationId: user.organizationId },
  });

  const isAdmin = user.role === "client_admin" || user.role === "ignyte_staff";

  return (
    <div className="max-w-4xl">
      <h1 className="text-2xl font-bold mb-6">Billing & Subscription</h1>

      {/* Current plan */}
      {subscription && (
        <Card className="mb-8">
          <CardHeader>
            <CardTitle>Current Plan</CardTitle>
          </CardHeader>
          <div className="p-4 pt-0 space-y-3">
            <div className="flex items-center justify-between">
              <span className="text-gray-600">Plan</span>
              <span className="font-semibold capitalize">{subscription.plan}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-gray-600">Usage this month</span>
              <span className="font-semibold">
                {subscription.currentUsage} / {subscription.monthlyQuota} requests
              </span>
            </div>
            <div className="w-full bg-gray-200 rounded-full h-2">
              <div
                className="bg-[#E87722] h-2 rounded-full transition-all"
                style={{
                  width: `${Math.min(100, (subscription.currentUsage / subscription.monthlyQuota) * 100)}%`,
                }}
              />
            </div>
            <div className="flex items-center justify-between text-sm">
              <span className="text-gray-500">
                Resets: {subscription.quotaResetDate?.toLocaleDateString() ?? "N/A"}
              </span>
              <span className={`px-2 py-0.5 rounded text-xs font-medium ${
                subscription.status === "active" ? "bg-green-100 text-green-800" :
                subscription.status === "past_due" ? "bg-yellow-100 text-yellow-800" :
                "bg-red-100 text-red-800"
              }`}>
                {subscription.status}
              </span>
            </div>
            {subscription.currentUsage >= subscription.monthlyQuota && (
              <div className="mt-2 p-3 bg-amber-50 border border-amber-200 rounded-lg">
                <p className="text-sm text-amber-800">
                  You&apos;ve reached your monthly quota. Upgrade your plan to continue submitting requests.
                </p>
              </div>
            )}
          </div>
        </Card>
      )}

      {/* Plans */}
      <h2 className="text-lg font-semibold mb-4">
        {subscription ? "Upgrade Plan" : "Choose a Plan"}
      </h2>

      <div className="grid gap-4 md:grid-cols-3">
        {(Object.entries(PLANS) as [string, typeof PLANS[keyof typeof PLANS]][]).map(([key, plan]) => {
          const isCurrent = subscription?.plan === key;
          return (
            <Card
              key={key}
              className={`relative ${isCurrent ? "border-[#E87722] border-2" : ""}`}
            >
              {isCurrent && (
                <span className="absolute -top-3 left-4 bg-[#E87722] text-white text-xs px-2 py-0.5 rounded">
                  Current
                </span>
              )}
              <CardHeader>
                <CardTitle>{plan.name}</CardTitle>
                <p className="text-2xl font-bold text-[#1A1A2E]">
                  ${plan.priceMonthly}
                  <span className="text-sm font-normal text-gray-500">/mo</span>
                </p>
              </CardHeader>
              <div className="p-4 pt-0">
                <ul className="space-y-2 mb-4">
                  {plan.features.map((feature) => (
                    <li key={feature} className="text-sm text-gray-600 flex items-center gap-2">
                      <span className="text-green-500">•</span>
                      {feature}
                    </li>
                  ))}
                </ul>
                {isAdmin && !isCurrent && (
                  <SubscribeButton plan={key} />
                )}
              </div>
            </Card>
          );
        })}
      </div>
    </div>
  );
}
