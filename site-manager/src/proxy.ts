import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";

export function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl;

  // Allow embed routes to be iframed
  if (pathname.startsWith("/embed")) {
    const response = NextResponse.next();
    response.headers.delete("X-Frame-Options");
    response.headers.set(
      "Content-Security-Policy",
      "frame-ancestors 'self' https://*.ignyteconsulting.com https://ignyteconsulting.com http://localhost:*"
    );
    return response;
  }

  return NextResponse.next();
}

export const config = {
  matcher: ["/embed/:path*"],
};
