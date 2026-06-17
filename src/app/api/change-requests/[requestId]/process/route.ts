import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { getAdapter } from "@/adapters";
import { generateEdit } from "@/lib/ai";
import { validateDiff } from "@/lib/diff-validator";

export async function POST(
  request: Request,
  { params }: { params: Promise<{ requestId: string }> }
) {
  // Verify internal secret to prevent external calls
  const secret = request.headers.get("x-internal-secret");
  if (secret !== process.env.INTERNAL_API_SECRET) {
    return NextResponse.json({ error: "Forbidden" }, { status: 403 });
  }

  const { requestId } = await params;

  const changeRequest = await prisma.changeRequest.findUnique({
    where: { id: requestId },
    include: {
      site: true,
      requestedBy: true,
    },
  });

  if (!changeRequest) {
    return NextResponse.json({ error: "Not found" }, { status: 404 });
  }

  // Update status to generating
  await prisma.changeRequest.update({
    where: { id: requestId },
    data: { status: "generating" },
  });

  try {
    const site = changeRequest.site;
    const adapter = getAdapter(site);

    // 1. Generate the AI edit
    const editResult = await generateEdit(site, changeRequest.prompt);

    if (editResult.refused) {
      await prisma.changeRequest.update({
        where: { id: requestId },
        data: {
          status: "failed",
          flagged: true,
          flagReason: editResult.refusalReason,
          errorMessage: editResult.refusalReason,
        },
      });
      return NextResponse.json({ status: "refused", reason: editResult.refusalReason });
    }

    // 2. Validate the diff against allowlist
    const validation = validateDiff(site, editResult.files);

    if (!validation.valid) {
      await prisma.changeRequest.update({
        where: { id: requestId },
        data: {
          status: "preview_ready",
          flagged: true,
          flagReason: validation.reason,
          generatedDiff: JSON.stringify(editResult.files, null, 2),
        },
      });
      return NextResponse.json({ status: "flagged", reason: validation.reason });
    }

    // 3. Create branch
    const branchName = `ignyte/edit-${requestId.slice(0, 8)}`;
    const branch = await adapter.createBranch(site, branchName);

    // 4. Apply the edit
    await adapter.applyEdit(site, branch, editResult.files);

    // 5. Open PR
    const pr = await adapter.openPullRequest(
      site,
      branch,
      `Change requested by ${changeRequest.requestedBy.email}:\n\n${changeRequest.prompt}`
    );

    // 6. Get preview URL
    const previewUrl = await adapter.getPreviewUrl(site, pr);

    // 7. Update the change request
    await prisma.changeRequest.update({
      where: { id: requestId },
      data: {
        status: "preview_ready",
        branchName,
        prUrl: pr.url,
        prNumber: pr.number,
        previewUrl,
        generatedDiff: JSON.stringify(editResult.files, null, 2),
      },
    });

    return NextResponse.json({ status: "preview_ready", prUrl: pr.url, previewUrl });
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : "Unknown error";

    await prisma.changeRequest.update({
      where: { id: requestId },
      data: {
        status: "failed",
        errorMessage,
      },
    });

    return NextResponse.json({ status: "failed", error: errorMessage }, { status: 500 });
  }
}
