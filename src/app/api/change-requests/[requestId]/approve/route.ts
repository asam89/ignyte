import { NextResponse } from "next/server";
import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { getAdapter } from "@/adapters";

export async function POST(
  _request: Request,
  { params }: { params: Promise<{ requestId: string }> }
) {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const user = session.user as { id: string; role: string; organizationId: string | null };
  const { requestId } = await params;

  // Only admins/staff can approve
  if (user.role === "client_editor") {
    return NextResponse.json({ error: "Only admins can approve changes" }, { status: 403 });
  }

  const changeRequest = await prisma.changeRequest.findFirst({
    where: {
      id: requestId,
      status: "preview_ready",
      site: user.role === "ignyte_staff" ? {} : { organizationId: user.organizationId! },
    },
    include: { site: { include: { organization: { include: { subscription: true } } } } },
  });

  if (!changeRequest) {
    return NextResponse.json({ error: "Request not found or not ready for approval" }, { status: 404 });
  }

  // If flagged, only staff can approve
  if (changeRequest.flagged && user.role !== "ignyte_staff") {
    return NextResponse.json({ error: "Flagged requests require Ignyte staff approval" }, { status: 403 });
  }

  try {
    const adapter = getAdapter(changeRequest.site);

    // Merge the PR
    const { commitSha } = await adapter.merge(changeRequest.site, {
      number: changeRequest.prNumber!,
      url: changeRequest.prUrl!,
      title: "",
    });

    // Update change request
    await prisma.changeRequest.update({
      where: { id: requestId },
      data: {
        status: "merged",
        approvedById: user.id,
        commitSha,
      },
    });

    // Increment usage if counting on merge
    const subscription = changeRequest.site.organization.subscription;
    if (subscription?.countOnMerge) {
      await prisma.subscription.update({
        where: { id: subscription.id },
        data: { currentUsage: { increment: 1 } },
      });
    }

    // Audit log
    await prisma.auditLog.create({
      data: {
        action: "change_request.merged",
        details: { changeRequestId: requestId, commitSha },
        userId: user.id,
        organizationId: changeRequest.site.organizationId,
      },
    });

    return NextResponse.json({ status: "merged", commitSha });
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : "Merge failed";
    return NextResponse.json({ error: errorMessage }, { status: 500 });
  }
}
