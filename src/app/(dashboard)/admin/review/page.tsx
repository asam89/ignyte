import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { redirect } from "next/navigation";
import Link from "next/link";
import { Card } from "@/components/ui/card";

export default async function ReviewQueuePage() {
  const session = await auth();
  if (!session?.user) redirect("/login");
  if ((session.user as { role: string }).role !== "ignyte_staff") redirect("/sites");

  const requests = await prisma.changeRequest.findMany({
    where: {
      OR: [
        { status: "preview_ready" },
        { flagged: true, status: { not: "merged" } },
      ],
    },
    include: {
      site: { include: { organization: true } },
      requestedBy: true,
    },
    orderBy: { createdAt: "asc" },
  });

  return (
    <div>
      <h1 className="mb-2 text-2xl font-bold text-[#1A1A2E]">Review Queue</h1>
      <p className="mb-8 text-sm text-gray-600">
        Change requests awaiting approval or flagged for staff review.
      </p>

      {requests.length === 0 ? (
        <Card className="text-center py-12">
          <p className="text-gray-500">No pending reviews.</p>
        </Card>
      ) : (
        <div className="space-y-3">
          {requests.map((req) => (
            <Link
              key={req.id}
              href={`/sites/${req.siteId}/requests/${req.id}`}
              className="block"
            >
              <Card className="hover:border-[#E87722] transition-colors">
                <div className="flex items-start justify-between gap-4">
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-semibold text-[#1A1A2E]">
                        {req.site.name}
                      </span>
                      <span className="text-xs text-gray-400">
                        ({req.site.organization.name})
                      </span>
                    </div>
                    <p className="mt-1 truncate text-sm text-gray-700">
                      {req.prompt}
                    </p>
                    <p className="mt-1 text-xs text-gray-500">
                      by {req.requestedBy.name || req.requestedBy.email} ·{" "}
                      {new Date(req.createdAt).toLocaleDateString()}
                    </p>
                  </div>
                  <div className="flex flex-col items-end gap-1">
                    {req.flagged && (
                      <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                        flagged
                      </span>
                    )}
                    <span className="rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-800">
                      {req.status.replace("_", " ")}
                    </span>
                  </div>
                </div>
              </Card>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
