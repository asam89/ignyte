"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";

interface RequestActionsProps {
  requestId: string;
  status: string;
}

export function RequestActions({ requestId, status }: RequestActionsProps) {
  const router = useRouter();
  const [loading, setLoading] = useState<string | null>(null);

  async function handleAction(action: "approve" | "reject" | "revert" | "retry") {
    setLoading(action);
    try {
      const res = await fetch(`/api/change-requests/${requestId}/${action}`, {
        method: "POST",
      });

      if (!res.ok) {
        const data = await res.json();
        alert(data.error || `Failed to ${action}`);
      } else {
        router.refresh();
      }
    } catch {
      alert("Network error");
    } finally {
      setLoading(null);
    }
  }

  if (status === "preview_ready") {
    return (
      <div className="mb-6 flex items-center gap-3 rounded-lg border border-gray-200 bg-white p-4">
        <p className="flex-1 text-sm text-gray-700">
          Review the diff and preview above, then approve or reject this change.
        </p>
        <Button
          variant="danger"
          onClick={() => handleAction("reject")}
          disabled={loading !== null}
        >
          {loading === "reject" ? "Rejecting..." : "Reject"}
        </Button>
        <Button
          variant="primary"
          onClick={() => handleAction("approve")}
          disabled={loading !== null}
        >
          {loading === "approve" ? "Approving..." : "Approve & Deploy"}
        </Button>
      </div>
    );
  }

  if (status === "merged") {
    return (
      <div className="mb-6 flex items-center gap-3 rounded-lg border border-green-200 bg-green-50 p-4">
        <p className="flex-1 text-sm text-green-800">
          This change has been deployed to production.
        </p>
        <Button
          variant="danger"
          onClick={() => handleAction("revert")}
          disabled={loading !== null}
        >
          {loading === "revert" ? "Reverting..." : "Revert"}
        </Button>
      </div>
    );
  }

  if (status === "failed" || status === "rejected") {
    return (
      <div className="mb-6 flex items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 p-4">
        <p className="flex-1 text-sm text-gray-700">
          This request {status === "failed" ? "failed" : "was rejected"}. You can retry with the same prompt.
        </p>
        <Button
          variant="secondary"
          onClick={() => handleAction("retry")}
          disabled={loading !== null}
        >
          {loading === "retry" ? "Retrying..." : "Retry"}
        </Button>
      </div>
    );
  }

  return null;
}
