import Stripe from "stripe";

let _stripe: Stripe | null = null;

export function getStripe(): Stripe {
  if (!_stripe) {
    const key = process.env.STRIPE_SECRET_KEY;
    if (!key) throw new Error("STRIPE_SECRET_KEY not configured");
    _stripe = new Stripe(key);
  }
  return _stripe;
}

export const PLANS = {
  starter: {
    name: "Starter",
    monthlyQuota: 5,
    priceMonthly: 49,
    features: ["5 change requests/month", "1 site", "Email support"],
  },
  professional: {
    name: "Professional",
    monthlyQuota: 20,
    priceMonthly: 149,
    features: ["20 change requests/month", "3 sites", "Priority support", "Context documents"],
  },
  enterprise: {
    name: "Enterprise",
    monthlyQuota: 100,
    priceMonthly: 499,
    features: ["100 change requests/month", "Unlimited sites", "Dedicated support", "Custom editable regions"],
  },
} as const;

export type PlanKey = keyof typeof PLANS;

export function getPlanByQuota(quota: number): PlanKey {
  if (quota <= 5) return "starter";
  if (quota <= 20) return "professional";
  return "enterprise";
}
