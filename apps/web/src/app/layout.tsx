import type { Metadata } from "next";
import type { ReactNode } from "react";
import { I18nProvider } from "@/components/i18n/I18nProvider";
import "./globals.css";

export const metadata: Metadata = {
  title: "CProviderPrices",
  description: "Supplier price comparison and purchasing optimization.",
};

const themeBootstrap = `(() => { try { const saved = localStorage.getItem('cproviderprices-theme'); const dark = saved === 'dark' || (saved !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches); const theme = dark ? 'dark' : 'light'; document.documentElement.dataset.theme = theme; document.documentElement.style.colorScheme = theme; } catch (_) {} })();`;

type RootLayoutProps = Readonly<{
  children: ReactNode;
}>;

export default function RootLayout({ children }: RootLayoutProps) {
  return (
    <html lang="en" suppressHydrationWarning>
      <head>
        <script dangerouslySetInnerHTML={{ __html: themeBootstrap }} />
      </head>
      <body>
        <I18nProvider>{children}</I18nProvider>
      </body>
    </html>
  );
}
