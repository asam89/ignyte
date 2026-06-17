import Anthropic from "@anthropic-ai/sdk";
import type { Site } from "@/generated/prisma/client";
import type { FileEdit } from "@/adapters/types";
import { prisma } from "./prisma";
import { Octokit } from "octokit";

const SYSTEM_PROMPT = `You edit content on a client's existing website. You are given the current file
contents (limited to editable files), the client's brand/context documents, and a
plain-English change request. Make ONLY the requested content change.

ALLOWED: editing visible text, copy, headings, list items, inline markup, alt text,
and swapping image/logo/file URLs to ones provided.
FORBIDDEN: changing page layout/structure, adding/removing sections or components,
editing scripts, styles beyond inline content, config, routing, build files, or
dependencies. Do not introduce new pages.

Return only the files you changed, each as its full updated contents. If the request
would require a forbidden/structural change, do NOT attempt it — return a short
explanation that it needs Ignyte staff instead. Match the client's existing tone
using their context documents.

Respond in JSON format:
{
  "files": [
    { "path": "relative/path/to/file.tsx", "content": "full file content here" }
  ]
}

Or if the change is forbidden:
{
  "refused": true,
  "reason": "This change requires structural layout modifications that need Ignyte staff."
}`;

interface EditResult {
  files: FileEdit[];
  refused?: false;
  refusalReason?: undefined;
}

interface RefusedResult {
  files: FileEdit[];
  refused: true;
  refusalReason: string;
}

export async function generateEdit(
  site: Site,
  prompt: string
): Promise<EditResult | RefusedResult> {
  const anthropic = new Anthropic({
    apiKey: process.env.ANTHROPIC_API_KEY,
  });

  // Gather context
  const repoContext = await getRepoFileContents(site);
  const contextDocs = await getContextDocs(site);

  const userMessage = `## Current File Contents (editable files only)

${repoContext}

## Context Documents (brand/guidelines)

${contextDocs}

## Change Request

${prompt}`;

  const response = await anthropic.messages.create({
    model: "claude-sonnet-4-6",
    max_tokens: 8192,
    system: SYSTEM_PROMPT,
    messages: [{ role: "user", content: userMessage }],
  });

  // Parse the response
  const textBlock = response.content.find((b) => b.type === "text");
  if (!textBlock || textBlock.type !== "text") {
    throw new Error("No text response from AI");
  }

  const text = textBlock.text.trim();

  // Extract JSON from potential markdown code blocks
  const jsonMatch = text.match(/```(?:json)?\s*([\s\S]*?)```/) || [null, text];
  const jsonStr = jsonMatch[1]?.trim() || text;

  const parsed = JSON.parse(jsonStr);

  if (parsed.refused) {
    return {
      files: [],
      refused: true,
      refusalReason: parsed.reason || "Change requires Ignyte staff",
    };
  }

  return {
    files: parsed.files || [],
  };
}

async function getRepoFileContents(site: Site): Promise<string> {
  if (!site.repoOwner || !site.repoName) return "(no repo configured)";

  const token = process.env.GITHUB_APP_TOKEN;
  if (!token) return "(GitHub token not configured)";

  const octokit = new Octokit({ auth: token });
  const owner = site.repoOwner;
  const repo = site.repoName;
  const editablePaths = site.editablePaths;

  if (editablePaths.length === 0) return "(no editable paths configured)";

  const contents: string[] = [];

  for (const filePath of editablePaths) {
    // Skip glob patterns for now — only fetch exact file paths
    if (filePath.includes("*")) continue;

    try {
      const { data } = await octokit.rest.repos.getContent({
        owner,
        repo,
        path: filePath,
      });

      if ("content" in data && data.type === "file") {
        const decoded = Buffer.from(data.content, "base64").toString("utf-8");
        contents.push(`### ${filePath}\n\`\`\`\n${decoded}\n\`\`\``);
      }
    } catch {
      // File not found, skip
    }
  }

  return contents.length > 0 ? contents.join("\n\n") : "(no editable files found)";
}

async function getContextDocs(site: Site): Promise<string> {
  const docs = await prisma.contextDoc.findMany({
    where: { siteId: site.id },
    select: { name: true, extractedText: true },
  });

  if (docs.length === 0) return "(no context documents uploaded)";

  return docs
    .filter((d) => d.extractedText)
    .map((d) => `### ${d.name}\n${d.extractedText}`)
    .join("\n\n");
}
