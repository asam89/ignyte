import { prisma } from "@/lib/prisma";
import { verifyEmbedToken } from "@/lib/embed-token";
import { notFound } from "next/navigation";
import { EmbedWidget } from "./embed-widget";

export default async function EmbedPage({
  params,
  searchParams,
}: {
  params: Promise<{ siteId: string }>;
  searchParams: Promise<{ token?: string }>;
}) {
  const { siteId } = await params;
  const { token } = await searchParams;

  if (!token) {
    return (
      <div className="flex min-h-[300px] items-center justify-center p-6">
        <p className="text-sm text-red-500">Missing authentication token.</p>
      </div>
    );
  }

  const payload = verifyEmbedToken(token);
  if (!payload) {
    return (
      <div className="flex min-h-[300px] items-center justify-center p-6">
        <p className="text-sm text-red-500">Invalid or expired token. Please refresh the page.</p>
      </div>
    );
  }

  if (payload.siteId !== siteId) {
    return notFound();
  }

  const site = await prisma.site.findFirst({
    where: { id: siteId, organizationId: payload.organizationId },
  });

  if (!site) {
    return notFound();
  }

  const recentRequests = await prisma.changeRequest.findMany({
    where: { siteId: site.id },
    include: { requestedBy: { select: { name: true, email: true } } },
    orderBy: { createdAt: "desc" },
    take: 10,
  });

  return (
    <EmbedWidget
      site={{
        id: site.id,
        name: site.name,
        productionUrl: site.productionUrl,
      }}
      requests={recentRequests.map((r) => ({
        id: r.id,
        prompt: r.prompt,
        status: r.status,
        previewUrl: r.previewUrl,
        createdAt: r.createdAt.toISOString(),
        requestedBy: r.requestedBy.name || r.requestedBy.email,
      }))}
      token={token}
      userName={payload.name}
    />
  );
}
