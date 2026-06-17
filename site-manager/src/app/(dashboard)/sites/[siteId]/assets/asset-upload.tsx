"use client";

import { useState, useRef } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";

export function AssetUpload({ siteId }: { siteId: string }) {
  const router = useRouter();
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleUpload(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;

    setUploading(true);
    setError(null);

    try {
      const formData = new FormData();
      formData.append("file", file);
      formData.append("name", file.name);

      const res = await fetch(`/api/sites/${siteId}/assets`, {
        method: "POST",
        body: formData,
      });

      if (!res.ok) {
        const data = await res.json();
        setError(data.error || "Upload failed");
      } else {
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
        accept="image/*,application/pdf"
        onChange={handleUpload}
        className="hidden"
        id="asset-upload"
      />
      <label htmlFor="asset-upload" className="cursor-pointer">
        <p className="text-gray-600 mb-2">
          {uploading ? "Uploading..." : "Click to upload an asset"}
        </p>
        <p className="text-xs text-gray-400">
          Images (JPEG, PNG, GIF, WebP, SVG), PDF. Max 10MB.
        </p>
      </label>
      <Button
        variant="secondary"
        size="sm"
        className="mt-3"
        disabled={uploading}
        onClick={() => fileInputRef.current?.click()}
      >
        {uploading ? "Uploading..." : "Choose File"}
      </Button>
      {error && (
        <p className="mt-2 text-sm text-red-600">{error}</p>
      )}
    </div>
  );
}
