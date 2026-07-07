import { NextResponse } from "next/server";
import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { enqueueJob } from "@/lib/job-queue";
import { z } from "zod";
import "@/lib/pipeline"; // registers the handler

const createRequestSchema = z.object({
  siteId: z.string().min(1),
  prompt: z.string().min(1).max(5000),
});

export async function GET(request: Request) {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const user = session.user as { id: string; role: string; organizationId: string | null };
  const isStaff = user.role === "ignyte_staff";

  const { searchParams } = new URL(request.url);
  const siteId = searchParams.get("siteId");
  const status = searchParams.get("status");
  const limit = Math.min(parseInt(searchParams.get("limit") || "50"), 100);
  const offset = parseInt(searchParams.get("offset") || "0");

  const where: Record<string, unknown> = {};

  if (siteId) {
    where.siteId = siteId;
  }

  if (status) {
    where.status = status;
  }

  // Tenant isolation
  if (!isStaff) {
    where.site = { organizationId: user.organizationId };
  }

  const [requests, total] = await Promise.all([
    prisma.changeRequest.findMany({
      where,
      include: {
        site: { select: { name: true, organizationId: true } },
        requestedBy: { select: { email: true, name: true } },
      },
      orderBy: { createdAt: "desc" },
      take: limit,
      skip: offset,
    }),
    prisma.changeRequest.count({ where }),
  ]);

  return NextResponse.json({ requests, total, limit, offset });
}

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
          resetDate: subscription.quotaResetDate,
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

  // Enqueue for async processing
  const jobId = await enqueueJob("process_change_request", {
    requestId: changeRequest.id,
  });

  return NextResponse.json(
    { ...changeRequest, jobId },
    { status: 201 }
  );
}
