import type { Site } from "@/generated/prisma/client";
import type { FileEdit } from "@/adapters/types";

interface ValidationResult {
  valid: boolean;
  reason?: string;
}

const FORBIDDEN_EXTENSIONS = [
  ".config.js",
  ".config.ts",
  ".config.mjs",
  "package.json",
  "package-lock.json",
  "tsconfig.json",
  ".env",
  ".env.local",
  "next.config",
  "tailwind.config",
  "postcss.config",
];

const FORBIDDEN_PATTERNS = [
  /import\s+.*from\s+['"][^'"]+['"]/g, // new imports (adding dependencies)
  /require\s*\(/g,
  /export\s+default\s+function/g, // new component exports
  /<script[\s>]/gi,
];

const MAX_LINES_CHANGED = 100;

export function validateDiff(site: Site, files: FileEdit[]): ValidationResult {
  const editablePaths = site.editablePaths;

  for (const file of files) {
    // Check against forbidden extensions
    const isForbidden = FORBIDDEN_EXTENSIONS.some((ext) =>
      file.path.endsWith(ext) || file.path.includes(ext)
    );
    if (isForbidden) {
      return {
        valid: false,
        reason: `File "${file.path}" is a configuration file and cannot be edited.`,
      };
    }

    // Check against editable allowlist (if configured)
    if (editablePaths.length > 0) {
      const isAllowed = editablePaths.some((pattern) => {
        if (pattern.includes("*")) {
          // Simple glob matching
          const regex = new RegExp(
            "^" + pattern.replace(/\*\*/g, ".*").replace(/\*/g, "[^/]*") + "$"
          );
          return regex.test(file.path);
        }
        return file.path === pattern || file.path.startsWith(pattern + "/");
      });

      if (!isAllowed) {
        return {
          valid: false,
          reason: `File "${file.path}" is outside the allowed editable paths.`,
        };
      }
    }

    // Check file size (reject unreasonably large changes)
    const lineCount = file.content.split("\n").length;
    if (lineCount > MAX_LINES_CHANGED * 10) {
      return {
        valid: false,
        reason: `File "${file.path}" has ${lineCount} lines — exceeds safety threshold.`,
      };
    }

    // Check for forbidden patterns (structural changes)
    for (const pattern of FORBIDDEN_PATTERNS) {
      // Reset regex state
      pattern.lastIndex = 0;
      const matches = file.content.match(pattern);
      // This is a heuristic — we'd compare against the original to detect NEW additions
      // For now, we allow existing patterns but flag if the file looks like it has
      // too many structural elements added. This is refined in later milestones.
      if (matches && matches.length > 20) {
        return {
          valid: false,
          reason: `File "${file.path}" appears to contain structural changes (many imports/exports detected).`,
        };
      }
    }
  }

  return { valid: true };
}
