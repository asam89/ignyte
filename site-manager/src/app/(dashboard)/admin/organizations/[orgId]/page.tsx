import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { redirect, notFound } from "next/navigation";
import { Card, CardHeader, CardTitle } from "@/components/ui/card";
import { InviteUserForm } from "./invite-user-form";

export default async function OrganizationDetailPage({
  params,
}: {
  params: Promise<{ orgId: string }>;
}) {
  const session = await auth();
  if (!session?.user) redirect("/login");

  const user = session.user as { role: string };
  if (user.role !== "ignyte_staff") redirect("/sites");

  const { orgId } = await params;

  const org = await prisma.organization.findUnique({
    where: { id: orgId },
    include: {
      users: {
        select: { id: true, email: true, name: true, role: true, createdAt: true },
        orderBy: { createdAt: "asc" },
      },
      sites: {
        select: { id: true, name: true, siteType: true, productionUrl: true },
      },
      subscription: true,
    },
  });

  if (!org) notFound();

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold">{org.name}</h1>
        <p className="text-gray-500">/{org.slug}</p>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        {/* Users */}
        <Card>
          <CardHeader>
            <CardTitle>Users ({org.users.length})</CardTitle>
          </CardHeader>
          <div className="p-4 pt-0">
            <div className="space-y-2 mb-4">
              {org.users.map((u) => (
                <div
                  key={u.id}
                  className="flex items-center justify-between p-2 rounded bg-gray-50"
                >
                  <div>
                    <p className="font-medium text-sm">{u.email}</p>
                    {u.name && <p className="text-xs text-gray-500">{u.name}</p>}
                  </div>
                  <span className="text-xs px-2 py-1 rounded bg-gray-200">
                    {u.role.replace("_", " ")}
                  </span>
                </div>
              ))}
            </div>
            <InviteUserForm orgId={orgId} />
          </div>
        </Card>

        {/* Sites */}
        <Card>
          <CardHeader>
            <CardTitle>Sites ({org.sites.length})</CardTitle>
          </CardHeader>
          <div className="p-4 pt-0">
            <div className="space-y-2 mb-4">
              {org.sites.map((site) => (
                <div
                  key={site.id}
                  className="flex items-center justify-between p-2 rounded bg-gray-50"
                >
                  <div>
                    <p className="font-medium text-sm">{site.name}</p>
                    {site.productionUrl && (
                      <p className="text-xs text-gray-500">{site.productionUrl}</p>
                    )}
                  </div>
                  <span className="text-xs px-2 py-1 rounded bg-blue-100 text-blue-800">
                    {site.siteType}
                  </span>
                </div>
              ))}
              {org.sites.length === 0 && (
                <p className="text-sm text-gray-500">No sites yet.</p>
              )}
            </div>
          </div>
        </Card>

        {/* Subscription */}
        <Card>
          <CardHeader>
            <CardTitle>Subscription</CardTitle>
          </CardHeader>
          <div className="p-4 pt-0">
            {org.subscription ? (
              <div className="space-y-2 text-sm">
                <p>
                  <span className="text-gray-500">Plan:</span>{" "}
                  <span className="font-medium">{org.subscription.plan}</span>
                </p>
                <p>
                  <span className="text-gray-500">Usage:</span>{" "}
                  <span className="font-medium">
                    {org.subscription.currentUsage} / {org.subscription.monthlyQuota}
                  </span>
                </p>
                <p>
                  <span className="text-gray-500">Resets:</span>{" "}
                  <span className="font-medium">
                    {org.subscription.quotaResetDate?.toLocaleDateString() ?? "N/A"}
                  </span>
                </p>
                <p>
                  <span className="text-gray-500">Count on:</span>{" "}
                  <span className="font-medium">
                    {org.subscription.countOnMerge ? "Merge" : "Submit"}
                  </span>
                </p>
              </div>
            ) : (
              <p className="text-sm text-gray-500">No subscription configured.</p>
            )}
          </div>
        </Card>
      </div>
    </div>
  );
}
