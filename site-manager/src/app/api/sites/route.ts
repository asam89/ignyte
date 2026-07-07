import { NextResponse } from "next/server";
import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { z } from "zod";

const createSiteSchema = z.object({
  name: z.string().min(1),
  siteType: z.enum(["git_static", "nextjs", "wordpress"]),
  repoOwner: z.string().min(1),
  repoName: z.string().min(1),
  productionUrl: z.string().url().optional().or(z.literal("")),
  organizationId: z.string().min(1),
  editablePaths: z.array(z.string()).default([]),
});

export async function POST(request: Request) {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const user = session.user as { role: string };
  if (user.role !== "ignyte_staff") {
    return NextResponse.json({ error: "Forbidden" }, { status: 403 });
  }

  const body = await request.json();
  const parsed = createSiteSchema.safeParse(body);

  if (!parsed.success) {
    return NextResponse.json(
      { error: "Invalid input", details: parsed.error.flatten() },
      { status: 400 }
    );
  }

  const { name, siteType, repoOwner, repoName, productionUrl, organizationId, editablePaths } =
    parsed.data;

  const site = await prisma.site.create({
    data: {
      name,
      siteType,
      repoOwner,
      repoName,
      productionUrl: productionUrl || null,
      organizationId,
      editablePaths,
    },
  });

  // Audit log
  await prisma.auditLog.create({
    data: {
      action: "site.created",
      details: { siteId: site.id, name, siteType, repoOwner, repoName },
      userId: session.user.id,
      organizationId,
    },
  });

  return NextResponse.json(site, { status: 201 });
}

export async function GET() {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const user = session.user as { role: string; organizationId: string | null };
  const isStaff = user.role === "ignyte_staff";

  const sites = await prisma.site.findMany({
    where: isStaff ? {} : { organizationId: user.organizationId! },
    include: {
      organization: true,
      _count: { select: { changeRequests: true } },
    },
    orderBy: { updatedAt: "desc" },
  });

  return NextResponse.json(sites);
}
