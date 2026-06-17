import type { Site } from "@/generated/prisma/client";
import type { SiteAdapter } from "./types";
import { GitNextjsAdapter } from "./git-nextjs-adapter";
import { WordPressAdapter } from "./wordpress-adapter";

export function getAdapter(site: Site): SiteAdapter {
  switch (site.siteType) {
    case "git_static":
    case "nextjs":
      return new GitNextjsAdapter();
    case "wordpress":
      return new WordPressAdapter();
    default:
      throw new Error(`Unsupported site type: ${site.siteType}`);
  }
}

export type { SiteAdapter, Branch, PullRequest, FileEdit } from "./types";
