import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { redirect } from "next/navigation";
import Link from "next/link";
import { Card, CardHeader, CardTitle } from "@/components/ui/card";

export default async function SitesPage() {
  const session = await auth();
  if (!session?.user) redirect("/login");

  const user = session.user as { id: string; role: string; organizationId: string | null };
  const isStaff = user.role === "ignyte_staff";

  const sites = await prisma.site.findMany({
    where: isStaff ? {} : { organizationId: user.organizationId! },
    include: {
      organization: true,
      _count: { select: { changeRequests: true } },
    },
    orderBy: { updatedAt: "desc" },
  });

  return (
    <div>
      <div className="mb-8 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-[#1A1A2E]">Your Sites</h1>
          <p className="mt-1 text-sm text-gray-600">
            Select a site to submit content change requests
          </p>
        </div>
      </div>

      {sites.length === 0 ? (
        <Card className="text-center py-12">
          <p className="text-gray-500">No sites configured yet.</p>
          {isStaff && (
            <Link
              href="/admin/onboard"
              className="mt-4 inline-block text-sm text-[#E87722] hover:underline"
            >
              Onboard a site →
            </Link>
          )}
        </Card>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {sites.map((site) => (
            <Link key={site.id} href={`/sites/${site.id}`}>
              <Card className="hover:border-[#E87722] hover:shadow-md transition-all cursor-pointer h-full">
                <CardHeader>
                  <CardTitle>{site.name}</CardTitle>
                  {isStaff && (
                    <p className="text-xs text-gray-400">
                      {site.organization.name}
                    </p>
                  )}
                </CardHeader>
                <div className="space-y-2">
                  <div className="flex items-center gap-2 text-sm text-gray-600">
                    <span className="inline-block rounded bg-gray-100 px-2 py-0.5 text-xs font-medium">
                      {site.siteType.replace("_", " ")}
                    </span>
                  </div>
                  {site.productionUrl && (
                    <p className="text-xs text-gray-400 truncate">
                      {site.productionUrl}
                    </p>
                  )}
                  <p className="text-xs text-gray-500">
                    {site._count.changeRequests} change request
                    {site._count.changeRequests !== 1 ? "s" : ""}
                  </p>
                </div>
              </Card>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
