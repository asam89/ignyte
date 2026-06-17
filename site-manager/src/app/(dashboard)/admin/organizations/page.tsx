import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { redirect } from "next/navigation";
import Link from "next/link";
import { Card, CardHeader, CardTitle } from "@/components/ui/card";

export default async function OrganizationsPage() {
  const session = await auth();
  if (!session?.user) redirect("/login");

  const user = session.user as { role: string };
  if (user.role !== "ignyte_staff") redirect("/sites");

  const organizations = await prisma.organization.findMany({
    include: {
      _count: { select: { users: true, sites: true } },
      subscription: true,
    },
    orderBy: { createdAt: "desc" },
  });

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold">Organizations</h1>
        <Link
          href="/admin/organizations/new"
          className="px-4 py-2 bg-[#E87722] text-white rounded-lg hover:bg-[#d06a1e] transition-colors"
        >
          New Organization
        </Link>
      </div>

      <div className="grid gap-4">
        {organizations.map((org) => (
          <Link key={org.id} href={`/admin/organizations/${org.id}`}>
            <Card className="hover:shadow-md transition-shadow cursor-pointer">
              <CardHeader>
                <div className="flex items-center justify-between">
                  <div>
                    <CardTitle>{org.name}</CardTitle>
                    <p className="text-sm text-gray-500 mt-1">/{org.slug}</p>
                  </div>
                  <div className="flex gap-4 text-sm text-gray-600">
                    <span>{org._count.users} users</span>
                    <span>{org._count.sites} sites</span>
                    {org.subscription && (
                      <span className="text-green-600">
                        {org.subscription.currentUsage}/{org.subscription.monthlyQuota} requests
                      </span>
                    )}
                  </div>
                </div>
              </CardHeader>
            </Card>
          </Link>
        ))}

        {organizations.length === 0 && (
          <p className="text-gray-500 text-center py-8">
            No organizations yet. Create one to get started.
          </p>
        )}
      </div>
    </div>
  );
}
