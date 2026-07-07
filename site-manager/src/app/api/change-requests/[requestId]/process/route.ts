import { NextResponse } from "next/server";
import { processChangeRequest } from "@/lib/pipeline";

/**
 * Internal endpoint for manually triggering change request processing.
 * In production, the job queue handles this automatically.
 * This endpoint is kept for debugging and manual retriggers.
 */
export async function POST(
  request: Request,
  { params }: { params: Promise<{ requestId: string }> }
) {
  // Verify internal secret to prevent external calls
  const secret = request.headers.get("x-internal-secret");
  if (secret !== process.env.INTERNAL_API_SECRET) {
    return NextResponse.json({ error: "Forbidden" }, { status: 403 });
  }

  const { requestId } = await params;

  try {
    await processChangeRequest({ requestId });
    return NextResponse.json({ status: "processed" });
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : "Unknown error";
    return NextResponse.json({ status: "failed", error: errorMessage }, { status: 500 });
  }
}
