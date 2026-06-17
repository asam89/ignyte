import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { redirect } from "next/navigation";
import { Card } from "@/components/ui/card";

export default async function AuditLogPage() {
  const session = await auth();
  if (!session?.user) redirect("/login");

  const user = session.user as { role: string };
  if (user.role !== "ignyte_staff") redirect("/sites");

  const logs = await prisma.auditLog.findMany({
    include: {
      organization: { select: { name: true } },
    },
    orderBy: { createdAt: "desc" },
    take: 100,
  });

  // Resolve user emails for display
  const userIds = [...new Set(logs.map((l) => l.userId).filter(Boolean))] as string[];
  const users = userIds.length > 0
    ? await prisma.user.findMany({
        where: { id: { in: userIds } },
        select: { id: true, email: true },
      })
    : [];
  const userMap = new Map(users.map((u) => [u.id, u.email]));

  const actionColors: Record<string, string> = {
    "change_request.created": "text-blue-700 bg-blue-50",
    "change_request.preview_ready": "text-purple-700 bg-purple-50",
    "change_request.approved": "text-green-700 bg-green-50",
    "change_request.rejected": "text-red-700 bg-red-50",
    "change_request.reverted": "text-gray-700 bg-gray-50",
    "change_request.refused": "text-amber-700 bg-amber-50",
    "change_request.flagged": "text-orange-700 bg-orange-50",
    "change_request.retried": "text-blue-700 bg-blue-50",
    "organization.created": "text-indigo-700 bg-indigo-50",
    "user.invited": "text-cyan-700 bg-cyan-50",
    "site.created": "text-teal-700 bg-teal-50",
  };

  return (
    <div>
      <h1 className="text-2xl font-bold mb-6">Audit Log</h1>

      <div className="space-y-2">
        {logs.map((log) => (
          <Card key={log.id} className="p-3">
            <div className="flex items-center gap-3">
              <span
                className={`text-xs font-mono px-2 py-0.5 rounded ${
                  actionColors[log.action] || "text-gray-700 bg-gray-50"
                }`}
              >
                {log.action}
              </span>
              <span className="text-sm text-gray-600">
                {(log.userId && userMap.get(log.userId)) || "system"}
              </span>
              {log.organization && (
                <span className="text-xs text-gray-400">
                  ({log.organization.name})
                </span>
              )}
              <span className="ml-auto text-xs text-gray-400">
                {new Date(log.createdAt).toLocaleString()}
              </span>
            </div>
            {log.details && (
              <pre className="mt-1 text-xs text-gray-500 overflow-x-auto">
                {JSON.stringify(log.details, null, 2)}
              </pre>
            )}
          </Card>
        ))}

        {logs.length === 0 && (
          <p className="text-gray-500 text-center py-8">No audit logs yet.</p>
        )}
      </div>
    </div>
  );
}
