import type { Site } from "@/generated/prisma/client";

export interface Branch {
  name: string;
  sha: string;
}

export interface PullRequest {
  number: number;
  url: string;
  title: string;
}

export interface FileEdit {
  path: string;
  content: string;
}

export interface SiteAdapter {
  createBranch(site: Site, name: string): Promise<Branch>;
  applyEdit(site: Site, branch: Branch, files: FileEdit[]): Promise<void>;
  openPullRequest(site: Site, branch: Branch, summary: string): Promise<PullRequest>;
  getPreviewUrl(site: Site, pr: PullRequest): Promise<string>;
  merge(site: Site, pr: PullRequest): Promise<{ commitSha: string }>;
  revert(site: Site, commitSha: string): Promise<void>;
}
