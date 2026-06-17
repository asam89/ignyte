"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

export function InviteUserForm({ orgId }: { orgId: string }) {
  const [email, setEmail] = useState("");
  const [name, setName] = useState("");
  const [role, setRole] = useState<"client_admin" | "client_editor">("client_editor");
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState<{ success?: string; error?: string; tempPassword?: string } | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    setResult(null);

    try {
      const res = await fetch(`/api/organizations/${orgId}/users`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email, name: name || undefined, role }),
      });

      const data = await res.json();

      if (!res.ok) {
        setResult({ error: data.error });
      } else {
        setResult({
          success: `User ${data.email} invited successfully.`,
          tempPassword: data.temporaryPassword,
        });
        setEmail("");
        setName("");
      }
    } catch {
      setResult({ error: "Failed to invite user" });
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="border-t pt-4">
      <h4 className="text-sm font-medium mb-2">Invite User</h4>
      <form onSubmit={handleSubmit} className="space-y-2">
        <Input
          label="Email"
          type="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          required
        />
        <Input
          label="Name (optional)"
          value={name}
          onChange={(e) => setName(e.target.value)}
        />
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            Role
          </label>
          <select
            value={role}
            onChange={(e) => setRole(e.target.value as "client_admin" | "client_editor")}
            className="w-full px-3 py-2 border rounded-lg text-sm"
          >
            <option value="client_editor">Editor</option>
            <option value="client_admin">Admin</option>
          </select>
        </div>
        <Button type="submit" disabled={loading}>
          {loading ? "Inviting..." : "Invite"}
        </Button>
      </form>

      {result?.success && (
        <div className="mt-3 p-2 bg-green-50 text-green-800 text-sm rounded">
          <p>{result.success}</p>
          {result.tempPassword && (
            <p className="mt-1 font-mono text-xs">
              Temp password: <strong>{result.tempPassword}</strong>
            </p>
          )}
        </div>
      )}
      {result?.error && (
        <div className="mt-3 p-2 bg-red-50 text-red-800 text-sm rounded">
          {result.error}
        </div>
      )}
    </div>
  );
}
