import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { redirect } from "next/navigation";
import { OnboardForm } from "./onboard-form";

export default async function OnboardPage() {
  const session = await auth();
  if (!session?.user) redirect("/login");
  if ((session.user as { role: string }).role !== "ignyte_staff") redirect("/sites");

  const orgs = await prisma.organization.findMany({
    orderBy: { name: "asc" },
  });

  return (
    <div className="max-w-2xl">
      <h1 className="mb-2 text-2xl font-bold text-[#1A1A2E]">Onboard a Site</h1>
      <p className="mb-8 text-sm text-gray-600">
        Connect a client&apos;s repository, choose the site type and adapter, and define
        editable paths.
      </p>
      <OnboardForm organizations={orgs.map((o) => ({ id: o.id, name: o.name }))} />
    </div>
  );
}
