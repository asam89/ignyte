import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { redirect, notFound } from "next/navigation";
import Link from "next/link";
import { Card } from "@/components/ui/card";
import { RequestActions } from "./request-actions";

export default async function RequestDetailPage({
  params,
}: {
  params: Promise<{ siteId: string; requestId: string }>;
}) {
  const session = await auth();
  if (!session?.user) redirect("/login");

  const { siteId, requestId } = await params;
  const user = session.user as { id: string; role: string; organizationId: string | null };
  const isStaff = user.role === "ignyte_staff";

  const request = await prisma.changeRequest.findFirst({
    where: {
      id: requestId,
      siteId,
      site: isStaff ? {} : { organizationId: user.organizationId! },
    },
    include: {
      site: true,
      requestedBy: true,
      approvedBy: true,
    },
  });

  if (!request) notFound();

  const canApprove =
    (request.status === "preview_ready") &&
    (isStaff || user.role === "client_admin");

  const statusColors: Record<string, string> = {
    pending: "bg-yellow-100 text-yellow-800",
    generating: "bg-blue-100 text-blue-800",
    preview_ready: "bg-purple-100 text-purple-800",
    approved: "bg-green-100 text-green-800",
    rejected: "bg-red-100 text-red-800",
    merged: "bg-emerald-100 text-emerald-800",
    reverted: "bg-gray-100 text-gray-800",
    failed: "bg-red-100 text-red-800",
  };

  return (
    <div className="max-w-4xl">
      {/* Breadcrumb */}
      <div className="mb-6">
        <Link
          href={`/sites/${siteId}`}
          className="text-sm text-gray-500 hover:text-gray-700"
        >
          ← Back to {request.site.name}
        </Link>
      </div>

      {/* Header */}
      <div className="mb-6 flex items-start justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold text-[#1A1A2E]">Change Request</h1>
          <p className="mt-1 text-sm text-gray-600">
            Submitted by {request.requestedBy.name || request.requestedBy.email} on{" "}
            {new Date(request.createdAt).toLocaleString()}
          </p>
        </div>
        <span
          className={`rounded-full px-3 py-1 text-sm font-medium ${
            statusColors[request.status] || "bg-gray-100 text-gray-800"
          }`}
        >
          {request.status.replace("_", " ")}
        </span>
      </div>

      {/* Prompt */}
      <Card className="mb-6">
        <h2 className="mb-2 text-sm font-semibold text-gray-700">Request</h2>
        <p className="text-gray-900">{request.prompt}</p>
      </Card>

      {/* Preview URL */}
      {request.previewUrl && (
        <Card className="mb-6">
          <h2 className="mb-2 text-sm font-semibold text-gray-700">Preview</h2>
          <a
            href={request.previewUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="text-[#E87722] hover:underline"
          >
            {request.previewUrl} ↗
          </a>
        </Card>
      )}

      {/* Diff */}
      {request.generatedDiff && (
        <Card className="mb-6">
          <h2 className="mb-2 text-sm font-semibold text-gray-700">Generated Diff</h2>
          <pre className="overflow-x-auto rounded-lg bg-gray-900 p-4 text-xs text-gray-100">
            <code>{request.generatedDiff}</code>
          </pre>
        </Card>
      )}

      {/* Error */}
      {request.errorMessage && (
        <Card className="mb-6 border-red-200 bg-red-50">
          <h2 className="mb-2 text-sm font-semibold text-red-700">Error</h2>
          <p className="text-sm text-red-600">{request.errorMessage}</p>
        </Card>
      )}

      {/* Flagged */}
      {request.flagged && (
        <Card className="mb-6 border-amber-200 bg-amber-50">
          <h2 className="mb-2 text-sm font-semibold text-amber-700">
            Flagged for Staff Review
          </h2>
          <p className="text-sm text-amber-600">
            {request.flagReason || "This change requires Ignyte staff approval."}
          </p>
        </Card>
      )}

      {/* Actions */}
      {(canApprove || request.status === "merged" || request.status === "failed" || request.status === "rejected") && (
        <RequestActions requestId={request.id} status={request.status} />
      )}

      {/* Merge info */}
      {request.status === "merged" && request.commitSha && (
        <Card className="border-emerald-200 bg-emerald-50">
          <h2 className="mb-2 text-sm font-semibold text-emerald-700">
            Merged to Production
          </h2>
          <p className="text-sm text-emerald-600">
            Commit: <code className="font-mono">{request.commitSha}</code>
          </p>
          {request.approvedBy && (
            <p className="mt-1 text-sm text-emerald-600">
              Approved by {request.approvedBy.name || request.approvedBy.email}
            </p>
          )}
        </Card>
      )}

      {/* Reverted info */}
      {request.status === "reverted" && (
        <Card className="border-gray-200 bg-gray-50">
          <h2 className="mb-2 text-sm font-semibold text-gray-700">
            Reverted
          </h2>
          <p className="text-sm text-gray-600">
            This change was reverted from production.
          </p>
        </Card>
      )}
    </div>
  );
}
