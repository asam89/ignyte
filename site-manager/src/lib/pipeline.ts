/**
 * Change-request processing pipeline.
 * Orchestrates: AI edit → diff validation → branch → PR → preview → status update
 */

import { prisma } from "./prisma";
import { getAdapter } from "@/adapters";
import { generateEdit } from "./ai";
import { validateDiff } from "./diff-validator";
import { registerHandler } from "./job-queue";

export interface PipelineResult {
  status: "preview_ready" | "flagged" | "refused" | "failed";
  prUrl?: string;
  previewUrl?: string;
  reason?: string;
  error?: string;
}

async function processChangeRequest(payload: Record<string, unknown>): Promise<void> {
  const requestId = payload.requestId as string;

  const changeRequest = await prisma.changeRequest.findUnique({
    where: { id: requestId },
    include: {
      site: true,
      requestedBy: true,
    },
  });

  if (!changeRequest) {
    throw new Error(`Change request ${requestId} not found`);
  }

  // Update status to generating
  await prisma.changeRequest.update({
    where: { id: requestId },
    data: { status: "generating" },
  });

  const site = changeRequest.site;
  const adapter = getAdapter(site);

  // Step 1: Generate the AI edit
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

    await prisma.auditLog.create({
      data: {
        action: "change_request.refused",
        details: { requestId, reason: editResult.refusalReason },
        userId: changeRequest.requestedById,
        organizationId: site.organizationId,
      },
    });
    return;
  }

  // Step 2: Validate the diff against allowlist
  const validation = validateDiff(site, editResult.files);

  if (!validation.valid) {
    // Flagged for staff review — still create PR for visibility
    await prisma.changeRequest.update({
      where: { id: requestId },
      data: {
        status: "preview_ready",
        flagged: true,
        flagReason: validation.reason,
        generatedDiff: JSON.stringify(editResult.files, null, 2),
      },
    });

    await prisma.auditLog.create({
      data: {
        action: "change_request.flagged",
        details: { requestId, reason: validation.reason },
        userId: changeRequest.requestedById,
        organizationId: site.organizationId,
      },
    });
    return;
  }

  // Step 3: Create branch
  const branchName = `ignyte/edit-${requestId.slice(0, 8)}-${Date.now()}`;
  const branch = await adapter.createBranch(site, branchName);

  // Step 4: Apply the edit
  await adapter.applyEdit(site, branch, editResult.files);

  // Step 5: Open PR
  const pr = await adapter.openPullRequest(
    site,
    branch,
    `[Ignyte Site Manager] Change request by ${changeRequest.requestedBy.email}\n\n**Prompt:** ${changeRequest.prompt}`
  );

  // Step 6: Get preview URL
  let previewUrl: string | null = null;
  try {
    previewUrl = await adapter.getPreviewUrl(site, pr);
  } catch {
    // Preview URL may not be immediately available — update later via webhook
  }

  // Step 7: Update the change request
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

  await prisma.auditLog.create({
    data: {
      action: "change_request.preview_ready",
      details: { requestId, prUrl: pr.url, previewUrl, branchName },
      userId: changeRequest.requestedById,
      organizationId: site.organizationId,
    },
  });
}

// Register the handler with the job queue
registerHandler("process_change_request", processChangeRequest);

export { processChangeRequest };
