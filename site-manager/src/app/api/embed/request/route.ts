import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { verifyEmbedToken } from "@/lib/embed-token";
import { enqueueJob } from "@/lib/job-queue";
import { z } from "zod";
import "@/lib/pipeline";

const requestSchema = z.object({
  siteId: z.string().min(1),
  prompt: z.string().min(1).max(5000),
});

export async function POST(request: Request) {
  const authHeader = request.headers.get("authorization") || "";
  if (!authHeader.startsWith("Embed ")) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const token = authHeader.slice(6);
  const payload = verifyEmbedToken(token);
  if (!payload) {
    return NextResponse.json({ error: "Invalid or expired token" }, { status: 401 });
  }

  const body = await request.json();
  const parsed = requestSchema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json(
      { error: "Invalid input", details: parsed.error.flatten() },
      { status: 400 }
    );
  }

  const { siteId, prompt } = parsed.data;

  if (siteId !== payload.siteId) {
    return NextResponse.json({ error: "Site mismatch" }, { status: 403 });
  }

  // Check quota
  const site = await prisma.site.findFirst({
    where: { id: siteId, organizationId: payload.organizationId },
    include: { organization: { include: { subscription: true } } },
  });

  if (!site) {
    return NextResponse.json({ error: "Site not found" }, { status: 404 });
  }

  const subscription = site.organization.subscription;
  if (subscription) {
    const countOnSubmit = !subscription.countOnMerge;
    if (countOnSubmit && subscription.currentUsage >= subscription.monthlyQuota) {
      return NextResponse.json(
        { error: "Monthly quota exceeded. Please contact your account manager to upgrade." },
        { status: 429 }
      );
    }
  }

  const changeRequest = await prisma.changeRequest.create({
    data: {
      prompt,
      status: "pending",
      siteId,
      requestedById: payload.userId,
    },
  });

  await prisma.auditLog.create({
    data: {
      action: "change_request.created",
      details: { changeRequestId: changeRequest.id, prompt, source: "embed" },
      userId: payload.userId,
      organizationId: payload.organizationId,
    },
  });

  const jobId = await enqueueJob("process_change_request", {
    requestId: changeRequest.id,
  });

  return NextResponse.json(
    { id: changeRequest.id, status: changeRequest.status, jobId },
    { status: 201 }
  );
}
