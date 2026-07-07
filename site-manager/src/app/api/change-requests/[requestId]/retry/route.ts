import { NextResponse } from "next/server";
import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { enqueueJob } from "@/lib/job-queue";
import "@/lib/pipeline"; // registers the handler

export async function POST(
  _request: Request,
  { params }: { params: Promise<{ requestId: string }> }
) {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const { requestId } = await params;
  const user = session.user as { id: string; role: string; organizationId: string | null };
  const isStaff = user.role === "ignyte_staff";

  const changeRequest = await prisma.changeRequest.findUnique({
    where: { id: requestId },
    include: { site: true },
  });

  if (!changeRequest) {
    return NextResponse.json({ error: "Not found" }, { status: 404 });
  }

  // Verify org access
  if (!isStaff && changeRequest.site.organizationId !== user.organizationId) {
    return NextResponse.json({ error: "Forbidden" }, { status: 403 });
  }

  // Can only retry failed or rejected requests
  if (!["failed", "rejected"].includes(changeRequest.status)) {
    return NextResponse.json(
      { error: "Can only retry failed or rejected requests" },
      { status: 400 }
    );
  }

  // Reset the request state
  await prisma.changeRequest.update({
    where: { id: requestId },
    data: {
      status: "pending",
      errorMessage: null,
      flagged: false,
      flagReason: null,
      branchName: null,
      prUrl: null,
      prNumber: null,
      previewUrl: null,
      generatedDiff: null,
      commitSha: null,
    },
  });

  // Enqueue for processing
  const jobId = await enqueueJob("process_change_request", { requestId });

  await prisma.auditLog.create({
    data: {
      action: "change_request.retried",
      details: { requestId, jobId, retriedBy: user.id },
      userId: user.id,
      organizationId: changeRequest.site.organizationId,
    },
  });

  return NextResponse.json({ status: "requeued", jobId });
}
