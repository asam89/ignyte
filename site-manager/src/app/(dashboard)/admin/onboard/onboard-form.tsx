"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

interface OnboardFormProps {
  organizations: { id: string; name: string }[];
}

export function OnboardForm({ organizations }: OnboardFormProps) {
  const router = useRouter();
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setLoading(true);
    setError("");

    const formData = new FormData(e.currentTarget);
    const body = {
      name: formData.get("name"),
      siteType: formData.get("siteType"),
      repoOwner: formData.get("repoOwner"),
      repoName: formData.get("repoName"),
      productionUrl: formData.get("productionUrl"),
      organizationId: formData.get("organizationId"),
      editablePaths: (formData.get("editablePaths") as string)
        .split("\n")
        .map((s) => s.trim())
        .filter(Boolean),
    };

    try {
      const res = await fetch("/api/sites", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });

      if (!res.ok) {
        const data = await res.json();
        setError(data.error || "Failed to create site");
      } else {
        router.push("/admin");
      }
    } catch {
      setError("Network error");
    } finally {
      setLoading(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-6">
      {error && (
        <div className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{error}</div>
      )}

      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">
          Organization
        </label>
        <select
          name="organizationId"
          required
          className="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-[#E87722] focus:outline-none focus:ring-1 focus:ring-[#E87722]"
        >
          <option value="">Select organization...</option>
          {organizations.map((org) => (
            <option key={org.id} value={org.id}>
              {org.name}
            </option>
          ))}
        </select>
      </div>

      <Input id="name" name="name" label="Site Name" placeholder="e.g. Baseera.ca" required />

      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">
          Site Type
        </label>
        <select
          name="siteType"
          required
          className="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-[#E87722] focus:outline-none focus:ring-1 focus:ring-[#E87722]"
        >
          <option value="nextjs">Next.js</option>
          <option value="git_static">Git Static</option>
          <option value="wordpress">WordPress</option>
        </select>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <Input
          id="repoOwner"
          name="repoOwner"
          label="Repo Owner"
          placeholder="e.g. asam89"
          required
        />
        <Input
          id="repoName"
          name="repoName"
          label="Repo Name"
          placeholder="e.g. baseera-ca"
          required
        />
      </div>

      <Input
        id="productionUrl"
        name="productionUrl"
        label="Production URL"
        placeholder="https://baseera.ca"
        type="url"
      />

      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">
          Editable Paths (one per line)
        </label>
        <textarea
          name="editablePaths"
          rows={4}
          placeholder={"src/app/page.tsx\nsrc/content/**\npublic/images/**"}
          className="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-[#E87722] focus:outline-none focus:ring-1 focus:ring-[#E87722] resize-none"
        />
        <p className="mt-1 text-xs text-gray-500">
          Only files matching these paths can be edited by the AI. Glob patterns supported.
        </p>
      </div>

      <Button type="submit" disabled={loading} className="w-full">
        {loading ? "Creating..." : "Create Site"}
      </Button>
    </form>
  );
}
