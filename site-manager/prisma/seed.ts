import { PrismaClient } from "../src/generated/prisma/client";
import { hash } from "bcryptjs";

const prisma = new PrismaClient();

async function main() {
  // Create Ignyte staff user
  const staffPassword = await hash("ignyte2026!", 12);
  const staff = await prisma.user.upsert({
    where: { email: "asam@ignyteconsulting.com" },
    update: {},
    create: {
      email: "asam@ignyteconsulting.com",
      name: "Alex Sam",
      passwordHash: staffPassword,
      role: "ignyte_staff",
    },
  });
  console.log("Created staff user:", staff.email);

  // Create Baseera organization
  const baseera = await prisma.organization.upsert({
    where: { slug: "baseera" },
    update: {},
    create: {
      name: "Baseera",
      slug: "baseera",
    },
  });
  console.log("Created organization:", baseera.name);

  // Create a client admin for Baseera
  const clientPassword = await hash("baseera2026!", 12);
  const clientAdmin = await prisma.user.upsert({
    where: { email: "admin@baseera.ca" },
    update: {},
    create: {
      email: "admin@baseera.ca",
      name: "Baseera Admin",
      passwordHash: clientPassword,
      role: "client_admin",
      organizationId: baseera.id,
    },
  });
  console.log("Created client admin:", clientAdmin.email);

  // Create baseera.ca site
  const site = await prisma.site.upsert({
    where: { id: "baseera-site" },
    update: {},
    create: {
      id: "baseera-site",
      name: "baseera.ca",
      siteType: "nextjs",
      repoOwner: "asam89",
      repoName: "baseera-ca",
      productionUrl: "https://baseera.ca",
      organizationId: baseera.id,
      editablePaths: [
        "src/app/page.tsx",
        "src/app/about/page.tsx",
        "src/app/contact/page.tsx",
        "src/content/**",
        "public/images/**",
      ],
    },
  });
  console.log("Created site:", site.name);

  // Create starter subscription for Baseera
  await prisma.subscription.upsert({
    where: { organizationId: baseera.id },
    update: {},
    create: {
      plan: "starter",
      monthlyQuota: 10,
      currentUsage: 0,
      organizationId: baseera.id,
    },
  });
  console.log("Created subscription for:", baseera.name);
}

main()
  .then(() => prisma.$disconnect())
  .catch((e) => {
    console.error(e);
    prisma.$disconnect();
    process.exit(1);
  });
