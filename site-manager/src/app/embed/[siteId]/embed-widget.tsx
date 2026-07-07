"use client";

import { useState } from "react";

interface EmbedRequest {
  id: string;
  prompt: string;
  status: string;
  previewUrl: string | null;
  createdAt: string;
  requestedBy: string;
}

interface EmbedWidgetProps {
  site: { id: string; name: string; productionUrl: string | null };
  requests: EmbedRequest[];
  token: string;
  userName: string;
}

const statusConfig: Record<string, { label: string; color: string; bg: string }> = {
  pending: { label: "Pending", color: "#92400e", bg: "#fef3c7" },
  generating: { label: "Generating", color: "#1e40af", bg: "#dbeafe" },
  preview_ready: { label: "Preview Ready", color: "#6b21a8", bg: "#f3e8ff" },
  approved: { label: "Approved", color: "#166534", bg: "#dcfce7" },
  rejected: { label: "Rejected", color: "#991b1b", bg: "#fee2e2" },
  merged: { label: "Live", color: "#065f46", bg: "#d1fae5" },
  reverted: { label: "Reverted", color: "#6b7280", bg: "#f3f4f6" },
  failed: { label: "Failed", color: "#991b1b", bg: "#fee2e2" },
};

export function EmbedWidget({ site, requests: initialRequests, token, userName }: EmbedWidgetProps) {
  const [prompt, setPrompt] = useState("");
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState<{ type: "success" | "error"; text: string } | null>(null);
  const [requests, setRequests] = useState(initialRequests);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!prompt.trim() || loading) return;

    setLoading(true);
    setMessage(null);

    try {
      const res = await fetch("/api/embed/request", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Embed ${token}`,
        },
        body: JSON.stringify({ siteId: site.id, prompt: prompt.trim() }),
      });

      const data = await res.json();

      if (!res.ok) {
        setMessage({ type: "error", text: data.error || "Failed to submit request" });
      } else {
        setMessage({ type: "success", text: "Request submitted! Our AI is generating your edit — you'll see a preview shortly." });
        setPrompt("");
        setRequests((prev) => [
          {
            id: data.id,
            prompt: prompt.trim(),
            status: "pending",
            previewUrl: null,
            createdAt: new Date().toISOString(),
            requestedBy: userName,
          },
          ...prev,
        ]);
      }
    } catch {
      setMessage({ type: "error", text: "Network error. Please try again." });
    } finally {
      setLoading(false);
    }
  }

  return (
    <div style={{ fontFamily: "'DM Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif", padding: "24px", maxWidth: "100%" }}>
      {/* Header */}
      <div style={{ marginBottom: "24px" }}>
        <div style={{ display: "flex", alignItems: "center", gap: "10px", marginBottom: "4px" }}>
          <span style={{ fontSize: "20px" }}>🌐</span>
          <h2 style={{ fontSize: "1.25rem", fontWeight: 700, color: "#1A1A2E", margin: 0 }}>
            Website Updates
          </h2>
        </div>
        <p style={{ fontSize: "0.85rem", color: "#6b7280", margin: "4px 0 0 30px" }}>
          {site.name}
          {site.productionUrl && (
            <> · <a href={site.productionUrl} target="_blank" rel="noopener noreferrer" style={{ color: "#E87722" }}>{site.productionUrl}</a></>
          )}
        </p>
      </div>

      {/* Request form */}
      <form onSubmit={handleSubmit} style={{ marginBottom: "28px" }}>
        <div style={{
          border: "2px solid #e5e7eb",
          borderRadius: "12px",
          overflow: "hidden",
          transition: "border-color 0.2s",
        }}>
          <textarea
            value={prompt}
            onChange={(e) => setPrompt(e.target.value)}
            placeholder='Tell us what to change, e.g. "Update the homepage tagline to: Innovation starts here" or "Change the phone number to 416-555-1234"'
            rows={3}
            disabled={loading}
            style={{
              width: "100%",
              border: "none",
              padding: "16px",
              fontSize: "0.95rem",
              fontFamily: "inherit",
              resize: "none",
              outline: "none",
              background: "white",
              boxSizing: "border-box",
            }}
          />
          <div style={{
            display: "flex",
            alignItems: "center",
            justifyContent: "space-between",
            padding: "10px 16px",
            background: "#f9fafb",
            borderTop: "1px solid #e5e7eb",
          }}>
            <span style={{ fontSize: "0.78rem", color: "#9ca3af" }}>
              Changes are reviewed before going live — nothing goes to production without approval.
            </span>
            <button
              type="submit"
              disabled={loading || !prompt.trim()}
              style={{
                background: loading || !prompt.trim() ? "#d1d5db" : "#E87722",
                color: "white",
                border: "none",
                padding: "8px 20px",
                borderRadius: "8px",
                fontSize: "0.88rem",
                fontWeight: 600,
                cursor: loading || !prompt.trim() ? "not-allowed" : "pointer",
                fontFamily: "inherit",
                whiteSpace: "nowrap",
              }}
            >
              {loading ? "Submitting..." : "Submit Request"}
            </button>
          </div>
        </div>
      </form>

      {/* Message */}
      {message && (
        <div style={{
          padding: "12px 16px",
          borderRadius: "10px",
          marginBottom: "20px",
          fontSize: "0.88rem",
          background: message.type === "success" ? "#f0fdf4" : "#fef2f2",
          color: message.type === "success" ? "#166534" : "#991b1b",
          border: `1px solid ${message.type === "success" ? "#bbf7d0" : "#fecaca"}`,
        }}>
          {message.text}
        </div>
      )}

      {/* Recent requests */}
      {requests.length > 0 && (
        <div>
          <h3 style={{ fontSize: "0.95rem", fontWeight: 700, color: "#1A1A2E", marginBottom: "12px" }}>
            Recent Requests
          </h3>
          <div style={{ display: "flex", flexDirection: "column", gap: "10px" }}>
            {requests.map((req) => {
              const status = statusConfig[req.status] || statusConfig.pending;
              return (
                <div
                  key={req.id}
                  style={{
                    background: "white",
                    border: "1px solid #e5e7eb",
                    borderRadius: "10px",
                    padding: "14px 16px",
                  }}
                >
                  <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", gap: "12px" }}>
                    <p style={{ fontSize: "0.9rem", color: "#1f2937", margin: 0, flex: 1 }}>
                      {req.prompt}
                    </p>
                    <span style={{
                      fontSize: "0.72rem",
                      fontWeight: 700,
                      padding: "3px 10px",
                      borderRadius: "50px",
                      background: status.bg,
                      color: status.color,
                      whiteSpace: "nowrap",
                      textTransform: "uppercase",
                      letterSpacing: "0.04em",
                    }}>
                      {status.label}
                    </span>
                  </div>
                  <div style={{ display: "flex", alignItems: "center", gap: "12px", marginTop: "8px" }}>
                    <span style={{ fontSize: "0.78rem", color: "#9ca3af" }}>
                      {new Date(req.createdAt).toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" })}
                    </span>
                    {req.previewUrl && (
                      <a
                        href={req.previewUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        style={{ fontSize: "0.78rem", color: "#E87722", fontWeight: 600, textDecoration: "none" }}
                      >
                        View Preview →
                      </a>
                    )}
                    {req.status === "merged" && (
                      <span style={{ fontSize: "0.78rem", color: "#065f46", fontWeight: 600 }}>
                        ✓ Live on site
                      </span>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}
