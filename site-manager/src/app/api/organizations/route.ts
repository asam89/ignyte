import { NextResponse } from "next/server";
import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { z } from "zod";

const createOrgSchema = z.object({
  name: z.string().min(1).max(200),
  slug: z.string().min(1).max(100).regex(/^[a-z0-9-]+$/),
});

export async function GET() {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const user = session.user as { id: string; role: string; organizationId: string | null };
  const isStaff = user.role === "ignyte_staff";

  if (!isStaff) {
    // Non-staff only see their own org
    if (!user.organizationId) {
      return NextResponse.json([]);
    }
    const org = await prisma.organization.findUnique({
      where: { id: user.organizationId },
      include: { _count: { select: { users: true, sites: true } } },
    });
    return NextResponse.json(org ? [org] : []);
  }

  // Staff sees all orgs
  const orgs = await prisma.organization.findMany({
    include: {
      _count: { select: { users: true, sites: true } },
      subscription: true,
    },
    orderBy: { createdAt: "desc" },
  });

  return NextResponse.json(orgs);
}

export async function POST(request: Request) {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const user = session.user as { id: string; role: string };
  if (user.role !== "ignyte_staff") {
    return NextResponse.json({ error: "Forbidden" }, { status: 403 });
  }

  const body = await request.json();
  const parsed = createOrgSchema.safeParse(body);

  if (!parsed.success) {
    return NextResponse.json(
      { error: "Invalid input", details: parsed.error.flatten() },
      { status: 400 }
    );
  }

  const existing = await prisma.organization.findUnique({
    where: { slug: parsed.data.slug },
  });

  if (existing) {
    return NextResponse.json(
      { error: "Organization with this slug already exists" },
      { status: 409 }
    );
  }

  const org = await prisma.organization.create({
    data: parsed.data,
  });

  await prisma.auditLog.create({
    data: {
      action: "organization.created",
      details: { organizationId: org.id, name: org.name, slug: org.slug },
      userId: user.id,
      organizationId: org.id,
    },
  });

  return NextResponse.json(org, { status: 201 });
}
