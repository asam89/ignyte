import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { redirect } from "next/navigation";
import Link from "next/link";
import { Card, CardTitle } from "@/components/ui/card";

export default async function AdminPage() {
  const session = await auth();
  if (!session?.user) redirect("/login");
  if ((session.user as { role: string }).role !== "ignyte_staff") redirect("/sites");

  const [orgCount, siteCount, requestCount, pendingReview] = await Promise.all([
    prisma.organization.count(),
    prisma.site.count(),
    prisma.changeRequest.count(),
    prisma.changeRequest.count({
      where: { OR: [{ status: "preview_ready" }, { flagged: true }] },
    }),
  ]);

  return (
    <div>
      <h1 className="mb-8 text-2xl font-bold text-[#1A1A2E]">Admin Dashboard</h1>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-8">
        <Card>
          <p className="text-sm text-gray-500">Organizations</p>
          <p className="mt-1 text-3xl font-bold text-[#1A1A2E]">{orgCount}</p>
        </Card>
        <Card>
          <p className="text-sm text-gray-500">Sites</p>
          <p className="mt-1 text-3xl font-bold text-[#1A1A2E]">{siteCount}</p>
        </Card>
        <Card>
          <p className="text-sm text-gray-500">Total Requests</p>
          <p className="mt-1 text-3xl font-bold text-[#1A1A2E]">{requestCount}</p>
        </Card>
        <Card>
          <p className="text-sm text-gray-500">Pending Review</p>
          <p className="mt-1 text-3xl font-bold text-[#E87722]">{pendingReview}</p>
        </Card>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <Link href="/admin/onboard">
          <Card className="hover:border-[#E87722] transition-colors cursor-pointer">
            <CardTitle>Onboard a Site</CardTitle>
            <p className="mt-2 text-sm text-gray-600">
              Connect a new client repo and configure editable paths
            </p>
          </Card>
        </Link>
        <Link href="/admin/review">
          <Card className="hover:border-[#E87722] transition-colors cursor-pointer">
            <CardTitle>Review Queue</CardTitle>
            <p className="mt-2 text-sm text-gray-600">
              Review flagged and pending change requests
            </p>
          </Card>
        </Link>
      </div>
    </div>
  );
}
