import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { getStripe, PLANS } from "@/lib/stripe";
import type { PlanKey } from "@/lib/stripe";
import Stripe from "stripe";

/**
 * Stripe webhook handler.
 * Handles subscription lifecycle events: created, updated, deleted.
 */
export async function POST(request: Request) {
  const body = await request.text();
  const signature = request.headers.get("stripe-signature");

  if (!signature || !process.env.STRIPE_WEBHOOK_SECRET) {
    return NextResponse.json({ error: "Missing signature or webhook secret" }, { status: 400 });
  }

  let event: Stripe.Event;

  try {
    event = getStripe().webhooks.constructEvent(
      body,
      signature,
      process.env.STRIPE_WEBHOOK_SECRET
    );
  } catch {
    return NextResponse.json({ error: "Invalid signature" }, { status: 400 });
  }

  switch (event.type) {
    case "checkout.session.completed": {
      const session = event.data.object as Stripe.Checkout.Session;
      const orgId = session.metadata?.organizationId;
      const plan = (session.metadata?.plan || "starter") as PlanKey;

      if (orgId && session.subscription) {
        const subscriptionId = typeof session.subscription === "string"
          ? session.subscription
          : session.subscription.id;

        await prisma.subscription.upsert({
          where: { organizationId: orgId },
          update: {
            stripeCustomerId: session.customer as string,
            stripeSubscriptionId: subscriptionId,
            plan,
            monthlyQuota: PLANS[plan]?.monthlyQuota || 10,
            status: "active",
          },
          create: {
            organizationId: orgId,
            stripeCustomerId: session.customer as string,
            stripeSubscriptionId: subscriptionId,
            plan,
            monthlyQuota: PLANS[plan]?.monthlyQuota || 10,
            currentUsage: 0,
            countOnMerge: true,
            status: "active",
            quotaResetDate: getNextResetDate(),
          },
        });

        await prisma.auditLog.create({
          data: {
            action: "subscription.created",
            details: { plan, subscriptionId },
            organizationId: orgId,
          },
        });
      }
      break;
    }

    case "customer.subscription.updated": {
      const subscription = event.data.object as Stripe.Subscription;
      const existing = await prisma.subscription.findFirst({
        where: { stripeSubscriptionId: subscription.id },
      });

      if (existing) {
        const status = subscription.status === "active" ? "active"
          : subscription.status === "past_due" ? "past_due"
          : "canceled";

        await prisma.subscription.update({
          where: { id: existing.id },
          data: { status },
        });
      }
      break;
    }

    case "customer.subscription.deleted": {
      const subscription = event.data.object as Stripe.Subscription;
      const existing = await prisma.subscription.findFirst({
        where: { stripeSubscriptionId: subscription.id },
      });

      if (existing) {
        await prisma.subscription.update({
          where: { id: existing.id },
          data: { status: "canceled" },
        });

        await prisma.auditLog.create({
          data: {
            action: "subscription.canceled",
            details: { subscriptionId: subscription.id },
            organizationId: existing.organizationId,
          },
        });
      }
      break;
    }

    case "invoice.paid": {
      // Reset quota on successful invoice payment (monthly renewal)
      const invoice = event.data.object as Stripe.Invoice;
      const subRef = invoice.parent?.subscription_details?.subscription;
      if (subRef) {
        const subId = typeof subRef === "string" ? subRef : subRef.id;

        const existing = await prisma.subscription.findFirst({
          where: { stripeSubscriptionId: subId },
        });

        if (existing) {
          await prisma.subscription.update({
            where: { id: existing.id },
            data: {
              currentUsage: 0,
              quotaResetDate: getNextResetDate(),
            },
          });
        }
      }
      break;
    }
  }

  return NextResponse.json({ received: true });
}

function getNextResetDate(): Date {
  const now = new Date();
  return new Date(now.getFullYear(), now.getMonth() + 1, 1);
}
