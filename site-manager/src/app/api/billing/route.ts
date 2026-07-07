import { NextResponse } from "next/server";
import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { getStripe, PLANS } from "@/lib/stripe";
import type { PlanKey } from "@/lib/stripe";

export async function GET() {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const user = session.user as { id: string; role: string; organizationId: string | null };

  if (!user.organizationId) {
    return NextResponse.json({ error: "No organization" }, { status: 400 });
  }

  const subscription = await prisma.subscription.findUnique({
    where: { organizationId: user.organizationId },
  });

  return NextResponse.json({
    subscription,
    plans: PLANS,
  });
}

export async function POST(request: Request) {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const user = session.user as { id: string; role: string; organizationId: string | null };

  if (user.role !== "client_admin" && user.role !== "ignyte_staff") {
    return NextResponse.json({ error: "Only admins can manage billing" }, { status: 403 });
  }

  if (!user.organizationId) {
    return NextResponse.json({ error: "No organization" }, { status: 400 });
  }

  const body = await request.json();
  const plan = body.plan as PlanKey;

  if (!plan || !PLANS[plan]) {
    return NextResponse.json({ error: "Invalid plan" }, { status: 400 });
  }

  const org = await prisma.organization.findUnique({
    where: { id: user.organizationId },
    include: { subscription: true },
  });

  if (!org) {
    return NextResponse.json({ error: "Organization not found" }, { status: 404 });
  }

  // Create or get Stripe customer
  let customerId = org.subscription?.stripeCustomerId;

  if (!customerId) {
    const customer = await getStripe().customers.create({
      name: org.name,
      metadata: { organizationId: org.id },
    });
    customerId = customer.id;
  }

  // Create Stripe Checkout session
  const checkoutSession = await getStripe().checkout.sessions.create({
    customer: customerId,
    mode: "subscription",
    line_items: [
      {
        price_data: {
          currency: "cad",
          product_data: {
            name: `Ignyte Site Manager — ${PLANS[plan].name}`,
            description: `${PLANS[plan].monthlyQuota} change requests/month`,
          },
          recurring: { interval: "month" },
          unit_amount: PLANS[plan].priceMonthly * 100,
        },
        quantity: 1,
      },
    ],
    metadata: {
      organizationId: org.id,
      plan,
    },
    success_url: `${process.env.NEXT_PUBLIC_APP_URL}/billing?success=true`,
    cancel_url: `${process.env.NEXT_PUBLIC_APP_URL}/billing?canceled=true`,
  });

  return NextResponse.json({ url: checkoutSession.url });
}
