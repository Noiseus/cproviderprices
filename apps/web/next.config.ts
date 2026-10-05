import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  output: "standalone",
  transpilePackages: ["@cproviderprices/i18n"],
};

export default nextConfig;
