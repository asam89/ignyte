import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { redirect, notFound } from "next/navigation";
import Link from "next/link";
import { AssetUpload } from "./asset-upload";

export default async function AssetsPage({
  params,
}: {
  params: Promise<{ siteId: string }>;
}) {
  const session = await auth();
  if (!session?.user) redirect("/login");

  const { siteId } = await params;
  const user = session.user as { role: string; organizationId: string | null };
  const isStaff = user.role === "ignyte_staff";

  const site = await prisma.site.findFirst({
    where: {
      id: siteId,
      ...(isStaff ? {} : { organizationId: user.organizationId! }),
    },
  });

  if (!site) notFound();

  const assets = await prisma.asset.findMany({
    where: { siteId },
    orderBy: { createdAt: "desc" },
  });

  return (
    <div className="max-w-4xl">
      <div className="mb-6">
        <Link
          href={`/sites/${siteId}`}
          className="text-sm text-gray-500 hover:text-gray-700"
        >
          ← Back to {site.name}
        </Link>
      </div>

      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold text-[#1A1A2E]">
          Assets — {site.name}
        </h1>
      </div>

      <p className="text-sm text-gray-600 mb-6">
        Upload logos, images, and PDFs that can be referenced in change requests.
        These are <strong>publishable</strong> — their CDN URLs can be inserted into your site.
      </p>

      <AssetUpload siteId={siteId} />

      <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {assets.map((asset) => (
          <div
            key={asset.id}
            className="rounded-lg border border-gray-200 p-4 bg-white"
          >
            {asset.mimeType.startsWith("image/") && (
              <div className="mb-3 aspect-video rounded bg-gray-100 flex items-center justify-center overflow-hidden">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img
                  src={asset.cdnUrl}
                  alt={asset.name}
                  className="max-h-full max-w-full object-contain"
                />
              </div>
            )}
            <h3 className="font-medium text-sm truncate">{asset.name}</h3>
            <p className="text-xs text-gray-500 mt-1">
              {asset.type} • {(asset.sizeBytes / 1024).toFixed(1)}KB
            </p>
            <div className="mt-2">
              <input
                readOnly
                value={asset.cdnUrl}
                className="w-full text-xs bg-gray-50 border rounded px-2 py-1 font-mono"
              />
            </div>
          </div>
        ))}
      </div>

      {assets.length === 0 && (
        <p className="text-gray-500 text-center py-8">
          No assets uploaded yet. Upload logos, images, or PDFs above.
        </p>
      )}
    </div>
  );
}
