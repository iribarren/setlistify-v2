import { Calendar, User } from "lucide-react-native";
import type { ComponentType } from "react";

/** AC-9.1: the authenticated shell's two destinations (`NavShell.dc.html`). */
export interface NavDestination {
  href: "/concerts" | "/account";
  label: string;
  icon: ComponentType<{ size?: number; color?: string; strokeWidth?: number }>;
}

export const NAV_DESTINATIONS: NavDestination[] = [
  { href: "/concerts", label: "Concerts", icon: Calendar },
  { href: "/account", label: "Account", icon: User },
];
