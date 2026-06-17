"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";

interface NewRequestFormProps {
  siteId: string;
}

export function NewRequestForm({ siteId }: NewRequestFormProps) {
  const [prompt, setPrompt] = useState("");
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState<{ type: "success" | "error"; text: string } | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!prompt.trim()) return;

    setLoading(true);
    setMessage(null);

    try {
      const res = await fetch("/api/change-requests", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ siteId, prompt: prompt.trim() }),
      });

      const data = await res.json();

      if (!res.ok) {
        setMessage({ type: "error", text: data.error || "Failed to submit request" });
      } else {
        setMessage({ type: "success", text: "Request submitted! AI is generating your edit..." });
        setPrompt("");
      }
    } catch {
      setMessage({ type: "error", text: "Network error. Please try again." });
    } finally {
      setLoading(false);
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      <div className="space-y-3">
        <textarea
          value={prompt}
          onChange={(e) => setPrompt(e.target.value)}
          placeholder='Describe what you want to change, e.g. "Change the homepage tagline to: Your trusted IT partner"'
          rows={3}
          className="block w-full rounded-lg border border-gray-300 px-4 py-3 text-sm shadow-sm transition-colors focus:border-[#E87722] focus:outline-none focus:ring-1 focus:ring-[#E87722] resize-none"
          disabled={loading}
        />
        <div className="flex items-center justify-between">
          <p className="text-xs text-gray-400">
            Your request will be reviewed before going live.
          </p>
          <Button type="submit" disabled={loading || !prompt.trim()}>
            {loading ? "Submitting..." : "Submit Request"}
          </Button>
        </div>
      </div>

      {message && (
        <div
          className={`mt-3 rounded-lg p-3 text-sm ${
            message.type === "success"
              ? "bg-green-50 text-green-700"
              : "bg-red-50 text-red-700"
          }`}
        >
          {message.text}
        </div>
      )}
    </form>
  );
}
