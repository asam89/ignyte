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

  const { requestId } = await params;
  const user = session.user as { id: string; role: string; organizationId: string | null };
  const isStaff = user.role === "ignyte_staff";
  const isAdmin = user.role === "client_admin";

  if (!isStaff && !isAdmin) {
    return NextResponse.json(
      { error: "Only admins can revert changes" },
      { status: 403 }
    );
  }

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

  // Can only revert merged requests
  if (changeRequest.status !== "merged") {
    return NextResponse.json(
      { error: "Can only revert merged change requests" },
      { status: 400 }
    );
  }

  if (!changeRequest.commitSha) {
    return NextResponse.json(
      { error: "No commit SHA recorded — cannot revert" },
      { status: 400 }
    );
  }

  try {
    const adapter = getAdapter(changeRequest.site);
    await adapter.revert(changeRequest.site, changeRequest.commitSha);

    await prisma.changeRequest.update({
      where: { id: requestId },
      data: { status: "reverted" },
    });

    await prisma.auditLog.create({
      data: {
        action: "change_request.reverted",
        details: {
          requestId,
          commitSha: changeRequest.commitSha,
          revertedBy: user.id,
        },
        userId: user.id,
        organizationId: changeRequest.site.organizationId,
      },
    });

    return NextResponse.json({ status: "reverted" });
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : "Revert failed";
    return NextResponse.json(
      { error: errorMessage },
      { status: 500 }
    );
  }
}
