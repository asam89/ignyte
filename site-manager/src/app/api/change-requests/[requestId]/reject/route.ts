import { NextResponse } from "next/server";
import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";

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

  if (user.role === "client_editor") {
    return NextResponse.json({ error: "Only admins can reject changes" }, { status: 403 });
  }

  const changeRequest = await prisma.changeRequest.findFirst({
    where: {
      id: requestId,
      status: "preview_ready",
      site: user.role === "ignyte_staff" ? {} : { organizationId: user.organizationId! },
    },
    include: { site: true },
  });

  if (!changeRequest) {
    return NextResponse.json({ error: "Request not found or not ready for review" }, { status: 404 });
  }

  await prisma.changeRequest.update({
    where: { id: requestId },
    data: {
      status: "rejected",
      approvedById: user.id, // records who rejected
    },
  });

  // Audit log
  await prisma.auditLog.create({
    data: {
      action: "change_request.rejected",
      details: { changeRequestId: requestId },
      userId: user.id,
      organizationId: changeRequest.site.organizationId,
    },
  });

  return NextResponse.json({ status: "rejected" });
}
