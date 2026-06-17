"use client";

import { useState, useRef } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";

export function DocUpload({ siteId }: { siteId: string }) {
  const router = useRouter();
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  async function handleUpload(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;

    setUploading(true);
    setError(null);
    setSuccess(null);

    try {
      const formData = new FormData();
      formData.append("file", file);
      formData.append("name", file.name);

      const res = await fetch(`/api/sites/${siteId}/context-docs`, {
        method: "POST",
        body: formData,
      });

      if (!res.ok) {
        const data = await res.json();
        setError(data.error || "Upload failed");
      } else {
        const data = await res.json();
        setSuccess(
          data.hasText
            ? `Uploaded & text extracted successfully.`
            : `Uploaded (no text could be extracted from this file type).`
        );
        router.refresh();
      }
    } catch {
      setError("Network error — upload failed");
    } finally {
      setUploading(false);
      if (fileInputRef.current) {
        fileInputRef.current.value = "";
      }
    }
  }

  return (
    <div className="rounded-lg border-2 border-dashed border-gray-300 p-6 text-center">
      <input
        ref={fileInputRef}
        type="file"
        accept=".pdf,.docx,.txt,.md,.html"
        onChange={handleUpload}
        className="hidden"
        id="doc-upload"
      />
      <label htmlFor="doc-upload" className="cursor-pointer">
        <p className="text-gray-600 mb-2">
          {uploading ? "Uploading & extracting text..." : "Click to upload a context document"}
        </p>
        <p className="text-xs text-gray-400">
          PDF, DOCX, TXT, Markdown, HTML. Max 25MB. Text will be auto-extracted for AI context.
        </p>
      </label>
      <Button
        variant="secondary"
        size="sm"
        className="mt-3"
        disabled={uploading}
        onClick={() => fileInputRef.current?.click()}
      >
        {uploading ? "Processing..." : "Choose File"}
      </Button>
      {error && (
        <p className="mt-2 text-sm text-red-600">{error}</p>
      )}
      {success && (
        <p className="mt-2 text-sm text-green-600">{success}</p>
      )}
    </div>
  );
}
