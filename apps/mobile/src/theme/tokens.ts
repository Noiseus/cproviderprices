import rawTokens from "@cproviderprices/design-tokens/tokens.json";

export const tokens = rawTokens;

export const theme = {
  background: rawTokens.color.slate[50],
  surface: rawTokens.color.white,
  text: rawTokens.color.graphite[950],
  mutedText: rawTokens.color.slate[600],
  border: rawTokens.color.slate[200],
  primary: rawTokens.color.cyan[700],
  danger: rawTokens.color.red[700],
  warning: rawTokens.color.amber[700],
  success: rawTokens.color.green[700],
} as const;
