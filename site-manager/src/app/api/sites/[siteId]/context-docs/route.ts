import { NextResponse } from "next/server";
import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { uploadToR2, deleteFromR2, contextDocKey } from "@/lib/r2";
import { extractText, truncateText } from "@/lib/text-extraction";

const ALLOWED_DOC_TYPES = [
  "application/pdf",
  "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
  "text/plain",
  "text/markdown",
  "text/html",
];

const MAX_FILE_SIZE = 25 * 1024 * 1024; // 25MB

export async function GET(
  _request: Request,
  { params }: { params: Promise<{ siteId: string }> }
) {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const { siteId } = await params;
  const user = session.user as { id: string; role: string; organizationId: string | null };
  const isStaff = user.role === "ignyte_staff";

  const site = await prisma.site.findFirst({
    where: {
      id: siteId,
      ...(isStaff ? {} : { organizationId: user.organizationId! }),
    },
  });

  if (!site) {
    return NextResponse.json({ error: "Site not found" }, { status: 404 });
  }

  const docs = await prisma.contextDoc.findMany({
    where: { siteId },
    select: {
      id: true,
      name: true,
      fileName: true,
      mimeType: true,
      sizeBytes: true,
      createdAt: true,
      updatedAt: true,
      // Omit extractedText from listing (can be large)
    },
    orderBy: { createdAt: "desc" },
  });

  return NextResponse.json(docs);
}

export async function POST(
  request: Request,
  { params }: { params: Promise<{ siteId: string }> }
) {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const { siteId } = await params;
  const user = session.user as { id: string; role: string; organizationId: string | null };
  const isStaff = user.role === "ignyte_staff";

  const site = await prisma.site.findFirst({
    where: {
      id: siteId,
      ...(isStaff ? {} : { organizationId: user.organizationId! }),
    },
  });

  if (!site) {
    return NextResponse.json({ error: "Site not found" }, { status: 404 });
  }

  const formData = await request.formData();
  const file = formData.get("file") as File | null;
  const name = (formData.get("name") as string) || "";

  if (!file) {
    return NextResponse.json({ error: "No file provided" }, { status: 400 });
  }

  if (!ALLOWED_DOC_TYPES.includes(file.type)) {
    return NextResponse.json(
      { error: `File type ${file.type} not allowed. Allowed: PDF, DOCX, TXT, Markdown, HTML` },
      { status: 400 }
    );
  }

  if (file.size > MAX_FILE_SIZE) {
    return NextResponse.json(
      { error: `File too large. Maximum: ${MAX_FILE_SIZE / 1024 / 1024}MB` },
      { status: 400 }
    );
  }

  const buffer = Buffer.from(await file.arrayBuffer());

  // Extract text content for AI context
  const rawText = await extractText(buffer, file.type);
  const extractedText = truncateText(rawText);

  // Upload original file to R2
  const key = contextDocKey(site.organizationId, siteId, file.name);
  await uploadToR2(buffer, key, file.type);

  const doc = await prisma.contextDoc.create({
    data: {
      name: name || file.name,
      fileName: file.name,
      mimeType: file.type,
      sizeBytes: file.size,
      r2Key: key,
      extractedText: extractedText || null,
      siteId,
    },
  });

  await prisma.auditLog.create({
    data: {
      action: "context_doc.uploaded",
      details: {
        docId: doc.id,
        name: doc.name,
        mimeType: file.type,
        textLength: extractedText.length,
      },
      userId: user.id,
      organizationId: site.organizationId,
    },
  });

  return NextResponse.json(
    { ...doc, extractedText: undefined, hasText: !!extractedText },
    { status: 201 }
  );
}

export async function DELETE(
  request: Request,
  { params }: { params: Promise<{ siteId: string }> }
) {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const { siteId } = await params;
  const user = session.user as { id: string; role: string; organizationId: string | null };
  const isStaff = user.role === "ignyte_staff";

  const { searchParams } = new URL(request.url);
  const docId = searchParams.get("docId");

  if (!docId) {
    return NextResponse.json({ error: "docId required" }, { status: 400 });
  }

  const doc = await prisma.contextDoc.findFirst({
    where: {
      id: docId,
      siteId,
      site: isStaff ? {} : { organizationId: user.organizationId! },
    },
  });

  if (!doc) {
    return NextResponse.json({ error: "Document not found" }, { status: 404 });
  }

  await deleteFromR2(doc.r2Key);
  await prisma.contextDoc.delete({ where: { id: docId } });

  return NextResponse.json({ status: "deleted" });
}
