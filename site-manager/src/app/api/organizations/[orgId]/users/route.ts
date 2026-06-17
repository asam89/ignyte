import { NextResponse } from "next/server";
import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { z } from "zod";
import { hash } from "bcryptjs";

const inviteUserSchema = z.object({
  email: z.string().email(),
  name: z.string().min(1).max(200).optional(),
  role: z.enum(["client_admin", "client_editor"]),
  password: z.string().min(8).optional(),
});

export async function GET(
  _request: Request,
  { params }: { params: Promise<{ orgId: string }> }
) {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const { orgId } = await params;
  const user = session.user as { id: string; role: string; organizationId: string | null };
  const isStaff = user.role === "ignyte_staff";

  // Only staff or members of the org can view users
  if (!isStaff && user.organizationId !== orgId) {
    return NextResponse.json({ error: "Forbidden" }, { status: 403 });
  }

  const users = await prisma.user.findMany({
    where: { organizationId: orgId },
    select: {
      id: true,
      email: true,
      name: true,
      role: true,
      createdAt: true,
    },
    orderBy: { createdAt: "asc" },
  });

  return NextResponse.json(users);
}

export async function POST(
  request: Request,
  { params }: { params: Promise<{ orgId: string }> }
) {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const { orgId } = await params;
  const currentUser = session.user as { id: string; role: string; organizationId: string | null };
  const isStaff = currentUser.role === "ignyte_staff";
  const isOrgAdmin = currentUser.role === "client_admin" && currentUser.organizationId === orgId;

  if (!isStaff && !isOrgAdmin) {
    return NextResponse.json({ error: "Forbidden" }, { status: 403 });
  }

  // Verify org exists
  const org = await prisma.organization.findUnique({ where: { id: orgId } });
  if (!org) {
    return NextResponse.json({ error: "Organization not found" }, { status: 404 });
  }

  const body = await request.json();
  const parsed = inviteUserSchema.safeParse(body);

  if (!parsed.success) {
    return NextResponse.json(
      { error: "Invalid input", details: parsed.error.flatten() },
      { status: 400 }
    );
  }

  // Check if user already exists
  const existing = await prisma.user.findUnique({
    where: { email: parsed.data.email },
  });

  if (existing) {
    if (existing.organizationId === orgId) {
      return NextResponse.json(
        { error: "User already belongs to this organization" },
        { status: 409 }
      );
    }
    return NextResponse.json(
      { error: "Email already registered with another organization" },
      { status: 409 }
    );
  }

  // Generate a temporary password if not provided
  const tempPassword = parsed.data.password || `temp_${Math.random().toString(36).slice(2, 14)}`;
  const passwordHash = await hash(tempPassword, 12);

  const newUser = await prisma.user.create({
    data: {
      email: parsed.data.email,
      name: parsed.data.name,
      role: parsed.data.role,
      organizationId: orgId,
      passwordHash,
    },
    select: {
      id: true,
      email: true,
      name: true,
      role: true,
      createdAt: true,
    },
  });

  await prisma.auditLog.create({
    data: {
      action: "user.invited",
      details: {
        invitedUserId: newUser.id,
        email: newUser.email,
        role: parsed.data.role,
      },
      userId: currentUser.id,
      organizationId: orgId,
    },
  });

  return NextResponse.json(
    {
      ...newUser,
      // Only show temp password on creation (staff/admin must communicate it)
      ...(parsed.data.password ? {} : { temporaryPassword: tempPassword }),
    },
    { status: 201 }
  );
}
