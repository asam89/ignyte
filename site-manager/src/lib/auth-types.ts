import { UserRole } from "@/generated/prisma/client";

declare module "next-auth" {
  interface Session {
    user: {
      id: string;
      email: string;
      name?: string | null;
      image?: string | null;
      role: UserRole;
      organizationId: string | null;
    };
  }

  interface User {
    role: UserRole;
    organizationId: string | null;
  }
}

declare module "@auth/core/jwt" {
  interface JWT {
    role: UserRole;
    organizationId: string | null;
  }
}
