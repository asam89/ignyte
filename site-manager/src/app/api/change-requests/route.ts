import { NextResponse } from "next/server";
import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { z } from "zod";

const createRequestSchema = z.object({
  siteId: z.string().min(1),
  prompt: z.string().min(1).max(5000),
});

export async function POST(request: Request) {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const user = session.user as { id: string; role: string; organizationId: string | null };
  const isStaff = user.role === "ignyte_staff";

  const body = await request.json();
  const parsed = createRequestSchema.safeParse(body);

  if (!parsed.success) {
    return NextResponse.json(
      { error: "Invalid input", details: parsed.error.flatten() },
      { status: 400 }
    );
  }

  const { siteId, prompt } = parsed.data;

  // Verify site access
  const site = await prisma.site.findFirst({
    where: {
      id: siteId,
      ...(isStaff ? {} : { organizationId: user.organizationId! }),
    },
    include: { organization: { include: { subscription: true } } },
  });

  if (!site) {
    return NextResponse.json({ error: "Site not found" }, { status: 404 });
  }

  // Check quota
  const subscription = site.organization.subscription;
  if (subscription) {
    const countOnSubmit = !subscription.countOnMerge;
    if (countOnSubmit && subscription.currentUsage >= subscription.monthlyQuota) {
      return NextResponse.json(
        {
          error: "Monthly quota exceeded. Please upgrade your plan or wait for quota reset.",
          quota: subscription.monthlyQuota,
          usage: subscription.currentUsage,
        },
        { status: 429 }
      );
    }
  }

  // Create change request
  const changeRequest = await prisma.changeRequest.create({
    data: {
      prompt,
      status: "pending",
      siteId,
      requestedById: user.id,
    },
  });

  // Audit log
  await prisma.auditLog.create({
    data: {
      action: "change_request.created",
      details: { changeRequestId: changeRequest.id, prompt },
      userId: user.id,
      organizationId: site.organizationId,
    },
  });

  // Trigger the async pipeline (in production this would use a queue)
  // For now we'll trigger it via a separate API call
  fetch(`${process.env.NEXT_PUBLIC_APP_URL || "http://localhost:3000"}/api/change-requests/${changeRequest.id}/process`, {
    method: "POST",
    headers: { "x-internal-secret": process.env.INTERNAL_API_SECRET || "" },
  }).catch(() => {
    // Fire and forget - errors handled in the pipeline
  });

  return NextResponse.json(changeRequest, { status: 201 });
}
