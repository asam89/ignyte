import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import crypto from "crypto";

/**
 * Vercel deployment webhook.
 * When a preview deploy finishes, updates the ChangeRequest with the preview URL.
 * Configure in Vercel project: Settings → Git → Deploy Hooks or Webhooks.
 */
export async function POST(request: Request) {
  const body = await request.text();

  // Verify webhook signature if configured
  const signature = request.headers.get("x-vercel-signature");
  if (process.env.VERCEL_WEBHOOK_SECRET && signature) {
    const hmac = crypto.createHmac("sha1", process.env.VERCEL_WEBHOOK_SECRET);
    hmac.update(body);
    const expected = hmac.digest("hex");
    if (signature !== expected) {
      return NextResponse.json({ error: "Invalid signature" }, { status: 401 });
    }
  }

  const payload = JSON.parse(body);

  // Only process deployment.ready events
  if (payload.type !== "deployment.ready") {
    return NextResponse.json({ status: "ignored" });
  }

  const deployment = payload.payload;
  const branch = deployment?.meta?.githubCommitRef;
  const previewUrl = deployment?.url
    ? `https://${deployment.url}`
    : null;

  if (!branch || !previewUrl) {
    return NextResponse.json({ status: "ignored", reason: "no branch or url" });
  }

  // Match against pending change requests by branch name
  if (branch.startsWith("ignyte/edit-")) {
    const changeRequest = await prisma.changeRequest.findFirst({
      where: {
        branchName: branch,
        status: "preview_ready",
        previewUrl: null,
      },
    });

    if (changeRequest) {
      await prisma.changeRequest.update({
        where: { id: changeRequest.id },
        data: { previewUrl },
      });

      return NextResponse.json({ status: "updated", requestId: changeRequest.id });
    }
  }

  return NextResponse.json({ status: "no_match" });
}
