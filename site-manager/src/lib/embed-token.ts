import { createHmac, timingSafeEqual } from "crypto";

const EMBED_TOKEN_TTL_MS = 8 * 60 * 60 * 1000; // 8 hours

interface EmbedTokenPayload {
  userId: string;
  siteId: string;
  email: string;
  name: string;
  role: string;
  organizationId: string;
  exp: number;
}

function getSecret(): string {
  const secret = process.env.EMBED_SECRET || process.env.AUTH_SECRET;
  if (!secret) throw new Error("EMBED_SECRET or AUTH_SECRET not configured");
  return secret;
}

function sign(payload: string): string {
  return createHmac("sha256", getSecret()).update(payload).digest("hex");
}

export function generateEmbedToken(data: Omit<EmbedTokenPayload, "exp">): string {
  const payload: EmbedTokenPayload = {
    ...data,
    exp: Date.now() + EMBED_TOKEN_TTL_MS,
  };
  const encoded = Buffer.from(JSON.stringify(payload)).toString("base64url");
  const signature = sign(encoded);
  return `${encoded}.${signature}`;
}

export function verifyEmbedToken(token: string): EmbedTokenPayload | null {
  const parts = token.split(".");
  if (parts.length !== 2) return null;

  const [encoded, signature] = parts;
  const expected = sign(encoded);

  if (!timingSafeEqual(Buffer.from(signature), Buffer.from(expected))) {
    return null;
  }

  try {
    const payload = JSON.parse(
      Buffer.from(encoded, "base64url").toString("utf-8")
    ) as EmbedTokenPayload;

    if (payload.exp < Date.now()) return null;

    return payload;
  } catch {
    return null;
  }
}
