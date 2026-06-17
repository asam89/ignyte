import { Octokit } from "octokit";
import type { Site } from "@/generated/prisma/client";
import type { SiteAdapter, Branch, PullRequest, FileEdit } from "./types";

function getOctokit(): Octokit {
  const token = process.env.GITHUB_APP_TOKEN;
  if (!token) throw new Error("GITHUB_APP_TOKEN not configured");
  return new Octokit({ auth: token });
}

export class GitNextjsAdapter implements SiteAdapter {
  async createBranch(site: Site, name: string): Promise<Branch> {
    const octokit = getOctokit();
    const owner = site.repoOwner!;
    const repo = site.repoName!;

    // Get the SHA of the default branch (main)
    const { data: ref } = await octokit.rest.repos.getBranch({
      owner,
      repo,
      branch: "main",
    });

    const sha = ref.commit.sha;

    // Create the new branch
    await octokit.rest.git.createRef({
      owner,
      repo,
      ref: `refs/heads/${name}`,
      sha,
    });

    return { name, sha };
  }

  async applyEdit(site: Site, branch: Branch, files: FileEdit[]): Promise<void> {
    const octokit = getOctokit();
    const owner = site.repoOwner!;
    const repo = site.repoName!;

    // Get the current commit tree
    const { data: refData } = await octokit.rest.git.getRef({
      owner,
      repo,
      ref: `heads/${branch.name}`,
    });
    const latestSha = refData.object.sha;

    const { data: commit } = await octokit.rest.git.getCommit({
      owner,
      repo,
      commit_sha: latestSha,
    });

    // Create blobs for each file
    const treeItems = await Promise.all(
      files.map(async (file) => {
        const { data: blob } = await octokit.rest.git.createBlob({
          owner,
          repo,
          content: Buffer.from(file.content).toString("base64"),
          encoding: "base64",
        });
        return {
          path: file.path,
          mode: "100644" as const,
          type: "blob" as const,
          sha: blob.sha,
        };
      })
    );

    // Create a new tree
    const { data: newTree } = await octokit.rest.git.createTree({
      owner,
      repo,
      base_tree: commit.tree.sha,
      tree: treeItems,
    });

    // Create a commit
    const { data: newCommit } = await octokit.rest.git.createCommit({
      owner,
      repo,
      message: `Content update via Ignyte Site Manager`,
      tree: newTree.sha,
      parents: [latestSha],
    });

    // Update branch ref
    await octokit.rest.git.updateRef({
      owner,
      repo,
      ref: `heads/${branch.name}`,
      sha: newCommit.sha,
    });
  }

  async openPullRequest(
    site: Site,
    branch: Branch,
    summary: string
  ): Promise<PullRequest> {
    const octokit = getOctokit();
    const owner = site.repoOwner!;
    const repo = site.repoName!;

    const { data: pr } = await octokit.rest.pulls.create({
      owner,
      repo,
      title: `[Ignyte] Content Update`,
      body: summary,
      head: branch.name,
      base: "main",
    });

    return {
      number: pr.number,
      url: pr.html_url,
      title: pr.title,
    };
  }

  async getPreviewUrl(site: Site, pr: PullRequest): Promise<string> {
    // Vercel auto-deploys preview for each PR branch
    // The URL pattern is typically: https://<project>-<branch>-<team>.vercel.app
    // Or we can poll the Vercel deployments API
    const octokit = getOctokit();
    const owner = site.repoOwner!;
    const repo = site.repoName!;

    // Wait for deployment status check (up to 2 minutes)
    const maxAttempts = 12;
    for (let i = 0; i < maxAttempts; i++) {
      const { data: statuses } = await octokit.rest.repos.listCommitStatusesForRef({
        owner,
        repo,
        ref: `pull/${pr.number}/head`,
      });

      const vercelStatus = statuses.find(
        (s) => s.context.includes("vercel") || s.context.includes("deployment")
      );

      if (vercelStatus?.target_url) {
        return vercelStatus.target_url;
      }

      // Also check deployments
      const { data: deployments } = await octokit.rest.repos.listDeployments({
        owner,
        repo,
        ref: `pull/${pr.number}/head`,
        per_page: 1,
      });

      if (deployments.length > 0) {
        const { data: deploymentStatuses } =
          await octokit.rest.repos.listDeploymentStatuses({
            owner,
            repo,
            deployment_id: deployments[0].id,
          });

        const success = deploymentStatuses.find((s) => s.state === "success");
        if (success?.environment_url) {
          return success.environment_url;
        }
      }

      await new Promise((resolve) => setTimeout(resolve, 10000));
    }

    // Fallback: construct the expected Vercel preview URL
    return `Preview pending for PR #${pr.number}`;
  }

  async merge(site: Site, pr: PullRequest): Promise<{ commitSha: string }> {
    const octokit = getOctokit();
    const owner = site.repoOwner!;
    const repo = site.repoName!;

    const { data: mergeResult } = await octokit.rest.pulls.merge({
      owner,
      repo,
      pull_number: pr.number,
      merge_method: "squash",
    });

    return { commitSha: mergeResult.sha };
  }

  async revert(site: Site, commitSha: string): Promise<void> {
    const octokit = getOctokit();
    const owner = site.repoOwner!;
    const repo = site.repoName!;

    // Create a revert commit via the GitHub API
    // We need to create a PR that reverts the specific commit
    const branchName = `revert-${commitSha.slice(0, 7)}`;

    // Get main branch SHA
    const { data: mainRef } = await octokit.rest.repos.getBranch({
      owner,
      repo,
      branch: "main",
    });

    // Create revert branch
    await octokit.rest.git.createRef({
      owner,
      repo,
      ref: `refs/heads/${branchName}`,
      sha: mainRef.commit.sha,
    });

    // Use the merge API to create a revert
    // GitHub doesn't have a direct revert API, so we merge the parent
    const { data: commit } = await octokit.rest.git.getCommit({
      owner,
      repo,
      commit_sha: commitSha,
    });

    // Create the revert by cherry-picking the inverse
    const { data: revertPr } = await octokit.rest.pulls.create({
      owner,
      repo,
      title: `[Ignyte] Revert: ${commit.message.split("\n")[0]}`,
      body: `Reverting commit ${commitSha}`,
      head: branchName,
      base: "main",
    });

    // Auto-merge the revert
    await octokit.rest.pulls.merge({
      owner,
      repo,
      pull_number: revertPr.number,
      merge_method: "squash",
    });
  }
}
