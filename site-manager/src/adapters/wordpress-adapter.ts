import type { Site } from "@/generated/prisma/client";
import type { SiteAdapter, Branch, PullRequest, FileEdit } from "./types";

/**
 * WordPress adapter stub — implements SiteAdapter interface for WordPress sites.
 * Edits are made via the WP REST API instead of Git.
 * To be fully implemented when WordPress clients are onboarded.
 */
export class WordPressAdapter implements SiteAdapter {
  async createBranch(_site: Site, _name: string): Promise<Branch> {
    // WordPress doesn't use branches — this creates a "draft revision"
    throw new Error("WordPressAdapter.createBranch not yet implemented");
  }

  async applyEdit(_site: Site, _branch: Branch, _files: FileEdit[]): Promise<void> {
    // Would use WP REST API to update page/post content
    throw new Error("WordPressAdapter.applyEdit not yet implemented");
  }

  async openPullRequest(
    _site: Site,
    _branch: Branch,
    _summary: string
  ): Promise<PullRequest> {
    // WordPress doesn't have PRs — this would create a staged revision
    throw new Error("WordPressAdapter.openPullRequest not yet implemented");
  }

  async getPreviewUrl(_site: Site, _pr: PullRequest): Promise<string> {
    // Would return the WP preview URL for the draft revision
    throw new Error("WordPressAdapter.getPreviewUrl not yet implemented");
  }

  async merge(_site: Site, _pr: PullRequest): Promise<{ commitSha: string }> {
    // Would publish the draft revision
    throw new Error("WordPressAdapter.merge not yet implemented");
  }

  async revert(_site: Site, _commitSha: string): Promise<void> {
    // Would restore the previous revision via WP REST API
    throw new Error("WordPressAdapter.revert not yet implemented");
  }
}
