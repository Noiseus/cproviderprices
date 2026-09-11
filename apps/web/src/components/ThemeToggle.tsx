"use client";

import { useEffect, useState } from "react";
import { Select } from "./ui";

export type ThemePreference = "system" | "light" | "dark";
export const themeStorageKey = "cproviderprices-theme";

export function applyTheme(preference: ThemePreference) {
  const dark =
    preference === "dark" ||
    (preference === "system" &&
      window.matchMedia("(prefers-color-scheme: dark)").matches);
  document.documentElement.dataset.theme = dark ? "dark" : "light";
  document.documentElement.style.colorScheme = dark ? "dark" : "light";
}

export function ThemeToggle({ compact = false }: { compact?: boolean }) {
  const [preference, setPreference] = useState<ThemePreference>("system");

  useEffect(() => {
    const saved = localStorage.getItem(themeStorageKey);
    const initial: ThemePreference =
      saved === "light" || saved === "dark" ? saved : "system";
    setPreference(initial);
    applyTheme(initial);
    const media = window.matchMedia("(prefers-color-scheme: dark)");
    const updateSystemTheme = () => {
      if ((localStorage.getItem(themeStorageKey) ?? "system") === "system")
        applyTheme("system");
    };
    media.addEventListener("change", updateSystemTheme);
    return () => media.removeEventListener("change", updateSystemTheme);
  }, []);

  return (
    <Select
      className={
        compact ? "theme-toggle theme-toggle--compact" : "theme-toggle"
      }
      label="Theme"
      value={preference}
      onChange={(event) => {
        const next = event.target.value as ThemePreference;
        setPreference(next);
        if (next === "system") localStorage.removeItem(themeStorageKey);
        else localStorage.setItem(themeStorageKey, next);
        applyTheme(next);
      }}
    >
      <option value="system">System</option>
      <option value="light">Light</option>
      <option value="dark">Dark</option>
    </Select>
  );
}
