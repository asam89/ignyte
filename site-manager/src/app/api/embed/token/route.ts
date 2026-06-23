import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { generateEmbedToken } from "@/lib/embed-token";
import { z } from "zod";

const tokenRequestSchema = z.object({
  email: z.string().email(),
  siteId: z.string().min(1),
});

/**
 * POST /api/embed/token
 * Called by the PHP portal backend to get an embed token for a client.
 * Authenticated via INTERNAL_API_SECRET header.
 */
export async function POST(request: Request) {
  const apiSecret = process.env.INTERNAL_API_SECRET;
  if (!apiSecret) {
    return NextResponse.json({ error: "Server misconfigured" }, { status: 500 });
  }

  const authHeader = request.headers.get("authorization");
  if (authHeader !== `Bearer ${apiSecret}`) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const body = await request.json();
  const parsed = tokenRequestSchema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json(
      { error: "Invalid input", details: parsed.error.flatten() },
      { status: 400 }
    );
  }

  const { email, siteId } = parsed.data;

  // Find user by email
  const user = await prisma.user.findUnique({
    where: { email },
    include: { organization: true },
  });

  if (!user || !user.organizationId) {
    return NextResponse.json({ error: "User not found" }, { status: 404 });
  }

  // Verify site belongs to user's org
  const site = await prisma.site.findFirst({
    where: { id: siteId, organizationId: user.organizationId },
  });

  if (!site) {
    return NextResponse.json({ error: "Site not found" }, { status: 404 });
  }

  const token = generateEmbedToken({
    userId: user.id,
    siteId: site.id,
    email: user.email,
    name: user.name || user.email,
    role: user.role,
    organizationId: user.organizationId,
  });

  return NextResponse.json({ token, expiresIn: 8 * 60 * 60 });
}
