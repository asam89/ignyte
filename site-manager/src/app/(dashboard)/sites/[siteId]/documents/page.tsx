import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { redirect, notFound } from "next/navigation";
import Link from "next/link";
import { DocUpload } from "./doc-upload";

export default async function DocumentsPage({
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

  const docs = await prisma.contextDoc.findMany({
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
          Context Documents — {site.name}
        </h1>
      </div>

      <p className="text-sm text-gray-600 mb-6">
        Upload brand guidelines, voice documents, or reference material.
        These are <strong>never published</strong> — text is extracted and used
        as AI context to match your brand tone and style.
      </p>

      <DocUpload siteId={siteId} />

      <div className="mt-8 space-y-3">
        {docs.map((doc) => (
          <div
            key={doc.id}
            className="rounded-lg border border-gray-200 p-4 bg-white flex items-center gap-4"
          >
            <div className="flex-shrink-0 w-10 h-10 bg-gray-100 rounded flex items-center justify-center">
              <span className="text-lg">
                {doc.mimeType === "application/pdf" ? "📄" : "📝"}
              </span>
            </div>
            <div className="flex-1 min-w-0">
              <h3 className="font-medium text-sm truncate">{doc.name}</h3>
              <p className="text-xs text-gray-500 mt-0.5">
                {doc.fileName} • {(doc.sizeBytes / 1024).toFixed(1)}KB •{" "}
                {doc.extractedText ? `${doc.extractedText.length.toLocaleString()} chars extracted` : "No text extracted"}
              </p>
            </div>
            <span className="text-xs text-gray-400">
              {new Date(doc.createdAt).toLocaleDateString()}
            </span>
          </div>
        ))}
      </div>

      {docs.length === 0 && (
        <p className="text-gray-500 text-center py-8">
          No context documents yet. Upload brand guidelines, voice docs, or reference material above.
        </p>
      )}
    </div>
  );
}
