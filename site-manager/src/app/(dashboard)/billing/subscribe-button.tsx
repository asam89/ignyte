"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";

export function SubscribeButton({ plan }: { plan: string }) {
  const [loading, setLoading] = useState(false);

  async function handleSubscribe() {
    setLoading(true);
    try {
      const res = await fetch("/api/billing", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ plan }),
      });

      const data = await res.json();
      if (data.url) {
        window.location.href = data.url;
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <Button
      onClick={handleSubscribe}
      disabled={loading}
      className="w-full bg-[#E87722] hover:bg-[#d66a1d] text-white"
    >
      {loading ? "Redirecting..." : "Subscribe"}
    </Button>
  );
}
