"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

interface SidebarProps {
  user: {
    name?: string | null;
    email: string;
    role: string;
    organizationId: string | null;
  };
}

export function Sidebar({ user }: SidebarProps) {
  const pathname = usePathname();
  const isStaff = user.role === "ignyte_staff";

  const navItems = [
    { href: "/sites", label: "Sites", icon: "🌐" },
    { href: "/billing", label: "Billing", icon: "💳" },
    ...(isStaff
      ? [
          { href: "/admin", label: "Dashboard", icon: "⚙️" },
          { href: "/admin/organizations", label: "Organizations", icon: "🏢" },
          { href: "/admin/onboard", label: "Onboard Site", icon: "➕" },
          { href: "/admin/review", label: "Review Queue", icon: "📋" },
          { href: "/admin/audit", label: "Audit Log", icon: "📜" },
        ]
      : []),
  ];

  return (
    <aside className="flex w-64 flex-col border-r border-gray-200 bg-[#1A1A2E]">
      {/* Logo */}
      <div className="flex h-16 items-center px-6">
        <Link href="/sites" className="flex items-center gap-2">
          <span className="text-xl font-bold text-[#E87722]">IGNYTE</span>
          <span className="text-sm text-gray-300">Site Manager</span>
        </Link>
      </div>

      {/* Navigation */}
      <nav className="flex-1 px-3 py-4 space-y-1">
        {navItems.map((item) => {
          const isActive = pathname === item.href || pathname.startsWith(item.href + "/");
          return (
            <Link
              key={item.href}
              href={item.href}
              className={`flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors ${
                isActive
                  ? "bg-[#E87722]/10 text-[#E87722]"
                  : "text-gray-300 hover:bg-white/5 hover:text-white"
              }`}
            >
              <span>{item.icon}</span>
              <span>{item.label}</span>
            </Link>
          );
        })}
      </nav>

      {/* User section */}
      <div className="border-t border-gray-700 px-4 py-4">
        <div className="flex items-center gap-3">
          <div className="flex h-8 w-8 items-center justify-center rounded-full bg-[#E87722] text-sm font-medium text-white">
            {(user.name || user.email)[0].toUpperCase()}
          </div>
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm text-white">
              {user.name || user.email}
            </p>
            <p className="truncate text-xs text-gray-400">{user.role.replace("_", " ")}</p>
          </div>
        </div>
        <form action="/api/auth/signout" method="POST" className="mt-3">
          <button
            type="submit"
            className="w-full rounded-lg px-3 py-1.5 text-xs text-gray-400 hover:bg-white/5 hover:text-white transition-colors"
          >
            Sign out
          </button>
        </form>
      </div>
    </aside>
  );
}
