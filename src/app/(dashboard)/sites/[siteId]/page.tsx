import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { redirect, notFound } from "next/navigation";
import Link from "next/link";
import { Card, CardTitle } from "@/components/ui/card";
import { NewRequestForm } from "./new-request-form";

export default async function SiteDetailPage({
  params,
}: {
  params: Promise<{ siteId: string }>;
}) {
  const session = await auth();
  if (!session?.user) redirect("/login");

  const { siteId } = await params;
  const user = session.user as { id: string; role: string; organizationId: string | null };
  const isStaff = user.role === "ignyte_staff";

  const site = await prisma.site.findFirst({
    where: {
      id: siteId,
      ...(isStaff ? {} : { organizationId: user.organizationId! }),
    },
  });

  if (!site) notFound();

  const recentRequests = await prisma.changeRequest.findMany({
    where: { siteId: site.id },
    include: { requestedBy: true },
    orderBy: { createdAt: "desc" },
    take: 10,
  });

  const statusColors: Record<string, string> = {
    pending: "bg-yellow-100 text-yellow-800",
    generating: "bg-blue-100 text-blue-800",
    preview_ready: "bg-purple-100 text-purple-800",
    approved: "bg-green-100 text-green-800",
    rejected: "bg-red-100 text-red-800",
    merged: "bg-emerald-100 text-emerald-800",
    failed: "bg-red-100 text-red-800",
  };

  return (
    <div className="max-w-4xl">
      {/* Header */}
      <div className="mb-8">
        <Link href="/sites" className="text-sm text-gray-500 hover:text-gray-700">
          ← Back to sites
        </Link>
        <h1 className="mt-2 text-2xl font-bold text-[#1A1A2E]">{site.name}</h1>
        {site.productionUrl && (
          <a
            href={site.productionUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="text-sm text-[#E87722] hover:underline"
          >
            {site.productionUrl} ↗
          </a>
        )}
      </div>

      {/* New request form */}
      <Card className="mb-8">
        <CardTitle className="mb-4">Submit a Change Request</CardTitle>
        <NewRequestForm siteId={site.id} />
      </Card>

      {/* Recent requests */}
      <div>
        <h2 className="mb-4 text-lg font-semibold text-[#1A1A2E]">
          Recent Requests
        </h2>
        {recentRequests.length === 0 ? (
          <p className="text-sm text-gray-500">No requests yet.</p>
        ) : (
          <div className="space-y-3">
            {recentRequests.map((req) => (
              <Link
                key={req.id}
                href={`/sites/${site.id}/requests/${req.id}`}
                className="block"
              >
                <Card className="hover:border-gray-300 transition-colors">
                  <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium text-gray-900">
                        {req.prompt}
                      </p>
                      <p className="mt-1 text-xs text-gray-500">
                        by {req.requestedBy.name || req.requestedBy.email} ·{" "}
                        {new Date(req.createdAt).toLocaleDateString()}
                      </p>
                    </div>
                    <span
                      className={`inline-block whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ${
                        statusColors[req.status] || "bg-gray-100 text-gray-800"
                      }`}
                    >
                      {req.status.replace("_", " ")}
                    </span>
                  </div>
                </Card>
              </Link>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
