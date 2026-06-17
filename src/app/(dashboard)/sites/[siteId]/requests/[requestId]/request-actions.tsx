"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";

interface RequestActionsProps {
  requestId: string;
  siteId: string;
}

export function RequestActions({ requestId, siteId }: RequestActionsProps) {
  const router = useRouter();
  const [loading, setLoading] = useState<"approve" | "reject" | null>(null);

  async function handleAction(action: "approve" | "reject") {
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
